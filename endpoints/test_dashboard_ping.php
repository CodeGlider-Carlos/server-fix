<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
  'ok' => true,
  'archivo' => __FILE__,
  'hora' => date('Y-m-d H:i:s')
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);