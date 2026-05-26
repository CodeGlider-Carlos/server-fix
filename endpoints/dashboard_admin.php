<?php
/*
ez/pats/endpoints/dashboard_admin.php
*/
require_once __DIR__ . '/bootstrap.php';

$__patsEngineFile = __DIR__ . '/pats_comisiones_engine.php';
if (is_file($__patsEngineFile)) {
  require_once $__patsEngineFile;
}

$__patsSyncFile = __DIR__ . '/pats_sync_comisiones_cache.php';
if (is_file($__patsSyncFile)) {
  require_once $__patsSyncFile;
}

if (!in_array($PATS_ROLE, $PATS_ADMIN_ROLES, true)) {
  pats_json(['ok' => false, 'error' => 'Acceso denegado'], 403);
}

$f = pats_filters();
$anio = (int)($f['anio'] ?: date('Y'));

/* =========================================================
   MOTOR DE COMISIONES + SYNC CACHE
   ---------------------------------------------------------
   Primero completa pats_comisiones_generadas con las reglas reales.
   Después actualiza los campos cache en franquicias, distribuidores
   y gestores. Ambos bloques son seguros: si fallan, el dashboard
   no se rompe y el error queda en el log.
========================================================= */
$patsEngineComisiones = ['ok' => false, 'warnings' => ['No ejecutado']];
if (function_exists('pats_generar_comisiones_todo')) {
  try {
    $patsEngineComisiones = pats_generar_comisiones_todo($cx);
  } catch (Throwable $e) {
    $patsEngineComisiones = [
      'ok' => false,
      'error' => $e->getMessage(),
      'warnings' => ['Falló generación de comisiones']
    ];
    error_log('PATS engine admin error: ' . $e->getMessage());
  }
}

$patsSyncComisionesCache = ['ok' => false, 'warnings' => ['No ejecutado']];
if (function_exists('pats_sync_comisiones_cache')) {
  try {
    $patsSyncComisionesCache = pats_sync_comisiones_cache($cx);
  } catch (Throwable $e) {
    $patsSyncComisionesCache = [
      'ok' => false,
      'error' => $e->getMessage(),
      'warnings' => ['Falló sincronización cache de comisiones']
    ];
    error_log('PATS sync cache admin error: ' . $e->getMessage());
  }
}

/* =========================================================
   HELPERS LOCALES
========================================================= */
function pats_period_filter(string $field, array $f): string {
  $parts = [];
  if (!empty($f['anio'])) {
    $parts[] = "YEAR({$field}) = " . (int)$f['anio'];
  }
  if (!empty($f['mes'])) {
    $parts[] = "MONTH({$field}) = " . (int)$f['mes'];
  }
  return $parts ? (' AND ' . implode(' AND ', $parts)) : '';
}

function pats_historial_count(mysqli $cx, array $entityTypes, string $eventLike, array $f): int {
  $types = array_map(fn($x) => "'" . $cx->real_escape_string($x) . "'", $entityTypes);
  $typesSql = implode(',', $types);
  $like = $cx->real_escape_string($eventLike);

  $sql = "
    SELECT COUNT(*) AS total
    FROM pats_historial h
    WHERE h.entidad_tipo IN ({$typesSql})
      AND h.evento_tipo LIKE '{$like}'
      " . pats_period_filter('h.fecha_evento', $f);

  $row = pats_one($cx, $sql);
  return (int)($row['total'] ?? 0);
}

function pats_admin_table_exists_safe(mysqli $cx, string $table): bool {
  if (function_exists('pats_table_exists')) return pats_table_exists($cx, $table);
  $tableEsc = $cx->real_escape_string($table);
  $rs = $cx->query("SHOW TABLES LIKE '{$tableEsc}'");
  if (!$rs) return false;
  $ok = $rs->num_rows > 0;
  $rs->free();
  return $ok;
}

function pats_admin_money_num($v): float {
  return round((float)($v ?? 0), 2);
}

function pats_admin_calc_contratos_actor(mysqli $cx, string $actorTipoSql, string $fromJoinSql, string $whereSql, float $fallbackVendido): array {
  if (!pats_admin_table_exists_safe($cx, 'pats_contratos_actor') || !pats_admin_table_exists_safe($cx, 'pats_pagos_actor')) {
    return [
      'valor_contrato' => pats_admin_money_num($fallbackVendido),
      'pagado' => pats_admin_money_num($fallbackVendido),
      'saldo' => 0.0,
      'fallback_usado' => 1
    ];
  }

  $hasParcialidades = pats_admin_table_exists_safe($cx, 'pats_parcialidades_actor');

  $parcialidadesJoin = $hasParcialidades ? "
    LEFT JOIN (
      SELECT id_contrato, COALESCE(SUM(saldo_pendiente),0) AS saldo_pendiente
      FROM pats_parcialidades_actor
      GROUP BY id_contrato
    ) par ON par.id_contrato = ca.id_contrato
  " : "";

  $saldoExpr = $hasParcialidades
    ? "COALESCE(SUM(COALESCE(par.saldo_pendiente,0)),0)"
    : "COALESCE(SUM(GREATEST(COALESCE(ca.valor_total,0) - COALESCE(pag.total_pagado,0),0)),0)";

  $row = pats_one($cx, "
    SELECT
      COALESCE(SUM(COALESCE(ca.valor_total,0)),0) AS valor_contrato,
      COALESCE(SUM(COALESCE(pag.total_pagado,0)),0) AS pagado,
      {$saldoExpr} AS saldo
    FROM pats_contratos_actor ca
    {$fromJoinSql}
    LEFT JOIN (
      SELECT id_contrato, COALESCE(SUM(monto_pago),0) AS total_pagado
      FROM pats_pagos_actor
      GROUP BY id_contrato
    ) pag ON pag.id_contrato = ca.id_contrato
    {$parcialidadesJoin}
    WHERE LOWER(TRIM(ca.actor_tipo)) IN ({$actorTipoSql})
      AND ca.activo = 1
      {$whereSql}
  ");

  $valorContrato = pats_admin_money_num($row['valor_contrato'] ?? 0);
  $pagado = pats_admin_money_num($row['pagado'] ?? 0);
  $saldo = pats_admin_money_num($row['saldo'] ?? 0);

  if ($valorContrato <= 0 && $pagado <= 0 && $fallbackVendido > 0) {
    return [
      'valor_contrato' => pats_admin_money_num($fallbackVendido),
      'pagado' => pats_admin_money_num($fallbackVendido),
      'saldo' => 0.0,
      'fallback_usado' => 1
    ];
  }

  if ($pagado <= 0 && $saldo <= 0 && $valorContrato > 0) {
    $pagado = $valorContrato;
  }

  return [
    'valor_contrato' => $valorContrato,
    'pagado' => $pagado,
    'saldo' => $saldo,
    'fallback_usado' => 0
  ];
}

/* =========================================================
   SPLITS GLOBALES DESDE REGLAS
========================================================= */
$splitAnual = pats_pats_split($cx, 'anual');
$splitMensual = pats_pats_split($cx, 'mensual');

$unidadAnual = (float)($splitAnual['unidad'] ?? 0);
$unidadMensual = (float)($splitMensual['unidad'] ?? 0);

/* =========================================================
   FILTRO BASE FRANQUICIAS
========================================================= */
$whereFranqCards = ['f.activo = 1'];

if ($f['pais'] !== '') {
  $whereFranqCards[] = "f.pais='" . addslashes($f['pais']) . "'";
}
if ($f['region'] !== '') {
  $whereFranqCards[] = "f.region='" . addslashes($f['region']) . "'";
}
if ($f['zona'] !== '') {
  $whereFranqCards[] = "f.zona='" . addslashes($f['zona']) . "'";
}

$whereFranqCardsSql = implode(' AND ', $whereFranqCards);

/* =========================================================
   EXPRESIONES PARA VENTAS DIRECTAS ADMINPATS
   ---------------------------------------------------------
   Este dashboard es global: debe incluir franquicias,
   distribuciones y pasaportes aunque no tengan franquicia
   asociada. Si no existe región operativa, se agrupan como
   ADMINPATS DIRECTO en rankings.
========================================================= */
$distRegionExpr = "COALESCE(NULLIF(TRIM(d.region),''), NULLIF(TRIM(f.region),''), 'ADMINPATS DIRECTO')";
$distPaisExpr = "COALESCE(NULLIF(TRIM(d.pais),''), NULLIF(TRIM(f.pais),''), '')";
$distZonaExpr = "COALESCE(NULLIF(TRIM(d.zona),''), NULLIF(TRIM(f.zona),''), '')";
$distFechaExpr = (function_exists('pats_column_exists') && pats_column_exists($cx, 'pats_distribuidores', 'fecha_alta'))
  ? "COALESCE(NULLIF(d.fecha_alta, '0000-00-00'), DATE(d.created_at))"
  : "DATE(d.created_at)";

/*
  Filtros tolerantes para datos históricos:
  - Algunas ventas guardan país como MX y otras como México.
  - Algunas distribuciones directas no tienen franquicia asociada.
  - Si existe región/zona en distribuidor, se usa; si no, se toma la de la franquicia.
*/
$distPaisFilter = '';
if ($f['pais'] !== '') {
  $paisEsc = addslashes($f['pais']);
  $distPaisFilter = " AND (
    UPPER(TRIM(COALESCE(d.pais,''))) = UPPER('{$paisEsc}')
    OR UPPER(TRIM(COALESCE(f.pais,''))) = UPPER('{$paisEsc}')
    OR (UPPER('{$paisEsc}') IN ('MX','MEXICO','MÉXICO') AND UPPER(TRIM(COALESCE(d.pais,''))) IN ('MX','MEXICO','MÉXICO'))
    OR (UPPER('{$paisEsc}') IN ('MX','MEXICO','MÉXICO') AND UPPER(TRIM(COALESCE(f.pais,''))) IN ('MX','MEXICO','MÉXICO'))
  )";
}

