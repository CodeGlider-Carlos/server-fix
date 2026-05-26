<?php
/*
  ez/pats/endpoints/public_pasaporte_vigente_validar.php

  Endpoint público auxiliar para solicitud_pats.php.

  OBJETIVO OPERATIVO:
  Para permitir alta de ADULTO MAYOR (>=65 años), el formulario debe validar
  que existan 2 pasaportes vigentes previos. Cada validación se hace con:
  - id_pasaporte
  - fecha_nacimiento

  TABLA CONSULTADA:
  pats_pasaportes

  CRITERIO DE VIGENCIA:
  - id_pasaporte exacto
  - fecha_nacimiento exacta
  - activo = 1
  - estatus = 'activo'
  - vigencia >= CURDATE()

  IMPORTANTE PARA EL SIGUIENTE DESARROLLADOR:
  Este endpoint NO crea relaciones ni guarda todavía el requisito del adulto mayor.
  Solo valida y devuelve los datos mínimos del pasaporte vigente.

  PENDIENTE BACKEND:
  En public_checkout_generar_orden.php, al guardar la solicitud/orden/pasaporte,
  persistir los 2 id_pasaporte validados en la tabla definitiva que corresponda,
  idealmente una tabla puente/auditable, por ejemplo:
    pats_pasaporte_requisitos_adulto_mayor
  con columnas sugeridas:
    id, id_orden, id_pasaporte_nuevo, id_pasaporte_requisito,
    fecha_nacimiento_validada, validado_en, created_at
*/

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

header('Content-Type: application/json; charset=utf-8');

function out_json(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

require_once '../../../varSQL/bd_pats.php';
require_once '../../../varSQL/var_pats.php';

$cx = $conexionMod ?? $db_all2 ?? $db_all ?? null;
if (!$cx || !($cx instanceof mysqli)) {
  out_json(['ok' => false, 'error' => 'No hay conexión mysqli disponible'], 500);
}

$idPasaporte = (int)preg_replace('/\D+/', '', (string)($_POST['id_pasaporte'] ?? ''));
$fechaNacimiento = trim((string)($_POST['fecha_nacimiento'] ?? ''));

if ($idPasaporte <= 0) {
  out_json(['ok' => false, 'error' => 'ID de pasaporte inválido'], 422);
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaNacimiento)) {
  out_json(['ok' => false, 'error' => 'Fecha de nacimiento inválida'], 422);
}

$stmt = $cx->prepare("\n  SELECT\n    id_pasaporte, curp, nombres, apellido_pa, apellido_ma, fecha_nacimiento,\n    telefono, correo, vigencia, estatus, activo, region, zona, unidad\n  FROM pats_pasaportes\n  WHERE id_pasaporte = ?\n    AND fecha_nacimiento = ?\n    AND activo = 1\n    AND LOWER(estatus) = 'activo'\n    AND vigencia >= CURDATE()\n  LIMIT 1\n");

if (!$stmt) {
  out_json(['ok' => false, 'error' => 'No fue posible preparar validación: ' . $cx->error], 500);
}

$stmt->bind_param('is', $idPasaporte, $fechaNacimiento);
$stmt->execute();
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$row) {
  out_json([
    'ok' => false,
    'error' => 'No se encontró un pasaporte activo y vigente con ese ID y fecha de nacimiento.'
  ], 404);
}

out_json([
  'ok' => true,
  'pasaporte' => [
    'id_pasaporte' => (int)$row['id_pasaporte'],
    'curp' => (string)$row['curp'],
    'nombres' => (string)$row['nombres'],
    'apellido_pa' => (string)$row['apellido_pa'],
    'apellido_ma' => (string)$row['apellido_ma'],
    'fecha_nacimiento' => (string)$row['fecha_nacimiento'],
    'vigencia' => (string)$row['vigencia'],
    'estatus' => (string)$row['estatus'],
    'region' => (string)($row['region'] ?? ''),
    'zona' => (string)($row['zona'] ?? ''),
    'unidad' => (string)($row['unidad'] ?? ''),
  ]
]);
