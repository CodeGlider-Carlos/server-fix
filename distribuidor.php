<?php
/*
ez/pats/distribuidor.php
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
$modoDirectosFranquicia = ((int)($_GET['directos_franquicia'] ?? 0) === 1) || ((int)($_GET['id_distribuidor'] ?? 0) < 0);
$idFranquiciaDirecta = (int)($_GET['id_franquicia'] ?? 0);
if ($idFranquiciaDirecta <= 0 && (int)($_GET['id_distribuidor'] ?? 0) < 0) {
  $idFranquiciaDirecta = abs((int)$_GET['id_distribuidor']);
}
$userLog    = trim($_SESSION['usuario'] ?? '');
$userName   = trim($_SESSION['nombre'] ?? $userLog);
$userRegion = trim($_SESSION['acroregion'] ?? ($_SESSION['region'] ?? ''));
$userUnidad = trim($_SESSION['acronu'] ?? ($_SESSION['unidad'] ?? ''));
$diaMes = (int)date('j');
$puedeEnviarFactura = (($diaMes <= 5) || in_array($adminrol, ['ADMIN', 'ADMINPATS'], true));
if ($modoDirectosFranquicia) {
  $puedeEnviarFactura = false;
}

$rolapp = strtoupper(trim($_SESSION['rolapp'] ?? ''));
$puedeVerLinkPatsDistribuidor = (
  in_array($adminrol, ['ADMIN', 'ADMINPATS'], true)
  || in_array($rolapp, ['DISTPATS', 'FRANQPATS'], true)
);

$mostrarBloqueLinksDistribuidor = $puedeVerLinkPatsDistribuidor && !$modoDirectosFranquicia;

$textoBotonLinkPats = ($rolapp === 'FRANQPATS' && !in_array($adminrol, ['ADMIN', 'ADMINPATS'], true))
  ? 'Copiar link PATS del distribuidor'
  : 'Copiar Mi Link de PATS';

$puedeDesplegarVencidos =
  in_array($adminrol, ['ADMIN', 'ADMINPATS'], true)
  || in_array($rolapp, ['DISTPATS', 'FRANQPATS'], true);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PATS — Distribuidor</title>
  <link rel="icon" type="image/x-icon" href="../../img/ez.ico" />
  <link rel="stylesheet" href="../../css/index.css?v=<?= $ver ?>">
  <link rel="stylesheet" href="../../css/gloval_responsive.css?v=<?= $ver ?>">
  <link rel="stylesheet" href="css/pats.css?v=<?= $ver ?>">
  <link rel="stylesheet" href="css/pats_responsive.css?v=<?= $ver ?>">
</head>
<body>
<?php require_once '../../loglog/contador.php'; ?>
<div id="cabecera">
  <div class="bienvenido">PATS · Distribuidor</div>
  <?php require_once '../nav/noti_user_pats.php'; ?>
</div>
<?php require_once '../nav/nav_mod.php'; ?>

<content class="back_content">
  <div class="pats-wrap pats-view-distribuidor">

    <section class="pats-hero">
      <div class="pats-hero__left">
        <div class="pats-kicker"><?= $modoDirectosFranquicia ? 'PATS · VENTA DIRECTA DE FRANQUICIA' : 'PATS · DETALLE DE DISTRIBUIDOR' ?></div>
        <h1 class="pats-title"><?= $modoDirectosFranquicia ? 'PATS directos de franquicia' : 'Mis Pasaportes' ?></h1>
        <p class="pats-subtitle">
          <?= $modoDirectosFranquicia ? 'Detalle de PATS vendidos directamente por la franquicia, sin distribuidor intermediario.' : 'Da seguimiento a tus PATS, tus comisiones y a los vencidos con cálculo acumulado.' ?>
        </p>
        <?php if (in_array($adminrol, ['ADMIN', 'ADMINPATS'], true)): ?>
          <div class="pats-hero__chips">
            <button type="button" class="pats-chip pats-chip--action" id="btnPatsBackFranquicia">← Volver a franquicia</button>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <section class="pats-filters pats-filters--compact pats-filters--3">
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

    <div class="pats-grid pats-grid--distribuidor">
      <aside class="pats-panel pats-panel--left pats-panel--ficha">
        <?php if ($mostrarBloqueLinksDistribuidor): ?>
          <div class="pats-ficha__actions">
            <button
              type="button"
              class="pats-ficha__cta"
              id="btnCopiarLinkPublicoPats"
              title="<?= htmlspecialchars($textoBotonLinkPats, ENT_QUOTES, 'UTF-8') ?>"
            >
              <?= htmlspecialchars($textoBotonLinkPats, ENT_QUOTES, 'UTF-8') ?>
            </button>

            <input
              type="text"
              id="patsLinkPublicoInput"
              readonly
              value=""
              placeholder="El link PATS aparecerá aquí al cargar el distribuidor"
              style="margin-top:10px; width:100%; box-sizing:border-box; padding:10px 12px; border-radius:12px; border:1px solid rgba(31,41,55,.18); background:#f8fafc; color:#0f172a; font-size:12px;"
            >
          </div>
        <?php endif; ?>

        <div class="pats-ficha" id="patsDistribuidorFicha">
          <div class="pats-ficha__row"><span>Región</span><strong id="patsFichaRegion">-</strong></div>
          <div class="pats-ficha__row"><span>Zona</span><strong id="patsFichaZona">-</strong></div>
          <div class="pats-ficha__row"><span>Unidad</span><strong id="patsFichaUnidad">-</strong></div>
          <div class="pats-ficha__row"><span>Franquicia</span><strong id="patsFichaFranquicia">-</strong></div>
          <div class="pats-ficha__row"><span>Distribuidor</span><strong id="patsFichaDistribuidor">-</strong></div>
          <div class="pats-ficha__row"><span>Correo</span><strong id="patsFichaCorreo">-</strong></div>
          <div class="pats-ficha__row"><span>Teléfono</span><strong id="patsFichaTelefono">-</strong></div>
        </div>

      </aside>

      <section class="pats-panel pats-panel--right">
        <div class="pats-kpis" id="patsDistribuidorKpis">
          <article class="pats-kpi-card"><span class="k">Ventas reales</span><strong>$0</strong></article>
          <article class="pats-kpi-card"><span class="k">Monto vencido</span><strong>$0</strong></article>
          <article class="pats-kpi-card"><span class="k"><?= $modoDirectosFranquicia ? 'Comisión directa franquicia' : 'Mis comisiones activas' ?></span><strong>$0</strong></article>
          <article class="pats-kpi-card"><span class="k">Comisiones perdidas por vencidos</span><strong>$0</strong></article>
        </div>

        <div class="pats-chart-row">
          <article class="pats-card pats-chart-card">
            <div class="pats-card__head"><h3>Base vs real</h3></div>
            <div class="pats-card__body pats-card__body--chart">
              <canvas id="patsDistribuidorChart1"></canvas>
              <div class="pats-card__empty" id="patsDistribuidorChart1Empty" hidden>Sin datos para mostrar.</div>
            </div>
          </article>

          <article class="pats-card pats-chart-card">
            <div class="pats-card__head"><h3>Activos vs vencidos</h3></div>
            <div class="pats-card__body pats-card__body--chart">
              <canvas id="patsDistribuidorChart2"></canvas>
              <div class="pats-card__empty" id="patsDistribuidorChart2Empty" hidden>Sin datos para mostrar.</div>
            </div>
          </article>
        </div>

        <article class="pats-card">
          <div class="pats-card__head">
            <h3>Seguimiento de vencidos</h3>
          </div>

          <div class="pats-search-wrap">
            <input type="text" id="patsSearchPasaporte" placeholder="Buscar por nombre, empresa, correo o teléfono...">
          </div>

          <div class="pats-left-list" id="patsVencidosList">
            <div class="pats-empty-state">Cargando vencidos...</div>
          </div>
        </article>
      </section>
    </div>

  </div>
</content>

<div id="patsToastHost" class="pats-toast-host"></div>

<?php if ($puedeEnviarFactura): ?>
<div class="pf-modal" id="modalEnviarFactura" hidden>
  <div class="pf-modal__backdrop"></div>
  <div class="pf-modal__dialog" style="max-width:760px;">
    <div class="pf-modal__head">
      <strong id="facturaModalTitle">Enviar factura</strong>
      <button type="button" class="pf-btn pf-btn--ghost" id="btnCerrarFacturaModal">Cerrar</button>
    </div>

    <div class="pf-modal__body">
      <form id="frmEnviarFactura" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="tipo_actor" id="factura_tipo_actor" value="DISTRIBUIDOR">
        <input type="hidden" name="id_actor" id="factura_id_actor" value="">
        <input type="hidden" name="saldo_reportado" id="factura_saldo_reportado" value="0">

        <div class="pf-kv" style="margin-bottom:16px;">
          <div class="pf-kv__item">
            <span>Periodo</span>
            <strong><?= date('m/Y') ?></strong>
          </div>
          <div class="pf-kv__item">
            <span>Saldo pendiente</span>
            <strong id="facturaSaldoPendiente">$0.00</strong>
          </div>
        </div>

        <div class="pats-field">
          <label for="factura_folio">Folio factura</label>
          <input type="text" name="folio_factura" id="factura_folio" maxlength="120">
        </div>

        <div class="pats-field">
          <label for="factura_pdf">Factura PDF</label>
          <label class="pats-file" for="factura_pdf">
            <input
              type="file"
              name="factura_pdf"
              id="factura_pdf"
              accept="application/pdf,.pdf"
              required
            >
            <span class="pats-file__btn">Seleccionar PDF</span>
            <span class="pats-file__name" id="factura_pdf_name">Ningún archivo seleccionado</span>
          </label>
          <small class="pats-file__hint">Solo PDF. Puedes cargar una factura por periodo.</small>
        </div>

        <div class="pats-field">
          <label for="factura_observaciones">Observaciones</label>
          <textarea name="observaciones" id="factura_observaciones" rows="4"></textarea>
        </div>

        <div class="pf-actions" style="margin-top:16px;">
          <button type="button" class="pf-btn pf-btn--ghost" id="btnCancelarFactura">Cancelar</button>
          <button type="submit" class="pf-btn pf-btn--primary">Enviar factura</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
window.PATS_CONTEXT = {
  view: 'distribuidor',
  directos_franquicia: <?= $modoDirectosFranquicia ? 'true' : 'false' ?>,
  id_franquicia_directa: <?= (int)$idFranquiciaDirecta ?>,
  rol: <?= json_encode($adminrol, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  rolapp: <?= json_encode($rolapp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  puede_desplegar_vencidos: <?= $puedeDesplegarVencidos ? 'true' : 'false' ?>,
  usuario: <?= json_encode($userLog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  nombre: <?= json_encode($userName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  region: <?= json_encode($userRegion, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  unidad: <?= json_encode($userUnidad, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
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

  async function abrirModalFactura() {
    const qs = new URLSearchParams(window.location.search);
    const idDistribuidor = Number(qs.get('id_distribuidor') || 0);

    if (idDistribuidor <= 0) {
      toast('No se pudo identificar el distribuidor para la factura.', 'error', 3400);
      return;
    }

    $('#factura_id_actor').value = String(idDistribuidor);
    $('#facturaSaldoPendiente').textContent = money(0);
    $('#factura_saldo_reportado').value = '0';
    $('#modalEnviarFactura').hidden = false;

    try {
      const data = await getJSON(`../patsfin/endpoints/cxp_detalle.php?tipo_actor=DISTRIBUIDOR&id_actor=${encodeURIComponent(idDistribuidor)}`);
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
    if ($('#factura_tipo_actor')) $('#factura_tipo_actor').value = 'DISTRIBUIDOR';
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

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="js/pats_core.js?v=<?= $ver ?>"></script>
<script src="js/pats_ui.js?v=<?= $ver ?>"></script>
<script src="js/pats_charts.js?v=<?= $ver ?>"></script>
<script src="js/pats_distribuidor.js?v=<?= $ver ?>"></script>
</body>
</html>