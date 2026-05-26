<?php
/*
ez/pats/endpoints/solicitud_documento_ver.php
Visualiza documentos de solicitud y contratos relacionados.
*/
require_once __DIR__ . '/bootstrap.php';

if (empty($_SESSION['usuario'])) {
  http_response_code(403);
  exit('Sesión inválida');
}

function sdoc_clean($v): string {
  return trim((string)($v ?? ''));
}

$idSolicitud = (int)($_GET['id_solicitud'] ?? 0);
$tipo = strtoupper(sdoc_clean($_GET['tipo'] ?? ''));

if ($idSolicitud <= 0 || $tipo === '') {
  http_response_code(422);
  exit('Parámetros inválidos');
}

$permitidos = [
  'INE',
  'CURP',
  'COMPROBANTE_DOMICILIO',
  'CEDULA_FISCAL',
  'CARATULA_BANCARIA',
  'CONTRATO_ADMIN',
  'CONTRATO_FIRMADO'
];

if (!in_array($tipo, $permitidos, true)) {
  http_response_code(422);
  exit('Tipo no permitido');
}

$solicitud = pats_one($cx, "
  SELECT
    id_solicitud,
    id_franquicia,
    contrato_admin_path,
    contrato_firmado_path,
    activo
  FROM pats_solicitudes_distribuidor
  WHERE id_solicitud = {$idSolicitud}
    AND activo = 1
  LIMIT 1
");

if (!$solicitud) {
  http_response_code(404);
  exit('Solicitud no encontrada');
}

$path = '';

if ($tipo === 'CONTRATO_ADMIN') {
  $path = (string)($solicitud['contrato_admin_path'] ?? '');
} elseif ($tipo === 'CONTRATO_FIRMADO') {
  $path = (string)($solicitud['contrato_firmado_path'] ?? '');
} else {
  $tipoEsc = $cx->real_escape_string($tipo);

  $doc = pats_one($cx, "
    SELECT
      archivo_path,
      mime_type,
      archivo_nombre_original
    FROM pats_solicitudes_distribuidor_documentos
    WHERE id_solicitud = {$idSolicitud}
      AND tipo_documento = '{$tipoEsc}'
      AND vigente = 1
    ORDER BY id_documento_solicitud DESC
    LIMIT 1
  ");

  if ($doc) {
    $path = (string)($doc['archivo_path'] ?? '');
  }
}

if ($path === '' || !is_file($path)) {
  http_response_code(404);
  exit('Archivo no encontrado');
}

$ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
$mime = 'application/octet-stream';

$mimeMap = [
  'pdf'  => 'application/pdf',
  'jpg'  => 'image/jpeg',
  'jpeg' => 'image/jpeg',
  'png'  => 'image/png',
  'webp' => 'image/webp'
];

if (isset($mimeMap[$ext])) {
  $mime = $mimeMap[$ext];
} else {
  $finfo = @finfo_open(FILEINFO_MIME_TYPE);
  if ($finfo) {
    $detected = @finfo_file($finfo, $path);
    if (is_string($detected) && $detected !== '') {
      $mime = $detected;
    }
    @finfo_close($finfo);
  }
}

$filename = basename($path);
$inline = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true);

if (ob_get_length()) {
  ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . addslashes($filename) . '"');

readfile($path);
exit;