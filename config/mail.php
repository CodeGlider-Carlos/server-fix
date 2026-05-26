<?php
/*
ez/pats/config/mail.php

Configuración SMTP para correos automáticos PATS.

IMPORTANTE:
- No subas contraseñas reales a repositorios.
- Pega la contraseña solo en el servidor o usa variable de entorno.
- Como la contraseña ya fue compartida en chat, conviene rotarla al terminar pruebas.
*/

declare(strict_types=1);

if (!defined('PATS_SMTP_HOST')) {
  define('PATS_SMTP_HOST', 'smtpout.secureserver.net');
}

if (!defined('PATS_SMTP_PORT')) {
  define('PATS_SMTP_PORT', 465);
}

if (!defined('PATS_SMTP_SECURE')) {
  define('PATS_SMTP_SECURE', 'ssl');
}

if (!defined('PATS_SMTP_USER')) {
  define('PATS_SMTP_USER', 'noreply@pasaporteatusalud.com');
}

/*
  Opción recomendada:
  - Configurar variable de entorno PATS_SMTP_PASS en el servidor.

  Opción simple en HostGator/cPanel:
  - Reemplazar PON_AQUI_PASSWORD_SMTP por la contraseña del correo.
*/
if (!defined('PATS_SMTP_PASS')) {
  define('PATS_SMTP_PASS', getenv('PATS_SMTP_PASS') ?: '5Ys!Rd$@2026');
}

if (!defined('PATS_MAIL_FROM')) {
  define('PATS_MAIL_FROM', 'noreply@pasaporteatusalud.com');
}

if (!defined('PATS_MAIL_FROM_NAME')) {
  define('PATS_MAIL_FROM_NAME', 'Pasaporte a tu Salud');
}

/*
  Ruta futura para que el usuario defina su contraseña.
  Si todavía no existe la pantalla, déjala así y la creamos después.
*/
if (!defined('PATS_ACCESS_RESET_URL')) {
define('PATS_ACCESS_RESET_URL', 'https://50d.com.mx/50D/EZHS/ez/pats/crear-password.php');
}
