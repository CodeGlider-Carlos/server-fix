<?php
/*
ez/pats/endpoints/solicitud_distribuidor_guardar.php
*/
require_once __DIR__ . '/bootstrap.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  pats_json(['ok' => false, 'error' => 'Método no permitido'], 405);
}

/* =========================================================
   HELPERS
========================================================= */
function sol_clean($v): string {
  return trim((string)($v ?? ''));
}

function sol_digits($v): string {
  return preg_replace('/\D+/', '', (string)($v ?? ''));
}

function sol_num($v): float {
  return round((float)($v ?? 0), 2);
}

function sol_validate_email(string $correo): bool {
  return (bool)filter_var($correo, FILTER_VALIDATE_EMAIL);
}

function sol_validate_phone(string $telefono): bool {
  return strlen(sol_digits($telefono)) === 10;
}

function sol_validate_clabe(string $clabe): bool {
  $clabe = sol_digits($clabe);
  if ($clabe === '') return true;
  if (strlen($clabe) !== 18) return false;

  $factors = [3, 7, 1];
  $sum = 0;

  for ($i = 0; $i < 17; $i++) {
    $digit = (int)$clabe[$i];
    $factor = $factors[$i % 3];
    $sum += (($digit * $factor) % 10);
  }

  $control = (10 - ($sum % 10)) % 10;
  return $control === (int)$clabe[17];
}

function sol_save_upload(string $baseDir, array $file, bool $required = true): ?array {
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
    throw new RuntimeException('No fue posible mover el archivo ' . $orig);
  }

  return [
    'path' => $target,
    'original' => $orig,
    'mime' => $mime,
    'size_kb' => $sizeKb
  ];
}

function sol_insert_documento(mysqli $cx, int $idSolicitud, string $tipoDocumento, array $meta, int $userAlta = 0): void {
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
    throw new RuntimeException('No fue posible preparar documento de solicitud');
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
    throw new RuntimeException('No fue posible guardar documento de solicitud: ' . $stmt->error);
  }
  $stmt->close();
}

function sol_insert_historial(mysqli $cx, int $idSolicitud, string $eventoTipo, ?string $estatusAnterior, ?string $estatusNuevo, ?array $payload, ?int $userEvento): void {
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
    throw new RuntimeException('No fue posible preparar historial de solicitud');
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
    throw new RuntimeException('No fue posible guardar historial de solicitud: ' . $stmt->error);
  }
  $stmt->close();
}

/* =========================================================
   INPUTS
========================================================= */
$userSolicita = (int)($_SESSION['id'] ?? 0);

$idSolicitudEditar = (int)($_POST['id_solicitud'] ?? 0);
$idFranquicia = (int)($_POST['id_franquicia'] ?? 0);

$pais = sol_clean($_POST['pais'] ?? '');
$region = strtoupper(sol_clean($_POST['region'] ?? ''));
$zona = sol_clean($_POST['zona'] ?? '');
$zonaNueva = sol_clean($_POST['zona_nueva'] ?? '');
$unidad = sol_clean($_POST['unidad'] ?? '');

$nombre = sol_clean($_POST['nombre'] ?? '');
$razonSocial = sol_clean($_POST['razon_social'] ?? '');
$rfc = strtoupper(sol_clean($_POST['rfc'] ?? ''));
$telefono = sol_digits($_POST['telefono'] ?? '');
$correo = mb_strtolower(sol_clean($_POST['correo'] ?? ''));
$direccion = sol_clean($_POST['direccion'] ?? '');

$banco = sol_clean($_POST['banco'] ?? '');
$numeroCuenta = sol_clean($_POST['numero_cuenta'] ?? '');
$clabe = sol_digits($_POST['clabe'] ?? '');
$titularCuenta = sol_clean($_POST['titular_cuenta'] ?? '');

$modalidadPago = strtoupper(sol_clean($_POST['modalidad_pago'] ?? 'CONTADO'));
$valorTotalPost = sol_num($_POST['valor_total'] ?? 0);
$enganche = max(0, sol_num($_POST['enganche'] ?? 0));
$saldoFinanciadoPost = max(0, sol_num($_POST['saldo_financiado'] ?? 0));
$plazoMeses = (int)($_POST['plazo_meses'] ?? 0);
$periodicidad = strtoupper(sol_clean($_POST['periodicidad'] ?? 'MENSUAL'));
$fechaInicio = sol_clean($_POST['fecha_inicio'] ?? '');
$fechaPrimerVenc = sol_clean($_POST['fecha_primer_vencimiento'] ?? '');

