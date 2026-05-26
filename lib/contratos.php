<?php
/*
ez/pats/lib/contratos.php

Motor de contrato PATS.
- Centraliza datos del prestador.
- Normaliza datos de paciente, tutor, nacionalidad y operación.
- Renderiza templates/contrato_pats_base.php.
*/

if (!function_exists('pats_h')) {
  function pats_h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('pats_contract_clean')) {
  function pats_contract_clean($v): string { return trim((string)($v ?? '')); }
}
if (!function_exists('pats_contract_digits')) {
  function pats_contract_digits($v): string { return preg_replace('/\D+/', '', (string)($v ?? '')); }
}
if (!function_exists('pats_contract_upper')) {
  function pats_contract_upper($v): string { return strtoupper(pats_contract_clean($v)); }
}
if (!function_exists('pats_contract_build_full_name')) {
  function pats_contract_build_full_name(array $data): string {
    return trim((string)($data['nombre_usuario'] ?? '') . ' ' . (string)($data['apellido_pa'] ?? '') . ' ' . (string)($data['apellido_ma'] ?? ''));
  }
}
if (!function_exists('pats_contract_build_full_address')) {
  function pats_contract_build_full_address(array $data): string {
    $calle = pats_contract_clean($data['dom_calle'] ?? '');
    $numExt = pats_contract_clean($data['dom_num_ext'] ?? '');
    $numInt = pats_contract_clean($data['dom_num_int'] ?? '');
    $colonia = pats_contract_clean($data['dom_colonia'] ?? '');
    $cp = pats_contract_clean($data['dom_cp'] ?? '');
    $municipio = pats_contract_clean($data['dom_municipio'] ?? '');
    $estado = pats_contract_clean($data['dom_estado'] ?? '');
    $pais = pats_contract_clean($data['dom_pais'] ?? 'México');
    $line1 = trim($calle . ($numExt !== '' ? ' ' . $numExt : ''));
    if ($numInt !== '') $line1 .= ' Int. ' . $numInt;
    $parts = [];
    if ($line1 !== '') $parts[] = $line1;
    if ($colonia !== '') $parts[] = 'Col. ' . $colonia;
    if ($cp !== '') $parts[] = 'C.P. ' . $cp;
    if ($municipio !== '') $parts[] = $municipio;
    if ($estado !== '') $parts[] = $estado;
    if ($pais !== '') $parts[] = $pais;
    return implode(', ', $parts);
  }
}
if (!function_exists('pats_contract_month_name_es')) {
  function pats_contract_month_name_es(int $month): string {
    $meses = [1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',5=>'mayo',6=>'junio',7=>'julio',8=>'agosto',9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre'];
    return $meses[$month] ?? '';
  }
}
if (!function_exists('pats_money_es')) {
  function pats_money_es($amount): string { return '$' . number_format((float)$amount, 2, '.', ',') . ' M.N.'; }
}
if (!function_exists('pats_contract_default_company_data')) {
  function pats_contract_default_company_data(): array {
    return [
      'prestador_razon_social'      => 'CRT OPERADORES DE HOSPITALES, SOCIEDAD ANÓNIMA DE CAPITAL VARIABLE',
      'prestador_representante'     => 'PEDRO AZCÁRRAGA RAMOS',
      'prestador_domicilio'         => 'CALLE PASEO SINFONÍA NÚMERO EXTERIOR 1, LOCAL 2-14, LOMAS DE ANGELÓPOLIS II, SAN ANDRÉS CHOLULA, ESTADO DE PUEBLA, CÓDIGO POSTAL 72830',
      'prestador_rfc'               => 'COH2202144H5',
      'prestador_escritura_numero'  => '39,861',
      'prestador_escritura_volumen' => '830',
      'prestador_escritura_fecha'   => 'catorce de febrero de dos mil veintidós',
      'prestador_notario_nombre'    => 'Ernesto Joaquín Briones Amador',
      'prestador_notaria_numero'    => '46',
      'prestador_folio_mercantil'   => 'N-2022022991',
      'prestador_cuenta_pago'       => '01600866709',
      'prestador_clabe_pago'        => '042650016008667096',
      'prestador_banco_pago'        => 'Banca Mifel, S.A., Institución de Banca Múltiple, Grupo Financiero Mifel',
      'prestador_correo_contacto'   => 'silvia@pasaporteatusalud.com',
      'ciudad_firma'                => 'Puebla',
      'estado_firma'                => 'Puebla',
      'monto_mensual'               => '800.00',
      'monto_reactivacion'          => '100.00',
      'vigencia_dias'               => '30',
      'contrato_version'            => '2.0',
    ];
  }
}
if (!function_exists('pats_contract_build_data')) {
  function pats_contract_build_data(array $payload): array {
    $company = pats_contract_default_company_data();
    $fullName = pats_contract_build_full_name($payload);
    $fullAddress = pats_contract_build_full_address($payload);
    $fechaFirma = new DateTime('now');
    $dia = (int)$fechaFirma->format('j');
    $mesNum = (int)$fechaFirma->format('n');
    $anio = (int)$fechaFirma->format('Y');
    $mesNombre = pats_contract_month_name_es($mesNum);
    $nacionalidadTipo = pats_contract_upper($payload['nacionalidad_tipo'] ?? 'MEXICANA');
    $esMexicano = ($nacionalidadTipo === 'MEXICANA');
    $esMenor = (string)($payload['es_menor'] ?? '0') === '1';
    $esDependiente = (string)($payload['es_dependiente_representado'] ?? '0') === '1';
    $requiereResponsable = (string)($payload['requiere_responsable'] ?? '0') === '1' || $esMenor || $esDependiente;
    $esAdultoMayor = (string)($payload['es_adulto_mayor'] ?? '0') === '1';
    $tutorNombre = pats_contract_clean($payload['tutor_nombre_completo'] ?? '');
    $firmante = ($requiereResponsable && $tutorNombre !== '') ? $tutorNombre : $fullName;
    $monto = (float)($payload['monto_orden'] ?? $company['monto_mensual']);
    $frecuencia = pats_contract_upper($payload['frecuencia'] ?? 'MENSUAL');
    $idText = $esMexicano
      ? 'credencial para votar vigente expedida por el Instituto Nacional Electoral'
      : 'documento oficial de identidad o pasaporte vigente expedido por autoridad competente';
    $curpText = $esMexicano ? pats_contract_clean($payload['curp_usuario'] ?? '') : 'No aplica por nacionalidad extranjera / no mexicana';
    $rfcText = $esMexicano ? (pats_contract_clean($payload['rfc_usuario'] ?? '') ?: 'Pendiente / no proporcionado') : 'No aplica por nacionalidad extranjera / no residente fiscal en México';
    return array_merge($company, $payload, [
      'afiliado_nombre_completo'    => $fullName,
      'afiliado_nacionalidad'       => pats_contract_clean($payload['nacionalidad'] ?? ($esMexicano ? 'mexicana' : '')),
      'afiliado_nacionalidad_tipo'  => $nacionalidadTipo,
      'afiliado_pais_nacimiento'    => pats_contract_clean($payload['pais_nacimiento'] ?? ($esMexicano ? 'México' : '')),
      'afiliado_domicilio_completo' => $fullAddress,
      'afiliado_rfc'                => $rfcText,
      'afiliado_curp'               => $curpText,
      'afiliado_correo'             => pats_contract_clean($payload['correo_usuario_pats'] ?? ''),
      'afiliado_telefono'           => pats_contract_digits($payload['telefono_usuario'] ?? ''),
      'afiliado_tipo_identificacion'=> pats_contract_clean($payload['tipo_documento_identidad'] ?? ($esMexicano ? 'INE' : 'PASAPORTE')),
      'afiliado_pais_documento_identidad' => pats_contract_clean($payload['pais_documento_identidad'] ?? ($esMexicano ? 'México' : '')),
      'afiliado_numero_identificacion' => pats_contract_clean($payload['numero_documento_identidad'] ?? ''),
      'afiliado_identificacion_texto'=> $idText,
      'afiliado_actividad_ocupacion' => pats_contract_clean($payload['actividad_ocupacion'] ?? ''),
      'afiliado_estado_civil'        => pats_contract_clean($payload['estado_civil'] ?? ''),
      'es_menor_bool'               => $esMenor,
      'es_adulto_mayor_bool'        => $esAdultoMayor,
      'es_dependiente_bool'         => $esDependiente,
      'requiere_responsable_bool'   => $requiereResponsable,
      'modo_firma'                  => pats_contract_upper($payload['modo_firma'] ?? 'FIRMA_PROPIA'),
      'tipo_representacion'         => pats_contract_upper($payload['tipo_representacion'] ?? ($requiereResponsable ? 'RESPONSABLE_AUTORIZADO' : 'FIRMA_PROPIA')),
      'relacion_responsable_paciente' => pats_contract_clean($payload['relacion_responsable_paciente'] ?? ''),
      'motivo_responsable'          => pats_contract_clean($payload['motivo_responsable'] ?? ''),
      'monto_contrato'              => pats_money_es($monto),
      'frecuencia_texto'            => $frecuencia === 'ANUAL' ? 'anual' : 'mensual',
      'vigencia_texto'              => $frecuencia === 'ANUAL' ? '12 meses' : $company['vigencia_dias'] . ' días',
      'cuota_reactivacion_texto'    => pats_money_es($company['monto_reactivacion']),
      'dia_firma'                   => (string)$dia,
      'mes_firma'                   => $mesNombre,
      'anio_firma'                  => (string)$anio,
      'fecha_firma_larga'           => $dia . ' de ' . $mesNombre . ' de ' . $anio,
      'nombre_firmante'             => $firmante,
      'firma_afiliado_base64'       => pats_contract_clean($payload['firma_base64'] ?? ''),
      'tutor_nombre_completo'       => $tutorNombre,
      'tutor_curp'                  => pats_contract_upper($payload['tutor_curp'] ?? ''),
      'tutor_rfc'                   => pats_contract_upper($payload['tutor_rfc'] ?? ''),
      'tutor_nacionalidad_tipo'     => pats_contract_upper($payload['tutor_nacionalidad_tipo'] ?? 'MEXICANA'),
      'tutor_nacionalidad'          => pats_contract_clean($payload['tutor_nacionalidad'] ?? 'mexicana'),
      'tutor_tipo_identificacion'   => pats_contract_clean($payload['tutor_tipo_documento_identidad'] ?? 'INE'),
      'tutor_numero_identificacion' => pats_contract_clean($payload['tutor_numero_documento_identidad'] ?? ''),
    ]);
  }
}
if (!function_exists('pats_render_contract_html')) {
  function pats_render_contract_html(array $payload): string {
    $template = __DIR__ . '/../templates/contrato_pats_base.php';
    if (!is_file($template)) throw new RuntimeException('No existe la plantilla base del contrato PATS');
    $data = pats_contract_build_data($payload);

    /* Firma del representante legal — se lee del disco y se embebe como data URI */
    $_firmaPath = __DIR__ . '/../assets/firma.jpeg';
    $data['prestador_firma_base64'] = is_file($_firmaPath)
      ? 'data:image/jpeg;base64,' . base64_encode((string)file_get_contents($_firmaPath))
      : '';

    ob_start(); require $template; return (string)ob_get_clean();
  }
}
if (!function_exists('pats_contract_hash')) {
  function pats_contract_hash(string $html): string { return hash('sha256', $html); }
}



