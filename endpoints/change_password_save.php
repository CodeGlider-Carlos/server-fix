<?php
/*
ez/pats/endpoints/change_password_save.php
*/
session_start();
require_once '../../../varSQL/bd_pats.php';
require_once '../../../varSQL/var_pats.php';

header('Content-Type: application/json; charset=utf-8');

function jerr(string $msg, int $code = 422): void {
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function jout(array $data): void {
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  jerr('Método no permitido', 405);
}

if (empty($_SESSION['usuario']) || strtoupper(trim((string)($_SESSION['portal_login'] ?? ''))) !== 'PATS') {
  jerr('Sesión no válida', 401);
}

$current = (string)($_POST['current_password'] ?? '');
$new = (string)($_POST['new_password'] ?? '');
$confirm = (string)($_POST['confirm_password'] ?? '');
$usuario = trim((string)($_SESSION['usuario'] ?? ''));

if ($current === '' || $new === '' || $confirm === '') {
  jerr('Debes completar todos los campos.');
}

if ($new !== $confirm) {
  jerr('La confirmación de contraseña no coincide.');
}

if (strlen($new) < 9) {
  jerr('La nueva contraseña debe tener al menos 9 caracteres.');
}
if (!preg_match('/[A-Za-z]/', $new)) {
  jerr('La nueva contraseña debe incluir al menos una letra.');
}
if (!preg_match('/\d/', $new)) {
  jerr('La nueva contraseña debe incluir al menos un número.');
}
if (!preg_match('/[^A-Za-z\d]/', $new)) {
  jerr('La nueva contraseña debe incluir al menos un símbolo.');
}

$row = null;
$stmt = $db_pats->prepare("
  SELECT id, usuario, contrasena, rolapp, rol
  FROM pats_users
  WHERE usuario = :usuario
    AND activo = 1
  LIMIT 1
");
$stmt->execute([':usuario' => $usuario]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  jerr('Usuario no encontrado.', 404);
}

$hash = (string)($row['contrasena'] ?? '');
$okCurrent = false;

/* hash normal */
if ($hash !== '' && password_verify($current, $hash)) {
  $okCurrent = true;
}

/* fallback legacy temporal por si aún quedan passwords en texto plano */
if (!$okCurrent && $hash !== '' && hash_equals($hash, $current)) {
  $okCurrent = true;
}

if (!$okCurrent) {
  jerr('La contraseña actual no es correcta.');
}

$newHash = password_hash($new, PASSWORD_DEFAULT);

$upd = $db_pats->prepare("
  UPDATE pats_users
  SET
    contrasena = :hash,
    must_change_password = 0,
    password_last_change = NOW(),
    failed_attempts = 0,
    locked_until = NULL,
    updated_at = NOW()
  WHERE id = :id
  LIMIT 1
");

$upd->execute([
  ':hash' => $newHash,
  ':id'   => (int)$row['id']
]);

$rolapp = strtoupper(trim((string)($row['rolapp'] ?? '')));
$rol    = strtoupper(trim((string)($row['rol'] ?? '')));

/* =========================================================
   REDIRECCION POSTERIOR AL CAMBIO DE CONTRASEÑA
   ---------------------------------------------------------
   REGLA ACTUAL:
   - Por ahora todos los usuarios PATS regresan a pats/index.php
   - Más adelante puedes abrir rutas específicas por rolapp
     (FRANQPATS, DISTPATS, GESTORPATS, MED, LAB, RX, FAV, etc.)
   - Si aparecen más rolapp en el futuro, solo agrega un case
========================================================= */
$redirect = 'index.php';

/*
|--------------------------------------------------------------------------
| ESCALAMIENTO FUTURO POR ROLAPP
|--------------------------------------------------------------------------
| Dejar comentado por ahora.
| Cuando quieras rutas específicas, puedes activar algo así:
|--------------------------------------------------------------------------
switch ($rolapp) {
  case 'ADMINPATS':
  case 'FINPATS':
    $redirect = '../../patsfin/index.php';
    break;

  case 'FRANQPATS':
    $redirect = '../franquicia.php';
    break;

  case 'DISTPATS':
    $redirect = '../distribuidor.php';
    break;

  case 'GESTORPATS':
    $redirect = '../gestor.php';
    break;

  case 'MED':
    $redirect = '../medico.php';
    break;

  case 'LAB':
    $redirect = '../lab.php';
    break;

  case 'RX':
    $redirect = '../rx.php';
    break;

  case 'FAV':
    $redirect = '../favoritos.php';
    break;

  default:
    $redirect = '../index.php';
    break;
}
*/

/*
|--------------------------------------------------------------------------
| ESCALAMIENTO FUTURO POR ROL
|--------------------------------------------------------------------------
| También puedes complementar por rol si algún usuario no trae
| rolapp consistente todavía.
|--------------------------------------------------------------------------
if (in_array($rol, ['ADMIN', 'DIRO', 'DIRG', 'VIC'], true)) {
  $redirect = '../../patsfin/index.php';
}
*/

jout([
  'ok' => true,
  'redirect' => $redirect
]);