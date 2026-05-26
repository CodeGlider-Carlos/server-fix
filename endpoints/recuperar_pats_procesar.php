<?php
/*
  endpoints/recuperar_pats_procesar.php
  Recupera un alta PATS usando datos de pats_respaldo.
  No verifica pago con Stripe — solo para casos donde el pago ya fue confirmado
  pero la transacción de BD falló.
  Requiere token de un solo uso de pats_tokens_recuperacion.
*/
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

header('Content-Type: text/html; charset=utf-8');

$stripeConfig = __DIR__ . '/../config/config.php';
if (is_file($stripeConfig)) require_once $stripeConfig;

require_once __DIR__ . '/../../../varSQL/bd_pats.php';
require_once __DIR__ . '/../../../varSQL/var_pats.php';
require_once __DIR__ . '/../lib/contratos.php';

$cx = $conexionMod ?? $db_all2 ?? $db_all ?? null;

$__mailerLib = __DIR__ . '/../lib/pats_mailer.php';
if (is_file($__mailerLib)) require_once $__mailerLib;

mysqli_report(MYSQLI_REPORT_OFF);

/* ── Helpers (mismas que generar_orden, en request separado no hay conflicto) ── */
function rec_die(string $msg): void {
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
    <title>Error — Recuperación PATS</title>
    <style>body{font-family:system-ui;max-width:640px;margin:60px auto;padding:20px}
    .err{background:#fef2f2;border:2px solid #f87171;border-radius:12px;padding:24px;color:#b91c1c}
    a{color:#2563eb}</style></head><body>
    <div class="err"><strong>Error en recuperación</strong><br><br>' . htmlspecialchars($msg) . '</div>
    <br><a href="../recuperar_pats.php">← Volver</a></body></html>';
    exit;
}

function rec_clean($v): string  { return trim((string)($v ?? '')); }
function rec_upper($v): string  { return strtoupper(rec_clean($v)); }
function rec_num($v): float     { return round((float)($v ?? 0), 2); }
function rec_digits($v): string { return preg_replace('/\D+/', '', (string)($v ?? '')); }
function rec_get_ip(): string   { return (string)($_SERVER['REMOTE_ADDR'] ?? ''); }
function rec_get_ua(): string   { return (string)($_SERVER['HTTP_USER_AGENT'] ?? ''); }

function rec_build_ref(): string {
    return 'PATS-REC-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
}
function rec_build_folio(): string {
    return 'ORD-REC-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

function rec_calcular_edad(string $fn): int {
    if ($fn === '') return 0;
    try { return (int)(new DateTime($fn))->diff(new DateTime('today'))->y; }
    catch (Throwable $e) { return 0; }
}

function rec_nominal_y_recargo(string $freq, float $monto): array {
    $base = strtoupper(trim($freq)) === 'MENSUAL' ? 800.00 : 9600.00;
    return [min($monto, $base), max(0, $monto - $base)];
}

function rec_freq_to_tipo(string $freq): int {
    return strtoupper(trim($freq)) === 'MENSUAL' ? 2 : 1;
}

function rec_calcular_vigencia(string $freq): array {
    $fechaAlta = date('Y-m-d H:i:s');
    if (strtoupper(trim($freq)) === 'ANUAL') {
        return [$fechaAlta, date('Y-m-d', strtotime('+12 months')), date('Y-m-d H:i:s', strtotime('+12 months'))];
    }
    return [$fechaAlta, date('Y-m-d', strtotime('+1 month')), date('Y-m-d H:i:s', strtotime('+1 month'))];
}

function rec_hash_contract(string $html): string { return hash('sha256', $html); }

function rec_get_table_columns(mysqli $cx, string $table): array {
    $cols = [];
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $rs = $cx->query("SHOW COLUMNS FROM {$safe}");
    if ($rs instanceof mysqli_result) {
        while ($row = $rs->fetch_assoc()) $cols[strtolower((string)$row['Field'])] = (string)$row['Field'];
        $rs->free();
    }
    return $cols;
}

function rec_insert_dynamic(mysqli $cx, string $table, array $data, array $typeMap = []): int {
    $colsMap = rec_get_table_columns($cx, $table);
    if (!$colsMap) throw new RuntimeException("Tabla {$table} no encontrada.");
    $cols = $placeholders = $values = [];
    $types = '';
    foreach ($data as $key => $value) {
        $k = strtolower((string)$key);
        if (!isset($colsMap[$k])) continue;
        $cols[]         = '`' . $colsMap[$k] . '`';
        $placeholders[] = '?';
        $values[]       = $value;
        $types         .= $typeMap[$k] ?? 's';
    }
    if (!$cols) throw new RuntimeException("Sin columnas compatibles para {$table}.");
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $stmt = $cx->prepare("INSERT INTO {$safe} (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")");
    if (!$stmt) throw new RuntimeException("Prepare {$table}: " . $cx->error);
    $refs = [$types];
    foreach ($values as $i => $v) $refs[] = &$values[$i];
    call_user_func_array([$stmt, 'bind_param'], $refs);
    if (!$stmt->execute()) throw new RuntimeException("Insert {$table}: " . $stmt->error);
    $id = (int)$stmt->insert_id;
    $stmt->close();
    return $id;
}

function rec_store_upload(?array $file, string $prefix): ?array {
    if (!$file || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return null;
    $allowed = ['pdf','png','jpg','jpeg','webp'];
    $orig    = (string)($file['name'] ?? 'archivo');
    $ext     = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $safeExt = preg_replace('/[^a-z0-9]/i', '', $ext) ?: 'bin';
    if (!in_array($safeExt, $allowed, true)) throw new RuntimeException('Formato no permitido: ' . $orig);
    $relDir  = 'uploads/public_checkout/' . date('Y/m');
    $baseDir = dirname(__DIR__) . '/' . $relDir;
    if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
        throw new RuntimeException('No fue posible crear directorio de uploads');
    }
    $filename = $prefix . '_REC_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $safeExt;
    $target   = $baseDir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $target)) throw new RuntimeException('No fue posible mover: ' . $orig);
    return ['path'=>$target,'relative_path'=>$relDir.'/'.$filename,'name'=>$orig,'size'=>(int)($file['size']??0),'type'=>(string)($file['type']??''),'extension'=>$safeExt];
}

function rec_store_base64(string $dataUrl, string $prefix): ?array {
    $dataUrl = trim($dataUrl);
    if ($dataUrl === '') return null;
    if (!preg_match('#^data:image/(png|jpeg|jpg|webp);base64,#i', $dataUrl, $m)) return null;
    $ext = strtolower($m[1]); if ($ext==='jpeg') $ext='jpg';
    $bin = base64_decode(preg_replace('#^data:image/(png|jpeg|jpg|webp);base64,#i','', $dataUrl), true);
    if ($bin === false || strlen($bin) < 100) return null;
    $relDir  = 'uploads/public_checkout/' . date('Y/m');
    $baseDir = dirname(__DIR__) . '/' . $relDir;
    if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) throw new RuntimeException('Sin directorio de uploads');
    $filename = $prefix . '_REC_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target   = $baseDir . '/' . $filename;
    if (file_put_contents($target, $bin) === false) throw new RuntimeException('No fue posible guardar la imagen');
    return ['path'=>$target,'relative_path'=>$relDir.'/'.$filename,'name'=>$filename,'size'=>strlen($bin),'type'=>'image/'.$ext,'extension'=>$ext];
}

