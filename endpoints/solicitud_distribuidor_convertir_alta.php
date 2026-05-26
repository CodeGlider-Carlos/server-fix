<?php
/*
ez/patsfin/endpoints/solicitud_distribuidor_convertir_alta.php

Versión corregida para estructura nueva:
- pats_solicitudes_distribuidor NO contiene contrato_digital_* / firma_digital_* / fotografia_*.
- Firma/foto/contrato digital se toman desde pats_solicitudes_distribuidor_documentos.
- Correo REAL de bienvenida activo después del commit usando pats_smtp_send_mail().
- Basado en el patrón real de public_checkout_generar_orden.php: config/mail.php + lib/pats_mailer.php + envío posterior al COMMIT.
*/
require_once __DIR__ . '/bootstrap.php';

/*
  Correo REAL de bienvenida.
  Carga config SMTP y mailer de PATS. El envío se intenta después del commit;
  si SMTP falla, la conversión no se revierte y se devuelve email_error.
*/
$__sdcaMailConfigs = [
  __DIR__ . '/../../pats/config/mail.php',
  __DIR__ . '/../config/mail.php',
];
foreach ($__sdcaMailConfigs as $__sdcaMailConfig) {
  if (is_file($__sdcaMailConfig)) {
    require_once $__sdcaMailConfig;
    break;
  }
}

$__sdcaMailerLibs = [
  __DIR__ . '/../../pats/lib/pats_mailer.php',
  __DIR__ . '/../lib/pats_mailer.php',
];
foreach ($__sdcaMailerLibs as $__sdcaMailerLib) {
  if (is_file($__sdcaMailerLib)) {
    require_once $__sdcaMailerLib;
    break;
  }
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
  fin_json(['ok' => false, 'error' => 'Método no permitido'], 405);
}

/* =========================================================
   HELPERS LOCALES
========================================================= */
function sdca_clean($v): string {
  return trim((string)($v ?? ''));
}

function sdca_bool($v): bool {
  return !empty($v) && trim((string)$v) !== '';
}

function sdca_password_temp(int $len = 10): string {
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789@#$%';
  $max = strlen($alphabet) - 1;
  $out = '';

  for ($i = 0; $i < $len; $i++) {
    $out .= $alphabet[random_int(0, $max)];
  }

  return $out;
}

function sdca_validate_mx_phone(string $telefono): bool {
  $digits = preg_replace('/\D+/', '', $telefono);
  return strlen($digits) === 10;
}

