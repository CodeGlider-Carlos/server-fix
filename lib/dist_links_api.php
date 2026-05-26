<?php
/*
 * ez/pats/lib/dist_links_api.php
 * Helper — llama a la API de userpats vía cURL.
 */

if (! defined('USERPATS_API_URL')) {
    define('USERPATS_API_URL', 'https://admin-pats.50d.com.mx/api');
}
if (! defined('USERPATS_API_KEY')) {
    define('USERPATS_API_KEY', 'wYcDQFj3YfDN7sxySGtoZ6eKj2jz8NOmXxylhWXP1qGKUSOE');
}

function userpats_api(string $method, string $path, array $data = [], array $query = []): array
{
    $url = rtrim(USERPATS_API_URL, '/') . $path;
    if ($query) $url .= '?' . http_build_query($query);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'X-Api-Key: ' . USERPATS_API_KEY,
            'Accept: application/json',
        ],
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }

    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'error' => 'cURL error: ' . $curlErr, '_http_code' => 0];
    }

    $decoded = json_decode($body, true);
    if (! is_array($decoded)) {
        return ['ok' => false, 'error' => 'Respuesta inválida del servidor.', '_http_code' => $httpCode];
    }

    $decoded['_http_code'] = $httpCode;
    return $decoded;
}
