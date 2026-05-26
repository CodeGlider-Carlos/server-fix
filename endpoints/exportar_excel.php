<?php
/*
ez/pats/endpoints/exportar_excel.php
*/
require_once __DIR__ . '/bootstrap.php';

pats_json([
  'ok' => true,
  'message' => 'Base de exportación preparada. Aquí irá la salida XLSX/CSV según vista y filtros.'
]);