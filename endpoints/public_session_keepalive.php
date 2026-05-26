<?php
/*
ez/pats/endpoints/public_session_keepalive.php

Mantiene viva la sesión pública del formulario PATS.
No guarda datos, no crea pagos, no modifica pasaportes.
Solo renueva actividad de sesión y confirma CSRF.
*/

declare(strict_types=1);

$PATS_PUBLIC_SESSION_TTL = 7200;

ini_set('session.gc_maxlifetime', (string)$PATS_PUBLIC_SESSION_TTL);
ini_set('session.cookie_lifetime', (string)$PATS_PUBLIC_SESSION_TTL);

session_set_cookie_params([
    'lifetime' => $PATS_PUBLIC_SESSION_TTL,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

function out_json(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    out_json(['ok' => false, 'error' => 'Método no permitido.'], 405);
}

$csrfSesion = trim((string)($_SESSION['_csrf_pats_publico'] ?? ''));
$csrfHeader = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
$csrfPost   = trim((string)($_POST['_csrf'] ?? ''));

$csrf = $csrfHeader !== '' ? $csrfHeader : $csrfPost;

if ($csrfSesion === '' || $csrf === '' || !hash_equals($csrfSesion, $csrf)) {
    out_json([
        'ok' => false,
        'expired' => true,
        'error' => 'La sesión expiró.'
    ], 419);
}

$_SESSION['_pats_public_last_activity'] = time();

out_json([
    'ok' => true,
    'expired' => false,
    'server_time' => date('c'),
]);