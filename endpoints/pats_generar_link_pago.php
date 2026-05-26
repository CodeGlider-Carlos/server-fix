<?php
/*
ez/pats/endpoints/pats_generar_link_pago.php
POST — genera un Stripe Payment Link para una orden pendiente.
*/
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../../../varSQL/bd_pats.php';
require_once __DIR__ . '/../../../varSQL/var_pats.php';

function glp_json(array $d, int $code = 200): void {
  http_response_code($code);
  echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  glp_json(['ok' => false, 'error' => 'Método no permitido.'], 405);
}

$adminrol = strtoupper(trim((string)($_SESSION['rol']   ?? '')));
$rolapp   = strtoupper(trim((string)($_SESSION['rolapp'] ?? '')));
$rolesAdmin = ['ADMIN', 'ADMINPATS', 'DIRO', 'DIRG', 'VIC'];

if (!in_array($adminrol, $rolesAdmin, true) && !in_array($rolapp, $rolesAdmin, true)) {
  glp_json(['ok' => false, 'error' => 'Sin autorización.'], 403);
}

$idOrden = (int)($_POST['id_orden'] ?? 0);
if ($idOrden <= 0) {
  glp_json(['ok' => false, 'error' => 'ID de orden inválido.'], 422);
}

$secret = defined('STRIPE_SECRET_KEY') ? trim((string)STRIPE_SECRET_KEY) : '';
if ($secret === '' || str_starts_with($secret, 'sk_live_XXXX') || str_starts_with($secret, 'sk_test_XXXX')) {
  glp_json(['ok' => false, 'error' => 'Stripe no está configurado.'], 500);
}

$cx = $conexionMod ?? $db_all2 ?? $db_all ?? null;
if (!$cx || !($cx instanceof mysqli)) {
  glp_json(['ok' => false, 'error' => 'Sin conexión a base de datos.'], 500);
}

/* Cargar datos de la orden */
$stmt = $cx->prepare("
  SELECT id_orden, folio_orden, correo_usuario_pats, nombre_usuario, apellido_pa,
         monto_orden, moneda, frecuencia, estatus_pago, estatus_orden
  FROM pats_ordenes_pago
  WHERE id_orden = ?
  LIMIT 1
");
if (!$stmt) glp_json(['ok' => false, 'error' => 'Error de BD.'], 500);
$stmt->bind_param('i', $idOrden);
$stmt->execute();
$orden = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$orden) {
  glp_json(['ok' => false, 'error' => 'Orden no encontrada.'], 404);
}

$esPendiente = in_array($orden['estatus_pago'] ?? '', ['PENDIENTE', 'PENDIENTE_OXXO'], true)
            || ($orden['estatus_orden'] ?? '') === 'PENDIENTE_OXXO';

if (!$esPendiente) {
  glp_json(['ok' => false, 'error' => 'Esta orden no está pendiente de pago.'], 422);
}

$monto    = (float)($orden['monto_orden'] ?? 0);
$moneda   = strtolower(trim((string)($orden['moneda'] ?? 'mxn')));
$folio    = (string)($orden['folio_orden'] ?? "Orden #{$idOrden}");
$correo   = (string)($orden['correo_usuario_pats'] ?? '');
$nombre   = trim(($orden['nombre_usuario'] ?? '') . ' ' . ($orden['apellido_pa'] ?? ''));

if ($monto <= 0) {
  glp_json(['ok' => false, 'error' => 'El monto de la orden es inválido.'], 422);
}

$amountCents = (int)round($monto * 100);

/* Crear Price inline + Payment Link en un solo paso */
$params = http_build_query([
  'line_items[0][price_data][currency]'                      => $moneda,
  'line_items[0][price_data][product_data][name]'            => 'Pasaporte PATS · ' . $folio,
  'line_items[0][price_data][product_data][description]'     => 'Pago de pasaporte PATS. Frecuencia: ' . ($orden['frecuencia'] ?? ''),
  'line_items[0][price_data][unit_amount]'                   => $amountCents,
  'line_items[0][quantity]'                                  => 1,
  'after_completion[type]'                                   => 'hosted_confirmation',
  'after_completion[hosted_confirmation][custom_message]'    => 'Tu pago fue recibido. PATS confirmará tu pasaporte en breve.',
  'metadata[id_orden]'                                       => $idOrden,
  'metadata[folio]'                                          => $folio,
  'metadata[sistema]'                                        => 'PATS',
  'payment_method_types[0]'                                  => 'card',
]);

$ch = curl_init('https://api.stripe.com/v1/payment_links');
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST           => true,
  CURLOPT_POSTFIELDS     => $params,
  CURLOPT_USERPWD        => $secret . ':',
  CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
  CURLOPT_TIMEOUT        => 20,
]);

$body     = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($body === false || $curlErr !== '') {
  glp_json(['ok' => false, 'error' => 'No se pudo contactar Stripe: ' . $curlErr], 500);
}

$json = json_decode((string)$body, true);
if (!is_array($json)) {
  glp_json(['ok' => false, 'error' => 'Respuesta inválida de Stripe.'], 500);
}

if ($httpCode < 200 || $httpCode >= 300) {
  glp_json(['ok' => false, 'error' => 'Stripe: ' . ($json['error']['message'] ?? "HTTP {$httpCode}")], 500);
}

$url = (string)($json['url'] ?? '');
if ($url === '') {
  glp_json(['ok' => false, 'error' => 'Stripe no devolvió una URL de pago.'], 500);
}

glp_json([
  'ok'       => true,
  'url'      => $url,
  'id_orden' => $idOrden,
  'folio'    => $folio,
  'monto'    => $monto,
]);
