<?php
/*
ez/pats/endpoints/public_checkout_generar_orden.php

PATS · Generar orden pública de checkout

VERSIÓN INTEGRADA CON solicitud_pats.php FINAL

Rutas relacionadas:
- ez/pats/solicitud_pats.php
- ez/pats/endpoints/public_contract_preview.php
- ez/pats/endpoints/public_pasaporte_vigente_validar.php
- ez/pats/lib/contratos.php
- ez/pats/templates/contrato_pats_base.php

Puntos integrados en este endpoint:
1. Token opcional:
   - Con token: resuelve actor comercial con public_checkout_resolver.php.
   - Sin token: venta directa ADMINPATS / corporativo.
   - Ya no bloquea por "Falta token público".

2. Nacionalidad:
   - Paciente mexicano: CURP obligatorio.
   - Paciente extranjero/no mexicano: CURP no aplica.
   - Extranjero/no mexicano: identificación/pasaporte frente obligatorio solo si firma por sí mismo.
   - No se exige INE ni CIF mexicana a extranjeros.

3. Menores:
   - Paciente real: menor.
   - Firmante/administrador: mamá, papá o tutor.
   - Menor mexicano: debe guardar doc_curp como CURP documental del menor/paciente.
   - Menor extranjero: no CURP; no identificación del menor; identificación la aporta tutor/responsable.
   - Los documentos del tutor se guardan separados como tutor_doc_*.
   - nombre_firmante del contrato = tutor/responsable.

4. Dependiente / paciente representado:
   - Paciente real = persona que usará PATS.
   - Responsable = persona que firma, administra usuario/contraseña y recibe accesos.
   - Dependiente mexicano: debe guardar doc_curp como CURP documental del paciente/dependiente.
   - Dependiente extranjero: no CURP; identificación la aporta el responsable.
   - nombre_firmante del contrato = responsable.

5. Adulto mayor:
   - Si es adulto mayor con firma propia, exige validación de 2 pasaportes vigentes.
   - Guarda:
     adulto_mayor_pasaportes_validados
     adulto_mayor_pasaporte_1_json
     adulto_mayor_pasaporte_2_json
   - Revalida contra BD cuando recibe id_pasaporte + fecha_nacimiento.

6. Documentos nuevos:
   - doc_identificacion_frente
   - doc_identificacion_reverso
   - doc_curp
   - doc_comprobante_domicilio
   - doc_constancia_fiscal
   - tutor_doc_identificacion_frente
   - tutor_doc_identificacion_reverso
   - tutor_doc_curp
   - tutor_doc_constancia_fiscal
   - doc_acreditacion_representacion
   - foto_base64 convertida a archivo físico

7. Contrato firmado:
   - Renderiza contrato completo con todos los campos recibidos.
   - Guarda HTML completo usando pats_save_signed_contract_record().
   - Guarda firma, IP y user agent.
   - El hash se calcula dentro de lib/contratos.php si esa función ya está implementada.

Notas para desarrollador:
- Este endpoint mantiene compatibilidad con la tabla pats_ordenes_pago existente.
- Los nuevos campos se guardan dentro de payload_confirmacion_json para no exigir cambios inmediatos de esquema.
- Si después se agregan columnas reales para tutor/documentos/nacionalidad, migrar desde payload_confirmacion_json.
- No capturar ni guardar número completo de tarjeta aquí.
- Este endpoint espera recibir stripe_payment_intent_id ya confirmado por Stripe.js.
- Aquí se revalida contra Stripe con STRIPE_SECRET_KEY antes de guardar orden/contrato/documentos.
*/

/*
|--------------------------------------------------------------------------
| Config Stripe
|--------------------------------------------------------------------------
| Este endpoint revalida el PaymentIntent después de que Stripe.js confirma
| el pago en solicitud_pats.php.
|
| public_stripe_intent.php usa este mismo config para crear el PaymentIntent.
| Aquí también debe cargarse para tener STRIPE_SECRET_KEY disponible.
|--------------------------------------------------------------------------
*/
$stripeConfig = __DIR__ . '/../config/config.php';
if (is_file($stripeConfig)) {
  require_once $stripeConfig;
}

/*
|--------------------------------------------------------------------------
| Conexión pública PATS
|--------------------------------------------------------------------------
| IMPORTANTE:
| Este endpoint es público. NO debe usar endpoints/bootstrap.php si ese bootstrap
| exige sesión interna de usuario, porque entonces rompe el guardado con 401:
| "Sesión no válida".
|
| La seguridad real de este endpoint se basa en:
| - POST
| - stripe_payment_intent_id confirmado
| - revalidación del PaymentIntent contra Stripe con STRIPE_SECRET_KEY
| - validaciones de negocio/documentos/firma antes de insertar
|--------------------------------------------------------------------------
*/
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

require_once __DIR__ . '/../../../varSQL/bd_pats.php';
require_once __DIR__ . '/../../../varSQL/var_pats.php';
require_once __DIR__ . '/../lib/contratos.php';

$cx = $conexionMod ?? $db_all2 ?? $db_all ?? null;

if (!$cx || !($cx instanceof mysqli)) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'ok' => false,
    'error' => 'No hay conexión mysqli disponible para PATS.'
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
$__mailConfig = __DIR__ . '/../config/mail.php';
if (is_file($__mailConfig)) {
  require_once $__mailConfig;
}

$__mailerLib = __DIR__ . '/../lib/pats_mailer.php';
if (is_file($__mailerLib)) {
  require_once $__mailerLib;
}

$__resolver = __DIR__ . '/../public_checkout_resolver.php';
if (is_file($__resolver)) {
  require_once $__resolver;
}

mysqli_report(MYSQLI_REPORT_OFF);

