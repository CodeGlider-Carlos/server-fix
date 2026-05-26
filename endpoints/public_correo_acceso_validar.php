<?php
/*
ez/pats/endpoints/public_correo_acceso_validar.php

Valida desde Paso 1 si un correo ya existe como usuario PATS.

Objetivo:
- Evitar que el usuario avance si el correo ya está registrado.
- Evitar crear PaymentIntent/cobro en Stripe con un correo duplicado.
- Validar tanto pats_pasaporte_accesos.correo_usuario como respaldo pats_pasaportes.correo.

Respuesta:
{
  "ok": true,
  "disponible": true|false,
  "existe": true|false,
  "mensaje": "..."
}
*/

declare(strict_types=1);

require_once '../../../varSQL/bd_pats.php';
require_once '../../../varSQL/var_pats.php';

mysqli_report(MYSQLI_REPORT_OFF);

header('Content-Type: application/json; charset=utf-8');

$cx = $conexionMod ?? $db_all2 ?? $db_all ?? null;

function pcav_json(array $payload, int $code = 200): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function pcav_clean($v): string {
  return trim((string)($v ?? ''));
}

function pcav_table_exists(mysqli $cx, string $table): bool {
  $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
  if ($safe === '') return false;

  $rs = $cx->query("SHOW TABLES LIKE '" . $cx->real_escape_string($safe) . "'");
  if (!$rs) return false;

  $ok = $rs->num_rows > 0;
  $rs->free();

  return $ok;
}

function pcav_correo_existe_en_accesos(mysqli $cx, string $correo): bool {
  if (!pcav_table_exists($cx, 'pats_pasaporte_accesos')) {
    return false;
  }

  $stmt = $cx->prepare("
    SELECT id_acceso
    FROM pats_pasaporte_accesos
    WHERE LOWER(TRIM(correo_usuario)) = ?
      AND activo = 1
    LIMIT 1
  ");

  if (!$stmt) {
    throw new RuntimeException('No fue posible consultar accesos: ' . $cx->error);
  }

  $stmt->bind_param('s', $correo);
  $stmt->execute();

  $rs = $stmt->get_result();
  $existe = $rs && $rs->num_rows > 0;

  $stmt->close();

  return $existe;
}

function pcav_correo_existe_en_pasaportes(mysqli $cx, string $correo): bool {
  if (!pcav_table_exists($cx, 'pats_pasaportes')) {
    return false;
  }

  $stmt = $cx->prepare("
    SELECT id_pasaporte
    FROM pats_pasaportes
    WHERE LOWER(TRIM(correo)) = ?
      AND activo = 1
      AND LOWER(TRIM(COALESCE(estatus, ''))) NOT IN ('cancelado', 'baja', 'inactivo')
    LIMIT 1
  ");

  if (!$stmt) {
    throw new RuntimeException('No fue posible consultar pasaportes: ' . $cx->error);
  }

  $stmt->bind_param('s', $correo);
  $stmt->execute();

  $rs = $stmt->get_result();
  $existe = $rs && $rs->num_rows > 0;

  $stmt->close();

  return $existe;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  pcav_json([
    'ok' => false,
    'disponible' => false,
    'error' => 'Método no permitido.'
  ], 405);
}

if (!$cx || !($cx instanceof mysqli)) {
  pcav_json([
    'ok' => false,
    'disponible' => false,
    'error' => 'No hay conexión mysqli disponible.'
  ], 500);
}

$correo = strtolower(pcav_clean($_POST['correo'] ?? ''));

if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
  pcav_json([
    'ok' => false,
    'disponible' => false,
    'error' => 'Correo no válido.'
  ], 422);
}

try {
  $existeAcceso = pcav_correo_existe_en_accesos($cx, $correo);
  $existePasaporte = pcav_correo_existe_en_pasaportes($cx, $correo);
  $existe = $existeAcceso || $existePasaporte;

  pcav_json([
    'ok' => true,
    'disponible' => !$existe,
    'existe' => $existe,
    'existe_en_accesos' => $existeAcceso,
    'existe_en_pasaportes' => $existePasaporte,
    'mensaje' => $existe
      ? 'Este correo ya tiene un Pasaporte PATS registrado. Usa otro correo o recupera tu acceso.'
      : 'Correo disponible.'
  ]);
} catch (Throwable $e) {
  pcav_json([
    'ok' => false,
    'disponible' => false,
    'error' => $e->getMessage()
  ], 500);
}
