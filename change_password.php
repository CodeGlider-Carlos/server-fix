<?php
/*
ez/pats/change_password.php
*/
session_start();
require_once '../../varSQL/bd_pats.php';
require_once '../../varSQL/var_pats.php';
if (empty($_SESSION['usuario']) || strtoupper(trim((string)($_SESSION['portal_login'] ?? ''))) !== 'PATS') {
  header('Location: ../../index.php');
  exit;
}

$ver = time();
$nombre = trim((string)($_SESSION['nombre'] ?? 'Usuario'));
$usuario = trim((string)($_SESSION['usuario'] ?? ''));
$rol = trim((string)($_SESSION['rol'] ?? ''));
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>PATS · Cambio de contraseña</title>
  <link rel="icon" type="image/x-icon" href="../../img/ez.ico" />
  <link rel="stylesheet" type="text/css" href="css/change_password_premium.css">
</head>
<body>
  <div class="cp-wrap">
    <section class="cp-hero">
      <h1 class="cp-title">Cambia tu Contraseña</h1>
      <p class="cp-subtitle">
        Por seguridad, debes definir una nueva contraseña antes de continuar al sistema PATS.
      </p>
    </section>

    <section class="cp-shell">
      <div class="cp-shell__head">
        <span class="cp-kicker">PATS</span>
        <h2 class="cp-shell__title">Primer acceso seguro</h2>
        <p class="cp-shell__sub">
          Valida tu contraseña actual y crea una nueva clave con la política mínima requerida.
        </p>
      </div>

      <div class="cp-steps">
        <div class="cp-step is-done">
          <span class="cp-step__num">1</span>
          <span class="cp-step__label">Acceso</span>
        </div>
        <div class="cp-step is-active">
          <span class="cp-step__num">2</span>
          <span class="cp-step__label">Cambio de contraseña</span>
        </div>
        <div class="cp-step">
          <span class="cp-step__num">3</span>
          <span class="cp-step__label">Continuar al sistema</span>
        </div>
      </div>

      <div class="cp-body">
        <form id="frmChangePassword" novalidate>
          <div class="cp-block">
            <h3 class="cp-block__title">Datos de acceso</h3>

            <div class="cp-pills">
              <div class="cp-pill">Usuario: <?= htmlspecialchars($usuario, ENT_QUOTES, 'UTF-8') ?></div>
              <div class="cp-pill">Rol: <?= htmlspecialchars($rol, ENT_QUOTES, 'UTF-8') ?></div>
              <div class="cp-pill">Nombre: <?= htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') ?></div>
            </div>

            <div class="cp-note">
              La nueva contraseña debe tener al menos <strong>9 caracteres</strong> e incluir
              <strong>letras</strong>, <strong>números</strong> y <strong>símbolos</strong>.
            </div>

<div class="cp-field full">
  <label for="current_password">Contraseña actual</label>
  <div class="cp-passbox">
    <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
    <button type="button" class="cp-passbox__toggle" data-toggle-pass="#current_password" aria-label="Mostrar contraseña actual" title="Mostrar contraseña">
      <span class="cp-passbox__icon">👁</span>
    </button>
  </div>
</div>

<div class="cp-field">
  <label for="new_password">Nueva contraseña</label>
  <div class="cp-passbox">
    <input type="password" id="new_password" name="new_password" autocomplete="new-password" required>
    <button type="button" class="cp-passbox__toggle" data-toggle-pass="#new_password" aria-label="Mostrar nueva contraseña" title="Mostrar contraseña">
      <span class="cp-passbox__icon">👁</span>
    </button>
  </div>
</div>

<div class="cp-field">
  <label for="confirm_password">Confirmar nueva contraseña</label>
  <div class="cp-passbox">
    <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
    <button type="button" class="cp-passbox__toggle" data-toggle-pass="#confirm_password" aria-label="Mostrar confirmación de contraseña" title="Mostrar contraseña">
      <span class="cp-passbox__icon">👁</span>
    </button>
  </div>
