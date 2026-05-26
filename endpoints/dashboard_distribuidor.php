<?php
/*
Archivo: ez/pats/endpoints/dashboard_distribuidor.php
M車dulo: PATS ﹞ Dashboard Distribuidor
Prop車sito: Endpoint JSON m赤nimo y estable para resolver dashboard de distribuidor.
Conexiones: ../../../varSQL/bd_pats.php, ../../../varSQL/var_pats.php
*/

declare(strict_types=1);

ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_OFF);

function out_json(array $data, int $code = 200): void {
  while (ob_get_level() > 0) { @ob_end_clean(); }
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

register_shutdown_function(function () {
  $e = error_get_last();
  if (!$e) return;
  if (!in_array((int)$e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;

  while (ob_get_level() > 0) { @ob_end_clean(); }
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'ok' => false,
    'error' => 'Fatal PHP',
    'detalle' => $e['message'] ?? '',
    'archivo' => $e['file'] ?? '',
    'linea' => $e['line'] ?? 0
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}

require_once __DIR__ . '/../../../varSQL/bd_pats.php';
if (is_file(__DIR__ . '/../../../varSQL/var_pats.php')) {
  require_once __DIR__ . '/../../../varSQL/var_pats.php';
}

$cx = $conexionMod ?? $db_all2 ?? $db_all ?? $conexion ?? null;

if (!$cx instanceof mysqli) {
  out_json([
    'ok' => false,
    'error' => 'No hay conexi車n mysqli disponible.',
    'debug' => [
      'conexionMod' => isset($conexionMod) ? gettype($conexionMod) : 'no_definida',
      'db_all2' => isset($db_all2) ? gettype($db_all2) : 'no_definida',
      'db_all' => isset($db_all) ? gettype($db_all) : 'no_definida',
      'conexion' => isset($conexion) ? gettype($conexion) : 'no_definida'
    ]
  ], 500);
}

function s($v): string { return trim((string)($v ?? '')); }
function i($v): int { return (int)($v ?? 0); }
function n($v): float { return round((float)($v ?? 0), 2); }

function all_rows(mysqli $cx, string $sql): array {
  $rs = $cx->query($sql);
  if (!$rs instanceof mysqli_result) return [];
  $out = [];
  while ($r = $rs->fetch_assoc()) $out[] = $r;
  $rs->free();
  return $out;
}

function one_row(mysqli $cx, string $sql): array {
  $rows = all_rows($cx, $sql);
  return $rows[0] ?? [];
}

function token_only(string $raw): string {
  $raw = trim($raw);
  if ($raw === '') return '';
  if (strpos($raw, '?t=') !== false || strpos($raw, '&t=') !== false) {
    $p = parse_url($raw);
    if (!empty($p['query'])) {
      parse_str($p['query'], $q);
      return trim((string)($q['t'] ?? ''));
    }
  }
  return $raw;
}

function link_pats(string $token): string {
  $token = token_only($token);
  return $token !== '' ? 'https://pasaporteatusalud.com/landing_pats.php?t=' . rawurlencode($token) : '';
}

function meses_vencidos(array $p): int {
  $m = i($p['meses_vencidos'] ?? 0);
  if ($m > 0) return $m;

  $vig = s($p['vigencia'] ?? '');
  if ($vig === '') return 1;

  try {
    $v = new DateTime($vig);
    $h = new DateTime('today');
    if ($v >= $h) return 0;
    $d = $v->diff($h);
    return max(1, ((int)$d->y * 12) + (int)$d->m + ((int)$d->d > 0 ? 1 : 0));
  } catch (Throwable $e) {
    return 1;
  }
}

$idDistribuidor = i($_GET['id_distribuidor'] ?? 0);
$idFranquicia = i($_GET['id_franquicia'] ?? 0);
$anio = i($_GET['anio'] ?? 0);
$mes = i($_GET['mes'] ?? 0);


/*
Resolver distribuidor cuando el usuario entra directo como DISTPATS
sin id_distribuidor en la URL.
No altera el caso ADMIN, porque si la URL trae id_distribuidor se respeta.
*/
if ($idDistribuidor <= 0) {
  $sessionUsuario = s($_SESSION['usuario'] ?? '');
  $sessionCorreo  = s($_SESSION['correo'] ?? '');
  $sessionId      = i($_SESSION['idUser'] ?? ($_SESSION['id'] ?? 0));

  $whereUser = [];

  if ($sessionId > 0) {
    $whereUser[] = "u.id = {$sessionId}";
  }

  if ($sessionUsuario !== '') {
    $safeUsuario = $cx->real_escape_string($sessionUsuario);
    $whereUser[] = "LOWER(TRIM(u.usuario)) = LOWER('{$safeUsuario}')";
  }

  if ($sessionCorreo !== '') {
    $safeCorreo = $cx->real_escape_string($sessionCorreo);
    $whereUser[] = "LOWER(TRIM(u.correo)) = LOWER('{$safeCorreo}')";
  }

  if ($whereUser) {
    $actor = one_row($cx, "
      SELECT
        u.id,
        u.usuario,
        u.correo,
        u.rolapp,
        u.rol,
        u.tipo_actor,
        u.id_actor
      FROM pats_users u
      WHERE " . implode(' OR ', $whereUser) . "
      LIMIT 1
    ");

    $rolappActor = strtoupper(s($actor['rolapp'] ?? ''));
    $rolActor    = strtoupper(s($actor['rol'] ?? ''));
    $tipoActor   = strtoupper(s($actor['tipo_actor'] ?? ''));

    if (
      $rolappActor === 'DISTPATS'
      || $rolActor === 'DISTRIBUIDOR'
      || $rolActor === 'DIST'
      || $tipoActor === 'DISTRIBUIDOR'
    ) {
      $idDistribuidor = i($actor['id_actor'] ?? 0);
    }
  }
}

/*
Fallback adicional:
Si pats_users no resolvi車, intentamos empatar directo contra pats_distribuidores
por usuario/correo. Esto NO afecta ADMIN porque solo corre si id_distribuidor sigue vac赤o.
*/
if ($idDistribuidor <= 0) {
  $sessionUsuario = s($_SESSION['usuario'] ?? '');
  $sessionCorreo  = s($_SESSION['correo'] ?? '');

  $whereDist = [];

  if ($sessionUsuario !== '') {
    $safeUsuario = $cx->real_escape_string($sessionUsuario);
    $whereDist[] = "LOWER(TRIM(d.usuario)) = LOWER('{$safeUsuario}')";
  }

  if ($sessionCorreo !== '') {
    $safeCorreo = $cx->real_escape_string($sessionCorreo);
    $whereDist[] = "LOWER(TRIM(d.correo)) = LOWER('{$safeCorreo}')";
  }

  if ($whereDist) {
    $distSesion = one_row($cx, "
      SELECT d.id_distribuidor
      FROM pats_distribuidores d
      WHERE " . implode(' OR ', $whereDist) . "
      LIMIT 1
    ");

    if ($distSesion) {
      $idDistribuidor = i($distSesion['id_distribuidor'] ?? 0);
    }
  }
}


if ($idDistribuidor <= 0) {
  out_json([
    'ok' => true,
    'distribuidor' => [],
    'kpis' => [],
    'frecuencia' => [],
    'vencidos' => [],
    'charts' => []
  ]);
}

$dist = one_row($cx, "
  SELECT d.*, f.nombre_franquicia
  FROM pats_distribuidores d
  LEFT JOIN pats_franquicias f ON f.id_franquicia = d.id_franquicia
  WHERE d.id_distribuidor = {$idDistribuidor}
  LIMIT 1
");

if (!$dist) {
  out_json([
    'ok' => true,
    'distribuidor' => [],
    'kpis' => [],
    'frecuencia' => [],
    'vencidos' => [],
    'charts' => [],
    'warning' => 'Distribuidor no encontrado'
  ]);
}

$token = token_only(s($dist['public_checkout_token'] ?? ''));
$dist['public_checkout_token'] = $token;
$dist['link_pats_publico'] = link_pats($token);
$dist['tiene_link_pats_publico'] = $dist['link_pats_publico'] !== '' ? 1 : 0;

$where = "COALESCE(p.id_distribuidor,0) = {$idDistribuidor}";
if ($anio > 0) $where .= " AND YEAR(p.created_at) = {$anio}";
if ($mes > 0) $where .= " AND MONTH(p.created_at) = {$mes}";

$pasaportes = all_rows($cx, "SELECT p.* FROM pats_pasaportes p WHERE {$where}");

$ventasReal = 0;
$ventasNominal = 0;
$montoVencido = 0;
$activos = 0;
$vencidosCount = 0;
$anuales = 0;
$mensuales = 0;
$comisionesActivas = 0;
$comisionesPerdidas = 0;
$vencidos = [];

foreach ($pasaportes as $p) {
  $estatus = strtolower(s($p['estatus'] ?? ''));
  $activo = i($p['activo'] ?? 0) === 1;
  $freq = strtolower(s($p['frecuencia_pago'] ?? 'mensual'));

  $nominal = $freq === 'anual' ? 9600 : 800;
  $valor = n($p['valor_final_pasaporte'] ?? 0);
  if ($valor <= 0) $valor = n($p['valor_pasaporte'] ?? 0);
  if ($valor <= 0) $valor = $nominal;

  $ventasNominal += $nominal;

  if ($activo && in_array($estatus, ['activo', 'vigente'], true)) {
    $activos++;
    $ventasReal += $valor;

    if ($freq === 'anual') {
      $anuales++;
      $comisionesActivas += 960;
    } else {
      $mensuales++;
      $comisionesActivas += 80;
    }
  }

  if ($activo && $estatus === 'vencido') {
    $vencidosCount++;
    $mv = max(1, meses_vencidos($p));

    if ($freq === 'anual') {
      $base = 9600;
      $final = max($valor, 9600);
      $perdida = 960;
    } else {
      $base = 800 * $mv;
      $final = $base + (100 * $mv);
      $perdida = 80 * $mv;
    }

    $montoVencido += $final;
    $comisionesPerdidas += $perdida;

    $nombre = trim(s($p['nombres'] ?? '') . ' ' . s($p['apellido_pa'] ?? '') . ' ' . s($p['apellido_ma'] ?? ''));

    $vencidos[] = [
      'id_pasaporte' => i($p['id_pasaporte'] ?? 0),
      'nombre_completo' => $nombre !== '' ? $nombre : 'Sin nombre registrado',
      'nombre_empresa' => s($p['nombre_empresa'] ?? '') ?: 'No aplica',
      'vigencia' => s($p['vigencia'] ?? ''),
      'estatus' => 'Falta de pago',
      'valor_base' => n($base),
      'valor_final' => n($final),
      'meses_vencidos' => $mv,
      'recargo_acumulado' => $freq === 'anual' ? 0 : n(100 * $mv),
      'correo' => s($p['correo'] ?? ''),
      'telefono' => s($p['telefono'] ?? ''),
      'frecuencia_origen' => s($p['frecuencia_pago'] ?? ''),
      'comision_perdida' => n($perdida)
    ];
  }
}

$totalFreq = max(1, $anuales + $mensuales);

out_json([
  'ok' => true,
  'modo_directos_franquicia' => false,
  'distribuidor' => $dist,
  'kpis' => [
    'ventas_real' => n($ventasReal),
    'ventas_nominal' => n($ventasNominal),
    'monto_vencido' => n($montoVencido),
    'activos' => $activos,
    'vencidos' => $vencidosCount,
    'comision_distribuidor' => n($comisionesActivas),
    'mis_comisiones_activas' => n($comisionesActivas),
    'mis_comisiones_pagadas' => 0,
    'mis_comisiones_compensadas' => 0,
    'mis_comisiones_activas_teoricas' => n($comisionesActivas),
    'mis_comisiones_perdidas' => n($comisionesPerdidas),
    'total_pasaportes' => count($pasaportes),
    'valor_distribucion_no_comisionable' => n($dist['valor_distribucion'] ?? 0)
  ],
  'frecuencia' => [
    'anuales' => $anuales,
    'mensuales' => $mensuales,
    'porcentaje_anuales' => round(($anuales / $totalFreq) * 100, 1),
    'porcentaje_mensuales' => round(($mensuales / $totalFreq) * 100, 1)
  ],
  'vencidos' => $vencidos,
  'charts' => [
    'nominal_real' => [
      'labels' => ['Nominal', 'Real'],
      'values' => [n($ventasNominal), n($ventasReal)]
    ],
    'estado' => [
      'labels' => ['Activos', 'Vencidos'],
      'values' => [$activos, $vencidosCount]
    ]
  ]
]);