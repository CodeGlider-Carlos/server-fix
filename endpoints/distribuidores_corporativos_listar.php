<?php
/*
Archivo: ez/pats/endpoints/distribuidores_corporativos_listar.php
M車dulo: PATS
Prop車sito: Endpoint para listar distribuidores directos de ADMINPATS/corporativo.
Responsabilidad:
  - Consultar distribuidores corporativos directos reales.
  - Excluir distribuidores asociados a franquicia y, si existe columna, excluir distribuidores asociados a gestor.
  - Calcular resumen operativo, contrato, saldo, PATS vendidos, comisiones y liga al dashboard individual.
  - No ocultar distribuidores por fecha de alta cuando tienen ventas/contratos activos fuera del periodo de creaci車n.
Conexiones: bootstrap.php, pats_distribuidores, pats_pasaportes, pats_contratos_actor, pats_pagos_actor, pats_parcialidades_actor, pats_comisiones_generadas.
Tipo: Endpoint espec赤fico de PATS para ADMIN/ADMINPATS y roles directivos autorizados.
*/

require_once __DIR__ . '/bootstrap.php';

mysqli_report(MYSQLI_REPORT_OFF);

if (!in_array($PATS_ROLE, $PATS_ADMIN_ROLES, true)) {
  pats_json(['ok' => false, 'error' => 'Acceso denegado'], 403);
}

if (!function_exists('pdc_table_exists')) {
  function pdc_table_exists(mysqli $cx, string $table): bool {
    if (function_exists('pats_table_exists')) return pats_table_exists($cx, $table);
    $tableEsc = $cx->real_escape_string($table);
    $rs = $cx->query("SHOW TABLES LIKE '{$tableEsc}'");
    if (!$rs) return false;
    $ok = $rs->num_rows > 0;
    $rs->free();
    return $ok;
  }
}

if (!function_exists('pdc_column_exists')) {
  function pdc_column_exists(mysqli $cx, string $table, string $column): bool {
    if (function_exists('pats_column_exists')) return pats_column_exists($cx, $table, $column);
    $tableEsc = $cx->real_escape_string($table);
    $columnEsc = $cx->real_escape_string($column);
    $rs = $cx->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$columnEsc}'");
    if (!$rs) return false;
    $ok = $rs->num_rows > 0;
    $rs->free();
    return $ok;
  }
}

if (!function_exists('pdc_first_col')) {
  function pdc_first_col(mysqli $cx, string $table, array $cols): string {
    foreach ($cols as $col) {
      if (pdc_column_exists($cx, $table, $col)) return $col;
    }
    return '';
  }
}

if (!function_exists('pdc_period_filter')) {
  function pdc_period_filter(string $field, int $anio, int $mes): string {
    $parts = [];
    if ($anio > 0) $parts[] = "YEAR({$field}) = {$anio}";
    if ($mes > 0 && $mes <= 12) $parts[] = "MONTH({$field}) = {$mes}";
    return $parts ? (' AND ' . implode(' AND ', $parts)) : '';
  }
}

if (!function_exists('pdc_num')) {
  function pdc_num($v): float {
    return round((float)($v ?? 0), 2);
  }
}

if (!function_exists('pdc_norm')) {
  function pdc_norm($v): string {
    return trim((string)($v ?? ''));
  }
}

if (!function_exists('pdc_sql_value')) {
  function pdc_sql_value(mysqli $cx, string $table, string $col, string $fallback): string {
    return pdc_column_exists($cx, $table, $col) ? $col : $fallback;
  }
}

/* =========================================================
   Filtros
========================================================= */
$q = trim((string)($_GET['q'] ?? ''));
$region = trim((string)($_GET['region'] ?? ''));
$zona = trim((string)($_GET['zona'] ?? ''));
$anio = (int)($_GET['anio'] ?? date('Y'));
$mes = (int)($_GET['mes'] ?? 0);

/* =========================================================
   Columnas seguras
========================================================= */
$hasDistIdGestor = pdc_column_exists($cx, 'pats_distribuidores', 'id_gestor');
$hasDistIdGestorAsociado = pdc_column_exists($cx, 'pats_distribuidores', 'id_gestor_asociado');
$hasDistOrigen = pdc_column_exists($cx, 'pats_distribuidores', 'origen_comercial');
$hasDistVentaAdmin = pdc_column_exists($cx, 'pats_distribuidores', 'venta_directa_adminpats');
$hasDistTokenUpdated = pdc_column_exists($cx, 'pats_distribuidores', 'public_checkout_updated_at');

