<?php
/*
ez/pats/endpoints/crear_password_token_save.php
Guarda contraseña creada desde token_reset.
*/

declare(strict_types=1);

require_once '../../../varSQL/bd_pats.php';
require_once '../../../varSQL/var_pats.php';

mysqli_report(MYSQLI_REPORT_OFF);
header('Content-Type: application/json; charset=utf-8');

$cx = $conexionMod ?? $db_all2 ?? $db_all ?? null;

function cpts_json(array $payload, int $code = 200): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function cpts_clean($v): string {
  return trim((string)($v ?? ''));
}

function cpts_valid_password(string $pwd): bool {
  return strlen($pwd) >= 9
    && preg_match('/[A-Za-zÁÉÍÓÚáéíóúÑñ]/u', $pwd)
    && preg_match('/\d/', $pwd)
    && preg_match('/[^A-Za-zÁÉÍÓÚáéíóúÑñ\d]/u', $pwd);
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  cpts_json(['ok' => false, 'error' => 'Método no permitido.'], 405);
}

if (!$cx || !($cx instanceof mysqli)) {
  cpts_json(['ok' => false, 'error' => 'No hay conexión mysqli disponible.'], 500);
}

$token = cpts_clean($_POST['token'] ?? '');
$newPassword = (string)($_POST['new_password'] ?? '');
$confirmPassword = (string)($_POST['confirm_password'] ?? '');

if ($token === '') {
  cpts_json(['ok' => false, 'error' => 'Falta token de acceso.'], 422);
}

if ($newPassword !== $confirmPassword) {
  cpts_json(['ok' => false, 'error' => 'La confirmación de contraseña no coincide.'], 422);
}

if (!cpts_valid_password($newPassword)) {
  cpts_json([
    'ok' => false,
    'error' => 'La contraseña debe tener al menos 9 caracteres e incluir letras, números y símbolos.'
  ], 422);
}

$stmt = $cx->prepare("
  SELECT id_acceso
  FROM pats_pasaporte_accesos
  WHERE token_reset = ?
    AND activo = 1
    AND UPPER(TRIM(estatus)) = 'ACTIVO'
    AND token_reset IS NOT NULL
    AND token_reset <> ''
    AND token_reset_expira IS NOT NULL
    AND token_reset_expira >= NOW()
  LIMIT 1
");

if (!$stmt) {
  cpts_json(['ok' => false, 'error' => 'No fue posible validar el token: ' . $cx->error], 500);
}

$stmt->bind_param('s', $token);
$stmt->execute();
$rs = $stmt->get_result();
$row = $rs ? $rs->fetch_assoc() : null;
$stmt->close();

if (!$row) {
  cpts_json([
    'ok' => false,
    'error' => 'El enlace ya fue utilizado, expiró o no corresponde a un acceso activo.'
  ], 410);
}

$idAcceso = (int)($row['id_acceso'] ?? 0);
$passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

$upd = $cx->prepare("
  UPDATE pats_pasaporte_accesos
  SET
    password_hash = ?,
    password_temporal = 0,
    debe_cambiar_password = 0,
    token_reset = NULL,
    token_reset_expira = NULL,
    updated_at = NOW()
  WHERE id_acceso = ?
  LIMIT 1
");

if (!$upd) {
  cpts_json(['ok' => false, 'error' => 'No fue posible preparar actualización: ' . $cx->error], 500);
}

$upd->bind_param('si', $passwordHash, $idAcceso);

if (!$upd->execute()) {
  $err = $upd->error;
  $upd->close();
  cpts_json(['ok' => false, 'error' => 'No fue posible guardar contraseña: ' . $err], 500);
}

$upd->close();

cpts_json([
  'ok' => true,
  'message' => 'Contraseña creada correctamente.',
  'redirect' => 'https://pasaporteatusalud.com'
]);
