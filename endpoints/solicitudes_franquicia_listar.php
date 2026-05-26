<?php
/*
ez/patsfin/endpoints/solicitudes_franquicia_listar.php
*/
require_once __DIR__ . '/bootstrap.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
  fin_json(['ok' => false, 'error' => 'Método no permitido'], 405);
}

/* =========================================================
   HELPERS
========================================================= */
function sfl_clean($v): string {
  return trim((string)($v ?? ''));
}

function sfl_bool($v): bool {
  return !empty($v) && trim((string)$v) !== '';
}

function sfl_file_public_url(string $path): string {
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

/* =========================================================
   VALIDAR TABLAS
========================================================= */
$tableExists = true;

if (function_exists('fin_table_exists')) {
  $tableExists = fin_table_exists($cx, 'pats_solicitudes_franquicia');
} else {
  $rs = $cx->query("SHOW TABLES LIKE 'pats_solicitudes_franquicia'");
  $tableExists = $rs && $rs->num_rows > 0;
  if ($rs) $rs->free();
}

if (!$tableExists) {
  fin_json([
    'ok' => true,
    'items' => [],
    'pagination' => [
      'page' => 1,
      'limit' => 50,
      'offset' => 0,
      'total' => 0,
      'pages' => 1
    ],
    'kpis' => [
      'total' => 0,
      'enviada' => 0,
      'validando_documentos' => 0,
      'observada' => 0,
      'validada_documentalmente' => 0,
      'contrato_digital_firmado' => 0,
      'autorizada' => 0,
      'rechazada' => 0,
      'convertida_alta' => 0,
      'pendientes' => 0,
      'contratos_digitales' => 0,
      'fotografias' => 0
    ],
    'catalogos' => [
      'regiones' => [],
      'estatus' => [],
      'tipos_persona' => ['FISICA', 'MORAL'],
      'titularidades' => ['INDIVIDUAL', 'COMPARTIDA']
    ]
  ]);
}

/* =========================================================
   FILTROS
========================================================= */
$estatus = strtoupper(sfl_clean($_GET['estatus'] ?? ''));
$q       = sfl_clean($_GET['q'] ?? '');
$region  = strtoupper(sfl_clean($_GET['region'] ?? ''));
$tipoPersona = strtoupper(sfl_clean($_GET['tipo_persona'] ?? ''));
$titularidadTipo = strtoupper(sfl_clean($_GET['titularidad_tipo'] ?? ''));
$soloPendientes = (int)($_GET['solo_pendientes'] ?? 0);

$page = max(1, (int)($_GET['page'] ?? 1));

$limit = (int)($_GET['limit'] ?? 50);
if ($limit <= 0) $limit = 50;
if ($limit > 200) $limit = 200;

$offset = ($page - 1) * $limit;

$where = [
  "s.activo = 1"
];

if ($estatus !== '') {
  $where[] = "UPPER(TRIM(s.estatus)) = '" . fin_esc($cx, $estatus) . "'";
}

if ($region !== '') {
  $where[] = "UPPER(TRIM(s.region)) = '" . fin_esc($cx, $region) . "'";
}

if ($tipoPersona !== '' && in_array($tipoPersona, ['FISICA', 'MORAL'], true)) {
  $where[] = "UPPER(TRIM(COALESCE(s.tipo_persona,'FISICA'))) = '" . fin_esc($cx, $tipoPersona) . "'";
}

if ($titularidadTipo !== '' && in_array($titularidadTipo, ['INDIVIDUAL', 'COMPARTIDA'], true)) {
  $where[] = "UPPER(TRIM(COALESCE(s.titularidad_tipo,'INDIVIDUAL'))) = '" . fin_esc($cx, $titularidadTipo) . "'";
}

if ($q !== '') {
  $qEsc = fin_esc($cx, $q);

  $where[] = "(
    s.nombre_comercial LIKE '%{$qEsc}%'
    OR s.nombre_titular LIKE '%{$qEsc}%'
    OR s.razon_social LIKE '%{$qEsc}%'
    OR s.rfc LIKE '%{$qEsc}%'
    OR s.correo LIKE '%{$qEsc}%'
    OR s.telefono LIKE '%{$qEsc}%'
    OR g.nombre_gestor LIKE '%{$qEsc}%'
  )";
}

if ($soloPendientes === 1) {
  $where[] = "UPPER(TRIM(s.estatus)) NOT IN ('CONVERTIDA_ALTA','RECHAZADA')";
}

$whereSql = implode("\n    AND ", $where);