$distFechaExpr = pdc_column_exists($cx, 'pats_distribuidores', 'fecha_alta')
  ? "COALESCE(NULLIF(d.fecha_alta, '0000-00-00'), DATE(d.created_at))"
  : "DATE(d.created_at)";

$distGestorExpr = "0";
if ($hasDistIdGestor && $hasDistIdGestorAsociado) {
  $distGestorExpr = "COALESCE(d.id_gestor, d.id_gestor_asociado, 0)";
} elseif ($hasDistIdGestor) {
  $distGestorExpr = "COALESCE(d.id_gestor,0)";
} elseif ($hasDistIdGestorAsociado) {
  $distGestorExpr = "COALESCE(d.id_gestor_asociado,0)";
}

$origenExpr = $hasDistOrigen ? "COALESCE(d.origen_comercial,'')" : "''";
$ventaAdminExpr = $hasDistVentaAdmin ? "COALESCE(d.venta_directa_adminpats,0)" : "0";
$tokenUpdatedExpr = $hasDistTokenUpdated ? "d.public_checkout_updated_at" : "NULL";

/* =========================================================
   WHERE PRINCIPAL
   ---------------------------------------------------------
   Importante:
   - Este endpoint es para distribuidores corporativos directos.
   - No se filtra por fecha de alta del distribuidor en el WHERE principal,
     porque un distribuidor creado antes puede tener PATS/contrato/pagos en el periodo actual.
========================================================= */
$where = [
  'd.activo = 1',
  'COALESCE(d.id_franquicia,0) = 0',
  "COALESCE({$distGestorExpr},0) = 0"
];

/*
  Si el maestro tiene origen_comercial / venta_directa_adminpats, reforzamos que sea corporativo.
  Si no existe origen, id_franquicia=0 e id_gestor=0 sigue siendo el criterio seguro.
*/
if ($hasDistOrigen || $hasDistVentaAdmin) {
  $where[] = "(
    {$ventaAdminExpr} = 1
    OR UPPER(TRIM({$origenExpr})) IN ('', 'ADMINPATS_DIRECTO', 'CORPORATIVO', 'CORPORATIVO_DIRECTO')
  )";
}

if ($q !== '') {
  $qEsc = $cx->real_escape_string(mb_strtolower($q));
  $where[] = "(
    LOWER(TRIM(COALESCE(d.nombre,''))) LIKE '%{$qEsc}%'
    OR LOWER(TRIM(COALESCE(d.correo,''))) LIKE '%{$qEsc}%'
    OR LOWER(TRIM(COALESCE(d.telefono,''))) LIKE '%{$qEsc}%'
    OR LOWER(TRIM(COALESCE(d.region,''))) LIKE '%{$qEsc}%'
    OR LOWER(TRIM(COALESCE(d.zona,''))) LIKE '%{$qEsc}%'
    OR LOWER(TRIM(COALESCE(d.unidad,''))) LIKE '%{$qEsc}%'
  )";
}

if ($region !== '') {
  $where[] = "LOWER(TRIM(COALESCE(d.region,''))) = LOWER(TRIM('" . $cx->real_escape_string($region) . "'))";
}

if ($zona !== '') {
  $where[] = "LOWER(TRIM(COALESCE(d.zona,''))) = LOWER(TRIM('" . $cx->real_escape_string($zona) . "'))";
}

$whereSql = implode(' AND ', $where);

$periodPats = pdc_period_filter('p.created_at', $anio, $mes);
$periodCom = pdc_period_filter('cg.fecha_generacion', $anio, $mes);

/* =========================================================
   Subconsulta PATS vendidos por distribuidor
========================================================= */
$patsSub = "
  SELECT
    p.id_distribuidor,
    COUNT(CASE WHEN p.activo = 1 THEN 1 END) AS total_pasaportes,
    SUM(CASE WHEN LOWER(TRIM(COALESCE(p.estatus,''))) = 'activo' AND p.activo = 1 THEN 1 ELSE 0 END) AS pats_activos,
    SUM(CASE WHEN LOWER(TRIM(COALESCE(p.estatus,''))) = 'vencido' AND p.activo = 1 THEN 1 ELSE 0 END) AS pats_vencidos,
    COALESCE(SUM(
      CASE
        WHEN p.activo <> 1 THEN 0
        WHEN COALESCE(p.valor_final_pasaporte,0) > 0 THEN p.valor_final_pasaporte
        WHEN COALESCE(p.valor_pasaporte,0) > 0 THEN p.valor_pasaporte
        WHEN LOWER(TRIM(COALESCE(p.frecuencia_pago,''))) = 'anual' THEN 9600
        ELSE 800
      END
    ),0) AS ventas_pats
  FROM pats_pasaportes p
  WHERE COALESCE(p.id_distribuidor,0) > 0
    {$periodPats}
  GROUP BY p.id_distribuidor
