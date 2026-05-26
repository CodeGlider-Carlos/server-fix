<?php
/*
ez/pats/lib/pats_mailer.php

Mailer SMTP ligero sin Composer.
Corregido para SMTP estricto:
- Normaliza todo el mensaje a CRLF.
- Evita bare LF para no provocar:
  552 Message contains bare LF and is violating 822.bis section 2.3
*/

declare(strict_types=1);

if (!function_exists('pats_mailer_eol')) {
  function pats_mailer_eol(): string {
    return "\r\n";
  }
}

if (!function_exists('pats_mailer_normalize_eol')) {
  function pats_mailer_normalize_eol(string $s): string {
    /*
      Convierte cualquier salto:
      - CRLF
      - CR solo
      - LF solo
      a CRLF estricto.
    */
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    return str_replace("\n", "\r\n", $s);
  }
}

if (!function_exists('pats_mailer_read')) {
  function pats_mailer_read($fp): string {
    $data = '';
    while (($line = fgets($fp, 515)) !== false) {
      $data .= $line;
      if (isset($line[3]) && $line[3] === ' ') {
        break;
      }
    }
    return $data;
  }
}

if (!function_exists('pats_mailer_cmd')) {
  function pats_mailer_cmd($fp, string $cmd, array $okCodes): string {
    fwrite($fp, $cmd . pats_mailer_eol());
    $res = pats_mailer_read($fp);
    $code = (int)substr($res, 0, 3);

    if (!in_array($code, $okCodes, true)) {
      throw new RuntimeException('SMTP error en comando [' . $cmd . ']: ' . trim($res));
    }

    return $res;
  }
}

if (!function_exists('pats_mailer_header_encode')) {
  function pats_mailer_header_encode(string $text): string {
    return '=?UTF-8?B?' . base64_encode($text) . '?=';
  }
}

if (!function_exists('pats_mailer_dot_stuff')) {
  function pats_mailer_dot_stuff(string $message): string {
    /*
      SMTP exige duplicar punto si una línea inicia con punto.
      Usamos modo multilinea con CRLF ya normalizado.
    */
    return preg_replace('/^\./m', '..', $message) ?? $message;
  }
}

