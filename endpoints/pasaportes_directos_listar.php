<?php
/*
Archivo: ez/pats/endpoints/pasaportes_directos_listar.php
Módulo: PATS · Pasaportes directos vendidos
Propósito: Entregar PATS directos vendidos por corporativo, gestor/gerente o franquicia con paginación real desde BD.
Responsabilidad:
- AdminPATS ve PATS corporativos: sin franquicia, sin distribuidor y sin gestor.
- AdminPATS en gestor.php solo ve PATS directos cuando selecciona un gerente y se envía id_actor/id_gestor.
- Usuario Gestor/Gerente ve automático sus PATS directos por sesión.
- Franquiciatario ve PATS con id_franquicia propia y sin distribuidor.
- Devuelve máximo per_page registros por página para no cargar miles de pasaportes.
- Soporta búsqueda por nombre, CURP, correo, teléfono, empresa e ID.
Conexiones: bootstrap.php, pats_pasaportes, pats_comisiones_generadas.
Tipo: Endpoint JSON específico de PATS.
*/

require_once __DIR__ . '/bootstrap.php';

$f = pats_filters();

if (!function_exists('pdl_num')) {
  function pdl_num($v): float {
    return round((float)($v ?? 0), 2);
  }
}

if (!function_exists('pdl_clean')) {
  function pdl_clean($v): string {
    return trim((string)($v ?? ''));
  }
}

if (!function_exists('pdl_esc')) {
  function pdl_esc(mysqli $cx, $v): string {
    return $cx->real_escape_string((string)($v ?? ''));
  }
}

if (!function_exists('pdl_one_local')) {
  function pdl_one_local(mysqli $cx, string $sql): array {
    $rs = $cx->query($sql);
    if (!$rs) return [];
    $row = $rs->fetch_assoc();
    $rs->free();
    return is_array($row) ? $row : [];
  }
}

if (!function_exists('pdl_period_filter')) {
  function pdl_period_filter(string $alias, array $f): string {
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias);
    if ($a === '') $a = 'p';

    $parts = [];
    if (!empty($f['anio'])) $parts[] = "YEAR({$a}.created_at) = " . (int)$f['anio'];
    if (!empty($f['mes']))  $parts[] = "MONTH({$a}.created_at) = " . (int)$f['mes'];

    return $parts ? (' AND ' . implode(' AND ', $parts)) : '';
  }
}

if (!function_exists('pdl_value_expr')) {
  function pdl_value_expr(string $alias = 'p'): string {
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias);
    if ($a === '') $a = 'p';

    return "COALESCE(NULLIF({$a}.valor_final_pasaporte,0), NULLIF({$a}.valor_pasaporte,0), CASE WHEN LOWER(TRIM(COALESCE({$a}.frecuencia_pago,''))) = 'anual' THEN 9600 ELSE 800 END)";
  }
}

if (!function_exists('pdl_split')) {
  function pdl_split(string $frecuencia): array {
    $f = strtolower(trim($frecuencia));
    if ($f === 'anual') {
      return [
        'valor' => 9600.00,
        'hospital' => 6000.00,
        'admin_asociado' => 2400.00,
        'admin_directo' => 3600.00,
        'bolsa_comercial' => 1200.00,
      ];
    }

    return [
      'valor' => 800.00,
      'hospital' => 500.00,
      'admin_asociado' => 200.00,
      'admin_directo' => 300.00,
      'bolsa_comercial' => 100.00,
    ];
  }
}

if (!function_exists('pdl_empty')) {
  function pdl_empty(string $tipoActor, int $idActor, int $page, int $perPage, string $q = ''): void {
    pats_json([
      'ok' => true,
      'tipo_actor' => $tipoActor,
      'id_actor' => $idActor,
      'page' => $page,
      'per_page' => $perPage,
      'total_rows' => 0,
      'total_pages' => 1,
      'q' => $q,
      'totales' => [
        'total_pasaportes' => 0,
        'activos' => 0,
        'vencidos' => 0,
        'ventas' => 0,
        'comision_actor' => 0,
        'hospital' => 0,
        'adminpats' => 0
      ],
      'pasaportes' => []
    ]);
  }
}