</div>

            <div class="cp-pass-rules">
              <div class="cp-rule" id="ruleLen">Mínimo 9 caracteres</div>
              <div class="cp-rule" id="ruleLetter">Al menos una letra</div>
              <div class="cp-rule" id="ruleNumber">Al menos un número</div>
              <div class="cp-rule" id="ruleSymbol">Al menos un símbolo</div>
              <div class="cp-rule" id="ruleMatch">La confirmación debe coincidir</div>
            </div>
          </div>

          <div class="cp-actions">
            <button type="button" class="cp-btn cp-btn--ghost" id="btnLogout">Salir</button>
            <button type="submit" class="cp-btn cp-btn--primary" id="btnSave">Guardar y continuar</button>
          </div>
        </form>
      </div>
    </section>
  </div>

  <div class="cp-toast-host" id="cpToastHost"></div>

  <script>
    (() => {
      "use strict";

      const $ = (s) => document.querySelector(s);

      function toast(msg, type = 'info') {
        const host = $('#cpToastHost');
        if (!host) return;
        const item = document.createElement('div');
        item.className = `cp-toast cp-toast--${type}`;
        item.textContent = msg;
        host.appendChild(item);
        setTimeout(() => {
          item.style.transition = 'opacity .22s ease, transform .22s ease';
          item.style.opacity = '0';
          item.style.transform = 'translateY(-4px)';
          setTimeout(() => item.remove(), 240);
        }, 2600);
      }

      function validPassword(pwd) {
        const s = String(pwd || '');
        return {
          len: s.length >= 9,
          letter: /[A-Za-z]/.test(s),
          number: /\d/.test(s),
          symbol: /[^A-Za-z\d]/.test(s)
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

        const current = $('#current_password')?.value || '';
        const pwd = $('#new_password')?.value || '';
        const conf = $('#confirm_password')?.value || '';

        if (!current.trim()) return toast('Debes capturar tu contraseña actual.', 'error');
        if (!syncRules()) return toast('La nueva contraseña no cumple la política requerida.', 'error');
        if (pwd !== conf) return toast('La confirmación no coincide.', 'error');

        const btn = $('#btnSave');
        btn.disabled = true;
        btn.textContent = 'Guardando...';

        try {
          const fd = new FormData();
          fd.append('current_password', current);
          fd.append('new_password', pwd);
          fd.append('confirm_password', conf);

          const res = await fetch('endpoints/change_password_save.php', {
            method: 'POST',
            body: fd
          });

          const text = await res.text();
          let data = {};
          try {
            data = text ? JSON.parse(text) : {};
          } catch {
            console.error(text);
            toast('La respuesta del servidor no es válida.', 'error');
            btn.disabled = false;
            btn.textContent = 'Guardar y continuar';
            return;
          }

          if (!res.ok || data.ok === false) {
            toast(data.error || 'No fue posible actualizar la contraseña.', 'error');
            btn.disabled = false;
            btn.textContent = 'Guardar y continuar';
            return;
          }

          toast('Contraseña actualizada correctamente.', 'success');

          setTimeout(() => {
            window.location.href = data.redirect || 'index.php';
          }, 900);

        } catch (e) {
          console.error(e);
          toast('No fue posible actualizar la contraseña.', 'error');
          btn.disabled = false;
          btn.textContent = 'Guardar y continuar';
        }
      }

      document.addEventListener('DOMContentLoaded', () => {
        $('#new_password')?.addEventListener('input', syncRules);
        $('#confirm_password')?.addEventListener('input', syncRules);

        $('#frmChangePassword')?.addEventListener('submit', submitForm);

        $('#btnLogout')?.addEventListener('click', () => {
          window.location.href = '../../loglog/logout.php';
        });

       document.querySelectorAll('[data-toggle-pass]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const selector = btn.getAttribute('data-toggle-pass');
                const input = selector ? document.querySelector(selector) : null;
                const icon = btn.querySelector('.cp-passbox__icon');
                if (!input) return;

                const willShow = input.type === 'password';
                input.type = willShow ? 'text' : 'password';

                btn.classList.toggle('is-on', willShow);
                btn.setAttribute('title', willShow ? 'Ocultar contraseña' : 'Mostrar contraseña');
                btn.setAttribute('aria-label', willShow ? 'Ocultar contraseña' : 'Mostrar contraseña');

                if (icon) {
                icon.textContent = willShow ? '👁' : '👁';
                }
            });
         
        });
        syncRules();
      });
    })();
  </script>
</body>
</html>