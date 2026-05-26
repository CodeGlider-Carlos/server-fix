<?php
/*
ez/pats/admin.php
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

/*
  Roles con acceso al dashboard global PATS.
  Se valida rol y rolapp porque en PATS puede venir ADMINPATS en cualquiera de los dos.
*/
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
  <title>ADMIN PATS Dashboard Global</title>
  <link rel="icon" type="image/x-icon" href="../../img/ez.ico" />
  <link rel="stylesheet" href="../../css/index.css?v=<?= $ver ?>">
  <link rel="stylesheet" href="../../css/gloval_responsive.css?v=<?= $ver ?>">
  <link rel="stylesheet" href="css/pats.css?v=<?= $ver ?>">
  
    <link rel="stylesheet" href="css/pats_directos_cards.css?v=<?= $ver ?>">
  <link rel="stylesheet" href="css/pats_responsive.css?v=<?= $ver ?>">
</head>
<body>

<?php require_once '../../loglog/contador.php'; ?>

<div id="cabecera">
  <div class="bienvenido">ADMIN PATS</div>
  <?php require_once '../nav/noti_user_pats.php'; ?>
</div>

<?php require_once '../nav/nav_mod.php'; ?>

<content class="back_content">
  <div class="pats-wrap pats-view-admin">

    <section class="pats-hero">
      <div class="pats-hero__left">
        <div class="pats-kicker">EZHS · PATS · DASHBOARD ADMINISTRATIVO</div>
        <h1 class="pats-title">Dashboard Global</h1>
        <p class="pats-subtitle">
          Vista corporativa de ventas, contratos reales, participación institucional ADMINPATS, hospitales, gerentes y comisiones.
        </p>

        <div class="pats-hero__chips">
          <button type="button" class="pats-chip pats-chip--action" id="btnGoGestorView">
            Ver vista gerente
          </button>

          <button type="button" class="pats-chip pats-chip--action" id="btnGoDistribuidoresCorporativos">
            Ver distribuidores corporativos
          </button>

          <button type="button" class="pats-chip pats-chip--action" id="btnGenerarLinkDistribucion">
            🔗— Generar link de alta DISTRIBUIDOR
          </button>

          <button type="button" class="pats-chip pats-chip--action" id="btnGenerarLinkFranquicia">
            🔗— Generar link de alta FRANQUICIA
          </button>
        </div>
      </div>
    </section>

    <section class="pats-filters">
      <div class="pats-field">
        <label for="patsPais">País</label>
        <select id="patsPais"><option value="">Selecciona...</option></select>
      </div>
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
    </section>

    <div class="pats-grid pats-grid--admin">
      <aside class="pats-panel pats-panel--left">
        <div class="pats-panel__head">
          <div>
            <h3>Regiones</h3>
            <p>Selecciona una región para entrar a las franquicias de ese territorio.</p>
          </div>
        </div>

        <div class="pats-search-wrap">
          <input type="text" id="patsSearchRegion" placeholder="Buscar región...">
        </div>

        <div class="pats-admin-public-links">
          <button type="button" class="pats-admin-public-link-btn" id="btnCopiarLandingDistribucionAdmin" title="Copiar landing pública de distribución sin token">
            <span>🔗</span>
            <strong>Link distribución</strong>
          </button>

          <button type="button" class="pats-admin-public-link-btn" id="btnCopiarLandingPatsAdmin" title="Copiar landing pública PATS sin token">
            <span>🔗</span>
            <strong>Link PATS</strong>
          </button>

          <button type="button" class="pats-admin-public-link-btn" id="btnCopiarLandingFranquiciaAdmin" title="Copiar landing pública de franquicia sin token">
            <span>🔗</span>
            <strong>Link franquicia</strong>
          </button>
        </div>

        <div class="pats-left-list" id="patsRegionList">
          <div class="pats-empty-state">Cargando regiones...</div>
        </div>
      </aside>

      <section class="pats-panel pats-panel--right">

        <article class="pats-card pats-kpi-block">
          <div class="pats-card__head"><h3>Ventas Globales</h3></div>
          <div class="pats-admin-block">
            <div class="pats-admin-total-card">
              <span class="k">Total</span>
              <strong id="patsGlobalTotal">$0</strong>
            </div>
            <div class="pats-admin-subgrid">
              <article class="pats-kpi-card">
                <span class="k">Franquicias</span>
                <strong id="patsGlobalFranq">$0</strong>
                <small id="patsGlobalFranqNote">Nuevos 0 · Reactivados 0</small>
              </article>
              <article class="pats-kpi-card">
                <span class="k">Distribuciones</span>
                <strong id="patsGlobalDist">$0</strong>
                <small id="patsGlobalDistNote">Nuevos 0 · Reactivados 0</small>
              </article>
              <article class="pats-kpi-card">
                <span class="k">PATS</span>
                <strong id="patsGlobalPats">$0</strong>
                <small id="patsGlobalPatsNote">Nuevos 0 · Reactivados 0</small>
              </article>
            </div>
          </div>
        </article>

        <article class="pats-card pats-kpi-block">
          <div class="pats-card__head"><h3>Dinero cobrado y pendiente</h3></div>
          <div class="pats-admin-block">
            <div class="pats-admin-total-card">
              <span class="k">Dinero recibido</span>
              <strong id="patsAdminIngresoRealDepositado">$0</strong>
              <small id="patsAdminIngresoRealDepositadoNote">Pagos ya registrados de franquicias, distribuciones y PATS</small>
            </div>
            <div class="pats-admin-subgrid">
              <article class="pats-kpi-card">
                <span class="k">Por cobrar</span>
                <strong id="patsAdminSaldoPendienteContratos">$0</strong>
                <small id="patsAdminSaldoPendienteContratosNote">Saldo pendiente de franquicias y distribuciones</small>
              </article>
              <article class="pats-kpi-card">
                <span class="k">Ingreso real AdminPATS</span>
                <strong id="patsAdminIngresoAdminReal">$0</strong>
                <small id="patsAdminIngresoAdminRealNote">Dinero recibido menos hospital y comisiones liberadas</small>
              </article>
              <article class="pats-kpi-card">
                <span class="k">Total vendido</span>
                <strong id="patsAdminVentasContratadas">$0</strong>
                <small id="patsAdminVentasContratadasNote">Ventas firmadas, aunque no estén totalmente pagadas</small>
              </article>
            </div>
          </div>
        </article>

        <article class="pats-card pats-kpi-block">
          <div class="pats-card__head"><h3>Franquicias vendidas</h3></div>
          <div class="pats-admin-subgrid pats-admin-subgrid--3">
            <article class="pats-kpi-card">
              <span class="k">Precio de lista</span>
              <strong id="patsAdminValorCatalogoFranq">$0</strong>
              <small id="patsAdminValorCatalogoFranqNote">Lo que costarían sin descuentos ni participaciones</small>
            </article>
            <article class="pats-kpi-card">
              <span class="k">Monto vendido</span>
              <strong id="patsAdminValorContratoFranq">$0</strong>
              <small id="patsAdminValorContratoFranqNote">Lo realmente firmado en contrato</small>
            </article>
            <article class="pats-kpi-card">
              <span class="k">Diferencia no cobrada</span>
              <strong id="patsAdminParticipacionAdminPats">$0</strong>
              <small id="patsAdminParticipacionAdminPatsNote">Descuentos, participación o copropiedad asumida por AdminPATS</small>
            </article>
          </div>
        </article>

        <article class="pats-card pats-kpi-block">
          <div class="pats-card__head"><h3>Ingresos ADMIN PATS</h3></div>
          <div class="pats-admin-block">
            <div class="pats-admin-total-card">
              <span class="k">Ingreso AdminPATS neto</span>
              <strong id="patsAdminIngresoTotal">$0</strong>
              <small id="patsAdminIngresoFormulaNote">Ventas contratadas - salidas - hospital</small>
            </div>
            <div class="pats-admin-subgrid">
              <article class="pats-kpi-card">
                <span class="k">Franquicias vendidas</span>
                <strong id="patsAdminIngresoFranq">$0</strong>
              </article>
              <article class="pats-kpi-card">
                <span class="k">Ingreso por distribuciones</span>
                <strong id="patsAdminIngresoDist">$0</strong>
              </article>
              <article class="pats-kpi-card">
                <span class="k">Ingreso por PATS</span>
                <strong id="patsAdminIngresoPats">$0</strong>
                <small id="patsAdminRecargosNote">Recargos $0</small>
              </article>
            </div>
          </div>
        </article>

        <article class="pats-card pats-kpi-block">
          <div class="pats-card__head"><h3>Ingresos Hospital por PATS</h3></div>
          <div class="pats-admin-hospital-grid" id="patsAdminHospitalList">
            <div class="pats-empty-inline">Cargando...</div>
          </div>
        </article>

        <article class="pats-card pats-kpi-block">
          <div class="pats-card__head"><h3>Comisiones generadas</h3></div>
          <div class="pats-admin-subgrid pats-admin-subgrid--3">
            <article class="pats-kpi-card">
              <span class="k">Franquiciatario</span>
              <strong id="patsAdminComisionFranquicia">$0</strong>
              <small id="patsAdminComisionFranquiciaNote">Distribuciones $0 · PATS $0</small>
            </article>
            <article class="pats-kpi-card">
              <span class="k">Distribuidor</span>
              <strong id="patsAdminComisionDistribuidor">$0</strong>
            </article>
            <article class="pats-kpi-card">
              <span class="k">Gerente</span>
              <strong id="patsAdminComisionGestor">$0</strong>
              <small id="patsAdminComisionGestorNote">Pagada $0 · Pendiente $0</small>
            </article>
          </div>
        </article>

        <article class="pats-card pats-kpi-block">
          <div class="pats-card__head"><h3>Estructura comercial</h3></div>
          <div class="pats-admin-subgrid pats-admin-subgrid--3">
            <article class="pats-kpi-card"><span class="k">Gerente</span><strong id="patsEstructuraGestores">0</strong></article>
            <article class="pats-kpi-card"><span class="k">Franquicias con gerentes</span><strong id="patsEstructuraFranqConGestor">0</strong></article>
            <article class="pats-kpi-card"><span class="k">Franquicias sin gerentes</span><strong id="patsEstructuraFranqSinGestor">0</strong></article>
            <article class="pats-kpi-card"><span class="k">Compartidas con ADMINPATS</span><strong id="patsEstructuraFranqCompartidas">0</strong></article>
            <article class="pats-kpi-card"><span class="k">Franquicias individuales</span><strong id="patsEstructuraFranqIndividuales">0</strong></article>
            <article class="pats-kpi-card">
              <span class="k">Copropiedad</span>
              <strong id="patsEstructuraCopropietarios">0</strong>
              <small id="patsEstructuraTitularesAccesoNote">Titulares reales con acceso 0</small>
            </article>
          </div>
        </article>

        <div class="pats-chart-row pats-chart-row--triple">
          <article class="pats-card pats-chart-card">
            <div class="pats-card__head"><h3>Quién recibe el dinero</h3></div>
            <div class="pats-card__body pats-card__body--chart">
              <canvas id="patsAdminChartDistribucion"></canvas>
              <div class="pats-card__empty" id="patsAdminChartDistribucionEmpty" hidden>Sin datos para mostrar.</div>
            </div>
          </article>

          <article class="pats-card pats-chart-card">
            <div class="pats-card__head"><h3>Franquicias: precio vs venta real</h3></div>
            <div class="pats-card__body pats-card__body--chart">
              <canvas id="patsAdminChartNominalRealExtra"></canvas>
              <div class="pats-card__empty" id="patsAdminChartNominalRealExtraEmpty" hidden>Sin datos para mostrar.</div>
            </div>
          </article>

          <article class="pats-card pats-chart-card">
            <div class="pats-card__head"><h3>PATS vendidos por tipo de pago</h3></div>
            <div class="pats-card__body pats-card__body--chart">
              <canvas id="patsAdminChartFrecuencia"></canvas>
              <div class="pats-card__empty" id="patsAdminChartFrecuenciaEmpty" hidden>Sin datos para mostrar.</div>
            </div>
          </article>
        </div>

        <div class="pats-chart-row">
          <article class="pats-card pats-chart-card">
            <div class="pats-card__head"><h3>Compartidas con ADMINPATS y Gerentes</h3></div>
            <div class="pats-card__body pats-card__body--chart">
              <canvas id="patsAdminChartCopropiedadGestor"></canvas>
              <div class="pats-card__empty" id="patsAdminChartCopropiedadGestorEmpty" hidden>Sin datos para mostrar.</div>
            </div>
          </article>

          <article class="pats-card pats-chart-card pats-chart-card--wide">
            <div class="pats-card__head"><h3>Ventas y volúmen por mes</h3></div>
            <div class="pats-card__body pats-card__body--chart">
              <canvas id="patsAdminChartMensualAnual" height="340"></canvas>
              <div class="pats-card__empty" id="patsAdminChartMensualAnualEmpty" hidden>Sin datos para mostrar.</div>
            </div>
          </article>
        </div>

        <div class="pats-ranking-row pats-ranking-row--final">
          <article class="pats-card">
            <div class="pats-card__head"><h3>Ranking regiones con más PATS activos</h3></div>
            <div class="pats-ranking-list" id="patsRankingRegionesActivas"><div class="pats-empty-inline">Cargando...</div></div>
          </article>

          <article class="pats-card">
            <div class="pats-card__head"><h3>Ranking regiones con más ingresos</h3></div>
            <div class="pats-ranking-list" id="patsRankingRegionesIngresos"><div class="pats-empty-inline">Cargando...</div></div>
          </article>
          
               
        </div>
   <div id="patsDirectosMount"></div>
      </section>
    </div>
  </div>