/* =========================================================
   PAGINACIÓN Y BÚSQUEDA
========================================================= */
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 5);
if ($perPage < 5) $perPage = 5;
if ($perPage > 20) $perPage = 20;

$q = pdl_clean($_GET['q'] ?? '');
$offset = ($page - 1) * $perPage;

/* =========================================================
   RESOLVER ACTOR
========================================================= */
$tipoActor = strtolower(pdl_clean($_GET['tipo_actor'] ?? ''));
$idActor = (int)($_GET['id_actor'] ?? $_GET['id_gestor'] ?? $_GET['id_franquicia'] ?? 0);

$isAdmin = in_array($PATS_ROLE, $PATS_ADMIN_ROLES, true);
$rolapp = strtoupper(pdl_clean($PATS_ACTOR['rolapp'] ?? ($_SESSION['rolapp'] ?? '')));

if ($tipoActor === '') {
  if ($rolapp === 'GESTORPATS') {
    $tipoActor = 'gestor';
  } elseif ($rolapp === 'FRANQPATS') {
    $tipoActor = 'franquicia';
  } else {
    $tipoActor = 'admin';
  }
}

/*
  Regla crítica:
  - ADMIN/ADMINPATS en gestor.php NO se auto-resuelve. Requiere id_actor desde #patsGestorActor.
  - Usuario GESTORPATS sí se resuelve automáticamente por sesión.
*/
if (!$isAdmin) {
  if ($rolapp === 'GESTORPATS') {
    $tipoActor = 'gestor';
    $idActor = (int)($PATS_ACTOR['id_gestor'] ?? 0);
  } elseif ($rolapp === 'FRANQPATS') {
    $tipoActor = 'franquicia';
    $idActor = (int)($PATS_ACTOR['id_franquicia'] ?? 0);
  } else {
    pats_json(['ok' => false, 'error' => 'Acceso no permitido para consultar PATS directos.'], 403);
  }
}

if (!in_array($tipoActor, ['admin', 'gestor', 'franquicia'], true)) {
  $tipoActor = 'admin';
}

/*
  Si es ADMIN consultando gestor/franquicia y no seleccionó actor, devolver vacío.
  No se hace fallback automático para evitar mostrar datos de otro gerente.
*/
if (($tipoActor === 'gestor' || $tipoActor === 'franquicia') && $idActor <= 0) {
  pdl_empty($tipoActor, 0, $page, $perPage, $q);
}

/* =========================================================
   WHERE SEGÚN TIPO
========================================================= */
$where = ["p.activo = 1"];

if ($tipoActor === 'admin') {
  $where[] = "COALESCE(p.id_franquicia,0) = 0";
  $where[] = "COALESCE(p.id_distribuidor,0) = 0";
  $where[] = "COALESCE(p.id_gestor,0) = 0";

  /*
    IMPORTANTE:
    Los PATS corporativos directos pueden guardarse con region/zona/unidad vacías
    porque no pasan por franquicia, distribuidor ni gestor.
    pats_filters() puede traer región/zona desde sesión o filtros generales del dashboard,
    y eso escondía los directos corporativos en ADMINPATS.

    Por eso aquí NO filtramos por región/zona.
    La condición real de directo corporativo es solo:
      id_franquicia = 0, id_distribuidor = 0, id_gestor = 0/NULL.
  */
} elseif ($tipoActor === 'gestor') {
  $where[] = "COALESCE(p.id_gestor,0) = {$idActor}";
  $where[] = "COALESCE(p.id_franquicia,0) = 0";
  $where[] = "COALESCE(p.id_distribuidor,0) = 0";
} elseif ($tipoActor === 'franquicia') {
  $where[] = "COALESCE(p.id_franquicia,0) = {$idActor}";
  $where[] = "COALESCE(p.id_distribuidor,0) = 0";
}

