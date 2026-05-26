<?php
/*
ez/pats/test_mail_pats_debug.php

Diagnóstico SMTP PATS.
BORRAR después de probar.

Uso:
https://TU_DOMINIO/50D/EZHS/ez/pats/test_mail_pats_debug.php?to=correo@destino.com

Este archivo muestra errores de configuración de forma controlada.
*/

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

function out_line(string $txt = ''): void {
  echo $txt . "\n";
}

function mask_val(string $v): string {
  if ($v === '') return '(vacío)';
  if (strlen($v) <= 8) return str_repeat('*', strlen($v));
  return substr($v, 0, 3) . str_repeat('*', max(4, strlen($v) - 7)) . substr($v, -4);
}

out_line("=== PATS SMTP DEBUG ===");
out_line("PHP: " . PHP_VERSION);
out_line("__DIR__: " . __DIR__);
out_line("");

$mailConfig = __DIR__ . '/config/mail.php';
$mailerLib  = __DIR__ . '/lib/pats_mailer.php';

out_line("Revisando archivos:");
out_line("- mail.php: " . $mailConfig . " => " . (is_file($mailConfig) ? "OK" : "NO EXISTE"));
out_line("- pats_mailer.php: " . $mailerLib . " => " . (is_file($mailerLib) ? "OK" : "NO EXISTE"));
out_line("");

if (!is_file($mailConfig)) {
  http_response_code(500);
  out_line("ERROR: No existe ez/pats/config/mail.php");
  exit;
}

if (!is_file($mailerLib)) {
  http_response_code(500);
  out_line("ERROR: No existe ez/pats/lib/pats_mailer.php");
  exit;
}

try {
  require_once $mailConfig;
  require_once $mailerLib;
} catch (Throwable $e) {
  http_response_code(500);
  out_line("ERROR al cargar archivos:");
  out_line($e->getMessage());
  exit;
}

out_line("Constantes SMTP:");
out_line("- PATS_SMTP_HOST: " . (defined('PATS_SMTP_HOST') ? PATS_SMTP_HOST : 'NO DEFINIDA'));
out_line("- PATS_SMTP_PORT: " . (defined('PATS_SMTP_PORT') ? (string)PATS_SMTP_PORT : 'NO DEFINIDA'));
out_line("- PATS_SMTP_SECURE: " . (defined('PATS_SMTP_SECURE') ? PATS_SMTP_SECURE : 'NO DEFINIDA'));
out_line("- PATS_SMTP_USER: " . (defined('PATS_SMTP_USER') ? PATS_SMTP_USER : 'NO DEFINIDA'));
out_line("- PATS_SMTP_PASS: " . (defined('PATS_SMTP_PASS') ? mask_val((string)PATS_SMTP_PASS) : 'NO DEFINIDA'));
out_line("- PATS_MAIL_FROM: " . (defined('PATS_MAIL_FROM') ? PATS_MAIL_FROM : 'NO DEFINIDA'));
out_line("");

if (!defined('PATS_SMTP_PASS') || trim((string)PATS_SMTP_PASS) === '' || str_contains((string)PATS_SMTP_PASS, 'PON_AQUI')) {
  http_response_code(500);
  out_line("ERROR: Falta configurar PATS_SMTP_PASS en ez/pats/config/mail.php");
  exit;
}

$host = defined('PATS_SMTP_HOST') ? (string)PATS_SMTP_HOST : '';
$port = defined('PATS_SMTP_PORT') ? (int)PATS_SMTP_PORT : 465;
$secure = defined('PATS_SMTP_SECURE') ? strtolower((string)PATS_SMTP_SECURE) : 'ssl';
$remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;

out_line("Probando conexión socket:");
out_line("- Remote: " . $remote);

$errno = 0;
$errstr = '';
$fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);

if (!$fp) {
  http_response_code(500);
  out_line("ERROR conexión SMTP:");
  out_line("errno: " . $errno);
  out_line("errstr: " . $errstr);
  out_line("");
  out_line("Posibles causas:");
  out_line("- HostGator bloquea salida SMTP 465 desde este hosting.");
  out_line("- El servidor SMTP correcto no es smtpout.secureserver.net para este buzón.");
  out_line("- SSL no está permitido desde PHP en este hosting.");
  exit;
}

stream_set_timeout($fp, 20);
$banner = fgets($fp, 515);
fclose($fp);

out_line("Conexión SMTP OK.");
out_line("Banner: " . trim((string)$banner));
out_line("");

$to = trim((string)($_GET['to'] ?? ''));
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
  out_line("No se envió correo porque falta destino válido.");
  out_line("Ejemplo:");
  out_line("test_mail_pats_debug.php?to=correo@destino.com");
  exit;
}

out_line("Enviando correo de prueba a: " . $to);

try {
  if (!function_exists('pats_smtp_send_mail')) {
    throw new RuntimeException('No existe función pats_smtp_send_mail(). Revisa ez/pats/lib/pats_mailer.php');
  }

  $ok = pats_smtp_send_mail(
    $to,
    'Prueba SMTP PATS',
    '<h2>Prueba SMTP PATS</h2><p>Si recibes este correo, el SMTP quedó funcionando correctamente.</p>',
    "Prueba SMTP PATS\n\nSi recibes este correo, el SMTP quedó funcionando correctamente."
  );

  out_line($ok ? "OK: correo enviado." : "ERROR: pats_smtp_send_mail devolvió false.");
} catch (Throwable $e) {
  http_response_code(500);
  out_line("ERROR al enviar:");
  out_line($e->getMessage());
}
