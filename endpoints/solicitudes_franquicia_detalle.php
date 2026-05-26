<?php
/*
ez/patsfin/endpoints/solicitudes_franquicia_detalle.php
*/
require_once __DIR__ . '/bootstrap.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
  fin_json(['ok' => false, 'error' => 'Método no permitido'], 405);
}

$idSolicitud = (int)($_GET['id_solicitud'] ?? 0);

if ($idSolicitud <= 0) {
  fin_json(['ok' => false, 'error' => 'Falta id_solicitud válido'], 422);
}

/* =========================================================
   HELPERS LOCALES
========================================================= */
function sfd_clean($v): string {
  return trim((string)($v ?? ''));
}

function sfd_bool($v): bool {
  return !empty($v) && trim((string)$v) !== '';
}

function sfd_file_public_url(string $path): string {
  $path = trim($path);

  if ($path === '') {
    return '';
  }

  $pathNorm = str_replace('\\', '/', $path);
  $lower = strtolower($pathNorm);

  /*
    Local Windows:
    C:/wamp64/www/EZHS/EZHS/ez/patsfin/uploads/...
    -> uploads/...
  */
  $patsfinPos = strpos($lower, '/ez/patsfin/');
  if ($patsfinPos !== false) {
    return substr($pathNorm, $patsfinPos + strlen('/ez/patsfin/'));
  }

  /*
    Local Windows / servidor:
    .../ez/pats/uploads/...
    -> ../pats/uploads/...
  */
  $patsPos = strpos($lower, '/ez/pats/');
  if ($patsPos !== false) {
    return '../pats/' . substr($pathNorm, $patsPos + strlen('/ez/pats/'));
  }

  /*
    Servidor Linux:
    /home/.../patsfin/uploads/...
    -> uploads/...
  */
  $patsfinUploadsPos = strpos($lower, '/patsfin/uploads/');
  if ($patsfinUploadsPos !== false) {
    return substr($pathNorm, $patsfinUploadsPos + strlen('/patsfin/'));
  }

  /*
    Servidor Linux:
    /home/.../pats/uploads/...
    -> ../pats/uploads/...
  */
  $patsUploadsPos = strpos($lower, '/pats/uploads/');
  if ($patsUploadsPos !== false) {
    return '../pats/' . substr($pathNorm, $patsUploadsPos + strlen('/pats/'));
  }

  if (str_starts_with($lower, 'uploads/')) {
    return $pathNorm;
  }

  if (str_starts_with($lower, '../pats/')) {
    return $pathNorm;
  }

  return $pathNorm;
}

function sfd_table_exists(mysqli $cx, string $table): bool {
  if (function_exists('fin_table_exists')) {
    return fin_table_exists($cx, $table);
  }

  $tableEsc = fin_esc($cx, $table);
  $rs = $cx->query("SHOW TABLES LIKE '{$tableEsc}'");

  if (!$rs) {
    return false;
  }

  $exists = $rs->num_rows > 0;
  $rs->free();

  return $exists;
}

function sfd_columns(mysqli $cx, string $table): array {
  $cols = [];

  $rs = $cx->query("SHOW COLUMNS FROM `{$table}`");

  if ($rs) {
    while ($row = $rs->fetch_assoc()) {
      $field = (string)($row['Field'] ?? '');
      if ($field !== '') {
        $cols[] = $field;
      }
    }
    $rs->free();
  }

  return $cols;
}

function sfd_documentos_requeridos(string $tipoPersona): array {
  $tipoPersona = strtoupper(trim($tipoPersona));

  $docs = [
    'INE',
    'CEDULA_FISCAL',
    'COMPROBANTE_DOMICILIO',
    'CARATULA_BANCARIA',
    'COMPROBANTE_PAGO'
  ];

  if ($tipoPersona === 'FISICA') {
    $docs[] = 'CURP';
  }

  if ($tipoPersona === 'MORAL') {
    $docs[] = 'ACTA_CONSTITUTIVA';
    $docs[] = 'PODER_NOTARIAL';
  }

  return $docs;
}

/* =========================================================
   VALIDAR TABLAS
========================================================= */
if (!sfd_table_exists($cx, 'pats_solicitudes_franquicia')) {
  fin_json(['ok' => false, 'error' => 'No existe la tabla pats_solicitudes_franquicia'], 500);
}

