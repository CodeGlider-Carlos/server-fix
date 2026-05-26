<?php
/*
ez/pats/index.php
*/
session_start();
require_once '../../varSQL/bd_pats.php';
require_once '../../varSQL/var_pats.php';

if (empty($_SESSION['usuario'])) {
  session_destroy();
  header("Location: ../../../index.php");
  exit;
}
$adminrol   = strtoupper(trim((string)($_SESSION['rol'] ?? '')));
$rolapp     = strtoupper(trim((string)($_SESSION['rolapp'] ?? '')));

$userLog    = trim((string)($_SESSION['usuario'] ?? ''));
$userName   = trim((string)($_SESSION['nombre'] ?? $userLog));
$userRegion = trim((string)($_SESSION['acroregion'] ?? ($_SESSION['region'] ?? '')));
$userUnidad = trim((string)($_SESSION['acronu'] ?? ($_SESSION['unidad'] ?? '')));

$rolesAdmin = ['ADMIN', 'ADMINPATS', 'DIRO', 'DIRG', 'VIC'];

if (in_array($adminrol, $rolesAdmin, true) || in_array($rolapp, $rolesAdmin, true)) {
  header('Location: admin.php');
  exit;
}

if (in_array($adminrol, ['FRANQ', 'FRANQPATS', 'FRANQUICIATARIO'], true) || in_array($rolapp, ['FRANQ', 'FRANQPATS', 'FRANQUICIATARIO'], true)) {
  header('Location: franquicia.php');
  exit;
}

if (in_array($adminrol, ['DIST', 'DISTPATS', 'DISTRIBUIDOR'], true) || in_array($rolapp, ['DIST', 'DISTPATS', 'DISTRIBUIDOR'], true)) {
  header('Location: distribuidor.php');
  exit;
}

if (in_array($adminrol, ['GESTOR', 'GESTORPATS', 'GESTOR_FRANQUICIAS'], true) || in_array($rolapp, ['GESTOR', 'GESTORPATS', 'GESTOR_FRANQUICIAS'], true)) {
  header('Location: gestor.php');
  exit;
}

header('Location: admin.php');
exit;