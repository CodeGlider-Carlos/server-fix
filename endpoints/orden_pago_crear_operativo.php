<?php
session_start();
require_once '../../../varSQL/bd_pats.php';
require_once '../../../varSQL/var_pats.php';

mysqli_report(MYSQLI_REPORT_OFF);

if (empty($_SESSION['usuario'])) {
  session_destroy();
  http_response_code(401);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'error' => 'Sesión no válida']);
  exit;
}

$cx = $conexionMod ?? $db_all2 ?? $db_all ?? null;
if (!$cx || !($cx instanceof mysqli)) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'error' => 'No hay conexión mysqli disponible']);
  exit;
}

function out($payload, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function clean($v): string {
  return trim((string)($v ?? ''));
}

function num($v): float {
  return round((float)($v ?? 0), 2);
}

$tbOrdenes = 'pats_ordenes_pago';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  out(['ok' => false, 'error' => 'Método no permitido'], 405);
}

/* =========================================================
   CAMPOS
========================================================= */
$tipoOrigen = strtoupper(clean($_POST['tipo_origen'] ?? 'DISTRIBUIDOR'));
$origenCheckout = strtoupper(clean($_POST['origen_checkout'] ?? 'OPERATIVO_DISTRIBUIDOR'));

$idDistribuidor = (int)($_POST['id_distribuidor'] ?? 0);
$idFranquicia = (int)($_POST['id_franquicia'] ?? 0);
$idPasaporte = (int)($_POST['id_pasaporte'] ?? 0);
$idTipoPrecio = (int)($_POST['id_tipo_precio'] ?? 0);

$correo = mb_strtolower(clean($_POST['correo_usuario_pats'] ?? ''));
$curp = strtoupper(clean($_POST['curp_usuario'] ?? ''));
$nombre = clean($_POST['nombre_usuario'] ?? '');
$apellidoPa = clean($_POST['apellido_pa'] ?? '');
$apellidoMa = clean($_POST['apellido_ma'] ?? '');
$fechaNacimiento = clean($_POST['fecha_nacimiento'] ?? '');
$telefono = preg_replace('/\D+/', '', (string)($_POST['telefono_usuario'] ?? ''));

$tipoOperacion = strtoupper(clean($_POST['tipo_operacion'] ?? 'ALTA_PATS'));
$frecuencia = strtoupper(clean($_POST['frecuencia'] ?? 'ANUAL'));
$monto = num($_POST['monto_orden'] ?? 0);
$moneda = strtoupper(clean($_POST['moneda'] ?? 'MXN'));

$pais = clean($_POST['pais'] ?? 'México');
$region = strtoupper(clean($_POST['region'] ?? ''));
$zona = clean($_POST['zona'] ?? '');
$unidad = clean($_POST['unidad'] ?? '');
$tipoCliente = strtolower(clean($_POST['tipo_cliente'] ?? 'privado'));
$nombreEmpresa = clean($_POST['nombre_empresa'] ?? '');

$userCreo = clean($_SESSION['usuario'] ?? '');

if ($idDistribuidor <= 0 || $idFranquicia <= 0) {
  out(['ok' => false, 'error' => 'Falta distribuidor o franquicia'], 422);
}
if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
  out(['ok' => false, 'error' => 'Correo no válido'], 422);
}
if (strlen($telefono) !== 10) {
  out(['ok' => false, 'error' => 'Teléfono inválido'], 422);
}
if ($monto <= 0) {
  out(['ok' => false, 'error' => 'Monto inválido'], 422);
}
if ($tipoOperacion === 'ALTA_PATS' && $idPasaporte <= 0) {
  if ($nombre === '' || $apellidoPa === '' || $fechaNacimiento === '' || $curp === '') {
    out(['ok' => false, 'error' => 'Faltan datos mínimos para crear el pasaporte después del pago'], 422);
  }
}
if ($idTipoPrecio <= 0) {
  $idTipoPrecio = ($frecuencia === 'MENSUAL') ? 2 : 1;
}
if ($tipoCliente === 'empresa' && $nombreEmpresa === '') {
  out(['ok' => false, 'error' => 'Falta nombre de empresa para tipo de cliente empresa'], 422);
}

