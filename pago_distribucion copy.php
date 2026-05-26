<?php
/*
ez/pats/pago_distribucion.php
*/
session_start();

require_once '../../varSQL/bd_pats.php';
require_once '../../varSQL/var_pats.php';
require_once __DIR__ . '/public_checkout_resolver.php';

$cx = $cx
  ?? $conexionMod
  ?? $conexion
  ?? null;

if (!$cx instanceof mysqli) {
  die('No se pudo inicializar la conexión mysqli para pago_distribucion.php');
}

$ver = time();

function pd_estado_nombre_desde_acronimo(string $acr): string {
  $map = [
    'AGS' => 'Aguascalientes',
    'BCN' => 'Baja California',
    'BCS' => 'Baja California Sur',
    'CAM' => 'Campeche',
    'CHP' => 'Chiapas',
    'CHH' => 'Chihuahua',
    'CDMX' => 'Ciudad de México',
    'COA' => 'Coahuila',
    'COL' => 'Colima',
    'DGO' => 'Durango',
    'GTO' => 'Guanajuato',
    'GRO' => 'Guerrero',
    'HGO' => 'Hidalgo',
    'JAL' => 'Jalisco',
    'MEX' => 'Estado de México',
    'MIC' => 'Michoacán',
    'MOR' => 'Morelos',
    'NAY' => 'Nayarit',
    'NLE' => 'Nuevo León',
    'OAX' => 'Oaxaca',
    'PUE' => 'Puebla',
    'QRO' => 'Querétaro',
    'ROO' => 'Quintana Roo',
    'SLP' => 'San Luis Potosí',
    'SIN' => 'Sinaloa',
    'SON' => 'Sonora',
    'TAB' => 'Tabasco',
    'TAM' => 'Tamaulipas',
    'TLAX' => 'Tlaxcala',
    'VER' => 'Veracruz',
    'YUC' => 'Yucatán',
    'ZAC' => 'Zacatecas'
  ];

  $acr = strtoupper(trim($acr));
  return $map[$acr] ?? $acr;
}

$token = trim((string)($_GET['t'] ?? ''));
if ($token === '') {
  http_response_code(400);
  die('Falta token público');
}

$ctx = pats_resolve_public_checkout_token($cx, $token);
if (!$ctx) {
  http_response_code(404);
  die('El link público no es válido o ya no está activo');
}

