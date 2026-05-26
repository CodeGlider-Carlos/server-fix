/*
ez/pats/js/pats_distribuidores_corporativos.js
Módulo: PATS
Propósito: Controlar la vista de distribuidores corporativos ADMINPATS.
Responsabilidad: Consultar el endpoint, renderizar KPIs/listado y abrir dashboard individual de distribuidor.
Conexiones: endpoints/distribuidores_corporativos_listar.php, distribuidores_corporativos.php, distribuidor.php.
Tipo: JavaScript específico de PATS.
*/
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

  function n(v) {
    const x = Number(v || 0);
    return Number.isFinite(x) ? x : 0;
  }

  function esc(str) {
    return String(str || "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function toast(message, type = "info", timeout = 2800) {
    let host = document.getElementById("patsToastHost");
    if (!host) {
      host = document.createElement("div");
      host.id = "patsToastHost";
      host.className = "pats-toast-host";
      document.body.appendChild(host);
    }

    const el = document.createElement("div");
    el.className = `pats-toast pats-toast--${type}`;
    el.textContent = String(message || "");
    host.appendChild(el);

    setTimeout(() => {
      el.style.opacity = "0";
      el.style.transform = "translateY(-4px)";
      setTimeout(() => el.remove(), 240);
    }, timeout);
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
      throw new Error("Respuesta inválida del servidor.");
    }

    if (!res.ok || data.ok === false) {
      throw new Error(data.error || "No fue posible cargar la información.");
    }

    return data;
  }

  function buildQuery() {
    const qs = new URLSearchParams();

    const q = ($("#distCorpQ")?.value || "").trim();
    const region = ($("#distCorpRegion")?.value || "").trim();
    const zona = ($("#distCorpZona")?.value || "").trim();
    const anio = ($("#distCorpAnio")?.value || "").trim();
    const mes = ($("#distCorpMes")?.value || "").trim();

    if (q) qs.set("q", q);
    if (region) qs.set("region", region);
    if (zona) qs.set("zona", zona);
    if (anio) qs.set("anio", anio);
    if (mes) qs.set("mes", mes);

    return qs.toString();
  }

  function setText(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
  }

  function renderKpis(k = {}) {
    setText("distCorpTotal", String(n(k.total_distribuidores)));
    setText("distCorpVentaDistribuciones", money(k.valor_distribuciones || 0));
    setText("distCorpPatsVendidos", String(n(k.pats_vendidos)));
    setText("distCorpPatsMontoNote", `Monto PATS ${money(k.ventas_pats || 0)} · Activos ${n(k.pats_activos)} · Vencidos ${n(k.pats_vencidos)}`);
    setText("distCorpCobrado", money(k.dinero_recibido || 0));
    setText("distCorpPorCobrar", money(k.por_cobrar || 0));
    setText("distCorpComisiones", money(k.comisiones_distribuidor || 0));
  }

  function statusLabel(row) {
    const pendiente = n(row.saldo_pendiente_contrato);
    const pagado = n(row.total_pagado_contrato);
    const valor = n(row.valor_contrato || row.valor_distribucion);

    if (pendiente > 0) return { text: "Con saldo pendiente", cls: "is-warn" };
    if (pagado > 0 || valor > 0) return { text: "Al corriente", cls: "is-ok" };
    return { text: "Sin contrato detectado", cls: "is-muted" };
  }

  function renderList(items = []) {
    const host = $("#distCorpList");
    if (!host) return;

    if (!Array.isArray(items) || !items.length) {
      host.innerHTML = `<div class="pats-empty-state">No se encontraron distribuidores corporativos con los filtros seleccionados.</div>`;
      return;
    }

    host.innerHTML = items.map((row) => {
      const st = statusLabel(row);
      const ubicacion = [row.region, row.zona, row.unidad].filter(Boolean).join(" · ") || "Sin ubicación operativa";
      const dashboardUrl = row.dashboard_url || `distribuidor.php?id_distribuidor=${encodeURIComponent(row.id_distribuidor || "")}`;

      return `
        <article class="distcorp-card">
          <div class="distcorp-card__top">
            <div>
              <span class="distcorp-card__eyebrow">Distribuidor #${esc(row.id_distribuidor)}</span>
              <h3>${esc(row.nombre || "Distribuidor")}</h3>
              <p>${esc(ubicacion)}</p>
            </div>
            <span class="distcorp-status ${st.cls}">${esc(st.text)}</span>
          </div>

          <div class="distcorp-card__meta">
            <span>${esc(row.correo || "Sin correo")}</span>
            <span>${esc(row.telefono || "Sin teléfono")}</span>
            <span>Alta ${esc(row.fecha_alta || "-")}</span>
          </div>

          <div class="distcorp-card__grid">
            <div><small>Distribución</small><strong>${money(row.valor_distribucion || 0)}</strong></div>
            <div><small>Cobrado</small><strong>${money(row.total_pagado_contrato || 0)}</strong></div>
            <div><small>Por cobrar</small><strong>${money(row.saldo_pendiente_contrato || 0)}</strong></div>
            <div><small>PATS</small><strong>${n(row.total_pasaportes)}</strong></div>
            <div><small>Ventas PATS</small><strong>${money(row.ventas_pats || 0)}</strong></div>
            <div><small>Comisión</small><strong>${money(row.comision_total || 0)}</strong></div>
          </div>

          <div class="distcorp-card__foot">
            <div class="distcorp-mini">
              <span>Activos ${n(row.pats_activos)}</span>
              <span>Vencidos ${n(row.pats_vencidos)}</span>
              <span>Pendiente comisión ${money(row.comision_pendiente || 0)}</span>
            </div>
            <button type="button" class="pats-chip pats-chip--action distcorp-open" data-url="${esc(dashboardUrl)}">
              Ver dashboard
            </button>
          </div>
        </article>
      `;
    }).join("");

    host.querySelectorAll(".distcorp-open").forEach((btn) => {
      btn.addEventListener("click", () => {
        const url = btn.getAttribute("data-url") || "";
        if (url) window.location.href = url;
      });
    });
  }

  async function load() {
    const host = $("#distCorpList");
    if (host) host.innerHTML = `<div class="pats-empty-state">Cargando distribuidores corporativos...</div>`;

    try {
      const qs = buildQuery();
      const data = await getJSON(`endpoints/distribuidores_corporativos_listar.php${qs ? `?${qs}` : ""}`);
      renderKpis(data.kpis || {});
      renderList(Array.isArray(data.distribuidores) ? data.distribuidores : []);
    } catch (err) {
      renderKpis({});
      if (host) host.innerHTML = `<div class="pats-empty-state">No fue posible cargar distribuidores corporativos.</div>`;
      toast(err.message || "Error cargando distribuidores corporativos.", "error", 3400);
    }
  }

  function applyQueryDefaults() {
    const qs = new URLSearchParams(window.location.search);
    const region = qs.get("region") || "";
    const zona = qs.get("zona") || "";
    const anio = qs.get("anio") || "";
    const mes = qs.get("mes") || "";

    if (region && $("#distCorpRegion")) $("#distCorpRegion").value = region;
    if (zona && $("#distCorpZona")) $("#distCorpZona").value = zona;
    if (anio && $("#distCorpAnio")) $("#distCorpAnio").value = anio;
    if (mes && $("#distCorpMes")) $("#distCorpMes").value = mes;
  }

  function bind() {
    $("#btnVolverAdminPats")?.addEventListener("click", () => {
      window.location.href = "admin.php";
    });

    $("#btnGenerarLinkDistribucionCorp")?.addEventListener("click", () => {
      const url = new URL("https://50d.com.mx/50D/EZHS/ez/patsfin/distribucion_links.php", window.location.href);
      url.searchParams.set("id_franquicia", "0");
      window.open(url.toString(), "_blank");
    });

    $("#btnDistCorpRecargar")?.addEventListener("click", load);
    $("#distCorpRegion")?.addEventListener("change", load);
    $("#distCorpZona")?.addEventListener("change", load);
    $("#distCorpAnio")?.addEventListener("change", load);
    $("#distCorpMes")?.addEventListener("change", load);

    const q = $("#distCorpQ");
    if (q) {
      q.addEventListener("input", () => {
        clearTimeout(q._t);
        q._t = setTimeout(load, 320);
      });
    }
  }

  document.addEventListener("DOMContentLoaded", () => {
    applyQueryDefaults();
    bind();
    load();
  });
})();
