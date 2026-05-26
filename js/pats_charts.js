/*
ez/pats/js/pats_charts.js
*/
(() => {
  "use strict";

  if (!window.Chart) return;

  const PATSCharts = window.PATSCharts = window.PATSCharts || {};

  const PATS_COLOR_SYSTEM = {
    admin: "#124eff",
    hospital: "#01eeff",
    franquiciatario: "#ffb993",
    distribuidor: "#96ffb9",
    franquicia: "#7A5CFF",
    pats: "#7A5CFF",
    extra: "#28E0C0",
    activo: "#6A7CFF",
    vencido: "#FF6B93",
    nominal: "#2F7CFF",
    real: "#7A5CFF",
    mensual: "#20D6FF",
    anual: "#2F7CFF",
    ingreso: "#2F7CFF",
    frecuencia: "#7A5CFF",
    default: "#4E74FF"
  };

  function hexToRgba(hex, alpha = 1) {
    const clean = String(hex || "").replace("#", "").trim();
    const normalized = clean.length === 3
      ? clean.split("").map(ch => ch + ch).join("")
      : clean;

    const int = parseInt(normalized || "4E74FF", 16);
    const r = (int >> 16) & 255;
    const g = (int >> 8) & 255;
    const b = int & 255;
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
  }

  function gradientFromHex(ctx, hex, alphaTop = .96, alphaBottom = .72) {
    const g = ctx.createLinearGradient(0, 0, 0, 300);
    g.addColorStop(0, hexToRgba(hex, alphaTop));
    g.addColorStop(1, hexToRgba(hex, alphaBottom));
    return g;
  }

  function normalizeSemanticKey(label = "") {
    const txt = String(label || "").toLowerCase().trim();

    if (txt.includes("admin")) return "admin";
    if (txt.includes("hospital")) return "hospital";
    if (txt.includes("franquiciatario")) return "franquiciatario";
    if (txt.includes("distribuidor")) return "distribuidor";
    if (txt.includes("franquicia")) return "franquicia";
    if (txt.includes("pats")) return "pats";
    if (txt.includes("extra")) return "extra";
    if (txt.includes("activo")) return "activo";
    if (txt.includes("vencido")) return "vencido";
    if (txt.includes("nominal")) return "nominal";
    if (txt.includes("real")) return "real";
    if (txt.includes("mensual")) return "mensual";
    if (txt.includes("anual")) return "anual";
    if (txt.includes("ingreso")) return "ingreso";
    if (txt.includes("frecuencia")) return "frecuencia";

    return "default";
  }

  function getSemanticColor(label = "") {
    const key = normalizeSemanticKey(label);
    return PATS_COLOR_SYSTEM[key] || PATS_COLOR_SYSTEM.default;
  }

  function getSemanticGradient(ctx, label = "") {
    return gradientFromHex(ctx, getSemanticColor(label));
  }

  function money(v) {
    return new Intl.NumberFormat("es-MX", {
      style: "currency",
      currency: "MXN",
      maximumFractionDigits: 0
    }).format(Number(v || 0));
  }

  function numberFmt(v) {
    const n = Number(v || 0);
    if (n >= 1000) return n.toLocaleString("es-MX");
    return String(n);
  }

  function isMoneyCanvasId(canvasId = "") {
    const ids = new Set([
      // ADMIN
      "patsAdminChartDistribucion",
      "patsAdminChartNominalRealExtra",
      "patsAdminChartMensualAnual",

      // MIS FRANQUICIAS
      "patsMisFranqChart1",

      // FRANQUICIA
      "patsFranquiciaChart1",
      "patsFranquiciaChart2",
      "patsFranquiciaChartMensual",

      // DISTRIBUIDOR
      "patsDistribuidorChart1"
    ]);

    return ids.has(String(canvasId || ""));
  }

  function resolveMoneyFlag(canvas, explicitFlag) {
    if (typeof explicitFlag === "boolean") return explicitFlag;
    return isMoneyCanvasId(canvas?.id || "");
  }

function shouldHideXAxisLabels(canvas) {
  const hiddenIds = new Set([
    "patsAdminChartNominalRealExtra",
    "patsAdminChartFrecuencia",
    "patsMisFranqChart1",
    "patsFranquiciaChart1",
    "patsFranquiciaChart2",
    "patsDistribuidorChart1"
  ]);

  return hiddenIds.has(String(canvas?.id || ""));
}

  function getTooltipEl(canvas) {
    const key = canvas.id || `chart-${Math.random().toString(36).slice(2)}`;
    let el = document.querySelector(`.pats-chart-tooltip[data-for="${key}"]`);
    if (!el) {
      el = document.createElement("div");
      el.className = "pats-chart-tooltip";
      el.dataset.for = key;
      document.body.appendChild(el);
    }
    return el;
  }

  function externalTooltipHandler(context) {
    const { chart, tooltip } = context;
    const canvas = chart.canvas;
    const tooltipEl = getTooltipEl(canvas);

    if (tooltip.opacity === 0) {
      tooltipEl.style.opacity = "0";
      tooltipEl.style.transform = "translateY(8px)";
      return;
    }

    let html = `<div class="pats-chart-tooltip__inner">`;

    (tooltip.title || []).forEach((title) => {
      html += `<div class="pats-chart-tooltip__title">${title}</div>`;
    });

    if (Array.isArray(tooltip.dataPoints) && tooltip.dataPoints.length) {
      tooltip.dataPoints.forEach((dp, i) => {
        const ds = dp.dataset || {};
        const colors = tooltip.labelColors?.[i] || {};
        const rawValue = Number(dp.raw ?? 0);

        const isMoney =
          typeof ds._money === "boolean"
            ? ds._money
            : (ds.yAxisID === "yMoney");

      const pointLabel =
  dp.label ||
  chart?.data?.labels?.[dp.dataIndex] ||
  ds.label ||
  "";

const label = pointLabel;
const value = isMoney ? money(rawValue) : numberFmt(rawValue);

       html += `
  <div class="pats-chart-tooltip__row">
    <span class="pats-chart-tooltip__dot" style="background:${colors.backgroundColor || colors.borderColor || "#6EA8FF"}"></span>
    <span class="pats-chart-tooltip__text">${label ? `${label}: ${value}` : value}</span>
  </div>
`;
      });
    } else if (tooltip.body) {
      const bodyLines = tooltip.body.map((b) => b.lines);
      bodyLines.forEach((body, i) => {
        const colors = tooltip.labelColors?.[i] || {};
        const value = Array.isArray(body) ? body.join(" ") : body;
        html += `
          <div class="pats-chart-tooltip__row">
            <span class="pats-chart-tooltip__dot" style="background:${colors.backgroundColor || "#6EA8FF"}"></span>
            <span class="pats-chart-tooltip__text">${value}</span>
          </div>
        `;
      });
    }

    html += `</div>`;
    tooltipEl.innerHTML = html;

    const rect = canvas.getBoundingClientRect();
    tooltipEl.style.opacity = "1";
    tooltipEl.style.transform = "translate(-50%, calc(-100% - 12px))";

    const pageX = rect.left + window.pageXOffset + tooltip.caretX;
    const pageY = rect.top + window.pageYOffset + tooltip.caretY;

    tooltipEl.style.left = pageX + "px";
    tooltipEl.style.top = pageY + "px";

    const tipRect = tooltipEl.getBoundingClientRect();
    const margin = 12;

    let finalLeft = pageX;
    let finalTop = pageY;
    let transformX = -50;
    let placeBelow = false;

    if (tipRect.left < margin) {
      finalLeft = window.pageXOffset + margin;
      transformX = 0;
    }

    if (tipRect.right > window.pageXOffset + window.innerWidth - margin) {
      finalLeft = window.pageXOffset + window.innerWidth - margin;
      transformX = -100;
    }

    if (tipRect.top < margin) {
      placeBelow = true;
      finalTop = pageY + 16;
    }

    tooltipEl.style.left = finalLeft + "px";
    tooltipEl.style.top = finalTop + "px";
    tooltipEl.style.transform = placeBelow
      ? `translate(${transformX}%, 0)`
      : `translate(${transformX}%, calc(-100% - 12px))`;
  }


  PATSCharts.externalTooltipHandler = externalTooltipHandler;



  function commonPlugins() {
    return {
      legend: {
        position: "bottom",
        labels: {
          boxWidth: 12,
          boxHeight: 12,
          usePointStyle: true,
          pointStyle: "circle",
          color: "#5F77A8",
          font: {
            size: 11,
            weight: "700"
          },
          padding: 16
        }
      },
      tooltip: {
        enabled: false,
        external: externalTooltipHandler
      }
    };
  }

  function commonScales(isMoney = false, canvas = null) {
    const hideXLabels = shouldHideXAxisLabels(canvas);

    return {
      x: {
        ticks: {
          display: !hideXLabels,
          color: "#6B82B0",
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
      y: {
        beginAtZero: true,
        ticks: {
          color: "#6B82B0",
          font: {
            size: 11,
            weight: "700"
          },
          callback: (value) => isMoney ? money(value) : numberFmt(value)
        },
        grid: {
          color: "rgba(84, 107, 154, .12)",
          drawBorder: false
        },
        border: {
          display: false
        }
      }
    };
  }

  function gradientBlue(ctx) {
    return getSemanticGradient(ctx, "admin");
  }

  function gradientCyan(ctx) {
    return getSemanticGradient(ctx, "distribuidor");
  }

  function gradientPink(ctx) {
    return getSemanticGradient(ctx, "hospital");
  }

  function gradientYellow(ctx) {
    return getSemanticGradient(ctx, "franquiciatario");
  }

  PATSCharts.buildBar = (canvas, labels, values, label = "Valores", explicitMoney = undefined) => {
    const ctx = canvas.getContext("2d");
    const isMoney = resolveMoneyFlag(canvas, explicitMoney);
    const colorLabel = label || (Array.isArray(labels) && labels.length === 1 ? labels[0] : "default");

    return new Chart(ctx, {
      type: "bar",
      data: {
        labels,
        datasets: [{
          label,
          data: values,
          _money: isMoney,
          backgroundColor: getSemanticGradient(ctx, colorLabel),
          borderRadius: 16,
          borderSkipped: false,
          maxBarThickness: 52
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: {
          duration: 500,
          easing: "easeOutQuart"
        },
        interaction: {
          mode: "index",
          intersect: false
        },
        layout: {
          padding: {
            top: 10,
            right: 8,
            bottom: 0,
            left: 4
          }
        },
        plugins: commonPlugins(),
               scales: commonScales(isMoney, canvas)
      }
    });
  };

  PATSCharts.buildDoughnut = (canvas, labels, values, explicitMoney = undefined) => {
    const ctx = canvas.getContext("2d");
    const isMoney = resolveMoneyFlag(canvas, explicitMoney);
    const colors = (labels || []).map(label => getSemanticGradient(ctx, label));

    return new Chart(ctx, {
      type: "doughnut",
      data: {
        labels,
        datasets: [{
          data: values,
          _money: isMoney,
          backgroundColor: colors.length ? colors : [
            getSemanticGradient(ctx, "admin"),
            getSemanticGradient(ctx, "hospital"),
            getSemanticGradient(ctx, "franquiciatario"),
            getSemanticGradient(ctx, "distribuidor")
          ],
          borderWidth: 0,
          hoverOffset: 10,
          spacing: 2
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: "54%",
        animation: {
          duration: 500,
          easing: "easeOutQuart"
        },
        interaction: {
          mode: "nearest",
          intersect: false
        },
        layout: {
          padding: {
            top: 8,
            right: 8,
            bottom: 0,
            left: 8
          }
        },
        plugins: commonPlugins()
      }
    });
  };


  PATSCharts.buildLineDualAxis = (canvas, payload) => {
    const ctx = canvas.getContext("2d");

    return new Chart(ctx, {
      type: "line",
      data: {
        labels: payload.labels || [],
        datasets: [
          {
            label: "Ingreso total",
            data: payload.ingreso || [],
            _money: true,
            yAxisID: "yMoney",
            tension: 0.35,
            fill: false,
            borderColor: PATS_COLOR_SYSTEM.ingreso,
            backgroundColor: PATS_COLOR_SYSTEM.ingreso,
            pointBackgroundColor: PATS_COLOR_SYSTEM.ingreso,
            pointBorderColor: "#ffffff",
            pointRadius: 4,
            pointHoverRadius: 6,
            borderWidth: 3
          },
          {
            label: "Franquicias",
            data: payload.franquicias || [],
            _money: false,
            yAxisID: "yCount",
            tension: 0.35,
            fill: false,
            borderColor: PATS_COLOR_SYSTEM.franquicia,
            backgroundColor: PATS_COLOR_SYSTEM.franquicia,
            pointBackgroundColor: PATS_COLOR_SYSTEM.franquicia,
            pointBorderColor: "#ffffff",
            pointRadius: 4,
            pointHoverRadius: 6,
            borderWidth: 2
          },
          {
            label: "Distribuidores",
            data: payload.distribuidores || [],
            _money: false,
            yAxisID: "yCount",
            tension: 0.35,
            fill: false,
            borderColor: PATS_COLOR_SYSTEM.distribuidor,
            backgroundColor: PATS_COLOR_SYSTEM.distribuidor,
            pointBackgroundColor: PATS_COLOR_SYSTEM.distribuidor,
            pointBorderColor: "#ffffff",
            pointRadius: 4,
            pointHoverRadius: 6,
            borderWidth: 2
          },
          {
            label: "PATS",
            data: payload.pats || [],
            _money: false,
            yAxisID: "yCount",
            tension: 0.35,
            fill: false,
            borderColor: PATS_COLOR_SYSTEM.pats,
            backgroundColor: PATS_COLOR_SYSTEM.pats,
            pointBackgroundColor: PATS_COLOR_SYSTEM.pats,
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
        animation: {
          duration: 500,
          easing: "easeOutQuart"
        },
        interaction: {
          mode: "index",
          intersect: false
        },
        plugins: commonPlugins(),
        scales: {
          x: {
            ticks: {
              color: "#6B82B0",
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
              color: "#6B82B0",
              callback: (value) => money(value)
            },
            grid: {
              color: "rgba(84, 107, 154, .12)",
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
              color: "#6B82B0",
              callback: (value) => numberFmt(value)
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
  };
})();