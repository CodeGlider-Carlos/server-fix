<?php
/*
  recuperar_cliente.php
  Formulario de recuperación para el CLIENTE.
  El admin genera el link desde recuperar_pats.php y se lo envía al cliente.
  URL: recuperar_cliente.php?t=TOKEN&r=ID_RESPALDO
*/
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$stripeConfig = __DIR__ . '/config/config.php';
if (is_file($stripeConfig)) require_once $stripeConfig;

require_once __DIR__ . '/../../varSQL/bd_pats.php';
require_once __DIR__ . '/../../varSQL/var_pats.php';
$cx = $conexionMod ?? $db_all2 ?? $db_all ?? null;

mysqli_report(MYSQLI_REPORT_OFF);

function rc_h($v): string    { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function rc_clean($v): string { return trim((string)($v ?? '')); }
function rc_val(array $d, string $k, string $fb = ''): string { return rc_clean($d[$k] ?? $fb); }

/* ── Leer parámetros de URL ── */
$tokenClaro  = strtoupper(rc_clean($_GET['t'] ?? ''));
$idRespaldo  = (int)($_GET['r'] ?? 0);

$errorFatal  = '';
$formData    = [];
$respaldo    = null;
$tokenValido = false;
$idToken     = 0;

/* ── Validar token ── */
if ($tokenClaro === '' || !($cx instanceof mysqli)) {
    $errorFatal = 'El enlace no es válido o ha expirado.';
} else {
    $tokenHash = hash('sha256', $tokenClaro);
    $stmtTok = $cx->prepare("SELECT id_token, expira_at, usado FROM pats_tokens_recuperacion WHERE token_hash=? LIMIT 1");
    if ($stmtTok) {
        $stmtTok->bind_param('s', $tokenHash);
        $stmtTok->execute();
        $rs      = $stmtTok->get_result();
        $rowTok  = $rs ? $rs->fetch_assoc() : null;
        $stmtTok->close();
        if (!$rowTok)                          $errorFatal = 'El enlace no es válido.';
        elseif ((int)$rowTok['usado'] === 1)   $errorFatal = 'Este enlace ya fue utilizado. Solicita uno nuevo a tu asesor.';
        elseif (new DateTime() > new DateTime((string)$rowTok['expira_at'])) $errorFatal = 'El enlace ha expirado. Solicita uno nuevo a tu asesor.';
        else { $tokenValido = true; $idToken = (int)$rowTok['id_token']; }
    } else {
        $errorFatal = 'Error al validar el enlace.';
    }
}

/* ── Si hay respaldo, cargar datos para pre-llenar ── */
if ($tokenValido && $idRespaldo > 0 && $cx instanceof mysqli) {
    $stmtR = $cx->prepare("SELECT * FROM pats_respaldo WHERE id_respaldo=? LIMIT 1");
    if ($stmtR) {
        $stmtR->bind_param('i', $idRespaldo);
        $stmtR->execute();
        $rsR      = $stmtR->get_result();
        $respaldo = $rsR ? $rsR->fetch_assoc() : null;
        $stmtR->close();
    }
    if ($respaldo) {
        $pl = json_decode((string)($respaldo['payload_post_json'] ?? '{}'), true) ?: [];
        $formData = [
            'nombre_usuario'               => rc_val($pl, 'nombre_usuario', $respaldo['nombres'] ?? ''),
            'apellido_pa'                  => rc_val($pl, 'apellido_pa',    $respaldo['apellido_pa'] ?? ''),
            'apellido_ma'                  => rc_val($pl, 'apellido_ma'),
            'curp_usuario'                 => strtoupper(rc_val($pl, 'curp_usuario')),
            'fecha_nacimiento'             => rc_val($pl, 'fecha_nacimiento'),
            'correo_usuario_pats'          => rc_val($pl, 'correo_usuario_pats', $respaldo['correo'] ?? ''),
            'telefono_usuario'             => rc_val($pl, 'telefono_usuario',    $respaldo['telefono'] ?? ''),
            'tipo_cliente'                 => rc_val($pl, 'tipo_cliente', 'privado'),
            'nombre_empresa'               => rc_val($pl, 'nombre_empresa'),
            'rfc_usuario'                  => strtoupper(rc_val($pl, 'rfc_usuario')),
            'nacionalidad_tipo'            => strtoupper(rc_val($pl, 'nacionalidad_tipo', 'MEXICANA')),
            'nacionalidad'                 => rc_val($pl, 'nacionalidad'),
            'pais_nacimiento'              => rc_val($pl, 'pais_nacimiento'),
            'dom_calle'       => rc_val($pl, 'dom_calle'),
            'dom_num_ext'     => rc_val($pl, 'dom_num_ext'),
            'dom_num_int'     => rc_val($pl, 'dom_num_int'),
            'dom_colonia'     => rc_val($pl, 'dom_colonia'),
            'dom_cp'          => rc_val($pl, 'dom_cp'),
            'dom_municipio'   => rc_val($pl, 'dom_municipio'),
            'dom_estado'      => rc_val($pl, 'dom_estado'),
            'dom_estado_acronimo' => strtoupper(rc_val($pl, 'dom_estado_acronimo')),
            'dom_pais'        => rc_val($pl, 'dom_pais', 'México'),
            'modo_firma'      => strtoupper(rc_val($pl, 'modo_firma', 'FIRMA_PROPIA')),
            'relacion_responsable_paciente' => rc_val($pl, 'relacion_responsable_paciente'),
            'motivo_responsable'            => rc_val($pl, 'motivo_responsable'),
            'tutor_nombre'          => rc_val($pl, 'tutor_nombre'),
            'tutor_apellido_pa'     => rc_val($pl, 'tutor_apellido_pa'),
            'tutor_apellido_ma'     => rc_val($pl, 'tutor_apellido_ma'),
            'tutor_curp'            => strtoupper(rc_val($pl, 'tutor_curp')),
            'tutor_fecha_nacimiento'=> rc_val($pl, 'tutor_fecha_nacimiento'),
            'tutor_correo'          => rc_val($pl, 'tutor_correo'),
            'tutor_telefono'        => rc_val($pl, 'tutor_telefono'),
            'tutor_nacionalidad_tipo'=> strtoupper(rc_val($pl, 'tutor_nacionalidad_tipo', 'MEXICANA')),
            'frecuencia'   => strtoupper(rc_val($pl, 'frecuencia', $respaldo['frecuencia_pago'] ?? 'MENSUAL')),
            'monto_orden'  => (string)($respaldo['monto_orden'] ?? $pl['monto_orden'] ?? ''),
            'moneda'       => strtoupper(rc_val($pl, 'moneda', $respaldo['moneda'] ?? 'MXN')),
            'id_franquicia'        => (string)($respaldo['id_franquicia']        ?? '0'),
            'id_distribuidor'      => (string)($respaldo['id_distribuidor']      ?? '0'),
            'id_gestor'            => (string)($pl['id_gestor']                  ?? '0'),
            'pais'                 => (string)($respaldo['pais']                 ?? 'México'),
            'region'               => (string)($respaldo['region']               ?? ''),
            'zona'                 => (string)($respaldo['zona']                 ?? ''),
            'unidad'               => (string)($respaldo['unidad']               ?? ''),
            'actor_tipo_publico'   => (string)($respaldo['actor_tipo_publico']   ?? 'ADMINPATS'),
            'tipo_origen'          => (string)($respaldo['tipo_origen']          ?? ''),
            'stripe_payment_intent_id' => (string)($respaldo['stripe_payment_intent_id'] ?? ''),
            'foto_base64'  => rc_val($pl, 'foto_base64'),
            'firma_base64' => rc_val($pl, 'firma_base64'),
        ];
    }
}

/* ── Helpers de campo ── */
function rc_input(string $name, array $d, string $type = 'text', string $placeholder = '', bool $req = false, string $extraClass = '', string $extraAttr = ''): string {
    $val = rc_h($d[$name] ?? '');
    $r   = $req ? ' required' : '';
    $ph  = $placeholder !== '' ? ' placeholder="'.rc_h($placeholder).'"' : '';
    $cls = 'input' . ($extraClass !== '' ? ' '.$extraClass : '');
    return "<input class=\"{$cls}\" type=\"{$type}\" name=\"{$name}\" id=\"{$name}\" value=\"{$val}\"{$ph}{$r}{$extraAttr}>";
}
function rc_sel(string $name, array $opts, array $d, bool $req = false): string {
    $cur = rc_clean($d[$name] ?? '');
    $r   = $req ? ' required' : '';
    $out = "<select class=\"sel\" name=\"{$name}\" id=\"{$name}\"{$r}>";
    foreach ($opts as $v => $lbl) {
        $s    = ($cur === (string)$v) ? ' selected' : '';
        $out .= "<option value=\"".rc_h((string)$v)."\"{$s}>".rc_h($lbl)."</option>";
    }
    return $out . '</select>';
}

/* ── Datos de presentación en sidebar ── */
$sideNombre  = '';
$sideMonto   = '';
$sideFrecuencia = '';
$sideCorreo  = '';
if ($respaldo) {
    $sideNombre  = trim(($respaldo['nombres'] ?? '') . ' ' . ($respaldo['apellido_pa'] ?? ''));
    $sideMonto   = $formData['monto_orden'] ?? '';
    $sideFrecuencia = strtolower($formData['frecuencia'] ?? 'mensual');
    $sideCorreo  = $respaldo['correo'] ?? '';
}
?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PATS · Completa tu alta</title>
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
      overflow-x: hidden;
    }

    /* ── TOPBAR ── */
    .topbar {
      position: sticky; top:0; z-index:100;
      min-height: 60px; height: auto;
      padding: 10px clamp(14px,3vw,28px);
      background:
        radial-gradient(circle at top left, rgba(6,182,212,.16), transparent 34%),
        linear-gradient(135deg, rgba(13,27,62,.98), rgba(22,37,80,.98));
      backdrop-filter: blur(20px);
      border-bottom: 1px solid rgba(255,255,255,.08);
      display: flex; align-items:center; justify-content:space-between; gap:14px;
    }
    .topbar__brand {
      min-width:0; display:flex; align-items:center; gap:10px;
      font-size: clamp(15px,2.2vw,17px); font-weight:800; color:#fff; letter-spacing:-.02em; line-height:1.15;
    }
    .topbar__brand i {
      width:34px; height:34px; border-radius:12px; flex:0 0 34px;
      display:inline-flex; align-items:center; justify-content:center;
      font-size:21px; color:var(--cyan);
      background:rgba(6,182,212,.11); border:1px solid rgba(6,182,212,.22);
    }
    .topbar__tag {
      min-width:0; font-size:10.5px; font-weight:700; letter-spacing:.10em;
      text-transform:uppercase; color:rgba(255,255,255,.38);
      white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    }
    .topbar__badge {
      flex:0 0 auto; display:inline-flex; align-items:center; justify-content:center; gap:6px;
      min-height:30px; font-size:11.5px; font-weight:700; color:var(--cyan-light);
      background:rgba(6,182,212,.12); border:1px solid rgba(6,182,212,.30);
      padding:6px 14px; border-radius:999px; white-space:nowrap;
      box-shadow:inset 0 1px 0 rgba(255,255,255,.06);
    }
    .topbar__badge i { font-size:14px; }

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
      content:''; position:absolute; top:-50px; right:-50px;
      width:180px; height:180px; border-radius:50%;
      background:radial-gradient(circle, rgba(6,182,212,.18) 0%, transparent 70%);
    }
    .sidebar__hero::after {
      content:''; position:absolute; bottom:-30px; left:-30px;
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
    .hero__title { font-size:19px; font-weight:800; line-height:1.25; margin-bottom:5px; position:relative; z-index:1; }
    .hero__sub   { font-size:12.5px; opacity:.65; line-height:1.55; position:relative; z-index:1; }
    .hero__patient {
      background:rgba(255,255,255,.09); border:1px solid rgba(255,255,255,.15);
      border-radius:12px; padding:14px 16px; margin-top:18px; position:relative; z-index:1;
    }
    .hero__patient-label {
      font-size:10px; font-weight:700; letter-spacing:.10em;
      text-transform:uppercase; opacity:.6; margin-bottom:6px;
    }
    .hero__patient-name { font-size:16px; font-weight:800; line-height:1.2; margin-bottom:4px; }
    .hero__patient-meta { font-size:11.5px; opacity:.6; }
    .hero__secure {
      display:flex; align-items:center; gap:6px; margin-top:10px; font-size:11.5px; opacity:.55;
    }
    .hero__secure i { font-size:13px; color:var(--cyan); opacity:1; }

    /* ── INFO BOX (sidebar) ── */
    .step-box {
      background: var(--surface); border:1px solid var(--border);
      border-radius: var(--radius); padding:18px 20px; box-shadow: var(--shadow-sm);
    }
    .step-box__label {
      font-size:10.5px; font-weight:700; letter-spacing:.09em;
      text-transform:uppercase; color:var(--slate-400); margin-bottom:14px;
    }
    .check-list { list-style:none; display:flex; flex-direction:column; gap:10px; }
    .check-item {
      display:flex; align-items:flex-start; gap:10px;
      font-size:13px; color:var(--slate-600); line-height:1.4;
    }
    .check-item i { font-size:16px; color:var(--blue-mid); flex-shrink:0; margin-top:1px; }

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
      height:100%; width:100%;
      background:linear-gradient(90deg, var(--blue-mid), var(--cyan));
      border-radius:0 4px 4px 0;
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
      text-transform:uppercase; color:var(--blue-mid); margin-bottom:10px;
    }
    .card-title { font-size:21px; font-weight:800; color:var(--slate-800); letter-spacing:-.02em; margin-bottom:5px; }
    .card-desc  { font-size:13.5px; color:var(--slate-500); line-height:1.55; }

    /* Card body */
    .card-body { padding:32px 38px; display:flex; flex-direction:column; gap:28px; }

    /* Card footer */
    .card-footer {
      padding:20px 38px 26px;
      border-top:1px solid var(--slate-100);
      background:var(--surface-2);
      display:flex; align-items:center; justify-content:flex-end; gap:16px;
    }

    /* ── SECTION DIVIDER ── */
    .sec-divider {
      display:flex; align-items:center; gap:12px;
    }
    .sec-divider__line { flex:1; height:1px; background:var(--border); }
    .sec-divider__lbl {
      font-size:10.5px; font-weight:700; letter-spacing:.09em;
      text-transform:uppercase; color:var(--slate-400);
      white-space:nowrap;
    }

    /* ── FIELDS ── */
    .fields { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
    .fields-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:18px; }
    .fields-4 { display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:18px; }
    .field  { display:flex; flex-direction:column; gap:7px; }
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
      color:var(--slate-300); font-size:17px; pointer-events:none; transition:color .2s;
    }
    .input-wrap:focus-within .icon-l { color:var(--blue-mid); }

    .input, .sel {
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
    .input:focus, .sel:focus {
      border-color:var(--blue-mid);
      box-shadow:0 0 0 3px rgba(37,99,235,.10);
      background:#fff;
    }
    .sel {
      cursor:pointer;
      background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%2394a3b8' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
      background-repeat:no-repeat; background-position:right 13px center; padding-right:36px;
    }
    .input[style*="font-family:var(--mono)"] { font-family:var(--mono); }

    /* ── FILE ZONE ── */
    .file-zone {
      position:relative; border:2px dashed rgba(37,99,235,.25);
      border-radius:var(--radius-sm); background:rgba(37,99,235,.03);
      padding:20px 16px; text-align:center; cursor:pointer; transition:all .2s;
    }
    .file-zone:hover, .file-zone.dragover { border-color:var(--blue-mid); background:rgba(37,99,235,.07); }
    .file-zone.filled { border-style:solid; border-color:var(--success); background:var(--success-bg); }
    .file-zone input[type=file] { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%; }
    .file-zone__icon { font-size:26px; color:var(--blue-light); margin-bottom:6px; }
    .file-zone.filled .file-zone__icon { color:var(--success); }
    .file-zone__title { font-size:13px; font-weight:600; color:var(--slate-600); }
    .file-zone__sub   { font-size:11px; color:var(--slate-400); margin-top:3px; }
    .file-zone__name  { font-size:12.5px; font-weight:600; color:var(--success); margin-top:6px; display:none; }
    .file-zone.filled .file-zone__name  { display:block; }
    .file-zone.filled .file-zone__title,
    .file-zone.filled .file-zone__sub   { display:none; }
    .docs-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }

    /* ── FOTO PREVIEW (respaldo) ── */
    .foto-preview {
      display:flex; align-items:center; gap:14px;
      background:var(--surface-2); border:1px solid var(--border);
      border-radius:var(--radius-sm); padding:14px;
    }
    .foto-preview img {
      width:72px; height:72px; border-radius:var(--radius-sm);
      object-fit:cover; border:2px solid var(--border);
    }
    .foto-preview__info { font-size:12.5px; color:var(--slate-500); line-height:1.5; }
    .foto-preview__lbl  { font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--slate-400); margin-bottom:3px; }

    /* ── CAMERA ── */
    .cam-wrap { display:flex; flex-direction:column; align-items:center; gap:14px; }
    .cam-stage {
      position:relative; width:100%; max-width:420px; aspect-ratio:4/3;
      background:var(--slate-800); border-radius:var(--radius); overflow:hidden;
      display:flex; align-items:center; justify-content:center;
      box-shadow:0 8px 24px rgba(0,0,0,.2);
    }
    .cam-stage video, .cam-stage canvas, .cam-stage img { width:100%; height:100%; object-fit:cover; display:block; }
    .cam-guide {
      position:absolute; width:48%; aspect-ratio:3/4;
      border:2px dashed rgba(6,182,212,.7); border-radius:8px; pointer-events:none;
      box-shadow:0 0 0 1000px rgba(0,0,0,.25);
    }
    .cam-placeholder {
      display:flex; flex-direction:column; align-items:center; gap:10px;
      color:var(--slate-400); padding:40px; text-align:center;
    }
    .cam-placeholder i { font-size:44px; color:var(--slate-500); }
    .cam-placeholder span { font-size:13px; }
    .cam-controls { display:flex; gap:10px; flex-wrap:wrap; justify-content:center; }
    .cam-alt { font-size:12px; color:var(--slate-400); margin-top:4px; text-align:center; }
    .cam-alt label { color:var(--blue-mid); cursor:pointer; font-weight:600; text-decoration:underline; }
    .cam-captured { position:relative; width:100%; max-width:420px; }
    .cam-captured img { width:100%; border-radius:var(--radius); box-shadow:0 8px 24px rgba(0,0,0,.2); display:block; }
    .cam-captured__ok {
      position:absolute; top:10px; right:10px;
      background:var(--success); color:#fff; border-radius:100px;
      padding:4px 12px; font-size:12px; font-weight:700;
      display:flex; align-items:center; gap:5px;
    }

    /* ── PASSWORD STRENGTH ── */
    .pw-strength-wrap { height:4px; background:var(--slate-100); border-radius:2px; margin-top:6px; overflow:hidden; }
    .pw-strength-bar  { height:100%; width:0; border-radius:2px; transition:width .3s, background .3s; }

    /* ── INFO NOTE ── */
    .info-note {
      display:flex; align-items:flex-start; gap:10px;
      padding:12px 14px;
      background:rgba(37,99,235,.05); border-left:3px solid var(--blue-mid);
      border-radius:0 var(--radius-sm) var(--radius-sm) 0;
      font-size:13px; color:var(--slate-600); line-height:1.55;
    }
    .info-note i { font-size:16px; color:var(--blue-mid); flex-shrink:0; margin-top:1px; }

    .warn-note {
      display:flex; align-items:flex-start; gap:10px;
      padding:12px 14px;
      background:rgba(245,158,11,.06); border-left:3px solid var(--warning);
      border-radius:0 var(--radius-sm) var(--radius-sm) 0;
      font-size:13px; color:var(--slate-600); line-height:1.55;
    }
    .warn-note i { font-size:16px; color:var(--warning); flex-shrink:0; margin-top:1px; }

    /* ── MINI CARD (tutor) ── */
    .mini-card { background:var(--surface-2); border:1.5px solid var(--border); border-radius:var(--radius); overflow:hidden; }
    .mini-card__head {
      padding:10px 16px;
      background:linear-gradient(135deg, var(--navy), var(--navy-2));
      color:#fff; font-size:12.5px; font-weight:700;
      display:flex; align-items:center; gap:8px;
    }
    .mini-card__head i { font-size:15px; color:var(--cyan); }
    .mini-card__body { padding:16px; }

    /* ── ERROR SCREEN ── */
    .error-screen {
      padding:56px 38px; text-align:center;
    }
    .error-screen__icon { font-size:56px; color:var(--danger); margin-bottom:16px; }
    .error-screen__title { font-size:22px; font-weight:800; color:var(--slate-800); margin-bottom:8px; }
    .error-screen__msg { font-size:14px; color:var(--slate-500); line-height:1.6; max-width:380px; margin:0 auto; }

    /* ── BUTTONS ── */
    .btn {
      display:inline-flex; align-items:center; justify-content:center; gap:8px;
      padding:11px 24px; min-height:42px;
      border-radius:var(--radius-sm);
      font-family:var(--font); font-size:13.5px; font-weight:700;
      border:none; cursor:pointer; transition:all .18s; white-space:nowrap;
    }
    .btn i { font-size:17px; }
    .btn:disabled { opacity:.45; cursor:not-allowed; }
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
    .btn--loading { pointer-events:none; }

    /* ── RESPONSIVE ── */
    @media (max-width:1100px) {
      .layout { grid-template-columns:240px minmax(0,1fr); gap:22px; padding:28px 18px 72px; }
      .card-header, .card-body, .card-footer { padding-left:28px; padding-right:28px; }
    }
    @media (max-width:960px) {
      .layout { grid-template-columns:1fr; padding:16px 14px 76px; gap:14px; }
      .sidebar { position:static; }
      .sidebar__hero { display:none; }
      .fields, .fields-3, .fields-4, .docs-grid { grid-template-columns:1fr 1fr; }
    }
    @media (max-width:720px) {
      .topbar { align-items:flex-start; flex-direction:column; gap:8px; }
      .card-footer { flex-direction:column; }
      .card-footer .btn { width:100%; }
    }
    @media (max-width:560px) {
      .fields, .fields-3, .fields-4, .docs-grid { grid-template-columns:1fr; }
      .card-body { padding:22px 20px; gap:22px; }
      .card-footer { padding:14px 20px 20px; }
    }
  </style>
