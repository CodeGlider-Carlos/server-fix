<?php
/*
ez/pats/endpoints/pasarela_webhook.php
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../patsfin/endpoints/bootstrap.php';
require_once __DIR__ . '/../../patsfin/lib/mail_welcome.php';

mysqli_report(MYSQLI_REPORT_OFF);

/* =========================================================
   HELPERS BASE
========================================================= */
function pw_clean($v): string {
  return trim((string)($v ?? ''));
}

function pw_num($v): float {
  return round((float)($v ?? 0), 2);
}

function pw_headers_json(): string {
  if (function_exists('getallheaders')) {
    $headers = getallheaders();
  } else {
    $headers = [];
    foreach ($_SERVER as $k => $v) {
      if (strpos($k, 'HTTP_') === 0) {
        $headers[$k] = $v;
      }
    }
  }
  return json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function pw_json(array $payload, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function pw_event_is_paid(string $status, string $type = ''): bool {
  $status = strtoupper(trim($status));
  $type   = strtoupper(trim($type));

  if (in_array($status, ['PAID', 'APPROVED', 'CONFIRMED', 'COMPLETED', 'SUCCESS', 'SUCCEEDED', 'PAGADO'], true)) {
    return true;
  }

  if (in_array($type, ['PAYMENT.SUCCEEDED', 'CHARGE.SUCCEEDED', 'ORDER.PAID'], true)) {
    return true;
  }

  return false;
}

function pw_password_temp(int $len = 10): string {
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789@#$%';
  $max = strlen($alphabet) - 1;
  $out = '';
  for ($i = 0; $i < $len; $i++) {
    $out .= $alphabet[random_int(0, $max)];
  }
  return $out;
}

function pw_generate_public_checkout_token(string $seed = ''): string {
  return substr(hash('sha256', $seed . '|' . microtime(true) . '|' . bin2hex(random_bytes(16))), 0, 64);
}

function pw_insert_historial_local(
  mysqli $cx,
  string $entidadTipo,
  int $entidadId,
  string $eventoTipo,
  ?string $estadoAnterior,
  ?string $estadoNuevo,
  ?string $payloadJson,
  ?int $userEvento
): void {
  $stmt = $cx->prepare("
    INSERT INTO pats_historial
    (entidad_tipo, entidad_id, evento_tipo, estado_anterior, estado_nuevo, payload_json, user_evento, fecha_evento, created_at)
    VALUES
    (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
  ");
  if (!$stmt) {
    throw new RuntimeException('No fue posible preparar historial');
  }

  $stmt->bind_param(
    'sissssi',
    $entidadTipo,
    $entidadId,
    $eventoTipo,
    $estadoAnterior,
    $estadoNuevo,
    $payloadJson,
    $userEvento
  );

  if (!$stmt->execute()) {
    throw new RuntimeException('No fue posible guardar historial: ' . $stmt->error);
  }
  $stmt->close();
}

function pw_insert_documento_local(mysqli $cx, string $actorTipo, int $actorId, string $tipoDocumento, array $meta, int $userAlta = 0): void {
  $stmt = $cx->prepare("
    INSERT INTO pats_documentos_actor
    (
      actor_tipo, actor_id, tipo_documento,
      archivo_path, archivo_nombre_original, mime_type, size_kb,
      vigente, observaciones, user_alta, created_at, updated_at
    )
    VALUES
    (?, ?, ?, ?, ?, ?, ?, 1, NULL, ?, NOW(), NOW())
  ");
  if (!$stmt) {
    throw new RuntimeException('No fue posible preparar inserción de documento');
  }

  $path = (string)($meta['path'] ?? '');
  $orig = (string)($meta['original'] ?? '');
  $mime = (string)($meta['mime'] ?? '');
  $size = (int)($meta['size_kb'] ?? 0);

  $stmt->bind_param(
    'sissssii',
    $actorTipo,
    $actorId,
    $tipoDocumento,
    $path,
    $orig,
    $mime,
    $size,
    $userAlta
  );

  if (!$stmt->execute()) {
    throw new RuntimeException('No fue posible guardar documento: ' . $stmt->error);
  }
  $stmt->close();
}

function pw_find_orden_pago(mysqli $cx, string $referenciaExterna, string $orderId, string $paymentIntentId): ?array {
  $where = [];

  if ($referenciaExterna !== '') {
    $where[] = "referencia_pago = '" . fin_esc($cx, $referenciaExterna) . "'";
    $where[] = "referencia_externa = '" . fin_esc($cx, $referenciaExterna) . "'";
  }

  if ($orderId !== '') {
    $where[] = "folio_orden = '" . fin_esc($cx, $orderId) . "'";
    $where[] = "order_id_externo = '" . fin_esc($cx, $orderId) . "'";
  }

  if ($paymentIntentId !== '') {
    $where[] = "payment_intent_id = '" . fin_esc($cx, $paymentIntentId) . "'";
  }

  if (!$where) return null;

  return fin_one($cx, "
    SELECT *
    FROM pats_ordenes_pago
    WHERE " . implode(' OR ', $where) . "
    ORDER BY id_orden DESC
    LIMIT 1
  ");
}

function pw_update_orden_pago_gateway(
  mysqli $cx,
  int $idOrden,
  string $transaccionIdExterna,
  string $referenciaExterna,
  string $paymentIntentId,
  string $chargeId,
  string $payloadJson,
  string $estatusOrden,
  string $estatusPago
): void {
  $stmt = $cx->prepare("
    UPDATE pats_ordenes_pago
    SET
      transaccion_id_externa = ?,
      referencia_externa = ?,
      payment_intent_id = ?,
      charge_id = ?,
      payload_confirmacion_json = ?,
      estatus_orden = ?,
      estatus_pago = ?,
      fecha_confirmacion = NOW(),
      fecha_pago = CASE WHEN ? = 'CONFIRMADO' THEN NOW() ELSE fecha_pago END,
      updated_at = NOW()
    WHERE id_orden = ?
    LIMIT 1
  ");
  if (!$stmt) {
    throw new RuntimeException('No fue posible preparar update de orden: ' . $cx->error);
  }

  $stmt->bind_param(
    'ssssssssi',
    $transaccionIdExterna,
    $referenciaExterna,
    $paymentIntentId,
    $chargeId,
    $payloadJson,
    $estatusOrden,
    $estatusPago,
    $estatusPago,
    $idOrden
  );

  if (!$stmt->execute()) {
    throw new RuntimeException('No fue posible actualizar la orden: ' . $stmt->error);
  }
  $stmt->close();
}

function pw_public_checkout_link(string $token): string {
  if ($token === '') return '';
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  return rtrim($scheme . '://' . $host, '/') . '/EZHS/EZHS/ez/pats/pago_pats.php?t=' . urlencode($token);
}

/* =========================================================
   INTEGRACIÓN · ALTA DISTRIBUCIÓN DESDE ORDEN PAGADA
========================================================= */
function pw_integrar_distribucion_desde_orden(mysqli $cx, array $orden): array {
  $idOrden = (int)($orden['id_orden'] ?? 0);
  if ($idOrden <= 0) {
    throw new RuntimeException('Orden inválida para integración');
  }

  if ((int)($orden['procesado_integracion'] ?? 0) === 1) {
    return [
      'ok' => true,
      'ya_procesado' => true,
      'id_distribuidor' => (int)($orden['id_distribuidor'] ?? 0)
    ];
  }

  $payload = json_decode((string)($orden['payload_confirmacion_json'] ?? '{}'), true);
  if (!is_array($payload)) $payload = [];

  $sol = $payload['solicitud_distribucion'] ?? [];
  if (!is_array($sol) || empty($sol)) {
    throw new RuntimeException('La orden no contiene payload de solicitud de distribución');
  }

  $idFranquicia = (int)($orden['id_franquicia'] ?? ($sol['id_franquicia'] ?? 0));
  if ($idFranquicia <= 0) {
    throw new RuntimeException('No se resolvió la franquicia de la orden');
  }

  $franquicia = fin_one($cx, "
    SELECT *
    FROM pats_franquicias
    WHERE id_franquicia = {$idFranquicia}
      AND activo = 1
    LIMIT 1
  ");
  if (!$franquicia) {
    throw new RuntimeException('La franquicia asociada no existe o está inactiva');
  }

  $pais = fin_clean($orden['pais'] ?? ($sol['pais'] ?? ($franquicia['pais'] ?? 'México')));
  $region = strtoupper(fin_clean($orden['region'] ?? ($sol['region'] ?? ($franquicia['region'] ?? ''))));
  $zona = fin_clean($orden['zona'] ?? ($sol['zona'] ?? ($franquicia['zona'] ?? '')));
  $unidad = fin_clean($orden['unidad'] ?? ($sol['unidad'] ?? ($franquicia['unidad'] ?? '')));

  $nombre = fin_clean($orden['nombre_usuario'] ?? '');
  $correo = mb_strtolower(fin_clean($orden['correo_usuario_pats'] ?? ''));
  $telefono = preg_replace('/\D+/', '', (string)($orden['telefono_usuario'] ?? ''));

  $razonSocial = fin_clean($orden['nombre_empresa'] ?? ($sol['razon_social'] ?? ''));
  $rfc = strtoupper(fin_clean($sol['rfc'] ?? ''));
  $direccion = fin_clean($sol['direccion'] ?? '');
  $banco = fin_clean($sol['banco'] ?? '');
  $numeroCuenta = fin_clean($sol['numero_cuenta'] ?? '');
  $clabe = preg_replace('/\D+/', '', (string)($sol['clabe'] ?? ''));
  $titularCuenta = fin_clean($sol['titular_cuenta'] ?? '');

  $modalidadPago = strtoupper(fin_clean($sol['modalidad_pago'] ?? 'CONTADO'));
  $enganche = fin_num($sol['enganche'] ?? 0);
  $saldoFinanciado = fin_num($sol['saldo_financiado'] ?? 0);
  $plazoMeses = (int)($sol['plazo_meses'] ?? 0);
  $periodicidad = strtoupper(fin_clean($sol['periodicidad'] ?? 'MENSUAL'));
  $fechaInicio = fin_clean($sol['fecha_inicio'] ?? date('Y-m-d'));
  $fechaPrimerVenc = fin_clean($sol['fecha_primer_vencimiento'] ?? $fechaInicio);
  $ambitoRegion = fin_clean($sol['ambito_region'] ?? 'misma_region');

  $valorTotal = fin_num($orden['monto_orden'] ?? 0);
  if ($valorTotal <= 0) {
    throw new RuntimeException('Monto de orden inválido para integración');
  }

  $checkDist = fin_one($cx, "
    SELECT id_distribuidor
    FROM pats_distribuidores
    WHERE correo = '" . fin_esc($cx, $correo) . "'
    LIMIT 1
  ");
  if (!empty($checkDist['id_distribuidor'])) {
    $idExistente = (int)$checkDist['id_distribuidor'];

    fin_exec($cx, "
      UPDATE pats_ordenes_pago
      SET
        id_distribuidor = {$idExistente},
        procesado_integracion = 1,
        updated_at = NOW()
      WHERE id_orden = {$idOrden}
      LIMIT 1
    ");

    return [
      'ok' => true,
      'ya_existia' => true,
      'id_distribuidor' => $idExistente
    ];
  }

  $checkUser = fin_one($cx, "
    SELECT id
    FROM pats_users
    WHERE correo = '" . fin_esc($cx, $correo) . "'
       OR usuario = '" . fin_esc($cx, $correo) . "'
    LIMIT 1
  ");
  if (!empty($checkUser['id'])) {
    throw new RuntimeException('Ya existe un usuario PATS con el correo del distribuidor');
  }

  $gestorRel = fin_one($cx, "
    SELECT
      gf.id_relacion,
      gf.id_gestor,
      g.nombre_gestor
    FROM pats_gestor_franquicias gf
    INNER JOIN pats_gestores g
      ON g.id_gestor = gf.id_gestor
     AND g.activo = 1
    WHERE gf.id_franquicia = {$idFranquicia}
      AND gf.activo = 1
    ORDER BY gf.id_relacion DESC
    LIMIT 1
  ");

  $idGestor = (int)($gestorRel['id_gestor'] ?? 0);
  $tieneGestor = $idGestor > 0;

  $tempPassword = pw_password_temp(10);
  $tempPasswordHash = password_hash($tempPassword, PASSWORD_DEFAULT);

  $publicCheckoutToken = pw_generate_public_checkout_token($correo . '|' . $idFranquicia . '|' . $nombre);
  $publicCheckoutActivo = 1;
  $publicCheckoutUpdatedAt = date('Y-m-d H:i:s');

  $codigoDistribuidor = 'DST-' . strtoupper($unidad !== '' ? $unidad : substr($region, 0, 3)) . '-' . str_pad((string)(time() % 1000000), 6, '0', STR_PAD_LEFT);
  $fechaRenovacion = $fechaInicio;
  $fechaProximaRenovacion = (new DateTime($fechaInicio))->modify('+5 years')->format('Y-m-d');

  $stmt = $cx->prepare("
    INSERT INTO pats_distribuidores
    (
      id_franquicia, region, nombre, rfc, telefono, correo, valor_distribucion, fecha_alta,
      zona, file, user_alta, pais, unidad, id_unidad, codigo_distribuidor,
      banco, numero_cuenta, clabe, titular_cuenta,
      estatus, activo,
      comision_acumulada_periodo, comision_pagada_periodo, comision_por_pagar_periodo,
      fecha_renovacion, fecha_proxima_renovacion, renovacion_estatus,
      public_checkout_token, public_checkout_activo, public_checkout_updated_at,
      created_at, updated_at
    )
    VALUES
    (
      ?, ?, ?, ?, ?, ?, ?, ?,
      ?, NULL, 0, ?, ?, NULL, ?,
      ?, ?, ?, ?,
      'ACTIVO', 1,
      0, 0, 0,
      ?, ?, 'vigente',
      ?, ?, ?,
      NOW(), NOW()
    )
  ");
  if (!$stmt) {
    throw new RuntimeException('No fue posible preparar alta de distribuidor');
  }

  $stmt->bind_param(
    'isssssdsssssssssssss',
    $idFranquicia,
    $region,
    $nombre,
    $rfc,
    $telefono,
    $correo,
    $valorTotal,
    $fechaInicio,
    $zona,
    $pais,
    $unidad,
    $codigoDistribuidor,
    $banco,
    $numeroCuenta,
    $clabe,
    $titularCuenta,
    $fechaRenovacion,
    $fechaProximaRenovacion,
    $publicCheckoutToken,
    $publicCheckoutActivo,
    $publicCheckoutUpdatedAt
  );

  if (!$stmt->execute()) {
    throw new RuntimeException('No fue posible guardar distribuidor: ' . $stmt->error);
  }
  $idDistribuidor = (int)$stmt->insert_id;
  $stmt->close();

  $stmt = $cx->prepare("
    INSERT INTO pats_users
    (
      app, rolapp, rol, tipo_actor, id_actor,
      nombre, usuario, correo, contrasena,
      region, acroregion, unidad, acronu,
      vigente, activo, must_change_password,
      password_last_change, last_login_at, failed_attempts, locked_until,
      perfil, ced, telefono, created_at, updated_at
    )
    VALUES
    (
      'PATS', 'DISTPATS', 'DIST', 'DISTRIBUIDOR', ?,
      ?, ?, ?, ?,
      ?, ?, ?, ?,
      NULL, 1, 1,
      NULL, NULL, 0, NULL,
      NULL, NULL, ?, NOW(), NOW()
    )
  ");
  if (!$stmt) {
    throw new RuntimeException('No fue posible preparar usuario distribuidor');
  }

  $stmt->bind_param(
    'isssssssss',
    $idDistribuidor,
    $nombre,
    $correo,
    $correo,
    $tempPasswordHash,
    $region,
    $region,
    $unidad,
    $unidad,
    $telefono
  );

  if (!$stmt->execute()) {
    throw new RuntimeException('No fue posible guardar usuario distribuidor: ' . $stmt->error);
  }
  $idUserPats = (int)$stmt->insert_id;
  $stmt->close();

  fin_exec($cx, "
    UPDATE pats_distribuidores
    SET id_user_pats = {$idUserPats}
    WHERE id_distribuidor = {$idDistribuidor}
    LIMIT 1
  ");

  $payloadDocs = $sol['documentos'] ?? [];
  foreach ([
    'doc_ine' => 'INE',
    'doc_domicilio' => 'COMPROBANTE_DOMICILIO',
    'doc_cedula' => 'CEDULA_FISCAL',
    'doc_contrato' => 'CONTRATO',
    'doc_caratula_bancaria' => 'CARATULA_BANCARIA'
  ] as $k => $tipoDoc) {
    $meta = $payloadDocs[$k] ?? null;
    if (!is_array($meta) || empty($meta['path'])) continue;

    $metaLocal = [
      'path' => (string)($meta['path'] ?? ''),
      'original' => (string)($meta['original'] ?? $tipoDoc),
      'mime' => (string)($meta['mime'] ?? ''),
      'size_kb' => (int)($meta['size_kb'] ?? 0)
    ];
    pw_insert_documento_local($cx, 'distribuidor', $idDistribuidor, $tipoDoc, $metaLocal, 0);
  }

  if (!empty($payloadDocs['doc_contrato']['path'])) {
    fin_exec($cx, "
      UPDATE pats_distribuidores
      SET file = '" . fin_esc($cx, (string)$payloadDocs['doc_contrato']['path']) . "'
      WHERE id_distribuidor = {$idDistribuidor}
      LIMIT 1
    ");
  }

  $numeroContrato = 'DIS-' . str_pad((string)$idDistribuidor, 6, '0', STR_PAD_LEFT);
  $estatusContrato = ($modalidadPago === 'CONTADO' || $saldoFinanciado <= 0) ? 'LIQUIDADO' : 'VIGENTE';
  $fechaFirma = $fechaInicio;

  $stmt = $cx->prepare("
    INSERT INTO pats_contratos_actor
    (
      actor_tipo, actor_id, tipo_contrato, numero_contrato,
      modalidad_pago, valor_total, enganche, saldo_financiado,
      plazo_meses, periodicidad, fecha_inicio, fecha_primer_vencimiento,
      fecha_firma, fecha_termino, moneda, tasa_recargo,
      estatus, motivo_cancelacion, observaciones,
      activo, user_alta, created_at, updated_at
    )
    VALUES
    (
      'distribuidor', ?, 'alta_distribuidor', ?,
      ?, ?, ?, ?,
      ?, ?, ?, ?,
      ?, NULL, 'MXN', 0,
      ?, NULL, NULL,
      1, 0, NOW(), NOW()
    )
  ");
  if (!$stmt) {
    throw new RuntimeException('No fue posible preparar contrato financiero');
  }

  $stmt->bind_param(
    'issdddisssss',
    $idDistribuidor,
    $numeroContrato,
    $modalidadPago,
    $valorTotal,
    $enganche,
    $saldoFinanciado,
    $plazoMeses,
    $periodicidad,
    $fechaInicio,
    $fechaPrimerVenc,
    $fechaFirma,
    $estatusContrato
  );

  if (!$stmt->execute()) {
    throw new RuntimeException('No fue posible guardar contrato financiero: ' . $stmt->error);
  }
  $idContrato = (int)$stmt->insert_id;
  $stmt->close();

  if ($modalidadPago !== 'CONTADO' && $saldoFinanciado > 0 && $plazoMeses > 0) {
    fin_create_parcialidades($cx, $idContrato, $saldoFinanciado, $plazoMeses, $periodicidad, $fechaPrimerVenc);
  }

  $stmt = $cx->prepare("
    INSERT INTO pats_renovaciones_distribucion
    (
      id_distribuidor, id_franquicia, fecha_inicio_vigencia, fecha_fin_vigencia,
      monto_renovacion, ambito_region, estatus, fecha_confirmacion, observaciones,
      created_at, updated_at
    )
    VALUES
    (?, ?, ?, ?, ?, ?, 'vigente', NOW(), ?, NOW(), NOW())
  ");
  if (!$stmt) {
    throw new RuntimeException('No fue posible preparar renovación');
  }

  $obsRenov = 'Alta inicial desde checkout público';
  $stmt->bind_param(
    'iissdss',
    $idDistribuidor,
    $idFranquicia,
    $fechaInicio,
    $fechaProximaRenovacion,
    $valorTotal,
    $ambitoRegion,
    $obsRenov
  );

  if (!$stmt->execute()) {
    throw new RuntimeException('No fue posible guardar renovación: ' . $stmt->error);
  }
  $stmt->close();

  pw_insert_historial_local(
    $cx,
    'distribuidor',
    $idDistribuidor,
    'alta_checkout_publico',
    null,
    'ACTIVO',
    json_encode([
      'id_franquicia' => $idFranquicia,
      'valor_distribucion' => $valorTotal,
      'ambito_region' => $ambitoRegion,
      'numero_contrato' => $numeroContrato,
      'tiene_gestor' => $tieneGestor,
      'id_gestor' => $idGestor > 0 ? $idGestor : null,
      'id_orden' => $idOrden
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    null
  );

  $montoAdmin = 0.0;
  $montoFranq = 0.0;
  $montoGestor = 0.0;
  $idReglaAdmin = null;
  $idReglaFranq = null;

  if ($tieneGestor) {
    $montoAdmin = 9000.00;
    $montoFranq = 10000.00;
    $montoGestor = 1000.00;
  } else {
    $reglaAdmin = fin_get_active_rule($cx, [
      'tipo_operacion' => 'distribucion',
      'subtipo_operacion' => 'alta_renovacion',
      'ambito_region' => $ambitoRegion,
      'beneficiario' => 'admin',
      'fecha' => $fechaInicio
    ]);

    $reglaFranq = fin_get_active_rule($cx, [
      'tipo_operacion' => 'distribucion',
      'subtipo_operacion' => 'alta_renovacion',
      'ambito_region' => $ambitoRegion,
      'beneficiario' => 'franquicia',
      'fecha' => $fechaInicio
    ]);

    $idReglaAdmin = isset($reglaAdmin['id_regla']) ? (int)$reglaAdmin['id_regla'] : null;
    $idReglaFranq = isset($reglaFranq['id_regla']) ? (int)$reglaFranq['id_regla'] : null;

    $montoAdmin = fin_num($reglaAdmin['valor_calculo'] ?? 0);
    $montoFranq = fin_num($reglaFranq['valor_calculo'] ?? 0);
  }

  if ($montoAdmin > 0 && function_exists('fin_insert_comision_generada')) {
    fin_insert_comision_generada(
      $cx,
      'venta_distribucion',
      $idDistribuidor,
      $idReglaAdmin,
      'admin',
      1,
      $montoAdmin,
      $tieneGestor
        ? 'Comisión admin por distribución ligada a franquicia con gestor'
        : 'Comisión admin por alta/renovación de distribución',
      'por_pagar'
    );
  }

  if ($montoAdmin > 0 && function_exists('fin_insert_mov_fin')) {
    fin_insert_mov_fin(
      $cx,
      'corpo',
      $idFranquicia,
      $montoAdmin,
      $tieneGestor
        ? 'comision_distribucion_con_gestor'
        : ($ambitoRegion === 'misma_region' ? 'comision_distribucion_misma_region' : 'comision_distribucion_fuera_region'),
      'pendiente',
      $pais,
      $region,
      $zona,
      $unidad,
      $tieneGestor
        ? 'Comisión ADMIN por distribución con gestor'
        : 'Comisión ADMIN por distribución'
    );
  }

  if ($montoFranq > 0 && function_exists('fin_insert_comision_generada')) {
    fin_insert_comision_generada(
      $cx,
      'venta_distribucion',
      $idDistribuidor,
      $idReglaFranq,
      'franquicia',
      $idFranquicia,
      $montoFranq,
      $tieneGestor
        ? 'Comisión franquicia por distribución ligada a gestor'
        : 'Comisión franquicia por alta/renovación de distribución',
      'por_pagar'
    );
  }

  if ($montoFranq > 0 && function_exists('fin_insert_comision_titulares')) {
    fin_insert_comision_titulares($cx, $idFranquicia, $idDistribuidor, $montoFranq, $fechaInicio);
  }

  if ($montoFranq > 0 && function_exists('fin_insert_mov_fin')) {
    fin_insert_mov_fin(
      $cx,
      'franquicia',
      $idFranquicia,
      $montoFranq,
      $tieneGestor
        ? 'comision_distribucion_franquicia_con_gestor'
        : ($ambitoRegion === 'misma_region' ? 'comision_distribucion_franquicia_misma_region' : 'comision_distribucion_franquicia_fuera_region'),
      'pendiente',
      $pais,
      $region,
      $zona,
      $unidad,
      $tieneGestor
        ? 'Comisión FRANQUICIA por distribución con gestor'
        : 'Comisión FRANQUICIA por distribución'
    );
  }

  if ($montoGestor > 0 && $idGestor > 0) {
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
      (?, ?, 'ALTA_DISTRIBUCION', ?, YEAR(?), MONTH(?),
       ?, ?, 'GENERADA', NOW(), NULL, 'Comisión fija por distribución asociada',
       NOW(), NOW())
    ");
    if (!$stmt) {
      throw new RuntimeException('No fue posible preparar comisión de gestor');
    }

    $stmt->bind_param(
      'iissdd',
      $idGestor,
      $idFranquicia,
      $idDistribuidor,
      $fechaInicio,
      $fechaInicio,
      $valorTotal,
      $montoGestor
    );

    if (!$stmt->execute()) {
      throw new RuntimeException('No fue posible guardar comisión de gestor: ' . $stmt->error);
    }
    $stmt->close();
  }

  fin_exec($cx, "
    UPDATE pats_ordenes_pago
    SET
      id_distribuidor = {$idDistribuidor},
      procesado_integracion = 1,
      updated_at = NOW()
    WHERE id_orden = {$idOrden}
    LIMIT 1
  ");

  return [
    'ok' => true,
    'id_distribuidor' => $idDistribuidor,
    'id_user_pats' => $idUserPats,
    'id_contrato' => $idContrato,
    'public_checkout_token' => $publicCheckoutToken,
    'public_checkout_link' => pw_public_checkout_link($publicCheckoutToken)
  ];
}

/* =========================================================
   LECTURA DEL PAYLOAD
========================================================= */
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload)) {
  $payload = [];
}

$proveedor = pw_clean($_GET['proveedor'] ?? ($_POST['proveedor'] ?? ($payload['provider'] ?? 'GENERIC')));
$ambiente  = pw_clean($_GET['ambiente'] ?? ($_POST['ambiente'] ?? 'PROD'));

$eventId = pw_clean($payload['event_id'] ?? ($payload['id'] ?? ''));
$eventType = pw_clean($payload['event_type'] ?? ($payload['type'] ?? ''));
$eventStatus = pw_clean($payload['event_status'] ?? ($payload['status'] ?? ''));

$transaccionIdExterna = pw_clean($payload['transaction_id'] ?? ($payload['transaccion_id_externa'] ?? ''));
$referenciaExterna = pw_clean(
  $payload['reference']
  ?? ($payload['referencia_externa'] ?? '')
  ?? ($payload['metadata']['referencia_pago'] ?? '')
);

$paymentIntentId = pw_clean($payload['payment_intent_id'] ?? '');
$chargeId = pw_clean($payload['charge_id'] ?? '');
$orderId = pw_clean(
  $payload['order_id']
  ?? ($payload['metadata']['folio_orden'] ?? '')
);
$customerIdExterno = pw_clean($payload['customer_id'] ?? '');

$monto = pw_num($payload['amount'] ?? ($payload['monto'] ?? 0));
$moneda = pw_clean($payload['currency'] ?? ($payload['moneda'] ?? 'MXN'));

$headersJson = pw_headers_json();
$payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$ipOrigen = pw_clean($_SERVER['REMOTE_ADDR'] ?? '');
$userAgent = pw_clean($_SERVER['HTTP_USER_AGENT'] ?? '');

if ($proveedor === '') $proveedor = 'GENERIC';
if ($ambiente === '') $ambiente = 'PROD';
if ($moneda === '') $moneda = 'MXN';

/* =========================================================
   VALIDACIÓN MÍNIMA
========================================================= */
if ($eventId === '' && $referenciaExterna === '' && $orderId === '') {
  pw_json([
    'ok' => false,
    'error' => 'El webhook debe incluir al menos event_id o reference/order_id'
  ], 422);
}

/* =========================================================
   INSERT / UPDATE RAW
========================================================= */
$sql = "
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
  (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 'WEBHOOK', ?, ?, NULL, NOW(), NOW(), NOW())
  ON DUPLICATE KEY UPDATE
    event_type = VALUES(event_type),
    event_status = VALUES(event_status),
    transaccion_id_externa = VALUES(transaccion_id_externa),
    referencia_externa = VALUES(referencia_externa),
    payment_intent_id = VALUES(payment_intent_id),
    charge_id = VALUES(charge_id),
    order_id = VALUES(order_id),
    customer_id_externo = VALUES(customer_id_externo),
    monto = VALUES(monto),
    moneda = VALUES(moneda),
    payload_json = VALUES(payload_json),
    headers_json = VALUES(headers_json),
    ip_origen = VALUES(ip_origen),
    user_agent = VALUES(user_agent),
    updated_at = NOW()
";

$stmt = $cx->prepare($sql);
if (!$stmt) {
  pw_json([
    'ok' => false,
    'error' => 'No fue posible preparar webhook: ' . $cx->error
  ], 500);
}

$stmt->bind_param(
  'sssssssssssddssss',
  $proveedor,
  $ambiente,
  $eventId,
  $eventType,
  $eventStatus,
  $transaccionIdExterna,
  $referenciaExterna,
  $paymentIntentId,
  $chargeId,
  $orderId,
  $customerIdExterno,
  $monto,
  $moneda,
  $payloadJson,
  $headersJson,
  $ipOrigen,
  $userAgent
);

if (!$stmt->execute()) {
  pw_json([
    'ok' => false,
    'error' => 'No fue posible guardar webhook: ' . $stmt->error
  ], 500);
}

$idEvento = (int)$stmt->insert_id;
$stmt->close();

/* =========================================================
   PROCESAR ORDEN RELACIONADA
========================================================= */
$resultadoIntegracion = null;
$tipoOperacion = '';
$orden = pw_find_orden_pago($cx, $referenciaExterna, $orderId, $paymentIntentId);

if ($orden) {
  $idOrden = (int)($orden['id_orden'] ?? 0);
  $tipoOperacion = strtoupper(trim((string)($orden['tipo_operacion'] ?? '')));

  $estatusOrden = (string)($orden['estatus_orden'] ?? 'CHECKOUT_GENERADO');
  $estatusPago = (string)($orden['estatus_pago'] ?? 'PENDIENTE');

  if (pw_event_is_paid($eventStatus, $eventType)) {
    $estatusOrden = 'CONFIRMADO';
    $estatusPago = 'CONFIRMADO';
  } elseif (in_array(strtoupper($eventStatus), ['EXPIRED', 'CANCELLED', 'CANCELED', 'FAILED'], true)) {
    $estatusOrden = 'CANCELADO';
    $estatusPago = 'CANCELADO';
  }

  $payloadMerged = json_encode([
    'webhook_raw' => $payload,
    'payload_prev' => json_decode((string)($orden['payload_confirmacion_json'] ?? '{}'), true)
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  pw_update_orden_pago_gateway(
    $cx,
    $idOrden,
    $transaccionIdExterna,
    $referenciaExterna,
    $paymentIntentId,
    $chargeId,
    $payloadMerged,
    $estatusOrden,
    $estatusPago
  );

  $ordenActualizada = pw_find_orden_pago($cx, $referenciaExterna, $orderId, $paymentIntentId);

  if ($tipoOperacion === 'ALTA_DISTRIBUCION' && $estatusPago === 'CONFIRMADO' && $ordenActualizada) {
    try {
      $cx->begin_transaction();
      $resultadoIntegracion = pw_integrar_distribucion_desde_orden($cx, $ordenActualizada);
      $cx->commit();
    } catch (Throwable $e) {
      @$cx->rollback();
      pw_json([
        'ok' => false,
        'error' => 'Webhook guardado pero falló integración de distribución: ' . $e->getMessage(),
        'id_evento_raw' => $idEvento,
        'id_orden' => $idOrden
      ], 500);
    }
  }
}

/* =========================================================
   RESPUESTA
========================================================= */
pw_json([
  'ok' => true,
  'id_evento_raw' => $idEvento,
  'proveedor' => $proveedor,
  'event_id' => $eventId,
  'referencia_externa' => $referenciaExterna,
  'order_id' => $orderId,
  'payment_intent_id' => $paymentIntentId,
  'procesado_orden' => $orden ? true : false,
  'tipo_operacion' => $tipoOperacion,
  'resultado_integracion' => $resultadoIntegracion
]);