/* ── Validación de método ── */
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') rec_die('Método no permitido.');
if (!$cx instanceof mysqli) rec_die('Sin conexión a base de datos.');

/* ── Validar token ── */
$tokenClaro = strtoupper(trim((string)($_POST['token'] ?? '')));
if ($tokenClaro === '') rec_die('Falta el código de recuperación.');

$tokenHash = hash('sha256', $tokenClaro);
$stmtTok = $cx->prepare("SELECT id_token, expira_at, usado FROM pats_tokens_recuperacion WHERE token_hash = ? LIMIT 1");
if (!$stmtTok) rec_die('Error preparando validación de token.');
$stmtTok->bind_param('s', $tokenHash);
$stmtTok->execute();
$rsTok  = $stmtTok->get_result();
$rowTok = $rsTok ? $rsTok->fetch_assoc() : null;
$stmtTok->close();

if (!$rowTok)                          rec_die('El código de recuperación no es válido.');
if ((int)$rowTok['usado'] === 1)       rec_die('Este código ya fue utilizado. Genera uno nuevo.');
if (new DateTime() > new DateTime((string)$rowTok['expira_at'])) rec_die('El código ha expirado. Genera uno nuevo.');

$idToken = (int)$rowTok['id_token'];

/* ── Leer respaldo (opcional — 0 = sin respaldo) ── */
$idRespaldo = (int)($_POST['id_respaldo'] ?? 0);
$respaldo   = null;
$payload    = [];

if ($idRespaldo > 0) {
    $stmtR = $cx->prepare("SELECT * FROM pats_respaldo WHERE id_respaldo = ? LIMIT 1");
    if (!$stmtR) rec_die('Error consultando respaldo.');
    $stmtR->bind_param('i', $idRespaldo);
    $stmtR->execute();
    $rsR      = $stmtR->get_result();
    $respaldo = $rsR ? $rsR->fetch_assoc() : null;
    $stmtR->close();
    if (!$respaldo) rec_die('No se encontró el registro de respaldo.');
    $payload = json_decode((string)($respaldo['payload_post_json'] ?? '{}'), true) ?: [];
}

/* Helper — lee de $_POST */
$p = function(string $key, string $fallback = ''): string {
    return rec_clean($_POST[$key] ?? $fallback);
};

