<?php
/*
ez/pats/endpoints/mis_solicitudes_distribuidor_listar.php
*/
require_once __DIR__ . '/bootstrap.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
  pats_json(['ok' => false, 'error' => 'Método no permitido'], 405);
}

function msd_clean($v): string {
  return trim((string)($v ?? ''));
}

if (empty($_SESSION['usuario'])) {
  pats_json(['ok' => false, 'error' => 'Sesión inválida'], 403);
}

$idFranquicia = (int)($_GET['id_franquicia'] ?? 0);
$q = msd_clean($_GET['q'] ?? '');
$limit = (int)($_GET['limit'] ?? 50);
if ($limit <= 0) $limit = 50;
if ($limit > 200) $limit = 200;

$where = ["s.activo = 1"];

if ($idFranquicia > 0) {
  $where[] = "s.id_franquicia = {$idFranquicia}";
} else {
  $userRegion = trim($_SESSION['acroregion'] ?? ($_SESSION['region'] ?? ''));
  $userUnidad = trim($_SESSION['acronu'] ?? ($_SESSION['unidad'] ?? ''));

  if ($userRegion !== '') {
    $where[] = "s.region = '" . $cx->real_escape_string($userRegion) . "'";
  }
  if ($userUnidad !== '') {
    $where[] = "s.unidad = '" . $cx->real_escape_string($userUnidad) . "'";
  }
}

if ($q !== '') {
  $qEsc = $cx->real_escape_string($q);
  $where[] = "(
    s.nombre LIKE '%{$qEsc}%'
    OR s.correo LIKE '%{$qEsc}%'
    OR s.telefono LIKE '%{$qEsc}%'
    OR f.nombre_franquicia LIKE '%{$qEsc}%'
  )";
}

$sqlWhere = implode(' AND ', $where);

$rows = pats_all($cx, "
  SELECT
    s.id_solicitud,
    s.id_franquicia,
    s.id_distribuidor_generado,
    s.nombre,
    s.razon_social,
    s.rfc,
    s.telefono,
    s.correo,
    s.direccion,
    s.region,
    s.zona,
    s.unidad,
    s.contrato_admin_path,
    s.contrato_firmado_path,
    s.estatus,
    s.motivo_rechazo,
    s.observaciones_admin,
    s.observaciones_franquicia,
    s.fecha_envio_contrato,
    s.fecha_carga_firmado,
    s.fecha_autorizacion,
    s.fecha_conversion_alta,
    s.created_at,
    s.updated_at,
    f.nombre_franquicia
  FROM pats_solicitudes_distribuidor s
  LEFT JOIN pats_franquicias f
    ON f.id_franquicia = s.id_franquicia
  WHERE {$sqlWhere}
  ORDER BY s.id_solicitud DESC
  LIMIT {$limit}
");

pats_json([
  'ok' => true,
  'items' => $rows
]);