/* =========================================================
   HELPERS
========================================================= */
function ppg_json(array $payload, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function ppg_clean($v): string {
  return trim((string)($v ?? ''));
}

function ppg_upper($v): string {
  return strtoupper(ppg_clean($v));
}

function ppg_num($v): float {
  return round((float)($v ?? 0), 2);
}

function ppg_digits($v): string {
  return preg_replace('/\D+/', '', (string)($v ?? ''));
}

function ppg_valid_email(string $v): bool {
  return (bool)filter_var($v, FILTER_VALIDATE_EMAIL);
}

function ppg_build_ref(): string {
  return 'PATS-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(4)));
}

function ppg_build_folio(): string {
  return 'ORD-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

function ppg_get_ip(): string {
  return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function ppg_get_user_agent(): string {
  return (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
}

function ppg_freq_to_tipo_precio(string $frecuencia): int {
  return strtoupper(trim($frecuencia)) === 'MENSUAL' ? 2 : 1;
}

function ppg_nominal_y_recargo(string $frecuencia, float $monto): array {
  $freq = strtoupper(trim($frecuencia));
  $base = ($freq === 'MENSUAL') ? 800.00 : 9600.00;
  $nominal = min($monto, $base);
  $recargo = max(0, $monto - $base);
  return [$nominal, $recargo];
}

function ppg_calcular_edad(string $fechaNacimiento): int {
  $fechaNacimiento = ppg_clean($fechaNacimiento);
  if ($fechaNacimiento === '') return 0;

  try {
    $nac = new DateTime($fechaNacimiento);
    $hoy = new DateTime('today');
    return (int)$nac->diff($hoy)->y;
  } catch (Throwable $e) {
    return 0;
  }
}

function ppg_default_checkout_ctx(): array {
  return [
    'id_distribuidor' => 0,
    'id_franquicia'   => 0,
    'id_gestor'       => 0,
    'pais'            => 'México',
    'region'          => '',
    'zona'            => '',
    'unidad'          => '',
    'tipo_origen'     => 'ADMINPATS',
    'actor_tipo_publico' => 'ADMINPATS',
  ];
}

/*
|--------------------------------------------------------------------------
| Token opcional
|--------------------------------------------------------------------------
| - Si viene token_publico, se resuelve con pats_resolve_public_checkout_token().
| - Si NO viene token_publico, se permite continuar como ADMINPATS directo.
|--------------------------------------------------------------------------
*/
function ppg_resolve_checkout_ctx(mysqli $cx, string $token): array {
  $ctx = ppg_default_checkout_ctx();
  $token = ppg_clean($token);

  if ($token === '') {
    return $ctx;
  }

  if (!function_exists('pats_resolve_public_checkout_token')) {
    throw new RuntimeException('No está disponible public_checkout_resolver.php para resolver el token.');
  }

  $resolved = pats_resolve_public_checkout_token($cx, $token);
  if (!$resolved || !is_array($resolved)) {
    throw new RuntimeException('Token público no válido o inactivo.');
  }

  $ctx = array_merge($ctx, $resolved);

  if (empty($ctx['actor_tipo_publico'])) {
    if (!empty($ctx['id_distribuidor'])) {
      $ctx['actor_tipo_publico'] = 'DISTRIBUIDOR';
    } elseif (!empty($ctx['id_gestor'])) {
      $ctx['actor_tipo_publico'] = 'GESTOR';
    } elseif (!empty($ctx['id_franquicia'])) {
      $ctx['actor_tipo_publico'] = 'FRANQUICIA';
    } else {
      $ctx['actor_tipo_publico'] = 'ADMINPATS';
    }
  }

  if (empty($ctx['tipo_origen'])) {
    $ctx['tipo_origen'] = $ctx['actor_tipo_publico'];
  }

  return $ctx;
}

/* ---------------------------------------------------------
   STRIPE · VERIFICACIÓN REAL DEL PAGO
--------------------------------------------------------- */

/*
  IMPORTANTE:
  solicitud_pats.php debe confirmar el pago con Stripe.js y mandar:
    stripe_payment_intent_id

  Este endpoint NO crea un checkout nuevo.
  Este endpoint revalida el PaymentIntent contra Stripe antes de guardar.

  Debe existir STRIPE_SECRET_KEY definido en alguno de estos lugares:
  - bootstrap.php
  - config/config.php incluido desde bootstrap.php
  - variable de entorno STRIPE_SECRET_KEY
*/
function ppg_get_stripe_secret(): string {
  if (defined('STRIPE_SECRET_KEY')) {
    return trim((string)STRIPE_SECRET_KEY);
  }

  $env = getenv('STRIPE_SECRET_KEY');
  if ($env !== false && trim((string)$env) !== '') {
    return trim((string)$env);
  }

  return '';
}

function ppg_curl_stripe_get(string $url, string $secret): array {
  if (!function_exists('curl_init')) {
    throw new RuntimeException('cURL no está disponible para validar Stripe.');
  }

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD        => $secret . ':',
    CURLOPT_TIMEOUT        => 25,
  ]);

  $body = curl_exec($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  if ($body === false || $err !== '') {
    throw new RuntimeException('No fue posible contactar Stripe: ' . $err);
  }

  $json = json_decode((string)$body, true);
  if (!is_array($json)) {
    throw new RuntimeException('Stripe no devolvió JSON válido.');
  }

  if ($http < 200 || $http >= 300) {
    $msg = $json['error']['message'] ?? ('HTTP ' . $http);
    throw new RuntimeException('Stripe: ' . $msg);
  }

  return $json;
}

function ppg_verify_stripe_payment_intent(string $paymentIntentId, float $montoOrden, string $moneda): array {
  $paymentIntentId = ppg_clean($paymentIntentId);

  if ($paymentIntentId === '') {
    throw new RuntimeException('No se recibió el ID del pago de Stripe.');
  }

  if (!preg_match('/^pi_[A-Za-z0-9_]+$/', $paymentIntentId)) {
    throw new RuntimeException('El ID del pago de Stripe no tiene formato válido.');
  }

  $secret = ppg_get_stripe_secret();

  if ($secret === '' || str_starts_with($secret, 'sk_live_XXXX') || str_starts_with($secret, 'sk_test_XXXX')) {
    throw new RuntimeException('Stripe no está configurado. Falta STRIPE_SECRET_KEY válido.');
  }

  $intent = ppg_curl_stripe_get(
    'https://api.stripe.com/v1/payment_intents/' . rawurlencode($paymentIntentId),
    $secret
  );

  if (($intent['status'] ?? '') !== 'succeeded') {
    throw new RuntimeException('El pago no está confirmado en Stripe. Estado actual: ' . (string)($intent['status'] ?? 'desconocido'));
  }

  $amountPaid = round(((float)($intent['amount_received'] ?? $intent['amount'] ?? 0)) / 100, 2);
  $currency = strtoupper((string)($intent['currency'] ?? ''));
  $expectedCurrency = strtoupper(trim($moneda ?: 'MXN'));

  if ($currency !== strtolower($expectedCurrency) && $currency !== $expectedCurrency) {
    throw new RuntimeException('La moneda del pago en Stripe no coincide con la orden.');
  }

  if (abs($amountPaid - $montoOrden) > 1.00) {
    throw new RuntimeException('El monto pagado en Stripe (' . number_format($amountPaid, 2, '.', '') . ') no coincide con la orden (' . number_format($montoOrden, 2, '.', '') . ').');
  }

  $chargeId = '';
  if (!empty($intent['latest_charge']) && is_string($intent['latest_charge'])) {
    $chargeId = $intent['latest_charge'];
  } elseif (!empty($intent['charges']['data'][0]['id'])) {
    $chargeId = (string)$intent['charges']['data'][0]['id'];
  }

  return [
    'id' => (string)($intent['id'] ?? $paymentIntentId),
    'status' => (string)($intent['status'] ?? ''),
    'amount_paid' => $amountPaid,
    'currency' => $currency,
    'latest_charge' => $chargeId,
    'raw' => $intent,
  ];
}

/*
|--------------------------------------------------------------------------
| Idempotencia por PaymentIntent
|--------------------------------------------------------------------------
| Si Stripe ya fue confirmado y este endpoint se reintenta, no debe duplicar orden.
|--------------------------------------------------------------------------
*/
function ppg_find_existing_order_by_payment_intent(mysqli $cx, string $paymentIntentId): ?array {
  $paymentIntentId = ppg_clean($paymentIntentId);
  if ($paymentIntentId === '') return null;

  $stmt = $cx->prepare("
    SELECT
      id_orden,
      referencia_pago,
      folio_orden,
      estatus_orden,
      estatus_pago,
      id_pasaporte_generado,
      payload_confirmacion_json
    FROM pats_ordenes_pago
    WHERE payment_intent_id = ?
       OR transaccion_id_externa = ?
       OR referencia_externa = ?
    ORDER BY id_orden DESC
    LIMIT 1
  ");

  if (!$stmt) {
    return null;
  }

  $stmt->bind_param('sss', $paymentIntentId, $paymentIntentId, $paymentIntentId);
  $stmt->execute();
  $rs = $stmt->get_result();
  $row = $rs ? $rs->fetch_assoc() : null;
  $stmt->close();

  return $row ?: null;
}

/*
|--------------------------------------------------------------------------
| Guardar upload
|--------------------------------------------------------------------------
| Devuelve metadatos con ruta absoluta y relativa.
|--------------------------------------------------------------------------
*/
function ppg_store_upload(?array $file, string $prefix): ?array {
  if (!$file || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
    return null;
  }

  $allowed = ['pdf', 'png', 'jpg', 'jpeg', 'webp'];
  $orig = (string)($file['name'] ?? 'archivo');
  $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
  $safeExt = preg_replace('/[^a-z0-9]/i', '', $ext);
  if ($safeExt === '') $safeExt = 'bin';

  if (!in_array($safeExt, $allowed, true)) {
    throw new RuntimeException('Formato no permitido para archivo: ' . $orig);
  }

  $relDir = 'uploads/public_checkout/' . date('Y/m');
  $baseDir = dirname(__DIR__) . '/' . $relDir;

  if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
    throw new RuntimeException('No fue posible crear directorio de uploads');
  }

  $filename = $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $safeExt;
  $target = $baseDir . '/' . $filename;
  $relPath = $relDir . '/' . $filename;

  if (!move_uploaded_file($file['tmp_name'], $target)) {
    throw new RuntimeException('No fue posible mover archivo: ' . $orig);
  }

  return [
    'path' => $target,
    'relative_path' => $relPath,
    'name' => $orig,
    'size' => (int)($file['size'] ?? 0),
    'type' => (string)($file['type'] ?? ''),
    'extension' => $safeExt,
  ];
}

/*
|--------------------------------------------------------------------------
| Guardar imagen base64
|--------------------------------------------------------------------------
| Se usa para foto_base64. La firma se guarda en contratos.php.
|--------------------------------------------------------------------------
*/
function ppg_store_base64_image(string $dataUrl, string $prefix): ?array {
  $dataUrl = trim($dataUrl);
  if ($dataUrl === '') return null;

  if (!preg_match('#^data:image/(png|jpeg|jpg|webp);base64,#i', $dataUrl, $m)) {
    throw new RuntimeException('La fotografía no tiene un formato válido.');
  }

  $ext = strtolower($m[1]);
  if ($ext === 'jpeg') $ext = 'jpg';

  $base64 = preg_replace('#^data:image/(png|jpeg|jpg|webp);base64,#i', '', $dataUrl);
  $bin = base64_decode($base64, true);

  if ($bin === false || strlen($bin) < 100) {
    throw new RuntimeException('La fotografía no pudo procesarse correctamente.');
  }

  $relDir = 'uploads/public_checkout/' . date('Y/m');
  $baseDir = dirname(__DIR__) . '/' . $relDir;

  if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
    throw new RuntimeException('No fue posible crear directorio de uploads');
  }

  $filename = $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
  $target = $baseDir . '/' . $filename;
  $relPath = $relDir . '/' . $filename;

  if (file_put_contents($target, $bin) === false) {
    throw new RuntimeException('No fue posible guardar la fotografía.');
  }

  return [
    'path' => $target,
    'relative_path' => $relPath,
    'name' => $filename,
    'size' => strlen($bin),
    'type' => 'image/' . $ext,
    'extension' => $ext,
  ];
}

function ppg_file_present(string $key): bool {
  // Revisa primero si hay un pre-upload en sesión para este campo
  $csrf = (string)($_SESSION['_csrf_pats_publico'] ?? '');
  if ($csrf !== '') {
    $meta = $_SESSION['pats_preloads'][$csrf][$key] ?? null;
    if (is_array($meta) && !empty($meta['relative_path'])) {
      $abs = dirname(__DIR__) . '/' . $meta['relative_path'];
      if (file_exists($abs)) {
        return true;
      }
    }
  }
  // Fallback: archivo enviado en esta petición
  return isset($_FILES[$key]) && !empty($_FILES[$key]['tmp_name']) && is_uploaded_file($_FILES[$key]['tmp_name']);
}

/*
|--------------------------------------------------------------------------
| Validación dinámica de pasaporte vigente
|--------------------------------------------------------------------------
| Intenta adaptarse a columnas existentes de pats_pasaportes:
| - Fecha vigencia: fecha_vencimiento_real, fecha_vencimiento, vigencia, fecha_vigencia.
| - Estado: activo, estatus.
|--------------------------------------------------------------------------
*/
function ppg_get_table_columns(mysqli $cx, string $table): array {
  $cols = [];
  $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
  $rs = $cx->query("SHOW COLUMNS FROM {$safe}");
  if ($rs instanceof mysqli_result) {
    while ($row = $rs->fetch_assoc()) {
      $cols[strtolower((string)$row['Field'])] = (string)$row['Field'];
    }
    $rs->free();
  }
  return $cols;
}

function ppg_validate_pasaporte_vigente(mysqli $cx, string $idPasaporte, string $fechaNacimiento): ?array {
  $idPasaporte = ppg_digits($idPasaporte);
  $fechaNacimiento = ppg_clean($fechaNacimiento);

  if ($idPasaporte === '' || $fechaNacimiento === '') {
    return null;
  }

  $cols = ppg_get_table_columns($cx, 'pats_pasaportes');
  if (empty($cols['id_pasaporte'])) {
    return null;
  }

  $dateCol = '';
  foreach (['fecha_vencimiento_real', 'fecha_vencimiento', 'vigencia', 'fecha_vigencia'] as $c) {
    if (!empty($cols[$c])) {
      $dateCol = $cols[$c];
      break;
    }
  }

  $where = ["id_pasaporte = ?"];
  $types = 's';
  $vals = [$idPasaporte];

  if (!empty($cols['fecha_nacimiento'])) {
    $where[] = "fecha_nacimiento = ?";
    $types .= 's';
    $vals[] = $fechaNacimiento;
  }

  if (!empty($cols['activo'])) {
    $where[] = "activo = 1";
  }

  if (!empty($cols['estatus'])) {
    $where[] = "(UPPER(estatus) IN ('ACTIVO','VIGENTE') OR estatus = '1')";
  }

  if ($dateCol !== '') {
    $where[] = "({$dateCol} IS NULL OR {$dateCol} = '0000-00-00' OR {$dateCol} >= CURDATE())";
  }

  $sql = "SELECT * FROM pats_pasaportes WHERE " . implode(' AND ', $where) . " LIMIT 1";
  $stmt = $cx->prepare($sql);
  if (!$stmt) {
    throw new RuntimeException('No fue posible preparar validación de pasaporte vigente: ' . $cx->error);
  }

  $stmt->bind_param($types, ...$vals);
  $stmt->execute();
  $rs = $stmt->get_result();
  $row = $rs ? $rs->fetch_assoc() : null;
  $stmt->close();

  return $row ?: null;
}

/* =========================================================
   VALIDACIÓN DE MÉTODO
========================================================= */
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  ppg_json([
    'ok' => false,
    'error' => 'Método no permitido'
  ], 405);
}

/* =========================================================
   ENTRADAS BASE
========================================================= */
$tokenPublico = ppg_clean($_POST['token_publico'] ?? '');

try {
  $ctx = ppg_resolve_checkout_ctx($cx, $tokenPublico);
} catch (Throwable $e) {
  ppg_json(['ok' => false, 'error' => $e->getMessage()], 422);
}

/* Contacto de acceso */
$correoAcceso = ppg_clean($_POST['correo_usuario_pats'] ?? '');
$telefonoAcceso = ppg_digits($_POST['telefono_usuario'] ?? '');

/* Datos del paciente real */
$nombre = ppg_clean($_POST['nombre_usuario'] ?? '');
$apellidoPa = ppg_clean($_POST['apellido_pa'] ?? '');
$apellidoMa = ppg_clean($_POST['apellido_ma'] ?? '');
$curp = ppg_upper($_POST['curp_usuario'] ?? '');
$fechaNacimiento = ppg_clean($_POST['fecha_nacimiento'] ?? '');

$tipoCliente = ppg_clean($_POST['tipo_cliente'] ?? 'privado');
$nombreEmpresa = ppg_clean($_POST['nombre_empresa'] ?? '');

/* Nacionalidad / documento paciente */
$nacionalidadTipo = ppg_upper($_POST['nacionalidad_tipo'] ?? 'MEXICANA');
if (!in_array($nacionalidadTipo, ['MEXICANA', 'EXTRANJERA'], true)) {
  $nacionalidadTipo = 'MEXICANA';
}
$pacienteMexicano = ($nacionalidadTipo === 'MEXICANA');

$nacionalidad = ppg_clean($_POST['nacionalidad'] ?? ($pacienteMexicano ? 'mexicana' : ''));
$paisNacimiento = ppg_clean($_POST['pais_nacimiento'] ?? ($pacienteMexicano ? 'México' : ''));
$rfcUsuario = ppg_upper($_POST['rfc_usuario'] ?? '');
$actividadOcupacion = ppg_clean($_POST['actividad_ocupacion'] ?? '');
$estadoCivil = ppg_clean($_POST['estado_civil'] ?? '');
$tipoDocumentoIdentidad = ppg_upper($_POST['tipo_documento_identidad'] ?? '');
$paisDocumentoIdentidad = ppg_clean($_POST['pais_documento_identidad'] ?? '');
$numeroDocumentoIdentidad = ppg_upper($_POST['numero_documento_identidad'] ?? '');

/* Domicilio */
$domCalle = ppg_clean($_POST['dom_calle'] ?? '');
$domNumExt = ppg_clean($_POST['dom_num_ext'] ?? '');
$domNumInt = ppg_clean($_POST['dom_num_int'] ?? '');
$domColonia = ppg_clean($_POST['dom_colonia'] ?? '');
$domCp = ppg_digits($_POST['dom_cp'] ?? '');
$domMunicipio = ppg_clean($_POST['dom_municipio'] ?? '');
$domEstado = ppg_clean($_POST['dom_estado'] ?? '');
$domEstadoAcronimo = ppg_upper($_POST['dom_estado_acronimo'] ?? '');
$domPais = ppg_clean($_POST['dom_pais'] ?? 'México');

/* Firma / foto */
$firmaBase64 = ppg_clean($_POST['firma_base64'] ?? '');
$fotoBase64 = ppg_clean($_POST['foto_base64'] ?? '');
$aceptaContrato = isset($_POST['acepta_contrato']) ? 1 : 0;
$stripePaymentIntentId = ppg_clean($_POST['stripe_payment_intent_id'] ?? '');

/* Pago */
$frecuencia = ppg_upper($_POST['frecuencia'] ?? 'MENSUAL');
if ($frecuencia === '') $frecuencia = 'MENSUAL';

$moneda = ppg_upper($_POST['moneda'] ?? 'MXN');
if ($moneda === '') $moneda = 'MXN';

$montoOrden = ppg_num($_POST['monto_orden'] ?? 800);
if ($montoOrden <= 0) {
  $montoOrden = ($frecuencia === 'MENSUAL') ? 800.00 : 9600.00;
}

[$montoNominalBase, $montoExtraRecargo] = ppg_nominal_y_recargo($frecuencia, $montoOrden);
$idTipoPrecio = ppg_freq_to_tipo_precio($frecuencia);

/* Stripe confirmado desde frontend */
if ($stripePaymentIntentId === '') {
  ppg_json(['ok' => false, 'error' => 'No se recibió el ID del pago de Stripe.'], 422);
}


/* Representación */
$edad = ppg_calcular_edad($fechaNacimiento);
$tipoPacientePost = ppg_upper($_POST['tipo_paciente'] ?? '');
$modoFirma = ppg_upper($_POST['modo_firma'] ?? 'FIRMA_PROPIA');
if (!in_array($modoFirma, ['FIRMA_PROPIA', 'TUTOR_FAMILIAR', 'RESPONSABLE_AUTORIZADO'], true)) {
  $modoFirma = 'FIRMA_PROPIA';
}

$esMenor = ($edad > 0 && $edad < 18);
$esAdultoMayor = ($edad >= 65);
$requiereResponsable = ($esMenor || $modoFirma === 'TUTOR_FAMILIAR' || $modoFirma === 'RESPONSABLE_AUTORIZADO');

if ($esMenor) {
  $modoFirma = 'TUTOR_FAMILIAR';
}

$esDependienteRepresentado = (!$esMenor && $requiereResponsable);

$tipoPaciente = $esDependienteRepresentado
  ? 'DEPENDIENTE_CON_RESPONSABLE'
  : ($esMenor ? 'MENOR' : ($esAdultoMayor ? 'ADULTO_MAYOR' : 'ADULTO'));

$tipoRepresentacion = ppg_upper($_POST['tipo_representacion'] ?? $modoFirma);
$relacionResponsable = ppg_clean($_POST['relacion_responsable_paciente'] ?? '');
$motivoResponsable = ppg_clean($_POST['motivo_responsable'] ?? '');

/* Tutor / responsable */
$tutorNombre = ppg_clean($_POST['tutor_nombre'] ?? '');
$tutorApellidoPa = ppg_clean($_POST['tutor_apellido_pa'] ?? '');
$tutorApellidoMa = ppg_clean($_POST['tutor_apellido_ma'] ?? '');
$tutorCurp = ppg_upper($_POST['tutor_curp'] ?? '');
$tutorRfc = ppg_upper($_POST['tutor_rfc'] ?? '');
$tutorFechaNacimiento = ppg_clean($_POST['tutor_fecha_nacimiento'] ?? '');
$tutorCorreo = ppg_clean($_POST['tutor_correo'] ?? '');
$tutorTelefono = ppg_digits($_POST['tutor_telefono'] ?? '');
$tutorNacionalidadTipo = ppg_upper($_POST['tutor_nacionalidad_tipo'] ?? 'MEXICANA');
if (!in_array($tutorNacionalidadTipo, ['MEXICANA', 'EXTRANJERA'], true)) {
  $tutorNacionalidadTipo = 'MEXICANA';
}
if ($esMenor && !$pacienteMexicano) {
  /*
    Mismo criterio que frontend final:
    menor extranjero fuerza responsable extranjero para no pedir INE/CURP/CIF mexicana.
  */
  $tutorNacionalidadTipo = 'EXTRANJERA';
}
$tutorMexicano = ($tutorNacionalidadTipo === 'MEXICANA');

$tutorNacionalidad = ppg_clean($_POST['tutor_nacionalidad'] ?? ($tutorMexicano ? 'mexicana' : ''));
$tutorPaisNacimiento = ppg_clean($_POST['tutor_pais_nacimiento'] ?? ($tutorMexicano ? 'México' : ''));
$tutorTipoDocumentoIdentidad = ppg_upper($_POST['tutor_tipo_documento_identidad'] ?? '');
$tutorPaisDocumentoIdentidad = ppg_clean($_POST['tutor_pais_documento_identidad'] ?? '');
$tutorNumeroDocumentoIdentidad = ppg_upper($_POST['tutor_numero_documento_identidad'] ?? '');

/* Adulto mayor */
$adultoMayorPasaportesValidados = ppg_clean($_POST['adulto_mayor_pasaportes_validados'] ?? '0');
$adultoMayorPasaporte1Json = ppg_clean($_POST['adulto_mayor_pasaporte_1_json'] ?? '');
$adultoMayorPasaporte2Json = ppg_clean($_POST['adulto_mayor_pasaporte_2_json'] ?? '');

$am1IdPasaporte = ppg_digits($_POST['am1_id_pasaporte'] ?? '');
$am1FechaNacimiento = ppg_clean($_POST['am1_fecha_nacimiento'] ?? '');
$am2IdPasaporte = ppg_digits($_POST['am2_id_pasaporte'] ?? '');
$am2FechaNacimiento = ppg_clean($_POST['am2_fecha_nacimiento'] ?? '');

/* =========================================================
   ORIGEN REAL DESDE TOKEN / ADMINPATS DIRECTO
========================================================= */
$actorTipoPublico = ppg_upper($ctx['actor_tipo_publico'] ?? $ctx['tipo_origen'] ?? 'ADMINPATS');
if (!in_array($actorTipoPublico, ['DISTRIBUIDOR', 'GESTOR', 'FRANQUICIA', 'ADMINPATS'], true)) {
  $actorTipoPublico = 'ADMINPATS';
}

$tipoOrigen = ppg_upper($ctx['tipo_origen'] ?? $actorTipoPublico);
if (!in_array($tipoOrigen, ['DISTRIBUIDOR', 'GESTOR', 'FRANQUICIA', 'ADMINPATS'], true)) {
  $tipoOrigen = $actorTipoPublico;
}

$origenCheckout = ppg_clean($_POST['origen_checkout'] ?? 'PORTAL_PUBLICO');

$idDistribuidor = (int)($ctx['id_distribuidor'] ?? 0);
$idGestor = (int)($ctx['id_gestor'] ?? 0);
$idFranquicia = (int)($ctx['id_franquicia'] ?? 0);

$pais = ppg_clean($ctx['pais'] ?? 'México');
$region = ppg_clean($ctx['region'] ?? '');
$zona = ppg_clean($ctx['zona'] ?? '');
$unidad = ppg_clean($ctx['unidad'] ?? '');

/* =========================================================
   VALIDACIÓN DE NEGOCIO FINAL
========================================================= */
if (!ppg_valid_email($correoAcceso)) {
  ppg_json(['ok' => false, 'error' => 'Correo inválido'], 422);
}
if (strlen($telefonoAcceso) !== 10) {
  ppg_json(['ok' => false, 'error' => 'Teléfono inválido'], 422);
}
if ($nombre === '' || $apellidoPa === '') {
  ppg_json(['ok' => false, 'error' => 'Faltan datos personales obligatorios'], 422);
}
if ($fechaNacimiento === '') {
  ppg_json(['ok' => false, 'error' => 'Falta fecha de nacimiento'], 422);
}
if ($pacienteMexicano && $curp === '') {
  ppg_json(['ok' => false, 'error' => 'Falta CURP del paciente'], 422);
}
if (!$pacienteMexicano && !$requiereResponsable && $numeroDocumentoIdentidad === '') {
  ppg_json(['ok' => false, 'error' => 'Falta número de identificación o pasaporte del paciente'], 422);
}
if ($domCalle === '' || $domNumExt === '' || $domColonia === '' || $domCp === '' || $domMunicipio === '' || $domEstado === '' || $domPais === '') {
  ppg_json(['ok' => false, 'error' => 'Faltan datos del domicilio'], 422);
}
if ($aceptaContrato !== 1) {
  ppg_json(['ok' => false, 'error' => 'Debes aceptar el contrato'], 422);
}
if ($firmaBase64 === '') {
  ppg_json(['ok' => false, 'error' => 'Debes firmar el contrato'], 422);
}
if ($fotoBase64 === '') {
  ppg_json(['ok' => false, 'error' => 'Falta fotografía del paciente'], 422);
}

/* Validación de responsable/tutor */
if ($requiereResponsable) {
  if ($tutorNombre === '' || $tutorApellidoPa === '') {
    ppg_json(['ok' => false, 'error' => 'Faltan datos de la persona responsable'], 422);
  }
  if ($tutorFechaNacimiento === '') {
    ppg_json(['ok' => false, 'error' => 'Falta fecha de nacimiento de la persona responsable'], 422);
  }
  if (!ppg_valid_email($tutorCorreo)) {
    ppg_json(['ok' => false, 'error' => 'Correo inválido de la persona responsable'], 422);
  }
  if (strlen($tutorTelefono) !== 10) {
    ppg_json(['ok' => false, 'error' => 'Teléfono inválido de la persona responsable'], 422);
  }
  if ($relacionResponsable === '') {
    ppg_json(['ok' => false, 'error' => 'Falta relación de la persona responsable con el paciente'], 422);
  }
  if ($esDependienteRepresentado && $motivoResponsable === '') {
    ppg_json(['ok' => false, 'error' => 'Falta motivo por el que firma la persona responsable'], 422);
  }

  if ($tutorMexicano && $tutorCurp === '') {
    ppg_json(['ok' => false, 'error' => 'Falta CURP de la persona responsable'], 422);
  }
  if (!$tutorMexicano && $tutorNumeroDocumentoIdentidad === '') {
    ppg_json(['ok' => false, 'error' => 'Falta identificación o pasaporte de la persona responsable'], 422);
  }
}

/* Validación documentos paciente */
if (!ppg_file_present('doc_identificacion_frente')) {
  ppg_json(['ok' => false, 'error' => 'Debes cargar la identificación frente del paciente'], 422);
}

/* Validación documentos responsable */
if ($requiereResponsable) {
  if (!ppg_file_present('tutor_doc_identificacion_frente')) {
    ppg_json(['ok' => false, 'error' => 'Debes cargar identificación o pasaporte frente de la persona responsable'], 422);
  }
  if ($tutorMexicano && !ppg_file_present('tutor_doc_identificacion_reverso')) {
    ppg_json(['ok' => false, 'error' => 'Debes cargar identificación reverso de la persona responsable'], 422);
  }
  if ($tutorMexicano && !ppg_file_present('tutor_doc_curp')) {
    ppg_json(['ok' => false, 'error' => 'Debes cargar CURP documental de la persona responsable'], 422);
  }
}

/* Validación adulto mayor */
if ($esAdultoMayor && !$esDependienteRepresentado) {
  if ($am1IdPasaporte === '' || $am1FechaNacimiento === '' || $am2IdPasaporte === '' || $am2FechaNacimiento === '') {
    ppg_json(['ok' => false, 'error' => 'Debes validar 2 pasaportes vigentes para adulto mayor'], 422);
  }
  if ($am1IdPasaporte === $am2IdPasaporte) {
    ppg_json(['ok' => false, 'error' => 'Los dos pasaportes vigentes deben ser diferentes'], 422);
  }

  $am1 = ppg_validate_pasaporte_vigente($cx, $am1IdPasaporte, $am1FechaNacimiento);
  $am2 = ppg_validate_pasaporte_vigente($cx, $am2IdPasaporte, $am2FechaNacimiento);

  if (!$am1 || !$am2) {
    ppg_json(['ok' => false, 'error' => 'No fue posible confirmar los 2 pasaportes vigentes del adulto mayor'], 422);
  }

  $adultoMayorPasaportesValidados = '1';
  $adultoMayorPasaporte1Json = json_encode($am1, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $adultoMayorPasaporte2Json = json_encode($am2, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/* =========================================================
   RESPALDO PRE-PAGO
   Guardar respaldo ANTES de verificar el pago con Stripe.
   Si la transacción de BD falla después del cobro, este
   registro permite recuperar los datos del afiliado.
========================================================= */
$referenciaPago = ppg_build_ref();
$folioOrden     = ppg_build_folio();
$correoOrden    = ($requiereResponsable && $tutorCorreo !== '') ? $tutorCorreo : $correoAcceso;
$telefonoOrden  = ($requiereResponsable && $tutorTelefono !== '') ? $tutorTelefono : $telefonoAcceso;
$curpOrden      = $pacienteMexicano && $curp !== '' ? $curp : 'NO_APLICA';
$fullName       = trim($nombre . ' ' . $apellidoPa . ' ' . $apellidoMa);
$tutorFullName  = trim($tutorNombre . ' ' . $tutorApellidoPa . ' ' . $tutorApellidoMa);
$nombreFirmante = $requiereResponsable ? $tutorFullName : $fullName;

$idRespaldo = 0;
try {
  $idRespaldo = ppg_insertar_respaldo($cx, [
    'stripe_payment_intent_id' => $stripePaymentIntentId,
    'referencia_pago'          => $referenciaPago,
    'folio_orden'              => $folioOrden,
    'id_franquicia'            => $idFranquicia,
    'id_distribuidor'          => $idDistribuidor,
    'id_tipo_precio'           => $idTipoPrecio,
    'curp'                     => $curpOrden,
    'nombres'                  => $nombre,
    'apellido_pa'              => $apellidoPa,
    'apellido_ma'              => $apellidoMa,
    'fecha_nacimiento'         => $fechaNacimiento,
    'telefono'                 => $telefonoOrden,
    'correo'                   => $correoOrden,
    'frecuencia_pago'          => $frecuencia,
    'monto_orden'              => $montoOrden,
    'moneda'                   => $moneda,
    'pais'                     => $pais,
    'region'                   => $region,
    'zona'                     => $zona,
    'unidad'                   => $unidad,
    'tipo_cliente'             => $tipoCliente,
    'tipo_origen'              => $tipoOrigen,
    'actor_tipo_publico'       => $actorTipoPublico,
    'tipo_paciente'            => $tipoPaciente,
    'modo_firma'               => $modoFirma,
    'nacionalidad_tipo'        => $nacionalidadTipo,
    'nombre_firmante'          => $nombreFirmante,
    'payload_post'             => $_POST,
  ]);
} catch (Throwable $errRespaldo) {
  error_log('PATS respaldo error: ' . $errRespaldo->getMessage());
  /* El respaldo falla silenciosamente para no bloquear el alta */
}

/* =========================================================
   STRIPE · VALIDACIÓN REAL E IDEMPOTENCIA
========================================================= */
try {
  $stripePago = ppg_verify_stripe_payment_intent($stripePaymentIntentId, $montoOrden, $moneda);
} catch (Throwable $e) {
  ppg_json(['ok' => false, 'error' => $e->getMessage()], 422);
}

$ordenExistente = ppg_find_existing_order_by_payment_intent($cx, $stripePaymentIntentId);
if ($ordenExistente) {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
  $endpointsBase = rtrim($scheme . '://' . $host . $scriptDir, '/');
  $patsBase = preg_replace('#/endpoints$#', '', $endpointsBase);
  $refExistente = (string)($ordenExistente['referencia_pago'] ?? '');
  $checkoutUrlExistente = $patsBase . '/pago_resultado.php?ref=' . urlencode($refExistente) . '&status=CONFIRMADO';

  ppg_json([
    'ok' => true,
    'duplicado' => true,
    'id_orden' => (int)($ordenExistente['id_orden'] ?? 0),
    'referencia_pago' => $refExistente,
    'folio_orden' => (string)($ordenExistente['folio_orden'] ?? ''),
    'checkout_url' => $checkoutUrlExistente,
    'message' => 'El pago ya había sido registrado. No se duplicó la orden.',
  ]);
}

/* =========================================================
   PREPARACIÓN
========================================================= */
$referenciaPago = ppg_build_ref();
$folioOrden = ppg_build_folio();
$proveedorPasarela = 'STRIPE';
$stripeChargeId = (string)($stripePago['latest_charge'] ?? '');

$fullName = trim($nombre . ' ' . $apellidoPa . ' ' . $apellidoMa);
$tutorFullName = trim($tutorNombre . ' ' . $tutorApellidoPa . ' ' . $tutorApellidoMa);
$nombreFirmante = $requiereResponsable ? $tutorFullName : $fullName;

/*
  Si el paciente requiere responsable, el correo/teléfono administrativo debe ser el del responsable.
  El paciente real se conserva en datos del paciente.
*/
$correoOrden = $requiereResponsable && $tutorCorreo !== '' ? $tutorCorreo : $correoAcceso;
$telefonoOrden = $requiereResponsable && $tutorTelefono !== '' ? $tutorTelefono : $telefonoAcceso;

/*
  Compatibilidad con columna curp_usuario:
  - Si paciente mexicano: se guarda CURP real.
  - Si extranjero: se guarda NO_APLICA para evitar romper columnas NOT NULL.
*/
$curpOrden = $pacienteMexicano && $curp !== '' ? $curp : 'NO_APLICA';

/* Payload completo para contrato */
$payloadContrato = [
  'nombre_usuario' => $nombre,
  'apellido_pa' => $apellidoPa,
  'apellido_ma' => $apellidoMa,
  'fecha_nacimiento' => $fechaNacimiento,
  'curp_usuario' => $curp,
  'correo_usuario_pats' => $correoOrden,
  'telefono_usuario' => $telefonoOrden,
  'rfc_usuario' => $rfcUsuario,

  'nacionalidad_tipo' => $nacionalidadTipo,
  'nacionalidad' => $nacionalidad,
  'pais_nacimiento' => $paisNacimiento,
  'actividad_ocupacion' => $actividadOcupacion,
  'estado_civil' => $estadoCivil,
  'tipo_documento_identidad' => $tipoDocumentoIdentidad,
  'pais_documento_identidad' => $paisDocumentoIdentidad,
  'numero_documento_identidad' => $numeroDocumentoIdentidad,

  'dom_calle' => $domCalle,
  'dom_num_ext' => $domNumExt,
  'dom_num_int' => $domNumInt,
  'dom_colonia' => $domColonia,
  'dom_cp' => $domCp,
  'dom_municipio' => $domMunicipio,
  'dom_estado' => $domEstado,
  'dom_pais' => $domPais,

  'firma_base64' => $firmaBase64,
  'frecuencia' => $frecuencia,
  'monto_orden' => (string)$montoOrden,
  'moneda' => $moneda,

  'token_publico' => $tokenPublico,
  'id_distribuidor' => $idDistribuidor,
  'id_franquicia' => $idFranquicia,
  'id_gestor' => $idGestor,
  'tipo_origen' => $tipoOrigen,
  'pais' => $pais,
  'region' => $region,
  'zona' => $zona,
  'unidad' => $unidad,

  'tipo_paciente' => $tipoPaciente,
  'es_menor' => $esMenor ? '1' : '0',
  'es_adulto_mayor' => $esAdultoMayor ? '1' : '0',
  'edad_paciente' => (string)$edad,

  'modo_firma' => $modoFirma,
  'requiere_responsable' => $requiereResponsable ? '1' : '0',
  'tipo_representacion' => $tipoRepresentacion,
  'relacion_responsable_paciente' => $relacionResponsable,
  'motivo_responsable' => $motivoResponsable,

  'paciente_nombre_completo' => $fullName,
  'paciente_curp' => $curp,
  'paciente_fecha_nacimiento' => $fechaNacimiento,

  'tutor_nombre' => $tutorNombre,
  'tutor_apellido_pa' => $tutorApellidoPa,
  'tutor_apellido_ma' => $tutorApellidoMa,
  'tutor_nombre_completo' => $tutorFullName,
  'tutor_curp' => $tutorCurp,
  'tutor_rfc' => $tutorRfc,
  'tutor_fecha_nacimiento' => $tutorFechaNacimiento,
  'tutor_correo' => $tutorCorreo,
  'tutor_telefono' => $tutorTelefono,
  'tutor_nacionalidad_tipo' => $tutorNacionalidadTipo,
  'tutor_nacionalidad' => $tutorNacionalidad,
  'tutor_pais_nacimiento' => $tutorPaisNacimiento,
  'tutor_tipo_documento_identidad' => $tutorTipoDocumentoIdentidad,
  'tutor_pais_documento_identidad' => $tutorPaisDocumentoIdentidad,
  'tutor_numero_documento_identidad' => $tutorNumeroDocumentoIdentidad,

  'adulto_mayor_pasaportes_validados' => $adultoMayorPasaportesValidados,
  'adulto_mayor_pasaporte_1_json' => $adultoMayorPasaporte1Json,
  'adulto_mayor_pasaporte_2_json' => $adultoMayorPasaporte2Json,
  'adulto_mayor_id_pasaporte_1' => $am1IdPasaporte,
  'adulto_mayor_id_pasaporte_2' => $am2IdPasaporte,
  'adulto_mayor_fecha_nacimiento_1' => $am1FechaNacimiento,
  'adulto_mayor_fecha_nacimiento_2' => $am2FechaNacimiento,
];

$payloadCheckoutContext = [
  'token_publico' => $tokenPublico,
  'actor_tipo_publico' => $actorTipoPublico,
  'tipo_origen' => $tipoOrigen,
  'origen_checkout' => $origenCheckout,
  'id_distribuidor' => $idDistribuidor,
  'id_gestor' => $idGestor,
  'id_franquicia' => $idFranquicia,
  'pais' => $pais,
  'region' => $region,
  'zona' => $zona,
  'unidad' => $unidad,
  'venta_directa_adminpats' => $tokenPublico === '' ? 1 : 0,
];


/*
|--------------------------------------------------------------------------
| INSERT dinámico sobre columnas existentes
|--------------------------------------------------------------------------
| Evita romper si producción tiene columnas nuevas opcionales o si alguna
| columna auxiliar todavía no existe. Solo inserta columnas presentes.
|--------------------------------------------------------------------------
*/
function ppg_insert_dynamic(mysqli $cx, string $table, array $data, array $typeMap = []): int {
  $colsMap = ppg_get_table_columns($cx, $table);
  if (!$colsMap) {
    throw new RuntimeException("No existe la tabla {$table} o no se pudieron leer sus columnas.");
  }

  $cols = [];
  $placeholders = [];
  $values = [];
  $types = '';

  foreach ($data as $key => $value) {
    $k = strtolower((string)$key);
    if (!isset($colsMap[$k])) {
      continue;
    }

    $cols[] = '`' . $colsMap[$k] . '`';
    $placeholders[] = '?';
    $values[] = $value;

    $types .= $typeMap[$k] ?? 's';
  }

  if (!$cols) {
    throw new RuntimeException("No hay columnas compatibles para insertar en {$table}.");
  }

  $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
  $sql = "INSERT INTO {$safeTable} (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")";
  $stmt = $cx->prepare($sql);

  if (!$stmt) {
    throw new RuntimeException("No fue posible preparar INSERT dinámico en {$table}: " . $cx->error);
  }

  $refs = [];
  $refs[] = $types;
  foreach ($values as $i => $v) {
    $refs[] = &$values[$i];
  }

  call_user_func_array([$stmt, 'bind_param'], $refs);

  if (!$stmt->execute()) {
    throw new RuntimeException("No fue posible insertar en {$table}: " . $stmt->error);
  }

  $id = (int)$stmt->insert_id;
  $stmt->close();

  return $id;
}

/*
|--------------------------------------------------------------------------
| Crear pasaporte real después de pago confirmado
|--------------------------------------------------------------------------
*/
function ppg_calcular_vigencia_pasaporte(string $frecuencia): array {
  $freq = strtoupper(trim($frecuencia));
  $fechaAlta = date('Y-m-d H:i:s');

  if ($freq === 'ANUAL') {
    $vigenciaDate = date('Y-m-d', strtotime('+12 months'));
    $vigenciaReal = date('Y-m-d H:i:s', strtotime('+12 months'));
  } else {
    $vigenciaDate = date('Y-m-d', strtotime('+1 month'));
    $vigenciaReal = date('Y-m-d H:i:s', strtotime('+1 month'));
  }

  return [$fechaAlta, $vigenciaDate, $vigenciaReal];
}

function ppg_insertar_pasaporte_confirmado(mysqli $cx, array $d): int {
  [$fechaAlta, $vigencia, $fechaVencimientoReal] =
    ppg_calcular_vigencia_pasaporte((string)($d['frecuencia_pago'] ?? 'MENSUAL'));

  $foto = $d['foto_paciente'] ?? null;
  $fotoPath = is_array($foto) ? (string)($foto['relative_path'] ?? '') : '';
  $fotoNombre = is_array($foto) ? (string)($foto['name'] ?? '') : '';
  $fotoMime = is_array($foto) ? (string)($foto['type'] ?? '') : '';
  $fotoSize = is_array($foto) ? (int)($foto['size'] ?? 0) : 0;
  $fotoSizeKb = $fotoSize > 0 ? (int)ceil($fotoSize / 1024) : 0;

  $data = [
    'id_franquicia' => (int)($d['id_franquicia'] ?? 0),
    'id_distribuidor' => (int)($d['id_distribuidor'] ?? 0),
    'id_tipo_precio' => (int)($d['id_tipo_precio'] ?? 0),
    'id_cliente' => null,
    'id_beneficiario' => null,
    'id_unidad' => null,

    'curp' => (string)($d['curp'] ?? 'NO_APLICA'),
    'nombres' => (string)($d['nombres'] ?? ''),
    'apellido_pa' => (string)($d['apellido_pa'] ?? ''),
    'apellido_ma' => (string)($d['apellido_ma'] ?? ''),
    'fecha_nacimiento' => (string)($d['fecha_nacimiento'] ?? ''),
    'telefono' => (string)($d['telefono'] ?? ''),
    'correo' => (string)($d['correo'] ?? ''),

    'fecha_alta' => $fechaAlta,
    'vigencia' => $vigencia,
    'fecha_baja' => null,
    'frecuencia_pago' => (string)($d['frecuencia_pago'] ?? 'MENSUAL'),
    'estatus' => 'activo',

    'valor_pasaporte' => (float)($d['valor_pasaporte'] ?? 0),
    'valor_final_pasaporte' => (float)($d['valor_final_pasaporte'] ?? 0),
    'cupon' => (string)($d['stripe_payment_intent_id'] ?? ''),

    'pais' => (string)($d['pais'] ?? 'México'),
    'region' => (string)($d['region'] ?? ''),
    'zona' => (string)($d['zona'] ?? ''),
    'unidad' => (string)($d['unidad'] ?? ''),
    'tipo_cliente' => (string)($d['tipo_cliente'] ?? 'privado'),
    'nombre_empresa' => (string)($d['nombre_empresa'] ?? ''),

    'fotografia_path' => $fotoPath,
    'fotografia_nombre' => $fotoNombre,
    'fotografia_nombre_original' => $fotoNombre,
    'fotografia_mime' => $fotoMime,
    'fotografia_mime_type' => $fotoMime,
    'fotografia_size_bytes' => $fotoSize,
    'fotografia_size_kb' => $fotoSizeKb,
    'fecha_fotografia' => $fotoPath !== '' ? $fechaAlta : null,

    'fecha_ultimo_pago' => $fechaAlta,
    'fecha_vencimiento_real' => $fechaVencimientoReal,
    'meses_vencidos' => 0,
    'recargo_acumulado' => 0.00,
    'activo' => 1,
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
  ];

  return ppg_insert_dynamic($cx, 'pats_pasaportes', $data, [
    'id_franquicia' => 'i',
    'id_distribuidor' => 'i',
    'id_tipo_precio' => 'i',
    'id_cliente' => 'i',
    'id_beneficiario' => 'i',
    'id_unidad' => 'i',
    'valor_pasaporte' => 'd',
    'valor_final_pasaporte' => 'd',
    'fotografia_size_bytes' => 'i',
    'fotografia_size_kb' => 'i',
    'meses_vencidos' => 'i',
    'recargo_acumulado' => 'd',
    'activo' => 'i',
  ]);
}

function ppg_marcar_orden_con_pasaporte(mysqli $cx, int $idOrden, int $idPasaporte): void {
  $stmt = $cx->prepare("
    UPDATE pats_ordenes_pago
    SET
      id_pasaporte = ?,
      pasaporte_creado = 1,
      id_pasaporte_generado = ?,
      procesado_integracion = 1,
      updated_at = NOW()
    WHERE id_orden = ?
    LIMIT 1
  ");

  if (!$stmt) {
    throw new RuntimeException('No fue posible preparar actualización de orden con pasaporte: ' . $cx->error);
  }

  $stmt->bind_param('iii', $idPasaporte, $idPasaporte, $idOrden);

  if (!$stmt->execute()) {
    throw new RuntimeException('No fue posible marcar la orden con el pasaporte creado: ' . $stmt->error);
  }

  $stmt->close();
}

function ppg_insertar_alta_pasaporte(mysqli $cx, array $d): int {
  return ppg_insert_dynamic($cx, 'pats_pasaporte_altas', [
    'id_pasaporte' => (int)($d['id_pasaporte'] ?? 0),
    'id_orden' => (int)($d['id_orden'] ?? 0),
    'referencia_pago' => (string)($d['referencia_pago'] ?? ''),
    'stripe_payment_intent_id' => (string)($d['stripe_payment_intent_id'] ?? ''),
    'stripe_charge_id' => (string)($d['stripe_charge_id'] ?? ''),

    'token_publico' => (string)($d['token_publico'] ?? ''),
    'actor_tipo_publico' => (string)($d['actor_tipo_publico'] ?? 'ADMINPATS'),
    'tipo_origen' => (string)($d['tipo_origen'] ?? 'ADMINPATS'),
    'origen_checkout' => (string)($d['origen_checkout'] ?? 'PORTAL_PUBLICO'),

    'id_franquicia' => (int)($d['id_franquicia'] ?? 0),
    'id_distribuidor' => (int)($d['id_distribuidor'] ?? 0),
    'id_gestor' => (int)($d['id_gestor'] ?? 0),

    'tipo_paciente' => (string)($d['tipo_paciente'] ?? 'ADULTO'),
    'modo_firma' => (string)($d['modo_firma'] ?? 'FIRMA_PROPIA'),
    'requiere_responsable' => !empty($d['requiere_responsable']) ? 1 : 0,
    'tipo_representacion' => (string)($d['tipo_representacion'] ?? ''),
    'relacion_responsable_paciente' => (string)($d['relacion_responsable_paciente'] ?? ''),
    'motivo_responsable' => (string)($d['motivo_responsable'] ?? ''),

    'nacionalidad_tipo' => (string)($d['nacionalidad_tipo'] ?? 'MEXICANA'),
    'paciente_es_menor' => !empty($d['paciente_es_menor']) ? 1 : 0,
    'paciente_es_adulto_mayor' => !empty($d['paciente_es_adulto_mayor']) ? 1 : 0,

    'datos_paciente_json' => (string)($d['datos_paciente_json'] ?? '{}'),
    'datos_responsable_json' => (string)($d['datos_responsable_json'] ?? '{}'),
    'domicilio_json' => (string)($d['domicilio_json'] ?? '{}'),
    'adulto_mayor_json' => (string)($d['adulto_mayor_json'] ?? '{}'),
    'origen_comercial_json' => (string)($d['origen_comercial_json'] ?? '{}'),
    'stripe_json' => (string)($d['stripe_json'] ?? '{}'),
    'payload_formulario_json' => (string)($d['payload_formulario_json'] ?? '{}'),

    'ip_registro' => ppg_get_ip(),
    'user_agent_registro' => ppg_get_user_agent(),
    'estatus' => 'PAGO_CONFIRMADO',
    'activo' => 1,
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
  ], [
    'id_pasaporte' => 'i',
    'id_orden' => 'i',
    'id_franquicia' => 'i',
    'id_distribuidor' => 'i',
    'id_gestor' => 'i',
    'requiere_responsable' => 'i',
    'paciente_es_menor' => 'i',
    'paciente_es_adulto_mayor' => 'i',
    'activo' => 'i',
  ]);
}

function ppg_insertar_documento_pasaporte(mysqli $cx, int $idPasaporte, int $idAlta, int $idOrden, string $actor, string $tipo, ?array $doc, bool $obligatorio = false, array $metadata = []): ?int {
  if (!$doc || empty($doc['relative_path'])) {
    return null;
  }

  return ppg_insert_dynamic($cx, 'pats_pasaporte_documentos', [
    'id_pasaporte' => $idPasaporte,
    'id_alta' => $idAlta,
    'id_orden' => $idOrden,
    'actor_documento' => $actor,
    'tipo_documento' => $tipo,
    'etiqueta' => str_replace('_', ' ', $tipo),
    'archivo_path' => (string)($doc['relative_path'] ?? ''),
    'archivo_nombre_original' => (string)($doc['name'] ?? ''),
    'archivo_mime_type' => (string)($doc['type'] ?? ''),
    'archivo_extension' => (string)($doc['extension'] ?? ''),
    'archivo_size_bytes' => (int)($doc['size'] ?? 0),
    'es_obligatorio' => $obligatorio ? 1 : 0,
    'estatus' => 'CARGADO',
    'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'activo' => 1,
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
  ], [
    'id_pasaporte' => 'i',
    'id_alta' => 'i',
    'id_orden' => 'i',
    'archivo_size_bytes' => 'i',
    'es_obligatorio' => 'i',
    'activo' => 'i',
  ]);
}

function ppg_hash_contract(string $html): string {
  return hash('sha256', $html);
}

/*
|--------------------------------------------------------------------------
| Resolver archivo: sesión (pre-upload) o $_FILES (upload directo)
|--------------------------------------------------------------------------
| Si el JS ya subió el archivo progresivamente (public_preupload.php),
| la ruta queda en sesión y aquí se recupera sin necesidad de re-subir.
| Si no hay pre-upload, cae al comportamiento original de ppg_store_upload.
|--------------------------------------------------------------------------
*/
function ppg_resolve_file(string $fieldName, string $prefix): ?array {
  $csrf = (string)($_SESSION['_csrf_pats_publico'] ?? '');
  if ($csrf !== '') {
    $meta = $_SESSION['pats_preloads'][$csrf][$fieldName] ?? null;
    if (
      is_array($meta) &&
      !empty($meta['relative_path']) &&
      file_exists(dirname(__DIR__) . '/' . $meta['relative_path'])
    ) {
      return $meta;
    }
  }

  $file = $_FILES[$fieldName] ?? null;
  if (!$file || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
    return null;
  }
  return ppg_store_upload($file, $prefix);
}

/*
|--------------------------------------------------------------------------
| Respaldo pre-pago
|--------------------------------------------------------------------------
| Se guarda en pats_respaldo ANTES de verificar Stripe.
| Si la transacción de BD falla después del cobro, el respaldo permite
| recuperar todos los datos del afiliado manualmente.
|--------------------------------------------------------------------------
*/
function ppg_insertar_respaldo(mysqli $cx, array $d): int {
  $payloadJson = json_encode($d['payload_post'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  $stmt = $cx->prepare("
    INSERT INTO pats_respaldo
    (
      stripe_payment_intent_id,
      referencia_pago,
      folio_orden,
      estatus_respaldo,
      id_franquicia,
      id_distribuidor,
      id_tipo_precio,
      curp,
      nombres,
      apellido_pa,
      apellido_ma,
      fecha_nacimiento,
      telefono,
      correo,
      frecuencia_pago,
      monto_orden,
      moneda,
      pais,
      region,
      zona,
      unidad,
      tipo_cliente,
      tipo_origen,
      actor_tipo_publico,
      tipo_paciente,
      modo_firma,
      nacionalidad_tipo,
      nombre_firmante,
      payload_post_json,
      ip_registro,
      user_agent_registro,
      created_at,
      updated_at
    )
    VALUES (?,?,?,'PENDIENTE_PAGO',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
  ");

  if (!$stmt) {
    throw new RuntimeException('No fue posible preparar INSERT respaldo: ' . $cx->error);
  }

  $stripePI    = (string)($d['stripe_payment_intent_id'] ?? '');
  $ref         = (string)($d['referencia_pago'] ?? '');
  $folio       = (string)($d['folio_orden'] ?? '');
  $idFranq     = (int)($d['id_franquicia'] ?? 0);
  $idDist      = (int)($d['id_distribuidor'] ?? 0);
  $idTipo      = (int)($d['id_tipo_precio'] ?? 0);
  $curp        = (string)($d['curp'] ?? '');
  $nombres     = (string)($d['nombres'] ?? '');
  $apPa        = (string)($d['apellido_pa'] ?? '');
  $apMa        = (string)($d['apellido_ma'] ?? '');
  $fnac        = (string)($d['fecha_nacimiento'] ?? '');
  $tel         = (string)($d['telefono'] ?? '');
  $correo      = (string)($d['correo'] ?? '');
  $freq        = (string)($d['frecuencia_pago'] ?? '');
  $monto       = (float)($d['monto_orden'] ?? 0);
  $moneda      = (string)($d['moneda'] ?? 'MXN');
  $pais        = (string)($d['pais'] ?? '');
  $region      = (string)($d['region'] ?? '');
  $zona        = (string)($d['zona'] ?? '');
  $unidad      = (string)($d['unidad'] ?? '');
  $tipoCliente = (string)($d['tipo_cliente'] ?? '');
  $tipoOrigen  = (string)($d['tipo_origen'] ?? '');
  $actorTipo   = (string)($d['actor_tipo_publico'] ?? '');
  $tipoPac     = (string)($d['tipo_paciente'] ?? '');
  $modoFirma   = (string)($d['modo_firma'] ?? '');
  $nac         = (string)($d['nacionalidad_tipo'] ?? '');
  $firmante    = (string)($d['nombre_firmante'] ?? '');
  $ip          = ppg_get_ip();
  $ua          = ppg_get_user_agent();

  $stmt->bind_param(
    'sssiiissssssssdsssssssssssssss',
    $stripePI, $ref, $folio,
    $idFranq, $idDist, $idTipo,
    $curp, $nombres, $apPa, $apMa,
    $fnac, $tel, $correo, $freq,
    $monto, $moneda,
    $pais, $region, $zona, $unidad,
    $tipoCliente, $tipoOrigen, $actorTipo,
    $tipoPac, $modoFirma, $nac, $firmante,
    $payloadJson, $ip, $ua
  );

  if (!$stmt->execute()) {
    throw new RuntimeException('No fue posible insertar respaldo: ' . $stmt->error);
  }

  $id = (int)$stmt->insert_id;
  $stmt->close();
  return $id;
}

function ppg_actualizar_respaldo_confirmado(mysqli $cx, int $idRespaldo, int $idPasaporte, int $idOrden): void {
  $stmt = $cx->prepare("
    UPDATE pats_respaldo
    SET
      estatus_respaldo     = 'PAGO_CONFIRMADO',
      id_pasaporte_generado = ?,
      id_orden_generado    = ?,
      updated_at           = NOW()
    WHERE id_respaldo = ?
    LIMIT 1
  ");

  if (!$stmt) {
    throw new RuntimeException('No fue posible preparar update respaldo: ' . $cx->error);
  }

  $stmt->bind_param('iii', $idPasaporte, $idOrden, $idRespaldo);

  if (!$stmt->execute()) {
    throw new RuntimeException('No fue posible actualizar respaldo: ' . $stmt->error);
  }
  $stmt->close();
}

function ppg_insertar_contrato_firmado(mysqli $cx, array $d): int {
  $html = (string)($d['html_contrato_renderizado'] ?? '');
  return ppg_insert_dynamic($cx, 'pats_contratos_firmados', [
    'id_pasaporte' => (int)($d['id_pasaporte'] ?? 0),
    'id_alta' => (int)($d['id_alta'] ?? 0),
    'id_orden' => (int)($d['id_orden'] ?? 0),
    'id_distribuidor' => (int)($d['id_distribuidor'] ?? 0),
    'id_franquicia' => (int)($d['id_franquicia'] ?? 0),
    'id_gestor' => (int)($d['id_gestor'] ?? 0),
    'token_publico' => (string)($d['token_publico'] ?? ''),
    'contrato_clave' => (string)($d['contrato_clave'] ?? 'contrato_pats_base'),
    'contrato_version' => (string)($d['contrato_version'] ?? '1.0'),
    'html_contrato_renderizado' => $html,
    'contrato_digital_html' => $html,
    'hash_contrato' => ppg_hash_contract($html),
    'contrato_digital_hash' => ppg_hash_contract($html),
    'firma_afiliado_base64' => (string)($d['firma_afiliado_base64'] ?? ''),
    'firma_digital_data' => (string)($d['firma_afiliado_base64'] ?? ''),
    'nombre_firmante' => (string)($d['nombre_firmante'] ?? ''),
    'firma_digital_nombre' => (string)($d['nombre_firmante'] ?? ''),
    'tipo_firmante' => (string)($d['tipo_firmante'] ?? ''),
    'fecha_firma' => date('Y-m-d H:i:s'),
    'contrato_digital_firmado_at' => date('Y-m-d H:i:s'),
    'ip_firma' => ppg_get_ip(),
    'firma_digital_ip' => ppg_get_ip(),
    'user_agent_firma' => ppg_get_user_agent(),
    'firma_digital_user_agent' => ppg_get_user_agent(),
    'pdf_path' => '',
    'estatus' => 'firmado',
    'activo' => 1,
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
  ], [
    'id_pasaporte' => 'i',
    'id_alta' => 'i',
    'id_orden' => 'i',
    'id_distribuidor' => 'i',
    'id_franquicia' => 'i',
    'id_gestor' => 'i',
    'activo' => 'i',
  ]);
}

function ppg_generar_password_temporal(): string {
  return strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
}

function ppg_insertar_acceso_pasaporte(mysqli $cx, array $d): array {
  $passwordTemporal = ppg_generar_password_temporal();
  $resetToken = bin2hex(random_bytes(24));

  $id = ppg_insert_dynamic($cx, 'pats_pasaporte_accesos', [
    'id_pasaporte' => (int)($d['id_pasaporte'] ?? 0),
    'id_alta' => (int)($d['id_alta'] ?? 0),
    'id_orden' => (int)($d['id_orden'] ?? 0),
    'tipo_acceso' => (string)($d['tipo_acceso'] ?? 'PACIENTE'),
    'correo_usuario' => (string)($d['correo_usuario'] ?? ''),
    'telefono_usuario' => (string)($d['telefono_usuario'] ?? ''),
    'nombre_usuario' => (string)($d['nombre_usuario'] ?? ''),
    'nombre_paciente' => (string)($d['nombre_paciente'] ?? ''),
    'password_hash' => password_hash($passwordTemporal, PASSWORD_DEFAULT),
    'password_temporal' => 1,
    'debe_cambiar_password' => 1,
    'token_reset' => $resetToken,
    'token_reset_expira' => date('Y-m-d H:i:s', strtotime('+7 days')),
    'intentos_fallidos' => 0,
    'estatus' => 'ACTIVO',
    'activo' => 1,
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
  ], [
    'id_pasaporte' => 'i',
    'id_alta' => 'i',
    'id_orden' => 'i',
    'password_temporal' => 'i',
    'debe_cambiar_password' => 'i',
    'intentos_fallidos' => 'i',
    'activo' => 'i',
  ]);

  return [
    'id_acceso' => $id,
    'token_reset' => $resetToken,
    /*
      Por seguridad no se devuelve ni se guarda la contraseña temporal en claro.
      El correo usará token_reset para que el usuario defina su contraseña.
    */
  ];
}


/* =========================================================
   PROCESO
========================================================= */
try {
  $cx->begin_transaction();

  /* 1) Guardar uploads (usa pre-carga de sesión si existe, sube en el momento si no) */
  $documentosCargados = [
    'doc_identificacion_frente'       => ppg_resolve_file('doc_identificacion_frente',       'paciente_identificacion_frente'),
    'doc_identificacion_reverso'      => ppg_resolve_file('doc_identificacion_reverso',      'paciente_identificacion_reverso'),
    'doc_curp'                        => ppg_resolve_file('doc_curp',                        'paciente_curp'),
    'doc_comprobante_domicilio'       => ppg_resolve_file('doc_comprobante_domicilio',       'paciente_comprobante_domicilio'),
    'doc_constancia_fiscal'           => ppg_resolve_file('doc_constancia_fiscal',           'paciente_constancia_fiscal'),

    'tutor_doc_identificacion_frente' => ppg_resolve_file('tutor_doc_identificacion_frente', 'responsable_identificacion_frente'),
    'tutor_doc_identificacion_reverso'=> ppg_resolve_file('tutor_doc_identificacion_reverso','responsable_identificacion_reverso'),
    'tutor_doc_curp'                  => ppg_resolve_file('tutor_doc_curp',                  'responsable_curp'),
    'tutor_doc_constancia_fiscal'     => ppg_resolve_file('tutor_doc_constancia_fiscal',     'responsable_constancia_fiscal'),

    'doc_acreditacion_representacion' => ppg_resolve_file('doc_acreditacion_representacion', 'acreditacion_representacion'),
    'foto_paciente'                   => ppg_store_base64_image($fotoBase64, 'foto_paciente'),
  ];

  /* 2) Insertar orden base */
  $stmt = $cx->prepare("
    INSERT INTO pats_ordenes_pago
    (
      id_pasaporte,
      id_franquicia,
      id_distribuidor,
      id_tipo_precio,
      correo_usuario_pats,
      curp_usuario,
      nombre_usuario,
      apellido_pa,
      apellido_ma,
      fecha_nacimiento,
      telefono_usuario,
      pais,
      region,
      zona,
      unidad,
      tipo_cliente,
      nombre_empresa,
      tipo_operacion,
      frecuencia,
      monto_orden,
      monto_nominal_base,
      monto_extra_recargo,
      moneda,
      referencia_pago,
      folio_orden,
      estatus_orden,
      estatus_pago,
      proveedor_pasarela,
      transaccion_id_externa,
      payment_intent_id,
      charge_id,
      referencia_externa,
      order_id_externo,
      payload_confirmacion_json,
      fecha_pago,
      fecha_confirmacion,
      pasaporte_creado,
      id_pasaporte_generado,
      procesado_integracion,
      created_at,
      updated_at
    )
    VALUES
    (
      NULL,
      ?, ?, ?,
      ?, ?, ?, ?, ?, ?, ?,
      ?, ?, ?, ?,
      ?, ?, 'ALTA_PATS',
      ?, ?, ?, ?, ?, ?, ?,
      'PAGO_CONFIRMADO', 'CONFIRMADO',
      ?, ?, ?, ?, ?, ?, '{}',
      NOW(), NOW(),
      0, NULL, 0,
      NOW(), NOW()
    )
  ");
  if (!$stmt) {
    throw new RuntimeException('No fue posible preparar orden: ' . $cx->error);
  }

  $stmt->bind_param(
    'iiissssssssssssssdddsssssssss',
    $idFranquicia,
    $idDistribuidor,
    $idTipoPrecio,
    $correoOrden,
    $curpOrden,
    $nombre,
    $apellidoPa,
    $apellidoMa,
    $fechaNacimiento,
    $telefonoOrden,
    $pais,
    $region,
    $zona,
    $unidad,
    $tipoCliente,
    $nombreEmpresa,
    $frecuencia,
    $montoOrden,
    $montoNominalBase,
    $montoExtraRecargo,
    $moneda,
    $referenciaPago,
    $folioOrden,
    $proveedorPasarela,
    $stripePaymentIntentId,
    $stripePaymentIntentId,
    $stripeChargeId,
    $stripePaymentIntentId,
    $stripePaymentIntentId
  );

  if (!$stmt->execute()) {
    throw new RuntimeException('No fue posible insertar la orden: ' . $stmt->error);
  }
  $idOrden = (int)$stmt->insert_id;
  $stmt->close();

  /* 3) Crear pasaporte real después de pago confirmado */
  $idPasaporteGenerado = ppg_insertar_pasaporte_confirmado($cx, [
    'id_franquicia' => $idFranquicia,
    'id_distribuidor' => $idDistribuidor,
    'id_tipo_precio' => $idTipoPrecio,
    'curp' => $curpOrden,
    'nombres' => $nombre,
    'apellido_pa' => $apellidoPa,
    'apellido_ma' => $apellidoMa,
    'fecha_nacimiento' => $fechaNacimiento,
    'telefono' => $telefonoOrden,
    'correo' => $correoOrden,
    'frecuencia_pago' => $frecuencia,
    'valor_pasaporte' => $montoNominalBase,
    'valor_final_pasaporte' => $montoOrden,
    'stripe_payment_intent_id' => $stripePaymentIntentId,
    'pais' => $pais,
    'region' => $region,
    'zona' => $zona,
    'unidad' => $unidad,
    'tipo_cliente' => $tipoCliente,
    'nombre_empresa' => $nombreEmpresa,
    'foto_paciente' => $documentosCargados['foto_paciente'] ?? null,
  ]);

  ppg_marcar_orden_con_pasaporte($cx, $idOrden, $idPasaporteGenerado);

  /* 4) Guardar expediente completo de alta */
  $datosPacienteJson = json_encode([
    'nombre' => $nombre,
    'apellido_pa' => $apellidoPa,
    'apellido_ma' => $apellidoMa,
    'nombre_completo' => $fullName,
    'curp' => $curp,
    'fecha_nacimiento' => $fechaNacimiento,
    'edad' => $edad,
    'tipo_paciente' => $tipoPaciente,
    'nacionalidad_tipo' => $nacionalidadTipo,
    'nacionalidad' => $nacionalidad,
    'pais_nacimiento' => $paisNacimiento,
    'rfc_usuario' => $rfcUsuario,
    'actividad_ocupacion' => $actividadOcupacion,
    'estado_civil' => $estadoCivil,
    'tipo_documento_identidad' => $tipoDocumentoIdentidad,
    'pais_documento_identidad' => $paisDocumentoIdentidad,
    'numero_documento_identidad' => $numeroDocumentoIdentidad,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  $datosResponsableJson = json_encode([
    'nombre' => $tutorNombre,
    'apellido_pa' => $tutorApellidoPa,
    'apellido_ma' => $tutorApellidoMa,
    'nombre_completo' => $tutorFullName,
    'curp' => $tutorCurp,
    'rfc' => $tutorRfc,
    'fecha_nacimiento' => $tutorFechaNacimiento,
    'correo' => $tutorCorreo,
    'telefono' => $tutorTelefono,
    'nacionalidad_tipo' => $tutorNacionalidadTipo,
    'nacionalidad' => $tutorNacionalidad,
    'pais_nacimiento' => $tutorPaisNacimiento,
    'tipo_documento_identidad' => $tutorTipoDocumentoIdentidad,
    'pais_documento_identidad' => $tutorPaisDocumentoIdentidad,
    'numero_documento_identidad' => $tutorNumeroDocumentoIdentidad,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  $domicilioJson = json_encode([
    'calle' => $domCalle,
    'num_ext' => $domNumExt,
    'num_int' => $domNumInt,
    'colonia' => $domColonia,
    'cp' => $domCp,
    'municipio' => $domMunicipio,
    'estado' => $domEstado,
    'estado_acronimo' => $domEstadoAcronimo,
    'pais' => $domPais,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  $adultoMayorJson = json_encode([
    'pasaportes_validados' => $adultoMayorPasaportesValidados,
    'am1_id_pasaporte' => $am1IdPasaporte,
    'am1_fecha_nacimiento' => $am1FechaNacimiento,
    'am2_id_pasaporte' => $am2IdPasaporte,
    'am2_fecha_nacimiento' => $am2FechaNacimiento,
    'pasaporte_1_json' => $adultoMayorPasaporte1Json,
    'pasaporte_2_json' => $adultoMayorPasaporte2Json,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  $origenComercialJson = json_encode($payloadCheckoutContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  $stripeJson = json_encode([
    'payment_intent_id' => $stripePaymentIntentId,
    'status' => (string)($stripePago['status'] ?? ''),
    'amount_paid' => (float)($stripePago['amount_paid'] ?? 0),
    'currency' => (string)($stripePago['currency'] ?? ''),
    'latest_charge' => $stripeChargeId,
    'raw' => $stripePago['raw'] ?? [],
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$payloadFormularioJson = json_encode([
  'post' => $_POST,
  'id_pasaporte_generado' => $idPasaporteGenerado,
  'id_alta_pasaporte' => null,
  'id_acceso_pasaporte' => null,
  'documentos_cargados' => $documentosCargados,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  $idAltaPasaporte = ppg_insertar_alta_pasaporte($cx, [
    'id_pasaporte' => $idPasaporteGenerado,
    'id_orden' => $idOrden,
    'referencia_pago' => $referenciaPago,
    'stripe_payment_intent_id' => $stripePaymentIntentId,
    'stripe_charge_id' => $stripeChargeId,
    'token_publico' => $tokenPublico,
    'actor_tipo_publico' => $actorTipoPublico,
    'tipo_origen' => $tipoOrigen,
    'origen_checkout' => $origenCheckout,
    'id_franquicia' => $idFranquicia,
    'id_distribuidor' => $idDistribuidor,
    'id_gestor' => $idGestor,
    'tipo_paciente' => $tipoPaciente,
    'modo_firma' => $modoFirma,
    'requiere_responsable' => $requiereResponsable ? 1 : 0,
    'tipo_representacion' => $tipoRepresentacion,
    'relacion_responsable_paciente' => $relacionResponsable,
    'motivo_responsable' => $motivoResponsable,
    'nacionalidad_tipo' => $nacionalidadTipo,
    'paciente_es_menor' => $esMenor ? 1 : 0,
    'paciente_es_adulto_mayor' => $esAdultoMayor ? 1 : 0,
    'datos_paciente_json' => $datosPacienteJson,
    'datos_responsable_json' => $datosResponsableJson,
    'domicilio_json' => $domicilioJson,
    'adulto_mayor_json' => $adultoMayorJson,
    'origen_comercial_json' => $origenComercialJson,
    'stripe_json' => $stripeJson,
    'payload_formulario_json' => $payloadFormularioJson,
  ]);

  /* 4.1) Guardar documentos aplicables por modalidad */
  $docMap = [
    'doc_identificacion_frente' => ['PACIENTE', 'PACIENTE_IDENTIFICACION_FRENTE', !$requiereResponsable && $pacienteMexicano],
    'doc_identificacion_reverso' => ['PACIENTE', 'PACIENTE_IDENTIFICACION_REVERSO', !$requiereResponsable && $pacienteMexicano],
    'doc_curp' => ['PACIENTE', 'PACIENTE_CURP', $pacienteMexicano],
    'doc_comprobante_domicilio' => ['PACIENTE', 'PACIENTE_COMPROBANTE_DOMICILIO', true],
    'doc_constancia_fiscal' => ['PACIENTE', 'PACIENTE_CONSTANCIA_FISCAL', false],
    'foto_paciente' => ['PACIENTE', 'PACIENTE_FOTO', true],

    'tutor_doc_identificacion_frente' => ['RESPONSABLE', 'RESPONSABLE_IDENTIFICACION_FRENTE', $requiereResponsable],
    'tutor_doc_identificacion_reverso' => ['RESPONSABLE', 'RESPONSABLE_IDENTIFICACION_REVERSO', $requiereResponsable && $tutorMexicano],
    'tutor_doc_curp' => ['RESPONSABLE', 'RESPONSABLE_CURP', $requiereResponsable && $tutorMexicano],
    'tutor_doc_constancia_fiscal' => ['RESPONSABLE', 'RESPONSABLE_CONSTANCIA_FISCAL', false],
    'doc_acreditacion_representacion' => ['RESPONSABLE', 'ACREDITACION_REPRESENTACION', $esDependienteRepresentado],
  ];

  foreach ($docMap as $key => $cfgDoc) {
    [$actorDoc, $tipoDoc, $obligatorioDoc] = $cfgDoc;
    ppg_insertar_documento_pasaporte(
      $cx,
      $idPasaporteGenerado,
      $idAltaPasaporte,
      $idOrden,
      $actorDoc,
      $tipoDoc,
      $documentosCargados[$key] ?? null,
      (bool)$obligatorioDoc,
      [
        'tipo_paciente' => $tipoPaciente,
        'modo_firma' => $modoFirma,
        'nacionalidad_tipo' => $nacionalidadTipo,
        'tutor_nacionalidad_tipo' => $tutorNacionalidadTipo,
      ]
    );
  }

  /* 4.2) Renderizar y guardar contrato firmado */
  $htmlContrato = pats_render_contract_html($payloadContrato);

  $idContratoFirmado = ppg_insertar_contrato_firmado($cx, [
    'id_pasaporte' => $idPasaporteGenerado,
    'id_alta' => $idAltaPasaporte,
    'id_orden' => $idOrden,
    'id_distribuidor' => $idDistribuidor,
    'id_franquicia' => $idFranquicia,
    'id_gestor' => $idGestor,
    'token_publico' => $tokenPublico,
    'contrato_clave' => 'contrato_pats_base',
    'contrato_version' => '1.0',
    'html_contrato_renderizado' => $htmlContrato,
    'firma_afiliado_base64' => $firmaBase64,
    'nombre_firmante' => $nombreFirmante,
    'tipo_firmante' => $requiereResponsable ? 'RESPONSABLE' : 'PACIENTE',
  ]);

  /* 4.3) Crear acceso de usuario */
  $tipoAcceso = $requiereResponsable ? ($esMenor ? 'TUTOR' : 'RESPONSABLE') : 'PACIENTE';
  $accesoCreado = ppg_insertar_acceso_pasaporte($cx, [
    'id_pasaporte' => $idPasaporteGenerado,
    'id_alta' => $idAltaPasaporte,
    'id_orden' => $idOrden,
    'tipo_acceso' => $tipoAcceso,
    'correo_usuario' => $correoOrden,
    'telefono_usuario' => $telefonoOrden,
    'nombre_usuario' => $nombreFirmante,
    'nombre_paciente' => $fullName,
  ]);

  $idAccesoPasaporte = (int)($accesoCreado['id_acceso'] ?? 0);
  $tokenResetAcceso = (string)($accesoCreado['token_reset'] ?? '');


  /* 5) Guardar contexto inicial de orden */
  $payloadOrdenInicial = [
    'checkout_context' => $payloadCheckoutContext,
    'paciente' => [
      'nombre' => $nombre,
      'apellido_pa' => $apellidoPa,
      'apellido_ma' => $apellidoMa,
      'nombre_completo' => $fullName,
      'curp' => $curp,
      'fecha_nacimiento' => $fechaNacimiento,
      'edad' => $edad,
      'tipo_paciente' => $tipoPaciente,
      'nacionalidad_tipo' => $nacionalidadTipo,
      'nacionalidad' => $nacionalidad,
      'pais_nacimiento' => $paisNacimiento,
      'rfc_usuario' => $rfcUsuario,
      'actividad_ocupacion' => $actividadOcupacion,
      'estado_civil' => $estadoCivil,
      'tipo_documento_identidad' => $tipoDocumentoIdentidad,
      'pais_documento_identidad' => $paisDocumentoIdentidad,
      'numero_documento_identidad' => $numeroDocumentoIdentidad,
    ],
    'contacto_acceso' => [
      'correo_capturado' => $correoAcceso,
      'telefono_capturado' => $telefonoAcceso,
      'correo_orden' => $correoOrden,
      'telefono_orden' => $telefonoOrden,
    ],
    'domicilio' => [
      'calle' => $domCalle,
      'num_ext' => $domNumExt,
      'num_int' => $domNumInt,
      'colonia' => $domColonia,
      'cp' => $domCp,
      'municipio' => $domMunicipio,
      'estado' => $domEstado,
      'estado_acronimo' => $domEstadoAcronimo,
      'pais' => $domPais,
    ],
    'representacion' => [
      'modo_firma' => $modoFirma,
      'requiere_responsable' => $requiereResponsable ? 1 : 0,
      'tipo_representacion' => $tipoRepresentacion,
      'relacion_responsable_paciente' => $relacionResponsable,
      'motivo_responsable' => $motivoResponsable,
      'nombre_firmante' => $nombreFirmante,
    ],
    'responsable' => [
      'nombre' => $tutorNombre,
      'apellido_pa' => $tutorApellidoPa,
      'apellido_ma' => $tutorApellidoMa,
      'nombre_completo' => $tutorFullName,
      'curp' => $tutorCurp,
      'rfc' => $tutorRfc,
      'fecha_nacimiento' => $tutorFechaNacimiento,
      'correo' => $tutorCorreo,
      'telefono' => $tutorTelefono,
      'nacionalidad_tipo' => $tutorNacionalidadTipo,
      'nacionalidad' => $tutorNacionalidad,
      'pais_nacimiento' => $tutorPaisNacimiento,
      'tipo_documento_identidad' => $tutorTipoDocumentoIdentidad,
      'pais_documento_identidad' => $tutorPaisDocumentoIdentidad,
      'numero_documento_identidad' => $tutorNumeroDocumentoIdentidad,
    ],
    'adulto_mayor' => [
      'pasaportes_validados' => $adultoMayorPasaportesValidados,
      'am1_id_pasaporte' => $am1IdPasaporte,
      'am1_fecha_nacimiento' => $am1FechaNacimiento,
      'am2_id_pasaporte' => $am2IdPasaporte,
      'am2_fecha_nacimiento' => $am2FechaNacimiento,
      'pasaporte_1_json' => $adultoMayorPasaporte1Json,
      'pasaporte_2_json' => $adultoMayorPasaporte2Json,
    ],
    'documentos_cargados' => $documentosCargados,
    'stripe' => [
      'payment_intent_id' => $stripePaymentIntentId,
      'status' => (string)($stripePago['status'] ?? ''),
      'amount_paid' => (float)($stripePago['amount_paid'] ?? 0),
      'currency' => (string)($stripePago['currency'] ?? ''),
      'latest_charge' => $stripeChargeId,
      'raw' => $stripePago['raw'] ?? [],
    ],
    'contrato' => [
      'id_contrato_firmado' => $idContratoFirmado,
      'id_pasaporte_generado' => $idPasaporteGenerado,
      'id_alta_pasaporte' => $idAltaPasaporte,
      'nombre_firmante' => $nombreFirmante,
      'ip_firma' => ppg_get_ip(),
      'user_agent_firma' => ppg_get_user_agent(),
    ],
    'estado_inicial' => [
      'estatus_orden' => 'PAGO_CONFIRMADO',
      'estatus_pago' => 'CONFIRMADO'
    ]
  ];

  $payloadOrdenInicialJson = json_encode($payloadOrdenInicial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  $stmtInit = $cx->prepare("
    UPDATE pats_ordenes_pago
    SET payload_confirmacion_json = ?, updated_at = NOW()
    WHERE id_orden = ?
    LIMIT 1
  ");
  if (!$stmtInit) {
    throw new RuntimeException('No fue posible preparar update inicial de orden: ' . $cx->error);
  }
  $stmtInit->bind_param('si', $payloadOrdenInicialJson, $idOrden);
  if (!$stmtInit->execute()) {
    throw new RuntimeException('No fue posible guardar contexto inicial de la orden: ' . $stmtInit->error);
  }
  $stmtInit->close();

  /* 6) Preparar respuesta de resultado */
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

  $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
  $endpointsBase = rtrim($scheme . '://' . $host . $scriptDir, '/');
  $patsBase = preg_replace('#/endpoints$#', '', $endpointsBase);

  $checkoutUrl = $patsBase . '/pago_resultado.php?ref=' . urlencode($referenciaPago) . '&status=CONFIRMADO';

  $providerMeta = [
    'referencia_pago' => $referenciaPago,
    'folio_orden' => $folioOrden,
    'id_orden' => $idOrden,
    'id_distribuidor' => $idDistribuidor,
    'id_gestor' => $idGestor,
    'id_franquicia' => $idFranquicia,
    'id_contrato_firmado' => $idContratoFirmado,
    'actor_tipo_publico' => $actorTipoPublico,
    'tipo_origen' => $tipoOrigen,
    'origen_checkout' => $origenCheckout,
    'tipo_paciente' => $tipoPaciente,
    'requiere_responsable' => $requiereResponsable ? 1 : 0,
    'nombre_firmante' => $nombreFirmante,
    'stripe_payment_intent_id' => $stripePaymentIntentId,
    'stripe_charge_id' => $stripeChargeId,
  ];

  $providerPayload = [
    'provider' => 'STRIPE',
    'reference' => $referenciaPago,
    'amount' => $montoOrden,
    'amount_paid' => (float)($stripePago['amount_paid'] ?? 0),
    'currency' => $moneda,
    'description' => 'Alta PATS - ' . $fullName,
    'customer_email' => $correoOrden,
    'customer_phone' => $telefonoOrden,
    'payment_intent_id' => $stripePaymentIntentId,
    'charge_id' => $stripeChargeId,
    'metadata' => $providerMeta
  ];

  $providerRawMerged = [
    'checkout_context' => $payloadCheckoutContext,
    'provider_payload' => $providerPayload,
    'stripe_raw' => $stripePago['raw'] ?? [],
    'resultado_url' => $checkoutUrl
  ];

  $providerRaw = json_encode($providerRawMerged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  /* 7) Actualizar orden con payload final de Stripe */
  $stmt2 = $cx->prepare("
    UPDATE pats_ordenes_pago
    SET
      transaccion_id_externa = ?,
      payment_intent_id = ?,
      charge_id = ?,
      referencia_externa = ?,
      order_id_externo = ?,
      payload_confirmacion_json = ?,
      updated_at = NOW()
    WHERE id_orden = ?
    LIMIT 1
  ");
  if (!$stmt2) {
    throw new RuntimeException('No fue posible preparar update orden Stripe: ' . $cx->error);
  }

  $chargeId = $stripeChargeId;

  $stmt2->bind_param(
    'ssssssi',
    $stripePaymentIntentId,
    $stripePaymentIntentId,
    $chargeId,
    $stripePaymentIntentId,
    $stripePaymentIntentId,
    $providerRaw,
    $idOrden
  );

  if (!$stmt2->execute()) {
    throw new RuntimeException('No fue posible actualizar la orden con datos de Stripe: ' . $stmt2->error);
  }
  $stmt2->close();

  $cx->commit();

  /* Marcar respaldo como confirmado (fallo silencioso: el pasaporte ya quedó guardado) */
  if ($idRespaldo > 0) {
    try {
      ppg_actualizar_respaldo_confirmado($cx, $idRespaldo, $idPasaporteGenerado, $idOrden);
    } catch (Throwable $errConfirm) {
      error_log('PATS respaldo confirm error: ' . $errConfirm->getMessage());
    }
  }

  /*
    Correo automático:
    Se envía después del commit para no bloquear el alta si el SMTP falla.
    Si falla el correo, el pago/pasaporte quedan guardados y se reporta mail_ok=false.
  */
  $mailOk = false;
  $mailError = '';
  $mailResetUrl = '';

  try {
    if (function_exists('pats_send_pasaporte_confirmacion_email')) {
      $baseUrlAcceso = defined('PATS_ACCESS_RESET_URL')
        ? (string)PATS_ACCESS_RESET_URL
        : 'https://pasaporteatusalud.com/crear-password';

      $mailResetUrl = $tokenResetAcceso !== ''
        ? $baseUrlAcceso . '?t=' . rawurlencode($tokenResetAcceso)
        : '';

      $mailOk = pats_send_pasaporte_confirmacion_email([
        'to' => $correoOrden,
        'nombre_firmante' => $nombreFirmante,
        'nombre_paciente' => $fullName,
        'id_pasaporte' => $idPasaporteGenerado,
        'referencia_pago' => $referenciaPago,
        'folio_orden' => $folioOrden,
        'monto' => $montoOrden,
        'moneda' => $moneda,
        'frecuencia' => $frecuencia,
        'usuario' => $correoOrden,
        'reset_url' => $mailResetUrl,
        'tipo_acceso' => $tipoAcceso,
      ]);
    }
  } catch (Throwable $mailEx) {
    $mailOk = false;
    $mailError = $mailEx->getMessage();
    error_log('PATS mail error: ' . $mailError);
  }

  ppg_json([
    'ok' => true,
    'id_orden' => $idOrden,
    'id_contrato_firmado' => $idContratoFirmado,
    'id_pasaporte_generado' => $idPasaporteGenerado,
    'id_alta_pasaporte' => $idAltaPasaporte,
    'id_acceso_pasaporte' => $idAccesoPasaporte,
    'mail_ok' => $mailOk,
    'mail_error' => $mailError,
    'referencia_pago' => $referenciaPago,
    'folio_orden' => $folioOrden,
    'checkout_url' => $checkoutUrl,
    'actor_tipo_publico' => $actorTipoPublico,
    'tipo_origen' => $tipoOrigen,
    'venta_directa_adminpats' => $tokenPublico === '' ? 1 : 0,
    'tipo_paciente' => $tipoPaciente,
    'requiere_responsable' => $requiereResponsable ? 1 : 0,
    'nombre_firmante' => $nombreFirmante,
    'provider_payload' => $providerPayload
  ]);

} catch (Throwable $e) {
  @$cx->rollback();
  ppg_json([
    'ok' => false,
    'error' => $e->getMessage()
  ], 500);
}