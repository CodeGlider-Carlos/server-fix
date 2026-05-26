/*
ez/pats/js/pats_mis_franquicias.js
*/
(() => {
  "use strict";

  const PATS = window.PATS;
  if (!PATS || !PATS.ctx || PATS.ctx.view !== "mis_franquicias") return;

  function getQueryValue(name) {
    const qs = new URLSearchParams(window.location.search);
    return (qs.get(name) || "").trim();
  }

  function num(v) {
    return Number(v || 0);
  }

  function renderKPIs(kpis) {
    const cards = PATS.$$("#patsMisFranqKpis .pats-kpi-card");
    if (cards.length < 4) return;

    cards[0].querySelector("strong").textContent = PATS.formatMoney(kpis.ventas_totales || 0);
    cards[1].querySelector("strong").textContent = PATS.formatMoney(kpis.ventas_real || 0);
    cards[2].querySelector("strong").textContent = PATS.formatMoney(kpis.ingreso_extra || 0);
    cards[3].querySelector("strong").textContent = String(num(kpis.activos));
  }

  function goFranquicia(item) {
    const qs = new URLSearchParams();

    qs.set("id_franquicia", String(item.id_franquicia || ""));

    if (item.region) {
      qs.set("region", String(item.region));
    } else if (PATS.state.filters.region) {
      qs.set("region", String(PATS.state.filters.region));
    }

    if (item.zona) {
      qs.set("zona", String(item.zona));
    } else if (PATS.state.filters.zona) {
      qs.set("zona", String(PATS.state.filters.zona));
    }

    if (PATS.state.filters.anio) qs.set("anio", String(PATS.state.filters.anio));
    if (PATS.state.filters.mes) qs.set("mes", String(PATS.state.filters.mes));

    window.location.href = `franquicia.php?${qs.toString()}`;
  }

  function buildFranquiciaCard(item) {
    const comisionAdminPats = num(item.comision_admin || 0);
    const comisionAdminDistribuciones = num(item.comision_admin_distribuciones || 0);
    const comisionAdminTotal = comisionAdminPats + comisionAdminDistribuciones;

    return `
      <div class="pats-accordion-card__top">
        <span class="pats-accordion-card__title">${PATS.escapeHtml(item.nombre_franquicia || "-")}</span>
        <button type="button" class="pats-accordion-card__chevron-btn" aria-label="Expandir franquicia">
          <span class="pats-accordion-card__chevron">⌄</span>
        </button>
      </div>

      <div class="pats-accordion-card__body">
        <div>${PATS.escapeHtml(item.region || "-")} · ${PATS.escapeHtml(item.zona || "-")} · ${PATS.escapeHtml(item.unidad || "-")}</div>
        <div>Distribuidores: ${num(item.total_distribuidores)}</div>
        <div>Ventas totales: ${PATS.formatMoney(item.ventas_totales || 0)}</div>
        <div>Ventas PATS: ${PATS.formatMoney(item.ventas_real || 0)}</div>
        <div>Distribuciones: ${PATS.formatMoney(item.ventas_distribuciones || 0)}</div>
        <div>Ingreso extra: ${PATS.formatMoney(item.ingreso_extra || 0)}</div>
        <div>Activos: ${num(item.activos)} · Vencidos: ${num(item.vencidos)}</div>

        <div style="margin-top:10px; padding-top:10px; border-top:1px solid rgba(255,255,255,.10);">
          <div><strong>Comisión corporativo:</strong> ${PATS.formatMoney(comisionAdminTotal)}</div>
          <div>Por distribuciones: ${PATS.formatMoney(item.comision_admin_distribuciones || 0)}</div>
          <div>Por PATS: ${PATS.formatMoney(item.comision_admin || 0)}</div>
        </div>

        <div class="pats-accordion-card__actions">
          <span class="pats-accordion-card__hint"></span>
          <button type="button" class="pats-mini-btn pats-mini-btn--go">Ver franquicia →</button>
        </div>
      </div>
    `;
  }

  function renderFranquicias(items) {
    const wrap = PATS.$("#patsFranquiciaList");
    const total = PATS.$("#patsMisFranqTotal");
    const regionLabel = PATS.$("#patsMisFranqRegionLabel");

    if (!wrap) return;

    if (total) total.textContent = String(Array.isArray(items) ? items.length : 0);
    if (regionLabel) {
      regionLabel.textContent = PATS.state.filters.region || PATS.ctx.region || "Todas disponibles";
    }

    wrap.innerHTML = "";

    if (!Array.isArray(items) || !items.length) {
      wrap.innerHTML = `<div class="pats-empty-state">Sin franquicias disponibles.</div>`;
      return;
    }

    items.forEach((item) => {
      const card = document.createElement("article");
      card.className = "pats-accordion-card pats-accordion-card--franq is-collapsed";
      card.innerHTML = buildFranquiciaCard(item);

      const btnChevron = card.querySelector(".pats-accordion-card__chevron-btn");
      const btnGo = card.querySelector(".pats-mini-btn--go");

      if (btnChevron) {
        btnChevron.addEventListener("click", (ev) => {
          ev.preventDefault();
          ev.stopPropagation();

          const isOpen = card.classList.contains("is-open");

          PATS.$$("#patsFranquiciaList .pats-accordion-card").forEach((x) => {
            x.classList.remove("is-open");
            x.classList.add("is-collapsed");
          });

          if (!isOpen) {
            card.classList.remove("is-collapsed");
            card.classList.add("is-open");
          }
        });
      }

      if (btnGo) {
        btnGo.addEventListener("click", (ev) => {
          ev.preventDefault();
          ev.stopPropagation();
          goFranquicia(item);
        });
      }

      card.addEventListener("dblclick", () => goFranquicia(item));

      wrap.appendChild(card);
    });
  }

  function renderCharts(items) {
    const rows = Array.isArray(items) ? items : [];
    const labels = rows.map((x) => x.nombre_franquicia || "-");
    const ventasTotales = rows.map((x) => num(x.ventas_totales || 0));
    const activos = rows.reduce((a, x) => a + num(x.activos || 0), 0);
    const vencidos = rows.reduce((a, x) => a + num(x.vencidos || 0), 0);

    const c1 = document.getElementById("patsMisFranqChart1");
    const c2 = document.getElementById("patsMisFranqChart2");
    const e1 = PATS.$("#patsMisFranqChart1Empty");
    const e2 = PATS.$("#patsMisFranqChart2Empty");

    PATS.destroyChart("misFranq1");
    PATS.destroyChart("misFranq2");

    const has1 = ventasTotales.some((v) => v > 0);
    const has2 = activos > 0 || vencidos > 0;

    if (c1 && has1 && window.PATSCharts) {
      c1.hidden = false;
      if (e1) e1.hidden = true;
      PATS.state.charts.misFranq1 = window.PATSCharts.buildBar(
        c1,
        labels,
        ventasTotales,
        "Ventas totales",
        true
      );
    } else {
      if (c1) c1.hidden = true;
      if (e1) e1.hidden = false;
    }

    if (c2 && has2 && window.PATSCharts) {
      c2.hidden = false;
      if (e2) e2.hidden = true;
      PATS.state.charts.misFranq2 = window.PATSCharts.buildDoughnut(
        c2,
        ["Activos", "Vencidos"],
        [activos, vencidos],
        false
      );
    } else {
      if (c2) c2.hidden = true;
      if (e2) e2.hidden = false;
    }
  }

  function renderRanking(items) {
    const wrapVentas = PATS.$("#patsRankingVentasFranquicia");
    const wrapActivos = PATS.$("#patsRankingActivosFranquicia");
    const wrapVencidos = PATS.$("#patsRankingVencidosFranquicia");

    const source = Array.isArray(items) ? [...items] : [];

    const topVentas = [...source]
      .sort((a, b) => num(b.ventas_totales || 0) - num(a.ventas_totales || 0))
      .slice(0, 5);

    const topActivos = [...source]
      .sort((a, b) => num(b.activos || 0) - num(a.activos || 0))
      .slice(0, 5);

    const topVencidos = [...source]
      .sort((a, b) => {
        const cmp = num(b.vencidos || 0) - num(a.vencidos || 0);
        if (cmp !== 0) return cmp;
        return num(b.monto_vencido || 0) - num(a.monto_vencido || 0);
      })
      .slice(0, 5);

    if (wrapVentas) {
      wrapVentas.innerHTML = topVentas.length
        ? topVentas.map((item, idx) => `
            <div class="pats-ranking-item">
              <span>${idx + 1}. ${PATS.escapeHtml(item.nombre_franquicia || "-")}</span>
              <strong>${PATS.formatMoney(item.ventas_totales || 0)}</strong>
            </div>
          `).join("")
        : `<div class="pats-empty-inline">Sin datos.</div>`;
    }

    if (wrapActivos) {
      wrapActivos.innerHTML = topActivos.length
        ? topActivos.map((item, idx) => `
            <div class="pats-ranking-item">
              <span>${idx + 1}. ${PATS.escapeHtml(item.nombre_franquicia || "-")}</span>
              <strong>${num(item.activos || 0)} activos</strong>
            </div>
          `).join("")
        : `<div class="pats-empty-inline">Sin datos.</div>`;
    }

    if (wrapVencidos) {
      wrapVencidos.innerHTML = topVencidos.length
        ? topVencidos.map((item, idx) => `
            <div class="pats-ranking-item">
              <span>${idx + 1}. ${PATS.escapeHtml(item.nombre_franquicia || "-")}</span>
              <strong>${num(item.vencidos || 0)} · ${PATS.formatMoney(item.monto_vencido || 0)}</strong>
            </div>
          `).join("")
        : `<div class="pats-empty-inline">Sin datos.</div>`;
    }
  }

  async function loadMisFranquicias() {
    try {
      const data = await PATS.getJSON("dashboard_mis_franquicias.php");
      const items = Array.isArray(data?.franquicias) ? data.franquicias : [];
      const kpis = data?.kpis && typeof data.kpis === "object" ? data.kpis : {};

      renderKPIs(kpis);
      renderFranquicias(items);
      renderCharts(items);
      renderRanking(items);
    } catch (e) {
      console.error("[PATS MIS FRANQUICIAS] load error:", e);

      const wrap = PATS.$("#patsFranquiciaList");
      if (wrap) {
        wrap.innerHTML = `<div class="pats-empty-state">No fue posible cargar las franquicias.</div>`;
      }

      renderKPIs({});
      renderCharts([]);
      renderRanking([]);
      PATS.toast(e.message || "Error cargando franquicias");
    }
  }

  document.addEventListener("DOMContentLoaded", async () => {
    const regionQ = getQueryValue("region");
    const zonaQ = getQueryValue("zona");
    const anioQ = getQueryValue("anio");
    const mesQ = getQueryValue("mes");

    PATS.state.filters.region = regionQ || PATS.ctx.region || "";
    PATS.state.filters.zona = zonaQ || PATS.state.filters.zona || "";
    if (anioQ) PATS.state.filters.anio = anioQ;
    if (mesQ) PATS.state.filters.mes = mesQ;

    const btnBack = PATS.$("#btnPatsBackAdmin");
    if (btnBack) {
      btnBack.addEventListener("click", () => PATS.goToAdmin());
    }

    await PATS.loadScopeOptions();
    PATS.bindCommonFilters(loadMisFranquicias);

    const search = PATS.$("#patsSearchFranquicia");
    if (search) {
      search.addEventListener("input", PATS.debounce(() => {
        const q = search.value.trim().toLowerCase();
        PATS.$$("#patsFranquiciaList .pats-accordion-card").forEach((card) => {
          const txt = (card.textContent || "").toLowerCase();
          card.style.display = txt.includes(q) ? "" : "none";
        });
      }, 150));
    }

    loadMisFranquicias();
  });
})();