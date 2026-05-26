/*
ez/pats/js/pats_core.js
*/
(() => {
  "use strict";

  const PATS = window.PATS = window.PATS || {};
  const CTX = window.PATS_CONTEXT || {};

  const path = window.location.pathname || "";
  const m = path.match(/^(.*?\/ez)\//i);
  const baseUrl = m ? m[1] : "/ez";

  PATS.ctx = {
    ...CTX,
    baseUrl,
    endpointsUrl: `${baseUrl}/pats/endpoints`
  };

  const defaultUnidad =
    PATS.ctx.view === 'distribuidor'
      ? (PATS.ctx.unidad || '')
      : '';

  PATS.state = {
    filters: {
      pais: "",
      region: PATS.ctx.region || "",
      zona: "",
      unidad: defaultUnidad,
      anio: new Date().getFullYear(),
      mes: "",
      id_franquicia: 0,
      id_distribuidor: 0,
      q: ""
    },
    charts: {},
    scopeOptions: {
      paises: [],
      regiones: [],
      zonas: [],
      unidades: []
    }
  };

  PATS.$ = (selector, root = document) => root.querySelector(selector);
  PATS.$$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

  PATS.formatMoney = (value) => {
    const num = Number(value || 0);
    return new Intl.NumberFormat("es-MX", {
      style: "currency",
      currency: "MXN",
      maximumFractionDigits: 0
    }).format(num);
  };

  PATS.escapeHtml = (str) => String(str ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');

  PATS.debounce = (fn, wait = 250) => {
    let t;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...args), wait);
    };
  };

  PATS.buildQuery = (extra = {}) => {
    const payload = { ...PATS.state.filters, ...extra };
    const qs = new URLSearchParams();
    Object.entries(payload).forEach(([k, v]) => {
      if (v !== "" && v !== null && v !== undefined) qs.set(k, String(v));
    });
    return qs.toString();
  };

PATS.getJSON = async (endpoint, extra = {}) => {
  const qs = PATS.buildQuery(extra);
  const url = `${PATS.ctx.endpointsUrl}/${endpoint}${qs ? `?${qs}` : ""}`;

  const res = await fetch(url, { credentials: "same-origin" });
  const raw = await res.text();

  try {
    const json = JSON.parse(raw);
    if (!res.ok || json?.ok === false) {
      throw new Error(json?.error || `Error en ${endpoint}`);
    }
    return json;
  } catch (e) {
    console.error('[PATS RAW RESPONSE]', url, raw);
    throw new Error(`Respuesta no JSON en ${endpoint}`);
  }
};
  PATS.fillSelect = (selector, items, placeholder = 'Selecciona...') => {
    const el = PATS.$(selector);
    if (!el) return;

    const map = {
      '#patsPais': 'pais',
      '#patsRegion': 'region',
      '#patsZona': 'zona'
    };

    const currentKey = map[selector] || '';
    const current = currentKey ? (PATS.state.filters[currentKey] || '') : '';

    el.innerHTML = `<option value="">${placeholder}</option>`;
    (items || []).forEach((item) => {
      const opt = document.createElement('option');
      opt.value = item.value;
      opt.textContent = item.label;
      if (String(current) === String(item.value)) opt.selected = true;
      el.appendChild(opt);
    });
  };

  PATS.setFilterInputs = () => {
    const map = {
      '#patsPais': 'pais',
      '#patsRegion': 'region',
      '#patsZona': 'zona',
      '#patsAnio': 'anio',
      '#patsMes': 'mes'
    };

    Object.entries(map).forEach(([sel, key]) => {
      const el = PATS.$(sel);
      if (!el) return;
      if (key in PATS.state.filters) {
        el.value = PATS.state.filters[key] ?? '';
      }
    });
  };


  PATS.syncPaisVisibility = () => {
    const paisSelect = PATS.$('#patsPais');
    if (!paisSelect) return;

    const paisField = paisSelect.closest('.pats-field');
    if (!paisField) return;

    const paises = PATS.state.scopeOptions?.paises || [];

    // Si solo hay 1 país (o ninguno), no se muestra el select
    if (paises.length <= 1) {
      paisField.style.display = 'none';

      // Si existe exactamente uno, se toma en automático
      if (paises.length === 1) {
        PATS.state.filters.pais = String(paises[0].value || '');
      } else {
        PATS.state.filters.pais = '';
      }
    } else {
      paisField.style.display = '';
    }
  };


  PATS.bindCommonFilters = (onChange) => {
    const bindings = [
      ['#patsPais', 'pais'],
      ['#patsRegion', 'region'],
      ['#patsZona', 'zona'],
      ['#patsAnio', 'anio'],
      ['#patsMes', 'mes']
    ];

    bindings.forEach(([sel, key]) => {
      const el = PATS.$(sel);
      if (!el) return;

      el.addEventListener('change', async () => {
        PATS.state.filters[key] = el.value;

        if (key === 'pais') {
          PATS.state.filters.region = '';
          PATS.state.filters.zona = '';
          await PATS.loadScopeOptions();
        } else if (key === 'region') {
          PATS.state.filters.zona = '';
          await PATS.loadScopeOptions();
        }

        onChange?.();
      });
    });
  };
  PATS.loadScopeOptions = async () => {
    try {
      const data = await PATS.getJSON('filtros_scope.php');
      PATS.state.scopeOptions = data.options || {};

      // Si solo hay un país, tomarlo automáticamente antes de pintar selects
      const paises = PATS.state.scopeOptions.paises || [];
      if (paises.length === 1) {
        PATS.state.filters.pais = String(paises[0].value || '');
      }

      PATS.fillSelect('#patsPais', PATS.state.scopeOptions.paises || [], 'Selecciona...');
      PATS.fillSelect('#patsRegion', PATS.state.scopeOptions.regiones || [], 'Selecciona...');
      PATS.fillSelect('#patsZona', PATS.state.scopeOptions.zonas || [], 'Selecciona...');

      PATS.syncPaisVisibility();
      PATS.setFilterInputs();
    } catch (err) {
      console.error('[PATS CORE] loadScopeOptions error:', err);
    }
  };

  PATS.destroyChart = (key) => {
    if (PATS.state.charts[key]) {
      PATS.state.charts[key].destroy();
      delete PATS.state.charts[key];
    }
  };

  PATS.goToAdmin = () => {
    const qs = new URLSearchParams();
    if (PATS.state.filters.region) qs.set('region', PATS.state.filters.region);
    if (PATS.state.filters.zona) qs.set('zona', PATS.state.filters.zona);
    if (PATS.state.filters.anio) qs.set('anio', PATS.state.filters.anio);
    if (PATS.state.filters.mes) qs.set('mes', PATS.state.filters.mes);
    window.location.href = `admin.php${qs.toString() ? `?${qs.toString()}` : ''}`;
  };

  PATS.goToFranquicia = (idFranquicia = 0, region = '') => {
    const qs = new URLSearchParams();
    if (idFranquicia) qs.set('id_franquicia', String(idFranquicia));
    if (region || PATS.state.filters.region) qs.set('region', region || PATS.state.filters.region);
    if (PATS.state.filters.zona) qs.set('zona', PATS.state.filters.zona);
    if (PATS.state.filters.anio) qs.set('anio', PATS.state.filters.anio);
    if (PATS.state.filters.mes) qs.set('mes', PATS.state.filters.mes);
    window.location.href = `franquicia.php?${qs.toString()}`;
  };

  PATS.goToDistribuidor = (idDistribuidor = 0, idFranquicia = 0) => {
    const qs = new URLSearchParams();
    if (idDistribuidor) qs.set('id_distribuidor', String(idDistribuidor));
    if (idFranquicia || PATS.state.filters.id_franquicia) qs.set('id_franquicia', String(idFranquicia || PATS.state.filters.id_franquicia));
    if (PATS.state.filters.region) qs.set('region', PATS.state.filters.region);
    if (PATS.state.filters.zona) qs.set('zona', PATS.state.filters.zona);
    if (PATS.state.filters.anio) qs.set('anio', PATS.state.filters.anio);
    if (PATS.state.filters.mes) qs.set('mes', PATS.state.filters.mes);
    window.location.href = `distribuidor.php?${qs.toString()}`;
  };

  PATS.toast = (msg) => {
    console.log('[PATS]', msg);
  };


function resolvePatsSemanticTone(label = "") {
  const txt = String(label || "").toLowerCase().trim();

  if (txt.includes("hospital")) return "hospital";
  if (txt.includes("distribuidor")) return "distribuidor";
  if (txt.includes("franquiciatario")) return "franquiciatario";
  if (txt.includes("franquicia")) return "franquicia";

  if (txt.includes("extra") || txt.includes("recargo")) return "extra";
  if (txt.includes("vencido")) return "vencido";
  if (txt.includes("activo")) return "activo";

  if (txt.includes("nominal")) return "nominal";
  if (txt.includes("real")) return "real";
  if (txt.includes("anual")) return "anual";
  if (txt.includes("mensual")) return "mensual";
  if (txt.includes("frecuencia")) return "frecuencia";
  if (txt.includes("ingreso")) return "ingreso";
  if (txt.includes("admin")) return "admin";
  if (txt.includes("pats")) return "pats";

  return "default";
}

window.PATS = window.PATS || {};
window.PATS.resolveSemanticTone = resolvePatsSemanticTone;

window.PATS.paintKpiSemanticTones = function paintKpiSemanticTones(scopeSelector = ".pats-kpi-card") {
  document.querySelectorAll(scopeSelector).forEach((card) => {
    const labelEl = card.querySelector(".k");
    const label = labelEl ? labelEl.textContent : "";
    const tone = resolvePatsSemanticTone(label);
    card.setAttribute("data-kpi-tone", tone);
  });
};


})();