$distRegionFilter = $f['region'] !== ''
  ? " AND (d.region='" . addslashes($f['region']) . "' OR f.region='" . addslashes($f['region']) . "')"
  : '';

$distZonaFilter = $f['zona'] !== ''
  ? " AND (d.zona='" . addslashes($f['zona']) . "' OR f.zona='" . addslashes($f['zona']) . "')"
  : '';

/* =========================================================
   PRECIO CATÁLOGO FRANQUICIA
========================================================= */
$rowPrecioFranq = pats_one($cx, "
  SELECT precio
  FROM pats_cat_precios
  WHERE LOWER(TRIM(tipo)) = 'franquicia'
    AND LOWER(TRIM(modalidad)) = 'unico'
  ORDER BY id ASC
  LIMIT 1
");
$precioCatalogoFranquicia = (float)($rowPrecioFranq['precio'] ?? 1000000);

/* =========================================================
   REGIONES
========================================================= */
$regiones = pats_all($cx, "
  SELECT
    f.region,
    COUNT(DISTINCT f.id_franquicia) AS total_franquicias,
    (
      SELECT COUNT(*)
      FROM pats_distribuidores d
      WHERE d.region = f.region
        AND d.activo = 1
    ) AS total_distribuidores,
    (
      SELECT COUNT(*)
      FROM pats_pasaportes p
      WHERE p.region = f.region
        AND p.estatus='activo'
        AND p.activo = 1
    ) AS total_pats_activos
  FROM pats_franquicias f
  WHERE {$whereFranqCardsSql}
  GROUP BY f.region
  ORDER BY f.region ASC
");

/* =========================================================
   FRANQUICIAS
   valor_franquicia = valor contratado real
========================================================= */
$rowFranq = pats_one($cx, "
  SELECT
    COUNT(*) AS total,
    COALESCE(SUM(f.valor_franquicia),0) AS monto_real
  FROM pats_franquicias f
  WHERE f.activo = 1
  " . pats_period_filter('f.created_at', $f)
);

$totalFranquicias = (int)($rowFranq['total'] ?? 0);
$montoFranquicias = (float)($rowFranq['monto_real'] ?? 0);
$valorCatalogoFranquicias = pats_num($totalFranquicias * $precioCatalogoFranquicia);

/* =========================================================
   PARTICIPACIÓN ADMINPATS EN COMPARTIDAS
========================================================= */
$rowParticipacionAdminPats = pats_one($cx, "
  SELECT
    COALESCE(SUM(
      CASE
        WHEN UPPER(TRIM(COALESCE(f.titularidad_tipo,''))) = 'COMPARTIDA'
        THEN ({$precioCatalogoFranquicia} - COALESCE(f.valor_franquicia,0))
        ELSE 0
      END
    ),0) AS participacion_adminpats
  FROM pats_franquicias f
  WHERE f.activo = 1
  " . pats_period_filter('f.created_at', $f)
);
$participacionAdminPats = (float)($rowParticipacionAdminPats['participacion_adminpats'] ?? 0);

/* =========================================================
   DISTRIBUCIONES
   - venta bruta desde pats_distribuidores
   - comisiones reales desde pats_comisiones_generadas
========================================================= */
$rowDist = pats_one($cx, "
  SELECT
    COUNT(*) AS total,
    COALESCE(SUM(d.valor_distribucion),0) AS monto_distribuciones
  FROM pats_distribuidores d
  LEFT JOIN pats_franquicias f
    ON f.id_franquicia = d.id_franquicia
  WHERE d.activo = 1
    " . $distPaisFilter . "
    " . $distRegionFilter . "
    " . $distZonaFilter . "
    " . pats_period_filter($distFechaExpr, $f)
);

$totalDistribuciones = (int)($rowDist['total'] ?? 0);
$montoDistribuciones = (float)($rowDist['monto_distribuciones'] ?? 0);

/* Ingreso AdminPATS por distribuciones
   ---------------------------------------------------------
   Cálculo operativo directo desde pats_distribuidores.
   No usa pats_comisiones_generadas para este KPI porque ahí
   pueden existir filas históricas duplicadas o generadas por
   motores anteriores.

   Regla para dashboard ADMIN:
   - Distribución corporativa directa sin franquicia:
     AdminPATS conserva el valor completo de la distribución.
   - Distribución asociada a franquicia:
     AdminPATS conserva 50% del valor de distribución.
   - Si más adelante se activa regla de gestor/gerente, se debe
     parametrizar desde motor/reglas; por ahora NO se toca aquí.

   Ejemplo esperado actual:
   - 2 directas corporativas x $20,000 = $40,000
   - 1 asociada a franquicia x 50% = $10,000
   Total ingreso AdminPATS por distribuciones = $50,000
========================================================= */
$rowComAdminDist = pats_one($cx, "
  SELECT
    COALESCE(SUM(
      CASE
        WHEN COALESCE(d.id_franquicia,0) = 0 THEN COALESCE(d.valor_distribucion,0)
        ELSE COALESCE(d.valor_distribucion,0) * 0.50
      END
    ),0) AS total,
    0 AS generado_admin,
    COALESCE(SUM(
      CASE
        WHEN COALESCE(d.id_franquicia,0) = 0 THEN COALESCE(d.valor_distribucion,0)
        ELSE COALESCE(d.valor_distribucion,0) * 0.50
      END
    ),0) AS fallback_admin_usado
  FROM pats_distribuidores d
  LEFT JOIN pats_franquicias f
    ON f.id_franquicia = d.id_franquicia
  WHERE d.activo = 1
    " . $distPaisFilter . "
    " . $distRegionFilter . "
    " . $distZonaFilter . "
    " . pats_period_filter($distFechaExpr, $f) . "
");
$montoAdminDistribuciones = (float)($rowComAdminDist['total'] ?? 0);
$montoAdminDistribucionesGenerado = (float)($rowComAdminDist['generado_admin'] ?? 0);
$montoAdminDistribucionesFallback = (float)($rowComAdminDist['fallback_admin_usado'] ?? 0);

/* Comisión FRANQUICIATARIO por distribución */
$rowComFranqDist = pats_one($cx, "
  SELECT
    COALESCE(SUM(cg.monto_comision),0) AS total
  FROM pats_comisiones_generadas cg
  INNER JOIN pats_distribuidores d
    ON d.id_distribuidor = cg.id_origen
  LEFT JOIN pats_franquicias f
    ON f.id_franquicia = d.id_franquicia
  WHERE cg.tipo_origen = 'venta_distribucion'
    AND LOWER(TRIM(cg.beneficiario_tipo)) = 'franquicia'
    " . $distPaisFilter . "
    " . $distRegionFilter . "
    " . $distZonaFilter . "
    " . pats_period_filter('cg.fecha_generacion', $f)
);
$montoFranquiciatarioDistribuciones = (float)($rowComFranqDist['total'] ?? 0);

/* =========================================================
   FALLBACK COMISIONES DISTRIBUCIÓN
   ---------------------------------------------------------
   El dashboard ADMIN es global y no debe depender solo de
   pats_comisiones_generadas, porque en altas anteriores puede
   existir la venta en pats_distribuidores sin que se haya
   generado la comisión histórica.

   Regla mínima segura para mostrar:
   - Sin gestor: AdminPATS 50% / Franquiciatario 50%
   - Con gestor: AdminPATS 9000 / Franquiciatario 10000 / Gestor 1000

   Solo se aplica fallback por beneficiario cuando ese
   beneficiario NO tiene comisión generada para esa distribución.
========================================================= */
$rowDistComFallback = pats_one($cx, "
  SELECT
    COALESCE(SUM(
      CASE
        WHEN COALESCE(ca.total_admin,0) > 0 THEN 0
        WHEN COALESCE(d.id_franquicia,0) = 0 THEN COALESCE(d.valor_distribucion,0)
        WHEN COALESCE(f.tiene_gestor,0) = 1 THEN 9000
        ELSE COALESCE(d.valor_distribucion,0) * 0.50
      END
    ),0) AS fallback_admin,

    COALESCE(SUM(
      CASE
        WHEN COALESCE(cf.total_franquicia,0) > 0 THEN 0
        WHEN COALESCE(d.id_franquicia,0) = 0 THEN 0
        WHEN COALESCE(f.tiene_gestor,0) = 1 THEN 10000
        ELSE COALESCE(d.valor_distribucion,0) * 0.50
      END
    ),0) AS fallback_franquicia,

    COALESCE(SUM(
      CASE
        WHEN COALESCE(cg.total_gestor,0) > 0 THEN 0
        WHEN COALESCE(d.id_franquicia,0) = 0 THEN 0
        WHEN COALESCE(f.tiene_gestor,0) = 1 THEN 1000
        ELSE 0
      END
    ),0) AS fallback_gestor
  FROM pats_distribuidores d
  LEFT JOIN pats_franquicias f
    ON f.id_franquicia = d.id_franquicia
  LEFT JOIN (
    SELECT id_origen, SUM(monto_comision) AS total_admin
    FROM pats_comisiones_generadas
    WHERE tipo_origen = 'venta_distribucion'
      AND LOWER(TRIM(beneficiario_tipo)) = 'admin'
    GROUP BY id_origen
  ) ca
    ON ca.id_origen = d.id_distribuidor
  LEFT JOIN (
    SELECT id_origen, SUM(monto_comision) AS total_franquicia
    FROM pats_comisiones_generadas
    WHERE tipo_origen = 'venta_distribucion'
      AND LOWER(TRIM(beneficiario_tipo)) = 'franquicia'
    GROUP BY id_origen
  ) cf
    ON cf.id_origen = d.id_distribuidor
  LEFT JOIN (
    SELECT id_origen, SUM(monto_comision) AS total_gestor
    FROM pats_comisiones_generadas
    WHERE tipo_origen = 'venta_distribucion'
      AND LOWER(TRIM(beneficiario_tipo)) = 'gestor'
    GROUP BY id_origen
  ) cg
    ON cg.id_origen = d.id_distribuidor
  WHERE d.activo = 1
    " . $distPaisFilter . "
    " . $distRegionFilter . "
    " . $distZonaFilter . "
    " . pats_period_filter($distFechaExpr, $f) . "
");

/*
  AdminPATS distribuciones ya fue calculado de forma consolidada por distribuidor.
  No se vuelve a sumar fallback_admin aquí para evitar duplicar el KPI.
*/
$montoFranquiciatarioDistribucionesFallback = (float)($rowDistComFallback['fallback_franquicia'] ?? 0);
$montoComisionGestorFallback = (float)($rowDistComFallback['fallback_gestor'] ?? 0);

$montoFranquiciatarioDistribuciones += $montoFranquiciatarioDistribucionesFallback;

/* =========================================================
   PATS
   - estas comisiones NO se afectan por deuda contractual
   - Regla blindada por id_franquicia / id_distribuidor
   ---------------------------------------------------------
   1) PATS corporativo directo:
      id_franquicia = 0 AND id_distribuidor = 0
      AdminPATS recibe admin + franquicia + distribuidor.

   2) PATS directo de franquicia, sin distribuidor:
      id_franquicia > 0 AND id_distribuidor = 0
      AdminPATS recibe admin asociado.
      Franquicia recibe franquicia + distribuidor.

   3) PATS con franquicia + distribuidor:
      AdminPATS, franquicia y distribuidor reciben su split base.
========================================================= */
$rowPats = pats_metricas_financieras($cx, "1=1" . pats_period_filter('p.created_at', $f), 'p');

$adminMensualAsociado = (float)($splitMensual['admin'] ?? 200);
$adminAnualAsociado = (float)($splitAnual['admin'] ?? 2400);

$franqMensualBase = (float)($splitMensual['franquicia'] ?? 20);
$franqAnualBase = (float)($splitAnual['franquicia'] ?? 240);

$distMensualBase = (float)($splitMensual['distribuidor'] ?? 80);
$distAnualBase = (float)($splitAnual['distribuidor'] ?? 960);

$nominalMensual = (float)($splitMensual['nominal'] ?? 800);
$nominalAnual = (float)($splitAnual['nominal'] ?? 9600);

$adminMensualDirecto = pats_num($adminMensualAsociado + $franqMensualBase + $distMensualBase);
$adminAnualDirecto = pats_num($adminAnualAsociado + $franqAnualBase + $distAnualBase);
$franqMensualDirecto = pats_num($franqMensualBase + $distMensualBase);
$franqAnualDirecto = pats_num($franqAnualBase + $distAnualBase);

$rowPatsOficial = pats_one($cx, "
  SELECT
    COUNT(*) AS total_pasaportes,
    COALESCE(SUM(COALESCE(p.valor_final_pasaporte,0)),0) AS ventas_real,
    COALESCE(SUM(
      CASE
        WHEN LOWER(TRIM(COALESCE(p.frecuencia_pago,''))) = 'anual' THEN {$nominalAnual}
        ELSE {$nominalMensual}
      END
    ),0) AS ventas_nominal,

    COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(p.frecuencia_pago,''))) = 'anual' THEN 1 ELSE 0 END),0) AS total_anual,
    COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(p.frecuencia_pago,''))) <> 'anual' THEN 1 ELSE 0 END),0) AS total_mensual,

    COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(p.frecuencia_pago,''))) = 'anual' THEN COALESCE(p.valor_final_pasaporte,0) ELSE 0 END),0) AS real_anual,
    COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(p.frecuencia_pago,''))) <> 'anual' THEN COALESCE(p.valor_final_pasaporte,0) ELSE 0 END),0) AS real_mensual,
    COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(p.frecuencia_pago,''))) = 'anual' THEN {$nominalAnual} ELSE 0 END),0) AS nominal_anual,
    COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(p.frecuencia_pago,''))) <> 'anual' THEN {$nominalMensual} ELSE 0 END),0) AS nominal_mensual,

    COALESCE(SUM(
      CASE
WHEN LOWER(TRIM(COALESCE(p.frecuencia_pago,''))) = 'anual'
  AND COALESCE(p.id_franquicia,0) = 0
  AND COALESCE(p.id_distribuidor,0) = 0
  AND COALESCE(p.id_gestor,0) = 0
  THEN {$adminAnualDirecto}
        WHEN LOWER(TRIM(COALESCE(p.frecuencia_pago,''))) = 'anual'
          THEN {$adminAnualAsociado}
   WHEN COALESCE(p.id_franquicia,0) = 0
  AND COALESCE(p.id_distribuidor,0) = 0
  AND COALESCE(p.id_gestor,0) = 0
  THEN {$adminMensualDirecto}
        ELSE {$adminMensualAsociado}
      END
    ),0) AS comision_admin,

    COALESCE(SUM(
      CASE
        WHEN LOWER(TRIM(COALESCE(p.frecuencia_pago,''))) = 'anual' THEN {$unidadAnual}
        ELSE {$unidadMensual}
      END
    ),0) AS ingreso_hospital,

    COALESCE(SUM(
      CASE
        WHEN COALESCE(p.id_franquicia,0) <= 0 THEN 0
        WHEN LOWER(TRIM(COALESCE(p.frecuencia_pago,''))) = 'anual'
          AND COALESCE(p.id_distribuidor,0) <= 0
          THEN {$franqAnualDirecto}
        WHEN LOWER(TRIM(COALESCE(p.frecuencia_pago,''))) = 'anual'
          THEN {$franqAnualBase}
        WHEN COALESCE(p.id_distribuidor,0) <= 0
          THEN {$franqMensualDirecto}
        ELSE {$franqMensualBase}
      END
    ),0) AS comision_franquicia,

    COALESCE(SUM(
      CASE
        WHEN COALESCE(p.id_distribuidor,0) <= 0 THEN 0
        WHEN LOWER(TRIM(COALESCE(p.frecuencia_pago,''))) = 'anual' THEN {$distAnualBase}
        ELSE {$distMensualBase}
      END
    ),0) AS comision_distribuidor
  FROM pats_pasaportes p
  WHERE p.activo = 1
  " . pats_period_filter('p.created_at', $f) . "
