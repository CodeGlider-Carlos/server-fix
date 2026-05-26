<?php
/*
ez/pats/pago_resultado.php

PATS · Resultado público de pago
Estilo alineado a landing pública PATS:
- DM Sans
- fondo dark premium
- nav fija con logo blanco
- gradientes azul/cian/violeta
- card clara tipo landing
- responsive completo
- redirección automática en 5 segundos a pasaporteatusalud.com
*/

require_once '../../varSQL/bd_pats.php';
require_once '../../varSQL/var_pats.php';

$ver = time();
$cx = $conexionMod ?? $db_all2 ?? $db_all ?? null;

if (!$cx || !($cx instanceof mysqli)) {
  http_response_code(500);
  die('No hay conexión mysqli disponible');
}

function h($v): string {
  return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function money_mx($v): string {
  return '$' . number_format((float)$v, 2);
}

$redirectUrl = 'https://pasaporteatusalud.com';

$ref = trim((string)($_GET['ref'] ?? ''));
$statusHint = strtoupper(trim((string)($_GET['status'] ?? '')));

$orden = null;

if ($ref !== '') {
  $stmt = $cx->prepare("
    SELECT
      id_orden,
      id_pasaporte,
      id_pasaporte_generado,
      folio_orden,
      referencia_pago,
      estatus_orden,
      estatus_pago,
      proveedor_pasarela,
      monto_orden,
      moneda,
      correo_usuario_pats,
      nombre_usuario,
      apellido_pa,
      apellido_ma,
      fecha_pago,
      fecha_confirmacion,
      pasaporte_creado,
      created_at
    FROM pats_ordenes_pago
    WHERE referencia_pago = ?
       OR folio_orden = ?
    LIMIT 1
  ");

  if ($stmt) {
    $stmt->bind_param('ss', $ref, $ref);
    $stmt->execute();
    $rs = $stmt->get_result();
    $orden = $rs ? $rs->fetch_assoc() : null;
    $stmt->close();
  }
}

function estado_visual(?array $orden, string $statusHint = ''): array {
  if (!$orden) {
    return [
      'clave' => 'NO_ENCONTRADA',
      'titulo' => 'No encontramos tu referencia',
      'texto' => 'No fue posible localizar la orden con la referencia proporcionada. Si el cargo aparece en tu banco, conserva tu comprobante y contacta a PATS.',
      'chip' => 'Referencia no encontrada',
      'kind' => 'danger',
      'icon' => '!'
    ];
  }

  $estatusPago = strtoupper(trim((string)($orden['estatus_pago'] ?? '')));
  $estatusOrden = strtoupper(trim((string)($orden['estatus_orden'] ?? '')));

  $pagosOk = ['CONFIRMADO', 'PAGADO', 'PAID', 'SUCCEEDED'];
  $ordenesOk = ['PAGADA', 'CONFIRMADA', 'PAGO_CONFIRMADO', 'PAGO_COMPLETADO'];

  if (
    in_array($estatusPago, $pagosOk, true) ||
    in_array($estatusOrden, $ordenesOk, true) ||
    $statusHint === 'CONFIRMADO'
  ) {
    $pasaporteCreado = (int)($orden['pasaporte_creado'] ?? 0) === 1
      || (int)($orden['id_pasaporte_generado'] ?? 0) > 0
      || (int)($orden['id_pasaporte'] ?? 0) > 0;

    return [
      'clave' => 'PAGADA',
      'titulo' => '¡Pago confirmado!',
      'texto' => $pasaporteCreado
        ? 'Tu pago fue confirmado y tu Pasaporte PATS quedó registrado correctamente. En unos segundos volverás al sitio principal.'
        : 'Tu pago fue confirmado. Estamos terminando de integrar tu pasaporte en el sistema. En unos segundos volverás al sitio principal.',
      'chip' => $pasaporteCreado ? 'Pasaporte registrado' : 'Pago confirmado',
      'kind' => 'success',
      'icon' => '✓'
    ];
  }

  $pendientesPago = ['PENDIENTE', 'REPORTADO', 'EN_PROCESO', 'PROCESSING'];
  $pendientesOrden = ['CHECKOUT_GENERADO', 'PENDIENTE', 'PROCESANDO'];

  if (
    in_array($estatusPago, $pendientesPago, true) ||
    in_array($estatusOrden, $pendientesOrden, true) ||
    in_array($statusHint, ['PENDIENTE_PROVEEDOR', 'PROCESSING'], true)
  ) {
    return [
      'clave' => 'PENDIENTE_CONFIRMACION',
      'titulo' => 'Tu pago está en validación',
      'texto' => 'Recibimos la operación y estamos esperando la confirmación final del proveedor de pagos. En unos segundos volverás al sitio principal.',
      'chip' => 'En validación',
      'kind' => 'info',
      'icon' => '…'
    ];
  }

  $fallidosPago = ['CANCELADO', 'FALLIDO', 'EXPIRADO', 'CANCELED', 'FAILED'];
  $fallidasOrden = ['CANCELADA', 'EXPIRADA', 'FALLIDA'];

  if (in_array($estatusPago, $fallidosPago, true) || in_array($estatusOrden, $fallidasOrden, true)) {
    return [
      'clave' => 'NO_COMPLETADO',
      'titulo' => 'El pago no se completó',
      'texto' => 'La operación no pudo confirmarse. Puedes intentar nuevamente o contactar a PATS si necesitas apoyo.',
      'chip' => 'No completado',
      'kind' => 'danger',
      'icon' => '!'
    ];
  }

  return [
    'clave' => 'PROCESANDO',
    'titulo' => 'Estamos procesando tu operación',
    'texto' => 'Tu orden ya fue generada. En breve tendrás el resultado final de la operación. En unos segundos volverás al sitio principal.',
    'chip' => 'Procesando',
    'kind' => 'info',
    'icon' => '…'
  ];
}

$estado = estado_visual($orden, $statusHint);

$nombreCompleto = $orden
  ? trim((string)($orden['nombre_usuario'] ?? '') . ' ' . (string)($orden['apellido_pa'] ?? '') . ' ' . (string)($orden['apellido_ma'] ?? ''))
  : '';

$idPasaporteVisual = '-';
if ($orden) {
  $idPasaporteVisual = (string)(
    ($orden['id_pasaporte_generado'] ?? '') ?:
    (($orden['id_pasaporte'] ?? '') ?: '-')
  );
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PATS · Resultado de pago</title>

  <link rel="icon" type="image/x-icon" href="../../img/ez.ico" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link
    href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700;800;900&display=swap"
    rel="stylesheet"
  />

  <style>
    :root {
      --p1: #073BFF;
      --p2: #006DFF;
      --cyan: #00D9C8;
      --lime: #B8F21D;
      --violet: #8A6CFF;
      --dark: #02040B;
      --dark2: #050916;
      --dark3: #07142F;
      --white: #ffffff;
      --soft: #F2F6FF;
      --soft2: #EAF0FF;
      --text: #0B1022;
      --text2: #3B435C;
      --muted: #6D7898;
      --border: #DCE5FB;
      --danger: #E05278;
      --success: #00D9A3;
      --shadow: 0 24px 80px rgba(0, 37, 160, .18);
      --ease: cubic-bezier(.22,1,.36,1);
      --ff: "DM Sans", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }
    html { min-height: 100%; overflow-x: hidden; }
    body {
      min-height: 100svh;
      font-family: var(--ff);
      color: var(--white);
      background:
        radial-gradient(circle at 80% 18%, rgba(0, 217, 200, .20), transparent 30%),
        radial-gradient(circle at 8% 88%, rgba(128, 98, 255, .16), transparent 32%),
        linear-gradient(135deg, #071127 0%, #0b1d53 46%, #13358e 100%);
      overflow-x: hidden;
      -webkit-font-smoothing: antialiased;
    }

    body::before {
      content: "";
      position: fixed;
      inset: 0;
      background-image:
        linear-gradient(rgba(255,255,255,.045) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,.045) 1px, transparent 1px);
      background-size: 64px 64px;
      mask-image: radial-gradient(ellipse at 75% 35%, #000 0%, transparent 72%);
      pointer-events: none;
      z-index: 0;
    }

    body::after {
      content: "";
      position: fixed;
      inset: 0;
      background:
        radial-gradient(circle at 18% 22%, rgba(0, 115, 255, .10), transparent 24%),
        radial-gradient(circle at 84% 18%, rgba(0, 217, 200, .12), transparent 24%);
      pointer-events: none;
      z-index: 0;
    }

    a { color: inherit; text-decoration: none; }
    button { font-family: inherit; border: 0; cursor: pointer; }

    .wrap {
      width: min(1180px, calc(100% - 36px));
      margin: 0 auto;
    }

    .nav {
      position: fixed;
      top: 0; left: 0; right: 0;
      z-index: 50;
      height: 74px;
      display: flex;
      align-items: center;
      background:
        radial-gradient(circle at 90% 0%, rgba(0, 217, 200, .16), transparent 30%),
        linear-gradient(135deg, rgba(7, 17, 39, .96), rgba(11, 29, 83, .95) 54%, rgba(15, 48, 128, .94));
      border-bottom: 1px solid rgba(255,255,255,.08);
      backdrop-filter: blur(18px);
    }

    .nav-inner {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 22px;
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 12px;
      min-width: 168px;
    }

    .brand img {
      height: 54px;
      width: auto;
      object-fit: contain;
      display: block;
    }

    .nav-cta {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 11px 18px;
      border-radius: 999px;
      background: linear-gradient(135deg, #083dff 0%, #006fff 48%, #12d8ca 100%);
      color: #fff;
      font-size: .82rem;
      font-weight: 900;
      box-shadow: 0 16px 42px rgba(0, 109, 255, .28);
      white-space: nowrap;
    }

    .result-page {
      position: relative;
      z-index: 1;
      min-height: 100svh;
      padding: 112px 0 42px;
      display: grid;
      align-items: center;
    }

    .result-grid {
      display: grid;
      grid-template-columns: minmax(0, .92fr) minmax(420px, 1.08fr);
      gap: clamp(26px, 4.5vw, 68px);
      align-items: center;
    }

    .hero-copy {
      min-width: 0;
    }

    .hero-kicker {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      padding: 8px 14px;
      margin-bottom: 24px;
      border-radius: 999px;
      background: rgba(255,255,255,.09);
      border: 1px solid rgba(255,255,255,.14);
      color: rgba(255,255,255,.82);
      font-size: .74rem;
      font-weight: 900;
      letter-spacing: .12em;
      text-transform: uppercase;
      backdrop-filter: blur(12px);
    }

    .hero-kicker i {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: var(--cyan);
      box-shadow: 0 0 16px rgba(0,217,200,.9);
    }

    .hero-title {
      font-size: clamp(3.2rem, 7vw, 8rem);
      line-height: .84;
      letter-spacing: -.075em;
      font-weight: 900;
      margin-bottom: 24px;
    }

    .hero-title span,
    .gradient-text {
      background: linear-gradient(90deg, #ffffff 0%, #cfe0ff 20%, #79b5ff 46%, #1fd6c8 76%, #b8f21d 100%);
      -webkit-background-clip: text;
      background-clip: text;
      -webkit-text-fill-color: transparent;
    }

    .hero-text {
      max-width: 570px;
      color: rgba(255,255,255,.68);
      font-size: 1.04rem;
      line-height: 1.75;
      font-weight: 500;
      margin-bottom: 24px;
    }

    .hero-pills {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }

    .pill {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      padding: 8px 12px;
      border-radius: 999px;
      background: rgba(255,255,255,.08);
      border: 1px solid rgba(255,255,255,.13);
      color: rgba(255,255,255,.76);
      font-size: .74rem;
      font-weight: 800;
    }

    .pill::before {
      content: "✓";
      display: inline-grid;
      place-items: center;
      width: 16px;
      height: 16px;
      border-radius: 50%;
      background: rgba(0,217,200,.16);
      color: var(--cyan);
      font-size: .68rem;
      font-weight: 900;
    }

    .result-card {
      position: relative;
      overflow: hidden;
      border-radius: 34px;
      background:
        linear-gradient(180deg, rgba(255,255,255,.96), rgba(242,246,255,.95));
      color: var(--text);
      border: 1px solid rgba(255,255,255,.30);
      box-shadow: 0 40px 100px rgba(0,0,0,.34);
    }

    .result-card::before {
      content: "";
      position: absolute;
      inset: 0;
      background:
        linear-gradient(90deg, rgba(0,115,255,.10) 1px, transparent 1px),
        linear-gradient(rgba(0,115,255,.08) 1px, transparent 1px);
      background-size: 18px 18px;
      mask-image: radial-gradient(ellipse at 50% 18%, #000 0%, transparent 58%);
      opacity: .48;
      pointer-events: none;
    }

    .result-head,
    .result-body {
      position: relative;
      z-index: 1;
    }

    .result-head {
      padding: clamp(24px, 4vw, 38px);
      background:
        radial-gradient(circle at 92% 10%, rgba(0, 217, 200, .13), transparent 30%),
        linear-gradient(135deg, rgba(7,59,255,.08), rgba(255,255,255,.40));
      border-bottom: 1px solid rgba(0, 37, 160, .10);
    }

    .status-row {
      display: flex;
      gap: 18px;
      align-items: flex-start;
    }

    .status-icon {
      width: 74px;
      height: 74px;
      flex: 0 0 74px;
      border-radius: 24px;
      display: grid;
      place-items: center;
      color: #fff;
      font-size: 38px;
      font-weight: 900;
      box-shadow: 0 24px 60px rgba(0,37,160,.18);
    }

    .pr-success .status-icon {
      background: linear-gradient(135deg, #00bd8f, #00d9c8);
    }

    .pr-info .status-icon {
      background: linear-gradient(135deg, var(--p1), var(--p2) 56%, var(--cyan));
    }

    .pr-danger .status-icon {
      background: linear-gradient(135deg, var(--danger), #ff7c9c);
    }

    .chip {
      display: inline-flex;
      align-items: center;
      min-height: 30px;
      padding: 0 12px;
      border-radius: 999px;
      font-size: .68rem;
      font-weight: 900;
      letter-spacing: .14em;
      text-transform: uppercase;
      margin-bottom: 12px;
    }

    .pr-success .chip {
      background: rgba(0, 217, 163, .12);
      color: #008965;
      border: 1px solid rgba(0, 217, 163, .22);
    }

    .pr-info .chip {
      background: rgba(0, 109, 255, .11);
      color: #083dff;
      border: 1px solid rgba(0, 109, 255, .18);
    }

    .pr-danger .chip {
      background: rgba(224,82,120,.11);
      color: #b24367;
      border: 1px solid rgba(224,82,120,.18);
    }

    .result-title {
      color: var(--text);
      font-size: clamp(2.05rem, 4vw, 3.3rem);
      line-height: .93;
      letter-spacing: -.065em;
      font-weight: 900;
      margin-bottom: 12px;
    }

    .result-copy {
      color: var(--text2);
      line-height: 1.7;
      font-size: .98rem;
      font-weight: 650;
      max-width: 610px;
    }

    .result-body {
      padding: clamp(20px, 3.2vw, 34px);
    }

    .details-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
    }

    .detail {
      min-height: 94px;
      border-radius: 20px;
      padding: 16px 17px;
      background: #fff;
      border: 1px solid var(--border);
      box-shadow: 0 10px 26px rgba(0, 37, 160, .06);
    }

    .detail span {
      display: block;
      color: var(--muted);
      font-size: .68rem;
      font-weight: 900;
      letter-spacing: .11em;
      text-transform: uppercase;
      margin-bottom: 8px;
    }

    .detail strong {
      display: block;
      color: var(--text);
      font-size: .96rem;
      line-height: 1.32;
      font-weight: 900;
      overflow-wrap: anywhere;
    }

    .countdown {
      margin-top: 14px;
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 16px;
      align-items: center;
      padding: 18px;
      border-radius: 22px;
      color: #fff;
      background:
        radial-gradient(circle at 90% 10%, rgba(0,217,200,.22), transparent 30%),
        linear-gradient(135deg, #07142F, #083dff 58%, #00a6d8);
      box-shadow: 0 18px 44px rgba(0,37,160,.16);
    }

    .countdown strong {
      display: block;
      font-size: .96rem;
      font-weight: 900;
      margin-bottom: 4px;
    }

    .countdown span {
      display: block;
      color: rgba(255,255,255,.74);
      font-size: .78rem;
      line-height: 1.5;
      font-weight: 700;
    }

    .count-badge {
      width: 58px;
      height: 58px;
      border-radius: 18px;
      display: grid;
      place-items: center;
      color: #fff;
      font-size: 1.65rem;
      font-weight: 900;
      background: rgba(255,255,255,.12);
      border: 1px solid rgba(255,255,255,.16);
      box-shadow: inset 0 1px 0 rgba(255,255,255,.14);
    }

    .actions {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-top: 16px;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      min-height: 48px;
      padding: 14px 22px;
      border-radius: 999px;
      font-weight: 900;
      font-size: .88rem;
      transition: .24s var(--ease);
    }

    .btn:hover {
      transform: translateY(-3px);
    }

    .btn-primary {
      background: linear-gradient(135deg, #083dff 0%, #006fff 48%, #12d8ca 100%);
      color: #fff;
      box-shadow: 0 16px 42px rgba(0, 109, 255, .28);
    }

    .btn-secondary {
      background: #fff;
      border: 1px solid var(--border);
      color: var(--text2);
    }

    .mini-note {
      margin-top: 14px;
      color: var(--muted);
      font-size: .78rem;
      line-height: 1.6;
      font-weight: 700;
    }

    @media (max-width: 1120px) {
      .result-page {
        align-items: start;
      }

      .result-grid {
        grid-template-columns: 1fr;
        padding: 36px 0 54px;
      }

      .hero-copy {
        max-width: 790px;
      }

      .result-card {
        max-width: 820px;
      }
    }

    @media (max-width: 760px) {
      .wrap {
        width: min(100% - 26px, 1180px);
      }

      .nav {
        height: 68px;
      }

      .brand img {
        height: 46px;
      }

      .nav-cta {
        padding: 10px 14px;
        font-size: .74rem;
      }

      .result-page {
        padding-top: 92px;
        padding-bottom: 28px;
      }

      .result-grid {
        padding: 20px 0 34px;
        gap: 22px;
      }

      .hero-title {
        font-size: clamp(3rem, 14vw, 4.8rem);
      }

      .hero-text {
        font-size: .96rem;
      }

      .hero-pills {
        gap: 6px;
      }

      .pill {
        font-size: .68rem;
        padding: 7px 10px;
      }

      .result-card {
        border-radius: 26px;
      }

      .result-head {
        padding: 22px 18px;
      }

      .status-row {
        gap: 12px;
      }

      .status-icon {
        width: 58px;
        height: 58px;
        flex-basis: 58px;
        border-radius: 19px;
        font-size: 30px;
      }

      .chip {
        min-height: 27px;
        padding: 0 10px;
        font-size: .58rem;
        letter-spacing: .10em;
        margin-bottom: 9px;
      }

      .result-title {
        font-size: clamp(1.8rem, 8vw, 2.6rem);
      }

      .result-copy {
        font-size: .88rem;
      }

      .result-body {
        padding: 16px;
      }

      .details-grid {
        grid-template-columns: 1fr;
        gap: 10px;
      }

      .detail {
        min-height: auto;
        padding: 14px;
        border-radius: 17px;
      }

      .detail span {
        font-size: .62rem;
      }

      .detail strong {
        font-size: .90rem;
      }

      .countdown {
        border-radius: 18px;
        padding: 15px;
        gap: 12px;
      }

      .count-badge {
        width: 50px;
        height: 50px;
        border-radius: 16px;
        font-size: 1.4rem;
      }

      .actions {
        display: grid;
        grid-template-columns: 1fr;
      }

      .btn {
        width: 100%;
        min-height: 47px;
      }
    }

    @media (max-width: 430px) {
      .wrap {
        width: min(100% - 18px, 1180px);
      }

      .nav-cta {
        display: none;
      }

      .hero-kicker {
        font-size: .62rem;
        letter-spacing: .10em;
        margin-bottom: 18px;
      }

      .hero-title {
        font-size: clamp(2.65rem, 16vw, 3.7rem);
      }

      .status-row {
        display: block;
      }

      .status-icon {
        margin-bottom: 12px;
      }

      .countdown {
        grid-template-columns: 1fr;
      }

      .count-badge {
        justify-self: start;
      }
    }

    @media (prefers-reduced-motion: reduce) {
      .btn { transition: none; }
      .btn:hover { transform: none; }
    }
  </style>
</head>

<body class="pr-<?= h($estado['kind']) ?>">
  <header class="nav">
    <div class="wrap nav-inner">
      <a class="brand" href="<?= h($redirectUrl) ?>" aria-label="Pasaporte a tu Salud">
        <img src="https://50d.com.mx/50D/EZHS/img2/logos/PATS_W.png" alt="Pasaporte a tu Salud" />
      </a>

      <a class="nav-cta" href="<?= h($redirectUrl) ?>">Volver al sitio</a>
    </div>
  </header>

  <main class="result-page">
    <div class="wrap result-grid">
      <section class="hero-copy">
        <div class="hero-kicker"><i></i> PATS · Operación segura</div>

        <h1 class="hero-title">
          Resultado<br />
          de tu<br />
          <span>pago</span>
        </h1>

        <p class="hero-text">
          Validamos tu operación con el proveedor de pagos y preparamos tu regreso automático
          al sitio principal de Pasaporte a tu Salud.
        </p>

        <div class="hero-pills">
          <span class="pill">Pago seguro</span>
          <span class="pill">Registro protegido</span>
          <span class="pill">Confirmación automática</span>
        </div>
      </section>

      <section class="result-card">
        <div class="result-head">
          <div class="status-row">
            <div class="status-icon"><?= h($estado['icon']) ?></div>
            <div>
              <div class="chip"><?= h($estado['chip']) ?></div>
              <h2 class="result-title"><?= h($estado['titulo']) ?></h2>
              <p class="result-copy"><?= h($estado['texto']) ?></p>
            </div>
          </div>
        </div>

        <div class="result-body">
          <div class="details-grid">
            <div class="detail">
              <span>Referencia</span>
              <strong><?= h($ref !== '' ? $ref : '-') ?></strong>
            </div>

            <div class="detail">
              <span>Folio</span>
              <strong><?= h($orden['folio_orden'] ?? '-') ?></strong>
            </div>

            <div class="detail">
              <span>Pasaporte</span>
              <strong><?= h($idPasaporteVisual) ?></strong>
            </div>

            <div class="detail">
              <span>Afiliado</span>
              <strong><?= h($nombreCompleto !== '' ? $nombreCompleto : '-') ?></strong>
            </div>

            <div class="detail">
              <span>Correo</span>
              <strong><?= h($orden['correo_usuario_pats'] ?? '-') ?></strong>
            </div>

            <div class="detail">
              <span>Monto</span>
              <strong><?= $orden ? h(money_mx($orden['monto_orden'] ?? 0) . ' ' . ($orden['moneda'] ?? 'MXN')) : '-' ?></strong>
            </div>
          </div>

          <div class="countdown">
            <div>
              <strong>Regresaremos al sitio principal automáticamente</strong>
              <span>Esta pantalla redirige en unos segundos. También puedes volver manualmente.</span>
            </div>
            <div class="count-badge" id="redirectCount">5</div>
          </div>

          <div class="actions">
            <a class="btn btn-primary" href="<?= h($redirectUrl) ?>">Volver a pasaporteatusalud.com</a>
            <button class="btn btn-secondary" type="button" onclick="history.back()">Regresar</button>
          </div>

          <p class="mini-note">
            Si tu pago fue confirmado y aún no ves tu pasaporte, espera unos minutos y conserva tu referencia. <br> <br>
            Si no recibiste un correo en bandeja prinicipal, revisa en tu bandeja de spam.
          </p>
        </div>
      </section>
    </div>
  </main>

  <script>
    (() => {
      const redirectUrl = <?= json_encode($redirectUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      const countEl = document.getElementById('redirectCount');
      let seconds = 5;

      const tick = () => {
        seconds -= 1;

        if (countEl) {
          countEl.textContent = String(Math.max(seconds, 0));
        }

        if (seconds <= 0) {
          window.location.href = redirectUrl;
          return;
        }

        setTimeout(tick, 1000);
      };

      setTimeout(tick, 1000);
    })();
  </script>
</body>
</html>
