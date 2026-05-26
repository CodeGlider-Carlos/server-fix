<?php
/*
ez/pats/endpoints/listar_regiones.php
*/
require_once __DIR__ . '/bootstrap.php';

if (!in_array($PATS_ROLE, $PATS_ADMIN_ROLES, true)) {
  pats_json(['ok' => false, 'error' => 'Acceso denegado'], 403);
}

$f = pats_filters();
$where = pats_scope_where($f, 'f');

$items = pats_all($cx, "
  SELECT
    f.region,
    COUNT(DISTINCT f.id_franquicia) AS total_franquicias,
    (
      SELECT COUNT(*) FROM pats_distribuidores d
      WHERE d.region = f.region AND d.activo = 1
    ) AS total_distribuidores,
    (
      SELECT COUNT(*) FROM pats_pasaportes p
      WHERE p.region = f.region AND p.estatus='activo' AND p.activo = 1
    ) AS total_pats_activos
  FROM pats_franquicias f
  WHERE {$where} AND f.activo = 1
  GROUP BY f.region
  ORDER BY f.region ASC
");

pats_json(['ok' => true, 'items' => $items]);