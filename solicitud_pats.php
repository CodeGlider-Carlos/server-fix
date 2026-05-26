<?php
/*
ez/pats/solicitud_pats.php
Formulario público PATS convertido desde Laravel Blade a PHP normal.
Versión integrada al flujo real PATS local:
- Conexión real PATS por mysqli: ../../varSQL/bd_pats.php y ../../varSQL/var_pats.php
- Resolver real de token: ez/pats/public_checkout_resolver.php
- Preview real de contrato: ez/pats/endpoints/public_contract_preview.php
- Orden real de checkout: ez/pats/endpoints/public_checkout_generar_orden.php

Rutas destino:
- ez/pats/solicitud_pats.php
- ez/pats/endpoints/public_contract_preview.php
- ez/pats/endpoints/public_pasaporte_vigente_validar.php
- ez/pats/lib/contratos.php
- ez/pats/templates/contrato_pats_base.php

Puntos ya integrados:
1. Token opcional:
   - Con token: resuelve actor comercial cuando el resolver existe y el token es válido.
   - Sin token: se considera venta directa ADMINPATS / corporativo.

2. Nacionalidad:
   - Mexicano: CURP, identificación/INE, RFC/CIF si aplica.
   - Extranjero/no mexicano: no CURP, no INE, no CIF mexicana.
   - Extranjero/no mexicano: se pide identificación oficial o pasaporte; al menos frente obligatorio y reverso opcional.
   - Las etiquetas cambian automáticamente para no mostrar INE/CURP/CIF cuando no aplica.

3. Menores:
   - Paciente real: menor.
   - Firmante/administrador: mamá, papá o tutor.
   - Menor mexicano: CURP documental del menor.
   - Menor extranjero: identificación/pasaporte del menor frente obligatorio.
   - CIF fiscal corresponde al tutor/responsable cuando aplique, no al menor.
   - IMPORTANTE: doc_curp siempre corresponde al paciente real mexicano, no al tutor.
   - Si el menor es extranjero, el tutor/responsable también se adapta como extranjero/no mexicano para no pedir INE/CURP/CIF mexicana.

4. Paciente dependiente / representado:
   - Se integró una pregunta amigable: “¿Quién firmará y administrará este pasaporte?”
   - Opciones visibles para usuario:
     a) Yo mismo
     b) Mi mamá, papá o tutor
     c) Un responsable autorizado
   - Internamente se guarda:
     modo_firma = FIRMA_PROPIA | TUTOR_FAMILIAR | RESPONSABLE_AUTORIZADO
     requiere_responsable = 0/1
     tipo_representacion = FIRMA_PROPIA | TUTOR_FAMILIAR | RESPONSABLE_AUTORIZADO
   - Si requiere responsable:
     paciente real = persona que usará PATS
     responsable = persona que firma, recibe accesos y administra cuenta
   - Para adultos/dependientes se captura:
     relacion_responsable_paciente
     motivo_responsable
     doc_acreditacion_representacion (opcional/según política)
   - Dependiente mexicano con responsable: también debe adjuntar CURP documental del paciente/dependiente en doc_curp.
   - Dependiente extranjero con responsable: no CURP; identificación/pasaporte frente obligatorio.
   - El contrato cambia redacción: ya no dice “por propio derecho” cuando firma un responsable.

5. Adulto mayor:
   - Ya no captura acompañantes manuales.
   - Valida 2 pasaportes vigentes existentes por id_pasaporte + fecha_nacimiento.
   - Endpoint incluido: public_pasaporte_vigente_validar.php
   - No puede avanzar si no valida 2 pasaportes vigentes diferentes.

6. Contrato completo:
   - contrato_pats_base.php contiene carátula, declaraciones, cláusulas, anexos, aviso de privacidad y dueño beneficiario.
   - Se adaptó para:
     - mexicano / extranjero
     - menor / tutor
     - paciente dependiente / responsable autorizado
     - adulto mayor
   - El contrato que se firma debe guardarse completo en HTML con hash y firma digital.

Pendiente en public_checkout_generar_orden.php:
- Guardar campos nuevos de nacionalidad/documento:
  nacionalidad_tipo, nacionalidad, pais_nacimiento,
  tipo_documento_identidad, pais_documento_identidad, numero_documento_identidad.

- Guardar modalidad de firma/representación:
  modo_firma, requiere_responsable, tipo_representacion,
  relacion_responsable_paciente, motivo_responsable.

- Guardar tutor/responsable y sus documentos cuando aplique:
  tutor_nombre, tutor_apellido_pa, tutor_apellido_ma,
  tutor_curp, tutor_rfc, tutor_fecha_nacimiento,
  tutor_correo, tutor_telefono,
  tutor_nacionalidad_tipo, tutor_nacionalidad,
  tutor_pais_nacimiento, tutor_tipo_documento_identidad,
  tutor_pais_documento_identidad, tutor_numero_documento_identidad.

- Guardar relación adulto mayor con 2 pasaportes vigentes:
  adulto_mayor_pasaportes_validados,
  adulto_mayor_pasaporte_1_json,
  adulto_mayor_pasaporte_2_json.

- Guardar documentos nuevos:
  doc_identificacion_frente,
  doc_identificacion_reverso,
  doc_curp,  // CURP documental del paciente real mexicano: adulto, menor o dependiente
  doc_comprobante_domicilio,
  doc_constancia_fiscal,
  tutor_doc_identificacion_frente,
  tutor_doc_identificacion_reverso,
  tutor_doc_curp,
  tutor_doc_constancia_fiscal,
  doc_acreditacion_representacion.

- Guardar contrato firmado completo:
  html_contrato_renderizado,
  hash_contrato,
  firma_base64,
  nombre_firmante,
  fecha_firma,
  ip_firma,
  user_agent_firma.

Notas operativas:
- El formulario usa lenguaje amigable para el usuario, pero mantiene campos técnicos en hidden/POST para backend.
- No capturar número completo de tarjeta en el formulario. La pasarela debe devolver marca/terminación/banco cuando aplique.
- El preview de contrato no guarda nada; solo renderiza.

*/

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once '../../varSQL/bd_pats.php';
require_once '../../varSQL/var_pats.php';

/*
  Stripe:
  Archivo mínimo esperado:
  - ez/pats/config/config.php

  Debe definir:
  - STRIPE_PUBLIC_KEY
  - STRIPE_SECRET_KEY

  No debe reemplazar la conexión real del módulo PATS.
  La conexión sigue viniendo de ../../varSQL/bd_pats.php y ../../varSQL/var_pats.php
*/
$__stripeConfig = __DIR__ . '/config/config.php';
if (is_file($__stripeConfig)) {
    require_once $__stripeConfig;
}

$__resolver = __DIR__ . '/public_checkout_resolver.php';
if (is_file($__resolver)) {
    require_once $__resolver;
}

$ver = time();
$cx = $conexionMod ?? $db_all2 ?? $db_all ?? null;

if (!$cx || !($cx instanceof mysqli)) {
    http_response_code(500);
    die('No hay conexión mysqli disponible');
}

