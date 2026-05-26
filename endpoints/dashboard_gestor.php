<?php
/*
ez/pats/endpoints/dashboard_gestor.php

PATS ¡¤ Dashboard de gestor / gerente
Ajuste operativo:
- Mantiene m¨¦tricas de franquicias asociadas por pats_gestor_franquicias.
- Suma PATS directos del gestor usando pats_pasaportes.id_gestor.
- Para PATS directo de gestor aplica comisi¨®n visual mensual $100 / anual $1,200.
- Tambi¨¦n lee comisiones generadas con tipo_origen = pago_pasaporte, que es el tipo usado por pats_comisiones_engine.php.
- No modifica tablas. Solo devuelve JSON para dashboard.
*/
require_once __DIR__ . '/bootstrap.php';

$f = pats_filters();
$isAdmin = in_array($PATS_ROLE, $PATS_ADMIN_ROLES, true);
$idGestor = (int)($_GET['id_gestor'] ?? 0);
$soloMeta = (int)($_GET['solo_meta'] ?? 0);

if (!function_exists('dg_num')) {
  function dg_num($v): float { return round((float)($v ?? 0), 2); }
}
if (!function_exists('dg_clean')) {
  function dg_clean($v): string { return trim((string)($v ?? '')); }
}
if (!function_exists('dg_pats_value_expr')) {
  function dg_pats_value_expr(string $alias = 'p'): string {
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias);
    if ($a === '') $a = 'p';
    return "COALESCE(NULLIF({$a}.valor_final_pasaporte,0), NULLIF({$a}.valor_pasaporte,0), CASE WHEN LOWER(TRIM(COALESCE({$a}.frecuencia_pago,''))) = 'anual' THEN 9600 ELSE 800 END)";
  }
}
if (!function_exists('dg_period_filter')) {
  function dg_period_filter(string $field, array $f): string {
    $sql = '';
    if (!empty($f['anio'])) $sql .= " AND YEAR({$field}) = " . (int)$f['anio'];
    if (!empty($f['mes'])) $sql .= " AND MONTH({$field}) = " . (int)$f['mes'];
    return $sql;
  }
}
if (!function_exists('dg_region_filter')) {
  function dg_region_filter(mysqli $cx, string $field, array $f): string {
    if (empty($f['region'])) return '';
    return " AND {$field} = '" . $cx->real_escape_string((string)$f['region']) . "'";
  }
}
if (!function_exists('dg_empty_response')) {
  function dg_empty_response(array $gestoresMeta, int $idGestor = 0): void {
    pats_json([
      'ok' => true,
      'gestores' => $gestoresMeta,
      'id_gestor_actual' => $idGestor,
      'gestor' => [],
      'kpis' => [
        'franquicias_asociadas' => 0,
        'ventas_globales' => 0,
        'ventas_franquicias' => 0,
        'ventas_distribuciones' => 0,
        'ventas_pats' => 0,
        'ventas_pats_directos' => 0,
        'pats_directos' => 0,
        'comision_total' => 0,
        'comision_pagada' => 0,
        'comision_pendiente' => 0,
        'cxc_total' => 0,
        'comision_franquicias' => 0,
        'comision_distribuciones' => 0,
        'comision_pats_directos' => 0
      ],
      'regiones' => [],
      'franquicias_meta' => [],
      'franquicias' => [],
      'pats_directos_gestor' => [
        'total' => 0,
        'ventas' => 0,
        'comision_generada' => 0,
        'comision_fallback_visual' => 0,
        'comision_total_usada' => 0
      ],
      'charts' => [
        'ventas_por_franquicia' => ['labels' => [], 'values' => []],
        'comisiones_por_franquicia' => ['labels' => [], 'values' => []],
        'mensual' => ['labels' => [], 'ventas' => [], 'comisiones' => []]
      ],
      'rankings' => ['ventas' => [], 'comision' => []]
    ]);
  }
}
if (!function_exists('dg_contrato_ratio')) {
  function dg_contrato_ratio(mysqli $cx, string $actorTipo, int $actorId, float $fallback): array {
    $actorTipoEsc = $cx->real_escape_string(strtolower(trim($actorTipo)));
    $actorId = (int)$actorId;
    $out = ['id_contrato' => 0, 'valor_total' => $fallback, 'total_pagado' => $fallback, 'ratio' => $fallback > 0 ? 1.0 : 0.0, 'fuente' => 'fallback_valor_actor'];
    if ($actorId <= 0) return $out;
    $contrato = pats_one($cx, "
      SELECT id_contrato, valor_total
      FROM pats_contratos_actor
      WHERE actor_tipo = '{$actorTipoEsc}'
        AND actor_id = {$actorId}
        AND activo = 1
      ORDER BY id_contrato DESC
      LIMIT 1
    ");
    if (!$contrato) return $out;
    $idContrato = (int)($contrato['id_contrato'] ?? 0);
    $valorTotal = dg_num($contrato['valor_total'] ?? $fallback);
    $pagado = 0.0;
    if ($idContrato > 0) {
      $row = pats_one($cx, "SELECT COALESCE(SUM(monto_pago),0) AS total_pagado FROM pats_pagos_actor WHERE id_contrato = {$idContrato}");
      $pagado = dg_num($row['total_pagado'] ?? 0);
    }
    if ($valorTotal <= 0 && $fallback > 0) $valorTotal = $fallback;
    $ratio = $valorTotal > 0 ? min(1, max(0, $pagado / $valorTotal)) : 0.0;
    if ($ratio <= 0 && $fallback > 0) {
      $pagado = $fallback;
      $ratio = $valorTotal > 0 ? min(1, max(0, $pagado / $valorTotal)) : 1.0;
    }
    return ['id_contrato' => $idContrato, 'valor_total' => dg_num($valorTotal), 'total_pagado' => dg_num($pagado), 'ratio' => $ratio, 'fuente' => 'contrato_actor'];
  }
}

$gestoresMeta = [];
if ($isAdmin) {
  $gestoresMeta = pats_all($cx, "
    SELECT id_gestor, nombre_gestor, correo, telefono, activo
    FROM pats_gestores
    WHERE activo = 1
    ORDER BY nombre_gestor ASC
  ");
}

if ($soloMeta === 1) {
  pats_json(['ok' => true, 'gestores' => $gestoresMeta, 'regiones' => [], 'franquicias_meta' => [], 'id_gestor_actual' => $idGestor]);
}

if (!$isAdmin && ($PATS_ACTOR['rolapp'] ?? '') === 'GESTORPATS' && (int)($PATS_ACTOR['id_gestor'] ?? 0) > 0) {
  $idGestor = (int)$PATS_ACTOR['id_gestor'];
}

if ($idGestor <= 0) {
  dg_empty_response($gestoresMeta, 0);
}

$gestor = pats_one($cx, "
  SELECT id_gestor, nombre_gestor, correo, telefono, activo, public_checkout_token, public_checkout_activo, public_checkout_updated_at
  FROM pats_gestores
  WHERE id_gestor = {$idGestor}
    AND activo = 1
  LIMIT 1
");
if (!$gestor) dg_empty_response($gestoresMeta, $idGestor);

$gestor['id_gestor'] = (int)($gestor['id_gestor'] ?? 0);
$gestor['public_checkout_token'] = dg_clean($gestor['public_checkout_token'] ?? '');
$gestor['public_checkout_activo'] = (int)($gestor['public_checkout_activo'] ?? 0);
$gestor['public_checkout_updated_at'] = dg_clean($gestor['public_checkout_updated_at'] ?? '');
$gestor['link_pats_publico'] = $gestor['public_checkout_token'] !== ''
  ? 'https://pasaporteatusalud.com/landing_pats.php?t=' . rawurlencode($gestor['public_checkout_token'])
  : '';

$whereFranq = ["gf.id_gestor = {$idGestor}", "gf.activo = 1", "f.activo = 1"];
if (!empty($f['region'])) $whereFranq[] = "f.region = '" . $cx->real_escape_string((string)$f['region']) . "'";
if (!empty($f['id_franquicia'])) $whereFranq[] = "f.id_franquicia = " . (int)$f['id_franquicia'];
$whereFranqSql = implode(' AND ', $whereFranq);

$regiones = pats_all($cx, "
  SELECT DISTINCT f.region
  FROM pats_gestor_franquicias gf
  INNER JOIN pats_franquicias f ON f.id_franquicia = gf.id_franquicia
  WHERE gf.id_gestor = {$idGestor}
    AND gf.activo = 1
    AND f.activo = 1
  ORDER BY f.region ASC
");

$franquiciasMeta = pats_all($cx, "
  SELECT f.id_franquicia, f.nombre_franquicia
  FROM pats_gestor_franquicias gf
  INNER JOIN pats_franquicias f ON f.id_franquicia = gf.id_franquicia
  WHERE {$whereFranqSql}
  ORDER BY f.nombre_franquicia ASC
");

$patsValueExpr = dg_pats_value_expr('p');
$franquicias = pats_all($cx, "
  SELECT
    f.id_franquicia,
    f.nombre_franquicia,
    f.region,
    f.zona,
    f.unidad,
    f.codigo_franquicia,
    f.estatus,
    COALESCE(f.valor_franquicia,0) AS valor_franquicia,
    f.created_at AS fecha_franquicia,
    (
      SELECT COALESCE(SUM({$patsValueExpr}), 0)
      FROM pats_pasaportes p
      WHERE p.id_franquicia = f.id_franquicia
        AND p.activo = 1
        " . dg_period_filter('p.created_at', $f) . "
    ) AS ventas_pats,
    (
      SELECT COUNT(*)
      FROM pats_pasaportes p
      WHERE p.id_franquicia = f.id_franquicia
        AND p.activo = 1
        " . dg_period_filter('p.created_at', $f) . "
    ) AS pats_total,
    (
      SELECT COUNT(*)
      FROM pats_pasaportes p
      WHERE p.id_franquicia = f.id_franquicia
        AND p.activo = 1
        AND LOWER(TRIM(COALESCE(p.estatus,''))) IN ('activo','vigente')
        " . dg_period_filter('p.created_at', $f) . "
    ) AS pats_activos,
    (
      SELECT COUNT(*)
      FROM pats_distribuidores d
      WHERE d.id_franquicia = f.id_franquicia
        AND d.activo = 1
        " . dg_period_filter('d.created_at', $f) . "
    ) AS distribuciones_total,
    (
      SELECT COALESCE(SUM(d.valor_distribucion),0)
      FROM pats_distribuidores d
      WHERE d.id_franquicia = f.id_franquicia
        AND d.activo = 1
        " . dg_period_filter('d.created_at', $f) . "
    ) AS ventas_distribuciones,
    (
      SELECT COUNT(*)
      FROM pats_distribuidores d
      WHERE d.id_franquicia = f.id_franquicia
        AND d.activo = 1
        " . dg_period_filter('d.created_at', $f) . "
        AND NOT EXISTS (
          SELECT 1 FROM pats_comisiones_generadas cgx
          WHERE cgx.tipo_origen = 'venta_distribucion'
            AND cgx.id_origen = d.id_distribuidor
            AND LOWER(TRIM(cgx.beneficiario_tipo)) = 'gestor'
            AND cgx.beneficiario_id = gf.id_gestor
        )
    ) AS distribuciones_sin_comision_generada,
    (
      SELECT COALESCE(SUM(cg.monto_comision), 0)
      FROM pats_comisiones_generadas cg
      WHERE LOWER(TRIM(cg.beneficiario_tipo)) = 'gestor'
        AND cg.beneficiario_id = gf.id_gestor
        AND cg.tipo_origen = 'venta_franquicia'
        AND cg.id_origen = f.id_franquicia
        " . dg_period_filter('cg.fecha_generacion', $f) . "
    ) AS comision_franquicia_generada,
    (
      SELECT COALESCE(SUM(cg.monto_comision), 0)
      FROM pats_comisiones_generadas cg
      WHERE LOWER(TRIM(cg.beneficiario_tipo)) = 'gestor'
        AND cg.beneficiario_id = gf.id_gestor
        AND cg.tipo_origen = 'venta_distribucion'
        AND EXISTS (
          SELECT 1 FROM pats_distribuidores d
          WHERE d.id_distribuidor = cg.id_origen
            AND d.id_franquicia = f.id_franquicia
        )
        " . dg_period_filter('cg.fecha_generacion', $f) . "
    ) AS comision_distribucion_generada,
    (
      SELECT COALESCE(SUM(cg.monto_comision), 0)
      FROM pats_comisiones_generadas cg
      WHERE LOWER(TRIM(cg.beneficiario_tipo)) = 'gestor'
        AND cg.beneficiario_id = gf.id_gestor
        AND LOWER(TRIM(cg.estatus)) = 'pagado'
        AND (
          (cg.tipo_origen = 'venta_franquicia' AND cg.id_origen = f.id_franquicia)
          OR (cg.tipo_origen = 'venta_distribucion' AND EXISTS (SELECT 1 FROM pats_distribuidores d WHERE d.id_distribuidor = cg.id_origen AND d.id_franquicia = f.id_franquicia))
        )
        " . dg_period_filter('cg.fecha_generacion', $f) . "
    ) AS comision_pagada_generada,
    (
      SELECT COALESCE(SUM(cg.monto_comision), 0)
      FROM pats_comisiones_generadas cg
      WHERE LOWER(TRIM(cg.beneficiario_tipo)) = 'gestor'
        AND cg.beneficiario_id = gf.id_gestor
        AND LOWER(TRIM(cg.estatus)) IN ('por_pagar','pendiente','solicitado','en_revision','aprobado')
        AND (
          (cg.tipo_origen = 'venta_franquicia' AND cg.id_origen = f.id_franquicia)
          OR (cg.tipo_origen = 'venta_distribucion' AND EXISTS (SELECT 1 FROM pats_distribuidores d WHERE d.id_distribuidor = cg.id_origen AND d.id_franquicia = f.id_franquicia))
        )
        " . dg_period_filter('cg.fecha_generacion', $f) . "
    ) AS comision_pendiente_generada,
    (
      SELECT COALESCE(SUM(ca.saldo_financiado), 0)
      FROM pats_contratos_actor ca
      WHERE ca.actor_tipo = 'franquicia'
        AND ca.actor_id = f.id_franquicia
        AND ca.activo = 1
        AND ca.estatus IN ('VIGENTE','VENCIDO','ACTIVO')
    ) AS cxc_total
  FROM pats_gestor_franquicias gf
  INNER JOIN pats_franquicias f ON f.id_franquicia = gf.id_franquicia
  WHERE {$whereFranqSql}
  ORDER BY f.nombre_franquicia ASC
");

foreach ($franquicias as &$fr) {
  $idFranquicia = (int)($fr['id_franquicia'] ?? 0);
  $valorFranquicia = dg_num($fr['valor_franquicia'] ?? 0);
  $ventasPatsF = dg_num($fr['ventas_pats'] ?? 0);
  $ventasDistribucionesF = dg_num($fr['ventas_distribuciones'] ?? 0);
  $ventasTotalF = dg_num($valorFranquicia + $ventasDistribucionesF + $ventasPatsF);
  $genFranq = dg_num($fr['comision_franquicia_generada'] ?? 0);
  $genDist = dg_num($fr['comision_distribucion_generada'] ?? 0);
  $genTotal = dg_num($genFranq + $genDist);
  $pagadaGenerada = dg_num($fr['comision_pagada_generada'] ?? 0);
  $pendienteGenerada = dg_num($fr['comision_pendiente_generada'] ?? 0);
  $contratoRatio = dg_contrato_ratio($cx, 'franquicia', $idFranquicia, $valorFranquicia);
  $fallbackFranq = 0.0;
  if ($genFranq <= 0 && $valorFranquicia > 0) {
    $fallbackFranq = dg_num(50000.00 * (float)($contratoRatio['ratio'] ?? 0));
    if ($fallbackFranq <= 0) $fallbackFranq = 50000.00;
  }
  $fallbackDist = dg_num(((int)($fr['distribuciones_sin_comision_generada'] ?? 0)) * 1000.00);
  $fallbackTotal = dg_num($fallbackFranq + $fallbackDist);
  $fr['valor_franquicia'] = $valorFranquicia;
  $fr['ventas_pats'] = $ventasPatsF;
  $fr['ventas_distribuciones'] = $ventasDistribucionesF;
  $fr['ventas_total'] = $ventasTotalF;
  $fr['comision_franquicia_generada'] = $genFranq;
  $fr['comision_distribucion_generada'] = $genDist;
  $fr['comision_franquicia_fallback'] = $fallbackFranq;
  $fr['comision_distribucion_fallback'] = $fallbackDist;
  $fr['comision_gestor_total'] = dg_num($genTotal + $fallbackTotal);
  $fr['comision_pagada'] = $pagadaGenerada;
  $fr['comision_pendiente'] = dg_num($pendienteGenerada + $fallbackTotal);
  $fr['comision_fallback_visual'] = $fallbackTotal;
  $fr['distribuciones_total'] = (int)($fr['distribuciones_total'] ?? 0);
  $fr['pats_total'] = (int)($fr['pats_total'] ?? 0);
  $fr['pats_activos'] = (int)($fr['pats_activos'] ?? 0);
  $fr['cxc_total'] = dg_num($fr['cxc_total'] ?? 0);
  $fr['contrato_franquicia'] = $contratoRatio;
}
unset($fr);

$franquiciasAsociadas = count($franquicias);
$ventasGlobales = 0.0;
$ventasFranquicias = 0.0;
$ventasDistribuciones = 0.0;
$ventasPats = 0.0;
$comisionTotal = 0.0;
$comisionPagada = 0.0;
$comisionPendiente = 0.0;
$comisionFranquicias = 0.0;
$comisionDistribuciones = 0.0;
$cxcTotal = 0.0;

foreach ($franquicias as $fr) {
  $ventasGlobales += (float)($fr['ventas_total'] ?? 0);
  $ventasFranquicias += (float)($fr['valor_franquicia'] ?? 0);
  $ventasDistribuciones += (float)($fr['ventas_distribuciones'] ?? 0);
  $ventasPats += (float)($fr['ventas_pats'] ?? 0);
  $comisionTotal += (float)($fr['comision_gestor_total'] ?? 0);
  $comisionPagada += (float)($fr['comision_pagada'] ?? 0);
  $comisionPendiente += (float)($fr['comision_pendiente'] ?? 0);
  $comisionFranquicias += (float)($fr['comision_franquicia_generada'] ?? 0) + (float)($fr['comision_franquicia_fallback'] ?? 0);
  $comisionDistribuciones += (float)($fr['comision_distribucion_generada'] ?? 0) + (float)($fr['comision_distribucion_fallback'] ?? 0);
  $cxcTotal += (float)($fr['cxc_total'] ?? 0);
}

$whereDirectos = "p.id_gestor = {$idGestor} AND COALESCE(p.id_franquicia,0) = 0 AND COALESCE(p.id_distribuidor,0) = 0 AND p.activo = 1";
$whereDirectos .= dg_period_filter('p.created_at', $f);
$whereDirectos .= dg_region_filter($cx, 'p.region', $f);
if (!empty($f['id_franquicia'])) $whereDirectos .= " AND 1=0";

$rowPatsDirectos = pats_one($cx, "
  SELECT
    COUNT(*) AS total_pats,
    COALESCE(SUM(" . dg_pats_value_expr('p') . "),0) AS ventas,
    COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(p.frecuencia_pago,''))) = 'anual' THEN 1200 ELSE 100 END),0) AS comision_teorica
  FROM pats_pasaportes p
  WHERE {$whereDirectos}
");
$patsDirectosGestorTotal = (int)($rowPatsDirectos['total_pats'] ?? 0);
$ventasPatsDirectosGestor = dg_num($rowPatsDirectos['ventas'] ?? 0);
$comisionPatsDirectosFallback = dg_num($rowPatsDirectos['comision_teorica'] ?? 0);

$rowComisionPatsDirectos = pats_one($cx, "
  SELECT
    COALESCE(SUM(cg.monto_comision),0) AS total,
    COALESCE(SUM(CASE WHEN LOWER(TRIM(cg.estatus)) = 'pagado' THEN cg.monto_comision ELSE 0 END),0) AS pagada,
    COALESCE(SUM(CASE WHEN LOWER(TRIM(cg.estatus)) IN ('por_pagar','pendiente','solicitado','en_revision','aprobado') THEN cg.monto_comision ELSE 0 END),0) AS pendiente
  FROM pats_comisiones_generadas cg
  WHERE LOWER(TRIM(cg.beneficiario_tipo)) = 'gestor'
    AND cg.beneficiario_id = {$idGestor}
    AND cg.tipo_origen IN ('pago_pasaporte','venta_pats','venta_pasaporte','pasaporte')
    " . dg_period_filter('cg.fecha_generacion', $f) . "
");
$comisionPatsDirectosGenerada = dg_num($rowComisionPatsDirectos['total'] ?? 0);
$comisionPatsDirectosPagada = dg_num($rowComisionPatsDirectos['pagada'] ?? 0);
$comisionPatsDirectosPendiente = dg_num($rowComisionPatsDirectos['pendiente'] ?? 0);
$comisionPatsDirectos = $comisionPatsDirectosGenerada > 0 ? $comisionPatsDirectosGenerada : $comisionPatsDirectosFallback;
if ($comisionPatsDirectosGenerada <= 0 && $comisionPatsDirectosFallback > 0) {
  $comisionPatsDirectosPendiente = $comisionPatsDirectosFallback;
}

$ventasPats = dg_num($ventasPats + $ventasPatsDirectosGestor);
$ventasGlobales = dg_num($ventasGlobales + $ventasPatsDirectosGestor);
$comisionTotal = dg_num($comisionTotal + $comisionPatsDirectos);
$comisionPagada = dg_num($comisionPagada + $comisionPatsDirectosPagada);
$comisionPendiente = dg_num($comisionPendiente + $comisionPatsDirectosPendiente);

$kpis = [
  'franquicias_asociadas' => $franquiciasAsociadas,
  'ventas_globales' => dg_num($ventasGlobales),
  'ventas_franquicias' => dg_num($ventasFranquicias),
  'ventas_distribuciones' => dg_num($ventasDistribuciones),
  'ventas_pats' => dg_num($ventasPats),
  'ventas_pats_directos' => dg_num($ventasPatsDirectosGestor),
  'pats_directos' => $patsDirectosGestorTotal,
  'comision_total' => dg_num($comisionTotal),
  'comision_pagada' => dg_num($comisionPagada),
  'comision_pendiente' => dg_num($comisionPendiente),
  'cxc_total' => dg_num($cxcTotal),
  'comision_franquicias' => dg_num($comisionFranquicias),
  'comision_distribuciones' => dg_num($comisionDistribuciones),
  'comision_pats_directos' => dg_num($comisionPatsDirectos)
];

$chartVentas = ['labels' => [], 'values' => []];
$chartComisiones = ['labels' => [], 'values' => []];
foreach ($franquicias as $fr) {
  $chartVentas['labels'][] = (string)($fr['nombre_franquicia'] ?? '-');
  $chartVentas['values'][] = (float)($fr['ventas_total'] ?? 0);
  $chartComisiones['labels'][] = (string)($fr['nombre_franquicia'] ?? '-');
  $chartComisiones['values'][] = (float)($fr['comision_gestor_total'] ?? 0);
}
if ($patsDirectosGestorTotal > 0) {
  $chartVentas['labels'][] = 'PATS directos';
  $chartVentas['values'][] = (float)$ventasPatsDirectosGestor;
  $chartComisiones['labels'][] = 'PATS directos';
  $chartComisiones['values'][] = (float)$comisionPatsDirectos;
}

$anio = (int)($f['anio'] ?: date('Y'));
$mesesBase = [1=>'Ene',2=>'Feb',3=>'Mar',4=>'Abr',5=>'May',6=>'Jun',7=>'Jul',8=>'Ago',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dic'];
$chartMensual = [];
for ($m=1;$m<=12;$m++) $chartMensual[$m] = ['label'=>$mesesBase[$m], 'ventas'=>0.0, 'comisiones'=>0.0];

$rsPatsMes = pats_all($cx, "
  SELECT MONTH(p.created_at) AS mes, COALESCE(SUM(" . dg_pats_value_expr('p') . "),0) AS monto
  FROM pats_pasaportes p
  INNER JOIN pats_gestor_franquicias gf ON gf.id_franquicia = p.id_franquicia AND gf.id_gestor = {$idGestor} AND gf.activo = 1
  WHERE p.activo = 1 AND YEAR(p.created_at) = {$anio}
    " . dg_region_filter($cx, 'p.region', $f) . "
    " . (!empty($f['id_franquicia']) ? " AND p.id_franquicia = " . (int)$f['id_franquicia'] : "") . "
  GROUP BY MONTH(p.created_at)
");
foreach ($rsPatsMes as $row) { $m=(int)($row['mes']??0); if($m>=1&&$m<=12) $chartMensual[$m]['ventas'] += (float)($row['monto']??0); }

$rsPatsDirectosMes = pats_all($cx, "
  SELECT MONTH(p.created_at) AS mes,
         COALESCE(SUM(" . dg_pats_value_expr('p') . "),0) AS ventas,
         COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(p.frecuencia_pago,''))) = 'anual' THEN 1200 ELSE 100 END),0) AS comision
  FROM pats_pasaportes p
  WHERE p.activo = 1
    AND p.id_gestor = {$idGestor}
    AND COALESCE(p.id_franquicia,0) = 0
    AND COALESCE(p.id_distribuidor,0) = 0
    AND YEAR(p.created_at) = {$anio}
    " . dg_region_filter($cx, 'p.region', $f) . "
    " . (!empty($f['id_franquicia']) ? " AND 1=0" : "") . "
  GROUP BY MONTH(p.created_at)
");
foreach ($rsPatsDirectosMes as $row) {
  $m=(int)($row['mes']??0);
  if($m>=1&&$m<=12) {
    $chartMensual[$m]['ventas'] += (float)($row['ventas']??0);
    if ($comisionPatsDirectosGenerada <= 0) $chartMensual[$m]['comisiones'] += (float)($row['comision']??0);
  }
}

$rsFranqMes = pats_all($cx, "
  SELECT MONTH(f.created_at) AS mes, COALESCE(SUM(f.valor_franquicia),0) AS monto
  FROM pats_franquicias f
  INNER JOIN pats_gestor_franquicias gf ON gf.id_franquicia = f.id_franquicia AND gf.id_gestor = {$idGestor} AND gf.activo = 1
  WHERE f.activo = 1 AND YEAR(f.created_at) = {$anio}
    " . dg_region_filter($cx, 'f.region', $f) . "
    " . (!empty($f['id_franquicia']) ? " AND f.id_franquicia = " . (int)$f['id_franquicia'] : "") . "
  GROUP BY MONTH(f.created_at)
");
foreach ($rsFranqMes as $row) { $m=(int)($row['mes']??0); if($m>=1&&$m<=12) $chartMensual[$m]['ventas'] += (float)($row['monto']??0); }

$rsDistMes = pats_all($cx, "
  SELECT MONTH(d.created_at) AS mes, COALESCE(SUM(d.valor_distribucion),0) AS monto
  FROM pats_distribuidores d
  INNER JOIN pats_franquicias f ON f.id_franquicia = d.id_franquicia
  INNER JOIN pats_gestor_franquicias gf ON gf.id_franquicia = f.id_franquicia AND gf.id_gestor = {$idGestor} AND gf.activo = 1
  WHERE d.activo = 1 AND YEAR(d.created_at) = {$anio}
    " . dg_region_filter($cx, 'f.region', $f) . "
    " . (!empty($f['id_franquicia']) ? " AND f.id_franquicia = " . (int)$f['id_franquicia'] : "") . "
  GROUP BY MONTH(d.created_at)
");
foreach ($rsDistMes as $row) { $m=(int)($row['mes']??0); if($m>=1&&$m<=12) $chartMensual[$m]['ventas'] += (float)($row['monto']??0); }

$rsComMes = pats_all($cx, "
  SELECT MONTH(cg.fecha_generacion) AS mes, COALESCE(SUM(cg.monto_comision),0) AS monto
  FROM pats_comisiones_generadas cg
  WHERE LOWER(TRIM(cg.beneficiario_tipo)) = 'gestor'
    AND cg.beneficiario_id = {$idGestor}
    AND YEAR(cg.fecha_generacion) = {$anio}
    AND cg.tipo_origen IN ('venta_franquicia','venta_distribucion','pago_pasaporte','venta_pats','venta_pasaporte','pasaporte')
  GROUP BY MONTH(cg.fecha_generacion)
");
foreach ($rsComMes as $row) { $m=(int)($row['mes']??0); if($m>=1&&$m<=12) $chartMensual[$m]['comisiones'] += (float)($row['monto']??0); }

$chartMensualOut = ['labels'=>[], 'ventas'=>[], 'comisiones'=>[]];
foreach ($chartMensual as $mrow) {
  $chartMensualOut['labels'][] = $mrow['label'];
  $chartMensualOut['ventas'][] = (float)dg_num($mrow['ventas']);
  $chartMensualOut['comisiones'][] = (float)dg_num($mrow['comisiones']);
}

$rankingVentas = $franquicias;
usort($rankingVentas, fn($a,$b) => (float)($b['ventas_total'] ?? 0) <=> (float)($a['ventas_total'] ?? 0));
$rankingVentas = array_slice($rankingVentas, 0, 10);
$rankingComision = $franquicias;
usort($rankingComision, fn($a,$b) => (float)($b['comision_gestor_total'] ?? 0) <=> (float)($a['comision_gestor_total'] ?? 0));
$rankingComision = array_slice($rankingComision, 0, 10);

pats_json([
  'ok' => true,
  'gestores' => $gestoresMeta,
  'id_gestor_actual' => $idGestor,
  'gestor' => $gestor,
  'kpis' => $kpis,
  'pats_directos_gestor' => [
    'total' => $patsDirectosGestorTotal,
    'ventas' => dg_num($ventasPatsDirectosGestor),
    'comision_generada' => dg_num($comisionPatsDirectosGenerada),
    'comision_fallback_visual' => dg_num($comisionPatsDirectosFallback),
    'comision_total_usada' => dg_num($comisionPatsDirectos)
  ],
  'regiones' => $regiones,
  'franquicias_meta' => $franquiciasMeta,
  'franquicias' => $franquicias,
  'charts' => [
    'ventas_por_franquicia' => $chartVentas,
    'comisiones_por_franquicia' => $chartComisiones,
    'mensual' => $chartMensualOut
  ],
  'rankings' => ['ventas' => $rankingVentas, 'comision' => $rankingComision]
]);
