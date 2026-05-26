<?php
/*
ez/pats/endpoints/pasarela_procesar_confirmados.php
*/
require_once __DIR__ . '/bootstrap.php';

mysqli_report(MYSQLI_REPORT_OFF);

/* =========================================================
   HELPERS
========================================================= */
function pp_money($v): float {
  return round((float)($v ?? 0), 2);
}

function pp_clean($v): string {
  return trim((string)($v ?? ''));
}

function pp_freq_to_tipo_precio(string $frecuencia): int {
  return strtoupper(trim($frecuencia)) === 'MENSUAL' ? 2 : 1;
}

function pp_build_vigencia(string $frecuencia): array {
  $freq = strtoupper(trim($frecuencia));
  $hoy = new DateTime('now');

  if ($freq === 'MENSUAL') {
    $vigencia = (clone $hoy)->modify('+1 month')->format('Y-m-d');
    $vencReal = (clone $hoy)->modify('+1 month')->setTime(23, 59, 59)->format('Y-m-d H:i:s');
  } else {
    $vigencia = (clone $hoy)->modify('+1 year')->format('Y-m-d');
    $vencReal = (clone $hoy)->modify('+1 year')->setTime(23, 59, 59)->format('Y-m-d H:i:s');
  }

  return [$vigencia, $vencReal];
}

function pp_pasarela_exit_ok(int $procesados, int $errores): void {
  pats_json([
    'ok' => true,
    'procesados' => $procesados,
    'errores' => $errores
  ]);
}

function pp_decode_json_array($json): array {
  if (is_array($json)) return $json;
  $tmp = json_decode((string)$json, true);
  return is_array($tmp) ? $tmp : [];
}

function pp_checkout_context(array $orden): array {
  $payload = pp_decode_json_array($orden['payload_confirmacion_json'] ?? '{}');

  if (!empty($payload['checkout_context']) && is_array($payload['checkout_context'])) {
    return $payload['checkout_context'];
  }

  if (!empty($payload['payload_prev']['checkout_context']) && is_array($payload['payload_prev']['checkout_context'])) {
    return $payload['payload_prev']['checkout_context'];
  }

  return [
    'actor_tipo_publico' => 'DISTRIBUIDOR',
    'tipo_origen' => 'DISTRIBUIDOR',
    'origen_checkout' => 'PORTAL_PUBLICO',
    'id_distribuidor' => (int)($orden['id_distribuidor'] ?? 0),
    'id_gestor' => 0,
    'id_franquicia' => (int)($orden['id_franquicia'] ?? 0),
    'pais' => (string)($orden['pais'] ?? 'México'),
    'region' => (string)($orden['region'] ?? ''),
    'zona' => (string)($orden['zona'] ?? ''),
    'unidad' => (string)($orden['unidad'] ?? '')
  ];
}

