<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_OFF);

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

$bdPats  = __DIR__ . '/../../../varSQL/bd_pats.php';
$varPats = __DIR__ . '/../../../varSQL/var_pats.php';

$out = [
  'ok' => false,
  'archivo' => __FILE__,
  'bdPats' => $bdPats,
  'bdPats_existe' => is_file($bdPats),
  'varPats' => $varPats,
  'varPats_existe' => is_file($varPats),
  'session_usuario' => $_SESSION['usuario'] ?? null,
];

if (!is_file($bdPats)) {
  $out['error'] = 'No existe bd_pats.php';
  echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

require_once $bdPats;

if (is_file($varPats)) {
  require_once $varPats;
}

$cx = $conexionMod ?? $db_all2 ?? $db_all ?? $conexion ?? null;

$out['conexionMod'] = isset($conexionMod) ? gettype($conexionMod) : 'no_definida';
$out['db_all2'] = isset($db_all2) ? gettype($db_all2) : 'no_definida';
$out['db_all'] = isset($db_all) ? gettype($db_all) : 'no_definida';
$out['conexion'] = isset($conexion) ? gettype($conexion) : 'no_definida';
$out['cx_es_mysqli'] = $cx instanceof mysqli;

if (!$cx instanceof mysqli) {
  $out['error'] = 'No hay mysqli disponible';
  echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$rs = $cx->query("SELECT COUNT(*) AS total FROM pats_distribuidores");
$row = $rs ? $rs->fetch_assoc() : null;

$out['ok'] = true;
$out['total_distribuidores'] = $row['total'] ?? null;

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);