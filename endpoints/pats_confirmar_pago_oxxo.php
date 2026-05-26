<?php
/*
ez/pats/endpoints/pats_confirmar_pago_oxxo.php
POST — admin confirma un pago OXXO pendiente.
*/
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../../../varSQL/bd_pats.php';
require_once __DIR__ . '/../../../varSQL/var_pats.php';

function cp_json(array $d, int $code = 200): void {
  http_response_code($code);
  echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  cp_json(['ok' => false, 'error' => 'Método no permitido.'], 405);
}

$adminrol = strtoupper(trim((string)($_SESSION['rol']   ?? '')));
$rolapp   = strtoupper(trim((string)($_SESSION['rolapp'] ?? '')));
$rolesAdmin = ['ADMIN', 'ADMINPATS', 'DIRO', 'DIRG', 'VIC'];

if (!in_array($adminrol, $rolesAdmin, true) && !in_array($rolapp, $rolesAdmin, true)) {
  cp_json(['ok' => false, 'error' => 'Sin autorización.'], 403);
}

$idOrden = (int)($_POST['id_orden'] ?? 0);
$obs     = trim((string)($_POST['observaciones'] ?? ''));
$idAdmin = (int)($_SESSION['id_usuario'] ?? 0);

if ($idOrden <= 0) {
  cp_json(['ok' => false, 'error' => 'ID de orden inválido.'], 422);
}

$cx = $conexionMod ?? $db_all2 ?? $db_all ?? null;
if (!$cx || !($cx instanceof mysqli)) {
  cp_json(['ok' => false, 'error' => 'Sin conexión a base de datos.'], 500);
}

$cx->begin_transaction();

try {
  /* 1) Actualizar estatus de la orden */
  $stmtO = $cx->prepare("
    UPDATE pats_ordenes_pago
    SET estatus_orden = 'PAGO_CONFIRMADO',
        estatus_pago  = 'CONFIRMADO',
        fecha_pago    = COALESCE(fecha_pago, NOW()),
        updated_at    = NOW()
    WHERE id_orden = ? AND estatus_pago IN ('PENDIENTE','PENDIENTE_OXXO')
    LIMIT 1
  ");
  if (!$stmtO) throw new RuntimeException('Prepare orden: ' . $cx->error);
  $stmtO->bind_param('i', $idOrden);
  $stmtO->execute();
  $affected = $stmtO->affected_rows;
  $stmtO->close();

  if ($affected === 0) {
    $cx->rollback();
    cp_json(['ok' => false, 'error' => 'La orden no existe o ya fue procesada.'], 422);
  }

  /* 2) Actualizar pats_oxxo_pendientes si existe */
  $tblCheck = $cx->query("SHOW TABLES LIKE 'pats_oxxo_pendientes'");
  if ($tblCheck && $tblCheck->num_rows > 0) {
    $stmtX = $cx->prepare("
      UPDATE pats_oxxo_pendientes
      SET estatus        = 'CONFIRMADO',
          confirmado_por = ?,
          confirmado_en  = NOW(),
          observaciones  = ?,
          updated_at     = NOW()
      WHERE id_orden = ?
    ");
    if ($stmtX) {
      $stmtX->bind_param('isi', $idAdmin, $obs, $idOrden);
      $stmtX->execute();
      $stmtX->close();
    }
  }

  /* 3) Activar pasaporte asociado */
  $stmtP = $cx->prepare("
    UPDATE pats_pasaportes pp
    INNER JOIN pats_ordenes_pago op ON op.id_pasaporte_generado = pp.id_pasaporte
    SET pp.activo = 1, pp.updated_at = NOW()
    WHERE op.id_orden = ?
    LIMIT 1
  ");
  if ($stmtP) {
    $stmtP->bind_param('i', $idOrden);
    $stmtP->execute();
    $stmtP->close();
  }

  $cx->commit();
  cp_json(['ok' => true, 'message' => 'Pago confirmado correctamente.']);
} catch (Throwable $e) {
  $cx->rollback();
  cp_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