$rowPrecio = null;
$stmtPrecio = $cx->prepare("
  SELECT precio
  FROM pats_cat_precios
  WHERE LOWER(TRIM(tipo)) = 'distribucion'
    AND LOWER(TRIM(modalidad)) = 'misma_region'
  ORDER BY id ASC
  LIMIT 1
");
if ($stmtPrecio) {
  $stmtPrecio->execute();
  $rsPrecio = $stmtPrecio->get_result();
  $rowPrecio = $rsPrecio ? $rsPrecio->fetch_assoc() : null;
  $stmtPrecio->close();
}

$precioDistribucion = (float)($rowPrecio['precio'] ?? 20000);

$actorTipo = (string)($ctx['actor_tipo_publico'] ?? '');
$nombreActor = (string)($ctx['nombre_actor_publico'] ?? '');
$nombreFranquicia = (string)($ctx['nombre_franquicia'] ?? '');
$region = strtoupper(trim((string)($ctx['region'] ?? '')));
$zona = (string)($ctx['zona'] ?? '');
$unidad = (string)($ctx['unidad'] ?? '');
$pais = (string)($ctx['pais'] ?? 'México');
$estadoNombre = pd_estado_nombre_desde_acronimo($region);

$labelOrigen = $actorTipo === 'GESTOR'
  ? 'Gestor autorizado'
  : ($actorTipo === 'DISTRIBUIDOR' ? 'Distribuidor autorizado' : 'Franquicia autorizada');

$descOrigen = $actorTipo === 'GESTOR'
  ? 'Este enlace pertenece a un gestor y registrará la distribución vinculada a su franquicia asociada.'
  : ($actorTipo === 'DISTRIBUIDOR'
      ? 'Este enlace pertenece a un distribuidor y mantendrá la trazabilidad comercial de la franquicia asociada.'
      : 'Este enlace pertenece directamente a la franquicia y generará la alta de distribución sobre su propia estructura comercial.');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PATS · Distribución y pago</title>
  <link rel="icon" type="image/x-icon" href="../../img/ez.ico" />
  <link rel="stylesheet" href="css/pago_pats_premium.css?v=<?= $ver ?>">
</head>
<body>
  <div class="pp-wrap">

    <section class="pp-hero">
      <div class="pp-hero__inner">
        <div class="pp-kicker">PATS · DISTRIBUCIÓN · ONBOARDING COMERCIAL</div>
        <h1 class="pp-title">Activa tu distribución</h1>
        <p class="pp-subtitle">
          Completa tu registro comercial y continúa a la pasarela para activar tu distribución con PATS.
        </p>

        <div class="pp-trust-row">
          <span class="pp-trust-chip"><?= htmlspecialchars($labelOrigen, ENT_QUOTES, 'UTF-8') ?></span>
          <span class="pp-trust-chip"><?= htmlspecialchars($nombreFranquicia ?: 'Franquicia no identificada', ENT_QUOTES, 'UTF-8') ?></span>
          <span class="pp-trust-chip"><?= htmlspecialchars($estadoNombre . ($zona ? ' · ' . $zona : ''), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
      </div>
    </section>

    <section class="pp-shell">
      <div class="pp-shell__head">
        <div class="pp-headline">Distribución PATS</div>
        <h2 class="pp-shell__title">Registro comercial y esquema de pago</h2>
        <p class="pp-shell__sub">
          <?= htmlspecialchars($descOrigen, ENT_QUOTES, 'UTF-8') ?>
        </p>
      </div>

      <div class="pp-steps-wrap">
        <div class="pp-steps" id="ppSteps">
          <button type="button" class="pp-step is-active" data-step="1"><span class="pp-step__num">1</span><span class="pp-step__label">Acceso</span></button>
          <button type="button" class="pp-step" data-step="2"><span class="pp-step__num">2</span><span class="pp-step__label">General</span></button>
          <button type="button" class="pp-step" data-step="3"><span class="pp-step__num">3</span><span class="pp-step__label">Legal</span></button>
          <button type="button" class="pp-step" data-step="4"><span class="pp-step__num">4</span><span class="pp-step__label">Contacto</span></button>
          <button type="button" class="pp-step" data-step="5"><span class="pp-step__num">5</span><span class="pp-step__label">Finanzas</span></button>
          <button type="button" class="pp-step" data-step="6"><span class="pp-step__num">6</span><span class="pp-step__label">Confirmación</span></button>
        </div>
      </div>

      <form id="frmPagoDistribucion" class="pp-body" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="public_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="actor_tipo_publico" value="<?= htmlspecialchars($actorTipo, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="id_distribuidor_origen" value="<?= (int)($ctx['id_distribuidor'] ?? 0) ?>">
        <input type="hidden" name="id_gestor_origen" value="<?= (int)($ctx['id_gestor'] ?? 0) ?>">
        <input type="hidden" name="id_franquicia" value="<?= (int)($ctx['id_franquicia'] ?? 0) ?>">
        <input type="hidden" name="public_checkout_token" value="<?= htmlspecialchars((string)($ctx['public_checkout_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

        <section class="pp-panel is-active" data-step-panel="1">
          <div class="pp-block">
            <h3 class="pp-block__title">Contexto del enlace</h3>
            <div class="pp-note">
              Este link público está vinculado a una estructura comercial válida dentro de PATS. Revisa el contexto antes de continuar.
            </div>

            <div class="pp-identity-pills">
              <span class="pp-pill">Origen: <?= htmlspecialchars($labelOrigen, ENT_QUOTES, 'UTF-8') ?></span>
              <span class="pp-pill">Actor: <?= htmlspecialchars($nombreActor ?: '-', ENT_QUOTES, 'UTF-8') ?></span>
              <span class="pp-pill">Franquicia: <?= htmlspecialchars($nombreFranquicia ?: '-', ENT_QUOTES, 'UTF-8') ?></span>
              <span class="pp-pill">Región: <?= htmlspecialchars($estadoNombre ?: '-', ENT_QUOTES, 'UTF-8') ?></span>
              <span class="pp-pill">Zona: <?= htmlspecialchars($zona ?: '-', ENT_QUOTES, 'UTF-8') ?></span>
              <span class="pp-pill">Unidad: <?= htmlspecialchars($unidad ?: '-', ENT_QUOTES, 'UTF-8') ?></span>
            </div>

            <div class="pp-fields" style="margin-top:16px;">
              <div class="pp-field">
                <label>País</label>
                <input type="text" name="pais" value="<?= htmlspecialchars($pais, ENT_QUOTES, 'UTF-8') ?>" readonly>
              </div>
              <div class="pp-field">
                <label>Región</label>
                <input type="text" name="region" value="<?= htmlspecialchars($region, ENT_QUOTES, 'UTF-8') ?>" readonly>
              </div>
              <div class="pp-field">
                <label>Zona</label>
                <input type="text" name="zona" value="<?= htmlspecialchars($zona, ENT_QUOTES, 'UTF-8') ?>" readonly>
              </div>
              <div class="pp-field">
                <label>Unidad</label>
                <input type="text" name="unidad" value="<?= htmlspecialchars($unidad, ENT_QUOTES, 'UTF-8') ?>" readonly>
              </div>
            </div>
          </div>
        </section>

        <section class="pp-panel" data-step-panel="2">
          <div class="pp-block">
            <h3 class="pp-block__title">Información general del distribuidor</h3>

            <div class="pp-fields">
              <div class="pp-field">
                <label for="nombre">Nombre del distribuidor</label>
                <input type="text" id="nombre" name="nombre" placeholder="Nombre completo o comercial" required>
              </div>

              <div class="pp-field">
                <label for="razon_social">Razón social</label>
                <input type="text" id="razon_social" name="razon_social" placeholder="Razón social si aplica">
              </div>

              <div class="pp-field full">
                <label for="direccion">Dirección</label>
                <textarea id="direccion" name="direccion" placeholder="Dirección operativa o fiscal"></textarea>
              </div>
            </div>
          </div>
        </section>

        <section class="pp-panel" data-step-panel="3">
          <div class="pp-block">
            <h3 class="pp-block__title">Documentación legal</h3>
            <div class="pp-note">
              Carga los documentos básicos para alta comercial. Puedes usar PDF o imagen.
            </div>

            <div class="pp-stack" style="margin-top:16px;">
              <div class="pp-mini-card">
                <div class="pp-mini-card__head">INE</div>
                <div class="pp-mini-card__body">
                  <label class="pp-file">
                    <input type="file" id="doc_ine" name="doc_ine" accept=".pdf,.png,.jpg,.jpeg,.webp" required>
                    <span class="pp-file__btn">Seleccionar archivo</span>
                    <span class="pp-file__text">Ningún archivo seleccionado</span>
                  </label>
                </div>
              </div>

              <div class="pp-mini-card">
                <div class="pp-mini-card__head">Comprobante de domicilio</div>
                <div class="pp-mini-card__body">
                  <label class="pp-file">
                    <input type="file" id="doc_domicilio" name="doc_domicilio" accept=".pdf,.png,.jpg,.jpeg,.webp" required>
                    <span class="pp-file__btn">Seleccionar archivo</span>
                    <span class="pp-file__text">Ningún archivo seleccionado</span>
                  </label>
                </div>
              </div>

              <div class="pp-mini-card">
                <div class="pp-mini-card__head">Cédula fiscal</div>
                <div class="pp-mini-card__body">
                  <label class="pp-file">
                    <input type="file" id="doc_cedula" name="doc_cedula" accept=".pdf,.png,.jpg,.jpeg,.webp" required>
                    <span class="pp-file__btn">Seleccionar archivo</span>
                    <span class="pp-file__text">Ningún archivo seleccionado</span>
                  </label>
                </div>
              </div>
            </div>
          </div>
        </section>

        <section class="pp-panel" data-step-panel="4">
          <div class="pp-block">
            <h3 class="pp-block__title">Datos de contacto</h3>

            <div class="pp-fields">
              <div class="pp-field">
                <label for="telefono">Teléfono</label>
                <input type="text" id="telefono" name="telefono" placeholder="10 dígitos" maxlength="10" required>
              </div>

              <div class="pp-field">
                <label for="correo">Correo</label>
                <input type="email" id="correo" name="correo" placeholder="correo@dominio.com" required>
              </div>

              <div class="pp-field">
                <label for="rfc">RFC</label>
                <input type="text" id="rfc" name="rfc" placeholder="RFC">
              </div>

              <div class="pp-field">
                <label for="clabe">CLABE</label>
                <input type="text" id="clabe" name="clabe" placeholder="18 dígitos" maxlength="18">
              </div>

              <div class="pp-field">
                <label for="banco">Banco</label>
                <input type="text" id="banco" name="banco" placeholder="Banco">
              </div>

              <div class="pp-field">
                <label for="numero_cuenta">Número de cuenta</label>
                <input type="text" id="numero_cuenta" name="numero_cuenta" placeholder="Número de cuenta">
              </div>

              <div class="pp-field">
                <label for="titular_cuenta">Titular de la cuenta</label>
                <input type="text" id="titular_cuenta" name="titular_cuenta" placeholder="Titular bancario">
              </div>

              <div class="pp-field full">
                <label>Carátula bancaria</label>
                <label class="pp-file">
                  <input type="file" id="doc_caratula_bancaria" name="doc_caratula_bancaria" accept=".pdf,.png,.jpg,.jpeg,.webp" required>
                  <span class="pp-file__btn">Seleccionar archivo</span>
                  <span class="pp-file__text">Ningún archivo seleccionado</span>
                </label>
              </div>
            </div>
          </div>
        </section>

        <section class="pp-panel" data-step-panel="5">
          <div class="pp-block">
            <h3 class="pp-block__title">Condiciones financieras</h3>
            <div class="pp-note">
              El valor de distribución se configura según el catálogo vigente. Puedes liquidar de contado o diferir según el esquema autorizado.
            </div>

            <div class="pp-fields" style="margin-top:16px;">
              <div class="pp-field">
                <label for="modalidad_pago">Modalidad de pago</label>
                <select id="modalidad_pago" name="modalidad_pago" required>
                  <option value="CONTADO">Contado</option>
                  <option value="ENGANCHE_DIFERIDO">Enganche + diferido</option>
                  <option value="DIFERIDO">Diferido</option>
                </select>
              </div>

              <div class="pp-field">
                <label for="valor_total">Valor total</label>
                <input type="number" id="valor_total" name="valor_total" value="<?= number_format($precioDistribucion, 2, '.', '') ?>" readonly>
              </div>

              <div class="pp-field">
                <label for="enganche">Enganche</label>
                <input type="number" id="enganche" name="enganche" value="0" min="0" step="0.01">
              </div>

              <div class="pp-field">
                <label for="saldo_financiado">Saldo financiado</label>
                <input type="number" id="saldo_financiado" name="saldo_financiado" value="<?= number_format($precioDistribucion, 2, '.', '') ?>" readonly>
              </div>

              <div class="pp-field">
                <label for="plazo_meses">Plazo (meses)</label>
                <input type="number" id="plazo_meses" name="plazo_meses" value="0" min="0" step="1">
              </div>

              <div class="pp-field">
                <label for="periodicidad">Periodicidad</label>
                <select id="periodicidad" name="periodicidad">
                  <option value="MENSUAL" selected>Mensual</option>
                  <option value="QUINCENAL">Quincenal</option>
                  <option value="SEMANAL">Semanal</option>
                  <option value="UNICA">Única</option>
                </select>
              </div>

              <div class="pp-field">
                <label for="fecha_inicio">Fecha de inicio</label>
                <input type="date" id="fecha_inicio" name="fecha_inicio" required>
              </div>

              <div class="pp-field">
                <label for="fecha_primer_vencimiento">Primer vencimiento</label>
                <input type="date" id="fecha_primer_vencimiento" name="fecha_primer_vencimiento">
              </div>
            </div>

            <div class="pp-block" id="planPreviewCard" style="padding:16px 18px;margin-top:16px;" hidden>
              <h3 class="pp-block__title" style="margin-bottom:12px;">Vista previa de parcialidades</h3>
              <div id="planPreviewBody"></div>
            </div>
          </div>
        </section>

        <section class="pp-panel" data-step-panel="6">
          <div class="pp-block">
            <h3 class="pp-block__title">Confirmación final</h3>

            <div class="pp-note">
              Estás a punto de generar la orden de distribución y continuar a la pasarela de pago.
              Verifica los datos comerciales y financieros antes de continuar.
            </div>

            <label class="pp-check" style="margin-top:16px;">
              <input type="checkbox" id="acepta_confirmacion" name="acepta_confirmacion">
              <span>Confirmo que la información capturada es correcta y autorizo la generación de la orden de distribución.</span>
            </label>

            <div class="pp-block" style="padding:16px 18px; margin-top:16px;">
              <h3 class="pp-block__title" style="margin-bottom:12px;">Resumen final</h3>
              <div class="pp-summary">
                <div class="pp-summary__item"><span>Franquicia</span><strong id="sumFranquicia"><?= htmlspecialchars($nombreFranquicia ?: '-', ENT_QUOTES, 'UTF-8') ?></strong></div>
                <div class="pp-summary__item"><span>Origen</span><strong id="sumOrigen"><?= htmlspecialchars($labelOrigen, ENT_QUOTES, 'UTF-8') ?></strong></div>
                <div class="pp-summary__item"><span>Solicitante</span><strong id="sumNombre">-</strong></div>
                <div class="pp-summary__item"><span>Correo</span><strong id="sumCorreo">-</strong></div>
                <div class="pp-summary__item"><span>Teléfono</span><strong id="sumTelefono">-</strong></div>
                <div class="pp-summary__item"><span>Modalidad</span><strong id="sumModalidad">-</strong></div>
                <div class="pp-summary__item"><span>Valor total</span><strong id="sumValorTotal"><?= '$' . number_format($precioDistribucion, 2) ?></strong></div>
                <div class="pp-summary__item"><span>Saldo financiado</span><strong id="sumSaldo"><?= '$' . number_format($precioDistribucion, 2) ?></strong></div>
              </div>
            </div>
          </div>
        </section>

        <div class="pp-actions">
          <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button type="button" class="pp-btn pp-btn--ghost" id="btnWizardPrev">Anterior</button>
            <button type="button" class="pp-btn pp-btn--soft" id="btnWizardNext">Siguiente</button>
          </div>

          <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button type="button" class="pp-btn pp-btn--ghost" id="btnPreviewPlan" hidden>Vista previa de parcialidades</button>
            <button type="submit" class="pp-btn pp-btn--primary" id="btnWizardSubmit" hidden>Continuar a pago</button>
          </div>
        </div>
      </form>
    </section>
  </div>

<script>
(() => {
  "use strict";

  const $ = (s) => document.querySelector(s);
  const $$ = (s) => Array.from(document.querySelectorAll(s));

  let currentStep = 1;
  const totalSteps = 6;

  function parseNum(v) {
    const n = Number(v || 0);
    return Number.isFinite(n) ? n : 0;
  }

  function onlyDigits(v) {
    return String(v || "").replace(/\D+/g, "");
  }

  function validPhone(v) {
    return onlyDigits(v).length === 10;
  }

  function validEmail(v) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(v || "").trim());
  }

  function validClabe(v) {
    const clabe = onlyDigits(v);
    if (clabe.length !== 18) return false;
    const factors = [3, 7, 1];
    let sum = 0;
    for (let i = 0; i < 17; i++) {
      const digit = Number(clabe[i]);
      const factor = factors[i % 3];
      sum += ((digit * factor) % 10);
    }
    const control = (10 - (sum % 10)) % 10;
    return control === Number(clabe[17]);
  }

  function money(v) {
    return new Intl.NumberFormat("es-MX", {
      style: "currency",
      currency: "MXN",
      maximumFractionDigits: 2
    }).format(Number(v || 0));
  }

  function toast(msg, type = "info") {
    let host = document.getElementById("ppToastHost");
    if (!host) {
      host = document.createElement("div");
      host.id = "ppToastHost";
      document.body.appendChild(host);
    }

    const item = document.createElement("div");
    item.textContent = msg;
    item.style.minWidth = "220px";
    item.style.maxWidth = "420px";
    item.style.padding = "10px 12px";
    item.style.borderRadius = "12px";
    item.style.color = "#fff";
    item.style.fontSize = "13px";
    item.style.lineHeight = "1.25";
    item.style.fontWeight = "700";
    item.style.letterSpacing = ".1px";
    item.style.boxShadow = "0 12px 28px rgba(6,12,28,.28)";
    item.style.border = "1px solid rgba(255,255,255,.10)";
    item.style.pointerEvents = "auto";
    item.style.backdropFilter = "blur(10px)";
    item.style.background =
      type === "error"
        ? "linear-gradient(135deg, rgba(55,16,24,.96), rgba(108,26,48,.96))"
        : type === "success"
        ? "linear-gradient(135deg, rgba(10,34,54,.96), rgba(18,92,116,.96))"
        : "linear-gradient(135deg, rgba(12,21,46,.96), rgba(36,28,74,.96))";

    host.appendChild(item);

    setTimeout(() => {
      item.style.transition = "opacity .22s ease, transform .22s ease";
      item.style.opacity = "0";
      item.style.transform = "translateY(-4px)";
      setTimeout(() => item.remove(), 240);
    }, 2400);
  }

  function bindCustomFileInputs() {
    $$(".pp-file input[type='file']").forEach((input) => {
      if (input.dataset.boundFile === "1") return;
      input.dataset.boundFile = "1";

      input.addEventListener("change", () => {
        const wrap = input.closest(".pp-file");
        const text = wrap?.querySelector(".pp-file__text");
        if (!text) return;

        const files = input.files;
        if (files && files.length > 0) {
          text.textContent = files.length === 1
            ? files[0].name
            : `${files.length} archivos seleccionados`;
        } else {
          text.textContent = "Ningún archivo seleccionado";
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
    if (preview) preview.hidden = currentStep !== 5;
  }

  function goStep(step) {
    currentStep = Math.max(1, Math.min(totalSteps, step));
    syncWizard();
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  function syncSaldo() {
    const total = parseNum($("#valor_total")?.value);
    const enganche = parseNum($("#enganche")?.value);
    const saldo = Math.max(0, total - enganche);
    if ($("#saldo_financiado")) {
      $("#saldo_financiado").value = saldo.toFixed(2);
    }
    syncSummary();
  }

  function syncFinancialMode() {
    const modalidad = ($("#modalidad_pago")?.value || "").toUpperCase();
    const isContado = modalidad === "CONTADO";

    const plazo = $("#plazo_meses");
    const periodicidad = $("#periodicidad");
    const primerVenc = $("#fecha_primer_vencimiento");

    if (isContado) {
      if ($("#enganche")) $("#enganche").value = "0";
      if (plazo) {
        plazo.value = "0";
        plazo.disabled = true;
      }
      if (periodicidad) periodicidad.disabled = true;
      if (primerVenc) {
        primerVenc.value = "";
        primerVenc.disabled = true;
      }
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
    const periodicidad = $("#periodicidad")?.value || "MENSUAL";
    const primerVenc = $("#fecha_primer_vencimiento")?.value || $("#fecha_inicio")?.value;

    const wrap = $("#planPreviewBody");
    const card = $("#planPreviewCard");
    if (!wrap || !card) return;

    if (!primerVenc) {
      wrap.innerHTML = `<div class="pp-note">Captura fecha de inicio o primer vencimiento.</div>`;
      card.hidden = false;
      return;
    }

    if (modalidad === "CONTADO" || saldo <= 0 || plazo <= 0) {
      wrap.innerHTML = `
        <div class="pp-summary">
          <div class="pp-summary__item"><span>Modalidad</span><strong>${modalidad}</strong></div>
          <div class="pp-summary__item"><span>Saldo financiado</span><strong>${money(saldo)}</strong></div>
          <div class="pp-summary__item"><span>Parcialidades</span><strong>No aplica</strong></div>
        </div>
      `;
      card.hidden = false;
      return;
    }

    let fechas = [];
    const base = new Date(primerVenc + "T00:00:00");

    for (let i = 0; i < plazo; i++) {
      const d = new Date(base);
      if (periodicidad === "SEMANAL") d.setDate(base.getDate() + (i * 7));
      else if (periodicidad === "QUINCENAL") d.setDate(base.getDate() + (i * 15));
      else if (periodicidad === "UNICA") d.setDate(base.getDate());
      else d.setMonth(base.getMonth() + i);

      const y = d.getFullYear();
      const m = String(d.getMonth() + 1).padStart(2, "0");
      const day = String(d.getDate()).padStart(2, "0");
      fechas.push(`${y}-${m}-${day}`);
      if (periodicidad === "UNICA") break;
    }

    const n = fechas.length || 1;
    const baseMonto = Math.floor((saldo / n) * 100) / 100;
    let acumulado = 0;
    let html = `<div class="pp-stack">`;

    fechas.forEach((fecha, idx) => {
      let monto = baseMonto;
      acumulado += monto;
      if (idx === fechas.length - 1) {
        monto += +(saldo - acumulado).toFixed(2);
      }
      html += `
        <div class="pp-mini-card">
          <div class="pp-mini-card__body" style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;">
            <strong>Parcialidad ${idx + 1}</strong>
            <span>${fecha}</span>
            <strong>${money(monto)}</strong>
          </div>
        </div>
      `;
    });

    html += `</div>`;
    wrap.innerHTML = html;
    card.hidden = false;
  }

  function syncSummary() {
    const nombre = ($("#nombre")?.value || "").trim();
    const correo = ($("#correo")?.value || "").trim();
    const telefono = ($("#telefono")?.value || "").trim();
    const modalidad = ($("#modalidad_pago")?.value || "").trim();
    const total = parseNum($("#valor_total")?.value);
    const saldo = parseNum($("#saldo_financiado")?.value);

    if ($("#sumNombre")) $("#sumNombre").textContent = nombre || "-";
    if ($("#sumCorreo")) $("#sumCorreo").textContent = correo || "-";
    if ($("#sumTelefono")) $("#sumTelefono").textContent = telefono || "-";
    if ($("#sumModalidad")) $("#sumModalidad").textContent = modalidad || "-";
    if ($("#sumValorTotal")) $("#sumValorTotal").textContent = money(total || 0);
    if ($("#sumSaldo")) $("#sumSaldo").textContent = money(saldo || 0);
  }

  function validateStep1() {
    return true;
  }

  function validateStep2() {
    const nombre = ($("#nombre")?.value || "").trim();
    if (!nombre) {
      toast("Debes capturar el nombre del distribuidor.", "error");
      $("#nombre")?.focus();
      return false;
    }
    return true;
  }

  function validateStep3() {
    const ine = $("#doc_ine");
    const dom = $("#doc_domicilio");
    const ced = $("#doc_cedula");

    if (!ine?.files?.length) {
      toast("Debes cargar el INE.", "error");
      return false;
    }
    if (!dom?.files?.length) {
      toast("Debes cargar el comprobante de domicilio.", "error");
      return false;
    }
    if (!ced?.files?.length) {
      toast("Debes cargar la cédula fiscal.", "error");
      return false;
    }

    return true;
  }

  function validateStep4() {
    const tel = $("#telefono")?.value || "";
    const correo = ($("#correo")?.value || "").trim();
    const clabe = $("#clabe")?.value || "";
    const caratula = $("#doc_caratula_bancaria");

    if (!validPhone(tel)) {
      toast("El teléfono debe tener 10 dígitos.", "error");
      $("#telefono")?.focus();
      return false;
    }

    if (!validEmail(correo)) {
      toast("El correo no es válido.", "error");
      $("#correo")?.focus();
      return false;
    }

    if (clabe && !validClabe(clabe)) {
      toast("La CLABE no es válida.", "error");
      $("#clabe")?.focus();
      return false;
    }

    if (!caratula?.files?.length) {
      toast("Debes cargar la carátula bancaria.", "error");
      return false;
    }

    return true;
  }

  function validateStep5() {
    const modalidad = ($("#modalidad_pago")?.value || "").trim();
    const fechaInicio = ($("#fecha_inicio")?.value || "").trim();

    if (!modalidad) {
      toast("Debes seleccionar la modalidad de pago.", "error");
      return false;
    }

    if (!fechaInicio) {
      toast("Debes capturar la fecha de inicio.", "error");
      $("#fecha_inicio")?.focus();
      return false;
    }

    return true;
  }

  function validateStep6() {
    if (!$("#acepta_confirmacion")?.checked) {
      toast("Debes confirmar la información para continuar.", "error");
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
    if (currentStep === 6) return validateStep6();
    return true;
  }

  function bindWizard() {
    $("#btnWizardPrev")?.addEventListener("click", () => {
      if (currentStep > 1) goStep(currentStep - 1);
    });

    $("#btnWizardNext")?.addEventListener("click", () => {
      if (!validateCurrentStep()) return;
      if (currentStep < totalSteps) goStep(currentStep + 1);
    });

    $$("[data-step]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const target = Number(btn.dataset.step || 1);
        if (target < currentStep) {
          goStep(target);
        }
      });
    });
  }

  async function submitForm(ev) {
    ev.preventDefault();
    syncSaldo();
    syncSummary();

    if (
      !validateStep1() ||
      !validateStep2() ||
      !validateStep3() ||
      !validateStep4() ||
      !validateStep5() ||
      !validateStep6()
    ) {
      return;
    }

    const form = ev.currentTarget;
    const fd = new FormData(form);

    const btn = $("#btnWizardSubmit");
    if (btn) {
      btn.disabled = true;
      btn.dataset.oldText = btn.textContent || "";
      btn.textContent = "Generando orden...";
    }

    try {
      const res = await fetch("endpoints/public_checkout_generar_orden_distribucion.php", {
        method: "POST",
        body: fd,
        headers: { "X-Requested-With": "XMLHttpRequest" }
      });

      const text = await res.text();
      let data = {};

      try {
        data = text ? JSON.parse(text) : {};
      } catch {
        console.error("STATUS:", res.status);
        console.error("RAW RESPONSE:", text);
        toast("El endpoint devolvió algo que no es JSON. Revisa consola.", "error");

        const win = window.open("", "_blank");
        if (win) {
          win.document.write("<pre>" + String(text)
            .replaceAll("&", "&amp;")
            .replaceAll("<", "&lt;")
            .replaceAll(">", "&gt;") + "</pre>");
        }
        return;
      }

      if (!res.ok || data.ok === false) {
        toast(data.error || "No fue posible generar la orden de distribución.", "error");
        console.error("public_checkout_generar_orden_distribucion error =>", data);
        return;
      }

      toast("Orden generada correctamente. Redirigiendo a pago...", "success");

      if (data.checkout_url) {
        setTimeout(() => {
          window.location.href = data.checkout_url;
        }, 700);
      }
    } catch (err) {
      console.error(err);
      toast("Error de red o servidor al generar la orden.", "error");
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.textContent = btn.dataset.oldText || "Continuar a pago";
      }
    }
  }

  document.addEventListener("DOMContentLoaded", () => {
    bindCustomFileInputs();
    bindWizard();
    syncFinancialMode();
    syncSaldo();
    syncWizard();
    syncSummary();

    $("#frmPagoDistribucion")?.addEventListener("submit", submitForm);

    $("#telefono")?.addEventListener("input", (e) => {
      e.target.value = onlyDigits(e.target.value).slice(0, 10);
      syncSummary();
    });

    $("#clabe")?.addEventListener("input", (e) => {
      e.target.value = onlyDigits(e.target.value).slice(0, 18);
    });

    $("#nombre")?.addEventListener("input", syncSummary);
    $("#correo")?.addEventListener("input", syncSummary);

    $("#modalidad_pago")?.addEventListener("change", () => {
      syncFinancialMode();
      syncSummary();
    });

    $("#enganche")?.addEventListener("input", syncSaldo);
    $("#plazo_meses")?.addEventListener("input", syncSummary);
    $("#periodicidad")?.addEventListener("change", syncSummary);
    $("#fecha_inicio")?.addEventListener("change", syncSummary);
    $("#fecha_primer_vencimiento")?.addEventListener("change", syncSummary);

    $("#btnPreviewPlan")?.addEventListener("click", buildPlanPreview);
  });
})();
</script>
</body>
</html>