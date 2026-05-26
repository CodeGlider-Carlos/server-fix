<?php
/*
  diagnostico_bd.php
  Muestra a qué base de datos se está conectando PATS.
  ELIMINAR después de usar.
*/

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../../varSQL/bd_pats.php';
require_once __DIR__ . '/../../../varSQL/var_pats.php';

$cx = $conexionMod ?? $db_all2 ?? $db_all ?? null;

header('Content-Type: text/plain; charset=utf-8');

if (!$cx || !($cx instanceof mysqli)) {
    echo "ERROR: No hay conexión mysqli disponible.\n";
    exit;
}

echo "=== DIAGNÓSTICO BD PATS ===\n\n";
echo "Host:        " . $cx->host_info . "\n";
echo "Base de datos: ";

$rs = $cx->query("SELECT DATABASE() AS db");
$row = $rs ? $rs->fetch_assoc() : null;
echo ($row['db'] ?? '(no seleccionada)') . "\n";

echo "Usuario:     ";
$rs2 = $cx->query("SELECT USER() AS u");
$row2 = $rs2 ? $rs2->fetch_assoc() : null;
echo ($row2['u'] ?? '(desconocido)') . "\n";

echo "Versión MySQL: " . $cx->server_info . "\n";
echo "Charset:     " . $cx->character_set_name() . "\n";
echo "Error actual: " . ($cx->error ?: 'ninguno') . "\n";

echo "\n=== VARIABLES DISPONIBLES ===\n";
echo 'isset($conexionMod): ' . (isset($conexionMod) ? 'SÍ' : 'NO') . "\n";
echo 'isset($db_all2):     ' . (isset($db_all2)     ? 'SÍ' : 'NO') . "\n";
echo 'isset($db_all):      ' . (isset($db_all)      ? 'SÍ' : 'NO') . "\n";
echo "\nVariable usada: " . (isset($conexionMod) ? '$conexionMod' : (isset($db_all2) ? '$db_all2' : '$db_all')) . "\n";
