<?php
/*
ez/pats/endpoints/public_checkout_generar_orden_distribucion.php
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../public_checkout_resolver.php';

mysqli_report(MYSQLI_REPORT_OFF);

if (!function_exists('ppgd_json')) {
  function ppgd_json(array $payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }
}

if (!function_exists('ppgd_clean')) {
  function ppgd_clean($v): string {
    return trim((string)($v ?? ''));
  }
}

if (!function_exists('ppgd_num')) {
  function ppgd_num($v): float {
    return round((float)($v ?? 0), 2);
  }
}

if (!function_exists('ppgd_digits')) {
  function ppgd_digits($v): string {
    return preg_replace('/\D+/', '', (string)($v ?? ''));
  }
}

if (!function_exists('ppgd_esc')) {
  function ppgd_esc(mysqli $cx, $v): string {
    return $cx->real_escape_string(trim((string)($v ?? '')));
  }
}

if (!function_exists('ppgd_one')) {
  function ppgd_one(mysqli $cx, string $sql): ?array {
    $rs = $cx->query($sql);
    if (!$rs) {
      throw new RuntimeException('Error SQL: ' . $cx->error);
    }
    $row = $rs->fetch_assoc();
    $rs->close();
    return $row ?: null;
  }
}

if (!function_exists('ppgd_exec')) {
  function ppgd_exec(mysqli $cx, string $sql): void {
    if (!$cx->query($sql)) {
      throw new RuntimeException('Error SQL: ' . $cx->error);
    }
  }
}

if (!function_exists('ppgd_valid_email')) {
  function ppgd_valid_email(string $v): bool {
    return (bool)filter_var($v, FILTER_VALIDATE_EMAIL);
  }
}

if (!function_exists('ppgd_valid_phone')) {
  function ppgd_valid_phone(string $v): bool {
    return strlen(ppgd_digits($v)) === 10;
  }
}

if (!function_exists('ppgd_valid_clabe')) {
  function ppgd_valid_clabe(string $clabe): bool {
    $clabe = ppgd_digits($clabe);
    if (strlen($clabe) !== 18) return false;

    $factors = [3, 7, 1];
    $sum = 0;
    for ($i = 0; $i < 17; $i++) {
      $digit = (int)$clabe[$i];
      $factor = $factors[$i % 3];
      $sum += (($digit * $factor) % 10);
    }

    $control = (10 - ($sum % 10)) % 10;
    return $control === (int)$clabe[17];
  }
}

if (!function_exists('ppgd_build_ref')) {
  function ppgd_build_ref(): string {
    return 'DIST-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(4)));
  }
}

if (!function_exists('ppgd_build_folio')) {
  function ppgd_build_folio(): string {
    return 'ODI-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
  }
}

if (!function_exists('ppgd_validate_token')) {
  function ppgd_validate_token(mysqli $cx, string $token): ?array {
    return pats_resolve_public_checkout_token($cx, $token);
  }
}

if (!function_exists('ppgd_store_upload')) {
  function ppgd_store_upload(array $file, string $prefix = 'file'): array {
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
      throw new RuntimeException("Falta archivo requerido: {$prefix}");
    }

    $baseDir = dirname(__DIR__) . '/uploads/public_checkout_distribucion/' . date('Y/m');
    if (!is_dir($baseDir) && !@mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
      throw new RuntimeException('No fue posible crear directorio de uploads públicos');
    }

    $orig = (string)($file['name'] ?? ($prefix . '.bin'));
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $safeExt = preg_match('/^[a-z0-9]{1,8}$/', $ext) ? $ext : 'bin';
    $filename = $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(5)) . '.' . $safeExt;
    $dest = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
      throw new RuntimeException("No fue posible guardar archivo: {$orig}");
    }

    $mime = (string)($file['type'] ?? 'application/octet-stream');
    $sizeKb = (int)ceil(((int)($file['size'] ?? 0)) / 1024);

    return [
      'path' => str_replace(dirname(__DIR__) . DIRECTORY_SEPARATOR, '', $dest),
      'full_path' => $dest,
      'original' => $orig,
      'mime' => $mime,
      'size_kb' => $sizeKb
    ];
  }
}

if (!function_exists('ppgd_provider_create_checkout')) {
  function ppgd_provider_create_checkout(array $orden, array $cliente, array $meta): array {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePats = preg_replace('#/endpoints$#', '', $scriptDir); // /EZHS/EZHS/ez/pats

    $checkoutUrl = rtrim($scheme . '://' . $host . $basePats, '/') .
      '/pago_resultado.php?ref=' . urlencode((string)($orden['referencia_pago'] ?? '')) .
      '&status=PENDIENTE_PROVEEDOR';

    return [
      'checkout_url' => $checkoutUrl,
      'order_id_externo' => 'MOCK-' . strtoupper(bin2hex(random_bytes(4))),
      'referencia_externa' => 'REF-' . strtoupper(bin2hex(random_bytes(4))),
      'payment_intent_id' => '',
      'charge_id' => '',
      'raw_response' => [
        'mock' => true,
        'tipo' => 'distribucion',
        'checkout_url' => $checkoutUrl
      ]
    ];
  }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  ppgd_json(['ok' => false, 'error' => 'Método no permitido'], 405);
}

$tokenPublico = ppgd_clean($_POST['public_token'] ?? $_POST['token_publico'] ?? '');
if ($tokenPublico === '') {
  ppgd_json(['ok' => false, 'error' => 'Falta token público'], 422);
}

$ctx = ppgd_validate_token($cx, $tokenPublico);
if (!$ctx) {
  ppgd_json(['ok' => false, 'error' => 'El link público no es válido o ya no está activo'], 404);
}

$idFranquicia = (int)($ctx['id_franquicia'] ?? 0);
if ($idFranquicia <= 0) {
  ppgd_json(['ok' => false, 'error' => 'No fue posible resolver la franquicia vinculada al enlace'], 422);
}

$required = ['nombre', 'telefono', 'correo', 'modalidad_pago', 'fecha_inicio'];
foreach ($required as $k) {
  if (ppgd_clean($_POST[$k] ?? '') === '') {
    ppgd_json(['ok' => false, 'error' => "Falta campo requerido: {$k}"], 422);
  }
}

$docsRequired = [
  'doc_ine' => 'INE',
  'doc_domicilio' => 'COMPROBANTE_DOMICILIO',
  'doc_cedula' => 'CEDULA_FISCAL',
  'doc_caratula_bancaria' => 'CARATULA_BANCARIA'
];

foreach ($docsRequired as $k => $label) {
  if (empty($_FILES[$k]['tmp_name'])) {
    ppgd_json(['ok' => false, 'error' => "Falta documento requerido: {$label}"], 422);
  }
}

$aceptaConfirmacion = (int)!empty($_POST['acepta_confirmacion']);
if ($aceptaConfirmacion !== 1) {
  ppgd_json(['ok' => false, 'error' => 'Debes confirmar la información para continuar'], 422);
}

$pais = ppgd_clean($_POST['pais'] ?? ($ctx['pais'] ?? 'México'));
$region = strtoupper(ppgd_clean($_POST['region'] ?? ($ctx['region'] ?? '')));
$zona = ppgd_clean($_POST['zona'] ?? ($ctx['zona'] ?? '')));
$unidad = ppgd_clean($_POST['unidad'] ?? ($ctx['unidad'] ?? ''));

$nombre = ppgd_clean($_POST['nombre'] ?? '');
$razonSocial = ppgd_clean($_POST['razon_social'] ?? '');
$direccion = ppgd_clean($_POST['direccion'] ?? '');
$rfc = strtoupper(ppgd_clean($_POST['rfc'] ?? ''));
$telefono = ppgd_digits($_POST['telefono'] ?? '');
$correo = mb_strtolower(ppgd_clean($_POST['correo'] ?? ''));

$banco = ppgd_clean($_POST['banco'] ?? '');
$numeroCuenta = ppgd_clean($_POST['numero_cuenta'] ?? '');
$clabe = ppgd_digits($_POST['clabe'] ?? '');
$titularCuenta = ppgd_clean($_POST['titular_cuenta'] ?? '');

$modalidadPago = strtoupper(ppgd_clean($_POST['modalidad_pago'] ?? 'CONTADO'));
$enganche = ppgd_num($_POST['enganche'] ?? 0);
$valorTotalReq = ppgd_num($_POST['valor_total'] ?? 0);
$plazoMeses = (int)($_POST['plazo_meses'] ?? 0);
$periodicidad = strtoupper(ppgd_clean($_POST['periodicidad'] ?? 'MENSUAL'));
$fechaInicio = ppgd_clean($_POST['fecha_inicio'] ?? '');
$fechaPrimerVenc = ppgd_clean($_POST['fecha_primer_vencimiento'] ?? '');
if ($fechaPrimerVenc === '') $fechaPrimerVenc = $fechaInicio;

if (!ppgd_valid_email($correo)) {
  ppgd_json(['ok' => false, 'error' => 'El correo no es válido'], 422);
}
if (!ppgd_valid_phone($telefono)) {
  ppgd_json(['ok' => false, 'error' => 'El teléfono debe tener 10 dígitos'], 422);
}
if ($clabe !== '' && !ppgd_valid_clabe($clabe)) {
  ppgd_json(['ok' => false, 'error' => 'La CLABE bancaria no es válida'], 422);
}

$franquicia = ppgd_one($cx, "
  SELECT *
  FROM pats_franquicias
  WHERE id_franquicia = {$idFranquicia}
    AND activo = 1
  LIMIT 1
");
if (!$franquicia) {
  ppgd_json(['ok' => false, 'error' => 'La franquicia vinculada al enlace no existe o está inactiva'], 404);
}

$regionFranq = strtoupper(ppgd_clean($franquicia['region'] ?? ''));
$ambitoRegion = ($regionFranq === $region) ? 'misma_region' : 'fuera_region';

$rowPrecio = ppgd_one($cx, "
  SELECT precio
  FROM pats_cat_precios
  WHERE LOWER(TRIM(tipo)) = 'distribucion'
    AND LOWER(TRIM(modalidad)) = '" . ppgd_esc($cx, $ambitoRegion) . "'
  ORDER BY id ASC
  LIMIT 1
");

$valorTotal = ppgd_num($rowPrecio['precio'] ?? ($valorTotalReq > 0 ? $valorTotalReq : 20000));
if ($valorTotal <= 0) {
  ppgd_json(['ok' => false, 'error' => 'No se encontró el precio de distribución'], 422);
}

if ($enganche < 0) $enganche = 0;
if ($enganche > $valorTotal) {
  ppgd_json(['ok' => false, 'error' => 'El enganche no puede ser mayor al valor total'], 422);
}

$saldoFinanciado = max(0, $valorTotal - $enganche);
$estatusOrden = 'CHECKOUT_GENERADO';
$estatusPago = 'PENDIENTE';
$moneda = 'MXN';
$proveedorPasarela = 'mock';

$checkDist = ppgd_one($cx, "
  SELECT id_distribuidor
  FROM pats_distribuidores
  WHERE correo = '" . ppgd_esc($cx, $correo) . "'
  LIMIT 1
");
if (!empty($checkDist['id_distribuidor'])) {
  ppgd_json(['ok' => false, 'error' => 'Ya existe un distribuidor con ese correo'], 409);
}

$checkUser = ppgd_one($cx, "
  SELECT id
  FROM pats_users
  WHERE correo = '" . ppgd_esc($cx, $correo) . "'
     OR usuario = '" . ppgd_esc($cx, $correo) . "'
  LIMIT 1
");
if (!empty($checkUser['id'])) {
  ppgd_json(['ok' => false, 'error' => 'Ya existe un usuario PATS con ese correo/usuario'], 409);
}

$referenciaPago = ppgd_build_ref();
$folioOrden = ppgd_build_folio();
$publicTokenOrden = 'tok_dist_' . strtolower(bin2hex(random_bytes(12)));
$publicTokenExpiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
$linkCheckoutPublico = '';

try {
  $cx->begin_transaction();

  $docIne = ppgd_store_upload($_FILES['doc_ine'], 'dist_ine');
  $docDomicilio = ppgd_store_upload($_FILES['doc_domicilio'], 'dist_dom');
  $docCedula = ppgd_store_upload($_FILES['doc_cedula'], 'dist_ced');
  $docCaratula = ppgd_store_upload($_FILES['doc_caratula_bancaria'], 'dist_caratula');

  $payloadCheckoutJson = json_encode([
    'tipo_origen' => (string)($ctx['actor_tipo_publico'] ?? 'FRANQUICIA'),
    'id_distribuidor_origen' => (int)($ctx['id_distribuidor'] ?? 0),
    'id_gestor_origen' => (int)($ctx['id_gestor'] ?? 0),
    'id_franquicia' => $idFranquicia,
    'correo_usuario_pats' => $correo,
    'nombre_usuario' => $nombre,
    'telefono_usuario' => $telefono,
    'pais' => $pais,
    'region' => $region,
    'zona' => $zona,
    'unidad' => $unidad,
    'tipo_cliente' => 'DISTRIBUIDOR',
    'nombre_empresa' => $razonSocial,
    'tipo_operacion' => 'ALTA_DISTRIBUCION',
    'frecuencia' => $periodicidad,
    'monto_orden' => $valorTotal,
    'monto_nominal_base' => $valorTotal,
    'monto_extra_recargo' => 0,
    'moneda' => $moneda,
    'modalidad_pago' => $modalidadPago,
    'enganche' => $enganche,
    'saldo_financiado' => $saldoFinanciado,
    'plazo_meses' => $plazoMeses,
    'fecha_inicio' => $fechaInicio,
    'fecha_primer_vencimiento' => $fechaPrimerVenc,
    'direccion' => $direccion,
    'rfc' => $rfc,
    'banco' => $banco,
    'numero_cuenta' => $numeroCuenta,
    'clabe' => $clabe,
    'titular_cuenta' => $titularCuenta
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  $sqlOrden = "
    INSERT INTO pats_ordenes_pago
    (
      folio_orden,
      referencia_pago,
      public_token,
      public_token_expires_at,
      link_checkout_publico,
      enviado_cliente,
      fecha_envio_cliente,
      referencia_externa,
      order_id_externo,
      tipo_origen,
      origen_checkout,
      id_distribuidor,
      id_franquicia,
      id_pasaporte,
      correo_usuario_pats,
      curp_usuario,
      nombre_usuario,
      apellido_pa,
      apellido_ma,
      fecha_nacimiento,
      telefono_usuario,
      id_tipo_precio,
      tipo_operacion,
      frecuencia,
      monto_orden,
      monto_nominal_base,
      monto_extra_recargo,
      moneda,
      pais,
      region,
      zona,
      unidad,
      tipo_cliente,
      nombre_empresa,
      estatus_orden,
      estatus_pago,
      proveedor_pasarela,
      transaccion_id_externa,
      payment_intent_id,
      charge_id,
      payload_checkout_json,
      payload_confirmacion_json,
      usuario_creado,
      id_usuario_generado,
      fecha_alta_usuario,
      pasaporte_creado,
      id_pasaporte_generado,
      fecha_alta_pasaporte,
      procesado_integracion,
      fecha_procesamiento_integracion,
      intentos_procesamiento,
      error_integracion,
      observaciones,
      user_creo,
      user_confirmo,
      fecha_orden,
      fecha_pago,
      fecha_confirmacion,
      created_at,
      updated_at
    )
    VALUES
    (
      '" . ppgd_esc($cx, $folioOrden) . "',
      '" . ppgd_esc($cx, $referenciaPago) . "',
      '" . ppgd_esc($cx, $publicTokenOrden) . "',
      '" . ppgd_esc($cx, $publicTokenExpiresAt) . "',
      '" . ppgd_esc($cx, $linkCheckoutPublico) . "',
      0,
      NULL,
      '',
      '',
      '" . ppgd_esc($cx, (string)($ctx['actor_tipo_publico'] ?? 'FRANQUICIA')) . "',
      'PUBLIC_DISTRIBUCION',
      NULL,
      {$idFranquicia},
      NULL,
      '" . ppgd_esc($cx, $correo) . "',
      '',
      '" . ppgd_esc($cx, $nombre) . "',
      '',
      '',
      NULL,
      '" . ppgd_esc($cx, $telefono) . "',
      0,
      'ALTA_DISTRIBUCION',
      '" . ppgd_esc($cx, $periodicidad) . "',
      " . number_format($valorTotal, 2, '.', '') . ",
      " . number_format($valorTotal, 2, '.', '') . ",
      0,
      '" . ppgd_esc($cx, $moneda) . "',
      '" . ppgd_esc($cx, $pais) . "',
      '" . ppgd_esc($cx, $region) . "',
      '" . ppgd_esc($cx, $zona) . "',
      '" . ppgd_esc($cx, $unidad) . "',
      'DISTRIBUIDOR',
      '" . ppgd_esc($cx, $razonSocial) . "',
      'CHECKOUT_GENERADO',
      'PENDIENTE',
      '" . ppgd_esc($cx, $proveedorPasarela) . "',
      '',
      '',
      '',
      '" . ppgd_esc($cx, $payloadCheckoutJson) . "',
      '{}',
      0,
      NULL,
      NULL,
      0,
      NULL,
      NULL,
      0,
      NULL,
      0,
      NULL,
      NULL,
      NULL,
      NULL,
      NOW(),
      NULL,
      NULL,
      NOW(),
      NOW()
    )
  ";

  ppgd_exec($cx, $sqlOrden);
  $idOrden = (int)$cx->insert_id;
  if ($idOrden <= 0) {
    throw new RuntimeException('No fue posible obtener id_orden');
  }

  $payloadDocs = [
    'actor_tipo_publico' => (string)($ctx['actor_tipo_publico'] ?? ''),
    'id_franquicia' => $idFranquicia,
    'id_gestor' => (int)($ctx['id_gestor'] ?? 0),
    'id_distribuidor_origen' => (int)($ctx['id_distribuidor'] ?? 0),
    'ambito_region' => $ambitoRegion,
    'modalidad_pago' => $modalidadPago,
    'enganche' => $enganche,
    'saldo_financiado' => $saldoFinanciado,
    'plazo_meses' => $plazoMeses,
    'periodicidad' => $periodicidad,
    'fecha_inicio' => $fechaInicio,
    'fecha_primer_vencimiento' => $fechaPrimerVenc,
    'rfc' => $rfc,
    'direccion' => $direccion,
    'banco' => $banco,
    'numero_cuenta' => $numeroCuenta,
    'clabe' => $clabe,
    'titular_cuenta' => $titularCuenta,
    'documentos' => [
      'doc_ine' => $docIne,
      'doc_domicilio' => $docDomicilio,
      'doc_cedula' => $docCedula,
      'doc_caratula_bancaria' => $docCaratula
    ]
  ];

  $payloadDocsJson = json_encode($payloadDocs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  $sqlMeta = "
    UPDATE pats_ordenes_pago
    SET
      payload_confirmacion_json = '" . ppgd_esc($cx, $payloadDocsJson) . "',
      updated_at = NOW()
    WHERE id_orden = {$idOrden}
    LIMIT 1
  ";
  ppgd_exec($cx, $sqlMeta);

  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
  $patsBase = dirname($scriptDir); // /ez/pats

  $returnUrl = rtrim($scheme . '://' . $host . $patsBase, '/') .
    '/pago_resultado.php?ref=' . urlencode($referenciaPago);

  $webhookUrl = rtrim($scheme . '://' . $host . $scriptDir, '/') .
    '/pasarela_webhook.php?proveedor=' . urlencode($proveedorPasarela);

  $providerMeta = [
    'referencia_pago' => $referenciaPago,
    'folio_orden' => $folioOrden,
    'id_orden' => $idOrden,
    'id_franquicia' => $idFranquicia,
    'tipo_operacion' => 'ALTA_DISTRIBUCION'
  ];

  $providerPayload = [
    'reference' => $referenciaPago,
    'amount' => $valorTotal,
    'currency' => $moneda,
    'description' => 'Alta Distribución PATS - ' . $nombre,
    'customer_email' => $correo,
    'customer_phone' => $telefono,
    'return_url' => $returnUrl,
    'webhook_url' => $webhookUrl,
    'metadata' => $providerMeta
  ];

  $providerResponse = ppgd_provider_create_checkout(
    [
      'id_orden' => $idOrden,
      'referencia_pago' => $referenciaPago,
      'folio_orden' => $folioOrden,
      'monto_orden' => $valorTotal,
      'moneda' => $moneda
    ],
    [
      'nombre_completo' => $nombre,
      'correo' => $correo,
      'telefono' => $telefono
    ],
    $providerPayload
  );

  $checkoutUrl = (string)($providerResponse['checkout_url'] ?? '');
  $orderIdExterno = (string)($providerResponse['order_id_externo'] ?? '');
  $referenciaExterna = (string)($providerResponse['referencia_externa'] ?? '');
  $paymentIntentId = (string)($providerResponse['payment_intent_id'] ?? '');
  $chargeId = (string)($providerResponse['charge_id'] ?? '');
  $providerRaw = json_encode($providerResponse['raw_response'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  if ($checkoutUrl === '') {
    throw new RuntimeException('La pasarela no devolvió checkout_url');
  }

  $payloadFinal = json_encode([
    'provider_raw' => json_decode($providerRaw, true),
    'solicitud_distribucion' => $payloadDocs
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  $sqlPasarela = "
    UPDATE pats_ordenes_pago
    SET
      link_checkout_publico = '" . ppgd_esc($cx, $checkoutUrl) . "',
      referencia_externa = '" . ppgd_esc($cx, $referenciaExterna) . "',
      order_id_externo = '" . ppgd_esc($cx, $orderIdExterno) . "',
      payment_intent_id = '" . ppgd_esc($cx, $paymentIntentId) . "',
      charge_id = '" . ppgd_esc($cx, $chargeId) . "',
      payload_confirmacion_json = '" . ppgd_esc($cx, $payloadFinal) . "',
      updated_at = NOW()
    WHERE id_orden = {$idOrden}
    LIMIT 1
  ";
  ppgd_exec($cx, $sqlPasarela);

  $cx->commit();

  ppgd_json([
    'ok' => true,
    'id_orden' => $idOrden,
    'referencia_pago' => $referenciaPago,
    'folio_orden' => $folioOrden,
    'checkout_url' => $checkoutUrl,
    'provider_payload' => $providerPayload
  ]);

} catch (Throwable $e) {
  @$cx->rollback();
  ppgd_json([
    'ok' => false,
    'error' => $e->getMessage()
  ], 500);
}