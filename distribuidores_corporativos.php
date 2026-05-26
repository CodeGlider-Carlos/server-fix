<?php
/*
ez/pats/distribuidores_corporativos.php
Módulo: PATS
Propósito: Vista corporativa para ubicar distribuidores directos de ADMINPATS.
Responsabilidad: Mostrar filtros, KPIs y listado de distribuidores sin franquicia asociada, con acceso directo al dashboard individual del distribuidor.
Conexiones: varSQL/bd_pats.php, varSQL/var_pats.php, endpoints/distribuidores_corporativos_listar.php, js/pats_distribuidores_corporativos.js.
Tipo: Vista específica de PATS para ADMIN/ADMINPATS y roles directivos autorizados.
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
$adminrol   = strtoupper(trim((string)($_SESSION['rol'] ?? '')));
$rolapp     = strtoupper(trim((string)($_SESSION['rolapp'] ?? '')));

$userLog    = trim((string)($_SESSION['usuario'] ?? ''));
$userName   = trim((string)($_SESSION['nombre'] ?? $userLog));
$userRegion = trim((string)($_SESSION['acroregion'] ?? ($_SESSION['region'] ?? '')));
$userUnidad = trim((string)($_SESSION['acronu'] ?? ($_SESSION['unidad'] ?? '')));

$rolesAdmin = ['ADMIN', 'ADMINPATS', 'DIRO', 'DIRG', 'VIC'];

if (!in_array($adminrol, $rolesAdmin, true) && !in_array($rolapp, $rolesAdmin, true)) {
  header('Location: index.php');
  exit;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ADMIN PATS · Distribuidores corporativos</title>
  <link rel="icon" type="image/x-icon" href="../../img/ez.ico" />
  <link rel="stylesheet" href="../../css/index.css?v=<?= $ver ?>">
  <link rel="stylesheet" href="../../css/gloval_responsive.css?v=<?= $ver ?>">
  <link rel="stylesheet" href="css/pats.css?v=<?= $ver ?>">
  <link rel="stylesheet" href="css/pats_responsive.css?v=<?= $ver ?>">
  <link rel="stylesheet" href="css/pats_distribuidores_corporativos.css?v=<?= $ver ?>">
</head>
<body>

<?php require_once '../../loglog/contador.php'; ?>

<div id="cabecera">
  <div class="bienvenido">ADMIN PATS</div>
  <?php require_once '../nav/noti_user_pats.php'; ?>
</div>

<?php require_once '../nav/nav_mod.php'; ?>

<content class="back_content">
  <div class="pats-wrap pats-view-admin pats-view-distcorp">

    <section class="pats-hero pats-distcorp-hero">
      <div class="pats-hero__left">
        <div class="pats-kicker">EZHS · PATS · ADMINPATS DIRECTO</div>
        <h1 class="pats-title">Distribuidores corporativos</h1>
        <p class="pats-subtitle">
          Ubica los distribuidores dados de alta directamente por ADMINPATS, revisa sus ventas PATS, contrato, saldo pendiente y entra a su dashboard individual.
        </p>

        <div class="pats-hero__chips">
          <button type="button" class="pats-chip pats-chip--action" id="btnVolverAdminPats">
            ← Volver al dashboard global
          </button>
          <button type="button" class="pats-chip pats-chip--action" id="btnGenerarLinkDistribucionCorp">
            🔗 Generar link de alta DISTRIBUIDOR
          </button>
        </div>
      </div>
    </section>

    <section class="pats-filters pats-distcorp-filters">
      <div class="pats-field">
        <label for="distCorpQ">Buscar distribuidor</label>
        <input type="text" id="distCorpQ" placeholder="Nombre, correo, teléfono, región...">
      </div>

      <div class="pats-field">
        <label for="distCorpRegion">Región</label>
        <input type="text" id="distCorpRegion" placeholder="Todas">
      </div>

      <div class="pats-field">
        <label for="distCorpZona">Zona</label>
        <input type="text" id="distCorpZona" placeholder="Todas">
      </div>

      <div class="pats-field">
        <label for="distCorpAnio">Año</label>
        <input type="number" id="distCorpAnio" min="2020" max="2100" value="<?= date('Y') ?>">
      </div>

      <div class="pats-field">
        <label for="distCorpMes">Mes</label>
        <select id="distCorpMes">
          <option value="">Todos</option>
          <option value="1">Enero</option>
          <option value="2">Febrero</option>
          <option value="3">Marzo</option>
          <option value="4">Abril</option>
          <option value="5">Mayo</option>
          <option value="6">Junio</option>
          <option value="7">Julio</option>
          <option value="8">Agosto</option>
          <option value="9">Septiembre</option>
          <option value="10">Octubre</option>
          <option value="11">Noviembre</option>
          <option value="12">Diciembre</option>
        </select>
      </div>

      <div class="pats-field pats-field--button">
        <label>&nbsp;</label>
        <button type="button" class="pats-chip pats-chip--action" id="btnDistCorpRecargar">Recargar</button>
      </div>
    </section>

    <section class="pats-card pats-kpi-block pats-distcorp-summary">
      <div class="pats-card__head">
        <h3>Resumen corporativo</h3>
      </div>
      <div class="pats-admin-subgrid pats-admin-subgrid--3">
        <article class="pats-kpi-card">
          <span class="k">Distribuidores directos</span>
          <strong id="distCorpTotal">0</strong>
          <small>Sin franquicia asociada</small>
        </article>
        <article class="pats-kpi-card">
          <span class="k">Venta de distribuciones</span>
          <strong id="distCorpVentaDistribuciones">$0</strong>
          <small>Valor registrado de altas corporativas</small>
        </article>
        <article class="pats-kpi-card">
          <span class="k">PATS vendidos</span>
          <strong id="distCorpPatsVendidos">0</strong>
          <small id="distCorpPatsMontoNote">Monto PATS $0</small>
        </article>
        <article class="pats-kpi-card">
          <span class="k">Dinero recibido</span>
          <strong id="distCorpCobrado">$0</strong>
          <small>Pagos registrados de contratos</small>
        </article>
        <article class="pats-kpi-card">
          <span class="k">Por cobrar</span>
          <strong id="distCorpPorCobrar">$0</strong>
          <small>Saldos pendientes de contrato</small>
        </article>
        <article class="pats-kpi-card">
          <span class="k">Comisiones distribuidor</span>
          <strong id="distCorpComisiones">$0</strong>
          <small>Por PATS vendidos</small>
        </article>
      </div>
    </section>

    <section class="pats-card pats-distcorp-list-card">
      <div class="pats-card__head pats-distcorp-list-head">
        <div>
          <h3>Distribuidores directos ADMINPATS</h3>
          <p>Selecciona un distribuidor para abrir su dashboard individual.</p>
        </div>
      </div>
      <div class="pats-distcorp-list" id="distCorpList">
        <div class="pats-empty-state">Cargando distribuidores corporativos...</div>
      </div>
    </section>

  </div>
</content>

<div id="patsToastHost" class="pats-toast-host"></div>

<script>
window.PATS_CONTEXT = {
  view: 'distribuidores_corporativos',
  rol: <?= json_encode($adminrol, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  rolapp: <?= json_encode($rolapp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  usuario: <?= json_encode($userLog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  nombre: <?= json_encode($userName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  region: <?= json_encode($userRegion, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  unidad: <?= json_encode($userUnidad, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
};
</script>
<script src="js/pats_distribuidores_corporativos.js?v=<?= $ver ?>"></script>
</body>
</html>
