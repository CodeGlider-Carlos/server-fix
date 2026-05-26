<?php
/*
ez/patsfin/endpoints/solicitud_franquicia_actualizar_estatus.php
*/
require_once __DIR__ . '/bootstrap.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
  fin_json(['ok' => false, 'error' => 'Método no permitido'], 405);
}

/* =========================================================
   HELPERS LOCALES
========================================================= */
function sfae_clean($v): string {
  return trim((string)($v ?? ''));
}

function sfae_bool($v): bool {
  return !empty($v) && trim((string)$v) !== '';
}

function sfae_table_exists(mysqli $cx, string $table): bool {
  if (function_exists('fin_table_exists')) {
    return fin_table_exists($cx, $table);
  }

  $tableEsc = fin_esc($cx, $table);
  $rs = $cx->query("SHOW TABLES LIKE '{$tableEsc}'");

  if (!$rs) {
    return false;
  }

  $exists = $rs->num_rows > 0;
  $rs->free();

  return $exists;
}

function sfae_column_exists(mysqli $cx, string $table, string $column): bool {
  $tableEsc = fin_esc($cx, $table);
  $columnEsc = fin_esc($cx, $column);

  $rs = $cx->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$columnEsc}'");

  if (!$rs) {
    return false;
  }

  $exists = $rs->num_rows > 0;
  $rs->free();

  return $exists;
}

function sfae_hash_file_safe(string $path): string {
  if ($path === '' || !is_file($path)) {
    return '';
  }

  return hash_file('sha256', $path) ?: '';
}

function sfae_documentos_requeridos(string $tipoPersona): array {
  $tipoPersona = strtoupper(trim($tipoPersona));

  $docs = [
    'INE',
    'CEDULA_FISCAL',
    'COMPROBANTE_DOMICILIO',
    'CARATULA_BANCARIA',
    'COMPROBANTE_PAGO'
  ];

  if ($tipoPersona === 'FISICA') {
    $docs[] = 'CURP';
  }

  if ($tipoPersona === 'MORAL') {
    $docs[] = 'ACTA_CONSTITUTIVA';
    $docs[] = 'PODER_NOTARIAL';
  }

  return $docs;
}