if ($zona === '__NUEVA__') {
  $zona = $zonaNueva;
}

$docsOptional = [
  'doc_caratula_bancaria' => 'CARATULA_BANCARIA'
];

$docsRequired = [
  'doc_ine' => 'INE',
  'doc_curp' => 'CURP',
  'doc_domicilio' => 'COMPROBANTE_DOMICILIO',
  'doc_cedula' => 'CEDULA_FISCAL',
  'doc_comprobante_pago' => 'COMPROBANTE_PAGO'
];
/* =========================================================
   VALIDACIONES BASE
========================================================= */
if ($idFranquicia <= 0) {
  pats_json(['ok' => false, 'error' => 'Falta franquicia válida'], 422);
}

if ($pais === '' || $region === '' || $zona === '' || $nombre === '' || $telefono === '' || $correo === '') {
  pats_json(['ok' => false, 'error' => 'Faltan campos obligatorios'], 422);
}

if (!sol_validate_email($correo)) {
  pats_json(['ok' => false, 'error' => 'Correo inválido'], 422);
}

if (!sol_validate_phone($telefono)) {
  pats_json(['ok' => false, 'error' => 'El teléfono debe tener 10 dígitos'], 422);
}

if (!sol_validate_clabe($clabe)) {
  pats_json(['ok' => false, 'error' => 'La CLABE no es válida'], 422);
}

if ($modalidadPago === '') {
  pats_json(['ok' => false, 'error' => 'Falta modalidad de pago'], 422);
}

if ($fechaInicio === '') {
  pats_json(['ok' => false, 'error' => 'Falta fecha de inicio'], 422);
}

if (!in_array($modalidadPago, ['CONTADO', 'ENGANCHE_DIFERIDO', 'DIFERIDO'], true)) {
  pats_json(['ok' => false, 'error' => 'Modalidad de pago no válida'], 422);
}

if (!in_array($periodicidad, ['MENSUAL', 'QUINCENAL', 'SEMANAL', 'UNICA'], true)) {
  pats_json(['ok' => false, 'error' => 'Periodicidad no válida'], 422);
}

if ($idSolicitudEditar <= 0) {
  foreach ($docsRequired as $key => $label) {
    if (empty($_FILES[$key]['tmp_name'])) {
      pats_json(['ok' => false, 'error' => "Falta documento requerido: {$label}"], 422);
    }
  }
}