/* =========================================================
   TOTAL
========================================================= */
$totalRow = fin_one($cx, "
  SELECT COUNT(*) AS total
  FROM pats_solicitudes_franquicia s
  LEFT JOIN pats_gestores g
    ON g.id_gestor = s.id_gestor
  WHERE {$whereSql}
");

$total = (int)($totalRow['total'] ?? 0);

/* =========================================================
   LISTADO
========================================================= */
$items = fin_all($cx, "
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

    s.contrato_digital_hash,
    s.contrato_digital_firmado_at,
    s.firma_digital_nombre,
    s.firma_digital_rfc,

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

    s.created_at,
    s.updated_at,

    g.nombre_gestor,
    g.correo AS correo_gestor,
    g.telefono AS telefono_gestor,

    (
      SELECT COUNT(*)
      FROM pats_solicitudes_franquicia_documentos d
      WHERE d.id_solicitud = s.id_solicitud
        AND d.vigente = 1
    ) AS total_documentos,

    (
      SELECT COUNT(*)
      FROM pats_solicitudes_franquicia_documentos d
      WHERE d.id_solicitud = s.id_solicitud
        AND d.vigente = 1
        AND UPPER(TRIM(d.tipo_documento)) IN ('CONTRATO_FIRMADO','CONTRATO_DIGITAL_PDF','CONTRATO_DIGITAL_HTML')
    ) AS total_contrato_docs,

    (
      SELECT COUNT(*)
      FROM pats_solicitudes_franquicia_documentos d
      WHERE d.id_solicitud = s.id_solicitud
        AND d.vigente = 1
        AND UPPER(TRIM(d.tipo_documento)) IN ('FOTOGRAFIA_SOLICITANTE')
    ) AS total_fotografia_docs

  FROM pats_solicitudes_franquicia s
  LEFT JOIN pats_gestores g
    ON g.id_gestor = s.id_gestor
  WHERE {$whereSql}
  ORDER BY
    CASE UPPER(TRIM(s.estatus))
      WHEN 'ENVIADA' THEN 1
      WHEN 'VALIDANDO_DOCUMENTOS' THEN 2
      WHEN 'OBSERVADA' THEN 3
      WHEN 'VALIDADA_DOCUMENTALMENTE' THEN 4
      WHEN 'CONTRATO_DIGITAL_FIRMADO' THEN 5
      WHEN 'CONTRATO_FIRMADO_CARGADO' THEN 6
      WHEN 'AUTORIZADA' THEN 7
      WHEN 'RECHAZADA' THEN 8
      WHEN 'CONVERTIDA_ALTA' THEN 9
      ELSE 20
    END ASC,
    s.id_solicitud DESC
  LIMIT {$limit}
  OFFSET {$offset}
");

/* =========================================================
   NORMALIZACIÓN
========================================================= */
$itemsOut = [];

foreach ($items as $row) {
  $tipo = strtoupper(trim((string)($row['tipo_persona'] ?? 'FISICA')));
  if (!in_array($tipo, ['FISICA', 'MORAL'], true)) {
    $tipo = 'FISICA';
  }

  $titularidad = strtoupper(trim((string)($row['titularidad_tipo'] ?? 'INDIVIDUAL')));
  if (!in_array($titularidad, ['INDIVIDUAL', 'COMPARTIDA'], true)) {
    $titularidad = 'INDIVIDUAL';
  }

  $estatusRow = strtoupper(trim((string)($row['estatus'] ?? 'ENVIADA')));

  $contratoDigitalFirmado = (
    sfl_bool($row['contrato_digital_hash'] ?? '') &&
    sfl_bool($row['contrato_digital_firmado_at'] ?? '')
  );

  $fotografiaCapturada = (
    sfl_bool($row['fotografia_path'] ?? '') ||
    (int)($row['total_fotografia_docs'] ?? 0) > 0
  );

  $contratoFirmadoHistorico = (
    sfl_bool($row['contrato_firmado_path'] ?? '') ||
    (int)($row['total_contrato_docs'] ?? 0) > 0
  );

  $puedeAutorizar = (
    ($contratoDigitalFirmado && $fotografiaCapturada) ||
    $contratoFirmadoHistorico
  );

  $direccionCompleta = sfl_clean($row['direccion'] ?? '');

  if ($direccionCompleta === '') {
    $direccionCompleta = trim(implode(' ', array_filter([
      sfl_clean($row['calle'] ?? ''),
      sfl_clean($row['numero_exterior'] ?? '') !== '' ? 'No. ' . sfl_clean($row['numero_exterior'] ?? '') : '',
      sfl_clean($row['numero_interior'] ?? '') !== '' ? 'Int. ' . sfl_clean($row['numero_interior'] ?? '') : '',
      sfl_clean($row['colonia'] ?? '') !== '' ? 'Col. ' . sfl_clean($row['colonia'] ?? '') : '',
      sfl_clean($row['zona'] ?? ''),
      sfl_clean($row['region'] ?? ''),
      sfl_clean($row['codigo_postal'] ?? '') !== '' ? 'CP ' . sfl_clean($row['codigo_postal'] ?? '') : '',
      'México'
    ])));
  }

  $row['tipo_persona'] = $tipo;
  $row['titularidad_tipo'] = $titularidad;
  $row['estatus'] = $estatusRow;

  $row['direccion_completa'] = $direccionCompleta;

  $row['contrato_admin_url'] = sfl_file_public_url((string)($row['contrato_admin_path'] ?? ''));
  $row['contrato_firmado_url'] = sfl_file_public_url((string)($row['contrato_firmado_path'] ?? ''));
  $row['fotografia_url'] = sfl_file_public_url((string)($row['fotografia_path'] ?? ''));

  $row['flags'] = [
    'contrato_digital_firmado' => $contratoDigitalFirmado,
    'fotografia_capturada' => $fotografiaCapturada,
    'contrato_firmado_historico' => $contratoFirmadoHistorico,
    'puede_autorizar' => $puedeAutorizar,
    'convertida' => !empty($row['id_franquicia_generada']) || $estatusRow === 'CONVERTIDA_ALTA',
    'rechazada' => $estatusRow === 'RECHAZADA',
    'observada' => $estatusRow === 'OBSERVADA',
    'tiene_gestor' => (int)($row['id_gestor'] ?? 0) > 0,
    'titularidad_compartida' => $titularidad === 'COMPARTIDA'
  ];

  $row['resumen_firma'] = [
    'hash' => (string)($row['contrato_digital_hash'] ?? ''),
    'fecha' => (string)($row['contrato_digital_firmado_at'] ?? ''),
    'nombre' => (string)($row['firma_digital_nombre'] ?? ''),
    'rfc' => (string)($row['firma_digital_rfc'] ?? '')
  ];

  $itemsOut[] = $row;
}

/* =========================================================
   KPIS
========================================================= */
$kpisRows = fin_all($cx, "
  SELECT
    UPPER(TRIM(estatus)) AS estatus,
    COUNT(*) AS total
  FROM pats_solicitudes_franquicia
  WHERE activo = 1
  GROUP BY UPPER(TRIM(estatus))
");

$kpis = [
  'total' => 0,
  'enviada' => 0,
  'validando_documentos' => 0,
  'observada' => 0,
  'validada_documentalmente' => 0,
  'contrato_digital_firmado' => 0,
  'contrato_firmado_cargado' => 0,
  'autorizada' => 0,
  'rechazada' => 0,
  'convertida_alta' => 0,
  'pendientes' => 0,
  'contratos_digitales' => 0,
  'fotografias' => 0
];

foreach ($kpisRows as $kr) {
  $key = strtolower(trim((string)($kr['estatus'] ?? '')));
  $val = (int)($kr['total'] ?? 0);

  $kpis['total'] += $val;

  if (isset($kpis[$key])) {
    $kpis[$key] = $val;
  }
}

$kpis['pendientes'] =
  $kpis['total']
  - $kpis['rechazada']
  - $kpis['convertida_alta'];

$firmaStats = fin_one($cx, "
  SELECT
    SUM(CASE WHEN contrato_digital_hash IS NOT NULL AND TRIM(contrato_digital_hash) <> ''
              AND contrato_digital_firmado_at IS NOT NULL
             THEN 1 ELSE 0 END) AS contratos_digitales,
    SUM(CASE WHEN fotografia_path IS NOT NULL AND TRIM(fotografia_path) <> ''
             THEN 1 ELSE 0 END) AS fotografias
  FROM pats_solicitudes_franquicia
  WHERE activo = 1
");

$kpis['contratos_digitales'] = (int)($firmaStats['contratos_digitales'] ?? 0);
$kpis['fotografias'] = (int)($firmaStats['fotografias'] ?? 0);

/* =========================================================
   CATÁLOGOS
========================================================= */
$regiones = fin_all($cx, "
  SELECT DISTINCT UPPER(TRIM(region)) AS region
  FROM pats_solicitudes_franquicia
  WHERE activo = 1
    AND TRIM(COALESCE(region,'')) <> ''
  ORDER BY region ASC
");

$estatusDisponibles = fin_all($cx, "
  SELECT DISTINCT UPPER(TRIM(estatus)) AS estatus
  FROM pats_solicitudes_franquicia
  WHERE activo = 1
    AND TRIM(COALESCE(estatus,'')) <> ''
  ORDER BY estatus ASC
");

fin_json([
  'ok' => true,
  'items' => $itemsOut,
  'pagination' => [
    'page' => $page,
    'limit' => $limit,
    'offset' => $offset,
    'total' => $total,
    'pages' => $limit > 0 ? (int)ceil($total / $limit) : 1
  ],
  'kpis' => $kpis,
  'catalogos' => [
    'regiones' => array_values(array_map(static fn($r) => $r['region'], $regiones)),
    'estatus' => array_values(array_map(static fn($r) => $r['estatus'], $estatusDisponibles)),
    'tipos_persona' => ['FISICA', 'MORAL'],
    'titularidades' => ['INDIVIDUAL', 'COMPARTIDA']
  ]
]);