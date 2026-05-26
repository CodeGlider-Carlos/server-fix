<?php
/*
ez/pats/solicitud_pago.php
*/
session_start();
require_once '../../varSQL/bd_pats.php';
require_once '../../varSQL/var_pats.php';

if (empty($_SESSION['usuario'])) {
  session_destroy();
  header("Location: ../../../index.php");
  exit;
}

$ver = time();
$userLog = trim($_SESSION['usuario'] ?? '');
$userName = trim($_SESSION['nombre'] ?? $userLog);

$cx = $conexionMod ?? $db_all2 ?? $db_all ?? null;
if (!$cx || !($cx instanceof mysqli)) {
  die('No hay conexión mysqli disponible');
}

function h($v): string {
  return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

$idDistribuidor = (int)($_GET['id_distribuidor'] ?? 0);
$idFranquicia = (int)($_GET['id_franquicia'] ?? 0);
$idPasaporte = (int)($_GET['id_pasaporte'] ?? 0);

$dist = null;

/* =========================================================
   Resolver distribuidor actual
========================================================= */
if ($idDistribuidor > 0) {
  $sql = "
    SELECT
      d.id_distribuidor,
      d.nombre AS nombre_distribuidor,
      d.correo,
      d.telefono,
      d.region,
      d.zona,
      d.unidad,
      d.id_franquicia,
      f.nombre_franquicia,
      f.pais
    FROM pats_distribuidores d
    LEFT JOIN pats_franquicias f
      ON f.id_franquicia = d.id_franquicia
    WHERE d.id_distribuidor = ?
    LIMIT 1
  ";
  $stmt = $cx->prepare($sql);
  if ($stmt) {
    $stmt->bind_param('i', $idDistribuidor);
    $stmt->execute();
    $rs = $stmt->get_result();
    $dist = $rs ? $rs->fetch_assoc() : null;
    $stmt->close();
  }
}

if (!$dist) {
  $sql = "
    SELECT
      d.id_distribuidor,
      d.nombre AS nombre_distribuidor,
      d.correo,
      d.telefono,
      d.region,
      d.zona,
      d.unidad,
      d.id_franquicia,
      f.nombre_franquicia,
      f.pais
    FROM pats_users u
    INNER JOIN pats_distribuidores d
      ON d.id_distribuidor = u.id_actor
     AND UPPER(TRIM(u.tipo_actor)) = 'DISTRIBUIDOR'
    LEFT JOIN pats_franquicias f
      ON f.id_franquicia = d.id_franquicia
    WHERE u.usuario = ?
       OR u.correo = ?
    LIMIT 1
  ";
  $stmt = $cx->prepare($sql);
  if ($stmt) {
    $stmt->bind_param('ss', $userLog, $userLog);
    $stmt->execute();
    $rs = $stmt->get_result();
    $dist = $rs ? $rs->fetch_assoc() : null;
    $stmt->close();
  }
}

if (!$dist) {
  die('No fue posible identificar al distribuidor actual');
}

/* =========================================================
   Si viene id_pasaporte, cargarlo (renovación / pago existente)
========================================================= */
$pasaporte = null;
if ($idPasaporte > 0) {
  $stmt = $cx->prepare("
    SELECT *
    FROM pats_pasaportes
    WHERE id_pasaporte = ?
    LIMIT 1
  ");
  if ($stmt) {
    $stmt->bind_param('i', $idPasaporte);
    $stmt->execute();
    $rs = $stmt->get_result();
    $pasaporte = $rs ? $rs->fetch_assoc() : null;
    $stmt->close();
  }
}

$cfgMontos = [
  'ANUAL' => 9600,
  'MENSUAL' => 800
];

$estadoDefault = strtoupper((string)($dist['region'] ?? ''));
$paisDefault = (string)($dist['pais'] ?? 'México');
$zonaDefault = (string)($dist['zona'] ?? '');
$unidadDefault = (string)($dist['unidad'] ?? '');

$tipoClienteDefault = strtolower((string)($pasaporte['tipo_cliente'] ?? 'privado'));
$empresaDefault = (string)($pasaporte['nombre_empresa'] ?? '');

$idTipoPrecioDefault = 1;
if (!empty($pasaporte['id_tipo_precio'])) {
  $idTipoPrecioDefault = (int)$pasaporte['id_tipo_precio'];
} elseif (!empty($pasaporte['frecuencia_pago']) && strtolower((string)$pasaporte['frecuencia_pago']) === 'mensual') {
  $idTipoPrecioDefault = 2;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PATS · Solicitud de pago</title>
  <link rel="icon" type="image/x-icon" href="../../img/ez.ico" />
  <link rel="stylesheet" href="../../css/index.css?v=<?= $ver ?>">
  <link rel="stylesheet" href="../../css/gloval_responsive.css?v=<?= $ver ?>">
  <link rel="stylesheet" href="css/pats.css?v=<?= $ver ?>">
  <link rel="stylesheet" href="css/pats_responsive.css?v=<?= $ver ?>">
  <style>
    .pats-pay-wrap{max-width:1180px;margin:0 auto;padding:18px}
    .pats-pay-grid{display:grid;grid-template-columns:360px 1fr;gap:18px}
    .pats-pay-card{background:#fff;border:1px solid rgba(150,160,190,.16);border-radius:22px;box-shadow:0 16px 40px rgba(12,22,52,.08);overflow:hidden}
    .pats-pay-card__head{padding:16px 18px;border-bottom:1px solid rgba(180,190,215,.18);background:linear-gradient(135deg, rgba(10,34,54,.98), rgba(18,92,116,.98));color:#fff}
    .pats-pay-card__head h3{margin:0;font-size:20px;font-weight:900}
    .pats-pay-card__body{padding:18px}
    .pats-pay-kv{display:grid;gap:10px}
    .pats-pay-kv div{display:flex;justify-content:space-between;gap:12px;padding:10px 12px;border-radius:12px;background:#f7f9fd}
    .pats-pay-fields{display:grid;grid-template-columns:1fr 1fr;gap:14px 16px}
    .pats-pay-fields .full{grid-column:1/-1}
    .pats-pay-actions{display:flex;justify-content:flex-end;gap:12px;flex-wrap:wrap;margin-top:18px}
    .pats-btn{border:0;border-radius:14px;padding:12px 16px;font-weight:800;cursor:pointer;min-height:44px}
    .pats-btn--ghost{background:#eef2fb;color:#18336a}
    .pats-btn--primary{background:linear-gradient(135deg, rgba(10,34,54,.98), rgba(18,92,116,.98));color:#fff}
    .pats-pay-result[hidden]{display:none!important}
    #patsToastHost{position:fixed;top:14px;right:14px;z-index:99999;display:grid;gap:8px;pointer-events:none}
    .pats-inline-note{margin-top:10px;font-size:12px;color:#5f6f8b}
    @media (max-width:960px){
      .pats-pay-grid{grid-template-columns:1fr}
      .pats-pay-fields{grid-template-columns:1fr}
    }
  </style>
</head>
<body>
<?php require_once '../../loglog/contador.php'; ?>
<div id="cabecera">
  <div class="bienvenido">PATS · Solicitud de pago</div>
  <?php require_once '../nav/noti_user_pats.php'; ?>
</div>
<?php require_once '../nav/nav_mod.php'; ?>

<content class="back_content">
  <div class="pats-pay-wrap">

    <section class="pats-hero">
      <div class="pats-hero__left">
        <div class="pats-kicker">PATS · CHECKOUT OPERATIVO</div>
        <h1 class="pats-title"><?= $pasaporte ? 'Renovación / pago de PATS' : 'Nueva solicitud de pago' ?></h1>
        <p class="pats-subtitle">
          Genera una orden trazable para pago PATS y continúa al checkout público.
        </p>
      </div>
    </section>

    <div class="pats-pay-grid">
      <aside class="pats-pay-card">
        <div class="pats-pay-card__head"><h3>Contexto comercial</h3></div>
        <div class="pats-pay-card__body">
          <div class="pats-pay-kv">
            <div><span>Franquicia</span><strong><?= h($dist['nombre_franquicia']) ?></strong></div>
            <div><span>Distribuidor</span><strong><?= h($dist['nombre_distribuidor']) ?></strong></div>
            <div><span>País</span><strong><?= h($paisDefault) ?></strong></div>
            <div><span>Región</span><strong><?= h($estadoDefault) ?></strong></div>
            <div><span>Zona</span><strong><?= h($zonaDefault) ?></strong></div>
            <div><span>Unidad</span><strong><?= h($unidadDefault) ?></strong></div>
          </div>

          <?php if ($pasaporte): ?>
            <div class="pats-inline-note">
              Se está generando una orden ligada al pasaporte existente #<?= h($pasaporte['id_pasaporte']) ?>.
            </div>
          <?php else: ?>
            <div class="pats-inline-note">
              Esta orden servirá para alta inicial de PATS después del pago confirmado.
            </div>
          <?php endif; ?>
        </div>
      </aside>

      <section class="pats-pay-card">
        <div class="pats-pay-card__head"><h3>Datos para la orden</h3></div>
        <div class="pats-pay-card__body">
          <form id="frmOrdenPagoPats" novalidate>
            <div class="pats-pay-fields">
              <div class="full">
                <label for="nombre_usuario">Nombre(s)</label>
                <input type="text" id="nombre_usuario" name="nombre_usuario" required value="<?= h($pasaporte['nombres'] ?? '') ?>">
              </div>

              <div>
                <label for="apellido_pa">Apellido paterno</label>
                <input type="text" id="apellido_pa" name="apellido_pa" required value="<?= h($pasaporte['apellido_pa'] ?? '') ?>">
              </div>

              <div>
                <label for="apellido_ma">Apellido materno</label>
                <input type="text" id="apellido_ma" name="apellido_ma" value="<?= h($pasaporte['apellido_ma'] ?? '') ?>">
              </div>

              <div>
                <label for="fecha_nacimiento">Fecha de nacimiento</label>
                <input type="date" id="fecha_nacimiento" name="fecha_nacimiento" required value="<?= h($pasaporte['fecha_nacimiento'] ?? '') ?>">
              </div>

              <div>
                <label for="curp_usuario">CURP</label>
                <input type="text" id="curp_usuario" name="curp_usuario" maxlength="18" required value="<?= h($pasaporte['curp'] ?? '') ?>">
              </div>

              <div>
                <label for="correo_usuario_pats">Correo del usuario PATS</label>
                <input type="email" id="correo_usuario_pats" name="correo_usuario_pats" required value="<?= h($pasaporte['correo'] ?? '') ?>">
              </div>

              <div>
                <label for="telefono_usuario">Teléfono</label>
                <input type="text" id="telefono_usuario" name="telefono_usuario" maxlength="10" inputmode="numeric" required value="<?= h($pasaporte['telefono'] ?? '') ?>">
              </div>

              <div>
                <label for="tipo_cliente">Tipo de cliente</label>
                <select id="tipo_cliente" name="tipo_cliente" required>
                  <option value="privado" <?= $tipoClienteDefault === 'privado' ? 'selected' : '' ?>>Privado</option>
                  <option value="empresa" <?= $tipoClienteDefault === 'empresa' ? 'selected' : '' ?>>Empresa</option>
                </select>
              </div>

              <div class="full" id="wrapNombreEmpresa">
                <label for="nombre_empresa">Nombre de empresa</label>
                <input type="text" id="nombre_empresa" name="nombre_empresa" value="<?= h($empresaDefault) ?>">
              </div>

              <div>
                <label for="frecuencia">Frecuencia</label>
                <select id="frecuencia" name="frecuencia" required>
                  <option value="ANUAL" <?= (isset($pasaporte['frecuencia_pago']) && strtolower((string)$pasaporte['frecuencia_pago']) === 'anual') ? 'selected' : '' ?>>Anual</option>
                  <option value="MENSUAL" <?= (isset($pasaporte['frecuencia_pago']) && strtolower((string)$pasaporte['frecuencia_pago']) === 'mensual') ? 'selected' : '' ?>>Mensual</option>
                </select>
              </div>

              <div>
                <label for="monto_orden">Monto</label>
                <input type="number" id="monto_orden" name="monto_orden" readonly>
              </div>

              <div>
                <label for="pais">País</label>
                <input type="text" id="pais" name="pais" readonly value="<?= h($paisDefault) ?>">
              </div>

              <div>
                <label for="region">Región</label>
                <input type="text" id="region" name="region" readonly value="<?= h($estadoDefault) ?>">
              </div>

              <div>
                <label for="zona">Zona</label>
                <input type="text" id="zona" name="zona" readonly value="<?= h($zonaDefault) ?>">
              </div>

              <div>
                <label for="unidad">Unidad</label>
                <input type="text" id="unidad" name="unidad" readonly value="<?= h($unidadDefault) ?>">
              </div>
            </div>

            <input type="hidden" name="tipo_origen" value="DISTRIBUIDOR">
            <input type="hidden" name="origen_checkout" value="<?= $pasaporte ? 'PORTAL_PATS' : 'OPERATIVO_DISTRIBUIDOR' ?>">
            <input type="hidden" name="tipo_operacion" value="<?= $pasaporte ? 'RENOVACION_PATS' : 'ALTA_PATS' ?>">
            <input type="hidden" name="id_distribuidor" value="<?= h($dist['id_distribuidor']) ?>">
            <input type="hidden" name="id_franquicia" value="<?= h($dist['id_franquicia']) ?>">
            <input type="hidden" name="id_pasaporte" value="<?= h($pasaporte['id_pasaporte'] ?? 0) ?>">
            <input type="hidden" name="id_tipo_precio" id="id_tipo_precio" value="<?= h($idTipoPrecioDefault) ?>">
            <input type="hidden" name="moneda" value="MXN">

            <div class="pats-pay-actions">
              <a href="distribuidor.php?id_distribuidor=<?= urlencode((string)$dist['id_distribuidor']) ?>&id_franquicia=<?= urlencode((string)$dist['id_franquicia']) ?>" class="pats-btn pats-btn--ghost" style="text-decoration:none;display:inline-flex;align-items:center;">Cancelar</a>
              <button type="submit" class="pats-btn pats-btn--primary">Generar orden</button>
            </div>
          </form>

          <div class="pats-pay-result" id="patsPayResult" hidden style="margin-top:18px;">
            <div class="pats-pay-kv">
              <div><span>Folio</span><strong id="payFolio">-</strong></div>
              <div><span>Referencia</span><strong id="payRef">-</strong></div>
            </div>

            <div style="margin-top:14px;">
              <label for="payPublicLink" style="display:block;font-weight:800;margin-bottom:8px;">Link de pago para cliente</label>
              <input type="text" id="payPublicLink" readonly style="width:100%;">
            </div>

            <div class="pats-pay-actions">
              <button type="button" id="btnCopiarLink" class="pats-btn pats-btn--ghost">Copiar link</button>
              <a href="#" id="btnAbrirPublico" class="pats-btn pats-btn--ghost" style="text-decoration:none;display:inline-flex;align-items:center;">Abrir link público</a>
              <a href="#" id="btnIrCheckout" class="pats-btn pats-btn--primary" style="text-decoration:none;display:inline-flex;align-items:center;">Ir al checkout interno</a>
            </div>
          </div>
        </div>
      </section>
    </div>

  </div>
</content>

<script>
window.PATS_ORDEN_CFG = {
  monto_anual: 9600,
  monto_mensual: 800
};
window.PATS_DISTRIBUIDOR_CTX = <?= json_encode($dist, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.PATS_PASAPORTE_CTX = <?= json_encode($pasaporte, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="js/pats_solicitud_pago.js?v=<?= $ver ?>"></script>
</body>
</html>