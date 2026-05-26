/*
ez/pats/js/pats_gestor.js
*/
(() => {
  "use strict";

  const PATS = window.PATS;
  if (!PATS || !PATS.ctx || PATS.ctx.view !== "gestor") return;

  const $ = (s) => document.querySelector(s);
  const $$ = (s) => Array.from(document.querySelectorAll(s));
  const CTX = window.PATS_CONTEXT || {};
  const IS_ADMIN = !!CTX.isAdmin;

  let gestorActualToken = "";
  let gestorActualActivo = 0;
  let gestorActualId = 0;

  function num(v) {
    const n = Number(v || 0);
    return Number.isFinite(n) ? n : 0;
  }

  function arr(v) {
    return Array.isArray(v) ? v : [];
  }

  function esc(v) {
    return String(v ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function money(v) {
    return new Intl.NumberFormat("es-MX", {
      style: "currency",
      currency: "MXN",
      maximumFractionDigits: 2
    }).format(num(v));
  }

  function getQueryValue(name) {
    const qs = new URLSearchParams(window.location.search);
    return (qs.get(name) || "").trim();
  }

  function getQueryInt(name) {
    const qs = new URLSearchParams(window.location.search);
    return Number(qs.get(name) || 0);
  }

  function toast(msg) {
    if (window.PATS && typeof window.PATS.toast === "function") {
      window.PATS.toast(msg);
    } else {
      alert(msg);
    }
  }

  function qs() {
    const p = new URLSearchParams();

    const idGestor = $("#patsGestorActor")?.value || "";
    const region = $("#patsGestorRegion")?.value || "";
    const franquicia = $("#patsGestorFranquicia")?.value || "";
    const anio = $("#patsGestorAnio")?.value || "";
    const mes = $("#patsGestorMes")?.value || "";

    if (IS_ADMIN && idGestor) p.set("id_gestor", idGestor);
    if (region) p.set("region", region);
    if (franquicia) p.set("id_franquicia", franquicia);
    if (anio) p.set("anio", anio);
    if (mes) p.set("mes", mes);

    return p.toString();
  }

  async function getJSON(url) {
    const res = await fetch(url, {
      headers: { "X-Requested-With": "XMLHttpRequest" }
    });

    const text = await res.text();
    let data = {};
    try {
      data = text ? JSON.parse(text) : {};
    } catch {
      throw new Error("Respuesta inválida del servidor");
    }

    if (!res.ok || data.ok === false) {
      throw new Error(data.error || "No fue posible cargar el panel del gestor");
    }

    return data;
  }

  function upsertSelectOptions(selectEl, items, valueKey, labelBuilder, keepEmptyLabel = "Todas") {
    if (!selectEl) return;

    const current = String(selectEl.value || "");
    selectEl.innerHTML = `<option value="">${keepEmptyLabel}</option>`;

    arr(items).forEach((row) => {
      const op = document.createElement("option");
      op.value = String(row[valueKey] ?? "");
      op.textContent = labelBuilder(row);
      selectEl.appendChild(op);
    });

    if ([...selectEl.options].some(op => op.value === current)) {
      selectEl.value = current;
    }
  }

  function syncFiltersMeta(data = {}) {
    const regiones = arr(data.regiones);
    const franquicias = arr(data.franquicias_meta);
    const gestores = arr(data.gestores);

    upsertSelectOptions(
      $("#patsGestorRegion"),
      regiones,
      "region",
      (row) => String(row.region || "-"),
      "Todas"
    );

    upsertSelectOptions(
      $("#patsGestorFranquicia"),
      franquicias,
      "id_franquicia",
      (row) => String(row.nombre_franquicia || `Franquicia ${row.id_franquicia || ""}`),
      "Todas"
    );

    if (IS_ADMIN) {
      upsertSelectOptions(
        $("#patsGestorActor"),
        gestores,
        "id_gestor",
        (row) => String(row.nombre_gestor || `Gestor ${row.id_gestor || ""}`),
        "Seleccionar gerente"
      );

      if (!$("#patsGestorActor")?.value && data.id_gestor_actual) {
        $("#patsGestorActor").value = String(data.id_gestor_actual);
      }
    }
  }

  function renderHeaderMeta(data = {}) {
    const gestor = data.gestor || {};
    const franquicias = arr(data.franquicias);

    const nombreGestor =
      String(gestor.nombre_gestor || "").trim() ||
      String($("#patsGestorActor")?.selectedOptions?.[0]?.textContent || "").trim() ||
      String(PATS.ctx.nombre || "").trim() ||
      "-";

    const lblNombre = $("#patsGestorNombreLabel");
    const lblTotal = $("#patsGestorTotalFranquicias");

    if (lblNombre) lblNombre.textContent = nombreGestor;
    if (lblTotal) lblTotal.textContent = String(franquicias.length);
  }

  function renderKpis(kpis = {}) {
    const host = $("#patsGestorKpis");
    if (!host) return;

    host.innerHTML = `
      <article class="pats-kpi-card"><span class="k">Franquicias asociadas</span><strong>${num(kpis.franquicias_asociadas)}</strong></article>
      <article class="pats-kpi-card"><span class="k">Ventas globales</span><strong>${money(kpis.ventas_globales)}</strong></article>
      <article class="pats-kpi-card"><span class="k">Comisión total</span><strong>${money(kpis.comision_total)}</strong></article>
      <article class="pats-kpi-card"><span class="k">Comisión pagada</span><strong>${money(kpis.comision_pagada)}</strong></article>
      <article class="pats-kpi-card"><span class="k">Comisión pendiente</span><strong>${money(kpis.comision_pendiente)}</strong></article>
      <article class="pats-kpi-card"><span class="k">CxC total asociado</span><strong>${money(kpis.cxc_total)}</strong></article>
    `;

    if (window.PATS && typeof window.PATS.paintKpiSemanticTones === "function") {
      window.PATS.paintKpiSemanticTones("#patsGestorKpis .pats-kpi-card");
    }
  }

  function goFranquicia(item) {
    const p = new URLSearchParams();

    if (item.id_franquicia) p.set("id_franquicia", String(item.id_franquicia));
    if (item.region) p.set("region", String(item.region));
    if (item.zona) p.set("zona", String(item.zona));

    const anio = $("#patsGestorAnio")?.value || "";
    const mes = $("#patsGestorMes")?.value || "";

    if (anio) p.set("anio", anio);
    if (mes) p.set("mes", mes);

    window.location.href = `franquicia.php?${p.toString()}`;
  }

  function buildFranquiciaCard(item) {
    return `
      <div class="pats-accordion-card__top">
        <span class="pats-accordion-card__title">${esc(item.nombre_franquicia || "-")}</span>
        <button type="button" class="pats-accordion-card__chevron-btn" aria-label="Expandir franquicia">
          <span class="pats-accordion-card__chevron">⌄</span>
        </button>
      </div>

      <div class="pats-accordion-card__body">
        <div>${esc([item.region, item.zona, item.unidad].filter(Boolean).join(" · ") || "-")}</div>
        <div>Ventas: ${money(item.ventas_total || 0)}</div>
        <div>Distribuciones: ${num(item.distribuciones_total || 0)}</div>
        <div>PATS: ${num(item.pats_total || 0)}</div>

        <div style="margin-top:10px; padding-top:10px; border-top:1px solid rgba(255,255,255,.10);">
          <div><strong>Comisión gestor:</strong> ${money(item.comision_gestor_total || 0)}</div>
          <div>Pagado: ${money(item.comision_pagada || 0)}</div>
          <div>Pendiente: ${money(item.comision_pendiente || 0)}</div>
          <div>CxC asociado: ${money(item.cxc_total || 0)}</div>
        </div>

        <div class="pats-accordion-card__actions">
          <span class="pats-accordion-card__hint">Entrar al detalle de la franquicia.</span>
          <button type="button" class="pats-mini-btn pats-mini-btn--go">Ver franquicia →</button>
        </div>
      </div>
    `;
  }

  function renderFranquiciaList(items = []) {
    const host = $("#patsGestorFranquiciaList");
    if (!host) return;

    if (!items.length) {
      host.innerHTML = `<div class="pats-empty-state">Sin franquicias asociadas.</div>`;
      return;
    }

    host.innerHTML = "";

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

          $$("#patsGestorFranquiciaList .pats-accordion-card").forEach((x) => {
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

      card.addEventListener("click", () => {
        const sel = $("#patsGestorFranquicia");
        if (sel) {
          sel.value = String(item.id_franquicia || "");
          loadData();
        }
      });

      card.addEventListener("dblclick", () => goFranquicia(item));

      host.appendChild(card);
    });
  }

  function renderTable(items = []) {
    const body = $("#patsGestorTableBody");
    if (!body) return;

    if (!items.length) {
      body.innerHTML = `
        <tr>
          <td colspan="10" class="pats-empty-inline">Sin datos para mostrar.</td>
        </tr>
      `;
      return;
    }

    body.innerHTML = items.map((row) => `
      <tr>
        <td>${esc(row.nombre_franquicia || "-")}</td>
        <td>${esc(row.region || "-")}</td>
        <td>${esc(row.zona || "-")}</td>
        <td>${esc(row.unidad || "-")}</td>
        <td>${money(row.ventas_total || 0)}</td>
        <td>${num(row.distribuciones_total || 0)}</td>
        <td>${num(row.pats_total || 0)}</td>
        <td>${money(row.comision_gestor_total || 0)}</td>
        <td>${money(row.comision_pagada || 0)}</td>
        <td>${money(row.comision_pendiente || 0)}</td>
      </tr>
    `).join("");
  }

  function renderRankings(rankings = {}) {
    const ventasHost = $("#patsGestorRankingVentas");
    const comisionHost = $("#patsGestorRankingComision");

    const topVentas = arr(rankings.ventas);
    const topComision = arr(rankings.comision);

    if (ventasHost) {
      ventasHost.innerHTML = topVentas.length
        ? topVentas.map((row, idx) => `
          <div class="pats-ranking-item">
            <span>${idx + 1}. ${esc(row.nombre_franquicia || "-")}</span>
            <strong>${money(row.ventas_total || 0)}</strong>
          </div>
        `).join("")
        : `<div class="pats-empty-inline">Sin datos.</div>`;
    }

    if (comisionHost) {
      comisionHost.innerHTML = topComision.length
        ? topComision.map((row, idx) => `
          <div class="pats-ranking-item">
            <span>${idx + 1}. ${esc(row.nombre_franquicia || "-")}</span>
            <strong>${money(row.comision_gestor_total || 0)}</strong>
          </div>
        `).join("")
        : `<div class="pats-empty-inline">Sin datos.</div>`;
    }
  }

  function renderCharts(charts = {}) {
    const ventasData = charts.ventas_por_franquicia || { labels: [], values: [] };
    const comisionesData = charts.comisiones_por_franquicia || { labels: [], values: [] };
    const mensualData = charts.mensual || { labels: [], ventas: [], comisiones: [] };

    const c1 = document.getElementById("patsGestorChart1");
    const c2 = document.getElementById("patsGestorChart2");
    const c3 = document.getElementById("patsGestorChartMensual");

    const e1 = $("#patsGestorChart1Empty");
    const e2 = $("#patsGestorChart2Empty");
    const e3 = $("#patsGestorChartMensualEmpty");

    PATS.destroyChart("gestor1");
    PATS.destroyChart("gestor2");
    PATS.destroyChart("gestorMensual");

    const hasVentas = arr(ventasData.labels).length > 0 && arr(ventasData.values).some(v => num(v) > 0);
    const hasComisiones = arr(comisionesData.labels).length > 0 && arr(comisionesData.values).some(v => num(v) > 0);
    const hasMensual = arr(mensualData.labels).length > 0 &&
      [...arr(mensualData.ventas), ...arr(mensualData.comisiones)].some(v => num(v) > 0);

    if (c1 && hasVentas && window.PATSCharts) {
      c1.hidden = false;
      if (e1) e1.hidden = true;
      PATS.state.charts.gestor1 = window.PATSCharts.buildBar(
        c1,
        ventasData.labels || [],
        (ventasData.values || []).map(v => num(v)),
        "Ventas",
        true
      );
    } else {
      if (c1) c1.hidden = true;
      if (e1) e1.hidden = false;
    }

    if (c2 && hasComisiones && window.PATSCharts) {
      c2.hidden = false;
      if (e2) e2.hidden = true;
      PATS.state.charts.gestor2 = window.PATSCharts.buildBar(
        c2,
        comisionesData.labels || [],
        (comisionesData.values || []).map(v => num(v)),
        "Comisiones",
        true
      );
    } else {
      if (c2) c2.hidden = true;
      if (e2) e2.hidden = false;
    }

    if (c3 && hasMensual && window.Chart) {
      c3.hidden = false;
      if (e3) e3.hidden = true;

      PATS.state.charts.gestorMensual = new Chart(c3, {
        type: "line",
        data: {
          labels: mensualData.labels || [],
          datasets: [
            {
              label: "Ventas",
              data: (mensualData.ventas || []).map(v => num(v)),
              borderColor: "#20D6FF",
              backgroundColor: "rgba(32,214,255,.16)",
              pointBackgroundColor: "#20D6FF",
              pointBorderColor: "#ffffff",
              pointRadius: 4,
              pointHoverRadius: 6,
              pointBorderWidth: 2,
              borderWidth: 3,
              tension: 0.35,
              fill: true
            },
            {
              label: "Comisiones",
              data: (mensualData.comisiones || []).map(v => num(v)),
              borderColor: "#7A5CFF",
              backgroundColor: "rgba(122,92,255,.16)",
              pointBackgroundColor: "#7A5CFF",
              pointBorderColor: "#ffffff",
              pointRadius: 4,
              pointHoverRadius: 6,
              pointBorderWidth: 2,
              borderWidth: 3,
              tension: 0.35,
              fill: true
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: { mode: "index", intersect: false },
          plugins: {
            legend: {
              position: "bottom",
              labels: {
                boxWidth: 12,
                boxHeight: 12,
                usePointStyle: true,
                pointStyle: "circle",
                color: "#AFC3EC",
                font: { size: 11, weight: "700" },
                padding: 16
              }
            },
            tooltip: {
              enabled: false,
              external: window.PATSCharts?.externalTooltipHandler || undefined
            }
          },
          scales: {
            x: {
              ticks: {
                color: "#8EA7D8",
                font: { size: 11, weight: "700" }
              },
              grid: { display: false, drawBorder: false },
              border: { display: false }
            },
            y: {
              beginAtZero: true,
              ticks: {
                color: "#8EA7D8",
                callback: (value) => money(value)
              },
              grid: {
                color: "rgba(123, 148, 210, .14)",
                drawBorder: false
              },
              border: { display: false }
            }
          }
        }
      });
    } else {
      if (c3) c3.hidden = true;
      if (e3) e3.hidden = false;
    }
  }

  async function copyLink(link, okText, errorText) {
    const text = String(link || "").trim();

    if (!text || text.startsWith("function ") || text.includes("function buildGestorPublicLinks")) {
      console.error("[PATS GESTOR] Link inválido para copiar:", text);
      toast("No se pudo construir un link público válido.");
      return;
    }

    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
      } else {
        const ta = document.createElement("textarea");
        ta.value = text;
        ta.setAttribute("readonly", "");
        ta.style.position = "fixed";
        ta.style.opacity = "0";
        ta.style.left = "-9999px";
        ta.style.top = "-9999px";
        document.body.appendChild(ta);
        ta.focus();
        ta.select();

        const ok = document.execCommand("copy");
        document.body.removeChild(ta);

        if (!ok) throw new Error("No se pudo copiar");
      }

      toast(okText || "Link copiado correctamente");
    } catch (e) {
      console.error(e);
      toast(errorText || "No fue posible copiar el link.");
    }
  }

  function buildGestorPublicLinks(token) {
    const safeToken = encodeURIComponent(String(token || "").trim());

    return {
      distribucion: `https://admin-pats.50d.com.mx/distribucion/solicitud?t=${safeToken}`,
      pats: `https://pasaporteatusalud.com/landing_pats.php?t=${safeToken}`,
      franquicia: `https://pasaporteatusalud.com/landing_franquicia.php?t=${safeToken}`
    };
  }

  function getGestorTokenOrThrow() {
    if (IS_ADMIN && getGestorIdActual() <= 0) {
      throw new Error("Primero selecciona un gerente para copiar sus links públicos.");
    }

    const token = String(gestorActualToken || "").trim();

    if (!token) {
      throw new Error("Este gerente aún no tiene token público disponible.");
    }

    if (Number(gestorActualActivo || 0) !== 1) {
      throw new Error("El token público del gerente está inactivo.");
    }

    return token;
  }

  function getGestorIdActual() {
    const fromSelect = $("#patsGestorActor")?.value || "";
    const fromQuery = getQueryInt("id_gestor") || "";

    /*
      ADMIN debe elegir gestor explícitamente.
      Esto evita abrir links internos sin id_gestor.
    */
    if (IS_ADMIN && !String(fromSelect || "").trim() && !Number(fromQuery || 0)) {
      return 0;
    }

    const id =
      Number(fromSelect || 0) ||
      Number(fromQuery || 0) ||
      Number(gestorActualId || 0);

    return Number.isFinite(id) ? id : 0;
  }

  function syncGestorLinkButtonsState() {
    const requiereGestor = IS_ADMIN;
    const idGestor = getGestorIdActual();
    const tieneGestor = idGestor > 0;

    const disabled = requiereGestor && !tieneGestor;

    const botones = [
      "#btnGestorPanelLinkDistribucion",
      "#btnGestorPanelLinkPats",
      "#btnGestorPanelLinkFranquicia",
      "#btnSolicitarFranquicia",
      "#btnSolicitarDistribuidor"
    ];

    botones.forEach((selector) => {
      const btn = $(selector);
      if (!btn) return;

      btn.disabled = disabled;
      btn.setAttribute("aria-disabled", disabled ? "true" : "false");
      btn.title = disabled
        ? "Primero selecciona un gerente"
        : (btn.dataset.originalTitle || btn.title || "");

      if (!btn.dataset.originalTitle) {
        btn.dataset.originalTitle = btn.title || "";
      }

      btn.style.opacity = disabled ? ".48" : "";
      btn.style.cursor = disabled ? "not-allowed" : "";
      btn.style.pointerEvents = disabled ? "none" : "";
    });
  }


  function bindCopiarLinkPublicoGestor() {
    const botones = [
      {
        selector: "#btnGestorPanelLinkDistribucion",
        tipo: "distribucion",
        ok: "Link de distribución copiado correctamente",
        err: "No fue posible copiar el link de distribución"
      },
      {
        selector: "#btnGestorPanelLinkPats",
        tipo: "pats",
        ok: "Link PATS copiado correctamente",
        err: "No fue posible copiar el link PATS"
      },
      {
        selector: "#btnGestorPanelLinkFranquicia",
        tipo: "franquicia",
        ok: "Link de franquicia copiado correctamente",
        err: "No fue posible copiar el link de franquicia"
      }
    ];

    botones.forEach((cfg) => {
      const btn = $(cfg.selector);
      if (!btn) return;

      btn.onclick = async (ev) => {
        ev.preventDefault();
        ev.stopPropagation();

        try {
          const token = getGestorTokenOrThrow();
          const links = buildGestorPublicLinks(token);
          const link = String(links[cfg.tipo] || "").trim();

          await copyLink(link, cfg.ok, cfg.err);
        } catch (e) {
          console.error(e);
          toast(e.message || "No fue posible obtener el token público.");
        }
      };
    });
  }

  async function loadData() {
    if (IS_ADMIN) {
      const selGestor = $("#patsGestorActor");
      if (selGestor && !selGestor.value) {
        gestorActualToken = "";
        gestorActualActivo = 0;
        gestorActualId = 0;

        const meta = await getJSON(`endpoints/dashboard_gestor.php?solo_meta=1`);
        syncFiltersMeta(meta);
        renderHeaderMeta({});
        renderKpis({});
        renderFranquiciaList([]);
        renderTable([]);
        renderRankings({});
        renderCharts({});
        syncGestorLinkButtonsState();
        return;
      }
    }

    const data = await getJSON(`endpoints/dashboard_gestor.php?${qs()}`);

    const gestor = data?.gestor || {};
    gestorActualToken = String(gestor.public_checkout_token || "").trim();
    gestorActualActivo = Number(gestor.public_checkout_activo || 0);
    gestorActualId = Number(gestor.id_gestor || 0);

    syncFiltersMeta(data);
    renderHeaderMeta(data);
    renderKpis(data.kpis || {});
    renderFranquiciaList(arr(data.franquicias));
    renderTable(arr(data.franquicias));
    renderRankings(data.rankings || {});
    renderCharts(data.charts || {});
  }

  function bindEvents() {
    [
      "#patsGestorActor",
      "#patsGestorRegion",
      "#patsGestorFranquicia",
      "#patsGestorAnio",
      "#patsGestorMes"
    ].forEach((sel) => {
      const el = $(sel);
      if (!el) return;

      el.addEventListener("change", async () => {
        syncGestorLinkButtonsState();
        await loadData();
        syncGestorLinkButtonsState();
      });
    });

    const btnBack = $("#btnPatsBackAdmin");
    if (btnBack) {
      btnBack.addEventListener("click", () => {
        window.location.href = "admin.php";
      });
    }

    const btnSolicitarFranquicia = $("#btnSolicitarFranquicia");
    if (btnSolicitarFranquicia) {
      btnSolicitarFranquicia.addEventListener("click", () => {
        const idGestor = getGestorIdActual();

        if (IS_ADMIN && idGestor <= 0) {
          toast("Primero selecciona un gerente.");
          return;
        }
/*
        const url = new URL(
          "https://50d.com.mx/50D/EZHS/ez/patsfin/franquicia_links.php",
          window.location.href
        );
*/
 const url = new URL(
          "",
          window.location.href
        );

        if (idGestor > 0) {
          url.searchParams.set("id_gestor", String(idGestor));
        }

        window.open(url.toString(), "_blank");
      });
    }

    const btnSolicitarDistribuidor = $("#btnSolicitarDistribuidor");
    if (btnSolicitarDistribuidor) {
      btnSolicitarDistribuidor.addEventListener("click", () => {
        const idGestor = getGestorIdActual();

        if (IS_ADMIN && idGestor <= 0) {
          toast("Primero selecciona un gerente.");
          return;
        }

        const url = new URL(
          "",
          window.location.href
        );
        /*
        
        const url = new URL(
          "https://50d.com.mx/50D/EZHS/ez/patsfin/distribucion_links.php",
          window.locati
        */

        if (idGestor > 0) {
          url.searchParams.set("id_gestor", String(idGestor));
        }

        window.open(url.toString(), "_blank");
      });
    }
    const search = $("#patsSearchFranquicia");
    if (search) {
      search.addEventListener("input", PATS.debounce(() => {
        const q = search.value.trim().toLowerCase();
        $$("#patsGestorFranquiciaList .pats-accordion-card").forEach((card) => {
          const txt = (card.textContent || "").toLowerCase();
          card.style.display = txt.includes(q) ? "" : "none";
        });
      }, 150));
    }
  }

  document.addEventListener("DOMContentLoaded", async () => {
    const idGestorQ = getQueryInt("id_gestor");
    const regionQ = getQueryValue("region");
    const idFranquiciaQ = getQueryInt("id_franquicia");
    const anioQ = getQueryValue("anio");
    const mesQ = getQueryValue("mes");

    if (IS_ADMIN && idGestorQ > 0) {
      const sel = $("#patsGestorActor");
      if (sel) sel.value = String(idGestorQ);
    }

    if (regionQ) {
      const sel = $("#patsGestorRegion");
      if (sel) sel.value = regionQ;
    }

    if (idFranquiciaQ > 0) {
      const sel = $("#patsGestorFranquicia");
      if (sel) sel.value = String(idFranquiciaQ);
    }

    if (anioQ) {
      const el = $("#patsGestorAnio");
      if (el) el.value = anioQ;
    }

    if (mesQ) {
      const el = $("#patsGestorMes");
      if (el) el.value = mesQ;
    }

    bindEvents();
    bindCopiarLinkPublicoGestor();
    syncGestorLinkButtonsState();
    try {
      if (IS_ADMIN) {
        const meta = await getJSON("endpoints/dashboard_gestor.php?solo_meta=1");
        syncFiltersMeta(meta);

        if (idGestorQ > 0) {
          const sel = $("#patsGestorActor");
          if (sel) sel.value = String(idGestorQ);
        }
      }

      await loadData();
    } catch (err) {
      console.error(err);

      const host = $("#patsGestorFranquiciaList");
      if (host) {
        host.innerHTML = `<div class="pats-empty-state">${esc(err.message || "Error al cargar el panel del gestor")}</div>`;
      }

      const table = $("#patsGestorTableBody");
      if (table) {
        table.innerHTML = `<tr><td colspan="10" class="pats-empty-inline">${esc(err.message || "Error al cargar")}</td></tr>`;
      }
    }
  });
})();