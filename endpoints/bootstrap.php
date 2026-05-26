<?php
/*
ez/pats/endpoints/bootstrap.php
*/
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

require_once '../../../varSQL/bd_pats.php';
require_once '../../../varSQL/var_pats.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['usuario'])) {
  http_response_code(401);
  echo json_encode([
    'ok' => false,
    'error' => 'Sesión no válida'
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

/* =========================================================
   CONEXIÓN
   ---------------------------------------------------------
   En PATS puede existir la conexión como $conexionMod, $db_all2
   o $db_all según el entorno/archivo de variables cargado.
   Se deja tolerante para no romper dashboards entre local/servidor.
========================================================= */
$cx = $conexionMod ?? $db_all2 ?? $db_all ?? null;
if (!$cx || !($cx instanceof mysqli)) {
  http_response_code(500);
  echo json_encode([
    'ok' => false,
    'error' => 'No hay conexión mysqli disponible'
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

/* =========================================================
   SESIÓN / ROLES
   ---------------------------------------------------------
   ADMINPATS debe comportarse como rol administrador en los
   dashboards PATS, igual que ADMIN.
========================================================= */
$PATS_ADMIN_ROLES = ['ADMIN', 'ADMINPATS', 'DIRO', 'DIRG', 'VIC'];
$PATS_ROLE = strtoupper(trim($_SESSION['rol'] ?? ''));
$PATS_ROLEAPP = strtoupper(trim($_SESSION['rolapp'] ?? ''));
$PATS_USER = trim($_SESSION['usuario'] ?? '');
$PATS_NAME = trim($_SESSION['nombre'] ?? $PATS_USER);
$PATS_REGION = trim($_SESSION['acroregion'] ?? ($_SESSION['region'] ?? ''));
$PATS_UNIDAD = trim($_SESSION['acronu'] ?? ($_SESSION['unidad'] ?? ''));

function pats_json($payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function pats_str($value): string {
  return trim((string)($value ?? ''));
}

function pats_int($value): int {
  return (int)($value ?? 0);
}

function pats_num($value): float {
  return round((float)($value ?? 0), 2);
}

function pats_filters(): array {
  return [
    'pais' => pats_str($_GET['pais'] ?? ''),
    'region' => pats_str($_GET['region'] ?? ''),
    'zona' => pats_str($_GET['zona'] ?? ''),
    'unidad' => pats_str($_GET['unidad'] ?? ''),
    'anio' => pats_int($_GET['anio'] ?? date('Y')),
    'mes' => pats_int($_GET['mes'] ?? 0),
    'id_franquicia' => pats_int($_GET['id_franquicia'] ?? 0),
    'id_distribuidor' => pats_int($_GET['id_distribuidor'] ?? 0),
    'q' => pats_str($_GET['q'] ?? '')
  ];
}

function pats_scope_where(array $f, string $alias = ''): string {
  global $PATS_ROLE, $PATS_REGION, $PATS_UNIDAD;

  $p = $alias !== '' ? $alias . '.' : '';
  $where = ['1=1'];

  if ($f['pais'] !== '')   $where[] = $p . "pais='" . addslashes($f['pais']) . "'";
  if ($f['region'] !== '') $where[] = $p . "region='" . addslashes($f['region']) . "'";
  if ($f['zona'] !== '')   $where[] = $p . "zona='" . addslashes($f['zona']) . "'";
  if ($f['unidad'] !== '') $where[] = $p . "unidad='" . addslashes($f['unidad']) . "'";

  if ($f['anio'] > 0) {
    $where[] = "YEAR(" . $p . "created_at)=" . (int)$f['anio'];
  }
  if ($f['mes'] > 0) {
    $where[] = "MONTH(" . $p . "created_at)=" . (int)$f['mes'];
  }

  if ($PATS_ROLE === 'FRANQ') {
    $where[] = $p . "region='" . addslashes($PATS_REGION) . "'";
  }

  if ($PATS_ROLE === 'DIST') {
    if ($PATS_REGION !== '') {
      $where[] = $p . "region='" . addslashes($PATS_REGION) . "'";
    }
    if ($PATS_UNIDAD !== '') {
      $where[] = $p . "unidad='" . addslashes($PATS_UNIDAD) . "'";
    }
  }

  return implode(' AND ', $where);
}

function pats_one(mysqli $cx, string $sql): array {
  $rs = $cx->query($sql);
  if (!$rs) return [];
  $row = $rs->fetch_assoc();
  return is_array($row) ? $row : [];
}

function pats_all(mysqli $cx, string $sql): array {
  $out = [];
  $rs = $cx->query($sql);
  if (!$rs) return $out;
  while ($row = $rs->fetch_assoc()) {
    $out[] = $row;
  }
  return $out;
}

function pats_exec(mysqli $cx, string $sql): bool {
  return (bool)$cx->query($sql);
}

/* =========================================================
   TABLAS / COLUMNAS
========================================================= */
function pats_table_exists(mysqli $cx, string $table): bool {
  $tableEsc = $cx->real_escape_string($table);
  $rs = $cx->query("SHOW TABLES LIKE '{$tableEsc}'");
  if (!$rs) return false;
  $exists = $rs->num_rows > 0;
  $rs->free();
  return $exists;
}

function pats_column_exists(mysqli $cx, string $table, string $column): bool {
  $tableEsc = $cx->real_escape_string($table);
  $columnEsc = $cx->real_escape_string($column);
  $rs = $cx->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$columnEsc}'");
  if (!$rs) return false;
  $exists = $rs->num_rows > 0;
  $rs->free();
  return $exists;
}

/* =========================================================
   ORIGEN COMERCIAL / ADMINPATS DIRECTO
========================================================= */
function pats_comision_bancaria_distribucion(): float {
  return 2500.00;
}

function pats_es_adminpats_directo(array $row): bool {
  $origen = strtoupper(trim((string)($row['origen_comercial'] ?? '')));
  $directa = (int)($row['venta_directa_adminpats'] ?? 0);
  return $origen === 'ADMINPATS_DIRECTO' || $directa === 1;
}

function pats_where_no_adminpats_directo(mysqli $cx, string $table, string $alias): string {
  $parts = [];

  if (pats_column_exists($cx, $table, 'origen_comercial')) {
    $parts[] = "UPPER(TRIM(COALESCE({$alias}.origen_comercial,''))) <> 'ADMINPATS_DIRECTO'";
  }

  if (pats_column_exists($cx, $table, 'venta_directa_adminpats')) {
    $parts[] = "COALESCE({$alias}.venta_directa_adminpats,0) = 0";
  }

  return $parts ? (' AND ' . implode(' AND ', $parts)) : '';
}

function pats_where_adminpats_directo(mysqli $cx, string $table, string $alias): string {
  $parts = [];

  if (pats_column_exists($cx, $table, 'origen_comercial')) {
    $parts[] = "UPPER(TRIM(COALESCE({$alias}.origen_comercial,''))) = 'ADMINPATS_DIRECTO'";
  }

  if (pats_column_exists($cx, $table, 'venta_directa_adminpats')) {
    $parts[] = "COALESCE({$alias}.venta_directa_adminpats,0) = 1";
  }

  return $parts ? (' AND (' . implode(' OR ', $parts) . ')') : ' AND 1=0';
}

function pats_user_match_where(mysqli $cx): array {
  $parts = [];

  $sessionUserId = (int)($_SESSION['idUser'] ?? ($_SESSION['id'] ?? 0));
  $sessionUsuario = trim((string)($_SESSION['usuario'] ?? ''));
  $sessionCorreo = trim((string)($_SESSION['correo'] ?? ''));

  if ($sessionUserId > 0) {
    $parts[] = "u.id = {$sessionUserId}";
  }

  if ($sessionUsuario !== '') {
    $parts[] = "LOWER(TRIM(u.usuario)) = LOWER('" . $cx->real_escape_string($sessionUsuario) . "')";
  }

  if ($sessionCorreo !== '') {
    $parts[] = "LOWER(TRIM(u.correo)) = LOWER('" . $cx->real_escape_string($sessionCorreo) . "')";
  }

  return $parts;
}

function pats_resolve_logged_actor(mysqli $cx): array {
  $whereParts = pats_user_match_where($cx);

  $empty = [
    'ok' => false,
    'user_id' => 0,
    'rolapp' => '',
    'rol' => '',
    'tipo_actor' => '',
    'id_actor' => 0,
    'id_titular' => 0,
    'id_franquicia' => 0,
    'id_distribuidor' => 0,
    'id_gestor' => 0
  ];

  if (!$whereParts) {
    return $empty;
  }

  $row = pats_one($cx, "
    SELECT
      u.id,
      u.rolapp,
      u.rol,
      u.tipo_actor,
      u.id_actor,
      u.usuario,
      u.correo
    FROM pats_users u
    WHERE " . implode(' OR ', $whereParts) . "
    LIMIT 1
  ");

  if (!$row) {
    return $empty;
  }

  $rolapp = strtoupper(trim((string)($row['rolapp'] ?? '')));
  $rol = strtoupper(trim((string)($row['rol'] ?? '')));
  $tipoActor = strtoupper(trim((string)($row['tipo_actor'] ?? '')));
  $idActor = (int)($row['id_actor'] ?? 0);

  $out = [
    'ok' => true,
    'user_id' => (int)($row['id'] ?? 0),
    'rolapp' => $rolapp,
    'rol' => $rol,
    'tipo_actor' => $tipoActor,
    'id_actor' => $idActor,
    'id_titular' => 0,
    'id_franquicia' => 0,
    'id_distribuidor' => 0,
    'id_gestor' => 0
  ];

  if ($rolapp === 'FRANQPATS' || $rol === 'FRANQ' || $tipoActor === 'TITULAR_FRANQUICIA') {
    $out['id_titular'] = $idActor;

    if ($idActor > 0) {
      $tit = pats_one($cx, "
        SELECT id_titular, id_franquicia
        FROM pats_franquicia_titulares
        WHERE id_titular = {$idActor}
          AND activo = 1
        LIMIT 1
      ");

      if ($tit) {
        $out['id_titular'] = (int)($tit['id_titular'] ?? 0);
        $out['id_franquicia'] = (int)($tit['id_franquicia'] ?? 0);
      }
    }

    return $out;
  }

  if ($rolapp === 'DISTPATS' || $rol === 'DISTRIBUIDOR' || $rol === 'DIST' || $tipoActor === 'DISTRIBUIDOR') {
    $out['id_distribuidor'] = $idActor;
    return $out;
  }

  if ($rolapp === 'GESTORPATS' || $rol === 'GESTOR' || $tipoActor === 'GESTOR_FRANQUICIAS') {
    $out['id_gestor'] = $idActor;
    return $out;
  }

  return $out;
}

$PATS_ACTOR = pats_resolve_logged_actor($cx);

/* =========================================================
   REGLAS GLOBALES DE COMISION
========================================================= */
function pats_rule_cache_key(string $tipoOperacion, string $subtipoOperacion, string $modalidad, string $ambito, string $beneficiario): string {
  return implode('|', [
    strtolower(trim($tipoOperacion)),
    strtolower(trim($subtipoOperacion)),
    strtolower(trim($modalidad)),
    strtolower(trim($ambito)),
    strtolower(trim($beneficiario))
  ]);
}

function pats_get_active_rule(
  mysqli $cx,
  string $tipoOperacion,
  string $subtipoOperacion = '',
  string $modalidad = '',
  string $ambito = 'na',
  string $beneficiario = '',
  ?string $fecha = null
): array {
  static $cache = [];

  $fecha = $fecha ?: date('Y-m-d');
  $key = pats_rule_cache_key($tipoOperacion, $subtipoOperacion, $modalidad, $ambito, $beneficiario) . '|' . $fecha;

  if (isset($cache[$key])) {
    return $cache[$key];
  }

  $tipoOperacion = $cx->real_escape_string(trim($tipoOperacion));
  $subtipoOperacion = $cx->real_escape_string(trim($subtipoOperacion));
  $modalidad = $cx->real_escape_string(trim($modalidad));
  $ambito = $cx->real_escape_string(trim($ambito));
  $beneficiario = $cx->real_escape_string(trim($beneficiario));
  $fecha = $cx->real_escape_string($fecha);

  $where = [
    "r.tipo_operacion = '{$tipoOperacion}'",
    "r.beneficiario = '{$beneficiario}'",
    "r.activo = 1",
    "r.vigencia_ini <= '{$fecha}'",
    "(r.vigencia_fin IS NULL OR r.vigencia_fin >= '{$fecha}')"
  ];

  if ($subtipoOperacion !== '') {
    $where[] = "COALESCE(r.subtipo_operacion,'') = '{$subtipoOperacion}'";
  }

  if ($modalidad !== '') {
    $where[] = "COALESCE(r.modalidad_pago,'') = '{$modalidad}'";
  }

  if ($ambito !== '') {
    $where[] = "COALESCE(r.ambito_region,'') = '{$ambito}'";
  }

  $sql = "
    SELECT *
    FROM pats_reglas_comision r
    WHERE " . implode(' AND ', $where) . "
    ORDER BY r.orden_aplicacion ASC, r.id_regla ASC
    LIMIT 1
  ";

  $cache[$key] = pats_one($cx, $sql) ?: [];
  return $cache[$key];
}

function pats_rule_value(
  mysqli $cx,
  string $tipoOperacion,
  string $subtipoOperacion = '',
  string $modalidad = '',
  string $ambito = 'na',
  string $beneficiario = '',
  ?string $fecha = null
): float {
  $row = pats_get_active_rule($cx, $tipoOperacion, $subtipoOperacion, $modalidad, $ambito, $beneficiario, $fecha);
  return pats_num($row['valor_calculo'] ?? 0);
}

function pats_pats_split(mysqli $cx, string $frecuencia, ?string $fecha = null): array {
  $freq = strtolower(trim($frecuencia));
  if (!in_array($freq, ['anual', 'mensual'], true)) {
    $freq = 'mensual';
  }

  $fallback = $freq === 'anual'
    ? [
        'nominal' => 9600.00,
        'admin' => 3600.00,
        'unidad' => 4800.00,
        'franquicia' => 240.00,
        'distribuidor' => 960.00,
      ]
    : [
        'nominal' => 800.00,
        'admin' => 300.00,
        'unidad' => 400.00,
        'franquicia' => 20.00,
        'distribuidor' => 80.00,
      ];

  $admin = pats_rule_value($cx, 'pasaporte', 'membresia', $freq, 'na', 'admin', $fecha);
  $unidad = pats_rule_value($cx, 'pasaporte', 'membresia', $freq, 'na', 'unidad', $fecha);
  $franquicia = pats_rule_value($cx, 'pasaporte', 'membresia', $freq, 'na', 'franquicia', $fecha);
  $distribuidor = pats_rule_value($cx, 'pasaporte', 'membresia', $freq, 'na', 'distribuidor', $fecha);

  return [
    'nominal' => $fallback['nominal'],
    'admin' => $admin > 0 ? $admin : $fallback['admin'],
    'unidad' => $unidad > 0 ? $unidad : $fallback['unidad'],
    'franquicia' => $franquicia > 0 ? $franquicia : $fallback['franquicia'],
    'distribuidor' => $distribuidor > 0 ? $distribuidor : $fallback['distribuidor'],
  ];
}

/* =========================================================
   MORA / RECARGO
========================================================= */
function pats_calc_meses_vencidos(?string $vigencia): int {
  if (!$vigencia) return 0;

  try {
    $hoy = new DateTime('today');
    $vence = new DateTime($vigencia);

    if ($hoy <= $vence) return 0;

    $years = (int)$hoy->format('Y') - (int)$vence->format('Y');
    $months = (int)$hoy->format('n') - (int)$vence->format('n');
    $total = ($years * 12) + $months;

    if ((int)$hoy->format('j') >= (int)$vence->format('j')) {
      $total += 1;
    }

    return max(1, $total);
  } catch (Throwable $e) {
    return 0;
  }
}

function pats_recargo_mora_mensual(): float {
  return 100.00;
}

function pats_vencido_mensual_total(int $mesesVencidos): float {
  $meses = max(1, $mesesVencidos);
  return pats_num((800.00 * $meses) + (pats_recargo_mora_mensual() * $meses));
}

/* =========================================================
   METRICAS FINANCIERAS
   ---------------------------------------------------------
   Lee split PATS desde reglas activas, con fallback oficial.
   Ajuste nuevo:
   - Si id_franquicia > 0 e id_distribuidor = 0, la franquicia
     absorbe la comisión del distribuidor.
   - No se toca el caso ADMINPATS directo para evitar duplicar
     el ajuste que ya realiza dashboard_admin.php.
========================================================= */
function pats_metricas_financieras(mysqli $cx, string $whereSql, string $alias = 'p'): array {
  $alias = preg_replace('/[^a-zA-Z0-9_]/', '', $alias);
  if ($alias === '') $alias = 'p';

  $whereSql = trim($whereSql);
  if ($whereSql === '') $whereSql = '1=1';

  $rows = pats_all($cx, "
    SELECT
      {$alias}.id_pasaporte,
      COALESCE({$alias}.id_franquicia,0) AS id_franquicia,
      COALESCE({$alias}.id_distribuidor,0) AS id_distribuidor,
      {$alias}.frecuencia_pago,
      {$alias}.estatus,
      {$alias}.valor_final_pasaporte,
      {$alias}.valor_pasaporte,
      {$alias}.meses_vencidos,
      {$alias}.recargo_acumulado,
      {$alias}.vigencia,
      {$alias}.created_at
    FROM pats_pasaportes {$alias}
    WHERE {$whereSql} AND {$alias}.activo = 1
  ");

  $out = [
    'total_pasaportes' => 0,

    'ventas_real' => 0.0,
    'ventas_nominal' => 0.0,
    'ingreso_extra' => 0.0,

    'real_anual' => 0.0,
    'real_mensual' => 0.0,
    'nominal_anual' => 0.0,
    'nominal_mensual' => 0.0,

    'total_anual' => 0,
    'total_mensual' => 0,

    'activos' => 0,
    'vencidos' => 0,
    'monto_vencido' => 0.0,

    'comision_admin' => 0.0,
    'ingreso_hospital' => 0.0,
    'comision_franquicia' => 0.0,
    'comision_distribuidor' => 0.0
  ];

  foreach ($rows as $row) {
    $frecuencia = strtolower(trim((string)($row['frecuencia_pago'] ?? 'mensual')));
    $estatus = strtolower(trim((string)($row['estatus'] ?? '')));
    $valorFinal = pats_num($row['valor_final_pasaporte'] ?? 0);
    $fechaBase = !empty($row['created_at']) ? substr((string)$row['created_at'], 0, 10) : null;

    $split = pats_pats_split($cx, $frecuencia, $fechaBase);
    $nominal = pats_num($split['nominal'] ?? 0);

    $idFranqMetricas = (int)($row['id_franquicia'] ?? 0);
    $idDistMetricas = (int)($row['id_distribuidor'] ?? 0);

    $comisionAdminMetricas = pats_num($split['admin'] ?? 0);
    $ingresoHospitalMetricas = pats_num($split['unidad'] ?? 0);
    $comisionFranquiciaMetricas = pats_num($split['franquicia'] ?? 0);
    $comisionDistribuidorMetricas = pats_num($split['distribuidor'] ?? 0);

    if ($idFranqMetricas > 0 && $idDistMetricas <= 0) {
      $comisionFranquiciaMetricas = pats_num($comisionFranquiciaMetricas + $comisionDistribuidorMetricas);
      $comisionDistribuidorMetricas = 0.0;
    }

    $out['total_pasaportes']++;
    $valorBase = pats_num($row['valor_pasaporte'] ?? $nominal);
    $extraCobrado = pats_num(max(0, $valorFinal - $valorBase));

    $out['ventas_real'] = pats_num($out['ventas_real'] + $valorFinal);
    $out['ventas_nominal'] = pats_num($out['ventas_nominal'] + $nominal);
    $out['ingreso_extra'] = pats_num($out['ingreso_extra'] + $extraCobrado);
    $out['comision_admin'] = pats_num($out['comision_admin'] + $comisionAdminMetricas);
    $out['ingreso_hospital'] = pats_num($out['ingreso_hospital'] + $ingresoHospitalMetricas);
    $out['comision_franquicia'] = pats_num($out['comision_franquicia'] + $comisionFranquiciaMetricas);
    $out['comision_distribuidor'] = pats_num($out['comision_distribuidor'] + $comisionDistribuidorMetricas);

    if ($frecuencia === 'anual') {
      $out['total_anual']++;
      $out['real_anual'] = pats_num($out['real_anual'] + $valorFinal);
      $out['nominal_anual'] = pats_num($out['nominal_anual'] + $nominal);
    } else {
      $out['total_mensual']++;
      $out['real_mensual'] = pats_num($out['real_mensual'] + $valorFinal);
      $out['nominal_mensual'] = pats_num($out['nominal_mensual'] + $nominal);
    }

    if ($estatus === 'activo') {
      $out['activos']++;
    } elseif ($estatus === 'vencido') {
      $out['vencidos']++;

      if ($frecuencia === 'mensual') {
        $meses = (int)($row['meses_vencidos'] ?? 0);
        if ($meses <= 0) {
          $meses = pats_calc_meses_vencidos((string)($row['vigencia'] ?? ''));
        }
        $out['monto_vencido'] = pats_num($out['monto_vencido'] + pats_vencido_mensual_total($meses));
      } else {
        $out['monto_vencido'] = pats_num($out['monto_vencido'] + max($valorFinal, $nominal));
      }
    }
  }

  return $out;
}
