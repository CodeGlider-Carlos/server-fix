/*
ez/pats/js/pats_franquicia.js
*/
(() => {
  "use strict";

  const PATS = window.PATS;
  if (!PATS || !PATS.ctx || PATS.ctx.view !== "franquicia") return;

  function getQueryValue(name) {
    const qs = new URLSearchParams(window.location.search);
    return qs.get(name) || "";
  }

  function getQueryInt(name) {
    const qs = new URLSearchParams(window.location.search);
    return Number(qs.get(name) || 0);
  }

  function toNum(v) {
    const n = Number(v);
    return Number.isFinite(n) ? n : 0;
  }

  function isObj(v) {
    return !!v && typeof v === "object" && !Array.isArray(v);
  }

  function pick(obj, keys, fallback = null) {
    if (!isObj(obj)) return fallback;
    for (const key of keys) {
      if (Object.prototype.hasOwnProperty.call(obj, key) && obj[key] !== undefined && obj[key] !== null) {
        return obj[key];
      }
    }
    return fallback;
  }

  function pickNum(obj, keys, fallback = 0) {
    return toNum(pick(obj, keys, fallback));
  }

  function pickArray(obj, keys, fallback = []) {
    const value = pick(obj, keys, fallback);
    return Array.isArray(value) ? value : fallback;
  }

  function getFranquiciaNode(data) {
    if (isObj(data?.franquicia)) return data.franquicia;
    if (isObj(data?.actor)) return data.actor;
    if (isObj(data?.detalle)) return data.detalle;
    return {};
  }

  function getDistribuidoresList(data) {
    if (Array.isArray(data?.distribuidores)) return data.distribuidores;
    if (Array.isArray(data?.items)) return data.items;
    if (Array.isArray(data?.lista_distribuidores)) return data.lista_distribuidores;
    if (Array.isArray(data?.detalle_distribuidores)) return data.detalle_distribuidores;
    return [];
  }

  function getRankingsNode(data) {
    if (isObj(data?.rankings)) return data.rankings;
    if (isObj(data?.ranking)) return data.ranking;
    return {};
  }

  function normalizeKpis(raw) {
    const kpis = isObj(raw) ? raw : {};

    return {
      ventas_real: pickNum(kpis, ["ventas_real", "ventas", "venta_real", "ingreso_real"]),
      monto_vencido: pickNum(kpis, ["monto_vencido", "vencido", "total_vencido", "saldo_vencido"]),

      mis_comisiones_distribucion: pickNum(kpis, [
        "mis_comisiones_distribucion",
        "comision_franquicia",
        "comisiones_por_distribucion",
        "mis_comisiones_distribuidores",
        "mis_comisiones_por_distribuidores",
        "comision_por_distribucion",
        "comision_distribucion"
      ]),

      mis_comisiones_pats_activos: pickNum(kpis, [
        "mis_comisiones_pats_activos",
        "mis_comisiones_activas",
        "comisiones_pats_activos",
        "comision_por_pats_activos",
        "mis_comisiones_por_pats_activos",
        "comisiones_activas"
      ])
    };
  }

  function normalizeDistribuidorItem(item) {
    const activoCount = pickNum(item, ["pats_activos", "activos", "total_activos"]);
    const vencidoCount = pickNum(item, ["pats_vencidos", "vencidos", "total_vencidos"]);

    const porDistribucion = pickNum(item, [
      "comision_por_distribucion",
      "comision_distribucion",
      "comisiones_por_distribucion"
    ]);

    const porPats = pickNum(item, [
      "comision_por_pats_activos",
      "mis_comisiones_pats_activos",
      "comisiones_pats_activos",
      "comisiones_activas"
    ]);

    const totalComision =
      pickNum(item, ["comision_total", "comisiones_totales", "total_comision"]) ||
      (porDistribucion + porPats);

    return {
      id_distribuidor: pickNum(item, ["id_distribuidor", "id"]),
      nombre: pick(item, ["nombre", "nombre_distribuidor", "distribuidor"], "-"),
      rfc: pick(item, ["rfc"], "-"),
      telefono: pick(item, ["telefono", "tel"], "-"),
      correo: pick(item, ["correo", "email"], "-"),
      unidad: pick(item, ["unidad"], "-"),
      region: pick(item, ["region"], ""),
      zona: pick(item, ["zona"], ""),
      pats_activos: activoCount,
      pats_vencidos: vencidoCount,
      ventas_real: pickNum(item, ["ventas_real", "ventas", "ingreso_real"]),
      comision_total: totalComision,
      comision_por_distribucion: porDistribucion,
      comision_por_pats_activos: porPats
    };
  }

  function normalizeRankingItem(item) {
    return {
      nombre: pick(item, ["nombre", "nombre_distribuidor", "distribuidor"], "-"),
      pats_activos: pickNum(item, ["pats_activos", "activos", "total_activos"]),
      pats_vencidos: pickNum(item, ["pats_vencidos", "vencidos", "total_vencidos"]),
      comision_por_pats_activos: pickNum(item, [
        "comision_por_pats_activos",
        "mis_comisiones_pats_activos",
        "comisiones_pats_activos",
        "comisiones_activas"
      ]),
      comision_perdida_vencidos: pickNum(item, [
        "comision_perdida_vencidos",
        "mis_comisiones_perdidas",
        "comisiones_perdidas",
        "perdida_vencidos"
      ])
    };
  }

  function normalizeRankings(rankings, distribuidores) {
    const node = isObj(rankings) ? rankings : {};

    let activos = pickArray(node, ["activos", "top_activos", "ranking_activos"]);
    let vencidos = pickArray(node, ["vencidos", "top_vencidos", "ranking_vencidos"]);

    if (!activos.length && Array.isArray(distribuidores) && distribuidores.length) {
      activos = [...distribuidores]
        .sort((a, b) => toNum(b.pats_activos) - toNum(a.pats_activos))
        .slice(0, 10);
    }

    if (!vencidos.length && Array.isArray(distribuidores) && distribuidores.length) {
      vencidos = [...distribuidores]
        .sort((a, b) => toNum(b.pats_vencidos) - toNum(a.pats_vencidos))
        .slice(0, 10);
    }

    return {
      activos: activos.map(normalizeRankingItem),
      vencidos: vencidos.map(normalizeRankingItem)
    };
  }

  function normalizeCharts(charts, distribuidores, rankings, kpis) {
    const node = isObj(charts) ? charts : {};

    const realNode = isObj(node.real)
      ? node.real
      : (isObj(node.ventas_reales) ? node.ventas_reales : {});

    const nominalRealNode = isObj(node.nominal_real) ? node.nominal_real : {};
    const mensualNode = isObj(node.mensual_anual)
      ? node.mensual_anual
      : (isObj(node.evolucion_mensual)
          ? node.evolucion_mensual
          : (isObj(node.mensual) ? node.mensual : {}));

    const chartReal = {
      labels: pickArray(realNode, ["labels"], []),
      values: pickArray(realNode, ["values", "data"], []).map(toNum)
    };

    if (!chartReal.labels.length || !chartReal.values.length) {
      if (nominalRealNode.labels && nominalRealNode.values) {
        chartReal.labels = pickArray(nominalRealNode, ["labels"], []);
        chartReal.values = pickArray(nominalRealNode, ["values"], []).map(toNum);
      } else if (Array.isArray(distribuidores) && distribuidores.length) {
        const distTop = [...distribuidores]
          .sort((a, b) => toNum(b.ventas_real) - toNum(a.ventas_real))
          .slice(0, 8);

        chartReal.labels = distTop.map(x => String(x.nombre || "-"));
        chartReal.values = distTop.map(x => toNum(x.ventas_real));
      }
    }

    const mensual = {
      labels: pickArray(mensualNode, ["labels", "meses"], []),
      ventas_globales: pickArray(mensualNode, ["ventas_globales", "ventas", "ventas_reales"], []).map(toNum),
      ganancia_franquicia: pickArray(mensualNode, ["ganancia_franquicia", "ganancia", "comisiones"], []).map(toNum),
      distribuidores: pickArray(mensualNode, ["distribuidores", "total_distribuidores"], []).map(toNum),
      pats: pickArray(mensualNode, ["pats", "pasaportes", "total_pats"], []).map(toNum)
    };

    if (!mensual.labels.length) {
      mensual.labels = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
    }

    function padSeries(arr) {
      const out = Array.isArray(arr) ? [...arr] : [];
      while (out.length < mensual.labels.length) out.push(0);
      return out.slice(0, mensual.labels.length);
    }

    mensual.ventas_globales = padSeries(mensual.ventas_globales);
    mensual.ganancia_franquicia = padSeries(mensual.ganancia_franquicia);
    mensual.distribuidores = padSeries(mensual.distribuidores);
    mensual.pats = padSeries(mensual.pats);

    if (!mensual.ventas_globales.some(v => v > 0) && kpis.ventas_real > 0) {
      mensual.ventas_globales[mensual.labels.length - 1] = kpis.ventas_real;
    }

    if (!mensual.ganancia_franquicia.some(v => v > 0)) {
      const totalGanancia = toNum(kpis.mis_comisiones_distribucion) + toNum(kpis.mis_comisiones_pats_activos);
      if (totalGanancia > 0) {
        mensual.ganancia_franquicia[mensual.labels.length - 1] = totalGanancia;
      }
    }

    if (!mensual.distribuidores.some(v => v > 0) && Array.isArray(distribuidores) && distribuidores.length) {
      mensual.distribuidores[mensual.labels.length - 1] = distribuidores.length;
    }

    if (!mensual.pats.some(v => v > 0) && Array.isArray(distribuidores) && distribuidores.length) {
      const totalPats = distribuidores.reduce((acc, item) => acc + toNum(item.pats_activos) + toNum(item.pats_vencidos), 0);
      if (totalPats > 0) {
        mensual.pats[mensual.labels.length - 1] = totalPats;
      }
    }

    return {
      real: chartReal,
      mensual_anual: mensual
    };
  }

  function renderKPIs(rawKpis) {
  const kpis = normalizeKpis(rawKpis);
  const cards = PATS.$$("#patsFranquiciaKpis .pats-kpi-card");
  if (cards.length < 4) return;

  cards[0].querySelector("strong").textContent = PATS.formatMoney(kpis.ventas_real || 0);
  cards[1].querySelector("strong").textContent = PATS.formatMoney(kpis.monto_vencido || 0);
  cards[2].querySelector("strong").textContent = PATS.formatMoney(kpis.mis_comisiones_distribucion || 0);
  cards[3].querySelector("strong").textContent = PATS.formatMoney(kpis.mis_comisiones_pats_activos || 0);

  if (window.PATS && typeof window.PATS.paintKpiSemanticTones === "function") {
    window.PATS.paintKpiSemanticTones("#patsFranquiciaKpis .pats-kpi-card");
  }
}
  function goDistribuidor(item) {
    const qs = new URLSearchParams();

    const idDistribuidor = Number(item.id_distribuidor || 0);
    const idFranquicia = Number(PATS.state.filters.id_franquicia || 0);

    if (idDistribuidor > 0) qs.set("id_distribuidor", String(idDistribuidor));
    if (idFranquicia > 0) qs.set("id_franquicia", String(idFranquicia));

    if (item.region) {
      qs.set("region", item.region);
    } else if (PATS.state.filters.region) {
      qs.set("region", PATS.state.filters.region);
    }

    if (item.zona) {
      qs.set("zona", item.zona);
    } else if (PATS.state.filters.zona) {
      qs.set("zona", PATS.state.filters.zona);
    }

    if (PATS.state.filters.anio) qs.set("anio", String(PATS.state.filters.anio));
    if (PATS.state.filters.mes) qs.set("mes", String(PATS.state.filters.mes));

    window.location.href = `distribuidor.php?${qs.toString()}`;
  }

  function renderDistribuidores(items) {
    const wrap = PATS.$("#patsDistribuidorList");
    if (!wrap) return;

    wrap.innerHTML = "";

    const rows = Array.isArray(items) ? items.map(normalizeDistribuidorItem) : [];

    if (!rows.length) {
      wrap.innerHTML = `<div class="pats-empty-state">Sin distribuidores disponibles.</div>`;
      return;
    }

    rows.forEach((item) => {
      const card = document.createElement("article");
      card.className = "pats-accordion-card pats-accordion-card--dist is-collapsed";

      card.innerHTML = `
        <div class="pats-accordion-card__top">
          <span class="pats-accordion-card__title">${PATS.escapeHtml(item.nombre || "-")}</span>
          <button type="button" class="pats-accordion-card__chevron-btn" aria-label="Expandir distribuidor">
            <span class="pats-accordion-card__chevron">⌄</span>
          </button>
        </div>

        <div class="pats-accordion-card__body">
          <div>RFC: ${PATS.escapeHtml(item.rfc || "-")}</div>
          <div>Tel: ${PATS.escapeHtml(item.telefono || "-")}</div>
          <div>Correo: ${PATS.escapeHtml(item.correo || "-")}</div>
          <div>Unidad: ${PATS.escapeHtml(item.unidad || "-")}</div>
          <div>PATS activos: ${Number(item.pats_activos || 0)}</div>
          <div>PATS vencidos: ${Number(item.pats_vencidos || 0)}</div>
          <div>Ventas reales: ${PATS.formatMoney(item.ventas_real || 0)}</div>

          <div style="margin-top:10px; padding-top:10px; border-top:1px solid rgba(255,255,255,.10);">
            <div><strong>Mi comisión total:</strong> ${PATS.formatMoney(item.comision_total || 0)}</div>
            <div>Por distribuidor: ${PATS.formatMoney(item.comision_por_distribucion || 0)}</div>
            <div>Por PATS activos: ${PATS.formatMoney(item.comision_por_pats_activos || 0)}</div>
          </div>

          <div class="pats-accordion-card__actions">
            <span class="pats-accordion-card__hint">Entrar al detalle del distribuidor.</span>
            <button type="button" class="pats-mini-btn pats-mini-btn--go">Ver detalle →</button>
          </div>
        </div>
      `;

      const btnChevron = card.querySelector(".pats-accordion-card__chevron-btn");
      const btnGo = card.querySelector(".pats-mini-btn--go");

      if (btnChevron) {
        btnChevron.addEventListener("click", (ev) => {
          ev.preventDefault();
          ev.stopPropagation();

          const isOpen = card.classList.contains("is-open");

          PATS.$$("#patsDistribuidorList .pats-accordion-card").forEach((x) => {
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
          goDistribuidor(item);
        });
      }

      card.addEventListener("dblclick", () => goDistribuidor(item));
      wrap.appendChild(card);
    });
  }

  function renderRankings(rawRankings, rawDistribuidores = []) {
    const rankings = normalizeRankings(rawRankings, rawDistribuidores.map(normalizeDistribuidorItem));
    const wrapA = PATS.$("#patsFranqRankingActivos");
    const wrapV = PATS.$("#patsFranqRankingVencidos");

    if (wrapA) {
      const items = rankings.activos || [];
      wrapA.innerHTML = items.length
        ? items.map(item => `
            <div class="pats-ranking-item">
              <span>${PATS.escapeHtml(item.nombre || "-")}</span>
              <strong>${Number(item.pats_activos || 0)} · ${PATS.formatMoney(item.comision_por_pats_activos || 0)}</strong>
            </div>
          `).join("")
        : `<div class="pats-empty-inline">Sin datos.</div>`;
    }

    if (wrapV) {
      const items = rankings.vencidos || [];
      wrapV.innerHTML = items.length
        ? items.map(item => `
            <div class="pats-ranking-item">
              <span>${PATS.escapeHtml(item.nombre || "-")}</span>
              <strong>${Number(item.pats_vencidos || 0)} · ${PATS.formatMoney(item.comision_perdida_vencidos || 0)}</strong>
            </div>
          `).join("")
        : `<div class="pats-empty-inline">Sin datos.</div>`;
    }
  }

  function renderCharts(rawCharts, rawRankings, rawDistribuidores, rawKpis) {
    const distribuidores = Array.isArray(rawDistribuidores) ? rawDistribuidores.map(normalizeDistribuidorItem) : [];
    const rankings = normalizeRankings(rawRankings, distribuidores);
    const kpis = normalizeKpis(rawKpis);
    const charts = normalizeCharts(rawCharts, distribuidores, rankings, kpis);

    const c1 = document.getElementById("patsFranquiciaChart1");
    const c2 = document.getElementById("patsFranquiciaChart2");
    const e1 = PATS.$("#patsFranquiciaChart1Empty");
    const e2 = PATS.$("#patsFranquiciaChart2Empty");

    PATS.destroyChart("franq1");
    PATS.destroyChart("franq2");

    const labelsReal = Array.isArray(charts?.real?.labels) ? charts.real.labels : [];
    const valuesReal = Array.isArray(charts?.real?.values) ? charts.real.values.map(v => Number(v || 0)) : [];

    const activos = Array.isArray(rankings?.activos) ? rankings.activos.slice(0, 8) : [];
    const vencidos = Array.isArray(rankings?.vencidos) ? rankings.vencidos.slice(0, 8) : [];

    const labelsRanking = [];
    const valuesActivos = [];
    const valuesVencidos = [];

    const mapa = new Map();

    activos.forEach((item) => {
      const nombre = String(item?.nombre || "-");
      if (!mapa.has(nombre)) {
        mapa.set(nombre, { activos: 0, vencidos: 0 });
      }
      mapa.get(nombre).activos = Number(item?.pats_activos || 0);
    });

    vencidos.forEach((item) => {
      const nombre = String(item?.nombre || "-");
      if (!mapa.has(nombre)) {
        mapa.set(nombre, { activos: 0, vencidos: 0 });
      }
      mapa.get(nombre).vencidos = Number(item?.pats_vencidos || 0);
    });

    Array.from(mapa.entries()).forEach(([nombre, vals]) => {
      labelsRanking.push(nombre);
      valuesActivos.push(Number(vals.activos || 0));
      valuesVencidos.push(Number(vals.vencidos || 0));
    });

    const has1 = valuesReal.some(v => v > 0);
    const has2 = valuesActivos.some(v => v > 0) || valuesVencidos.some(v => v > 0);

    if (c1 && has1 && window.PATSCharts) {
      c1.hidden = false;
      if (e1) e1.hidden = true;
      PATS.state.charts.franq1 = window.PATSCharts.buildBar(
        c1,
        labelsReal,
        valuesReal,
        "Ventas reales",
        true
      );
    } else {
      if (c1) c1.hidden = true;
      if (e1) e1.hidden = false;
    }

    if (c2 && has2 && window.Chart) {
      c2.hidden = false;
      if (e2) e2.hidden = true;

      const ctx2 = c2.getContext("2d");

      PATS.state.charts.franq2 = new Chart(ctx2, {
        type: "bar",
        data: {
          labels: labelsRanking,
          datasets: [
            {
              label: "Activos",
              data: valuesActivos,
              backgroundColor: "rgba(47,124,255,.88)",
              borderColor: "#2F7CFF",
              borderWidth: 1,
              borderRadius: 10,
              borderSkipped: false
            },
            {
              label: "Vencidos",
              data: valuesVencidos,
              backgroundColor: "rgba(246,190,79,.88)",
              borderColor: "#F6BE4F",
              borderWidth: 1,
              borderRadius: 10,
              borderSkipped: false
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: {
            mode: "index",
            intersect: false
          },
          plugins: {
            legend: {
              position: "bottom",
              labels: {
                boxWidth: 12,
                boxHeight: 12,
                usePointStyle: true,
                pointStyle: "circle",
                color: "#AFC3EC",
                font: {
                  size: 11,
                  weight: "700"
                },
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
                display: false
              },
              grid: {
                display: false,
                drawBorder: false
              },
              border: {
                display: false
              }
            },
            y: {
              beginAtZero: true,
              ticks: {
                color: "#8EA7D8",
                callback: (value) => Number(value || 0).toLocaleString("es-MX")
              },
              grid: {
                color: "rgba(123, 148, 210, .14)",
                drawBorder: false
              },
              border: {
                display: false
              }
            }
          }
        }
      });
    } else {
      if (c2) c2.hidden = true;
      if (e2) e2.hidden = false;
    }

    renderMensualChart(charts?.mensual_anual || {});
  }

  function renderMensualChart(chart) {
    const canvas = document.getElementById("patsFranquiciaChartMensual");
    const empty = PATS.$("#patsFranquiciaChartMensualEmpty");
    if (!canvas || !window.Chart) return;

    PATS.destroyChart("franqMensual");

    const labels = Array.isArray(chart.labels) ? chart.labels : [];
    const ventas = Array.isArray(chart.ventas_globales) ? chart.ventas_globales : [];
    const ganancia = Array.isArray(chart.ganancia_franquicia) ? chart.ganancia_franquicia : [];
    const distribuidores = Array.isArray(chart.distribuidores) ? chart.distribuidores : [];
    const pats = Array.isArray(chart.pats) ? chart.pats : [];

    const hasData = [...ventas, ...ganancia, ...distribuidores, ...pats]
      .some(v => Number(v) > 0);

    if (!hasData) {
      canvas.hidden = true;
      if (empty) empty.hidden = false;
      return;
    }

    canvas.hidden = false;
    if (empty) empty.hidden = true;

    const ctx = canvas.getContext("2d");

    PATS.state.charts.franqMensual = new Chart(ctx, {
      type: "line",
      data: {
        labels,
        datasets: [
          {
            label: "Ventas globales",
            data: ventas,
            _money: true,
            yAxisID: "yMoney",
            tension: 0.35,
            fill: false,
            borderColor: "#2F7CFF",
            backgroundColor: "#2F7CFF",
            pointBackgroundColor: "#2F7CFF",
            pointBorderColor: "#ffffff",
            pointRadius: 4,
            pointHoverRadius: 6,
            borderWidth: 3
          },
          {
            label: "Mi ganancia",
            data: ganancia,
            _money: true,
            yAxisID: "yMoney",
            tension: 0.35,
            fill: false,
            borderColor: "#7A5CFF",
            backgroundColor: "#7A5CFF",
            pointBackgroundColor: "#7A5CFF",
            pointBorderColor: "#ffffff",
            pointRadius: 4,
            pointHoverRadius: 6,
            borderWidth: 3
          },
          {
            label: "Distribuidores",
            data: distribuidores,
            _money: false,
            yAxisID: "yCount",
            tension: 0.35,
            fill: false,
            borderColor: "#20D6FF",
            backgroundColor: "#20D6FF",
            pointBackgroundColor: "#20D6FF",
            pointBorderColor: "#ffffff",
            pointRadius: 4,
            pointHoverRadius: 6,
            borderWidth: 2
          },
          {
            label: "PATS",
            data: pats,
            _money: false,
            yAxisID: "yCount",
            tension: 0.35,
            fill: false,
            borderColor: "#F6BE4F",
            backgroundColor: "#F6BE4F",
            pointBackgroundColor: "#F6BE4F",
            pointBorderColor: "#ffffff",
            pointRadius: 4,
            pointHoverRadius: 6,
            borderWidth: 2
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
          mode: "index",
          intersect: false
        },
        plugins: {
          legend: {
            position: "bottom",
            labels: {
              boxWidth: 12,
              boxHeight: 12,
              usePointStyle: true,
              pointStyle: "circle",
              color: "#AFC3EC",
              font: {
                size: 11,
                weight: "700"
              },
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
              font: {
                size: 11,
                weight: "700"
              }
            },
            grid: {
              display: false,
              drawBorder: false
            },
            border: {
              display: false
            }
          },
          yMoney: {
            type: "linear",
            position: "left",
            beginAtZero: true,
            ticks: {
              color: "#8EA7D8",
              callback: (value) => new Intl.NumberFormat("es-MX", {
                style: "currency",
                currency: "MXN",
                maximumFractionDigits: 0
              }).format(Number(value || 0))
            },
            grid: {
              color: "rgba(123, 148, 210, .14)",
              drawBorder: false
            },
            border: {
              display: false
            }
          },
          yCount: {
            type: "linear",
            position: "right",
            beginAtZero: true,
            ticks: {
              color: "#8EA7D8",
              callback: (value) => Number(value || 0).toLocaleString("es-MX")
            },
            grid: {
              drawOnChartArea: false,
              drawBorder: false
            },
            border: {
              display: false
            }
          }
        }
      }
    });
  }

  async function resolveFranquiciaIdByRegion() {
    try {
      const data = await PATS.getJSON("listar_franquicias.php");
      const items = Array.isArray(data.items) ? data.items : [];
      if (!items.length) return 0;
      return Number(items[0].id_franquicia || 0);
    } catch (err) {
      console.error("[PATS FRANQUICIA] resolveFranquiciaIdByRegion error:", err);
      return 0;
    }
  }

  function getPublicCheckoutToken(franquicia) {
    return String(
      pick(franquicia, [
        "public_checkout_token",
        "checkout_token",
        "token_publico",
        "token_checkout_publico"
      ], "")
    ).trim();
  }

  function isPublicCheckoutActivo(franquicia) {
    const activo = pick(franquicia, [
      "public_checkout_activo",
      "checkout_activo",
      "token_activo"
    ], 1);

    return Number(activo) === 1;
  }

  function getPatsPublicBasePath() {
    const path = window.location.pathname || "";

    const idx = path.toLowerCase().indexOf("/ez/pats/");
    if (idx >= 0) {
      return `${window.location.origin}${path.slice(0, idx)}/ez/pats`;
    }

    return `${window.location.origin}/EZHS/EZHS/ez/pats`;
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
    try {
      await navigator.clipboard.writeText(link);
      PATS.toast(okMsg || "Link copiado correctamente");
    } catch (e) {
      console.error(e);

      /*
        Fallback por si el navegador bloquea navigator.clipboard
        en HTTP, iframe, permisos o contexto no seguro.
      */
      try {
        const tmp = document.createElement("textarea");
        tmp.value = link;
        tmp.setAttribute("readonly", "readonly");
        tmp.style.position = "fixed";
        tmp.style.left = "-9999px";
        document.body.appendChild(tmp);
        tmp.select();
        document.execCommand("copy");
        tmp.remove();

        PATS.toast(okMsg || "Link copiado correctamente");
      } catch (err) {
        console.error(err);
        PATS.toast("No fue posible copiar el link");
      }
    }
  }

  function getPublicCheckoutToken(franquicia) {
    return String(
      pick(franquicia, [
        "public_checkout_token",
        "checkout_token",
        "token_publico",
        "token_checkout_publico"
      ], "")
    ).trim();
  }

  function isPublicCheckoutActivo(franquicia) {
    const activo = pick(franquicia, [
      "public_checkout_activo",
      "checkout_activo",
      "token_activo"
    ], 1);

    return Number(activo) === 1;
  }

  function getPatsPublicBasePath() {
    const path = window.location.pathname || "";

    const idx = path.toLowerCase().indexOf("/ez/pats/");
    if (idx >= 0) {
      return `${window.location.origin}${path.slice(0, idx)}/ez/pats`;
    }

    return `${window.location.origin}/EZHS/EZHS/ez/pats`;
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
    try {
      await navigator.clipboard.writeText(link);
      PATS.toast(okMsg || "Link copiado correctamente");
    } catch (e) {
      console.error(e);

      /*
        Fallback por si el navegador bloquea navigator.clipboard
        en HTTP, iframe, permisos o contexto no seguro.
      */
      try {
        const tmp = document.createElement("textarea");
        tmp.value = link;
        tmp.setAttribute("readonly", "readonly");
        tmp.style.position = "fixed";
        tmp.style.left = "-9999px";
        document.body.appendChild(tmp);
        tmp.select();
        document.execCommand("copy");
        tmp.remove();

        PATS.toast(okMsg || "Link copiado correctamente");
      } catch (err) {
        console.error(err);
        PATS.toast("No fue posible copiar el link");
      }
    }
  }

  function bindCopiarLinkPublicoFranquicia(franquicia) {
    const btnPats = PATS.$("#btnCopiarLinkPublicoPats");
    const btnDistribucion = PATS.$("#btnCopiarLinkDistribucionFranquicia");

    const token = getPublicCheckoutToken(franquicia);
    const activo = isPublicCheckoutActivo(franquicia);

    const puedeUsarLink = !!token && activo;

    setLinkButtonState(
      btnPats,
      puedeUsarLink,
      puedeUsarLink ? "Copiar link público para venta de PATS" : "Aún no tienes link público disponible"
    );

    setLinkButtonState(
      btnDistribucion,
      puedeUsarLink,
      puedeUsarLink ? "Copiar link público para alta de distribución" : "Aún no tienes link público disponible"
    );

    if (!puedeUsarLink) {
      if (btnPats) {
        btnPats.onclick = () => {
          PATS.toast("Aún no tienes link público disponible.");
        };
      }

      if (btnDistribucion) {
        btnDistribucion.onclick = () => {
          PATS.toast("Aún no tienes link público disponible.");
        };
      }

      return;
    }

 /*
  Links públicos con token de franquicia.
  Ambos deben ir a las landings públicas de pasaporteatusalud.com,
  no directo al checkout interno.
*/
const linkPats = `https://pasaporteatusalud.com/landing_pats.php?t=${encodeURIComponent(token)}`;

const linkDistribucion = `https://50d.com.mx/50D/EZHS/ez/pats/pago_distribucion.php?t=${encodeURIComponent(token)}`;

    if (btnPats) {
      btnPats.onclick = async (ev) => {
        ev.preventDefault();
        await copiarLinkPublico(linkPats, "Link PATS copiado correctamente");
      };
    }

    if (btnDistribucion) {
      btnDistribucion.onclick = async (ev) => {
        ev.preventDefault();
        await copiarLinkPublico(linkDistribucion, "Link de distribución copiado correctamente");
      };
    }
  }

  async function loadFranquiciaDashboard() {
    try {
      if (!PATS.state.filters.id_franquicia) {
        PATS.state.filters.id_franquicia = await resolveFranquiciaIdByRegion();
      }

      if (!PATS.state.filters.id_franquicia) {
        renderKPIs({});
        renderDistribuidores([]);
        renderCharts({}, {}, [], {});
        renderRankings({}, []);
        return;
      }

      const data = await PATS.getJSON("dashboard_franquicia.php");
      const franquicia = getFranquiciaNode(data);
      const distribuidores = getDistribuidoresList(data);
      const rankings = getRankingsNode(data);
      const kpis = isObj(data?.kpis) ? data.kpis : {};
      const charts = isObj(data?.charts) ? data.charts : {};

      bindCopiarLinkPublicoFranquicia(franquicia);
      renderKPIs(kpis);
      renderDistribuidores(distribuidores);
      renderCharts(charts, rankings, distribuidores, kpis);
      renderRankings(rankings, distribuidores);
    } catch (e) {
      console.error("[PATS FRANQUICIA] loadFranquiciaDashboard error:", e);
      renderKPIs({});
      renderDistribuidores([]);
      renderCharts({}, {}, [], {});
      renderRankings({}, []);
      PATS.toast(e.message || "No fue posible cargar la franquicia");
    }
  }

  document.addEventListener("DOMContentLoaded", async () => {
    const btnBack = PATS.$("#btnPatsBackAdmin");
    if (btnBack) {
      btnBack.addEventListener("click", () => {
        const qs = new URLSearchParams();
        if (PATS.state.filters.region) qs.set("region", String(PATS.state.filters.region));
        if (PATS.state.filters.zona) qs.set("zona", String(PATS.state.filters.zona));
        if (PATS.state.filters.anio) qs.set("anio", String(PATS.state.filters.anio));
        if (PATS.state.filters.mes) qs.set("mes", String(PATS.state.filters.mes));
        window.location.href = `mis_franquicias.php${qs.toString() ? `?${qs.toString()}` : ""}`;
      });
    }

    const regionQ = getQueryValue("region");
    const zonaQ = getQueryValue("zona");
    const anioQ = getQueryValue("anio");
    const mesQ = getQueryValue("mes");
    const idFranqQ = getQueryInt("id_franquicia");

    if (regionQ) PATS.state.filters.region = regionQ;
    if (zonaQ) PATS.state.filters.zona = zonaQ;
    if (anioQ) PATS.state.filters.anio = anioQ;
    if (mesQ) PATS.state.filters.mes = mesQ;
    if (idFranqQ) PATS.state.filters.id_franquicia = idFranqQ;

    await PATS.loadScopeOptions();
    PATS.bindCommonFilters(loadFranquiciaDashboard);

    loadFranquiciaDashboard();
  });
})();