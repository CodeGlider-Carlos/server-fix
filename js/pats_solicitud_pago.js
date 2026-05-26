(() => {
  "use strict";

  const $ = (s) => document.querySelector(s);

  function onlyDigits(v) {
    return String(v || "").replace(/\D+/g, "");
  }

  function validEmail(v) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(v || "").trim());
  }

  function validPhone(v) {
    return onlyDigits(v).length === 10;
  }

  function validCurp(v) {
    const curp = String(v || "").trim().toUpperCase();
    return /^[A-Z][AEIOUX][A-Z]{2}\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])[HM][A-Z]{5}[A-Z0-9]\d$/.test(curp);
  }

  function toast(msg, type = "info") {
    let host = document.getElementById("patsToastHost");
    if (!host) {
      host = document.createElement("div");
      host.id = "patsToastHost";
      document.body.appendChild(host);
    }

    const item = document.createElement("div");
    item.textContent = msg;
    item.style.minWidth = "220px";
    item.style.maxWidth = "320px";
    item.style.padding = "10px 12px";
    item.style.borderRadius = "12px";
    item.style.color = "#fff";
    item.style.fontSize = "13px";
    item.style.fontWeight = "700";
    item.style.background =
      type === "error"
        ? "linear-gradient(135deg, rgba(55,16,24,.96), rgba(108,26,48,.96))"
        : type === "success"
        ? "linear-gradient(135deg, rgba(10,34,54,.96), rgba(18,92,116,.96))"
        : "linear-gradient(135deg, rgba(12,21,46,.96), rgba(36,28,74,.96))";
    item.style.boxShadow = "0 12px 28px rgba(6,12,28,.28)";
    item.style.pointerEvents = "auto";

    host.appendChild(item);

    setTimeout(() => {
      item.style.transition = "opacity .22s ease, transform .22s ease";
      item.style.opacity = "0";
      item.style.transform = "translateY(-4px)";
      setTimeout(() => item.remove(), 240);
    }, 2200);
  }

  function syncMonto() {
    const cfg = window.PATS_ORDEN_CFG || {};
    const frecuencia = ($("#frecuencia")?.value || "ANUAL").toUpperCase();
    const monto = frecuencia === "MENSUAL"
      ? Number(cfg.monto_mensual || 800)
      : Number(cfg.monto_anual || 9600);

    const inputMonto = $("#monto_orden");
    const inputTipo = $("#id_tipo_precio");

    if (inputMonto) inputMonto.value = monto.toFixed(2);
    if (inputTipo) inputTipo.value = frecuencia === "MENSUAL" ? "2" : "1";
  }

  function syncEmpresa() {
    const tipoCliente = ($("#tipo_cliente")?.value || "privado").toLowerCase();
    const wrap = $("#wrapNombreEmpresa");
    const input = $("#nombre_empresa");

    const isEmpresa = tipoCliente === "empresa";
    if (wrap) wrap.style.display = isEmpresa ? "" : "none";
    if (input) {
      input.required = isEmpresa;
      if (!isEmpresa) input.value = "";
    }
  }

  async function submitForm(ev) {
    ev.preventDefault();

    const correo = $("#correo_usuario_pats")?.value || "";
    const telefono = $("#telefono_usuario")?.value || "";
    const curp = ($("#curp_usuario")?.value || "").toUpperCase().trim();
    const tipoCliente = ($("#tipo_cliente")?.value || "privado").toLowerCase();
    const nombreEmpresa = $("#nombre_empresa")?.value || "";

    if (!validEmail(correo)) {
      toast("Correo no válido.", "error");
      return;
    }

    if (!validPhone(telefono)) {
      toast("El teléfono debe tener 10 dígitos.", "error");
      return;
    }

    if (!validCurp(curp)) {
      toast("La CURP no parece válida.", "error");
      return;
    }

    if (tipoCliente === "empresa" && !String(nombreEmpresa).trim()) {
      toast("Debes capturar nombre de empresa.", "error");
      return;
    }

    const form = $("#frmOrdenPagoPats");
    const fd = new FormData(form);

    const btn = form.querySelector('[type="submit"]');
    if (btn) {
      btn.disabled = true;
      btn.dataset.oldText = btn.textContent || "Generar orden";
      btn.textContent = "Generando...";
    }

    try {
      const res = await fetch("./endpoints/orden_pago_crear_operativo.php", {
        method: "POST",
        body: fd,
        headers: { "X-Requested-With": "XMLHttpRequest" }
      });

      const text = await res.text();
      let data = {};
      try {
        data = text ? JSON.parse(text) : {};
      } catch {
        toast("Respuesta inválida del servidor.", "error");
        console.error(text);
        return;
      }

      if (!res.ok || data.ok === false) {
        toast(data.error || "No fue posible crear la orden.", "error");
        console.error(data);
        return;
      }

      $("#payFolio").textContent = data.folio_orden || "-";
      $("#payRef").textContent = data.referencia_pago || "-";
      $("#payPublicLink").value = data.checkout_publico_url || "";
      $("#btnIrCheckout").href = data.checkout_url || "#";
      $("#btnAbrirPublico").href = data.checkout_publico_url || "#";
      $("#patsPayResult").hidden = false;

      const btnCopiar = $("#btnCopiarLink");
      if (btnCopiar) {
        btnCopiar.onclick = async () => {
          try {
            await navigator.clipboard.writeText($("#payPublicLink").value || "");
            toast("Link copiado correctamente.", "success");
          } catch (e) {
            console.error(e);
            toast("No fue posible copiar el link.", "error");
          }
        };
      }

      toast("Orden creada correctamente.", "success");
    } catch (err) {
      console.error(err);
      toast("Error de red o servidor.", "error");
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.textContent = btn.dataset.oldText || "Generar orden";
      }
    }
  }

  document.addEventListener("DOMContentLoaded", () => {
    const tel = $("#telefono_usuario");
    if (tel) {
      tel.addEventListener("input", () => {
        tel.value = onlyDigits(tel.value).slice(0, 10);
      });
    }

    const curp = $("#curp_usuario");
    if (curp) {
      curp.addEventListener("input", () => {
        curp.value = curp.value.toUpperCase().replace(/[^A-Z0-9]/g, "").slice(0, 18);
      });
    }

    const frecuencia = $("#frecuencia");
    if (frecuencia) frecuencia.addEventListener("change", syncMonto);

    const tipoCliente = $("#tipo_cliente");
    if (tipoCliente) tipoCliente.addEventListener("change", syncEmpresa);

    syncMonto();
    syncEmpresa();

    const form = $("#frmOrdenPagoPats");
    if (form) form.addEventListener("submit", submitForm);
  });
})();