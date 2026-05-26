<?php
/*
ez/pats/test_mail_pats.php

PRUEBA TEMPORAL de correo SMTP PATS.
Borrar este archivo después de probar.

Uso:
https://TU_DOMINIO/50D/EZHS/ez/pats/test_mail_pats.php?to=correo@destino.com

Requiere:
- ez/pats/config/mail.php
- ez/pats/lib/pats_mailer.php
*/

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/config/mail.php';
require_once __DIR__ . '/lib/pats_mailer.php';

$to = trim((string)($_GET['to'] ?? ''));

if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
  http_response_code(422);
  echo "Falta correo destino válido. Ejemplo:\n";
  echo "test_mail_pats.php?to=correo@destino.com\n";
  exit;
}

try {
  $ok = pats_smtp_send_mail(
    $to,
    'Prueba SMTP PATS',
    '<h2>Prueba SMTP PATS</h2><p>Si recibes este correo, el SMTP quedó funcionando correctamente.</p>',
    "Prueba SMTP PATS\n\nSi recibes este correo, el SMTP quedó funcionando correctamente."
  );

  echo $ok ? "OK: correo enviado a {$to}\n" : "ERROR: pats_smtp_send_mail devolvió false\n";
} catch (Throwable $e) {
  http_response_code(500);
  echo "ERROR SMTP:\n";
  echo $e->getMessage() . "\n";
}
