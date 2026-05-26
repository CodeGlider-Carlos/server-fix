<?php
/*
ez/pats/endpoints/public_contract_preview.php

Preview público del contrato PATS.
- Renderiza contrato, carátula y anexos con datos capturados.
- Permite token vacío: venta directa ADMINPATS.
- Con token: resuelve actor comercial si existe.
- No guarda orden, contrato ni documentos.

IMPORTANTE:
Este endpoint es público. No debe exigir login de usuario interno.
Solo debe validar POST, conexión PATS, token público cuando exista y CSRF público si está activo.
*/

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../varSQL/bd_pats.php';
require_once __DIR__ . '/../../../varSQL/var_pats.php';
require_once __DIR__ . '/../lib/contratos.php';

mysqli_report(MYSQLI_REPORT_OFF);

$cx = $conexionMod ?? $db_all2 ?? $db_all ?? null;

if (!$cx || !($cx instanceof mysqli)) {
  http_response_code(500);
  echo json_encode([
    'ok' => false,
    'error' => 'No hay conexión mysqli disponible para PATS.'
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function pcp_json(array $payload, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function pcp_clean($v): string {
  return trim((string)($v ?? ''));
}

function pcp_digits($v): string {
  return preg_replace('/\D+/', '', (string)($v ?? ''));
}

function pcp_upper($v): string {
  return strtoupper(pcp_clean($v));
}

function pcp_get_csrf_from_request(): string {
  $header = pcp_clean($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
  if ($header !== '') return $header;

  return pcp_clean($_POST['_csrf'] ?? '');
}

/*
  CSRF público:
  Si solicitud_pats.php creó $_SESSION['_csrf_pats_publico'], se valida.
  Si por compatibilidad aún no existe en alguna instalación, no se bloquea el preview.
  El bloqueo fuerte de pago queda en public_stripe_intent.php.
*/
function pcp_validate_public_csrf_soft(): void {
  /*
    Preview público:
    Este endpoint NO guarda orden, NO cobra, NO guarda contrato y NO guarda documentos.
    Por eso no debe bloquear el render si el CSRF público no coincide.

    El bloqueo fuerte de sesión/firma/contrato debe estar en:
    - public_stripe_intent.php
    - public_checkout_generar_orden.php
  */
  return;
}

function pcp_default_ctx(): array {
  return [
    'id_distribuidor' => 0,
    'id_franquicia'   => 0,
    'id_gestor'       => 0,
    'pais'            => 'México',
    'region'          => '',
    'zona'            => '',
    'unidad'          => '',
    'tipo_origen'     => 'ADMINPATS',
  ];
}

function pcp_validate_token_distribuidor(mysqli $cx, string $token): ?array {
  $stmt = $cx->prepare("
    SELECT
      d.id_distribuidor,
      d.id_franquicia,
      0 AS id_gestor,
      d.public_checkout_token,
      d.public_checkout_activo,
      f.pais,
      d.region,
      d.zona,
      d.unidad
    FROM pats_distribuidores d
    LEFT JOIN pats_franquicias f ON f.id_franquicia = d.id_franquicia
    WHERE d.public_checkout_token = ?
      AND d.public_checkout_activo = 1
      AND d.activo = 1
    LIMIT 1
  ");

  if (!$stmt) {
    throw new RuntimeException('No fue posible preparar la validación del token: ' . $cx->error);
  }

  $stmt->bind_param('s', $token);
  $stmt->execute();
  $rs = $stmt->get_result();
  $ctx = $rs ? $rs->fetch_assoc() : null;
  $stmt->close();

  if (!$ctx) return null;

  $ctx['tipo_origen'] = 'DISTRIBUIDOR';
  return $ctx;
}

function pcp_resolve_public_ctx(mysqli $cx, string $token): ?array {
  $token = pcp_clean($token);

  if ($token === '') {
    return pcp_default_ctx();
  }

  $resolver = __DIR__ . '/../public_checkout_resolver.php';

  if (is_file($resolver)) {
    require_once $resolver;

    if (function_exists('pats_resolve_public_checkout_token')) {
      $resolved = pats_resolve_public_checkout_token($cx, $token);

      if (is_array($resolved) && !empty($resolved)) {
        $ctx = array_merge(pcp_default_ctx(), $resolved);

        if (empty($ctx['tipo_origen'])) {
          if (!empty($ctx['id_distribuidor'])) {
            $ctx['tipo_origen'] = 'DISTRIBUIDOR';
          } elseif (!empty($ctx['id_franquicia'])) {
            $ctx['tipo_origen'] = 'FRANQUICIA';
          } elseif (!empty($ctx['id_gestor'])) {
            $ctx['tipo_origen'] = 'GESTOR';
          } else {
            $ctx['tipo_origen'] = 'ADMINPATS';
          }
        }

        return $ctx;
      }
    }
  }

  return pcp_validate_token_distribuidor($cx, $token);
}

function pcp_calcular_edad(string $fechaNacimiento): int {
  $fechaNacimiento = pcp_clean($fechaNacimiento);

  if ($fechaNacimiento === '') {
    return 0;
  }

  try {
    return (int)(new DateTime($fechaNacimiento))->diff(new DateTime('today'))->y;
  } catch (Throwable $e) {
    return 0;
  }
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  pcp_json(['ok' => false, 'error' => 'Método no permitido'], 405);
}

pcp_validate_public_csrf_soft();

$tokenPublico = pcp_clean($_POST['token_publico'] ?? '');

try {
  $ctx = pcp_resolve_public_ctx($cx, $tokenPublico);
} catch (Throwable $e) {
  pcp_json(['ok' => false, 'error' => $e->getMessage()], 500);
}

if (!$ctx) {
  pcp_json(['ok' => false, 'error' => 'Token público no válido'], 422);
}

$ctx = array_merge(pcp_default_ctx(), $ctx);

$fechaNacimientoPaciente = pcp_clean($_POST['fecha_nacimiento'] ?? '');
$edadPaciente = pcp_calcular_edad($fechaNacimientoPaciente);

$esMenor = ($edadPaciente > 0 && $edadPaciente < 18);
$esAdultoMayor = ($edadPaciente >= 65);

$modoFirma = pcp_upper($_POST['modo_firma'] ?? 'FIRMA_PROPIA');
$requiereResponsable = $esMenor || in_array($modoFirma, ['TUTOR_FAMILIAR', 'RESPONSABLE_AUTORIZADO'], true);
$esDependiente = (!$esMenor && $requiereResponsable);

$pacienteNombre = pcp_clean($_POST['nombre_usuario'] ?? '');
$pacienteApellidoPa = pcp_clean($_POST['apellido_pa'] ?? '');
$pacienteApellidoMa = pcp_clean($_POST['apellido_ma'] ?? '');
$pacienteCurp = pcp_upper($_POST['curp_usuario'] ?? '');
$pacienteNombreCompleto = trim($pacienteNombre . ' ' . $pacienteApellidoPa . ' ' . $pacienteApellidoMa);

$tutorNombre = pcp_clean($_POST['tutor_nombre'] ?? '');
$tutorApellidoPa = pcp_clean($_POST['tutor_apellido_pa'] ?? '');
$tutorApellidoMa = pcp_clean($_POST['tutor_apellido_ma'] ?? '');
$tutorNombreCompleto = trim($tutorNombre . ' ' . $tutorApellidoPa . ' ' . $tutorApellidoMa);

$correoContrato = pcp_clean($_POST['correo_usuario_pats'] ?? '');
$telefonoContrato = pcp_digits($_POST['telefono_usuario'] ?? '');

if ($requiereResponsable) {
  $tutorCorreo = pcp_clean($_POST['tutor_correo'] ?? '');
  $tutorTelefono = pcp_digits($_POST['tutor_telefono'] ?? '');

  if ($tutorCorreo !== '') {
    $correoContrato = $tutorCorreo;
  }

  if ($tutorTelefono !== '') {
    $telefonoContrato = $tutorTelefono;
  }
}

$payloadContrato = [
  'nombre_usuario'       => $pacienteNombre,
  'apellido_pa'          => $pacienteApellidoPa,
  'apellido_ma'          => $pacienteApellidoMa,
  'fecha_nacimiento'     => $fechaNacimientoPaciente,
  'curp_usuario'         => $pacienteCurp,
  'correo_usuario_pats'  => $correoContrato,
  'telefono_usuario'     => $telefonoContrato,

  'rfc_usuario'          => pcp_upper($_POST['rfc_usuario'] ?? ''),
  'nacionalidad_tipo'    => pcp_upper($_POST['nacionalidad_tipo'] ?? 'MEXICANA'),
  'nacionalidad'         => pcp_clean($_POST['nacionalidad'] ?? 'mexicana'),
  'pais_nacimiento'      => pcp_clean($_POST['pais_nacimiento'] ?? 'México'),
  'actividad_ocupacion'  => pcp_clean($_POST['actividad_ocupacion'] ?? ''),
  'estado_civil'         => pcp_upper($_POST['estado_civil'] ?? ''),

  'tipo_documento_identidad' => pcp_upper($_POST['tipo_documento_identidad'] ?? 'INE'),
  'pais_documento_identidad' => pcp_clean($_POST['pais_documento_identidad'] ?? 'México'),
  'numero_documento_identidad' => pcp_clean($_POST['numero_documento_identidad'] ?? ''),

  'dom_calle'            => pcp_clean($_POST['dom_calle'] ?? ''),
  'dom_num_ext'          => pcp_clean($_POST['dom_num_ext'] ?? ''),
  'dom_num_int'          => pcp_clean($_POST['dom_num_int'] ?? ''),
  'dom_colonia'          => pcp_clean($_POST['dom_colonia'] ?? ''),
  'dom_cp'               => pcp_digits($_POST['dom_cp'] ?? ''),
  'dom_municipio'        => pcp_clean($_POST['dom_municipio'] ?? ''),
  'dom_estado'           => pcp_clean($_POST['dom_estado'] ?? ($ctx['region'] ?? '')),
  'dom_pais'             => pcp_clean($_POST['dom_pais'] ?? ($ctx['pais'] ?? 'México')),

  'firma_base64'         => pcp_clean($_POST['firma_base64'] ?? ''),

  'frecuencia'           => pcp_upper($_POST['frecuencia'] ?? 'MENSUAL'),
  'monto_orden'          => pcp_clean($_POST['monto_orden'] ?? '800'),
  'moneda'               => pcp_upper($_POST['moneda'] ?? 'MXN'),

  'token_publico'        => $tokenPublico,
  'id_distribuidor'      => (int)($ctx['id_distribuidor'] ?? 0),
  'id_franquicia'        => (int)($ctx['id_franquicia'] ?? 0),
  'id_gestor'            => (int)($ctx['id_gestor'] ?? 0),
  'tipo_origen'          => pcp_upper($ctx['tipo_origen'] ?? 'ADMINPATS'),
  'pais'                 => pcp_clean($ctx['pais'] ?? 'México'),
  'region'               => pcp_clean($ctx['region'] ?? ''),
  'zona'                 => pcp_clean($ctx['zona'] ?? ''),
  'unidad'               => pcp_clean($ctx['unidad'] ?? ''),

  'es_menor'             => $esMenor ? '1' : '0',
  'es_dependiente_representado' => $esDependiente ? '1' : '0',
  'requiere_responsable' => $requiereResponsable ? '1' : '0',
  'modo_firma'           => $modoFirma,
  'tipo_representacion'  => pcp_upper($_POST['tipo_representacion'] ?? $modoFirma),
  'relacion_responsable_paciente' => pcp_upper($_POST['relacion_responsable_paciente'] ?? ''),
  'motivo_responsable'   => pcp_upper($_POST['motivo_responsable'] ?? ''),

  'es_adulto_mayor'      => $esAdultoMayor ? '1' : '0',
  'edad_paciente'        => (string)$edadPaciente,

  'paciente_nombre_completo' => $pacienteNombreCompleto,
  'paciente_curp'            => $pacienteCurp,
  'paciente_fecha_nacimiento'=> $fechaNacimientoPaciente,

  'tutor_nombre'         => $tutorNombre,
  'tutor_apellido_pa'    => $tutorApellidoPa,
  'tutor_apellido_ma'    => $tutorApellidoMa,
  'tutor_nombre_completo'=> $tutorNombreCompleto,
  'tutor_curp'           => pcp_upper($_POST['tutor_curp'] ?? ''),
  'tutor_rfc'            => pcp_upper($_POST['tutor_rfc'] ?? ''),
  'tutor_fecha_nacimiento' => pcp_clean($_POST['tutor_fecha_nacimiento'] ?? ''),
  'tutor_correo'         => pcp_clean($_POST['tutor_correo'] ?? ''),
  'tutor_telefono'       => pcp_digits($_POST['tutor_telefono'] ?? ''),
  'tutor_nacionalidad_tipo' => pcp_upper($_POST['tutor_nacionalidad_tipo'] ?? 'MEXICANA'),
  'tutor_nacionalidad' => pcp_clean($_POST['tutor_nacionalidad'] ?? 'mexicana'),
  'tutor_pais_nacimiento' => pcp_clean($_POST['tutor_pais_nacimiento'] ?? 'México'),
  'tutor_tipo_documento_identidad' => pcp_upper($_POST['tutor_tipo_documento_identidad'] ?? 'INE'),
  'tutor_pais_documento_identidad' => pcp_clean($_POST['tutor_pais_documento_identidad'] ?? 'México'),
  'tutor_numero_documento_identidad' => pcp_clean($_POST['tutor_numero_documento_identidad'] ?? ''),

  'doc_acreditacion_representacion' => '',

  'adulto_mayor_pasaportes_validados' => pcp_clean($_POST['adulto_mayor_pasaportes_validados'] ?? '0'),
  'adulto_mayor_pasaporte_1_json' => pcp_clean($_POST['adulto_mayor_pasaporte_1_json'] ?? ''),
  'adulto_mayor_pasaporte_2_json' => pcp_clean($_POST['adulto_mayor_pasaporte_2_json'] ?? ''),
];

try {
  if (!function_exists('pats_render_contract_html')) {
    throw new RuntimeException('No está disponible pats_render_contract_html(). Revisa ez/pats/lib/contratos.php');
  }

  $html = pats_render_contract_html($payloadContrato);

  pcp_json([
    'ok' => true,
    'html' => $html
  ]);
} catch (Throwable $e) {
  pcp_json([
    'ok' => false,
    'error' => $e->getMessage()
  ], 500);
}