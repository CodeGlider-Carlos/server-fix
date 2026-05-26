<?php
/*
ez/pats/endpoints/solicitud_distribuidor_franquicia_actualizar.php
*/
require_once __DIR__ . '/bootstrap.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  pats_json(['ok' => false, 'error' => 'Método no permitido'], 405);
}

/* =========================================================
   HELPERS
========================================================= */
function sff_clean($v): string {
  return trim((string)($v ?? ''));
}

function sff_save_upload(string $baseDir, array $file, bool $required = true): ?array {
  if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
    if ($required) {
      throw new RuntimeException('Archivo requerido no recibido');
    }
    return null;
  }

  if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
    throw new RuntimeException('No fue posible crear el directorio de uploads');
  }

  $orig = (string)($file['name'] ?? 'archivo');
  $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
  $safeExt = preg_replace('/[^a-z0-9]/i', '', $ext);
  if ($safeExt === '') $safeExt = 'bin';

  $mime = (string)($file['type'] ?? 'application/octet-stream');
  $sizeBytes = (int)($file['size'] ?? 0);
  $sizeKb = (int)ceil($sizeBytes / 1024);

  $filename = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $safeExt;
  $target = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

  if (!move_uploaded_file($file['tmp_name'], $target)) {
    throw new RuntimeException('No fue posible mover el archivo: ' . $orig);
  }

  return [
    'path' => $target,
    'original' => $orig,
    'mime' => $mime,
    'size_kb' => $sizeKb
  ];
}

