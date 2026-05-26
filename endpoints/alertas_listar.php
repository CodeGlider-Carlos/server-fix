<?php
/*
ez/pats/endpoints/alertas_listar.php
*/
require_once __DIR__ . '/bootstrap.php';

$items = pats_all($cx, "
  SELECT *
  FROM pats_alertas
  WHERE visto = 0
  ORDER BY fecha_alerta DESC
  LIMIT 50
");

pats_json(['ok' => true, 'items' => $items]);