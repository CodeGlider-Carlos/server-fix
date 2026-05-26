<?php
/*
ez/pats/endpoints/listar_distribuidores.php
*/
require_once __DIR__ . '/bootstrap.php';

$f = pats_filters();
$where = pats_scope_where($f, 'd');

if ($f['id_franquicia'] > 0) {
  $where .= " AND d.id_franquicia=" . (int)$f['id_franquicia'];
}

$items = pats_all($cx, "
  SELECT *
  FROM pats_distribuidores d
  WHERE {$where} AND d.activo = 1
  ORDER BY d.nombre ASC
");

pats_json(['ok' => true, 'items' => $items]);