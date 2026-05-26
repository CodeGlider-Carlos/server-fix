<?php
/*
 * ez/pats/generacion_links.php
 * Generación unificada de links (distribuidor y franquicia).
 */
session_start();
require_once '../../varSQL/bd_pats.php';
require_once '../../varSQL/var_pats.php';

if (empty($_SESSION['usuario'])) {
    session_destroy();
    header("Location: ../../../index.php");
    exit;
}

$adminrol = strtoupper(trim((string)($_SESSION['rol'] ?? '')));
$rolapp   = strtoupper(trim((string)($_SESSION['rolapp'] ?? '')));
$rolesAdmin = ['ADMIN', 'ADMINPATS', 'DIRO', 'DIRG', 'VIC'];

if (!in_array($adminrol, $rolesAdmin, true) && !in_array($rolapp, $rolesAdmin, true)) {
    header('Location: index.php');
    exit;
}

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }
}

// Pre-selección desde query param (?tipo=distribuidor | ?tipo=franquicia)
$tipoInicial = in_array($_GET['tipo'] ?? '', ['distribuidor', 'franquicia'], true)
    ? $_GET['tipo']
    : '';

$ver = time();

$estados = [
    'AGS' => 'Aguascalientes', 'BCN' => 'Baja California',   'BCS' => 'Baja California Sur',
    'CAM' => 'Campeche',       'CHP' => 'Chiapas',           'CHH' => 'Chihuahua',
    'CDMX'=> 'Ciudad de México','COA'=> 'Coahuila',          'COL' => 'Colima',
    'DUR' => 'Durango',        'MEX' => 'Estado de México',  'GTO' => 'Guanajuato',
    'GRO' => 'Guerrero',       'HGO' => 'Hidalgo',           'JAL' => 'Jalisco',
    'MIC' => 'Michoacán',      'MOR' => 'Morelos',           'NAY' => 'Nayarit',
    'NLE' => 'Nuevo León',     'OAX' => 'Oaxaca',            'PUE' => 'Puebla',
    'QRO' => 'Querétaro',      'ROO' => 'Quintana Roo',      'SLP' => 'San Luis Potosí',
    'SIN' => 'Sinaloa',        'SON' => 'Sonora',            'TAB' => 'Tabasco',
    'TAM' => 'Tamaulipas',     'TLAX'=> 'Tlaxcala',          'VER' => 'Veracruz',
    'YUC' => 'Yucatán',        'ZAC' => 'Zacatecas',
];