</head>
<body>

  <!-- TOPBAR -->
  <header class="topbar">
    <div class="topbar__brand">
      <i class="mdi mdi-card-account-details-outline"></i>
      <div>
        <div>PATS</div>
        <div class="topbar__tag">Pasaporte a tu Salud</div>
      </div>
    </div>
    <div class="topbar__badge">
      <i class="mdi mdi-shield-check"></i> Proceso seguro
    </div>
  </header>

  <div class="layout">

    <!-- ════ SIDEBAR ════ -->
    <aside class="sidebar">

      <div class="sidebar__hero">
        <div class="hero__icon"><i class="mdi mdi-account-reactivate-outline"></i></div>
        <div class="hero__title">Recuperación<br>de Alta</div>
        <div class="hero__sub">Completa y verifica tus datos para activar tu Pasaporte a tu Salud.</div>

        <?php if ($respaldo && $sideNombre !== ''): ?>
        <div class="hero__patient">
          <div class="hero__patient-label">Solicitud encontrada</div>
          <div class="hero__patient-name"><?= rc_h($sideNombre) ?></div>
          <?php if ($sideCorreo): ?>
          <div class="hero__patient-meta"><?= rc_h($sideCorreo) ?></div>
          <?php endif; ?>
          <?php if ($sideMonto): ?>
          <div class="hero__patient-meta" style="margin-top:4px">
            $<?= rc_h(number_format((float)$sideMonto, 0, '.', ',')) ?> MXN / <?= rc_h($sideFrecuencia) ?>
          </div>
          <?php endif; ?>
        </div>
        <?php elseif (!$respaldo && $tokenValido): ?>
        <div class="hero__patient">
          <div class="hero__patient-label">Alta nueva</div>
          <div class="hero__patient-meta">Completa todos los campos para registrar tu pasaporte.</div>
        </div>
        <?php endif; ?>

        <div class="hero__secure">
          <i class="mdi mdi-lock"></i>
          Formulario cifrado · Datos protegidos
        </div>
      </div>

      <div class="step-box">
        <div class="step-box__label">¿Qué necesitas tener listo?</div>
        <ul class="check-list">
          <li class="check-item"><i class="mdi mdi-card-account-details-outline"></i><span>Identificación oficial (INE o pasaporte)</span></li>
          <li class="check-item"><i class="mdi mdi-file-certificate-outline"></i><span>CURP en documento</span></li>
          <li class="check-item"><i class="mdi mdi-home-map-marker"></i><span>Comprobante de domicilio reciente</span></li>
          <li class="check-item"><i class="mdi mdi-camera-account"></i><span>Fotografía tipo retrato (frente)</span></li>
          <li class="check-item"><i class="mdi mdi-lock-outline"></i><span>Contraseña que desees usar para tu cuenta</span></li>
        </ul>
      </div>

    </aside>

    <!-- ════ MAIN ════ -->
    <main>

      <?php if ($errorFatal !== '' || !$tokenValido): ?>

        <div class="main-card">
          <div class="progress-wrap"><div class="progress-fill" style="width:0;background:var(--danger)"></div></div>
          <div class="error-screen">
            <div class="error-screen__icon"><i class="mdi mdi-link-off"></i></div>
            <div class="error-screen__title">Enlace no disponible</div>
            <div class="error-screen__msg">
              <?= rc_h($errorFatal !== '' ? $errorFatal : 'No se pudo verificar el enlace.') ?>
              <br><br>
              Comunícate con tu asesor para solicitar un nuevo enlace de recuperación.
            </div>
          </div>
        </div>

      <?php else: ?>

        <form method="POST" action="endpoints/recuperar_pats_procesar.php" enctype="multipart/form-data" id="frmCliente" novalidate>

          <!-- Campos ocultos de control -->
          <input type="hidden" name="token"       value="<?= rc_h($tokenClaro) ?>">
          <input type="hidden" name="id_respaldo" value="<?= $idRespaldo ?>">
          <input type="hidden" name="id_franquicia"          value="<?= rc_h($formData['id_franquicia']      ?? '0') ?>">
          <input type="hidden" name="id_distribuidor"        value="<?= rc_h($formData['id_distribuidor']    ?? '0') ?>">
          <input type="hidden" name="id_gestor"              value="<?= rc_h($formData['id_gestor']          ?? '0') ?>">
          <input type="hidden" name="pais"                   value="<?= rc_h($formData['pais']               ?? 'México') ?>">
          <input type="hidden" name="region"                 value="<?= rc_h($formData['region']             ?? '') ?>">
          <input type="hidden" name="zona"                   value="<?= rc_h($formData['zona']               ?? '') ?>">
          <input type="hidden" name="unidad"                 value="<?= rc_h($formData['unidad']             ?? '') ?>">
          <input type="hidden" name="actor_tipo_publico"     value="<?= rc_h($formData['actor_tipo_publico'] ?? 'ADMINPATS') ?>">
          <input type="hidden" name="tipo_origen"            value="<?= rc_h($formData['tipo_origen']        ?? '') ?>">
          <input type="hidden" name="stripe_payment_intent_id" value="<?= rc_h($formData['stripe_payment_intent_id'] ?? '') ?>">
          <input type="hidden" name="frecuencia"             value="<?= rc_h($formData['frecuencia']         ?? 'MENSUAL') ?>">
          <input type="hidden" name="monto_orden"            value="<?= rc_h($formData['monto_orden']        ?? '0') ?>">
          <input type="hidden" name="moneda"                 value="<?= rc_h($formData['moneda']             ?? 'MXN') ?>">
          <input type="hidden" name="dom_estado_acronimo"    value="<?= rc_h($formData['dom_estado_acronimo'] ?? '') ?>">
          <?php if (!empty($formData['foto_base64'])): ?>
          <textarea name="foto_base64" style="display:none"><?= rc_h($formData['foto_base64']) ?></textarea>
          <?php endif; ?>
          <?php if (!empty($formData['firma_base64'])): ?>
          <textarea name="firma_base64" style="display:none"><?= rc_h($formData['firma_base64']) ?></textarea>
          <?php endif; ?>

          <div class="main-card">

            <!-- Progress -->
            <div class="progress-wrap">
              <div class="progress-fill"></div>
            </div>

            <!-- Header -->
            <div class="card-header">
              <div>
                <div class="card-tag">
                  <i class="mdi mdi-account-reactivate-outline"></i>
                  Recuperación de alta PATS
                </div>
                <h1 class="card-title">Completa tu alta</h1>
                <p class="card-desc">
                  <?php if ($respaldo): ?>
                  Encontramos tu solicitud previa. Verifica tus datos y crea tu contraseña de acceso.
                  <?php else: ?>
                  Completa tu información para activar tu Pasaporte a tu Salud.
                  <?php endif; ?>
                </p>
              </div>
            </div>

            <!-- Body -->
            <div class="card-body">

              <?php if ($respaldo): ?>
              <div class="info-note">
                <i class="mdi mdi-information-outline"></i>
                <span>Tus datos fueron pre-llenados con la información de tu solicitud anterior. Revisa que todo sea correcto antes de enviar.</span>
              </div>
              <?php endif; ?>

              <!-- ══ DATOS PERSONALES ══ -->
              <div>
                <div class="sec-divider" style="margin-bottom:18px">
                  <div class="sec-divider__line"></div>
                  <span class="sec-divider__lbl">Datos personales</span>
                  <div class="sec-divider__line"></div>
                </div>

                <div class="fields-3">
                  <div class="field">
                    <label class="label" for="nombre_usuario">Nombre(s) <span class="label__req">*</span></label>
                    <div class="input-wrap">
                      <i class="mdi mdi-account-outline icon-l"></i>
                      <?= rc_input('nombre_usuario', $formData, 'text', 'Nombre(s) de pila', true) ?>
                    </div>
                  </div>
                  <div class="field">
                    <label class="label" for="apellido_pa">Apellido paterno <span class="label__req">*</span></label>
                    <div class="input-wrap">
                      <i class="mdi mdi-account-outline icon-l"></i>
                      <?= rc_input('apellido_pa', $formData, 'text', 'Primer apellido', true) ?>
                    </div>
                  </div>
                  <div class="field">
                    <label class="label" for="apellido_ma">Apellido materno <span class="label__opt">Opcional</span></label>
                    <div class="input-wrap">
                      <i class="mdi mdi-account-outline icon-l"></i>
                      <?= rc_input('apellido_ma', $formData, 'text', 'Segundo apellido') ?>
                    </div>
                  </div>
                </div>

                <div class="fields-3" style="margin-top:18px">
                  <div class="field">
                    <label class="label" for="curp_usuario">CURP <span class="label__opt">18 caracteres</span></label>
                    <div class="input-wrap">
                      <i class="mdi mdi-card-account-details-outline icon-l"></i>
                      <?= rc_input('curp_usuario', $formData, 'text', 'CURP del afiliado', false, '', ' maxlength="18" style="font-family:var(--mono);text-transform:uppercase;"') ?>
                    </div>
                  </div>
                  <div class="field">
                    <label class="label" for="fecha_nacimiento">Fecha de nacimiento <span class="label__req">*</span></label>
                    <div class="input-wrap">
                      <i class="mdi mdi-cake-variant-outline icon-l"></i>
                      <?= rc_input('fecha_nacimiento', $formData, 'date', '', true, '', ' style="font-family:var(--mono);"') ?>
                    </div>
                  </div>
                  <div class="field">
                    <label class="label" for="nacionalidad_tipo">Residencia <span class="label__req">*</span></label>
                    <div class="input-wrap">
                      <i class="mdi mdi-earth icon-l"></i>
                      <?= rc_sel('nacionalidad_tipo', ['MEXICANA'=>'México','EXTRANJERA'=>'Otro país'], $formData, true) ?>
                    </div>
                  </div>
                </div>

                <div class="fields" style="margin-top:18px">
                  <div class="field">
                    <label class="label" for="correo_usuario_pats">Correo electrónico <span class="label__req">*</span></label>
                    <div class="input-wrap">
                      <i class="mdi mdi-email-outline icon-l"></i>
                      <?= rc_input('correo_usuario_pats', $formData, 'email', 'ejemplo@correo.com', true) ?>
                    </div>
                  </div>
                  <div class="field">
                    <label class="label" for="telefono_usuario">Teléfono celular <span class="label__req">*</span></label>
                    <div class="input-wrap">
                      <i class="mdi mdi-phone-outline icon-l"></i>
                      <?= rc_input('telefono_usuario', $formData, 'tel', '10 dígitos', true, '', ' maxlength="10" inputmode="numeric"') ?>
                    </div>
                  </div>
                </div>
              </div>

              <!-- ══ DOMICILIO ══ -->
              <div>
                <div class="sec-divider" style="margin-bottom:18px">
                  <div class="sec-divider__line"></div>
                  <span class="sec-divider__lbl">Domicilio</span>
                  <div class="sec-divider__line"></div>
                </div>

                <div class="fields-3">
                  <div class="field">
                    <label class="label" for="dom_calle">Calle</label>
                    <div class="input-wrap">
                      <i class="mdi mdi-road-variant icon-l"></i>
                      <?= rc_input('dom_calle', $formData, 'text', 'Nombre de la calle') ?>
                    </div>
                  </div>
                  <div class="field">
                    <label class="label" for="dom_num_ext">Núm. exterior</label>
                    <div class="input-wrap">
                      <i class="mdi mdi-pound icon-l"></i>
                      <?= rc_input('dom_num_ext', $formData, 'text', 'Ej. 123') ?>
                    </div>
                  </div>
                  <div class="field">
                    <label class="label" for="dom_num_int">Núm. interior <span class="label__opt">Si aplica</span></label>
                    <div class="input-wrap">
                      <i class="mdi mdi-pound icon-l"></i>
                      <?= rc_input('dom_num_int', $formData, 'text', 'Depto, local…') ?>
                    </div>
                  </div>
                </div>

                <div class="fields-4" style="margin-top:18px">
                  <div class="field">
                    <label class="label" for="dom_colonia">Colonia</label>
                    <div class="input-wrap">
                      <i class="mdi mdi-map-marker-outline icon-l"></i>
                      <?= rc_input('dom_colonia', $formData) ?>
                    </div>
                  </div>
                  <div class="field">
                    <label class="label" for="dom_cp">C.P.</label>
                    <div class="input-wrap">
                      <i class="mdi mdi-mailbox-outline icon-l"></i>
                      <?= rc_input('dom_cp', $formData, 'text', '5 dígitos', false, '', ' maxlength="5" inputmode="numeric"') ?>
                    </div>
                  </div>
                  <div class="field">
                    <label class="label" for="dom_municipio">Municipio / Alcaldía</label>
                    <div class="input-wrap">
                      <i class="mdi mdi-city-variant-outline icon-l"></i>
                      <?= rc_input('dom_municipio', $formData) ?>
                    </div>
                  </div>
                  <div class="field">
                    <label class="label" for="dom_estado">Estado</label>
                    <div class="input-wrap">
                      <i class="mdi mdi-map-outline icon-l"></i>
                      <?= rc_input('dom_estado', $formData) ?>
                    </div>
                  </div>
                </div>

                <div class="fields" style="margin-top:18px">
                  <div class="field">
                    <label class="label" for="dom_pais">País</label>
                    <div class="input-wrap">
                      <i class="mdi mdi-earth icon-l"></i>
                      <?= rc_input('dom_pais', $formData, 'text', 'México') ?>
                    </div>
                  </div>
                </div>
              </div>

              <!-- ══ FIRMA / REPRESENTACIÓN ══ -->
              <div>
                <div class="sec-divider" style="margin-bottom:18px">
                  <div class="sec-divider__line"></div>
                  <span class="sec-divider__lbl">Firma y acceso</span>
                  <div class="sec-divider__line"></div>
                </div>

                <div class="field">
                  <label class="label" for="modo_firma">¿Quién firmará y administrará este pasaporte? <span class="label__req">*</span></label>
                  <div class="input-wrap">
                    <i class="mdi mdi-pen-plus icon-l"></i>
                    <?= rc_sel('modo_firma', [
                      'FIRMA_PROPIA'           => 'Yo mismo (adulto)',
                      'TUTOR_FAMILIAR'         => 'Mi mamá, papá o tutor (para menores)',
                      'RESPONSABLE_AUTORIZADO' => 'Un responsable autorizado'
                    ], $formData, true) ?>
                  </div>
                </div>

                <!-- Sección tutor (se muestra/oculta con JS) -->
                <div id="secTutor" style="display:none; margin-top:20px;">
                  <div class="mini-card">
                    <div class="mini-card__head">
                      <i class="mdi mdi-account-tie-outline"></i>
                      Datos de la persona responsable / tutor
                    </div>
                    <div class="mini-card__body">
                      <div class="fields-3">
                        <div class="field">
                          <label class="label" for="tutor_nombre">Nombre(s)</label>
                          <div class="input-wrap">
                            <i class="mdi mdi-account-outline icon-l"></i>
                            <?= rc_input('tutor_nombre', $formData) ?>
                          </div>
                        </div>
                        <div class="field">
                          <label class="label" for="tutor_apellido_pa">Apellido paterno</label>
                          <div class="input-wrap">
                            <i class="mdi mdi-account-outline icon-l"></i>
                            <?= rc_input('tutor_apellido_pa', $formData) ?>
                          </div>
                        </div>
                        <div class="field">
                          <label class="label" for="tutor_apellido_ma">Apellido materno</label>
                          <div class="input-wrap">
                            <i class="mdi mdi-account-outline icon-l"></i>
                            <?= rc_input('tutor_apellido_ma', $formData) ?>
                          </div>
                        </div>
                      </div>
                      <div class="fields-3" style="margin-top:14px">
                        <div class="field">
                          <label class="label" for="tutor_curp">CURP tutor</label>
                          <div class="input-wrap">
                            <i class="mdi mdi-card-account-details-outline icon-l"></i>
                            <?= rc_input('tutor_curp', $formData, 'text', '', false, '', ' maxlength="18" style="font-family:var(--mono);text-transform:uppercase;"') ?>
                          </div>
                        </div>
                        <div class="field">
                          <label class="label" for="tutor_fecha_nacimiento">Fecha nacimiento</label>
                          <div class="input-wrap">
                            <i class="mdi mdi-cake-variant-outline icon-l"></i>
                            <?= rc_input('tutor_fecha_nacimiento', $formData, 'date', '', false, '', ' style="font-family:var(--mono);"') ?>
                          </div>
                        </div>
                        <div class="field">
                          <label class="label" for="relacion_responsable_paciente">Relación con el paciente</label>
                          <div class="input-wrap">
                            <i class="mdi mdi-account-heart-outline icon-l"></i>
                            <?= rc_input('relacion_responsable_paciente', $formData, 'text', 'padre, madre, tutor…') ?>
                          </div>
                        </div>
                      </div>
                      <div class="fields" style="margin-top:14px">
                        <div class="field">
                          <label class="label" for="tutor_correo">Correo tutor</label>
                          <div class="input-wrap">
                            <i class="mdi mdi-email-outline icon-l"></i>
                            <?= rc_input('tutor_correo', $formData, 'email') ?>
                          </div>
                        </div>
                        <div class="field">
                          <label class="label" for="tutor_telefono">Teléfono tutor</label>
                          <div class="input-wrap">
                            <i class="mdi mdi-phone-outline icon-l"></i>
                            <?= rc_input('tutor_telefono', $formData, 'tel', '10 dígitos') ?>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- ══ DOCUMENTOS ══ -->
              <div>
                <div class="sec-divider" style="margin-bottom:18px">
                  <div class="sec-divider__line"></div>
                  <span class="sec-divider__lbl">Documentos</span>
                  <div class="sec-divider__line"></div>
                </div>

                <div class="docs-grid">
                  <label class="file-zone" id="zone_ine_f">
                    <input type="file" name="doc_identificacion_frente" accept=".pdf,.png,.jpg,.jpeg,.webp" data-zone="zone_ine_f">
                    <div class="file-zone__icon"><i class="mdi mdi-card-account-details"></i></div>
                    <div class="file-zone__title">INE / Identificación — frente</div>
                    <div class="file-zone__sub">PDF, PNG o JPG</div>
                    <div class="file-zone__name"></div>
                  </label>

                  <label class="file-zone" id="zone_ine_r">
                    <input type="file" name="doc_identificacion_reverso" accept=".pdf,.png,.jpg,.jpeg,.webp" data-zone="zone_ine_r">
                    <div class="file-zone__icon"><i class="mdi mdi-card-account-details-outline"></i></div>
                    <div class="file-zone__title">INE / Identificación — reverso</div>
                    <div class="file-zone__sub">PDF, PNG o JPG</div>
                    <div class="file-zone__name"></div>
                  </label>

                  <label class="file-zone" id="zone_curp">
                    <input type="file" name="doc_curp" accept=".pdf,.png,.jpg,.jpeg,.webp" data-zone="zone_curp">
                    <div class="file-zone__icon"><i class="mdi mdi-file-certificate-outline"></i></div>
                    <div class="file-zone__title">CURP documental</div>
                    <div class="file-zone__sub">PDF, PNG o JPG</div>
                    <div class="file-zone__name"></div>
                  </label>

                  <label class="file-zone" id="zone_dom">
                    <input type="file" name="doc_comprobante_domicilio" accept=".pdf,.png,.jpg,.jpeg,.webp" data-zone="zone_dom">
                    <div class="file-zone__icon"><i class="mdi mdi-home-map-marker"></i></div>
                    <div class="file-zone__title">Comprobante de domicilio</div>
                    <div class="file-zone__sub">PDF, PNG o JPG</div>
                    <div class="file-zone__name"></div>
                  </label>
                </div>
              </div>

              <!-- ══ FOTOGRAFÍA ══ -->
              <div>
                <div class="sec-divider" style="margin-bottom:18px">
                  <div class="sec-divider__line"></div>
                  <span class="sec-divider__lbl">Fotografía</span>
                  <div class="sec-divider__line"></div>
                </div>

                <?php if (!empty($formData['foto_base64'])): ?>
                <div class="foto-preview" style="margin-bottom:14px">
                  <img src="<?= rc_h($formData['foto_base64']) ?>" alt="Foto registrada">
                  <div>
                    <div class="foto-preview__lbl">Foto registrada previamente</div>
                    <div class="foto-preview__info">Puedes tomar una nueva foto para actualizarla.</div>
                  </div>
                </div>
                <?php endif; ?>

                <!-- Cámara -->
                <div class="cam-wrap">
                  <!-- Estado: sin cámara activa -->
                  <div class="cam-stage" id="camStage">
                    <div class="cam-placeholder" id="camPlaceholder">
                      <i class="mdi mdi-camera-account"></i>
                      <span>Presiona el botón para activar la cámara y tomar tu fotografía</span>
                    </div>
                    <video id="camVideo" autoplay playsinline style="display:none"></video>
                    <div class="cam-guide" id="camGuide" style="display:none"></div>
                    <canvas id="camCanvas" style="display:none"></canvas>
                  </div>

                  <!-- Foto capturada -->
                  <div class="cam-captured" id="camCaptured" style="display:none">
                    <img id="camPreview" alt="Foto tomada">
                    <div class="cam-captured__ok"><i class="mdi mdi-check"></i> Foto lista</div>
                  </div>

                  <!-- Controles -->
                  <div class="cam-controls" id="camControls">
                    <button type="button" class="btn btn--primary" id="btnActivarCam">
                      <i class="mdi mdi-camera"></i> Activar cámara
                    </button>
                  </div>
                  <div class="cam-controls" id="camShootControls" style="display:none">
                    <button type="button" class="btn btn--success" id="btnCapturar">
                      <i class="mdi mdi-circle-slice-8"></i> Tomar foto
                    </button>
                    <button type="button" class="btn" style="background:var(--surface-2);color:var(--slate-600);border:1.5px solid var(--border);" id="btnCancelarCam">
                      Cancelar
                    </button>
                  </div>
                  <div class="cam-controls" id="camRetakeControls" style="display:none">
                    <button type="button" class="btn btn--primary" id="btnRetomar">
                      <i class="mdi mdi-camera-retake"></i> Tomar otra
                    </button>
                  </div>

                  <div class="cam-alt">
                    ¿Problemas con la cámara?
                    <label for="foto_galeria">Seleccionar desde galería</label>
                  </div>
                  <input type="file" id="foto_galeria" name="foto_nueva" accept=".jpg,.jpeg,.png,.webp" style="display:none">
                </div>

                <!-- base64 de la foto (oculto) -->
                <textarea name="foto_base64" id="fotoBase64Ta" style="display:none"><?= !empty($formData['foto_base64']) ? rc_h($formData['foto_base64']) : '' ?></textarea>
              </div>

              <!-- ══ CONTRASEÑA ══ -->
              <div>
                <div class="sec-divider" style="margin-bottom:18px">
                  <div class="sec-divider__line"></div>
                  <span class="sec-divider__lbl">Crea tu contraseña</span>
                  <div class="sec-divider__line"></div>
                </div>

                <div class="info-note" style="margin-bottom:18px">
                  <i class="mdi mdi-lock-outline"></i>
                  <span>Esta contraseña la usarás para acceder a tu Pasaporte PATS en línea. Elige una segura y no la compartas.</span>
                </div>

                <div class="fields">
                  <div class="field">
                    <label class="label" for="pwd_nuevo">Nueva contraseña <span class="label__req">*</span></label>
                    <div class="input-wrap">
                      <i class="mdi mdi-lock-outline icon-l"></i>
                      <input class="input" type="password" id="pwd_nuevo" name="pwd_nuevo"
                             placeholder="Mínimo 8 caracteres" required minlength="8"
                             oninput="evalPwd(this.value)">
                    </div>
                    <div class="pw-strength-wrap"><div class="pw-strength-bar" id="pwBar"></div></div>
                  </div>
                  <div class="field">
                    <label class="label" for="pwd_confirmar">Confirmar contraseña <span class="label__req">*</span></label>
                    <div class="input-wrap">
                      <i class="mdi mdi-lock-check-outline icon-l"></i>
                      <input class="input" type="password" id="pwd_confirmar" name="pwd_confirmar"
                             placeholder="Repite tu contraseña" required minlength="8">
                    </div>
                  </div>
                </div>
              </div>

              <!-- Aviso final -->
              <div class="warn-note">
                <i class="mdi mdi-alert-circle-outline"></i>
                <span>Al enviar este formulario confirmas que los datos son correctos y autorizas la creación de tu Pasaporte a tu Salud.</span>
              </div>

            </div><!-- /card-body -->

            <!-- Footer -->
            <div class="card-footer">
              <button type="submit" class="btn btn--success" id="btnEnviar">
                <i class="mdi mdi-check-circle-outline"></i>
                Completar mi alta
              </button>
            </div>

          </div><!-- /main-card -->

        </form>

      <?php endif; ?>

    </main>

  </div><!-- /layout -->