if (!function_exists('e')) {
    function e($s): string {
        return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('pats_estados_mx_map_local')) {
    function pats_estados_mx_map_local(): array {
        return [
            'AGS'  => 'Aguascalientes',
            'BCN'  => 'Baja California',
            'BCS'  => 'Baja California Sur',
            'CAM'  => 'Campeche',
            'CHP'  => 'Chiapas',
            'CHH'  => 'Chihuahua',
            'CDMX' => 'Ciudad de México',
            'COA'  => 'Coahuila',
            'COL'  => 'Colima',
            'DGO'  => 'Durango',
            'GTO'  => 'Guanajuato',
            'GRO'  => 'Guerrero',
            'HGO'  => 'Hidalgo',
            'JAL'  => 'Jalisco',
            'MEX'  => 'Estado de México',
            'MIC'  => 'Michoacán',
            'MOR'  => 'Morelos',
            'NAY'  => 'Nayarit',
            'NLE'  => 'Nuevo León',
            'OAX'  => 'Oaxaca',
            'PUE'  => 'Puebla',
            'QRO'  => 'Querétaro',
            'ROO'  => 'Quintana Roo',
            'SLP'  => 'San Luis Potosí',
            'SIN'  => 'Sinaloa',
            'SON'  => 'Sonora',
            'TAB'  => 'Tabasco',
            'TAM'  => 'Tamaulipas',
            'TLAX' => 'Tlaxcala',
            'VER'  => 'Veracruz',
            'YUC'  => 'Yucatán',
            'ZAC'  => 'Zacatecas',
        ];
    }
}

if (!function_exists('pats_estado_nombre_desde_acronimo_local')) {
    function pats_estado_nombre_desde_acronimo_local(string $acr): string {
        $map = pats_estados_mx_map_local();
        $acr = strtoupper(trim($acr));
        return $map[$acr] ?? $acr;
    }
}

if (empty($_SESSION['_csrf_pats_publico'])) {
    $_SESSION['_csrf_pats_publico'] = bin2hex(random_bytes(24));
}
$csrf = (string)$_SESSION['_csrf_pats_publico'];

$token = trim((string)($_GET['t'] ?? $_POST['token_publico'] ?? ''));

/* Valores oficiales PATS. */
$montoMensual = 800.0;
$montoAnual   = 9600.0;

$estados = pats_estados_mx_map_local();
$pais = 'México';
$estadoAcronimo = 'JAL';
$estadoNombre = pats_estado_nombre_desde_acronimo_local($estadoAcronimo);

/*
  Sin token = venta directa ADMINPATS/corporativo.
  Con token = se resuelve el actor dueño del link público.
*/
$ctx = [
    'id_distribuidor' => 0,
    'id_franquicia'   => 0,
    'id_gestor'       => 0,
    'pais'            => 'México',
    'region'          => '',
    'zona'            => '',
    'unidad'          => '',
    'tipo_origen'     => 'ADMINPATS',
];

if ($token !== '') {
    if (!function_exists('pats_resolve_public_checkout_token')) {
        http_response_code(500);
        die('No está disponible public_checkout_resolver.php');
    }

    $resolved = pats_resolve_public_checkout_token($cx, $token);

    if (!$resolved || !is_array($resolved)) {
        http_response_code(404);
        die('El link público no es válido o ya no está activo');
    }

    $ctx = array_merge($ctx, $resolved);

    /* Normalización defensiva por si el resolver no trae tipo_origen. */
    if (empty($ctx['tipo_origen'])) {
        if (!empty($ctx['id_distribuidor'])) {
            $ctx['tipo_origen'] = 'DISTRIBUIDOR';
        } elseif (!empty($ctx['id_franquicia'])) {
            $ctx['tipo_origen'] = 'FRANQUICIA';
        } elseif (!empty($ctx['id_gestor'])) {
            $ctx['tipo_origen'] = 'GESTOR';
        } else {
            $ctx['tipo_origen'] = 'ADMINPATS';
        }
    }
}

$pais = trim((string)($ctx['pais'] ?? '')) ?: 'México';

/* La región del token suele venir como acrónimo de estado. */
$regionCtx = strtoupper(trim((string)($ctx['region'] ?? '')));
if ($regionCtx !== '') {
    $estadoAcronimo = $regionCtx;
    $estadoNombre = pats_estado_nombre_desde_acronimo_local($estadoAcronimo);
}

/* Si por alguna razón el ctx trae estado textual, se respeta como nombre visible. */
if (!empty($ctx['estado']) && is_string($ctx['estado'])) {
    $estadoNombre = (string)$ctx['estado'];
}

/* Catálogo de precios opcional, por si existe en la BD PATS. Mantiene defaults si no aplica. */
try {
    $rs = $cx->query("SELECT frecuencia, precio FROM pats_cat_precios WHERE activo = 1 AND tipo = 'PATS' ORDER BY id_tipo_precio ASC");
    if ($rs instanceof mysqli_result) {
        while ($px = $rs->fetch_assoc()) {
            $freq = strtoupper(trim((string)($px['frecuencia'] ?? '')));
            $precio = (float)($px['precio'] ?? 0);
            if ($precio > 0 && $freq === 'MENSUAL') $montoMensual = $precio;
            if ($precio > 0 && $freq === 'ANUAL')   $montoAnual   = $precio;
        }
        $rs->free();
    }
} catch (Throwable $e) {
    /* Defaults oficiales. */
}

$stepsInfo = [
    ['label'=>'Acceso',       'desc'=>'Correo y teléfono',     'icon'=>'mdi-email-check-outline'],
    ['label'=>'Personales',   'desc'=>'Nombre y CURP',         'icon'=>'mdi-account-outline'],
    ['label'=>'Domicilio',    'desc'=>'Tu dirección',           'icon'=>'mdi-map-marker-outline'],
    ['label'=>'Documentos',   'desc'=>'INE y CURP',             'icon'=>'mdi-folder-open-outline'],
    ['label'=>'Fotografía',   'desc'=>'Tu selfie de acceso',    'icon'=>'mdi-camera-account'],
    ['label'=>'Contrato',     'desc'=>'Firma y pago',           'icon'=>'mdi-file-sign'],
];

$docsRequeridos = [
    /*
      DOCUMENTOS DEL PACIENTE / AFILIADO REAL
      - México adulto: identificación oficial/INE frente y reverso + CURP documental + comprobante domicilio + CIF opcional.
      - Extranjero adulto: identificación/pasaporte frente obligatorio; reverso opcional; NO CURP; NO CIF mexicana.
      - Menor mexicano: CURP documental del menor + fotografía; NO INE del menor; CIF corresponde al tutor si aplica.
      - Menor extranjero: identificación/pasaporte del menor frente obligatorio; reverso opcional; NO CURP; NO CIF.
      - Dependiente mexicano con responsable: SÍ debe cargar CURP documental del paciente/dependiente.
      - Dependiente extranjero con responsable: NO CURP; identificación/pasaporte frente obligatorio.
      El JS decide visibilidad y required según nacionalidad_tipo + edad + modo_firma.
    */
    ['id'=>'doc_identificacion_frente',  'icon'=>'mdi-card-account-details',        'label'=>'Identificación / INE frente',          'req'=>true,  'role'=>'identificacion_frente'],
    ['id'=>'doc_identificacion_reverso', 'icon'=>'mdi-card-account-details-outline', 'label'=>'Identificación / INE reverso (opcional)', 'req'=>false, 'role'=>'identificacion_reverso'],
];

$stripePublicKey = defined('STRIPE_PUBLIC_KEY') ? (string)STRIPE_PUBLIC_KEY : '';

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= e($csrf) ?>">
  <title>PATS · Activa tu Pasaporte</title>
  <link rel="icon" type="image/x-icon" href="../../img/ez.ico">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/@mdi/font@7.4.47/css/materialdesignicons.min.css" rel="stylesheet">

  <style>
    :root {
      --navy:       #0d1b3e;
      --navy-2:     #162550;
      --blue:       #1d4ed8;
      --blue-mid:   #2563eb;
      --blue-light: #3b82f6;
      --cyan:       #06b6d4;
      --cyan-light: #22d3ee;
      --surface:    #ffffff;
      --surface-2:  #f8faff;
      --border:     rgba(37,99,235,.13);
      --border-2:   rgba(37,99,235,.22);
      --slate-100:  #f0f4ff;
      --slate-300:  #cbd5e1;
      --slate-400:  #94a3b8;
      --slate-500:  #64748b;
      --slate-600:  #475569;
      --slate-700:  #334155;
      --slate-800:  #1e293b;
      --success:    #10b981;
      --success-bg: #ecfdf5;
      --danger:     #ef4444;
      --danger-bg:  #fff1f2;
      --warning:    #f59e0b;
      --shadow-sm:  0 1px 4px rgba(13,27,62,.08),0 1px 2px rgba(0,0,0,.04);
      --shadow-md:  0 4px 18px rgba(13,27,62,.10),0 2px 6px rgba(0,0,0,.05);
      --shadow-lg:  0 12px 40px rgba(13,27,62,.13),0 4px 12px rgba(0,0,0,.06);
      --shadow-card:0 0 0 1px var(--border),var(--shadow-lg);
      --radius-sm:  10px;
      --radius:     16px;
      --radius-lg:  22px;
      --radius-xl:  28px;
      --font:       'Plus Jakarta Sans',system-ui,sans-serif;
      --mono:       'JetBrains Mono',monospace;
    }

    *,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
    html { scroll-behavior:smooth; }

    body {
      font-family: var(--font);
      background: var(--slate-100);
      color: var(--slate-800);
      min-height: 100vh;
      -webkit-font-smoothing: antialiased;
    }

    /* ── TOPBAR ── */
    .topbar {
      position: sticky; top:0; z-index:100;
      background: rgba(13,27,62,.97);
      backdrop-filter: blur(20px);
      border-bottom: 1px solid rgba(255,255,255,.07);
      height: 60px; padding: 0 28px;
      display: flex; align-items:center; justify-content:space-between;
    }
    .topbar__brand {
      display:flex; align-items:center; gap:10px;
      font-size:17px; font-weight:800; color:#fff; letter-spacing:-.02em;
    }
    .topbar__brand i { font-size:22px; color:var(--cyan); }
    .topbar__tag {
      font-size:10.5px; font-weight:700; letter-spacing:.10em; text-transform:uppercase;
      color:rgba(255,255,255,.35);
    }
    .topbar__badge {
      display:flex; align-items:center; gap:6px;
      font-size:11.5px; font-weight:600; color:var(--cyan);
      background:rgba(6,182,212,.12); border:1px solid rgba(6,182,212,.3);
      padding:5px 14px; border-radius:100px;
    }
    .topbar__badge i { font-size:13px; }

    /* ── LAYOUT ── */
    .layout {
      max-width:1160px; margin:0 auto;
      padding:36px 24px 80px;
      display:grid;
      grid-template-columns:270px 1fr;
      gap:28px;
      align-items:start;
    }

    /* ── SIDEBAR ── */
    .sidebar { position:sticky; top:80px; }

    .sidebar__hero {
      background: linear-gradient(145deg, var(--navy) 0%, var(--navy-2) 40%, #0e2d6a 100%);
      border-radius: var(--radius-lg);
      padding: 28px 24px;
      color: white;
      margin-bottom: 14px;
      position: relative; overflow:hidden;
      box-shadow: 0 8px 32px rgba(13,27,62,.35);
    }
    .sidebar__hero::before {
      content:''; position:absolute;
      top:-50px; right:-50px;
      width:180px; height:180px; border-radius:50%;
      background:radial-gradient(circle, rgba(6,182,212,.18) 0%, transparent 70%);
    }
    .sidebar__hero::after {
      content:''; position:absolute;
      bottom:-30px; left:-30px;
      width:130px; height:130px; border-radius:50%;
      background:radial-gradient(circle, rgba(29,78,216,.22) 0%, transparent 70%);
    }
    .hero__icon {
      width:52px; height:52px; border-radius:14px;
      background:linear-gradient(135deg,rgba(6,182,212,.3),rgba(29,78,216,.3));
      border:1px solid rgba(255,255,255,.15);
      display:flex; align-items:center; justify-content:center;
      font-size:24px; margin-bottom:16px; position:relative; z-index:1;
    }
    .hero__title {
      font-size:19px; font-weight:800; line-height:1.25;
      margin-bottom:5px; position:relative; z-index:1;
    }
    .hero__sub {
      font-size:12.5px; opacity:.65; line-height:1.55;
      position:relative; z-index:1;
    }
    .hero__price {
      background:rgba(255,255,255,.09);
      border:1px solid rgba(255,255,255,.15);
      border-radius:12px; padding:14px 16px;
      margin-top:18px; position:relative; z-index:1;
    }
    .hero__price-label {
      font-size:10px; font-weight:700; letter-spacing:.10em;
      text-transform:uppercase; opacity:.6; margin-bottom:4px;
    }
    .hero__price-row {
      display:flex; align-items:baseline; gap:6px;
    }
    .hero__price-amount {
      font-size:26px; font-weight:800; line-height:1;
    }
    .hero__price-freq {
      font-size:12px; opacity:.65;
    }
    .hero__secure {
      display:flex; align-items:center; gap:6px;
      margin-top:10px; font-size:11.5px; opacity:.55;
    }
    .hero__secure i { font-size:13px; color:var(--cyan); opacity:1; }

    /* ── STEP LIST ── */
    .step-box {
      background: var(--surface);
      border:1px solid var(--border);
      border-radius: var(--radius);
      padding:18px 20px;
      box-shadow: var(--shadow-sm);
    }
    .step-box__label {
      font-size:10.5px; font-weight:700; letter-spacing:.09em;
      text-transform:uppercase; color:var(--slate-400); margin-bottom:16px;
    }
    .step-list {
      list-style:none; position:relative;
    }
    .step-list::before {
      content:''; position:absolute;
      left:15px; top:8px; bottom:8px;
      width:2px; background:var(--border);
      border-radius:2px;
    }
    .step-list__fill {
      position:absolute; left:15px; top:8px;
      width:2px; background:linear-gradient(to bottom, var(--blue-mid), var(--cyan));
      border-radius:2px; transition:height .5s cubic-bezier(.65,0,.35,1); height:0;
    }
    .step-item {
      display:flex; align-items:center; gap:12px;
      padding:8px 0; cursor:default;
      position:relative; z-index:1;
    }
    .step-item--back { cursor:pointer; }
    .step-num {
      width:32px; height:32px; flex-shrink:0; border-radius:50%;
      display:flex; align-items:center; justify-content:center;
      font-size:12px; font-weight:700;
      background:var(--slate-100); border:2px solid var(--border);
      color:var(--slate-400); transition:all .3s cubic-bezier(.34,1.56,.64,1);
    }
    .step-item.is-active .step-num {
      background:var(--blue-mid); border-color:var(--blue-mid);
      color:#fff; box-shadow:0 0 0 5px rgba(37,99,235,.15);
      transform:scale(1.08);
    }
    .step-item.is-done .step-num {
      background:var(--success); border-color:var(--success); color:#fff;
    }
    .step-item.is-done .step-num::before { content:''; }
    .step-label {
      font-size:13px; font-weight:600; color:var(--slate-400); line-height:1.2;
    }
    .step-desc { font-size:11px; color:var(--slate-300); margin-top:1px; }
    .step-item.is-active .step-label { color:var(--blue-mid); }
    .step-item.is-done  .step-label  { color:var(--slate-600); }

    /* ── MAIN CARD ── */
    .main-card {
      background:var(--surface);
      border-radius:var(--radius-xl);
      box-shadow:var(--shadow-card);
      overflow:hidden;
    }

    /* Progress bar */
    .progress-wrap { height:4px; background:var(--slate-100); }
    .progress-fill {
      height:100%; width:16.66%;
      background:linear-gradient(90deg, var(--blue-mid), var(--cyan));
      border-radius:0 4px 4px 0;
      transition:width .55s cubic-bezier(.65,0,.35,1);
      position:relative;
    }
    .progress-fill::after {
      content:''; position:absolute;
      right:-4px; top:-3px;
      width:10px; height:10px; border-radius:50%;
      background:var(--cyan); box-shadow:0 0 10px var(--cyan);
    }

    /* Card header */
    .card-header {
      padding:30px 38px 24px;
      border-bottom:1px solid var(--slate-100);
      display:flex; align-items:flex-start; justify-content:space-between; gap:16px;
    }
    .card-tag {
      display:inline-flex; align-items:center; gap:6px;
      background:var(--slate-100); border:1px solid var(--border);
      border-radius:100px; padding:4px 12px;
      font-size:10.5px; font-weight:700; letter-spacing:.08em;
      text-transform:uppercase; color:var(--blue-mid);
      margin-bottom:10px;
    }
    .card-title {
      font-size:21px; font-weight:800; color:var(--slate-800);
      letter-spacing:-.02em; margin-bottom:5px;
    }
    .card-desc { font-size:13.5px; color:var(--slate-500); line-height:1.55; }
    .card-counter {
      font-family:var(--mono); font-size:12.5px; font-weight:500;
      color:var(--slate-400); white-space:nowrap;
      background:var(--surface-2); border:1px solid var(--border);
      border-radius:var(--radius-sm); padding:6px 12px; flex-shrink:0;
    }

    /* Card body */
    .card-body { padding:32px 38px; }

    /* Card footer */
    .card-footer {
      padding:20px 38px 26px;
      border-top:1px solid var(--slate-100);
      background:var(--surface-2);
      display:flex; align-items:center; justify-content:space-between; gap:16px;
    }

    /* ── PANELS ── */
    .pp-panel { display:none; }
    .pp-panel.is-active {
      display:block;
      animation:fadeSlide .38s cubic-bezier(.25,.46,.45,.94) both;
    }
    @keyframes fadeSlide {
      from { opacity:0; transform:translateX(20px); }
      to   { opacity:1; transform:translateX(0); }
    }

    /* ── FIELDS ── */
    .fields {
      display:grid; grid-template-columns:1fr 1fr; gap:18px;
    }
    .field { display:flex; flex-direction:column; gap:7px; }
    .field--full { grid-column:1/-1; }

    .label {
      font-size:11.5px; font-weight:700; letter-spacing:.06em;
      text-transform:uppercase; color:var(--slate-500);
      display:flex; align-items:center; gap:6px;
    }
    .label__req { color:var(--blue-mid); }
    .label__opt {
      font-weight:500; font-size:10px; text-transform:none;
      letter-spacing:0; color:var(--slate-300); margin-left:auto;
    }

    /* Input wrap */
    .input-wrap { position:relative; }
    .input-wrap .icon-l {
      position:absolute; left:13px; top:50%; transform:translateY(-50%);
      color:var(--slate-300); font-size:17px; pointer-events:none;
      transition:color .2s;
    }
    .input-wrap:focus-within .icon-l { color:var(--blue-mid); }

    .input, .sel, .ta {
      width:100%; padding:12px 14px;
      font-family:var(--font); font-size:14px; color:var(--slate-800);
      background:var(--surface-2); border:1.5px solid var(--border);
      border-radius:var(--radius-sm); outline:none;
      transition:border-color .18s, box-shadow .18s, background .18s;
      -webkit-appearance:none;
    }
    .input-wrap .input, .input-wrap .sel { padding-left:42px; }
    .input::placeholder { color:var(--slate-300); }
    .input:hover, .sel:hover { border-color:rgba(37,99,235,.28); }
    .input:focus, .sel:focus, .ta:focus {
      border-color:var(--blue-mid);
      box-shadow:0 0 0 3px rgba(37,99,235,.10);
      background:#fff;
    }
    .input[readonly] {
      background:var(--slate-100); color:var(--slate-500);
      cursor:default;
    }
    .sel {
      cursor:pointer;
      background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%2394a3b8' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
      background-repeat:no-repeat; background-position:right 13px center; padding-right:36px;
    }

    /* ── DIVIDER ── */
    .divider {
      grid-column:1/-1; display:flex; align-items:center; gap:12px; margin:4px 0;
    }
    .divider__line { flex:1; height:1px; background:var(--border); }
    .divider__lbl {
      font-size:10.5px; font-weight:700; letter-spacing:.09em;
      text-transform:uppercase; color:var(--slate-300);
    }


    /* ── CORREO DISPONIBLE ── */
    .email-status {
      margin-top:8px;
      padding:9px 11px;
      border-radius:var(--radius-sm);
      border:1px solid var(--border);
      background:var(--surface-2);
      color:var(--slate-500);
      font-size:12px;
      line-height:1.45;
      font-weight:700;
      display:none;
    }
    .email-status.show { display:block; }
    .email-status.is-checking {
      border-color:rgba(37,99,235,.22);
      background:rgba(37,99,235,.05);
      color:var(--blue-mid);
    }
    .email-status.is-ok {
      border-color:rgba(16,185,129,.35);
      background:var(--success-bg);
      color:#047857;
    }
    .email-status.is-error {
      border-color:rgba(239,68,68,.30);
      background:var(--danger-bg);
      color:#b91c1c;
    }

    /* ── IDENTITY PILLS ── */
    .id-pills { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:16px; }
    .id-pill {
      display:inline-flex; align-items:center; gap:6px;
      background:rgba(37,99,235,.07); border:1px solid rgba(37,99,235,.15);
      border-radius:100px; padding:5px 14px;
      font-size:12px; font-weight:600; color:var(--blue-mid);
    }
    .id-pill i { font-size:13px; }

    /* ── MINI CARD (acompañantes) ── */
    .mini-card {
      background:var(--surface-2); border:1.5px solid var(--border);
      border-radius:var(--radius); overflow:hidden;
    }
    .mini-card__head {
      padding:10px 16px;
      background:linear-gradient(135deg, var(--navy), var(--navy-2));
      color:#fff; font-size:12.5px; font-weight:700;
      display:flex; align-items:center; gap:8px;
    }
    .mini-card__head i { font-size:15px; color:var(--cyan); }
    .mini-card__body { padding:16px; }

    /* ── FILE ZONE ── */
    .file-zone {
      position:relative;
      border:2px dashed rgba(37,99,235,.25);
      border-radius:var(--radius-sm);
      background:rgba(37,99,235,.03);
      padding:20px 16px; text-align:center;
      cursor:pointer; transition:all .2s;
    }
    .file-zone:hover, .file-zone.dragover {
      border-color:var(--blue-mid);
      background:rgba(37,99,235,.07);
    }
    .file-zone.filled {
      border-style:solid; border-color:var(--success);
      background:var(--success-bg);
    }
    .file-zone input[type=file] {
      position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%;
    }
    .file-zone__icon { font-size:26px; color:var(--blue-light); margin-bottom:6px; }
    .file-zone.filled .file-zone__icon { color:var(--success); }
    .file-zone__title { font-size:13px; font-weight:600; color:var(--slate-600); }
    .file-zone__sub   { font-size:11px; color:var(--slate-400); margin-top:3px; }
    .file-zone__name  { font-size:12.5px; font-weight:600; color:var(--success); margin-top:6px; display:none; }
    .file-zone.filled .file-zone__name  { display:block; }
    .file-zone.filled .file-zone__title,
    .file-zone.filled .file-zone__sub   { display:none; }

    .docs-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }

    /* ── CAMERA ── */
    .cam-stage {
      position:relative; width:100%; max-width:420px; aspect-ratio:4/3;
      background:var(--slate-800); border-radius:var(--radius); overflow:hidden;
      display:flex; align-items:center; justify-content:center;
      box-shadow:0 8px 24px rgba(0,0,0,.2);
    }
    .cam-stage video, .cam-stage img { width:100%; height:100%; object-fit:cover; }
    .cam-guide {
      position:absolute; width:50%; aspect-ratio:3/4;
      border:2px dashed rgba(6,182,212,.7); border-radius:8px; pointer-events:none;
      box-shadow:0 0 0 1000px rgba(0,0,0,.25);
    }
    .cam-placeholder {
      display:flex; flex-direction:column; align-items:center; gap:10px;
      color:var(--slate-400); padding:40px; text-align:center;
    }
    .cam-placeholder i { font-size:44px; color:var(--slate-500); }
    .cam-placeholder span { font-size:13px; }

    /* ── SIGNATURE ── */
    .sig-wrap {
      background:#fff; border:1.5px solid var(--border);
      border-radius:var(--radius); overflow:hidden;
    }
    .sig-top {
      display:flex; justify-content:space-between; align-items:center;
      padding:12px 16px; border-bottom:1px solid var(--border); flex-wrap:wrap; gap:8px;
    }
    .sig-top__lbl strong { display:block; font-size:13.5px; color:var(--navy); }
    .sig-top__lbl small { font-size:11.5px; color:var(--slate-400); }
    .sig-canvas { width:100%; height:150px; background:#fafcff; cursor:crosshair; touch-action:none; display:block; }

    /* ── CONTRACT ── */
    .contract-box {
      border:1.5px solid var(--border); border-radius:var(--radius); overflow:hidden;
    }
    .contract-banner {
      display:flex; align-items:center; gap:8px;
      background:rgba(37,99,235,.05); border-bottom:1px solid var(--border);
      padding:10px 14px; font-size:12.5px; color:var(--slate-600);
    }
    .contract-banner i { color:var(--blue-mid); font-size:15px; flex-shrink:0; }
    .contract-view {
      max-height:280px; overflow-y:auto; padding:16px;
      font-size:13px; line-height:1.65; color:var(--slate-700); background:#fff;
    }
    .contract-view h3 { font-size:14px; color:var(--navy); margin-bottom:8px; }
    .contract-view h4 { font-size:13px; color:var(--navy); margin:8px 0 4px; }
    .contract-view p  { margin-bottom:6px; }
    .contract-empty   { padding:20px; text-align:center; color:var(--slate-400); font-size:13px; }

    /* ── TERMS ── */
    .terms-box {
      display:flex; align-items:flex-start; gap:12px;
      padding:14px 16px;
      background:rgba(37,99,235,.04); border:1.5px solid var(--border);
      border-radius:var(--radius-sm); cursor:pointer; transition:border-color .2s;
    }
    .terms-box:has(input:checked) { border-color:var(--blue-mid); }
    .terms-box input[type=checkbox] { width:16px; height:16px; flex-shrink:0; margin-top:2px; accent-color:var(--blue-mid); }
    .terms-box__text { font-size:13px; color:var(--slate-600); line-height:1.55; }

    /* ── STRIPE PAYMENT ── */
    .stripe-box {
      border:1.5px solid var(--border);
      border-radius:var(--radius);
      background:#fff;
      overflow:hidden;
    }
    .stripe-box__head {
      display:flex; align-items:center; justify-content:space-between; gap:12px;
      padding:12px 16px;
      background:rgba(37,99,235,.05);
      border-bottom:1px solid var(--border);
      color:var(--slate-700);
      font-size:12.5px;
      font-weight:700;
    }
    .stripe-box__head small {
      color:var(--slate-400);
      font-weight:600;
    }
    .stripe-card-element {
      padding:16px;
      min-height:54px;
      background:#fff;
    }
    .stripe-error {
      display:none;
      padding:10px 14px;
      border-top:1px solid rgba(239,68,68,.16);
      background:var(--danger-bg);
      color:#b91c1c;
      font-size:12.5px;
      font-weight:700;
      line-height:1.45;
    }
    .stripe-error.show { display:block; }
    .stripe-help {
      display:flex; align-items:center; gap:8px;
      padding:10px 14px;
      border-top:1px solid var(--border);
      background:var(--surface-2);
      color:var(--slate-500);
      font-size:11.8px;
      line-height:1.45;
    }
    .stripe-help i { color:var(--cyan); font-size:15px; }

    /* ── MSI · Meses sin intereses ── */
    .msi-panel {
      margin-top:14px; padding:16px;
      background:rgba(6,182,212,.05); border:1.5px solid rgba(6,182,212,.22);
      border-radius:var(--radius); animation:fadeSlide .3s both;
    }
    .msi-panel__label {
      font-size:11px; font-weight:700; letter-spacing:.08em; text-transform:uppercase;
      color:var(--cyan); display:flex; align-items:center; gap:6px; margin-bottom:12px;
    }
    .msi-plan-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(110px,1fr)); gap:10px; }
    .msi-radio { display:none; }
    .msi-card {
      display:flex; flex-direction:column; align-items:center; justify-content:center;
      gap:4px; padding:12px 8px; text-align:center;
      background:var(--surface-2); border:2px solid var(--border);
      border-radius:var(--radius-sm); cursor:pointer; transition:all .18s;
    }
    .msi-card:hover { border-color:rgba(6,182,212,.4); background:#fff; }
    .msi-radio:checked + .msi-card {
      border-color:var(--cyan); background:rgba(6,182,212,.07);
      box-shadow:0 0 0 3px rgba(6,182,212,.12);
    }
    .msi-card__num {
      font-size:22px; font-weight:800; font-family:var(--mono);
      color:var(--navy); line-height:1;
    }
    .msi-radio:checked + .msi-card .msi-card__num { color:var(--cyan); }
    .msi-card__lbl { font-size:10.5px; font-weight:700; color:var(--slate-400); }
    .msi-card__sub { font-size:10px; color:var(--slate-300); }
    .msi-confirm {
      margin-top:12px; width:100%;
      background:linear-gradient(135deg,var(--cyan),var(--blue-mid)); color:#fff;
      box-shadow:0 4px 14px rgba(6,182,212,.28);
    }
    .msi-confirm:hover:not(:disabled) { transform:translateY(-1px); box-shadow:0 6px 20px rgba(6,182,212,.38); }

    /* ── MSI preferencia (visible al elegir Anual) ── */
    .msi-pref-wrap {
      margin-top:10px; padding:12px 14px;
      background:rgba(6,182,212,.06); border:1.5px solid rgba(6,182,212,.22);
      border-radius:var(--radius-sm); animation:fadeSlide .25s both;
    }
    .msi-pref-wrap .label { color:var(--cyan); margin-bottom:6px; }
    .msi-pref-wrap .sel { border-color:rgba(6,182,212,.35); }

    /* ── SUMMARY ── */
    .summary-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
    .summary-item {
      background:var(--surface-2); border:1px solid var(--border);
      border-radius:var(--radius-sm); padding:12px 14px;
    }
    .summary-item__lbl {
      font-size:10.5px; font-weight:700; letter-spacing:.07em;
      text-transform:uppercase; color:var(--slate-400); margin-bottom:4px;
    }
    .summary-item__val { font-size:14px; font-weight:600; color:var(--slate-700); }

    /* ── PAYMENT SELECT ── */
    .pay-cards { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    .pay-radio { display:none; }
    .pay-card {
      display:flex; align-items:flex-start; gap:12px;
      padding:14px 16px;
      background:var(--surface-2); border:2px solid var(--border);
      border-radius:var(--radius-sm); cursor:pointer; transition:all .2s;
    }
    .pay-card:hover { border-color:rgba(37,99,235,.3); }
    .pay-radio:checked + .pay-card {
      border-color:var(--blue-mid); background:rgba(37,99,235,.05);
      box-shadow:0 0 0 3px rgba(37,99,235,.08);
    }
    .pay-dot {
      width:18px; height:18px; flex-shrink:0; border-radius:50%;
      border:2px solid var(--slate-300); margin-top:2px; position:relative; transition:all .2s;
    }
    .pay-radio:checked + .pay-card .pay-dot {
      border-color:var(--blue-mid); background:var(--blue-mid);
    }
    .pay-radio:checked + .pay-card .pay-dot::after {
      content:''; position:absolute; inset:3px; border-radius:50%; background:#fff;
    }
    .pay-card__title { font-size:14px; font-weight:700; color:var(--slate-700); margin-bottom:2px; }
    .pay-card__sub   { font-size:12px; color:var(--slate-400); line-height:1.4; }

    /* ── BUTTONS ── */
    .btn {
      display:inline-flex; align-items:center; gap:8px;
      padding:11px 24px; border-radius:var(--radius-sm);
      font-family:var(--font); font-size:13.5px; font-weight:700;
      border:none; cursor:pointer; transition:all .18s; white-space:nowrap;
    }
    .btn i { font-size:17px; }
    .btn:disabled { opacity:.45; cursor:not-allowed; transform:none !important; box-shadow:none !important; }

    .btn--ghost {
      background:#fff; color:var(--slate-500); border:1.5px solid var(--border);
    }
    .btn--ghost:hover { border-color:rgba(37,99,235,.3); color:var(--slate-700); }

    .btn--primary {
      background:var(--blue-mid); color:#fff;
      box-shadow:0 4px 14px rgba(37,99,235,.28);
    }
    .btn--primary:hover:not(:disabled) {
      background:var(--blue); transform:translateY(-1px);
      box-shadow:0 6px 20px rgba(37,99,235,.35);
    }

    .btn--success {
      background:var(--success); color:#fff;
      box-shadow:0 4px 14px rgba(16,185,129,.28);
    }
    .btn--success:hover:not(:disabled) {
      background:#059669; transform:translateY(-1px);
      box-shadow:0 6px 20px rgba(16,185,129,.35);
    }

    .btn--soft {
      background:linear-gradient(135deg, var(--blue-mid), var(--blue-light)); color:#fff;
      box-shadow:0 4px 14px rgba(37,99,235,.25);
    }
    .btn--soft:hover:not(:disabled) {
      transform:translateY(-1px);
      box-shadow:0 6px 20px rgba(37,99,235,.35);
    }



    /* ── FRIENDLY RESPONSIBLE PERSON SELECTOR ── */
    .choice-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
    .choice-radio { position:absolute; opacity:0; pointer-events:none; }
    .choice-card {
      display:flex; gap:12px; align-items:flex-start;
      padding:14px 15px; min-height:112px;
      border:2px solid var(--border); border-radius:var(--radius);
      background:var(--surface-2); cursor:pointer; transition:all .18s ease;
    }
    .choice-card:hover { border-color:rgba(37,99,235,.32); background:#fff; transform:translateY(-1px); }
    .choice-radio:checked + .choice-card {
      border-color:var(--blue-mid); background:rgba(37,99,235,.06);
      box-shadow:0 0 0 3px rgba(37,99,235,.08);
    }
    .choice-icon {
      width:34px; height:34px; border-radius:12px; flex-shrink:0;
      display:flex; align-items:center; justify-content:center;
      background:rgba(37,99,235,.09); color:var(--blue-mid); font-size:18px;
    }
    .choice-title { font-size:13.5px; font-weight:800; color:var(--slate-800); margin-bottom:4px; }
    .choice-desc { font-size:12px; color:var(--slate-500); line-height:1.45; }
    .soft-alert {
      padding:12px 14px; border-radius:var(--radius-sm); background:rgba(6,182,212,.08);
      border:1px solid rgba(6,182,212,.18); color:var(--slate-700); font-size:13px; line-height:1.55;
      display:flex; gap:10px; align-items:flex-start;
    }
    .soft-alert i { color:var(--cyan); font-size:16px; flex-shrink:0; margin-top:1px; }
    @media (max-width:960px) { .choice-grid { grid-template-columns:1fr; } }

    /* ── TOAST ── */
    #ppToastHost {
      position:fixed; bottom:24px; right:24px; z-index:99999;
      display:flex; flex-direction:column; gap:8px; align-items:flex-end;
      pointer-events:none;
    }

    /* ── MODAL ── */
    .pp-modal {
      position:fixed; inset:0;
      background:rgba(13,27,62,.55); backdrop-filter:blur(4px);
      display:flex; align-items:center; justify-content:center; z-index:9999; padding:16px;
    }
    .pp-modal.pp-hidden { display:none !important; }
    .pp-modal__box {
      background:#fff; border-radius:var(--radius-lg);
      padding:28px; max-width:400px; width:100%;
      box-shadow:0 24px 64px rgba(13,27,62,.22);
    }
    .pp-modal__head {
      font-size:11px; font-weight:700; letter-spacing:.12em;
      text-transform:uppercase; color:var(--blue-mid); margin-bottom:10px;
      display:flex; align-items:center; gap:8px;
    }
    .pp-modal__head i { font-size:16px; }
    .pp-modal__body { font-size:14.5px; color:var(--slate-700); line-height:1.6; margin-bottom:20px; }
    .pp-modal__actions { display:flex; justify-content:flex-end; }


    /* ── VALIDACIONES PATS ESPECIALES ── */
    .passport-status {
      margin-top:10px;
      padding:10px 12px;
      border-radius:var(--radius-sm);
      border:1px solid var(--border);
      background:var(--surface-2);
      color:var(--slate-500);
      font-size:12.5px;
      font-weight:700;
      line-height:1.45;
    }
    .passport-status.is-ok {
      border-color:rgba(16,185,129,.35);
      background:var(--success-bg);
      color:#047857;
    }
    .passport-status.is-error {
      border-color:rgba(239,68,68,.30);
      background:var(--danger-bg);
      color:#b91c1c;
    }

    /* ── UTILITIES ── */
    .pp-hidden { display:none !important; }
    .stack { display:flex; flex-direction:column; gap:14px; }
    .info-note {
      display:flex; align-items:flex-start; gap:10px;
      padding:12px 14px;
      background:rgba(37,99,235,.05); border-left:3px solid var(--blue-mid);
      border-radius:0 var(--radius-sm) var(--radius-sm) 0;
      font-size:13px; color:var(--slate-600); line-height:1.55;
    }
    .info-note i { font-size:16px; color:var(--blue-mid); flex-shrink:0; margin-top:1px; }
/* ─────────────────────────────────────────────
   RESPONSIVE GLOBAL PATS
   Corrige layout completo, no solo header.
───────────────────────────────────────────── */

html,
body {
  max-width: 100%;
  overflow-x: hidden;
}

img,
video,
canvas,
iframe,
svg {
  max-width: 100%;
}

.layout,
.sidebar,
.main-card,
.card-header,
.card-body,
.card-footer,
.fields,
.docs-grid,
.summary-grid,
.pay-cards,
.choice-grid,
.mini-card,
.contract-box,
.stripe-box,
.sig-wrap,
.cam-stage {
  min-width: 0;
}

.input,
.sel,
.ta,
.btn {
  max-width: 100%;
}

/* Tablet / pantallas medianas */
@media (max-width: 1100px) {
  .layout {
    max-width: 100%;
    grid-template-columns: 250px minmax(0, 1fr);
    gap: 22px;
    padding: 28px 18px 72px;
  }

  .card-header,
  .card-body,
  .card-footer {
    padding-left: 28px;
    padding-right: 28px;
  }

  .sidebar__hero {
    padding: 24px 20px;
  }
}

/* Tablet vertical / móvil grande */
@media (max-width: 960px) {
  body {
    background:
      linear-gradient(180deg, #edf4ff 0%, #f7faff 48%, #eef4ff 100%);
  }

  .topbar {
    min-height: 58px;
    height: auto;
    padding: 10px 16px;
    gap: 12px;
  }

  .topbar__brand {
    min-width: 0;
    flex: 1 1 auto;
  }

  .topbar__tag {
    max-width: 42vw;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .topbar__badge {
    flex: 0 0 auto;
    white-space: nowrap;
  }

  .layout {
    grid-template-columns: 1fr;
    padding: 16px 14px 76px;
    gap: 14px;
  }

  .sidebar {
    position: static;
    min-width: 0;
  }

  .sidebar__hero {
    display: none;
  }

  .step-box {
    position: sticky;
    top: 58px;
    z-index: 80;
    display: block;
    overflow: hidden;
    padding: 10px;
    border-radius: 18px;
    background: rgba(255,255,255,.95);
    backdrop-filter: blur(16px);
    box-shadow: 0 8px 24px rgba(13,27,62,.10);
  }

  .step-box__label {
    display: none;
  }

  .step-list {
    display: flex;
    gap: 8px;
    overflow-x: auto;
    overflow-y: hidden;
    padding: 2px 2px 4px;
    scroll-snap-type: x proximity;
    scrollbar-width: none;
  }

  .step-list::-webkit-scrollbar {
    display: none;
  }

  .step-list::before,
  .step-list__fill {
    display: none;
  }

  .step-item {
    flex: 0 0 auto;
    min-width: 118px;
    gap: 8px;
    padding: 7px 9px;
    border-radius: 14px;
    scroll-snap-align: start;
    background: rgba(240,244,255,.75);
    border: 1px solid rgba(37,99,235,.08);
  }

  .step-item.is-active {
    background: rgba(37,99,235,.08);
    border-color: rgba(37,99,235,.20);
  }

  .step-num {
    width: 28px;
    height: 28px;
    font-size: 11px;
    border-width: 1.5px;
  }

  .step-desc {
    display: none;
  }

  .step-label {
    max-width: 74px;
    font-size: 11px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .main-card {
    border-radius: 22px;
  }

  .card-header {
    padding: 20px 20px 16px;
    flex-direction: column;
    gap: 10px;
  }

  .card-title {
    font-size: clamp(20px, 5vw, 24px);
    line-height: 1.15;
  }

  .card-desc {
    font-size: 13px;
  }

  .card-counter {
    align-self: flex-start;
  }

  .card-body {
    padding: 22px 20px;
  }

  .card-footer {
    padding: 14px 20px 20px;
    gap: 12px;
  }

  .fields,
  .docs-grid,
  .summary-grid,
  .pay-cards,
  .choice-grid {
    grid-template-columns: 1fr;
  }

  .choice-card {
    min-height: auto;
  }

  .cam-stage {
    max-width: 100%;
  }

  .contract-view {
    max-height: 340px;
  }
}

/* Móvil */
@media (max-width: 720px) {
  .topbar {
    align-items: flex-start;
    flex-direction: column;
    gap: 8px;
  }

  .topbar__brand {
    width: 100%;
  }

  .topbar__tag {
    max-width: none;
    white-space: normal;
    line-height: 1.3;
  }

  .topbar__badge {
    align-self: flex-start;
    min-height: 28px;
    padding: 5px 12px;
    font-size: 11px;
  }

  .step-box {
    top: 86px;
  }

  .card-footer {
    position: sticky;
    bottom: 0;
    z-index: 70;
    margin: 0 -20px -20px;
    padding: 12px 16px calc(14px + env(safe-area-inset-bottom));
    background: rgba(248,250,255,.97);
    backdrop-filter: blur(16px);
    border-top: 1px solid rgba(37,99,235,.12);
    box-shadow: 0 -10px 26px rgba(13,27,62,.08);
  }

  .card-footer > div {
    width: 100%;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
  }

  .btn {
    min-height: 42px;
  }

  #ppToastHost {
    left: 12px;
    right: 12px;
    bottom: calc(82px + env(safe-area-inset-bottom));
    align-items: stretch;
  }

  #ppToastHost > div {
    max-width: none !important;
    width: 100%;
  }

  .stripe-box__head {
    align-items: flex-start;
    flex-direction: column;
    gap: 4px;
  }

  .sig-top {
    align-items: flex-start;
    flex-direction: column;
  }

  .sig-top .btn {
    width: 100%;
  }
}

/* Móvil pequeño */
@media (max-width: 560px) {
  .layout {
    padding: 12px 10px 76px;
  }

  .topbar {
    padding: 9px 12px;
  }

  .topbar__brand {
    font-size: 15px;
  }

  .topbar__brand i {
    width: 30px;
    height: 30px;
    flex-basis: 30px;
    font-size: 18px;
    border-radius: 10px;
  }

  .topbar__tag {
    display: none;
  }

  .step-box {
    top: 78px;
    padding: 8px;
    border-radius: 16px;
  }

  .step-item {
    min-width: 46px;
    padding: 6px;
    justify-content: center;
  }

  .step-item > div:last-child {
    display: none;
  }

  .step-num {
    width: 30px;
    height: 30px;
  }

  .main-card {
    border-radius: 18px;
  }

  .card-header {
    padding: 18px 16px 14px;
  }

  .card-body {
    padding: 18px 16px;
  }

  .card-footer {
    margin-left: -16px;
    margin-right: -16px;
    margin-bottom: -18px;
    padding-left: 12px;
    padding-right: 12px;
  }

  .card-tag {
    max-width: 100%;
    font-size: 10px;
  }

  .card-title {
    font-size: 20px;
  }

  .card-desc {
    font-size: 12.7px;
  }

  .field {
    gap: 6px;
  }

  .input,
  .sel,
  .ta {
    min-height: 44px;
    font-size: 14px;
    padding-top: 11px;
    padding-bottom: 11px;
  }

  .input-wrap .input,
  .input-wrap .sel {
    padding-left: 40px;
  }

  .label {
    font-size: 10.8px;
  }

  .divider {
    gap: 8px;
  }

  .divider__lbl {
    font-size: 9.8px;
    text-align: center;
  }

  .file-zone {
    padding: 16px 12px;
  }

  .cam-stage {
    border-radius: 14px;
  }

  .sig-canvas {
    height: 180px;
  }

  .summary-item {
    padding: 11px 12px;
  }

  .pp-modal {
    align-items: flex-end;
    padding: 10px;
  }

  .pp-modal__box {
    max-width: none;
    border-radius: 22px;
    padding: 22px;
  }
}

/* Celular muy angosto */
@media (max-width: 390px) {
  .topbar__badge {
    width: 100%;
  }

  .card-footer {
    flex-direction: column-reverse;
    align-items: stretch;
  }

  .card-footer > div {
    width: 100%;
  }

  .card-footer .btn,
  #btnPrev {
    width: 100%;
    justify-content: center;
  }
}
.btn {
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:8px;
  padding:11px 24px;
  min-height:42px;
  border-radius:var(--radius-sm);
  font-family:var(--font);
  font-size:13.5px;
  font-weight:700;
  border:none;
  cursor:pointer;
  transition:all .18s;
  white-space:nowrap;
  text-align:center;
}

/* ── TOPBAR RESPONSIVE PREMIUM ── */
.topbar {
  position: sticky;
  top: 0;
  z-index: 100;
  min-height: 60px;
  height: auto;
  padding: 10px clamp(14px, 3vw, 28px);
  background:
    radial-gradient(circle at top left, rgba(6,182,212,.16), transparent 34%),
    linear-gradient(135deg, rgba(13,27,62,.98), rgba(22,37,80,.98));
  backdrop-filter: blur(20px);
  border-bottom: 1px solid rgba(255,255,255,.08);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
}

.topbar__brand {
  min-width: 0;
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: clamp(15px, 2.2vw, 17px);
  font-weight: 800;
  color: #fff;
  letter-spacing: -.02em;
  line-height: 1.15;
}

.topbar__brand i {
  width: 34px;
  height: 34px;
  border-radius: 12px;
  flex: 0 0 34px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 21px;
  color: var(--cyan);
  background: rgba(6,182,212,.11);
  border: 1px solid rgba(6,182,212,.22);
}

.topbar__tag {
  min-width: 0;
  font-size: 10.5px;
  font-weight: 700;
  letter-spacing: .10em;
  text-transform: uppercase;
  color: rgba(255,255,255,.38);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.topbar__badge {
  flex: 0 0 auto;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  min-height: 30px;
  font-size: 11.5px;
  font-weight: 700;
  color: var(--cyan-light);
  background: rgba(6,182,212,.12);
  border: 1px solid rgba(6,182,212,.30);
  padding: 6px 14px;
  border-radius: 999px;
  white-space: nowrap;
  box-shadow: inset 0 1px 0 rgba(255,255,255,.06);
}

.topbar__badge i {
  font-size: 14px;
}
 #logopats {
  display: block;
  width: min(40%, 220px);
  max-width: 100%;
  height: auto;
  object-fit: contain;
}

@media (max-width: 960px) {
  #logopats {
    width: min(48%, 190px);
  }
}

@media (max-width: 560px) {
  #logopats {
    width: min(62%, 170px);
    margin-inline: auto;
  }
}

