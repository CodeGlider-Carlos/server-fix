<?php
/*
ez/pats/mis_franquicias.php
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
$adminrol   = strtoupper(trim($_SESSION['rol'] ?? ''));
$userLog    = trim($_SESSION['usuario'] ?? '');
$userName   = trim($_SESSION['nombre'] ?? $userLog);
$userRegion = trim($_SESSION['acroregion'] ?? ($_SESSION['region'] ?? ''));
$userUnidad = trim($_SESSION['acronu'] ?? ($_SESSION['unidad'] ?? ''));
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PATS — Mis Franquicias</title>
  <link rel="icon" type="image/x-icon" href="../../img/ez.ico" />
  <link rel="stylesheet" href="../../css/index.css?v=<?= $ver ?>">
  <link rel="stylesheet" href="../../css/gloval_responsive.css?v=<?= $ver ?>">
  <link rel="stylesheet" href="css/pats.css?v=<?= $ver ?>">
  <link rel="stylesheet" href="css/pats_responsive.css?v=<?= $ver ?>">
</head>
<body>
<?php require_once '../../loglog/contador.php'; ?>

<div id="cabecera">
  <div class="bienvenido">PATS · Mis Franquicias</div>
  <?php require_once '../nav/noti_user_pats.php'; ?>
</div>

<?php require_once '../nav/nav_mod.php'; ?>

<content class="back_content">
  <div class="pats-wrap pats-view-mis-franquicias">

    <section class="pats-hero">
      <div class="pats-hero__left">
        <div class="pats-kicker">PATS · FRANQUICIAS POR REGIÓN</div>
        <h1 class="pats-title">Mis Franquicias</h1>
        <p class="pats-subtitle">
          Consulta las franquicias de la región seleccionada y compara su desempeño antes de entrar al detalle.
        </p>
        <div class="pats-hero__chips">
          <button type="button" class="pats-chip pats-chip--action" id="btnPatsBackAdmin">← Volver a admin</button>
        </div>
      </div>

      <div class="pats-hero__right">
        <div class="pats-meta-card">
          <span class="k">Región</span>
          <span class="v" id="patsMisFranqRegionLabel">-</span>
        </div>
        <div class="pats-meta-card">
          <span class="k">Franquicias</span>
          <span class="v" id="patsMisFranqTotal">0</span>
        </div>
      </div>
    </section>

    <section class="pats-filters pats-filters--compact pats-filters--4">
      <div class="pats-field">
        <label for="patsRegion">Región</label>
        <select id="patsRegion"><option value="">Selecciona...</option></select>
      </div>

      <div class="pats-field">
        <label for="patsZona">Zona</label>
        <select id="patsZona"><option value="">Selecciona...</option></select>
      </div>

      <div class="pats-field">
        <label for="patsAnio">Año</label>
        <input type="number" id="patsAnio" min="2020" max="2100" value="<?= date('Y') ?>">
      </div>

      <div class="pats-field">
        <label for="patsMes">Mes</label>
        <select id="patsMes">
          <option value="">Todos</option>
          <option value="1">Enero</option>
          <option value="2">Febrero</option>
          <option value="3">Febrero</option>
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
    </section>

    <div class="pats-grid pats-grid--mis-franquicias">
      <aside class="pats-panel pats-panel--left">
        <div class="pats-panel__head">
          <div>
            <h3>Franquicias</h3>
            <p>Selecciona una franquicia para consultar su detalle completo.</p>
          </div>
        </div>

        <div class="pats-search-wrap">
          <input type="text" id="patsSearchFranquicia" placeholder="Buscar franquicia...">
        </div>

        <div class="pats-left-list" id="patsFranquiciaList">
          <div class="pats-empty-state">Cargando franquicias...</div>
        </div>
      </aside>

      <section class="pats-panel pats-panel--right">
        <div class="pats-kpis" id="patsMisFranqKpis">
          <article class="pats-kpi-card"><span class="k">Ventas totales</span><strong>$0</strong></article>
          <article class="pats-kpi-card"><span class="k">Ventas PATS</span><strong>$0</strong></article>
          <article class="pats-kpi-card"><span class="k">Ingreso extra</span><strong>$0</strong></article>
          <article class="pats-kpi-card"><span class="k">PATS activos</span><strong>0</strong></article>
        </div>

        <div class="pats-chart-row">
          <article class="pats-card pats-chart-card">
            <div class="pats-card__head"><h3>Ventas totales por franquicia</h3></div>
            <div class="pats-card__body pats-card__body--chart">
              <canvas id="patsMisFranqChart1"></canvas>
              <div class="pats-card__empty" id="patsMisFranqChart1Empty" hidden>Sin datos para mostrar.</div>
            </div>
          </article>

          <article class="pats-card pats-chart-card">
            <div class="pats-card__head"><h3>Activos vs vencidos</h3></div>
            <div class="pats-card__body pats-card__body--chart">
              <canvas id="patsMisFranqChart2"></canvas>
              <div class="pats-card__empty" id="patsMisFranqChart2Empty" hidden>Sin datos para mostrar.</div>
            </div>
          </article>
        </div>

        <div class="pats-ranking-row pats-ranking-row--triple">
          <article class="pats-card">
            <div class="pats-card__head"><h3>Top 5 franquicias con más ventas</h3></div>
            <div class="pats-ranking-list" id="patsRankingVentasFranquicia">
              <div class="pats-empty-inline">Cargando...</div>
            </div>
          </article>

          <article class="pats-card">
            <div class="pats-card__head"><h3>Top 5 franquicias con más PATS activos</h3></div>
            <div class="pats-ranking-list" id="patsRankingActivosFranquicia">
              <div class="pats-empty-inline">Cargando...</div>
            </div>
          </article>

          <article class="pats-card">
            <div class="pats-card__head"><h3>Top 5 franquicias con más PATS vencidos</h3></div>
            <div class="pats-ranking-list" id="patsRankingVencidosFranquicia">
              <div class="pats-empty-inline">Cargando...</div>
            </div>
          </article>
        </div>
      </section>
    </div>

  </div>
</content>

<script>
window.PATS_CONTEXT = {
  view: 'mis_franquicias',
  rol: <?= json_encode($adminrol, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  usuario: <?= json_encode($userLog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  nombre: <?= json_encode($userName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  region: <?= json_encode($userRegion, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  unidad: <?= json_encode($userUnidad, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
};
</script>

<script src="../../js/chart.js"></script>
<script src="js/pats_core.js?v=<?= $ver ?>"></script>
<script src="js/pats_ui.js?v=<?= $ver ?>"></script>
<script src="js/pats_charts.js?v=<?= $ver ?>"></script>
<script src="js/pats_mis_franquicias.js?v=<?= $ver ?>"></script>
</body>
</html>