if ($q !== '') {
  $qEsc = pdl_esc($cx, $q);
  $qLike = "'%" . $qEsc . "%'";
  $qId = preg_replace('/[^0-9]/', '', $q);

  $searchParts = [
    "p.nombres LIKE {$qLike}",
    "p.apellido_pa LIKE {$qLike}",
    "p.apellido_ma LIKE {$qLike}",
    "CONCAT_WS(' ', p.nombres, p.apellido_pa, p.apellido_ma) LIKE {$qLike}",
    "p.curp LIKE {$qLike}",
    "p.correo LIKE {$qLike}",
    "p.telefono LIKE {$qLike}",
    "p.nombre_empresa LIKE {$qLike}"
  ];

  if ($qId !== '') {
    $searchParts[] = "p.id_pasaporte = " . (int)$qId;
  }

  $where[] = '(' . implode(' OR ', $searchParts) . ')';
}

$whereSql = implode(' AND ', $where) . pdl_period_filter('p', $f);
$valorExpr = pdl_value_expr('p');

$benefWhere = $tipoActor === 'admin'
  ? "AND LOWER(TRIM(cg.beneficiario_tipo)) = 'admin'"
  : "AND LOWER(TRIM(cg.beneficiario_tipo)) = '{$tipoActor}' AND cg.beneficiario_id = {$idActor}";

