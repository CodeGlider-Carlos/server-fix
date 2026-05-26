<?php
/*
ez/pats/endpoints/dashboard_mis_franquicias.php
*/
require_once __DIR__ . '/bootstrap.php';

$f = pats_filters();

/*
|--------------------------------------------------------------------------
| REGLA:
| Mis Franquicias debe listar TODAS las franquicias de la región/scope.
| NO debe cerrarse por zona para el listado principal de cards.
|--------------------------------------------------------------------------
*/
$whereFranq = ["f.activo = 1"];

if ($f['region'] !== '') {
  $whereFranq[] = "f.region = '" . addslashes($f['region']) . "'";
}

/* OJO:
   intencionalmente NO filtramos por zona aquí,
   para que se muestren todas las franquicias de la región.
*/

if ($f['anio'] > 0) {
  $whereFranq[] = "YEAR(f.created_at) = " . (int)$f['anio'];
}

if ($f['mes'] > 0) {
  $whereFranq[] = "MONTH(f.created_at) = " . (int)$f['mes'];
}

$whereFranqSql = implode(' AND ', $whereFranq);

$rows = pats_all($cx, "
  SELECT
    f.id_franquicia,
    f.nombre_franquicia,
    f.razon_social,
    f.franquiciatario,
    f.region,
    f.zona,
    f.unidad,
    f.telefono,
    f.correo,
    (
      SELECT COUNT(*)
      FROM pats_distribuidores d
      WHERE d.id_franquicia = f.id_franquicia
        AND d.activo = 1
    ) AS total_distribuidores
  FROM pats_franquicias f
  WHERE {$whereFranqSql}
  ORDER BY f.nombre_franquicia ASC
");

$franquicias = [];
$kpis = [
  'ventas_real' => 0.0,
  'ventas_nominal' => 0.0,
  'ingreso_extra' => 0.0,
  'monto_vencido' => 0.0,
  'activos' => 0,
  'vencidos' => 0,

  'ventas_distribuciones' => 0.0,
  'ventas_totales' => 0.0,

  'comision_admin_distribuciones' => 0.0,
  'comision_franquicia_distribuciones' => 0.0,
  'comision_gestor_distribuciones' => 0.0
];

foreach ($rows as $row) {
  $idFranquicia = (int)($row['id_franquicia'] ?? 0);

  $wherePats = [
    "p.id_franquicia = {$idFranquicia}"
  ];

  /* =========================================================
     RESPETAR FILTROS DE COMPORTAMIENTO / PERIODO
     OJO:
     - NO cerramos la lista principal por zona
     - pero sí respetamos región y periodo en métricas
  ========================================================= */
  if ($f['region'] !== '') {
    $wherePats[] = "p.region = '" . addslashes($f['region']) . "'";
  }

  if ($f['anio'] > 0) {
    $wherePats[] = "YEAR(p.created_at) = " . (int)$f['anio'];
  }

  if ($f['mes'] > 0) {
    $wherePats[] = "MONTH(p.created_at) = " . (int)$f['mes'];
  }

  $fin = pats_metricas_financieras(
    $cx,
    implode(' AND ', $wherePats),
    'p'
  );

  $ventasPats = (float)($fin['ventas_real'] ?? 0);

  /* =========================================================
     VENTAS REALES DE DISTRIBUCIONES
     YA NO USAR total_distribuidores * 20000
  ========================================================= */
  $rowDist = pats_one($cx, "
    SELECT
      COUNT(*) AS total_distribuciones,
      COALESCE(SUM(d.valor_distribucion),0) AS ventas_distribuciones
    FROM pats_distribuidores d
    WHERE d.id_franquicia = {$idFranquicia}
      AND d.activo = 1
      " . ($f['region'] !== '' ? " AND d.region = '" . addslashes($f['region']) . "'" : "") . "
      " . ($f['anio'] > 0 ? " AND YEAR(d.created_at) = " . (int)$f['anio'] : "") . "
      " . ($f['mes'] > 0 ? " AND MONTH(d.created_at) = " . (int)$f['mes'] : "") . "
  ");

  $ventasDistribuciones = (float)($rowDist['ventas_distribuciones'] ?? 0);
  $totalDistribuciones = (int)($rowDist['total_distribuciones'] ?? 0);

  /* =========================================================
     COMISIONES REALES POR DISTRIBUCION
     DESDE pats_comisiones_generadas
  ========================================================= */
  $rowComDist = pats_one($cx, "
    SELECT
      COALESCE(SUM(CASE
        WHEN LOWER(TRIM(cg.beneficiario_tipo)) = 'admin'
        THEN cg.monto_comision ELSE 0 END),0) AS comision_admin_distribuciones,

      COALESCE(SUM(CASE
        WHEN LOWER(TRIM(cg.beneficiario_tipo)) = 'franquicia'
        THEN cg.monto_comision ELSE 0 END),0) AS comision_franquicia_distribuciones,

      COALESCE(SUM(CASE
        WHEN LOWER(TRIM(cg.beneficiario_tipo)) = 'gestor'
        THEN cg.monto_comision ELSE 0 END),0) AS comision_gestor_distribuciones

    FROM pats_comisiones_generadas cg
    INNER JOIN pats_distribuidores d
      ON d.id_distribuidor = cg.id_origen
     AND d.id_franquicia = {$idFranquicia}
    WHERE cg.tipo_origen = 'venta_distribucion'
      " . ($f['region'] !== '' ? " AND d.region = '" . addslashes($f['region']) . "'" : "") . "
      " . ($f['anio'] > 0 ? " AND YEAR(cg.fecha_generacion) = " . (int)$f['anio'] : "") . "
      " . ($f['mes'] > 0 ? " AND MONTH(cg.fecha_generacion) = " . (int)$f['mes'] : "") . "
  ");

  $comisionAdminDistribuciones = (float)($rowComDist['comision_admin_distribuciones'] ?? 0);
  $comisionFranquiciaDistribuciones = (float)($rowComDist['comision_franquicia_distribuciones'] ?? 0);
  $comisionGestorDistribuciones = (float)($rowComDist['comision_gestor_distribuciones'] ?? 0);

  /* =========================================================
     FALLBACK VISUAL SEGURO
     ---------------------------------------------------------
     Si la distribución ya existe, pero todavía no se generó
     pats_comisiones_generadas, no dejamos las comisiones en cero.

     Mínimo seguro para no romper el endpoint:
     - AdminPATS 50%
     - Franquicia 50%
     - Gestor 0 aquí, salvo que ya venga generado en comisiones.

     Esto NO actualiza tablas. Solo corrige la visualización.
  ========================================================= */
  if (
    $ventasDistribuciones > 0
    && $comisionAdminDistribuciones <= 0
    && $comisionFranquiciaDistribuciones <= 0
    && $comisionGestorDistribuciones <= 0
  ) {
    $comisionAdminDistribuciones = pats_num($ventasDistribuciones * 0.50);
    $comisionFranquiciaDistribuciones = pats_num($ventasDistribuciones * 0.50);
    $comisionGestorDistribuciones = 0.0;
  }

  $item = array_merge($row, [
    'ventas_real' => $ventasPats,
    'ventas_nominal' => (float)($fin['ventas_nominal'] ?? 0),
    'ingreso_extra' => (float)($fin['ingreso_extra'] ?? 0),
    'monto_vencido' => (float)($fin['monto_vencido'] ?? 0),
    'activos' => (int)($fin['activos'] ?? 0),
    'vencidos' => (int)($fin['vencidos'] ?? 0),

    /* PATS normales */
    'comision_admin' => (float)($fin['comision_admin'] ?? 0),
    'ingreso_hospital' => (float)($fin['ingreso_hospital'] ?? 0),
    'comision_franquicia_pats' => (float)($fin['comision_franquicia'] ?? 0),
    'comision_distribuidor_pats' => (float)($fin['comision_distribuidor'] ?? 0),

    /* Distribuciones reales */
    'total_distribuciones_periodo' => $totalDistribuciones,
    'ventas_distribuciones' => $ventasDistribuciones,

    /* Comisiones reales / fallback por distribución */
    'comision_admin_distribuciones' => $comisionAdminDistribuciones,
    'comision_franquicia_distribuciones' => $comisionFranquiciaDistribuciones,
    'comision_gestor_distribuciones' => $comisionGestorDistribuciones,

    /* Totales */
    'ventas_totales' => pats_num($ventasPats + $ventasDistribuciones)
  ]);

  $franquicias[] = $item;

  $kpis['ventas_real'] += (float)$item['ventas_real'];
  $kpis['ventas_nominal'] += (float)$item['ventas_nominal'];
  $kpis['ingreso_extra'] += (float)$item['ingreso_extra'];
  $kpis['monto_vencido'] += (float)$item['monto_vencido'];
  $kpis['activos'] += (int)$item['activos'];
  $kpis['vencidos'] += (int)$item['vencidos'];

  $kpis['ventas_distribuciones'] += (float)$item['ventas_distribuciones'];
  $kpis['ventas_totales'] += (float)$item['ventas_totales'];

  $kpis['comision_admin_distribuciones'] += (float)$item['comision_admin_distribuciones'];
  $kpis['comision_franquicia_distribuciones'] += (float)$item['comision_franquicia_distribuciones'];
  $kpis['comision_gestor_distribuciones'] += (float)$item['comision_gestor_distribuciones'];
}

$kpis['ventas_real'] = pats_num($kpis['ventas_real']);
$kpis['ventas_nominal'] = pats_num($kpis['ventas_nominal']);
$kpis['ingreso_extra'] = pats_num($kpis['ingreso_extra']);
$kpis['monto_vencido'] = pats_num($kpis['monto_vencido']);

$kpis['ventas_distribuciones'] = pats_num($kpis['ventas_distribuciones']);
$kpis['ventas_totales'] = pats_num($kpis['ventas_totales']);

$kpis['comision_admin_distribuciones'] = pats_num($kpis['comision_admin_distribuciones']);
$kpis['comision_franquicia_distribuciones'] = pats_num($kpis['comision_franquicia_distribuciones']);
$kpis['comision_gestor_distribuciones'] = pats_num($kpis['comision_gestor_distribuciones']);

pats_json([
  'ok' => true,
  'filters' => $f,
  'kpis' => $kpis,
  'franquicias' => $franquicias
]);
