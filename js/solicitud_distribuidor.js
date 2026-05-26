/*
ez/pats/js/solicitud_distribuidor.js
*/
(() => {
  "use strict";

  const $ = (s) => document.querySelector(s);
  const $$ = (s) => Array.from(document.querySelectorAll(s));
  const CFG = window.PATS_SOL_CFG || {};

  let currentStep = 1;
  const totalSteps = 5;

  function onlyDigits(v) {
    return String(v || "").replace(/\D+/g, "");
  }

  function validEmail(v) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(v || "").trim());
  }

  function validPhone(v) {
    return onlyDigits(v).length === 10;
  }

  function validClabe(v) {
    const clabe = onlyDigits(v);
    if (!clabe) return true;
    if (clabe.length !== 18) return false;

    const factores = [3, 7, 1];
    let suma = 0;

    for (let i = 0; i < 17; i++) {
      const dig = Number(clabe[i]);
      suma += ((dig * factores[i % 3]) % 10);
    }

    const control = (10 - (suma % 10)) % 10;
    return control === Number(clabe[17]);
  }

  function parseNum(v) {
    const n = Number(v || 0);
    return Number.isFinite(n) ? n : 0;
  }

  function money(v) {
    return new Intl.NumberFormat("es-MX", {
      style: "currency",
      currency: "MXN",
      maximumFractionDigits: 2
    }).format(Number(v || 0));
  }

  function toast(msg, type = "info", timeout = 2600) {
    const el = $("#solfrmToast");
    if (!el) return;
    el.textContent = msg || "";
    el.classList.add("is-open");
    el.style.background =
      type === "error"
        ? "linear-gradient(135deg, rgba(55,16,24,.96), rgba(108,26,48,.96))"
        : type === "success"
          ? "linear-gradient(135deg, rgba(10,34,54,.96), rgba(18,92,116,.96))"
          : "linear-gradient(135deg, rgba(12,21,46,.96), rgba(36,28,74,.96))";

    clearTimeout(el._hideTimer);
    el._hideTimer = setTimeout(() => el.classList.remove("is-open"), timeout);
  }

  function bindCustomFileInputs() {
    $$(".pats-file-native").forEach((input) => {
      if (input.dataset.boundFile === "1") return;
      input.dataset.boundFile = "1";

      input.addEventListener("change", () => {
        const wrap = input.closest(".pats-file-upload");
        const text = wrap?.querySelector(".pats-file-upload__text");
        if (!text) return;

        const files = input.files;
        if (files && files.length > 0) {
          text.textContent = files.length === 1 ? files[0].name : `${files.length} archivos seleccionados`;
          wrap.classList.add("is-filled");
        } else {
          text.textContent = "Ningún archivo seleccionado";
          wrap.classList.remove("is-filled");
        }
      });
    });
  }

  function syncWizard() {
    $$("[data-step-panel]").forEach((panel) => {
      const active = Number(panel.dataset.stepPanel) === currentStep;
      panel.classList.toggle("is-active", active);
      panel.hidden = !active;
    });

    $$("[data-step]").forEach((btn) => {
      const step = Number(btn.dataset.step);
      btn.classList.toggle("is-active", step === currentStep);
      btn.classList.toggle("is-done", step < currentStep);
    });

    const prev = $("#btnWizardPrev");
    const next = $("#btnWizardNext");
    const submit = $("#btnWizardSubmit");
    const preview = $("#btnPreviewPlan");

    if (prev) prev.style.visibility = currentStep === 1 ? "hidden" : "visible";
    if (next) next.hidden = currentStep === totalSteps;
    if (submit) submit.hidden = currentStep !== totalSteps;
    if (preview) preview.hidden = currentStep !== totalSteps;
  }

  function goStep(step) {
    currentStep = Math.max(1, Math.min(totalSteps, step));
    syncWizard();
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  function populateEstados(selected = "") {
    const sel = $("#region");
    if (!sel) return;

    sel.innerHTML = `<option value="">Selecciona...</option>`;
    Object.entries(CFG.estados || {}).forEach(([code, label]) => {
      const op = document.createElement("option");
      op.value = code;
      op.textContent = `${code} · ${label}`;
      if (String(code).toUpperCase() === String(selected || "").toUpperCase()) {
        op.selected = true;
      }
      sel.appendChild(op);
    });
  }

  function populateZonas(regionCode = "", selectedZona = "") {
    const sel = $("#zona");
    const wrapNueva = $("#zona_nueva_wrap");
    const inputNueva = $("#zona_nueva");
    if (!sel) return;

    const zonas = Array.isArray(CFG.zonas_por_estado?.[String(regionCode || "").toUpperCase()])
      ? CFG.zonas_por_estado[String(regionCode || "").toUpperCase()]
      : [];

    sel.innerHTML = `<option value="">Selecciona...</option>`;

    zonas.forEach((zona) => {
      const op = document.createElement("option");
      op.value = String(zona);
      op.textContent = String(zona);
      if (String(zona).toUpperCase() === String(selectedZona || "").toUpperCase()) {
        op.selected = true;
      }
      sel.appendChild(op);
    });

    const nueva = document.createElement("option");
    nueva.value = "__NUEVA__";
    nueva.textContent = "Agregar nueva zona...";
    sel.appendChild(nueva);

    const exists = zonas.some(z => String(z).toUpperCase() === String(selectedZona || "").toUpperCase());

    if (selectedZona && !exists) {
      sel.value = "__NUEVA__";
      if (wrapNueva) wrapNueva.hidden = false;
      if (inputNueva) inputNueva.value = selectedZona;
    } else {
      if (wrapNueva) wrapNueva.hidden = sel.value !== "__NUEVA__";
      if (sel.value !== "__NUEVA__" && inputNueva) inputNueva.value = "";
    }
  }

  function fillFranquiciaContext() {
    const select = $("#id_franquicia");
    if (!select) return;

    const op = select.selectedOptions?.[0] || null;
    const pais = op?.dataset.pais || "México";
    const region = op?.dataset.region || CFG.region_default || "";
    const zona = op?.dataset.zona || CFG.zona_default || "";
    const unidad = op?.dataset.unidad || "";

    if ($("#pais")) $("#pais").value = pais;
    if ($("#unidad")) $("#unidad").value = unidad;

    populateEstados(region);
    if ($("#region")) $("#region").value = region;

    populateZonas(region, zona);
  }

function syncTipoPersona() {
  const tipo = ($("#tipo_persona")?.value || "FISICA").toUpperCase().trim();
  const bloque = $("#bloqueDocumentosMoral");
  const razon = $("#razon_social");
  const acta = $("#doc_acta_constitutiva");
  const poder = $("#doc_poder_notarial");

  const esMoral = tipo === "MORAL";

  if (bloque) {
    bloque.style.display = esMoral ? "" : "none";
  }

  if (razon) {
    razon.required = esMoral;
    if (!esMoral) razon.value = "";
  }

  if (acta && !esMoral) {
    acta.value = "";
  }

  if (poder && !esMoral) {
    poder.value = "";
  }
}

  function hasExistingDoc(docId) {
    return !!document.querySelector(`[data-doc-actual="${docId}"]`);
  }

  function hasSelectedFile(inputId) {
    const input = document.getElementById(inputId);
    return !!(input && input.files && input.files.length > 0);
  }

  function syncSaldo() {
    const total = parseNum($("#valor_total")?.value);
    const enganche = parseNum($("#enganche")?.value);
    const saldo = Math.max(0, total - enganche);
    if ($("#saldo_financiado")) $("#saldo_financiado").value = saldo.toFixed(2);
  }

  function syncModoFinanzas() {
    const modalidad = ($("#modalidad_pago")?.value || "CONTADO").toUpperCase();
    const plazo = $("#plazo_meses");
    const periodicidad = $("#periodicidad");
    const primerVenc = $("#fecha_primer_vencimiento");

    if (modalidad === "CONTADO") {
      if ($("#enganche")) $("#enganche").value = "0";
      if (plazo) { plazo.value = "0"; plazo.disabled = true; }
      if (periodicidad) periodicidad.disabled = true;
      if (primerVenc) { primerVenc.value = ""; primerVenc.disabled = true; }
    } else {
      if (plazo) plazo.disabled = false;
      if (periodicidad) periodicidad.disabled = false;
      if (primerVenc) primerVenc.disabled = false;
    }

    syncSaldo();
  }

  function buildPlanPreview() {
    syncSaldo();

    const modalidad = ($("#modalidad_pago")?.value || "CONTADO").toUpperCase();
    const saldo = parseNum($("#saldo_financiado")?.value);
    const plazo = parseInt($("#plazo_meses")?.value || "0", 10);
    const periodicidad = ($("#periodicidad")?.value || "MENSUAL").toUpperCase();
    const primerVenc = ($("#fecha_primer_vencimiento")?.value || $("#fecha_inicio")?.value || "").trim();

    const body = $("#planPreviewBody");
    const card = $("#planPreviewCard");
    if (!body || !card) return;

    if (!primerVenc) {
      body.innerHTML = `<div class="pats-empty-inline">Captura la fecha de inicio o el primer vencimiento.</div>`;
      card.hidden = false;
      return;
    }

    if (modalidad === "CONTADO" || saldo <= 0 || plazo <= 0) {
      body.innerHTML = `
        <div class="pats-finance-plan-line"><span>Modalidad</span><strong>${modalidad}</strong></div>
        <div class="pats-finance-plan-line"><span>Saldo financiado</span><strong>${money(saldo)}</strong></div>
        <div class="pats-finance-plan-line"><span>Parcialidades</span><strong>No aplica</strong></div>
      `;
      card.hidden = false;
      return;
    }

    const base = new Date(primerVenc + "T00:00:00");
    const fechas = [];

    for (let i = 0; i < plazo; i++) {
      const d = new Date(base);
      if (periodicidad === "SEMANAL") d.setDate(base.getDate() + (i * 7));
      else if (periodicidad === "QUINCENAL") d.setDate(base.getDate() + (i * 15));
      else if (periodicidad === "UNICA") d.setDate(base.getDate());
      else d.setMonth(base.getMonth() + i);

      const y = d.getFullYear();
      const m = String(d.getMonth() + 1).padStart(2, "0");
      const dd = String(d.getDate()).padStart(2, "0");
      fechas.push(`${y}-${m}-${dd}`);

      if (periodicidad === "UNICA") break;
    }

    const n = fechas.length || 1;
    const baseMonto = Math.floor((saldo / n) * 100) / 100;
    let acumulado = 0;

    let html = `<div class="pats-finance-plan-table">`;
    fechas.forEach((fecha, idx) => {
      let monto = baseMonto;
      acumulado += monto;
      if (idx === fechas.length - 1) {
        monto += +(saldo - acumulado).toFixed(2);
      }
      html += `
        <div class="pats-finance-plan-row">
          <span>${idx + 1}</span>
          <span>${fecha}</span>
          <strong>${money(monto)}</strong>
        </div>
      `;
    });
    html += `</div>`;

    body.innerHTML = html;
    card.hidden = false;
  }

  function validateStep1() {
    const required = ["id_franquicia", "pais", "region", "zona", "unidad"];
    for (const id of required) {
      const el = $("#" + id);
      if (!el || !String(el.value || "").trim()) {
        toast("Completa la franquicia y el contexto.", "error");
        el?.focus();
        return false;
      }
    }

    if (($("#zona")?.value || "") === "__NUEVA__" && !String($("#zona_nueva")?.value || "").trim()) {
      toast("Debes capturar la nueva zona.", "error");
      $("#zona_nueva")?.focus();
      return false;
    }

    return true;
  }

  function validateStep2() {
    const nombre = ($("#nombre")?.value || "").trim();
    const correo = ($("#correo")?.value || "").trim();
    const telefono = ($("#telefono")?.value || "").trim();

    if (!nombre) {
      toast("Captura el nombre del distribuidor.", "error");
      $("#nombre")?.focus();
      return false;
    }
    if (!validEmail(correo)) {
      toast("El correo no es válido.", "error");
      $("#correo")?.focus();
      return false;
    }
    if (!validPhone(telefono)) {
      toast("El teléfono debe tener 10 dígitos.", "error");
      $("#telefono")?.focus();
      return false;
    }
    return true;
  }

  function validateStep3() {
    const clabe = onlyDigits($("#clabe")?.value || "");
    if (clabe && !validClabe(clabe)) {
      toast("La CLABE no es válida.", "error");
      $("#clabe")?.focus();
      return false;
    }

    if (!hasSelectedFile("doc_caratula_bancaria") && !hasExistingDoc("doc_caratula_bancaria")) {
      toast("Debes cargar la carátula bancaria.", "error");
      $("#doc_caratula_bancaria")?.focus();
      return false;
    }

    return true;
  }

function validateStep4() {
  const tipo = ($("#tipo_persona")?.value || "").toUpperCase().trim();

  if (!tipo) {
    toast("Debes seleccionar si es persona física o moral.", "error");
    $("#tipo_persona")?.focus();
    return false;
  }

  const docsBase = [
    { id: "doc_ine", msg: "Debes cargar el INE." },
    { id: "doc_curp", msg: "Debes cargar la CURP." },
    { id: "doc_domicilio", msg: "Debes cargar el comprobante de domicilio." },
    { id: "doc_cedula", msg: "Debes cargar la cédula fiscal." }
  ];

  for (const doc of docsBase) {
    if (!hasSelectedFile(doc.id) && !hasExistingDoc(doc.id)) {
      toast(doc.msg, "error");
      $("#" + doc.id)?.focus();
      return false;
    }
  }

  if (tipo === "MORAL") {
    const razonSocial = ($("#razon_social")?.value || "").trim();
    const okActa = hasSelectedFile("doc_acta_constitutiva") || hasExistingDoc("doc_acta_constitutiva");
    const okPoder = hasSelectedFile("doc_poder_notarial") || hasExistingDoc("doc_poder_notarial");

    if (!razonSocial) {
      toast("Para persona moral debes capturar la razón social.", "error");
      $("#razon_social")?.focus();
      return false;
    }

    if (!okActa && !okPoder) {
      toast("Para persona moral debes cargar al menos acta constitutiva o poder notarial.", "error");
      $("#doc_acta_constitutiva")?.focus();
      return false;
    }
  }

  return true;
}

  function validateStep5() {
    const modalidad = ($("#modalidad_pago")?.value || "").trim();
    const fechaInicio = ($("#fecha_inicio")?.value || "").trim();
    const total = parseNum($("#valor_total")?.value);
    const enganche = parseNum($("#enganche")?.value);
    const saldo = parseNum($("#saldo_financiado")?.value);
    const plazo = parseInt($("#plazo_meses")?.value || "0", 10);

    if (!modalidad) {
      toast("Selecciona la modalidad de pago.", "error");
      $("#modalidad_pago")?.focus();
      return false;
    }

    if (!fechaInicio) {
      toast("Debes capturar la fecha de inicio.", "error");
      $("#fecha_inicio")?.focus();
      return false;
    }

    if (enganche > total) {
      toast("El enganche no puede ser mayor al valor total.", "error");
      $("#enganche")?.focus();
      return false;
    }

    if (modalidad !== "CONTADO" && saldo > 0 && plazo <= 0) {
      toast("Debes capturar un plazo válido para el financiamiento.", "error");
      $("#plazo_meses")?.focus();
      return false;
    }

    if (!hasSelectedFile("doc_comprobante_pago") && !hasExistingDoc("doc_comprobante_pago")) {
      toast("Debes cargar el comprobante de pago.", "error");
      $("#doc_comprobante_pago")?.focus();
      return false;
    }

    return true;
  }

  function validateCurrentStep() {
    if (currentStep === 1) return validateStep1();
    if (currentStep === 2) return validateStep2();
    if (currentStep === 3) return validateStep3();
    if (currentStep === 4) return validateStep4();
    if (currentStep === 5) return validateStep5();
    return true;
  }

  async function submitSolicitud(ev) {
    ev.preventDefault();

    syncSaldo();

    if (
      !validateStep1() ||
      !validateStep2() ||
      !validateStep3() ||
      !validateStep4() ||
      !validateStep5()
    ) {
      return;
    }

    const form = ev.currentTarget;
    const fd = new FormData(form);

    if (($("#zona")?.value || "") === "__NUEVA__") {
      fd.set("zona", ($("#zona_nueva")?.value || "").trim());
    }

    const btn = $("#btnWizardSubmit");
    if (btn) {
      btn.disabled = true;
      btn.dataset.oldText = btn.textContent || "";
      btn.textContent = "Enviando...";
    }

    try {
      const res = await fetch("endpoints/solicitud_distribuidor_guardar.php", {
        method: "POST",
        body: fd,
        headers: { "X-Requested-With": "XMLHttpRequest" }
      });

      const text = await res.text();
      let data = {};
      try {
        data = text ? JSON.parse(text) : {};
      } catch {
        throw new Error("Respuesta no válida del servidor");
      }

      if (!res.ok || data.ok === false) {
        throw new Error(data.error || "No fue posible enviar la solicitud");
      }

      toast(
        data.modo === "edicion"
          ? "Solicitud actualizada correctamente"
          : "Solicitud enviada correctamente",
        "success"
      );

      setTimeout(() => {
        window.location.href = (CFG.volver_href || "franquicia.php");
      }, 900);
    } catch (err) {
      toast(err.message || "No fue posible enviar la solicitud", "error", 3400);
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.textContent = btn.dataset.oldText || "Enviar solicitud";
      }
    }
  }

  document.addEventListener("DOMContentLoaded", () => {
    const franquiciaSel = $("#id_franquicia");
    const regionSel = $("#region");
    const zonaSel = $("#zona");
    const zonaNuevaWrap = $("#zona_nueva_wrap");
    const zonaNuevaInput = $("#zona_nueva");

    populateEstados(CFG.region_default || "");
    fillFranquiciaContext();
    syncTipoPersona();
    syncModoFinanzas();
    syncSaldo();
    bindCustomFileInputs();
    syncWizard();

    franquiciaSel?.addEventListener("change", fillFranquiciaContext);

    regionSel?.addEventListener("change", () => {
      populateZonas(regionSel.value, "");
    });

    zonaSel?.addEventListener("change", () => {
      const isNueva = zonaSel.value === "__NUEVA__";
      if (zonaNuevaWrap) zonaNuevaWrap.hidden = !isNueva;
      if (zonaNuevaInput && !isNueva) zonaNuevaInput.value = "";
    });

    $("#tipo_persona")?.addEventListener("change", syncTipoPersona);

    $("#telefono")?.addEventListener("input", (e) => {
      e.target.value = onlyDigits(e.target.value).slice(0, 10);
    });

    $("#clabe")?.addEventListener("input", (e) => {
      e.target.value = onlyDigits(e.target.value).slice(0, 18);
    });

    $("#enganche")?.addEventListener("input", syncSaldo);
    $("#modalidad_pago")?.addEventListener("change", syncModoFinanzas);
    $("#btnPreviewPlan")?.addEventListener("click", buildPlanPreview);

    $("#btnWizardPrev")?.addEventListener("click", () => {
      if (currentStep > 1) goStep(currentStep - 1);
    });

    $("#btnWizardNext")?.addEventListener("click", () => {
      if (!validateCurrentStep()) return;
      if (currentStep < totalSteps) goStep(currentStep + 1);
    });

    $$("[data-step]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const step = Number(btn.dataset.step || 1);
        if (step < currentStep) goStep(step);
      });
    });

    $("#btnCancelarSolicitud")?.addEventListener("click", () => {
      window.location.href = (CFG.volver_href || "franquicia.php");
    });

    $("#frmSolicitudDistribuidor")?.addEventListener("submit", submitSolicitud);
  });
})();