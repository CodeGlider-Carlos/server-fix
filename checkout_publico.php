<?php
/*
ez/pats/checkout_publico.php
*/
session_start();
require_once '../../varSQL/bd_pats.php';
require_once '../../varSQL/var_pats.php';

$ver = time();
$token = trim($_GET['t'] ?? '');

$cx = $conexionMod ?? $db_all2 ?? $db_all ?? null;
if (!$cx || !($cx instanceof mysqli)) {
  die('No hay conexión mysqli disponible');
}

$stmt = $cx->prepare("
  SELECT *
  FROM pats_ordenes_pago
  WHERE public_token = ?
  LIMIT 1
");
if (!$stmt) {
  die('No fue posible preparar consulta');
}
$stmt->bind_param('s', $token);
$stmt->execute();
$rs = $stmt->get_result();
$orden = $rs ? $rs->fetch_assoc() : null;
$stmt->close();

if (!$orden) {
  die('Link no válido');
}

if (!empty($orden['public_token_expires_at']) && strtotime((string)$orden['public_token_expires_at']) < time()) {
  die('Este link de pago ya expiró');
}

if (strtoupper((string)$orden['estatus_pago']) === 'CONFIRMADO') {
  die('Esta orden ya fue pagada');
}

function h($v): string {
  return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');

$webhookUrl = $scheme . '://' . $host . $basePath . '/endpoints/pasarela_webhook.php?proveedor=PROVEEDOR&ambiente=PROD';
$returnOkUrl = $scheme . '://' . $host . $basePath . '/checkout_resultado.php?status=ok&folio=' . urlencode((string)$orden['folio_orden']);
$returnFailUrl = $scheme . '://' . $host . $basePath . '/checkout_resultado.php?status=fail&folio=' . urlencode((string)$orden['folio_orden']);

$payloadProveedor = [
  'order_id' => (string)$orden['folio_orden'],
  'reference' => (string)$orden['referencia_pago'],
  'amount' => (float)$orden['monto_orden'],
  'currency' => (string)($orden['moneda'] ?: 'MXN'),
  'customer' => [
    'name' => (string)$orden['nombre_usuario'],
    'email' => (string)$orden['correo_usuario_pats'],
    'phone' => (string)$orden['telefono_usuario'],
    'curp' => (string)$orden['curp_usuario'],
  ],
  'metadata' => [
    'folio_orden' => (string)$orden['folio_orden'],
    'referencia_pago' => (string)$orden['referencia_pago'],
    'tipo_origen' => (string)$orden['tipo_origen'],
    'id_distribuidor' => (int)$orden['id_distribuidor'],
    'id_franquicia' => (int)$orden['id_franquicia'],
    'tipo_operacion' => (string)$orden['tipo_operacion'],
    'frecuencia' => (string)$orden['frecuencia']
  ],
  'webhook_url' => $webhookUrl,
  'return_url_ok' => $returnOkUrl,
  'return_url_fail' => $returnFailUrl
];
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PATS · Checkout público</title>
  <link rel="icon" type="image/x-icon" href="../../img/ez.ico" />
  <style>
    :root{
      --ez-bg:#f5f7fb;
      --ez-card:#ffffff;
      --ez-line:rgba(180,190,215,.18);
      --ez-title:#163463;
      --ez-text:#44516d;
      --ez-grad:linear-gradient(135deg, rgba(10,34,54,.98), rgba(18,92,116,.98));
    }

    *{box-sizing:border-box}
    body{
      margin:0;
      background:var(--ez-bg);
      font-family:Arial,Helvetica,sans-serif;
      color:var(--ez-text);
    }

    .pay-shell{
      max-width:960px;
      margin:0 auto;
      padding:24px 16px 40px;
    }

    .pay-hero{
      margin-bottom:18px;
      border-radius:24px;
      overflow:hidden;
      background:var(--ez-grad);
      color:#fff;
      box-shadow:0 20px 48px rgba(12,22,52,.14);
    }

    .pay-hero__body{
      padding:22px 22px 20px;
    }

    .pay-kicker{
      font-size:12px;
      font-weight:800;
      letter-spacing:.08em;
      opacity:.86;
      text-transform:uppercase;
      margin-bottom:8px;
    }

    .pay-title{
      margin:0 0 8px;
      font-size:42px;
      line-height:1.05;
      font-weight:900;
    }

    .pay-subtitle{
      margin:0;
      font-size:15px;
      line-height:1.45;
      color:rgba(255,255,255,.9);
    }

    .pay-grid{
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap:18px;
    }

    .pay-card{
      background:var(--ez-card);
      border-radius:22px;
      border:1px solid var(--ez-line);
      box-shadow:0 16px 40px rgba(12,22,52,.08);
      overflow:hidden;
    }

    .pay-card__head{
      padding:16px 18px;
      border-bottom:1px solid var(--ez-line);
      background:#fff;
    }

    .pay-card__head h3{
      margin:0;
      color:var(--ez-title);
      font-size:18px;
      font-weight:900;
    }

    .pay-card__body{
      padding:18px;
    }

    .pay-kv{
      display:grid;
      gap:10px;
    }

    .pay-kv__row{
      display:flex;
      justify-content:space-between;
      gap:12px;
      align-items:flex-start;
      padding:12px 14px;
      border-radius:14px;
      background:#f7f9fd;
    }

    .pay-kv__row span{
      font-size:13px;
      color:#5b6883;
      font-weight:700;
    }

    .pay-kv__row strong{
      text-align:right;
      color:var(--ez-title);
      font-size:14px;
      font-weight:900;
      word-break:break-word;
    }

    .pay-highlight{
      margin-top:14px;
      padding:14px 16px;
      border-radius:16px;
      background:linear-gradient(135deg, rgba(10,34,54,.06), rgba(18,92,116,.08));
      border:1px solid rgba(18,92,116,.08);
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:12px;
    }

    .pay-highlight span{
      font-size:13px;
      font-weight:700;
      color:#47607f;
    }

    .pay-highlight strong{
      font-size:28px;
      line-height:1;
      color:var(--ez-title);
      font-weight:900;
    }

    .pay-box{
      padding:16px;
      border-radius:16px;
      background:#f7f9fd;
      border:1px dashed rgba(18,92,116,.18);
    }

    .pay-box__title{
      margin:0 0 8px;
      font-size:13px;
      font-weight:900;
      color:var(--ez-title);
      text-transform:uppercase;
      letter-spacing:.04em;
    }

    .pay-box__text{
      margin:0;
      font-size:13px;
      line-height:1.5;
      color:#52617d;
    }

    .pay-actions{
      margin-top:18px;
      display:flex;
      justify-content:flex-end;
      gap:10px;
      flex-wrap:wrap;
    }

    .pay-btn{
      border:0;
      border-radius:14px;
      padding:12px 16px;
      font-weight:900;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      cursor:pointer;
      min-height:44px;
      font-size:14px;
    }

    .pay-btn--ghost{
      background:#eef2fb;
      color:#18336a;
    }

    .pay-btn--primary{
      background:var(--ez-grad);
      color:#fff;
      box-shadow:0 12px 28px rgba(6,12,28,.16);
    }

    .pay-provider-mount{
      margin-top:14px;
      min-height:140px;
      border-radius:18px;
      background:#fff;
      border:1px dashed rgba(18,92,116,.22);
      display:grid;
      place-items:center;
      text-align:center;
      padding:22px;
    }

    .pay-provider-mount strong{
      display:block;
      color:var(--ez-title);
      font-size:18px;
      margin-bottom:8px;
    }

    .pay-provider-mount span{
      font-size:13px;
      line-height:1.45;
      color:#5b6883;
      max-width:460px;
    }

    .pay-dev{
      margin-top:18px;
      background:#0f1731;
      color:#dce6ff;
      border-radius:18px;
      padding:14px;
      font-size:12px;
      line-height:1.5;
      overflow:auto;
    }

    @media (max-width: 900px){
      .pay-grid{
        grid-template-columns:1fr;
      }

      .pay-title{
        font-size:34px;
      }
    }

    @media (max-width: 640px){
      .pay-title{
        font-size:28px;
      }

      .pay-shell{
        padding:14px 12px 28px;
      }

      .pay-card__body,
      .pay-card__head{
        padding:14px;
      }

      .pay-kv__row{
        flex-direction:column;
      }

      .pay-kv__row strong{
        text-align:left;
      }

      .pay-highlight{
        flex-direction:column;
        align-items:flex-start;
      }

      .pay-highlight strong{
        font-size:24px;
      }
    }
  </style>
</head>
<body>
  <div class="pay-shell">
    <section class="pay-hero">
      <div class="pay-hero__body">
        <div class="pay-kicker">PATS · CHECKOUT PÚBLICO</div>
        <h1 class="pay-title">Pago de PATS</h1>
        <p class="pay-subtitle">
          Esta página ya contiene todos los datos necesarios para que el proveedor de pasarela monte aquí su botón, widget o redirect de pago.
        </p>
      </div>
    </section>

    <div class="pay-grid">
      <article class="pay-card">
        <div class="pay-card__head">
          <h3>Resumen de la orden</h3>
        </div>
        <div class="pay-card__body">
          <div class="pay-kv">
            <div class="pay-kv__row">
              <span>Folio</span>
              <strong><?= h($orden['folio_orden']) ?></strong>
            </div>
            <div class="pay-kv__row">
              <span>Referencia</span>
              <strong><?= h($orden['referencia_pago']) ?></strong>
            </div>
            <div class="pay-kv__row">
              <span>Nombre</span>
              <strong><?= h($orden['nombre_usuario']) ?></strong>
            </div>
            <div class="pay-kv__row">
              <span>Correo</span>
              <strong><?= h($orden['correo_usuario_pats']) ?></strong>
            </div>
            <div class="pay-kv__row">
              <span>Teléfono</span>
              <strong><?= h($orden['telefono_usuario']) ?></strong>
            </div>
            <div class="pay-kv__row">
              <span>Frecuencia</span>
              <strong><?= h($orden['frecuencia']) ?></strong>
            </div>
          </div>

          <div class="pay-highlight">
            <span>Monto total a pagar</span>
            <strong>$<?= number_format((float)$orden['monto_orden'], 2) ?></strong>
          </div>
        </div>
      </article>

      <article class="pay-card">
        <div class="pay-card__head">
          <h3>Integración de pasarela</h3>
        </div>
        <div class="pay-card__body">
          <div class="pay-box">
            <p class="pay-box__title">Qué debe hacer el proveedor</p>
            <p class="pay-box__text">
              Leer el objeto <strong>window.EZ_PASARELA_CHECKOUT</strong>, montar aquí su checkout y enviar el resultado del pago al webhook definido por EZ.
            </p>
          </div>

          <div id="ezPasarelaMount" class="pay-provider-mount">
            <div>
              <strong>Contenedor de pasarela</strong>
              <span>
                Aquí el proveedor puede renderizar su botón, formulario embebido, widget o iniciar su redirect usando los datos ya preparados por EZ.
              </span>
            </div>
          </div>

          <div class="pay-actions">
            <a href="<?= h($returnFailUrl) ?>" class="pay-btn pay-btn--ghost">Cancelar</a>
            <button type="button" id="btnFakePay" class="pay-btn pay-btn--primary">Simular integración local</button>
          </div>
        </div>
      </article>
    </div>

    <pre class="pay-dev" id="ezPasarelaDebug"></pre>
  </div>

  <script>
    window.EZ_PASARELA_CHECKOUT = <?= json_encode($payloadProveedor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>;
  </script>

  <script>
    (function () {
      const payload = window.EZ_PASARELA_CHECKOUT || {};
      const debug = document.getElementById("ezPasarelaDebug");
      const fakeBtn = document.getElementById("btnFakePay");

      if (debug) {
        debug.textContent =
        `window.EZ_PASARELA_CHECKOUT =
        ${JSON.stringify(payload, null, 2)}`;
      }

      if (fakeBtn) {
        fakeBtn.addEventListener("click", async () => {
          const fakePayload = {
            event_id: "evt_local_" + Date.now(),
            event_type: "payment_succeeded",
            status: "confirmed",
            transaction_id: "trx_local_" + Date.now(),
            reference: payload.reference,
            order_id: payload.order_id,
            payment_intent_id: "pi_local_" + Date.now(),
            charge_id: "ch_local_" + Date.now(),
            amount: payload.amount,
            currency: payload.currency,
            customer_id: payload.customer?.email || "",
            metadata: payload.metadata || {}
          };

          try {
            const res = await fetch(payload.webhook_url.replace("PROVEEDOR", "LOCAL_TEST"), {
              method: "POST",
              headers: {
                "Content-Type": "application/json"
              },
              body: JSON.stringify(fakePayload)
            });

            const text = await res.text();
            alert("Webhook local enviado.\n\nRespuesta:\n" + text);
          } catch (e) {
            console.error(e);
            alert("No fue posible enviar webhook local.");
          }
        });
      }
    })();
  </script>
</body>
</html>