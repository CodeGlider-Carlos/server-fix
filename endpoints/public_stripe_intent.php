<?php
/*
ez/pats/endpoints/public_stripe_intent.php

PATS · Crear PaymentIntent de Stripe

Este endpoint es requerido por:
- ez/pats/solicitud_pats.php

Flujo:
1. solicitud_pats.php valida todo el formulario.
2. solicitud_pats.php llama a este endpoint para crear PaymentIntent.
3. Stripe.js confirma el pago en navegador.
4. solicitud_pats.php manda stripe_payment_intent_id a public_checkout_generar_orden.php.
5. public_checkout_generar_orden.php revalida el PaymentIntent contra Stripe y guarda orden/contrato/documentos.

IMPORTANTE:
- Este endpoint NO guarda pasaporte.
- Este endpoint NO guarda documentos.
- Este endpoint NO guarda contrato.
- Solo prepara el cobro seguro en Stripe.

Requiere:
- ez/pats/config/config.php con:
  STRIPE_PUBLIC_KEY
  STRIPE_SECRET_KEY
  STRIPE_CURRENCY = mxn
*/

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';

function psi_json(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function psi_clean($v): string {
    return trim((string)($v ?? ''));
}

function psi_num($v): float {
    return round((float)($v ?? 0), 2);
}

function psi_upper($v): string {
    return strtoupper(psi_clean($v));
}

function psi_valid_email(string $email): bool {
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

function psi_get_csrf_from_request(): string {
    $header = psi_clean($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($header !== '') {
        return $header;
    }

    return psi_clean($_POST['_csrf'] ?? '');
}

function psi_validate_csrf(): void {
    $csrf = psi_get_csrf_from_request();

    if ($csrf === '') {
        psi_json(['ok' => false, 'error' => 'Sesión inválida. Recarga la página.'], 403);
    }

    /*
      Compatibilidad 1:
      solicitud_pats.php usa $_SESSION['_csrf_pats_publico'].
    */
    $sessionCsrf = psi_clean($_SESSION['_csrf_pats_publico'] ?? '');
    if ($sessionCsrf !== '' && hash_equals($sessionCsrf, $csrf)) {
        return;
    }

    /*
      Compatibilidad 2:
      Si config/config.php implementa pats_csrf_validate() con HMAC stateless,
      también se acepta.
    */
    if (function_exists('pats_csrf_validate') && pats_csrf_validate($csrf)) {
        return;
    }

    psi_json(['ok' => false, 'error' => 'Sesión inválida. Recarga la página.'], 403);
}

function psi_get_stripe_secret(): string {
    if (defined('STRIPE_SECRET_KEY')) {
        return trim((string) STRIPE_SECRET_KEY);
    }

    $env = getenv('STRIPE_SECRET_KEY');
    if ($env !== false && trim((string)$env) !== '') {
        return trim((string)$env);
    }

    return '';
}

function psi_stripe_request(string $secret, array $params, string $idempotencyKey = ''): array {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL no está disponible para conectar con Stripe.');
    }

    $headers = [
        'Content-Type: application/x-www-form-urlencoded'
    ];

    if ($idempotencyKey !== '') {
        $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    }

    $ch = curl_init('https://api.stripe.com/v1/payment_intents');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_USERPWD        => $secret . ':',
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 25,
    ]);

    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false || $err !== '') {
        throw new RuntimeException('No fue posible contactar Stripe: ' . $err);
    }

    $json = json_decode((string)$body, true);

    if (!is_array($json)) {
        throw new RuntimeException('Stripe no devolvió JSON válido.');
    }

    if ($http < 200 || $http >= 300) {
        $msg = $json['error']['message'] ?? ('HTTP ' . $http);
        throw new RuntimeException('Stripe: ' . $msg);
    }

    return $json;
}

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        psi_json(['ok' => false, 'error' => 'Método no permitido.'], 405);
    }

    psi_validate_csrf();

    $secret = psi_get_stripe_secret();

    if (
        $secret === ''
        || str_contains($secret, 'REEMPLAZAR')
        || str_starts_with($secret, 'sk_live_XXXX')
        || str_starts_with($secret, 'sk_test_XXXX')
    ) {
        psi_json(['ok' => false, 'error' => 'Stripe no está configurado. Falta STRIPE_SECRET_KEY válido.'], 500);
    }

    $frecuencia = psi_upper($_POST['frecuencia'] ?? 'MENSUAL');
    $montoOrden = psi_num($_POST['monto_orden'] ?? 0);
    $correo     = strtolower(psi_clean($_POST['correo'] ?? $_POST['correo_usuario_pats'] ?? ''));
    $nombre     = psi_clean($_POST['nombre'] ?? $_POST['nombre_usuario'] ?? '');
    $intentKey  = preg_replace('/[^a-zA-Z0-9_\-]/', '', psi_clean($_POST['intent_key'] ?? ''));

    if ($montoOrden <= 0) {
        psi_json(['ok' => false, 'error' => 'Monto inválido para el pago.'], 422);
    }

    if ($correo !== '' && !psi_valid_email($correo)) {
        psi_json(['ok' => false, 'error' => 'Correo inválido para el pago.'], 422);
    }

    /*
      Seguridad operativa:
      El frontend manda monto_orden. Se acepta porque public_checkout_generar_orden.php
      vuelve a verificar el PaymentIntent contra Stripe y el monto.
      Aun así, aquí normalizamos a centavos.
    */
    $amountCents = (int)round($montoOrden * 100);
    if ($amountCents < 100) {
        psi_json(['ok' => false, 'error' => 'Monto demasiado bajo para procesar.'], 422);
    }

    $currency = defined('STRIPE_CURRENCY') ? strtolower((string) STRIPE_CURRENCY) : 'mxn';
    if ($currency === '') {
        $currency = 'mxn';
    }

    $metadata = [
        'sistema'    => 'PATS',
        'flujo'      => 'ALTA_PATS_PUBLICA',
        'frecuencia' => $frecuencia,
        'correo'     => $correo,
    ];

    $params = [
        'amount' => $amountCents,
        'currency' => $currency,
        'description' => 'Alta PATS ' . ($frecuencia ?: 'MENSUAL'),
        'metadata[sistema]' => $metadata['sistema'],
        'metadata[flujo]' => $metadata['flujo'],
        'metadata[frecuencia]' => $metadata['frecuencia'],
        'metadata[correo]' => $metadata['correo'],
        'automatic_payment_methods[enabled]' => 'true',
        'payment_method_options[card][installments][enabled]' => 'true',
    ];

    if ($correo !== '') {
        $params['receipt_email'] = $correo;
    }

    /*
      Idempotency-Key:
      Evita crear múltiples PaymentIntent si el usuario da doble clic o reintenta.
    */
    $idempotencyKey = 'pats-intent-' . date('Ymd') . '-' . ($intentKey ?: bin2hex(random_bytes(8)));

    $intent = psi_stripe_request($secret, $params, $idempotencyKey);

    $clientSecret    = (string)($intent['client_secret'] ?? '');
    $paymentIntentId = (string)($intent['id'] ?? '');

    if ($clientSecret === '' || $paymentIntentId === '') {
        throw new RuntimeException('Stripe no devolvió client_secret o PaymentIntent ID.');
    }

    $availablePlans = $intent['payment_method_options']['card']['installments']['available_plans'] ?? [];

    psi_json([
        'ok'               => true,
        'client_secret'    => $clientSecret,
        'payment_intent_id'=> $paymentIntentId,
        'amount'           => $amountCents,
        'amount_mxn'       => $montoOrden,
        'currency'         => $currency,
        'available_plans'  => $availablePlans,
    ]);
} catch (Throwable $e) {
    psi_json([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}