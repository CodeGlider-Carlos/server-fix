<?php
/*
ez/pats/endpoints/simular_pago_confirmado.php

SIMULADOR LOCAL:
- Busca una orden por id_orden / referencia / folio
- Inserta un evento fake pagado en pats_gateway_eventos_raw
- Ejecuta el procesador real de confirmados
- Devuelve JSON para verificar si ya alimentó tablas operativas/dashboard
*/
require_once __DIR__ . '/bootstrap.php';

mysqli_report(MYSQLI_REPORT_OFF);

/* =========================================================
   HELPERS
========================================================= */
function spc_json(array $payload, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function spc_clean($v): string {
  return trim((string)($v ?? ''));
}

function spc_num($v): float {
  return round((float)($v ?? 0), 2);
}

function spc_find_orden(mysqli $cx, int $idOrden, string $ref, string $folio): ?array {
  $where = [];

  if ($idOrden > 0) {
    $where[] = "id_orden = {$idOrden}";
  }
  if ($ref !== '') {
    $refEsc = $cx->real_escape_string($ref);
    $where[] = "referencia_pago = '{$refEsc}'";
    $where[] = "referencia_externa = '{$refEsc}'";
  }
  if ($folio !== '') {
    $folioEsc = $cx->real_escape_string($folio);
    $where[] = "folio_orden = '{$folioEsc}'";
    $where[] = "order_id_externo = '{$folioEsc}'";
  }

  if (!$where) return null;

  $sql = "
    SELECT *
    FROM pats_ordenes_pago
    WHERE " . implode(' OR ', $where) . "
    ORDER BY id_orden DESC
    LIMIT 1
  ";

  $rs = $cx->query($sql);
  if (!$rs) {
    throw new RuntimeException('No fue posible consultar la orden: ' . $cx->error);
  }

  $row = $rs->fetch_assoc();
  $rs->close();

  return $row ?: null;
}

function spc_insert_raw_pagado(mysqli $cx, array $orden): int {
  $ref = spc_clean($orden['referencia_pago'] ?? '');
  $folio = spc_clean($orden['folio_orden'] ?? '');
  $paymentIntent = spc_clean($orden['payment_intent_id'] ?? '');
  $chargeId = spc_clean($orden['charge_id'] ?? '');
  $moneda = spc_clean($orden['moneda'] ?? 'MXN');
  if ($moneda === '') $moneda = 'MXN';

  $payload = [
    'provider' => 'SIMULADOR_LOCAL',
    'event_id' => 'SIM-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(4))),
    'event_type' => 'PAYMENT_SUCCEEDED',
    'event_status' => 'PAID',
    'reference' => $ref,
    'order_id' => $folio,
    'payment_intent_id' => $paymentIntent !== '' ? $paymentIntent : ('PI-SIM-' . strtoupper(bin2hex(random_bytes(4)))),
    'charge_id' => $chargeId !== '' ? $chargeId : ('CH-SIM-' . strtoupper(bin2hex(random_bytes(4)))),
    'transaction_id' => 'TRX-SIM-' . strtoupper(bin2hex(random_bytes(4))),
    'amount' => spc_num($orden['monto_orden'] ?? 0),
    'currency' => $moneda,
    'metadata' => [
      'referencia_pago' => $ref,
      'folio_orden' => $folio,
      'id_orden' => (int)($orden['id_orden'] ?? 0)
    ]
  ];

  $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $headersJson = json_encode([
    'X-Simulated' => '1',
    'X-Source' => 'simular_pago_confirmado.php'
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  $stmt = $cx->prepare("
    INSERT INTO pats_gateway_eventos_raw
    (
      proveedor, ambiente,
      event_id, event_type, event_status,
      transaccion_id_externa, referencia_externa, payment_intent_id, charge_id, order_id, customer_id_externo,
      monto, moneda,
      payload_json, headers_json,
      firma_valida, procesado, intentos_procesamiento,
      fuente, ip_origen, user_agent,
      fecha_evento_proveedor, fecha_recepcion, created_at, updated_at
    )
    VALUES
    (?, 'LOCAL',
     ?, ?, ?,
     ?, ?, ?, ?, ?, '',
     ?, ?,
     ?, ?,
     1, 0, 0,
     'SIMULADOR_LOCAL', '127.0.0.1', 'SIMULADOR_LOCAL',
     NOW(), NOW(), NOW(), NOW()
    )
  ");
  if (!$stmt) {
    throw new RuntimeException('No fue posible preparar raw simulado: ' . $cx->error);
  }

  $proveedor = 'SIMULADOR_LOCAL';
  $eventId = (string)$payload['event_id'];
  $eventType = (string)$payload['event_type'];
  $eventStatus = (string)$payload['event_status'];
  $trx = (string)$payload['transaction_id'];
  $referencia = (string)$payload['reference'];
  $pi = (string)$payload['payment_intent_id'];
  $ch = (string)$payload['charge_id'];
  $orderId = (string)$payload['order_id'];
  $monto = (float)$payload['amount'];

  $stmt->bind_param(
    'sssssssssdsss',
    $proveedor,
    $eventId,
    $eventType,
    $eventStatus,
    $trx,
    $referencia,
    $pi,
    $ch,
    $orderId,
    $monto,
    $moneda,
    $payloadJson,
    $headersJson
  );

  if (!$stmt->execute()) {
    throw new RuntimeException('No fue posible insertar raw simulado: ' . $stmt->error);
  }

  $idEvento = (int)$stmt->insert_id;
  $stmt->close();

  return $idEvento;
}

function spc_resumen_post(mysqli $cx, int $idOrden): array {
  $idOrden = (int)$idOrden;

  $orden = null;
  $rs = $cx->query("
    SELECT id_orden, id_pasaporte, referencia_pago, folio_orden, estatus_orden, estatus_pago, procesado_integracion
    FROM pats_ordenes_pago
    WHERE id_orden = {$idOrden}
    LIMIT 1
  ");
  if ($rs) {
    $orden = $rs->fetch_assoc() ?: null;
    $rs->close();
  }

  $idPasaporte = (int)($orden['id_pasaporte'] ?? 0);

  $pagoPasaporte = null;
  $rs = $cx->query("
    SELECT id, referencia_pago, monto, estatus_pago
    FROM pats_pagos_pasaporte
    WHERE id_orden = {$idOrden}
    ORDER BY id DESC
    LIMIT 1
  ");
  if ($rs) {
    $pagoPasaporte = $rs->fetch_assoc() ?: null;
    $rs->close();
  }

  $comisiones = [
    'admin' => 0,
    'franquicia' => 0,
    'distribuidor' => 0
  ];
  $rs = $cx->query("
    SELECT beneficiario_tipo, COUNT(*) total
    FROM pats_comisiones_generadas
    WHERE tipo_origen = 'pago_pats'
      AND id_origen = {$idOrden}
    GROUP BY beneficiario_tipo
  ");
  if ($rs) {
    while ($row = $rs->fetch_assoc()) {
      $tipo = strtolower((string)($row['beneficiario_tipo'] ?? ''));
      if (isset($comisiones[$tipo])) {
        $comisiones[$tipo] = (int)($row['total'] ?? 0);
      }
    }
    $rs->close();
  }

  $movs = 0;
  $rs = $cx->query("
    SELECT COUNT(*) c
    FROM pats_movimientos_financieros
    WHERE observaciones LIKE '%" . $cx->real_escape_string((string)($orden['referencia_pago'] ?? '')) . "%'
  ");
  if ($rs) {
    $row = $rs->fetch_assoc();
    $movs = (int)($row['c'] ?? 0);
    $rs->close();
  }

  $pasaporte = null;
  if ($idPasaporte > 0) {
    $rs = $cx->query("
      SELECT id_pasaporte, estatus, frecuencia_pago, valor_pasaporte, valor_final_pasaporte, vigencia, fecha_vencimiento_real
      FROM pats_pasaportes
      WHERE id_pasaporte = {$idPasaporte}
      LIMIT 1
    ");
    if ($rs) {
      $pasaporte = $rs->fetch_assoc() ?: null;
      $rs->close();
    }
  }

  return [
    'orden' => $orden,
    'pasaporte' => $pasaporte,
    'pago_pasaporte' => $pagoPasaporte,
    'comisiones' => $comisiones,
    'movimientos_financieros_relacionados' => $movs
  ];
}

/* =========================================================
   ENTRADA
========================================================= */
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  spc_json([
    'ok' => false,
    'error' => 'Método no permitido. Usa POST.'
  ], 405);
}

$idOrden = (int)($_POST['id_orden'] ?? 0);
$ref = spc_clean($_POST['ref'] ?? '');
$folio = spc_clean($_POST['folio'] ?? '');

if ($idOrden <= 0 && $ref === '' && $folio === '') {
  spc_json([
    'ok' => false,
    'error' => 'Debes enviar id_orden, ref o folio'
  ], 422);
}

try {
  $orden = spc_find_orden($cx, $idOrden, $ref, $folio);
  if (!$orden) {
    spc_json([
      'ok' => false,
      'error' => 'No se encontró la orden'
    ], 404);
  }

  $idOrdenReal = (int)($orden['id_orden'] ?? 0);

  // Inserta raw fake pendiente de procesar
  $idEventoRaw = spc_insert_raw_pagado($cx, $orden);

  // Ejecuta el procesador real por lote
  ob_start();
  require __DIR__ . '/pasarela_procesar_confirmados.php';
  $salidaProcesador = trim((string)ob_get_clean());

  $jsonProcesador = null;
  if ($salidaProcesador !== '') {
    $tmp = json_decode($salidaProcesador, true);
    if (is_array($tmp)) {
      $jsonProcesador = $tmp;
    }
  }

  $resumen = spc_resumen_post($cx, $idOrdenReal);

  spc_json([
    'ok' => true,
    'modo' => 'SIMULADOR_LOCAL',
    'id_evento_raw' => $idEventoRaw,
    'id_orden' => $idOrdenReal,
    'referencia_pago' => $orden['referencia_pago'] ?? '',
    'folio_orden' => $orden['folio_orden'] ?? '',
    'resultado_procesador' => $jsonProcesador,
    'resumen' => $resumen
  ]);

} catch (Throwable $e) {
  spc_json([
    'ok' => false,
    'error' => $e->getMessage()
  ], 500);
}