";

/* =========================================================
   Subconsulta comisiones de distribuidor por PATS
   ---------------------------------------------------------
   La venta/alta de distribuci車n corporativa NO es comisi車n del distribuidor.
   Solo se toma lo que est谷 generado como beneficiario distribuidor.
========================================================= */
$comSub = "
  SELECT
    cg.beneficiario_id AS id_distribuidor,
    COALESCE(SUM(cg.monto_comision),0) AS comision_total,
    COALESCE(SUM(CASE WHEN LOWER(TRIM(cg.estatus)) = 'pagado' THEN cg.monto_comision ELSE 0 END),0) AS comision_pagada,
    COALESCE(SUM(CASE WHEN LOWER(TRIM(cg.estatus)) IN ('por_pagar','pendiente','solicitado','en_revision','aprobado') THEN cg.monto_comision ELSE 0 END),0) AS comision_pendiente
  FROM pats_comisiones_generadas cg
  WHERE LOWER(TRIM(cg.beneficiario_tipo)) = 'distribuidor'
    {$periodCom}
  GROUP BY cg.beneficiario_id
";

/* =========================================================
   Contratos / pagos / parcialidades
========================================================= */
$hasContratos = pdc_table_exists($cx, 'pats_contratos_actor');
$hasPagos = pdc_table_exists($cx, 'pats_pagos_actor');
$hasParcialidades = pdc_table_exists($cx, 'pats_parcialidades_actor');

$contratoJoin = "LEFT JOIN (SELECT 0 AS actor_id, 0 AS id_contrato, 0 AS valor_total, 0 AS total_pagado, 0 AS saldo_pendiente, '' AS modalidad_pago, '' AS estatus) ca ON 1=0";

if ($hasContratos && pdc_column_exists($cx, 'pats_contratos_actor', 'actor_id') && pdc_column_exists($cx, 'pats_contratos_actor', 'actor_tipo')) {
  $valorCol = pdc_first_col($cx, 'pats_contratos_actor', ['valor_total', 'monto_total', 'valor_contrato', 'monto_contrato', 'total_contrato']);
  $valorExpr = $valorCol !== '' ? "COALESCE(ca.`{$valorCol}`,0)" : '0';
  $modalidadExpr = pdc_column_exists($cx, 'pats_contratos_actor', 'modalidad_pago') ? 'ca.modalidad_pago' : "''";
  $estatusExpr = pdc_column_exists($cx, 'pats_contratos_actor', 'estatus') ? 'ca.estatus' : "''";
  $activoCond = pdc_column_exists($cx, 'pats_contratos_actor', 'activo') ? ' AND ca.activo = 1' : '';

  $pagoJoin = "LEFT JOIN (SELECT 0 AS id_contrato, 0 AS total_pagado) pay ON 1=0";
  if ($hasPagos && pdc_column_exists($cx, 'pats_pagos_actor', 'id_contrato') && pdc_column_exists($cx, 'pats_pagos_actor', 'monto_pago')) {
    $pagoJoin = "
      LEFT JOIN (
        SELECT id_contrato, COALESCE(SUM(monto_pago),0) AS total_pagado
        FROM pats_pagos_actor
        GROUP BY id_contrato
      ) pay ON pay.id_contrato = ca.id_contrato
    ";
  }

  $parJoin = "LEFT JOIN (SELECT 0 AS id_contrato, 0 AS total_parcialidades, 0 AS saldo_pendiente) par ON 1=0";
  if ($hasParcialidades && pdc_column_exists($cx, 'pats_parcialidades_actor', 'id_contrato') && pdc_column_exists($cx, 'pats_parcialidades_actor', 'saldo_pendiente')) {
    $parJoin = "
      LEFT JOIN (
        SELECT id_contrato, COUNT(*) AS total_parcialidades, COALESCE(SUM(saldo_pendiente),0) AS saldo_pendiente
        FROM pats_parcialidades_actor
        GROUP BY id_contrato
      ) par ON par.id_contrato = ca.id_contrato
    ";
  }

  $contratoJoin = "
    LEFT JOIN (
      SELECT
        ca.actor_id,
        MAX(ca.id_contrato) AS id_contrato,
        COALESCE(SUM({$valorExpr}),0) AS valor_total,
        COALESCE(SUM(pay.total_pagado),0) AS total_pagado,
        COALESCE(SUM(
          CASE
            WHEN COALESCE(par.total_parcialidades,0) > 0 THEN COALESCE(par.saldo_pendiente,0)
            ELSE GREATEST({$valorExpr} - COALESCE(pay.total_pagado,0), 0)
          END
        ),0) AS saldo_pendiente,
        MAX({$modalidadExpr}) AS modalidad_pago,
        MAX({$estatusExpr}) AS estatus
      FROM pats_contratos_actor ca
      {$pagoJoin}
      {$parJoin}
      WHERE LOWER(TRIM(ca.actor_tipo)) IN ('distribuidor','distribucion')
        {$activoCond}
      GROUP BY ca.actor_id
    ) ca ON ca.actor_id = d.id_distribuidor
  ";
}