<script>
/* ── Tutor toggle ── */
(function() {
  var modoFirma = document.getElementById('modo_firma');
  var secTutor  = document.getElementById('secTutor');
  if (!modoFirma || !secTutor) return;
  function toggleTutor() {
    var v = modoFirma.value;
    secTutor.style.display = (v === 'TUTOR_FAMILIAR' || v === 'RESPONSABLE_AUTORIZADO') ? '' : 'none';
  }
  modoFirma.addEventListener('change', toggleTutor);
  toggleTutor();
})();

/* ── File zones ── */
document.querySelectorAll('.file-zone input[type=file]').forEach(function(inp) {
  inp.addEventListener('change', function() {
    var zoneId = this.getAttribute('data-zone');
    var zone   = document.getElementById(zoneId);
    if (!zone) return;
    var nameEl = zone.querySelector('.file-zone__name');
    if (this.files && this.files[0]) {
      zone.classList.add('filled');
      if (nameEl) nameEl.textContent = this.files[0].name;
    } else {
      zone.classList.remove('filled');
      if (nameEl) nameEl.textContent = '';
    }
  });
});
document.querySelectorAll('.file-zone').forEach(function(zone) {
  zone.addEventListener('dragover',  function(e) { e.preventDefault(); zone.classList.add('dragover'); });
  zone.addEventListener('dragleave', function()  { zone.classList.remove('dragover'); });
  zone.addEventListener('drop',      function()  { zone.classList.remove('dragover'); });
});