if (!function_exists('pats_contract_hash')) {
  function pats_contract_hash(string $html): string {
    return hash('sha256', $html);
  }
}

if (!function_exists('pats_save_signed_contract_record')) {
  function pats_save_signed_contract_record(mysqli $cx, array $meta, string $html): int {
    $stmt = $cx->prepare("
      INSERT INTO pats_contratos_firmados
      (
        id_orden, id_pasaporte, id_distribuidor, id_franquicia, token_publico,
        contrato_clave, contrato_version,
        html_contrato_renderizado, firma_afiliado_base64, nombre_firmante,
        hash_contrato, fecha_firma, ip_firma, user_agent_firma,
        pdf_path, estatus, created_at, updated_at
      )
      VALUES
      (
        ?, ?, ?, ?, ?,
        ?, ?,
        ?, ?, ?,
        ?, NOW(), ?, ?,
        ?, 'firmado', NOW(), NOW()
      )
    ");

    if (!$stmt) {
      throw new RuntimeException('No fue posible preparar pats_contratos_firmados: ' . $cx->error);
    }

    $idOrden = (int)($meta['id_orden'] ?? 0);
    $idPasaporte = !empty($meta['id_pasaporte']) ? (int)$meta['id_pasaporte'] : null;
    $idDistribuidor = (int)($meta['id_distribuidor'] ?? 0);
    $idFranquicia = (int)($meta['id_franquicia'] ?? 0);
    $tokenPublico = (string)($meta['token_publico'] ?? '');
    $contratoClave = (string)($meta['contrato_clave'] ?? 'contrato_pats_base');
    $contratoVersion = (string)($meta['contrato_version'] ?? '1.0');
    $firmaBase64 = (string)($meta['firma_afiliado_base64'] ?? '');
    $nombreFirmante = (string)($meta['nombre_firmante'] ?? '');
    $hash = pats_contract_hash($html);
    $ipFirma = (string)($meta['ip_firma'] ?? '');
    $userAgent = (string)($meta['user_agent_firma'] ?? '');
    $pdfPath = (string)($meta['pdf_path'] ?? '');

    $stmt->bind_param(
      'iiiissssssssss',
      $idOrden,
      $idPasaporte,
      $idDistribuidor,
      $idFranquicia,
      $tokenPublico,
      $contratoClave,
      $contratoVersion,
      $html,
      $firmaBase64,
      $nombreFirmante,
      $hash,
      $ipFirma,
      $userAgent,
      $pdfPath
    );

    if (!$stmt->execute()) {
      throw new RuntimeException('No fue posible guardar el contrato firmado: ' . $stmt->error);
    }

    $id = (int)$stmt->insert_id;
    $stmt->close();

    return $id;
  }
}