/* Datos del paciente */
$nombre           = $p('nombre_usuario');
$apellidoPa       = $p('apellido_pa');
$apellidoMa       = $p('apellido_ma');
$curp             = strtoupper($p('curp_usuario'));
$fechaNacimiento  = $p('fecha_nacimiento');
$correoAcceso     = $p('correo_usuario_pats');
$telefonoAcceso   = rec_digits($_POST['telefono_usuario'] ?? '');
$tipoCliente      = $p('tipo_cliente', 'privado');
$nombreEmpresa    = $p('nombre_empresa');
$rfcUsuario       = strtoupper($p('rfc_usuario'));
$actividadOcup    = $p('actividad_ocupacion');
$estadoCivil      = $p('estado_civil');
$nacionalidadTipo = strtoupper($p('nacionalidad_tipo', 'MEXICANA'));
if (!in_array($nacionalidadTipo, ['MEXICANA','EXTRANJERA'], true)) $nacionalidadTipo = 'MEXICANA';
$pacienteMexicano = ($nacionalidadTipo === 'MEXICANA');
$nacionalidad     = $p('nacionalidad', $pacienteMexicano ? 'mexicana' : '');
$paisNacimiento   = $p('pais_nacimiento', $pacienteMexicano ? 'México' : '');
$tipoDocIdent     = strtoupper($p('tipo_documento_identidad'));
$paisDocIdent     = $p('pais_documento_identidad');
$numDocIdent      = strtoupper($p('numero_documento_identidad'));

/* Domicilio */
$domCalle     = $p('dom_calle');
$domNumExt    = $p('dom_num_ext');
$domNumInt    = $p('dom_num_int');
$domColonia   = $p('dom_colonia');
$domCp        = rec_digits($_POST['dom_cp'] ?? '');
$domMunicipio = $p('dom_municipio');
$domEstado    = $p('dom_estado');
$domEstAcr    = strtoupper($p('dom_estado_acronimo'));
$domPais      = $p('dom_pais', 'México');

/* Pago */
$frecuencia = strtoupper($p('frecuencia', 'MENSUAL'));
if (!in_array($frecuencia, ['MENSUAL','ANUAL'], true)) $frecuencia = 'MENSUAL';
$moneda     = strtoupper($p('moneda', 'MXN'));
if ($moneda === '') $moneda = 'MXN';
$montoOrden = rec_num($_POST['monto_orden'] ?? 0);
if ($montoOrden <= 0) $montoOrden = ($frecuencia === 'MENSUAL') ? 800.00 : 9600.00;
[$montoBase, $montoRecargo] = rec_nominal_y_recargo($frecuencia, $montoOrden);
$idTipoPrecio = rec_freq_to_tipo($frecuencia);

/* Firma / foto: del POST (hidden fields del form); fallback al JSON del respaldo */
$firmaBase64 = rec_clean($_POST['firma_base64'] ?? '');
$fotoBase64  = rec_clean($_POST['foto_base64'] ?? '');
if ($fotoBase64  === '' && $payload) $fotoBase64  = rec_clean($payload['foto_base64']  ?? '');
if ($firmaBase64 === '' && $payload) $firmaBase64 = rec_clean($payload['firma_base64'] ?? '');

/* Representación */
$edad             = rec_calcular_edad($fechaNacimiento);
$modoFirma        = strtoupper($p('modo_firma', 'FIRMA_PROPIA'));
if (!in_array($modoFirma, ['FIRMA_PROPIA','TUTOR_FAMILIAR','RESPONSABLE_AUTORIZADO'], true)) $modoFirma = 'FIRMA_PROPIA';
$esMenor          = ($edad > 0 && $edad < 18);
$esAdultoMayor    = ($edad >= 65);
if ($esMenor) $modoFirma = 'TUTOR_FAMILIAR';
$requiereResp     = ($esMenor || $modoFirma === 'TUTOR_FAMILIAR' || $modoFirma === 'RESPONSABLE_AUTORIZADO');
$esDependiente    = (!$esMenor && $requiereResp);
$tipoPaciente     = $esDependiente ? 'DEPENDIENTE_CON_RESPONSABLE' : ($esMenor ? 'MENOR' : ($esAdultoMayor ? 'ADULTO_MAYOR' : 'ADULTO'));
$tipoRepres       = strtoupper($p('tipo_representacion', $modoFirma));
$relacionResp     = $p('relacion_responsable_paciente');
$motivoResp       = $p('motivo_responsable');

/* Tutor / responsable */
$tutorNombre    = $p('tutor_nombre');
$tutorApPa      = $p('tutor_apellido_pa');
$tutorApMa      = $p('tutor_apellido_ma');
$tutorCurp      = strtoupper($p('tutor_curp'));
$tutorRfc       = strtoupper($p('tutor_rfc'));
$tutorFnac      = $p('tutor_fecha_nacimiento');
$tutorCorreo    = $p('tutor_correo');
$tutorTel       = rec_digits($_POST['tutor_telefono'] ?? '');
$tutorNacTipo   = strtoupper($p('tutor_nacionalidad_tipo', 'MEXICANA'));
if (!in_array($tutorNacTipo, ['MEXICANA','EXTRANJERA'], true)) $tutorNacTipo = 'MEXICANA';
$tutorMexicano  = ($tutorNacTipo === 'MEXICANA');
$tutorNac       = $p('tutor_nacionalidad', $tutorMexicano ? 'mexicana' : '');
$tutorPaisNac   = $p('tutor_pais_nacimiento', $tutorMexicano ? 'México' : '');

