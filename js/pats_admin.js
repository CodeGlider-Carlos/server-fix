/*
ez/pats/js/pats_admin.js
*/
(() => {
  "use strict";

  const PATS = window.PATS;
  if (!PATS || !PATS.ctx || PATS.ctx.view !== 'admin') return;

  function getQueryValue(name) {
    const qs = new URLSearchParams(window.location.search);
    return qs.get(name) || '';
  }

  function setText(selector, value) {
    const el = PATS.$(selector);
    if (el) el.textContent = value;
  }

  function n(v) {
    const x = Number(v || 0);
    return Number.isFinite(x) ? x : 0;
  }

  function arr(v) {
    return Array.isArray(v) ? v : [];
  }

  function money(v) {
    return PATS.formatMoney(n(v));
  }

  function syncAdminTopbarVisibility() {
    const paises = PATS.state.scopeOptions?.paises || [];
    const paisSelect = PATS.$('#patsPais');
    const regionSelect = PATS.$('#patsRegion');

    const paisField = paisSelect ? paisSelect.closest('.pats-field') : null;
    const regionField = regionSelect ? regionSelect.closest('.pats-field') : null;

    if (paises.length <= 1) {
      if (paisField) paisField.style.display = 'none';
      if (regionField) regionField.style.display = 'none';
      PATS.state.filters.region = '';
      if (regionSelect) regionSelect.value = '';
    } else {
      if (paisField) paisField.style.display = '';
      if (regionField) regionField.style.display = '';
    }
  }

  function goMisFranquicias(region = '') {
    const qs = new URLSearchParams();
    if (region) qs.set('region', region);
    if (PATS.state.filters.zona) qs.set('zona', PATS.state.filters.zona);
    if (PATS.state.filters.anio) qs.set('anio', PATS.state.filters.anio);
    if (PATS.state.filters.mes) qs.set('mes', PATS.state.filters.mes);

    window.location.href = `mis_franquicias.php${qs.toString() ? `?${qs.toString()}` : ''}`;
  }

  function goGestorView() {
    const qs = new URLSearchParams();
    if (PATS.state.filters.region) qs.set('region', PATS.state.filters.region);
    if (PATS.state.filters.zona) qs.set('zona', PATS.state.filters.zona);
    if (PATS.state.filters.anio) qs.set('anio', PATS.state.filters.anio);
    if (PATS.state.filters.mes) qs.set('mes', PATS.state.filters.mes);
    window.location.href = `gestor.php${qs.toString() ? `?${qs.toString()}` : ''}`;
  }

  function renderRegions(items) {
    const wrap = PATS.$('#patsRegionList');
    if (!wrap) return;

    wrap.innerHTML = '';

    if (!Array.isArray(items) || !items.length) {
      wrap.innerHTML = `<div class="pats-empty-state">Sin regiones disponibles.</div>`;
      return;
    }

    items.forEach((item) => {
      const region = String(item.region || '').trim();

      const card = document.createElement('button');
      card.type = 'button';
      card.className = 'pats-region-card is-live';
      card.innerHTML = `
        <span class="pats-region-card__glow"></span>
        <span class="pats-region-card__title">${PATS.escapeHtml(region)}</span>
        <span class="pats-region-card__meta">
          ${n(item.total_franquicias)} franquicias · ${n(item.total_distribuidores)} distribuidores · ${n(item.total_pats_activos)} PATS activos
        </span>
        <span class="pats-region-card__cta">Ver franquicias →</span>
      `;
      card.addEventListener('click', () => goMisFranquicias(region));
      wrap.appendChild(card);
    });
  }

  function renderSummary(kpis, hospitales) {
    setText('#patsGlobalTotal', money(kpis.ventas_globales));
    setText('#patsGlobalFranq', money(kpis.monto_franquicias));
    setText('#patsGlobalDist', money(kpis.monto_distribuciones));
    setText('#patsGlobalPats', money(kpis.monto_pats_real));

    setText('#patsGlobalFranqNote', `Nuevos ${n(kpis.nuevas_franquicias)} · Reactivados ${n(kpis.reactivadas_franquicias)}`);
    setText('#patsGlobalDistNote', `Nuevos ${n(kpis.nuevas_distribuciones)} · Reactivados ${n(kpis.reactivadas_distribuciones)}`);
    setText('#patsGlobalPatsNote', `Nuevos ${n(kpis.nuevos_pats)} · Reactivados ${n(kpis.reactivados_pats)}`);

    setText('#patsAdminIngresoRealDepositado', money(kpis.ingreso_real_depositado));
    setText('#patsAdminSaldoPendienteContratos', money(kpis.saldo_pendiente_contratos));
    setText('#patsAdminIngresoAdminReal', money(kpis.ingreso_admin_real));
    setText('#patsAdminVentasContratadas', money(kpis.ventas_contratadas || kpis.ventas_globales));

    setText(
      '#patsAdminIngresoRealDepositadoNote',
      `Franquicias ${money(kpis.ingreso_real_franquicias)} · Distribuciones ${money(kpis.ingreso_real_distribuciones)} · PATS ${money(kpis.ingreso_real_pats)}`
    );
    setText(
      '#patsAdminSaldoPendienteContratosNote',
      `Franquicias ${money(kpis.saldo_pendiente_franquicias)} · Distribuciones ${money(kpis.saldo_pendiente_distribuciones)}`
    );
    setText(
      '#patsAdminIngresoAdminRealNote',
      `Hospital real ${money(kpis.hospital_real_pats)} · Comisiones liberadas ${money(kpis.comisiones_reales_liberadas)}`
    );
    setText(
      '#patsAdminVentasContratadasNote',
      `Ventas firmadas, aunque no estén totalmente pagadas`
    );

    setText('#patsAdminValorCatalogoFranq', money(kpis.valor_catalogo_franquicias));
    setText('#patsAdminValorContratoFranq', money(kpis.monto_franquicias));
    setText('#patsAdminParticipacionAdminPats', money(kpis.participacion_adminpats));

    setText('#patsAdminValorCatalogoFranqNote', `Precio de lista · Franquicias ${n(kpis.total_franquicias)}`);
    setText('#patsAdminValorContratoFranqNote', `Lo realmente firmado en contrato`);
    setText('#patsAdminParticipacionAdminPatsNote', `Descuentos, participación o copropiedad · Compartidas ${n(kpis.franquicias_compartidas)}`);

    setText('#patsAdminIngresoTotal', money(kpis.ingreso_admin_total));
    setText('#patsAdminIngresoFranq', money(kpis.monto_franquicias));
    setText('#patsAdminIngresoDist', money(kpis.monto_admin_distribuciones));
    setText('#patsAdminIngresoPats', money(kpis.monto_admin_pasaporte));

    setText('#patsAdminIngresoFormulaNote', `ventas contratadas - comisiones - hospital`);
    setText('#patsAdminRecargosNote', `Recargos ${money(kpis.monto_recargos)}`);

    setText('#patsAdminComisionFranquicia', money(kpis.monto_comision_franquicia));
    setText(
      '#patsAdminComisionFranquiciaNote',
      `Distribuciones ${money(kpis.monto_franquiciatario_distribuciones)} · PATS ${money(kpis.monto_comision_franquicia_pats)}`
    );

    setText('#patsAdminComisionDistribuidor', money(kpis.monto_comision_distribuidor));
    setText('#patsAdminComisionGestor', money(kpis.monto_comision_gestor));
    setText(
      '#patsAdminComisionGestorNote',
      `Pagada ${money(kpis.monto_comision_gestor_pagada)} · Pendiente ${money(kpis.monto_comision_gestor_pendiente)}`
    );

    setText('#patsEstructuraGestores', n(kpis.total_gestores));
    setText('#patsEstructuraFranqConGestor', n(kpis.franquicias_con_gestor));
    setText('#patsEstructuraFranqSinGestor', n(kpis.franquicias_sin_gestor));
    setText('#patsEstructuraFranqCompartidas', n(kpis.franquicias_compartidas));
    setText('#patsEstructuraFranqIndividuales', n(kpis.franquicias_individuales));
    setText('#patsEstructuraCopropietarios', n(kpis.total_copropietarios));
    setText('#patsEstructuraTitularesAccesoNote', `Titulares reales con acceso ${n(kpis.titulares_con_acceso)}`);

    const hospitalWrap = PATS.$('#patsAdminHospitalList');
    if (hospitalWrap) {
      const items = arr(hospitales);
      hospitalWrap.innerHTML = items.length
        ? items.map(item => `
            <article class="pats-kpi-card">
              <span class="k">${PATS.escapeHtml(item.hospital || 'Hospital')}</span>
              <strong>${money(item.ingreso_hospital)}</strong>
            </article>
          `).join('')
        : `<div class="pats-empty-inline">Sin datos.</div>`;
    }

    if (window.PATS && typeof window.PATS.paintKpiSemanticTones === "function") {
      window.PATS.paintKpiSemanticTones(".pats-kpi-card");
    }
  }

  function renderChartBar(canvasId, emptyId, labels, values, chartKey, label, isMoney = false) {
    const canvas = document.getElementById(canvasId);
    const empty = PATS.$(emptyId);
    PATS.destroyChart(chartKey);

    const vals = arr(values).map(v => n(v));
    const hasData = vals.some(v => v > 0);

    if (canvas && hasData && window.PATSCharts) {
      canvas.hidden = false;
      if (empty) empty.hidden = true;
      PATS.state.charts[chartKey] = window.PATSCharts.buildBar(canvas, labels || [], vals, label, isMoney);
    } else {
      if (canvas) canvas.hidden = true;
      if (empty) empty.hidden = false;
    }
  }

  function renderChartDoughnut(canvasId, emptyId, labels, values, chartKey, isMoney = false) {
    const canvas = document.getElementById(canvasId);
    const empty = PATS.$(emptyId);
    PATS.destroyChart(chartKey);

    const vals = arr(values).map(v => n(v));
    const hasData = vals.some(v => v > 0);

    if (canvas && hasData && window.PATSCharts) {
      canvas.hidden = false;
      if (empty) empty.hidden = true;
      PATS.state.charts[chartKey] = window.PATSCharts.buildDoughnut(canvas, labels || [], vals, isMoney);
    } else {
      if (canvas) canvas.hidden = true;
      if (empty) empty.hidden = false;
    }
  }

  function renderChartMensualAnual(charts) {
    const canvas = document.getElementById('patsAdminChartMensualAnual');
    const empty = PATS.$('#patsAdminChartMensualAnualEmpty');

    PATS.destroyChart('adminMensualAnual');

    const chart = charts?.mensual_anual || {};
    const labels = arr(chart.labels);
    const ingreso = arr(chart.ingreso_total);
    const franquicias = arr(chart.franquicias);
    const distribuidores = arr(chart.distribuidores);
    const pats = arr(chart.pats);
    const gestores = arr(chart.gestores);

    const hasData = [...ingreso, ...franquicias, ...distribuidores, ...pats, ...gestores].some(v => n(v) > 0);

    if (canvas && hasData && window.PATSCharts && typeof window.PATSCharts.buildLineDualAxis === 'function') {
      canvas.hidden = false;
      if (empty) empty.hidden = true;
      PATS.state.charts.adminMensualAnual = window.PATSCharts.buildLineDualAxis(canvas, {
        labels,
        ingreso,
        franquicias,
        distribuidores,
        pats,
        gestores
      });
    } else {
      if (canvas) canvas.hidden = true;
      if (empty) empty.hidden = false;
    }
  }

  function renderCharts(charts) {
    renderChartDoughnut(
      'patsAdminChartDistribucion',
      '#patsAdminChartDistribucionEmpty',
      charts?.distribucion?.labels || [],
      charts?.distribucion?.values || [],
      'adminDistribucion',
      true
    );

    renderChartBar(
      'patsAdminChartNominalRealExtra',
      '#patsAdminChartNominalRealExtraEmpty',
      charts?.nominal_real_extra?.labels || [],
      charts?.nominal_real_extra?.values || [],
      'adminNominalRealExtra',
      'Precio vs venta',
      true
    );

    renderChartBar(
      'patsAdminChartFrecuencia',
      '#patsAdminChartFrecuenciaEmpty',
      charts?.frecuencia?.labels || [],
      charts?.frecuencia?.values || [],
      'adminFrecuencia',
      'PATS por tipo de pago',
      true
    );

    renderChartBar(
      'patsAdminChartCopropiedadGestor',
      '#patsAdminChartCopropiedadGestorEmpty',
      charts?.copropiedad_gestor?.labels || [],
      charts?.copropiedad_gestor?.values || [],
      'adminCopropiedadGestor',
      'Estructura',
      false
    );

    renderChartMensualAnual(charts);
  }

  function renderRankings(rankings) {
    const wrapActivas = PATS.$('#patsRankingRegionesActivas');
    const wrapIngresos = PATS.$('#patsRankingRegionesIngresos');

    if (wrapActivas) {
      const items = arr(rankings?.regiones_pats_activos);
      wrapActivas.innerHTML = items.length
        ? items.map((r, idx) => `
            <div class="pats-ranking-item">
              <span>${idx + 1}. ${PATS.escapeHtml(r.region || '-')}</span>
              <strong>${n(r.total_pats_activos)} activos</strong>
            </div>
          `).join('')
        : `<div class="pats-empty-inline">Sin datos.</div>`;
    }

    if (wrapIngresos) {
      const items = arr(rankings?.ingresos_region);
      wrapIngresos.innerHTML = items.length
        ? items.map((r, idx) => `
            <div class="pats-ranking-item">
              <span>${idx + 1}. ${PATS.escapeHtml(r.region || '-')}</span>
              <strong>${money(r.ingreso_total)}</strong>
            </div>
          `).join('')
        : `<div class="pats-empty-inline">Sin datos.</div>`;
    }
  }

  async function loadAdminDashboard() {
    try {
      const data = await PATS.getJSON('dashboard_admin.php');
      renderRegions(data.regiones || []);
      renderSummary(data.kpis || {}, data.hospitales || []);
      renderCharts(data.charts || {});
      renderRankings(data.rankings || {});
    } catch (e) {
      console.error('[PATS ADMIN] loadAdminDashboard error:', e);
      const wrap = PATS.$('#patsRegionList');
      if (wrap) wrap.innerHTML = `<div class="pats-empty-state">No fue posible cargar las regiones.</div>`;
      PATS.toast(e.message || 'Error cargando dashboard admin');
    }
  }

  async function copiarLinkPublicoAdmin(link, okMsg) {
    if (!link) {
      PATS.toast("No hay link disponible.");
      return;
    }

    try {
      await navigator.clipboard.writeText(link);
      PATS.toast(okMsg || "Link copiado correctamente");
    } catch (e) {
      try {
        const tmp = document.createElement("textarea");
        tmp.value = link;
        tmp.setAttribute("readonly", "readonly");
        tmp.style.position = "fixed";
        tmp.style.left = "-9999px";
        tmp.style.top = "-9999px";
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

  function bindAdminExtras() {
    const btnGestor = PATS.$('#btnGoGestorView');
    if (btnGestor) {
      btnGestor.addEventListener('click', () => {
        goGestorView();
      });
    }

    const linksPublicos = {
      distribucion: 'https://50d.com.mx/50D/EZHS/ez/pats/pago_distribucion.php',
      pats: 'https://pasaporteatusalud.com/landing_pats.php',
      franquicia: 'https://50d.com.mx/50D/EZHS/ez/pats/landing_franquicia.php'
    };

    const btnDist = PATS.$('#btnCopiarLandingDistribucionAdmin');
    const btnPats = PATS.$('#btnCopiarLandingPatsAdmin');
    const btnFran = PATS.$('#btnCopiarLandingFranquiciaAdmin');

    if (btnDist) {
      btnDist.addEventListener('click', (ev) => {
        ev.preventDefault();
        copiarLinkPublicoAdmin(linksPublicos.distribucion, 'Link público de distribución copiado');
      });
    }

    if (btnPats) {
      btnPats.addEventListener('click', (ev) => {
        ev.preventDefault();
        copiarLinkPublicoAdmin(linksPublicos.pats, 'Link público PATS copiado');
      });
    }

    if (btnFran) {
      btnFran.addEventListener('click', (ev) => {
        ev.preventDefault();
        copiarLinkPublicoAdmin(linksPublicos.franquicia, 'Link público de franquicia copiado');
      });
    }
  }

  document.addEventListener('DOMContentLoaded', async () => {
    const regionQ = getQueryValue('region');
    const zonaQ = getQueryValue('zona');
    const anioQ = getQueryValue('anio');
    const mesQ = getQueryValue('mes');

    PATS.state.filters.region = regionQ ? regionQ : '';
    if (zonaQ) PATS.state.filters.zona = zonaQ;
    if (anioQ) PATS.state.filters.anio = anioQ;
    if (mesQ) PATS.state.filters.mes = mesQ;

    await PATS.loadScopeOptions();
    syncAdminTopbarVisibility();
    PATS.bindCommonFilters(loadAdminDashboard);
    bindAdminExtras();

    const search = PATS.$('#patsSearchRegion');
    if (search) {
      search.addEventListener('input', PATS.debounce(() => {
        const q = search.value.trim().toLowerCase();
        PATS.$$('.pats-region-card').forEach((card) => {
          const txt = card.textContent.toLowerCase();
          card.style.display = txt.includes(q) ? '' : 'none';
        });
      }, 150));
    }

    loadAdminDashboard();
  });
})();