/* =========================================================
   VALIDACIÓN DE FRANQUICIA
========================================================= */
$franquicia = pats_one($cx, "
  SELECT
    id_franquicia,
    nombre_franquicia,
    franquiciatario,
    pais,
    region,
    zona,
    unidad,
    activo
  FROM pats_franquicias
  WHERE id_franquicia = {$idFranquicia}
    AND activo = 1
  LIMIT 1
");

if (!$franquicia) {
  pats_json(['ok' => false, 'error' => 'La franquicia no existe o está inactiva'], 404);
}

/* =========================================================
   PRECIO Y REGLAS FINANCIERAS
========================================================= */
$regionFranq = strtoupper(sol_clean($franquicia['region'] ?? ''));
$ambitoRegion = ($regionFranq !== '' && $regionFranq === $region) ? 'misma_region' : 'otra_region';

$rowPrecio = pats_one($cx, "
  SELECT precio
  FROM pats_cat_precios
  WHERE LOWER(TRIM(tipo)) = 'distribucion'
    AND LOWER(TRIM(modalidad)) = '" . $cx->real_escape_string($ambitoRegion) . "'
  ORDER BY id ASC
  LIMIT 1
");

$valorTotalCatalogo = sol_num($rowPrecio['precio'] ?? 0);
if ($valorTotalCatalogo <= 0) {
  $valorTotalCatalogo = $valorTotalPost > 0 ? $valorTotalPost : 20000.00;
}

$valorTotal = $valorTotalCatalogo;
$saldoFinanciado = max(0, round($valorTotal - $enganche, 2));

if ($modalidadPago === 'CONTADO') {
  $enganche = 0.00;
  $saldoFinanciado = 0.00;
  $plazoMeses = 0;
  $fechaPrimerVenc = null;
} else {
  if ($saldoFinanciado > 0 && $plazoMeses <= 0) {
    pats_json(['ok' => false, 'error' => 'Debes indicar un plazo válido para pago diferido'], 422);
  }

  if ($fechaPrimerVenc === '') {
    $fechaPrimerVenc = $fechaInicio;
  }
}

if ($enganche > $valorTotal) {
  pats_json(['ok' => false, 'error' => 'El enganche no puede ser mayor al valor total'], 422);
}

/* =========================================================
   SI ES EDICIÓN, VALIDAR QUE PUEDA EDITARSE
========================================================= */
$solicitudEditable = null;
if ($idSolicitudEditar > 0) {
  $solicitudEditable = pats_one($cx, "
    SELECT *
    FROM pats_solicitudes_distribuidor
    WHERE id_solicitud = {$idSolicitudEditar}
      AND id_franquicia = {$idFranquicia}
      AND activo = 1
    LIMIT 1
  ");

  if (!$solicitudEditable) {
    pats_json(['ok' => false, 'error' => 'La solicitud a editar no existe'], 404);
  }

  if (strtoupper((string)($solicitudEditable['estatus'] ?? '')) !== 'ENVIADA') {
    pats_json(['ok' => false, 'error' => 'Solo se puede editar una solicitud en estatus ENVIADA'], 422);
  }
}

/* =========================================================
   EVITAR DUPLICADOS
========================================================= */
$correoEsc = $cx->real_escape_string($correo);

$existeDistribuidor = pats_one($cx, "
  SELECT id_distribuidor
  FROM pats_distribuidores
  WHERE correo = '{$correoEsc}'
  LIMIT 1
");
if (!empty($existeDistribuidor['id_distribuidor'])) {
  pats_json(['ok' => false, 'error' => 'Ya existe un distribuidor definitivo con ese correo'], 409);
}

$extraWhere = $idSolicitudEditar > 0 ? " AND id_solicitud <> {$idSolicitudEditar} " : "";
$solicitudActiva = pats_one($cx, "
  SELECT id_solicitud, estatus
  FROM pats_solicitudes_distribuidor
  WHERE correo = '{$correoEsc}'
    AND estatus NOT IN ('RECHAZADA', 'CONVERTIDA_ALTA')
    {$extraWhere}
  ORDER BY id_solicitud DESC
  LIMIT 1
");
if (!empty($solicitudActiva['id_solicitud'])) {
  pats_json([
    'ok' => false,
    'error' => 'Ya existe una solicitud activa para este correo',
    'id_solicitud' => (int)$solicitudActiva['id_solicitud'],
    'estatus' => (string)$solicitudActiva['estatus']
  ], 409);
}

/* =========================================================
   PROCESO
========================================================= */
$cx->begin_transaction();

try {
  if ($idSolicitudEditar > 0) {
    $stmt = $cx->prepare("
      UPDATE pats_solicitudes_distribuidor
      SET
        pais = ?,
        region = ?,
        zona = ?,
        unidad = ?,
        nombre = ?,
        razon_social = ?,
        rfc = ?,
        telefono = ?,
        correo = ?,
        direccion = ?,
        banco = ?,
        numero_cuenta = ?,
        clabe = ?,
        titular_cuenta = ?,
        modalidad_pago = ?,
        valor_total = ?,
        enganche = ?,
        saldo_financiado = ?,
        plazo_meses = ?,
        periodicidad = ?,
        fecha_inicio = ?,
        fecha_primer_vencimiento = ?,
        updated_at = NOW()
      WHERE id_solicitud = ?
      LIMIT 1
    ");
    if (!$stmt) {
      throw new RuntimeException('No fue posible preparar la actualización');
    }

    $stmt->bind_param(
      'ssssssssssssssdddisssi',
      $pais,
      $region,
      $zona,
      $unidad,
      $nombre,
      $razonSocial,
      $rfc,
      $telefono,
      $correo,
      $direccion,
      $banco,
      $numeroCuenta,
      $clabe,
      $titularCuenta,
      $modalidadPago,
      $valorTotal,
      $enganche,
      $saldoFinanciado,
      $plazoMeses,
      $periodicidad,
      $fechaInicio,
      $fechaPrimerVenc,
      $idSolicitudEditar
    );

    if (!$stmt->execute()) {
      throw new RuntimeException('No fue posible actualizar la solicitud: ' . $stmt->error);
    }
    $stmt->close();

    $idSolicitud = $idSolicitudEditar;

    sol_insert_historial(
      $cx,
      $idSolicitud,
      'solicitud_editada',
      'ENVIADA',
      'ENVIADA',
      [
        'correo' => $correo,
        'telefono' => $telefono,
        'zona' => $zona,
        'modalidad_pago' => $modalidadPago,
        'valor_total' => $valorTotal,
        'enganche' => $enganche,
        'saldo_financiado' => $saldoFinanciado,
        'plazo_meses' => $plazoMeses,
        'periodicidad' => $periodicidad,
        'fecha_inicio' => $fechaInicio,
        'fecha_primer_vencimiento' => $fechaPrimerVenc
      ],
      $userSolicita > 0 ? $userSolicita : null
    );

  } else {
    $stmt = $cx->prepare("
      INSERT INTO pats_solicitudes_distribuidor
      (
        id_franquicia,
        id_distribuidor_generado,
        user_solicita,
        user_valida,
        user_autoriza,
        pais, region, zona, unidad,
        nombre, razon_social, rfc, telefono, correo, direccion,
        banco, numero_cuenta, clabe, titular_cuenta,
        modalidad_pago, valor_total, enganche, saldo_financiado,
        plazo_meses, periodicidad, fecha_inicio, fecha_primer_vencimiento,
        contrato_admin_path, contrato_firmado_path,
        estatus, motivo_rechazo, observaciones_admin, observaciones_franquicia,
        fecha_envio_contrato, fecha_carga_firmado, fecha_autorizacion, fecha_conversion_alta,
        activo, created_at, updated_at
      )
      VALUES
      (
        ?,
        NULL,
        ?, NULL, NULL,
        ?, ?, ?, ?,
        ?, ?, ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?,
        NULL, NULL,
        'ENVIADA', NULL, NULL, NULL,
        NULL, NULL, NULL, NULL,
        1, NOW(), NOW()
      )
    ");
    if (!$stmt) {
      throw new RuntimeException('No fue posible preparar la solicitud: ' . $cx->error);
    }

    $stmt->bind_param(
      'iissssssssssssssdddissss',
      $idFranquicia,
      $userSolicita,
      $pais,
      $region,
      $zona,
      $unidad,
      $nombre,
      $razonSocial,
      $rfc,
      $telefono,
      $correo,
      $direccion,
      $banco,
      $numeroCuenta,
      $clabe,
      $titularCuenta,
      $modalidadPago,
      $valorTotal,
      $enganche,
      $saldoFinanciado,
      $plazoMeses,
      $periodicidad,
      $fechaInicio,
      $fechaPrimerVenc
    );

    if (!$stmt->execute()) {
      throw new RuntimeException('No fue posible guardar la solicitud: ' . $stmt->error);
    }

    $idSolicitud = (int)$stmt->insert_id;
    $stmt->close();

    sol_insert_historial(
      $cx,
      $idSolicitud,
      'solicitud_enviada',
      null,
      'ENVIADA',
      [
        'id_franquicia' => $idFranquicia,
        'franquicia' => (string)($franquicia['nombre_franquicia'] ?? ''),
        'correo' => $correo,
        'telefono' => $telefono,
        'zona' => $zona,
        'modalidad_pago' => $modalidadPago,
        'valor_total' => $valorTotal,
        'enganche' => $enganche,
        'saldo_financiado' => $saldoFinanciado,
        'plazo_meses' => $plazoMeses,
        'periodicidad' => $periodicidad,
        'fecha_inicio' => $fechaInicio,
        'fecha_primer_vencimiento' => $fechaPrimerVenc
      ],
      $userSolicita > 0 ? $userSolicita : null
    );
  }

  $baseDir = dirname(__DIR__) . '/uploads/solicitudes_distribuidor/' . $idSolicitud;

  foreach ($docsRequired as $key => $tipoDocumento) {
    if (!empty($_FILES[$key]['tmp_name'])) {
      $meta = sol_save_upload($baseDir, $_FILES[$key], true);
      sol_insert_documento($cx, $idSolicitud, $tipoDocumento, $meta, $userSolicita);
    } elseif ($idSolicitudEditar <= 0) {
      throw new RuntimeException("Falta documento requerido: {$tipoDocumento}");
    }
  }

  foreach ($docsOptional as $key => $tipoDocumento) {
    if (!empty($_FILES[$key]['tmp_name'])) {
      $meta = sol_save_upload($baseDir, $_FILES[$key], false);
      if ($meta) {
        sol_insert_documento($cx, $idSolicitud, $tipoDocumento, $meta, $userSolicita);
      }
    }
  }

  $cx->commit();

  pats_json([
    'ok' => true,
    'id_solicitud' => $idSolicitud,
    'estatus' => 'ENVIADA',
    'modo' => $idSolicitudEditar > 0 ? 'edicion' : 'alta',
    'message' => $idSolicitudEditar > 0 ? 'Solicitud actualizada correctamente' : 'Solicitud enviada correctamente'
  ]);

} catch (Throwable $e) {
  $cx->rollback();
  pats_json([
    'ok' => false,
    'error' => $e->getMessage()
  ], 500);
}