/* Origen comercial — todo desde $_POST */
$actorTipo      = $p('actor_tipo_publico', 'ADMINPATS');
$tipoOrigen     = $p('tipo_origen', $actorTipo);
$idDistrib      = (int)($_POST['id_distribuidor'] ?? 0);
$idFranq        = (int)($_POST['id_franquicia']   ?? 0);
$idGestor       = (int)($_POST['id_gestor']        ?? 0);
$pais           = $p('pais', 'México');
$region         = $p('region');
$zona           = $p('zona');
$unidad         = $p('unidad');
$tokenPublico   = $p('token_publico');
$origenCheckout = 'RECUPERACION_MANUAL';
$stripePI       = $p('stripe_payment_intent_id');

/* Nombres compuestos */
$fullName       = trim($nombre . ' ' . $apellidoPa . ' ' . $apellidoMa);
$tutorFullName  = trim($tutorNombre . ' ' . $tutorApPa . ' ' . $tutorApMa);
$nombreFirmante = $requiereResp ? $tutorFullName : $fullName;
$correoOrden    = ($requiereResp && $tutorCorreo !== '') ? $tutorCorreo : $correoAcceso;
$telefonoOrden  = ($requiereResp && $tutorTel !== '') ? $tutorTel : $telefonoAcceso;
$curpOrden      = $pacienteMexicano && $curp !== '' ? $curp : 'NO_APLICA';

/* Payload para contrato */
$payloadContrato = array_merge($payload, [
    'nombre_usuario'         => $nombre,
    'apellido_pa'            => $apellidoPa,
    'apellido_ma'            => $apellidoMa,
    'curp_usuario'           => $curp,
    'correo_usuario_pats'    => $correoOrden,
    'telefono_usuario'       => $telefonoOrden,
    'tipo_paciente'          => $tipoPaciente,
    'modo_firma'             => $modoFirma,
    'nombre_firmante'        => $nombreFirmante,
    'paciente_nombre_completo' => $fullName,
    'tutor_nombre_completo'  => $tutorFullName,
    'firma_base64'           => $firmaBase64,
    'frecuencia'             => $frecuencia,
    'monto_orden'            => (string)$montoOrden,
    'moneda'                 => $moneda,
    'pais'                   => $pais,
    'region'                 => $region,
    'zona'                   => $zona,
    'unidad'                 => $unidad,
]);

/* ── Quemar token e iniciar transacción ── */
$ipUso = rec_get_ip();
$stmtBurn = $cx->prepare("UPDATE pats_tokens_recuperacion SET usado=1, usado_at=NOW(), ip_uso=? WHERE id_token=? LIMIT 1");
if ($stmtBurn) {
    $stmtBurn->bind_param('si', $ipUso, $idToken);
    $stmtBurn->execute();
    $stmtBurn->close();
}

