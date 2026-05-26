<?php
/*
ez/pats/endpoints/listar_franquicias.php
*/
require_once __DIR__ . '/bootstrap.php';

$f = pats_filters();
$where = pats_scope_where($f, 'f');

if ($f['region'] !== '') {
  $where .= " AND f.region='" . addslashes($f['region']) . "'";
}

$items = pats_all($cx, "
  SELECT *
  FROM pats_franquicias f
  WHERE {$where} AND f.activo = 1
  ORDER BY f.nombre_franquicia ASC
");

pats_json(['ok' => true, 'items' => $items]);