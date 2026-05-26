<?php
/*
ez/pats/franquicia.php
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
/*
$diaMes = (int)date('j');
$puedeEnviarFactura = ($diaMes <= 5) || in_array($adminrol, ['ADMIN', 'ADMINPATS'], true);
*/
$diaMes = (int)date('j');
$puedeEnviarFactura = false;
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PATS — Franquicia</title>
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
  <div class="bienvenido">PATS · Franquicia</div>
  <?php require_once '../nav/noti_user_pats.php'; ?>
</div>
<?php require_once '../nav/nav_mod.php'; ?>

<content class="back_content">
  <div class="pats-wrap pats-view-franquicia">

    <section class="pats-hero">
      <div class="pats-hero__left">
        <div class="pats-kicker">PATS · DETALLE DE FRANQUICIA</div>
        <h1 class="pats-title">Mi Franquicia</h1>
        <p class="pats-subtitle">
          Consulta distribuidores, ventas, pasaportes, solicitudes y la ganancia del franquiciatario.
        </p>
       <div class="pats-hero__chips">
  <?php if (in_array($adminrol, ['ADMIN', 'ADMINPATS'], true)): ?>
    <button type="button" class="pats-chip pats-chip--action" id="btnPatsBackAdmin">
      ← Volver a admin
    </button>
  <?php endif; ?>

  <button type="button" class="pats-chip pats-chip--action" id="btnGenerarLinkDistribucion">
    🔗 Generar link de alta distribución
  </button>
</div>
      </div>
    </section>

    <section class="pats-filters pats-filters--compact pats-filters--3">
      <div class="pats-field" style="display:none !important;">
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

    <div class="pats-grid pats-grid--franquicia">
      <aside class="pats-panel pats-panel--left">
        <div class="pats-panel__head">
          <div>
            <h3>Distribuidores</h3>
            <p>Da clic para ver más información o doble clic para entrar a su vista.</p>
          </div>
        </div>

       <div class="pats-admin-public-links">

  <button
    type="button"
    class="pats-admin-public-link-btn"
    id="btnCopiarLinkPublicoPats"
    disabled
    aria-disabled="true"
    title="Cargando link público..."
  >
    <span>🔗</span>
    <strong>Link PATS</strong>
  </button>

  <button
    type="button"
    class="pats-admin-public-link-btn"
    id="btnCopiarLinkDistribucionFranquicia"
    disabled
    aria-disabled="true"
    title="Cargando link público..."
  >
    <span>🔗</span>
    <strong>Link distribución</strong>
  </button>

