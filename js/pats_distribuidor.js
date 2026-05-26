/*
ez/pats/js/pats_distribuidor.js
PATS · Distribuidor
Corrección crítica:
- NO manda "dashboard_distribuidor.php?id=..." dentro de PATS.getJSON().
- PATS.getJSON() ya agrega filtros con ? usando pats_core.js.
- Por eso aquí se manda: PATS.getJSON("dashboard_distribuidor.php", filtrosExtra)
- Resuelve el Link PATS igual que franquicia:
  public_checkout_token -> https://pasaporteatusalud.com/landing_pats.php?t=TOKEN
*/
(() => {
  "use strict";

  const PATS = window.PATS;
  if (!PATS || !PATS.ctx || PATS.ctx.view !== "distribuidor") return;

  const puedeDesplegarVencidos = !!window.PATS_CONTEXT?.puede_desplegar_vencidos;
  const LINK_PATS_BASE = "https://pasaporteatusalud.com/landing_pats.php?t=";

  function getQueryValue(name) {
    const qs = new URLSearchParams(window.location.search);
    return qs.get(name) || "";
  }

  function getQueryInt(name) {
    const qs = new URLSearchParams(window.location.search);
    return Number(qs.get(name) || 0);
  }

  function getDashboardExtraFilters() {
    const qsActual = new URLSearchParams(window.location.search);

    const extra = {};

    const idDistribuidor = qsActual.get("id_distribuidor") || String(PATS.state?.filters?.id_distribuidor || "");
    const idFranquicia = qsActual.get("id_franquicia") || String(PATS.state?.filters?.id_franquicia || "");
    const region = qsActual.get("region") || String(PATS.state?.filters?.region || "");
    const zona = qsActual.get("zona") || String(PATS.state?.filters?.zona || "");
    const anio = qsActual.get("anio") || String(PATS.state?.filters?.anio || "");
    const mes = qsActual.get("mes") || String(PATS.state?.filters?.mes || "");
    const directosFranquicia = qsActual.get("directos_franquicia") || "";

    if (idDistribuidor) extra.id_distribuidor = idDistribuidor;
    if (idFranquicia) extra.id_franquicia = idFranquicia;
    if (region) extra.region = region;
    if (zona) extra.zona = zona;
    if (anio) extra.anio = anio;
    if (mes) extra.mes = mes;
    if (directosFranquicia) extra.directos_franquicia = directosFranquicia;

    return extra;
  }

  function toToken(value) {
    let raw = String(value || "").trim();
    if (!raw) return "";

    if (raw.includes("?t=") || raw.includes("&t=")) {
      try {
        const url = new URL(raw, window.location.origin);
        raw = String(url.searchParams.get("t") || "").trim();
      } catch (_) {
        const m = raw.match(/[?&]t=([^&]+)/);
        raw = m ? decodeURIComponent(m[1]) : "";
      }
    }

    return raw;
  }

  function getPublicCheckoutTokenDistribuidor(distribuidor) {
    return toToken(
      distribuidor?.public_checkout_token ||
      distribuidor?.checkout_token ||
      distribuidor?.token_publico ||
      distribuidor?.token_checkout_publico ||
      ""
    );
  }

  function getLinkPatsDistribuidor(distribuidor) {
    const token = getPublicCheckoutTokenDistribuidor(distribuidor);
    if (token) return LINK_PATS_BASE + encodeURIComponent(token);

    const linkBackend = String(distribuidor?.link_pats_publico || "").trim();
    if (linkBackend.includes("pasaporteatusalud.com/landing_pats.php")) return linkBackend;

    return "";
  }

  function setLinkButtonState(btn, enabled, title = "") {
    if (!btn) return;

    btn.disabled = !enabled;
    btn.setAttribute("aria-disabled", enabled ? "false" : "true");
    if (title) btn.title = title;

    btn.style.opacity = enabled ? "1" : ".58";
    btn.style.cursor = enabled ? "pointer" : "not-allowed";
  }

  async function copiarLinkPublico(link, okMsg) {
    const value = String(link || "").trim();

    if (!value || !value.includes("pasaporteatusalud.com/landing_pats.php?t=")) {
      PATS.toast("No hay Link PATS válido para copiar.");
      return;
    }

    try {
      await navigator.clipboard.writeText(value);
      PATS.toast(okMsg || "Link PATS copiado correctamente");
      return;
    } catch (e) {
      console.warn("[PATS DISTRIBUIDOR] Clipboard API bloqueada, usando fallback.", e);
    }

    try {
      const tmp = document.createElement("textarea");
      tmp.value = value;
      tmp.setAttribute("readonly", "readonly");
      tmp.style.position = "fixed";
      tmp.style.left = "-9999px";
      tmp.style.top = "0";
      document.body.appendChild(tmp);
      tmp.focus();
      tmp.select();
      tmp.setSelectionRange(0, value.length);

      const ok = document.execCommand("copy");
      tmp.remove();

      if (ok) {
        PATS.toast(okMsg || "Link PATS copiado correctamente");
      } else {
        window.prompt("Copia el Link PATS:", value);
      }
    } catch (err) {
      console.error("[PATS DISTRIBUIDOR] No fue posible copiar:", err);
      window.prompt("Copia el Link PATS:", value);
    }
  }

function bindCopiarLinkPublicoDistribuidor(distribuidor) {
  const btnPats = PATS.$("#btnCopiarLinkPublicoPats");
  const input = PATS.$("#patsLinkPublicoInput");

  const linkPats = String(getLinkPatsDistribuidor(distribuidor) || "").trim();
  const esUrlValida = linkPats.includes("https://pasaporteatusalud.com/landing_pats.php?t=");

  if (input) {
    input.value = esUrlValida ? linkPats : "";
    input.placeholder = esUrlValida
      ? "Link PATS listo para copiar"
      : "Este distribuidor todavía no tiene token público PATS";

    input.onclick = () => {
      if (!input.value) return;
      input.focus();
      input.select();
    };
  }

  setLinkButtonState(
    btnPats,
    esUrlValida,
    esUrlValida
      ? "Copiar link público para venta de PATS"
      : "Aún no tienes link público disponible"
  );

  if (!btnPats) return;

  btnPats.onclick = async (ev) => {
    ev.preventDefault();
    ev.stopPropagation();

    const value = String(input?.value || linkPats || "").trim();

    if (!value || !value.includes("https://pasaporteatusalud.com/landing_pats.php?t=")) {
      PATS.toast("Aún no tienes link público disponible.");
      console.warn("[PATS DISTRIBUIDOR] No hay URL válida para copiar.", {
        distribuidor,
        linkPats,
        inputValue: input?.value || ""
      });
      return;
    }

    await copiarLinkPublico(value, "Link PATS copiado correctamente");
  };

  console.log("[PATS DISTRIBUIDOR] Link PATS final:", esUrlValida ? linkPats : "");
}
  function renderFicha(d) {
    const set = (id, value) => {
      const el = PATS.$(id);
      if (el) el.textContent = value || "-";
    };

    set("#patsFichaRegion", d.region || "-");
    set("#patsFichaZona", d.zona || "-");
    set("#patsFichaUnidad", d.unidad || "-");
    set("#patsFichaFranquicia", d.nombre_franquicia || "-");
    set("#patsFichaDistribuidor", d.nombre || "-");
    set("#patsFichaCorreo", d.correo || "-");
    set("#patsFichaTelefono", d.telefono || "-");
  }

  function renderKPIs(kpis) {
    const cards = PATS.$$("#patsDistribuidorKpis .pats-kpi-card");
    if (cards.length < 4) return;

    cards[0].querySelector("strong").textContent = PATS.formatMoney(kpis.ventas_real || 0);
    cards[1].querySelector("strong").textContent = PATS.formatMoney(kpis.monto_vencido || 0);
    cards[2].querySelector("strong").textContent = PATS.formatMoney(kpis.mis_comisiones_activas || 0);
    cards[3].querySelector("strong").textContent = PATS.formatMoney(kpis.mis_comisiones_perdidas || 0);

    if (window.PATS && typeof window.PATS.paintKpiSemanticTones === "function") {
      window.PATS.paintKpiSemanticTones("#patsDistribuidorKpis .pats-kpi-card");
    }
  }

  function renderCharts(charts) {
    const c1 = document.getElementById("patsDistribuidorChart1");
    const c2 = document.getElementById("patsDistribuidorChart2");
    const e1 = PATS.$("#patsDistribuidorChart1Empty");
    const e2 = PATS.$("#patsDistribuidorChart2Empty");

    PATS.destroyChart("dist1");
    PATS.destroyChart("dist2");

    const v1 = Array.isArray(charts?.nominal_real?.values)
      ? charts.nominal_real.values.map(v => Number(v || 0))
      : [];

    const v2 = Array.isArray(charts?.estado?.values)
      ? charts.estado.values.map(v => Number(v || 0))
      : [];

    const has1 = v1.some(v => v > 0);
    const has2 = v2.some(v => v > 0);

    if (c1 && has1 && window.PATSCharts) {
      c1.hidden = false;
      if (e1) e1.hidden = true;
      PATS.state.charts.dist1 = window.PATSCharts.buildBar(
        c1,
        charts.nominal_real.labels || [],
        v1,
        "Base vs real",
        true
      );
    } else {
      if (c1) c1.hidden = true;
      if (e1) e1.hidden = false;
    }

    if (c2 && has2 && window.PATSCharts) {
      c2.hidden = false;
      if (e2) e2.hidden = true;
      PATS.state.charts.dist2 = window.PATSCharts.buildDoughnut(
        c2,
        charts.estado.labels || [],
        v2,
        false
      );
    } else {
      if (c2) c2.hidden = true;
      if (e2) e2.hidden = false;
    }
  }

  function renderVencidos(items) {
    const wrap = PATS.$("#patsVencidosList");
    if (!wrap) return;

    wrap.innerHTML = "";

    if (!Array.isArray(items) || !items.length) {
      wrap.innerHTML = `<div class="pats-empty-state">Sin vencidos por ahora.</div>`;
      return;
    }

    items.forEach((item) => {
      const card = document.createElement("article");
      card.className = `pats-accordion-card pats-accordion-card--dist-vencido is-collapsed${puedeDesplegarVencidos ? "" : " is-locked"}`;

      card.innerHTML = `
        <div class="pats-accordion-card__top">
          <div class="pats-vencido-resumen">
            <span>${PATS.escapeHtml(item.nombre_completo || "-")}</span>
            <span>${PATS.escapeHtml(item.nombre_empresa || "No aplica")}</span>
            <span>${PATS.escapeHtml(item.estatus || "-")}</span>
            <span>${PATS.formatMoney(item.valor_base || 0)}</span>
            <span>${PATS.formatMoney(item.valor_final || 0)}</span>
          </div>
          <span class="pats-accordion-card__chevron">⌄</span>
        </div>

        <div class="pats-accordion-card__body">
          <div>Empresa: ${PATS.escapeHtml(item.nombre_empresa || "No aplica")}</div>
          <div>Estatus: ${PATS.escapeHtml(item.estatus || "-")}</div>
          <div>Frecuencia origen: ${PATS.escapeHtml(item.frecuencia_origen || "-")}</div>
          <div>Meses vencidos: ${Number(item.meses_vencidos || 0)}</div>
          <div>Valor base: ${PATS.formatMoney(item.valor_base || 0)}</div>
          <div>Valor final acumulado: ${PATS.formatMoney(item.valor_final || 0)}</div>
          <div>Comisión perdida: ${PATS.formatMoney(item.comision_perdida || 0)}</div>
          <div><strong>Contacto del usuario PATS</strong></div>
          <div>Correo: ${PATS.escapeHtml(item.correo || "No disponible")}</div>
          <div>Teléfono: ${PATS.escapeHtml(item.telefono || "No disponible")}</div>
        </div>
      `;

      const top = card.querySelector(".pats-accordion-card__top");
      top.addEventListener("click", () => {
        if (!puedeDesplegarVencidos) return;
        card.classList.toggle("is-open");
        card.classList.toggle("is-collapsed");
      });

      wrap.appendChild(card);
    });
  }

  function lockZonaDistribuidor(distribuidor) {
    const zonaSel = PATS.$("#patsZona");
    if (!zonaSel) return;

    const zonaReal = String(distribuidor?.zona || "").trim();

    zonaSel.innerHTML = "";
    const opt = document.createElement("option");
    opt.value = zonaReal;
    opt.textContent = zonaReal || "No definida";
    zonaSel.appendChild(opt);

    zonaSel.value = zonaReal;
    zonaSel.disabled = true;
  }

  async function loadDistribuidorDashboard() {
    try {
      const extra = getDashboardExtraFilters();

      console.log("[PATS DISTRIBUIDOR] endpoint:", "dashboard_distribuidor.php", extra);

      /*
        IMPORTANTE:
        No mandar "dashboard_distribuidor.php?id_distribuidor=1".
        pats_core.js ya arma la URL con ? y filtros en PATS.getJSON().
      */
      const data = await PATS.getJSON("dashboard_distribuidor.php", extra);

      if (data.distribuidor) {
        if (data.distribuidor.id_distribuidor) PATS.state.filters.id_distribuidor = Number(data.distribuidor.id_distribuidor || 0);
        if (data.distribuidor.id_franquicia) PATS.state.filters.id_franquicia = Number(data.distribuidor.id_franquicia || 0);
        if (data.distribuidor.region) PATS.state.filters.region = data.distribuidor.region;

        PATS.state.filters.zona = String(data.distribuidor.zona || "").trim();
        PATS.state.filters.unidad = String(data.distribuidor.unidad || "").trim();

        lockZonaDistribuidor(data.distribuidor);
        bindCopiarLinkPublicoDistribuidor(data.distribuidor);
      } else {
        bindCopiarLinkPublicoDistribuidor({});
      }

      renderFicha(data.distribuidor || {});
      renderKPIs(data.kpis || {});
      renderCharts(data.charts || {});
      renderVencidos(data.vencidos || []);
    } catch (e) {
      console.error("[PATS DISTRIBUIDOR] loadDistribuidorDashboard error:", e);
      renderFicha({});
      renderKPIs({});
      renderCharts({});
      renderVencidos([]);
      bindCopiarLinkPublicoDistribuidor({});
      PATS.toast(e.message || "Error cargando distribuidor");
    }
  }

  document.addEventListener("DOMContentLoaded", async () => {
    const idDistribuidor = getQueryInt("id_distribuidor");
    const idFranquicia = getQueryInt("id_franquicia");
    const region = getQueryValue("region");
    const zona = getQueryValue("zona");
    const anio = getQueryValue("anio");
    const mes = getQueryValue("mes");

    if (idDistribuidor) PATS.state.filters.id_distribuidor = idDistribuidor;
    if (idFranquicia) PATS.state.filters.id_franquicia = idFranquicia;
    if (region) PATS.state.filters.region = region;
    if (zona) PATS.state.filters.zona = zona;
    if (anio) PATS.state.filters.anio = anio;
    if (mes) PATS.state.filters.mes = mes;

    const btnBack = PATS.$("#btnPatsBackFranquicia");
    if (btnBack) {
      btnBack.addEventListener("click", () => {
        PATS.goToFranquicia(PATS.state.filters.id_franquicia, PATS.state.filters.region);
      });
    }

    await PATS.loadScopeOptions();
    PATS.bindCommonFilters(loadDistribuidorDashboard);

    const search = PATS.$("#patsSearchPasaporte");
    if (search) {
      search.addEventListener("input", PATS.debounce(() => {
        const q = search.value.trim().toLowerCase();
        PATS.$$("#patsVencidosList .pats-accordion-card").forEach((card) => {
          const txt = card.textContent.toLowerCase();
          card.style.display = txt.includes(q) ? "" : "none";
        });
      }, 200));
    }

    loadDistribuidorDashboard();
  });
})();
