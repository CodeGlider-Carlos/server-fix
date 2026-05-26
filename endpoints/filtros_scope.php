<?php
/*
ez/pats/endpoints/filtros_scope.php
*/
require_once __DIR__ . '/bootstrap.php';

function pats_distinct_values(mysqli $cx, string $table, string $field, string $where = '1=1'): array {
  $items = [];
  $sql = "SELECT DISTINCT {$field} AS valor FROM {$table} WHERE {$where} AND {$field} IS NOT NULL AND {$field} <> '' ORDER BY {$field} ASC";
  $rs = $cx->query($sql);
  if (!$rs) return $items;
  while ($row = $rs->fetch_assoc()) {
    $items[] = [
      'value' => (string)($row['valor'] ?? ''),
      'label' => (string)($row['valor'] ?? '')
    ];
  }
  return $items;
}

$f = pats_filters();

$whereFranq = ['1=1'];
$whereDist  = ['1=1'];
$wherePats  = ['1=1'];

if ($PATS_ROLE === 'FRANQ') {
  if ($PATS_REGION !== '') {
    $whereFranq[] = "region='" . addslashes($PATS_REGION) . "'";
    $whereDist[]  = "region='" . addslashes($PATS_REGION) . "'";
    $wherePats[]  = "region='" . addslashes($PATS_REGION) . "'";
  }
  if ($PATS_UNIDAD !== '') {
    $whereFranq[] = "unidad='" . addslashes($PATS_UNIDAD) . "'";
    $whereDist[]  = "unidad='" . addslashes($PATS_UNIDAD) . "'";
    $wherePats[]  = "unidad='" . addslashes($PATS_UNIDAD) . "'";
  }
}

if ($PATS_ROLE === 'DIST') {
  if ($PATS_REGION !== '') {
    $whereDist[] = "region='" . addslashes($PATS_REGION) . "'";
    $wherePats[] = "region='" . addslashes($PATS_REGION) . "'";
  }
  if ($PATS_UNIDAD !== '') {
    $whereDist[] = "unidad='" . addslashes($PATS_UNIDAD) . "'";
    $wherePats[] = "unidad='" . addslashes($PATS_UNIDAD) . "'";
  }
}

$paises = pats_distinct_values($cx, 'pats_franquicias', 'pais', implode(' AND ', $whereFranq));

$regionWhere = $whereFranq;
if ($f['pais'] !== '') {
  $regionWhere[] = "pais='" . addslashes($f['pais']) . "'";
}
$regiones = pats_distinct_values($cx, 'pats_franquicias', 'region', implode(' AND ', $regionWhere));

$zonaWhere = $whereFranq;
if ($f['pais'] !== '') {
  $zonaWhere[] = "pais='" . addslashes($f['pais']) . "'";
}
if ($f['region'] !== '') {
  $zonaWhere[] = "region='" . addslashes($f['region']) . "'";
}
$zonas = pats_distinct_values($cx, 'pats_franquicias', 'zona', implode(' AND ', $zonaWhere));

$unidadWhere = $whereFranq;
if ($f['pais'] !== '') {
  $unidadWhere[] = "pais='" . addslashes($f['pais']) . "'";
}
if ($f['region'] !== '') {
  $unidadWhere[] = "region='" . addslashes($f['region']) . "'";
}
$unidades = pats_distinct_values($cx, 'pats_franquicias', 'unidad', implode(' AND ', $unidadWhere));

pats_json([
  'ok' => true,
  'scope' => [
    'rol' => $PATS_ROLE,
    'region' => $PATS_REGION,
    'unidad' => $PATS_UNIDAD
  ],
  'options' => [
    'paises' => $paises,
    'regiones' => $regiones,
    'zonas' => $zonas,
    'unidades' => $unidades
  ]
]);