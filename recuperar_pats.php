<?php
/*
  recuperar_pats.php — Panel de administrador
  Muestra solicitudes fallidas, genera links de recuperación
  y los envía por correo al cliente (auto si hay email en respaldo,
  manual si no).
*/
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$stripeConfig = __DIR__ . '/config/config.php';
if (is_file($stripeConfig)) require_once $stripeConfig;

$mailConfig = __DIR__ . '/config/mail.php';
if (is_file($mailConfig)) require_once $mailConfig;

require_once __DIR__ . '/../../varSQL/bd_pats.php';
require_once __DIR__ . '/../../varSQL/var_pats.php';
$__mailer = __DIR__ . '/lib/pats_mailer.php';
if (is_file($__mailer)) require_once $__mailer;

$cx = $conexionMod ?? $db_all2 ?? $db_all ?? null;

if (!defined('PATS_RECOVERY_KEY') || PATS_RECOVERY_KEY === '' || PATS_RECOVERY_KEY === 'CAMBIA_ESTA_CLAVE_SEGURA') {
    die('<b>Error:</b> Define <code>PATS_RECOVERY_KEY</code> en <code>config/config.php</code>.');
}

mysqli_report(MYSQLI_REPORT_OFF);

/* ── Email de link de recuperación ── */
function rp_enviar_link(string $to, string $nombre, string $link, string $expira): bool {
    if (!function_exists('pats_smtp_send_mail')) return false;
    $expiraBonita = date('d/m/Y H:i', strtotime($expira));
    $nombre       = htmlspecialchars($nombre, ENT_QUOTES);
    $linkEsc      = htmlspecialchars($link,   ENT_QUOTES);
    $html = <<<HTML
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"></head><body
  style="margin:0;padding:0;background:#f1f5f9;font-family:system-ui,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0">
<tr><td align="center" style="padding:40px 16px">
<table width="540" cellpadding="0" cellspacing="0"
  style="background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08)">
  <tr><td style="background:#2563eb;padding:28px 32px">
    <div style="font-size:1.1rem;font-weight:700;color:#fff">PATS · Pasaporte a tu Salud</div>
  </td></tr>
  <tr><td style="padding:32px">
    <p style="font-size:1rem;font-weight:700;color:#1e293b;margin:0 0 12px">
      Hola, {$nombre}</p>
    <p style="font-size:.9rem;color:#475569;margin:0 0 24px;line-height:1.6">
      Tu asesor ha generado un enlace personalizado para que puedas completar tu alta al
      Pasaporte a tu Salud (PATS). Haz clic en el botón para continuar.</p>
    <div style="text-align:center;margin:28px 0">
      <a href="{$linkEsc}"
        style="display:inline-block;padding:14px 32px;background:#2563eb;color:#fff;
               border-radius:8px;font-weight:700;font-size:.95rem;text-decoration:none">
        Completar mi alta →
      </a>
    </div>
    <p style="font-size:.8rem;color:#94a3b8;margin:0 0 8px">
      O copia este enlace en tu navegador:</p>
    <p style="font-size:.78rem;color:#2563eb;word-break:break-all;margin:0 0 24px">{$linkEsc}</p>
    <hr style="border:none;border-top:1px solid #e2e8f0;margin:20px 0">
    <p style="font-size:.78rem;color:#94a3b8;margin:0">
      ⏱ Este enlace es de uso único y expira el {$expiraBonita}.
      Si no lo solicitaste, ignora este mensaje.</p>
  </td></tr>
</table>
</td></tr></table>
</body></html>
HTML;
    $text = "Hola {$nombre},\n\nCompleta tu alta PATS en:\n{$link}\n\n(Válido hasta: {$expiraBonita})\n";
    try {
        return pats_smtp_send_mail($to, 'Tu enlace para completar tu alta PATS', $html, $text);
    } catch (Throwable $e) {
        error_log('rp_enviar_link error: ' . $e->getMessage());
        return false;
    }
}

