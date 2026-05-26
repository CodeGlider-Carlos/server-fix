<?php
/*
ez/pats/crear-password.php

PATS · Crear contraseña desde enlace de correo.
Uso:
https://pasaporteatusalud.com/ez/pats/crear-password.php?t=TOKEN_RESET
*/

declare(strict_types=1);

require_once '../../varSQL/bd_pats.php';
require_once '../../varSQL/var_pats.php';

$cx = $conexionMod ?? $db_all2 ?? $db_all ?? null;

if (!$cx || !($cx instanceof mysqli)) {
  http_response_code(500);
  die('No hay conexión mysqli disponible');
}

function cp_h($v): string {
  return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cp_clean($v): string {
  return trim((string)($v ?? ''));
}

function cp_token_valido(mysqli $cx, string $token): ?array {
  if ($token === '') return null;

  $stmt = $cx->prepare("
    SELECT
      id_acceso,
      id_pasaporte,
      id_alta,
      id_orden,
      tipo_acceso,
      correo_usuario,
      telefono_usuario,
      nombre_usuario,
      nombre_paciente,
      password_temporal,
      debe_cambiar_password,
      token_reset,
      token_reset_expira,
      estatus,
      activo
    FROM pats_pasaporte_accesos
    WHERE token_reset = ?
      AND activo = 1
      AND UPPER(TRIM(estatus)) = 'ACTIVO'
      AND token_reset IS NOT NULL
      AND token_reset <> ''
      AND token_reset_expira IS NOT NULL
      AND token_reset_expira >= NOW()
    LIMIT 1
  ");

  if (!$stmt) return null;

  $stmt->bind_param('s', $token);
  $stmt->execute();
  $rs = $stmt->get_result();
  $row = $rs ? $rs->fetch_assoc() : null;
  $stmt->close();

  return $row ?: null;
}

$token = cp_clean($_GET['t'] ?? '');
$acceso = cp_token_valido($cx, $token);

$tokenEstado = 'ok';
$tokenTitulo = 'Define tu contraseña';
$tokenTexto = 'Crea una contraseña segura para activar tu acceso a PATS.';

if ($token === '') {
  $tokenEstado = 'invalid';
  $tokenTitulo = 'Enlace incompleto';
  $tokenTexto = 'El enlace no incluye el token necesario para crear la contraseña.';
} elseif (!$acceso) {
  $tokenEstado = 'invalid';
  $tokenTitulo = 'Enlace vencido o no válido';
  $tokenTexto = 'Este enlace ya fue utilizado, expiró o no corresponde a un acceso activo.';
}

$correo = $acceso ? (string)($acceso['correo_usuario'] ?? '') : '';
$nombrePaciente = $acceso ? (string)($acceso['nombre_paciente'] ?? '') : '';
$tipoAcceso = $acceso ? (string)($acceso['tipo_acceso'] ?? '') : '';
$loginUrl = 'https://pasaporteatusalud.com';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title>PATS · Crear contraseña</title>
  <link rel="icon" type="image/x-icon" href="../../img/ez.ico">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

  <style>
    :root{
      --p1:#073BFF;--p2:#006DFF;--cyan:#00D9C8;--lime:#B8F21D;--violet:#8A6CFF;
      --text:#0B1022;--text2:#3B435C;--muted:#6D7898;--border:#DCE5FB;
      --danger:#E05278;--success:#00B985;--soft:#F2F6FF;--ff:"DM Sans",system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
      --ease:cubic-bezier(.22,1,.36,1);
    }
    *{box-sizing:border-box;margin:0;padding:0}
    html{min-height:100%;overflow-x:hidden}
    body{
      min-height:100svh;font-family:var(--ff);color:#fff;overflow-x:hidden;-webkit-font-smoothing:antialiased;
      background:radial-gradient(circle at 82% 14%,rgba(0,217,200,.22),transparent 31%),
                 radial-gradient(circle at 8% 90%,rgba(138,108,255,.18),transparent 34%),
                 linear-gradient(135deg,#071127 0%,#0b1d53 46%,#13358e 100%);
    }
    body:before{
      content:"";position:fixed;inset:0;z-index:0;pointer-events:none;
      background-image:linear-gradient(rgba(255,255,255,.045) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.045) 1px,transparent 1px);
      background-size:64px 64px;mask-image:radial-gradient(ellipse at 75% 35%,#000 0%,transparent 72%);
    }
    a{color:inherit;text-decoration:none} button,input{font-family:inherit}
    .wrap{width:min(1180px,calc(100% - 36px));margin:0 auto}
    .nav{
      position:fixed;top:0;left:0;right:0;z-index:50;height:74px;display:flex;align-items:center;
      background:radial-gradient(circle at 90% 0%,rgba(0,217,200,.16),transparent 30%),linear-gradient(135deg,rgba(7,17,39,.96),rgba(11,29,83,.95) 54%,rgba(15,48,128,.94));
      border-bottom:1px solid rgba(255,255,255,.08);backdrop-filter:blur(18px)
    }
    .nav-inner{display:flex;align-items:center;justify-content:space-between;gap:22px}
    .brand{display:flex;align-items:center;gap:12px;min-width:168px}
    .brand img{height:54px;width:auto;object-fit:contain;display:block}
    .nav-chip{
      display:inline-flex;align-items:center;justify-content:center;padding:10px 16px;border-radius:999px;
      background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);color:rgba(255,255,255,.82);
      font-size:.78rem;font-weight:900;white-space:nowrap
    }
    .page{position:relative;z-index:1;min-height:100svh;padding:112px 0 44px;display:grid;align-items:center}
    .grid{display:grid;grid-template-columns:minmax(0,.92fr) minmax(420px,1.08fr);gap:clamp(26px,4.5vw,68px);align-items:center}
    .hero-kicker{
      display:inline-flex;align-items:center;gap:10px;padding:8px 14px;margin-bottom:24px;border-radius:999px;
      background:rgba(255,255,255,.09);border:1px solid rgba(255,255,255,.14);color:rgba(255,255,255,.82);
      font-size:.74rem;font-weight:900;letter-spacing:.12em;text-transform:uppercase;backdrop-filter:blur(12px)
    }
    .hero-kicker i{width:7px;height:7px;border-radius:50%;background:var(--cyan);box-shadow:0 0 16px rgba(0,217,200,.9)}
    .hero-title{font-size:clamp(3.2rem,7vw,7.4rem);line-height:.86;letter-spacing:-.075em;font-weight:900;margin-bottom:24px}
    .hero-title span{
      background:linear-gradient(90deg,#fff 0%,#cfe0ff 20%,#79b5ff 46%,#1fd6c8 76%,#b8f21d 100%);
      -webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent
    }
    .hero-text{max-width:570px;color:rgba(255,255,255,.68);font-size:1.04rem;line-height:1.75;font-weight:500;margin-bottom:24px}
    .hero-pills{display:flex;flex-wrap:wrap;gap:8px}
    .pill{
      display:inline-flex;align-items:center;gap:7px;padding:8px 12px;border-radius:999px;background:rgba(255,255,255,.08);
      border:1px solid rgba(255,255,255,.13);color:rgba(255,255,255,.76);font-size:.74rem;font-weight:800
    }
    .pill:before{content:"✓";display:inline-grid;place-items:center;width:16px;height:16px;border-radius:50%;background:rgba(0,217,200,.16);color:var(--cyan);font-size:.68rem;font-weight:900}
    .card{
      position:relative;overflow:hidden;border-radius:34px;background:linear-gradient(180deg,rgba(255,255,255,.97),rgba(242,246,255,.96));
      color:var(--text);border:1px solid rgba(255,255,255,.32);box-shadow:0 40px 100px rgba(0,0,0,.34)
    }
    .card:before{
      content:"";position:absolute;inset:0;pointer-events:none;opacity:.45;background:
      linear-gradient(90deg,rgba(0,115,255,.10) 1px,transparent 1px),linear-gradient(rgba(0,115,255,.08) 1px,transparent 1px);
      background-size:18px 18px;mask-image:radial-gradient(ellipse at 50% 18%,#000 0%,transparent 58%)
    }
    .card-head,.card-body{position:relative;z-index:1}
    .card-head{padding:clamp(24px,4vw,38px);background:radial-gradient(circle at 92% 10%,rgba(0,217,200,.13),transparent 30%),linear-gradient(135deg,rgba(7,59,255,.08),rgba(255,255,255,.40));border-bottom:1px solid rgba(0,37,160,.10)}
    .status-row{display:flex;gap:18px;align-items:flex-start}
    .status-icon{
      width:74px;height:74px;flex:0 0 74px;border-radius:24px;display:grid;place-items:center;color:#fff;font-size:34px;font-weight:900;
      background:linear-gradient(135deg,var(--p1),var(--p2) 56%,var(--cyan));box-shadow:0 24px 60px rgba(0,37,160,.18)
    }
    .status-icon.is-error{background:linear-gradient(135deg,var(--danger),#ff7c9c)}
    .chip{
      display:inline-flex;align-items:center;min-height:30px;padding:0 12px;border-radius:999px;font-size:.68rem;font-weight:900;
      letter-spacing:.14em;text-transform:uppercase;margin-bottom:12px;background:rgba(0,109,255,.11);color:#083dff;border:1px solid rgba(0,109,255,.18)
    }
    .chip.is-error{background:rgba(224,82,120,.11);color:#b24367;border-color:rgba(224,82,120,.18)}
    .title{color:var(--text);font-size:clamp(2.05rem,4vw,3.3rem);line-height:.95;letter-spacing:-.065em;font-weight:900;margin-bottom:12px}
    .copy{color:var(--text2);line-height:1.7;font-size:.98rem;font-weight:650;max-width:610px}
    .card-body{padding:clamp(20px,3.2vw,34px)}
    .pills-user{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px}
    .user-pill{
      display:inline-flex;align-items:center;gap:6px;min-height:34px;padding:0 12px;border-radius:999px;background:#fff;border:1px solid var(--border);
      color:var(--text2);font-size:.78rem;font-weight:800;box-shadow:0 10px 26px rgba(0,37,160,.05);overflow-wrap:anywhere
    }
    .note{padding:14px 15px;border-radius:18px;background:rgba(0,109,255,.07);border:1px solid rgba(0,109,255,.14);color:var(--text2);line-height:1.6;font-size:.88rem;font-weight:650;margin-bottom:18px}
    .form{display:grid;grid-template-columns:1fr 1fr;gap:14px}
    .field{display:flex;flex-direction:column;gap:7px}.field.full{grid-column:1/-1}
    .label{color:var(--muted);font-size:.70rem;font-weight:900;letter-spacing:.10em;text-transform:uppercase}
    .passbox{position:relative;display:flex;align-items:center;border-radius:18px;background:#fff;border:1px solid var(--border);box-shadow:0 10px 26px rgba(0,37,160,.06);overflow:hidden;transition:.2s var(--ease)}
    .passbox:focus-within{border-color:rgba(0,109,255,.45);box-shadow:0 0 0 4px rgba(0,109,255,.10),0 16px 34px rgba(0,37,160,.08)}
    .input{width:100%;min-height:52px;padding:0 52px 0 16px;border:0;outline:0;background:transparent;color:var(--text);font-size:1rem;font-weight:800}
    .input::placeholder{color:#9ba8c4;font-weight:700}
    .toggle{position:absolute;right:8px;width:38px;height:38px;border:0;border-radius:14px;background:var(--soft);color:var(--muted);font-size:16px;display:grid;place-items:center;cursor:pointer}
    .rules{grid-column:1/-1;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px;margin-top:4px}
    .rule{min-height:34px;display:flex;align-items:center;gap:7px;padding:8px 10px;border-radius:999px;background:#fff;border:1px solid var(--border);color:var(--muted);font-size:.76rem;font-weight:800}
    .rule:before{content:"";width:10px;height:10px;border-radius:50%;background:#cfd8ec;flex:0 0 auto}
    .rule.is-ok{color:#047857;border-color:rgba(0,185,133,.25);background:rgba(0,185,133,.08)}
    .rule.is-ok:before{background:var(--success);box-shadow:0 0 0 4px rgba(0,185,133,.12)}
    .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}
    .btn{display:inline-flex;align-items:center;justify-content:center;gap:10px;min-height:50px;padding:14px 22px;border-radius:999px;border:0;font-weight:900;font-size:.88rem;cursor:pointer;transition:.24s var(--ease)}
    .btn:hover{transform:translateY(-2px)}.btn:disabled{opacity:.55;cursor:not-allowed;transform:none}
    .btn-primary{background:linear-gradient(135deg,#083dff 0%,#006fff 48%,#12d8ca 100%);color:#fff;box-shadow:0 16px 42px rgba(0,109,255,.28)}
    .btn-secondary{background:#fff;border:1px solid var(--border);color:var(--text2)}
    .success-box{display:none;padding:18px;border-radius:22px;background:rgba(0,185,133,.09);border:1px solid rgba(0,185,133,.20);color:#047857;font-weight:800;line-height:1.55}
    .success-box.show{display:block}
    .invalid-box{padding:18px;border-radius:22px;background:rgba(224,82,120,.09);border:1px solid rgba(224,82,120,.20);color:#9f244c;font-weight:800;line-height:1.55}
    .toast-host{position:fixed;right:18px;bottom:18px;z-index:9999;display:flex;flex-direction:column;gap:8px;align-items:flex-end;pointer-events:none}
    .toast{width:min(360px,calc(100vw - 32px));padding:13px 14px;border-radius:16px;color:#fff;font-weight:850;font-size:.86rem;line-height:1.4;box-shadow:0 18px 42px rgba(0,0,0,.24);background:linear-gradient(135deg,#102a66,#083dff)}
    .toast.error{background:linear-gradient(135deg,#8f1d3d,#e05278)}.toast.success{background:linear-gradient(135deg,#006b55,#00b985)}
    .pp-hidden{display:none!important}
    @media (max-width:1120px){.page{align-items:start}.grid{grid-template-columns:1fr;padding:36px 0 54px}.hero-copy{max-width:790px}.card{max-width:820px}}
    @media (max-width:760px){
      .wrap{width:min(100% - 26px,1180px)}.nav{height:68px}.brand img{height:46px}.nav-chip{padding:9px 12px;font-size:.70rem}
      .page{padding-top:92px;padding-bottom:28px}.grid{padding:20px 0 34px;gap:22px}.hero-title{font-size:clamp(3rem,14vw,4.8rem)}.hero-text{font-size:.96rem}
      .card{border-radius:26px}.card-head{padding:22px 18px}.status-row{gap:12px}.status-icon{width:58px;height:58px;flex-basis:58px;border-radius:19px;font-size:28px}
      .chip{min-height:27px;padding:0 10px;font-size:.58rem;letter-spacing:.10em;margin-bottom:9px}.title{font-size:clamp(1.8rem,8vw,2.6rem)}.copy{font-size:.88rem}
      .card-body{padding:16px}.form{grid-template-columns:1fr;gap:12px}.rules{grid-template-columns:1fr}.actions{display:grid;grid-template-columns:1fr}.btn{width:100%}
    }
    @media (max-width:430px){
      .wrap{width:min(100% - 18px,1180px)}.nav-chip{display:none}.hero-kicker{font-size:.62rem;letter-spacing:.10em;margin-bottom:18px}
      .hero-title{font-size:clamp(2.65rem,16vw,3.7rem)}.status-row{display:block}.status-icon{margin-bottom:12px}.pills-user{display:grid;grid-template-columns:1fr}
    }
    @media (prefers-reduced-motion:reduce){.btn{transition:none}.btn:hover{transform:none}}
  </style>
</head>
<body>
  <header class="nav">
    <div class="wrap nav-inner">
      <a class="brand" href="<?= cp_h($loginUrl) ?>" aria-label="Pasaporte a tu Salud">
        <img src="https://50d.com.mx/50D/EZHS/img2/logos/PATS_W.png" alt="Pasaporte a tu Salud">
      </a>
      <a class="nav-chip" href="<?= cp_h($loginUrl) ?>">Volver al sitio</a>
    </div>
  </header>

  <main class="page">
    <div class="wrap grid">
      <section class="hero-copy">
        <div class="hero-kicker"><i></i> PATS · Acceso seguro</div>
        <h1 class="hero-title">Crea tu<br>contraseña<br><span>PATS</span></h1>
        <p class="hero-text">Este enlace permite activar tu acceso de forma segura. Tu usuario será el correo registrado durante la compra de tu Pasaporte PATS.</p>
        <div class="hero-pills">
          <span class="pill">Acceso protegido</span>
          <span class="pill">Contraseña cifrada</span>
          <span class="pill">Token temporal</span>
        </div>
      </section>

      <section class="card">
        <div class="card-head">
          <div class="status-row">
            <div class="status-icon <?= $tokenEstado !== 'ok' ? 'is-error' : '' ?>"><?= $tokenEstado === 'ok' ? '🔐' : '!' ?></div>
            <div>
              <div class="chip <?= $tokenEstado !== 'ok' ? 'is-error' : '' ?>"><?= $tokenEstado === 'ok' ? 'Primer acceso' : 'Enlace no válido' ?></div>
              <h2 class="title"><?= cp_h($tokenTitulo) ?></h2>
              <p class="copy"><?= cp_h($tokenTexto) ?></p>
            </div>
          </div>
        </div>

        <div class="card-body">
          <?php if ($tokenEstado !== 'ok'): ?>
            <div class="invalid-box">Solicita un nuevo enlace de acceso o contacta a PATS para recuperar tu cuenta.</div>
            <div class="actions"><a class="btn btn-primary" href="<?= cp_h($loginUrl) ?>">Volver a PATS</a></div>
          <?php else: ?>
            <div class="pills-user">
              <div class="user-pill">Usuario: <?= cp_h($correo) ?></div>
              <div class="user-pill">Acceso: <?= cp_h($tipoAcceso ?: 'PACIENTE') ?></div>
              <?php if ($nombrePaciente !== ''): ?><div class="user-pill">Paciente: <?= cp_h($nombrePaciente) ?></div><?php endif; ?>
            </div>

            <div class="note">
              La nueva contraseña debe tener al menos <strong>9 caracteres</strong> e incluir <strong>letras</strong>, <strong>números</strong> y <strong>símbolos</strong>.
            </div>

            <div class="success-box" id="successBox">Tu contraseña fue creada correctamente. Ya puedes continuar.</div>

            <form class="form" id="frmCrearPassword" autocomplete="off" novalidate>
              <input type="hidden" name="token" id="token" value="<?= cp_h($token) ?>">

              <div class="field">
                <label class="label" for="new_password">Nueva contraseña</label>
                <div class="passbox">
                  <input class="input" type="password" id="new_password" name="new_password" autocomplete="new-password" placeholder="Crea tu contraseña" required>
                  <button class="toggle" type="button" data-toggle-pass="#new_password" aria-label="Mostrar contraseña">👁</button>
                </div>
              </div>

              <div class="field">
                <label class="label" for="confirm_password">Confirmar contraseña</label>
                <div class="passbox">
                  <input class="input" type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" placeholder="Repite tu contraseña" required>
                  <button class="toggle" type="button" data-toggle-pass="#confirm_password" aria-label="Mostrar contraseña">👁</button>
                </div>
              </div>

              <div class="rules">
                <div class="rule" id="ruleLen">Mínimo 9 caracteres</div>
                <div class="rule" id="ruleLetter">Al menos una letra</div>
                <div class="rule" id="ruleNumber">Al menos un número</div>
                <div class="rule" id="ruleSymbol">Al menos un símbolo</div>
                <div class="rule" id="ruleMatch">La confirmación debe coincidir</div>
              </div>

              <div class="actions field full">
                <button class="btn btn-primary" type="submit" id="btnSave">Guardar contraseña</button>
                <a class="btn btn-secondary" href="<?= cp_h($loginUrl) ?>">Cancelar</a>
              </div>
            </form>
          <?php endif; ?>
        </div>
      </section>
    </div>
  </main>

  <div class="toast-host" id="toastHost"></div>

  <script>
    (() => {
      "use strict";
      const $ = (s) => document.querySelector(s);

      function toast(msg, type = 'info') {
        const host = $('#toastHost');
        if (!host) return;
        const item = document.createElement('div');
        item.className = 'toast ' + type;
        item.textContent = msg;
        host.appendChild(item);
        setTimeout(() => {
          item.style.transition = 'opacity .22s ease, transform .22s ease';
          item.style.opacity = '0';
          item.style.transform = 'translateY(-4px)';
          setTimeout(() => item.remove(), 240);
        }, 3000);
      }

      function validPassword(pwd) {
        const s = String(pwd || '');
        return {
          len: s.length >= 9,
          letter: /[A-Za-zÁÉÍÓÚáéíóúÑñ]/.test(s),
          number: /\d/.test(s),
          symbol: /[^A-Za-zÁÉÍÓÚáéíóúÑñ\d]/.test(s)
        };
      }

      function syncRules() {
        const pwd = $('#new_password')?.value || '';
        const conf = $('#confirm_password')?.value || '';
        const r = validPassword(pwd);
        $('#ruleLen')?.classList.toggle('is-ok', r.len);
        $('#ruleLetter')?.classList.toggle('is-ok', r.letter);
        $('#ruleNumber')?.classList.toggle('is-ok', r.number);
        $('#ruleSymbol')?.classList.toggle('is-ok', r.symbol);
        $('#ruleMatch')?.classList.toggle('is-ok', !!pwd && pwd === conf);
        return r.len && r.letter && r.number && r.symbol && !!pwd && pwd === conf;
      }

      async function submitForm(ev) {
        ev.preventDefault();
        const pwd = $('#new_password')?.value || '';
        const conf = $('#confirm_password')?.value || '';
        if (!syncRules()) return toast('La contraseña no cumple la política requerida.', 'error');
        if (pwd !== conf) return toast('La confirmación no coincide.', 'error');

        const btn = $('#btnSave');
        if (btn) { btn.disabled = true; btn.textContent = 'Guardando...'; }

        try {
          const fd = new FormData(ev.currentTarget);
          const res = await fetch('endpoints/crear_password_token_save.php', { method: 'POST', body: fd });
          const text = await res.text();
          let data = {};
          try { data = text ? JSON.parse(text) : {}; }
          catch { console.error(text); throw new Error('La respuesta del servidor no es válida.'); }

          if (!res.ok || data.ok === false) throw new Error(data.error || 'No fue posible guardar la contraseña.');

          $('#frmCrearPassword')?.classList.add('pp-hidden');
          $('#successBox')?.classList.add('show');
          toast('Contraseña creada correctamente.', 'success');

          setTimeout(() => {
            window.location.href = data.redirect || <?= json_encode($loginUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
          }, 1400);

        } catch (e) {
          console.error(e);
          toast(e.message || 'No fue posible guardar la contraseña.', 'error');
          if (btn) { btn.disabled = false; btn.textContent = 'Guardar contraseña'; }
        }
      }

      document.addEventListener('DOMContentLoaded', () => {
        $('#new_password')?.addEventListener('input', syncRules);
        $('#confirm_password')?.addEventListener('input', syncRules);
        $('#frmCrearPassword')?.addEventListener('submit', submitForm);

        document.querySelectorAll('[data-toggle-pass]').forEach((btn) => {
          btn.addEventListener('click', () => {
            const input = document.querySelector(btn.getAttribute('data-toggle-pass') || '');
            if (!input) return;
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
          });
        });
        syncRules();
      });
    })();
  </script>
</body>
</html>
