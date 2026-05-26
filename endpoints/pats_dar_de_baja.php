<?php
/*
ez/pats/endpoints/pats_dar_de_baja.php
POST — admin da de baja una orden y desactiva su pasaporte.
*/
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../../../varSQL/bd_pats.php';
require_once __DIR__ . '/../../../varSQL/var_pats.php';

function db_json(array $d, int $code = 200): void {
  http_response_code($code);
  echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  db_json(['ok' => false, 'error' => 'Método no permitido.'], 405);
}

$adminrol = strtoupper(trim((string)($_SESSION['rol']   ?? '')));
$rolapp   = strtoupper(trim((string)($_SESSION['rolapp'] ?? '')));
$rolesAdmin = ['ADMIN', 'ADMINPATS', 'DIRO', 'DIRG', 'VIC'];

if (!in_array($adminrol, $rolesAdmin, true) && !in_array($rolapp, $rolesAdmin, true)) {
  db_json(['ok' => false, 'error' => 'Sin autorización.'], 403);
}

$idOrden = (int)($_POST['id_orden'] ?? 0);
$obs     = trim((string)($_POST['observaciones'] ?? ''));

if ($idOrden <= 0) {
  db_json(['ok' => false, 'error' => 'ID de orden inválido.'], 422);
}

$cx = $conexionMod ?? $db_all2 ?? $db_all ?? null;
if (!$cx || !($cx instanceof mysqli)) {
  db_json(['ok' => false, 'error' => 'Sin conexión a base de datos.'], 500);
}

$cx->begin_transaction();

try {
  /* 1) Marcar orden como CANCELADO */
  $stmtO = $cx->prepare("
    UPDATE pats_ordenes_pago
    SET estatus_orden = 'CANCELADO',
        estatus_pago  = 'CANCELADO',
        updated_at    = NOW()
    WHERE id_orden = ?
    LIMIT 1
  ");
  if (!$stmtO) throw new RuntimeException('Prepare orden: ' . $cx->error);
  $stmtO->bind_param('i', $idOrden);
  $stmtO->execute();
  $stmtO->close();

  /* 2) Desactivar pasaporte vinculado */
  $stmtP = $cx->prepare("
    UPDATE pats_pasaportes pp
    INNER JOIN pats_ordenes_pago op ON op.id_pasaporte_generado = pp.id_pasaporte
    SET pp.activo = 0, pp.updated_at = NOW()
    WHERE op.id_orden = ?
    LIMIT 1
  ");
  if ($stmtP) {
    $stmtP->bind_param('i', $idOrden);
    $stmtP->execute();
    $stmtP->close();
  }

  /* 3) Marcar oxxo_pendiente como CANCELADO si existe */
  $tblCheck = $cx->query("SHOW TABLES LIKE 'pats_oxxo_pendientes'");
  if ($tblCheck && $tblCheck->num_rows > 0) {
    $stmtX = $cx->prepare("
      UPDATE pats_oxxo_pendientes
      SET estatus      = 'CANCELADO',
          observaciones = CONCAT(COALESCE(observaciones,''), IF(? != '', CONCAT(' | Baja: ', ?), '')),
          updated_at   = NOW()
      WHERE id_orden = ?
    ");
    if ($stmtX) {
      $stmtX->bind_param('ssi', $obs, $obs, $idOrden);
      $stmtX->execute();
      $stmtX->close();
    }
  }

  $cx->commit();
  db_json(['ok' => true, 'message' => 'Orden dada de baja correctamente.']);
} catch (Throwable $e) {
  $cx->rollback();
  db_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
