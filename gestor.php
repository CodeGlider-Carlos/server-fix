<?php
/*
ez/pats/gestor.php
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

$isPatsAdmin = in_array($adminrol, ['ADMIN', 'DIRO', 'DIRG', 'VIC', 'ADMINPATS'], true);
$diaMes = (int)date('j');

/*
  Factura deshabilitada en vista de gerente.
  Se conserva la variable en false para que no se renderice:
  - botón Enviar factura
  - modal de factura
  - scripts de factura
*/
$puedeEnviarFactura = false;
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PATS — Gerente</title>
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
  <div class="bienvenido">PATS · Gerente</div>
  <?php require_once '../nav/noti_user_pats.php'; ?>
</div>

<?php require_once '../nav/nav_mod.php'; ?>

<content class="back_content">
  <div class="pats-wrap pats-view-gestor">

    <section class="pats-hero">
      <div class="pats-hero__left">
        <div class="pats-kicker">PATS · GERENTES DE EXPANSIÓN</div>
        <h1 class="pats-title">Mis Franquicias Asociadas</h1>
        <p class="pats-subtitle">
          Consulta tus franquicias asociadas, compara su desempeño y entra al detalle operativo de cada una.
        </p>

       <div class="pats-hero__chips">
          <?php if ($isPatsAdmin): ?>
            <button type="button" class="pats-chip pats-chip--action" id="btnPatsBackAdmin">
              ← Volver a admin
            </button>
          <?php endif; ?>

          <button type="button" class="pats-chip pats-chip--action" id="btnSolicitarFranquicia">
            + Solicitar Alta Franquicia
          </button>

          <button type="button" class="pats-chip pats-chip--action" id="btnSolicitarDistribuidor">
            + Solicitar Alta Distribuidor
          </button>
        </div>
      </div>

      <div class="pats-hero__right">
        <div class="pats-meta-card">
          <span class="k">Gerente</span>
          <span class="v" id="patsGestorNombreLabel"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="pats-meta-card">
          <span class="k">Franquicias</span>
          <span class="v" id="patsGestorTotalFranquicias">0</span>
        </div>
      </div>
    </section>

    <section class="pats-filters pats-filters--compact pats-filters--5">
      <?php if ($isPatsAdmin): ?>
        <div class="pats-field">
          <label for="patsGestorActor">Gerente</label>
          <select id="patsGestorActor">
            <option value="">Seleccionar gerente</option>
          </select>
        </div>
      <?php endif; ?>

      <div class="pats-field">
        <label for="patsGestorRegion">Región</label>
        <select id="patsGestorRegion">
          <option value="">Todas</option>
        </select>
      </div>

      <div class="pats-field">
        <label for="patsGestorFranquicia">Franquicia</label>
        <select id="patsGestorFranquicia">
          <option value="">Todas</option>
        </select>
      </div>

      <div class="pats-field">
        <label for="patsGestorAnio">Año</label>
        <input type="number" id="patsGestorAnio" min="2020" max="2100" value="<?= date('Y') ?>">
      </div>

      <div class="pats-field">
        <label for="patsGestorMes">Mes</label>
        <select id="patsGestorMes">
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

    <div class="pats-grid pats-grid--mis-franquicias">
      <aside class="pats-panel pats-panel--left">
         
        <div class="pats-panel__head">
          <div>
            <h3>Franquicias asociadas</h3>
            <p>Selecciona una franquicia para ver su información. Doble clic para entrar al siguiente nivel.</p>
          </div>
        </div>
<div class="pats-admin-public-links">
  <button
    type="button"
    class="pats-admin-public-link-btn"
    id="btnGestorPanelLinkDistribucion"
    title="Copiar landing pública de distribución con token del gerente"
  >
    <span>🔗</span>
    <strong>Link distribución</strong>
  </button>

  <button
    type="button"
    class="pats-admin-public-link-btn"
    id="btnGestorPanelLinkPats"
    title="Copiar landing pública PATS con token del gerente"
  >
    <span>🔗</span>
    <strong>Link PATS</strong>
  </button>

  <button
    type="button"
    class="pats-admin-public-link-btn"
    id="btnGestorPanelLinkFranquicia"
    title="Copiar landing pública de franquicia con token del gerente"
  >
    <span>🔗</span>
    <strong>Link franquicia</strong>
  </button>