try {
    $cx->begin_transaction();

    /* 1) Archivos */
    $docFields = [
        'doc_identificacion_frente'        => 'paciente_identificacion_frente',
        'doc_identificacion_reverso'       => 'paciente_identificacion_reverso',
        'doc_curp'                         => 'paciente_curp',
        'doc_comprobante_domicilio'        => 'paciente_comprobante_domicilio',
        'doc_constancia_fiscal'            => 'paciente_constancia_fiscal',
        'tutor_doc_identificacion_frente'  => 'responsable_identificacion_frente',
        'tutor_doc_identificacion_reverso' => 'responsable_identificacion_reverso',
        'tutor_doc_curp'                   => 'responsable_curp',
        'tutor_doc_constancia_fiscal'      => 'responsable_constancia_fiscal',
        'doc_acreditacion_representacion'  => 'acreditacion_representacion',
    ];
    $docs = [];
    foreach ($docFields as $field => $prefix) {
        $docs[$field] = rec_store_upload($_FILES[$field] ?? null, $prefix);
    }
    $docs['foto_paciente'] = rec_store_base64($fotoBase64, 'foto_paciente');
    /* Fallback: foto subida como archivo si no vino base64 */
    if (!$docs['foto_paciente'] && !empty($_FILES['foto_nueva']['tmp_name'])) {
        $docs['foto_paciente'] = rec_store_upload($_FILES['foto_nueva'], 'foto_paciente');
    }

    /* 2) Referencias */
    $referenciaPago   = rec_build_ref();
    $folioOrden       = rec_build_folio();
    $proveedorPasarela = 'STRIPE';
    $stripeChargeId   = '';

    /* 3) Insertar orden */
    $stmt = $cx->prepare("
        INSERT INTO pats_ordenes_pago
        (id_pasaporte, id_franquicia, id_distribuidor, id_tipo_precio,
         correo_usuario_pats, curp_usuario, nombre_usuario, apellido_pa, apellido_ma,
         fecha_nacimiento, telefono_usuario, pais, region, zona, unidad,
         tipo_cliente, nombre_empresa, tipo_operacion, frecuencia,
         monto_orden, monto_nominal_base, monto_extra_recargo, moneda,
         referencia_pago, folio_orden, estatus_orden, estatus_pago,
         proveedor_pasarela, transaccion_id_externa, payment_intent_id,
         charge_id, referencia_externa, order_id_externo, payload_confirmacion_json,
         fecha_pago, fecha_confirmacion, pasaporte_creado, id_pasaporte_generado,
         procesado_integracion, created_at, updated_at)
        VALUES
        (NULL,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'ALTA_PATS',?,?,?,?,?,?,?,
         'PAGO_CONFIRMADO','CONFIRMADO',?,?,?,?,?,?,'{}',
         NOW(),NOW(),0,NULL,0,NOW(),NOW())
    ");
    if (!$stmt) throw new RuntimeException('Preparar orden: ' . $cx->error);

    $stmt->bind_param(
        'iiissssssssssssssdddsssssssss',
        $idFranq, $idDistrib, $idTipoPrecio,
        $correoOrden, $curpOrden, $nombre, $apellidoPa, $apellidoMa,
        $fechaNacimiento, $telefonoOrden,
        $pais, $region, $zona, $unidad, $tipoCliente, $nombreEmpresa,
        $frecuencia, $montoOrden, $montoBase, $montoRecargo,
        $moneda, $referenciaPago, $folioOrden,
        $proveedorPasarela, $stripePI, $stripePI,
        $stripeChargeId, $stripePI, $stripePI
    );
    if (!$stmt->execute()) throw new RuntimeException('Insertar orden: ' . $stmt->error);
    $idOrden = (int)$stmt->insert_id;
    $stmt->close();

    /* 4) Insertar pasaporte */
    [$fechaAlta, $vigencia, $fechaVencReal] = rec_calcular_vigencia($frecuencia);
    $foto     = $docs['foto_paciente'];
    $fotoPath = is_array($foto) ? (string)($foto['relative_path'] ?? '') : '';
    $fotoNom  = is_array($foto) ? (string)($foto['name'] ?? '') : '';
    $fotoMime = is_array($foto) ? (string)($foto['type'] ?? '') : '';
    $fotoSize = is_array($foto) ? (int)($foto['size'] ?? 0) : 0;
    $fotoSzKb = $fotoSize > 0 ? (int)ceil($fotoSize / 1024) : 0;

    $idPasaporte = rec_insert_dynamic($cx, 'pats_pasaportes', [
        'id_franquicia'           => $idFranq,
        'id_distribuidor'         => $idDistrib,
        'id_tipo_precio'          => $idTipoPrecio,
        'id_cliente'              => null,
        'id_beneficiario'         => null,
        'id_unidad'               => null,
        'curp'                    => $curpOrden,
        'nombres'                 => $nombre,
        'apellido_pa'             => $apellidoPa,
        'apellido_ma'             => $apellidoMa,
        'fecha_nacimiento'        => $fechaNacimiento,
        'telefono'                => $telefonoOrden,
        'correo'                  => $correoOrden,
        'fecha_alta'              => $fechaAlta,
        'vigencia'                => $vigencia,
        'fecha_baja'              => null,
        'frecuencia_pago'         => $frecuencia,
        'estatus'                 => 'activo',
        'valor_pasaporte'         => $montoBase,
        'valor_final_pasaporte'   => $montoOrden,
        'cupon'                   => $stripePI,
        'pais'                    => $pais,
        'region'                  => $region,
        'zona'                    => $zona,
        'unidad'                  => $unidad,
        'tipo_cliente'            => $tipoCliente,
        'nombre_empresa'          => $nombreEmpresa,
        'fotografia_path'         => $fotoPath,
        'fotografia_nombre'       => $fotoNom,
        'fotografia_nombre_original' => $fotoNom,
        'fotografia_mime'         => $fotoMime,
        'fotografia_mime_type'    => $fotoMime,
        'fotografia_size_bytes'   => $fotoSize,
        'fotografia_size_kb'      => $fotoSzKb,
        'fecha_fotografia'        => $fotoPath !== '' ? $fechaAlta : null,
        'fecha_ultimo_pago'       => $fechaAlta,
        'fecha_vencimiento_real'  => $fechaVencReal,
        'meses_vencidos'          => 0,
        'recargo_acumulado'       => 0.00,
        'activo'                  => 1,
        'created_at'              => date('Y-m-d H:i:s'),
        'updated_at'              => date('Y-m-d H:i:s'),
    ], [
        'id_franquicia'=>'i','id_distribuidor'=>'i','id_tipo_precio'=>'i',
        'id_cliente'=>'i','id_beneficiario'=>'i','id_unidad'=>'i',
        'valor_pasaporte'=>'d','valor_final_pasaporte'=>'d',
        'fotografia_size_bytes'=>'i','fotografia_size_kb'=>'i',
        'meses_vencidos'=>'i','recargo_acumulado'=>'d','activo'=>'i',
    ]);

    /* Vincular pasaporte con orden */
    $stmtLink = $cx->prepare("UPDATE pats_ordenes_pago SET id_pasaporte=?,pasaporte_creado=1,id_pasaporte_generado=?,procesado_integracion=1,updated_at=NOW() WHERE id_orden=? LIMIT 1");
    if ($stmtLink) {
        $stmtLink->bind_param('iii', $idPasaporte, $idPasaporte, $idOrden);
        $stmtLink->execute();
        $stmtLink->close();
    }

    /* 5) Alta de pasaporte */
    $datosPacJson = json_encode(['nombre'=>$nombre,'apellido_pa'=>$apellidoPa,'apellido_ma'=>$apellidoMa,'curp'=>$curp,'fecha_nacimiento'=>$fechaNacimiento,'nacionalidad_tipo'=>$nacionalidadTipo,'tipo_paciente'=>$tipoPaciente], JSON_UNESCAPED_UNICODE);
    $datosRespJson = json_encode(['nombre'=>$tutorNombre,'apellido_pa'=>$tutorApPa,'curp'=>$tutorCurp,'correo'=>$tutorCorreo,'telefono'=>$tutorTel,'nacionalidad_tipo'=>$tutorNacTipo], JSON_UNESCAPED_UNICODE);
    $domJson = json_encode(['calle'=>$domCalle,'num_ext'=>$domNumExt,'colonia'=>$domColonia,'cp'=>$domCp,'municipio'=>$domMunicipio,'estado'=>$domEstado,'pais'=>$domPais], JSON_UNESCAPED_UNICODE);
    $stripeJson = json_encode(['payment_intent_id'=>$stripePI,'status'=>'succeeded','recuperacion_manual'=>true], JSON_UNESCAPED_UNICODE);
    $payloadFormJson = json_encode(['recuperacion_manual'=>true,'id_respaldo'=>$idRespaldo,'sin_respaldo'=>($idRespaldo===0),'documentos_cargados'=>$docs], JSON_UNESCAPED_UNICODE);

    $idAlta = rec_insert_dynamic($cx, 'pats_pasaporte_altas', [
        'id_pasaporte'              => $idPasaporte,
        'id_orden'                  => $idOrden,
        'referencia_pago'           => $referenciaPago,
        'stripe_payment_intent_id'  => $stripePI,
        'stripe_charge_id'          => '',
        'token_publico'             => $tokenPublico,
        'actor_tipo_publico'        => $actorTipo,
        'tipo_origen'               => $tipoOrigen,
        'origen_checkout'           => $origenCheckout,
        'id_franquicia'             => $idFranq,
        'id_distribuidor'           => $idDistrib,
        'id_gestor'                 => $idGestor,
        'tipo_paciente'             => $tipoPaciente,
        'modo_firma'                => $modoFirma,
        'requiere_responsable'      => $requiereResp ? 1 : 0,
        'tipo_representacion'       => $tipoRepres,
        'relacion_responsable_paciente' => $relacionResp,
        'motivo_responsable'        => $motivoResp,
        'nacionalidad_tipo'         => $nacionalidadTipo,
        'paciente_es_menor'         => $esMenor ? 1 : 0,
        'paciente_es_adulto_mayor'  => $esAdultoMayor ? 1 : 0,
        'datos_paciente_json'       => $datosPacJson,
        'datos_responsable_json'    => $datosRespJson,
        'domicilio_json'            => $domJson,
        'adulto_mayor_json'         => '{}',
        'origen_comercial_json'     => json_encode(['tipo_origen'=>$tipoOrigen,'actor_tipo_publico'=>$actorTipo,'recuperacion_manual'=>true]),
        'stripe_json'               => $stripeJson,
        'payload_formulario_json'   => $payloadFormJson,
        'ip_registro'               => rec_get_ip(),
        'user_agent_registro'       => rec_get_ua(),
        'estatus'                   => 'PAGO_CONFIRMADO',
        'activo'                    => 1,
        'created_at'                => date('Y-m-d H:i:s'),
        'updated_at'                => date('Y-m-d H:i:s'),
    ], [
        'id_pasaporte'=>'i','id_orden'=>'i','id_franquicia'=>'i',
        'id_distribuidor'=>'i','id_gestor'=>'i',
        'requiere_responsable'=>'i','paciente_es_menor'=>'i',
        'paciente_es_adulto_mayor'=>'i','activo'=>'i',
    ]);

    /* 6) Documentos */
    $docMap = [
        'doc_identificacion_frente'       =>['PACIENTE','PACIENTE_IDENTIFICACION_FRENTE',  !$requiereResp && $pacienteMexicano],
        'doc_identificacion_reverso'      =>['PACIENTE','PACIENTE_IDENTIFICACION_REVERSO', !$requiereResp && $pacienteMexicano],
        'doc_curp'                        =>['PACIENTE','PACIENTE_CURP',                    $pacienteMexicano],
        'doc_comprobante_domicilio'       =>['PACIENTE','PACIENTE_COMPROBANTE_DOMICILIO',   true],
        'doc_constancia_fiscal'           =>['PACIENTE','PACIENTE_CONSTANCIA_FISCAL',       false],
        'foto_paciente'                   =>['PACIENTE','PACIENTE_FOTO',                    true],
        'tutor_doc_identificacion_frente' =>['RESPONSABLE','RESPONSABLE_IDENTIFICACION_FRENTE',  $requiereResp],
        'tutor_doc_identificacion_reverso'=>['RESPONSABLE','RESPONSABLE_IDENTIFICACION_REVERSO', $requiereResp && $tutorMexicano],
        'tutor_doc_curp'                  =>['RESPONSABLE','RESPONSABLE_CURP',               $requiereResp && $tutorMexicano],
        'tutor_doc_constancia_fiscal'     =>['RESPONSABLE','RESPONSABLE_CONSTANCIA_FISCAL',  false],
        'doc_acreditacion_representacion' =>['RESPONSABLE','ACREDITACION_REPRESENTACION',    $esDependiente],
    ];
    foreach ($docMap as $key => [$actor, $tipo, $oblig]) {
        $doc = $docs[$key] ?? null;
        if (!$doc || empty($doc['relative_path'])) continue;
        rec_insert_dynamic($cx, 'pats_pasaporte_documentos', [
            'id_pasaporte'           => $idPasaporte,
            'id_alta'                => $idAlta,
            'id_orden'               => $idOrden,
            'actor_documento'        => $actor,
            'tipo_documento'         => $tipo,
            'etiqueta'               => str_replace('_',' ',$tipo),
            'archivo_path'           => (string)($doc['relative_path']??''),
            'archivo_nombre_original'=> (string)($doc['name']??''),
            'archivo_mime_type'      => (string)($doc['type']??''),
            'archivo_extension'      => (string)($doc['extension']??''),
            'archivo_size_bytes'     => (int)($doc['size']??0),
            'es_obligatorio'         => $oblig ? 1 : 0,
            'estatus'                => 'CARGADO',
            'metadata_json'          => '{}',
            'activo'                 => 1,
            'created_at'             => date('Y-m-d H:i:s'),
            'updated_at'             => date('Y-m-d H:i:s'),
        ], ['id_pasaporte'=>'i','id_alta'=>'i','id_orden'=>'i','archivo_size_bytes'=>'i','es_obligatorio'=>'i','activo'=>'i']);
    }

    /* 7) Contrato */
    $htmlContrato = '';
    if (function_exists('pats_render_contract_html') && $firmaBase64 !== '') {
        $htmlContrato = pats_render_contract_html($payloadContrato);
    }
    $idContrato = 0;
    if ($htmlContrato !== '') {
        $idContrato = rec_insert_dynamic($cx, 'pats_contratos_firmados', [
            'id_pasaporte'              => $idPasaporte,
            'id_alta'                   => $idAlta,
            'id_orden'                  => $idOrden,
            'id_distribuidor'           => $idDistrib,
            'id_franquicia'             => $idFranq,
            'id_gestor'                 => $idGestor,
            'token_publico'             => $tokenPublico,
            'contrato_clave'            => 'contrato_pats_base',
            'contrato_version'          => '1.0',
            'html_contrato_renderizado' => $htmlContrato,
            'contrato_digital_html'     => $htmlContrato,
            'hash_contrato'             => rec_hash_contract($htmlContrato),
            'contrato_digital_hash'     => rec_hash_contract($htmlContrato),
            'firma_afiliado_base64'     => $firmaBase64,
            'firma_digital_data'        => $firmaBase64,
            'nombre_firmante'           => $nombreFirmante,
            'firma_digital_nombre'      => $nombreFirmante,
            'tipo_firmante'             => $requiereResp ? 'RESPONSABLE' : 'PACIENTE',
            'fecha_firma'               => date('Y-m-d H:i:s'),
            'contrato_digital_firmado_at' => date('Y-m-d H:i:s'),
            'ip_firma'                  => rec_get_ip(),
            'firma_digital_ip'          => rec_get_ip(),
            'user_agent_firma'          => rec_get_ua(),
            'firma_digital_user_agent'  => rec_get_ua(),
            'pdf_path'                  => '',
            'estatus'                   => 'firmado',
            'activo'                    => 1,
            'created_at'                => date('Y-m-d H:i:s'),
            'updated_at'                => date('Y-m-d H:i:s'),
        ], ['id_pasaporte'=>'i','id_alta'=>'i','id_orden'=>'i','id_distribuidor'=>'i','id_franquicia'=>'i','id_gestor'=>'i','activo'=>'i']);
    }

    /* 8) Acceso */
    $pwCliente   = rec_clean($_POST['pwd_nuevo'] ?? '');
    $pwEsTemporal = ($pwCliente === '' || strlen($pwCliente) < 8) ? 1 : 0;
    if ($pwEsTemporal) $pwCliente = strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
    $resetToken  = bin2hex(random_bytes(24));
    $tipoAcceso  = $requiereResp ? ($esMenor ? 'TUTOR' : 'RESPONSABLE') : 'PACIENTE';
    $idAcceso = rec_insert_dynamic($cx, 'pats_pasaporte_accesos', [
        'id_pasaporte'         => $idPasaporte,
        'id_alta'              => $idAlta,
        'id_orden'             => $idOrden,
        'tipo_acceso'          => $tipoAcceso,
        'correo_usuario'       => $correoOrden,
        'telefono_usuario'     => $telefonoOrden,
        'nombre_usuario'       => $nombreFirmante,
        'nombre_paciente'      => $fullName,
        'password_hash'        => password_hash($pwCliente, PASSWORD_DEFAULT),
        'password_temporal'    => $pwEsTemporal,
        'debe_cambiar_password'=> $pwEsTemporal,
        'token_reset'          => $resetToken,
        'token_reset_expira'   => date('Y-m-d H:i:s', strtotime('+7 days')),
        'intentos_fallidos'    => 0,
        'estatus'              => 'ACTIVO',
        'activo'               => 1,
        'created_at'           => date('Y-m-d H:i:s'),
        'updated_at'           => date('Y-m-d H:i:s'),
    ], ['id_pasaporte'=>'i','id_alta'=>'i','id_orden'=>'i','password_temporal'=>'i','debe_cambiar_password'=>'i','intentos_fallidos'=>'i','activo'=>'i']);

    $cx->commit();

    /* 9) Marcar respaldo como RECUPERADO (solo si se usó uno) */
    if ($idRespaldo > 0) {
        $stmtRec = $cx->prepare("UPDATE pats_respaldo SET estatus_respaldo='RECUPERADO', recuperado=1, recuperado_en=NOW(), id_pasaporte_generado=?, id_orden_generado=?, updated_at=NOW() WHERE id_respaldo=? LIMIT 1");
        if ($stmtRec) {
            $stmtRec->bind_param('iii', $idPasaporte, $idOrden, $idRespaldo);
            $stmtRec->execute();
            $stmtRec->close();
        }
    }

    /* 10) Correo (silencioso) */
    $mailOk = false;
    try {
        if (function_exists('pats_send_pasaporte_confirmacion_email')) {
            $baseUrl = defined('PATS_ACCESS_RESET_URL') ? (string)PATS_ACCESS_RESET_URL : 'https://pasaporteatusalud.com/crear-password';
            $mailOk  = pats_send_pasaporte_confirmacion_email([
                'to'              => $correoOrden,
                'nombre_firmante' => $nombreFirmante,
                'nombre_paciente' => $fullName,
                'id_pasaporte'    => $idPasaporte,
                'referencia_pago' => $referenciaPago,
                'folio_orden'     => $folioOrden,
                'monto'           => $montoOrden,
                'moneda'          => $moneda,
                'frecuencia'      => $frecuencia,
                'usuario'         => $correoOrden,
                'reset_url'       => $baseUrl . '?t=' . rawurlencode($resetToken),
                'tipo_acceso'     => $tipoAcceso,
            ]);
        }
    } catch (Throwable $mailEx) {
        error_log('PATS recovery mail error: ' . $mailEx->getMessage());
    }

} catch (Throwable $e) {
    @$cx->rollback();
    rec_die('Error al crear el alta: ' . $e->getMessage());
}
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Alta Recuperada — PATS</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,sans-serif;background:#f0fdf4;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
  .card{background:#fff;border-radius:16px;padding:36px;max-width:560px;width:100%;box-shadow:0 4px 24px rgba(0,0,0,.10)}
  .icon{font-size:3rem;margin-bottom:12px}
  h1{font-size:1.3rem;font-weight:700;color:#15803d;margin-bottom:8px}
  .sub{color:#64748b;font-size:.875rem;margin-bottom:24px}
  .data-grid{display:grid;gap:10px}
  .data-row{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:#f8fafc;border-radius:8px;font-size:.875rem}
  .data-row strong{color:#374151}
  .data-row span{color:#1e293b;font-weight:600;font-family:monospace}
  .warn{background:#fffbeb;border:1.5px solid #fbbf24;border-radius:8px;padding:12px;font-size:.8rem;color:#92400e;margin-top:16px}
  a{display:inline-block;margin-top:24px;padding:11px 22px;background:#2563eb;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;font-size:.9rem}
  a:hover{background:#1d4ed8}
</style>
</head>
<body>
<div class="card">
  <div class="icon">✅</div>
  <h1>Alta recuperada exitosamente</h1>
  <p class="sub">El pasaporte fue creado desde el respaldo. Aquí están los detalles:</p>

  <div class="data-grid">
    <div class="data-row"><strong>ID Pasaporte</strong> <span><?= $idPasaporte ?></span></div>
    <div class="data-row"><strong>ID Orden</strong>     <span><?= $idOrden ?></span></div>
    <div class="data-row"><strong>Referencia</strong>   <span><?= htmlspecialchars($referenciaPago) ?></span></div>
    <div class="data-row"><strong>Folio</strong>        <span><?= htmlspecialchars($folioOrden) ?></span></div>
    <div class="data-row"><strong>Paciente</strong>     <span><?= htmlspecialchars($fullName) ?></span></div>
    <div class="data-row"><strong>Correo</strong>       <span><?= htmlspecialchars($correoOrden) ?></span></div>
    <div class="data-row"><strong>Monto</strong>        <span>$<?= number_format($montoOrden,2) ?> <?= htmlspecialchars($moneda) ?></span></div>
    <div class="data-row"><strong>Contrato</strong>     <span><?= $idContrato > 0 ? 'Guardado (ID '.$idContrato.')' : 'Sin firma — subir manualmente' ?></span></div>
    <div class="data-row"><strong>Correo enviado</strong> <span><?= $mailOk ? 'Sí' : 'No (revisar SMTP)' ?></span></div>
  </div>

  <?php if ($idContrato === 0): ?>
  <div class="warn">⚠️ No se generó el contrato porque no se encontró la firma en el respaldo. Súbelo manualmente desde el panel de administrador.</div>
  <?php endif; ?>

  <a href="../recuperar_pats.php">← Volver al formulario</a>
</div>
</body>
</html>