</content>

<script>
window.PATS_CONTEXT = {
  view: 'admin',
  rol: <?= json_encode($adminrol, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  usuario: <?= json_encode($userLog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  nombre: <?= json_encode($userName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  region: <?= json_encode($userRegion, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  unidad: <?= json_encode($userUnidad, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
};

const btnLink = document.getElementById('btnGenerarLinkDistribucion');
if (btnLink) {
  btnLink.addEventListener('click', () => {
    const url = new URL('https://50d.com.mx/50D/EZHS/ez/patsfin/distribucion_links.php', window.location.href);
    url.searchParams.set('id_franquicia', 0);
    window.open(url.toString(), '_blank');
  });
}

const btnLinkFran = document.getElementById('btnGenerarLinkFranquicia');
if (btnLinkFran) {
  btnLinkFran.addEventListener('click', () => {
    const url = new URL('https://50d.com.mx/50D/EZHS/ez/patsfin/franquicia_links.php', window.location.href);
    url.searchParams.set('id_franquicia', 0);
    window.open(url.toString(), '_blank');
  });
}
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="js/pats_core.js?v=<?= $ver ?>"></script>

<script src="js/pats_directos_cards.js?v=<?php echo time(); ?>"></script>
<script src="js/pats_ui.js?v=<?= $ver ?>"></script>
<script src="js/pats_charts.js?v=<?= $ver ?>"></script>
<script src="js/pats_admin.js?v=<?= $ver ?>"></script>
<script src="js/pats_admin_distribuidores_corporativos_button_fix.js?v=<?php echo time(); ?>"></script>
</body>
</html>