</div>
        <div class="pats-search-wrap">
          <input type="text" id="patsSearchFranquicia" placeholder="Buscar franquicia...">
        </div>

        <div class="pats-left-list" id="patsGestorFranquiciaList">
          <div class="pats-empty-state">Cargando franquicias...</div>
        </div>
      </aside>

      <section class="pats-panel pats-panel--right">

       

        <div class="pats-kpis" id="patsGestorKpis">
          <article class="pats-kpi-card"><span class="k">Franquicias asociadas</span><strong>0</strong></article>
          <article class="pats-kpi-card"><span class="k">Ventas globales</span><strong>$0</strong></article>
          <article class="pats-kpi-card"><span class="k">Comisión total</span><strong>$0</strong></article>
          <article class="pats-kpi-card"><span class="k">Comisión pagada</span><strong>$0</strong></article>
          <article class="pats-kpi-card"><span class="k">Comisión pendiente</span><strong>$0</strong></article>
          <article class="pats-kpi-card"><span class="k">CxC total asociado</span><strong>$0</strong></article>
        </div>

        <div class="pats-chart-row">
          <article class="pats-card pats-chart-card">
            <div class="pats-card__head"><h3>Ventas por franquicia</h3></div>
            <div class="pats-card__body pats-card__body--chart">
              <canvas id="patsGestorChart1"></canvas>
              <div class="pats-card__empty" id="patsGestorChart1Empty" hidden>Sin datos para mostrar.</div>
            </div>
          </article>

          <article class="pats-card pats-chart-card">
            <div class="pats-card__head"><h3>Comisiones por franquicia</h3></div>
            <div class="pats-card__body pats-card__body--chart">
              <canvas id="patsGestorChart2"></canvas>
              <div class="pats-card__empty" id="patsGestorChart2Empty" hidden>Sin datos para mostrar.</div>
            </div>
          </article>
        </div>

        <div class="pats-chart-row">
          <article class="pats-card pats-chart-card pats-chart-card--wide">
            <div class="pats-card__head"><h3>Evolución mensual de ventas y comisiones</h3></div>
            <div class="pats-card__body pats-card__body--chart">
              <canvas id="patsGestorChartMensual" height="340"></canvas>
              <div class="pats-card__empty" id="patsGestorChartMensualEmpty" hidden>Sin datos para mostrar.</div>
            </div>
          </article>
        </div>

        <div class="pats-ranking-row pats-ranking-row--final">
          <article class="pats-card">
            <div class="pats-card__head"><h3>Top franquicias por ventas</h3></div>
            <div class="pats-ranking-list" id="patsGestorRankingVentas">
              <div class="pats-empty-inline">Cargando...</div>
            </div>
          </article>

          <article class="pats-card">
            <div class="pats-card__head"><h3>Top franquicias por comisión</h3></div>
            <div class="pats-ranking-list" id="patsGestorRankingComision">
              <div class="pats-empty-inline">Cargando...</div>
            </div>
          </article>
        </div>

        <article class="pats-card" style="margin-top:18px;">
          <div class="pats-card__head"><h3>Detalle de franquicias bajo gestión</h3></div>
          <div class="pats-card__body">
            <div class="pats-table-wrap">
              <table class="pats-table" id="patsGestorTable">
                <thead>
                  <tr>
                    <th>Franquicia</th>
                    <th>Región</th>
                    <th>Zona</th>
                    <th>Unidad</th>
                    <th>Ventas</th>
                    <th>Distribuciones</th>
                    <th>PATS</th>
                    <th>Comisión gestor</th>
                    <th>Pagado</th>
                    <th>Pendiente</th>
                  </tr>
                </thead>
                <tbody id="patsGestorTableBody">
                  <tr>
                    <td colspan="10" class="pats-empty-inline">Cargando...</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </article>
        <div id="patsDirectosMount"></div>
      </section>
    </div>

  </div>
</content>

<div id="patsToastHost" class="pats-toast-host"></div>


