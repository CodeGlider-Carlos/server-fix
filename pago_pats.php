<?php
/*
ez/pats/pago_pats.php
*/
session_start();
require_once '../../varSQL/bd_pats.php';
require_once '../../varSQL/var_pats.php';
require_once __DIR__ . '/public_checkout_resolver.php';
$ver = time();
$cx = $conexionMod ?? $db_all2 ?? $db_all ?? null;

if (!$cx || !($cx instanceof mysqli)) {
  http_response_code(500);
  die('No hay conexión mysqli disponible');
}

function h($v): string {
  return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function pats_estado_nombre_desde_acronimo(string $acr): string {
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


function pats_estados_mx_map(): array {
  return [
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
$montoAnual = 9600;
$montoMensual = 800;

$estadoAcronimoInicial = strtoupper(trim((string)($ctx['region'] ?? '')));
$estadoNombreInicial = pats_estado_nombre_desde_acronimo($estadoAcronimoInicial);
$estadosMx = pats_estados_mx_map();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PATS · Registro y pago</title>
  <link rel="icon" type="image/x-icon" href="../../img/ez.ico" />
<link rel="stylesheet" href="css/pago_pats_premium.css?v=<?= $ver ?>">
</head>
<body>
  <div class="pp-wrap">

  <section class="pp-hero">
  <div class="pp-hero__inner">
  
    <h1 class="pp-title">Activa tu Pasaporte</h1>
    <p class="pp-subtitle">
      Registro guiado, firma digital y pago seguro en un proceso claro, protegido y rápido.
    </p>

  
  </div>
</section>

    <section class="pp-shell">
     <div class="pp-shell__head">
        <div class="pp-headline">PATS</div>
        <h2 class="pp-shell__title">Completa tu registro paso a paso</h2>
        <p class="pp-shell__sub">
            Captura tus datos, valida tu identidad, firma tu contrato y continúa a pago seguro.
        </p>
        </div>

      <div class="pp-steps-wrap">
        <div class="pp-steps" id="ppSteps">
            <button type="button" class="pp-step is-active" data-step="1"><span class="pp-step__num">1</span><span class="pp-step__label">Acceso</span></button>
            <button type="button" class="pp-step" data-step="2"><span class="pp-step__num">2</span><span class="pp-step__label">Personales</span></button>
            <button type="button" class="pp-step" data-step="3"><span class="pp-step__num">3</span><span class="pp-step__label">Domicilio</span></button>
            <button type="button" class="pp-step" data-step="4"><span class="pp-step__num">4</span><span class="pp-step__label">Documentos</span></button>
            <button type="button" class="pp-step" data-step="5"><span class="pp-step__num">5</span><span class="pp-step__label">Fotografía</span></button>
            <button type="button" class="pp-step" data-step="6"><span class="pp-step__num">6</span><span class="pp-step__label">Contrato y pago</span></button>
        </div>
        </div>
      <div class="pp-body">
        <form id="frmPagoPatsPublico" novalidate>

          <!-- PASO 1 -->
          <section class="pp-panel is-active" data-step-panel="1">
            <div class="pp-block">
              <h3 class="pp-block__title">Identificación inicial</h3>
              <div class="pp-note">
                Ingresa tu correo y teléfono para iniciar el proceso. 
              </div>

              <div class="pp-fields" style="margin-top:16px;">
                <div class="pp-field">
                  <label for="login_correo">Correo electrónico</label>
                  <input type="email" id="login_correo" required>
                </div>
                <div class="pp-field">
                  <label for="login_telefono">Teléfono</label>
                  <input type="text" id="login_telefono" maxlength="10" inputmode="numeric" required>
                </div>
              </div>
            </div>


          </section>

          <!-- PASO 2 -->
          <section class="pp-panel" data-step-panel="2">
            <div class="pp-block">
              <h3 class="pp-block__title">Datos personales</h3>

              <div class="pp-identity-pills">
                <div class="pp-pill" id="pillCorreo">Correo: -</div>
                <div class="pp-pill" id="pillTelefono">Teléfono: -</div>
              </div>

              <div class="pp-fields" style="margin-top:16px;">
                <div class="pp-field full">
                  <label for="nombre_usuario">Nombre(s)</label>
                  <input type="text" id="nombre_usuario" name="nombre_usuario" required>
                </div>

                <div class="pp-field">
                  <label for="apellido_pa">Apellido paterno</label>
                  <input type="text" id="apellido_pa" name="apellido_pa" required>
                </div>

                <div class="pp-field">
                  <label for="apellido_ma">Apellido materno</label>
                  <input type="text" id="apellido_ma" name="apellido_ma">
                </div>

                <div class="pp-field">
                  <label for="curp_usuario">CURP</label>
                  <input type="text" id="curp_usuario" name="curp_usuario" maxlength="18" required>
                </div>

                <div class="pp-field">
                  <label for="fecha_nacimiento">Fecha de nacimiento</label>
                  <input type="date" id="fecha_nacimiento" name="fecha_nacimiento" required>
                </div>

                <div class="pp-field">
                  <label for="tipo_cliente">Tipo de cliente</label>
                  <select id="tipo_cliente" name="tipo_cliente" required>
                    <option value="privado">Privado</option>
                    <option value="empresa">Empresa</option>
                  </select>
                </div>

                <div class="pp-field full pp-hidden" id="wrapNombreEmpresa">
                  <label for="nombre_empresa">Nombre de empresa</label>
                  <input type="text" id="nombre_empresa" name="nombre_empresa">
                </div>
              </div>
            </div>

            <div class="pp-block pp-hidden" id="adultosMayoresWrap">
              <h3 class="pp-block__title">Acompañantes obligatorios</h3>
              <div class="pp-note">
                Por la edad capturada, debes registrar 2 usuarios adicionales acompañantes.
              </div>

              <div class="pp-stack">
                <article class="pp-mini-card">
                  <div class="pp-mini-card__head">Acompañante 1</div>
                  <div class="pp-mini-card__body">
                    <div class="pp-fields">
                      <div class="pp-field full">
                        <label for="ac1_nombre">Nombre(s)</label>
                        <input type="text" id="ac1_nombre" name="ac1_nombre">
                      </div>
                      <div class="pp-field">
                        <label for="ac1_apellido_pa">Apellido paterno</label>
                        <input type="text" id="ac1_apellido_pa" name="ac1_apellido_pa">
                      </div>
                      <div class="pp-field">
                        <label for="ac1_apellido_ma">Apellido materno</label>
                        <input type="text" id="ac1_apellido_ma" name="ac1_apellido_ma">
                      </div>
                      <div class="pp-field">
                        <label for="ac1_curp">CURP</label>
                        <input type="text" id="ac1_curp" name="ac1_curp" maxlength="18">
                      </div>
                      <div class="pp-field">
                        <label for="ac1_fecha_nacimiento">Fecha de nacimiento</label>
                        <input type="date" id="ac1_fecha_nacimiento" name="ac1_fecha_nacimiento">
                      </div>
                    </div>
                  </div>
                </article>

                <article class="pp-mini-card">
                  <div class="pp-mini-card__head">Acompañante 2</div>
                  <div class="pp-mini-card__body">
                    <div class="pp-fields">
                      <div class="pp-field full">
                        <label for="ac2_nombre">Nombre(s)</label>
                        <input type="text" id="ac2_nombre" name="ac2_nombre">
                      </div>
                      <div class="pp-field">
                        <label for="ac2_apellido_pa">Apellido paterno</label>
                        <input type="text" id="ac2_apellido_pa" name="ac2_apellido_pa">
                      </div>
                      <div class="pp-field">
                        <label for="ac2_apellido_ma">Apellido materno</label>
                        <input type="text" id="ac2_apellido_ma" name="ac2_apellido_ma">
                      </div>
                      <div class="pp-field">
                        <label for="ac2_curp">CURP</label>
                        <input type="text" id="ac2_curp" name="ac2_curp" maxlength="18">
                      </div>
                      <div class="pp-field">
                        <label for="ac2_fecha_nacimiento">Fecha de nacimiento</label>
                        <input type="date" id="ac2_fecha_nacimiento" name="ac2_fecha_nacimiento">
                      </div>
                    </div>
                  </div>
                </article>
              </div>
            </div>
          </section>

          <!-- PASO 3 -->
          <section class="pp-panel" data-step-panel="3">
            <div class="pp-block">
              <h3 class="pp-block__title">Domicilio</h3>
              <div class="pp-fields">
                <div class="pp-field full">
                  <label for="dom_calle">Calle</label>
                  <input type="text" id="dom_calle" name="dom_calle" required>
                </div>

                <div class="pp-field">
                  <label for="dom_num_ext">Número exterior</label>
                  <input type="text" id="dom_num_ext" name="dom_num_ext" required>
                </div>

                <div class="pp-field">
                  <label for="dom_num_int">Número interior</label>
                  <input type="text" id="dom_num_int" name="dom_num_int">
                </div>

                <div class="pp-field full">
                  <label for="dom_colonia">Colonia</label>
                  <input type="text" id="dom_colonia" name="dom_colonia" required>
                </div>

                <div class="pp-field">
                  <label for="dom_cp">Código postal</label>
                  <input type="text" id="dom_cp" name="dom_cp" maxlength="5" inputmode="numeric" required>
                </div>

                <div class="pp-field">
                  <label for="dom_municipio">Ciudad / Municipio</label>
                  <input type="text" id="dom_municipio" name="dom_municipio" required>
                </div>

             <div class="pp-field">
                <label for="dom_estado">Estado / Región</label>
                <input type="text" id="dom_estado" name="dom_estado" value="<?= h($estadoNombreInicial) ?>" required>
                </div>

            <div class="pp-field">
            <label for="dom_estado_acronimo">Estado</label>
            <select id="dom_estado_acronimo" name="dom_estado_acronimo" required>
            <?php foreach ($estadosMx as $acr => $nombre): ?>
                <option value="<?= h($acr) ?>" <?= $estadoAcronimoInicial === $acr ? 'selected' : '' ?>>
                <?= h($nombre) ?>
                </option>
            <?php endforeach; ?>
            </select>
            </div>

              <div class="pp-field full">
                <label for="dom_pais">País</label>
                <input type="text" id="dom_pais" name="dom_pais" value="<?= h($ctx['pais'] ?: 'México') ?>" required>
              </div>
              </div>
            </div>
          </section>

          <!-- PASO 4 -->
          <section class="pp-panel" data-step-panel="4">
            <div class="pp-block">
              <h3 class="pp-block__title">Documentos obligatorios</h3>
              <div class="pp-fields">
                <div class="pp-field">
                  <label>INE frente</label>
                  <label class="pp-file">
                    <input type="file" id="doc_ine_frente" name="doc_ine_frente" accept=".pdf,.png,.jpg,.jpeg,.webp" required>
                    <span class="pp-file__btn">Seleccionar archivo</span>
                    <span class="pp-file__text">Ningún archivo seleccionado</span>
                  </label>
                </div>

                <div class="pp-field">
                  <label>INE reverso</label>
                  <label class="pp-file">
                    <input type="file" id="doc_ine_reverso" name="doc_ine_reverso" accept=".pdf,.png,.jpg,.jpeg,.webp" required>
                    <span class="pp-file__btn">Seleccionar archivo</span>
                    <span class="pp-file__text">Ningún archivo seleccionado</span>
                  </label>
                </div>

                <div class="pp-field full">
                  <label>CURP documento</label>
                  <label class="pp-file">
                    <input type="file" id="doc_curp" name="doc_curp" accept=".pdf,.png,.jpg,.jpeg,.webp" required>
                    <span class="pp-file__btn">Seleccionar archivo</span>
                    <span class="pp-file__text">Ningún archivo seleccionado</span>
                  </label>
                </div>
              </div>
            </div>
          </section>

          <!-- PASO 5 -->
          <section class="pp-panel" data-step-panel="5">
            <div class="pp-block">
              <h3 class="pp-block__title">Fotografía del afiliado</h3>
              <div class="pp-note">
                En escritorio puedes usar tu cámara para centrarte y tomar la fotografía. En móvil puedes abrir directamente la cámara del dispositivo.
              </div>

              <div class="pp-camera" style="margin-top:16px;">
                <div class="pp-camera__stage">
                  <video id="camVideo" autoplay playsinline muted class="pp-hidden"></video>
                  <canvas id="camCanvas" class="pp-hidden"></canvas>
                  <img id="camPreview" alt="Vista previa" class="pp-hidden">
                  <div class="pp-camera__guide"></div>
                </div>

                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                  <button type="button" class="pp-btn pp-btn--ghost" id="btnIniciarCamara">Iniciar cámara</button>
                  <button type="button" class="pp-btn pp-btn--soft" id="btnCapturarFoto">Capturar foto</button>
                  <label class="pp-btn pp-btn--ghost" style="display:inline-flex;align-items:center;">
                    Subir foto
                    <input type="file" id="foto_manual" accept="image/*" capture="environment" class="pp-hidden">
                  </label>
                </div>

                <input type="hidden" id="foto_base64" name="foto_base64">
              </div>
            </div>
          </section>

          <!-- PASO 6 -->
          <section class="pp-panel" data-step-panel="6">
            <div class="pp-block">
              <h3 class="pp-block__title">Contrato y aceptación</h3>

              <div class="pp-contract-wrap">
                <div class="pp-note">
                  Revisa el contrato ya llenado con tus datos. Después firma en pantalla y continúa a la pasarela.
                </div>

                <div class="pp-contract-box">
                  <div id="contractPreview" class="pp-contract-view"></div>
                </div>

                <div class="pp-signature">
                  <div class="pp-signature__top">
                    <div>
                      <strong style="display:block;color:#173567;">Firma del afiliado</strong>
                      <small style="color:#6a7ea8;">Firma con mouse o con tu dedo en pantalla.</small>
                    </div>
                    <button type="button" class="pp-btn pp-btn--ghost" id="btnLimpiarFirma">Limpiar firma</button>
                  </div>

                  <div class="pp-signature__canvas-wrap">
                    <canvas id="signaturePad"></canvas>
                  </div>
                </div>

                <label class="pp-check">
                  <input type="checkbox" id="acepta_contrato" name="acepta_contrato">
                  <span>He leído, entendido y acepto el contrato. También reconozco que mi firma dibujada forma parte de la evidencia contractual del proceso.</span>
                </label>

                
                <div class="pp-fields" style="margin-top:16px;">
                  <div class="pp-field">
                    <label for="frecuencia_pago_publica">Frecuencia de pago</label>
                    <select id="frecuencia_pago_publica" required>
                      <option value="MENSUAL" selected>Mensual</option>
                      <option value="ANUAL">Anual</option>
                    </select>
                  </div>

                  <div class="pp-field">
                    <label for="monto_visual_publico">Monto</label>
                    <input type="text" id="monto_visual_publico" value="$800" readonly>
                  </div>
                </div>


                <div class="pp-block" style="padding:16px 18px;">
                  <h3 class="pp-block__title" style="margin-bottom:12px;">Resumen final</h3>
                  <div class="pp-summary">
                    <div class="pp-summary__item">
                      <span>Correo</span>
                      <strong id="sumCorreo">-</strong>
                    </div>
                    <div class="pp-summary__item">
                      <span>Teléfono</span>
                      <strong id="sumTelefono">-</strong>
                    </div>
                    <div class="pp-summary__item">
                      <span>Frecuencia</span>
                      <strong id="sumFrecuencia">-</strong>
                    </div>
                    <div class="pp-summary__item">
                      <span>Monto</span>
                      <strong id="sumMonto">$0</strong>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </section>

          <input type="hidden" name="token_publico" value="<?= h($token) ?>">
          <input type="hidden" name="id_distribuidor" value="<?= (int)$ctx['id_distribuidor'] ?>">
          <input type="hidden" name="id_franquicia" value="<?= (int)$ctx['id_franquicia'] ?>">
          <input type="hidden" name="pais" value="<?= h($ctx['pais'] ?: 'México') ?>">
          <input type="hidden" name="region" value="<?= h($ctx['region']) ?>">
          <input type="hidden" name="zona" value="<?= h($ctx['zona']) ?>">
          <input type="hidden" name="unidad" value="<?= h($ctx['unidad']) ?>">
          <input type="hidden" name="tipo_origen" value="DISTRIBUIDOR">
          <input type="hidden" name="origen_checkout" value="PORTAL_PUBLICO">
          <input type="hidden" name="tipo_operacion" value="ALTA_PATS">
          <input type="hidden" name="moneda" value="MXN">
          <input type="hidden" name="id_tipo_precio" id="id_tipo_precio" value="2">
          <input type="hidden" name="frecuencia" id="frecuencia" value="MENSUAL">
          <input type="hidden" name="monto_orden" id="monto_orden" value="800">
          <input type="hidden" name="correo_usuario_pats" id="hidden_correo_usuario_pats" value="">
          <input type="hidden" name="telefono_usuario" id="hidden_telefono_usuario" value="">
          <input type="hidden" name="firma_base64" id="firma_base64" value="">

          <div class="pp-actions">
            <button type="button" class="pp-btn pp-btn--ghost" id="btnPrev">Atrás</button>

            <div style="display:flex;gap:10px;flex-wrap:wrap;">
              <button type="button" class="pp-btn pp-btn--soft" id="btnNext">Siguiente</button>
             <button type="submit" class="pp-btn pp-btn--primary pp-hidden" id="btnSubmitPago">
                Continuar a pago seguro
                </button>
            </div>
          </div>
        </form>
      </div>
    </section>
  </div>

  <div id="ppToastHost"></div>

  <div id="ppModal" class="pp-modal pp-hidden">
    <div class="pp-modal__box">
      <div class="pp-modal__head">PATS</div>
      <div id="ppModalMsg" class="pp-modal__body">Mensaje</div>
      <div class="pp-modal__actions">
        <button type="button" class="pp-btn pp-btn--primary" id="btnCloseModal">Aceptar</button>
      </div>
    </div>
  </div>

<script>
window.PATS_PUBLIC_CFG = {
  token: <?= json_encode($token, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  monto_anual: <?= json_encode($montoAnual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  monto_mensual: <?= json_encode($montoMensual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
};
</script>

<script>
(() => {
  "use strict";

  const $ = (s) => document.querySelector(s);
  const $$ = (s) => Array.from(document.querySelectorAll(s));

  let currentStep = 1;
  const totalSteps = 6;
  let mediaStream = null;

  let signPad = null;
  let signCtx = null;
  let signDrawing = false;
  let signHasStroke = false;

  function onlyDigits(v) {
    return String(v || '').replace(/\D+/g, '');
  }

  function validPhone(v) {
    return onlyDigits(v).length === 10;
  }

  function validEmail(v) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(v || '').trim());
  }

  function calcAge(dateStr) {
    if (!dateStr) return 0;
    const birth = new Date(dateStr + 'T00:00:00');
    const today = new Date();
    let age = today.getFullYear() - birth.getFullYear();
    const m = today.getMonth() - birth.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) age--;
    return age;
  }

  function money(v) {
    return new Intl.NumberFormat("es-MX", {
      style: "currency",
      currency: "MXN",
      maximumFractionDigits: 0
    }).format(Number(v || 0));
  }

  function toast(msg, type = 'info') {
    const host = $('#ppToastHost');
    if (!host) return;

    const item = document.createElement('div');
    item.textContent = msg;
    item.style.minWidth = '240px';
    item.style.maxWidth = '360px';
    item.style.padding = '12px 14px';
    item.style.borderRadius = '16px';
    item.style.color = '#fff';
    item.style.fontSize = '13px';
    item.style.lineHeight = '1.35';
    item.style.fontWeight = '800';
    item.style.letterSpacing = '.1px';
    item.style.pointerEvents = 'auto';
    item.style.boxShadow = '0 18px 38px rgba(8,12,28,.24)';
    item.style.border = '1px solid rgba(255,255,255,.08)';
    item.style.backdropFilter = 'blur(10px)';
    item.style.background =
      type === 'error'
        ? 'linear-gradient(135deg, rgba(58,18,28,.96), rgba(112,28,46,.96))'
        : type === 'success'
        ? 'linear-gradient(135deg, rgba(8,42,54,.96), rgba(14,96,116,.96))'
        : 'linear-gradient(135deg, rgba(12,21,46,.96), rgba(36,28,74,.96))';

    host.appendChild(item);

    setTimeout(() => {
      item.style.transition = 'opacity .22s ease, transform .22s ease';
      item.style.opacity = '0';
      item.style.transform = 'translateY(-4px)';
      setTimeout(() => item.remove(), 240);
    }, 2600);
  }

  function showModal(msg) {
    const modal = $('#ppModal');
    const box = $('#ppModalMsg');
    if (!modal || !box) return;
    box.textContent = msg || 'Mensaje';
    modal.classList.remove('pp-hidden');
  }

  function hideModal() {
    $('#ppModal')?.classList.add('pp-hidden');
  }

  function syncSteps() {
    $$('[data-step-panel]').forEach((panel) => {
      panel.classList.toggle('is-active', Number(panel.dataset.stepPanel) === currentStep);
    });

    $$('[data-step]').forEach((btn) => {
      const step = Number(btn.dataset.step);
      btn.classList.toggle('is-active', step === currentStep);
      btn.classList.toggle('is-done', step < currentStep);
    });

    $('#btnPrev').style.visibility = currentStep === 1 ? 'hidden' : 'visible';
    $('#btnNext').classList.toggle('pp-hidden', currentStep === totalSteps);
    $('#btnSubmitPago').classList.toggle('pp-hidden', currentStep !== totalSteps);
  }

  function syncEmpresa() {
    const tipo = ($('#tipo_cliente')?.value || 'privado').toLowerCase();
    $('#wrapNombreEmpresa')?.classList.toggle('pp-hidden', tipo !== 'empresa');
  }
const ESTADOS_MX = {
  AGS: 'Aguascalientes',
  BCN: 'Baja California',
  BCS: 'Baja California Sur',
  CAM: 'Campeche',
  CHP: 'Chiapas',
  CHH: 'Chihuahua',
  CDMX: 'Ciudad de México',
  COA: 'Coahuila',
  COL: 'Colima',
  DGO: 'Durango',
  GTO: 'Guanajuato',
  GRO: 'Guerrero',
  HGO: 'Hidalgo',
  JAL: 'Jalisco',
  MEX: 'Estado de México',
  MIC: 'Michoacán',
  MOR: 'Morelos',
  NAY: 'Nayarit',
  NLE: 'Nuevo León',
  OAX: 'Oaxaca',
  PUE: 'Puebla',
  QRO: 'Querétaro',
  ROO: 'Quintana Roo',
  SLP: 'San Luis Potosí',
  SIN: 'Sinaloa',
  SON: 'Sonora',
  TAB: 'Tabasco',
  TAM: 'Tamaulipas',
  TLAX: 'Tlaxcala',
  VER: 'Veracruz',
  YUC: 'Yucatán',
  ZAC: 'Zacatecas'
};

function syncEstadoDesdeAcronimo() {
  const acr = ($('#dom_estado_acronimo')?.value || '').toUpperCase();
  const nombre = ESTADOS_MX[acr] || acr;
  if ($('#dom_estado')) {
    $('#dom_estado').value = nombre;
  }
}
  function syncIdentityFromLogin() {
    const correo = ($('#login_correo')?.value || '').trim();
    const tel = onlyDigits($('#login_telefono')?.value || '').slice(0, 10);

    $('#hidden_correo_usuario_pats').value = correo;
    $('#hidden_telefono_usuario').value = tel;

    const pillCorreo = $('#pillCorreo');
    const pillTelefono = $('#pillTelefono');
    const sumCorreo = $('#sumCorreo');
    const sumTelefono = $('#sumTelefono');

    if (pillCorreo) pillCorreo.textContent = `Correo: ${correo || '-'}`;
    if (pillTelefono) pillTelefono.textContent = `Teléfono: ${tel || '-'}`;
    if (sumCorreo) sumCorreo.textContent = correo || '-';
    if (sumTelefono) sumTelefono.textContent = tel || '-';
  }

function syncMonto() {
  const sel = $('#frecuencia_pago_publica');
  const freq = String(sel?.value || 'MENSUAL').toUpperCase();

  const montoMensual = Number(window.PATS_PUBLIC_CFG?.monto_mensual || 800);
  const montoAnual = Number(window.PATS_PUBLIC_CFG?.monto_anual || 9600);

  const esMensual = freq === 'MENSUAL';
  const monto = esMensual ? montoMensual : montoAnual;
  const tipoPrecio = esMensual ? '2' : '1';

  const hiddenFreq = $('#frecuencia');
  const hiddenMonto = $('#monto_orden');
  const hiddenTipo = $('#id_tipo_precio');
  const sumFreq = $('#sumFrecuencia');
  const sumMonto = $('#sumMonto');
  const visualMonto = $('#monto_visual_publico');

  if (hiddenFreq) hiddenFreq.value = freq;
  if (hiddenMonto) hiddenMonto.value = String(monto);
  if (hiddenTipo) hiddenTipo.value = tipoPrecio;

  if (sumFreq) sumFreq.textContent = esMensual ? 'MENSUAL' : 'ANUAL';
  if (sumMonto) sumMonto.textContent = money(monto);
  if (visualMonto) visualMonto.value = money(monto);
}

  function syncAdultosMayores() {
    const age = calcAge($('#fecha_nacimiento')?.value || '');
    $('#adultosMayoresWrap')?.classList.toggle('pp-hidden', age < 65);
  }

  function bindFileInputs() {
    $$('.pp-file input[type="file"]').forEach((input) => {
      if (input.dataset.boundFile === '1') return;
      input.dataset.boundFile = '1';

      input.addEventListener('change', () => {
        const wrap = input.closest('.pp-file');
        const text = wrap?.querySelector('.pp-file__text');
        if (!text) return;

        const files = input.files;
        if (files && files.length > 0) {
          text.textContent = files.length === 1 ? files[0].name : `${files.length} archivos seleccionados`;
        } else {
          text.textContent = 'Ningún archivo seleccionado';
        }
      });
    });
  }

  function buildContractHtml() {
    const fullName = [
      $('#nombre_usuario')?.value || '',
      $('#apellido_pa')?.value || '',
      $('#apellido_ma')?.value || ''
    ].join(' ').replace(/\s+/g, ' ').trim();

    const domicilio = [
      ($('#dom_calle')?.value || '') + ' ' + ($('#dom_num_ext')?.value || ''),
      ($('#dom_num_int')?.value || '').trim() ? 'Int. ' + ($('#dom_num_int')?.value || '').trim() : '',
      ($('#dom_colonia')?.value || '').trim() ? 'Col. ' + ($('#dom_colonia')?.value || '').trim() : '',
      ($('#dom_cp')?.value || '').trim() ? 'C.P. ' + ($('#dom_cp')?.value || '').trim() : '',
      $('#dom_municipio')?.value || '',
      $('#dom_estado')?.value || '',
      $('#dom_pais')?.value || ''
    ].filter(Boolean).join(', ');

    const curp = $('#curp_usuario')?.value || '';
    const correo = $('#hidden_correo_usuario_pats')?.value || '';
    const telefono = $('#hidden_telefono_usuario')?.value || '';
   const freq = ($('#frecuencia')?.value || 'MENSUAL').toUpperCase();
const monto = money($('#monto_orden')?.value || (freq === 'ANUAL' ? 9600 : 800));
const frecuenciaTexto = freq === 'ANUAL' ? 'anual' : 'mensual';

    const now = new Date();
    const months = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    const fechaLarga = `${now.getDate()} de ${months[now.getMonth()]} de ${now.getFullYear()}`;

    return `
      <h3>Contrato de adquisición de tarjeta de descuento</h3>
      <p>
        Contrato celebrado entre <strong>“EL PRESTADOR”</strong> y
        <strong>“EL AFILIADO”</strong>, siendo este último:
        <strong>${escapeHtml(fullName || '________________')}</strong>.
      </p>

      <h4>Declaraciones del afiliado</h4>
      <p><strong>Nombre completo:</strong> ${escapeHtml(fullName || '________________')}</p>
      <p><strong>CURP:</strong> ${escapeHtml(curp || '________________')}</p>
      <p><strong>Correo:</strong> ${escapeHtml(correo || '________________')}</p>
      <p><strong>Teléfono:</strong> ${escapeHtml(telefono || '________________')}</p>
      <p><strong>Domicilio:</strong> ${escapeHtml(domicilio || '________________')}</p>

      <h4>Objeto</h4>
      <p>
        El prestador otorga a título oneroso y temporal el uso de la Tarjeta de Descuento en favor del afiliado,
        para el aprovechamiento de un programa de beneficios que permite acceder a descuentos y servicios médicos
        privados de alta calidad de forma accesible, clara y sin restricciones conforme al programa PATS.
      </p>

      <h4>Precio</h4>
      <p>
        Las partes establecen que la Tarjeta de Descuento tendrá un costo ${escapeHtml(frecuenciaTexto)} de
      <strong>${escapeHtml(monto)}</strong>, IVA incluido, y que deberá ser cubierto por el afiliado dentro de los primeros
        cinco días de cada mes por los medios habilitados por el prestador.
      </p>

      <h4>Vigencia</h4>
      <p>
        La tarjeta tendrá una vigencia de ${freq === 'ANUAL' ? '12 meses' : '30 días'} y se prorrogará automáticamente siempre que el afiliado continúe cubriendo el pago ${escapeHtml(frecuenciaTexto)} correspondiente.
      </p>

      <h4>Beneficios</h4>
      <p>
        El afiliado gozará de los beneficios previstos por el programa, incluyendo consultas médicas y acceso a costos
        preferenciales dentro de la red aplicable, conforme al tabulador vigente y anexos del programa.
      </p>

      <h4>Obligaciones del afiliado</h4>
      <p>
        El afiliado se obliga a pagar puntualmente la tarjeta, usarla de forma personal e intransferible, cubrir las
        cuotas de recuperación que correspondan y presentar identificación oficial y medios de acceso válidos al hacer uso del programa.
      </p>

      <h4>Protección de datos y responsabilidad</h4>
      <p>
        El afiliado autoriza el uso de sus datos para la gestión de la tarjeta y reconoce que el prestador no es una
        aseguradora ni asume responsabilidad por los servicios médicos prestados por terceros, en los términos del contrato.
      </p>

      <h4>Jurisdicción</h4>
      <p>
        Las partes se someten a la legislación aplicable y a la jurisdicción de los tribunales competentes del Distrito Judicial de Puebla, Estado de Puebla.
      </p>

      <p>
        Leído y entendido por las partes, se firma electrónicamente el día
        <strong>${escapeHtml(fechaLarga)}</strong>.
      </p>
    `;
  }
  
let contractPreviewTimer = null;
let contractPreviewLoading = false;
let contractPreviewLastKey = '';

function queueContractPreview() {
  clearTimeout(contractPreviewTimer);
  contractPreviewTimer = setTimeout(() => {
    refreshContractPreview();
  }, 350);
}

async function refreshContractPreview() {
  const wrap = $('#contractPreview');
  if (!wrap) return;

  const correo = ($('#hidden_correo_usuario_pats')?.value || '').trim();
  const telefono = ($('#hidden_telefono_usuario')?.value || '').trim();

  const payloadKey = JSON.stringify({
    nombre_usuario: $('#nombre_usuario')?.value || '',
    apellido_pa: $('#apellido_pa')?.value || '',
    apellido_ma: $('#apellido_ma')?.value || '',
    curp_usuario: $('#curp_usuario')?.value || '',
    correo_usuario_pats: correo,
    telefono_usuario: telefono,
    frecuencia: $('#frecuencia')?.value || 'MENSUAL',
    monto_orden: $('#monto_orden')?.value || '800',
    dom_calle: $('#dom_calle')?.value || '',
    dom_num_ext: $('#dom_num_ext')?.value || '',
    dom_num_int: $('#dom_num_int')?.value || '',
    dom_colonia: $('#dom_colonia')?.value || '',
    dom_cp: $('#dom_cp')?.value || '',
    dom_municipio: $('#dom_municipio')?.value || '',
    dom_estado: $('#dom_estado')?.value || '',
    dom_pais: $('#dom_pais')?.value || '',
    firma_base64: $('#firma_base64')?.value || ''
  });

  if (payloadKey === contractPreviewLastKey || contractPreviewLoading) {
    return;
  }

  contractPreviewLastKey = payloadKey;
  contractPreviewLoading = true;

  const fd = new FormData();
  fd.append('token_publico', $('input[name="token_publico"]')?.value || '');
  fd.append('nombre_usuario', $('#nombre_usuario')?.value || '');
  fd.append('apellido_pa', $('#apellido_pa')?.value || '');
  fd.append('apellido_ma', $('#apellido_ma')?.value || '');
  fd.append('curp_usuario', $('#curp_usuario')?.value || '');
  fd.append('correo_usuario_pats', correo);
  fd.append('telefono_usuario', telefono);
  fd.append('frecuencia', $('#frecuencia')?.value || 'MENSUAL');
  fd.append('monto_orden', $('#monto_orden')?.value || '800');
  fd.append('dom_calle', $('#dom_calle')?.value || '');
  fd.append('dom_num_ext', $('#dom_num_ext')?.value || '');
  fd.append('dom_num_int', $('#dom_num_int')?.value || '');
  fd.append('dom_colonia', $('#dom_colonia')?.value || '');
  fd.append('dom_cp', $('#dom_cp')?.value || '');
  fd.append('dom_municipio', $('#dom_municipio')?.value || '');
  fd.append('dom_estado', $('#dom_estado')?.value || '');
  fd.append('dom_pais', $('#dom_pais')?.value || '');
  fd.append('firma_base64', $('#firma_base64')?.value || '');

  try {
    const res = await fetch('endpoints/public_contract_preview.php', {
      method: 'POST',
      body: fd
    });

    const text = await res.text();
    let data = {};
    try {
      data = text ? JSON.parse(text) : {};
    } catch {
      console.error(text);
      return;
    }

   if (!res.ok || data.ok === false) {
      wrap.innerHTML = `<div class="pats-empty-state">${data.error || 'No fue posible renderizar el contrato.'}</div>`;
      return;
    }

    wrap.innerHTML = data.html || '';
  } catch (e) {
    console.error(e);
  } finally {
    contractPreviewLoading = false;
  }
}



  function escapeHtml(v) {
    return String(v || '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

 function setupSignaturePad(forceReset = false) {
  signPad = $('#signaturePad');
  if (!signPad) return;

  const rect = signPad.getBoundingClientRect();
  if (!rect.width || !rect.height) return;

  const dpr = Math.max(window.devicePixelRatio || 1, 1);
  const newWidth = Math.floor(rect.width * dpr);
  const newHeight = Math.floor(rect.height * dpr);

  const oldData = (!forceReset && signHasStroke) ? signPad.toDataURL('image/png') : null;

  signPad.width = newWidth;
  signPad.height = newHeight;

  signCtx = signPad.getContext('2d');
  signCtx.setTransform(1, 0, 0, 1, 0, 0);
  signCtx.scale(dpr, dpr);
  signCtx.lineWidth = 2.4;
  signCtx.lineCap = 'round';
  signCtx.lineJoin = 'round';
  signCtx.strokeStyle = '#173b72';

  if (oldData) {
    const img = new Image();
    img.onload = () => {
      signCtx.drawImage(img, 0, 0, rect.width, rect.height);
    };
    img.src = oldData;
  }

  if (signPad.dataset.boundSign === '1') return;
  signPad.dataset.boundSign = '1';

  const getXY = (ev) => {
    const r = signPad.getBoundingClientRect();
    const touch = ev.touches && ev.touches[0] ? ev.touches[0] : null;
    const clientX = touch ? touch.clientX : ev.clientX;
    const clientY = touch ? touch.clientY : ev.clientY;
    return { x: clientX - r.left, y: clientY - r.top };
  };

  const start = (ev) => {
    ev.preventDefault();
    signDrawing = true;
    signHasStroke = true;
    const { x, y } = getXY(ev);
    signCtx.beginPath();
    signCtx.moveTo(x, y);
  };

  const move = (ev) => {
    if (!signDrawing) return;
    ev.preventDefault();
    const { x, y } = getXY(ev);
    signCtx.lineTo(x, y);
    signCtx.stroke();
  };

  const end = (ev) => {
    if (!signDrawing) return;
    ev.preventDefault();
    signDrawing = false;
    $('#firma_base64').value = signPad.toDataURL('image/png');
  };

  signPad.addEventListener('mousedown', start);
  signPad.addEventListener('mousemove', move);
  window.addEventListener('mouseup', end);

  signPad.addEventListener('touchstart', start, { passive: false });
  signPad.addEventListener('touchmove', move, { passive: false });
  signPad.addEventListener('touchend', end, { passive: false });
}

  function clearSignature() {
  if (!signCtx || !signPad) return;
  signCtx.clearRect(0, 0, signPad.width, signPad.height);
  signHasStroke = false;
  $('#firma_base64').value = '';
  setupSignaturePad(true);
}

  async function startCamera() {
    const video = $('#camVideo');
    const preview = $('#camPreview');
    const canvas = $('#camCanvas');

    try {
      if (mediaStream) {
        mediaStream.getTracks().forEach(t => t.stop());
      }

      mediaStream = await navigator.mediaDevices.getUserMedia({
        video: {
          facingMode: { ideal: 'user' },
          width: { ideal: 1280 },
          height: { ideal: 720 }
        },
        audio: false
      });

      video.srcObject = mediaStream;
      video.classList.remove('pp-hidden');
      preview.classList.add('pp-hidden');
      canvas.classList.add('pp-hidden');
    } catch (e) {
      console.error(e);
      showModal('No fue posible abrir la cámara.');
    }
  }

  function capturePhoto() {
    const video = $('#camVideo');
    const canvas = $('#camCanvas');
    const preview = $('#camPreview');
    const hidden = $('#foto_base64');

    if (!video || !canvas || !hidden || video.readyState < 2) {
      toast('Primero inicia la cámara.', 'error');
      return;
    }

    const ctx = canvas.getContext('2d');
    const w = video.videoWidth || 1280;
    const h = video.videoHeight || 720;

    canvas.width = w;
    canvas.height = h;

    ctx.filter = 'brightness(1.12) contrast(1.05) saturate(1.03)';
    ctx.drawImage(video, 0, 0, w, h);

    const dataUrl = canvas.toDataURL('image/jpeg', 0.92);
    hidden.value = dataUrl;

    preview.src = dataUrl;
    preview.classList.remove('pp-hidden');
    canvas.classList.add('pp-hidden');
    video.classList.add('pp-hidden');
  }

  function bindManualPhoto() {
    const input = $('#foto_manual');
    const preview = $('#camPreview');
    const hidden = $('#foto_base64');

    if (!input) return;

    input.addEventListener('change', () => {
      const file = input.files && input.files[0];
      if (!file) return;

      const reader = new FileReader();
      reader.onload = () => {
        hidden.value = String(reader.result || '');
        preview.src = hidden.value;
        preview.classList.remove('pp-hidden');
        $('#camVideo')?.classList.add('pp-hidden');
        $('#camCanvas')?.classList.add('pp-hidden');
      };
      reader.readAsDataURL(file);
    });
  }

  function validateStep1() {
    if (!validEmail($('#login_correo')?.value || '')) {
      toast('Captura un correo válido.', 'error');
      return false;
    }
    if (!validPhone($('#login_telefono')?.value || '')) {
      toast('Captura un teléfono válido de 10 dígitos.', 'error');
      return false;
    }
    syncIdentityFromLogin();
    return true;
  }

  function validateStep2() {
    if (!($('#nombre_usuario')?.value || '').trim()) return toast('Faltan nombres.', 'error'), false;
    if (!($('#apellido_pa')?.value || '').trim()) return toast('Falta apellido paterno.', 'error'), false;
    if (!($('#curp_usuario')?.value || '').trim()) return toast('Falta CURP.', 'error'), false;
    if (!($('#fecha_nacimiento')?.value || '').trim()) return toast('Falta fecha de nacimiento.', 'error'), false;

    const age = calcAge($('#fecha_nacimiento')?.value || '');
    if (age >= 65) {
      if (!($('#ac1_nombre')?.value || '').trim()) return toast('Falta acompañante 1.', 'error'), false;
      if (!($('#ac2_nombre')?.value || '').trim()) return toast('Falta acompañante 2.', 'error'), false;
    }

    return true;
  }

  function validateStep3() {
    if (!($('#dom_calle')?.value || '').trim()) return toast('Falta calle.', 'error'), false;
    if (!($('#dom_num_ext')?.value || '').trim()) return toast('Falta número exterior.', 'error'), false;
    if (!($('#dom_colonia')?.value || '').trim()) return toast('Falta colonia.', 'error'), false;
    if (onlyDigits($('#dom_cp')?.value || '').length !== 5) return toast('Código postal inválido.', 'error'), false;
    if (!($('#dom_municipio')?.value || '').trim()) return toast('Falta ciudad o municipio.', 'error'), false;
    if (!($('#dom_estado')?.value || '').trim()) return toast('Falta estado.', 'error'), false;
    if (!($('#dom_pais')?.value || '').trim()) return toast('Falta país.', 'error'), false;
    return true;
  }

  function validateStep4() {
    const req = ['doc_ine_frente', 'doc_ine_reverso', 'doc_curp'];
    for (const id of req) {
      const input = $('#' + id);
      if (!input?.files?.length) {
        toast('Debes cargar todos los documentos obligatorios.', 'error');
        return false;
      }
    }
    return true;
  }

  function validateStep5() {
    if (!($('#foto_base64')?.value || '').trim()) {
      toast('Debes capturar o subir la fotografía.', 'error');
      return false;
    }
    return true;
  }

  function validateStep6() {
    if (!$('#acepta_contrato')?.checked) {
      toast('Debes aceptar el contrato.', 'error');
      return false;
    }
    if (!signHasStroke || !($('#firma_base64')?.value || '').trim()) {
      toast('Debes firmar en pantalla para continuar.', 'error');
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

  async function submitWizard(ev) {
    ev.preventDefault();
    syncIdentityFromLogin();

    if (!validateStep6() || !validateStep5() || !validateStep4() || !validateStep3() || !validateStep2() || !validateStep1()) {
      return;
    }

    const fd = new FormData(ev.currentTarget);

    try {
      const res = await fetch('endpoints/public_checkout_generar_orden.php', {
        method: 'POST',
        body: fd
      });

      const text = await res.text();
      let data = {};
      try {
        data = text ? JSON.parse(text) : {};
      } catch {
        showModal('La respuesta del servidor no es válida. Intenta de nuevo en unos momentos.');
        console.error(text);
        return;
      }

      if (!res.ok || data.ok === false) {
        showModal(data.error || 'No fue posible generar la orden.');
        console.error(data);
        return;
      }

      if (data.checkout_url) {
        window.location.href = data.checkout_url;
        return;
      }

      showModal('La orden se generó, pero no se recibió la URL de checkout.');
    } catch (e) {
      console.error(e);
      showModal('No fue posible continuar a la pasarela. Intenta nuevamente.');
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    syncSteps();
    syncEmpresa();
    syncMonto();
    bindFileInputs();
    bindManualPhoto();
    syncIdentityFromLogin();
    setupSignaturePad();

 $('#frecuencia_pago_publica')?.addEventListener('change', () => {
  syncMonto();
  queueContractPreview();
});

    $('#btnCloseModal')?.addEventListener('click', hideModal);
    $('#btnLimpiarFirma')?.addEventListener('click', clearSignature);

    $('#login_telefono')?.addEventListener('input', (e) => {
      e.target.value = onlyDigits(e.target.value).slice(0, 10);
      syncIdentityFromLogin();
    });

    $('#login_correo')?.addEventListener('input', syncIdentityFromLogin);

   $('#curp_usuario')?.addEventListener('input', (e) => {
      e.target.value = String(e.target.value || '').toUpperCase().slice(0, 18);
      queueContractPreview();
    });

    $('#dom_cp')?.addEventListener('input', (e) => {
      e.target.value = onlyDigits(e.target.value).slice(0, 5);
      queueContractPreview();
    });
    [
      '#nombre_usuario', '#apellido_pa', '#apellido_ma', '#fecha_nacimiento',
      '#tipo_cliente', '#nombre_empresa',
      '#dom_calle', '#dom_num_ext', '#dom_num_int', '#dom_colonia',
      '#dom_municipio', '#dom_estado', '#dom_pais'
    ].forEach((sel) => {
      $(sel)?.addEventListener('input', queueContractPreview);
      $(sel)?.addEventListener('change', queueContractPreview);
    });

    $('#tipo_cliente')?.addEventListener('change', syncEmpresa);
 
$('#fecha_nacimiento')?.addEventListener('change', () => {
  syncAdultosMayores();
  queueContractPreview();
});

    $('#btnIniciarCamara')?.addEventListener('click', startCamera);
    $('#btnCapturarFoto')?.addEventListener('click', capturePhoto);

    $('#btnPrev')?.addEventListener('click', () => {
      if (currentStep > 1) {
        currentStep--;
        syncSteps();
      }
    });
  $('#dom_estado_acronimo')?.addEventListener('change', () => {
  syncEstadoDesdeAcronimo();
  queueContractPreview();
});

    window.addEventListener('resize', () => {
    if (currentStep === 6) {
        setTimeout(() => setupSignaturePad(false), 40);
    }
    });

    syncEstadoDesdeAcronimo();
    $('#btnNext')?.addEventListener('click', () => {
      if (!validateCurrentStep()) return;
        if (currentStep < totalSteps) {
          currentStep++;
          syncSteps();

          if (currentStep === 6) {
            syncIdentityFromLogin();
            syncMonto();
            refreshContractPreview();
            setTimeout(() => setupSignaturePad(false), 60);
          }
        }
    });

    $('#frmPagoPatsPublico')?.addEventListener('submit', submitWizard);
  });
})();
</script>
</body>
</html>