/* ── Password strength ── */
function evalPwd(v) {
  var bar = document.getElementById('pwBar');
  if (!bar) return;
  var score = 0;
  if (v.length >= 8)          score++;
  if (/[A-Z]/.test(v))        score++;
  if (/[0-9]/.test(v))        score++;
  if (/[^A-Za-z0-9]/.test(v)) score++;
  var colors = ['#ef4444','#f97316','#eab308','#10b981'];
  bar.style.background = colors[Math.max(0, score - 1)] || '#e2e8f0';
  bar.style.width = (score * 25) + '%';
}

/* ── CÁMARA ── */
(function() {
  var stage          = document.getElementById('camStage');
  var placeholder    = document.getElementById('camPlaceholder');
  var video          = document.getElementById('camVideo');
  var guide          = document.getElementById('camGuide');
  var canvas         = document.getElementById('camCanvas');
  var preview        = document.getElementById('camPreview');
  var captured       = document.getElementById('camCaptured');
  var controls       = document.getElementById('camControls');
  var shootControls  = document.getElementById('camShootControls');
  var retakeControls = document.getElementById('camRetakeControls');
  var ta             = document.getElementById('fotoBase64Ta');
  var btnActivar     = document.getElementById('btnActivarCam');
  var btnCapturar    = document.getElementById('btnCapturar');
  var btnCancelar    = document.getElementById('btnCancelarCam');
  var btnRetomar     = document.getElementById('btnRetomar');
  var galeria        = document.getElementById('foto_galeria');

  var stream = null;

  function setMode(mode) {
    /* mode: 'idle' | 'live' | 'done' */
    placeholder.style.display    = mode === 'idle' ? '' : 'none';
    video.style.display          = mode === 'live' ? '' : 'none';
    guide.style.display          = mode === 'live' ? '' : 'none';
    canvas.style.display         = 'none';
    captured.style.display       = mode === 'done' ? '' : 'none';
    controls.style.display       = mode === 'idle' ? '' : 'none';
    shootControls.style.display  = mode === 'live' ? '' : 'none';
    retakeControls.style.display = mode === 'done' ? '' : 'none';
  }

  function stopStream() {
    if (stream) { stream.getTracks().forEach(function(t) { t.stop(); }); stream = null; }
  }

  btnActivar && btnActivar.addEventListener('click', function() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      alert('Tu navegador no soporta acceso a la cámara. Usa la opción de galería.');
      return;
    }
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 960 } } })
      .then(function(s) {
        stream = s;
        video.srcObject = s;
        setMode('live');
      })
      .catch(function() {
        alert('No se pudo acceder a la cámara. Verifica los permisos o usa la opción de galería.');
      });
  });

  btnCapturar && btnCapturar.addEventListener('click', function() {
    canvas.width  = video.videoWidth  || 640;
    canvas.height = video.videoHeight || 480;
    canvas.getContext('2d').drawImage(video, 0, 0);
    var dataUrl = canvas.toDataURL('image/jpeg', 0.88);
    preview.src = dataUrl;
    ta.textContent = dataUrl;
    stopStream();
    setMode('done');
  });

  btnCancelar && btnCancelar.addEventListener('click', function() {
    stopStream();
    setMode('idle');
  });

  btnRetomar && btnRetomar.addEventListener('click', function() {
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' } })
      .then(function(s) {
        stream = s;
        video.srcObject = s;
        setMode('live');
      })
      .catch(function() {
        alert('No se pudo reabrir la cámara.');
      });
  });

  /* Galería como alternativa */
  galeria && galeria.addEventListener('change', function() {
    if (!this.files || !this.files[0]) return;
    var reader = new FileReader();
    reader.onload = function(e) {
      ta.textContent = e.target.result;
      preview.src    = e.target.result;
      stopStream();
      setMode('done');
    };
    reader.readAsDataURL(this.files[0]);
  });

  /* Al salir de la página, detener stream */
  window.addEventListener('beforeunload', stopStream);
})();

/* ── Validar antes de enviar ── */
(function() {
  var frm = document.getElementById('frmCliente');
  var btn = document.getElementById('btnEnviar');
  if (!frm) return;
  frm.addEventListener('submit', function(e) {
    var p1 = document.getElementById('pwd_nuevo');
    var p2 = document.getElementById('pwd_confirmar');
    if (p1 && p2 && p1.value !== p2.value) {
      e.preventDefault();
      alert('Las contraseñas no coinciden. Verifica e intenta de nuevo.');
      p2.focus();
      return;
    }
    if (p1 && p1.value.length > 0 && p1.value.length < 8) {
      e.preventDefault();
      alert('La contraseña debe tener al menos 8 caracteres.');
      p1.focus();
      return;
    }
    if (btn) {
      btn.classList.add('btn--loading');
      btn.innerHTML = '<i class="mdi mdi-loading mdi-spin"></i> Enviando…';
    }
  });
})();

/* ── CURP mayúsculas automático ── */
['curp_usuario','tutor_curp'].forEach(function(id) {
  var el = document.getElementById(id);
  if (el) el.addEventListener('input', function() { this.value = this.value.toUpperCase(); });
});
</script>

</body>
</html>