<?php
/*
ez/patsfin/endpoints/solicitud_franquicia_convertir_alta.php
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../lib/mail_welcome.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
  fin_json(['ok' => false, 'error' => 'Método no permitido'], 405);
}

/* =========================================================
   HELPERS LOCALES
========================================================= */
function sfca_clean($v): string {
  return trim((string)($v ?? ''));
}

function sfca_bool($v): bool {
  return !empty($v) && trim((string)$v) !== '';
}

function sfca_num($v): float {
  if (function_exists('fin_num')) {
    return fin_num($v);
  }

  return round((float)$v, 2);
}

function sfca_password_temp(int $len = 10): string {
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789@#$%';
  $max = strlen($alphabet) - 1;
  $out = '';

  for ($i = 0; $i < $len; $i++) {
    $out .= $alphabet[random_int(0, $max)];
  }

  return $out;
}

function sfca_validate_mx_phone(string $telefono): bool {
  $digits = preg_replace('/\D+/', '', $telefono);
  return strlen($digits) === 10;
}

function sfca_validate_clabe(string $clabe): bool {
  $clabe = preg_replace('/\D+/', '', $clabe);

  if ($clabe === '') {
    return true;
  }

  if (strlen($clabe) !== 18) {
    return false;
  }

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

function sfca_table_exists(mysqli $cx, string $table): bool {
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

function sfca_column_exists(mysqli $cx, string $table, string $column): bool {
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

function sfca_columns(mysqli $cx, string $table): array {
  $cols = [];

  $rs = $cx->query("SHOW COLUMNS FROM `{$table}`");

  if ($rs) {
    while ($row = $rs->fetch_assoc()) {
      $field = (string)($row['Field'] ?? '');
      if ($field !== '') {
        $cols[] = $field;
      }
    }
    $rs->free();
  }

  return $cols;
}

function sfca_bind_execute(mysqli_stmt $stmt, string $types, array $values): void {
  if ($types !== '') {
    $refs = [];

    foreach ($values as $k => $v) {
      $refs[$k] = &$values[$k];
    }

    array_unshift($refs, $types);

    if (!call_user_func_array([$stmt, 'bind_param'], $refs)) {
      throw new RuntimeException('No fue posible enlazar parámetros');
    }
  }

  if (!$stmt->execute()) {
    throw new RuntimeException('No fue posible ejecutar statement: ' . $stmt->error);
  }
}

function sfca_insert_dynamic(mysqli $cx, string $table, array $items): int {
  $realCols = sfca_columns($cx, $table);

  if (!$realCols) {
    throw new RuntimeException("No fue posible leer columnas de {$table}");
  }

  $cols = [];
  $marks = [];
  $types = '';
  $values = [];

  foreach ($items as $col => $cfg) {
    if (!in_array($col, $realCols, true)) {
      continue;
    }

    $cols[] = "`{$col}`";

    if (array_key_exists('raw', $cfg)) {
      $marks[] = (string)$cfg['raw'];
      continue;
    }

    $marks[] = '?';
    $types .= (string)$cfg['type'];
    $values[] = $cfg['value'];
  }

  if (!$cols) {
    throw new RuntimeException("No hay columnas válidas para insertar en {$table}");
  }

  $sql = "
    INSERT INTO `{$table}`
    (" . implode(', ', $cols) . ")
    VALUES
    (" . implode(', ', $marks) . ")
  ";

  $stmt = $cx->prepare($sql);

  if (!$stmt) {
    throw new RuntimeException("No fue posible preparar inserción en {$table}: " . $cx->error);
  }

  sfca_bind_execute($stmt, $types, $values);

  $id = (int)$stmt->insert_id;
  $stmt->close();

  return $id;
}

function sfca_update_dynamic(mysqli $cx, string $table, string $whereSql, array $items): void {
  $realCols = sfca_columns($cx, $table);

  if (!$realCols) {
    throw new RuntimeException("No fue posible leer columnas de {$table}");
  }

  $sets = [];
  $types = '';
  $values = [];

  foreach ($items as $col => $cfg) {
    if (!in_array($col, $realCols, true)) {
      continue;
    }

    if (array_key_exists('raw', $cfg)) {
      $sets[] = "`{$col}` = " . (string)$cfg['raw'];
      continue;
    }

    $sets[] = "`{$col}` = ?";
    $types .= (string)$cfg['type'];
    $values[] = $cfg['value'];
  }

  if (!$sets) {
    return;
  }

  $sql = "
    UPDATE `{$table}`
    SET " . implode(', ', $sets) . "
    WHERE {$whereSql}
  ";

  $stmt = $cx->prepare($sql);

  if (!$stmt) {
    throw new RuntimeException("No fue posible preparar actualización en {$table}: " . $cx->error);
  }

  sfca_bind_execute($stmt, $types, $values);
  $stmt->close();
}

function sfca_generate_public_checkout_token(string $seed = ''): string {
  return hash('sha256', 'PATS|FRANQUICIA|' . $seed . '|' . bin2hex(random_bytes(16)) . '|' . microtime(true));
}

function sfca_public_checkout_link(string $token): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $baseDir = str_replace('/patsfin/endpoints', '/pats', dirname($_SERVER['SCRIPT_NAME'] ?? ''));

  return rtrim($scheme . '://' . $host . $baseDir, '/') . '/pago_pats.php?t=' . urlencode($token);
}

function sfca_documentos_requeridos(string $tipoPersona): array {
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

function sfca_insert_historial_solicitud(
  mysqli $cx,
  int $idSolicitud,
  string $eventoTipo,
  ?string $estatusAnterior,
  ?string $estatusNuevo,
  ?array $payload,
  ?int $userEvento
): void {
  if (!sfca_table_exists($cx, 'pats_solicitudes_franquicia_historial')) {
    return;
  }

  $cols = sfca_columns($cx, 'pats_solicitudes_franquicia_historial');

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

function sfca_insert_historial_global(
  mysqli $cx,
  string $entidadTipo,
  int $entidadId,
  string $eventoTipo,
  ?string $estadoAnterior,
  ?string $estadoNuevo,
  ?array $payload,
  ?int $userEvento
): void {
  if (!sfca_table_exists($cx, 'pats_historial')) {
    return;
  }

  $cols = sfca_columns($cx, 'pats_historial');

  if (!$cols) {
    return;
  }

  $payloadJson = $payload
    ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    : null;

  $items = [];

  if (in_array('entidad_tipo', $cols, true)) $items['entidad_tipo'] = ['value' => $entidadTipo, 'type' => 's'];
  if (in_array('entidad_id', $cols, true)) $items['entidad_id'] = ['value' => $entidadId, 'type' => 'i'];
  if (in_array('evento_tipo', $cols, true)) $items['evento_tipo'] = ['value' => $eventoTipo, 'type' => 's'];
  if (in_array('estado_anterior', $cols, true)) $items['estado_anterior'] = ['value' => $estadoAnterior, 'type' => 's'];
  if (in_array('estado_nuevo', $cols, true)) $items['estado_nuevo'] = ['value' => $estadoNuevo, 'type' => 's'];
  if (in_array('payload_json', $cols, true)) $items['payload_json'] = ['value' => $payloadJson, 'type' => 's'];
  if (in_array('user_evento', $cols, true)) $items['user_evento'] = ['value' => $userEvento ?? 0, 'type' => 'i'];
  if (in_array('fecha_evento', $cols, true)) $items['fecha_evento'] = ['raw' => 'NOW()'];
  if (in_array('created_at', $cols, true)) $items['created_at'] = ['raw' => 'NOW()'];

  if ($items) {
    sfca_insert_dynamic($cx, 'pats_historial', $items);
  }
}

function sfca_insert_documento_actor(
  mysqli $cx,
  string $actorTipo,
  int $actorId,
  string $tipoDocumento,
  string $archivoPath,
  ?string $archivoNombreOriginal,
  ?string $mimeType,
  int $sizeKb,
  ?string $observaciones,
  int $userAlta = 0
): void {
  if (!sfca_table_exists($cx, 'pats_documentos_actor')) {
    return;
  }

  $archivoPath = sfca_clean($archivoPath);

  if ($archivoPath === '') {
    return;
  }

  $data = [
    'actor_tipo' => ['value' => $actorTipo, 'type' => 's'],
    'actor_id' => ['value' => $actorId, 'type' => 'i'],
    'tipo_documento' => ['value' => $tipoDocumento, 'type' => 's'],
    'archivo_path' => ['value' => $archivoPath, 'type' => 's'],
    'archivo_nombre_original' => ['value' => $archivoNombreOriginal ?: basename($archivoPath), 'type' => 's'],
    'mime_type' => ['value' => $mimeType ?: '', 'type' => 's'],
    'size_kb' => ['value' => $sizeKb, 'type' => 'i'],
    'vigente' => ['value' => 1, 'type' => 'i'],
    'observaciones' => ['value' => $observaciones ?: '', 'type' => 's'],
    'user_alta' => ['value' => $userAlta, 'type' => 'i'],
    'created_at' => ['raw' => 'NOW()'],
    'updated_at' => ['raw' => 'NOW()']
  ];

  sfca_insert_dynamic($cx, 'pats_documentos_actor', $data);
}

function sfca_create_text_file(string $dirAbs, string $filename, string $content): string {
  if (!is_dir($dirAbs) && !mkdir($dirAbs, 0775, true)) {
    throw new RuntimeException('No fue posible crear carpeta de contrato digital');
  }

  $filename = preg_replace('/[^a-zA-Z0-9_\.\-]+/', '_', $filename);
  $pathAbs = rtrim($dirAbs, '/\\') . DIRECTORY_SEPARATOR . $filename;

  if (file_put_contents($pathAbs, $content) === false) {
    throw new RuntimeException('No fue posible guardar archivo de contrato digital');
  }

  @chmod($pathAbs, 0644);

  return $pathAbs;
}

function sfca_relative_from_patsfin(string $path): string {
  $path = str_replace('\\', '/', trim($path));

  if ($path === '') {
    return '';
  }

  $needle = '/patsfin/';
  $pos = strpos($path, $needle);

  if ($pos !== false) {
    return substr($path, $pos + strlen($needle));
  }

  return $path;
}

/* =========================================================
   INPUT
========================================================= */
$idSolicitud = (int)($_POST['id_solicitud'] ?? 0);
$userAlta = (int)($_SESSION['id'] ?? 0);

if ($idSolicitud <= 0) {
  fin_json(['ok' => false, 'error' => 'Falta id_solicitud válido'], 422);
}

/* =========================================================
   VALIDAR TABLAS BASE
========================================================= */
if (!sfca_table_exists($cx, 'pats_solicitudes_franquicia')) {
  fin_json(['ok' => false, 'error' => 'No existe la tabla pats_solicitudes_franquicia'], 500);
}

if (!sfca_table_exists($cx, 'pats_franquicias')) {
  fin_json(['ok' => false, 'error' => 'No existe la tabla pats_franquicias'], 500);
}

/* =========================================================
   CARGAR SOLICITUD
========================================================= */
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

$estatusActual = strtoupper(sfca_clean($sol['estatus'] ?? ''));

if (!empty($sol['id_franquicia_generada'])) {
  fin_json([
    'ok' => false,
    'error' => 'Esta solicitud ya fue convertida a alta',
    'id_franquicia' => (int)$sol['id_franquicia_generada']
  ], 409);
}

if ($estatusActual === 'RECHAZADA') {
  fin_json(['ok' => false, 'error' => 'No se puede convertir una solicitud rechazada'], 422);
}

if ($estatusActual !== 'AUTORIZADA') {
  fin_json([
    'ok' => false,
    'error' => 'La solicitud debe estar AUTORIZADA antes de convertirla a alta definitiva'
  ], 422);
}

/* =========================================================
   DOCUMENTOS DE SOLICITUD
========================================================= */
$docsSolicitud = [];

if (sfca_table_exists($cx, 'pats_solicitudes_franquicia_documentos')) {
  $docsSolicitud = fin_all($cx, "
    SELECT *
    FROM pats_solicitudes_franquicia_documentos
    WHERE id_solicitud = {$idSolicitud}
      AND vigente = 1
    ORDER BY id_documento_solicitud ASC
  ");
}

$docsPorTipo = [];

foreach ($docsSolicitud as $doc) {
  $tipo = strtoupper(sfca_clean($doc['tipo_documento'] ?? ''));

  if ($tipo !== '' && !isset($docsPorTipo[$tipo])) {
    $docsPorTipo[$tipo] = $doc;
  }
}

/* =========================================================
   VALIDACIÓN CONTRATO / FOTO
========================================================= */
$contratoDigitalFirmado = (
  sfca_bool($sol['contrato_digital_hash'] ?? '') &&
  sfca_bool($sol['contrato_digital_firmado_at'] ?? '')
);

$fotografiaCapturada = (
  sfca_bool($sol['fotografia_path'] ?? '') ||
  isset($docsPorTipo['FOTOGRAFIA_SOLICITANTE'])
);

$contratoFirmadoHistorico = (
  sfca_bool($sol['contrato_firmado_path'] ?? '') ||
  isset($docsPorTipo['CONTRATO_FIRMADO'])
);

if (!$contratoDigitalFirmado && !$contratoFirmadoHistorico) {
  fin_json([
    'ok' => false,
    'error' => 'Falta contrato firmado. Puede ser contrato digital firmado o contrato firmado histórico.'
  ], 422);
}

if ($contratoDigitalFirmado && !$fotografiaCapturada) {
  fin_json([
    'ok' => false,
    'error' => 'Falta fotografía del solicitante asociada a la firma digital'
  ], 422);
}

/* =========================================================
   NORMALIZAR DATOS
========================================================= */
$pais = sfca_clean($sol['pais'] ?? 'México');
$region = strtoupper(sfca_clean($sol['region'] ?? ''));
$zona = strtoupper(sfca_clean($sol['zona'] ?? ''));
$unidad = sfca_clean($sol['unidad'] ?? '');

$nombreComercial = sfca_clean($sol['nombre_comercial'] ?? '');
$tipoPersona = strtoupper(sfca_clean($sol['tipo_persona'] ?? 'FISICA'));
$nombreTitular = sfca_clean($sol['nombre_titular'] ?? '');
$razonSocial = sfca_clean($sol['razon_social'] ?? '');
$rfc = strtoupper(sfca_clean($sol['rfc'] ?? ''));
$telefono = preg_replace('/\D+/', '', (string)($sol['telefono'] ?? ''));
$correo = mb_strtolower(sfca_clean($sol['correo'] ?? ''));

$direccion = sfca_clean($sol['direccion'] ?? '');
$calle = sfca_clean($sol['calle'] ?? '');
$numeroExterior = sfca_clean($sol['numero_exterior'] ?? '');
$numeroInterior = sfca_clean($sol['numero_interior'] ?? '');
$colonia = sfca_clean($sol['colonia'] ?? '');
$codigoPostal = sfca_clean($sol['codigo_postal'] ?? '');
$referenciasDireccion = sfca_clean($sol['referencias_direccion'] ?? '');

if ($direccion === '') {
  $direccion = trim(implode(' ', array_filter([
    $calle,
    $numeroExterior !== '' ? 'No. ' . $numeroExterior : '',
    $numeroInterior !== '' ? 'Int. ' . $numeroInterior : '',
    $colonia !== '' ? 'Col. ' . $colonia : '',
    $zona,
    $region,
    $codigoPostal !== '' ? 'CP ' . $codigoPostal : '',
    $pais ?: 'México'
  ])));
}

$titularidadTipo = strtoupper(sfca_clean($sol['titularidad_tipo'] ?? 'INDIVIDUAL'));
$porcentajeTitularReal = (float)($sol['porcentaje_titular_real'] ?? 100);
$porcentajeAdminPats = (float)($sol['porcentaje_adminpats'] ?? 0);

$banco = sfca_clean($sol['banco'] ?? '');
$numeroCuenta = preg_replace('/\D+/', '', (string)($sol['numero_cuenta'] ?? ''));
$clabe = preg_replace('/\D+/', '', (string)($sol['clabe'] ?? ''));
$titularCuenta = sfca_clean($sol['titular_cuenta'] ?? '');

$modalidadPago = strtoupper(sfca_clean($sol['modalidad_pago'] ?? 'CONTADO'));
$valorTotal = sfca_num($sol['valor_total'] ?? 0);
$enganche = sfca_num($sol['enganche'] ?? 0);
$saldoFinanciado = sfca_num($sol['saldo_financiado'] ?? 0);
$plazoMeses = (int)($sol['plazo_meses'] ?? 0);
$periodicidad = strtoupper(sfca_clean($sol['periodicidad'] ?? 'MENSUAL'));
$fechaInicio = sfca_clean($sol['fecha_inicio'] ?? '');
$fechaPrimerVenc = sfca_clean($sol['fecha_primer_vencimiento'] ?? '');

$idGestor = (int)($sol['id_gestor'] ?? 0);
$tokenOrigen = sfca_clean($sol['token_origen'] ?? '');
$origenSolicitud = strtoupper(sfca_clean($sol['origen_solicitud'] ?? 'CORPORATIVO'));

if ($tipoPersona !== 'MORAL') {
  $tipoPersona = 'FISICA';
}

if ($titularidadTipo !== 'COMPARTIDA') {
  $titularidadTipo = 'INDIVIDUAL';
}

if ($nombreComercial === '') {
  fin_json(['ok' => false, 'error' => 'La solicitud no tiene nombre comercial de franquicia'], 422);
}

if ($nombreTitular === '') {
  fin_json(['ok' => false, 'error' => 'La solicitud no tiene titular real'], 422);
}

if ($tipoPersona === 'MORAL' && $razonSocial === '') {
  fin_json(['ok' => false, 'error' => 'La solicitud es persona moral y no tiene razón social'], 422);
}

if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
  fin_json(['ok' => false, 'error' => 'La solicitud no tiene correo válido'], 422);
}

if (!sfca_validate_mx_phone($telefono)) {
  fin_json(['ok' => false, 'error' => 'La solicitud no tiene teléfono válido'], 422);
}

if ($clabe !== '' && !sfca_validate_clabe($clabe)) {
  fin_json(['ok' => false, 'error' => 'La solicitud tiene una CLABE inválida'], 422);
}

if (!in_array($modalidadPago, ['CONTADO', 'ENGANCHE_DIFERIDO', 'DIFERIDO'], true)) {
  $modalidadPago = 'CONTADO';
}

if ($fechaInicio === '') {
  fin_json(['ok' => false, 'error' => 'La solicitud no tiene fecha de inicio'], 422);
}

if ($fechaPrimerVenc === '') {
  $fechaPrimerVenc = $fechaInicio;
}

if ($valorTotal <= 0) {
  $precioRow = fin_one($cx, "
    SELECT precio
    FROM pats_cat_precios
    WHERE LOWER(TRIM(tipo)) = 'franquicia'
      AND LOWER(TRIM(modalidad)) = 'unico'
    ORDER BY id ASC
    LIMIT 1
  ");

  $valorTotal = sfca_num($precioRow['precio'] ?? 1000000);
}

if ($valorTotal <= 0) {
  $valorTotal = 1000000;
}

if ($modalidadPago === 'CONTADO') {
  $enganche = 0;
  $saldoFinanciado = 0;
  $plazoMeses = 0;
} else {
  if ($enganche < 0) {
    $enganche = 0;
  }

  if ($enganche > $valorTotal) {
    fin_json(['ok' => false, 'error' => 'El enganche no puede ser mayor al valor total'], 422);
  }

  $saldoFinanciado = max(0, sfca_num($valorTotal - $enganche));

  if ($saldoFinanciado > 0 && $plazoMeses <= 0) {
    fin_json(['ok' => false, 'error' => 'Falta plazo válido para financiamiento'], 422);
  }
}

/* =========================================================
   VALIDAR TITULARIDAD
========================================================= */
if ($titularidadTipo === 'INDIVIDUAL') {
  if (abs($porcentajeTitularReal - 100) > 0.01 || abs($porcentajeAdminPats) > 0.01) {
    fin_json([
      'ok' => false,
      'error' => 'Titularidad individual inválida: titular real debe ser 100% y ADMINPATS 0%.'
    ], 422);
  }
}

if ($titularidadTipo === 'COMPARTIDA') {
  $suma = $porcentajeTitularReal + $porcentajeAdminPats;

  if ($porcentajeTitularReal <= 0 || $porcentajeAdminPats <= 0 || abs($suma - 100) > 0.01) {
    fin_json([
      'ok' => false,
      'error' => 'Titularidad compartida inválida: titular real + ADMINPATS deben sumar 100%.'
    ], 422);
  }
}

/* =========================================================
   VALIDAR DOCUMENTOS REQUERIDOS
========================================================= */
$docsReq = sfca_documentos_requeridos($tipoPersona);

foreach ($docsReq as $tipoReq) {
  if (!isset($docsPorTipo[$tipoReq])) {
    fin_json([
      'ok' => false,
      'error' => "Falta documento requerido en la solicitud: {$tipoReq}"
    ], 422);
  }
}

/* =========================================================
   DUPLICADOS
========================================================= */
$correoEsc = fin_esc($cx, $correo);
$rfcEsc = fin_esc($cx, $rfc);
$nombreComercialEsc = fin_esc($cx, $nombreComercial);

$checkFranq = fin_one($cx, "
  SELECT id_franquicia
  FROM pats_franquicias
  WHERE LOWER(TRIM(COALESCE(correo,''))) = '{$correoEsc}'
     OR UPPER(TRIM(COALESCE(rfc,''))) = '{$rfcEsc}'
     OR LOWER(TRIM(COALESCE(nombre_franquicia,''))) = LOWER('{$nombreComercialEsc}')
  LIMIT 1
");

if (!empty($checkFranq['id_franquicia'])) {
  fin_json(['ok' => false, 'error' => 'Ya existe una franquicia con ese correo, RFC o nombre comercial'], 409);
}

if (sfca_table_exists($cx, 'pats_users')) {
  $checkUser = fin_one($cx, "
    SELECT id
    FROM pats_users
    WHERE correo = '{$correoEsc}'
       OR usuario = '{$correoEsc}'
    LIMIT 1
  ");

  if (!empty($checkUser['id'])) {
    fin_json(['ok' => false, 'error' => 'Ya existe un usuario PATS con ese correo/usuario'], 409);
  }
}

/* =========================================================
   DATOS DE SISTEMA
========================================================= */
$tempPassword = sfca_password_temp(10);
$tempPasswordHash = password_hash($tempPassword, PASSWORD_DEFAULT);

$publicCheckoutToken = sfca_generate_public_checkout_token($correo . '|' . $nombreComercial . '|SOLICITUD|' . $idSolicitud);
$publicCheckoutActivo = 1;
$publicCheckoutUpdatedAt = date('Y-m-d H:i:s');

$codigoFranquicia = 'FRQ-' . strtoupper($unidad !== '' ? $unidad : substr($region, 0, 3)) . '-' . str_pad((string)(time() % 1000000), 6, '0', STR_PAD_LEFT);

try {
  $fechaProximaRenovacion = (new DateTime($fechaInicio))->modify('+5 years')->format('Y-m-d');
} catch (Throwable $e) {
  fin_json(['ok' => false, 'error' => 'La fecha de inicio no es válida'], 422);
}

$fechaRenovacion = $fechaInicio;

/* =========================================================
   TRANSACCIÓN
========================================================= */
$cx->begin_transaction();

try {
  /* =======================================================
     INSERT FRANQUICIA
  ======================================================= */
  $nombreFranquiciatarioPrincipal = $tipoPersona === 'MORAL'
    ? ($razonSocial ?: $nombreTitular)
    : $nombreTitular;

  $franqData = [
    'pais' => ['value' => $pais, 'type' => 's'],
    'region' => ['value' => $region, 'type' => 's'],
    'zona' => ['value' => $zona, 'type' => 's'],
    'unidad' => ['value' => $unidad, 'type' => 's'],

    'nombre_franquicia' => ['value' => $nombreComercial, 'type' => 's'],
    'nombre_comercial' => ['value' => $nombreComercial, 'type' => 's'],
    'franquiciatario' => ['value' => $nombreFranquiciatarioPrincipal, 'type' => 's'],
    'nombre_titular' => ['value' => $nombreTitular, 'type' => 's'],
    'tipo_persona' => ['value' => $tipoPersona, 'type' => 's'],
    'razon_social' => ['value' => $razonSocial, 'type' => 's'],
    'rfc' => ['value' => $rfc, 'type' => 's'],

    'telefono' => ['value' => $telefono, 'type' => 's'],
    'correo' => ['value' => $correo, 'type' => 's'],
    'direccion' => ['value' => $direccion, 'type' => 's'],
    'calle' => ['value' => $calle, 'type' => 's'],
    'numero_exterior' => ['value' => $numeroExterior, 'type' => 's'],
    'numero_interior' => ['value' => $numeroInterior, 'type' => 's'],
    'colonia' => ['value' => $colonia, 'type' => 's'],
    'codigo_postal' => ['value' => $codigoPostal, 'type' => 's'],
    'referencias_direccion' => ['value' => $referenciasDireccion, 'type' => 's'],

    'titularidad_tipo' => ['value' => $titularidadTipo, 'type' => 's'],
    'porcentaje_titular_real' => ['value' => $porcentajeTitularReal, 'type' => 'd'],
    'porcentaje_adminpats' => ['value' => $porcentajeAdminPats, 'type' => 'd'],

    'valor_franquicia' => ['value' => $valorTotal, 'type' => 'd'],
    'fecha_alta' => ['value' => $fechaInicio, 'type' => 's'],
    'fecha_inicio' => ['value' => $fechaInicio, 'type' => 's'],
    'fecha_renovacion' => ['value' => $fechaRenovacion, 'type' => 's'],
    'fecha_proxima_renovacion' => ['value' => $fechaProximaRenovacion, 'type' => 's'],
    'renovacion_estatus' => ['value' => 'vigente', 'type' => 's'],

    'banco' => ['value' => $banco, 'type' => 's'],
    'numero_cuenta' => ['value' => $numeroCuenta, 'type' => 's'],
    'clabe' => ['value' => $clabe, 'type' => 's'],
    'titular_cuenta' => ['value' => $titularCuenta, 'type' => 's'],

    'id_gestor' => ['value' => $idGestor > 0 ? $idGestor : null, 'type' => 'i'],
    'token_origen' => ['value' => $tokenOrigen, 'type' => 's'],
    'origen_solicitud' => ['value' => $origenSolicitud, 'type' => 's'],

    'codigo_franquicia' => ['value' => $codigoFranquicia, 'type' => 's'],
    'estatus' => ['value' => 'ACTIVO', 'type' => 's'],
    'activo' => ['value' => 1, 'type' => 'i'],
    'user_alta' => ['value' => $userAlta, 'type' => 'i'],

    'public_checkout_token' => ['value' => $publicCheckoutToken, 'type' => 's'],
    'public_checkout_activo' => ['value' => $publicCheckoutActivo, 'type' => 'i'],
    'public_checkout_updated_at' => ['value' => $publicCheckoutUpdatedAt, 'type' => 's'],

    'created_at' => ['raw' => 'NOW()'],
    'updated_at' => ['raw' => 'NOW()']
  ];

  $idFranquicia = sfca_insert_dynamic($cx, 'pats_franquicias', $franqData);

  /* =======================================================
     TITULARES DE FRANQUICIA
  ======================================================= */
  $idTitularReal = 0;
  $idTitularAdmin = 0;

  if (sfca_table_exists($cx, 'pats_franquicia_titulares')) {
    $titularRealData = [
      'id_franquicia' => ['value' => $idFranquicia, 'type' => 'i'],
      'tipo_titular' => ['value' => 'REAL', 'type' => 's'],
      'nombre_titular' => ['value' => $nombreTitular, 'type' => 's'],
      'nombre' => ['value' => $nombreTitular, 'type' => 's'],
      'tipo_persona' => ['value' => $tipoPersona, 'type' => 's'],
      'razon_social' => ['value' => $razonSocial, 'type' => 's'],
      'rfc' => ['value' => $rfc, 'type' => 's'],
      'telefono' => ['value' => $telefono, 'type' => 's'],
      'correo' => ['value' => $correo, 'type' => 's'],
      'direccion' => ['value' => $direccion, 'type' => 's'],
      'porcentaje_participacion' => ['value' => $porcentajeTitularReal, 'type' => 'd'],
      'porcentaje' => ['value' => $porcentajeTitularReal, 'type' => 'd'],
      'banco' => ['value' => $banco, 'type' => 's'],
      'numero_cuenta' => ['value' => $numeroCuenta, 'type' => 's'],
      'clabe' => ['value' => $clabe, 'type' => 's'],
      'titular_cuenta' => ['value' => $titularCuenta, 'type' => 's'],
      'sin_saldo_por_pagar' => ['value' => 0, 'type' => 'i'],
      'sin_parcialidades' => ['value' => 0, 'type' => 'i'],
      'activo' => ['value' => 1, 'type' => 'i'],
      'user_alta' => ['value' => $userAlta, 'type' => 'i'],
      'created_at' => ['raw' => 'NOW()'],
      'updated_at' => ['raw' => 'NOW()']
    ];

    $idTitularReal = sfca_insert_dynamic($cx, 'pats_franquicia_titulares', $titularRealData);

    if ($titularidadTipo === 'COMPARTIDA' && $porcentajeAdminPats > 0) {
      $titularAdminData = [
        'id_franquicia' => ['value' => $idFranquicia, 'type' => 'i'],
        'tipo_titular' => ['value' => 'ADMINPATS', 'type' => 's'],
        'nombre_titular' => ['value' => 'ADMINPATS', 'type' => 's'],
        'nombre' => ['value' => 'ADMINPATS', 'type' => 's'],
        'tipo_persona' => ['value' => 'MORAL', 'type' => 's'],
        'razon_social' => ['value' => 'ADMINPATS', 'type' => 's'],
        'rfc' => ['value' => '', 'type' => 's'],
        'telefono' => ['value' => '', 'type' => 's'],
        'correo' => ['value' => '', 'type' => 's'],
        'direccion' => ['value' => '', 'type' => 's'],
        'porcentaje_participacion' => ['value' => $porcentajeAdminPats, 'type' => 'd'],
        'porcentaje' => ['value' => $porcentajeAdminPats, 'type' => 'd'],
        'sin_saldo_por_pagar' => ['value' => 1, 'type' => 'i'],
        'sin_parcialidades' => ['value' => 1, 'type' => 'i'],
        'activo' => ['value' => 1, 'type' => 'i'],
        'user_alta' => ['value' => $userAlta, 'type' => 'i'],
        'created_at' => ['raw' => 'NOW()'],
        'updated_at' => ['raw' => 'NOW()']
      ];

      $idTitularAdmin = sfca_insert_dynamic($cx, 'pats_franquicia_titulares', $titularAdminData);
    }
  }

  /* =======================================================
     RELACIÓN GERENTE - FRANQUICIA
  ======================================================= */
  if ($idGestor > 0 && sfca_table_exists($cx, 'pats_gestor_franquicias')) {
    $relCheck = fin_one($cx, "
      SELECT *
      FROM pats_gestor_franquicias
      WHERE id_gestor = {$idGestor}
        AND id_franquicia = {$idFranquicia}
      LIMIT 1
    ");

    if (!$relCheck) {
      $relData = [
        'id_gestor' => ['value' => $idGestor, 'type' => 'i'],
        'id_franquicia' => ['value' => $idFranquicia, 'type' => 'i'],
        'activo' => ['value' => 1, 'type' => 'i'],
        'user_alta' => ['value' => $userAlta, 'type' => 'i'],
        'created_at' => ['raw' => 'NOW()'],
        'updated_at' => ['raw' => 'NOW()']
      ];

      sfca_insert_dynamic($cx, 'pats_gestor_franquicias', $relData);
    }
  }

  /* =======================================================
     USUARIO PATS PARA FRANQUICIATARIO
  ======================================================= */
  $idUserPats = 0;

  if (sfca_table_exists($cx, 'pats_users')) {
    $userData = [
      'app' => ['value' => 'PATS', 'type' => 's'],
      'rolapp' => ['value' => 'FRANQPATS', 'type' => 's'],
      'rol' => ['value' => 'FRANQUICIATARIO', 'type' => 's'],
      'tipo_actor' => ['value' => 'FRANQUICIATARIO', 'type' => 's'],
      'id_actor' => ['value' => $idFranquicia, 'type' => 'i'],
      'nombre' => ['value' => $nombreTitular, 'type' => 's'],
      'usuario' => ['value' => $correo, 'type' => 's'],
      'correo' => ['value' => $correo, 'type' => 's'],
      'contrasena' => ['value' => $tempPasswordHash, 'type' => 's'],
      'region' => ['value' => $region, 'type' => 's'],
      'acroregion' => ['value' => $region, 'type' => 's'],
      'unidad' => ['value' => $unidad, 'type' => 's'],
      'acronu' => ['value' => $unidad, 'type' => 's'],
      'vigente' => ['raw' => 'NULL'],
      'activo' => ['value' => 1, 'type' => 'i'],
      'must_change_password' => ['value' => 1, 'type' => 'i'],
      'password_last_change' => ['raw' => 'NULL'],
      'last_login_at' => ['raw' => 'NULL'],
      'failed_attempts' => ['value' => 0, 'type' => 'i'],
      'locked_until' => ['raw' => 'NULL'],
      'perfil' => ['raw' => 'NULL'],
      'ced' => ['raw' => 'NULL'],
      'telefono' => ['value' => $telefono, 'type' => 's'],
      'created_at' => ['raw' => 'NOW()'],
      'updated_at' => ['raw' => 'NOW()']
    ];

    $idUserPats = sfca_insert_dynamic($cx, 'pats_users', $userData);
  }

  /* =======================================================
     DOCUMENTOS DEL ACTOR
  ======================================================= */
  $baseDirFranquiciaAbs = dirname(__DIR__) . '/uploads/franquicias/' . $idFranquicia;

  foreach ($docsSolicitud as $doc) {
    $tipoDoc = strtoupper(sfca_clean($doc['tipo_documento'] ?? ''));
    $pathDoc = sfca_clean($doc['archivo_path'] ?? '');

    if ($tipoDoc === '' || $pathDoc === '') {
      continue;
    }

    sfca_insert_documento_actor(
      $cx,
      'franquicia',
      $idFranquicia,
      $tipoDoc,
      $pathDoc,
      sfca_clean($doc['archivo_nombre_original'] ?? ''),
      sfca_clean($doc['mime_type'] ?? ''),
      (int)($doc['size_kb'] ?? 0),
      'Documento migrado desde solicitud de franquicia #' . $idSolicitud,
      $userAlta
    );
  }

  $pathContratoPrincipal = '';

  if ($contratoDigitalFirmado && sfca_bool($sol['contrato_digital_html'] ?? '')) {
    $html = (string)($sol['contrato_digital_html'] ?? '');
    $hash = sfca_clean($sol['contrato_digital_hash'] ?? '');
    $filename = 'contrato_digital_franquicia_' . $idSolicitud . '_' . substr($hash, 0, 16) . '.html';

    $htmlAbs = sfca_create_text_file($baseDirFranquiciaAbs, $filename, $html);
    $htmlRel = sfca_relative_from_patsfin($htmlAbs);

    sfca_insert_documento_actor(
      $cx,
      'franquicia',
      $idFranquicia,
      'CONTRATO_DIGITAL_HTML',
      $htmlRel,
      $filename,
      'text/html',
      (int)ceil(strlen($html) / 1024),
      'Contrato digital de franquicia firmado. Hash: ' . $hash,
      $userAlta
    );

    $pathContratoPrincipal = $htmlRel;
  }

  if ($pathContratoPrincipal === '' && isset($docsPorTipo['CONTRATO_DIGITAL_PDF'])) {
    $pathContratoPrincipal = sfca_clean($docsPorTipo['CONTRATO_DIGITAL_PDF']['archivo_path'] ?? '');
  }

  if ($pathContratoPrincipal === '' && isset($docsPorTipo['CONTRATO_FIRMADO'])) {
    $pathContratoPrincipal = sfca_clean($docsPorTipo['CONTRATO_FIRMADO']['archivo_path'] ?? '');
  }

  if ($pathContratoPrincipal === '' && sfca_bool($sol['contrato_firmado_path'] ?? '')) {
    $pathContratoPrincipal = sfca_clean($sol['contrato_firmado_path'] ?? '');

    sfca_insert_documento_actor(
      $cx,
      'franquicia',
      $idFranquicia,
      'CONTRATO_FIRMADO',
      $pathContratoPrincipal,
      basename($pathContratoPrincipal),
      '',
      0,
      'Contrato firmado histórico migrado desde solicitud #' . $idSolicitud,
      $userAlta
    );
  }

  if (sfca_bool($sol['fotografia_path'] ?? '') && !isset($docsPorTipo['FOTOGRAFIA_SOLICITANTE'])) {
    sfca_insert_documento_actor(
      $cx,
      'franquicia',
      $idFranquicia,
      'FOTOGRAFIA_SOLICITANTE',
      sfca_clean($sol['fotografia_path'] ?? ''),
      sfca_clean($sol['fotografia_nombre_original'] ?? 'fotografia_solicitante'),
      sfca_clean($sol['fotografia_mime_type'] ?? ''),
      (int)($sol['fotografia_size_kb'] ?? 0),
      'Fotografía asociada a firma digital desde solicitud #' . $idSolicitud,
      $userAlta
    );
  }

  if ($pathContratoPrincipal !== '') {
    sfca_update_dynamic($cx, 'pats_franquicias', "id_franquicia = {$idFranquicia} LIMIT 1", [
      'file' => ['value' => $pathContratoPrincipal, 'type' => 's'],
      'contrato_path' => ['value' => $pathContratoPrincipal, 'type' => 's'],
      'updated_at' => ['raw' => 'NOW()']
    ]);
  }

  /* =======================================================
     CONTRATO FINANCIERO
  ======================================================= */
  $idContrato = 0;
  $numeroContrato = 'FRQ-' . str_pad((string)$idFranquicia, 6, '0', STR_PAD_LEFT);
  $estatusContrato = ($modalidadPago === 'CONTADO' || $saldoFinanciado <= 0) ? 'LIQUIDADO' : 'VIGENTE';
  $fechaFirma = $contratoDigitalFirmado
    ? substr((string)$sol['contrato_digital_firmado_at'], 0, 10)
    : $fechaInicio;

  if (sfca_table_exists($cx, 'pats_contratos_actor')) {
    $contratoData = [
      'actor_tipo' => ['value' => 'franquicia', 'type' => 's'],
      'actor_id' => ['value' => $idFranquicia, 'type' => 'i'],
      'tipo_contrato' => ['value' => 'alta_franquicia', 'type' => 's'],
      'numero_contrato' => ['value' => $numeroContrato, 'type' => 's'],
      'modalidad_pago' => ['value' => $modalidadPago, 'type' => 's'],
      'valor_total' => ['value' => $valorTotal, 'type' => 'd'],
      'enganche' => ['value' => $enganche, 'type' => 'd'],
      'saldo_financiado' => ['value' => $saldoFinanciado, 'type' => 'd'],
      'plazo_meses' => ['value' => $plazoMeses, 'type' => 'i'],
      'periodicidad' => ['value' => $periodicidad, 'type' => 's'],
      'fecha_inicio' => ['value' => $fechaInicio, 'type' => 's'],
      'fecha_primer_vencimiento' => ['value' => $fechaPrimerVenc, 'type' => 's'],
      'fecha_firma' => ['value' => $fechaFirma, 'type' => 's'],
      'fecha_termino' => ['raw' => 'NULL'],
      'moneda' => ['value' => 'MXN', 'type' => 's'],
      'tasa_recargo' => ['value' => 0, 'type' => 'd'],
      'estatus' => ['value' => $estatusContrato, 'type' => 's'],
      'motivo_cancelacion' => ['raw' => 'NULL'],
      'observaciones' => [
        'value' => $contratoDigitalFirmado
          ? 'Contrato generado desde solicitud externa de franquicia con firma digital. Hash: ' . sfca_clean($sol['contrato_digital_hash'] ?? '')
          : 'Contrato generado desde solicitud externa de franquicia con contrato firmado histórico.',
        'type' => 's'
      ],
      'activo' => ['value' => 1, 'type' => 'i'],
      'user_alta' => ['value' => $userAlta, 'type' => 'i'],
      'created_at' => ['raw' => 'NOW()'],
      'updated_at' => ['raw' => 'NOW()']
    ];

    $idContrato = sfca_insert_dynamic($cx, 'pats_contratos_actor', $contratoData);

    if (
      $modalidadPago !== 'CONTADO'
      && $saldoFinanciado > 0
      && $plazoMeses > 0
      && function_exists('fin_create_parcialidades')
    ) {
      fin_create_parcialidades($cx, $idContrato, $saldoFinanciado, $plazoMeses, $periodicidad, $fechaPrimerVenc);
    }

    $montoPagoInicial = 0.0;

    if ($modalidadPago === 'CONTADO') {
      $montoPagoInicial = $valorTotal;
    } elseif ($enganche > 0) {
      $montoPagoInicial = $enganche;
    }

    if ($montoPagoInicial > 0 && function_exists('fin_registrar_pago_inicial_contrato')) {
      fin_registrar_pago_inicial_contrato(
        $cx,
        $idContrato,
        $montoPagoInicial,
        $fechaInicio,
        'PAGO_INICIAL',
        'Conversión solicitud franquicia',
        'Pago inicial registrado al convertir solicitud externa de franquicia'
      );
    }
  }

  /* =======================================================
     RENOVACIÓN / VIGENCIA
  ======================================================= */
  if (sfca_table_exists($cx, 'pats_renovaciones_franquicia')) {
    $renovData = [
      'id_franquicia' => ['value' => $idFranquicia, 'type' => 'i'],
      'fecha_inicio_vigencia' => ['value' => $fechaInicio, 'type' => 's'],
      'fecha_fin_vigencia' => ['value' => $fechaProximaRenovacion, 'type' => 's'],
      'monto_renovacion' => ['value' => $valorTotal, 'type' => 'd'],
      'estatus' => ['value' => 'vigente', 'type' => 's'],
      'fecha_confirmacion' => ['raw' => 'NOW()'],
      'observaciones' => ['value' => 'Conversión desde solicitud externa de franquicia #' . $idSolicitud, 'type' => 's'],
      'created_at' => ['raw' => 'NOW()'],
      'updated_at' => ['raw' => 'NOW()']
    ];

    sfca_insert_dynamic($cx, 'pats_renovaciones_franquicia', $renovData);
  }

  /* =======================================================
     ACTUALIZAR SOLICITUD
  ======================================================= */
  fin_exec($cx, "
    UPDATE pats_solicitudes_franquicia
    SET
      id_franquicia_generada = {$idFranquicia},
      estatus = 'CONVERTIDA_ALTA',
      fecha_conversion_alta = NOW(),
      updated_at = NOW()
    WHERE id_solicitud = {$idSolicitud}
    LIMIT 1
  ");

  sfca_insert_historial_solicitud(
    $cx,
    $idSolicitud,
    'CONVERSION_ALTA',
    $estatusActual,
    'CONVERTIDA_ALTA',
    [
      'id_franquicia' => $idFranquicia,
      'id_titular_real' => $idTitularReal > 0 ? $idTitularReal : null,
      'id_titular_adminpats' => $idTitularAdmin > 0 ? $idTitularAdmin : null,
      'id_user_pats' => $idUserPats > 0 ? $idUserPats : null,
      'id_contrato' => $idContrato > 0 ? $idContrato : null,
      'numero_contrato' => $numeroContrato,
      'contrato_digital_firmado' => $contratoDigitalFirmado,
      'contrato_firmado_historico' => $contratoFirmadoHistorico,
      'fotografia_capturada' => $fotografiaCapturada,
      'titularidad_tipo' => $titularidadTipo,
      'porcentaje_titular_real' => $porcentajeTitularReal,
      'porcentaje_adminpats' => $porcentajeAdminPats
    ],
    $userAlta > 0 ? $userAlta : null
  );

  sfca_insert_historial_global(
    $cx,
    'franquicia',
    $idFranquicia,
    'alta_desde_solicitud',
    null,
    'ACTIVO',
    [
      'id_solicitud' => $idSolicitud,
      'id_gestor' => $idGestor > 0 ? $idGestor : null,
      'valor_franquicia' => $valorTotal,
      'numero_contrato' => $numeroContrato,
      'tipo_persona' => $tipoPersona,
      'titularidad_tipo' => $titularidadTipo,
      'porcentaje_titular_real' => $porcentajeTitularReal,
      'porcentaje_adminpats' => $porcentajeAdminPats,
      'contrato_digital_hash' => sfca_clean($sol['contrato_digital_hash'] ?? ''),
      'public_checkout_token' => $publicCheckoutToken
    ],
    $userAlta > 0 ? $userAlta : null
  );

  /* =======================================================
     COMISIONES
     Si tu bootstrap ya soporta liberación por contrato, se invoca.
     Si no, no rompe.
  ======================================================= */
  $liberacionComisiones = null;

  if ($idContrato > 0 && function_exists('fin_liberar_comisiones_proporcionales_por_contrato')) {
    $liberacionComisiones = fin_liberar_comisiones_proporcionales_por_contrato(
      $cx,
      $idContrato,
      'CONVERSION_SOLICITUD_FRANQUICIA',
      $userAlta > 0 ? $userAlta : null
    );
  }

  $cx->commit();

  $emailSent = false;

  /*
  |--------------------------------------------------------------------------
  | ENVÍO DE BIENVENIDA - FRANQUICIATARIO
  |--------------------------------------------------------------------------
  | Activar cuando SMTP/PHPMailer esté listo.
  |--------------------------------------------------------------------------
  if (!empty($correo) && !empty($tempPassword) && function_exists('pats_send_welcome_email')) {
    $mailFranquiciatario = pats_send_welcome_email([
      'tipo_usuario' => 'Franquiciatario',
      'rol' => 'FRANQUICIATARIO',
      'nombre' => $nombreTitular,
      'correo' => $correo,
      'usuario' => $correo,
      'password_temp' => $tempPassword,
      'login_url' => PATS_LOGIN_URL
    ]);

    $emailSent = !empty($mailFranquiciatario['ok']);
  }
  */

  fin_json([
    'ok' => true,

    'id_solicitud' => $idSolicitud,
    'id_franquicia' => $idFranquicia,
    'id_titular_real' => $idTitularReal > 0 ? $idTitularReal : null,
    'id_titular_adminpats' => $idTitularAdmin > 0 ? $idTitularAdmin : null,
    'id_user_pats' => $idUserPats > 0 ? $idUserPats : null,
    'id_contrato' => $idContrato > 0 ? $idContrato : null,
    'numero_contrato' => $numeroContrato,
    'codigo_franquicia' => $codigoFranquicia,

    'nombre_comercial' => $nombreComercial,
    'nombre_titular' => $nombreTitular,
    'tipo_persona' => $tipoPersona,
    'razon_social' => $razonSocial,

    'titularidad_tipo' => $titularidadTipo,
    'porcentaje_titular_real' => $porcentajeTitularReal,
    'porcentaje_adminpats' => $porcentajeAdminPats,

    'id_gestor' => $idGestor > 0 ? $idGestor : null,
    'tiene_gestor' => $idGestor > 0,

    'contrato_digital_firmado' => $contratoDigitalFirmado,
    'contrato_firmado_historico' => $contratoFirmadoHistorico,
    'fotografia_capturada' => $fotografiaCapturada,

    'valor_total' => $valorTotal,
    'enganche' => $enganche,
    'saldo_financiado' => $saldoFinanciado,

    'public_checkout_token' => $publicCheckoutToken,
    'public_checkout_link' => sfca_public_checkout_link($publicCheckoutToken),

    'password_temp' => $tempPassword,
    'email_sent' => $emailSent,

    'liberacion_comisiones' => $liberacionComisiones
  ]);

} catch (Throwable $e) {
  $cx->rollback();

  fin_json([
    'ok' => false,
    'error' => $e->getMessage()
  ], 500);
}