/* =========================================================
   Consulta principal
========================================================= */
$rows = pats_all($cx, "
  SELECT
    d.id_distribuidor,
    COALESCE(NULLIF(TRIM(d.nombre),''), CONCAT('Distribuidor ', d.id_distribuidor)) AS nombre,
    d.correo,
    d.telefono,
    d.region,
    d.zona,
    d.unidad,
    d.pais,
    d.valor_distribucion,
    {$distFechaExpr} AS fecha_alta_operativa,
    d.public_checkout_token,
    d.public_checkout_activo,
    {$tokenUpdatedExpr} AS public_checkout_updated_at,
    COALESCE({$distGestorExpr},0) AS id_gestor_relacionado,
    {$origenExpr} AS origen_comercial,
    {$ventaAdminExpr} AS venta_directa_adminpats,
    COALESCE(p.total_pasaportes,0) AS total_pasaportes,
    COALESCE(p.pats_activos,0) AS pats_activos,
    COALESCE(p.pats_vencidos,0) AS pats_vencidos,
    COALESCE(p.ventas_pats,0) AS ventas_pats,
    COALESCE(cg.comision_total,0) AS comision_total,
    COALESCE(cg.comision_pagada,0) AS comision_pagada,
    COALESCE(cg.comision_pendiente,0) AS comision_pendiente,
    COALESCE(ca.id_contrato,0) AS id_contrato,
    COALESCE(ca.valor_total,0) AS valor_contrato,
    COALESCE(ca.total_pagado,0) AS total_pagado_contrato,
    COALESCE(ca.saldo_pendiente,0) AS saldo_pendiente_contrato,
    COALESCE(ca.modalidad_pago,'') AS modalidad_pago,
    COALESCE(ca.estatus,'') AS estatus_contrato
  FROM pats_distribuidores d
  LEFT JOIN ({$patsSub}) p ON p.id_distribuidor = d.id_distribuidor
  LEFT JOIN ({$comSub}) cg ON cg.id_distribuidor = d.id_distribuidor
  {$contratoJoin}
  WHERE {$whereSql}
  ORDER BY {$distFechaExpr} DESC, d.id_distribuidor DESC
  LIMIT 300
");

$items = [];
$kpis = [
  'total_distribuidores' => 0,
  'valor_distribuciones' => 0.0,
  'pats_vendidos' => 0,
  'pats_activos' => 0,
  'pats_vencidos' => 0,
  'ventas_pats' => 0.0,
  'dinero_recibido' => 0.0,
  'por_cobrar' => 0.0,
  'comisiones_distribuidor' => 0.0,
  'comisiones_pendientes' => 0.0
];

foreach ($rows as $row) {
  $id = (int)($row['id_distribuidor'] ?? 0);
  $valorDistribucion = pdc_num($row['valor_distribucion'] ?? 0);
  $totalPats = (int)($row['total_pasaportes'] ?? 0);
  $patsActivos = (int)($row['pats_activos'] ?? 0);
  $patsVencidos = (int)($row['pats_vencidos'] ?? 0);
  $ventasPats = pdc_num($row['ventas_pats'] ?? 0);
  $totalPagadoContrato = pdc_num($row['total_pagado_contrato'] ?? 0);
  $saldoPendienteContrato = pdc_num($row['saldo_pendiente_contrato'] ?? 0);
  $comisionTotal = pdc_num($row['comision_total'] ?? 0);
  $comisionPendiente = pdc_num($row['comision_pendiente'] ?? 0);

  $token = trim((string)($row['public_checkout_token'] ?? ''));
  $linkPats = $token !== ''
    ? 'https://pasaporteatusalud.com/landing_pats.php?t=' . rawurlencode($token)
    : '';

  $dashboardUrl = 'distribuidor.php?id_distribuidor=' . $id;
  if ((string)($row['region'] ?? '') !== '') $dashboardUrl .= '&region=' . rawurlencode((string)$row['region']);
  if ((string)($row['zona'] ?? '') !== '') $dashboardUrl .= '&zona=' . rawurlencode((string)$row['zona']);
  if ($anio > 0) $dashboardUrl .= '&anio=' . $anio;
  if ($mes > 0) $dashboardUrl .= '&mes=' . $mes;

  $kpis['total_distribuidores']++;
  $kpis['valor_distribuciones'] += $valorDistribucion;
  $kpis['pats_vendidos'] += $totalPats;
  $kpis['pats_activos'] += $patsActivos;
  $kpis['pats_vencidos'] += $patsVencidos;
  $kpis['ventas_pats'] += $ventasPats;
  $kpis['dinero_recibido'] += $totalPagadoContrato;
  $kpis['por_cobrar'] += $saldoPendienteContrato;
  $kpis['comisiones_distribuidor'] += $comisionTotal;
  $kpis['comisiones_pendientes'] += $comisionPendiente;

  $items[] = [
    'id_distribuidor' => $id,
    'nombre' => (string)($row['nombre'] ?? ''),
    'correo' => (string)($row['correo'] ?? ''),
    'telefono' => (string)($row['telefono'] ?? ''),
    'pais' => (string)($row['pais'] ?? ''),
    'region' => (string)($row['region'] ?? ''),
    'zona' => (string)($row['zona'] ?? ''),
    'unidad' => (string)($row['unidad'] ?? ''),
    'fecha_alta' => (string)($row['fecha_alta_operativa'] ?? ''),
    'valor_distribucion' => $valorDistribucion,
    'total_pasaportes' => $totalPats,
    'pats_activos' => $patsActivos,
    'pats_vencidos' => $patsVencidos,
    'ventas_pats' => $ventasPats,
    'comision_total' => $comisionTotal,
    'comision_pagada' => pdc_num($row['comision_pagada'] ?? 0),
    'comision_pendiente' => $comisionPendiente,
    'id_contrato' => (int)($row['id_contrato'] ?? 0),
    'valor_contrato' => pdc_num($row['valor_contrato'] ?? 0),
    'total_pagado_contrato' => $totalPagadoContrato,
    'saldo_pendiente_contrato' => $saldoPendienteContrato,
    'modalidad_pago' => (string)($row['modalidad_pago'] ?? ''),
    'estatus_contrato' => (string)($row['estatus_contrato'] ?? ''),
    'public_checkout_token' => $token,
    'public_checkout_activo' => (int)($row['public_checkout_activo'] ?? 0),
    'public_checkout_updated_at' => (string)($row['public_checkout_updated_at'] ?? ''),
    'link_pats_publico' => $linkPats,
    'tiene_link_pats_publico' => $linkPats !== '' ? 1 : 0,
    'id_gestor_relacionado' => (int)($row['id_gestor_relacionado'] ?? 0),
    'origen_comercial' => (string)($row['origen_comercial'] ?? ''),
    'venta_directa_adminpats' => (int)($row['venta_directa_adminpats'] ?? 0),
    'contexto_comercial' => 'CORPORATIVO_DIRECTO',
    'dashboard_url' => $dashboardUrl
  ];
}

foreach (['valor_distribuciones','ventas_pats','dinero_recibido','por_cobrar','comisiones_distribuidor','comisiones_pendientes'] as $key) {
  $kpis[$key] = pdc_num($kpis[$key]);
}

pats_json([
  'ok' => true,
  'filtros' => [
    'q' => $q,
    'region' => $region,
    'zona' => $zona,
    'anio' => $anio,
    'mes' => $mes
  ],
  'kpis' => $kpis,
  'distribuidores' => $items
]);
