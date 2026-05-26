<?php
/*
 * ez/pats/endpoints/distribucion_links_crear.php
 * POST — crea un link de distribucion en userpats via API.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../lib/dist_links_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit;
}

$campos = [
    'password', 'amount', 'type_pay',
    'prefill_nombre', 'prefill_apellido_paterno', 'prefill_apellido_materno',
    'prefill_correo', 'prefill_telefono', 'prefill_rfc', 'prefill_tipo_persona',
    'prefill_razon_social', 'prefill_nacionalidad', 'prefill_ocupacion',
    'prefill_pais', 'prefill_region', 'prefill_municipio',
    'prefill_calle', 'prefill_num_ext', 'prefill_num_int', 'prefill_cp', 'prefill_colonia',
    'prefill_banco', 'prefill_clabe', 'prefill_titular_cuenta',
    'prefill_tipo_identificacion', 'prefill_identificacion_emitida_por', 'prefill_numero_identificacion',
];

$data = [];
foreach ($campos as $campo) {
    if (isset($_POST[$campo]) && $_POST[$campo] !== '') {
        $data[$campo] = trim((string) $_POST[$campo]);
    }
}

$result = userpats_api('POST', '/distribucion-links', $data);

http_response_code($result['_http_code'] ?? 200);
unset($result['_http_code']);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
