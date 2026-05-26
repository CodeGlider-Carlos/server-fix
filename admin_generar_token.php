<?php
/*
  admin_generar_token.php
  Genera tokens de un solo uso para recuperar altas PATS sin pago.
  Protegido por PATS_RECOVERY_KEY definido en config/config.php.
*/
declare(strict_types=1);

$stripeConfig = __DIR__ . '/config/config.php';
if (is_file($stripeConfig)) require_once $stripeConfig;

if (!defined('PATS_RECOVERY_KEY') || PATS_RECOVERY_KEY === '' || PATS_RECOVERY_KEY === 'CAMBIA_ESTA_CLAVE_SEGURA') {
    die('<b>Error:</b> Define <code>PATS_RECOVERY_KEY</code> en <code>config/config.php</code> con una clave segura antes de usar esta herramienta.');
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../varSQL/bd_pats.php';
require_once __DIR__ . '/../../varSQL/var_pats.php';
$cx = $conexionMod ?? $db_all2 ?? $db_all ?? null;

$error      = '';
$tokenClaro = '';
$expira     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminKey = trim((string)($_POST['admin_key'] ?? ''));

    if (!hash_equals((string)PATS_RECOVERY_KEY, $adminKey)) {
        $error = 'Clave de administrador incorrecta.';
    } elseif (!$cx instanceof mysqli) {
        $error = 'Sin conexión a base de datos.';
    } else {
        $tokenClaro = strtoupper(bin2hex(random_bytes(8))); // 16 chars
        $tokenHash  = hash('sha256', $tokenClaro);
        $expiraAt   = date('Y-m-d H:i:s', strtotime('+24 hours'));
        $ip         = (string)($_SERVER['REMOTE_ADDR'] ?? '');

        $stmt = $cx->prepare("INSERT INTO pats_tokens_recuperacion (token_hash, expira_at, ip_creacion) VALUES (?,?,?)");
        if ($stmt) {
            $stmt->bind_param('sss', $tokenHash, $expiraAt, $ip);
            if ($stmt->execute()) {
                $expira = $expiraAt;
            } else {
                $error      = 'No fue posible guardar el token: ' . $stmt->error;
                $tokenClaro = '';
            }
            $stmt->close();
        } else {
            $error      = 'Error de BD: ' . $cx->error;
            $tokenClaro = '';
        }
    }
}
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Generar Token — Recuperación PATS</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,sans-serif;background:#f1f5f9;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
  .card{background:#fff;border-radius:16px;padding:32px;max-width:440px;width:100%;box-shadow:0 4px 24px rgba(0,0,0,.10)}
  .logo{font-size:1rem;font-weight:700;color:#2563eb;margin-bottom:4px}
  h1{font-size:1.15rem;font-weight:700;color:#1e293b;margin-bottom:20px}
  label{display:block;font-size:.8rem;font-weight:600;color:#475569;margin-bottom:6px}
  input[type=password]{width:100%;padding:11px 14px;border:1.5px solid #d1d5db;border-radius:8px;font-size:1rem;outline:none}
  input[type=password]:focus{border-color:#2563eb}
  button{margin-top:14px;width:100%;padding:13px;background:#2563eb;color:#fff;border:none;border-radius:8px;font-size:.95rem;font-weight:600;cursor:pointer}
  button:hover{background:#1d4ed8}
  .token-box{background:#f0fdf4;border:2px solid #22c55e;border-radius:12px;padding:24px;margin-top:20px;text-align:center}
  .token-code{font-size:2.2rem;font-family:monospace;font-weight:700;color:#15803d;letter-spacing:6px;margin:10px 0}
  .token-meta{font-size:.8rem;color:#4b7c59;margin-top:4px}
  .warn-box{background:#fffbeb;border:1.5px solid #fbbf24;border-radius:8px;padding:12px 14px;font-size:.8rem;color:#92400e;margin-top:14px}
  .error{background:#fef2f2;border:1.5px solid #f87171;border-radius:8px;padding:12px;color:#b91c1c;font-size:.875rem;margin-bottom:16px}
  .sep{border:none;border-top:1px solid #e2e8f0;margin:24px 0}
  a{color:#2563eb;font-size:.85rem}
</style>
</head>
<body>
<div class="card">
  <div class="logo">PATS · Admin</div>
  <h1>Generar token de recuperación</h1>

  <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if ($tokenClaro): ?>
    <div class="token-box">
      <div style="font-size:.85rem;font-weight:600;color:#166534">Token generado — cópialo ahora, no se volverá a mostrar</div>
      <div class="token-code"><?= htmlspecialchars($tokenClaro) ?></div>
      <div class="token-meta">Válido 24 h · Un solo uso · Expira: <?= htmlspecialchars($expira) ?></div>
    </div>
    <div class="warn-box">Comparte este código solo con el operador autorizado. Una vez usado quedará inválido.</div>
    <hr class="sep">
    <p style="font-size:.85rem;color:#64748b;margin-bottom:12px">¿Necesitas otro token?</p>
  <?php endif; ?>

  <form method="POST">
    <label for="admin_key">Clave de administrador</label>
    <input type="password" id="admin_key" name="admin_key" placeholder="••••••••" required autofocus>
    <button type="submit"><?= $tokenClaro ? 'Generar otro token' : 'Generar token' ?></button>
  </form>

  <hr class="sep">
  <a href="recuperar_pats.php">→ Ir al formulario de recuperación</a>
</div>
</body>
</html>