function sfae_get_docs_por_tipo(mysqli $cx, int $idSolicitud): array {
  if (!sfae_table_exists($cx, 'pats_solicitudes_franquicia_documentos')) {
    return [];
  }

  $docs = fin_all($cx, "
    SELECT *
    FROM pats_solicitudes_franquicia_documentos
    WHERE id_solicitud = {$idSolicitud}
      AND vigente = 1
    ORDER BY id_documento_solicitud DESC
  ");

  $out = [];

  foreach ($docs as $doc) {
    $tipo = strtoupper(sfae_clean($doc['tipo_documento'] ?? ''));

    if ($tipo !== '' && !isset($out[$tipo])) {
      $out[$tipo] = $doc;
    }
  }

  return $out;
}

function sfae_validacion_firma(array $sol, array $docsPorTipo): array {
  $contratoDigitalFirmado = (
    sfae_bool($sol['contrato_digital_hash'] ?? '') &&
    sfae_bool($sol['contrato_digital_firmado_at'] ?? '')
  );

  $fotografiaCapturada = (
    sfae_bool($sol['fotografia_path'] ?? '') ||
    isset($docsPorTipo['FOTOGRAFIA_SOLICITANTE'])
  );

  $contratoFirmadoHistorico = (
    sfae_bool($sol['contrato_firmado_path'] ?? '') ||
    isset($docsPorTipo['CONTRATO_FIRMADO'])
  );

  return [
    'contrato_digital_firmado' => $contratoDigitalFirmado,
    'fotografia_capturada' => $fotografiaCapturada,
    'contrato_firmado_historico' => $contratoFirmadoHistorico,
    'puede_autorizar' => (($contratoDigitalFirmado && $fotografiaCapturada) || $contratoFirmadoHistorico)
  ];
}

function sfae_desactivar_documentos_tipo(mysqli $cx, int $idSolicitud, string $tipoDocumento): void {
  if (!sfae_table_exists($cx, 'pats_solicitudes_franquicia_documentos')) {
    return;
  }

  fin_exec($cx, "
    UPDATE pats_solicitudes_franquicia_documentos
    SET vigente = 0,
        updated_at = NOW()
    WHERE id_solicitud = {$idSolicitud}
      AND UPPER(TRIM(tipo_documento)) = '" . fin_esc($cx, strtoupper($tipoDocumento)) . "'
      AND vigente = 1
  ");
}

function sfae_insert_documento_solicitud(
  mysqli $cx,
  int $idSolicitud,
  string $tipoDocumento,
  array $meta,
  string $origenDocumento,
  ?string $observaciones,
  int $userAlta
): int {
  if (!sfae_table_exists($cx, 'pats_solicitudes_franquicia_documentos')) {
    throw new RuntimeException('No existe la tabla pats_solicitudes_franquicia_documentos');
  }

  $hashArchivo = '';

  $path = sfae_clean($meta['path'] ?? '');
  $pathAbs = $path;

  if ($pathAbs !== '' && !is_file($pathAbs)) {
    $maybeAbs = dirname(__DIR__) . '/' . ltrim($pathAbs, '/\\');

    if (is_file($maybeAbs)) {
      $pathAbs = $maybeAbs;
    }
  }

  if ($pathAbs !== '' && is_file($pathAbs)) {
    $hashArchivo = sfae_hash_file_safe($pathAbs);
  }

  $pathDb = sfae_clean($meta['path'] ?? '');
  $original = sfae_clean($meta['original'] ?? '');
  $mime = sfae_clean($meta['mime'] ?? '');
  $sizeKb = (int)($meta['size_kb'] ?? 0);
  $observaciones = $observaciones ?? '';

  $hasOrigen = sfae_column_exists($cx, 'pats_solicitudes_franquicia_documentos', 'origen_documento');
  $hasHash = sfae_column_exists($cx, 'pats_solicitudes_franquicia_documentos', 'hash_archivo');

  if ($hasOrigen && $hasHash) {
    $stmt = $cx->prepare("
      INSERT INTO pats_solicitudes_franquicia_documentos
      (
        id_solicitud,
        tipo_documento,
        origen_documento,
        archivo_path,
        archivo_nombre_original,
        mime_type,
        size_kb,
        hash_archivo,
        vigente,
        observaciones,
        user_alta,
        created_at,
        updated_at
      )
      VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, NOW(), NOW())
    ");

    if (!$stmt) {
      throw new RuntimeException('No fue posible preparar documento de solicitud');
    }

    $stmt->bind_param(
      'isssssissi',
      $idSolicitud,
      $tipoDocumento,
      $origenDocumento,
      $pathDb,
      $original,
      $mime,
      $sizeKb,
      $hashArchivo,
      $observaciones,
      $userAlta
    );
  } else {
    $stmt = $cx->prepare("
      INSERT INTO pats_solicitudes_franquicia_documentos
      (
        id_solicitud,
        tipo_documento,
        archivo_path,
        archivo_nombre_original,
        mime_type,
        size_kb,
        vigente,
        observaciones,
        user_alta,
        created_at,
        updated_at
      )
      VALUES
      (?, ?, ?, ?, ?, ?, 1, ?, ?, NOW(), NOW())
    ");

    if (!$stmt) {
      throw new RuntimeException('No fue posible preparar documento de solicitud');
    }

    $stmt->bind_param(
      'issssisi',
      $idSolicitud,
      $tipoDocumento,
      $pathDb,
      $original,
      $mime,
      $sizeKb,
      $observaciones,
      $userAlta
    );
  }

  if (!$stmt->execute()) {
    throw new RuntimeException('No fue posible guardar documento de solicitud: ' . $stmt->error);
  }

  $id = (int)$stmt->insert_id;
  $stmt->close();

  return $id;
}

function sfae_insert_historial(
  mysqli $cx,
  int $idSolicitud,
  string $eventoTipo,
  ?string $estatusAnterior,
  ?string $estatusNuevo,
  ?array $payload,
  ?int $userEvento
): void {
  if (!sfae_table_exists($cx, 'pats_solicitudes_franquicia_historial')) {
    return;
  }

  $cols = [];
  $rsCols = $cx->query("SHOW COLUMNS FROM pats_solicitudes_franquicia_historial");

  if ($rsCols) {
    while ($c = $rsCols->fetch_assoc()) {
      $field = (string)($c['Field'] ?? '');
      if ($field !== '') {
        $cols[] = $field;
      }
    }
    $rsCols->free();
  }

  if (
    !in_array('id_solicitud', $cols, true) ||
    !in_array('evento_tipo', $cols, true)
  ) {
    return;
  }

  $payloadJson = $payload
    ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    : null;

  $fields = ['id_solicitud', 'evento_tipo'];
  $valuesSql = [
    (string)$idSolicitud,
    "'" . fin_esc($cx, $eventoTipo) . "'"
  ];

  if (in_array('estatus_anterior', $cols, true)) {
    $fields[] = 'estatus_anterior';
    $valuesSql[] = $estatusAnterior !== null ? "'" . fin_esc($cx, $estatusAnterior) . "'" : "NULL";
  }

  if (in_array('estatus_nuevo', $cols, true)) {
    $fields[] = 'estatus_nuevo';
    $valuesSql[] = $estatusNuevo !== null ? "'" . fin_esc($cx, $estatusNuevo) . "'" : "NULL";
  }

  if (in_array('payload_json', $cols, true)) {
    $fields[] = 'payload_json';
    $valuesSql[] = $payloadJson !== null ? "'" . fin_esc($cx, $payloadJson) . "'" : "NULL";
  }

  if (in_array('observaciones', $cols, true)) {
    $fields[] = 'observaciones';
    $valuesSql[] = isset($payload['observaciones'])
      ? "'" . fin_esc($cx, (string)$payload['observaciones']) . "'"
      : "NULL";
  }

  if (in_array('user_evento', $cols, true)) {
    $fields[] = 'user_evento';
    $valuesSql[] = $userEvento !== null ? (string)$userEvento : "NULL";
  }

  if (in_array('fecha_evento', $cols, true)) {
    $fields[] = 'fecha_evento';
    $valuesSql[] = "NOW()";
  }

  if (in_array('created_at', $cols, true)) {
    $fields[] = 'created_at';
    $valuesSql[] = "NOW()";
  }

  fin_exec($cx, "
    INSERT INTO pats_solicitudes_franquicia_historial
    (`" . implode('`,`', $fields) . "`)
    VALUES
    (" . implode(',', $valuesSql) . ")
  ");
}

function sfae_save_text_file(string $dirAbs, string $filename, string $content): array {
  if (!is_dir($dirAbs) && !mkdir($dirAbs, 0775, true)) {
    throw new RuntimeException('No fue posible crear carpeta para archivo digital');
  }

  $filename = preg_replace('/[^a-zA-Z0-9_\.\-]+/', '_', $filename);
  $pathAbs = rtrim($dirAbs, '/\\') . DIRECTORY_SEPARATOR . $filename;

  if (file_put_contents($pathAbs, $content) === false) {
    throw new RuntimeException('No fue posible guardar archivo digital');
  }

  @chmod($pathAbs, 0644);

  $pathRel = str_replace('\\', '/', $pathAbs);
  $needle = '/patsfin/';
  $pos = strpos($pathRel, $needle);

  if ($pos !== false) {
    $pathRel = substr($pathRel, $pos + strlen($needle));
  }

  return [
    'path' => $pathRel,
    'original' => $filename,
    'mime' => 'text/html',
    'size_kb' => (int)ceil(strlen($content) / 1024),
    'hash' => hash('sha256', $content)
  ];
}

/* =========================================================
   INPUT
========================================================= */
$idSolicitud = (int)($_POST['id_solicitud'] ?? 0);
$accion = strtoupper(sfae_clean($_POST['accion'] ?? ''));
$observaciones = sfae_clean($_POST['observaciones'] ?? ($_POST['observaciones_admin'] ?? ''));
$motivoRechazo = sfae_clean($_POST['motivo_rechazo'] ?? '');
$userEvento = (int)($_SESSION['id'] ?? 0);

if ($idSolicitud <= 0) {
  fin_json(['ok' => false, 'error' => 'Falta id_solicitud válido'], 422);
}

if ($accion === '') {
  fin_json(['ok' => false, 'error' => 'Falta acción'], 422);
}

/* =========================================================
   CARGA SOLICITUD
========================================================= */
if (!sfae_table_exists($cx, 'pats_solicitudes_franquicia')) {
  fin_json(['ok' => false, 'error' => 'No existe la tabla pats_solicitudes_franquicia'], 500);
}

$sol = fin_one($cx, "
  SELECT *
  FROM pats_solicitudes_franquicia
  WHERE id_solicitud = {$idSolicitud}
    AND activo = 1
  LIMIT 1
");

if (!$sol) {
  fin_json(['ok' => false, 'error' => 'Solicitud de franquicia no encontrada'], 404);
}

$estatusAnterior = strtoupper(sfae_clean($sol['estatus'] ?? 'ENVIADA'));

if ($estatusAnterior === 'CONVERTIDA_ALTA') {
  fin_json(['ok' => false, 'error' => 'La solicitud ya fue convertida a alta'], 409);
}

$docsPorTipo = sfae_get_docs_por_tipo($cx, $idSolicitud);

/* =========================================================
   TRANSACCIÓN
========================================================= */
$cx->begin_transaction();

try {
  $nuevoEstatus = $estatusAnterior;
  $payload = [
    'accion' => $accion
  ];

  /* =======================================================
     VALIDAR DOCUMENTOS
  ======================================================= */
  if ($accion === 'VALIDAR_DOCUMENTOS' || $accion === 'VALIDADA_DOCUMENTALMENTE') {
    $tipoPersona = strtoupper(sfae_clean($sol['tipo_persona'] ?? 'FISICA'));

    if (!in_array($tipoPersona, ['FISICA', 'MORAL'], true)) {
      $tipoPersona = 'FISICA';
    }

    $requeridos = sfae_documentos_requeridos($tipoPersona);
    $faltantes = [];

    foreach ($requeridos as $tipoReq) {
      if (!isset($docsPorTipo[$tipoReq])) {
        $faltantes[] = $tipoReq;
      }
    }

    if ($faltantes) {
      fin_json([
        'ok' => false,
        'error' => 'Faltan documentos requeridos: ' . implode(', ', $faltantes),
        'faltantes' => $faltantes
      ], 422);
    }

    $nuevoEstatus = 'VALIDADA_DOCUMENTALMENTE';

    fin_exec($cx, "
      UPDATE pats_solicitudes_franquicia
      SET estatus = '{$nuevoEstatus}',
          user_valida = " . ($userEvento > 0 ? $userEvento : 'NULL') . ",
          observaciones_admin = " . ($observaciones !== '' ? "'" . fin_esc($cx, $observaciones) . "'" : "observaciones_admin") . ",
          updated_at = NOW()
      WHERE id_solicitud = {$idSolicitud}
      LIMIT 1
    ");

    $payload['documentos_requeridos'] = $requeridos;
    $payload['observaciones'] = $observaciones;
  }

  /* =======================================================
     OBSERVAR
  ======================================================= */
  elseif ($accion === 'OBSERVAR' || $accion === 'OBSERVADA') {
    if ($observaciones === '') {
      fin_json(['ok' => false, 'error' => 'Debes capturar observaciones'], 422);
    }

    $nuevoEstatus = 'OBSERVADA';

    fin_exec($cx, "
      UPDATE pats_solicitudes_franquicia
      SET estatus = '{$nuevoEstatus}',
          observaciones_admin = '" . fin_esc($cx, $observaciones) . "',
          updated_at = NOW()
      WHERE id_solicitud = {$idSolicitud}
      LIMIT 1
    ");

    $payload['observaciones'] = $observaciones;
  }

  /* =======================================================
     RECHAZAR
  ======================================================= */
  elseif ($accion === 'RECHAZAR' || $accion === 'RECHAZADA') {
    if ($motivoRechazo === '' && $observaciones === '') {
      fin_json(['ok' => false, 'error' => 'Debes capturar motivo de rechazo u observaciones'], 422);
    }

    $nuevoEstatus = 'RECHAZADA';
    $motivoFinal = $motivoRechazo !== '' ? $motivoRechazo : $observaciones;

    fin_exec($cx, "
      UPDATE pats_solicitudes_franquicia
      SET estatus = '{$nuevoEstatus}',
          motivo_rechazo = '" . fin_esc($cx, $motivoFinal) . "',
          observaciones_admin = " . ($observaciones !== '' ? "'" . fin_esc($cx, $observaciones) . "'" : "observaciones_admin") . ",
          updated_at = NOW()
      WHERE id_solicitud = {$idSolicitud}
      LIMIT 1
    ");

    $payload['motivo_rechazo'] = $motivoFinal;
    $payload['observaciones'] = $observaciones;
  }

  /* =======================================================
     REABRIR / REGRESAR A VALIDACIÓN
  ======================================================= */
  elseif ($accion === 'REABRIR' || $accion === 'VALIDANDO_DOCUMENTOS') {
    $nuevoEstatus = 'VALIDANDO_DOCUMENTOS';

    fin_exec($cx, "
      UPDATE pats_solicitudes_franquicia
      SET estatus = '{$nuevoEstatus}',
          motivo_rechazo = NULL,
          observaciones_admin = " . ($observaciones !== '' ? "'" . fin_esc($cx, $observaciones) . "'" : "observaciones_admin") . ",
          updated_at = NOW()
      WHERE id_solicitud = {$idSolicitud}
      LIMIT 1
    ");

    $payload['observaciones'] = $observaciones;
  }

  /* =======================================================
     SUBIR FOTOGRAFÍA
  ======================================================= */
  elseif ($accion === 'SUBIR_FOTOGRAFIA' || $accion === 'GUARDAR_FOTOGRAFIA') {
    if (empty($_FILES['fotografia']['tmp_name']) && empty($_FILES['foto']['tmp_name'])) {
      fin_json(['ok' => false, 'error' => 'Falta archivo de fotografía'], 422);
    }

    $fileKey = !empty($_FILES['fotografia']['tmp_name']) ? 'fotografia' : 'foto';
    $baseDir = dirname(__DIR__) . '/uploads/solicitudes_franquicia/' . $idSolicitud . '/fotografia';

    $meta = fin_save_upload($baseDir, $_FILES[$fileKey], false);

    sfae_desactivar_documentos_tipo($cx, $idSolicitud, 'FOTOGRAFIA_SOLICITANTE');

    sfae_insert_documento_solicitud(
      $cx,
      $idSolicitud,
      'FOTOGRAFIA_SOLICITANTE',
      $meta,
      'FIRMA_DIGITAL',
      'Fotografía del solicitante asociada al proceso de firma/autorización de franquicia',
      $userEvento
    );

    fin_exec($cx, "
      UPDATE pats_solicitudes_franquicia
      SET fotografia_path = '" . fin_esc($cx, (string)($meta['path'] ?? '')) . "',
          fotografia_nombre_original = '" . fin_esc($cx, (string)($meta['original'] ?? '')) . "',
          fotografia_mime_type = '" . fin_esc($cx, (string)($meta['mime'] ?? '')) . "',
          fotografia_size_kb = " . (int)($meta['size_kb'] ?? 0) . ",
          fecha_fotografia = NOW(),
          updated_at = NOW()
      WHERE id_solicitud = {$idSolicitud}
      LIMIT 1
    ");

    $nuevoEstatus = $estatusAnterior;

    $payload['fotografia_path'] = (string)($meta['path'] ?? '');
    $payload['fotografia_nombre_original'] = (string)($meta['original'] ?? '');
  }

  /* =======================================================
     REGISTRAR FIRMA DIGITAL
     Preparado para endpoint/integración posterior.
  ======================================================= */
  elseif ($accion === 'REGISTRAR_FIRMA_DIGITAL' || $accion === 'CONTRATO_DIGITAL_FIRMADO') {
    $contratoHtml = (string)($_POST['contrato_digital_html'] ?? '');
    $contratoHash = sfae_clean($_POST['contrato_digital_hash'] ?? '');
    $firmaNombre = sfae_clean($_POST['firma_digital_nombre'] ?? '');
    $firmaRfc = strtoupper(sfae_clean($_POST['firma_digital_rfc'] ?? ''));
    $firmaData = (string)($_POST['firma_digital_data'] ?? '');
    $firmadoAt = sfae_clean($_POST['contrato_digital_firmado_at'] ?? '');

    if ($contratoHtml === '') {
      $contratoHtml = (string)($sol['contrato_digital_html'] ?? '');
    }

    if ($contratoHtml !== '' && $contratoHash === '') {
      $contratoHash = hash('sha256', $contratoHtml);
    }

    if ($contratoHash === '') {
      fin_json(['ok' => false, 'error' => 'Falta hash del contrato digital'], 422);
    }

    if ($firmadoAt === '') {
      $firmadoAt = date('Y-m-d H:i:s');
    }

    if ($firmaNombre === '') {
      $firmaNombre = sfae_clean($sol['nombre_titular'] ?? $sol['razon_social'] ?? '');
    }

    if ($firmaRfc === '') {
      $firmaRfc = strtoupper(sfae_clean($sol['rfc'] ?? ''));
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

    fin_exec($cx, "
      UPDATE pats_solicitudes_franquicia
      SET contrato_digital_html = " . ($contratoHtml !== '' ? "'" . fin_esc($cx, $contratoHtml) . "'" : "contrato_digital_html") . ",
          contrato_digital_hash = '" . fin_esc($cx, $contratoHash) . "',
          contrato_digital_firmado_at = '" . fin_esc($cx, $firmadoAt) . "',
          firma_digital_nombre = '" . fin_esc($cx, $firmaNombre) . "',
          firma_digital_rfc = '" . fin_esc($cx, $firmaRfc) . "',
          firma_digital_ip = '" . fin_esc($cx, $ip) . "',
          firma_digital_user_agent = '" . fin_esc($cx, mb_substr($ua, 0, 255)) . "',
          firma_digital_data = " . ($firmaData !== '' ? "'" . fin_esc($cx, $firmaData) . "'" : "firma_digital_data") . ",
          estatus = 'CONTRATO_DIGITAL_FIRMADO',
          updated_at = NOW()
      WHERE id_solicitud = {$idSolicitud}
      LIMIT 1
    ");

    if ($contratoHtml !== '') {
      $baseDir = dirname(__DIR__) . '/uploads/solicitudes_franquicia/' . $idSolicitud . '/digital';
      $metaHtml = sfae_save_text_file(
        $baseDir,
        'contrato_digital_franquicia_' . $idSolicitud . '_' . substr($contratoHash, 0, 16) . '.html',
        $contratoHtml
      );

      sfae_desactivar_documentos_tipo($cx, $idSolicitud, 'CONTRATO_DIGITAL_HTML');

      sfae_insert_documento_solicitud(
        $cx,
        $idSolicitud,
        'CONTRATO_DIGITAL_HTML',
        $metaHtml,
        'FIRMA_DIGITAL',
        'Contrato digital de franquicia firmado. Hash: ' . $contratoHash,
        $userEvento
      );
    }

    $nuevoEstatus = 'CONTRATO_DIGITAL_FIRMADO';

    $payload['contrato_digital_hash'] = $contratoHash;
    $payload['contrato_digital_firmado_at'] = $firmadoAt;
    $payload['firma_digital_nombre'] = $firmaNombre;
    $payload['firma_digital_rfc'] = $firmaRfc;
  }

  /* =======================================================
     AUTORIZAR
  ======================================================= */
  elseif ($accion === 'AUTORIZAR' || $accion === 'AUTORIZADA') {
    $solActual = fin_one($cx, "
      SELECT *
      FROM pats_solicitudes_franquicia
      WHERE id_solicitud = {$idSolicitud}
        AND activo = 1
      LIMIT 1
    ");

    if (!$solActual) {
      throw new RuntimeException('Solicitud no encontrada al autorizar');
    }

    $docsActuales = sfae_get_docs_por_tipo($cx, $idSolicitud);
    $firma = sfae_validacion_firma($solActual, $docsActuales);

    if (!$firma['puede_autorizar']) {
      fin_json([
        'ok' => false,
        'error' => 'No se puede autorizar: falta contrato digital firmado con fotografía, o contrato firmado histórico.',
        'validacion' => $firma
      ], 422);
    }

    $tipoPersona = strtoupper(sfae_clean($solActual['tipo_persona'] ?? 'FISICA'));

    if (!in_array($tipoPersona, ['FISICA', 'MORAL'], true)) {
      $tipoPersona = 'FISICA';
    }

    $requeridos = sfae_documentos_requeridos($tipoPersona);
    $faltantes = [];

    foreach ($requeridos as $tipoReq) {
      if (!isset($docsActuales[$tipoReq])) {
        $faltantes[] = $tipoReq;
      }
    }

    if ($faltantes) {
      fin_json([
        'ok' => false,
        'error' => 'No se puede autorizar. Faltan documentos requeridos: ' . implode(', ', $faltantes),
        'faltantes' => $faltantes
      ], 422);
    }

    $titularidad = strtoupper(sfae_clean($solActual['titularidad_tipo'] ?? 'INDIVIDUAL'));
    $porcentajeTitular = (float)($solActual['porcentaje_titular_real'] ?? 100);
    $porcentajeAdmin = (float)($solActual['porcentaje_adminpats'] ?? 0);

    if (!in_array($titularidad, ['INDIVIDUAL', 'COMPARTIDA'], true)) {
      $titularidad = 'INDIVIDUAL';
    }

    if ($titularidad === 'INDIVIDUAL') {
      if (abs($porcentajeTitular - 100) > 0.01 || abs($porcentajeAdmin) > 0.01) {
        fin_json([
          'ok' => false,
          'error' => 'Titularidad individual inválida: el titular real debe tener 100% y ADMINPATS 0%.'
        ], 422);
      }
    }

    if ($titularidad === 'COMPARTIDA') {
      $suma = $porcentajeTitular + $porcentajeAdmin;

      if ($porcentajeTitular <= 0 || $porcentajeAdmin <= 0 || abs($suma - 100) > 0.01) {
        fin_json([
          'ok' => false,
          'error' => 'Titularidad compartida inválida: titular real + ADMINPATS deben sumar 100%.'
        ], 422);
      }
    }

    $nuevoEstatus = 'AUTORIZADA';

    fin_exec($cx, "
      UPDATE pats_solicitudes_franquicia
      SET estatus = '{$nuevoEstatus}',
          user_autoriza = " . ($userEvento > 0 ? $userEvento : 'NULL') . ",
          fecha_autorizacion = NOW(),
          observaciones_admin = " . ($observaciones !== '' ? "'" . fin_esc($cx, $observaciones) . "'" : "observaciones_admin") . ",
          updated_at = NOW()
      WHERE id_solicitud = {$idSolicitud}
      LIMIT 1
    ");

    $payload['validacion'] = $firma;
    $payload['documentos_requeridos'] = $requeridos;
    $payload['titularidad_tipo'] = $titularidad;
    $payload['porcentaje_titular_real'] = $porcentajeTitular;
    $payload['porcentaje_adminpats'] = $porcentajeAdmin;
    $payload['observaciones'] = $observaciones;
  }

  /* =======================================================
     CONTRATO FIRMADO HISTÓRICO
  ======================================================= */
  elseif ($accion === 'GUARDAR_CONTRATO_FIRMADO' || $accion === 'CONTRATO_FIRMADO_CARGADO') {
    if (empty($_FILES['contrato_firmado']['tmp_name']) && empty($_FILES['doc_contrato_firmado']['tmp_name'])) {
      fin_json(['ok' => false, 'error' => 'Falta archivo de contrato firmado'], 422);
    }

    $fileKey = !empty($_FILES['contrato_firmado']['tmp_name']) ? 'contrato_firmado' : 'doc_contrato_firmado';
    $baseDir = dirname(__DIR__) . '/uploads/solicitudes_franquicia/' . $idSolicitud . '/contrato_firmado';

    $meta = fin_save_upload($baseDir, $_FILES[$fileKey], false);

    sfae_desactivar_documentos_tipo($cx, $idSolicitud, 'CONTRATO_FIRMADO');

    sfae_insert_documento_solicitud(
      $cx,
      $idSolicitud,
      'CONTRATO_FIRMADO',
      $meta,
      'CARGA_SOLICITANTE',
      'Contrato firmado histórico cargado como archivo',
      $userEvento
    );

    $nuevoEstatus = 'CONTRATO_FIRMADO_CARGADO';

    fin_exec($cx, "
      UPDATE pats_solicitudes_franquicia
      SET contrato_firmado_path = '" . fin_esc($cx, (string)($meta['path'] ?? '')) . "',
          fecha_carga_firmado = NOW(),
          estatus = '{$nuevoEstatus}',
          updated_at = NOW()
      WHERE id_solicitud = {$idSolicitud}
      LIMIT 1
    ");

    $payload['contrato_firmado_path'] = (string)($meta['path'] ?? '');
  }

  else {
    fin_json(['ok' => false, 'error' => 'Acción no soportada: ' . $accion], 422);
  }

  sfae_insert_historial(
    $cx,
    $idSolicitud,
    $accion,
    $estatusAnterior,
    $nuevoEstatus,
    $payload,
    $userEvento > 0 ? $userEvento : null
  );

  $cx->commit();

  $item = fin_one($cx, "
    SELECT *
    FROM pats_solicitudes_franquicia
    WHERE id_solicitud = {$idSolicitud}
    LIMIT 1
  ");

  $docsFinales = sfae_get_docs_por_tipo($cx, $idSolicitud);
  $validacionFinal = sfae_validacion_firma($item ?: [], $docsFinales);

  fin_json([
    'ok' => true,
    'id_solicitud' => $idSolicitud,
    'accion' => $accion,
    'estatus_anterior' => $estatusAnterior,
    'estatus_nuevo' => $nuevoEstatus,
    'item' => $item,
    'validacion' => $validacionFinal
  ]);

} catch (Throwable $e) {
  $cx->rollback();

  fin_json([
    'ok' => false,
    'error' => $e->getMessage()
  ], 500);
}