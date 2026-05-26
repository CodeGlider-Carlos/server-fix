<?php
/*
ez/patsfin/endpoints/solicitud_franquicia_generar_contrato_digital.php
*/
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../lib/contratos_digitales.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
  fin_json(['ok' => false, 'error' => 'Método no permitido'], 405);
}

$idSolicitud = (int)($_POST['id_solicitud'] ?? 0);
$userEvento = (int)($_SESSION['id'] ?? 0);

if ($idSolicitud <= 0) {
  fin_json(['ok' => false, 'error' => 'Falta id_solicitud válido'], 422);
}

if (!function_exists('fin_table_exists') || !fin_table_exists($cx, 'pats_solicitudes_franquicia')) {
  fin_json(['ok' => false, 'error' => 'No existe la tabla pats_solicitudes_franquicia'], 500);
}

$sol = fin_one($cx, "
  SELECT
    s.*,
    g.nombre_gestor,
    g.correo AS correo_gestor,
    g.telefono AS telefono_gestor
  FROM pats_solicitudes_franquicia s
  LEFT JOIN pats_gestores g
    ON g.id_gestor = s.id_gestor
  WHERE s.id_solicitud = {$idSolicitud}
    AND s.activo = 1
  LIMIT 1
");

if (!$sol) {
  fin_json(['ok' => false, 'error' => 'Solicitud de franquicia no encontrada'], 404);
}

$estatus = strtoupper(trim((string)($sol['estatus'] ?? '')));

if ($estatus === 'CONVERTIDA_ALTA') {
  fin_json([
    'ok' => false,
    'error' => 'La solicitud ya fue convertida a alta. No se puede regenerar contrato.'
  ], 422);
}

if ($estatus === 'RECHAZADA') {
  fin_json([
    'ok' => false,
    'error' => 'La solicitud está rechazada. No se puede generar contrato.'
  ], 422);
}

$yaFirmado = (
  !empty($sol['contrato_digital_hash'])
  && !empty($sol['contrato_digital_firmado_at'])
);

if ($yaFirmado) {
  fin_json([
    'ok' => true,
    'already_signed' => true,
    'message' => 'El contrato ya está firmado digitalmente. No se regeneró.',
    'contrato_digital_html' => (string)($sol['contrato_digital_html'] ?? ''),
    'contrato_digital_hash' => (string)($sol['contrato_digital_hash'] ?? ''),
    'contrato_digital_firmado_at' => (string)($sol['contrato_digital_firmado_at'] ?? '')
  ]);
}

$direccionCompleta = trim((string)($sol['direccion'] ?? ''));

if ($direccionCompleta === '') {
  $direccionCompleta = trim(implode(' ', array_filter([
    trim((string)($sol['calle'] ?? '')),
    trim((string)($sol['numero_exterior'] ?? '')) !== '' ? 'No. ' . trim((string)($sol['numero_exterior'] ?? '')) : '',
    trim((string)($sol['numero_interior'] ?? '')) !== '' ? 'Int. ' . trim((string)($sol['numero_interior'] ?? '')) : '',
    trim((string)($sol['colonia'] ?? '')) !== '' ? 'Col. ' . trim((string)($sol['colonia'] ?? '')) : '',
    trim((string)($sol['zona'] ?? '')),
    trim((string)($sol['region'] ?? '')),
    trim((string)($sol['codigo_postal'] ?? '')) !== '' ? 'CP ' . trim((string)($sol['codigo_postal'] ?? '')) : '',
    'México'
  ])));
}

$sol['direccion_completa'] = $direccionCompleta;

$docs = [];

if (fin_table_exists($cx, 'pats_solicitudes_franquicia_documentos')) {
  $docs = fin_all($cx, "
    SELECT *
    FROM pats_solicitudes_franquicia_documentos
    WHERE id_solicitud = {$idSolicitud}
      AND vigente = 1
    ORDER BY id_documento_solicitud ASC
  ");
}

$html = pats_cd_contrato_franquicia_html($sol, [
  'modo' => 'PREVIEW',
  'documentos' => $docs
]);

if (trim($html) === '') {
  fin_json(['ok' => false, 'error' => 'No se pudo generar el contrato HTML de franquicia'], 500);
}

$cx->begin_transaction();

try {
  fin_exec($cx, "
    UPDATE pats_solicitudes_franquicia
    SET
      contrato_digital_html = '" . fin_esc($cx, $html) . "',
      updated_at = NOW()
    WHERE id_solicitud = {$idSolicitud}
    LIMIT 1
  ");

  if (fin_table_exists($cx, 'pats_solicitudes_franquicia_historial')) {
    $cols = [];
    $rsCols = $cx->query("SHOW COLUMNS FROM pats_solicitudes_franquicia_historial");

    if ($rsCols) {
      while ($c = $rsCols->fetch_assoc()) {
        $cols[] = (string)($c['Field'] ?? '');
      }
      $rsCols->free();
    }

    if (
      in_array('id_solicitud', $cols, true)
      && in_array('evento_tipo', $cols, true)
    ) {
      $payload = json_encode([
        'accion' => 'GENERAR_CONTRATO_DIGITAL',
        'estado' => 'NO_FIRMADO',
        'html_size' => strlen($html),
        'tipo_persona' => (string)($sol['tipo_persona'] ?? ''),
        'titularidad_tipo' => (string)($sol['titularidad_tipo'] ?? '')
      ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

      $fields = ['id_solicitud', 'evento_tipo'];
      $values = [
        (string)$idSolicitud,
        "'GENERAR_CONTRATO_DIGITAL'"
      ];

      if (in_array('estatus_anterior', $cols, true)) {
        $fields[] = 'estatus_anterior';
        $values[] = "'" . fin_esc($cx, $estatus) . "'";
      }

      if (in_array('estatus_nuevo', $cols, true)) {
        $fields[] = 'estatus_nuevo';
        $values[] = "'" . fin_esc($cx, $estatus) . "'";
      }

      if (in_array('payload_json', $cols, true)) {
        $fields[] = 'payload_json';
        $values[] = "'" . fin_esc($cx, $payload) . "'";
      }

      if (in_array('user_evento', $cols, true)) {
        $fields[] = 'user_evento';
        $values[] = $userEvento > 0 ? (string)$userEvento : 'NULL';
      }

      if (in_array('fecha_evento', $cols, true)) {
        $fields[] = 'fecha_evento';
        $values[] = 'NOW()';
      }

      if (in_array('created_at', $cols, true)) {
        $fields[] = 'created_at';
        $values[] = 'NOW()';
      }

      fin_exec($cx, "
        INSERT INTO pats_solicitudes_franquicia_historial
        (`" . implode('`,`', $fields) . "`)
        VALUES
        (" . implode(',', $values) . ")
      ");
    }
  }

  $cx->commit();

  fin_json([
    'ok' => true,
    'id_solicitud' => $idSolicitud,
    'estado' => 'NO_FIRMADO',
    'contrato_digital_html' => $html
  ]);

} catch (Throwable $e) {
  $cx->rollback();

  fin_json([
    'ok' => false,
    'error' => $e->getMessage()
  ], 500);
}