/* =========================================================
   COUNT Y TOTALES GLOBALES DEL FILTRO
========================================================= */
$countRow = pdl_one_local($cx, "
  SELECT COUNT(*) AS total_rows
  FROM pats_pasaportes p
  WHERE {$whereSql}
");
$totalRows = (int)($countRow['total_rows'] ?? 0);
$totalPages = max(1, (int)ceil($totalRows / $perPage));

if ($page > $totalPages) {
  $page = $totalPages;
  $offset = ($page - 1) * $perPage;
}

$totalRowsData = pats_all($cx, "
  SELECT
    p.id_pasaporte,
    p.frecuencia_pago,
    p.estatus,
    {$valorExpr} AS valor_calculado,

    (
      SELECT COALESCE(SUM(cg.monto_comision),0)
      FROM pats_comisiones_generadas cg
      WHERE cg.tipo_origen = 'pago_pasaporte'
        AND cg.id_origen = p.id_pasaporte
        {$benefWhere}
        AND LOWER(TRIM(cg.estatus)) NOT IN ('cancelada','cancelado','anulada','anulado')
    ) AS comision_generada_actor,

    (
      SELECT COALESCE(SUM(cg.monto_comision),0)
      FROM pats_comisiones_generadas cg
      WHERE cg.tipo_origen = 'pago_pasaporte'
        AND cg.id_origen = p.id_pasaporte
        AND LOWER(TRIM(cg.beneficiario_tipo)) = 'unidad'
        AND LOWER(TRIM(cg.estatus)) NOT IN ('cancelada','cancelado','anulada','anulado')
    ) AS comision_generada_hospital,

    (
      SELECT COALESCE(SUM(cg.monto_comision),0)
      FROM pats_comisiones_generadas cg
      WHERE cg.tipo_origen = 'pago_pasaporte'
        AND cg.id_origen = p.id_pasaporte
        AND LOWER(TRIM(cg.beneficiario_tipo)) = 'admin'
        AND LOWER(TRIM(cg.estatus)) NOT IN ('cancelada','cancelado','anulada','anulado')
    ) AS comision_generada_admin

  FROM pats_pasaportes p
  WHERE {$whereSql}
");

$totales = [
  'total_pasaportes' => 0,
  'activos' => 0,
  'vencidos' => 0,
  'ventas' => 0.0,
  'comision_actor' => 0.0,
  'hospital' => 0.0,
  'adminpats' => 0.0
];

foreach ($totalRowsData as $r) {
  $freq = strtolower(pdl_clean($r['frecuencia_pago'] ?? 'mensual'));
  $split = pdl_split($freq === 'anual' ? 'anual' : 'mensual');
  $estatus = strtolower(pdl_clean($r['estatus'] ?? ''));
  $valor = pdl_num($r['valor_calculado'] ?? 0);
  if ($valor <= 0) $valor = $split['valor'];

  $comisionActorGenerada = pdl_num($r['comision_generada_actor'] ?? 0);
  $hospitalGenerada = pdl_num($r['comision_generada_hospital'] ?? 0);
  $adminGenerada = pdl_num($r['comision_generada_admin'] ?? 0);

  if ($tipoActor === 'admin') {
    $comisionActor = $comisionActorGenerada > 0 ? $comisionActorGenerada : $split['admin_directo'];
    $adminpats = $adminGenerada > 0 ? $adminGenerada : $split['admin_directo'];
  } else {
    $comisionActor = $comisionActorGenerada > 0 ? $comisionActorGenerada : $split['bolsa_comercial'];
    $adminpats = $adminGenerada > 0 ? $adminGenerada : $split['admin_asociado'];
  }

  $hospital = $hospitalGenerada > 0 ? $hospitalGenerada : $split['hospital'];

  $totales['total_pasaportes']++;
  if (in_array($estatus, ['activo','vigente'], true)) $totales['activos']++;
  if ($estatus === 'vencido') $totales['vencidos']++;

  $totales['ventas'] = pdl_num($totales['ventas'] + $valor);
  $totales['comision_actor'] = pdl_num($totales['comision_actor'] + $comisionActor);
  $totales['hospital'] = pdl_num($totales['hospital'] + $hospital);
  $totales['adminpats'] = pdl_num($totales['adminpats'] + $adminpats);
}

/* =========================================================
   PÁGINA ACTUAL
========================================================= */
$rows = pats_all($cx, "
  SELECT
    p.id_pasaporte,
    COALESCE(p.id_franquicia,0) AS id_franquicia,
    COALESCE(p.id_distribuidor,0) AS id_distribuidor,
    COALESCE(p.id_gestor,0) AS id_gestor,
    p.curp,
    p.nombres,
    p.apellido_pa,
    p.apellido_ma,
    p.fecha_nacimiento,
    p.telefono,
    p.correo,
    p.fecha_alta,
    p.vigencia,
    p.frecuencia_pago,
    p.estatus,
    p.valor_pasaporte,
    p.valor_final_pasaporte,
    {$valorExpr} AS valor_calculado,
    p.pais,
    p.region,
    p.zona,
    p.unidad,
    p.tipo_cliente,
    p.nombre_empresa,
    p.fotografia_path,
    p.fecha_ultimo_pago,
    p.fecha_vencimiento_real,
    p.meses_vencidos,
    p.recargo_acumulado,
    p.created_at,

    (
      SELECT COALESCE(SUM(cg.monto_comision),0)
      FROM pats_comisiones_generadas cg
      WHERE cg.tipo_origen = 'pago_pasaporte'
        AND cg.id_origen = p.id_pasaporte
        {$benefWhere}
        AND LOWER(TRIM(cg.estatus)) NOT IN ('cancelada','cancelado','anulada','anulado')
    ) AS comision_generada_actor,

    (
      SELECT COALESCE(SUM(cg.monto_comision),0)
      FROM pats_comisiones_generadas cg
      WHERE cg.tipo_origen = 'pago_pasaporte'
        AND cg.id_origen = p.id_pasaporte
        AND LOWER(TRIM(cg.beneficiario_tipo)) = 'unidad'
        AND LOWER(TRIM(cg.estatus)) NOT IN ('cancelada','cancelado','anulada','anulado')
    ) AS comision_generada_hospital,

    (
      SELECT COALESCE(SUM(cg.monto_comision),0)
      FROM pats_comisiones_generadas cg
      WHERE cg.tipo_origen = 'pago_pasaporte'
        AND cg.id_origen = p.id_pasaporte
        AND LOWER(TRIM(cg.beneficiario_tipo)) = 'admin'
        AND LOWER(TRIM(cg.estatus)) NOT IN ('cancelada','cancelado','anulada','anulado')
    ) AS comision_generada_admin

  FROM pats_pasaportes p
  WHERE {$whereSql}
  ORDER BY p.created_at DESC, p.id_pasaporte DESC
  LIMIT {$perPage} OFFSET {$offset}
");

$pasaportes = [];

foreach ($rows as $r) {
  $freq = strtolower(pdl_clean($r['frecuencia_pago'] ?? 'mensual'));
  $split = pdl_split($freq === 'anual' ? 'anual' : 'mensual');

  $valor = pdl_num($r['valor_calculado'] ?? 0);
  if ($valor <= 0) $valor = $split['valor'];

  $comisionActorGenerada = pdl_num($r['comision_generada_actor'] ?? 0);
  $hospitalGenerada = pdl_num($r['comision_generada_hospital'] ?? 0);
  $adminGenerada = pdl_num($r['comision_generada_admin'] ?? 0);

  if ($tipoActor === 'admin') {
    $comisionActor = $comisionActorGenerada > 0 ? $comisionActorGenerada : $split['admin_directo'];
    $adminpats = $adminGenerada > 0 ? $adminGenerada : $split['admin_directo'];
  } else {
    $comisionActor = $comisionActorGenerada > 0 ? $comisionActorGenerada : $split['bolsa_comercial'];
    $adminpats = $adminGenerada > 0 ? $adminGenerada : $split['admin_asociado'];
  }

  $hospital = $hospitalGenerada > 0 ? $hospitalGenerada : $split['hospital'];

  $nombre = trim(
    pdl_clean($r['nombres'] ?? '') . ' ' .
    pdl_clean($r['apellido_pa'] ?? '') . ' ' .
    pdl_clean($r['apellido_ma'] ?? '')
  );

  $pasaportes[] = [
    'id_pasaporte' => (int)($r['id_pasaporte'] ?? 0),
    'id_franquicia' => (int)($r['id_franquicia'] ?? 0),
    'id_distribuidor' => (int)($r['id_distribuidor'] ?? 0),
    'id_gestor' => (int)($r['id_gestor'] ?? 0),
    'nombre_completo' => $nombre ?: 'Sin nombre',
    'curp' => pdl_clean($r['curp'] ?? ''),
    'fecha_nacimiento' => pdl_clean($r['fecha_nacimiento'] ?? ''),
    'telefono' => pdl_clean($r['telefono'] ?? ''),
    'correo' => pdl_clean($r['correo'] ?? ''),
    'fecha_alta' => pdl_clean($r['fecha_alta'] ?? ''),
    'vigencia' => pdl_clean($r['vigencia'] ?? ''),
    'fecha_vencimiento_real' => pdl_clean($r['fecha_vencimiento_real'] ?? ''),
    'frecuencia_pago' => pdl_clean($r['frecuencia_pago'] ?? ''),
    'estatus' => pdl_clean($r['estatus'] ?? ''),
    'valor_base' => pdl_num($r['valor_pasaporte'] ?? 0),
    'valor_final' => $valor,
    'comision_actor' => pdl_num($comisionActor),
    'comision_hospital' => pdl_num($hospital),
    'comision_adminpats' => pdl_num($adminpats),
    'pais' => pdl_clean($r['pais'] ?? ''),
    'region' => pdl_clean($r['region'] ?? ''),
    'zona' => pdl_clean($r['zona'] ?? ''),
    'unidad' => pdl_clean($r['unidad'] ?? ''),
    'tipo_cliente' => pdl_clean($r['tipo_cliente'] ?? ''),
    'nombre_empresa' => pdl_clean($r['nombre_empresa'] ?? '') ?: 'No aplica',
    'fotografia_path' => pdl_clean($r['fotografia_path'] ?? ''),
    'meses_vencidos' => (int)($r['meses_vencidos'] ?? 0),
    'recargo_acumulado' => pdl_num($r['recargo_acumulado'] ?? 0),
    'created_at' => pdl_clean($r['created_at'] ?? '')
  ];
}

pats_json([
  'ok' => true,
  'tipo_actor' => $tipoActor,
  'id_actor' => $idActor,
  'page' => $page,
  'per_page' => $perPage,
  'total_rows' => $totalRows,
  'total_pages' => $totalPages,
  'q' => $q,
  'totales' => $totales,
  'pasaportes' => $pasaportes
]);