</div>

        <div class="pats-left-list" id="patsDistribuidorList">
          <div class="pats-empty-state">Cargando distribuidores...</div>
        </div>
  <!--
        <div class="pats-ficha__actions">
          <button type="button" class="pats-ficha__cta" id="btnCopiarLinkPublicoPats">
            Copiar Mi Link de PATS
          </button>

          <?php if ($puedeEnviarFactura): ?>
            <button type="button" class="pats-ficha__cta" id="btnEnviarFactura">
              Enviar factura
            </button>
          <?php endif; ?>
        </div>
          -->
      
      </aside>

      <section class="pats-panel pats-panel--right">
        <div class="pats-kpis" id="patsFranquiciaKpis">
          <article class="pats-kpi-card"><span class="k">Ventas</span><strong>$0</strong></article>
          <article class="pats-kpi-card"><span class="k">Monto vencido</span><strong>$0</strong></article>
          <article class="pats-kpi-card"><span class="k">Mis comisiones por distribuidores</span><strong>$0</strong></article>
          <article class="pats-kpi-card"><span class="k">Mis comisiones por PATS activos</span><strong>$0</strong></article>
        </div>

        <div class="pats-chart-row">
          <article class="pats-card pats-chart-card">
            <div class="pats-card__head"><h3>Ventas distribuidor</h3></div>
            <div class="pats-card__body pats-card__body--chart">
              <canvas id="patsFranquiciaChart1"></canvas>
              <div class="pats-card__empty" id="patsFranquiciaChart1Empty" hidden>Sin datos para mostrar.</div>
            </div>
          </article>

          <article class="pats-card pats-chart-card">
            <div class="pats-card__head"><h3>Distribuidores con más pasaportes activos y vencidos</h3></div>
            <div class="pats-card__body pats-card__body--chart">
              <canvas id="patsFranquiciaChart2"></canvas>
              <div class="pats-card__empty" id="patsFranquiciaChart2Empty" hidden>Sin datos para mostrar.</div>
            </div>
          </article>
        </div>

        <div class="pats-chart-row">
          <article class="pats-card pats-chart-card pats-chart-card--wide">
            <div class="pats-card__head"><h3>Evolución mensual: ventas, ganancia, distribuidores y PATS</h3></div>
            <div class="pats-card__body pats-card__body--chart">
              <canvas id="patsFranquiciaChartMensual" height="340"></canvas>
              <div class="pats-card__empty" id="patsFranquiciaChartMensualEmpty" hidden>Sin datos para mostrar.</div>
            </div>
          </article>
        </div>

        <div class="pats-ranking-row pats-ranking-row--final">
          <article class="pats-card">
            <div class="pats-card__head"><h3>Distribuidores con más PATS activos</h3></div>
            <div class="pats-ranking-list" id="patsFranqRankingActivos">
              <div class="pats-empty-inline">Cargando...</div>
            </div>
          </article>

          <article class="pats-card">
            <div class="pats-card__head"><h3>Distribuidores con más vencidos</h3></div>
            <div class="pats-ranking-list" id="patsFranqRankingVencidos">
              <div class="pats-empty-inline">Cargando...</div>
            </div>
          </article>
        </div>

        <article class="pats-card">
          <div class="pats-card__head">
            <h3>Mis solicitudes de distribuciones</h3>
          </div>

          <div class="pats-search-wrap">
            <input type="text" id="patsSearchSolicitudDistribuidor" placeholder="Buscar solicitud por nombre, correo o teléfono...">
          </div>

          <div class="pats-left-list" id="patsSolicitudesDistribuidorList">
            <div class="pats-empty-state">Cargando solicitudes...</div>
          </div>
        </article>