function sdca_validate_clabe(string $clabe): bool {
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

function sdca_table_exists(mysqli $cx, string $table): bool {
  $tableEsc = fin_esc($cx, $table);
  $rs = $cx->query("SHOW TABLES LIKE '{$tableEsc}'");

  if (!$rs) {
    return false;
  }

  $exists = $rs->num_rows > 0;
  $rs->free();

  return $exists;
}

function sdca_column_exists(mysqli $cx, string $table, string $column): bool {
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

function sdca_bind_execute(mysqli_stmt $stmt, string $types, array $values): void {
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

function sdca_insert_dynamic(mysqli $cx, string $table, array $items): int {
  $cols = [];
  $marks = [];
  $types = '';
  $values = [];

  foreach ($items as $col => $cfg) {
    $cols[] = "`{$col}`";

    if (array_key_exists('raw', $cfg)) {
      $marks[] = (string)$cfg['raw'];
      continue;
    }

    $marks[] = '?';
    $types .= (string)$cfg['type'];
    $values[] = $cfg['value'];
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

  sdca_bind_execute($stmt, $types, $values);

  $id = (int)$stmt->insert_id;
  $stmt->close();

  return $id;
}

function sdca_generate_public_checkout_token(string $seed = ''): string {
  return hash('sha256', 'PATS|' . $seed . '|' . bin2hex(random_bytes(16)) . '|' . microtime(true));
}

function sdca_public_checkout_link(string $token): string {
  return 'https://pasaporteatusalud.com/landing_pats.php?t=' . urlencode($token);
}

function sdca_distribuidor_login_url(): string {
  if (defined('PATS_DISTRIBUIDOR_LOGIN_URL') && trim((string)PATS_DISTRIBUIDOR_LOGIN_URL) !== '') {
    return trim((string)PATS_DISTRIBUIDOR_LOGIN_URL);
  }

  if (defined('PATS_LOGIN_URL') && trim((string)PATS_LOGIN_URL) !== '') {
    return trim((string)PATS_LOGIN_URL);
  }

  return 'https://50d.com.mx/50D/EZHS/';
}

function sdca_send_distribuidor_bienvenida_email(array $d): array {
  $to = sdca_clean($d['to'] ?? '');

  if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    return [
      'ok' => false,
      'error' => 'Correo destino inválido o vacío.'
    ];
  }

  $nombre = sdca_clean($d['nombre'] ?? 'Distribuidor');
  $usuario = sdca_clean($d['usuario'] ?? $to);
  $passwordTemp = (string)($d['password_temp'] ?? '');
  $loginUrl = sdca_clean($d['login_url'] ?? sdca_distribuidor_login_url());
  $codigoDistribuidor = sdca_clean($d['codigo_distribuidor'] ?? '');
  $nombreFranquicia = sdca_clean($d['nombre_franquicia'] ?? '');
  $region = sdca_clean($d['region'] ?? '');
  $zona = sdca_clean($d['zona'] ?? '');
  $unidad = sdca_clean($d['unidad'] ?? '');
  $linkPats = sdca_clean($d['public_checkout_link'] ?? '');

  $safeNombre = htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8');
  $safeUsuario = htmlspecialchars($usuario, ENT_QUOTES, 'UTF-8');
  $safePass = htmlspecialchars($passwordTemp, ENT_QUOTES, 'UTF-8');
  $safeLogin = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');
  $safeCodigo = htmlspecialchars($codigoDistribuidor, ENT_QUOTES, 'UTF-8');
  $safeFranquicia = htmlspecialchars($nombreFranquicia, ENT_QUOTES, 'UTF-8');
  $safeRegion = htmlspecialchars($region, ENT_QUOTES, 'UTF-8');
  $safeZona = htmlspecialchars($zona, ENT_QUOTES, 'UTF-8');
  $safeUnidad = htmlspecialchars($unidad, ENT_QUOTES, 'UTF-8');
  $safeLinkPats = htmlspecialchars($linkPats, ENT_QUOTES, 'UTF-8');

  $subject = 'Bienvenido a PATS · Acceso de distribuidor';

  $linkPatsHtml = $linkPats !== ''
    ? '<tr><td style="padding:10px;border-bottom:1px solid #eef2f8;color:#60708f;">Link público PATS</td><td style="padding:10px;border-bottom:1px solid #eef2f8;font-weight:800;word-break:break-all;"><a href="' . $safeLinkPats . '" style="color:#243c9c;text-decoration:none;">' . $safeLinkPats . '</a></td></tr>'
    : '';

  $html = '<!doctype html>
<html lang="es">
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#f3f7ff;font-family:Arial,Helvetica,sans-serif;color:#102a56;">
  <div style="max-width:660px;margin:0 auto;padding:28px 16px;">
    <div style="background:linear-gradient(135deg,#071a3d,#243c9c 55%,#6d5dfc);border-radius:26px 26px 0 0;padding:26px;color:#fff;">
      <div style="font-size:12px;font-weight:800;letter-spacing:.14em;text-transform:uppercase;opacity:.82;">Pasaporte a tu Salud</div>
      <h1 style="margin:10px 0 0;font-size:28px;line-height:1.08;">Tu acceso de distribuidor está listo</h1>
      <p style="margin:12px 0 0;color:#e8f0ff;line-height:1.55;">Tu solicitud fue autorizada y convertida a alta definitiva.</p>
    </div>

    <div style="background:#fff;border:1px solid #dce6f5;border-top:0;border-radius:0 0 26px 26px;padding:24px;">
      <p style="margin:0 0 16px;font-size:16px;line-height:1.55;">Hola <strong>' . $safeNombre . '</strong>, estos son tus datos de acceso:</p>

      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:0;">
        <tr><td style="padding:10px;border-bottom:1px solid #eef2f8;color:#60708f;">Usuario</td><td style="padding:10px;border-bottom:1px solid #eef2f8;font-weight:800;">' . $safeUsuario . '</td></tr>
        <tr><td style="padding:10px;border-bottom:1px solid #eef2f8;color:#60708f;">Contraseña temporal</td><td style="padding:10px;border-bottom:1px solid #eef2f8;font-weight:900;font-size:16px;letter-spacing:.04em;">' . $safePass . '</td></tr>
        <tr><td style="padding:10px;border-bottom:1px solid #eef2f8;color:#60708f;">Código distribuidor</td><td style="padding:10px;border-bottom:1px solid #eef2f8;font-weight:800;">' . $safeCodigo . '</td></tr>
        <tr><td style="padding:10px;border-bottom:1px solid #eef2f8;color:#60708f;">Franquicia</td><td style="padding:10px;border-bottom:1px solid #eef2f8;font-weight:800;">' . $safeFranquicia . '</td></tr>
        <tr><td style="padding:10px;border-bottom:1px solid #eef2f8;color:#60708f;">Región / Zona / Unidad</td><td style="padding:10px;border-bottom:1px solid #eef2f8;font-weight:800;">' . $safeRegion . ' / ' . $safeZona . ' / ' . $safeUnidad . '</td></tr>
        ' . $linkPatsHtml . '
      </table>

      <p style="margin:24px 0 0;">
        <a href="' . $safeLogin . '" style="display:inline-block;background:#0b2340;color:#fff;text-decoration:none;font-weight:800;padding:13px 18px;border-radius:14px;">
          Entrar al sistema
        </a>
      </p>

      <p style="margin:18px 0 0;color:#60708f;font-size:12px;line-height:1.45;">
        Por seguridad, cambia tu contraseña temporal al iniciar sesión. Si tú no solicitaste este acceso, contacta a ADMINPATS.
      </p>
    </div>
  </div>
</body>
</html>';

  $text = "Bienvenido a PATS\n\n"
    . "Nombre: {$nombre}\n"
    . "Usuario: {$usuario}\n"
    . "Contraseña temporal: {$passwordTemp}\n"
    . "Código distribuidor: {$codigoDistribuidor}\n"
    . "Franquicia: {$nombreFranquicia}\n"
    . "Región/Zona/Unidad: {$region} / {$zona} / {$unidad}\n"
    . "Acceso: {$loginUrl}\n";

  if ($linkPats !== '') {
    $text .= "Link público PATS: {$linkPats}\n";
  }

  if (!function_exists('pats_smtp_send_mail')) {
    return [
      'ok' => false,
      'error' => 'No está disponible pats_smtp_send_mail. Revisa ez/pats/lib/pats_mailer.php y ez/pats/config/mail.php.'
    ];
  }

  return [
    'ok' => pats_smtp_send_mail($to, $subject, $html, $text),
    'error' => ''
  ];
}

function sdca_insert_historial_solicitud(
  mysqli $cx,
  int $idSolicitud,
  string $eventoTipo,
  ?string $estatusAnterior,
  ?string $estatusNuevo,
  ?array $payload,
  ?int $userEvento
): void {
  if (!sdca_table_exists($cx, 'pats_solicitudes_distribuidor_historial')) {
    return;
  }

  $payloadJson = $payload
    ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    : null;

  $stmt = $cx->prepare("
    INSERT INTO pats_solicitudes_distribuidor_historial
    (
      id_solicitud,
      evento_tipo,
      estatus_anterior,
      estatus_nuevo,
      payload_json,
      user_evento,
      fecha_evento,
      created_at
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

function sdca_insert_historial_global(
  mysqli $cx,
  string $entidadTipo,
  int $entidadId,
  string $eventoTipo,
  ?string $estadoAnterior,
  ?string $estadoNuevo,
  ?array $payload,
  ?int $userEvento
): void {
  if (!sdca_table_exists($cx, 'pats_historial')) {
    return;
  }

  $payloadJson = $payload
    ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    : null;

  $stmt = $cx->prepare("
    INSERT INTO pats_historial
    (
      entidad_tipo,
      entidad_id,
      evento_tipo,
      estado_anterior,
      estado_nuevo,
      payload_json,
      user_evento,
      fecha_evento,
      created_at
    )
    VALUES
    (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
  ");

  if (!$stmt) {
    throw new RuntimeException('No fue posible preparar historial global');
  }

  $stmt->bind_param(
    'sissssi',
    $entidadTipo,
    $entidadId,
    $eventoTipo,
    $estadoAnterior,
    $estadoNuevo,
    $payloadJson,
    $userEvento
  );

  if (!$stmt->execute()) {
    throw new RuntimeException('No fue posible guardar historial global: ' . $stmt->error);
  }

  $stmt->close();
}

function sdca_insert_documento_actor(
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
  $archivoPath = sdca_clean($archivoPath);

  if ($archivoPath === '') {
    return;
  }

  $stmt = $cx->prepare("
    INSERT INTO pats_documentos_actor
    (
      actor_tipo,
      actor_id,
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
    (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, NOW(), NOW())
  ");

  if (!$stmt) {
    throw new RuntimeException('No fue posible preparar inserción de documento actor');
  }

  $archivoNombreOriginal = $archivoNombreOriginal ?: basename($archivoPath);
  $mimeType = $mimeType ?: '';
  $observaciones = $observaciones ?: '';

  $stmt->bind_param(
    'sissssisi',
    $actorTipo,
    $actorId,
    $tipoDocumento,
    $archivoPath,
    $archivoNombreOriginal,
    $mimeType,
    $sizeKb,
    $observaciones,
    $userAlta
  );

  if (!$stmt->execute()) {
    throw new RuntimeException('No fue posible guardar documento actor: ' . $stmt->error);
  }

  $stmt->close();
}

function sdca_doc_path(?array $doc): string {
  return is_array($doc) ? sdca_clean($doc['archivo_path'] ?? '') : '';
}

function sdca_doc_name(?array $doc, string $fallback = ''): string {
  return is_array($doc) ? sdca_clean($doc['archivo_nombre_original'] ?? $fallback) : $fallback;
}

function sdca_doc_mime(?array $doc): string {
  return is_array($doc) ? sdca_clean($doc['mime_type'] ?? '') : '';
}

function sdca_doc_size(?array $doc): int {
  return is_array($doc) ? (int)($doc['size_kb'] ?? 0) : 0;
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
   CARGA SOLICITUD
========================================================= */
$sol = fin_one($cx, "
  SELECT *
  FROM pats_solicitudes_distribuidor
  WHERE id_solicitud = {$idSolicitud}
    AND activo = 1
  LIMIT 1
");

if (!$sol) {
  fin_json(['ok' => false, 'error' => 'Solicitud no encontrada'], 404);
}

$estatusActual = strtoupper(sdca_clean($sol['estatus'] ?? ''));

if (!empty($sol['id_distribuidor_generado'])) {
  fin_json([
    'ok' => false,
    'error' => 'Esta solicitud ya fue convertida a alta',
    'id_distribuidor' => (int)$sol['id_distribuidor_generado']
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
   Estructura nueva real: no existe origen_documento/hash_archivo.
========================================================= */
$docsSolicitud = fin_all($cx, "
  SELECT
    id_documento_solicitud,
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
  FROM pats_solicitudes_distribuidor_documentos
  WHERE id_solicitud = {$idSolicitud}
    AND vigente = 1
  ORDER BY id_documento_solicitud ASC
");

$docsPorTipo = [];

foreach ($docsSolicitud as $doc) {
  $tipo = strtoupper(sdca_clean($doc['tipo_documento'] ?? ''));

  if ($tipo !== '' && !isset($docsPorTipo[$tipo])) {
    $docsPorTipo[$tipo] = $doc;
  }
}

$docContratoDigitalPdf = $docsPorTipo['CONTRATO_DIGITAL_PDF'] ?? null;
$docContratoDigitalHtml = $docsPorTipo['CONTRATO_DIGITAL_HTML'] ?? null;
$docContratoFirmado = $docsPorTipo['CONTRATO_FIRMADO'] ?? null;
$docFoto = $docsPorTipo['FOTOGRAFIA_SOLICITANTE'] ?? null;
$docFirmaImagen = $docsPorTipo['FIRMA_DIGITAL_IMAGEN'] ?? null;

/* =========================================================
   VALIDACIÓN DE CONTRATO DIGITAL / HISTÓRICO
========================================================= */
$contratoDigitalFirmado = (
  sdca_doc_path($docContratoDigitalPdf) !== '' ||
  sdca_doc_path($docFirmaImagen) !== ''
);

$fotografiaCapturada = (sdca_doc_path($docFoto) !== '');

$contratoFirmadoHistorico = (
  sdca_bool($sol['contrato_firmado_path'] ?? '') ||
  sdca_doc_path($docContratoFirmado) !== ''
);

if (!$contratoDigitalFirmado && !$contratoFirmadoHistorico) {
  fin_json([
    'ok' => false,
    'error' => 'Falta contrato firmado. Puede ser contrato digital/firma digital o contrato firmado histórico.'
  ], 422);
}

if ($contratoDigitalFirmado && !$fotografiaCapturada) {
  fin_json([
    'ok' => false,
    'error' => 'Falta fotografía del solicitante asociada a la firma digital'
  ], 422);
}

/* =========================================================
   DATOS BASE
========================================================= */
$idFranquicia = (int)($sol['id_franquicia'] ?? 0);

if ($idFranquicia <= 0) {
  fin_json(['ok' => false, 'error' => 'La solicitud no tiene franquicia asociada'], 422);
}

$franquicia = fin_one($cx, "
  SELECT *
  FROM pats_franquicias
  WHERE id_franquicia = {$idFranquicia}
    AND activo = 1
  LIMIT 1
");

if (!$franquicia) {
  fin_json(['ok' => false, 'error' => 'La franquicia asociada no existe o está inactiva'], 404);
}

$pais = sdca_clean($sol['pais'] ?? 'México');
$region = strtoupper(sdca_clean($sol['region'] ?? ''));
$zona = strtoupper(sdca_clean($sol['zona'] ?? ''));
$unidad = sdca_clean($sol['unidad'] ?? '');

$nombreBase = sdca_clean($sol['nombre'] ?? '');
$apPat = sdca_clean($sol['apellido_paterno'] ?? '');
$apMat = sdca_clean($sol['apellido_materno'] ?? '');
$nombre = trim($nombreBase . ' ' . $apPat . ' ' . $apMat);
if ($nombre === '') {
  $nombre = $nombreBase;
}

$tipoPersona = strtoupper(sdca_clean($sol['tipo_persona'] ?? 'FISICA'));
$razonSocial = sdca_clean($sol['razon_social'] ?? '');
$rfc = strtoupper(sdca_clean($sol['rfc'] ?? ''));
$direccion = sdca_clean($sol['direccion'] ?? '');
$telefono = preg_replace('/\D+/', '', (string)($sol['telefono'] ?? ''));
$correo = mb_strtolower(sdca_clean($sol['correo'] ?? ''));

$banco = sdca_clean($sol['banco'] ?? '');
$numeroCuenta = preg_replace('/\D+/', '', (string)($sol['numero_cuenta'] ?? ''));
$clabe = preg_replace('/\D+/', '', (string)($sol['clabe'] ?? ''));
$titularCuenta = sdca_clean($sol['titular_cuenta'] ?? '');

$modalidadPago = strtoupper(sdca_clean($sol['modalidad_pago'] ?? 'CONTADO'));
$valorTotal = fin_num($sol['valor_total'] ?? 0);
$enganche = fin_num($sol['enganche'] ?? 0);
$saldoFinanciado = fin_num($sol['saldo_financiado'] ?? 0);
$plazoMeses = (int)($sol['plazo_meses'] ?? 0);
$periodicidad = strtoupper(sdca_clean($sol['periodicidad'] ?? 'MENSUAL'));
$fechaInicio = sdca_clean($sol['fecha_inicio'] ?? '');
$fechaPrimerVenc = sdca_clean($sol['fecha_primer_vencimiento'] ?? '');

if ($fechaPrimerVenc === '') {
  $fechaPrimerVenc = $fechaInicio;
}

if (!in_array($tipoPersona, ['FISICA', 'MORAL'], true)) {
  $tipoPersona = 'FISICA';
}

if ($tipoPersona === 'MORAL' && $razonSocial === '') {
  fin_json(['ok' => false, 'error' => 'La solicitud es persona moral y no tiene razón social'], 422);
}

if ($nombre === '') {
  fin_json(['ok' => false, 'error' => 'La solicitud no tiene nombre de distribuidor'], 422);
}

if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
  fin_json(['ok' => false, 'error' => 'La solicitud no tiene correo válido'], 422);
}

if (!sdca_validate_mx_phone($telefono)) {
  fin_json(['ok' => false, 'error' => 'La solicitud no tiene teléfono válido'], 422);
}

if ($clabe !== '' && !sdca_validate_clabe($clabe)) {
  fin_json(['ok' => false, 'error' => 'La solicitud tiene una CLABE inválida'], 422);
}

if (!in_array($modalidadPago, ['CONTADO', 'ENGANCHE_DIFERIDO', 'DIFERIDO'], true)) {
  $modalidadPago = 'CONTADO';
}

if ($fechaInicio === '') {
  fin_json(['ok' => false, 'error' => 'La solicitud no tiene fecha de inicio'], 422);
}

/* =========================================================
   PRECIO / VALOR
========================================================= */
$regionFranq = strtoupper(sdca_clean($franquicia['region'] ?? ''));
$ambitoRegionPrecio = ($regionFranq === $region) ? 'misma_region' : 'fuera_region';

if ($valorTotal <= 0) {
  $rowPrecio = fin_one($cx, "
    SELECT precio
    FROM pats_cat_precios
    WHERE LOWER(TRIM(tipo)) = 'distribucion'
      AND LOWER(TRIM(modalidad)) = '" . fin_esc($cx, $ambitoRegionPrecio) . "'
    ORDER BY id ASC
    LIMIT 1
  ");

  $valorTotal = fin_num($rowPrecio['precio'] ?? 20000);
}

if ($valorTotal <= 0) {
  fin_json(['ok' => false, 'error' => 'No se pudo determinar el valor de distribución'], 422);
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

  $saldoFinanciado = max(0, fin_num($valorTotal - $enganche));

  if ($saldoFinanciado > 0 && $plazoMeses <= 0) {
    fin_json(['ok' => false, 'error' => 'Falta plazo válido para financiamiento'], 422);
  }
}

/* =========================================================
   DUPLICADOS
========================================================= */
$checkDist = fin_one($cx, "
  SELECT id_distribuidor
  FROM pats_distribuidores
  WHERE correo = '" . fin_esc($cx, $correo) . "'
  LIMIT 1
");

if (!empty($checkDist['id_distribuidor'])) {
  fin_json(['ok' => false, 'error' => 'Ya existe un distribuidor con ese correo'], 409);
}

$checkUser = fin_one($cx, "
  SELECT id
  FROM pats_users
  WHERE correo = '" . fin_esc($cx, $correo) . "'
     OR usuario = '" . fin_esc($cx, $correo) . "'
  LIMIT 1
");

if (!empty($checkUser['id'])) {
  fin_json(['ok' => false, 'error' => 'Ya existe un usuario PATS con ese correo/usuario'], 409);
}

/* =========================================================
   DOCUMENTOS REQUERIDOS
========================================================= */
$docsReq = [
  'INE',
  'CEDULA_FISCAL',
  'COMPROBANTE_DOMICILIO',
  'CARATULA_BANCARIA'
];

if ($tipoPersona === 'FISICA') {
  $docsReq[] = 'CURP';
}

if ($tipoPersona === 'MORAL') {
  $docsReq[] = 'ACTA_CONSTITUTIVA';
  $docsReq[] = 'PODER_NOTARIAL';
}

foreach ($docsReq as $tipoReq) {
  if (!isset($docsPorTipo[$tipoReq])) {
    fin_json([
      'ok' => false,
      'error' => "Falta documento requerido en la solicitud: {$tipoReq}"
    ], 422);
  }
}

/* =========================================================
   DATOS DE SISTEMA
========================================================= */
$tempPassword = sdca_password_temp(10);
$tempPasswordHash = password_hash($tempPassword, PASSWORD_DEFAULT);

$publicCheckoutToken = sdca_generate_public_checkout_token($correo . '|' . $idFranquicia . '|' . $nombre . '|SOLICITUD|' . $idSolicitud);
$publicCheckoutActivo = 1;
$publicCheckoutUpdatedAt = date('Y-m-d H:i:s');

$codigoDistribuidor = 'DST-' . strtoupper($unidad !== '' ? $unidad : substr($region, 0, 3)) . '-' . str_pad((string)(time() % 1000000), 6, '0', STR_PAD_LEFT);

try {
  $fechaProximaRenovacion = (new DateTime($fechaInicio))->modify('+5 years')->format('Y-m-d');
} catch (Throwable $e) {
  fin_json(['ok' => false, 'error' => 'La fecha de inicio no es válida'], 422);
}

$fechaRenovacion = $fechaInicio;

/* =========================================================
   GERENTE ACTIVO DE FRANQUICIA
========================================================= */
$gestorRel = fin_one($cx, "
  SELECT
    gf.id_gestor,
    g.nombre_gestor
  FROM pats_gestor_franquicias gf
  INNER JOIN pats_gestores g
    ON g.id_gestor = gf.id_gestor
   AND g.activo = 1
  WHERE gf.id_franquicia = {$idFranquicia}
    AND gf.activo = 1
  ORDER BY gf.id_relacion DESC
  LIMIT 1
");

$idGestor = (int)($gestorRel['id_gestor'] ?? 0);
$tieneGestor = $idGestor > 0;

/* =========================================================
   TRANSACCIÓN
========================================================= */
$cx->begin_transaction();

try {
  /* =======================================================
     INSERT DISTRIBUIDOR
  ======================================================= */
  $distData = [
    'id_franquicia' => ['value' => $idFranquicia, 'type' => 'i'],
    'region' => ['value' => $region, 'type' => 's'],
    'nombre' => ['value' => $nombre, 'type' => 's'],
    'rfc' => ['value' => $rfc, 'type' => 's'],
    'telefono' => ['value' => $telefono, 'type' => 's'],
    'correo' => ['value' => $correo, 'type' => 's'],
    'valor_distribucion' => ['value' => $valorTotal, 'type' => 'd'],
    'fecha_alta' => ['value' => $fechaInicio, 'type' => 's'],
    'zona' => ['value' => $zona, 'type' => 's'],
    'file' => ['raw' => 'NULL'],
    'user_alta' => ['value' => $userAlta, 'type' => 'i'],
    'pais' => ['value' => $pais, 'type' => 's'],
    'unidad' => ['value' => $unidad, 'type' => 's'],
    'id_unidad' => ['raw' => 'NULL'],
    'codigo_distribuidor' => ['value' => $codigoDistribuidor, 'type' => 's'],
    'banco' => ['value' => $banco, 'type' => 's'],
    'numero_cuenta' => ['value' => $numeroCuenta, 'type' => 's'],
    'clabe' => ['value' => $clabe, 'type' => 's'],
    'titular_cuenta' => ['value' => $titularCuenta, 'type' => 's'],
    'estatus' => ['value' => 'ACTIVO', 'type' => 's'],
    'activo' => ['value' => 1, 'type' => 'i'],
    'comision_acumulada_periodo' => ['value' => 0, 'type' => 'd'],
    'comision_pagada_periodo' => ['value' => 0, 'type' => 'd'],
    'comision_por_pagar_periodo' => ['value' => 0, 'type' => 'd'],
    'fecha_renovacion' => ['value' => $fechaRenovacion, 'type' => 's'],
    'fecha_proxima_renovacion' => ['value' => $fechaProximaRenovacion, 'type' => 's'],
    'renovacion_estatus' => ['value' => 'vigente', 'type' => 's'],
    'public_checkout_token' => ['value' => $publicCheckoutToken, 'type' => 's'],
    'public_checkout_activo' => ['value' => $publicCheckoutActivo, 'type' => 'i'],
    'public_checkout_updated_at' => ['value' => $publicCheckoutUpdatedAt, 'type' => 's'],
    'created_at' => ['raw' => 'NOW()'],
    'updated_at' => ['raw' => 'NOW()']
  ];

  if (sdca_column_exists($cx, 'pats_distribuidores', 'tipo_persona')) {
    $distData['tipo_persona'] = ['value' => $tipoPersona, 'type' => 's'];
  }

  if (sdca_column_exists($cx, 'pats_distribuidores', 'razon_social')) {
    $distData['razon_social'] = ['value' => $razonSocial, 'type' => 's'];
  }

  if (sdca_column_exists($cx, 'pats_distribuidores', 'direccion')) {
    $distData['direccion'] = ['value' => $direccion, 'type' => 's'];
  }

  $idDistribuidor = sdca_insert_dynamic($cx, 'pats_distribuidores', $distData);

  /* =======================================================
     USUARIO PATS
  ======================================================= */
  $stmt = $cx->prepare("
    INSERT INTO pats_users
    (
      app,
      rolapp,
      rol,
      tipo_actor,
      id_actor,
      nombre,
      usuario,
      correo,
      contrasena,
      region,
      acroregion,
      unidad,
      acronu,
      vigente,
      activo,
      must_change_password,
      password_last_change,
      last_login_at,
      failed_attempts,
      locked_until,
      perfil,
      ced,
      telefono,
      created_at,
      updated_at
    )
    VALUES
    (
      'PATS',
      'DISTPATS',
      'DISTRIBUIDOR',
      'DISTRIBUIDOR',
      ?,
      ?, ?, ?, ?,
      ?, ?, ?, ?,
      NULL,
      1,
      1,
      NULL,
      NULL,
      0,
      NULL,
      NULL,
      NULL,
      ?,
      NOW(),
      NOW()
    )
  ");

  if (!$stmt) {
    throw new RuntimeException('No fue posible preparar usuario PATS');
  }

  $acronu = $unidad;
  $acroregion = $region;

  $stmt->bind_param(
    'isssssssss',
    $idDistribuidor,
    $nombre,
    $correo,
    $correo,
    $tempPasswordHash,
    $region,
    $acroregion,
    $unidad,
    $acronu,
    $telefono
  );

  if (!$stmt->execute()) {
    throw new RuntimeException('No fue posible guardar usuario PATS: ' . $stmt->error);
  }

  $idUserPats = (int)$stmt->insert_id;
  $stmt->close();

  /* =======================================================
     COPIA / REFERENCIA DE DOCUMENTOS DE SOLICITUD A ACTOR
  ======================================================= */
  foreach ($docsSolicitud as $doc) {
    $tipoDoc = strtoupper(sdca_clean($doc['tipo_documento'] ?? ''));
    $pathDoc = sdca_clean($doc['archivo_path'] ?? '');

    if ($tipoDoc === '' || $pathDoc === '') {
      continue;
    }

    sdca_insert_documento_actor(
      $cx,
      'distribuidor',
      $idDistribuidor,
      $tipoDoc,
      $pathDoc,
      sdca_clean($doc['archivo_nombre_original'] ?? ''),
      sdca_clean($doc['mime_type'] ?? ''),
      (int)($doc['size_kb'] ?? 0),
      'Documento migrado desde solicitud de distribuidor #' . $idSolicitud,
      $userAlta
    );
  }

  $pathContratoPrincipal = '';
  $contratoHashRef = '';

  if (is_array($docContratoDigitalPdf) && sdca_doc_path($docContratoDigitalPdf) !== '') {
    $pathContratoPrincipal = sdca_doc_path($docContratoDigitalPdf);
    $contratoHashRef = 'CONTRATO_DIGITAL_PDF:' . basename($pathContratoPrincipal);
  }

  if ($pathContratoPrincipal === '' && is_array($docContratoDigitalHtml) && sdca_doc_path($docContratoDigitalHtml) !== '') {
    $pathContratoPrincipal = sdca_doc_path($docContratoDigitalHtml);
    $contratoHashRef = 'CONTRATO_DIGITAL_HTML:' . basename($pathContratoPrincipal);
  }

  if ($pathContratoPrincipal === '' && is_array($docContratoFirmado) && sdca_doc_path($docContratoFirmado) !== '') {
    $pathContratoPrincipal = sdca_doc_path($docContratoFirmado);
    $contratoHashRef = 'CONTRATO_FIRMADO:' . basename($pathContratoPrincipal);
  }

  if ($pathContratoPrincipal === '' && sdca_bool($sol['contrato_firmado_path'] ?? '')) {
    $pathContratoPrincipal = sdca_clean($sol['contrato_firmado_path'] ?? '');
    $contratoHashRef = 'CONTRATO_FIRMADO_HISTORICO:' . basename($pathContratoPrincipal);

    sdca_insert_documento_actor(
      $cx,
      'distribuidor',
      $idDistribuidor,
      'CONTRATO_FIRMADO',
      $pathContratoPrincipal,
      basename($pathContratoPrincipal),
      '',
      0,
      'Contrato firmado histórico migrado desde solicitud #' . $idSolicitud,
      $userAlta
    );
  }

  if ($pathContratoPrincipal !== '') {
    fin_exec($cx, "
      UPDATE pats_distribuidores
      SET file = '" . fin_esc($cx, $pathContratoPrincipal) . "'
      WHERE id_distribuidor = {$idDistribuidor}
      LIMIT 1
    ");
  }

  /* =======================================================
     CONTRATO FINANCIERO
  ======================================================= */
  $numeroContrato = 'DIS-' . str_pad((string)$idDistribuidor, 6, '0', STR_PAD_LEFT);
  $estatusContrato = ($modalidadPago === 'CONTADO' || $saldoFinanciado <= 0) ? 'LIQUIDADO' : 'VIGENTE';
  $fechaFirma = $fechaInicio;
  if (is_array($docFirmaImagen) && sdca_clean($docFirmaImagen['created_at'] ?? '') !== '') {
    $fechaFirma = substr(sdca_clean($docFirmaImagen['created_at'] ?? ''), 0, 10);
  } elseif (is_array($docContratoDigitalPdf) && sdca_clean($docContratoDigitalPdf['created_at'] ?? '') !== '') {
    $fechaFirma = substr(sdca_clean($docContratoDigitalPdf['created_at'] ?? ''), 0, 10);
  } elseif (is_array($docContratoDigitalHtml) && sdca_clean($docContratoDigitalHtml['created_at'] ?? '') !== '') {
    $fechaFirma = substr(sdca_clean($docContratoDigitalHtml['created_at'] ?? ''), 0, 10);
  } elseif (is_array($docContratoFirmado) && sdca_clean($docContratoFirmado['created_at'] ?? '') !== '') {
    $fechaFirma = substr(sdca_clean($docContratoFirmado['created_at'] ?? ''), 0, 10);
  }

  $stmt = $cx->prepare("
    INSERT INTO pats_contratos_actor
    (
      actor_tipo,
      actor_id,
      tipo_contrato,
      numero_contrato,
      modalidad_pago,
      valor_total,
      enganche,
      saldo_financiado,
      plazo_meses,
      periodicidad,
      fecha_inicio,
      fecha_primer_vencimiento,
      fecha_firma,
      fecha_termino,
      moneda,
      tasa_recargo,
      estatus,
      motivo_cancelacion,
      observaciones,
      activo,
      user_alta,
      created_at,
      updated_at
    )
    VALUES
    (
      'distribuidor',
      ?,
      'alta_distribuidor',
      ?,
      ?,
      ?,
      ?,
      ?,
      ?,
      ?,
      ?,
      ?,
      ?,
      NULL,
      'MXN',
      0,
      ?,
      NULL,
      ?,
      1,
      ?,
      NOW(),
      NOW()
    )
  ");

  if (!$stmt) {
    throw new RuntimeException('No fue posible preparar contrato financiero');
  }

  $obsContrato = $contratoDigitalFirmado
    ? 'Contrato generado desde solicitud externa con firma/contrato digital documentado. Ref: ' . $contratoHashRef
    : 'Contrato generado desde solicitud externa con contrato firmado histórico.';

  $stmt->bind_param(
    'issdddisssssii',
    $idDistribuidor,
    $numeroContrato,
    $modalidadPago,
    $valorTotal,
    $enganche,
    $saldoFinanciado,
    $plazoMeses,
    $periodicidad,
    $fechaInicio,
    $fechaPrimerVenc,
    $fechaFirma,
    $estatusContrato,
    $obsContrato,
    $userAlta
  );

  if (!$stmt->execute()) {
    throw new RuntimeException('No fue posible guardar contrato financiero: ' . $stmt->error);
  }

  $idContrato = (int)$stmt->insert_id;
  $stmt->close();

  if ($modalidadPago !== 'CONTADO' && $saldoFinanciado > 0 && $plazoMeses > 0) {
    fin_create_parcialidades($cx, $idContrato, $saldoFinanciado, $plazoMeses, $periodicidad, $fechaPrimerVenc);
  }

  /* =======================================================
     PAGO INICIAL
  ======================================================= */
  $montoPagoInicial = 0.0;

  if ($modalidadPago === 'CONTADO') {
    $montoPagoInicial = $valorTotal;
  } elseif ($enganche > 0) {
    $montoPagoInicial = $enganche;
  }

  if ($montoPagoInicial > 0) {
    fin_registrar_pago_inicial_contrato(
      $cx,
      $idContrato,
      $montoPagoInicial,
      $fechaInicio,
      'PAGO_INICIAL',
      'Conversión solicitud distribuidor',
      'Pago inicial registrado al convertir solicitud externa de distribuidor'
    );
  }

  /* =======================================================
     RENOVACIÓN
  ======================================================= */
  $stmt = $cx->prepare("
    INSERT INTO pats_renovaciones_distribucion
    (
      id_distribuidor,
      id_franquicia,
      fecha_inicio_vigencia,
      fecha_fin_vigencia,
      monto_renovacion,
      ambito_region,
      estatus,
      fecha_confirmacion,
      observaciones,
      created_at,
      updated_at
    )
    VALUES
    (?, ?, ?, ?, ?, ?, 'vigente', NOW(), ?, NOW(), NOW())
  ");

  if (!$stmt) {
    throw new RuntimeException('No fue posible preparar renovación');
  }

  $obsRenov = 'Conversión desde solicitud externa #' . $idSolicitud;

  $stmt->bind_param(
    'iissdss',
    $idDistribuidor,
    $idFranquicia,
    $fechaInicio,
    $fechaProximaRenovacion,
    $valorTotal,
    $ambitoRegionPrecio,
    $obsRenov
  );

  if (!$stmt->execute()) {
    throw new RuntimeException('No fue posible guardar renovación: ' . $stmt->error);
  }

  $stmt->close();

  /* =======================================================
     ACTUALIZAR SOLICITUD
  ======================================================= */
  fin_exec($cx, "
    UPDATE pats_solicitudes_distribuidor
    SET
      id_distribuidor_generado = {$idDistribuidor},
      estatus = 'CONVERTIDA_ALTA',
      fecha_conversion_alta = NOW(),
      updated_at = NOW()
    WHERE id_solicitud = {$idSolicitud}
    LIMIT 1
  ");

  sdca_insert_historial_solicitud(
    $cx,
    $idSolicitud,
    'CONVERSION_ALTA',
    $estatusActual,
    'CONVERTIDA_ALTA',
    [
      'id_distribuidor' => $idDistribuidor,
      'id_user_pats' => $idUserPats,
      'id_contrato' => $idContrato,
      'numero_contrato' => $numeroContrato,
      'contrato_digital_firmado' => $contratoDigitalFirmado,
      'contrato_firmado_historico' => $contratoFirmadoHistorico,
      'fotografia_capturada' => $fotografiaCapturada,
      'contrato_ref' => $contratoHashRef
    ],
    $userAlta > 0 ? $userAlta : null
  );

  sdca_insert_historial_global(
    $cx,
    'distribuidor',
    $idDistribuidor,
    'alta_desde_solicitud',
    null,
    'ACTIVO',
    [
      'id_solicitud' => $idSolicitud,
      'id_franquicia' => $idFranquicia,
      'valor_distribucion' => $valorTotal,
      'ambito_region_precio' => $ambitoRegionPrecio,
      'numero_contrato' => $numeroContrato,
      'tipo_persona' => $tipoPersona,
      'razon_social' => $razonSocial,
      'tiene_gestor' => $tieneGestor,
      'id_gestor' => $idGestor > 0 ? $idGestor : null,
      'contrato_ref' => $contratoHashRef,
      'public_checkout_token' => $publicCheckoutToken
    ],
    $userAlta > 0 ? $userAlta : null
  );

  /* =======================================================
     COMISIONES
     Compatible con bootstrap actual o versión extendida con contexto.
  ======================================================= */
  $liberacionComisiones = [
    'ok' => true,
    'liberado' => []
  ];

  if (function_exists('fin_liberar_comisiones_proporcionales_por_contrato')) {
    $ctxComision = [
      'tipo_solicitud' => 'distribuidor',
      'id_solicitud' => $idSolicitud,
      'modalidad_pago' => $modalidadPago,
      'intervalo_meses' => $plazoMeses,
      'valor_total' => $valorTotal
    ];

    $rf = new ReflectionFunction('fin_liberar_comisiones_proporcionales_por_contrato');
    if ($rf->getNumberOfParameters() >= 5) {
      $liberacionComisiones = fin_liberar_comisiones_proporcionales_por_contrato(
        $cx,
        $idContrato,
        'CONVERSION_SOLICITUD_DISTRIBUIDOR',
        $userAlta > 0 ? $userAlta : null,
        $ctxComision
      );
    } else {
      $liberacionComisiones = fin_liberar_comisiones_proporcionales_por_contrato(
        $cx,
        $idContrato,
        'CONVERSION_SOLICITUD_DISTRIBUIDOR',
        $userAlta > 0 ? $userAlta : null
      );
    }
  }

  $comisionAdminLiberadaEvento = 0.0;
  $comisionFranquiciaLiberadaEvento = 0.0;
  $comisionGestorLiberadaEvento = 0.0;

  foreach (($liberacionComisiones['liberado'] ?? []) as $rowLib) {
    $tipoBenef = strtolower(trim((string)($rowLib['beneficiario_tipo'] ?? '')));
    $delta = fin_num($rowLib['delta'] ?? 0);

    if ($tipoBenef === 'admin') {
      $comisionAdminLiberadaEvento += $delta;
    } elseif ($tipoBenef === 'franquicia') {
      $comisionFranquiciaLiberadaEvento += $delta;
    } elseif ($tipoBenef === 'gestor') {
      $comisionGestorLiberadaEvento += $delta;
    }
  }

  $comisionAdminLiberadaEvento = fin_num($comisionAdminLiberadaEvento);
  $comisionFranquiciaLiberadaEvento = fin_num($comisionFranquiciaLiberadaEvento);
  $comisionGestorLiberadaEvento = fin_num($comisionGestorLiberadaEvento);

  $cx->commit();

  /*
    Correo automático:
    Se envía después del commit para no bloquear la conversión si SMTP falla.
  */
  $emailSent = false;
  $emailAttempted = false;
  $emailError = '';
  $loginUrlDistribuidor = sdca_distribuidor_login_url();
  $publicCheckoutLink = sdca_public_checkout_link($publicCheckoutToken);

  try {
    if ($correo === '' || $tempPassword === '') {
      $emailError = 'No se intentó enviar correo: falta correo o contraseña temporal.';
    } elseif ($correo !== '' && $tempPassword !== '') {
      $emailAttempted = true;
      $mailDistribuidor = sdca_send_distribuidor_bienvenida_email([
        'to' => $correo,
        'nombre' => $nombre,
        'usuario' => $correo,
        'password_temp' => $tempPassword,
        'login_url' => $loginUrlDistribuidor,
        'codigo_distribuidor' => $codigoDistribuidor,
        'nombre_franquicia' => sdca_clean($franquicia['nombre_franquicia'] ?? $franquicia['franquiciatario'] ?? ''),
        'region' => $region,
        'zona' => $zona,
        'unidad' => $unidad,
        'public_checkout_link' => $publicCheckoutLink,
      ]);

      $emailSent = !empty($mailDistribuidor['ok']);
      $emailError = (string)($mailDistribuidor['error'] ?? '');

      sdca_insert_historial_solicitud(
        $cx,
        $idSolicitud,
        $emailSent ? 'CORREO_BIENVENIDA_ENVIADO' : 'CORREO_BIENVENIDA_FALLIDO',
        'CONVERTIDA_ALTA',
        'CONVERTIDA_ALTA',
        [
          'correo' => $correo,
          'id_user_pats' => $idUserPats,
          'id_distribuidor' => $idDistribuidor,
          'email_attempted' => $emailAttempted,
          'email_sent' => $emailSent,
          'email_error' => $emailError,
        ],
        $userAlta > 0 ? $userAlta : null
      );
    }
  } catch (Throwable $mailEx) {
    $emailSent = false;
    $emailError = $mailEx->getMessage();
    error_log('PATS distribuidor welcome mail error: ' . $emailError);
  }

  fin_json([
    'ok' => true,
    'id_solicitud' => $idSolicitud,
    'id_distribuidor' => $idDistribuidor,
    'id_user_pats' => $idUserPats,
    'id_contrato' => $idContrato,
    'numero_contrato' => $numeroContrato,
    'codigo_distribuidor' => $codigoDistribuidor,
    'id_franquicia' => $idFranquicia,
    'ambito_region_precio' => $ambitoRegionPrecio,
    'tipo_persona' => $tipoPersona,
    'razon_social' => $razonSocial,
    'tiene_gestor' => $tieneGestor,
    'id_gestor' => $idGestor > 0 ? $idGestor : null,
    'nombre_gestor' => sdca_clean($gestorRel['nombre_gestor'] ?? ''),
    'contrato_digital_firmado' => $contratoDigitalFirmado,
    'contrato_firmado_historico' => $contratoFirmadoHistorico,
    'fotografia_capturada' => $fotografiaCapturada,
    'valor_total' => $valorTotal,
    'enganche' => $enganche,
    'saldo_financiado' => $saldoFinanciado,
    'monto_pago_inicial' => $montoPagoInicial,
    'comision_admin_generada' => $comisionAdminLiberadaEvento,
    'comision_franquicia_generada_total' => $comisionFranquiciaLiberadaEvento,
    'comision_franquicia_liberada' => $comisionFranquiciaLiberadaEvento,
    'comision_gestor_generada' => $comisionGestorLiberadaEvento,
    'liberacion_comisiones' => $liberacionComisiones,
    'public_checkout_token' => $publicCheckoutToken,
    'public_checkout_link' => $publicCheckoutLink,
    'password_temp' => $tempPassword,
    'email_attempted' => $emailAttempted,
    'email_sent' => $emailSent,
    'email_error' => $emailError,
    'login_url' => $loginUrlDistribuidor
  ]);

} catch (Throwable $e) {
  $cx->rollback();

  fin_json([
    'ok' => false,
    'error' => $e->getMessage()
  ], 500);
}
