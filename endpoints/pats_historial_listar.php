<?php
/*
ez/pats/endpoints/pats_historial_listar.php
GET — lista órdenes de pago para el historial admin.
*/
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../../../varSQL/bd_pats.php';
require_once __DIR__ . '/../../../varSQL/var_pats.php';

function hl_json(array $d, int $code = 200): void {
  http_response_code($code);
  echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$adminrol = strtoupper(trim((string)($_SESSION['rol']   ?? '')));
$rolapp   = strtoupper(trim((string)($_SESSION['rolapp'] ?? '')));
$rolesAdmin = ['ADMIN', 'ADMINPATS', 'DIRO', 'DIRG', 'VIC'];

if (!in_array($adminrol, $rolesAdmin, true) && !in_array($rolapp, $rolesAdmin, true)) {
  hl_json(['ok' => false, 'error' => 'Sin autorización.'], 403);
}

$cx = $conexionMod ?? $db_all2 ?? $db_all ?? null;
if (!$cx || !($cx instanceof mysqli)) {
  hl_json(['ok' => false, 'error' => 'Sin conexión a base de datos.'], 500);
}

$metodo  = strtoupper(trim((string)($_GET['metodo']  ?? '')));
$estatus = strtoupper(trim((string)($_GET['estatus'] ?? '')));
$buscar  = trim((string)($_GET['buscar'] ?? ''));
$page    = max(1, (int)($_GET['page'] ?? 1));
$limit   = 30;
$offset  = ($page - 1) * $limit;

$where  = [];
$params = [];
$types  = '';

if ($metodo !== '') {
  $where[]  = 'metodo_pago = ?';
  $params[] = $metodo;
  $types   .= 's';
}
if ($estatus !== '') {
  if ($estatus === 'PENDIENTE') {
    $where[] = "(estatus_pago = 'PENDIENTE' OR estatus_orden = 'PENDIENTE_OXXO')";
  } elseif ($estatus === 'PENDIENTE_OXXO') {
    $where[] = "estatus_orden = 'PENDIENTE_OXXO'";
  } else {
    $where[]  = 'estatus_pago = ?';
    $params[] = $estatus;
    $types   .= 's';
  }
}
if ($buscar !== '') {
  $like     = '%' . $buscar . '%';
  $where[]  = '(correo_usuario_pats LIKE ? OR referencia_pago LIKE ? OR folio_orden LIKE ?)';
  $params[] = $like; $params[] = $like; $params[] = $like;
  $types   .= 'sss';
}

$whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* Columnas opcionales (pueden no existir aún si la migración no corrió) */
$extraCols = "
  COALESCE(op.metodo_pago, 'TARJETA') AS metodo_pago,
  '' AS oxxo_numero_referencia,
  '' AS oxxo_voucher_url
";

/* Try to detect if oxxo columns exist */
$colCheck = $cx->query("SHOW COLUMNS FROM pats_ordenes_pago LIKE 'metodo_pago'");
if ($colCheck && $colCheck->num_rows > 0) {
  $extraCols = "
    COALESCE(op.metodo_pago, 'TARJETA') AS metodo_pago,
    COALESCE(op.oxxo_numero_referencia, '') AS oxxo_numero_referencia,
    COALESCE(op.oxxo_voucher_url, '') AS oxxo_voucher_url
  ";
} else {
  /* fallback from pats_oxxo_pendientes */
  $extraCols = "
    CASE WHEN ox.id IS NOT NULL THEN 'OXXO' ELSE 'TARJETA' END AS metodo_pago,
    COALESCE(ox.oxxo_numero_referencia, '') AS oxxo_numero_referencia,
    COALESCE(ox.oxxo_voucher_url, '') AS oxxo_voucher_url
  ";
}

$countSql = "
  SELECT COUNT(*) AS total
  FROM pats_ordenes_pago op
  LEFT JOIN pats_oxxo_pendientes ox ON ox.id_orden = op.id_orden
  {$whereClause}
";

$listSql = "
  SELECT
    op.id_orden,
    op.folio_orden,
    op.referencia_pago,
    op.correo_usuario_pats,
    op.nombre_usuario,
    op.apellido_pa,
    op.monto_orden,
    op.moneda,
    op.frecuencia,
    op.estatus_orden,
    op.estatus_pago,
    op.proveedor_pasarela,
    op.payment_intent_id,
    op.created_at,
    {$extraCols}
  FROM pats_ordenes_pago op
  LEFT JOIN pats_oxxo_pendientes ox ON ox.id_orden = op.id_orden
  {$whereClause}
  ORDER BY op.id_orden DESC
  LIMIT {$limit} OFFSET {$offset}
";

try {
  /* count */
  if ($params) {
    $stmtC = $cx->prepare($countSql);
    $stmtC->bind_param($types, ...$params);
    $stmtC->execute();
    $total = (int)($stmtC->get_result()->fetch_assoc()['total'] ?? 0);
    $stmtC->close();
  } else {
    $total = (int)($cx->query($countSql)->fetch_assoc()['total'] ?? 0);
  }

  /* list */
  if ($params) {
    $stmtL = $cx->prepare($listSql);
    $stmtL->bind_param($types, ...$params);
    $stmtL->execute();
    $rows = $stmtL->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtL->close();
  } else {
    $rows = $cx->query($listSql)->fetch_all(MYSQLI_ASSOC);
  }

  hl_json([
    'ok'          => true,
    'items'       => $rows,
    'total'       => $total,
    'page'        => $page,
    'limit'       => $limit,
    'total_pages' => (int)ceil($total / $limit),
  ]);
} catch (Throwable $e) {
  hl_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