");

$montoPatsReal = (float)($rowPatsOficial['ventas_real'] ?? 0);
$montoPatsNominal = (float)($rowPatsOficial['ventas_nominal'] ?? 0);
$montoRecargos = pats_num(max(0, $montoPatsReal - $montoPatsNominal));

$montoAdminPasaporte = (float)($rowPatsOficial['comision_admin'] ?? 0);
$montoHospitalPasaporte = (float)($rowPatsOficial['ingreso_hospital'] ?? 0);

$montoComisionFranquiciaPats = (float)($rowPatsOficial['comision_franquicia'] ?? 0);
$montoComisionDistribuidor = (float)($rowPatsOficial['comision_distribuidor'] ?? 0);

/* =========================================================
   PATS DIRECTOS ADMINPATS
   ---------------------------------------------------------
   Variables usadas por el JSON final.
   Evita warnings por variables indefinidas y calcula cuántos
   pasaportes fueron venta directa ADMINPATS, sin franquicia
   ni distribuidor asociado.

   Regla:
   - PATS directo mensual: AdminPATS conserva también los $20
     de franquicia + $80 de distribuidor = $100 reubicados.
   - PATS directo anual: AdminPATS conserva también los $240
     de franquicia + $960 de distribuidor = $1,200 reubicados.
========================================================= */
$rowPatsDirectosAdmin = pats_one($cx, "
  SELECT
    COALESCE(SUM(
      CASE
        WHEN LOWER(TRIM(COALESCE(p.frecuencia_pago,''))) = 'anual' THEN 1
        ELSE 0
      END
    ),0) AS directos_anual,

    COALESCE(SUM(
      CASE
        WHEN LOWER(TRIM(COALESCE(p.frecuencia_pago,''))) <> 'anual' THEN 1
        ELSE 0
      END
    ),0) AS directos_mensual
  FROM pats_pasaportes p
WHERE p.activo = 1
  AND COALESCE(p.id_franquicia,0) = 0
  AND COALESCE(p.id_distribuidor,0) = 0
  AND COALESCE(p.id_gestor,0) = 0
  " . pats_period_filter('p.created_at', $f) . "
");

$patsDirectosAdminAnual = (int)($rowPatsDirectosAdmin['directos_anual'] ?? 0);
$patsDirectosAdminMensual = (int)($rowPatsDirectosAdmin['directos_mensual'] ?? 0);

$reubicacionDirectaAdminPats = pats_num(
  ($patsDirectosAdminAnual * ($franqAnualBase + $distAnualBase)) +
  ($patsDirectosAdminMensual * ($franqMensualBase + $distMensualBase))
);

/* Mantiene estructura usada por charts y respuesta JSON. */
$rowPats = array_merge($rowPats, [
  'total_pasaportes' => (int)($rowPatsOficial['total_pasaportes'] ?? 0),
  'ventas_real' => $montoPatsReal,
  'ventas_nominal' => $montoPatsNominal,
  'ingreso_extra' => $montoRecargos,
  'real_anual' => (float)($rowPatsOficial['real_anual'] ?? 0),
  'real_mensual' => (float)($rowPatsOficial['real_mensual'] ?? 0),
  'nominal_anual' => (float)($rowPatsOficial['nominal_anual'] ?? 0),
  'nominal_mensual' => (float)($rowPatsOficial['nominal_mensual'] ?? 0),
  'total_anual' => (int)($rowPatsOficial['total_anual'] ?? 0),
  'total_mensual' => (int)($rowPatsOficial['total_mensual'] ?? 0),
  'comision_admin' => $montoAdminPasaporte,
  'ingreso_hospital' => $montoHospitalPasaporte,
  'comision_franquicia' => $montoComisionFranquiciaPats,
  'comision_distribuidor' => $montoComisionDistribuidor
]);

$montoComisionFranquicia = pats_num($montoComisionFranquiciaPats + $montoFranquiciatarioDistribuciones);

/* =========================================================
   GESTORES Y COPROPIEDAD
========================================================= */
$rowGestores = pats_one($cx, "
  SELECT COUNT(DISTINCT g.id_gestor) AS total_gestores
  FROM pats_gestores g
  WHERE g.activo = 1
");
$totalGestores = (int)($rowGestores['total_gestores'] ?? 0);

$rowFranqGestor = pats_one($cx, "
  SELECT
    COUNT(DISTINCT CASE WHEN f.tiene_gestor = 1 THEN f.id_franquicia END) AS con_gestor,
    COUNT(DISTINCT CASE WHEN f.tiene_gestor = 0 THEN f.id_franquicia END) AS sin_gestor,
    COUNT(DISTINCT CASE WHEN UPPER(TRIM(COALESCE(f.titularidad_tipo,''))) = 'COMPARTIDA' THEN f.id_franquicia END) AS compartidas,
    COUNT(DISTINCT CASE WHEN UPPER(TRIM(COALESCE(f.titularidad_tipo,''))) = 'INDIVIDUAL' THEN f.id_franquicia END) AS individuales
  FROM pats_franquicias f
  WHERE {$whereFranqCardsSql}
");

$franquiciasConGestor = (int)($rowFranqGestor['con_gestor'] ?? 0);
$franquiciasSinGestor = (int)($rowFranqGestor['sin_gestor'] ?? 0);
$franquiciasCompartidas = (int)($rowFranqGestor['compartidas'] ?? 0);
$franquiciasIndividuales = (int)($rowFranqGestor['individuales'] ?? 0);

$rowTitulares = pats_one($cx, "
  SELECT
    COALESCE(SUM(COALESCE(f.total_titulares,1)),0) AS titulares_registrados,
    COALESCE(SUM(
      CASE
        WHEN COALESCE(f.total_titulares,1) > 1
        THEN COALESCE(f.total_titulares,1) - 1
        ELSE 0
      END
    ),0) AS total_copropietarios,
    (
      SELECT COUNT(*)
      FROM pats_franquicia_titulares t
      INNER JOIN pats_franquicias ff
        ON ff.id_franquicia = t.id_franquicia
      WHERE t.activo = 1
        AND t.tiene_acceso = 1
        AND ff.activo = 1
        " . ($f['pais'] !== '' ? " AND ff.pais='" . addslashes($f['pais']) . "'" : "") . "
        " . ($f['region'] !== '' ? " AND ff.region='" . addslashes($f['region']) . "'" : "") . "
        " . ($f['zona'] !== '' ? " AND ff.zona='" . addslashes($f['zona']) . "'" : "") . "
    ) AS titulares_con_acceso
  FROM pats_franquicias f
  WHERE {$whereFranqCardsSql}
");

$titularesRegistrados = (int)($rowTitulares['titulares_registrados'] ?? 0);
$totalCopropietarios = (int)($rowTitulares['total_copropietarios'] ?? 0);
$titularesConAcceso = (int)($rowTitulares['titulares_con_acceso'] ?? 0);

/* Comisión GESTOR por distribución */
$rowComisionGestor = pats_one($cx, "
  SELECT
    COALESCE(SUM(cg.monto_comision),0) AS total,
    COALESCE(SUM(CASE WHEN LOWER(TRIM(cg.estatus)) = 'pagado' THEN cg.monto_comision ELSE 0 END),0) AS pagada,
    COALESCE(SUM(CASE WHEN LOWER(TRIM(cg.estatus)) IN ('por_pagar','pendiente','solicitado','en_revision','aprobado') THEN cg.monto_comision ELSE 0 END),0) AS pendiente
  FROM pats_comisiones_generadas cg
  INNER JOIN pats_distribuidores d
    ON d.id_distribuidor = cg.id_origen
  LEFT JOIN pats_franquicias f
    ON f.id_franquicia = d.id_franquicia
  WHERE cg.tipo_origen = 'venta_distribucion'
    AND LOWER(TRIM(cg.beneficiario_tipo)) = 'gestor'
    " . $distPaisFilter . "
    " . $distRegionFilter . "
    " . $distZonaFilter . "
    " . pats_period_filter('cg.fecha_generacion', $f)
);

$montoComisionGestor = (float)($rowComisionGestor['total'] ?? 0) + (float)($montoComisionGestorFallback ?? 0);
$montoComisionGestorPagada = (float)($rowComisionGestor['pagada'] ?? 0);
$montoComisionGestorPendiente = (float)($rowComisionGestor['pendiente'] ?? 0) + (float)($montoComisionGestorFallback ?? 0);

/* =========================================================
   NUEVOS / REACTIVADOS
========================================================= */
$nuevasFranquicias = pats_historial_count($cx, ['franquicia'], 'alta%', $f);
$reactivadasFranquicias = pats_historial_count($cx, ['franquicia'], 'reactiv%', $f);

$nuevasDistribuciones = pats_historial_count($cx, ['distribuidor', 'distribucion'], 'alta%', $f);
$reactivadasDistribuciones = pats_historial_count($cx, ['distribuidor', 'distribucion'], 'reactiv%', $f);

$nuevosPats = pats_historial_count($cx, ['pasaporte', 'pats', 'pats_pasaporte'], 'alta%', $f);
$reactivadosPats = pats_historial_count($cx, ['pasaporte', 'pats', 'pats_pasaporte'], 'reactiv%', $f);

/* =========================================================
   VENTAS GLOBALES
========================================================= */
$ventasGlobales = $montoFranquicias + $montoDistribuciones + $montoPatsReal;

/* =========================================================
   DINERO COBRADO Y PENDIENTE
   ---------------------------------------------------------
   Cálculo operativo para la card "Dinero cobrado y pendiente".
   No depende de pats_comisiones_generadas para saber cuánto se cobró.
   - Franquicias y distribuciones: pagos/contratos de actor cuando existen.
   - Si no hay contrato/pagos, usa fallback de venta operativa activa.
   - PATS: valor real de pasaportes activos/cobrados del periodo.
========================================================= */
$franquiciasMoney = pats_admin_calc_contratos_actor(
  $cx,
  "'franquicia','franquiciatario'",
  "INNER JOIN pats_franquicias f ON f.id_franquicia = ca.actor_id",
  "AND f.activo = 1
   " . ($f['pais'] !== '' ? " AND f.pais='" . addslashes($f['pais']) . "'" : "") . "
   " . ($f['region'] !== '' ? " AND f.region='" . addslashes($f['region']) . "'" : "") . "
   " . ($f['zona'] !== '' ? " AND f.zona='" . addslashes($f['zona']) . "'" : "") . "
   " . pats_period_filter('f.created_at', $f),
  $montoFranquicias
);

$distribucionesMoney = pats_admin_calc_contratos_actor(
  $cx,
  "'distribuidor'",
  "INNER JOIN pats_distribuidores d ON d.id_distribuidor = ca.actor_id
   LEFT JOIN pats_franquicias f ON f.id_franquicia = d.id_franquicia",
  "AND d.activo = 1
   " . $distPaisFilter . "
   " . $distRegionFilter . "
   " . $distZonaFilter . "
   " . pats_period_filter($distFechaExpr, $f),
  $montoDistribuciones
);

$dineroRecibidoFranquicias = pats_admin_money_num($franquiciasMoney['pagado'] ?? 0);
$dineroRecibidoDistribuciones = pats_admin_money_num($distribucionesMoney['pagado'] ?? 0);
$dineroRecibidoPats = pats_admin_money_num($montoPatsReal);

$saldoPendienteFranquicias = pats_admin_money_num($franquiciasMoney['saldo'] ?? 0);
$saldoPendienteDistribuciones = pats_admin_money_num($distribucionesMoney['saldo'] ?? 0);
$saldoPendientePats = pats_admin_money_num($rowPats['monto_vencido'] ?? 0);

$dineroRecibidoTotal = pats_admin_money_num($dineroRecibidoFranquicias + $dineroRecibidoDistribuciones + $dineroRecibidoPats);
$saldoPendienteTotal = pats_admin_money_num($saldoPendienteFranquicias + $saldoPendienteDistribuciones + $saldoPendientePats);


/* =========================================================
   INGRESOS ADMIN
========================================================= */
$totalComisiones =
  $montoComisionFranquicia
  + $montoComisionDistribuidor
  + $montoComisionGestor;

$ingresoAdminBruto = $ventasGlobales;
$ingresoAdminTotal = $ventasGlobales - $totalComisiones - $montoHospitalPasaporte;

$comisionesLiberadasTotal = pats_admin_money_num($totalComisiones);
$hospitalReal = pats_admin_money_num($montoHospitalPasaporte);
$ingresoAdminReal = pats_admin_money_num($dineroRecibidoTotal - $comisionesLiberadasTotal - $hospitalReal);


/* =========================================================
   INGRESOS HOSPITAL
   ---------------------------------------------------------
   Vista corporativa ADMINPATS: se muestra una sola bolsa hospitalaria.
   Los PATS sin unidad explícita también forman parte de la comisión
   hospitalaria; no se separan como "Hospital base".
========================================================= */
$hospitales = [[
  'hospital' => 'Comisión hospital',
  'ingreso_hospital' => pats_num($montoHospitalPasaporte)
]];

/* =========================================================
   CHARTS
========================================================= */
$chartDistribucion = [
  'labels' => ['AdminPATS', 'Hospital', 'Franquicia', 'Distribuidor', 'Gerente'],
  'values' => [
    $ingresoAdminTotal,
    $montoHospitalPasaporte,
    $montoComisionFranquicia,
    $montoComisionDistribuidor,
    $montoComisionGestor
  ]
];

$chartNominalRealExtra = [
  'labels' => ['Precio de lista', 'Monto vendido', 'Diferencia no cobrada'],
  'values' => [
    $valorCatalogoFranquicias,
    $montoFranquicias,
    $participacionAdminPats
  ]
];

$chartFrecuencia = [
  'labels' => ['PATS anuales', 'PATS mensuales', 'Recargos cobrados'],
  'values' => [
    (float)($rowPats['nominal_anual'] ?? 0),
    (float)($rowPats['nominal_mensual'] ?? 0),
    $montoRecargos
  ]
];

$chartCopropiedadGestor = [
  'labels' => ['Compartidas ADMINPATS', 'Individuales', 'Con gestor', 'Sin gestor'],
  'values' => [
    $franquiciasCompartidas,
    $franquiciasIndividuales,
    $franquiciasConGestor,
    $franquiciasSinGestor
  ]
];

/* =========================================================
   MENSUAL
========================================================= */
$mesesBase = [
  1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr',
  5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago',
  9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic'
];

$chartMensual = [];
for ($m = 1; $m <= 12; $m++) {
  $chartMensual[$m] = [
    'label' => $mesesBase[$m],
    'ingreso_total' => 0.0,
    'franquicias' => 0,
    'distribuidores' => 0,
    'pats' => 0,
    'gestores' => 0.0
  ];
}

$rsPatsMes = pats_all($cx, "
  SELECT
    MONTH(p.created_at) AS mes,
    COUNT(*) AS total,
    COALESCE(SUM(p.valor_final_pasaporte),0) AS monto
  FROM pats_pasaportes p
  WHERE p.activo = 1
    AND YEAR(p.created_at) = {$anio}
  GROUP BY MONTH(p.created_at)
");
foreach ($rsPatsMes as $row) {
  $mes = (int)($row['mes'] ?? 0);
  if ($mes >= 1 && $mes <= 12) {
    $chartMensual[$mes]['pats'] = (int)($row['total'] ?? 0);
    $chartMensual[$mes]['ingreso_total'] += (float)($row['monto'] ?? 0);
  }
}

$rsFranqMes = pats_all($cx, "
  SELECT
    MONTH(f.created_at) AS mes,
    COUNT(*) AS total,
    COALESCE(SUM(f.valor_franquicia),0) AS monto
  FROM pats_franquicias f
  WHERE f.activo = 1
    AND YEAR(f.created_at) = {$anio}
  GROUP BY MONTH(f.created_at)
");
foreach ($rsFranqMes as $row) {
  $mes = (int)($row['mes'] ?? 0);
  if ($mes >= 1 && $mes <= 12) {
    $chartMensual[$mes]['franquicias'] = (int)($row['total'] ?? 0);
    $chartMensual[$mes]['ingreso_total'] += (float)($row['monto'] ?? 0);
  }
}

$rsDistMes = pats_all($cx, "
  SELECT
    MONTH({$distFechaExpr}) AS mes,
    COUNT(*) AS total,
    COALESCE(SUM(d.valor_distribucion),0) AS monto
  FROM pats_distribuidores d
  WHERE d.activo = 1
    AND YEAR({$distFechaExpr}) = {$anio}
  GROUP BY MONTH({$distFechaExpr})
");
foreach ($rsDistMes as $row) {
  $mes = (int)($row['mes'] ?? 0);
  if ($mes >= 1 && $mes <= 12) {
    $chartMensual[$mes]['distribuidores'] = (int)($row['total'] ?? 0);
    $chartMensual[$mes]['ingreso_total'] += (float)($row['monto'] ?? 0);
  }
}

$rsGestMes = pats_all($cx, "
  SELECT
    MONTH(cg.fecha_generacion) AS mes,
    COALESCE(SUM(cg.monto_comision),0) AS monto
  FROM pats_comisiones_generadas cg
  INNER JOIN pats_distribuidores d
    ON d.id_distribuidor = cg.id_origen
  LEFT JOIN pats_franquicias f
    ON f.id_franquicia = d.id_franquicia
  WHERE cg.tipo_origen = 'venta_distribucion'
    AND LOWER(TRIM(cg.beneficiario_tipo)) = 'gestor'
    AND YEAR(cg.fecha_generacion) = {$anio}
    " . $distPaisFilter . "
    " . $distRegionFilter . "
    " . $distZonaFilter . "
  GROUP BY MONTH(cg.fecha_generacion)
");
foreach ($rsGestMes as $row) {
  $mes = (int)($row['mes'] ?? 0);
  if ($mes >= 1 && $mes <= 12) {
    $chartMensual[$mes]['gestores'] = (float)($row['monto'] ?? 0);
  }
}

$chartMensualAnual = [
  'labels' => array_values(array_map(fn($x) => $x['label'], $chartMensual)),
  'ingreso_total' => array_values(array_map(fn($x) => (float)$x['ingreso_total'], $chartMensual)),
  'franquicias' => array_values(array_map(fn($x) => (int)$x['franquicias'], $chartMensual)),
  'distribuidores' => array_values(array_map(fn($x) => (int)$x['distribuidores'], $chartMensual)),
  'pats' => array_values(array_map(fn($x) => (int)$x['pats'], $chartMensual)),
  'gestores' => array_values(array_map(fn($x) => (float)$x['gestores'], $chartMensual))
];

/* =========================================================
   RANKINGS
========================================================= */
/*
  Ranking de PATS activos por región.
  IMPORTANTE: debe contar TODOS los pasaportes activos, aunque sean
  venta directa ADMINPATS y no tengan id_franquicia / id_distribuidor.
*/
$patsRegionExpr = "
  CASE
    WHEN COALESCE(p.id_franquicia,0) = 0
      AND COALESCE(p.id_distribuidor,0) = 0
      AND COALESCE(p.id_gestor,0) > 0
      THEN 'GERENTE DIRECTO'

    WHEN COALESCE(p.id_franquicia,0) = 0
      AND COALESCE(p.id_distribuidor,0) = 0
      AND COALESCE(p.id_gestor,0) = 0
      THEN 'ADMINPATS DIRECTO'

    ELSE COALESCE(NULLIF(TRIM(p.region),''), NULLIF(TRIM(f.region),''), 'SIN REGIÓN')
  END
";

$patsPaisExpr = "COALESCE(NULLIF(TRIM(p.pais),''), NULLIF(TRIM(f.pais),''), '')";
$patsZonaExpr = "COALESCE(NULLIF(TRIM(p.zona),''), NULLIF(TRIM(f.zona),''), '')";

$patsPaisFilter = '';
if ($f['pais'] !== '') {
  $paisEsc = addslashes($f['pais']);
  $patsPaisFilter = " AND (
    UPPER(TRIM(COALESCE(p.pais,''))) = UPPER('{$paisEsc}')
    OR UPPER(TRIM(COALESCE(f.pais,''))) = UPPER('{$paisEsc}')
    OR (UPPER('{$paisEsc}') IN ('MX','MEXICO','MÉXICO') AND UPPER(TRIM(COALESCE(p.pais,''))) IN ('MX','MEXICO','MÉXICO'))
    OR (UPPER('{$paisEsc}') IN ('MX','MEXICO','MÉXICO') AND UPPER(TRIM(COALESCE(f.pais,''))) IN ('MX','MEXICO','MÉXICO'))
  )";
}

$patsRegionFilter = $f['region'] !== ''
  ? " AND (p.region='" . addslashes($f['region']) . "' OR f.region='" . addslashes($f['region']) . "')"
  : '';

$patsZonaFilter = $f['zona'] !== ''
  ? " AND (p.zona='" . addslashes($f['zona']) . "' OR f.zona='" . addslashes($f['zona']) . "')"
  : '';

$rankingRegionesPatsActivos = pats_all($cx, "
  SELECT
    {$patsRegionExpr} AS region,
    COUNT(*) AS total_pats_activos
  FROM pats_pasaportes p
  LEFT JOIN pats_franquicias f
    ON f.id_franquicia = p.id_franquicia
  WHERE p.activo = 1
    AND LOWER(TRIM(COALESCE(p.estatus,''))) = 'activo'
    " . $patsPaisFilter . "
    " . $patsRegionFilter . "
    " . $patsZonaFilter . "
  GROUP BY {$patsRegionExpr}
  ORDER BY total_pats_activos DESC, region ASC
  LIMIT 10
");

$rowsIngresoFranqRegion = pats_all($cx, "
  SELECT
    f.region,
    COALESCE(SUM(f.valor_franquicia), 0) AS ingreso_franquicias
  FROM pats_franquicias f
  WHERE f.activo = 1
    " . ($f['pais'] !== '' ? " AND f.pais='" . addslashes($f['pais']) . "'" : "") . "
    " . ($f['region'] !== '' ? " AND f.region='" . addslashes($f['region']) . "'" : "") . "
    " . ($f['zona'] !== '' ? " AND f.zona='" . addslashes($f['zona']) . "'" : "") . "
    " . pats_period_filter('f.created_at', $f) . "
  GROUP BY f.region
");

$rowsIngresoDistRegion = pats_all($cx, "
  SELECT
    {$distRegionExpr} AS region,
    COALESCE(SUM(d.valor_distribucion), 0) AS ingreso_distribuciones
  FROM pats_distribuidores d
  LEFT JOIN pats_franquicias f
    ON f.id_franquicia = d.id_franquicia
  WHERE d.activo = 1
    " . $distPaisFilter . "
    " . $distRegionFilter . "
    " . $distZonaFilter . "
    " . pats_period_filter($distFechaExpr, $f) . "
  GROUP BY {$distRegionExpr}
");

$rowsIngresoPatsRegion = pats_all($cx, "
  SELECT
    {$patsRegionExpr} AS region,
    COALESCE(SUM(
      CASE
        WHEN COALESCE(p.valor_final_pasaporte,0) > 0 THEN p.valor_final_pasaporte
        WHEN COALESCE(p.valor_pasaporte,0) > 0 THEN p.valor_pasaporte
        WHEN LOWER(TRIM(COALESCE(p.frecuencia_pago,''))) = 'anual' THEN 9600
        ELSE 800
      END
    ), 0) AS ingreso_pats
  FROM pats_pasaportes p
  LEFT JOIN pats_franquicias f
    ON f.id_franquicia = p.id_franquicia
  WHERE p.activo = 1
    " . $patsPaisFilter . "
    " . $patsRegionFilter . "
    " . $patsZonaFilter . "
    " . pats_period_filter('p.created_at', $f) . "
  GROUP BY {$patsRegionExpr}
");

$rankingIngresosMap = [];
foreach ($regiones as $row) {
  $regionKey = (string)($row['region'] ?? '');
  if ($regionKey === '') continue;

  $rankingIngresosMap[$regionKey] = [
    'region' => $regionKey,
    'ingreso_franquicias' => 0.0,
    'ingreso_distribuciones' => 0.0,
    'ingreso_pats' => 0.0,
    'ingreso_total' => 0.0
  ];
}

foreach ($rowsIngresoFranqRegion as $row) {
  $regionKey = (string)($row['region'] ?? '');
  if ($regionKey === '') continue;
  if (!isset($rankingIngresosMap[$regionKey])) {
    $rankingIngresosMap[$regionKey] = [
      'region' => $regionKey,
      'ingreso_franquicias' => 0.0,
      'ingreso_distribuciones' => 0.0,
      'ingreso_pats' => 0.0,
      'ingreso_total' => 0.0
    ];
  }
  $rankingIngresosMap[$regionKey]['ingreso_franquicias'] = (float)($row['ingreso_franquicias'] ?? 0);
}

foreach ($rowsIngresoDistRegion as $row) {
  $regionKey = (string)($row['region'] ?? '');
  if ($regionKey === '') continue;
  if (!isset($rankingIngresosMap[$regionKey])) {
    $rankingIngresosMap[$regionKey] = [
      'region' => $regionKey,
      'ingreso_franquicias' => 0.0,
      'ingreso_distribuciones' => 0.0,
      'ingreso_pats' => 0.0,
      'ingreso_total' => 0.0
    ];
  }
  $rankingIngresosMap[$regionKey]['ingreso_distribuciones'] = (float)($row['ingreso_distribuciones'] ?? 0);
}

foreach ($rowsIngresoPatsRegion as $row) {
  $regionKey = (string)($row['region'] ?? '');
  if ($regionKey === '') continue;
  if (!isset($rankingIngresosMap[$regionKey])) {
    $rankingIngresosMap[$regionKey] = [
      'region' => $regionKey,
      'ingreso_franquicias' => 0.0,
      'ingreso_distribuciones' => 0.0,
      'ingreso_pats' => 0.0,
      'ingreso_total' => 0.0
    ];
  }
  $rankingIngresosMap[$regionKey]['ingreso_pats'] = (float)($row['ingreso_pats'] ?? 0);
}

$rankingIngresos = array_values(array_map(function ($row) {
  $row['ingreso_total'] =
    (float)($row['ingreso_franquicias'] ?? 0) +
    (float)($row['ingreso_distribuciones'] ?? 0) +
    (float)($row['ingreso_pats'] ?? 0);
  return $row;
}, $rankingIngresosMap));

usort($rankingIngresos, function ($a, $b) {
  return (float)($b['ingreso_total'] ?? 0) <=> (float)($a['ingreso_total'] ?? 0);
});
$rankingIngresos = array_slice($rankingIngresos, 0, 10);

/* =========================================================
   RESPUESTA
========================================================= */
pats_json([
  'ok' => true,
  'sync_comisiones_cache' => $patsSyncComisionesCache,
  'regiones' => $regiones,
  'hospitales' => $hospitales,
  'kpis' => [
    'total_franquicias' => $totalFranquicias,
    'valor_catalogo_franquicias' => $valorCatalogoFranquicias,
    'monto_franquicias' => $montoFranquicias,
    'participacion_adminpats' => $participacionAdminPats,

    'total_distribuciones' => $totalDistribuciones,
    'monto_distribuciones' => $montoDistribuciones,
    'monto_admin_distribuciones' => $montoAdminDistribuciones,
    'monto_admin_distribuciones_generado' => $montoAdminDistribucionesGenerado,
    'monto_admin_distribuciones_fallback' => $montoAdminDistribucionesFallback,
    'monto_franquiciatario_distribuciones' => $montoFranquiciatarioDistribuciones,
    'monto_franquiciatario_distribuciones_fallback' => $montoFranquiciatarioDistribucionesFallback,

    'monto_pats_real' => $montoPatsReal,
    'monto_pats_nominal' => $montoPatsNominal,
    'monto_recargos' => $montoRecargos,

    'monto_admin_pasaporte' => $montoAdminPasaporte,
    'monto_hospital_pasaporte' => $montoHospitalPasaporte,

    'monto_comision_franquicia_pats' => $montoComisionFranquiciaPats,
    'monto_comision_franquicia' => $montoComisionFranquicia,
    'monto_comision_distribuidor' => $montoComisionDistribuidor,
    'pats_directos_admin_anual' => $patsDirectosAdminAnual,
    'pats_directos_admin_mensual' => $patsDirectosAdminMensual,
    'reubicacion_directa_admin_pats' => $reubicacionDirectaAdminPats,

    'total_gestores' => $totalGestores,
    'franquicias_con_gestor' => $franquiciasConGestor,
    'franquicias_sin_gestor' => $franquiciasSinGestor,
    'franquicias_compartidas' => $franquiciasCompartidas,
    'franquicias_individuales' => $franquiciasIndividuales,
    'total_copropietarios' => $totalCopropietarios,
    'titulares_registrados' => $titularesRegistrados,
    'titulares_con_acceso' => $titularesConAcceso,

    'monto_comision_gestor' => $montoComisionGestor,
    'monto_comision_gestor_fallback' => $montoComisionGestorFallback,
    'monto_comision_gestor_pagada' => $montoComisionGestorPagada,
    'monto_comision_gestor_pendiente' => $montoComisionGestorPendiente,

    'nuevas_franquicias' => $nuevasFranquicias,
    'reactivadas_franquicias' => $reactivadasFranquicias,
    'nuevas_distribuciones' => $nuevasDistribuciones,
    'reactivadas_distribuciones' => $reactivadasDistribuciones,
    'nuevos_pats' => $nuevosPats,
    'reactivados_pats' => $reactivadosPats,

    /* =====================================================
       Dinero cobrado y pendiente
       -----------------------------------------------------
       Se entregan nombres nuevos y aliases legacy porque el JS
       actual de admin.php pinta algunos IDs con nombres anteriores.
    ===================================================== */
    'dinero_recibido_total' => $dineroRecibidoTotal,
    'dinero_recibido_franquicias' => $dineroRecibidoFranquicias,
    'dinero_recibido_distribuciones' => $dineroRecibidoDistribuciones,
    'dinero_recibido_pats' => $dineroRecibidoPats,

    'saldo_pendiente_total' => $saldoPendienteTotal,
    'saldo_pendiente_franquicias' => $saldoPendienteFranquicias,
    'saldo_pendiente_distribuciones' => $saldoPendienteDistribuciones,
    'saldo_pendiente_pats' => $saldoPendientePats,

    'hospital_real' => $hospitalReal,
    'comisiones_liberadas' => $comisionesLiberadasTotal,
    'ingreso_admin_real' => $ingresoAdminReal,

    /* Aliases usados por pats_admin.js actual */
    'ingreso_real_depositado' => $dineroRecibidoTotal,
    'ingreso_real_franquicias' => $dineroRecibidoFranquicias,
    'ingreso_real_distribuciones' => $dineroRecibidoDistribuciones,
    'ingreso_real_pats' => $dineroRecibidoPats,
    'saldo_pendiente_contratos' => $saldoPendienteTotal,
    'hospital_real_pagado' => $hospitalReal,
    'comisiones_reales_liberadas' => $comisionesLiberadasTotal,
    'ventas_contratadas' => $ventasGlobales,

    /* Aliases de compatibilidad / lectura clara */
    'total_cobrado' => $dineroRecibidoTotal,
    'por_cobrar' => $saldoPendienteTotal,
    'franquicias_cobradas' => $dineroRecibidoFranquicias,
    'distribuciones_cobradas' => $dineroRecibidoDistribuciones,
    'pats_cobrados' => $dineroRecibidoPats,
    'ingreso_real_adminpats' => $ingresoAdminReal,

    'ventas_globales' => $ventasGlobales,
    'ingreso_admin_bruto' => $ingresoAdminBruto,
    'ingreso_admin_total' => $ingresoAdminTotal
  ],
  'charts' => [
    'distribucion' => $chartDistribucion,
    'nominal_real_extra' => $chartNominalRealExtra,
    'frecuencia' => $chartFrecuencia,
    'copropiedad_gestor' => $chartCopropiedadGestor,
    'mensual_anual' => $chartMensualAnual
  ],
  'rankings' => [
    'regiones_pats_activos' => $rankingRegionesPatsActivos,
    'ingresos_region' => $rankingIngresos
  ]
]);