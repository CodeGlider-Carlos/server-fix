<?php
session_start();
require_once '../../varSQL/bd_pats.php';
require_once '../../varSQL/var_pats.php';

if (empty($_SESSION['usuario'])) {
  session_destroy();
  header("Location: ../../../index.php");
  exit;
}

$ver = time();
$folio = trim($_GET['folio'] ?? '');

$cx = $conexionMod ?? $db_all2 ?? $db_all ?? null;
if (!$cx || !($cx instanceof mysqli)) {
  die('No hay conexión mysqli disponible');
}

$stmt = $cx->prepare("
  SELECT *
  FROM pats_ordenes_pago
  WHERE folio_orden = ?
  LIMIT 1
");
if (!$stmt) {
  die('No fue posible preparar consulta de orden');
}
$stmt->bind_param('s', $folio);
$stmt->execute();
$rs = $stmt->get_result();
$orden = $rs ? $rs->fetch_assoc() : null;
$stmt->close();

if (!$orden) {
  die('Orden no encontrada');
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PATS · Checkout</title>
  <link rel="icon" type="image/x-icon" href="../../img/ez.ico" />
  <link rel="stylesheet" href="../../css/index.css?v=<?= $ver ?>">
  <style>
    body{background:#f5f7fb}
    .pay-shell{max-width:760px;margin:0 auto;padding:24px}
    .pay-card{background:#fff;border-radius:24px;box-shadow:0 20px 48px rgba(12,22,52,.10);overflow:hidden}
    .pay-head{padding:18px 20px;background:linear-gradient(135deg, rgba(10,34,54,.98), rgba(18,92,116,.98));color:#fff}
    .pay-body{padding:22px}
    .pay-kv{display:grid;gap:12px}
    .pay-kv div{display:flex;justify-content:space-between;gap:12px;padding:12px 14px;border-radius:14px;background:#f7f9fd}
    .pay-actions{display:flex;justify-content:flex-end;gap:12px;margin-top:18px}
    .pay-btn{border:0;border-radius:14px;padding:12px 16px;font-weight:800;text-decoration:none;display:inline-flex;align-items:center}
    .pay-btn--ghost{background:#eef2fb;color:#18336a}
    .pay-btn--primary{background:linear-gradient(135deg, rgba(10,34,54,.98), rgba(18,92,116,.98));color:#fff}
  </style>
</head>
<body>
  <div class="pay-shell">
    <div class="pay-card">
      <div class="pay-head">
        <h2 style="margin:0;">Checkout PATS</h2>
      </div>
      <div class="pay-body">
        <div class="pay-kv">
          <div><span>Folio</span><strong><?= htmlspecialchars((string)$orden['folio_orden']) ?></strong></div>
          <div><span>Referencia</span><strong><?= htmlspecialchars((string)$orden['referencia_pago']) ?></strong></div>
          <div><span>Usuario</span><strong><?= htmlspecialchars((string)$orden['nombre_usuario']) ?></strong></div>
          <div><span>Correo</span><strong><?= htmlspecialchars((string)$orden['correo_usuario_pats']) ?></strong></div>
          <div><span>Frecuencia</span><strong><?= htmlspecialchars((string)$orden['frecuencia']) ?></strong></div>
          <div><span>Monto</span><strong>$<?= number_format((float)$orden['monto_orden'], 2) ?></strong></div>
        </div>

        <div class="pay-actions">
          <a href="solicitud_pago.php" class="pay-btn pay-btn--ghost">Volver</a>
          <a href="#" class="pay-btn pay-btn--primary">Conectar pasarela aquí</a>
        </div>
      </div>
    </div>
  </div>
</body>
</html>