/* =========================================================
   SOLICITUD
========================================================= */
$item = fin_one($cx, "
  SELECT
    s.id_solicitud,
    s.id_franquicia_generada,
    s.id_gestor,
    s.token_origen,
    s.origen_solicitud,

    s.user_solicita,
    s.user_valida,
    s.user_autoriza,

    s.pais,
    s.region,
    s.zona,
    s.unidad,

    s.nombre_comercial,
    s.tipo_persona,
    s.nombre_titular,
    s.razon_social,
    s.rfc,
    s.telefono,
    s.correo,

    s.direccion,
    s.calle,
    s.numero_exterior,
    s.numero_interior,
    s.colonia,
    s.codigo_postal,
    s.referencias_direccion,

    s.titularidad_tipo,
    s.porcentaje_titular_real,
    s.porcentaje_adminpats,

    s.banco,
    s.numero_cuenta,
    s.clabe,
    s.titular_cuenta,

    s.modalidad_pago,
    s.valor_total,
    s.enganche,
    s.saldo_financiado,
    s.plazo_meses,
    s.periodicidad,
    s.fecha_inicio,
    s.fecha_primer_vencimiento,

    s.contrato_admin_path,
    s.contrato_firmado_path,

    s.contrato_digital_html,
    s.contrato_digital_hash,
    s.contrato_digital_firmado_at,
    s.firma_digital_nombre,
    s.firma_digital_rfc,
    s.firma_digital_ip,
    s.firma_digital_user_agent,
    s.firma_digital_data,

    s.fotografia_path,
    s.fotografia_nombre_original,
    s.fotografia_mime_type,
    s.fotografia_size_kb,
    s.fecha_fotografia,

    s.estatus,
    s.motivo_rechazo,
    s.observaciones_admin,
    s.observaciones_solicitante,

    s.fecha_envio_contrato,
    s.fecha_carga_firmado,
    s.fecha_autorizacion,
    s.fecha_conversion_alta,

    s.activo,
    s.created_at,
    s.updated_at,

    g.nombre_gestor,
    g.correo AS correo_gestor,
    g.telefono AS telefono_gestor,

    us.nombre AS nombre_solicita,
    uv.nombre AS nombre_valida,
    ua.nombre AS nombre_autoriza

  FROM pats_solicitudes_franquicia s
  LEFT JOIN pats_gestores g
    ON g.id_gestor = s.id_gestor
  LEFT JOIN pats_users us
    ON us.id = s.user_solicita
  LEFT JOIN pats_users uv
    ON uv.id = s.user_valida
  LEFT JOIN pats_users ua
    ON ua.id = s.user_autoriza
  WHERE s.id_solicitud = {$idSolicitud}
    AND s.activo = 1
  LIMIT 1
");

if (!$item) {
  fin_json(['ok' => false, 'error' => 'Solicitud de franquicia no encontrada'], 404);
}

/* =========================================================
   DIRECCIÓN COMPLETA
========================================================= */
$direccionCompleta = sfd_clean($item['direccion'] ?? '');

if ($direccionCompleta === '') {
  $direccionCompleta = trim(implode(' ', array_filter([
    sfd_clean($item['calle'] ?? ''),
    sfd_clean($item['numero_exterior'] ?? '') !== '' ? 'No. ' . sfd_clean($item['numero_exterior'] ?? '') : '',
    sfd_clean($item['numero_interior'] ?? '') !== '' ? 'Int. ' . sfd_clean($item['numero_interior'] ?? '') : '',
    sfd_clean($item['colonia'] ?? '') !== '' ? 'Col. ' . sfd_clean($item['colonia'] ?? '') : '',
    sfd_clean($item['zona'] ?? ''),
    sfd_clean($item['region'] ?? ''),
    sfd_clean($item['codigo_postal'] ?? '') !== '' ? 'CP ' . sfd_clean($item['codigo_postal'] ?? '') : '',
    'México'
  ])));
}

$item['direccion_completa'] = $direccionCompleta;

/* =========================================================
   DOCUMENTOS
========================================================= */
$documentos = [];

if (sfd_table_exists($cx, 'pats_solicitudes_franquicia_documentos')) {
  $documentos = fin_all($cx, "
    SELECT
      id_documento_solicitud,
      id_solicitud,
      tipo_documento,
      origen_documento,
      archivo_path,
      archivo_nombre_original,
      mime_type,
      size_kb,
      hash_archivo,
      vigente,
      observaciones,
      user_alta,
      created_at,
      updated_at
    FROM pats_solicitudes_franquicia_documentos
    WHERE id_solicitud = {$idSolicitud}
      AND vigente = 1
    ORDER BY
      FIELD(
        tipo_documento,
        'INE',
        'CURP',
        'CEDULA_FISCAL',
        'COMPROBANTE_DOMICILIO',
        'CARATULA_BANCARIA',
        'ACTA_CONSTITUTIVA',
        'PODER_NOTARIAL',
        'CONTRATO_DIGITAL_PDF',
        'CONTRATO_DIGITAL_HTML',
        'CONTRATO_FIRMADO',
        'FOTOGRAFIA_SOLICITANTE',
        'FIRMA_DIGITAL_IMAGEN',
        'COMPROBANTE_PAGO',
        'CONTRATO_ADMIN'
      ),
      id_documento_solicitud DESC
  ");
}

$docsNormalizados = [];
$docsPorTipo = [];

foreach ($documentos as $doc) {
  $tipo = strtoupper(sfd_clean($doc['tipo_documento'] ?? ''));
  $path = sfd_clean($doc['archivo_path'] ?? '');

  $doc['archivo_url'] = sfd_file_public_url($path);

  $docsNormalizados[] = $doc;

  if ($tipo !== '' && !isset($docsPorTipo[$tipo])) {
    $docsPorTipo[$tipo] = $doc;
  }
}

/* =========================================================
   HISTORIAL
   Compatible con distintas estructuras
========================================================= */
$historial = [];

if (sfd_table_exists($cx, 'pats_solicitudes_franquicia_historial')) {
  $histCols = sfd_columns($cx, 'pats_solicitudes_franquicia_historial');

  $orderCol = '';

  foreach (['id_historial', 'id_historial_solicitud', 'id_evento', 'id', 'created_at', 'fecha_evento'] as $candidate) {
    if (in_array($candidate, $histCols, true)) {
      $orderCol = $candidate;
      break;
    }
  }

  $whereSolicitud = in_array('id_solicitud', $histCols, true)
    ? "WHERE id_solicitud = {$idSolicitud}"
    : "";

  $orderSql = $orderCol !== ''
    ? "ORDER BY `{$orderCol}` ASC"
    : "";

  $historialRaw = fin_all($cx, "
    SELECT *
    FROM pats_solicitudes_franquicia_historial
    {$whereSolicitud}
    {$orderSql}
  ");

  foreach ($historialRaw as $h) {
    $historial[] = [
      'id_historial' => $h['id_historial']
        ?? $h['id_historial_solicitud']
        ?? $h['id_evento']
        ?? $h['id']
        ?? null,

      'id_solicitud' => $h['id_solicitud'] ?? $idSolicitud,

      'evento_tipo' => $h['evento_tipo']
        ?? $h['tipo_evento']
        ?? $h['accion']
        ?? $h['evento']
        ?? '',

      'estatus_anterior' => $h['estatus_anterior']
        ?? $h['estado_anterior']
        ?? null,

      'estatus_nuevo' => $h['estatus_nuevo']
        ?? $h['estado_nuevo']
        ?? null,

      'payload_json' => $h['payload_json']
        ?? $h['payload']
        ?? null,

      'observaciones' => $h['observaciones']
        ?? $h['comentario']
        ?? null,

      'user_evento' => $h['user_evento']
        ?? $h['user_alta']
        ?? $h['usuario']
        ?? null,

      'fecha_evento' => $h['fecha_evento']
        ?? $h['created_at']
        ?? $h['fecha']
        ?? null,

      'created_at' => $h['created_at']
        ?? $h['fecha_evento']
        ?? null,

      '_raw' => $h
    ];
  }
}

/* =========================================================
   FLAGS / VALIDACIÓN
========================================================= */
$tipoPersona = strtoupper(sfd_clean($item['tipo_persona'] ?? 'FISICA'));
if (!in_array($tipoPersona, ['FISICA', 'MORAL'], true)) {
  $tipoPersona = 'FISICA';
}

$titularidadTipo = strtoupper(sfd_clean($item['titularidad_tipo'] ?? 'INDIVIDUAL'));
if (!in_array($titularidadTipo, ['INDIVIDUAL', 'COMPARTIDA'], true)) {
  $titularidadTipo = 'INDIVIDUAL';
}

$contratoDigitalFirmado = (
  sfd_bool($item['contrato_digital_hash'] ?? '') &&
  sfd_bool($item['contrato_digital_firmado_at'] ?? '')
);

$fotografiaCapturada = (
  sfd_bool($item['fotografia_path'] ?? '') ||
  isset($docsPorTipo['FOTOGRAFIA_SOLICITANTE'])
);

$contratoFirmadoHistorico = (
  sfd_bool($item['contrato_firmado_path'] ?? '') ||
  isset($docsPorTipo['CONTRATO_FIRMADO'])
);

$puedeAutorizar = (
  ($contratoDigitalFirmado && $fotografiaCapturada) ||
  $contratoFirmadoHistorico
);

$documentosRequeridos = sfd_documentos_requeridos($tipoPersona);

$documentosChecklist = [];

foreach ($documentosRequeridos as $tipoReq) {
  $documentosChecklist[] = [
    'tipo_documento' => $tipoReq,
    'requerido' => true,
    'cargado' => isset($docsPorTipo[$tipoReq]),
    'documento' => $docsPorTipo[$tipoReq] ?? null
  ];
}

/* =========================================================
   NORMALIZACIÓN FINAL
========================================================= */
$item['tipo_persona'] = $tipoPersona;
$item['titularidad_tipo'] = $titularidadTipo;

$item['contrato_admin_url'] = sfd_file_public_url((string)($item['contrato_admin_path'] ?? ''));
$item['contrato_firmado_url'] = sfd_file_public_url((string)($item['contrato_firmado_path'] ?? ''));
$item['fotografia_url'] = sfd_file_public_url((string)($item['fotografia_path'] ?? ''));

$item['firma_digital'] = [
  'contrato_digital_firmado' => $contratoDigitalFirmado,
  'contrato_digital_hash' => (string)($item['contrato_digital_hash'] ?? ''),
  'contrato_digital_firmado_at' => (string)($item['contrato_digital_firmado_at'] ?? ''),
  'firma_digital_nombre' => (string)($item['firma_digital_nombre'] ?? ''),
  'firma_digital_rfc' => (string)($item['firma_digital_rfc'] ?? ''),
  'firma_digital_ip' => (string)($item['firma_digital_ip'] ?? ''),
  'firma_digital_user_agent' => (string)($item['firma_digital_user_agent'] ?? ''),
  'firma_digital_data' => (string)($item['firma_digital_data'] ?? ''),
  'tiene_html' => sfd_bool($item['contrato_digital_html'] ?? ''),
  'contrato_digital_html' => (string)($item['contrato_digital_html'] ?? '')
];

$item['fotografia'] = [
  'capturada' => $fotografiaCapturada,
  'path' => (string)($item['fotografia_path'] ?? ''),
  'url' => $item['fotografia_url'],
  'nombre_original' => (string)($item['fotografia_nombre_original'] ?? ''),
  'mime_type' => (string)($item['fotografia_mime_type'] ?? ''),
  'size_kb' => (int)($item['fotografia_size_kb'] ?? 0),
  'fecha_fotografia' => (string)($item['fecha_fotografia'] ?? '')
];

$item['validacion'] = [
  'contrato_digital_firmado' => $contratoDigitalFirmado,
  'fotografia_capturada' => $fotografiaCapturada,
  'contrato_firmado_historico' => $contratoFirmadoHistorico,
  'puede_autorizar' => $puedeAutorizar,
  'documentos_requeridos' => $documentosRequeridos,
  'documentos_checklist' => $documentosChecklist,
  'tipo_persona' => $tipoPersona,
  'titularidad_tipo' => $titularidadTipo,
  'titularidad_compartida' => $titularidadTipo === 'COMPARTIDA',
  'porcentaje_titular_real' => (float)($item['porcentaje_titular_real'] ?? 100),
  'porcentaje_adminpats' => (float)($item['porcentaje_adminpats'] ?? 0),
  'tiene_gestor' => (int)($item['id_gestor'] ?? 0) > 0
];

fin_json([
  'ok' => true,
  'item' => array_merge($item, [
    'documentos' => $docsNormalizados,
    'documentos_por_tipo' => $docsPorTipo,
    'historial' => $historial
  ])
]);