if (!function_exists('pats_smtp_send_mail')) {
  function pats_smtp_send_mail(string $to, string $subject, string $html, string $text = ''): bool {
    if (!defined('PATS_SMTP_HOST') || !defined('PATS_SMTP_USER') || !defined('PATS_SMTP_PASS')) {
      throw new RuntimeException('SMTP no configurado.');
    }

    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
      throw new RuntimeException('Correo destino inválido.');
    }

    $host = (string)PATS_SMTP_HOST;
    $port = (int)(defined('PATS_SMTP_PORT') ? PATS_SMTP_PORT : 465);
    $secure = defined('PATS_SMTP_SECURE') ? strtolower((string)PATS_SMTP_SECURE) : 'ssl';
    $user = (string)PATS_SMTP_USER;
    $pass = (string)PATS_SMTP_PASS;
    $from = defined('PATS_MAIL_FROM') ? (string)PATS_MAIL_FROM : $user;
    $fromName = defined('PATS_MAIL_FROM_NAME') ? (string)PATS_MAIL_FROM_NAME : 'PATS';

    if ($pass === '' || str_contains($pass, 'PON_AQUI')) {
      throw new RuntimeException('Falta configurar PATS_SMTP_PASS.');
    }

    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $errno = 0;
    $errstr = '';

    $fp = @stream_socket_client($remote, $errno, $errstr, 30, STREAM_CLIENT_CONNECT);
    if (!$fp) {
      throw new RuntimeException('No fue posible conectar al SMTP: ' . $errstr);
    }

    stream_set_timeout($fp, 30);

    $banner = pats_mailer_read($fp);
    if ((int)substr($banner, 0, 3) !== 220) {
      fclose($fp);
      throw new RuntimeException('SMTP no respondió correctamente: ' . trim($banner));
    }

    $hostName = $_SERVER['SERVER_NAME'] ?? 'pasaporteatusalud.com';

    pats_mailer_cmd($fp, 'EHLO ' . $hostName, [250]);
    pats_mailer_cmd($fp, 'AUTH LOGIN', [334]);
    pats_mailer_cmd($fp, base64_encode($user), [334]);
    pats_mailer_cmd($fp, base64_encode($pass), [235]);
    pats_mailer_cmd($fp, 'MAIL FROM:<' . $from . '>', [250]);
    pats_mailer_cmd($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
    pats_mailer_cmd($fp, 'DATA', [354]);

    $boundary = '=_PATS_' . bin2hex(random_bytes(12));

    if ($text === '') {
      $text = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html));
    }

    /*
      Normalizar partes ANTES de construir el MIME.
      Este fue el error: el HTML/texto traía LF simples.
    */
    $text = pats_mailer_normalize_eol($text);
    $html = pats_mailer_normalize_eol($html);

    $eol = pats_mailer_eol();

    $headers = [];
    $headers[] = 'Date: ' . date('r');
    $headers[] = 'From: ' . pats_mailer_header_encode($fromName) . ' <' . $from . '>';
    $headers[] = 'To: <' . $to . '>';
    $headers[] = 'Subject: ' . pats_mailer_header_encode($subject);
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

    $message = implode($eol, $headers) . $eol . $eol;

    $message .= '--' . $boundary . $eol;
    $message .= 'Content-Type: text/plain; charset=UTF-8' . $eol;
    $message .= 'Content-Transfer-Encoding: 8bit' . $eol . $eol;
    $message .= $text . $eol . $eol;

    $message .= '--' . $boundary . $eol;
    $message .= 'Content-Type: text/html; charset=UTF-8' . $eol;
    $message .= 'Content-Transfer-Encoding: 8bit' . $eol . $eol;
    $message .= $html . $eol . $eol;

    $message .= '--' . $boundary . '--' . $eol;

    /*
      Normalización final obligatoria.
      Evita cualquier LF suelto introducido por concatenación o variables.
    */
    $message = pats_mailer_normalize_eol($message);
    $message = pats_mailer_dot_stuff($message);

    fwrite($fp, $message . $eol . '.' . $eol);

    $dataRes = pats_mailer_read($fp);
    $dataCode = (int)substr($dataRes, 0, 3);
    if ($dataCode !== 250) {
      fclose($fp);
      throw new RuntimeException('SMTP no aceptó el mensaje: ' . trim($dataRes));
    }

    pats_mailer_cmd($fp, 'QUIT', [221]);
    fclose($fp);

    return true;
  }
}

