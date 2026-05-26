<?php
/*
ez/pats/endpoints/listar_vencidos.php
*/
require_once __DIR__ . '/bootstrap.php';

$f = pats_filters();
$where = pats_scope_where($f, 'p') . " AND p.estatus='vencido'";

if ($f['id_distribuidor'] > 0) {
  $where .= " AND p.id_distribuidor=" . (int)$f['id_distribuidor'];
}

if ($f['q'] !== '') {
  $q = addslashes($f['q']);
  $where .= " AND (
    CONCAT(p.nombres,' ',p.apellido_pa,' ',p.apellido_ma) LIKE '%{$q}%'
    OR p.nombre_empresa LIKE '%{$q}%'
  )";
}

$items = pats_all($cx, "
  SELECT
    p.id_pasaporte,
    CONCAT(p.nombres,' ',p.apellido_pa,' ',p.apellido_ma) AS nombre_completo,
    p.nombre_empresa,
    p.vigencia,
    p.estatus,
    p.valor_final_pasaporte,
    p.telefono,
    p.correo,
    p.meses_vencidos,
    p.recargo_acumulado
  FROM pats_pasaportes p
  WHERE {$where} AND p.activo = 1
  ORDER BY p.vigencia ASC
  LIMIT 200
");

pats_json(['ok' => true, 'items' => $items]);