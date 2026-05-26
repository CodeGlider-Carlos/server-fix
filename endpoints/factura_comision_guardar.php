<?php
/*
ez/pats/endpoints/factura_comision_guardar.php
*/
session_start();

require_once '../../../varSQL/bd_pats.php';
require_once '../../../varSQL/var_pats.php';

header('Content-Type: application/json; charset=utf-8');

function jout(array $data, int $code = 200): void {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function jerr(string $msg, int $code = 422): void {
  jout(['ok' => false, 'error' => $msg], $code);
}

if (empty($_SESSION['usuario'])) {
  jerr('Sesión no válida.', 401);
}

$adminrol = strtoupper(trim($_SESSION['rol'] ?? ''));
$diaMes   = (int)date('j');
$puedeEnviarFactura = ($diaMes <= 5) || in_array($adminrol, ['ADMIN', 'ADMINPATS'], true);

if (!$puedeEnviarFactura) {
  jerr('El periodo para enviar factura ya cerró.', 403);
}

$tipoActor      = strtoupper(trim($_POST['tipo_actor'] ?? ''));
$idActor        = (int)($_POST['id_actor'] ?? 0);
$saldoReportado = round((float)($_POST['saldo_reportado'] ?? 0), 2);
$folioFactura   = trim($_POST['folio_factura'] ?? '');
$observaciones  = trim($_POST['observaciones'] ?? '');

if (!in_array($tipoActor, ['FRANQUICIATARIO', 'DISTRIBUIDOR', 'GESTOR'], true)) {
  jerr('Tipo de actor inválido.');
}

if ($idActor <= 0) {
  jerr('Actor inválido.');
}

if ($saldoReportado < 0) {
  jerr('Saldo reportado inválido.');
}

if (
  !isset($_FILES['factura_pdf']) ||
  !is_array($_FILES['factura_pdf']) ||
  empty($_FILES['factura_pdf']['name'])
) {
  jerr('Debes seleccionar un PDF.');
}

$archivo = $_FILES['factura_pdf'];

if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  jerr('No fue posible cargar el archivo.');
}

$tmpPath = (string)($archivo['tmp_name'] ?? '');
if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
  jerr('Archivo temporal inválido.');
}

$ext  = strtolower(pathinfo((string)$archivo['name'], PATHINFO_EXTENSION));
$mime = function_exists('mime_content_type') ? (string)(mime_content_type($tmpPath) ?: '') : '';

if ($ext !== 'pdf') {
  jerr('Solo se permiten archivos PDF.');
}

if ($mime !== '' && stripos($mime, 'pdf') === false) {
  jerr('El archivo no parece ser un PDF válido.');
}

$maxBytes = 10 * 1024 * 1024; // 10 MB
$size = (int)($archivo['size'] ?? 0);
if ($size <= 0) {
  jerr('El archivo está vacío.');
}
if ($size > $maxBytes) {
  jerr('El PDF excede el límite de 10 MB.');
}

/*
  Validación de actor existente
*/
$tablaActor = '';
$colIdActor = '';

if ($tipoActor === 'FRANQUICIATARIO') {
  $tablaActor = 'pats_franquicias';
  $colIdActor = 'id_franquicia';
} elseif ($tipoActor === 'DISTRIBUIDOR') {
  $tablaActor = 'pats_distribuidores';
  $colIdActor = 'id_distribuidor';
} else {
  $tablaActor = 'pats_gestores';
  $colIdActor = 'id_gestor';
}

$sqlActor = "SELECT {$colIdActor} AS id_actor FROM {$tablaActor} WHERE {$colIdActor} = ? LIMIT 1";
$stmtActor = $conexion->prepare($sqlActor);
if (!$stmtActor) {
  jerr('No fue posible validar el actor.', 500);
}
$stmtActor->bind_param('i', $idActor);
$stmtActor->execute();
$rsActor = $stmtActor->get_result();
$actorExiste = $rsActor ? $rsActor->fetch_assoc() : null;
$stmtActor->close();

if (!$actorExiste) {
  jerr('El actor indicado no existe.', 404);
}

/*
  Datos de periodo actual
*/
$anio = (int)date('Y');
$mes  = (int)date('n');

/*
  Directorio físico
  Queda en /uploads/pats/facturas_comision/YYYY/MM/
*/
$mesTxt   = str_pad((string)$mes, 2, '0', STR_PAD_LEFT);
$dirRel   = "uploads/pats/facturas_comision/{$anio}/{$mesTxt}/";
$rootPath = dirname(__DIR__, 3);
$dirAbs   = $rootPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dirRel);

