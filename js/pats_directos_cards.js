/*
Archivo: ez/pats/js/pats_directos_cards.js
Módulo: PATS · Pasaportes directos vendidos
Propósito: Pintar PATS directos vendidos con búsqueda y paginación real de 5 registros.
Responsabilidad:
- ADMIN en gestor.php solo carga cuando selecciona gerente en #patsGestorActor.
- Usuario gerente carga automático según su actor de sesión.
- No cargar miles de PATS en navegador.
Conexiones: window.PATS, PATS.ctx, window.PATS_CONTEXT, endpoint pasaportes_directos_listar.php.
Tipo: JS específico PATS.
*/
(() => {
  "use strict";

  const PATS = window.PATS;
  if (!PATS || !PATS.ctx) return;

  const view = String(PATS.ctx.view || "").toLowerCase();
  if (!["admin", "gestor", "franquicia"].includes(view)) return;

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  const HAS_GESTOR_ADMIN_SELECTOR = !!document.getElementById("patsGestorActor");
  const IS_ADMIN_ACTOR = view === "admin"
    || HAS_GESTOR_ADMIN_SELECTOR
    || !!window.PATS_CONTEXT?.is_pats_admin
    || !!window.PATS_CONTEXT?.is_admin
    || !!PATS.ctx?.is_pats_admin
    || !!PATS.ctx?.is_admin;

  const isAdminDashboard = view === "admin";

  let currentPage = 1;
  let perPage = 5;
  let currentQuery = "";
  let totalPages = 1;
  let totalRows = 0;
  let searchTimer = null;
  let lastActorKey = "";

  function money(v) {
    if (PATS && typeof PATS.formatMoney === "function") return PATS.formatMoney(v || 0);
    return new Intl.NumberFormat("es-MX", { style: "currency", currency: "MXN" }).format(Number(v || 0));
  }

  function esc(v) {
    if (PATS && typeof PATS.escapeHtml === "function") return PATS.escapeHtml(v || "");
    const d = document.createElement("div");
    d.textContent = String(v || "");
    return d.innerHTML;
  }

  function q(name) {
    return new URLSearchParams(window.location.search).get(name) || "";
  }

  function injectStyles() {
    if ($("#patsDirectosCardsStyle")) return;

    const style = document.createElement("style");
    style.id = "patsDirectosCardsStyle";
    style.textContent = `
      #patsDirectosVendidosSection, #patsDirectosVendidosSection * { box-sizing: border-box; }
      #patsDirectosVendidosSection { width:100%; max-width:100%; margin:24px 0 0; padding:22px; border-radius:28px; background:linear-gradient(180deg,#fff,#f7f9fd); border:1px solid rgba(148,163,184,.28); box-shadow:0 24px 70px rgba(15,23,42,.14); color:#10203d; overflow:hidden; }
      #patsDirectosVendidosSection .pats-directos-head { display:grid; grid-template-columns:minmax(0,1fr) minmax(260px,360px); gap:18px; align-items:end; margin:0 0 18px; }
      #patsDirectosVendidosSection .pats-directos-title { margin:0; color:#10203d; font-size:26px; line-height:1.1; font-weight:950; }
      #patsDirectosVendidosSection .pats-directos-subtitle { margin:7px 0 0; color:#64748b; font-size:14px; line-height:1.45; font-weight:800; }
      #patsDirectosVendidosSection .pats-directos-search { width:100%; min-height:44px; border:1px solid rgba(100,116,139,.25); border-radius:16px; padding:11px 14px; background:#f8fbff; color:#0f172a; font-size:14px; font-weight:800; outline:none; }
      #patsDirectosVendidosSection .pats-directos-kpis { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:14px; margin:0 0 18px; }
      #patsDirectosVendidosSection.is-admin .pats-directos-kpis { grid-template-columns:repeat(4,minmax(0,1fr)); }
      #patsDirectosVendidosSection .pats-directo-kpi { position:relative; min-height:108px; padding:17px 18px 15px; border-radius:20px; background:radial-gradient(circle at 0 0, rgba(61,108,255,.26), transparent 34%), linear-gradient(135deg,#13294a,#20284f 62%,#141b3a); color:#f8fafc; box-shadow:0 16px 34px rgba(15,23,42,.18); overflow:hidden; border:1px solid rgba(255,255,255,.08); }
      #patsDirectosVendidosSection .pats-directo-kpi:before { content:""; position:absolute; width:10px; height:10px; border-radius:999px; left:14px; top:17px; background:#3b82f6; box-shadow:0 0 18px rgba(59,130,246,.9); }
      #patsDirectosVendidosSection .pats-directo-kpi span { display:block; margin:0 0 10px 16px; color:rgba(226,232,240,.78); font-size:13px; line-height:1.18; font-weight:900; }
      #patsDirectosVendidosSection .pats-directo-kpi strong { display:block; color:#fff; font-size:28px; line-height:1; font-weight:950; white-space:nowrap; }
      #patsDirectosVendidosSection .pats-directo-kpi small { display:block; margin-top:12px; color:rgba(191,204,232,.78); font-size:12px; line-height:1.35; font-weight:800; }
      #patsDirectosVendidosSection .pats-directos-list { display:grid; gap:12px; min-height:72px; }
      #patsDirectosVendidosSection .pats-directo-card { width:100%; border-radius:22px; border:1px solid rgba(148,163,184,.20); background:linear-gradient(135deg,#172243,#222d58 60%,#172044); color:#e5edf8; box-shadow:0 18px 42px rgba(15,23,42,.18); overflow:hidden; }
      #patsDirectosVendidosSection .pats-directo-top { width:100%; min-height:68px; display:grid; grid-template-columns:minmax(180px,1.8fr) minmax(82px,.7fr) minmax(82px,.7fr) minmax(92px,.8fr) minmax(92px,.8fr) 34px; gap:12px; align-items:center; padding:14px 18px; cursor:pointer; user-select:none; }
      #patsDirectosVendidosSection .pats-directo-top:hover { background:rgba(255,255,255,.035); }
      #patsDirectosVendidosSection .pats-directo-cell { min-width:0; }
      #patsDirectosVendidosSection .pats-directo-label { display:block; margin:0 0 4px; color:rgba(191,204,232,.62); font-size:10px; line-height:1; font-weight:900; text-transform:uppercase; letter-spacing:.08em; }
      #patsDirectosVendidosSection .pats-directo-value { display:block; color:#f8fafc; font-size:14px; line-height:1.25; font-weight:900; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
      #patsDirectosVendidosSection .pats-directo-name .pats-directo-value { font-size:16px; }
      #patsDirectosVendidosSection .pats-directo-chevron { display:grid; place-items:center; width:30px; height:30px; min-width:30px; margin-left:auto; border-radius:12px; background:rgba(255,255,255,.08); color:#fff; font-size:16px; line-height:1; font-weight:950; transition:transform .18s ease, background .18s ease; }
      #patsDirectosVendidosSection .pats-directo-card.is-open .pats-directo-chevron { transform:rotate(180deg); background:rgba(125,131,255,.22); }
      #patsDirectosVendidosSection .pats-directo-body { display:none; padding:0 18px 18px; }
      #patsDirectosVendidosSection .pats-directo-card.is-open .pats-directo-body { display:block; }
      #patsDirectosVendidosSection .pats-directo-detail-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; padding:15px; border-radius:18px; background:rgba(9,14,31,.32); border:1px solid rgba(255,255,255,.08); }
      #patsDirectosVendidosSection .pats-directo-detail { min-height:58px; padding:12px; border-radius:14px; background:rgba(255,255,255,.055); border:1px solid rgba(255,255,255,.07); }
      #patsDirectosVendidosSection .pats-directo-detail b { display:block; margin:0 0 6px; color:rgba(191,204,232,.66); font-size:10px; line-height:1; letter-spacing:.08em; text-transform:uppercase; }
      #patsDirectosVendidosSection .pats-directo-detail span { display:block; color:#f8fafc; font-size:13px; line-height:1.35; font-weight:800; overflow-wrap:anywhere; }
      #patsDirectosVendidosSection .pats-directos-empty { padding:34px 20px; border-radius:22px; text-align:center; color:#64748b; font-size:14px; font-weight:900; background:#f8fbff; border:1px dashed rgba(100,116,139,.25); }
      #patsDirectosVendidosSection .pats-directos-pager { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-top:16px; padding:12px; border-radius:18px; background:rgba(15,23,42,.04); border:1px solid rgba(148,163,184,.22); }
      #patsDirectosVendidosSection .pats-directos-pager__group { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
      #patsDirectosVendidosSection .pats-directos-pager button { border:0; border-radius:14px; padding:10px 14px; background:#172243; color:#fff; font-size:13px; font-weight:900; cursor:pointer; box-shadow:0 10px 24px rgba(15,23,42,.16); }
      #patsDirectosVendidosSection .pats-directos-pager button:disabled { opacity:.45; cursor:not-allowed; box-shadow:none; }
      #patsDirectosVendidosSection .pats-directos-page-info { color:#334155; font-size:13px; font-weight:900; }
      #patsDirectosVendidosSection .pats-directos-perpage { border:1px solid rgba(100,116,139,.25); border-radius:12px; padding:9px 10px; background:#fff; color:#0f172a; font-size:13px; font-weight:900; }
      @media (max-width:1100px){ #patsDirectosVendidosSection .pats-directos-kpis, #patsDirectosVendidosSection.is-admin .pats-directos-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); } #patsDirectosVendidosSection .pats-directo-top { grid-template-columns:minmax(160px,1.4fr) minmax(82px,.8fr) minmax(82px,.8fr) 34px; } #patsDirectosVendidosSection .pats-directo-top .pats-directo-cell:nth-child(4), #patsDirectosVendidosSection .pats-directo-top .pats-directo-cell:nth-child(5) { display:none; } #patsDirectosVendidosSection .pats-directo-detail-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
      @media (max-width:720px){ #patsDirectosVendidosSection { padding:18px; border-radius:22px; } #patsDirectosVendidosSection .pats-directos-head, #patsDirectosVendidosSection .pats-directos-kpis, #patsDirectosVendidosSection.is-admin .pats-directos-kpis { grid-template-columns:1fr; } #patsDirectosVendidosSection .pats-directo-top { grid-template-columns:1fr 30px; } #patsDirectosVendidosSection .pats-directo-top .pats-directo-cell:not(:first-child) { display:none; } #patsDirectosVendidosSection .pats-directo-detail-grid { grid-template-columns:1fr; } #patsDirectosVendidosSection .pats-directos-pager { align-items:stretch; flex-direction:column; } #patsDirectosVendidosSection .pats-directos-pager__group { justify-content:center; } }
    `;
    document.head.appendChild(style);
  }

  function getTipoActor() {
    if (view === "gestor") return "gestor";
    if (view === "franquicia") return "franquicia";
    return "admin";
  }

  function getIdActor() {
    if (view === "gestor") {
      if (IS_ADMIN_ACTOR) {
        return Number($("#patsGestorActor")?.value || q("id_gestor") || 0) || 0;
      }

      return Number(
        PATS.ctx?.id_gestor ||
        window.PATS_CONTEXT?.id_gestor ||
        window.PATS_CONTEXT?.id_actor ||
        q("id_gestor") ||
        0
      ) || 0;
    }

    if (view === "franquicia") {
      return Number(
        q("id_franquicia") ||
        PATS.state?.filters?.id_franquicia ||
        $("#patsFranquicia")?.value ||
        $("#patsFranquiciaSelect")?.value ||
        $("#id_franquicia")?.value ||
        window.PATS_CONTEXT?.id_franquicia ||
        window.PATS_CONTEXT?.id_actor ||
        0
      ) || 0;
    }

    return 0;
  }

  function shouldShowEmptyUntilSelection() {
    return view === "gestor" && IS_ADMIN_ACTOR && getIdActor() <= 0;
  }

  function getFilters() {
    const params = new URLSearchParams();
    params.set("tipo_actor", getTipoActor());
    params.set("id_actor", String(getIdActor() || 0));
    params.set("page", String(currentPage));
    params.set("per_page", String(perPage));
    if (currentQuery) params.set("q", currentQuery);

    const anio = q("anio") || PATS.state?.filters?.anio || $("#patsAnio")?.value || "";
    const mes = q("mes") || PATS.state?.filters?.mes || $("#patsMes")?.value || "";
    if (anio) params.set("anio", anio);
    if (mes) params.set("mes", mes);

    if (getTipoActor() === "admin") {
      const region = q("region") || PATS.state?.filters?.region || $("#patsRegion")?.value || "";
      const zona = q("zona") || PATS.state?.filters?.zona || $("#patsZona")?.value || "";
      if (region) params.set("region", region);
      if (zona) params.set("zona", zona);
    }

    return params;
  }

  function endpointsUrl() {
    return String(PATS.ctx.endpointsUrl || "endpoints").replace(/\/+$/, "");
  }

  function findMount() {
    return $("#patsDirectosMount") || $(".pats-dashboard-grid") || $(".pats-main-grid") || $(".pats-main") || $(".pats-content") || $("main") || document.body;
  }

  function ensureSection() {
    injectStyles();

    let section = $("#patsDirectosVendidosSection");
    if (section) return section;

    section = document.createElement("section");
    section.id = "patsDirectosVendidosSection";
    section.classList.toggle("is-admin", isAdminDashboard);

    const adminKpi = isAdminDashboard
      ? `<article class="pats-directo-kpi" data-kpi="admin-hospital"><span>AdminPATS / Hospital</span><strong>$0</strong><small>Admin + hospital</small></article>`
      : "";

    section.innerHTML = `
      <div class="pats-directos-head">
        <div>
          <h2 class="pats-directos-title">Ventas directas</h2>
          <p id="patsDirectosSubtitle" class="pats-directos-subtitle">Cargando información...</p>
        </div>
        <div><input id="patsDirectosSearch" class="pats-directos-search" type="search" placeholder="Buscar por nombre, CURP, correo, teléfono..."></div>
      </div>

      <div id="patsDirectosKpis" class="pats-directos-kpis">
        <article class="pats-directo-kpi" data-kpi="total"><span>Total PATS</span><strong>0</strong><small>Directos vendidos</small></article>
        <article class="pats-directo-kpi" data-kpi="ventas"><span>Ventas</span><strong>$0</strong><small>Valor vendido</small></article>
        <article class="pats-directo-kpi" data-kpi="actor"><span>Mis comisiones PATS</span><strong>$0</strong><small>Según regla comercial</small></article>
        ${adminKpi}
      </div>

      <div id="patsDirectosList" class="pats-directos-list"></div>

      <div class="pats-directos-pager">
        <div class="pats-directos-pager__group">
          <button type="button" id="patsDirectosPrev">‹ Anterior</button>
          <span id="patsDirectosPageInfo" class="pats-directos-page-info">Página 1 de 1</span>
          <button type="button" id="patsDirectosNext">Siguiente ›</button>
        </div>
        <div class="pats-directos-pager__group">
          <span class="pats-directos-page-info" id="patsDirectosTotalInfo">0 registros</span>
          <select id="patsDirectosPerPage" class="pats-directos-perpage" aria-label="Registros por página">
            <option value="5" selected>5 por página</option>
            <option value="10">10 por página</option>
            <option value="20">20 por página</option>
          </select>
        </div>
      </div>
    `;

    findMount().appendChild(section);
    bindControls();
    return section;
  }

  function subtitleByActor(tipo, id) {
    if (tipo === "gestor") {
      if (IS_ADMIN_ACTOR && !id) return "Selecciona un gerente para ver sus PATS directos.";
      return "PATS vendidos directamente por el gerente.";
    }
    if (tipo === "franquicia") return id ? `PATS vendidos directamente por la franquicia #${id}, sin distribuidor.` : "Selecciona una franquicia para ver sus PATS directos.";
    return "PATS corporativos sin franquicia, sin distribuidor y sin gerente.";
  }

  function titleByActor(tipo) {
    if (tipo === "gestor") return "PATS directos del gerente";
    if (tipo === "franquicia") return "PATS directos de franquicia";
    return "PATS directos corporativos";
  }

  function renderKpis(t) {
    const total = $('#patsDirectosKpis [data-kpi="total"]');
    const ventas = $('#patsDirectosKpis [data-kpi="ventas"]');
    const actor = $('#patsDirectosKpis [data-kpi="actor"]');
    const adminHospital = $('#patsDirectosKpis [data-kpi="admin-hospital"]');

    if (total) {
      total.querySelector("strong").textContent = String(t.total_pasaportes || 0);
      total.querySelector("small").textContent = `Activos ${Number(t.activos || 0)} · Vencidos ${Number(t.vencidos || 0)}`;
    }
    if (ventas) ventas.querySelector("strong").textContent = money(t.ventas || 0);
    if (actor) actor.querySelector("strong").textContent = money(t.comision_actor || 0);
    if (adminHospital) {
      adminHospital.querySelector("strong").textContent = money(Number(t.adminpats || 0) + Number(t.hospital || 0));
      adminHospital.querySelector("small").textContent = `Admin ${money(t.adminpats || 0)} · Hospital ${money(t.hospital || 0)}`;
    }
  }

  function detail(label, value) {
    return `<div class="pats-directo-detail"><b>${esc(label)}</b><span>${esc(value || "-")}</span></div>`;
  }

  function detailMoney(label, value) {
    return `<div class="pats-directo-detail"><b>${esc(label)}</b><span>${money(value || 0)}</span></div>`;
  }

  function renderList(items) {
    const wrap = $("#patsDirectosList");
    if (!wrap) return;

    wrap.innerHTML = "";

    if (!Array.isArray(items) || !items.length) {
      const msg = shouldShowEmptyUntilSelection()
        ? "Selecciona un gerente para ver sus PATS directos."
        : "Sin PATS directos para mostrar.";
      wrap.innerHTML = `<div class="pats-directos-empty">${esc(msg)}</div>`;
      return;
    }

    items.forEach((item) => {
      const card = document.createElement("article");
      card.className = "pats-directo-card is-collapsed";

      const adminDetails = isAdminDashboard
        ? `${detailMoney("AdminPATS", item.comision_adminpats || 0)}${detailMoney("Hospital", item.comision_hospital || 0)}`
        : "";

      card.innerHTML = `
        <div class="pats-directo-top" role="button" tabindex="0" aria-expanded="false">
          <div class="pats-directo-cell pats-directo-name"><span class="pats-directo-label">Paciente</span><span class="pats-directo-value">${esc(item.nombre_completo || "-")}</span></div>
          <div class="pats-directo-cell"><span class="pats-directo-label">Frecuencia</span><span class="pats-directo-value">${esc(item.frecuencia_pago || "-")}</span></div>
          <div class="pats-directo-cell"><span class="pats-directo-label">Estatus</span><span class="pats-directo-value">${esc(item.estatus || "-")}</span></div>
          <div class="pats-directo-cell"><span class="pats-directo-label">Venta</span><span class="pats-directo-value">${money(item.valor_final || 0)}</span></div>
          <div class="pats-directo-cell"><span class="pats-directo-label">Mi comisión</span><span class="pats-directo-value">${money(item.comision_actor || 0)}</span></div>
          <span class="pats-directo-chevron">⌄</span>
        </div>

        <div class="pats-directo-body">
          <div class="pats-directo-detail-grid">
            ${detail("ID PATS", Number(item.id_pasaporte || 0))}
            ${detail("CURP", item.curp || "No disponible")}
            ${detail("Fecha nacimiento", item.fecha_nacimiento || "No disponible")}
            ${detail("Fecha alta", item.fecha_alta || "No disponible")}
            ${detail("Vigencia", item.vigencia || item.fecha_vencimiento_real || "No disponible")}
            ${detail("Tipo cliente", item.tipo_cliente || "No disponible")}
            ${detailMoney("Valor final", item.valor_final || 0)}
            ${detailMoney("Mi comisión PATS", item.comision_actor || 0)}
            ${adminDetails}
            ${detail("Empresa", item.nombre_empresa || "No aplica")}
            ${detail("Correo", item.correo || "No disponible")}
            ${detail("Teléfono", item.telefono || "No disponible")}
            ${detail("Región / Zona / Unidad", `${item.region || "-"} · ${item.zona || "-"} · ${item.unidad || "-"}`)}
          </div>
        </div>
      `;

      const top = $(".pats-directo-top", card);
      const toggle = () => {
        const isOpen = card.classList.toggle("is-open");
        card.classList.toggle("is-collapsed", !isOpen);
        top.setAttribute("aria-expanded", isOpen ? "true" : "false");
      };

      top.addEventListener("click", toggle);
      top.addEventListener("keydown", (ev) => {
        if (ev.key === "Enter" || ev.key === " ") {
          ev.preventDefault();
          toggle();
        }
      });

      wrap.appendChild(card);
    });
  }

  function renderPager() {
    const prev = $("#patsDirectosPrev");
    const next = $("#patsDirectosNext");
    const info = $("#patsDirectosPageInfo");
    const totalInfo = $("#patsDirectosTotalInfo");
    const perSel = $("#patsDirectosPerPage");

    if (prev) prev.disabled = currentPage <= 1;
    if (next) next.disabled = currentPage >= totalPages;
    if (info) info.textContent = `Página ${currentPage} de ${totalPages}`;
    if (totalInfo) totalInfo.textContent = `${totalRows} registro${totalRows === 1 ? "" : "s"}`;
    if (perSel) perSel.value = String(perPage);
  }

  async function load() {
    ensureSection();

    const currentActorKey = `${getTipoActor()}:${getIdActor()}`;
    if (currentActorKey !== lastActorKey) {
      currentPage = 1;
      lastActorKey = currentActorKey;
    }

    const params = getFilters();
    const tipo = params.get("tipo_actor") || "admin";
    const id = Number(params.get("id_actor") || 0);

    const title = $("#patsDirectosVendidosSection .pats-directos-title");
    const subtitle = $("#patsDirectosSubtitle");
    if (title) title.textContent = titleByActor(tipo);
    if (subtitle) subtitle.textContent = subtitleByActor(tipo, id);

    try {
      const url = `${endpointsUrl()}/pasaportes_directos_listar.php?${params.toString()}`;
      const res = await fetch(url, { credentials: "same-origin", headers: { "Accept": "application/json" } });
      const txt = await res.text();

      let data;
      try {
        data = JSON.parse(txt);
      } catch (e) {
        console.error("[PATS DIRECTOS] Respuesta no JSON:", txt);
        throw new Error("Respuesta no JSON en pasaportes_directos_listar.php");
      }

      if (!data.ok) throw new Error(data.error || "No fue posible cargar PATS directos");

      currentPage = Number(data.page || 1);
      perPage = Number(data.per_page || 5);
      totalPages = Number(data.total_pages || 1);
      totalRows = Number(data.total_rows || 0);

      renderKpis(data.totales || {});
      renderList(data.pasaportes || []);
      renderPager();
    } catch (err) {
      console.error("[PATS DIRECTOS] Error:", err);
      renderKpis({});
      renderList([]);
      totalPages = 1;
      totalRows = 0;
      renderPager();
      if (PATS.toast) PATS.toast(err.message || "Error cargando PATS directos");
    }
  }

  function bindControls() {
    const search = $("#patsDirectosSearch");
    if (search && search.dataset.bound !== "1") {
      search.dataset.bound = "1";
      search.addEventListener("input", () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
          currentQuery = String(search.value || "").trim();
          currentPage = 1;
          load();
        }, 320);
      });
    }

    const prev = $("#patsDirectosPrev");
    if (prev && prev.dataset.bound !== "1") {
      prev.dataset.bound = "1";
      prev.addEventListener("click", () => {
        if (currentPage > 1) {
          currentPage--;
          load();
        }
      });
    }

    const next = $("#patsDirectosNext");
    if (next && next.dataset.bound !== "1") {
      next.dataset.bound = "1";
      next.addEventListener("click", () => {
        if (currentPage < totalPages) {
          currentPage++;
          load();
        }
      });
    }

    const perSel = $("#patsDirectosPerPage");
    if (perSel && perSel.dataset.bound !== "1") {
      perSel.dataset.bound = "1";
      perSel.addEventListener("change", () => {
        perPage = Number(perSel.value || 5);
        currentPage = 1;
        load();
      });
    }
  }

  function bindExternalFilters() {
    [
      "#patsGestorActor",
      "#patsAnio",
      "#patsMes",
      "#patsRegion",
      "#patsZona",
      "#patsGestor",
      "#patsGestorSelect",
      "#patsFranquicia",
      "#patsFranquiciaSelect"
    ].forEach((sel) => {
      const el = $(sel);
      if (el && el.dataset.patsDirectosBound !== "1") {
        el.dataset.patsDirectosBound = "1";
        el.addEventListener("change", () => {
          currentPage = 1;
          window.setTimeout(load, 250);
        });
      }
    });
  }

  document.addEventListener("DOMContentLoaded", () => {
    ensureSection();
    bindExternalFilters();
    load();
  });

  window.PATSDirectosVendidos = { load };
})();
