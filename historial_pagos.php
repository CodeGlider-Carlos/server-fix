<?php
/*
ez/pats/historial_pagos.php
Admin · Historial de pagos y gestión de órdenes OXXO
*/
session_start();
require_once '../../varSQL/bd_pats.php';
require_once '../../varSQL/var_pats.php';

if (empty($_SESSION['usuario'])) {
  session_destroy();
  header('Location: ../../../index.php');
  exit;
}

$adminrol = strtoupper(trim((string)($_SESSION['rol']   ?? '')));
$rolapp   = strtoupper(trim((string)($_SESSION['rolapp'] ?? '')));
$rolesAdmin = ['ADMIN', 'ADMINPATS', 'DIRO', 'DIRG', 'VIC'];

if (!in_array($adminrol, $rolesAdmin, true) && !in_array($rolapp, $rolesAdmin, true)) {
  header('Location: index.php');
  exit;
}
$ver = time();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Historial de pagos · PATS</title>
  <link rel="stylesheet" href="../../css/index.css?v=<?= $ver ?>">
  <link rel="stylesheet" href="../../css/gloval_responsive.css?v=<?= $ver ?>">
  <link rel="stylesheet" href="css/pats.css?v=<?= $ver ?>">
  <link rel="stylesheet" href="https://cdn.materialdesignicons.com/7.2.96/css/materialdesignicons.min.css">
  <style>
    :root {
      --blue-mid: #2563eb;
      --oxxo-red: #e63a1e;
      --border: #dce6f5;
    }
    body { background:#f3f7ff; font-family:system-ui,sans-serif; color:#102a56; margin:0; }
    .hp-wrap { max-width:1200px; margin:0 auto; padding:24px 16px 60px; }
    .hp-head { display:flex; align-items:center; gap:14px; margin-bottom:24px; flex-wrap:wrap; }
    .hp-head h1 { margin:0; font-size:22px; font-weight:800; }
    .hp-back { color:var(--blue-mid); text-decoration:none; font-size:13px; display:flex; align-items:center; gap:4px; }
    .hp-back:hover { text-decoration:underline; }

    .hp-filters { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:18px; align-items:flex-end; }
    .hp-filters select, .hp-filters input {
      border:1px solid var(--border); border-radius:8px; padding:7px 12px; font-size:13px;
      background:#fff; color:#102a56; outline:none;
    }
    .hp-filters button {
      background:var(--blue-mid); color:#fff; border:none; border-radius:8px;
      padding:8px 16px; font-size:13px; font-weight:700; cursor:pointer;
    }

    .hp-table-wrap { background:#fff; border:1px solid var(--border); border-radius:14px; overflow-x:auto; }
    table { width:100%; border-collapse:collapse; font-size:13px; }
    thead th { background:#f0f5ff; padding:11px 12px; text-align:left; font-weight:700; color:#374151; border-bottom:1px solid var(--border); white-space:nowrap; }
    tbody tr { border-bottom:1px solid #f0f5ff; transition:background .15s; }
    tbody tr:hover { background:#f8faff; }
    tbody td { padding:10px 12px; vertical-align:middle; }

    .badge {
      display:inline-block; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; white-space:nowrap;
    }
    .badge--confirmado  { background:#d1fae5; color:#065f46; }
    .badge--pendiente   { background:#fef9c3; color:#92400e; }
    .badge--pendiente-oxxo { background:#ffedd5; color:#c2410c; }
    .badge--cancelado   { background:#fee2e2; color:#991b1b; }
    .badge--tarjeta     { background:#ede9fe; color:#5b21b6; }
    .badge--oxxo        { background:#fff8f5; color:#e63a1e; border:1px solid #fca5a5; }

    .btn-sm {
      display:inline-flex; align-items:center; gap:5px;
      border:none; border-radius:8px; padding:6px 12px; font-size:12px; font-weight:700;
      cursor:pointer; white-space:nowrap; transition:.15s;
    }
    .btn-confirmar { background:#d1fae5; color:#065f46; }
    .btn-confirmar:hover { background:#a7f3d0; }
    .btn-baja      { background:#fee2e2; color:#991b1b; }
    .btn-baja:hover { background:#fecaca; }
    .btn-voucher   { background:#fff8f5; color:#e63a1e; border:1px solid #fca5a5; }
    .btn-voucher:hover { background:#ffedd5; }
    .btn-link-pago { background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; }
    .btn-link-pago:hover { background:#dbeafe; }

    .link-result-box {
      background:#f8faff; border:1px solid var(--border); border-radius:10px;
      padding:12px 14px; font-family:monospace; font-size:13px; word-break:break-all;
      color:#1e293b; margin:12px 0 0;
    }
    .btn-copiar {
      margin-top:10px; background:var(--blue-mid); color:#fff; border:none;
      border-radius:8px; padding:8px 16px; font-size:13px; font-weight:700; cursor:pointer; width:100%;
    }
    .btn-copiar:hover { background:#1d4ed8; }

    .hp-empty { text-align:center; padding:40px; color:#60708f; }
    .hp-pagination { display:flex; justify-content:flex-end; gap:6px; margin-top:12px; flex-wrap:wrap; }
    .hp-page-btn {
      border:1px solid var(--border); background:#fff; border-radius:8px;
      padding:6px 12px; font-size:12px; cursor:pointer; color:#374151;
    }
    .hp-page-btn.active { background:var(--blue-mid); color:#fff; border-color:var(--blue-mid); }

    .modal-overlay {
      display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:9000;
      align-items:center; justify-content:center;
    }
    .modal-overlay.open { display:flex; }
    .modal-box {
      background:#fff; border-radius:18px; padding:28px 24px; max-width:420px; width:90%;
      box-shadow:0 20px 60px rgba(0,0,0,.18);
    }
    .modal-box h3 { margin:0 0 10px; font-size:17px; }
    .modal-box p  { margin:0 0 18px; font-size:14px; color:#374151; line-height:1.55; }
    .modal-actions { display:flex; gap:10px; justify-content:flex-end; }
    .modal-actions button { border:none; border-radius:8px; padding:9px 18px; font-size:13px; font-weight:700; cursor:pointer; }
    .btn-cancel-modal { background:#f0f5ff; color:#374151; }
    .btn-confirm-modal { background:var(--blue-mid); color:#fff; }
    .btn-danger-modal  { background:#ef4444; color:#fff; }
  </style>
</head>
<body>
<?php require_once '../../loglog/contador.php'; ?>
<?php if (file_exists(__DIR__ . '/../nav/noti_user_pats.php')) require_once __DIR__ . '/../nav/noti_user_pats.php'; ?>
<?php if (file_exists(__DIR__ . '/../nav/nav_mod.php')) require_once __DIR__ . '/../nav/nav_mod.php'; ?>

<div class="hp-wrap">
  <div class="hp-head">
    <a href="admin.php" class="hp-back"><i class="mdi mdi-arrow-left"></i> Volver al panel</a>
    <h1><i class="mdi mdi-history"></i> Historial de pagos</h1>
  </div>

  <div class="hp-filters">
    <select id="filtroMetodo">
      <option value="">Todos los métodos</option>
      <option value="TARJETA">Tarjeta</option>
      <option value="OXXO">OXXO</option>
    </select>
    <select id="filtroEstatus">
      <option value="">Todos los estados</option>
      <option value="CONFIRMADO">Confirmado</option>
      <option value="PENDIENTE">Pendiente</option>
      <option value="PENDIENTE_OXXO">Pendiente OXXO</option>
      <option value="CANCELADO">Cancelado</option>
    </select>
    <input type="text" id="filtroBuscar" placeholder="Buscar correo o referencia..." style="min-width:220px;">
    <button id="btnFiltrar"><i class="mdi mdi-magnify"></i> Buscar</button>
    <button id="btnReset" style="background:#60708f;">Limpiar</button>
  </div>

  <div class="hp-table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Fecha</th>
          <th>Folio</th>
          <th>Nombre</th>
          <th>Correo</th>
          <th>Monto</th>
          <th>Método</th>
          <th>Estado</th>
          <th>Referencia OXXO</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody id="hpTbody">
        <tr><td colspan="10" class="hp-empty"><i class="mdi mdi-loading mdi-spin"></i> Cargando...</td></tr>
      </tbody>
    </table>
  </div>
  <div class="hp-pagination" id="hpPagination"></div>
</div>

<!-- Modal confirmar -->
<div class="modal-overlay" id="modalConfirmar">
  <div class="modal-box">
    <h3><i class="mdi mdi-check-circle-outline" style="color:#065f46;"></i> Confirmar pago OXXO</h3>
    <p id="modalConfirmarTxt">¿Confirmas que el pago OXXO fue recibido correctamente? Esto marcará la orden como <strong>CONFIRMADO</strong>.</p>
    <div class="hp-filters" style="margin-bottom:10px;">
      <input type="text" id="obsConfirmar" placeholder="Observaciones (opcional)" style="width:100%;box-sizing:border-box;">
    </div>
    <div class="modal-actions">
      <button class="btn-cancel-modal" id="btnCancelConfirmar">Cancelar</button>
      <button class="btn-confirm-modal" id="btnDoConfirmar">Confirmar pago</button>
    </div>
  </div>
</div>

<!-- Modal dar de baja -->
<div class="modal-overlay" id="modalBaja">
  <div class="modal-box">
    <h3><i class="mdi mdi-account-remove-outline" style="color:#991b1b;"></i> Dar de baja</h3>
    <p id="modalBajaTxt">¿Deseas dar de baja esta orden y desactivar el pasaporte asociado? Esta acción es irreversible.</p>
    <div class="hp-filters" style="margin-bottom:10px;">
      <input type="text" id="obsBaja" placeholder="Motivo de baja (opcional)" style="width:100%;box-sizing:border-box;">
    </div>
    <div class="modal-actions">
      <button class="btn-cancel-modal" id="btnCancelBaja">Cancelar</button>
      <button class="btn-danger-modal" id="btnDoBaja">Dar de baja</button>
    </div>
  </div>
</div>

<!-- Modal link de pago -->
<div class="modal-overlay" id="modalLinkPago">
  <div class="modal-box">
    <h3><i class="mdi mdi-link-variant" style="color:#1d4ed8;"></i> Generar link de pago</h3>
    <p id="modalLinkTxt" style="font-size:13px;color:#374151;">Se generará un link de pago de Stripe que puedes enviar al cliente para que complete el pago de su pasaporte.</p>
    <div id="linkPagoResultado" style="display:none;">
      <div class="link-result-box" id="linkPagoUrl"></div>
      <button class="btn-copiar" id="btnCopiarLink"><i class="mdi mdi-content-copy"></i> Copiar link</button>
    </div>
    <div class="modal-actions" style="margin-top:14px;">
      <button class="btn-cancel-modal" id="btnCancelLink">Cerrar</button>
      <button class="btn-confirm-modal" id="btnDoLink"><i class="mdi mdi-link-plus"></i> Generar link</button>
    </div>
  </div>
</div>

<script>
(() => {
  'use strict';

  const $ = s => document.querySelector(s);

  let page = 1;
  let totalPages = 1;
  let pendingIdOrden = null;

  function getFiltros() {
    return {
      metodo:  $('#filtroMetodo').value,
      estatus: $('#filtroEstatus').value,
      buscar:  $('#filtroBuscar').value.trim(),
      page,
    };
  }

  function badgeMetodo(m) {
    if (!m || m === 'TARJETA') return '<span class="badge badge--tarjeta">Tarjeta</span>';
    if (m === 'OXXO') return '<span class="badge badge--oxxo">OXXO</span>';
    return `<span class="badge">${m}</span>`;
  }

  function badgeEstatus(e) {
    const map = {
      CONFIRMADO: 'badge--confirmado',
      PENDIENTE: 'badge--pendiente',
      PENDIENTE_OXXO: 'badge--pendiente-oxxo',
      CANCELADO: 'badge--cancelado',
    };
    return `<span class="badge ${map[e] || ''}">${e || '—'}</span>`;
  }

  function fmtMonto(m, moneda) {
    return '$' + Number(m || 0).toLocaleString('es-MX', {minimumFractionDigits:2}) + ' ' + (moneda || 'MXN');
  }

  function fmtFecha(f) {
    if (!f) return '—';
    const d = new Date(f);
    return isNaN(d) ? f : d.toLocaleDateString('es-MX', {day:'2-digit',month:'short',year:'numeric'});
  }

  async function cargar() {
    const tbody = $('#hpTbody');
    tbody.innerHTML = '<tr><td colspan="10" class="hp-empty"><i class="mdi mdi-loading mdi-spin"></i> Cargando...</td></tr>';

    const p = getFiltros();
    const qs = new URLSearchParams(p).toString();

    try {
      const res  = await fetch('endpoints/pats_historial_listar.php?' + qs);
      const data = await res.json();

      if (!data.ok) {
        tbody.innerHTML = `<tr><td colspan="10" class="hp-empty" style="color:#b91c1c;">${data.error || 'Error al cargar.'}</td></tr>`;
        return;
      }

      totalPages = data.total_pages || 1;
      renderPagination();

      if (!data.items || !data.items.length) {
        tbody.innerHTML = '<tr><td colspan="10" class="hp-empty">No hay registros con esos filtros.</td></tr>';
        return;
      }

      tbody.innerHTML = data.items.map(r => {
        const esPendiente = ['PENDIENTE', 'PENDIENTE_OXXO'].includes(r.estatus_pago)
                         || r.estatus_orden === 'PENDIENTE_OXXO';
        const esOxxoPend  = r.estatus_pago === 'PENDIENTE' && r.metodo_pago === 'OXXO';

        const btnConfirmar = esOxxoPend
          ? `<button class="btn-sm btn-confirmar" data-id="${r.id_orden}" data-accion="confirmar" title="Confirmar pago OXXO">
               <i class="mdi mdi-check"></i> Confirmar
             </button>`
          : '';

        const btnVoucher = r.oxxo_voucher_url
          ? `<a href="${r.oxxo_voucher_url}" target="_blank" class="btn-sm btn-voucher" title="Ver ficha OXXO">
               <i class="mdi mdi-barcode"></i> Ficha
             </a>`
          : '';

        const btnLinkPago = esPendiente
          ? `<button class="btn-sm btn-link-pago" data-id="${r.id_orden}" data-accion="linkpago" title="Generar link de pago para el cliente">
               <i class="mdi mdi-link-plus"></i> Link pago
             </button>`
          : '';

        const btnBaja = esPendiente
          ? `<button class="btn-sm btn-baja" data-id="${r.id_orden}" data-accion="baja" title="Dar de baja">
               <i class="mdi mdi-account-off-outline"></i> Baja
             </button>`
          : '';

        return `<tr>
          <td style="color:#60708f;">${r.id_orden}</td>
          <td>${fmtFecha(r.created_at)}</td>
          <td style="font-family:monospace;font-size:12px;">${r.folio_orden || '—'}</td>
          <td>${r.nombre_usuario || ''} ${r.apellido_pa || ''}</td>
          <td style="font-size:12px;">${r.correo_usuario_pats || '—'}</td>
          <td style="font-weight:700;">${fmtMonto(r.monto_orden, r.moneda)}</td>
          <td>${badgeMetodo(r.metodo_pago)}</td>
          <td>${badgeEstatus(r.estatus_pago)}</td>
          <td style="font-family:monospace;font-size:11px;max-width:180px;word-break:break-all;">${r.oxxo_numero_referencia || '—'}</td>
          <td>
            <div style="display:flex;gap:5px;flex-wrap:wrap;">
              ${btnConfirmar}${btnVoucher}${btnLinkPago}${btnBaja}
            </div>
          </td>
        </tr>`;
      }).join('');

      // bind action buttons
      tbody.querySelectorAll('[data-accion]').forEach(btn => {
        btn.addEventListener('click', () => {
          const id = parseInt(btn.dataset.id);
          const accion = btn.dataset.accion;
          pendingIdOrden = id;
          if (accion === 'confirmar')  openModalConfirmar(id);
          else if (accion === 'baja') openModalBaja(id);
          else if (accion === 'linkpago') openModalLinkPago(id);
        });
      });

    } catch (e) {
      tbody.innerHTML = `<tr><td colspan="10" class="hp-empty" style="color:#b91c1c;">Error de red: ${e.message}</td></tr>`;
    }
  }

  function renderPagination() {
    const div = $('#hpPagination');
    div.innerHTML = '';
    for (let i = 1; i <= totalPages; i++) {
      const btn = document.createElement('button');
      btn.className = 'hp-page-btn' + (i === page ? ' active' : '');
      btn.textContent = i;
      btn.addEventListener('click', () => { page = i; cargar(); });
      div.appendChild(btn);
    }
  }

  // ── Modal confirmar ─────────────────────────────────────────────────────────
  function openModalConfirmar(id) {
    $('#modalConfirmarTxt').textContent = `¿Confirmas que el pago OXXO de la orden #${id} fue recibido? La orden quedará como CONFIRMADO.`;
    $('#obsConfirmar').value = '';
    $('#modalConfirmar').classList.add('open');
  }

  $('#btnCancelConfirmar').addEventListener('click', () => $('#modalConfirmar').classList.remove('open'));

  $('#btnDoConfirmar').addEventListener('click', async () => {
    if (!pendingIdOrden) return;
    const obs = $('#obsConfirmar').value.trim();
    $('#btnDoConfirmar').disabled = true;
    try {
      const fd = new FormData();
      fd.append('id_orden', pendingIdOrden);
      fd.append('observaciones', obs);
      const res  = await fetch('endpoints/pats_confirmar_pago_oxxo.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Error al confirmar.');
      $('#modalConfirmar').classList.remove('open');
      cargar();
    } catch (e) {
      alert('Error: ' + e.message);
    } finally {
      $('#btnDoConfirmar').disabled = false;
    }
  });

  // ── Modal baja ──────────────────────────────────────────────────────────────
  function openModalBaja(id) {
    $('#modalBajaTxt').textContent = `¿Dar de baja la orden #${id} y desactivar su pasaporte? Esta acción es irreversible.`;
    $('#obsBaja').value = '';
    $('#modalBaja').classList.add('open');
  }

  $('#btnCancelBaja').addEventListener('click', () => $('#modalBaja').classList.remove('open'));

  $('#btnDoBaja').addEventListener('click', async () => {
    if (!pendingIdOrden) return;
    const obs = $('#obsBaja').value.trim();
    $('#btnDoBaja').disabled = true;
    try {
      const fd = new FormData();
      fd.append('id_orden', pendingIdOrden);
      fd.append('observaciones', obs);
      const res  = await fetch('endpoints/pats_dar_de_baja.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Error al dar de baja.');
      $('#modalBaja').classList.remove('open');
      cargar();
    } catch (e) {
      alert('Error: ' + e.message);
    } finally {
      $('#btnDoBaja').disabled = false;
    }
  });

  // ── Modal link de pago ──────────────────────────────────────────────────────
  function openModalLinkPago(id) {
    $('#modalLinkTxt').textContent = `Se generará un link de pago de Stripe para la orden #${id}. Puedes enviarlo al cliente para que complete el pago.`;
    $('#linkPagoResultado').style.display = 'none';
    $('#linkPagoUrl').textContent = '';
    $('#btnDoLink').disabled = false;
    $('#btnDoLink').innerHTML = '<i class="mdi mdi-link-plus"></i> Generar link';
    $('#modalLinkPago').classList.add('open');
  }

  $('#btnCancelLink').addEventListener('click', () => $('#modalLinkPago').classList.remove('open'));

  $('#btnDoLink').addEventListener('click', async () => {
    if (!pendingIdOrden) return;
    const btn = $('#btnDoLink');
    btn.disabled = true;
    btn.innerHTML = '<i class="mdi mdi-loading mdi-spin"></i> Generando...';
    try {
      const fd = new FormData();
      fd.append('id_orden', pendingIdOrden);
      const res  = await fetch('endpoints/pats_generar_link_pago.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'No se pudo generar el link.');
      const url = data.url || '';
      $('#linkPagoUrl').textContent = url;
      $('#linkPagoResultado').style.display = 'block';
      $('#modalLinkTxt').textContent = 'Link generado. Cópialo y envíalo al cliente:';
      btn.innerHTML = '<i class="mdi mdi-refresh"></i> Regenerar';
      btn.disabled = false;
    } catch (e) {
      alert('Error: ' + e.message);
      btn.disabled = false;
      btn.innerHTML = '<i class="mdi mdi-link-plus"></i> Generar link';
    }
  });

  $('#btnCopiarLink').addEventListener('click', () => {
    const txt = $('#linkPagoUrl').textContent;
    if (!txt) return;
    navigator.clipboard.writeText(txt).then(() => {
      $('#btnCopiarLink').innerHTML = '<i class="mdi mdi-check"></i> ¡Copiado!';
      setTimeout(() => { $('#btnCopiarLink').innerHTML = '<i class="mdi mdi-content-copy"></i> Copiar link'; }, 2000);
    });
  });

  // ── Filtros ─────────────────────────────────────────────────────────────────
  $('#btnFiltrar').addEventListener('click', () => { page = 1; cargar(); });
  $('#btnReset').addEventListener('click', () => {
    $('#filtroMetodo').value = '';
    $('#filtroEstatus').value = '';
    $('#filtroBuscar').value = '';
    page = 1;
    cargar();
  });
  $('#filtroBuscar').addEventListener('keydown', e => { if (e.key === 'Enter') { page = 1; cargar(); } });

  cargar();
})();
</script>
</body>
</html>