if (!is_dir($dirAbs)) {
  if (!mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
    jerr('No fue posible crear el directorio de destino.', 500);
  }
}

/*
  Nombre seguro
*/
$baseOriginal = pathinfo((string)$archivo['name'], PATHINFO_FILENAME);
$baseOriginal = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$baseOriginal);
$baseOriginal = trim((string)$baseOriginal, '_');
if ($baseOriginal === '') {
  $baseOriginal = 'factura';
}

$nombreFinal = strtolower($tipoActor)
  . '_' . $idActor
  . '_' . $anio
  . '_' . $mesTxt
  . '_' . date('His')
  . '_' . bin2hex(random_bytes(4))
  . '.pdf';

$rutaAbs = $dirAbs . DIRECTORY_SEPARATOR . $nombreFinal;
$archivoUrl = '../../../' . $dirRel . $nombreFinal;

/*
  Si ya existe factura del mismo actor y periodo, recuperamos archivo previo
  para poder borrarlo después de una actualización exitosa.
*/
$sqlPrev = "
  SELECT id_factura, archivo_url
  FROM pats_facturas_comision
  WHERE tipo_actor = ?
    AND id_actor = ?
    AND periodo_anio = ?
    AND periodo_mes = ?
  LIMIT 1
";
$stmtPrev = $conexion->prepare($sqlPrev);
if (!$stmtPrev) {
  jerr('No fue posible revisar factura previa.', 500);
}
$stmtPrev->bind_param('siii', $tipoActor, $idActor, $anio, $mes);
$stmtPrev->execute();
$rsPrev = $stmtPrev->get_result();
$prev = $rsPrev ? $rsPrev->fetch_assoc() : null;
$stmtPrev->close();

if (!move_uploaded_file($tmpPath, $rutaAbs)) {
  jerr('No fue posible guardar el PDF.', 500);
}

$userLog = trim($_SESSION['usuario'] ?? '');

$sql = "
  INSERT INTO pats_facturas_comision
  (
    tipo_actor,
    id_actor,
    periodo_anio,
    periodo_mes,
    saldo_reportado,
    folio_factura,
    nombre_archivo,
    archivo_url,
    mime_type,
    tamano_bytes,
    observaciones,
    estatus,
    cargado_por,
    fecha_carga,
    created_at,
    updated_at
  )
  VALUES
  (
    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'CARGADA', ?, NOW(), NOW(), NOW()
  )
  ON DUPLICATE KEY UPDATE
    saldo_reportado = VALUES(saldo_reportado),
    folio_factura   = VALUES(folio_factura),
    nombre_archivo  = VALUES(nombre_archivo),
    archivo_url     = VALUES(archivo_url),
    mime_type       = VALUES(mime_type),
    tamano_bytes    = VALUES(tamano_bytes),
    observaciones   = VALUES(observaciones),
    estatus         = 'CARGADA',
    cargado_por     = VALUES(cargado_por),
    revisado_por    = NULL,
    fecha_revision  = NULL,
    fecha_carga     = NOW(),
    updated_at      = NOW()
";

$stmt = $conexion->prepare($sql);
if (!$stmt) {
  @unlink($rutaAbs);
  jerr('No fue posible preparar el guardado.', 500);
}

$nombreOriginal = (string)$archivo['name'];

$stmt->bind_param(
  'siiidssssiss',
  $tipoActor,
  $idActor,
  $anio,
  $mes,
  $saldoReportado,
  $folioFactura,
  $nombreOriginal,
  $archivoUrl,
  $mime,
  $size,
  $observaciones,
  $userLog
);

if (!$stmt->execute()) {
  $error = $stmt->error;
  $stmt->close();
  @unlink($rutaAbs);
  jerr('No fue posible guardar la factura: ' . $error, 500);
}

$stmt->close();

/*
  Si había una factura previa distinta, intentamos borrar su archivo físico
*/
if (!empty($prev['archivo_url']) && $prev['archivo_url'] !== $archivoUrl) {
  $prevUrl = (string)$prev['archivo_url'];
  $prevAbs = realpath($rootPath . DIRECTORY_SEPARATOR . str_replace(['../', '/'], ['', DIRECTORY_SEPARATOR], $prevUrl));
  if ($prevAbs && is_file($prevAbs)) {
    @unlink($prevAbs);
  }
}

jout([
  'ok' => true,
  'message' => 'Factura cargada correctamente.',
  'tipo_actor' => $tipoActor,
  'id_actor' => $idActor,
  'periodo_anio' => $anio,
  'periodo_mes' => $mes,
  'archivo_url' => $archivoUrl
]);