function pp_merge_payload_confirmacion(array $orden, array $append): string {
  $prev = pp_decode_json_array($orden['payload_confirmacion_json'] ?? '{}');
  $merged = array_merge($prev, $append);
  return json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function pp_insert_pago_pasaporte(mysqli $cx, array $orden): void {
  $ref = addslashes((string)($orden['referencia_pago'] ?? ''));
  $exists = pats_one($cx, "
    SELECT id
    FROM pats_pagos_pasaporte
    WHERE referencia_pago = '{$ref}'
    LIMIT 1
  ");
  if ($exists) return;

  $stmt = $cx->prepare("
    INSERT INTO pats_pagos_pasaporte
    (
      id_orden, id_pasaporte, id_franquicia, id_distribuidor, id_tipo_precio,
      correo, curp, nombre_usuario, apellido_pa, apellido_ma,
      tipo_operacion,
      monto, monto_nominal_base, monto_extra_recargo,
      frecuencia, metodo_pago, referencia_pago, referencia_externa,
      transaccion_id_externa, payment_intent_id, charge_id, proveedor_pasarela,
      estatus_pago, response_json, fecha_pago, fecha_confirmacion, moneda,
      observaciones, created_at, updated_at
    )
    VALUES
    (
      ?, ?, ?, ?, ?,
      ?, ?, ?, ?, ?,
      ?,
      ?, ?, ?,
      ?, ?, ?, ?,
      ?, ?, ?, ?,
      'confirmado', ?, NOW(), NOW(), ?,
      ?, NOW(), NOW()
    )
  ");
  if (!$stmt) {
    throw new RuntimeException('No fue posible preparar pats_pagos_pasaporte: ' . $cx->error);
  }

  $idOrden = (int)($orden['id_orden'] ?? 0);
  $idPasaporte = !empty($orden['id_pasaporte']) ? (int)$orden['id_pasaporte'] : null;
  $idFranquicia = (int)($orden['id_franquicia'] ?? 0);
  $idDistribuidor = (int)($orden['id_distribuidor'] ?? 0);
  $idTipoPrecio = (int)($orden['id_tipo_precio'] ?? 0);

  $correo = (string)($orden['correo_usuario_pats'] ?? '');
  $curp = (string)($orden['curp_usuario'] ?? '');
  $nombre = (string)($orden['nombre_usuario'] ?? '');
  $apPa = (string)($orden['apellido_pa'] ?? '');
  $apMa = (string)($orden['apellido_ma'] ?? '');

  $tipoOperacion = (string)($orden['tipo_operacion'] ?? 'ALTA_PATS');

  $montoTotal = pp_money($orden['monto_orden'] ?? 0);
  $montoNominalBase = pp_money($orden['monto_nominal_base'] ?? 0);
  $montoExtraRecargo = pp_money($orden['monto_extra_recargo'] ?? 0);

  $frecuencia = strtolower((string)($orden['frecuencia'] ?? 'anual'));
  $metodoPago = (string)($orden['metodo_pago'] ?? 'pasarela');
  $referenciaPago = (string)($orden['referencia_pago'] ?? '');
  $referenciaExterna = (string)($orden['referencia_externa'] ?? '');
  $trx = (string)($orden['transaccion_id_externa'] ?? '');
  $pi = (string)($orden['payment_intent_id'] ?? '');
  $ch = (string)($orden['charge_id'] ?? '');
  $proveedor = (string)($orden['proveedor_pasarela'] ?? '');

  $responseJson = (string)($orden['payload_confirmacion_json'] ?? '{}');
  $moneda = (string)($orden['moneda'] ?? 'MXN');
  $observaciones = 'Pago confirmado vía pasarela para orden ' . $referenciaPago;

  $stmt->bind_param(
    'iiiiissssssdddsssssssssss',
    $idOrden,
    $idPasaporte,
    $idFranquicia,
    $idDistribuidor,
    $idTipoPrecio,
    $correo,
    $curp,
    $nombre,
    $apPa,
    $apMa,
    $tipoOperacion,
    $montoTotal,
    $montoNominalBase,
    $montoExtraRecargo,
    $frecuencia,
    $metodoPago,
    $referenciaPago,
    $referenciaExterna,
    $trx,
    $pi,
    $ch,
    $proveedor,
    $responseJson,
    $moneda,
    $observaciones
  );

  if (!$stmt->execute()) {
    throw new RuntimeException('No fue posible insertar pats_pagos_pasaporte: ' . $stmt->error);
  }
  $stmt->close();
}

function pp_upsert_pasaporte(mysqli $cx, array &$orden): void {
  $frecuencia = strtoupper(trim((string)($orden['frecuencia'] ?? 'ANUAL')));
  [$vigencia, $vencReal] = pp_build_vigencia($frecuencia);

  $idPasaporte = (int)($orden['id_pasaporte'] ?? 0);
  $montoBase = pp_money($orden['monto_nominal_base'] ?? $orden['monto_orden'] ?? 0);
  $montoFinal = pp_money(($orden['monto_nominal_base'] ?? 0) + ($orden['monto_extra_recargo'] ?? 0));
  if ($montoFinal <= 0) $montoFinal = pp_money($orden['monto_orden'] ?? 0);

  $idTipoPrecio = (int)($orden['id_tipo_precio'] ?: pp_freq_to_tipo_precio($frecuencia));

  if ($idPasaporte > 0) {
    $stmt = $cx->prepare("
      UPDATE pats_pasaportes
      SET
        id_tipo_precio = ?,
        frecuencia_pago = ?,
        estatus = 'activo',
        valor_pasaporte = ?,
        valor_final_pasaporte = ?,
        fecha_ultimo_pago = NOW(),
        fecha_vencimiento_real = ?,
        vigencia = ?,
        meses_vencidos = 0,
        recargo_acumulado = 0,
        activo = 1,
        updated_at = NOW()
      WHERE id_pasaporte = ?
      LIMIT 1
    ");
    if (!$stmt) {
      throw new RuntimeException('No fue posible preparar update pats_pasaportes: ' . $cx->error);
    }

    $stmt->bind_param(
      'isddssi',
      $idTipoPrecio,
      $frecuencia,
      $montoBase,
      $montoFinal,
      $vencReal,
      $vigencia,
      $idPasaporte
    );

    if (!$stmt->execute()) {
      throw new RuntimeException('No fue posible actualizar pats_pasaportes: ' . $stmt->error);
    }
    $stmt->close();
    return;
  }

  $stmt = $cx->prepare("
    INSERT INTO pats_pasaportes
    (
      id_franquicia, id_distribuidor, id_tipo_precio,
      id_cliente, id_beneficiario, id_unidad,
      curp, nombres, apellido_pa, apellido_ma, fecha_nacimiento,
      telefono, correo,
      fecha_alta, vigencia, fecha_baja,
      frecuencia_pago, estatus,
      valor_pasaporte, valor_final_pasaporte,
      cupon, pais, region, zona, unidad,
      tipo_cliente, nombre_empresa,
      fecha_ultimo_pago, fecha_vencimiento_real,
      meses_vencidos, recargo_acumulado, activo,
      created_at, updated_at
    )
    VALUES
    (
      ?, ?, ?,
      NULL, NULL, NULL,
      ?, ?, ?, ?, ?,
      ?, ?,
      NOW(), ?, NULL,
      ?, 'activo',
      ?, ?,
      NULL, ?, ?, ?, ?,
      ?, ?,
      NOW(), ?,
      0, 0, 1,
      NOW(), NOW()
    )
  ");
  if (!$stmt) {
    throw new RuntimeException('No fue posible preparar insert pats_pasaportes: ' . $cx->error);
  }

  $idFranquicia = (int)($orden['id_franquicia'] ?? 0);
  $idDistribuidor = (int)($orden['id_distribuidor'] ?? 0);
  $curp = (string)($orden['curp_usuario'] ?? '');
  $nombres = (string)($orden['nombre_usuario'] ?? '');
  $apPa = (string)($orden['apellido_pa'] ?? '');
  $apMa = (string)($orden['apellido_ma'] ?? '');
  $fechaNacimiento = (string)($orden['fecha_nacimiento'] ?? '');
  $telefono = (string)($orden['telefono_usuario'] ?? '');
  $correo = (string)($orden['correo_usuario_pats'] ?? '');
  $pais = (string)($orden['pais'] ?? 'México');
  $region = (string)($orden['region'] ?? '');
  $zona = (string)($orden['zona'] ?? '');
  $unidad = (string)($orden['unidad'] ?? '');
  $tipoCliente = (string)($orden['tipo_cliente'] ?? 'privado');
  $nombreEmpresa = (string)($orden['nombre_empresa'] ?? null);

  $stmt->bind_param(
    'iiissssssssssddssssss',
    $idFranquicia,
    $idDistribuidor,
    $idTipoPrecio,
    $curp,
    $nombres,
    $apPa,
    $apMa,
    $fechaNacimiento,
    $telefono,
    $correo,
    $vigencia,
    $frecuencia,
    $montoBase,
    $montoFinal,
    $pais,
    $region,
    $zona,
    $unidad,
    $tipoCliente,
    $nombreEmpresa,
    $vencReal
  );

  if (!$stmt->execute()) {
    throw new RuntimeException('No fue posible insertar pats_pasaportes: ' . $stmt->error);
  }

  $nuevoId = (int)$stmt->insert_id;
  $stmt->close();

  $orden['id_pasaporte'] = $nuevoId;

  $stmt2 = $cx->prepare("
    UPDATE pats_ordenes_pago
    SET
      id_pasaporte = ?,
      pasaporte_creado = 1,
      id_pasaporte_generado = ?,
      fecha_alta_pasaporte = NOW(),
      procesado_integracion = 1,
      fecha_procesamiento_integracion = NOW(),
      updated_at = NOW()
    WHERE id_orden = ?
    LIMIT 1
  ");
  if (!$stmt2) {
    throw new RuntimeException('No fue posible preparar update orden->pasaporte: ' . $cx->error);
  }

  $idOrden = (int)$orden['id_orden'];
  $stmt2->bind_param('iii', $nuevoId, $nuevoId, $idOrden);
  if (!$stmt2->execute()) {
    throw new RuntimeException('No fue posible actualizar orden con id_pasaporte: ' . $stmt2->error);
  }
  $stmt2->close();
}

function pp_insert_comision(mysqli $cx, int $idOrden, string $benefTipo, ?int $benefId, float $monto, string $obs): void {
  $benefTipoEsc = addslashes($benefTipo);
  $exists = pats_one($cx, "
    SELECT id_comision
    FROM pats_comisiones_generadas
    WHERE tipo_origen = 'pago_pats'
      AND id_origen = {$idOrden}
      AND beneficiario_tipo = '{$benefTipoEsc}'
    LIMIT 1
  ");
  if ($exists) return;

  $stmt = $cx->prepare("
    INSERT INTO pats_comisiones_generadas
    (
      tipo_origen, id_origen, id_regla,
      beneficiario_tipo, beneficiario_id,
      monto_comision, moneda, fecha_generacion,
      estatus, observaciones, created_at, updated_at
    )
    VALUES
    ('pago_pats', ?, NULL, ?, ?, ?, 'MXN', NOW(), 'por_pagar', ?, NOW(), NOW())
  ");
  if (!$stmt) {
    throw new RuntimeException('No fue posible preparar comisión: ' . $cx->error);
  }

  $stmt->bind_param('isids', $idOrden, $benefTipo, $benefId, $monto, $obs);
  if (!$stmt->execute()) {
    throw new RuntimeException('No fue posible insertar comisión: ' . $stmt->error);
  }
  $stmt->close();
}

function pp_insert_comision_gestor(mysqli $cx, int $idGestor, int $idFranquicia, int $idOrden, string $frecuencia, float $monto, string $obs): void {
  if ($idGestor <= 0 || $monto <= 0) return;

  $exists = pats_one($cx, "
    SELECT id_comision
    FROM pats_comisiones_gestor
    WHERE id_gestor = {$idGestor}
      AND origen_tipo = 'PAGO_PATS_DIRECTO'
      AND origen_id = {$idOrden}
    LIMIT 1
  ");
  if ($exists) return;

  $hoy = new DateTime('now');
  $periodoAnio = (int)$hoy->format('Y');
  $periodoMes = (int)$hoy->format('n');
  $baseCalculo = strtoupper(trim($frecuencia)) === 'ANUAL' ? 9600.00 : 800.00;

  $stmt = $cx->prepare("
    INSERT INTO pats_comisiones_gestor
    (
      id_gestor, id_franquicia, origen_tipo, origen_id,
      periodo_anio, periodo_mes,
      base_calculo, monto_comision_gestor,
      estatus, fecha_calculo, fecha_pago, observaciones,
      created_at, updated_at
    )
    VALUES
    (?, ?, 'PAGO_PATS_DIRECTO', ?, ?, ?, ?, ?, 'GENERADA', NOW(), NULL, ?, NOW(), NOW())
  ");
  if (!$stmt) {
    throw new RuntimeException('No fue posible preparar comisión gestor PATS: ' . $cx->error);
  }

  $stmt->bind_param(
    'iiiiidds',
    $idGestor,
    $idFranquicia,
    $idOrden,
    $periodoAnio,
    $periodoMes,
    $baseCalculo,
    $monto,
    $obs
  );

  if (!$stmt->execute()) {
    throw new RuntimeException('No fue posible insertar comisión gestor PATS: ' . $stmt->error);
  }
  $stmt->close();
}

function pp_insert_mov_fin(mysqli $cx, string $tipo, int $idRelacionado, ?int $idPasaporte, float $monto, string $tipoMovimiento, string $estatus, array $orden, string $obs): void {
  $ref = addslashes((string)($orden['referencia_pago'] ?? ''));
  $tipoEsc = addslashes($tipo);
  $movEsc = addslashes($tipoMovimiento);

  $exists = pats_one($cx, "
    SELECT id_movimiento
    FROM pats_movimientos_financieros
    WHERE tipo = '{$tipoEsc}'
      AND id_relacionado = {$idRelacionado}
      AND tipo_movimiento = '{$movEsc}'
      AND observaciones LIKE '%{$ref}%'
    LIMIT 1
  ");
  if ($exists) return;

  $stmt = $cx->prepare("
    INSERT INTO pats_movimientos_financieros
    (
      tipo, id_relacionado, id_pasaporte, monto, tipo_movimiento, estatus, fecha_generado,
      evidencia, pais, region, zona, unidad, id_unidad, moneda, observaciones,
      created_at, updated_at
    )
    VALUES
    (?, ?, ?, ?, ?, ?, NOW(),
     NULL, ?, ?, ?, ?, NULL, 'MXN', ?, NOW(), NOW())
  ");
  if (!$stmt) {
    throw new RuntimeException('No fue posible preparar movimiento financiero: ' . $cx->error);
  }

  $pais = (string)($orden['pais'] ?? 'México');
  $region = (string)($orden['region'] ?? '');
  $zona = (string)($orden['zona'] ?? '');
  $unidad = (string)($orden['unidad'] ?? '');

  $stmt->bind_param(
    'siidsssssss',
    $tipo,
    $idRelacionado,
    $idPasaporte,
    $monto,
    $tipoMovimiento,
    $estatus,
    $pais,
    $region,
    $zona,
    $unidad,
    $obs
  );

  if (!$stmt->execute()) {
    throw new RuntimeException('No fue posible insertar movimiento financiero: ' . $stmt->error);
  }
  $stmt->close();
}

function pp_generar_reparto_pats(mysqli $cx, array $orden): void {
  $frecuenciaRaw = trim((string)($orden['frecuencia'] ?? 'ANUAL'));
  $frecuenciaUpper = strtoupper($frecuenciaRaw);
  $frecuenciaRule = strtolower($frecuenciaRaw);
  if (!in_array($frecuenciaRule, ['anual', 'mensual'], true)) {
    $frecuenciaRule = ($frecuenciaUpper === 'MENSUAL') ? 'mensual' : 'anual';
  }

  $idOrden = (int)($orden['id_orden'] ?? 0);
  $idPasaporte = !empty($orden['id_pasaporte']) ? (int)$orden['id_pasaporte'] : null;
  $idFranq = (int)($orden['id_franquicia'] ?? 0);
  $idDist = (int)($orden['id_distribuidor'] ?? 0);

  $montoBase = pp_money($orden['monto_nominal_base'] ?? 0);
  $montoRecargo = pp_money($orden['monto_extra_recargo'] ?? 0);
  if ($montoBase <= 0) {
    $montoBase = pp_money($orden['monto_orden'] ?? 0);
  }

  $fechaBase = !empty($orden['created_at']) ? substr((string)$orden['created_at'], 0, 10) : date('Y-m-d');
  $split = pats_pats_split($cx, $frecuenciaRule, $fechaBase);

  $admin = pp_money($split['admin'] ?? 0);
  $unidad = pp_money($split['unidad'] ?? 0);
  $franq = pp_money($split['franquicia'] ?? 0);
  $dist = pp_money($split['distribuidor'] ?? 0);

  $ctx = pp_checkout_context($orden);
  $actorTipoPublico = strtoupper(trim((string)($ctx['actor_tipo_publico'] ?? 'DISTRIBUIDOR')));
  $idGestor = (int)($ctx['id_gestor'] ?? 0);

  $ventaDirectaFranquicia = ($actorTipoPublico === 'FRANQUICIA' && $idDist <= 0);
  $ventaDirectaGestor = ($actorTipoPublico === 'GESTOR' && $idDist <= 0 && $idGestor > 0);

  if ($montoBase > 0) {
    if ($admin > 0) {
      pp_insert_comision($cx, $idOrden, 'admin', 1, $admin, 'Comisión ADMIN por pago PATS ' . $orden['referencia_pago']);
      pp_insert_mov_fin($cx, 'corpo', 1, $idPasaporte, $admin, 'comision_pats_admin', 'pendiente', $orden, 'Comisión ADMIN por pago PATS ' . $orden['referencia_pago']);
    }

    if ($ventaDirectaFranquicia) {
      $montoDirectoFranq = pp_money($franq + $dist);
      if ($montoDirectoFranq > 0) {
        pp_insert_comision($cx, $idOrden, 'franquicia', $idFranq, $montoDirectoFranq, 'Comisión directa FRANQUICIA por pago PATS ' . $orden['referencia_pago']);
        pp_insert_mov_fin($cx, 'franquicia', $idFranq, $idPasaporte, $montoDirectoFranq, 'comision_pats_franquicia_directa', 'pendiente', $orden, 'Comisión directa FRANQUICIA por pago PATS ' . $orden['referencia_pago']);
      }
    } elseif ($ventaDirectaGestor) {
      $montoDirectoGestor = pp_money($franq + $dist);
      if ($montoDirectoGestor > 0) {
        pp_insert_comision_gestor($cx, $idGestor, $idFranq, $idOrden, $frecuenciaUpper, $montoDirectoGestor, 'Comisión directa GESTOR por pago PATS ' . $orden['referencia_pago']);
        pp_insert_mov_fin($cx, 'gestor', $idGestor, $idPasaporte, $montoDirectoGestor, 'comision_pats_gestor_directa', 'pendiente', $orden, 'Comisión directa GESTOR por pago PATS ' . $orden['referencia_pago']);
      }
    } else {
      if ($franq > 0) {
        pp_insert_comision($cx, $idOrden, 'franquicia', $idFranq, $franq, 'Comisión FRANQUICIA por pago PATS ' . $orden['referencia_pago']);
        pp_insert_mov_fin($cx, 'franquicia', $idFranq, $idPasaporte, $franq, 'comision_pats_franquicia', 'pendiente', $orden, 'Comisión FRANQUICIA por pago PATS ' . $orden['referencia_pago']);
      }

      if ($dist > 0 && $idDist > 0) {
        pp_insert_comision($cx, $idOrden, 'distribuidor', $idDist, $dist, 'Comisión DISTRIBUIDOR por pago PATS ' . $orden['referencia_pago']);
        pp_insert_mov_fin($cx, 'distribuidor', $idDist, $idPasaporte, $dist, 'comision_pats_distribuidor', 'pendiente', $orden, 'Comisión DISTRIBUIDOR por pago PATS ' . $orden['referencia_pago']);
      } elseif ($dist > 0 && $idDist <= 0 && $idFranq > 0) {
        $montoDirectoFallback = pp_money($franq + $dist);
        pp_insert_comision($cx, $idOrden, 'franquicia', $idFranq, $montoDirectoFallback, 'Comisión FRANQUICIA sin distribuidor por pago PATS ' . $orden['referencia_pago']);
        pp_insert_mov_fin($cx, 'franquicia', $idFranq, $idPasaporte, $montoDirectoFallback, 'comision_pats_franquicia_sin_distribuidor', 'pendiente', $orden, 'Comisión FRANQUICIA sin distribuidor por pago PATS ' . $orden['referencia_pago']);
      }
    }

    if ($unidad > 0) {
      pp_insert_mov_fin($cx, 'unidad', $idFranq, $idPasaporte, $unidad, 'ingreso_pats_unidad', 'pagado', $orden, 'Ingreso unidad/hospital por pago PATS ' . $orden['referencia_pago']);
    }
  }

  if ($montoRecargo > 0) {
    pp_insert_comision($cx, $idOrden, 'admin', 1, $montoRecargo, 'Recargo ADMIN PATS ' . $orden['referencia_pago']);
    pp_insert_mov_fin($cx, 'corpo', 1, $idPasaporte, $montoRecargo, 'recargo_pats_admin', 'pagado', $orden, 'Recargo ADMIN PATS ' . $orden['referencia_pago']);
  }

  pp_insert_mov_fin($cx, 'corpo', $idOrden, $idPasaporte, pp_money($orden['monto_orden'] ?? 0), 'pago_pats_confirmado', 'pagado', $orden, 'Pago PATS confirmado ' . $orden['referencia_pago']);
}

function pp_extract_base_recargo(array $orden, array $raw): array {
  $monto = pp_money($orden['monto_orden'] ?? 0);
  $frecuencia = strtoupper(trim((string)($orden['frecuencia'] ?? 'ANUAL')));

  $baseEsperada = ($frecuencia === 'MENSUAL') ? 800.00 : 9600.00;
  $base = min($monto, $baseEsperada);
  $recargo = max(0, $monto - $base);

  if (isset($orden['monto_nominal_base']) && pp_money($orden['monto_nominal_base']) > 0) {
    $base = pp_money($orden['monto_nominal_base']);
  }
  if (isset($orden['monto_extra_recargo']) && pp_money($orden['monto_extra_recargo']) >= 0) {
    $recargo = pp_money($orden['monto_extra_recargo']);
  }

  return [$base, $recargo];
}

/* =========================================================
   LEER RAW
========================================================= */
$rows = pats_all($cx, "
  SELECT *
  FROM pats_gateway_eventos_raw
  WHERE procesado = 0
  ORDER BY id_evento_raw ASC
  LIMIT 100
");

$procesados = 0;
$errores = 0;

foreach ($rows as $row) {
  $idEventoRaw = (int)($row['id_evento_raw'] ?? 0);
  $referencia = pp_clean($row['referencia_externa'] ?? '');
  $orderId = pp_clean($row['order_id'] ?? '');
  $transaccion = pp_clean($row['transaccion_id_externa'] ?? '');
  $paymentIntent = pp_clean($row['payment_intent_id'] ?? '');
  $chargeId = pp_clean($row['charge_id'] ?? '');
  $payloadJsonRaw = pp_decode_json_array($row['payload_json'] ?? '{}');

  $estatusProveedor = strtoupper(pp_clean($row['event_status'] ?? ''));
  $tipoEvento = strtoupper(pp_clean($row['event_type'] ?? ''));

  $esPagoConfirmado =
    in_array($estatusProveedor, ['PAID', 'SUCCEEDED', 'CONFIRMED', 'AUTHORIZED', 'CAPTURED'], true)
    || in_array($tipoEvento, ['PAYMENT_SUCCEEDED', 'PAYMENT_CONFIRMED', 'CHARGE_SUCCEEDED'], true);

  try {
    if (!$esPagoConfirmado) {
      $cx->query("
        UPDATE pats_gateway_eventos_raw
        SET procesado = 1,
            fecha_procesamiento = NOW(),
            error_procesamiento = NULL,
            updated_at = NOW()
        WHERE id_evento_raw = {$idEventoRaw}
        LIMIT 1
      ");
      $procesados++;
      continue;
    }

    $where = [];
    if ($referencia !== '') {
      $where[] = "referencia_pago = '" . addslashes($referencia) . "'";
      $where[] = "referencia_externa = '" . addslashes($referencia) . "'";
    }
    if ($orderId !== '') {
      $where[] = "folio_orden = '" . addslashes($orderId) . "'";
      $where[] = "order_id_externo = '" . addslashes($orderId) . "'";
    }
    if ($paymentIntent !== '') {
      $where[] = "payment_intent_id = '" . addslashes($paymentIntent) . "'";
    }

    if (!$where) {
      throw new RuntimeException('Evento sin referencia_pago ni order_id');
    }

    $orden = pats_one($cx, "
      SELECT *
      FROM pats_ordenes_pago
      WHERE " . implode(' OR ', $where) . "
      LIMIT 1
    ");

    if (!$orden) {
      throw new RuntimeException('No se encontró la orden');
    }

    $idOrden = (int)($orden['id_orden'] ?? 0);
    [$montoBase, $montoRecargo] = pp_extract_base_recargo($orden, $row);

    $cx->begin_transaction();

    $proveedor = pp_clean($row['proveedor'] ?? 'GENERIC');
    $payloadMerged = pp_merge_payload_confirmacion($orden, [
      'gateway_raw' => $payloadJsonRaw,
      'gateway_status' => [
        'event_status' => $estatusProveedor,
        'event_type' => $tipoEvento,
        'procesado_en' => date('Y-m-d H:i:s')
      ]
    ]);

    $stmt = $cx->prepare("
      UPDATE pats_ordenes_pago
      SET
        estatus_orden = 'PAGADA',
        estatus_pago = 'CONFIRMADO',
        proveedor_pasarela = ?,
        transaccion_id_externa = ?,
        payment_intent_id = ?,
        charge_id = ?,
        referencia_externa = ?,
        order_id_externo = ?,
        payload_confirmacion_json = ?,
        monto_nominal_base = ?,
        monto_extra_recargo = ?,
        fecha_pago = NOW(),
        fecha_confirmacion = NOW(),
        updated_at = NOW()
      WHERE id_orden = ?
      LIMIT 1
    ");
    if (!$stmt) {
      throw new RuntimeException('No fue posible preparar update orden: ' . $cx->error);
    }

    $stmt->bind_param(
      'sssssssddi',
      $proveedor,
      $transaccion,
      $paymentIntent,
      $chargeId,
      $referencia,
      $orderId,
      $payloadMerged,
      $montoBase,
      $montoRecargo,
      $idOrden
    );

    if (!$stmt->execute()) {
      throw new RuntimeException('No fue posible actualizar la orden: ' . $stmt->error);
    }
    $stmt->close();

    $orden = pats_one($cx, "
      SELECT *
      FROM pats_ordenes_pago
      WHERE id_orden = {$idOrden}
      LIMIT 1
    ");
    if (!$orden) {
      throw new RuntimeException('No se pudo recargar la orden actualizada');
    }

    pp_upsert_pasaporte($cx, $orden);
    pp_insert_pago_pasaporte($cx, $orden);
    pp_generar_reparto_pats($cx, $orden);

    $cx->query("
      UPDATE pats_gateway_eventos_raw
      SET procesado = 1,
          fecha_procesamiento = NOW(),
          error_procesamiento = NULL,
          updated_at = NOW()
      WHERE id_evento_raw = {$idEventoRaw}
      LIMIT 1
    ");

    $cx->commit();
    $procesados++;

  } catch (Throwable $e) {
    @$cx->rollback();
    $errores++;

    $cx->query("
      UPDATE pats_gateway_eventos_raw
      SET
        intentos_procesamiento = intentos_procesamiento + 1,
        error_procesamiento = '" . addslashes($e->getMessage()) . "',
        updated_at = NOW()
      WHERE id_evento_raw = {$idEventoRaw}
      LIMIT 1
    ");
  }
}

pp_pasarela_exit_ok($procesados, $errores);