if (!function_exists('pats_send_pasaporte_confirmacion_email')) {
  function pats_send_pasaporte_confirmacion_email(array $d): bool {
    $to = trim((string)($d['to'] ?? ''));
    $nombre = trim((string)($d['nombre_firmante'] ?? ''));
    $paciente = trim((string)($d['nombre_paciente'] ?? ''));
    $idPasaporte = (string)($d['id_pasaporte'] ?? '');
    $referencia      = (string)($d['referencia_pago'] ?? '');
    $folio           = (string)($d['folio_orden'] ?? '');
    $monto           = (float)($d['monto'] ?? 0);
    $moneda          = (string)($d['moneda'] ?? 'MXN');
    $frecuencia      = (string)($d['frecuencia'] ?? '');
    $usuario         = (string)($d['usuario'] ?? $to);
    $resetUrl        = (string)($d['reset_url'] ?? '');
    $tipoAcceso      = (string)($d['tipo_acceso'] ?? 'PACIENTE');
    $metodoPago      = strtoupper((string)($d['metodo_pago'] ?? 'TARJETA'));
    $oxxoVoucherUrl  = (string)($d['oxxo_voucher_url'] ?? '');
    $oxxoNumeroRef   = (string)($d['oxxo_numero_referencia'] ?? '');
    $oxxoExpiresAt   = (string)($d['oxxo_expires_at'] ?? '');

    $subject = $metodoPago === 'OXXO'
      ? 'Tu ficha de pago OXXO · Pasaporte PATS'
      : 'Tu Pasaporte PATS fue registrado';

    $safeNombre = htmlspecialchars($nombre !== '' ? $nombre : 'Hola', ENT_QUOTES, 'UTF-8');
    $safePaciente = htmlspecialchars($paciente, ENT_QUOTES, 'UTF-8');
    $safeUsuario = htmlspecialchars($usuario, ENT_QUOTES, 'UTF-8');
    $safeRef = htmlspecialchars($referencia, ENT_QUOTES, 'UTF-8');
    $safeFolio = htmlspecialchars($folio, ENT_QUOTES, 'UTF-8');
    $safeId = htmlspecialchars($idPasaporte, ENT_QUOTES, 'UTF-8');
    $safeMonto = htmlspecialchars('$' . number_format($monto, 2) . ' ' . $moneda, ENT_QUOTES, 'UTF-8');
    $safeFrecuencia = htmlspecialchars($frecuencia, ENT_QUOTES, 'UTF-8');
    $safeTipoAcceso = htmlspecialchars($tipoAcceso, ENT_QUOTES, 'UTF-8');

    $oxxoHtml = '';
    if ($metodoPago === 'OXXO') {
      $safeOxxoNum     = htmlspecialchars($oxxoNumeroRef, ENT_QUOTES, 'UTF-8');
      $safeOxxoExpires = $oxxoExpiresAt !== '' ? htmlspecialchars(date('d/m/Y H:i', strtotime($oxxoExpiresAt)), ENT_QUOTES, 'UTF-8') : '';
      $oxxoBtnHtml = '';
      if ($oxxoVoucherUrl !== '') {
        $safeOxxoUrl = htmlspecialchars($oxxoVoucherUrl, ENT_QUOTES, 'UTF-8');
        $oxxoBtnHtml = '<p style="margin:16px 0 0;">
          <a href="' . $safeOxxoUrl . '" style="display:inline-block;background:#e63a1e;color:#fff;text-decoration:none;font-weight:800;padding:13px 20px;border-radius:14px;">
            Ver ficha de pago OXXO
          </a>
        </p>';
      }
      $oxxoHtml = '
      <div style="margin:20px 0 0;background:#fff8f5;border:2px solid #e63a1e;border-radius:14px;padding:18px 20px;">
        <div style="font-size:13px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#e63a1e;margin-bottom:10px;">Pago OXXO · Ficha de pago</div>
        <p style="margin:0 0 10px;font-size:13px;color:#374151;line-height:1.55;">
          Paga en cualquier tienda OXXO con la siguiente referencia o abriendo la ficha digital:
        </p>
        ' . ($safeOxxoNum !== '' ? '<div style="font-size:22px;font-weight:900;letter-spacing:.12em;color:#b91c1c;background:#fff;border:1px solid #fca5a5;border-radius:8px;padding:10px 16px;text-align:center;margin:10px 0;">' . $safeOxxoNum . '</div>' : '') . '
        ' . ($safeOxxoExpires !== '' ? '<p style="margin:8px 0 0;font-size:12px;color:#60708f;">Vence el: <strong>' . $safeOxxoExpires . '</strong></p>' : '') . '
        <p style="margin:8px 0 0;font-size:12px;color:#60708f;">
          Tu pasaporte quedará <strong>pendiente</strong> hasta que confirmemos el pago (máx. 24 h hábiles).
        </p>
        ' . $oxxoBtnHtml . '
      </div>';
    }

    $btnHtml = '';
    if ($resetUrl !== '') {
      $safeUrl = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');
      $btnHtml = '<p style="margin:24px 0 0;">
        <a href="' . $safeUrl . '" style="display:inline-block;background:#0b2340;color:#fff;text-decoration:none;font-weight:800;padding:13px 18px;border-radius:14px;">
          Definir mi contraseña
        </a>
      </p>
      <p style="margin:12px 0 0;color:#60708f;font-size:12px;line-height:1.45;">
        Este enlace es temporal. Si expira, podrás solicitar uno nuevo.
      </p>';
    }

    $html = '<!doctype html>
<html lang="es">
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#f3f7ff;font-family:Arial,Helvetica,sans-serif;color:#102a56;">
  <div style="max-width:640px;margin:0 auto;padding:28px 16px;">
    <div style="background:linear-gradient(135deg,#071a3d,#243c9c 55%,#6d5dfc);border-radius:26px 26px 0 0;padding:26px;color:#fff;">
      <div style="font-size:12px;font-weight:800;letter-spacing:.14em;text-transform:uppercase;opacity:.82;">Pasaporte a tu Salud</div>
      <h1 style="margin:10px 0 0;font-size:28px;line-height:1.05;">Tu pasaporte fue registrado</h1>
      <p style="margin:12px 0 0;color:#e8f0ff;line-height:1.55;">Gracias por confiar en PATS. Tu pago fue confirmado y tu registro quedó guardado.</p>
    </div>

    <div style="background:#fff;border:1px solid #dce6f5;border-top:0;border-radius:0 0 26px 26px;padding:24px;">
      <p style="margin:0 0 16px;font-size:16px;line-height:1.55;">' . $safeNombre . ', estos son los datos de tu registro:</p>

      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:0;">
        <tr><td style="padding:10px;border-bottom:1px solid #eef2f8;color:#60708f;">Pasaporte</td><td style="padding:10px;border-bottom:1px solid #eef2f8;font-weight:800;">' . $safeId . '</td></tr>
        <tr><td style="padding:10px;border-bottom:1px solid #eef2f8;color:#60708f;">Paciente</td><td style="padding:10px;border-bottom:1px solid #eef2f8;font-weight:800;">' . $safePaciente . '</td></tr>
        <tr><td style="padding:10px;border-bottom:1px solid #eef2f8;color:#60708f;">Referencia</td><td style="padding:10px;border-bottom:1px solid #eef2f8;font-weight:800;">' . $safeRef . '</td></tr>
        <tr><td style="padding:10px;border-bottom:1px solid #eef2f8;color:#60708f;">Folio</td><td style="padding:10px;border-bottom:1px solid #eef2f8;font-weight:800;">' . $safeFolio . '</td></tr>
        <tr><td style="padding:10px;border-bottom:1px solid #eef2f8;color:#60708f;">Pago</td><td style="padding:10px;border-bottom:1px solid #eef2f8;font-weight:800;">' . $safeMonto . '</td></tr>
        <tr><td style="padding:10px;border-bottom:1px solid #eef2f8;color:#60708f;">Frecuencia</td><td style="padding:10px;border-bottom:1px solid #eef2f8;font-weight:800;">' . $safeFrecuencia . '</td></tr>
        <tr><td style="padding:10px;color:#60708f;">Usuario</td><td style="padding:10px;font-weight:800;">' . $safeUsuario . '</td></tr>
      </table>

      <p style="margin:18px 0 0;color:#60708f;line-height:1.55;">
        Tipo de acceso: <strong style="color:#102a56;">' . $safeTipoAcceso . '</strong>.
        Si el paciente es menor o dependiente, el responsable será quien administre el acceso.
      </p>

      ' . $oxxoHtml . '

      ' . $btnHtml . '

      <p style="margin:24px 0 0;color:#60708f;font-size:12px;line-height:1.45;">
        Si tú no realizaste este registro, por favor contacta a PATS.
      </p>
    </div>
  </div>
</body>
</html>';

    $text = "Tu Pasaporte PATS fue registrado.\n\n"
      . "Pasaporte: {$idPasaporte}\n"
      . "Paciente: {$paciente}\n"
      . "Referencia: {$referencia}\n"
      . "Folio: {$folio}\n"
      . "Pago: $" . number_format($monto, 2) . " {$moneda}\n"
      . "Frecuencia: {$frecuencia}\n"
      . "Usuario: {$usuario}\n";

    if ($resetUrl !== '') {
      $text .= "\nDefine tu contraseña aquí: {$resetUrl}\n";
    }

    if ($metodoPago === 'OXXO') {
      $text .= "\n--- Ficha de pago OXXO ---\n";
      if ($oxxoNumeroRef !== '') {
        $text .= "Referencia OXXO: {$oxxoNumeroRef}\n";
      }
      if ($oxxoExpiresAt !== '') {
        $text .= "Vence: " . date('d/m/Y H:i', strtotime($oxxoExpiresAt)) . "\n";
      }
      if ($oxxoVoucherUrl !== '') {
        $text .= "Ficha digital: {$oxxoVoucherUrl}\n";
      }
      $text .= "Tu pasaporte quedará pendiente hasta confirmar el pago.\n";
    }

    return pats_smtp_send_mail($to, $subject, $html, $text);
  }
}
