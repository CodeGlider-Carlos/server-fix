/*
Archivo: ez/pats/js/pats_admin_distribuidores_corporativos_button_fix.js
Módulo: PATS · ADMINPATS Dashboard
Propósito:
  Corregir el botón "Ver distribuidores corporativos" para que navegue a la vista dedicada.
Responsabilidad:
  - Enlazar #btnGoDistribuidoresCorporativos.
  - Redirigir a ez/pats/distribuidores_corporativos.php.
  - Conservar filtros actuales region, zona, anio y mes si existen en admin.php.
  - No abrir listado dentro del dashboard admin.
Conexiones:
  admin.php, distribuidores_corporativos.php.
Tipo:
  JS específico de PATS Admin. Parche seguro y aislado.
*/

(() => {
  "use strict";

  function $(sel, root = document) {
    return root.querySelector(sel);
  }

  function getParam(name) {
    return new URLSearchParams(window.location.search).get(name) || "";
  }

  function getValue(selectors) {
    for (const sel of selectors) {
      const el = $(sel);
      if (!el) continue;

      const value = String(el.value || "").trim();
      if (value) return value;
    }
    return "";
  }

  function buildDistribuidoresCorporativosUrl() {
    const params = new URLSearchParams();

    const region = getParam("region") || getValue(["#patsRegion", "#adminRegion", "select[name='region']", "input[name='region']"]);
    const zona = getParam("zona") || getValue(["#patsZona", "#adminZona", "select[name='zona']", "input[name='zona']"]);
    const anio = getParam("anio") || getValue(["#patsAnio", "#adminAnio", "input[name='anio']", "select[name='anio']"]);
    const mes = getParam("mes") || getValue(["#patsMes", "#adminMes", "select[name='mes']", "input[name='mes']"]);

    if (region) params.set("region", region);
    if (zona) params.set("zona", zona);
    if (anio) params.set("anio", anio);
    if (mes) params.set("mes", mes);

    const qs = params.toString();

    /*
      admin.php y distribuidores_corporativos.php viven en la misma carpeta:
      ez/pats/admin.php
      ez/pats/distribuidores_corporativos.php
    */
    return `distribuidores_corporativos.php${qs ? "?" + qs : ""}`;
  }

  function bindButton() {
    const btn = $("#btnGoDistribuidoresCorporativos");
    if (!btn) return;

    btn.disabled = false;
    btn.removeAttribute("disabled");
    btn.setAttribute("type", "button");
    btn.classList.remove("is-disabled");

    if (btn.dataset.distCorpRedirectBound === "1") return;
    btn.dataset.distCorpRedirectBound = "1";

    btn.addEventListener("click", (ev) => {
      ev.preventDefault();
      ev.stopPropagation();

      window.location.href = buildDistribuidoresCorporativosUrl();
    }, true);
  }

  document.addEventListener("DOMContentLoaded", bindButton);
  window.addEventListener("load", bindButton);

  window.PATSGoDistribuidoresCorporativos = {
    bindButton,
    buildUrl: buildDistribuidoresCorporativosUrl
  };
})();