$exists = $cx->query("SHOW TABLES LIKE '{$tbOrdenes}'");
if (!$exists || $exists->num_rows === 0) {
  out(['ok' => false, 'error' => "No existe la tabla {$tbOrdenes} en la base actual"], 500);
}

$folioOrden = 'PATS-' . date('Ymd-His') . '-' . str_pad((string)random_int(1, 999999), 6, '0', STR_PAD_LEFT);
$referenciaPago = $folioOrden;
$publicToken = bin2hex(random_bytes(24));
$tokenExpiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');
$checkoutPublicoUrl = $scheme . '://' . $host . $basePath . '/checkout_publico.php?t=' . urlencode($publicToken);

$payloadCheckout = json_encode([
  'tipo_origen' => $tipoOrigen,
  'origen_checkout' => $origenCheckout,
  'id_distribuidor' => $idDistribuidor,
  'id_franquicia' => $idFranquicia,
  'id_pasaporte' => $idPasaporte > 0 ? $idPasaporte : null,
  'id_tipo_precio' => $idTipoPrecio,
  'correo_usuario_pats' => $correo,
  'curp_usuario' => $curp,
  'nombre_usuario' => $nombre,
  'apellido_pa' => $apellidoPa,
  'apellido_ma' => $apellidoMa,
  'fecha_nacimiento' => $fechaNacimiento,
  'telefono_usuario' => $telefono,
  'tipo_operacion' => $tipoOperacion,
  'frecuencia' => $frecuencia,
  'monto_orden' => $monto,
  'moneda' => $moneda,
  'pais' => $pais,
  'region' => $region,
  'zona' => $zona,
  'unidad' => $unidad,
  'tipo_cliente' => $tipoCliente,
  'nombre_empresa' => $nombreEmpresa
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$sql = "
  INSERT INTO {$tbOrdenes}
  (
    folio_orden, referencia_pago, public_token, public_token_expires_at, link_checkout_publico,
    tipo_origen, origen_checkout, id_distribuidor, id_franquicia, id_pasaporte,
    correo_usuario_pats, curp_usuario, nombre_usuario, apellido_pa, apellido_ma, fecha_nacimiento, telefono_usuario, id_tipo_precio,
    tipo_operacion, frecuencia, monto_orden, moneda,
    pais, region, zona, unidad, tipo_cliente, nombre_empresa,
    estatus_orden, estatus_pago,
    payload_checkout_json, user_creo, fecha_orden, created_at, updated_at
  )
  VALUES
  (?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
   ?, ?, ?, ?, ?, ?, ?, ?,
   ?, ?, ?, ?,
   ?, ?, ?, ?, ?, ?,
   'PENDIENTE', 'PENDIENTE',
   ?, ?, NOW(), NOW(), NOW())
";

$stmt = $cx->prepare($sql);
if (!$stmt) {
  out(['ok' => false, 'error' => 'No fue posible preparar orden: ' . $cx->error], 500);
}

$stmt->bind_param(
  'sssssssiiissssssisssdsssssssss',
  $folioOrden,
  $referenciaPago,
  $publicToken,
  $tokenExpiresAt,
  $checkoutPublicoUrl,
  $tipoOrigen,
  $origenCheckout,
  $idDistribuidor,
  $idFranquicia,
  $idPasaporte,
  $correo,
  $curp,
  $nombre,
  $apellidoPa,
  $apellidoMa,
  $fechaNacimiento,
  $telefono,
  $idTipoPrecio,
  $tipoOperacion,
  $frecuencia,
  $monto,
  $moneda,
  $pais,
  $region,
  $zona,
  $unidad,
  $tipoCliente,
  $nombreEmpresa,
  $payloadCheckout,
  $userCreo
);

if (!$stmt->execute()) {
  out(['ok' => false, 'error' => 'No fue posible guardar orden: ' . $stmt->error], 500);
}

$idOrden = (int)$stmt->insert_id;
$stmt->close();

out([
  'ok' => true,
  'id_orden' => $idOrden,
  'folio_orden' => $folioOrden,
  'referencia_pago' => $referenciaPago,
  'checkout_url' => 'checkout_pats.php?folio=' . urlencode($folioOrden),
  'checkout_publico_url' => $checkoutPublicoUrl,
  'public_token' => $publicToken
]);