<div id="patsDirectosMount"></div>
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
        <input type="hidden" name="tipo_actor" id="factura_tipo_actor" value="FRANQUICIATARIO">
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
  view: 'franquicia',
  rol: <?= json_encode($adminrol, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  usuario: <?= json_encode($userLog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  nombre: <?= json_encode($userName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  region: <?= json_encode($userRegion, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  unidad: <?= json_encode($userUnidad, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
};
</script>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const btn = document.getElementById('btnGenerarLinkDistribucion');
  if (!btn) return;

  btn.addEventListener('click', () => {
    const qs = new URLSearchParams(window.location.search);
    const idFranquicia = qs.get('id_franquicia') || '';

    const url = new URL(
      'https://50d.com.mx/50D/EZHS/ez/patsfin/distribucion_links.php',
      window.location.href
    );

    if (idFranquicia) {
      url.searchParams.set('id_franquicia', idFranquicia);
    }

    window.open(url.toString(), '_blank');
  });
});
</script>
<script>
(() => {
  "use strict";

  const $ = (s) => document.querySelector(s);

  function toast(msg, type = 'info', timeout = 2800) {
    const host = $('#patsToastHost');
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

  function esc(v) {
    return String(v ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function badgeClass(estatus) {
    const st = String(estatus || '').toUpperCase();
    if (['ENVIADA', 'VALIDANDO_DOCUMENTOS', 'CONTRATO_ENVIADO'].includes(st)) return 'warn';
    if (['VALIDADA_DOCUMENTALMENTE', 'CONTRATO_FIRMADO_CARGADO', 'AUTORIZADA', 'CONVERTIDA_ALTA'].includes(st)) return 'ok';
    if (['OBSERVADA', 'RECHAZADA'].includes(st)) return 'danger';
    return '';
  }

  async function getJSON(url) {
    const res = await fetch(url, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });

    const text = await res.text();
    let data = {};
    try {
      data = text ? JSON.parse(text) : {};
    } catch (e) {
      throw new Error('Respuesta inválida');
    }

    if (!res.ok || data.ok === false) {
      throw new Error(data.error || 'No fue posible cargar solicitudes');
    }

    return data;
  }

  function renderSolicitud(item) {
    const st = String(item.estatus || '').toUpperCase();
    const cls = badgeClass(st);

    const fechaBase = item.created_at || item.updated_at || '';
    const fechaTxt = fechaBase
      ? new Date(fechaBase.replace(' ', 'T')).toLocaleDateString('es-MX', {
          year: 'numeric',
          month: 'short',
          day: '2-digit'
        })
      : '-';

    return `
      <article class="pats-sol-accordion" data-sol-id="${Number(item.id_solicitud || 0)}">
        <button type="button" class="pats-sol-accordion__head">
          <div class="pats-sol-accordion__row">
            <div class="pats-sol-accordion__title">${esc(item.nombre || '-')}</div>

            <div class="pats-sol-accordion__meta-top">
              <span class="pats-sol-chip pats-sol-chip--${cls}">${esc(st)}</span>
              <span class="pats-sol-date">${esc(fechaTxt)}</span>
            </div>

            <span class="pats-sol-accordion__chevron-wrap" aria-hidden="true">
              <span class="pats-sol-accordion__chevron">⌄</span>
            </span>
          </div>
        </button>

        <div class="pats-sol-accordion__body">
          <div class="pats-sol-grid">
            <div><b>Correo:</b> ${esc(item.correo || '-')}</div>
            <div><b>Teléfono:</b> ${esc(item.telefono || '-')}</div>
            <div><b>Franquicia:</b> ${esc(item.nombre_franquicia || '-')}</div>
            <div><b>RFC:</b> ${esc(item.rfc || '-')}</div>
          </div>

          <div class="pats-sol-grid" style="margin-top:10px;">
            <div><b>Zona:</b> ${esc(item.zona || '-')}</div>
            <div><b>Unidad:</b> ${esc(item.unidad || '-')}</div>
            <div><b>Razón social:</b> ${esc(item.razon_social || '-')}</div>
            <div><b>Distribuidor generado:</b> ${item.id_distribuidor_generado ? '#' + Number(item.id_distribuidor_generado) : '-'}</div>
          </div>

          ${item.observaciones_admin || item.motivo_rechazo || item.observaciones_franquicia ? `
            <div class="pats-sol-notes">
              ${item.observaciones_admin ? `<div><b>Obs. admin:</b> ${esc(item.observaciones_admin)}</div>` : ''}
              ${item.observaciones_franquicia ? `<div><b>Obs. franquicia:</b> ${esc(item.observaciones_franquicia)}</div>` : ''}
              ${item.motivo_rechazo ? `<div><b>Motivo rechazo:</b> ${esc(item.motivo_rechazo)}</div>` : ''}
            </div>
          ` : ''}

                  <div class="pats-sol-actions">
            ${['ENVIADA', 'OBSERVADA'].includes(st) ? `
              <button type="button" class="pats-chip pats-chip--action btnEditarSolicitud"
                data-id="${Number(item.id_solicitud || 0)}">
                Editar solicitud
              </button>
            ` : ''}

            ${!['ENVIADA', 'OBSERVADA'].includes(st) ? `
              <span class="pats-chip pats-chip--action" style="opacity:.72; cursor:default;">
                En revisión administrativa
              </span>
            ` : ''}
          </div>
        </div>
      </article>
    `;
  }

  async function cargarSolicitudes() {
    const q = ($('#patsSearchSolicitudDistribuidor')?.value || '').trim();
    const qsPage = new URLSearchParams(window.location.search);
    const idFranquicia = qsPage.get('id_franquicia') || '';
    const url = `endpoints/mis_solicitudes_distribuidor_listar.php?id_franquicia=${encodeURIComponent(idFranquicia)}&q=${encodeURIComponent(q)}&limit=100`;

    const data = await getJSON(url);
    const wrap = $('#patsSolicitudesDistribuidorList');
    if (!wrap) return;

    const items = data.items || [];
    if (!items.length) {
      wrap.innerHTML = `<div class="pats-empty-state">No hay solicitudes registradas.</div>`;
      return;
    }

    wrap.innerHTML = items.map(renderSolicitud).join('');

    wrap.querySelectorAll('.pats-sol-accordion__head').forEach((btn) => {
      btn.addEventListener('click', () => {
        const card = btn.closest('.pats-sol-accordion');
        if (!card) return;

        const isOpen = card.classList.contains('is-open');

        wrap.querySelectorAll('.pats-sol-accordion').forEach((x) => {
          x.classList.remove('is-open');
        });

        if (!isOpen) {
          card.classList.add('is-open');
        }
      });
    });

    wrap.querySelectorAll('.btnEditarSolicitud').forEach((btn) => {
      btn.addEventListener('click', () => {
        const idSolicitud = Number(btn.dataset.id || 0);
        const qs = new URLSearchParams(window.location.search);

        const url = new URL('solicitud_distribuidor.php', window.location.href);
        url.searchParams.set('id_solicitud', String(idSolicitud));

   const idFranquicia = qs.get('id_franquicia') || '';
const region = qs.get('region') || '';
const zona = qs.get('zona') || '';
const anio = qs.get('anio') || '';
const mes = qs.get('mes') || '';

url.searchParams.set('from', 'franquicia');

if (idFranquicia) url.searchParams.set('id_franquicia', idFranquicia);
if (region) url.searchParams.set('region', region);
if (zona) url.searchParams.set('zona', zona);
if (anio) url.searchParams.set('anio', anio);
if (mes) url.searchParams.set('mes', mes);

        window.location.href = url.toString();
      });
    });

  }

  document.addEventListener('DOMContentLoaded', () => {
    $('#patsSearchSolicitudDistribuidor')?.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        cargarSolicitudes().catch((err) => {
          console.error(err);
          toast('No fue posible cargar solicitudes.', 'error', 3200);
        });
      }
    });

    cargarSolicitudes().catch((err) => {
      console.error(err);
      toast('No fue posible cargar solicitudes.', 'error', 3200);
    });
  });
})();
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
    const idFranquicia = Number(qs.get('id_franquicia') || 0);

    if (idFranquicia <= 0) {
      toast('No se pudo identificar la franquicia para la factura.', 'error', 3400);
      return;
    }

    $('#factura_id_actor').value = String(idFranquicia);
    $('#facturaSaldoPendiente').textContent = money(0);
    $('#factura_saldo_reportado').value = '0';
    $('#modalEnviarFactura').hidden = false;

    try {
      const data = await getJSON(`../patsfin/endpoints/cxp_detalle.php?tipo_actor=FRANQUICIATARIO&id_actor=${encodeURIComponent(idFranquicia)}`);
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
    if ($('#factura_tipo_actor')) $('#factura_tipo_actor').value = 'FRANQUICIATARIO';
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
<script src="js/pats_directos_cards.js?v=<?= $ver ?>"></script>
<script src="js/pats_ui.js?v=<?= $ver ?>"></script>
<script src="js/pats_charts.js?v=<?= $ver ?>"></script>
<script src="js/pats_franquicia.js?v=<?= $ver ?>"></script>

</body>
</html>