@media (max-width: 390px) {
  #logopats {
    width: min(72%, 150px);
  }
}

/* ── Network check popup ─────────────────────────────────── */
.net-check-overlay {
  position:fixed; inset:0; z-index:9999;
  display:flex; align-items:center; justify-content:center;
  background:rgba(0,0,0,.55); backdrop-filter:blur(3px);
}
.net-check-overlay.pp-hidden { display:none; }
.net-check-box {
  background:#fff; border-radius:16px; padding:2rem 2rem 1.5rem;
  max-width:420px; width:calc(100% - 2rem); box-shadow:0 8px 40px rgba(0,0,0,.22);
  text-align:center;
}
.net-check-box .net-icon {
  font-size:3rem; color:var(--primary,#2563eb); margin-bottom:.75rem;
}
.net-check-box h3 {
  margin:0 0 .5rem; font-size:1.1rem; font-weight:700; color:#1e293b;
}
.net-check-box p {
  margin:0 0 1.25rem; font-size:.875rem; color:#475569; line-height:1.5;
}
.net-check-actions {
  display:flex; gap:.75rem; justify-content:center; flex-wrap:wrap;
}
.net-check-actions .btn--secondary {
  background:transparent; border:1.5px solid #cbd5e1; color:#475569;
}
.net-check-actions .btn--secondary:hover { border-color:#94a3b8; }
  </style>
</head>
<body>

  <!-- TOPBAR -->
  <header class="topbar">
    <div class="topbar__brand">
  
     
       <img id="logopats" src="https://50d.com.mx/50D/EZHS/img2/logos/PATS_W.png" alt="Pasaporte a tu Salud" />
    </div>
    <div class="topbar__badge">
      <i class="mdi mdi-shield-check"></i> Proceso seguro
    </div>
  </header>

  <div class="layout">

    <!-- ════ SIDEBAR ════ -->
    <aside class="sidebar">

      <div class="sidebar__hero">
        <div class="hero__icon"><i class="mdi mdi-card-account-details-outline"></i></div>
        <div class="hero__title">Activa tu<br>Pasaporte</div>
        <div class="hero__sub">Acceso completo a la red médica más grande de México.</div>
        <div class="hero__price">
          <div class="hero__price-label">Pasaporte mensual</div>
          <div class="hero__price-row">
            <div class="hero__price-amount">$<?= number_format((float)$montoMensual, 0, '.', ',') ?></div>
            <div class="hero__price-freq">MXN / mes</div>
          </div>
        </div>
        <div class="hero__secure">
          <i class="mdi mdi-lock"></i>
          Pago 100% cifrado · PCI DSS
        </div>
      </div>

      <div class="step-box">
        <div class="step-box__label">Tu progreso</div>
<ul class="step-list" id="ppStepList">
          <div class="step-list__fill" id="ppStepFill"></div><?php foreach ($stepsInfo as $i => $s): ?>
            <li class="step-item <?= $i === 0 ? 'is-active' : '' ?>" data-step="<?= (int)($i + 1) ?>">
              <div class="step-num">
                <span><?= (int)($i + 1) ?></span>
              </div>
              <div>
                <div class="step-label"><?= e($s['label']) ?></div>
                <div class="step-desc"><?= e($s['desc']) ?></div>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>

    </aside>

    <!-- ════ MAIN ════ -->
    <main>
      <form id="frmPagoPatsPublico" novalidate enctype="multipart/form-data" autocomplete="off" data-lpignore="true" data-form-type="other">
        <!--
          Anti-autofill:
          Algunos navegadores/gestores ignoran autocomplete="off" en email/teléfono/nombre.
          Estos campos señuelo fuera de pantalla absorben intentos de autollenado.
        -->
        <div style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;" aria-hidden="true">
          <input type="text" name="fake_user_name" tabindex="-1" autocomplete="username">
          <input type="email" name="fake_email" tabindex="-1" autocomplete="email">
          <input type="password" name="fake_password" tabindex="-1" autocomplete="new-password">
          <input type="tel" name="fake_phone" tabindex="-1" autocomplete="tel">
        </div>

        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <div class="main-card">

          <!-- Progress -->
          <div class="progress-wrap">
            <div class="progress-fill" id="ppProgressFill" style="width:16.66%"></div>
          </div>

          <!-- Header -->
          <div class="card-header">
            <div>
              <div class="card-tag" id="ppCardTag">
                <i class="mdi mdi-email-check-outline"></i> Paso 1 de 6
              </div>
              <h1 class="card-title" id="ppCardTitle">Datos de acceso</h1>
              <p class="card-desc" id="ppCardDesc">Ingresa tu correo y teléfono para comenzar el proceso.</p>
            </div>
            <div class="card-counter" id="ppCardCounter">1 / 6</div>
          </div>

          <!-- Body -->
          <div class="card-body">

            <!-- ── PASO 1: ACCESO ── -->
            <section class="pp-panel is-active" data-step-panel="1">
              <div class="fields">
                <div class="field">
                  <label class="label" for="login_correo">Correo electrónico <span class="label__req">*</span></label>
                  <div class="input-wrap">
                    <i class="mdi mdi-email-outline icon-l"></i>
                    <input class="input" type="email" id="login_correo" placeholder="ejemplo@correo.com" required autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true" data-bwignore="true">
                  </div>
                  <div class="email-status" id="correoStatus"></div>
                </div>
                <div class="field">
                  <label class="label" for="login_telefono">Teléfono celular <span class="label__req">*</span></label>
                  <div class="input-wrap">
                    <i class="mdi mdi-phone-outline icon-l"></i>
                    <input class="input" type="text" id="login_telefono" maxlength="10" inputmode="numeric" placeholder="10 dígitos" required autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true" data-bwignore="true">
                  </div>
                </div>
                <div class="field--full">
                  <div class="info-note">
                    <i class="mdi mdi-information-outline"></i>
                    <span>Estos datos son tu identificación en la plataforma PATS. Asegúrate de capturarlos correctamente.</span>
                  </div>
                </div>
              </div>
            </section>

            <!-- ── PASO 2: PERSONALES ── -->
            <section class="pp-panel" data-step-panel="2">

              <div class="id-pills">
                <div class="id-pill"><i class="mdi mdi-email-outline"></i><span id="pillCorreo">Correo: —</span></div>
                <div class="id-pill"><i class="mdi mdi-phone-outline"></i><span id="pillTelefono">Teléfono: —</span></div>
              </div>

              <div class="fields">
                <div class="field field--full">
                  <label class="label" for="nombre_usuario">Nombre(s) <span class="label__req">*</span></label>
                  <div class="input-wrap">
                    <i class="mdi mdi-account-outline icon-l"></i>
                    <input class="input" type="text" id="nombre_usuario" name="nombre_usuario" placeholder="Nombre(s) de pila" required autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true" data-bwignore="true">
                  </div>
                </div>
                <div class="field">
                  <label class="label" for="apellido_pa">Apellido paterno <span class="label__req">*</span></label>
                  <div class="input-wrap">
                    <i class="mdi mdi-account-outline icon-l"></i>
                    <input class="input" type="text" id="apellido_pa" name="apellido_pa" placeholder="Primer apellido" required autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true" data-bwignore="true">
                  </div>
                </div>
                <div class="field">
                  <label class="label" for="apellido_ma">Apellido materno <span class="label__opt">Opcional</span></label>
                  <div class="input-wrap">
                    <i class="mdi mdi-account-outline icon-l"></i>
                    <input class="input" type="text" id="apellido_ma" name="apellido_ma" placeholder="Segundo apellido" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true" data-bwignore="true">
                  </div>
                </div>
                <div class="field">
                  <label class="label" for="curp_usuario">CURP <span class="label__req">*</span></label>
                  <div class="input-wrap">
                    <i class="mdi mdi-card-account-details-outline icon-l"></i>
                    <input class="input" type="text" id="curp_usuario" name="curp_usuario" maxlength="18" placeholder="18 caracteres" required autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" style="font-family:var(--mono);text-transform:uppercase;">
                  </div>
                </div>
                <div class="field">
                  <label class="label" for="fecha_nacimiento">Fecha de nacimiento <span class="label__req">*</span></label>
                  <div class="input-wrap">
                    <i class="mdi mdi-cake-variant-outline icon-l"></i>
                    <input class="input" type="date" id="fecha_nacimiento" name="fecha_nacimiento" required autocomplete="off" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" style="font-family:var(--mono);">
                  </div>
                </div>
                <div class="field">
                <!--    <label class="label" for="tipo_cliente">Tipo de cliente <span class="label__req">*</span></label> -->
                  <div class="input-wrap">
                    <i class="mdi mdi-account-group-outline icon-l"></i>
                    <select class="sel" id="tipo_cliente" name="tipo_cliente" required hidden>
                      <option value="privado">Privado</option>
                    </select>
                  </div>
                </div>
                <div class="field field--full pp-hidden" id="wrapNombreEmpresa">
                  <label class="label" for="nombre_empresa">Nombre de empresa</label>
                  <div class="input-wrap">
                    <i class="mdi mdi-office-building-outline icon-l"></i>
                    <input class="input" type="text" id="nombre_empresa" name="nombre_empresa" placeholder="Razón social">
                  </div>
                </div>

                <!--
                  ================================================================
                  DATOS FISCALES / IDENTIFICACIÓN / NACIONALIDAD
                  ================================================================
                  IMPORTANTE PARA DESARROLLADOR:
                  Estos campos alimentan carátula, declaraciones y Anexo 6 del contrato.
                  Si nacionalidad_tipo = MEXICANA: CURP/documento CURP aplican; RFC/CIF puede aplicar; identificación visible puede decir INE.
                  Si nacionalidad_tipo = EXTRANJERA: NO se exige CURP, RFC ni CIF mexicana; documentos visibles deben decir Identificación/Pasaporte.
                -->
                <div class="divider"><div class="divider__line"></div><span class="divider__lbl">Datos fiscales e identificación</span><div class="divider__line"></div></div>
                <div class="field">
                  <label class="label" for="nacionalidad_tipo">País de Residencia <span class="label__req">*</span></label>
                  <div class="input-wrap"><i class="mdi mdi-earth icon-l"></i><select class="sel" id="nacionalidad_tipo" name="nacionalidad_tipo" required><option value="MEXICANA" selected>México</option><option value="EXTRANJERA">Otro</option></select></div>
                </div>
                <div class="field"><label class="label" for="nacionalidad">Nacionalidad <span class="label__req">*</span></label><div class="input-wrap"><i class="mdi mdi-flag-outline icon-l"></i><input class="input" type="text" id="nacionalidad" name="nacionalidad" value="mexicana" required></div></div>
                <div class="field"><label class="label" for="pais_nacimiento">País de nacimiento <span class="label__req">*</span></label><div class="input-wrap"><i class="mdi mdi-map-marker-star-outline icon-l"></i><input class="input" type="text" id="pais_nacimiento" name="pais_nacimiento" value="México" required></div></div>
                <div class="field field--mx-only" data-mx-only="paciente"><label class="label" for="rfc_usuario">RFC / CIF <span class="label__opt">Si aplica</span></label><div class="input-wrap"><i class="mdi mdi-file-account-outline icon-l"></i><input class="input" type="text" id="rfc_usuario" name="rfc_usuario" maxlength="13" placeholder="RFC del afiliado" style="font-family:var(--mono);text-transform:uppercase;"></div></div>
                <div class="field"><label class="label" for="actividad_ocupacion">Actividad / ocupación / profesión</label><div class="input-wrap"><i class="mdi mdi-briefcase-outline icon-l"></i><input class="input" type="text" id="actividad_ocupacion" name="actividad_ocupacion" placeholder="Ej. Empleado, comerciante, estudiante"></div></div>
                <div class="field"><label class="label" for="estado_civil">Estado civil</label><div class="input-wrap"><i class="mdi mdi-account-heart-outline icon-l"></i><select class="sel" id="estado_civil" name="estado_civil"><option value="">Seleccionar</option><option value="SOLTERO(A)">Soltero(a)</option><option value="CASADO(A)">Casado(a)</option><option value="UNIÓN LIBRE">Unión libre</option><option value="DIVORCIADO(A)">Divorciado(a)</option><option value="VIUDO(A)">Viudo(a)</option></select></div></div>
                <div class="field"><label class="label" for="tipo_documento_identidad">Tipo de identificación <span class="label__req">*</span></label><div class="input-wrap"><i class="mdi mdi-card-account-details-outline icon-l"></i><select class="sel" id="tipo_documento_identidad" name="tipo_documento_identidad" required><option value="INE">INE / credencial para votar</option><option value="PASAPORTE">Pasaporte</option><option value="IDENTIFICACION_OFICIAL">Identificación oficial</option></select></div></div>
                <div class="field"><label class="label" for="pais_documento_identidad">País emisor identificación <span class="label__req">*</span></label><div class="input-wrap"><i class="mdi mdi-earth-box icon-l"></i><input class="input" type="text" id="pais_documento_identidad" name="pais_documento_identidad" value="México" required></div></div>
                <div class="field field--full"><label class="label" for="numero_documento_identidad">Número de identificación / pasaporte <span class="label__req">*</span></label><div class="input-wrap"><i class="mdi mdi-pound icon-l"></i><input class="input" type="text" id="numero_documento_identidad" name="numero_documento_identidad" placeholder="Número visible del documento"></div></div>

                <!--
                  ================================================================
                  PREGUNTA AMIGABLE · QUIÉN FIRMA Y ADMINISTRA
                  ================================================================
                  IMPORTANTE PARA DESARROLLADOR:
                  La interfaz usa lenguaje simple para no confundir al usuario.
                  Internamente se guarda modo_firma:
                  - FIRMA_PROPIA: paciente firma y administra su cuenta.
                  - TUTOR_FAMILIAR: menor de edad o paciente que requiere apoyo de mamá/papá/tutor.
                  - RESPONSABLE_AUTORIZADO: adulto/dependiente representado por otra persona.

                  En todos los casos con responsable:
                  - Paciente real = persona que usará PATS.
                  - Responsable = persona que firma, administra usuario/contraseña y recibe accesos.
                  - El contrato debe mostrar paciente y firmante/responsable por separado.
                  ================================================================
                -->
                <div class="divider"><div class="divider__line"></div><span class="divider__lbl">Firma y acceso</span><div class="divider__line"></div></div>
                <div class="field field--full">
                  <label class="label">¿Quién firmará y administrará este pasaporte? <span class="label__req">*</span></label>
                  <div class="choice-grid" id="modoFirmaGrid">
                    <label>
                      <input class="choice-radio" type="radio" name="modo_firma" id="modo_firma_propia" value="FIRMA_PROPIA" checked>
                      <span class="choice-card">
                        <span class="choice-icon"><i class="mdi mdi-account-check-outline"></i></span>
                        <span><span class="choice-title">Yo mismo</span><span class="choice-desc"> Soy el paciente y puedo firmar, recibir accesos y administrar mi cuenta.</span></span>
                      </span>
                    </label>
                    <label>
                      <input class="choice-radio" type="radio" name="modo_firma" id="modo_firma_tutor" value="TUTOR_FAMILIAR">
                      <span class="choice-card">
                        <span class="choice-icon"><i class="mdi mdi-account-heart-outline"></i></span>
                        <span><span class="choice-title">Mi mamá, papá o tutor</span><span class="choice-desc"> Para menores de edad o pacientes que necesitan que una persona cercana lo administre.</span></span>
                      </span>
                    </label>
                    <label>
                      <input class="choice-radio" type="radio" name="modo_firma" id="modo_firma_responsable" value="RESPONSABLE_AUTORIZADO">
                      <span class="choice-card">
                        <span class="choice-icon"><i class="mdi mdi-account-supervisor-circle-outline"></i></span>
                        <span><span class="choice-title">Un responsable autorizado</span><span class="choice-desc"> El paciente usará el pasaporte, pero otra persona firmará y administrará por él.</span></span>
                      </span>
                    </label>
                  </div>
                  <div class="soft-alert pp-hidden" id="modoFirmaHelp" style="margin-top:12px;">
                    <i class="mdi mdi-information-outline"></i>
                    <span id="modoFirmaHelpText">La persona responsable será quien firme el contrato, reciba los accesos y administre la cuenta del paciente.</span>
                  </div>
                </div>
              </div>

              <!--
                ================================================================
                BLOQUE DE REGLAS ESPECIALES POR EDAD
                ================================================================
                IMPORTANTE PARA EL SIGUIENTE DESARROLLADOR:

                1) MENOR DE 18 AÑOS:
                   - El paciente real / beneficiario del pasaporte es el menor.
                   - El menor NO debe cargar INE.
                   - El menor SÍ debe cargar su documento CURP como archivo si es mexicano.
                   - Si el menor es extranjero, se pide identificación/pasaporte frente obligatorio.
                   - El responsable familiar/tutor administra usuario/contraseña y firma el contrato.

                2) PACIENTE DEPENDIENTE O REPRESENTADO:
                   - Puede ser mayor de edad, pero otra persona firmará y administrará por él.
                   - En pantalla se muestra como "Un responsable autorizado" para no enredar al usuario.
                   - Paciente real = persona que usará PATS.
                   - Responsable = persona que firma/acepta/recibe accesos/administra.
                   - Se capturan relación, motivo simple y documento de acreditación opcional.

                3) MAYOR DE 65 AÑOS:
                   - Ya NO se capturan acompañantes manuales.
                   - Debe validar 2 pasaportes vigentes existentes en pats_pasaportes.
                   - La validación se hace por id_pasaporte + fecha_nacimiento de cada pasaporte.
                   - Si no se validan 2 pasaportes activos/vigentes, no puede continuar.
                   - Este formulario ya incluye el buscador/validador frontend.
                   - Endpoint requerido:
                     ez/pats/endpoints/public_pasaporte_vigente_validar.php
                ================================================================
              -->

              <!-- Persona responsable: tutor / familiar / representante autorizado -->
              <div class="pp-hidden" id="menorTutorWrap" style="margin-top:20px;">
                <div class="divider" style="margin-bottom:16px;">
                  <div class="divider__line"></div>
                  <span class="divider__lbl" id="responsableDividerLabel">Persona responsable</span>
                  <div class="divider__line"></div>
                </div>

                <div class="info-note" style="margin-bottom:16px;">
                  <i class="mdi mdi-account-supervisor-outline"></i>
                  <span id="responsableIntroText">
                    El paciente real será la persona que usará PATS. La persona responsable firmará el contrato, recibirá los accesos y administrará la cuenta.
                  </span>
                </div>

                <div class="mini-card">
                  <div class="mini-card__head">
                    <i class="mdi mdi-account-tie-outline"></i> <span id="responsableCardTitle">Datos de la persona responsable</span>
                  </div>
                  <div class="mini-card__body">
                    <div class="fields">
                      <div class="field field--full">
                        <label class="label" for="tutor_nombre">Nombre(s) de la persona responsable <span class="label__req">*</span></label>
                        <div class="input-wrap"><i class="mdi mdi-account-outline icon-l"></i>
                          <input class="input" type="text" id="tutor_nombre" name="tutor_nombre" placeholder="Nombre(s)">
                        </div>
                      </div>
                      <div class="field">
                        <label class="label" for="tutor_apellido_pa">Apellido paterno <span class="label__req">*</span></label>
                        <div class="input-wrap"><i class="mdi mdi-account-outline icon-l"></i>
                          <input class="input" type="text" id="tutor_apellido_pa" name="tutor_apellido_pa">
                        </div>
                      </div>
                      <div class="field">
                        <label class="label" for="tutor_apellido_ma">Apellido materno <span class="label__opt">Opcional</span></label>
                        <div class="input-wrap"><i class="mdi mdi-account-outline icon-l"></i>
                          <input class="input" type="text" id="tutor_apellido_ma" name="tutor_apellido_ma">
                        </div>
                      </div>
                      <div class="field field--mx-only-tutor" data-mx-only="tutor">
                        <label class="label" for="tutor_curp">CURP responsable <span class="label__req">*</span></label>
                        <div class="input-wrap"><i class="mdi mdi-card-account-details-outline icon-l"></i>
                          <input class="input" type="text" id="tutor_curp" name="tutor_curp" maxlength="18" style="font-family:var(--mono);text-transform:uppercase;">
                        </div>
                      </div>
                      <div class="field">
                        <label class="label" for="tutor_fecha_nacimiento">Fecha nacimiento responsable <span class="label__req">*</span></label>
                        <div class="input-wrap"><i class="mdi mdi-calendar-outline icon-l"></i>
                          <input class="input" type="date" id="tutor_fecha_nacimiento" name="tutor_fecha_nacimiento" style="font-family:var(--mono);">
                        </div>
                      </div>
                      <div class="field"><label class="label" for="tutor_nacionalidad_tipo">Nacionalidad responsable <span class="label__req">*</span></label><div class="input-wrap"><i class="mdi mdi-earth icon-l"></i><select class="sel" id="tutor_nacionalidad_tipo" name="tutor_nacionalidad_tipo"><option value="MEXICANA" selected>Mexicana</option><option value="EXTRANJERA">Extranjera / no mexicana</option></select></div></div>
                      <div class="field"><label class="label" for="tutor_nacionalidad">Nacionalidad textual responsable</label><div class="input-wrap"><i class="mdi mdi-flag-outline icon-l"></i><input class="input" type="text" id="tutor_nacionalidad" name="tutor_nacionalidad" value="mexicana"></div></div>
                      <div class="field"><label class="label" for="tutor_pais_nacimiento">País nacimiento responsable</label><div class="input-wrap"><i class="mdi mdi-map-marker-star-outline icon-l"></i><input class="input" type="text" id="tutor_pais_nacimiento" name="tutor_pais_nacimiento" value="México"></div></div>
                      <div class="field field--mx-only-tutor" data-mx-only="tutor"><label class="label" for="tutor_rfc">RFC / CIF responsable <span class="label__opt">Si aplica</span></label><div class="input-wrap"><i class="mdi mdi-file-account-outline icon-l"></i><input class="input" type="text" id="tutor_rfc" name="tutor_rfc" maxlength="13" style="font-family:var(--mono);text-transform:uppercase;"></div></div>
                      <div class="field"><label class="label" for="tutor_tipo_documento_identidad">Tipo identificación responsable</label><div class="input-wrap"><i class="mdi mdi-card-account-details-outline icon-l"></i><select class="sel" id="tutor_tipo_documento_identidad" name="tutor_tipo_documento_identidad"><option value="INE">INE / credencial para votar</option><option value="PASAPORTE">Pasaporte</option><option value="IDENTIFICACION_OFICIAL">Identificación oficial</option></select></div></div>
                      <div class="field"><label class="label" for="tutor_pais_documento_identidad">País emisor responsable</label><div class="input-wrap"><i class="mdi mdi-earth-box icon-l"></i><input class="input" type="text" id="tutor_pais_documento_identidad" name="tutor_pais_documento_identidad" value="México"></div></div>
                      <div class="field field--full"><label class="label" for="tutor_numero_documento_identidad">Número identificación / pasaporte responsable</label><div class="input-wrap"><i class="mdi mdi-pound icon-l"></i><input class="input" type="text" id="tutor_numero_documento_identidad" name="tutor_numero_documento_identidad"></div></div>
                      <div class="field">
                        <label class="label" for="tutor_correo">Correo responsable <span class="label__req">*</span></label>
                        <div class="input-wrap"><i class="mdi mdi-email-outline icon-l"></i>
                          <input class="input" type="email" id="tutor_correo" name="tutor_correo" placeholder="correo@dominio.com">
                        </div>
                      </div>
                      <div class="field">
                        <label class="label" for="tutor_telefono">Teléfono responsable <span class="label__req">*</span></label>
                        <div class="input-wrap"><i class="mdi mdi-phone-outline icon-l"></i>
                          <input class="input" type="text" id="tutor_telefono" name="tutor_telefono" maxlength="10" inputmode="numeric">
                        </div>
                      </div>

                      <div class="field">
                        <label class="label" for="relacion_responsable_paciente">Relación con el paciente <span class="label__req">*</span></label>
                        <div class="input-wrap"><i class="mdi mdi-account-group-outline icon-l"></i>
                          <select class="sel" id="relacion_responsable_paciente" name="relacion_responsable_paciente">
                            <option value="">Seleccionar</option>
                            <option value="MADRE">Madre</option>
                            <option value="PADRE">Padre</option>
                            <option value="HIJO(A)">Hijo(a)</option>
                            <option value="CONYUGE">Cónyuge</option>
                            <option value="HERMANO(A)">Hermano(a)</option>
                            <option value="TUTOR_LEGAL">Tutor legal</option>
                            <option value="RESPONSABLE_AUTORIZADO">Responsable autorizado</option>
                            <option value="OTRO">Otro</option>
                          </select>
                        </div>
                      </div>

                      <div class="field pp-hidden" id="wrapMotivoResponsable">
                        <label class="label" for="motivo_responsable">Motivo del apoyo <span class="label__req">*</span></label>
                        <div class="input-wrap"><i class="mdi mdi-hand-heart-outline icon-l"></i>
                          <select class="sel" id="motivo_responsable" name="motivo_responsable">
                            <option value="">Seleccionar</option>
                            <option value="DISCAPACIDAD_O_DEPENDENCIA">Discapacidad o dependencia</option>
                            <option value="CONDICION_MEDICA">Condición médica</option>
                            <option value="NO_PUEDE_DECIDIR">No puede tomar decisiones por sí mismo</option>
                            <option value="RESPONSABLE_FAMILIAR">Responsable familiar autorizado</option>
                            <option value="OTRO">Otro</option>
                          </select>
                        </div>
                      </div>

                      <div class="divider">
                        <div class="divider__line"></div>
                        <span class="divider__lbl">Documentos responsable</span>
                        <div class="divider__line"></div>
                      </div>

                      <div class="field">
                        <label class="label"><span data-tutor-doc-label-for="tutor_doc_identificacion_frente">Identificación responsable frente</span> <span class="label__req">*</span></label>
                        <div class="file-zone" id="zone_tutor_doc_identificacion_frente">
                          <input type="file" id="tutor_doc_identificacion_frente" name="tutor_doc_identificacion_frente" accept=".pdf,.png,.jpg,.jpeg,.webp">
                          <div class="file-zone__icon"><i class="mdi mdi-upload"></i></div>
                          <div class="file-zone__title">Clic para subir</div>
                          <div class="file-zone__sub">PDF · JPG · PNG</div>
                          <div class="file-zone__name"></div>
                        </div>
                      </div>
                      <div class="field">
                        <label class="label"><span data-tutor-doc-label-for="tutor_doc_identificacion_reverso">Identificación responsable reverso</span> <span class="label__req">*</span></label>
                        <div class="file-zone" id="zone_tutor_doc_identificacion_reverso">
                          <input type="file" id="tutor_doc_identificacion_reverso" name="tutor_doc_identificacion_reverso" accept=".pdf,.png,.jpg,.jpeg,.webp">
                          <div class="file-zone__icon"><i class="mdi mdi-upload"></i></div>
                          <div class="file-zone__title">Clic para subir</div>
                          <div class="file-zone__sub">PDF · JPG · PNG</div>
                          <div class="file-zone__name"></div>
                        </div>
                      </div>
                      <div class="field field--full field--mx-only-tutor" data-mx-only="tutor">
                        <label class="label">CURP responsable documento <span class="label__req">*</span></label>
                        <div class="file-zone" id="zone_tutor_doc_curp">
                          <input type="file" id="tutor_doc_curp" name="tutor_doc_curp" accept=".pdf,.png,.jpg,.jpeg,.webp">
                          <div class="file-zone__icon"><i class="mdi mdi-upload"></i></div>
                          <div class="file-zone__title">Clic para subir</div>
                          <div class="file-zone__sub">PDF · JPG · PNG</div>
                          <div class="file-zone__name"></div>
                        </div>
                      </div>
                      <div class="field field--full field--mx-only-tutor" data-mx-only="tutor">
                        <label class="label">CIF / Constancia fiscal responsable <span class="label__opt">Si aplica</span></label>
                        <div class="file-zone" id="zone_tutor_doc_constancia_fiscal">
                          <input type="file" id="tutor_doc_constancia_fiscal" name="tutor_doc_constancia_fiscal" accept=".pdf,.png,.jpg,.jpeg,.webp">
                          <div class="file-zone__icon"><i class="mdi mdi-upload"></i></div><div class="file-zone__title">Clic para subir</div><div class="file-zone__sub">PDF · JPG · PNG</div><div class="file-zone__name"></div>
                        </div>
                      </div>

                      <div class="field field--full pp-hidden" id="wrapDocAcreditacionResponsable">
                        <label class="label">Documento que acredita representación <span class="label__opt">Opcional / según política</span></label>
                        <div class="file-zone" id="zone_doc_acreditacion_representacion">
                          <input type="file" id="doc_acreditacion_representacion" name="doc_acreditacion_representacion" accept=".pdf,.png,.jpg,.jpeg,.webp">
                          <div class="file-zone__icon"><i class="mdi mdi-upload"></i></div>
                          <div class="file-zone__title">Clic para subir</div>
                          <div class="file-zone__sub">PDF · JPG · PNG</div>
                          <div class="file-zone__name"></div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Adulto mayor: validación de 2 pasaportes vigentes existentes -->
              <div class="pp-hidden" id="adultosMayoresWrap" style="margin-top:20px;">
                <div class="divider" style="margin-bottom:16px;">
                  <div class="divider__line"></div>
                  <span class="divider__lbl">Validación adulto mayor</span>
                  <div class="divider__line"></div>
                </div>

                <div class="info-note" style="margin-bottom:16px;">
                  <i class="mdi mdi-card-account-details-star-outline"></i>
                  <span>
                    Para continuar con un adulto mayor, el sistema debe validar 2 pasaportes vigentes existentes.
                    Ingresa el ID de pasaporte y la fecha de nacimiento registrada en cada uno.
                  </span>
                </div>

                <div class="stack">
                  <?php foreach ([1, 2] as $pvm): ?>
                  <div class="mini-card">
                    <div class="mini-card__head">
                      <i class="mdi mdi-shield-check-outline"></i> Pasaporte vigente requerido <?= (int)$pvm ?>
                    </div>
                    <div class="mini-card__body">
                      <div class="fields">
                        <div class="field">
                          <label class="label" for="am<?= (int)$pvm ?>_id_pasaporte">ID pasaporte <span class="label__req">*</span></label>
                          <div class="input-wrap"><i class="mdi mdi-identifier icon-l"></i>
                            <input class="input" type="text" id="am<?= (int)$pvm ?>_id_pasaporte" name="am<?= (int)$pvm ?>_id_pasaporte" inputmode="numeric" placeholder="Ej. 9001">
                          </div>
                        </div>
                        <div class="field">
                          <label class="label" for="am<?= (int)$pvm ?>_fecha_nacimiento">Fecha nacimiento <span class="label__req">*</span></label>
                          <div class="input-wrap"><i class="mdi mdi-calendar-outline icon-l"></i>
                            <input class="input" type="date" id="am<?= (int)$pvm ?>_fecha_nacimiento" name="am<?= (int)$pvm ?>_fecha_nacimiento" style="font-family:var(--mono);">
                          </div>
                        </div>
                        <div class="field field--full">
                          <button type="button" class="btn btn--soft" data-validar-pasaporte="<?= (int)$pvm ?>">
                            <i class="mdi mdi-magnify"></i> Validar pasaporte <?= (int)$pvm ?>
                          </button>
                          <div class="passport-status" id="am<?= (int)$pvm ?>_status">
                            Pendiente de validación
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </section>

            <!-- ── PASO 3: DOMICILIO ── -->
            <section class="pp-panel" data-step-panel="3">
              <div class="fields">
                <div class="field field--full">
                  <label class="label" for="dom_calle">Calle <span class="label__req">*</span></label>
                  <div class="input-wrap"><i class="mdi mdi-road icon-l"></i>
                    <input class="input" type="text" id="dom_calle" name="dom_calle" placeholder="Nombre de la calle" required>
                  </div>
                </div>
                <div class="field">
                  <label class="label" for="dom_num_ext">Número exterior <span class="label__req">*</span></label>
                  <div class="input-wrap"><i class="mdi mdi-numeric icon-l"></i>
                    <input class="input" type="text" id="dom_num_ext" name="dom_num_ext" placeholder="Ej. 42" required>
                  </div>
                </div>
                <div class="field">
                  <label class="label" for="dom_num_int">Número interior <span class="label__opt">Opcional</span></label>
                  <div class="input-wrap"><i class="mdi mdi-home-outline icon-l"></i>
                    <input class="input" type="text" id="dom_num_int" name="dom_num_int" placeholder="Ej. 3-B">
                  </div>
                </div>
                <div class="field field--full">
                  <label class="label" for="dom_colonia">Colonia <span class="label__req">*</span></label>
                  <div class="input-wrap"><i class="mdi mdi-map-marker-outline icon-l"></i>
                    <input class="input" type="text" id="dom_colonia" name="dom_colonia" placeholder="Nombre de la colonia" required>
                  </div>
                </div>
                <div class="field">
                  <label class="label" for="dom_cp">Código postal <span class="label__req">*</span></label>
                  <div class="input-wrap"><i class="mdi mdi-mailbox-outline icon-l"></i>
                    <input class="input" type="text" id="dom_cp" name="dom_cp" maxlength="5" inputmode="numeric" placeholder="5 dígitos" required>
                  </div>
                </div>
                <div class="field">
                  <label class="label" for="dom_municipio">Ciudad / Municipio <span class="label__req">*</span></label>
                  <div class="input-wrap"><i class="mdi mdi-city-outline icon-l"></i>
                    <input class="input" type="text" id="dom_municipio" name="dom_municipio" placeholder="Municipio" required>
                  </div>
                </div>
                <div class="field">
                  <label class="label" for="dom_estado_acronimo">Estado <span class="label__req">*</span></label>
                  <div class="input-wrap"><i class="mdi mdi-map icon-l"></i>
                    <select class="sel" id="dom_estado_acronimo" name="dom_estado_acronimo" required><?php foreach ($estados as $acr => $nombre): ?>
                        <option value="<?= e($acr) ?>" <?= $estadoAcronimo === $acr ? 'selected' : '' ?>><?= e($nombre) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
                <div class="field">
                  <label class="label" for="dom_estado">Estado (nombre) <span class="label__req">*</span></label>
                  <div class="input-wrap"><i class="mdi mdi-flag-outline icon-l"></i>
                    <input class="input" type="text" id="dom_estado" name="dom_estado" value="<?= e($estadoNombre) ?>" required>
                  </div>
                </div>
                <div class="field field--full">
                  <label class="label" for="dom_pais">País <span class="label__req">*</span></label>
                  <div class="input-wrap"><i class="mdi mdi-earth icon-l"></i>
                    <input class="input" type="text" id="dom_pais" name="dom_pais" value="<?= e($pais) ?>" required>
                  </div>
                </div>
              </div>
            </section>

            <!-- ── PASO 4: DOCUMENTOS ── -->
            <section class="pp-panel" data-step-panel="4">
              <div class="info-note" style="margin-bottom:20px;">
                <i class="mdi mdi-shield-lock-outline"></i>
                <span>Todos los archivos se transmiten de forma cifrada (SSL). Formatos: PDF, JPG, PNG, WEBP.</span>
              </div>

              <!--
                REGLA CRÍTICA · CURP DOCUMENTAL DEL PACIENTE MEXICANO:

                1) Menor mexicano:
                   - NO se solicita INE del menor.
                   - SÍ debe cargarse CURP documental del menor/paciente en:
                       name="doc_curp" id="doc_curp"

                2) Dependiente mexicano con responsable autorizado:
                   - El paciente/dependiente sigue siendo el usuario real de PATS.
                   - Aunque otra persona firme o administre, SÍ debe cargarse CURP documental
                     del paciente/dependiente en:
                       name="doc_curp" id="doc_curp"

                3) Paciente extranjero/no mexicano:
                   - NO se pide CURP.
                   - Se pide identificación/pasaporte; al menos frente obligatorio.

                Los documentos del tutor/responsable se capturan en el bloque del Paso 2:
                  tutor_doc_identificacion_frente, tutor_doc_identificacion_reverso, tutor_doc_curp

                PENDIENTE BACKEND:
                  public_checkout_generar_orden.php debe guardar doc_curp SIEMPRE como documento
                  del paciente real cuando el paciente sea mexicano, sea menor o dependiente.
                  tutor_doc_* debe guardarse separado como documentos del firmante/responsable.
              -->
              <div class="info-note pp-hidden" id="minorDocsNote" style="margin-bottom:20px;">
                <i class="mdi mdi-file-certificate-outline"></i>
                <span><strong>Paciente menor de edad mexicano:</strong> no se solicita INE del menor. En este paso debes cargar el <strong>CURP del menor / paciente</strong>. Los documentos del tutor se capturan en el bloque del tutor legal.</span>
              </div>

              <div class="info-note pp-hidden" id="dependentDocsNote" style="margin-bottom:20px;">
                <i class="mdi mdi-file-certificate-outline"></i>
                <span><strong>Paciente dependiente mexicano:</strong> aunque otra persona firme o administre la cuenta, en este paso debes cargar el <strong>CURP del paciente / dependiente</strong>. Los documentos de la persona responsable van en el bloque de responsable.</span>
              </div>

              <div class="info-note pp-hidden" id="foreignDocsNote" style="margin-bottom:20px;">
                <i class="mdi mdi-passport"></i>
                <span><strong>Paciente extranjero/no mexicano:</strong> no aplica CURP ni CIF mexicana. Debe cargarse identificación oficial o pasaporte; al menos el frente es obligatorio.</span>
              </div>

              <div class="docs-grid"><?php foreach ($docsRequeridos as $doc): ?>
                <div>
                  <div class="label" style="margin-bottom:7px;">
                    <i class="mdi <?= e($doc['icon']) ?>"></i>
                    <span class="doc-label-text" data-doc-label-for="<?= e($doc['id']) ?>"><?= e($doc['label']) ?></span>
                    <?php if (!empty($doc['req'])): ?><span class="label__req">*</span><?php endif; ?>
                  </div>
                  <div class="file-zone" id="zone_<?= e($doc['id']) ?>">
                    <input type="file" id="<?= e($doc['id']) ?>" name="<?= e($doc['id']) ?>" accept=".pdf,.png,.jpg,.jpeg,.webp" <?= !empty($doc['req']) ? 'required' : '' ?>>
                    <div class="file-zone__icon"><i class="mdi mdi-upload"></i></div>
                    <div class="file-zone__title">Clic para subir</div>
                    <div class="file-zone__sub">PDF · JPG · PNG</div>
                    <div class="file-zone__name" id="name_<?= e($doc['id']) ?>"></div>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
            </section>

            <!-- ── PASO 5: FOTOGRAFÍA ── -->
            <section class="pp-panel" data-step-panel="5">
              <div class="info-note" style="margin-bottom:18px;">
                <i class="mdi mdi-camera-account"></i>
                <span>Toma tu fotografía con la cámara o súbela desde tu dispositivo. Debe ser reciente y con buena iluminación.</span>
              </div>
              <div style="display:flex;flex-direction:column;gap:14px;align-items:center;">
                <div class="cam-stage">
                  <div class="cam-placeholder" id="camPlaceholder">
                    <i class="mdi mdi-camera-off-outline"></i>
                    <span>Activa la cámara o sube una foto</span>
                  </div>
                  <video id="camVideo" autoplay playsinline muted class="pp-hidden"></video>
                  <canvas id="camCanvas" class="pp-hidden"></canvas>
                  <img id="camPreview" alt="Vista previa" class="pp-hidden">
                  <div class="cam-guide"></div>
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:center;">
                  <button type="button" class="btn btn--ghost" id="btnIniciarCamara">
                    <i class="mdi mdi-camera"></i> Activar cámara
                  </button>
                  <button type="button" class="btn btn--primary pp-hidden" id="btnCapturarFoto">
                    <i class="mdi mdi-camera-iris"></i> Capturar
                  </button>
                  <label class="btn btn--ghost" style="cursor:pointer;">
                    <i class="mdi mdi-upload"></i> Subir foto
                    <input type="file" id="foto_manual" accept="image/*" capture="environment" class="pp-hidden">
                  </label>
                </div>
              </div>
              <input type="hidden" id="foto_base64" name="foto_base64">
            </section>

            <!-- ── PASO 6: CONTRATO Y PAGO ── -->
            <section class="pp-panel" data-step-panel="6">
              <div style="display:flex;flex-direction:column;gap:20px;">

                <!-- Firma — va primero para que el contrato se renderice con la firma incluida -->
                <div>
                  <div class="label" style="margin-bottom:6px;"><i class="mdi mdi-draw-pen"></i> Tu firma digital <span class="label__req">*</span></div>
                  <p style="font-size:12px;color:#60708f;margin:0 0 10px;">Firma aquí con el mouse o tu dedo. El contrato se actualizará automáticamente con tu firma.</p>
                  <div class="sig-wrap">
                    <div class="sig-top">
                      <div class="sig-top__lbl">
                        <strong>Firma del afiliado</strong>
                        <small>Firma con el mouse o con tu dedo en pantalla.</small>
                      </div>
                      <button type="button" class="btn btn--ghost" id="btnLimpiarFirma" style="padding:7px 14px;font-size:12px;">
                        <i class="mdi mdi-eraser"></i> Limpiar
                      </button>
                    </div>
                    <canvas id="signaturePad" class="sig-canvas"></canvas>
                  </div>
                </div>

                <!-- Contrato — se renderiza debajo con los datos del formulario y la firma del cliente -->
                <div>
                  <div class="label" style="margin-bottom:10px;"><i class="mdi mdi-file-document-outline"></i> Contrato de pasaporte</div>
                  <div class="contract-box">
                    <div class="contract-banner">
                      <i class="mdi mdi-information-outline"></i>
                      Lee el contrato completo. Tu firma aparece en el documento una vez que la ingresas arriba.
                    </div>
                    <div id="contractPreview" class="contract-view">
                      <div class="contract-empty">El contrato se cargará con tus datos al llegar a este paso.</div>
                    </div>
                  </div>
                </div>

                <!-- Frecuencia y monto -->
                <div class="fields">
                  <div class="divider">
                    <div class="divider__line"></div>
                    <span class="divider__lbl">Plan de pasaporte</span>
                    <div class="divider__line"></div>
                  </div>
                  <div class="field">
                    <label class="label" for="frecuencia_pago_publica">Frecuencia de pago</label>
                    <div class="input-wrap"><i class="mdi mdi-calendar-sync-outline icon-l"></i>
                      <select class="sel" id="frecuencia_pago_publica">
                             <option value="ANUAL" selected>Anual</option>
                        <option value="MENSUAL">Mensual</option>
                       
                      </select>
                    </div>
                  </div>

                  <!-- Preferencia MSI — solo visible cuando se elige Anual -->
                  <div id="msiPrefWrap" class="msi-pref-wrap pp-hidden">
                    <div class="label"><i class="mdi mdi-calendar-multiselect"></i> ¿Cuántos meses sin intereses?</div>
                    <div class="input-wrap"><i class="mdi mdi-credit-card-clock-outline icon-l"></i>
                      <select class="sel" id="msiPrefSelect">
                        <option value="0">1 pago (monto completo)</option>
                        <option value="3">3 meses sin intereses</option>
                        <option value="6">6 meses sin intereses</option>
                        <option value="9">9 meses sin intereses</option>
                        <option value="12">12 meses sin intereses</option>
                      </select>
                    </div>
                    <small style="color:var(--slate-400);font-size:10.5px;margin-top:4px;display:block;">
                      Los planes disponibles dependen de tu tarjeta y banco. Se confirman al ingresar tu tarjeta.
                    </small>
                  </div>
                  <div class="field">
                    <label class="label" for="monto_visual_publico">Monto a pagar</label>
                    <div class="input-wrap"><i class="mdi mdi-currency-mxn icon-l"></i>
                      <input class="input" type="text" id="monto_visual_publico" readonly
                        style="font-family:var(--mono);font-size:15px;font-weight:700;color:var(--blue-mid);background:rgba(37,99,235,.05);border-color:var(--border);">
                    </div>
                  </div>
                </div>

                <!-- Resumen -->
                <div>
                  <div class="label" style="margin-bottom:10px;"><i class="mdi mdi-clipboard-check-outline"></i> Resumen final</div>
                  <div class="summary-grid">
                    <div class="summary-item"><div class="summary-item__lbl">Correo</div><div class="summary-item__val" id="sumCorreo">—</div></div>
                    <div class="summary-item"><div class="summary-item__lbl">Teléfono</div><div class="summary-item__val" id="sumTelefono">—</div></div>
                    <div class="summary-item"><div class="summary-item__lbl">Frecuencia</div><div class="summary-item__val" id="sumFrecuencia">—</div></div>
                    <div class="summary-item"><div class="summary-item__lbl">Monto</div><div class="summary-item__val" id="sumMonto" style="color:var(--blue-mid);">$0</div></div>
                  </div>
                </div>

                <!-- Método de pago · selector -->
                <div>
                  <div class="label" style="margin-bottom:10px;">
                    <i class="mdi mdi-cash-multiple"></i> Método de pago <span class="label__req">*</span>
                  </div>
                  <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <label id="lblMetodoTarjeta" style="flex:1;min-width:140px;cursor:pointer;border:2px solid var(--blue-mid);border-radius:12px;padding:14px 18px;display:flex;align-items:center;gap:10px;background:rgba(37,99,235,.07);transition:.2s;">
                      <input type="radio" name="metodo_pago" id="metodoTarjeta" value="TARJETA" checked style="accent-color:var(--blue-mid);">
                      <span><i class="mdi mdi-credit-card-outline" style="font-size:20px;vertical-align:middle;"></i> <strong>Tarjeta</strong></span>
                    </label>
                    <label id="lblMetodoOxxo" style="flex:1;min-width:140px;cursor:pointer;border:2px solid #ccc;border-radius:12px;padding:14px 18px;display:flex;align-items:center;gap:10px;background:#fff;transition:.2s;">
                      <input type="radio" name="metodo_pago" id="metodoOxxo" value="OXXO" style="accent-color:#e63a1e;">
                      <span style="display:flex;align-items:center;gap:8px;">
                        <span style="font-size:18px;font-weight:900;color:#e63a1e;letter-spacing:.03em;">OXXO</span>
                        <strong>Efectivo</strong>
                      </span>
                    </label>
                  </div>
                </div>

                <!-- Pago con tarjeta · Stripe -->
                <div id="seccionTarjeta">
                  <div class="label" style="margin-bottom:10px;">
                    <i class="mdi mdi-credit-card-lock-outline"></i> Datos de tarjeta <span class="label__req">*</span>
                  </div>
                  <div class="stripe-box" id="stripeBox">
                    <div class="stripe-box__head">
                      <span><i class="mdi mdi-shield-lock-outline"></i> Pago seguro con Stripe</span>
                      <small>No guardamos los datos de tu tarjeta</small>
                    </div>
                    <div id="stripeCardElement" class="stripe-card-element"></div>
                    <div id="stripeCardError" class="stripe-error"></div>
                    <div class="stripe-help">
                      <i class="mdi mdi-lock-check-outline"></i>
                      <span>Tu tarjeta se valida directamente con Stripe. PATS solo recibe la confirmación segura del pago.</span>
                    </div>
                  </div>

                  <!-- Panel MSI — aparece solo si Stripe devuelve planes disponibles -->
                  <div id="msiPanel" class="msi-panel pp-hidden">
                    <div class="msi-panel__label">
                      <i class="mdi mdi-calendar-multiselect"></i>
                      Meses sin intereses disponibles con tu tarjeta
                    </div>
                    <div class="msi-plan-grid" id="msiPlanGrid"></div>
                    <button type="button" class="btn msi-confirm" id="btnConfirmarMSI">
                      <i class="mdi mdi-lock-check-outline"></i> Confirmar y pagar
                    </button>
                  </div>

                  <!-- Domiciliación -->
                  <label style="display:flex;align-items:flex-start;gap:10px;margin-top:14px;cursor:pointer;padding:12px 14px;border:1px solid var(--border);border-radius:10px;background:#f8faff;">
                    <input type="checkbox" id="chkDomiciliado" name="domiciliado" value="1" style="margin-top:2px;accent-color:var(--blue-mid);">
                    <span style="font-size:13px;line-height:1.5;color:#374151;">
                      <strong>Activar domiciliación automática</strong><br>
                      <span style="color:#60708f;">Permite que los pagos futuros de tu pasaporte PATS se cobren automáticamente con esta tarjeta.</span>
                    </span>
                  </label>
                </div>

                <!-- Pago con OXXO -->
                <div id="seccionOxxo" style="display:none;">
                  <div style="background:#fff8f5;border:2px solid #e63a1e;border-radius:14px;padding:18px 20px;">
                    <div style="font-weight:800;font-size:15px;color:#e63a1e;margin-bottom:8px;">
                      <span style="font-size:18px;font-weight:900;">OXXO</span> · Pago en efectivo
                    </div>
                    <p style="margin:0 0 10px;font-size:13px;color:#374151;line-height:1.55;">
                      Al continuar recibirás una <strong>ficha de pago OXXO</strong> en tu correo. Tienes <strong>3 días</strong> para pagar en cualquier tienda OXXO. Tu pasaporte quedará pendiente hasta que confirmemos el pago.
                    </p>
                    <ul style="margin:0;padding-left:18px;font-size:13px;color:#60708f;line-height:1.7;">
                      <li>El monto mínimo para pago OXXO es <strong>$10 MXN</strong>.</li>
                      <li>El pago puede tardar hasta 24 h en verse reflejado.</li>
                      <li>No se aceptan divisas distintas a MXN.</li>
                    </ul>
                  </div>
                </div>

                <!-- Términos -->
                <label class="terms-box">
                  <input type="checkbox" id="acepta_contrato" name="acepta_contrato">
                  <span class="terms-box__text">
                    He leído, entendido y acepto el contrato de pasaporte PATS. Reconozco que mi firma digital
                    forma parte de la evidencia contractual del proceso.
                  </span>
                </label>

              </div>
            </section>

          </div><!-- /card-body -->

          <!-- Footer -->
          <div class="card-footer">
            <button type="button" class="btn btn--ghost" id="btnPrev">
              <i class="mdi mdi-arrow-left"></i> Anterior
            </button>
            <div style="display:flex;gap:10px;">
              <button type="button" class="btn btn--soft" id="btnNext">
                Siguiente <i class="mdi mdi-arrow-right"></i>
              </button>
              <button type="submit" class="btn btn--success pp-hidden" id="btnSubmitPago">
                <i class="mdi mdi-lock-check-outline"></i> Continuar a pago seguro
              </button>
            </div>
          </div>

        </div><!-- /main-card -->

        <!-- Campos ocultos -->
        <input type="hidden" name="token_publico"       value="<?= e($token) ?>">
        <input type="hidden" name="id_distribuidor"     value="<?= (int)($ctx['id_distribuidor'] ?? 0) ?>">
        <input type="hidden" name="id_franquicia"       value="<?= (int)($ctx['id_franquicia'] ?? 0) ?>">
        <input type="hidden" name="id_gestor"           value="<?= (int)($ctx['id_gestor'] ?? 0) ?>">
        <input type="hidden" name="pais"                value="<?= e($pais) ?>">
        <input type="hidden" name="region"              value="<?= e($ctx['region'] ?? '') ?>">
        <input type="hidden" name="zona"                value="<?= e($ctx['zona'] ?? '') ?>">
        <input type="hidden" name="unidad"              value="<?= e($ctx['unidad'] ?? '') ?>">
        <input type="hidden" name="tipo_origen"         value="<?= e($ctx['tipo_origen'] ?? 'ADMINPATS') ?>">
        <input type="hidden" name="origen_checkout"     value="PORTAL_PUBLICO">
        <input type="hidden" name="tipo_operacion"      value="ALTA_PATS">
        <input type="hidden" name="moneda"              value="MXN">
        <input type="hidden" name="id_tipo_precio"      id="id_tipo_precio"   value="2">
        <input type="hidden" name="frecuencia"          id="frecuencia"       value="MENSUAL">
        <input type="hidden" name="monto_orden"         id="monto_orden"      value="<?= e((string)$montoMensual) ?>">
        <input type="hidden" name="correo_usuario_pats" id="hidden_correo_usuario_pats" value="">
        <input type="hidden" name="telefono_usuario"    id="hidden_telefono_usuario" value="">

        <!--
          Campos derivados por edad:
          - tipo_paciente = MENOR | ADULTO | ADULTO_MAYOR
          - adulto_mayor_pasaportes_validados = 1 solo cuando los 2 pasaportes vigentes fueron validados.
          PENDIENTE BACKEND:
          public_checkout_generar_orden.php debe guardar estos datos en las tablas definitivas
          (paciente menor / tutor legal / relación con pasaportes vigentes de adulto mayor).
        -->
        <input type="hidden" name="tipo_paciente" id="tipo_paciente" value="ADULTO">
        <input type="hidden" name="requiere_responsable" id="requiere_responsable" value="0">
        <input type="hidden" name="tipo_representacion" id="tipo_representacion" value="FIRMA_PROPIA">
        <input type="hidden" name="adulto_mayor_pasaportes_validados" id="adulto_mayor_pasaportes_validados" value="0">
        <input type="hidden" name="adulto_mayor_pasaporte_1_json" id="adulto_mayor_pasaporte_1_json" value="">
        <input type="hidden" name="adulto_mayor_pasaporte_2_json" id="adulto_mayor_pasaporte_2_json" value="">

        <input type="hidden" name="stripe_payment_intent_id" id="stripe_payment_intent_id" value="">
        <input type="hidden" name="firma_base64"        id="firma_base64"     value="">

      </form>
    </main>
  </div><!-- /layout -->

  <div id="ppToastHost"></div>

  <!-- Network check popup -->
  <div id="netCheckOverlay" class="net-check-overlay pp-hidden" role="dialog" aria-modal="true" aria-labelledby="netCheckTitle">
    <div class="net-check-box">
      <div class="net-icon"><i class="mdi mdi-wifi-alert"></i></div>
      <h3 id="netCheckTitle">Verifica tu conexión a internet</h3>
      <p>Para completar tu registro sin interrupciones, asegúrate de contar con una <strong>conexión estable a internet</strong> antes de continuar.<br>Una conexión inestable puede afectar la carga de documentos y el proceso de pago.</p>
      <div class="net-check-actions">
        <button type="button" class="btn btn--secondary" id="btnNetCheckLater">Revisar después</button>
        <button type="button" class="btn btn--primary" id="btnNetCheckReady"><i class="mdi mdi-check-circle-outline"></i> Mi conexión está lista</button>
      </div>
    </div>
  </div>

  <div id="ppModal" class="pp-modal pp-hidden">
    <div class="pp-modal__box">
      <div class="pp-modal__head"><i class="mdi mdi-hospital-box"></i> PATS</div>
      <div id="ppModalMsg" class="pp-modal__body">Mensaje</div>
      <div class="pp-modal__actions">
        <button type="button" class="btn btn--primary" id="btnCloseModal">Aceptar</button>
      </div>
    </div>
  </div>