function sff_insert_doc(mysqli $cx, int $idSolicitud, string $tipoDocumento, array $meta, int $userAlta = 0): void {
  $tipoEsc = $cx->real_escape_string($tipoDocumento);

  $cx->query("
    UPDATE pats_solicitudes_distribuidor_documentos
    SET vigente = 0, updated_at = NOW()
    WHERE id_solicitud = {$idSolicitud}
      AND tipo_documento = '{$tipoEsc}'
      AND vigente = 1
  ");

  $stmt = $cx->prepare("
    INSERT INTO pats_solicitudes_distribuidor_documentos
    (
      id_solicitud, tipo_documento,
      archivo_path, archivo_nombre_original, mime_type, size_kb,
      vigente, observaciones, user_alta,
      created_at, updated_at
    )
    VALUES
    (
      ?, ?, ?, ?, ?, ?,
      1, NULL, ?,
      NOW(), NOW()
    )
  ");
  if (!$stmt) {
    throw new RuntimeException('No fue posible preparar documento');
  }

  $path = (string)$meta['path'];
  $orig = (string)$meta['original'];
  $mime = (string)$meta['mime'];
  $sizeKb = (int)$meta['size_kb'];

  $stmt->bind_param(
    'issssii',
    $idSolicitud,
    $tipoDocumento,
    $path,
    $orig,
    $mime,
    $sizeKb,
    $userAlta
  );

  if (!$stmt->execute()) {
    throw new RuntimeException('No fue posible guardar documento: ' . $stmt->error);
  }
  $stmt->close();
}

function sff_insert_historial(mysqli $cx, int $idSolicitud, string $eventoTipo, ?string $estatusAnterior, ?string $estatusNuevo, ?array $payload, ?int $userEvento): void {
  $payloadJson = $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

  $stmt = $cx->prepare("
    INSERT INTO pats_solicitudes_distribuidor_historial
    (
      id_solicitud, evento_tipo, estatus_anterior, estatus_nuevo,
      payload_json, user_evento, fecha_evento, created_at
    )
    VALUES
    (?, ?, ?, ?, ?, ?, NOW(), NOW())
  ");
  if (!$stmt) {
    throw new RuntimeException('No fue posible preparar historial');
  }

  $stmt->bind_param(
    'issssi',
    $idSolicitud,
    $eventoTipo,
    $estatusAnterior,
    $estatusNuevo,
    $payloadJson,
    $userEvento
  );

  if (!$stmt->execute()) {
    throw new RuntimeException('No fue posible guardar historial: ' . $stmt->error);
  }
  $stmt->close();
}

/* =========================================================
   CONTROL DE ACCESO
========================================================= */
$userId = (int)($_SESSION['id'] ?? 0);

if (empty($_SESSION['usuario'])) {
  pats_json(['ok' => false, 'error' => 'Sesión inválida'], 403);
}

/* =========================================================
   INPUTS
========================================================= */
$idSolicitud = (int)($_POST['id_solicitud'] ?? 0);
$idFranquicia = (int)($_POST['id_franquicia'] ?? 0);
$accion = strtoupper(sff_clean($_POST['accion'] ?? ''));
$observacionesFranquicia = sff_clean($_POST['observaciones_franquicia'] ?? '');

if ($idSolicitud <= 0) {
  pats_json(['ok' => false, 'error' => 'Falta id_solicitud válido'], 422);
}
if ($idFranquicia <= 0) {
  pats_json(['ok' => false, 'error' => 'Falta id_franquicia válido'], 422);
}
if ($accion !== 'CARGAR_CONTRATO_FIRMADO') {
  pats_json(['ok' => false, 'error' => 'Acción no soportada'], 422);
}
if (empty($_FILES['doc_contrato_firmado']['tmp_name'])) {
  pats_json(['ok' => false, 'error' => 'Debes cargar el contrato firmado'], 422);
}

/* =========================================================
   CARGAR SOLICITUD
========================================================= */
$solicitud = pats_one($cx, "
  SELECT *
  FROM pats_solicitudes_distribuidor
  WHERE id_solicitud = {$idSolicitud}
    AND id_franquicia = {$idFranquicia}
    AND activo = 1
  LIMIT 1
");

if (!$solicitud) {
  pats_json(['ok' => false, 'error' => 'La solicitud no existe o no pertenece a la franquicia'], 404);
}

$estatusAnterior = strtoupper((string)($solicitud['estatus'] ?? ''));

if (empty($solicitud['contrato_admin_path'])) {
  pats_json(['ok' => false, 'error' => 'Aún no existe contrato enviado por ADMIN PATS'], 422);
}

if (!in_array($estatusAnterior, ['CONTRATO_ENVIADO', 'OBSERVADA', 'VALIDADA_DOCUMENTALMENTE'], true)) {
  pats_json([
    'ok' => false,
    'error' => 'La solicitud no está en un estado válido para cargar el contrato firmado'
  ], 422);
}

/* =========================================================
   PROCESO
========================================================= */
$cx->begin_transaction();

try {
  $baseDir = dirname(__DIR__) . '/uploads/solicitudes_distribuidor/' . $idSolicitud . '/contrato_firmado';
  $meta = sff_save_upload($baseDir, $_FILES['doc_contrato_firmado'], true);

  sff_insert_doc($cx, $idSolicitud, 'CONTRATO_FIRMADO', $meta, $userId);

  $estatusNuevo = 'CONTRATO_FIRMADO_CARGADO';
  $pathContratoFirmado = (string)$meta['path'];

  $stmt = $cx->prepare("
    UPDATE pats_solicitudes_distribuidor
    SET
      estatus = ?,
      contrato_firmado_path = ?,
      observaciones_franquicia = ?,
      fecha_carga_firmado = NOW(),
      updated_at = NOW()
    WHERE id_solicitud = ?
    LIMIT 1
  ");
  if (!$stmt) {
    throw new RuntimeException('No fue posible preparar actualización de solicitud');
  }

  $stmt->bind_param(
    'sssi',
    $estatusNuevo,
    $pathContratoFirmado,
    $observacionesFranquicia,
    $idSolicitud
  );

  if (!$stmt->execute()) {
    throw new RuntimeException('No fue posible guardar contrato firmado: ' . $stmt->error);
  }
  $stmt->close();

  sff_insert_historial(
    $cx,
    $idSolicitud,
    'contrato_firmado_cargado',
    $estatusAnterior,
    $estatusNuevo,
    [
      'archivo' => $pathContratoFirmado,
      'observaciones_franquicia' => $observacionesFranquicia
    ],
    $userId > 0 ? $userId : null
  );

  $cx->commit();

  pats_json([
    'ok' => true,
    'id_solicitud' => $idSolicitud,
    'estatus_anterior' => $estatusAnterior,
    'estatus_nuevo' => $estatusNuevo,
    'contrato_firmado_path' => $pathContratoFirmado
  ]);

} catch (Throwable $e) {
  $cx->rollback();
  pats_json([
    'ok' => false,
    'error' => $e->getMessage()
  ], 500);
}