<?php
require_once __DIR__ . '/bootstrap.php';

function j(array $p, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function s($v): string {
  return trim((string)($v ?? ''));
}

function money($v): string {
  return '$' . number_format((float)$v, 2);
}

$nombre = s($_POST['nombre'] ?? '');
$razonSocial = s($_POST['razon_social'] ?? '');
$rfc = s($_POST['rfc'] ?? '');
$telefono = s($_POST['telefono'] ?? '');
$correo = s($_POST['correo'] ?? '');
$direccion = s($_POST['direccion'] ?? '');
$pais = s($_POST['pais'] ?? 'México');
$region = s($_POST['region'] ?? '');
$zona = s($_POST['zona'] ?? '');
$unidad = s($_POST['unidad'] ?? '');
$modalidad = s($_POST['modalidad_pago'] ?? 'CONTADO');
$valorTotal = (float)($_POST['valor_total'] ?? 0);
$enganche = (float)($_POST['enganche'] ?? 0);
$saldo = (float)($_POST['saldo_financiado'] ?? 0);
$plazo = (int)($_POST['plazo_meses'] ?? 0);
$periodicidad = s($_POST['periodicidad'] ?? 'MENSUAL');
$fechaInicio = s($_POST['fecha_inicio'] ?? '');
$fechaPrimerVenc = s($_POST['fecha_primer_vencimiento'] ?? '');
$firma = s($_POST['firma_base64'] ?? '');

$fechaLarga = $fechaInicio !== '' ? date('d/m/Y', strtotime($fechaInicio)) : 'pendiente de definir';

$html = '
  <h3>Contrato comercial de distribución PATS</h3>

  <h4>Solicitante</h4>
  <p><strong>Nombre:</strong> ' . htmlspecialchars($nombre ?: '-', ENT_QUOTES, 'UTF-8') . '</p>
  <p><strong>Razón social:</strong> ' . htmlspecialchars($razonSocial ?: '-', ENT_QUOTES, 'UTF-8') . '</p>
  <p><strong>RFC:</strong> ' . htmlspecialchars($rfc ?: '-', ENT_QUOTES, 'UTF-8') . '</p>
  <p><strong>Correo:</strong> ' . htmlspecialchars($correo ?: '-', ENT_QUOTES, 'UTF-8') . '</p>
  <p><strong>Teléfono:</strong> ' . htmlspecialchars($telefono ?: '-', ENT_QUOTES, 'UTF-8') . '</p>
  <p><strong>Dirección:</strong> ' . htmlspecialchars($direccion ?: '-', ENT_QUOTES, 'UTF-8') . '</p>

  <h4>Contexto comercial</h4>
  <p><strong>País:</strong> ' . htmlspecialchars($pais, ENT_QUOTES, 'UTF-8') . '</p>
  <p><strong>Región:</strong> ' . htmlspecialchars($region ?: '-', ENT_QUOTES, 'UTF-8') . '</p>
  <p><strong>Zona:</strong> ' . htmlspecialchars($zona ?: '-', ENT_QUOTES, 'UTF-8') . '</p>
  <p><strong>Unidad:</strong> ' . htmlspecialchars($unidad ?: '-', ENT_QUOTES, 'UTF-8') . '</p>

  <h4>Condiciones financieras</h4>
  <p><strong>Modalidad:</strong> ' . htmlspecialchars($modalidad, ENT_QUOTES, 'UTF-8') . '</p>
  <p><strong>Valor total:</strong> ' . money($valorTotal) . '</p>
  <p><strong>Enganche:</strong> ' . money($enganche) . '</p>
  <p><strong>Saldo financiado:</strong> ' . money($saldo) . '</p>
  <p><strong>Plazo:</strong> ' . (int)$plazo . ' meses</p>
  <p><strong>Periodicidad:</strong> ' . htmlspecialchars($periodicidad, ENT_QUOTES, 'UTF-8') . '</p>
  <p><strong>Fecha de inicio:</strong> ' . htmlspecialchars($fechaInicio ?: '-', ENT_QUOTES, 'UTF-8') . '</p>
  <p><strong>Primer vencimiento:</strong> ' . htmlspecialchars($fechaPrimerVenc ?: '-', ENT_QUOTES, 'UTF-8') . '</p>

  <h4>Aceptación</h4>
  <p>
    El solicitante reconoce y acepta el esquema comercial de distribución, la validación documental,
    el proceso de pago y la activación posterior a confirmación.
  </p>

  <p>
    Leído y entendido, se presenta para firma electrónica el día
    <strong>' . htmlspecialchars($fechaLarga, ENT_QUOTES, 'UTF-8') . '</strong>.
  </p>';

if ($firma !== '') {
  $html .= '
    <h4>Firma capturada</h4>
    <p>Se ha detectado una firma electrónica dibujada en pantalla.</p>';
}

j([
  'ok' => true,
  'html' => $html
]);