/* ── Estado ── */
$fase     = 1; // 1=login, 2=panel
$error    = '';
$linkInfo = null; // ['url','respaldo','expira','emailSent','emailTo','emailErr']

/* ── Login ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_key'])) {
    if (hash_equals((string)PATS_RECOVERY_KEY, trim((string)$_POST['admin_key']))) {
        $_SESSION['pats_admin_panel'] = true;
        $fase = 2;
    } else {
        $error = 'Clave de administrador incorrecta.';
    }
}
if (!empty($_SESSION['pats_admin_panel'])) $fase = 2;

/* ── Logout ── */
if (isset($_POST['logout'])) {
    unset($_SESSION['pats_admin_panel']);
    header('Location: recuperar_pats.php'); exit;
}

/* ── Generar link ── */
if ($fase === 2 && ($_POST['action'] ?? '') === 'generar_link' && $cx instanceof mysqli) {
    $idR = (int)($_POST['id_respaldo'] ?? 0);
    $tokenClaro = strtoupper(bin2hex(random_bytes(16)));
    $tokenHash  = hash('sha256', $tokenClaro);
    $expira     = date('Y-m-d H:i:s', strtotime('+48 hours'));
    $ip         = (string)($_SERVER['REMOTE_ADDR'] ?? '');

    $stmtIns = $cx->prepare("INSERT INTO pats_tokens_recuperacion (token_hash, expira_at, ip_creacion) VALUES (?,?,?)");
    if ($stmtIns) {
        $stmtIns->bind_param('sss', $tokenHash, $expira, $ip);
        $stmtIns->execute();
        $stmtIns->close();

        $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host    = (string)($_SERVER['HTTP_HOST'] ?? '');
        $dir     = rtrim(dirname((string)($_SERVER['PHP_SELF'] ?? '')), '/');
        $linkUrl = $scheme . '://' . $host . $dir . '/recuperar_cliente.php?t=' . urlencode($tokenClaro) . '&r=' . $idR;

        /* Cargar respaldo para nombre y correo */
        $respaldoLink = null;
        if ($idR > 0) {
            $stR = $cx->prepare("SELECT id_respaldo, nombres, apellido_pa, correo, monto_orden, moneda, frecuencia_pago FROM pats_respaldo WHERE id_respaldo=? LIMIT 1");
            if ($stR) {
                $stR->bind_param('i', $idR);
                $stR->execute();
                $rsR = $stR->get_result();
                $respaldoLink = $rsR ? $rsR->fetch_assoc() : null;
                $stR->close();
            }
        }

        /* Enviar correo automático si el respaldo tiene email */
        $emailSent = false;
        $emailTo   = '';
        $emailErr  = '';
        if ($respaldoLink && !empty($respaldoLink['correo'])) {
            $emailTo   = trim((string)$respaldoLink['correo']);
            $nombre    = trim($respaldoLink['nombres'] . ' ' . $respaldoLink['apellido_pa']);
            $emailSent = rp_enviar_link($emailTo, $nombre, $linkUrl, $expira);
            if (!$emailSent) $emailErr = 'No se pudo enviar el correo automático. Envíalo manualmente.';
        } else {
            $emailErr = 'No hay correo registrado en el respaldo.';
        }

        $linkInfo = [
            'url'       => $linkUrl,
            'respaldo'  => $respaldoLink,
            'expira'    => $expira,
            'emailSent' => $emailSent,
            'emailTo'   => $emailTo,
            'emailErr'  => $emailErr,
        ];
    } else {
        $error = 'Error al crear token: ' . $cx->error;
    }
}

