<?php
/*
ez/pats/endpoints/public_preupload.php

Carga progresiva de archivos PATS.

Permite al frontend subir documentos paso a paso mientras el usuario
avanza en el formulario (antes del paso final de pago), reduciendo el
tiempo de espera al momento del submit.

Seguridad:
  - Valida CSRF contra $_SESSION['_csrf_pats_publico']
  - Solo acepta campos de documento permitidos
  - Solo acepta extensiones: pdf, png, jpg, jpeg, webp
  - Almacena la ruta en sesión (no en POST) para que el backend la use

Flujo:
  1. JS (solicitud_pats.php) llama este endpoint cuando el usuario avanza del Paso 4 → 5.
  2. Este endpoint guarda el archivo en uploads/public_checkout/YYYY/MM/
  3. Guarda la ruta en $_SESSION['pats_preloads'][csrf][campo]
  4. Retorna { ok: true, field: '...', path: '...', size: ... }
  5. public_checkout_generar_orden.php llama ppg_resolve_file() que revisa la sesión
     antes de intentar leer $_FILES[campo].
*/

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

function pu_json(array $d, int $code = 200): void {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* Método */
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    pu_json(['ok' => false, 'error' => 'Método no permitido'], 405);
}

/* CSRF */
$csrfHeader = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
$csrfPost   = trim((string)($_POST['csrf'] ?? ''));
$csrfSent   = $csrfHeader !== '' ? $csrfHeader : $csrfPost;
$csrfSesion = (string)($_SESSION['_csrf_pats_publico'] ?? '');

if ($csrfSent === '' || $csrfSesion === '' || !hash_equals($csrfSesion, $csrfSent)) {
    pu_json(['ok' => false, 'error' => 'Token de seguridad inválido'], 403);
}

/* Campo destino */
$fieldName = trim((string)($_POST['field'] ?? ''));

$allowedFields = [
    'doc_identificacion_frente',
    'doc_identificacion_reverso',
    'doc_curp',
    'doc_comprobante_domicilio',
    'doc_constancia_fiscal',
    'tutor_doc_identificacion_frente',
    'tutor_doc_identificacion_reverso',
    'tutor_doc_curp',
    'tutor_doc_constancia_fiscal',
    'doc_acreditacion_representacion',
];

if (!in_array($fieldName, $allowedFields, true)) {
    pu_json(['ok' => false, 'error' => 'Campo no permitido'], 422);
}

/* Archivo */
$file = $_FILES['file'] ?? null;
if (!$file || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
    pu_json(['ok' => false, 'error' => 'No se recibió ningún archivo'], 422);
}

if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'El archivo supera el límite del servidor.',
        UPLOAD_ERR_FORM_SIZE  => 'El archivo supera el límite del formulario.',
        UPLOAD_ERR_PARTIAL    => 'El archivo se subió parcialmente.',
        UPLOAD_ERR_NO_FILE    => 'No se recibió archivo.',
        UPLOAD_ERR_NO_TMP_DIR => 'Falta directorio temporal en el servidor.',
        UPLOAD_ERR_CANT_WRITE => 'No se pudo escribir el archivo.',
        UPLOAD_ERR_EXTENSION  => 'Una extensión de PHP bloqueó la subida.',
    ];
    $errCode = (int)($file['error'] ?? 0);
    pu_json(['ok' => false, 'error' => $uploadErrors[$errCode] ?? 'Error al subir archivo.'], 422);
}

/* Extensión */
$allowed = ['pdf', 'png', 'jpg', 'jpeg', 'webp'];
$orig    = (string)($file['name'] ?? 'archivo');
$ext     = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
$safeExt = preg_replace('/[^a-z0-9]/i', '', $ext);
if ($safeExt === '') $safeExt = 'bin';

if (!in_array($safeExt, $allowed, true)) {
    pu_json(['ok' => false, 'error' => 'Formato no permitido. Sube PDF, PNG, JPG o WEBP.'], 422);
}

/* Directorio destino */
$relDir  = 'uploads/public_checkout/' . date('Y/m');
$baseDir = dirname(__DIR__) . '/' . $relDir;

if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
    pu_json(['ok' => false, 'error' => 'No fue posible crear directorio de almacenamiento.'], 500);
}

/* Prefijo legible para el nombre de archivo */
$prefixMap = [
    'doc_identificacion_frente'      => 'paciente_identificacion_frente',
    'doc_identificacion_reverso'     => 'paciente_identificacion_reverso',
    'doc_curp'                       => 'paciente_curp',
    'doc_comprobante_domicilio'      => 'paciente_comprobante_domicilio',
    'doc_constancia_fiscal'          => 'paciente_constancia_fiscal',
    'tutor_doc_identificacion_frente'=> 'responsable_identificacion_frente',
    'tutor_doc_identificacion_reverso'=> 'responsable_identificacion_reverso',
    'tutor_doc_curp'                 => 'responsable_curp',
    'tutor_doc_constancia_fiscal'    => 'responsable_constancia_fiscal',
    'doc_acreditacion_representacion'=> 'acreditacion_representacion',
];
$prefix   = $prefixMap[$fieldName] ?? $fieldName;
$filename = $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $safeExt;
$target   = $baseDir . '/' . $filename;
$relPath  = $relDir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $target)) {
    pu_json(['ok' => false, 'error' => 'No fue posible guardar el archivo en el servidor.'], 500);
}

/* Guardar ruta en sesión bajo la clave CSRF */
if (!isset($_SESSION['pats_preloads'])) {
    $_SESSION['pats_preloads'] = [];
}
if (!isset($_SESSION['pats_preloads'][$csrfSesion])) {
    $_SESSION['pats_preloads'][$csrfSesion] = [];
}
$_SESSION['pats_preloads'][$csrfSesion][$fieldName] = [
    'path'           => $relPath,
    'name'           => $orig,
    'size'           => (int)($file['size'] ?? 0),
    'type'           => (string)($file['type'] ?? 'application/octet-stream'),
    'extension'      => $safeExt,
    'relative_path'  => $relPath,
];

pu_json([
    'ok'    => true,
    'field' => $fieldName,
    'path'  => $relPath,
    'size'  => (int)($file['size'] ?? 0),
]);
