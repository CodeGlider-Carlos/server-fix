<?php
/*
ez/pats/endpoints/dashboard_franquicia.php

Dashboard operativo de franquicia PATS.
Corrección aplicada:
- La venta/alta de distribución asociada a la franquicia NO se carga como venta del distribuidor.
- La card/canal "Venta directa de franquicia" concentra:
  a) la venta de distribución asociada a la franquicia,
  b) la comisión de franquicia por distribución,
  c) los PATS directos de la franquicia sin distribuidor,
  d) la comisión PATS directa de franquicia.
- Las cards de distribuidores solo muestran PATS realmente vendidos por ese distribuidor.
*/
require_once __DIR__ . '/bootstrap.php';

$f = pats_filters();
$idFranquicia = (int)$f['id_franquicia'];

/* =========================================================
   RESOLUCIÓN SEGÚN ROL / ACTOR
========================================================= */
if (!in_array($PATS_ROLE, $PATS_ADMIN_ROLES, true)) {
  if (($PATS_ACTOR['rolapp'] ?? '') === 'FRANQPATS' && (int)($PATS_ACTOR['id_franquicia'] ?? 0) > 0) {
    $idFranquicia = (int)$PATS_ACTOR['id_franquicia'];
  }
}

if ($idFranquicia <= 0 && in_array($PATS_ROLE, $PATS_ADMIN_ROLES, true) && $f['region'] !== '') {
  $tmp = pats_one($cx, "
    SELECT id_franquicia
    FROM pats_franquicias
    WHERE activo = 1
      AND region = '" . addslashes($f['region']) . "'
      " . ($f['zona'] !== '' ? "AND zona = '" . addslashes($f['zona']) . "'" : "") . "
    ORDER BY id_franquicia ASC
    LIMIT 1
  ");
  $idFranquicia = (int)($tmp['id_franquicia'] ?? 0);
}

if ($idFranquicia <= 0) {
  pats_json(['ok'=>true,'franquicia'=>[],'kpis'=>[],'distribuidores'=>[],'charts'=>[],'rankings'=>[]]);
}

$franquicia = pats_one($cx, "
  SELECT *
  FROM pats_franquicias
  WHERE id_franquicia = {$idFranquicia}
    AND activo = 1
  LIMIT 1
");

if (!$franquicia) {
  pats_json(['ok'=>true,'franquicia'=>[],'kpis'=>[],'distribuidores'=>[],'charts'=>[],'rankings'=>[]]);
}

/* =========================================================
   REGLAS GLOBALES
========================================================= */
$splitAnual = pats_pats_split($cx, 'anual');
$splitMensual = pats_pats_split($cx, 'mensual');

$franqAnual = (float)($splitAnual['franquicia'] ?? 240);
$franqMensual = (float)($splitMensual['franquicia'] ?? 20);
$distAnual = (float)($splitAnual['distribuidor'] ?? 960);
$distMensual = (float)($splitMensual['distribuidor'] ?? 80);

/* =========================================================
   FILTROS DE PERIODO
   Para PATS de franquicia NO filtramos por región/zona, porque
   los PATS directos pueden traer esos campos vacíos.
========================================================= */
$wherePats = [
  "p.id_franquicia = {$idFranquicia}",
  "p.activo = 1"
];
if ($f['anio'] > 0) $wherePats[] = "YEAR(p.created_at) = " . (int)$f['anio'];
if ($f['mes'] > 0)  $wherePats[] = "MONTH(p.created_at) = " . (int)$f['mes'];
$wherePatsSql = implode(' AND ', $wherePats);

$fin = pats_metricas_financieras($cx, $wherePatsSql, 'p');

/* Comisión PATS total correcta para franquicia */
$rowComisionPatsFranquicia = pats_one($cx, "
  SELECT
    COALESCE(SUM(
      CASE
        WHEN LOWER(TRIM(COALESCE(p.estatus,''))) = 'activo'
         AND LOWER(TRIM(COALESCE(p.frecuencia_pago,''))) = 'anual'
         AND COALESCE(p.id_distribuidor,0) > 0
          THEN {$franqAnual}
        WHEN LOWER(TRIM(COALESCE(p.estatus,''))) = 'activo'
         AND LOWER(TRIM(COALESCE(p.frecuencia_pago,''))) = 'mensual'
         AND COALESCE(p.id_distribuidor,0) > 0
          THEN {$franqMensual}
        WHEN LOWER(TRIM(COALESCE(p.estatus,''))) = 'activo'
         AND LOWER(TRIM(COALESCE(p.frecuencia_pago,''))) = 'anual'
         AND COALESCE(p.id_distribuidor,0) <= 0
          THEN {$franqAnual} + {$distAnual}
        WHEN LOWER(TRIM(COALESCE(p.estatus,''))) = 'activo'
         AND COALESCE(p.id_distribuidor,0) <= 0
          THEN {$franqMensual} + {$distMensual}
        ELSE 0
      END
    ),0) AS comision_pats_franquicia
  FROM pats_pasaportes p
  WHERE {$wherePatsSql}
");
$misComisionesPats = (float)($rowComisionPatsFranquicia['comision_pats_franquicia'] ?? 0);

/* =========================================================
   Distribuciones asociadas a la franquicia
   Se muestran en la card de venta directa de franquicia, no en
   la card del distribuidor.
========================================================= */
$whereDist = [
  "d.id_franquicia = {$idFranquicia}",
  "d.activo = 1"
];
if ($f['anio'] > 0) $whereDist[] = "YEAR(d.created_at) = " . (int)$f['anio'];
if ($f['mes'] > 0)  $whereDist[] = "MONTH(d.created_at) = " . (int)$f['mes'];
$whereDistSql = implode(' AND ', $whereDist);

$rowsDistribucionesFranquicia = pats_all($cx, "
  SELECT
    d.id_distribuidor,
    d.valor_distribucion,
    COALESCE(SUM(cg.monto_comision),0) AS comision_generada
  FROM pats_distribuidores d
  LEFT JOIN pats_comisiones_generadas cg
    ON cg.tipo_origen = 'venta_distribucion'
   AND LOWER(TRIM(cg.beneficiario_tipo)) = 'franquicia'
   AND cg.beneficiario_id = d.id_franquicia
   AND cg.id_origen = d.id_distribuidor
  WHERE {$whereDistSql}
  GROUP BY d.id_distribuidor, d.valor_distribucion
  ORDER BY d.id_distribuidor ASC
");

$ventasDistribucionDashboard = 0.0;
$comisionesDistribucionDashboard = 0.0;
foreach ($rowsDistribucionesFranquicia as $rd) {
  $valorDist = (float)($rd['valor_distribucion'] ?? 0);
  $comisionGenerada = (float)($rd['comision_generada'] ?? 0);
  $comisionUsada = $comisionGenerada > 0 ? $comisionGenerada : round($valorDist * 0.50, 2);
  $ventasDistribucionDashboard += $valorDist;
  $comisionesDistribucionDashboard += $comisionUsada;
}

/* PATS directos de franquicia, sin distribuidor */
$rowDirectos = pats_one($cx, "
  SELECT
    COUNT(*) AS pats_activos,
    COALESCE(SUM(p.valor_final_pasaporte),0) AS ventas_pasaportes,
    COALESCE(SUM(
      CASE
        WHEN LOWER(TRIM(COALESCE(p.frecuencia_pago,''))) = 'anual'
          THEN {$franqAnual} + {$distAnual}
        ELSE {$franqMensual} + {$distMensual}
      END
    ),0) AS comision_pats_activos
  FROM pats_pasaportes p
  WHERE p.id_franquicia = {$idFranquicia}
    AND COALESCE(p.id_distribuidor,0) <= 0
    AND p.activo = 1
    AND LOWER(TRIM(COALESCE(p.estatus,''))) = 'activo'
    " . ($f['anio'] > 0 ? "AND YEAR(p.created_at) = " . (int)$f['anio'] : "") . "
    " . ($f['mes'] > 0 ? "AND MONTH(p.created_at) = " . (int)$f['mes'] : "") . "
");
$directPatsActivos = (int)($rowDirectos['pats_activos'] ?? 0);
$directVentasPats = (float)($rowDirectos['ventas_pasaportes'] ?? 0);
$directComisionPats = (float)($rowDirectos['comision_pats_activos'] ?? 0);

/* =========================================================
   Distribuidores reales: solo PATS vendidos por cada distribuidor.
========================================================= */
$distribuidores = pats_all($cx, "
  SELECT
    d.id_distribuidor,
    d.id_franquicia,
    d.region,
    d.zona,
    d.unidad,
    d.nombre,
    d.rfc,
    d.telefono,
    d.correo,
    d.banco,
    COALESCE(d.valor_distribucion,0) AS valor_distribucion_asociado,

    (
      SELECT COUNT(*)
      FROM pats_pasaportes p
      WHERE p.id_distribuidor = d.id_distribuidor
        AND p.id_franquicia = d.id_franquicia
        AND LOWER(TRIM(COALESCE(p.estatus,''))) = 'activo'
        AND p.activo = 1
        " . ($f['anio'] > 0 ? "AND YEAR(p.created_at) = " . (int)$f['anio'] : "") . "
        " . ($f['mes'] > 0 ? "AND MONTH(p.created_at) = " . (int)$f['mes'] : "") . "
    ) AS pats_activos,

    (
      SELECT COUNT(*)
      FROM pats_pasaportes p
      WHERE p.id_distribuidor = d.id_distribuidor
        AND p.id_franquicia = d.id_franquicia
        AND LOWER(TRIM(COALESCE(p.estatus,''))) = 'vencido'
        AND p.activo = 1
        " . ($f['anio'] > 0 ? "AND YEAR(p.created_at) = " . (int)$f['anio'] : "") . "
        " . ($f['mes'] > 0 ? "AND MONTH(p.created_at) = " . (int)$f['mes'] : "") . "
    ) AS pats_vencidos,

    (
      SELECT COALESCE(SUM(p.valor_final_pasaporte),0)
      FROM pats_pasaportes p
      WHERE p.id_distribuidor = d.id_distribuidor
        AND p.id_franquicia = d.id_franquicia
        AND p.activo = 1
        " . ($f['anio'] > 0 ? "AND YEAR(p.created_at) = " . (int)$f['anio'] : "") . "
        " . ($f['mes'] > 0 ? "AND MONTH(p.created_at) = " . (int)$f['mes'] : "") . "
    ) AS ventas_pasaportes,

    (
      SELECT COALESCE(SUM(
        CASE
          WHEN LOWER(TRIM(COALESCE(p.estatus,'')))='activo' AND LOWER(TRIM(COALESCE(p.frecuencia_pago,'')))='anual' THEN {$franqAnual}
          WHEN LOWER(TRIM(COALESCE(p.estatus,'')))='activo' THEN {$franqMensual}
          ELSE 0
        END
      ),0)
      FROM pats_pasaportes p
      WHERE p.id_distribuidor = d.id_distribuidor
        AND p.id_franquicia = d.id_franquicia
        AND p.activo = 1
        " . ($f['anio'] > 0 ? "AND YEAR(p.created_at) = " . (int)$f['anio'] : "") . "
        " . ($f['mes'] > 0 ? "AND MONTH(p.created_at) = " . (int)$f['mes'] : "") . "
    ) AS comision_por_pats_activos,

    (
      SELECT COALESCE(SUM(
        CASE
          WHEN LOWER(TRIM(COALESCE(p.estatus,'')))='vencido' AND LOWER(TRIM(COALESCE(p.frecuencia_pago,'')))='anual' THEN {$franqAnual}
          WHEN LOWER(TRIM(COALESCE(p.estatus,'')))='vencido' THEN {$franqMensual}
          ELSE 0
        END
      ),0)
      FROM pats_pasaportes p
      WHERE p.id_distribuidor = d.id_distribuidor
        AND p.id_franquicia = d.id_franquicia
        AND p.activo = 1
        " . ($f['anio'] > 0 ? "AND YEAR(p.created_at) = " . (int)$f['anio'] : "") . "
        " . ($f['mes'] > 0 ? "AND MONTH(p.created_at) = " . (int)$f['mes'] : "") . "
    ) AS comision_perdida_vencidos

  FROM pats_distribuidores d
  WHERE d.id_franquicia = {$idFranquicia}
    AND d.activo = 1
  ORDER BY d.nombre ASC
");

foreach ($distribuidores as &$dist) {
  $dist['ventas_distribucion'] = 0.0;
  $dist['ventas_pasaportes'] = (float)($dist['ventas_pasaportes'] ?? 0);
  $dist['ventas_real'] = (float)$dist['ventas_pasaportes'];
  $dist['comision_por_distribucion'] = 0.0;
  $dist['comision_por_pats_activos'] = (float)($dist['comision_por_pats_activos'] ?? 0);
  $dist['comision_total'] = (float)$dist['comision_por_pats_activos'];
  $dist['canal'] = 'DISTRIBUIDOR';
}
unset($dist);

/* Card operativa: venta directa de franquicia */
$directCard = [
  'id_distribuidor' => -$idFranquicia,
  'id_franquicia' => $idFranquicia,
  'directos_franquicia' => 1,
  'detalle_url' => 'distribuidor.php?id_distribuidor=-' . $idFranquicia . '&directos_franquicia=1&id_franquicia=' . $idFranquicia,
  'region' => (string)($franquicia['region'] ?? ''),
  'zona' => (string)($franquicia['zona'] ?? ''),
  'unidad' => (string)($franquicia['unidad'] ?? ''),
  'nombre' => 'Venta directa de franquicia',
  'rfc' => '',
  'telefono' => '',
  'correo' => '',
  'banco' => '',
  'ventas_distribucion' => (float)$ventasDistribucionDashboard,
  'ventas_pasaportes' => (float)$directVentasPats,
  'ventas_real' => (float)($ventasDistribucionDashboard + $directVentasPats),
  'pats_activos' => $directPatsActivos,
  'pats_vencidos' => 0,
  'comision_por_distribucion' => (float)$comisionesDistribucionDashboard,
  'comision_por_pats_activos' => (float)$directComisionPats,
  'comision_perdida_vencidos' => 0.0,
  'comision_total' => (float)($comisionesDistribucionDashboard + $directComisionPats),
  'canal' => 'FRANQUICIA_DIRECTA'
];
array_unshift($distribuidores, $directCard);

$ventasPasaportesDashboard = (float)($fin['ventas_real'] ?? 0);
$ventasTotalesDashboard = (float)($ventasDistribucionDashboard + $ventasPasaportesDashboard);

/* Rankings */
$rankingActivos = $distribuidores;
usort($rankingActivos, function ($a, $b) {
  $cmp = (int)($b['pats_activos'] ?? 0) <=> (int)($a['pats_activos'] ?? 0);
  if ($cmp !== 0) return $cmp;
  return (float)($b['comision_por_pats_activos'] ?? 0) <=> (float)($a['comision_por_pats_activos'] ?? 0);
});
$rankingVencidos = $distribuidores;
usort($rankingVencidos, function ($a, $b) {
  $cmp = (int)($b['pats_vencidos'] ?? 0) <=> (int)($a['pats_vencidos'] ?? 0);
  if ($cmp !== 0) return $cmp;
  return (float)($b['comision_perdida_vencidos'] ?? 0) <=> (float)($a['comision_perdida_vencidos'] ?? 0);
});
$rankingActivos = array_slice($rankingActivos, 0, 10);
$rankingVencidos = array_slice($rankingVencidos, 0, 10);

/* Charts por canal / distribuidor */
$chartLabels = array_map(static fn($x) => (string)($x['nombre'] ?? '-'), $distribuidores);
$chartReal   = array_map(static fn($x) => (float)($x['ventas_real'] ?? 0), $distribuidores);

/* Chart mensual anual */
$anio = (int)($f['anio'] ?: date('Y'));
$mesesBase = [1=>'Ene',2=>'Feb',3=>'Mar',4=>'Abr',5=>'May',6=>'Jun',7=>'Jul',8=>'Ago',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dic'];
$chartMensual = [];
for ($m=1; $m<=12; $m++) {
  $chartMensual[$m] = ['label'=>$mesesBase[$m], 'ventas_globales'=>0.0, 'ganancia_franquicia'=>0.0, 'distribuidores'=>0, 'pats'=>0];
}

$rsDistMes = pats_all($cx, "
  SELECT
    MONTH(d.created_at) AS mes,
    COUNT(*) AS total_distribuidores,
    COALESCE(SUM(d.valor_distribucion),0) AS ventas_distribucion,
    COALESCE(SUM(cg.monto_comision),0) AS ganancia_generada
  FROM pats_distribuidores d
  LEFT JOIN pats_comisiones_generadas cg
    ON cg.tipo_origen = 'venta_distribucion'
   AND LOWER(TRIM(cg.beneficiario_tipo)) = 'franquicia'
   AND cg.beneficiario_id = d.id_franquicia
   AND cg.id_origen = d.id_distribuidor
  WHERE d.id_franquicia = {$idFranquicia}
    AND d.activo = 1
    AND YEAR(d.created_at) = {$anio}
  GROUP BY MONTH(d.created_at)
");
foreach ($rsDistMes as $row) {
  $mes = (int)($row['mes'] ?? 0);
  if ($mes >= 1 && $mes <= 12) {
    $ventasDistMes = (float)($row['ventas_distribucion'] ?? 0);
    $gananciaGenerada = (float)($row['ganancia_generada'] ?? 0);
    $gananciaDistMes = $gananciaGenerada > 0 ? $gananciaGenerada : round($ventasDistMes * 0.50, 2);
    $chartMensual[$mes]['distribuidores'] = (int)($row['total_distribuidores'] ?? 0);
    $chartMensual[$mes]['ventas_globales'] += $ventasDistMes;
    $chartMensual[$mes]['ganancia_franquicia'] += $gananciaDistMes;
  }
}

$rsPatsMes = pats_all($cx, "
  SELECT
    MONTH(p.created_at) AS mes,
    COUNT(*) AS total_pats,
    COALESCE(SUM(p.valor_final_pasaporte),0) AS ventas_pats,
    COALESCE(SUM(
      CASE
        WHEN LOWER(TRIM(COALESCE(p.frecuencia_pago,''))) = 'anual' AND COALESCE(p.id_distribuidor,0) > 0 THEN {$franqAnual}
        WHEN COALESCE(p.id_distribuidor,0) > 0 THEN {$franqMensual}
        WHEN LOWER(TRIM(COALESCE(p.frecuencia_pago,''))) = 'anual' THEN {$franqAnual} + {$distAnual}
        ELSE {$franqMensual} + {$distMensual}
      END
    ),0) AS ganancia_pats
  FROM pats_pasaportes p
  WHERE p.id_franquicia = {$idFranquicia}
    AND p.activo = 1
    AND YEAR(p.created_at) = {$anio}
  GROUP BY MONTH(p.created_at)
");
foreach ($rsPatsMes as $row) {
  $mes = (int)($row['mes'] ?? 0);
  if ($mes >= 1 && $mes <= 12) {
    $chartMensual[$mes]['pats'] = (int)($row['total_pats'] ?? 0);
    $chartMensual[$mes]['ventas_globales'] += (float)($row['ventas_pats'] ?? 0);
    $chartMensual[$mes]['ganancia_franquicia'] += (float)($row['ganancia_pats'] ?? 0);
  }
}

$chartMensualAnual = [
  'labels' => array_values(array_map(fn($x) => $x['label'], $chartMensual)),
  'ventas_globales' => array_values(array_map(fn($x) => (float)$x['ventas_globales'], $chartMensual)),
  'ganancia_franquicia' => array_values(array_map(fn($x) => (float)$x['ganancia_franquicia'], $chartMensual)),
  'distribuidores' => array_values(array_map(fn($x) => (int)$x['distribuidores'], $chartMensual)),
  'pats' => array_values(array_map(fn($x) => (int)$x['pats'], $chartMensual))
];

$franquicia['public_checkout_token'] = (string)($franquicia['public_checkout_token'] ?? '');
$franquicia['public_checkout_activo'] = (int)($franquicia['public_checkout_activo'] ?? 0);
$franquicia['public_checkout_updated_at'] = (string)($franquicia['public_checkout_updated_at'] ?? '');

pats_json([
  'ok' => true,
  'franquicia' => $franquicia,
  'kpis' => [
    'ventas_real' => (float)$ventasTotalesDashboard,
    'ventas_distribucion' => (float)$ventasDistribucionDashboard,
    'ventas_pasaportes' => (float)$ventasPasaportesDashboard,
    'ventas_nominal' => (float)($fin['ventas_nominal'] ?? 0),
    'monto_vencido' => (float)($fin['monto_vencido'] ?? 0),
    'activos' => (int)($fin['activos'] ?? 0),
    'vencidos' => (int)($fin['vencidos'] ?? 0),
    'mis_comisiones_distribucion' => (float)$comisionesDistribucionDashboard,
    'mis_comisiones_distribucion_generadas' => (float)$comisionesDistribucionDashboard,
    'mis_comisiones_pats_activos' => (float)$misComisionesPats
  ],
  'distribuidores' => $distribuidores,
  'charts' => [
    'real' => ['labels'=>$chartLabels, 'values'=>$chartReal],
    'mensual_anual' => $chartMensualAnual
  ],
  'rankings' => ['activos'=>$rankingActivos, 'vencidos'=>$rankingVencidos]
]);