$bancos = ['BBVA','Banamex / Citibanamex','Banorte','Santander','HSBC','Scotiabank','Inbursa','Afirme','BanBajío','Multiva','Banca Mifel','Azteca','BanRegio'];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Generación de links · PATS</title>
    <link rel="icon" type="image/x-icon" href="../../img/ez.ico">
    <link rel="stylesheet" href="../../css/index.css?v=<?= $ver ?>">
    <link rel="stylesheet" href="../../css/gloval_responsive.css?v=<?= $ver ?>">
    <link rel="stylesheet" href="css/pats.css?v=<?= $ver ?>">
    <style>
        .gl-wrap, .gl-wrap * { box-sizing: border-box; }
        .gl-wrap { max-width: 900px; margin: 0 auto; padding: 28px 20px 60px; }

        /* ── Selector de tipo ───────────────── */
        .gl-tipo-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 28px; }
        @media (max-width: 540px) { .gl-tipo-grid { grid-template-columns: 1fr; } }

        .gl-tipo-card {
            border: 2px solid rgba(37,99,235,.15); border-radius: 16px;
            padding: 22px 20px; cursor: pointer; background: #f8faff;
            transition: all .18s; text-align: center; user-select: none;
        }
        .gl-tipo-card:hover { border-color: #2563eb; background: #eff4ff; }
        .gl-tipo-card.selected {
            border-color: #2563eb; background: #eff4ff;
            box-shadow: 0 0 0 3px rgba(37,99,235,.12);
        }
        .gl-tipo-card__icon  { font-size: 30px; margin-bottom: 8px; }
        .gl-tipo-card__title { font-size: 14px; font-weight: 700; color: #1e293b; }
        .gl-tipo-card__sub   { font-size: 12px; color: #64748b; margin-top: 4px; }

        /* ── Card ───────────────────────────── */
        .gl-card {
            background: #fff; border-radius: 18px;
            box-shadow: 0 0 0 1px rgba(37,99,235,.12), 0 8px 28px rgba(13,27,62,.07);
            margin-bottom: 28px; overflow: hidden;
        }
        .gl-card__head {
            padding: 18px 24px; border-bottom: 1px solid rgba(37,99,235,.1);
            display: flex; align-items: center; gap: 12px;
        }
        .gl-card__head-icon {
            width: 36px; height: 36px; border-radius: 10px;
            background: #eff4ff; color: #2563eb;
            display: flex; align-items: center; justify-content: center; font-size: 18px;
        }
        .gl-card__head-title { font-size: 14px; font-weight: 700; }
        .gl-card__head-sub   { font-size: 12px; color: #64748b; margin-top: 1px; }
        .gl-card__body       { padding: 24px; }

        /* ── Grid / Field ───────────────────── */
        .gl-grid   { display: grid; gap: 16px; }
        .gl-grid-2 { grid-template-columns: 1fr 1fr; }
        .gl-grid-3 { grid-template-columns: 1fr 1fr 1fr; }
        .gl-grid-4 { grid-template-columns: 1fr 1fr 1fr 1fr; }
        @media (max-width: 680px) { .gl-grid-2,.gl-grid-3,.gl-grid-4 { grid-template-columns: 1fr; } }

        .gl-field { display: flex; flex-direction: column; gap: 5px; }
        .gl-label { font-size: 12px; font-weight: 700; color: #334155; }
        .gl-input, .gl-select {
            height: 40px; padding: 0 12px;
            border: 1.5px solid rgba(37,99,235,.15); border-radius: 10px;
            font-size: 13.5px; color: #1e293b; background: #f8faff; outline: none;
            transition: border-color .15s, box-shadow .15s; width: 100%;
            font-family: inherit;
        }
        .gl-input:focus, .gl-select:focus {
            border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.09); background: #fff;
        }
        .gl-input--mono { font-family: 'JetBrains Mono', monospace; }
        .gl-input--readonly { background: #f1f5f9; color: #64748b; cursor: not-allowed; }
        .gl-hint { font-size: 11px; color: #94a3b8; }

        /* ── Toggle pago ────────────────────── */
        .gl-toggle { display: flex; gap: 8px; flex-wrap: wrap; }
        .gl-toggle-opt { position: relative; }
        .gl-toggle-opt input { position: absolute; opacity: 0; width: 0; }
        .gl-toggle-opt label {
            display: flex; align-items: center; gap: 6px;
            padding: 8px 18px; border-radius: 10px;
            border: 1.5px solid rgba(37,99,235,.15);
            font-size: 13px; font-weight: 600; color: #475569;
            cursor: pointer; background: #f8faff; transition: all .15s;
        }
        .gl-toggle-opt input:checked + label { background: #2563eb; border-color: #2563eb; color: #fff; }

        /* ── Contraseña ─────────────────────── */
        .gl-pw-wrap { position: relative; }
        .gl-pw-wrap .gl-input { padding-right: 76px; }
        .gl-pw-actions {
            position: absolute; right: 6px; top: 50%; transform: translateY(-50%);
            display: flex; gap: 4px;
        }
        .gl-pw-btn {
            background: none; border: none; cursor: pointer; color: #94a3b8;
            font-size: 13px; padding: 4px 6px; border-radius: 6px;
            transition: color .12s, background .12s;
        }
        .gl-pw-btn:hover { color: #2563eb; background: #eff4ff; }

        /* ── Colapsable ─────────────────────── */
        .gl-collapse-btn {
            display: flex; align-items: center; gap: 7px;
            background: none; border: none; cursor: pointer;
            font-size: 13px; font-weight: 700; color: #2563eb;
            padding: 0; font-family: inherit;
        }
        .gl-collapse-btn .arrow { transition: transform .2s; display: inline-block; }
        .gl-collapse-btn.open .arrow { transform: rotate(90deg); }
        .gl-collapse-body { display: none; margin-top: 18px; }
        .gl-collapse-body.open { display: block; }
        .gl-section-title {
            font-size: 11px; font-weight: 700; letter-spacing: .07em;
            text-transform: uppercase; color: #94a3b8; margin: 20px 0 14px;
            display: flex; align-items: center; gap: 8px;
        }
        .gl-section-title::after { content: ''; flex: 1; height: 1px; background: rgba(37,99,235,.1); }

        /* ── Resultado ──────────────────────── */
        .gl-alert { display: flex; align-items: flex-start; gap: 10px; padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-size: 13px; }
        .gl-alert--success { background: #ecfdf5; border: 1.5px solid rgba(16,185,129,.25); color: #065f46; }
        .gl-alert--error   { background: #fff1f2; border: 1.5px solid rgba(239,68,68,.25); color: #991b1b; }
        .gl-alert i { font-size: 19px; flex-shrink: 0; margin-top: 1px; }
        .gl-result-row {
            display: flex; align-items: center; gap: 10px;
            margin-top: 6px; padding: 8px 12px;
            background: rgba(255,255,255,.6); border-radius: 7px;
            font-family: 'JetBrains Mono', monospace; font-size: 12px; word-break: break-all;
        }
        .gl-result-row a   { color: #065f46; text-decoration: none; flex: 1; }
        .gl-result-row span{ flex: 1; }
        .gl-copy-btn { background: none; border: none; cursor: pointer; color: #94a3b8; font-size: 15px; flex-shrink: 0; }
        .gl-copy-btn:hover { color: #2563eb; }

        /* ── Toast ──────────────────────────── */
        #glToast {
            position: fixed; bottom: 24px; right: 24px;
            background: #1e293b; color: #fff;
            padding: 11px 18px; border-radius: 10px;
            font-size: 13px; font-weight: 600; z-index: 9999;
            opacity: 0; transition: opacity .25s; pointer-events: none;
        }
        #glToast.show { opacity: 1; }
        .hidden { display: none !important; }
    </style>
</head>
<body>

<?php require_once '../../loglog/contador.php'; ?>

<div id="cabecera">
    <div class="bienvenido">PATS · Generación de links</div>
    <?php require_once '../nav/noti_user_pats.php'; ?>
</div>

<?php require_once '../nav/nav_mod.php'; ?>

<content class="back_content">
<div class="gl-wrap">

    <section class="pats-hero" style="margin-bottom:24px;">
        <div class="pats-hero__left">
            <div class="pats-kicker">EZHS · PATS · GENERACIÓN DE LINKS</div>
            <h1 class="pats-title">Generación de links de alta</h1>
            <p class="pats-subtitle">
                Genera un enlace protegido para que el candidato complete su solicitud.<br>
                Elige el tipo, configura el pago y los datos precargados opcionales.
            </p>
        </div>
        <div>
            <button type="button" class="pats-chip pats-chip--action" onclick="window.location.href='admin.php'">
                ← Volver al admin
            </button>
        </div>
    </section>

    <!-- Resultado -->
    <div id="glAlertBox"></div>

    <!-- Paso 1: Tipo -->
    <div class="gl-card">
        <div class="gl-card__head">
            <div class="gl-card__head-icon">①</div>
            <div>
                <div class="gl-card__head-title">Tipo de solicitud</div>
                <div class="gl-card__head-sub">¿Para quién es el link?</div>
            </div>
        </div>
        <div class="gl-card__body">
            <div class="gl-tipo-grid">
                <div class="gl-tipo-card" id="cardDist" onclick="selectTipo('distribuidor')">
                    <div class="gl-tipo-card__icon">🤝</div>
                    <div class="gl-tipo-card__title">Distribuidor</div>
                    <div class="gl-tipo-card__sub">Alta de distribuidor · monto configurable hasta $20,000</div>
                </div>
                <div class="gl-tipo-card" id="cardFranq" onclick="selectTipo('franquicia')">
                    <div class="gl-tipo-card__icon">🏢</div>
                    <div class="gl-tipo-card__title">Franquiciatario</div>
                    <div class="gl-tipo-card__sub">Alta de franquicia · pago fijo $999,999</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Paso 2: Configuración -->
    <div class="gl-card hidden" id="cardConfig">
        <div class="gl-card__head">
            <div class="gl-card__head-icon">②</div>
            <div>
                <div class="gl-card__head-title" id="configTitle">Configuración</div>
                <div class="gl-card__head-sub">Define pago, monto y datos opcionales del candidato.</div>
            </div>
        </div>
        <div class="gl-card__body">
            <form id="frmGenerar" autocomplete="off">
                <input type="hidden" id="hTipo" name="tipo">

                <!-- Pago + Monto -->
                <div class="gl-grid gl-grid-2" style="margin-bottom:20px;align-items:end;">
                    <div class="gl-field" id="wrapPago">
                        <label class="gl-label">Tipo de pago</label>
                        <div class="gl-toggle" style="margin-top:4px;">
                            <div class="gl-toggle-opt">
                                <input type="radio" name="con_pago" id="pago_si" value="1" checked onchange="togglePago(1)">
                                <label for="pago_si">💳 Con pago</label>
                            </div>
                            <div class="gl-toggle-opt">
                                <input type="radio" name="con_pago" id="pago_no" value="0" onchange="togglePago(0)">
                                <label for="pago_no">✓ Sin pago</label>
                            </div>
                        </div>
                    </div>
                    <div class="gl-field" id="wrapMonto">
                        <label class="gl-label" for="amount">Monto (MXN)</label>
                        <input class="gl-input gl-input--mono" type="number" id="amount" name="amount"
                               min="0" step="0.01" value="20000">
                        <span class="gl-hint" id="amountHint">Máx. $20,000 para distribuidores</span>
                    </div>
                </div>

                <!-- Contraseña autogenerada -->
                <div class="gl-field" style="max-width:420px;margin-bottom:24px;">
                    <label class="gl-label">Contraseña de acceso</label>
                    <div class="gl-pw-wrap">
                        <input class="gl-input gl-input--mono" type="text" id="password" name="password" readonly placeholder="—">
                        <div class="gl-pw-actions">
                            <button type="button" class="gl-pw-btn" title="Copiar contraseña" onclick="copiarPw()">📋</button>
                            <button type="button" class="gl-pw-btn" title="Regenerar" onclick="generarPassword()">🔄</button>
                        </div>
                    </div>
                    <span class="gl-hint">Autogenerada — compártela con el candidato junto con el link.</span>
                </div>

                <!-- Prefill colapsable -->
                <div style="border-top:1px solid rgba(37,99,235,.1);padding-top:20px;margin-bottom:24px;">
                    <button type="button" class="gl-collapse-btn" id="glPrefillToggle">
                        <span class="arrow">▶</span>
                        Datos precargados
                        <span style="font-size:11px;font-weight:400;color:#94a3b8;margin-left:4px;">(opcional)</span>
                    </button>

                    <div class="gl-collapse-body" id="glPrefillBody">

                        <div class="gl-section-title">Datos personales</div>
                        <div class="gl-grid gl-grid-3" style="margin-bottom:14px;">
                            <div class="gl-field">
                                <label class="gl-label" for="pn">Nombre(s)</label>
                                <input class="gl-input" type="text" id="pn" name="prefill_nombre" placeholder="Carlos">
                            </div>
                            <div class="gl-field">
                                <label class="gl-label" for="pp">Apellido paterno</label>
                                <input class="gl-input" type="text" id="pp" name="prefill_apellido_paterno" placeholder="González">
                            </div>
                            <div class="gl-field">
                                <label class="gl-label" for="pm">Apellido materno</label>
                                <input class="gl-input" type="text" id="pm" name="prefill_apellido_materno" placeholder="López">
                            </div>
                            <div class="gl-field">
                                <label class="gl-label" for="pc">Correo</label>
                                <input class="gl-input" type="email" id="pc" name="prefill_correo" placeholder="correo@ejemplo.com">
                            </div>
                            <div class="gl-field">
                                <label class="gl-label" for="pt">Teléfono</label>
                                <input class="gl-input gl-input--mono" type="text" id="pt" name="prefill_telefono" placeholder="3312345678" maxlength="10">
                            </div>
                            <div class="gl-field">
                                <label class="gl-label" for="ptp">Tipo persona</label>
                                <select class="gl-select" id="ptp" name="prefill_tipo_persona">
                                    <option value="">— No especificado —</option>
                                    <option value="FISICA">Física</option>
                                    <option value="MORAL">Moral</option>
                                </select>
                            </div>
                            <div class="gl-field">
                                <label class="gl-label" for="prs">Razón social</label>
                                <input class="gl-input" type="text" id="prs" name="prefill_razon_social" placeholder="Mi Empresa S.A. de C.V.">
                            </div>
                            <div class="gl-field">
                                <label class="gl-label" for="pna">Nacionalidad</label>
                                <input class="gl-input" type="text" id="pna" name="prefill_nacionalidad" placeholder="Mexicana" value="Mexicana">
                            </div>
                            <div class="gl-field">
                                <label class="gl-label" for="poc">Ocupación</label>
                                <input class="gl-input" type="text" id="poc" name="prefill_ocupacion" placeholder="Empresario">
                            </div>
                            <div class="gl-field">
                                <label class="gl-label" for="pr">RFC</label>
                                <input class="gl-input gl-input--mono" type="text" id="pr" name="prefill_rfc"
                                       placeholder="XXXX000000XXX" maxlength="13" style="text-transform:uppercase">
                            </div>
                        </div>

                        <!-- Identificación (solo distribuidor) -->
                        <div id="secIdentificacion">
                            <div class="gl-section-title">Identificación oficial</div>
                            <div class="gl-grid gl-grid-3" style="margin-bottom:14px;">
                                <div class="gl-field">
                                    <label class="gl-label" for="pti">Tipo</label>
                                    <select class="gl-select" id="pti" name="prefill_tipo_identificacion">
                                        <option value="">— Seleccionar —</option>
                                        <option value="INE">INE</option>
                                        <option value="Pasaporte">Pasaporte</option>
                                        <option value="Cédula profesional">Cédula profesional</option>
                                        <option value="Licencia de conducir">Licencia de conducir</option>
                                    </select>
                                </div>
                                <div class="gl-field">
                                    <label class="gl-label" for="pie">Emitida por</label>
                                    <input class="gl-input" type="text" id="pie" name="prefill_identificacion_emitida_por" placeholder="INE" value="INE">
                                </div>
                                <div class="gl-field">
                                    <label class="gl-label" for="pni">Número</label>
                                    <input class="gl-input gl-input--mono" type="text" id="pni" name="prefill_numero_identificacion" placeholder="1234567890">
                                </div>
                            </div>
                        </div>

                        <div class="gl-section-title">Domicilio</div>
                        <div class="gl-grid gl-grid-4" style="margin-bottom:10px;">
                            <div class="gl-field">
                                <label class="gl-label" for="ppais">País</label>
                                <select class="gl-select" id="ppais" name="prefill_pais">
                                    <option value="">— —</option>
                                    <option value="MX" selected>México</option>
                                    <option value="US">Estados Unidos</option>
                                </select>
                            </div>
                            <div class="gl-field">
                                <label class="gl-label" for="preg">Estado</label>
                                <select class="gl-select" id="preg" name="prefill_region">
                                    <option value="">— Estado —</option>
                                    <?php foreach ($estados as $acr => $nom): ?>
                                        <option value="<?= e($acr) ?>"><?= e($nom) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="gl-field">
                                <label class="gl-label" for="pmun">Municipio</label>
                                <input class="gl-input" type="text" id="pmun" name="prefill_municipio" placeholder="Guadalajara">
                            </div>
                            <div class="gl-field">
                                <label class="gl-label" for="pcp">C.P.</label>
                                <input class="gl-input gl-input--mono" type="text" id="pcp" name="prefill_cp" placeholder="44100" maxlength="5">
                            </div>
                        </div>
                        <div class="gl-grid gl-grid-4" style="margin-bottom:10px;">
                            <div class="gl-field" style="grid-column:span 2;">
                                <label class="gl-label" for="pcal">Calle</label>
                                <input class="gl-input" type="text" id="pcal" name="prefill_calle" placeholder="Av. Independencia">
                            </div>
                            <div class="gl-field">
                                <label class="gl-label" for="pext">Núm. ext.</label>
                                <input class="gl-input" type="text" id="pext" name="prefill_num_ext" placeholder="100">
                            </div>
                            <div class="gl-field">
                                <label class="gl-label" for="pint">Núm. int.</label>
                                <input class="gl-input" type="text" id="pint" name="prefill_num_int" placeholder="—">
                            </div>
                        </div>
                        <div class="gl-grid gl-grid-3" style="margin-bottom:14px;">
                            <div class="gl-field" style="grid-column:span 2;">
                                <label class="gl-label" for="pcol">Colonia</label>
                                <input class="gl-input" type="text" id="pcol" name="prefill_colonia" placeholder="Centro Histórico">
                            </div>
                        </div>

                        <div class="gl-section-title">Datos bancarios</div>
                        <div class="gl-grid gl-grid-3">
                            <div class="gl-field">
                                <label class="gl-label" for="pbanco">Banco</label>
                                <select class="gl-select" id="pbanco" name="prefill_banco">
                                    <option value="">— No especificado —</option>
                                    <?php foreach ($bancos as $b): ?>
                                        <option><?= e($b) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="gl-field">
                                <label class="gl-label" for="ptitular">Titular</label>
                                <input class="gl-input" type="text" id="ptitular" name="prefill_titular_cuenta" placeholder="Nombre en el banco">
                            </div>
                            <div class="gl-field">
                                <label class="gl-label" for="pclabe">CLABE</label>
                                <input class="gl-input gl-input--mono" type="text" id="pclabe" name="prefill_clabe" placeholder="18 dígitos" maxlength="18">
                            </div>
                        </div>

                    </div><!-- /prefill -->
                </div>

                <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                    <button type="submit" class="pats-chip pats-chip--action" id="glBtnGenerar">
                        🔗 Generar link
                    </button>
                    <button type="reset" class="pats-chip" onclick="resetTipo()">
                        Limpiar
                    </button>
                </div>

            </form>
        </div>
    </div>

</div>
</content>

<div id="glToast"></div>

<script>
window.GL_ENDPOINTS = {
    distribuidor: 'endpoints/distribucion_links_crear.php',
    franquicia:   'endpoints/franquicia_links_crear.php',
};
window.GL_TIPO_INICIAL = <?= json_encode($tipoInicial) ?>;

let glTipo = null;

/* ── Toast ────────────────────────────────────────────────────────────────────── */
let _toastTimer;
function glToast(msg, type = 'info', ms = 3500) {
    const el = document.getElementById('glToast');
    el.textContent = msg;
    el.style.background = type === 'error' ? '#991b1b' : type === 'success' ? '#065f46' : '#1e293b';
    el.classList.add('show');
    clearTimeout(_toastTimer);
    _toastTimer = setTimeout(() => el.classList.remove('show'), ms);
}

/* ── Contraseña ──────────────────────────────────────────────────────────────── */
function generarPassword() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    let pw = '';
    for (let i = 0; i < 8; i++) pw += chars.charAt(Math.floor(Math.random() * chars.length));
    document.getElementById('password').value = pw;
}

function copiarPw() {
    const v = document.getElementById('password').value;
    if (!v) return;
    navigator.clipboard?.writeText(v).then(() => glToast('Contraseña copiada ✓', 'success'));
}

/* ── Selector de tipo ────────────────────────────────────────────────────────── */
function selectTipo(tipo) {
    glTipo = tipo;
    document.getElementById('hTipo').value = tipo;
    document.getElementById('cardDist').classList.toggle('selected', tipo === 'distribuidor');
    document.getElementById('cardFranq').classList.toggle('selected', tipo === 'franquicia');

    const cardConfig = document.getElementById('cardConfig');
    cardConfig.classList.remove('hidden');

    const amountEl   = document.getElementById('amount');
    const amountHint = document.getElementById('amountHint');
    const secIdent   = document.getElementById('secIdentificacion');
    const wrapPago   = document.getElementById('wrapPago');

    if (tipo === 'franquicia') {
        document.getElementById('configTitle').textContent = 'Configuración — Franquiciatario';
        amountEl.value = '999999';
        amountEl.readOnly = true;
        amountEl.classList.add('gl-input--readonly');
        amountHint.textContent = 'Fijo $999,999 para franquicias';
        wrapPago.style.opacity = '0.4';
        wrapPago.style.pointerEvents = 'none';
        document.getElementById('pago_si').checked = true;
        secIdent.style.display = 'none';
    } else {
        document.getElementById('configTitle').textContent = 'Configuración — Distribuidor';
        amountEl.value = '20000';
        amountEl.max = '20000';
        amountEl.readOnly = false;
        amountEl.classList.remove('gl-input--readonly');
        amountHint.textContent = 'Máx. $20,000 para distribuidores';
        wrapPago.style.opacity = '';
        wrapPago.style.pointerEvents = '';
        secIdent.style.display = '';
    }

    if (!document.getElementById('password').value) generarPassword();
    document.getElementById('glAlertBox').innerHTML = '';
    cardConfig.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function togglePago(val) {
    const w = document.getElementById('wrapMonto');
    w.style.opacity = val ? '' : '0.4';
    w.style.pointerEvents = val ? '' : 'none';
}

function resetTipo() {
    glTipo = null;
    document.getElementById('cardDist').classList.remove('selected');
    document.getElementById('cardFranq').classList.remove('selected');
    document.getElementById('cardConfig').classList.add('hidden');
    document.getElementById('glAlertBox').innerHTML = '';
    document.getElementById('password').value = '';
    document.getElementById('glPrefillBody').classList.remove('open');
    document.getElementById('glPrefillToggle').classList.remove('open');
}

/* ── Prefill toggle ──────────────────────────────────────────────────────────── */
document.getElementById('glPrefillToggle').addEventListener('click', function () {
    const open = document.getElementById('glPrefillBody').classList.toggle('open');
    this.classList.toggle('open', open);
});

/* ── Submit ──────────────────────────────────────────────────────────────────── */
document.getElementById('frmGenerar').addEventListener('submit', function (e) {
    e.preventDefault();

    if (!glTipo) { glToast('Selecciona un tipo de solicitud.', 'error'); return; }

    const pw = document.getElementById('password').value.trim();
    if (!pw) { glToast('La contraseña no puede estar vacía.', 'error'); return; }

    const btn = document.getElementById('glBtnGenerar');
    btn.disabled = true;
    btn.textContent = 'Generando...';

    const fd = new FormData(this);

    if (glTipo === 'distribuidor') {
        const conPago = document.querySelector('input[name="con_pago"]:checked')?.value === '1';
        const monto = parseFloat(document.getElementById('amount').value) || 0;
        if (conPago && monto > 20000) {
            glToast('El monto máximo para distribuidores es $20,000.', 'error');
            btn.disabled = false; btn.textContent = '🔗 Generar link';
            return;
        }
        fd.set('type_pay', conPago ? 'card' : 'free');
        fd.set('amount', conPago ? monto : 0);
    }

    fetch(GL_ENDPOINTS[glTipo], { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.textContent = '🔗 Generar link';

            if (!data.ok) {
                document.getElementById('glAlertBox').innerHTML = `
                    <div class="gl-alert gl-alert--error"><i>⚠</i>
                        <div>${data.error || 'No se pudo crear el link.'}</div>
                    </div>`;
                document.getElementById('glAlertBox').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                return;
            }

            document.getElementById('glAlertBox').innerHTML = `
                <div class="gl-alert gl-alert--success"><i>✓</i>
                    <div style="width:100%;">
                        <div style="font-weight:700;margin-bottom:6px;">Link generado correctamente</div>
                        <div style="font-size:11px;opacity:.75;margin-bottom:4px;">URL DEL FORMULARIO</div>
                        <div class="gl-result-row">
                            <a href="${data.url}" target="_blank">${data.url}</a>
                            <button class="gl-copy-btn" onclick="glCopy('${data.url}')">📋</button>
                        </div>
                        <div style="font-size:11px;opacity:.75;margin:10px 0 4px;">CONTRASEÑA DE ACCESO</div>
                        <div class="gl-result-row">
                            <span style="font-weight:700;letter-spacing:.05em;">${pw}</span>
                            <button class="gl-copy-btn" onclick="glCopy('${pw}')">📋</button>
                        </div>
                    </div>
                </div>`;
            document.getElementById('glAlertBox').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            generarPassword();
        })
        .catch(() => {
            btn.disabled = false;
            btn.textContent = '🔗 Generar link';
            glToast('Error de conexión.', 'error');
        });
});

/* ── Copiar ──────────────────────────────────────────────────────────────────── */
function glCopy(text) {
    navigator.clipboard?.writeText(text)
        .then(() => glToast('Copiado ✓', 'success'))
        .catch(() => {
            const ta = document.createElement('textarea');
            ta.value = text; ta.style.cssText = 'position:fixed;opacity:0';
            document.body.appendChild(ta); ta.select();
            document.execCommand('copy'); document.body.removeChild(ta);
            glToast('Copiado ✓', 'success');
        });
}

/* ── Formato inputs ──────────────────────────────────────────────────────────── */
document.getElementById('pr').addEventListener('input', e => { e.target.value = e.target.value.toUpperCase(); });
document.getElementById('pt').addEventListener('input', e => { e.target.value = e.target.value.replace(/\D/g, '').slice(0, 10); });
document.getElementById('pclabe').addEventListener('input', e => { e.target.value = e.target.value.replace(/\D/g, '').slice(0, 18); });
document.getElementById('pcp').addEventListener('input', e => { e.target.value = e.target.value.replace(/\D/g, '').slice(0, 5); });
document.getElementById('amount').addEventListener('input', function () {
    if (glTipo === 'distribuidor' && parseFloat(this.value) > 20000) this.value = 20000;
});

/* ── Pre-selección desde URL ─────────────────────────────────────────────────── */
if (GL_TIPO_INICIAL) selectTipo(GL_TIPO_INICIAL);
</script>

</body>
</html>