<script src="https://js.stripe.com/v3/"></script>
<script>
window.PATS_PUBLIC_CFG = {
  token:         <?= json_encode($token, $jsonFlags) ?>,
  monto_anual:   <?= json_encode($montoAnual, $jsonFlags) ?>,
  monto_mensual: <?= json_encode($montoMensual, $jsonFlags) ?>,
  url_orden:     'endpoints/public_checkout_generar_orden.php',
  url_contrato:  'endpoints/public_contract_preview.php',
  url_stripe_intent: 'endpoints/public_stripe_intent.php',
  url_correo_validar: 'endpoints/public_correo_acceso_validar.php',
  url_preupload: 'endpoints/public_preupload.php',
  stripe_pk:     <?= json_encode($stripePublicKey, $jsonFlags) ?>,
  csrf:          <?= json_encode($csrf, $jsonFlags) ?>,
};
</script>

<script>
(() => {
  "use strict";

  const $ = (s) => document.querySelector(s);
  const $$ = (s) => Array.from(document.querySelectorAll(s));
  const CFG = window.PATS_PUBLIC_CFG;

  // Catálogo de estados disponible para JS.
  // IMPORTANTE: no borrar. syncEstadoDesdeAcronimo() lo usa al cambiar el select de Estado.
  const ESTADOS_MX = <?= json_encode($estados, $jsonFlags) ?>;

  const STEP_META = [
    { tag:'Paso 1 de 6', icon:'mdi-email-check-outline',       title:'Datos de acceso',      desc:'Ingresa tu correo y teléfono para comenzar.' },
    { tag:'Paso 2 de 6', icon:'mdi-account-outline',           title:'Datos personales',     desc:'Tu nombre completo, CURP y fecha de nacimiento.' },
    { tag:'Paso 3 de 6', icon:'mdi-map-marker-outline',        title:'Domicilio',             desc:'La dirección donde recibirás comunicaciones.' },
    { tag:'Paso 4 de 6', icon:'mdi-folder-open-outline',       title:'Documentos',            desc:'Sube tu INE y documento CURP.' },
    { tag:'Paso 5 de 6', icon:'mdi-camera-account',            title:'Fotografía',            desc:'Tu foto de acceso a la plataforma PATS.' },
    { tag:'Paso 6 de 6', icon:'mdi-file-sign',                 title:'Contrato y pago',       desc:'Firma el contrato y continúa al pago seguro.' },
  ];

  let currentStep = 1;
  const totalSteps = 6;
  let mediaStream = null;
  let signPad = null, signCtx = null, signDrawing = false, signHasStroke = false;

  /* Pre-upload state: tracks background file uploads as the user advances steps */
  const preUploads = { paths: {}, promises: {}, errors: {} };

  /*
    Stripe:
    - solicitud_pats.php crea/recibe client_secret desde public_stripe_intent.php.
    - Stripe.js confirma el pago en navegador.
    - Después se manda stripe_payment_intent_id a public_checkout_generar_orden.php.
    - El endpoint final vuelve a verificar el PaymentIntent contra Stripe con STRIPE_SECRET_KEY.
  */
  let stripe = null;
  let stripeElements = null;
  let stripeCard = null;
  let stripeReady = false;

  /* Estado MSI — se llena tras crear el PaymentIntent */
  const msi = { clientSecret: null, paymentIntentId: null, selectedPlan: null, fd: null, active: false };

  /*
    Validación temprana de correo:
    - Se dispara al escribir/blur en Paso 1.
    - Consulta pats_pasaporte_accesos y pats_pasaportes.
    - Si ya existe, no permite avanzar ni crear PaymentIntent en Stripe.
  */
  let correoCheckTimer = null;
  let correoCheckController = null;
  let correoUltimoValidado = '';
  let correoDisponible = null;
  let correoCheckLoading = false;

  /*
    Estado frontend para reglas especiales por edad.
    - ageMode MENOR: requiere tutor legal y documentos de tutor.
    - ageMode ADULTO_MAYOR: requiere 2 pasaportes vigentes existentes.
    - Los endpoints de guardado deben persistir estos datos; aquí solo se captura/valida.
  */
  let ageMode = 'ADULTO';
  const adultoMayorValidaciones = {
    1: { ok:false, data:null },
    2: { ok:false, data:null }
  };

  /*
    Control visual del bloque de adulto mayor.

    IMPORTANTE PARA DESARROLLADOR:
    El bloque de validación de 2 pasaportes vigentes solo debe mostrarse cuando
    el paciente realmente va por flujo ADULTO_MAYOR sin responsable/representante.

    Si el usuario se equivoca y cambia la fecha a menor de edad, o cambia la modalidad
    a "Un responsable autorizado" / dependiente, este bloque debe ocultarse y limpiar
    valores previos para no mandar datos residuales al backend.
  */
  function shouldShowAdultoMayorValidation() {
    return ageMode === 'ADULTO_MAYOR' && !esDependienteRepresentado();
  }

  function resetAdultoMayorValidation(clearInputs = true) {
    adultoMayorValidaciones[1] = { ok:false, data:null };
    adultoMayorValidaciones[2] = { ok:false, data:null };

    if ($('#adulto_mayor_pasaportes_validados')) $('#adulto_mayor_pasaportes_validados').value = '0';
    if ($('#adulto_mayor_pasaporte_1_json')) $('#adulto_mayor_pasaporte_1_json').value = '';
    if ($('#adulto_mayor_pasaporte_2_json')) $('#adulto_mayor_pasaporte_2_json').value = '';

    [1,2].forEach(n => {
      if (clearInputs) {
        const idInput = $('#am' + n + '_id_pasaporte');
        const fnInput = $('#am' + n + '_fecha_nacimiento');
        if (idInput) idInput.value = '';
        if (fnInput) fnInput.value = '';
      }

      const st = $('#am' + n + '_status');
      if (st) {
        st.className = 'passport-status';
        st.textContent = 'Pendiente de validación';
      }
    });
  }

  function syncAdultoMayorValidationUI() {
    const show = shouldShowAdultoMayorValidation();
    $('#adultosMayoresWrap')?.classList.toggle('pp-hidden', !show);

    if (!show) {
      resetAdultoMayorValidation(true);
    }
  }

  const onlyDigits = (v) => String(v || '').replace(/\D+/g, '');
  const validPhone = (v) => onlyDigits(v).length === 10;
  const validEmail = (v) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(v || '').trim());

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
    return new Intl.NumberFormat('es-MX', { style:'currency', currency:'MXN', maximumFractionDigits:0 }).format(Number(v || 0));
  }

  function toast(msg, type = 'info') {
    const host = $('#ppToastHost');
    if (!host) return;
    const item = document.createElement('div');
    const colors = {
      error:   'linear-gradient(135deg,#991b1b,#dc2626)',
      success: 'linear-gradient(135deg,#065f46,#10b981)',
      info:    'linear-gradient(135deg,#1e3a8a,#2563eb)',
    };
    Object.assign(item.style, {
      minWidth:'240px', maxWidth:'340px', padding:'12px 16px',
      borderRadius:'12px', color:'#fff', fontSize:'13px', lineHeight:'1.4',
      fontWeight:'600', pointerEvents:'auto', boxShadow:'0 16px 36px rgba(0,0,0,.22)',
      background: colors[type] || colors.info,
      display:'flex', alignItems:'center', gap:'8px',
    });
    const icons = { error:'❌', success:'✓', info:'ℹ' };
    item.innerHTML = `<span style="font-size:16px">${icons[type]||''}</span><span>${msg}</span>`;
    host.appendChild(item);
    setTimeout(() => {
      item.style.transition = 'opacity .25s,transform .25s';
      item.style.opacity = '0'; item.style.transform = 'translateY(-6px)';
      setTimeout(() => item.remove(), 260);
    }, 2800);
  }

  function showModal(msg) {
    const modal = $('#ppModal'), box = $('#ppModalMsg');
    if (!modal || !box) return;
    box.textContent = msg || 'Mensaje';
    modal.classList.remove('pp-hidden');
  }
  function hideModal() { $('#ppModal')?.classList.add('pp-hidden'); }


  function setCorreoStatus(kind, msg) {
    const el = $('#correoStatus');
    if (!el) return;

    el.className = 'email-status';
    if (!msg) {
      el.textContent = '';
      return;
    }

    el.textContent = msg;
    el.classList.add('show');

    if (kind === 'checking') el.classList.add('is-checking');
    else if (kind === 'ok') el.classList.add('is-ok');
    else if (kind === 'error') el.classList.add('is-error');
  }

  function resetCorreoDisponibilidad() {
    correoUltimoValidado = '';
    correoDisponible = null;
    correoCheckLoading = false;
    if (correoCheckController) {
      try { correoCheckController.abort(); } catch (e) {}
      correoCheckController = null;
    }
    setCorreoStatus('', '');
  }

  async function validarCorreoDisponible(force = false) {
    const correo = ($('#login_correo')?.value || '').trim().toLowerCase();

    if (!correo) {
      resetCorreoDisponibilidad();
      return false;
    }

    if (!validEmail(correo)) {
      correoDisponible = false;
      correoUltimoValidado = '';
      setCorreoStatus('error', 'Captura un correo válido.');
      return false;
    }

    if (!force && correoUltimoValidado === correo && correoDisponible === true) {
      return true;
    }

    if (!force && correoUltimoValidado === correo && correoDisponible === false) {
      return false;
    }

    if (correoCheckController) {
      try { correoCheckController.abort(); } catch (e) {}
    }

    correoCheckController = new AbortController();
    correoCheckLoading = true;
    setCorreoStatus('checking', 'Validando si el correo ya está registrado...');

    const fd = new FormData();
    fd.append('correo', correo);

    try {
      const res = await fetch(CFG.url_correo_validar, {
        method: 'POST',
        body: fd,
        signal: correoCheckController.signal
      });

      const text = await res.text();
      let data = {};
      try {
        data = text ? JSON.parse(text) : {};
      } catch (err) {
        console.error('Respuesta no JSON al validar correo:', text);
        correoDisponible = false;
        correoUltimoValidado = correo;
        setCorreoStatus('error', 'No fue posible validar el correo. Intenta nuevamente.');
        return false;
      }

      correoUltimoValidado = correo;
      correoDisponible = !!data.disponible;

      if (!res.ok || data.ok === false) {
        correoDisponible = false;
        setCorreoStatus('error', data.error || 'No fue posible validar el correo.');
        return false;
      }

      if (!data.disponible) {
        setCorreoStatus('error', data.mensaje || 'Este correo ya tiene un Pasaporte PATS registrado. Usa otro correo o recupera tu acceso.');
        return false;
      }

      setCorreoStatus('ok', 'Correo disponible. Puedes continuar.');
      return true;
    } catch (e) {
      if (e && e.name === 'AbortError') {
        return false;
      }

      console.error(e);
      correoDisponible = false;
      correoUltimoValidado = correo;
      setCorreoStatus('error', 'No fue posible validar el correo. Revisa tu conexión e intenta nuevamente.');
      return false;
    } finally {
      correoCheckLoading = false;
    }
  }

  function queueCorreoDisponibleCheck() {
    clearTimeout(correoCheckTimer);
    correoDisponible = null;
    correoUltimoValidado = '';
    setCorreoStatus('', '');

    const correo = ($('#login_correo')?.value || '').trim();
    if (!correo) return;

    correoCheckTimer = setTimeout(() => {
      validarCorreoDisponible(false);
    }, 520);
  }


  // ── Sync UI ────────────────────────────────────────────────────────────────

  function syncSteps() {
    $$('[data-step-panel]').forEach(p => {
      p.classList.toggle('is-active', Number(p.dataset.stepPanel) === currentStep);
    });

    const items = $$('[data-step]');
    items.forEach(b => {
      const s = Number(b.dataset.step);
      b.classList.toggle('is-active', s === currentStep);
      b.classList.toggle('is-done',   s < currentStep);
      b.classList.toggle('step-item--back', s < currentStep);
    });

    // Progress fill line in sidebar
    const fill = $('#ppStepFill');
    if (fill) fill.style.height = `${((currentStep-1)/(totalSteps-1))*100}%`;

    // Top progress bar
    const bar = $('#ppProgressFill');
    if (bar) bar.style.width = `${Math.round((currentStep/totalSteps)*100)}%`;

    // Header meta
    const meta = STEP_META[currentStep - 1];
    if (meta) {
      const tag = $('#ppCardTag');
      if (tag) tag.innerHTML = `<i class="mdi ${meta.icon}"></i> ${meta.tag}`;
      const title = $('#ppCardTitle');
      if (title) title.textContent = meta.title;
      const desc = $('#ppCardDesc');
      if (desc) desc.textContent = meta.desc;
      const ctr = $('#ppCardCounter');
      if (ctr) ctr.textContent = `${currentStep} / ${totalSteps}`;
    }

    // Buttons
    const prev = $('#btnPrev'), next = $('#btnNext'), submit = $('#btnSubmitPago');
    if (prev)   prev.style.visibility  = currentStep === 1 ? 'hidden' : 'visible';
    if (next)   next.classList.toggle('pp-hidden',  currentStep === totalSteps);
    if (submit) submit.classList.toggle('pp-hidden', currentStep !== totalSteps);
  }

  function syncEmpresa() {
    const tipo = ($('#tipo_cliente')?.value || 'privado').toLowerCase();
    $('#wrapNombreEmpresa')?.classList.toggle('pp-hidden', tipo !== 'empresa');
  }

  function syncAdultosMayores() {
    const age = calcAge($('#fecha_nacimiento')?.value || '');

    if (!age) {
      ageMode = 'ADULTO';
    } else if (age < 18) {
      ageMode = 'MENOR';
    } else if (age >= 65) {
      ageMode = 'ADULTO_MAYOR';
    } else {
      ageMode = 'ADULTO';
    }

    syncModoFirma();
    syncAdultoMayorValidationUI();

    const tipoPaciente = $('#tipo_paciente');
    if (tipoPaciente) tipoPaciente.value = esDependienteRepresentado() ? 'DEPENDIENTE_CON_RESPONSABLE' : ageMode;

    syncDocumentosPorEdad();
    queueContractPreview();
  }


  function isPacienteMexicano() {
    return String($('#nacionalidad_tipo')?.value || 'MEXICANA').toUpperCase() === 'MEXICANA';
  }

  function isTutorMexicano() {
    return String($('#tutor_nacionalidad_tipo')?.value || 'MEXICANA').toUpperCase() === 'MEXICANA';
  }

  function getModoFirma() {
    return String(document.querySelector('input[name="modo_firma"]:checked')?.value || 'FIRMA_PROPIA').toUpperCase();
  }

  function requiereResponsable() {
    const mode = getModoFirma();
    return ageMode === 'MENOR' || mode === 'TUTOR_FAMILIAR' || mode === 'RESPONSABLE_AUTORIZADO';
  }

  function esDependienteRepresentado() {
    return ageMode !== 'MENOR' && (getModoFirma() === 'TUTOR_FAMILIAR' || getModoFirma() === 'RESPONSABLE_AUTORIZADO');
  }

  function syncModoFirma() {
    const age = calcAge($('#fecha_nacimiento')?.value || '');
    const menor = age > 0 && age < 18;

    if (menor) {
      const radioTutor = $('#modo_firma_tutor');
      if (radioTutor) radioTutor.checked = true;
    }

    const needs = requiereResponsable();
    const dep = esDependienteRepresentado();

    $('#menorTutorWrap')?.classList.toggle('pp-hidden', !needs);
    $('#wrapMotivoResponsable')?.classList.toggle('pp-hidden', !dep);
    $('#wrapDocAcreditacionResponsable')?.classList.toggle('pp-hidden', !dep);
    $('#modoFirmaHelp')?.classList.toggle('pp-hidden', !needs);

    const mode = getModoFirma();
    if ($('#requiere_responsable')) $('#requiere_responsable').value = needs ? '1' : '0';
    if ($('#tipo_representacion')) $('#tipo_representacion').value = mode;

    /*
      Si el usuario estaba en adulto mayor y decide que el paciente será dependiente
      con responsable, se oculta y limpia la validación de pasaportes vigentes.
    */
    syncAdultoMayorValidationUI();

    if ($('#responsableDividerLabel')) $('#responsableDividerLabel').textContent = menor ? 'Mamá, papá o tutor' : 'Persona responsable';
    if ($('#responsableCardTitle')) $('#responsableCardTitle').textContent = menor ? 'Datos de mamá, papá o tutor' : 'Datos de la persona responsable';
    if ($('#responsableIntroText')) {
      $('#responsableIntroText').textContent = menor
        ? 'Como el paciente es menor de edad, necesitamos los datos de la persona que firmará, recibirá los accesos y administrará la cuenta.'
        : 'El paciente seguirá siendo quien usará PATS. Esta persona firmará el contrato, recibirá los accesos y administrará la cuenta.';
    }
    if ($('#modoFirmaHelpText')) {
      $('#modoFirmaHelpText').textContent = menor
        ? 'Por ser menor de edad, el contrato debe ser firmado por mamá, papá o tutor.'
        : 'La persona responsable será quien firme el contrato, reciba los accesos y administre la cuenta del paciente.';
    }

    setFieldRequired('relacion_responsable_paciente', needs);
    setFieldRequired('motivo_responsable', dep);
    syncNacionalidadDocumentos();
  }

  function setFieldRequired(id, required) {
    const el = $('#' + id);
    if (el) el.required = !!required;
  }

  function setFileZoneVisible(id, visible, required = false) {
    const input = $('#' + id);
    const cell = input?.closest('.docs-grid > div') || input?.closest('.field');
    if (cell) cell.classList.toggle('pp-hidden', !visible);
    if (input) input.required = !!required;
  }

  function syncNacionalidadDocumentos() {
    const mx = isPacienteMexicano();
    const menor = ageMode === 'MENOR';
    const needsResponsable = requiereResponsable();

    /*
      REGLA CRÍTICA · MENOR EXTRANJERO
      ------------------------------------------------------------
      Si el paciente menor NO es mexicano, el bloque del tutor también
      debe adaptarse automáticamente a extranjero/no mexicano.

      Esto evita pedir documentos mexicanos al tutor:
      - NO CURP responsable
      - NO RFC/CIF tutor
      - NO INE tutor
      - NO constancia fiscal mexicana tutor

      En ese caso se pide:
      - Identificación / pasaporte del tutor frente obligatorio
      - Reverso opcional
      - Número de documento/pasaporte
      - País emisor

      Si en el futuro se permite que un menor extranjero tenga tutor mexicano,
      esta regla debe cambiarse a una selección manual confirmada; por ahora,
      para evitar errores operativos, menor extranjero fuerza tutor extranjero.
    */
    const fuerzaTutorExtranjero = menor && !mx;
    const tutorSel = $('#tutor_nacionalidad_tipo');
    if (fuerzaTutorExtranjero && tutorSel) {
      tutorSel.value = 'EXTRANJERA';
    }
    const tutorMx = fuerzaTutorExtranjero ? false : isTutorMexicano();

    if ($('#nacionalidad')) {
      if (mx) {
        $('#nacionalidad').value = 'mexicana';
      } else if (String($('#nacionalidad').value || '').trim().toLowerCase() === 'mexicana') {
        $('#nacionalidad').value = '';
      }
    }

    if ($('#pais_nacimiento')) {
      if (mx) {
        if (!$('#pais_nacimiento').value) $('#pais_nacimiento').value = 'México';
      } else if (String($('#pais_nacimiento').value || '').trim().toLowerCase() === 'méxico' || String($('#pais_nacimiento').value || '').trim().toLowerCase() === 'mexico') {
        $('#pais_nacimiento').value = '';
      }
    }

    if ($('#pais_documento_identidad')) {
      if (mx) {
        if (!$('#pais_documento_identidad').value) $('#pais_documento_identidad').value = 'México';
      } else if (String($('#pais_documento_identidad').value || '').trim().toLowerCase() === 'méxico' || String($('#pais_documento_identidad').value || '').trim().toLowerCase() === 'mexico') {
        $('#pais_documento_identidad').value = '';
      }
    }

    if ($('#tipo_documento_identidad')) $('#tipo_documento_identidad').value = mx ? ($('#tipo_documento_identidad').value || 'INE') : ($('#tipo_documento_identidad').value === 'INE' ? 'PASAPORTE' : ($('#tipo_documento_identidad').value || 'PASAPORTE'));

    $$('.field--mx-only').forEach(el => el.classList.toggle('pp-hidden', !mx || menor));
    setFieldRequired('curp_usuario', mx);
    const curpWrap = $('#curp_usuario')?.closest('.field');
    if (curpWrap) curpWrap.classList.toggle('pp-hidden', !mx);

    setFileZoneVisible('doc_identificacion_frente', true, true);
    setFileZoneVisible('doc_identificacion_reverso', true, false);

    const minorNote = $('#minorDocsNote');
    if (minorNote) minorNote.classList.toggle('pp-hidden', !menor || !mx);
    const dependentNote = $('#dependentDocsNote');
    if (dependentNote) dependentNote.classList.toggle('pp-hidden', !esDependienteRepresentado() || !mx);
    const foreignNote = $('#foreignDocsNote');
    if (foreignNote) foreignNote.classList.toggle('pp-hidden', mx);

    const labels = {
      doc_identificacion_frente: mx ? 'Identificación oficial / INE frente' : 'Identificación / Pasaporte frente',
      doc_identificacion_reverso: mx ? 'Identificación oficial / INE reverso' : 'Identificación / Pasaporte reverso opcional',
      doc_curp: menor ? 'CURP del menor / paciente' : (esDependienteRepresentado() ? 'CURP del paciente / dependiente' : 'CURP paciente / afiliado'),
      doc_constancia_fiscal: 'CIF / Constancia fiscal',
      doc_comprobante_domicilio: 'Comprobante de domicilio'
    };
    Object.entries(labels).forEach(([id, txt]) => {
      const label = $('[data-doc-label-for="' + id + '"]');
      if (label) label.textContent = txt;
    });

    $$('.field--mx-only-tutor').forEach(el => el.classList.toggle('pp-hidden', !tutorMx));
    setFieldRequired('tutor_curp', tutorMx && needsResponsable);
    setFieldRequired('tutor_doc_curp', tutorMx && needsResponsable);
    setFieldRequired('tutor_doc_constancia_fiscal', false);
    setFieldRequired('tutor_rfc', false);
    setFieldRequired('tutor_doc_identificacion_frente', needsResponsable);
    setFieldRequired('tutor_doc_identificacion_reverso', needsResponsable && tutorMx);

    if ($('#tutor_nacionalidad')) $('#tutor_nacionalidad').value = tutorMx ? 'mexicana' : ($('#tutor_nacionalidad').value && $('#tutor_nacionalidad').value !== 'mexicana' ? $('#tutor_nacionalidad').value : '');

    if ($('#tutor_pais_nacimiento')) {
      if (tutorMx) {
        if (!$('#tutor_pais_nacimiento').value) $('#tutor_pais_nacimiento').value = 'México';
      } else if ($('#tutor_pais_nacimiento').value === 'México') {
        $('#tutor_pais_nacimiento').value = '';
      }
    }

    if ($('#tutor_pais_documento_identidad')) {
      if (tutorMx) {
        if (!$('#tutor_pais_documento_identidad').value) $('#tutor_pais_documento_identidad').value = 'México';
      } else if ($('#tutor_pais_documento_identidad').value === 'México') {
        $('#tutor_pais_documento_identidad').value = '';
      }
    }

    if ($('#tutor_tipo_documento_identidad')) {
      $('#tutor_tipo_documento_identidad').value = tutorMx
        ? ($('#tutor_tipo_documento_identidad').value || 'INE')
        : ($('#tutor_tipo_documento_identidad').value === 'INE' ? 'PASAPORTE' : ($('#tutor_tipo_documento_identidad').value || 'PASAPORTE'));
    }

    const t1 = $('[data-tutor-doc-label-for="tutor_doc_identificacion_frente"]');
    if (t1) t1.textContent = tutorMx ? 'Identificación oficial / Identificación responsable frente' : 'Identificación / pasaporte tutor frente';
    const t2 = $('[data-tutor-doc-label-for="tutor_doc_identificacion_reverso"]');
    if (t2) t2.textContent = tutorMx ? 'Identificación oficial / Identificación responsable reverso' : 'Identificación / pasaporte tutor reverso opcional';

    syncDomicilioPorNacionalidad();
  }

  function syncDocumentosPorEdad() {
    syncNacionalidadDocumentos();
  }

  function setLabelText(forId, text) {
    const label = document.querySelector('label[for="' + forId + '"]');
    if (!label) return;

    const req = label.querySelector('.label__req')?.outerHTML || '';
    const opt = label.querySelector('.label__opt')?.outerHTML || '';
    label.innerHTML = text + (req ? ' ' + req : '') + (opt ? ' ' + opt : '');
  }

  function isMexicoText(v) {
    const s = String(v || '').trim().toLowerCase();
    return s === 'méxico' || s === 'mexico';
  }

  function isMxStateName(v) {
    const s = String(v || '').trim();
    if (!s) return false;
    return Object.values(ESTADOS_MX).some(nombre => String(nombre).toLowerCase() === s.toLowerCase());
  }

  function syncEstadoDesdeAcronimo() {
    if (!isPacienteMexicano()) return;
    const acr = ($('#dom_estado_acronimo')?.value || '').toUpperCase();
    if ($('#dom_estado')) $('#dom_estado').value = ESTADOS_MX[acr] || acr;
  }

  function syncDomicilioPorNacionalidad() {
    const mx = isPacienteMexicano();
    const cp = $('#dom_cp');
    const estadoSelect = $('#dom_estado_acronimo');
    const estadoSelectField = estadoSelect?.closest('.field');
    const estadoTxt = $('#dom_estado');
    const paisDom = $('#dom_pais');

    setLabelText('dom_colonia', mx ? 'Colonia' : 'Colonia / barrio / localidad');
    setLabelText('dom_cp', mx ? 'Código postal' : 'Código postal / ZIP');
    setLabelText('dom_municipio', mx ? 'Ciudad / Municipio' : 'Ciudad / localidad');
    setLabelText('dom_estado_acronimo', 'Estado');
    setLabelText('dom_estado', mx ? 'Estado (nombre)' : 'Estado / provincia / región');
    setLabelText('dom_pais', mx ? 'País' : 'País de residencia');

    if (cp) {
      if (mx) {
        cp.maxLength = 5;
        cp.inputMode = 'numeric';
        cp.placeholder = '5 dígitos';
        cp.value = onlyDigits(cp.value).slice(0, 5);
      } else {
        cp.maxLength = 16;
        cp.inputMode = 'text';
        cp.placeholder = 'ZIP / postal code';
      }
    }

    if (estadoSelectField) estadoSelectField.classList.toggle('pp-hidden', !mx);
    if (estadoSelect) {
      estadoSelect.required = !!mx;
      estadoSelect.disabled = !mx;
      if (!mx) estadoSelect.value = '';
    }

    if (estadoTxt) {
      estadoTxt.required = true;
      estadoTxt.readOnly = false;
      if (mx) {
        syncEstadoDesdeAcronimo();
      } else if (isMxStateName(estadoTxt.value)) {
        estadoTxt.value = '';
      }
    }

    if (paisDom) {
      paisDom.required = true;
      if (mx) {
        paisDom.value = 'México';
      } else if (isMexicoText(paisDom.value)) {
        paisDom.value = '';
      }
    }
  }

  function syncIdentityFromLogin() {
    const correo = ($('#login_correo')?.value || '').trim();
    const tel = onlyDigits($('#login_telefono')?.value || '').slice(0, 10);

    const hiddenCorreo = $('#hidden_correo_usuario_pats');
    const hiddenTelefono = $('#hidden_telefono_usuario');

    if (hiddenCorreo) hiddenCorreo.value = correo;
    if (hiddenTelefono) hiddenTelefono.value = tel;

    const pillCorreo = $('#pillCorreo');
    const pillTelefono = $('#pillTelefono');
    const sumCorreo = $('#sumCorreo');
    const sumTelefono = $('#sumTelefono');

    if (pillCorreo) pillCorreo.textContent = correo || '—';
    if (pillTelefono) pillTelefono.textContent = tel || '—';
    if (sumCorreo) sumCorreo.textContent = correo || '—';
    if (sumTelefono) sumTelefono.textContent = tel || '—';
  }

  function syncMonto() {
    const freq   = String($('#frecuencia_pago_publica')?.value || 'MENSUAL').toUpperCase();
    const esMens = freq === 'MENSUAL';
    const monto  = esMens ? Number(CFG.monto_mensual) : Number(CFG.monto_anual);
    const tipoPx = esMens ? '2' : '1';
    if ($('#frecuencia'))           $('#frecuencia').value          = freq;
    if ($('#monto_orden'))          $('#monto_orden').value         = String(monto);
    if ($('#id_tipo_precio'))       $('#id_tipo_precio').value      = tipoPx;
    if ($('#sumFrecuencia'))        $('#sumFrecuencia').textContent = esMens ? 'MENSUAL' : 'ANUAL';
    if ($('#sumMonto'))             $('#sumMonto').textContent      = money(monto);
    if ($('#monto_visual_publico')) $('#monto_visual_publico').value= money(monto);

    /* Mostrar u ocultar preferencia MSI según frecuencia */
    const msiPrefWrap = $('#msiPrefWrap');
    if (msiPrefWrap) msiPrefWrap.classList.toggle('pp-hidden', esMens);
  }


  // ── Validación de pasaportes vigentes para adulto mayor ─────────────────────

  function setPassportStatus(n, ok, msg, data = null) {
    const st = $('#am' + n + '_status');
    if (st) {
      st.className = 'passport-status ' + (ok ? 'is-ok' : 'is-error');
      st.textContent = msg;
    }

    adultoMayorValidaciones[n] = { ok: !!ok, data: data || null };

    const allOk = adultoMayorValidaciones[1].ok && adultoMayorValidaciones[2].ok;
    if ($('#adulto_mayor_pasaportes_validados')) {
      $('#adulto_mayor_pasaportes_validados').value = allOk ? '1' : '0';
    }
    if ($('#adulto_mayor_pasaporte_' + n + '_json')) {
      $('#adulto_mayor_pasaporte_' + n + '_json').value = data ? JSON.stringify(data) : '';
    }
  }

  async function validarPasaporteVigenteAdultoMayor(n) {
    const id = onlyDigits($('#am' + n + '_id_pasaporte')?.value || '');
    const fecha = $('#am' + n + '_fecha_nacimiento')?.value || '';

    if (!id || !fecha) {
      setPassportStatus(n, false, 'Captura ID de pasaporte y fecha de nacimiento.');
      return false;
    }

    /*
      Endpoint nuevo propuesto:
      ez/pats/endpoints/public_pasaporte_vigente_validar.php

      Debe validar contra pats_pasaportes:
      - id_pasaporte exacto
      - fecha_nacimiento exacta
      - activo = 1
      - estatus = activo
      - vigencia >= CURDATE()

      Respuesta esperada:
      { ok:true, pasaporte:{ id_pasaporte, nombres, apellido_pa, apellido_ma, vigencia } }
    */
    const fd = new FormData();
    fd.append('id_pasaporte', id);
    fd.append('fecha_nacimiento', fecha);

    setPassportStatus(n, false, 'Validando pasaporte...');

    try {
      const res = await fetch('endpoints/public_pasaporte_vigente_validar.php', {
        method: 'POST',
        body: fd
      });

      const text = await res.text();
      let data = {};
      try {
        data = text ? JSON.parse(text) : {};
      } catch (err) {
        console.error('Respuesta no JSON al validar pasaporte:', text);
        setPassportStatus(n, false, 'El endpoint de validación no devolvió JSON válido.');
        return false;
      }

      if (!res.ok || data.ok === false) {
        setPassportStatus(n, false, data.error || 'Pasaporte no vigente o no encontrado.');
        return false;
      }

      const p = data.pasaporte || {};
      const nombre = [p.nombres, p.apellido_pa, p.apellido_ma].filter(Boolean).join(' ');
      setPassportStatus(n, true, `Validado: ${nombre || 'pasaporte vigente'} · Vigencia ${p.vigencia || ''}`, p);
      return true;
    } catch (e) {
      console.error(e);
      setPassportStatus(n, false, 'No fue posible conectar con la validación.');
      return false;
    }
  }

  // ── Contract preview ───────────────────────────────────────────────────────

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

    syncIdentityFromLogin();

    const correo = ($('#hidden_correo_usuario_pats')?.value || '').trim();
    const telefono = ($('#hidden_telefono_usuario')?.value || '').trim();

    const payloadKey = JSON.stringify({
      nombre_usuario: $('#nombre_usuario')?.value || '',
      apellido_pa: $('#apellido_pa')?.value || '',
      apellido_ma: $('#apellido_ma')?.value || '',
      curp_usuario: $('#curp_usuario')?.value || '',
      nacionalidad_tipo: $('#nacionalidad_tipo')?.value || 'MEXICANA',
      nacionalidad: $('#nacionalidad')?.value || '',
      pais_nacimiento: $('#pais_nacimiento')?.value || '',
      tipo_documento_identidad: $('#tipo_documento_identidad')?.value || '',
      pais_documento_identidad: $('#pais_documento_identidad')?.value || '',
      numero_documento_identidad: $('#numero_documento_identidad')?.value || '',
      correo_usuario_pats: correo,
      telefono_usuario: telefono,
      frecuencia: $('#frecuencia')?.value || 'MENSUAL',
      monto_orden: $('#monto_orden')?.value || String(CFG.monto_mensual || 800),
      dom_calle: $('#dom_calle')?.value || '',
      dom_num_ext: $('#dom_num_ext')?.value || '',
      dom_num_int: $('#dom_num_int')?.value || '',
      dom_colonia: $('#dom_colonia')?.value || '',
      dom_cp: $('#dom_cp')?.value || '',
      dom_municipio: $('#dom_municipio')?.value || '',
      dom_estado: $('#dom_estado')?.value || '',
      dom_pais: $('#dom_pais')?.value || '',
      tipo_paciente: $('#tipo_paciente')?.value || ageMode,
      tutor_nombre: $('#tutor_nombre')?.value || '',
      tutor_apellido_pa: $('#tutor_apellido_pa')?.value || '',
      tutor_apellido_ma: $('#tutor_apellido_ma')?.value || '',
      tutor_curp: $('#tutor_curp')?.value || '',
      tutor_correo: $('#tutor_correo')?.value || '',
      tutor_telefono: $('#tutor_telefono')?.value || '',
      adulto_mayor_pasaportes_validados: $('#adulto_mayor_pasaportes_validados')?.value || '0',
      modo_firma: getModoFirma(),
      requiere_responsable: $('#requiere_responsable')?.value || '0',
      relacion_responsable_paciente: $('#relacion_responsable_paciente')?.value || '',
      motivo_responsable: $('#motivo_responsable')?.value || '',
      firma_base64: $('#firma_base64')?.value || ''
    });

    if (payloadKey === contractPreviewLastKey || contractPreviewLoading) {
      return;
    }

    contractPreviewLastKey = payloadKey;
    contractPreviewLoading = true;
    wrap.innerHTML = '<div class="contract-empty">Renderizando contrato...</div>';

    const fd = new FormData();
    fd.append('token_publico', $('input[name="token_publico"]')?.value || '');
    fd.append('id_distribuidor', $('input[name="id_distribuidor"]')?.value || '0');
    fd.append('id_franquicia', $('input[name="id_franquicia"]')?.value || '0');
    fd.append('id_gestor', $('input[name="id_gestor"]')?.value || '0');
    fd.append('tipo_origen', $('input[name="tipo_origen"]')?.value || 'DISTRIBUIDOR');
    fd.append('region', $('input[name="region"]')?.value || '');
    fd.append('zona', $('input[name="zona"]')?.value || '');
    fd.append('unidad', $('input[name="unidad"]')?.value || '');

    fd.append('nombre_usuario', $('#nombre_usuario')?.value || '');
    fd.append('apellido_pa', $('#apellido_pa')?.value || '');
    fd.append('apellido_ma', $('#apellido_ma')?.value || '');
    fd.append('curp_usuario', $('#curp_usuario')?.value || '');
    fd.append('rfc_usuario', $('#rfc_usuario')?.value || '');
    fd.append('nacionalidad_tipo', $('#nacionalidad_tipo')?.value || 'MEXICANA');
    fd.append('nacionalidad', $('#nacionalidad')?.value || 'mexicana');
    fd.append('pais_nacimiento', $('#pais_nacimiento')?.value || 'México');
    fd.append('actividad_ocupacion', $('#actividad_ocupacion')?.value || '');
    fd.append('estado_civil', $('#estado_civil')?.value || '');
    fd.append('tipo_documento_identidad', $('#tipo_documento_identidad')?.value || 'INE');
    fd.append('pais_documento_identidad', $('#pais_documento_identidad')?.value || 'México');
    fd.append('numero_documento_identidad', $('#numero_documento_identidad')?.value || '');
    fd.append('correo_usuario_pats', correo);
    fd.append('telefono_usuario', telefono);
    fd.append('frecuencia', $('#frecuencia')?.value || 'MENSUAL');
    fd.append('monto_orden', $('#monto_orden')?.value || String(CFG.monto_mensual || 800));
    fd.append('dom_calle', $('#dom_calle')?.value || '');
    fd.append('dom_num_ext', $('#dom_num_ext')?.value || '');
    fd.append('dom_num_int', $('#dom_num_int')?.value || '');
    fd.append('dom_colonia', $('#dom_colonia')?.value || '');
    fd.append('dom_cp', $('#dom_cp')?.value || '');
    fd.append('dom_municipio', $('#dom_municipio')?.value || '');
    fd.append('dom_estado', $('#dom_estado')?.value || '');
    fd.append('dom_pais', $('#dom_pais')?.value || 'México');
    fd.append('firma_base64', $('#firma_base64')?.value || '');

    /*
      Datos especiales por edad:
      - Para MENOR, el endpoint de contrato debe usar tutor_* como firmante.
      - Para ADULTO_MAYOR, el endpoint puede mostrar la validación de pasaportes vigentes.
      Actualmente public_contract_preview.php puede ignorarlos si aún no fue actualizado.
    */
    fd.append('tipo_paciente', $('#tipo_paciente')?.value || ageMode);
    fd.append('modo_firma', getModoFirma());
    fd.append('requiere_responsable', $('#requiere_responsable')?.value || (requiereResponsable() ? '1' : '0'));
    fd.append('tipo_representacion', $('#tipo_representacion')?.value || getModoFirma());
    fd.append('relacion_responsable_paciente', $('#relacion_responsable_paciente')?.value || '');
    fd.append('motivo_responsable', $('#motivo_responsable')?.value || '');
    fd.append('tutor_nombre', $('#tutor_nombre')?.value || '');
    fd.append('tutor_apellido_pa', $('#tutor_apellido_pa')?.value || '');
    fd.append('tutor_apellido_ma', $('#tutor_apellido_ma')?.value || '');
    fd.append('tutor_curp', $('#tutor_curp')?.value || '');
    fd.append('tutor_rfc', $('#tutor_rfc')?.value || '');
    fd.append('tutor_nacionalidad_tipo', $('#tutor_nacionalidad_tipo')?.value || 'MEXICANA');
    fd.append('tutor_nacionalidad', $('#tutor_nacionalidad')?.value || 'mexicana');
    fd.append('tutor_pais_nacimiento', $('#tutor_pais_nacimiento')?.value || 'México');
    fd.append('tutor_tipo_documento_identidad', $('#tutor_tipo_documento_identidad')?.value || 'INE');
    fd.append('tutor_pais_documento_identidad', $('#tutor_pais_documento_identidad')?.value || 'México');
    fd.append('tutor_numero_documento_identidad', $('#tutor_numero_documento_identidad')?.value || '');
    fd.append('tutor_fecha_nacimiento', $('#tutor_fecha_nacimiento')?.value || '');
    fd.append('tutor_correo', $('#tutor_correo')?.value || '');
    fd.append('tutor_telefono', $('#tutor_telefono')?.value || '');
    fd.append('adulto_mayor_pasaportes_validados', $('#adulto_mayor_pasaportes_validados')?.value || '0');
    fd.append('adulto_mayor_pasaporte_1_json', $('#adulto_mayor_pasaporte_1_json')?.value || '');
    fd.append('adulto_mayor_pasaporte_2_json', $('#adulto_mayor_pasaporte_2_json')?.value || '');

    try {
      const res = await fetch('endpoints/public_contract_preview.php', {
        method: 'POST',
        body: fd
      });

      const text = await res.text();
      let data = {};

      try {
        data = text ? JSON.parse(text) : {};
      } catch (err) {
        console.error('Respuesta no JSON del contrato:', text);
        wrap.innerHTML = '<div class="contract-empty">El contrato no devolvió JSON válido. Revisa consola / Network.</div>';
        return;
      }

      if (!res.ok || data.ok === false) {
        wrap.innerHTML = `<div class="contract-empty">${data.error || 'No fue posible renderizar el contrato.'}</div>`;
        console.error('Error render contrato:', data);
        return;
      }

      wrap.innerHTML = data.html || '<div class="contract-empty">El contrato respondió vacío.</div>';
    } catch (e) {
      console.error('Fallo fetch contrato:', e);
      wrap.innerHTML = '<div class="contract-empty">No fue posible conectar con el contrato.</div>';
    } finally {
      contractPreviewLoading = false;
    }
  }

  // ── Signature pad ──────────────────────────────────────────────────────────

  function setupSignaturePad(forceReset = false) {
    signPad = $('#signaturePad');
    if (!signPad) return;
    const rect = signPad.getBoundingClientRect();
    if (!rect.width || !rect.height) return;
    const dpr = Math.max(window.devicePixelRatio || 1, 1);
    const oldData = (!forceReset && signHasStroke) ? signPad.toDataURL('image/png') : null;
    signPad.width  = Math.floor(rect.width  * dpr);
    signPad.height = Math.floor(rect.height * dpr);
    signCtx = signPad.getContext('2d');
    signCtx.setTransform(1,0,0,1,0,0); signCtx.scale(dpr, dpr);
    signCtx.lineWidth = 2.4; signCtx.lineCap = 'round'; signCtx.lineJoin = 'round';
    signCtx.strokeStyle = '#1d4ed8';
    if (oldData) { const img = new Image(); img.onload = () => signCtx.drawImage(img,0,0,rect.width,rect.height); img.src = oldData; }
    if (signPad.dataset.boundSign === '1') return;
    signPad.dataset.boundSign = '1';
    const getXY = (ev) => { const r = signPad.getBoundingClientRect(); const t = ev.touches?.[0]??null; return { x:(t?t.clientX:ev.clientX)-r.left, y:(t?t.clientY:ev.clientY)-r.top }; };
    const start = (ev) => { ev.preventDefault(); signDrawing=true; signHasStroke=true; const {x,y}=getXY(ev); signCtx.beginPath(); signCtx.moveTo(x,y); };
    const move  = (ev) => { if(!signDrawing) return; ev.preventDefault(); const {x,y}=getXY(ev); signCtx.lineTo(x,y); signCtx.stroke(); };
    const end   = (ev) => { if(!signDrawing) return; ev.preventDefault(); signDrawing=false; $('#firma_base64').value=signPad.toDataURL('image/png'); queueContractPreview(); };
    signPad.addEventListener('mousedown',  start);
    signPad.addEventListener('mousemove',  move);
    window.addEventListener('mouseup',     end);
    signPad.addEventListener('touchstart', start, { passive:false });
    signPad.addEventListener('touchmove',  move,  { passive:false });
    signPad.addEventListener('touchend',   end,   { passive:false });
  }

  function clearSignature() {
    if (!signCtx || !signPad) return;
    signCtx.clearRect(0,0,signPad.width,signPad.height);
    signHasStroke = false; $('#firma_base64').value = '';
    contractPreviewLastKey = '';
    setupSignaturePad(true);
    queueContractPreview();
  }

  // ── File inputs ────────────────────────────────────────────────────────────

  function bindFileInputs() {
    $$('.file-zone input[type="file"]').forEach(input => {
      if (input.dataset.boundFile === '1') return;
      input.dataset.boundFile = '1';
      input.addEventListener('change', () => {
        const zone  = input.closest('.file-zone');
        const nameEl = zone?.querySelector('.file-zone__name');
        const f = input.files?.[0];
        if (!f || !nameEl) return;
        nameEl.textContent = `✓ ${f.name}`;
        zone.classList.add('filled');
      });
      const zone = input.closest('.file-zone');
      zone?.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('dragover'); });
      zone?.addEventListener('dragleave', () => zone.classList.remove('dragover'));
      zone?.addEventListener('drop', e => {
        e.preventDefault(); zone.classList.remove('dragover');
        if (e.dataTransfer.files.length) {
          const dt = new DataTransfer(); dt.items.add(e.dataTransfer.files[0]);
          input.files = dt.files; input.dispatchEvent(new Event('change'));
        }
      });
    });
  }

  // ── Camera ─────────────────────────────────────────────────────────────────

  async function startCamera() {
    const video = $('#camVideo'), preview = $('#camPreview'), canvas = $('#camCanvas'), ph = $('#camPlaceholder');
    try {
      if (mediaStream) mediaStream.getTracks().forEach(t => t.stop());
      mediaStream = await navigator.mediaDevices.getUserMedia({ video:{ facingMode:{ ideal:'user' }, width:{ideal:1280}, height:{ideal:720} }, audio:false });
      video.srcObject = mediaStream;
      video.classList.remove('pp-hidden');
      preview.classList.add('pp-hidden');
      canvas.classList.add('pp-hidden');
      if (ph) ph.style.display = 'none';
      $('#btnIniciarCamara')?.classList.add('pp-hidden');
      $('#btnCapturarFoto')?.classList.remove('pp-hidden');
    } catch (e) {
      console.error(e);
      showModal('No fue posible abrir la cámara. Verifica los permisos del navegador.');
    }
  }

  function capturePhoto() {
    const video=$('#camVideo'), canvas=$('#camCanvas'), preview=$('#camPreview'), hidden=$('#foto_base64');
    if (!video||!canvas||!hidden||video.readyState<2) { toast('Primero inicia la cámara.','error'); return; }
    const ctx = canvas.getContext('2d');
    canvas.width=video.videoWidth||1280; canvas.height=video.videoHeight||720;
    ctx.filter='brightness(1.1) contrast(1.04)';
    ctx.drawImage(video,0,0,canvas.width,canvas.height);
    const dataUrl = canvas.toDataURL('image/jpeg',0.92);
    hidden.value = dataUrl;
    preview.src  = dataUrl;
    preview.classList.remove('pp-hidden');
    canvas.classList.add('pp-hidden');
    video.classList.add('pp-hidden');
    $('#btnCapturarFoto')?.classList.add('pp-hidden');
    if (mediaStream) { mediaStream.getTracks().forEach(t => t.stop()); mediaStream = null; }
    toast('¡Foto capturada correctamente!','success');
  }

  function bindManualPhoto() {
    const input=$('#foto_manual'), preview=$('#camPreview'), hidden=$('#foto_base64'), ph=$('#camPlaceholder');
    if (!input) return;
    input.addEventListener('change', () => {
      const file = input.files?.[0]; if (!file) return;
      const reader = new FileReader();
      reader.onload = () => {
        hidden.value = String(reader.result||'');
        preview.src  = hidden.value;
        preview.classList.remove('pp-hidden');
        $('#camVideo')?.classList.add('pp-hidden');
        $('#camCanvas')?.classList.add('pp-hidden');
        if (ph) ph.style.display = 'none';
        toast('Foto cargada correctamente.','success');
      };
      reader.readAsDataURL(file);
    });
  }

  // ── Validations ────────────────────────────────────────────────────────────

  async function validateStep1() {
    if (!validEmail($('#login_correo')?.value||''))  { toast('Captura un correo válido.','error');  return false; }
    if (!validPhone($('#login_telefono')?.value||'')){ toast('Teléfono: 10 dígitos.','error');      return false; }

    const correoOk = await validarCorreoDisponible(true);
    if (!correoOk) {
      toast('Este correo no puede continuar porque ya existe o no pudo validarse.', 'error');
      return false;
    }

    syncIdentityFromLogin();
    return true;
  }
  function validateStep2() {
    if (!($('#nombre_usuario')?.value||'').trim())   { toast('Falta nombre(s).','error');           return false; }
    if (!($('#apellido_pa')?.value||'').trim())      { toast('Falta apellido paterno.','error');    return false; }
    if (isPacienteMexicano() && !($('#curp_usuario')?.value||'').trim()) { toast('Falta CURP.','error'); return false; }
    if (!isPacienteMexicano() && !($('#numero_documento_identidad')?.value||'').trim()) { toast('Falta número de identificación o pasaporte.','error'); return false; }
    if (!($('#fecha_nacimiento')?.value||'').trim()) { toast('Falta fecha de nacimiento.','error'); return false; }

    syncAdultosMayores();

    /*
      Regla MENOR DE 18:
      El menor es el paciente real, pero el tutor es responsable legal, firmante
      y administrador de acceso. Se validan datos/documentos del tutor.
      PENDIENTE BACKEND: guardar tutor en tablas definitivas y usar tutor como firmante.
    */
    if (requiereResponsable()) {
      if (!($('#tutor_nombre')?.value||'').trim())      { toast('Falta nombre de la persona responsable.','error'); return false; }
      if (!($('#tutor_apellido_pa')?.value||'').trim()) { toast('Falta apellido paterno de la persona responsable.','error'); return false; }
      if (isTutorMexicano() && !($('#tutor_curp')?.value||'').trim()) { toast('Falta CURP de la persona responsable.','error'); return false; }
      if (!isTutorMexicano() && !($('#tutor_numero_documento_identidad')?.value||'').trim()) { toast('Falta identificación/pasaporte de la persona responsable.','error'); return false; }
      if (!($('#tutor_fecha_nacimiento')?.value||'').trim()) { toast('Falta fecha de nacimiento de la persona responsable.','error'); return false; }
      if (!validEmail($('#tutor_correo')?.value||''))   { toast('Captura correo válido de la persona responsable.','error'); return false; }
      if (!validPhone($('#tutor_telefono')?.value||'')) { toast('Captura teléfono de 10 dígitos de la persona responsable.','error'); return false; }
      if (!($('#relacion_responsable_paciente')?.value||'').trim()) { toast('Selecciona la relación de la persona responsable con el paciente.','error'); return false; }
      if (esDependienteRepresentado() && !($('#motivo_responsable')?.value||'').trim()) { toast('Selecciona por qué otra persona firmará o administrará por el paciente.','error'); return false; }
      const tutorDocs = isTutorMexicano() ? ['tutor_doc_identificacion_frente','tutor_doc_identificacion_reverso','tutor_doc_curp'] : ['tutor_doc_identificacion_frente'];
      for (const id of tutorDocs) { if (!$('#'+id)?.files?.length) { toast(isTutorMexicano() ? 'Debes cargar identificación frente/reverso y CURP de la persona responsable.' : 'Debes cargar identificación o pasaporte frente de la persona responsable.', 'error'); return false; } }
    }

    /*
      Regla ADULTO MAYOR:
      Ya no se capturan acompañantes. Deben validarse 2 pasaportes vigentes
      existentes en pats_pasaportes mediante id_pasaporte + fecha_nacimiento.
    */
    if (shouldShowAdultoMayorValidation()) {
      const p1 = onlyDigits($('#am1_id_pasaporte')?.value || '');
      const p2 = onlyDigits($('#am2_id_pasaporte')?.value || '');

      if (!p1 || !($('#am1_fecha_nacimiento')?.value || '')) {
        toast('Valida el pasaporte vigente requerido 1.','error');
        return false;
      }
      if (!p2 || !($('#am2_fecha_nacimiento')?.value || '')) {
        toast('Valida el pasaporte vigente requerido 2.','error');
        return false;
      }
      if (p1 === p2) {
        toast('Los dos pasaportes vigentes deben ser diferentes.','error');
        return false;
      }
      if (!adultoMayorValidaciones[1].ok || !adultoMayorValidaciones[2].ok) {
        toast('Debes validar 2 pasaportes vigentes antes de continuar.','error');
        return false;
      }
    }

    return true;
  }

  function validateStep3() {
    if (!($('#dom_calle')?.value||'').trim())     { toast('Falta calle.','error');            return false; }
    if (!($('#dom_num_ext')?.value||'').trim())   { toast('Falta número exterior.','error');  return false; }
    if (!($('#dom_colonia')?.value||'').trim())   { toast(isPacienteMexicano() ? 'Falta colonia.' : 'Falta colonia, barrio o localidad.','error'); return false; }

    const cpVal = String($('#dom_cp')?.value || '').trim();
    if (isPacienteMexicano()) {
      if (onlyDigits(cpVal).length !== 5) { toast('Código postal inválido. Debe tener 5 dígitos.','error'); return false; }
    } else {
      if (!cpVal) { toast('Falta código postal / ZIP.','error'); return false; }
      if (cpVal.length < 3 || cpVal.length > 16) { toast('Código postal / ZIP inválido.','error'); return false; }
    }

    if (!($('#dom_municipio')?.value||'').trim()) { toast(isPacienteMexicano() ? 'Falta municipio.' : 'Falta ciudad o localidad.','error'); return false; }
    if (!($('#dom_estado')?.value||'').trim())    { toast(isPacienteMexicano() ? 'Falta estado.' : 'Falta estado, provincia o región.','error'); return false; }
    if (!($('#dom_pais')?.value||'').trim())      { toast(isPacienteMexicano() ? 'Falta país.' : 'Falta país de residencia.','error'); return false; }
    return true;
  }
  function validateStep4() {
    syncAdultosMayores();

    /*
      Regla documentos:
      - Adulto / adulto mayor mexicano: documentos normales del paciente (INE frente, INE reverso, CURP).
      - Menor mexicano: el paciente menor no tiene INE; se exige CURP del menor.
      - Dependiente mexicano con responsable: aunque firme otra persona, se exige CURP documental del paciente/dependiente.
      - Extranjero/no mexicano: no CURP; identificación/pasaporte frente obligatorio.
        La identificación/CURP del tutor o responsable ya se valida en Paso 2 dentro del bloque responsable.
      PENDIENTE BACKEND: al guardar documentos, distinguir claramente:
      doc_curp = CURP documental del paciente real mexicano (menor, dependiente o adulto)
      tutor_doc_* = documentos del tutor/responsable firmante
    */
    const req = ['doc_identificacion_frente'];

    for (const id of req) {
      const input = $('#' + id);
      if (!input?.files?.length) {
        toast('Debes cargar los documentos obligatorios según edad y nacionalidad.', 'error');
        return false;
      }
    }
    return true;
  }

  function validateStep5() {
    if (!($('#foto_base64')?.value||'').trim()) { toast('Debes capturar o subir la fotografía.','error'); return false; }
    return true;
  }
  function validateStep6() {
    if (!$('#acepta_contrato')?.checked)              { toast('Debes aceptar el contrato.','error');       return false; }
    if (!signHasStroke||!($('#firma_base64')?.value||'').trim()) { toast('Debes firmar en pantalla.','error'); return false; }
    return true;
  }
  async function validateCurrentStep() {
    const fn = [null,validateStep1,validateStep2,validateStep3,validateStep4,validateStep5,validateStep6][currentStep];
    return fn ? await fn() : true;
  }

  // ── Método de pago · selector ───────────────────────────────────────────────

  function getMetodoPago() {
    return (document.querySelector('input[name="metodo_pago"]:checked')?.value || 'TARJETA').toUpperCase();
  }

  function syncMetodoPago() {
    const metodo = getMetodoPago();
    const secTarjeta = $('#seccionTarjeta');
    const secOxxo    = $('#seccionOxxo');
    const lblTarjeta = $('#lblMetodoTarjeta');
    const lblOxxo    = $('#lblMetodoOxxo');

    if (secTarjeta) secTarjeta.style.display = metodo === 'TARJETA' ? '' : 'none';
    if (secOxxo)    secOxxo.style.display    = metodo === 'OXXO'    ? '' : 'none';

    if (lblTarjeta) {
      lblTarjeta.style.borderColor  = metodo === 'TARJETA' ? 'var(--blue-mid)' : '#ccc';
      lblTarjeta.style.background   = metodo === 'TARJETA' ? 'rgba(37,99,235,.07)' : '#fff';
    }
    if (lblOxxo) {
      lblOxxo.style.borderColor = metodo === 'OXXO' ? '#e63a1e' : '#ccc';
      lblOxxo.style.background  = metodo === 'OXXO' ? '#fff8f5' : '#fff';
    }

    if (metodo === 'TARJETA' && !stripeReady) {
      initStripe();
    }
  }

  // ── Stripe ─────────────────────────────────────────────────────────────────

  function setStripeError(msg) {
    const el = $('#stripeCardError');
    if (!el) return;
    el.textContent = msg || '';
    el.classList.toggle('show', !!msg);
  }

  function initStripe() {
    const cardHost = $('#stripeCardElement');
    if (!cardHost) return;

    if (!CFG.stripe_pk) {
      setStripeError('Stripe no está configurado. Falta STRIPE_PUBLIC_KEY.');
      return;
    }

    if (typeof window.Stripe !== 'function') {
      setStripeError('No fue posible cargar Stripe.js. Revisa conexión o bloqueadores del navegador.');
      return;
    }

    try {
      stripe = window.Stripe(CFG.stripe_pk);
      stripeElements = stripe.elements();

      stripeCard = stripeElements.create('card', {
        hidePostalCode: true,
        style: {
          base: {
            color: '#1e293b',
            fontFamily: 'Plus Jakarta Sans, system-ui, sans-serif',
            fontSize: '15px',
            fontSmoothing: 'antialiased',
            '::placeholder': { color: '#94a3b8' }
          },
          invalid: {
            color: '#b91c1c',
            iconColor: '#ef4444'
          }
        }
      });

      stripeCard.mount('#stripeCardElement');
      stripeReady = true;

      stripeCard.on('change', (ev) => {
        setStripeError(ev.error ? ev.error.message : '');
      });
    } catch (e) {
      console.error(e);
      setStripeError('No fue posible inicializar el pago con tarjeta.');
    }
  }

  /* ── Renderiza las opciones MSI en el panel ── */
  function renderMsiPlans(plans) {
    const grid = $('#msiPlanGrid');
    const panel = $('#msiPanel');
    if (!grid || !panel) return;

    grid.innerHTML = '';

    /* Preferencia del usuario elegida en el select de frecuencia */
    const prefCount = Number($('#msiPrefSelect')?.value || 0);

    /* Opción 0: pago en una exhibición (siempre disponible) */
    const opts = [{ count: 0, label: '1 pago', sub: 'Sin mensualidades' }, ...plans.map(p => ({
      count: p.count,
      label: p.count + ' meses',
      sub: 'Sin intereses',
      plan: p
    }))];

    /* Determinar cuál opción preseleccionar según preferencia */
    const availableCounts = opts.map(o => o.count);
    let defaultIdx = 0;
    if (prefCount > 0) {
      const exactIdx = availableCounts.indexOf(prefCount);
      if (exactIdx >= 0) {
        defaultIdx = exactIdx;
      } else {
        /* Si la preferencia no está disponible, elegir el más cercano por debajo */
        const below = availableCounts.filter(c => c > 0 && c <= prefCount);
        if (below.length) defaultIdx = availableCounts.indexOf(Math.max(...below));
      }
    }

    opts.forEach((opt, i) => {
      const id = 'msi_opt_' + i;
      const checked = i === defaultIdx ? 'checked' : '';
      grid.insertAdjacentHTML('beforeend', `
        <label>
          <input class="msi-radio" type="radio" name="_msi_plan" id="${id}" value="${opt.count}" ${checked} data-plan='${opt.plan ? JSON.stringify(opt.plan) : 'null'}'>
          <span class="msi-card">
            <span class="msi-card__num">${opt.count === 0 ? '1' : opt.count}</span>
            <span class="msi-card__lbl">${opt.count === 0 ? 'pago' : 'meses'}</span>
            <span class="msi-card__sub">${opt.sub}</span>
          </span>
        </label>`);
    });

    panel.classList.remove('pp-hidden');
  }

  /* ── Confirma el pago con el plan MSI seleccionado ── */
  async function confirmarConPlanMSI() {
    if (!msi.clientSecret) throw new Error('No hay pago pendiente de confirmar.');

    const selectedRadio = document.querySelector('input[name="_msi_plan"]:checked');
    const planData = selectedRadio ? selectedRadio.getAttribute('data-plan') : 'null';
    msi.selectedPlan = planData && planData !== 'null' ? JSON.parse(planData) : null;

    const nombrePago = [
      $('#nombre_usuario')?.value || '',
      $('#apellido_pa')?.value || '',
      $('#apellido_ma')?.value || ''
    ].join(' ').replace(/\s+/g, ' ').trim();
    const correoPago = ($('#hidden_correo_usuario_pats')?.value || $('#login_correo')?.value || '').trim();
    const telPago = onlyDigits($('#hidden_telefono_usuario')?.value || $('#login_telefono')?.value || '');

    const confirmParams = {
      payment_method: {
        card: stripeCard,
        billing_details: {
          name: nombrePago || undefined,
          email: correoPago || undefined,
          phone: telPago || undefined
        }
      }
    };

    if (msi.selectedPlan) {
      confirmParams.payment_method_options = {
        card: { installments: { plan: msi.selectedPlan } }
      };
    }

    const result = await stripe.confirmCardPayment(msi.clientSecret, confirmParams);

    if (result.error) {
      setStripeError(result.error.message || 'El pago no pudo confirmarse.');
      throw new Error(result.error.message || 'El pago no pudo confirmarse.');
    }

    const pi = result.paymentIntent || {};
    if (pi.status !== 'succeeded') {
      throw new Error('El pago no quedó confirmado. Estado: ' + (pi.status || 'desconocido'));
    }

    const paymentIntentId = pi.id || msi.paymentIntentId || '';
    const hidden = $('#stripe_payment_intent_id');
    if (hidden) hidden.value = paymentIntentId;
    msi.fd.set('stripe_payment_intent_id', paymentIntentId);

    return paymentIntentId;
  }

  async function crearYConfirmarPagoStripe(fd) {
    if (!stripeReady || !stripe || !stripeCard) {
      throw new Error('Stripe no está listo. Recarga la página e intenta nuevamente.');
    }

    setStripeError('');

    const correoOk = await validarCorreoDisponible(true);
    if (!correoOk) {
      throw new Error('Este correo ya tiene un Pasaporte PATS registrado. No se creó el cargo en Stripe.');
    }

    const intentRes = await fetch(CFG.url_stripe_intent, {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': CFG.csrf },
      body: fd
    });

    const intentText = await intentRes.text();
    let intentData = {};
    try {
      intentData = intentText ? JSON.parse(intentText) : {};
    } catch (err) {
      console.error('Respuesta no JSON de Stripe intent:', intentText);
      throw new Error('El servidor no devolvió una respuesta válida al preparar el pago.');
    }

    if (!intentRes.ok || intentData.ok === false) {
      throw new Error(intentData.error || 'No fue posible preparar el pago con Stripe.');
    }

    const clientSecret = intentData.client_secret || intentData.clientSecret || '';
    if (!clientSecret) {
      throw new Error('Stripe no devolvió client_secret.');
    }

    const nombrePago = [
      $('#nombre_usuario')?.value || '',
      $('#apellido_pa')?.value || '',
      $('#apellido_ma')?.value || ''
    ].join(' ').replace(/\s+/g, ' ').trim();

    const correoPago = ($('#hidden_correo_usuario_pats')?.value || $('#login_correo')?.value || '').trim();
    const telPago = onlyDigits($('#hidden_telefono_usuario')?.value || $('#login_telefono')?.value || '');

    const result = await stripe.confirmCardPayment(clientSecret, {
      payment_method: {
        card: stripeCard,
        billing_details: {
          name: nombrePago || undefined,
          email: correoPago || undefined,
          phone: telPago || undefined
        }
      }
    });

    if (result.error) {
      setStripeError(result.error.message || 'El pago no pudo confirmarse.');
      throw new Error(result.error.message || 'El pago no pudo confirmarse.');
    }

    const pi = result.paymentIntent || {};
    if (pi.status !== 'succeeded') {
      throw new Error('El pago no quedó confirmado. Estado: ' + (pi.status || 'desconocido'));
    }

    const paymentIntentId = pi.id || intentData.payment_intent_id || intentData.paymentIntentId || '';
    if (!paymentIntentId) {
      throw new Error('No se recibió el identificador del pago confirmado.');
    }

    const hidden = $('#stripe_payment_intent_id');
    if (hidden) hidden.value = paymentIntentId;

    fd.set('stripe_payment_intent_id', paymentIntentId);
    return paymentIntentId;
  }

  async function crearYConfirmarPagoOxxo(fd) {
    if (!stripe) {
      stripe = window.Stripe(CFG.stripe_pk);
    }

    setStripeError('');

    const correoOk = await validarCorreoDisponible(true);
    if (!correoOk) {
      throw new Error('Este correo ya tiene un Pasaporte PATS registrado.');
    }

    const intentRes = await fetch(CFG.url_stripe_intent, {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': CFG.csrf },
      body: fd
    });

    const intentText = await intentRes.text();
    let intentData = {};
    try { intentData = intentText ? JSON.parse(intentText) : {}; } catch (_) {}

    if (!intentRes.ok || intentData.ok === false) {
      throw new Error(intentData.error || 'No fue posible preparar el pago OXXO.');
    }

    const clientSecret = intentData.client_secret || '';
    if (!clientSecret) throw new Error('Stripe no devolvió client_secret para OXXO.');

    const nombrePago  = [
      $('#nombre_usuario')?.value || '',
      $('#apellido_pa')?.value    || '',
      $('#apellido_ma')?.value    || ''
    ].join(' ').replace(/\s+/g, ' ').trim();
    const correoPago  = ($('#hidden_correo_usuario_pats')?.value || $('#login_correo')?.value || '').trim();

    const result = await stripe.confirmOxxoPayment(clientSecret, {
      payment_method: {
        billing_details: {
          name: nombrePago || 'Cliente PATS',
          email: correoPago || undefined
        }
      }
    });

    if (result.error) {
      throw new Error(result.error.message || 'No fue posible generar la ficha OXXO.');
    }

    const pi = result.paymentIntent || {};
    const paymentIntentId = pi.id || intentData.payment_intent_id || '';
    if (!paymentIntentId) throw new Error('No se recibió el ID del pago OXXO.');

    const oxxoDetails = pi.next_action?.oxxo_display_details || {};
    const oxxoNumero  = oxxoDetails.number || '';
    const oxxoVoucher = oxxoDetails.hosted_voucher_url || '';
    const oxxoExpires = oxxoDetails.expires_after || '';

    const hidden = $('#stripe_payment_intent_id');
    if (hidden) hidden.value = paymentIntentId;
    fd.set('stripe_payment_intent_id', paymentIntentId);
    fd.set('oxxo_numero_referencia', oxxoNumero);
    fd.set('oxxo_voucher_url', oxxoVoucher);
    fd.set('oxxo_expires_at', oxxoExpires ? new Date(oxxoExpires * 1000).toISOString() : '');

    return { paymentIntentId, oxxoNumero, oxxoVoucher };
  }

  // ── Pre-upload ──────────────────────────────────────────────────────────────

  async function preUploadField(fieldName, fileInput) {
    if (!fileInput || !fileInput.files || fileInput.files.length === 0) return;
    const file = fileInput.files[0];
    const fd = new FormData();
    fd.append('csrf', CFG.csrf);
    fd.append('field', fieldName);
    fd.append('file', file);
    const res = await fetch(CFG.url_preupload, {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': CFG.csrf },
      body: fd,
    });
    const data = await res.json();
    if (data.ok) {
      preUploads.paths[fieldName] = data.path;
    } else {
      preUploads.errors[fieldName] = data.error || 'Error al pre-cargar archivo';
    }
  }

  function triggerPreUploads(fields) {
    for (const fieldName of fields) {
      if (preUploads.paths[fieldName] || preUploads.promises[fieldName]) continue;
      const input = $(`[name="${fieldName}"]`);
      if (input && input.files && input.files.length > 0) {
        preUploads.promises[fieldName] = preUploadField(fieldName, input).catch(err => {
          preUploads.errors[fieldName] = err.message || 'Error de red al pre-cargar';
        });
      }
    }
  }

  // ── Network check popup ─────────────────────────────────────────────────────

  function showNetworkCheck() {
    const overlay = $('#netCheckOverlay');
    if (!overlay) return;
    overlay.classList.remove('pp-hidden');
    $('#btnNetCheckReady')?.addEventListener('click', () => overlay.classList.add('pp-hidden'), { once: true });
    $('#btnNetCheckLater')?.addEventListener('click', () => overlay.classList.add('pp-hidden'), { once: true });
  }

  // ── Submit ─────────────────────────────────────────────────────────────────

  async function submitWizard(ev) {
    ev.preventDefault();
    syncIdentityFromLogin();

    for (let s=1;s<=6;s++) {
      const fn=[null,validateStep1,validateStep2,validateStep3,validateStep4,validateStep5,validateStep6][s];
      if (fn && !(await fn())) return;
    }

    const btn = $('#btnSubmitPago');
    btn.disabled = true;
    btn.innerHTML = '<i class="mdi mdi-loading mdi-spin"></i> Confirmando pago...';

    const form = ev?.currentTarget || document.getElementById('frmPagoPatsPublico');
    if (!form) {
      showModal('No fue posible leer el formulario. Recarga la página e intenta nuevamente.');
      return;
    }

    const fd = new FormData(form);

    try {
      /* Esperar pre-cargas pendientes antes de enviar */
      const pendingUploads = Object.values(preUploads.promises);
      if (pendingUploads.length > 0) {
        btn.innerHTML = '<i class="mdi mdi-loading mdi-spin"></i> Preparando archivos...';
        await Promise.allSettled(pendingUploads);
      }
      /* Quitar del FormData los archivos que ya están en sesión del servidor */
      for (const fieldName of Object.keys(preUploads.paths)) {
        fd.delete(fieldName);
      }

      /*
        1) Confirmar pago en Stripe (tarjeta u OXXO).
        2) Adjuntar stripe_payment_intent_id al FormData.
        3) Enviar al endpoint final para que guarde orden, contrato y documentos.
      */
      const metodo = getMetodoPago();
      fd.set('metodo_pago', metodo);

      if (metodo === 'OXXO') {
        btn.innerHTML = '<i class="mdi mdi-loading mdi-spin"></i> Generando ficha OXXO...';
        await crearYConfirmarPagoOxxo(fd);
      } else {
        await crearYConfirmarPagoStripe(fd);
      }

      btn.innerHTML = '<i class="mdi mdi-loading mdi-spin"></i> Guardando registro...';

      const res  = await fetch(CFG.url_orden, {
        method:'POST',
        headers:{ 'X-CSRF-TOKEN': CFG.csrf },
        body: fd
      });

      const text = await res.text();
      let data = {};
      try {
        data = text ? JSON.parse(text) : {};
      } catch (err) {
        console.error('Respuesta no JSON al generar orden:', text);
        showModal('El pago fue confirmado, pero el servidor no devolvió una respuesta válida al guardar la orden. Revisa el endpoint public_checkout_generar_orden.php.');
        return;
      }

      if (!res.ok || data.ok===false) {
        showModal(data.error || 'El pago fue confirmado, pero no fue posible guardar la orden.');
        return;
      }

      if (data.checkout_url) {
        window.location.href = data.checkout_url;
        return;
      }

      showModal('Registro guardado. Referencia: '+(data.referencia_pago || data.referencia || '-'));
    } catch (e) {
      console.error(e);
      showModal(e.message || 'No fue posible confirmar el pago. Intenta nuevamente.');
    } finally {
      btn.disabled = false;
      btn.innerHTML = '<i class="mdi mdi-lock-check-outline"></i> Continuar a pago seguro';
    }
  }


  function bloquearAutocompleteNavegador() {
    const form = $('#frmPagoPatsPublico');

    if (form) {
      form.setAttribute('autocomplete', 'off');
      form.setAttribute('data-lpignore', 'true');
      form.setAttribute('data-1p-ignore', 'true');
      form.setAttribute('data-bwignore', 'true');
      form.setAttribute('data-form-type', 'other');
    }

    $$('input, select, textarea').forEach((el) => {
      const type = String(el.getAttribute('type') || '').toLowerCase();

      /*
        No tocar hidden/file/radio/checkbox para no romper POST, documentos,
        selección de modo de firma ni aceptación de contrato.
      */
      if (['hidden', 'file', 'radio', 'checkbox', 'button', 'submit'].includes(type)) {
        return;
      }

      el.setAttribute('autocomplete', 'off');
      el.setAttribute('autocorrect', 'off');
      el.setAttribute('autocapitalize', 'off');
      el.setAttribute('spellcheck', 'false');
      el.setAttribute('data-lpignore', 'true');
      el.setAttribute('data-1p-ignore', 'true');
      el.setAttribute('data-bwignore', 'true');
      el.setAttribute('data-form-type', 'other');

      /*
        Chrome/Edge pueden autollenar email/teléfono/nombre aunque el form tenga
        autocomplete="off". El readonly temporal evita el autollenado inicial y
        se libera apenas el usuario interactúa con el campo.
      */
      if (el.tagName === 'INPUT' && !el.readOnly) {
        el.setAttribute('readonly', 'readonly');

        const liberar = () => {
          el.removeAttribute('readonly');
          el.removeEventListener('focus', liberar);
          el.removeEventListener('touchstart', liberar);
          el.removeEventListener('mousedown', liberar);
        };

        el.addEventListener('focus', liberar);
        el.addEventListener('touchstart', liberar);
        el.addEventListener('mousedown', liberar);
      }
    });
  }


  // ── Init ───────────────────────────────────────────────────────────────────

  document.addEventListener('DOMContentLoaded', () => {
    showNetworkCheck();
    bloquearAutocompleteNavegador();
    syncSteps(); syncEmpresa(); syncMonto();
    bindFileInputs(); bindManualPhoto();
    syncMetodoPago();
    $$('input[name="metodo_pago"]').forEach(r => r.addEventListener('change', syncMetodoPago));
    syncIdentityFromLogin(); syncEstadoDesdeAcronimo(); syncAdultosMayores(); syncModoFirma(); syncNacionalidadDocumentos(); syncDomicilioPorNacionalidad(); setupSignaturePad(); syncAdultosMayores(); syncModoFirma();

    $('#btnCloseModal')?.addEventListener('click', hideModal);
    $('#btnLimpiarFirma')?.addEventListener('click', clearSignature);
    $('#btnIniciarCamara')?.addEventListener('click', startCamera);
    $('#btnCapturarFoto')?.addEventListener('click', capturePhoto);

    $('#login_telefono')?.addEventListener('input', e => {
      e.target.value = onlyDigits(e.target.value).slice(0,10); syncIdentityFromLogin();
    });
    $('#login_correo')?.addEventListener('input', () => {
      syncIdentityFromLogin();
      queueCorreoDisponibleCheck();
    });
    $('#login_correo')?.addEventListener('blur', () => {
      validarCorreoDisponible(true);
    });
    $('#curp_usuario')?.addEventListener('input', e => { e.target.value=e.target.value.toUpperCase().slice(0,18); queueContractPreview(); });
    $('#dom_cp')?.addEventListener('input', e => {
      if (isPacienteMexicano()) {
        e.target.value = onlyDigits(e.target.value).slice(0, 5);
      } else {
        e.target.value = String(e.target.value || '').toUpperCase().replace(/[^A-Z0-9\s\-]/g, '').slice(0, 16);
      }
      queueContractPreview();
    });
    $('#dom_estado_acronimo')?.addEventListener('change', () => { syncEstadoDesdeAcronimo(); queueContractPreview(); });
    $('#tipo_cliente')?.addEventListener('change', syncEmpresa);
    $('#fecha_nacimiento')?.addEventListener('change', () => { syncAdultosMayores(); syncModoFirma(); queueContractPreview(); });
    $$('input[name="modo_firma"]').forEach(r => r.addEventListener('change', () => { syncModoFirma(); queueContractPreview(); }));
    $('#frecuencia_pago_publica')?.addEventListener('change', () => { syncMonto(); queueContractPreview(); });

    $('#tutor_curp')?.addEventListener('input', e => { e.target.value = String(e.target.value || '').toUpperCase().slice(0,18); queueContractPreview(); });
    $('#tutor_telefono')?.addEventListener('input', e => { e.target.value = onlyDigits(e.target.value).slice(0,10); queueContractPreview(); });

    ['#nombre_usuario','#apellido_pa','#apellido_ma','#dom_calle','#dom_num_ext','#dom_num_int','#dom_colonia','#dom_municipio','#dom_estado','#dom_pais',
      '#tutor_nombre','#tutor_apellido_pa','#tutor_apellido_ma','#tutor_fecha_nacimiento','#tutor_correo','#relacion_responsable_paciente','#motivo_responsable'
    ].forEach(sel => {
      $(sel)?.addEventListener('input',  queueContractPreview);
      $(sel)?.addEventListener('change', queueContractPreview);
    });

    [1,2].forEach(n => {
      $('#am'+n+'_id_pasaporte')?.addEventListener('input', e => {
        e.target.value = onlyDigits(e.target.value);
        setPassportStatus(n, false, 'Pendiente de validación');
      });
      $('#am'+n+'_fecha_nacimiento')?.addEventListener('change', () => {
        setPassportStatus(n, false, 'Pendiente de validación');
      });
    });

    $$('[data-validar-pasaporte]').forEach(btn => {
      btn.addEventListener('click', () => validarPasaporteVigenteAdultoMayor(Number(btn.dataset.validarPasaporte || 0)));
    });

    ['nacionalidad_tipo','tutor_nacionalidad_tipo'].forEach(id => { $('#' + id)?.addEventListener('change', () => { syncNacionalidadDocumentos(); syncDomicilioPorNacionalidad(); queueContractPreview(); }); });
    ['nacionalidad','pais_nacimiento','pais_documento_identidad','tutor_nacionalidad','tutor_pais_nacimiento','tutor_pais_documento_identidad'].forEach(id => { $('#' + id)?.addEventListener('input', queueContractPreview); });
    ['rfc_usuario','tutor_rfc','numero_documento_identidad','tutor_numero_documento_identidad'].forEach(id => { $('#' + id)?.addEventListener('input', e => { e.target.value = String(e.target.value || '').toUpperCase(); queueContractPreview(); }); });
    window.addEventListener('resize', () => { if (currentStep===6) setTimeout(()=>setupSignaturePad(false),40); });

    $('#btnPrev')?.addEventListener('click', () => { if (currentStep>1) { currentStep--; syncSteps(); } });

    $('#btnNext')?.addEventListener('click', async () => {
      if (!(await validateCurrentStep())) return;
      if (currentStep < totalSteps) {
        const stepLeaving = currentStep;
        currentStep++;
        syncSteps();
        if (currentStep===6) { syncIdentityFromLogin(); syncMonto(); refreshContractPreview(); setTimeout(()=>setupSignaturePad(false),60); }

        /* Disparar pre-carga en background al salir del paso de documentos */
        if (stepLeaving === 4) {
          triggerPreUploads([
            'doc_identificacion_frente', 'doc_identificacion_reverso',
            'doc_curp', 'doc_comprobante_domicilio', 'doc_constancia_fiscal',
            'tutor_doc_identificacion_frente', 'tutor_doc_identificacion_reverso',
            'tutor_doc_curp', 'tutor_doc_constancia_fiscal',
            'doc_acreditacion_representacion',
          ]);
        }
      }
    });

    // Step items (click on done steps to go back)
    $$('[data-step]').forEach(el => {
      el.addEventListener('click', () => {
        const s = Number(el.dataset.step);
        if (s < currentStep) { currentStep=s; syncSteps(); }
      });
    });

    $('#frmPagoPatsPublico')?.addEventListener('submit', (ev) => submitWizard(ev));
  });
})();
</script>
</body>
</html>