<script>
window.PATS_CONTEXT = {
  view: 'gestor',
  rol: <?= json_encode($adminrol, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  usuario: <?= json_encode($userLog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  nombre: <?= json_encode($userName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  region: <?= json_encode($userRegion, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  unidad: <?= json_encode($userUnidad, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  actor: 'GESTOR',
  isAdmin: <?= json_encode($isPatsAdmin) ?>
};
</script>

<?php if ($puedeEnviarFactura): ?>
<script>
(() => {
  "use strict";

  const $ = (s) => document.querySelector(s);

  function money(v) {
    return new Intl.NumberFormat("es-MX", {
      style: "currency",
      currency: "MXN",
      maximumFractionDigits: 2
    }).format(Number(v || 0));
  }

  function toast(msg, type = 'info', timeout = 2800) {
    const host = document.getElementById('patsToastHost');
    if (!host) return;

    const el = document.createElement('div');
    el.className = `pats-toast pats-toast--${type}`;
    el.textContent = msg || '';
    host.appendChild(el);

    setTimeout(() => {
      el.style.opacity = '0';
      el.style.transform = 'translateY(-4px)';
      setTimeout(() => el.remove(), 240);
    }, timeout);
  }

  async function getJSON(url) {
    const res = await fetch(url, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });

    const text = await res.text();
    let data = {};
    try {
      data = text ? JSON.parse(text) : {};
    } catch {
      throw new Error('Respuesta inválida del servidor');
    }

    if (!res.ok || data.ok === false) {
      throw new Error(data.error || 'No fue posible consultar saldo');
    }

    return data;
  }

  async function postForm(url, fd) {
    const res = await fetch(url, {
      method: 'POST',
      body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });

    const text = await res.text();
    let data = {};
    try {
      data = text ? JSON.parse(text) : {};
    } catch {
      throw new Error('Respuesta inválida del servidor');
    }

    if (!res.ok || data.ok === false) {
      throw new Error(data.error || 'No fue posible guardar la factura');
    }

    return data;
  }

  function resolveGestorId() {
    const qs = new URLSearchParams(window.location.search);
    const fromQuery =
      qs.get('id_gestor') ||
      qs.get('gestor') ||
      qs.get('id_actor') ||
      '';

    if (fromQuery && Number(fromQuery) > 0) {
      return Number(fromQuery);
    }

    const sel = $('#patsGestorActor');
    if (sel && Number(sel.value || 0) > 0) {
      return Number(sel.value || 0);
    }

    return 0;
  }

  async function abrirModalFactura() {
    const idGestor = resolveGestorId();

    if (idGestor <= 0) {
      toast('No se pudo identificar el gestor para la factura.', 'error', 3400);
      return;
    }

    $('#factura_id_actor').value = String(idGestor);
    $('#facturaSaldoPendiente').textContent = money(0);
    $('#factura_saldo_reportado').value = '0';
    $('#modalEnviarFactura').hidden = false;

    try {
      const data = await getJSON(`../patsfin/endpoints/cxp_detalle.php?tipo_actor=GESTOR&id_actor=${encodeURIComponent(idGestor)}`);
      const comisiones = Array.isArray(data.comisiones) ? data.comisiones : [];

      const saldoPagable = comisiones
        .filter(c => ['por_pagar', 'solicitado', 'en_revision', 'aprobado', 'pendiente'].includes(String(c.estatus || '').toLowerCase()))
        .reduce((acc, c) => acc + Number(c.monto_comision || 0), 0);

      $('#facturaSaldoPendiente').textContent = money(saldoPagable);
      $('#factura_saldo_reportado').value = Number(saldoPagable || 0).toFixed(2);
    } catch (err) {
      toast(err.message || 'No fue posible consultar saldo pendiente.', 'error', 3400);
    }
  }

  function cerrarModalFactura() {
    const modal = $('#modalEnviarFactura');
    const form = $('#frmEnviarFactura');
    if (modal) modal.hidden = true;
    if (form) form.reset();
    if ($('#factura_tipo_actor')) $('#factura_tipo_actor').value = 'GESTOR';
  }

  async function submitFactura(ev) {
    ev.preventDefault();

    try {
      const fd = new FormData(ev.currentTarget);
      await postForm('endpoints/factura_comision_guardar.php', fd);
      toast('Factura enviada correctamente.', 'success');
      cerrarModalFactura();
    } catch (err) {
      toast(err.message || 'No fue posible guardar la factura.', 'error', 3400);
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    $('#btnEnviarFactura')?.addEventListener('click', abrirModalFactura);
    $('#btnCerrarFacturaModal')?.addEventListener('click', cerrarModalFactura);
    $('#btnCancelarFactura')?.addEventListener('click', cerrarModalFactura);
    $('#modalEnviarFactura .pf-modal__backdrop')?.addEventListener('click', cerrarModalFactura);
    $('#frmEnviarFactura')?.addEventListener('submit', submitFactura);

    const fileInput = $('#factura_pdf');
    const fileName = $('#factura_pdf_name');
    if (fileInput && fileName) {
      fileInput.addEventListener('change', () => {
        const file = fileInput.files && fileInput.files[0];
        fileName.textContent = file ? file.name : 'Ningún archivo seleccionado';
      });
    }
  });
})();
</script>
<?php endif; ?>

<script src="../../js/chart.js"></script>
<script src="js/pats_core.js?v=<?= $ver ?>"></script>
<script src="js/pats_directos_cards.js?v=<?php echo time(); ?>"></script>
<script src="js/pats_ui.js?v=<?= $ver ?>"></script>
<script src="js/pats_charts.js?v=<?= $ver ?>"></script>
<script src="js/pats_gestor.js?v=<?= $ver ?>"></script>
</body>
</html>