/* ── Enviar correo manual ── */
if ($fase === 2 && ($_POST['action'] ?? '') === 'enviar_correo_manual') {
    $manualLink   = trim((string)($_POST['link_url']    ?? ''));
    $manualEmail  = trim((string)($_POST['email_dest']  ?? ''));
    $manualNombre = trim((string)($_POST['nombre_dest'] ?? '')) ?: 'Cliente';
    $manualExpira = trim((string)($_POST['expira']      ?? ''));

    $emailSent = false;
    $emailErr  = '';
    if ($manualEmail === '' || !filter_var($manualEmail, FILTER_VALIDATE_EMAIL)) {
        $emailErr = 'Escribe un correo válido.';
    } else {
        $emailSent = rp_enviar_link($manualEmail, $manualNombre, $manualLink, $manualExpira);
        if (!$emailSent) $emailErr = 'No se pudo enviar. Verifica la configuración SMTP.';
    }

    $linkInfo = [
        'url'       => $manualLink,
        'respaldo'  => null,
        'expira'    => $manualExpira,
        'emailSent' => $emailSent,
        'emailTo'   => $manualEmail,
        'emailErr'  => $emailErr,
        'manual'    => true,
    ];
}

/* ── Lista de solicitudes fallidas ── */
$respaldos = [];
if ($fase === 2 && $cx instanceof mysqli) {
    $rs2 = $cx->query("
        SELECT r.id_respaldo, r.stripe_payment_intent_id, r.nombres, r.apellido_pa,
               r.correo, r.monto_orden, r.moneda, r.frecuencia_pago, r.created_at,
               r.estatus_respaldo
        FROM pats_respaldo r
        WHERE r.estatus_respaldo != 'RECUPERADO'
          AND NOT EXISTS (
              SELECT 1 FROM pats_pasaportes p
              WHERE p.correo = r.correo AND p.correo IS NOT NULL AND p.correo != ''
          )
        ORDER BY r.created_at DESC
        LIMIT 100
    ");
    if ($rs2 instanceof mysqli_result) {
        while ($r = $rs2->fetch_assoc()) $respaldos[] = $r;
    }
}
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Panel Recuperación PATS</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,sans-serif;background:#f1f5f9;min-height:100vh;padding:32px 16px}
  .wrap{max-width:1000px;margin:0 auto}
  .card{background:#fff;border-radius:16px;padding:32px;box-shadow:0 4px 24px rgba(0,0,0,.09);margin-bottom:24px}
  .logo{font-size:.9rem;font-weight:700;color:#2563eb;margin-bottom:4px}
  h1{font-size:1.2rem;font-weight:700;color:#1e293b;margin-bottom:6px}
  .sub{font-size:.85rem;color:#64748b;margin-bottom:20px}
  label{display:block;font-size:.8rem;font-weight:600;color:#475569;margin-bottom:5px}
  input[type=password],input[type=email],input[type=text]{width:100%;padding:10px 13px;border:1.5px solid #d1d5db;border-radius:8px;font-size:.9rem;outline:none;font-family:inherit}
  input:focus{border-color:#2563eb}
  .btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;background:#2563eb;color:#fff;border:none;border-radius:8px;font-size:.9rem;font-weight:600;cursor:pointer;text-decoration:none}
  .btn:hover{background:#1d4ed8}
  .btn-sm{padding:6px 14px;font-size:.8rem}
  .btn-sec{background:#f1f5f9;color:#374151;border:1.5px solid #d1d5db}
  .btn-sec:hover{background:#e2e8f0}
  .btn-green{background:#16a34a}
  .btn-green:hover{background:#15803d}
  .btn-orange{background:#ea580c}
  .btn-orange:hover{background:#c2410c}
  .error{background:#fef2f2;border:1.5px solid #f87171;border-radius:8px;padding:12px;color:#b91c1c;font-size:.875rem;margin-bottom:16px}
  /* link box */
  .link-card{border-radius:12px;padding:20px 24px;margin-bottom:20px}
  .link-card.ok{background:#f0fdf4;border:2px solid #22c55e}
  .link-card.warn{background:#fffbeb;border:2px solid #f59e0b}
  .link-card h3{font-size:.95rem;font-weight:700;margin-bottom:10px}
  .link-card.ok h3{color:#15803d}
  .link-card.warn h3{color:#92400e}
  .link-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px}
  .link-url{flex:1;font-family:monospace;font-size:.8rem;padding:9px 12px;background:#fff;border:1.5px solid #86efac;border-radius:8px;color:#166534;word-break:break-all}
  .link-url.warn-url{border-color:#fcd34d;color:#78350f}
  .expira-note{font-size:.78rem;color:#64748b;margin-bottom:14px}
  .email-status{font-size:.85rem;margin-bottom:10px;display:flex;align-items:center;gap:6px}
  .email-status.ok{color:#15803d}
  .email-status.fail{color:#b91c1c}
  /* manual email form */
  .manual-form{background:#fff;border:1.5px solid #e2e8f0;border-radius:10px;padding:16px;margin-top:12px}
  .manual-form h4{font-size:.82rem;font-weight:700;color:#374151;margin-bottom:10px}
  .mf-row{display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:end}
  /* table */
  table{width:100%;border-collapse:collapse;font-size:.85rem}
  th{text-align:left;padding:9px 12px;background:#f8fafc;color:#475569;font-weight:600;border-bottom:2px solid #e2e8f0}
  td{padding:9px 12px;border-bottom:1px solid #f1f5f9;color:#1e293b;vertical-align:middle}
  tr:hover td{background:#fafbfc}
  .badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:.72rem;font-weight:600}
  .badge-pend{background:#fef9c3;color:#854d0e}
  .badge-err{background:#fee2e2;color:#991b1b}
  .top-bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px}
  .empty{text-align:center;padding:40px;color:#64748b;font-size:.9rem}
  @media(max-width:600px){.mf-row{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
<div class="card">
  <div class="logo">PATS · Admin</div>

<?php if ($fase === 1): ?>
  <h1>Panel de recuperación</h1>
  <p class="sub">Acceso exclusivo para administradores.</p>
  <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="POST">
    <label for="admin_key">Clave de administrador</label>
    <input type="password" id="admin_key" name="admin_key" placeholder="••••••••" required autofocus>
    <div style="margin-top:14px">
      <button type="submit" class="btn">Entrar →</button>
    </div>
  </form>

<?php else: ?>
  <div class="top-bar">
    <div>
      <h1>Solicitudes con fallo de alta</h1>
      <p class="sub">Genera un link personalizado para cada cliente y se lo enviaremos automáticamente.</p>
    </div>
    <form method="POST" style="margin:0">
      <input type="hidden" name="logout" value="1">
      <button type="submit" class="btn btn-sec btn-sm">Cerrar sesión</button>
    </form>
  </div>

  <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <?php if ($linkInfo): ?>
  <?php
    $cardClass = $linkInfo['emailSent'] ? 'ok' : 'warn';
    $urlClass  = $linkInfo['emailSent'] ? '' : ' warn-url';
    $rb        = $linkInfo['respaldo'];
  ?>
  <div class="link-card <?= $cardClass ?>">
    <h3><?= $linkInfo['emailSent'] ? '✓ Link generado y correo enviado' : '⚠ Link generado — envío manual requerido' ?></h3>

    <?php if ($rb): ?>
    <div style="font-size:.83rem;color:#374151;margin-bottom:8px">
      Paciente: <strong><?= htmlspecialchars($rb['nombres'] . ' ' . $rb['apellido_pa']) ?></strong>
    </div>
    <?php endif; ?>

    <div class="link-row">
      <div class="link-url<?= $urlClass ?>" id="linkUrl"><?= htmlspecialchars($linkInfo['url']) ?></div>
      <button type="button" class="btn btn-sm btn-sec" onclick="copiarLink()">Copiar</button>
    </div>

    <div class="expira-note">⏱ Válido 48 h · Un solo uso · Expira: <?= htmlspecialchars($linkInfo['expira']) ?></div>

    <?php if ($linkInfo['emailSent']): ?>
    <div class="email-status ok">✓ Correo enviado a <strong><?= htmlspecialchars($linkInfo['emailTo']) ?></strong></div>
    <?php else: ?>
    <div class="email-status fail">✗ <?= htmlspecialchars($linkInfo['emailErr'] ?: 'No se envió correo.') ?></div>
    <?php endif; ?>

    <!-- Formulario de envío manual -->
    <div class="manual-form">
      <h4>Enviar por correo a otro destinatario</h4>
      <form method="POST">
        <input type="hidden" name="action"      value="enviar_correo_manual">
        <input type="hidden" name="link_url"    value="<?= htmlspecialchars($linkInfo['url']) ?>">
        <input type="hidden" name="expira"      value="<?= htmlspecialchars($linkInfo['expira']) ?>">
        <div class="mf-row">
          <div>
            <label for="nombre_dest">Nombre del destinatario</label>
            <input type="text" id="nombre_dest" name="nombre_dest"
                   placeholder="Nombre completo"
                   value="<?= $rb ? htmlspecialchars($rb['nombres'] . ' ' . $rb['apellido_pa']) : '' ?>">
          </div>
          <div>
            <label for="email_dest">Correo electrónico</label>
            <input type="email" id="email_dest" name="email_dest"
                   placeholder="correo@ejemplo.com"
                   value="<?= htmlspecialchars($linkInfo['emailSent'] ? '' : $linkInfo['emailTo']) ?>"
                   required>
          </div>
          <div>
            <label style="visibility:hidden">Enviar</label>
            <button type="submit" class="btn btn-orange btn-sm" style="width:100%">Enviar correo</button>
          </div>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <?php if (empty($respaldos)): ?>
    <div class="empty">No hay solicitudes con fallo pendiente.</div>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table>
    <thead>
      <tr>
        <th>ID</th><th>Paciente</th><th>Correo</th><th>Monto</th>
        <th>Frecuencia</th><th>Fecha</th><th>Estatus</th><th>Acción</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($respaldos as $r): ?>
      <tr>
        <td style="font-weight:600"><?= (int)$r['id_respaldo'] ?></td>
        <td><?= htmlspecialchars($r['nombres'] . ' ' . $r['apellido_pa']) ?></td>
        <td style="font-size:.78rem"><?= htmlspecialchars($r['correo'] ?? '—') ?></td>
        <td>$<?= number_format((float)$r['monto_orden'], 2) ?> <?= htmlspecialchars($r['moneda']) ?></td>
        <td><?= htmlspecialchars($r['frecuencia_pago']) ?></td>
        <td style="font-size:.78rem"><?= htmlspecialchars(substr((string)$r['created_at'], 0, 16)) ?></td>
        <td><span class="badge badge-<?= $r['estatus_respaldo']==='PENDIENTE_PAGO'?'pend':'err' ?>">
          <?= htmlspecialchars($r['estatus_respaldo']) ?></span></td>
        <td>
          <form method="POST" style="display:inline">
            <input type="hidden" name="action"      value="generar_link">
            <input type="hidden" name="id_respaldo" value="<?= (int)$r['id_respaldo'] ?>">
            <button type="submit" class="btn btn-green btn-sm">Generar y enviar link</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>

  <div style="margin-top:20px;padding-top:20px;border-top:1px solid #e2e8f0">
    <div style="font-size:.85rem;font-weight:600;color:#374151;margin-bottom:8px">¿Alta sin respaldo? Link en blanco:</div>
    <form method="POST" style="display:inline">
      <input type="hidden" name="action"      value="generar_link">
      <input type="hidden" name="id_respaldo" value="0">
      <button type="submit" class="btn btn-sec">Generar link en blanco</button>
    </form>
  </div>
<?php endif; ?>
</div>
</div>

<script>
function copiarLink() {
  var el = document.getElementById('linkUrl');
  if (!el) return;
  var text = el.textContent.trim();
  if (navigator.clipboard) {
    navigator.clipboard.writeText(text).then(function() {
      alert('Link copiado al portapapeles.');
    }).catch(fallback);
  } else { fallback(); }
  function fallback() {
    var rng = document.createRange();
    rng.selectNode(el);
    window.getSelection().removeAllRanges();
    window.getSelection().addRange(rng);
    document.execCommand('copy');
    alert('Link copiado.');
  }
}
</script>
</body>
</html>
