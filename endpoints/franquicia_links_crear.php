<?php
/*
 * ez/pats/endpoints/franquicia_links_crear.php
 * POST — crea un link de franquicia en userpats via API.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../lib/dist_links_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit;
}

$prefillFields = [
    'nombre', 'apellido_paterno', 'apellido_materno',
    'correo', 'telefono', 'rfc', 'tipo_persona', 'razon_social',
    'nacionalidad', 'ocupacion',
    'pais', 'region', 'municipio',
    'calle', 'num_ext', 'num_int', 'cp', 'colonia',
    'banco', 'clabe', 'titular_cuenta',
];

$data = ['password' => trim($_POST['password'] ?? '')];
if ($data['password'] === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'El campo password es obligatorio.']);
    exit;
}

foreach ($prefillFields as $field) {
    $val = trim($_POST["prefill_{$field}"] ?? '');
    if ($val !== '') $data["prefill_{$field}"] = $val;
}

$result = userpats_api('POST', '/franquicia-links', $data);

http_response_code($result['_http_code'] ?? 201);
unset($result['_http_code']);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
