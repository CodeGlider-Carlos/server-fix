<?php
/*
ez/pats/solicitud_distribuidor.php
*/
session_start();
require_once '../../varSQL/bd_pats.php';
require_once '../../varSQL/var_pats.php';

if (empty($_SESSION['usuario'])) {
  session_destroy();
  header("Location: ../../../index.php");
  exit;
}

$ver = time();
$userRegion = trim($_SESSION['acroregion'] ?? ($_SESSION['region'] ?? ''));
$userUnidad = trim($_SESSION['acronu'] ?? ($_SESSION['unidad'] ?? ''));

$idSolicitudEditar = (int)($_GET['id_solicitud'] ?? 0);
$idFranquiciaDefault = (int)($_GET['id_franquicia'] ?? 0);
$idGestorDefault = (int)($_GET['id_gestor'] ?? 0);

$regionDefaultGet = trim((string)($_GET['region'] ?? ''));
$zonaDefaultGet   = trim((string)($_GET['zona'] ?? ''));
$anioDefaultGet   = trim((string)($_GET['anio'] ?? ''));
$mesDefaultGet    = trim((string)($_GET['mes'] ?? ''));
$fromDashboard    = strtolower(trim((string)($_GET['from'] ?? 'franquicia')));

$volverBase = ($fromDashboard === 'gestor') ? 'gestor.php' : 'franquicia.php';
$params = [];

if ($fromDashboard === 'gestor') {
  if ($idGestorDefault > 0) $params['id_gestor'] = $idGestorDefault;
  if ($regionDefaultGet !== '') $params['region'] = $regionDefaultGet;
  if ($anioDefaultGet !== '') $params['anio'] = $anioDefaultGet;
  if ($mesDefaultGet !== '') $params['mes'] = $mesDefaultGet;
} else {
  if ($idFranquiciaDefault > 0) $params['id_franquicia'] = $idFranquiciaDefault;
  if ($regionDefaultGet !== '') $params['region'] = $regionDefaultGet;
  if ($zonaDefaultGet !== '') $params['zona'] = $zonaDefaultGet;
  if ($anioDefaultGet !== '') $params['anio'] = $anioDefaultGet;
  if ($mesDefaultGet !== '') $params['mes'] = $mesDefaultGet;
}

$volverHref = $volverBase . (!empty($params) ? ('?' . http_build_query($params)) : '');

function e($v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$estadosCatalogo = [
  'AGS'  => 'Aguascalientes',
  'BCN'  => 'Baja California',
  'BCS'  => 'Baja California Sur',
  'CAM'  => 'Campeche',
  'CHP'  => 'Chiapas',
  'CHH'  => 'Chihuahua',
  'CDMX' => 'Ciudad de México',
  'COA'  => 'Coahuila',
  'COL'  => 'Colima',
  'DUR'  => 'Durango',
  'MEX'  => 'Estado de México',
  'GTO'  => 'Guanajuato',
  'GRO'  => 'Guerrero',
  'HGO'  => 'Hidalgo',
  'JAL'  => 'Jalisco',
  'MIC'  => 'Michoacán',
  'MOR'  => 'Morelos',
  'NAY'  => 'Nayarit',
  'NLE'  => 'Nuevo León',
  'OAX'  => 'Oaxaca',
  'PUE'  => 'Puebla',
  'QRO'  => 'Querétaro',
  'ROO'  => 'Quintana Roo',
  'SLP'  => 'San Luis Potosí',
  'SIN'  => 'Sinaloa',
  'SON'  => 'Sonora',
  'TAB'  => 'Tabasco',
  'TAM'  => 'Tamaulipas',
  'TLAX' => 'Tlaxcala',
  'VER'  => 'Veracruz',
  'YUC'  => 'Yucatán',
  'ZAC'  => 'Zacatecas'
];

$aliasEstadoToCode = [];
foreach ($estadosCatalogo as $code => $label) {
  $aliasEstadoToCode[mb_strtolower($code)] = $code;
  $aliasEstadoToCode[mb_strtolower($label)] = $code;
}

$zonasRaw = [];
$zonasPorEstado = [];
$franquicias = [];
$franquiciaInicial = null;
$solicitudEditar = null;
$docsActuales = [];
$precioDistribucion = 20000.00;

if (isset($conexionMod) && $conexionMod instanceof mysqli) {
  $sqlPrecio = "
    SELECT precio
    FROM pats_cat_precios
    WHERE LOWER(TRIM(tipo)) = 'distribucion'
    ORDER BY id ASC
    LIMIT 1
  ";
  $rsPrecio = $conexionMod->query($sqlPrecio);
  if ($rsPrecio && $rsPrecio->num_rows > 0) {
    $rowPrecio = $rsPrecio->fetch_assoc();
    $precioDistribucion = (float)($rowPrecio['precio'] ?? 20000);
  }
  if ($rsPrecio) $rsPrecio->free();

  $sqlZonas = "
    SELECT DISTINCT region, zona
    FROM pats_franquicias
    WHERE TRIM(COALESCE(region,'')) <> ''
      AND TRIM(COALESCE(zona,'')) <> ''
    ORDER BY region ASC, zona ASC
  ";
  $rsZ = $conexionMod->query($sqlZonas);
  if ($rsZ) {
    while ($row = $rsZ->fetch_assoc()) {
      $zonasRaw[] = $row;
    }
    $rsZ->free();
  }

  foreach ($zonasRaw as $zr) {
    $regionRaw = trim((string)($zr['region'] ?? ''));
    $zonaRaw   = trim((string)($zr['zona'] ?? ''));

    if ($regionRaw === '' || $zonaRaw === '') continue;

    $regionKey = $aliasEstadoToCode[mb_strtolower($regionRaw)] ?? null;
    if (!$regionKey) continue;

    if (!isset($zonasPorEstado[$regionKey])) {
      $zonasPorEstado[$regionKey] = [];
    }

    $zonaCanon = mb_strtoupper($zonaRaw);
    if (!in_array($zonaCanon, $zonasPorEstado[$regionKey], true)) {
      $zonasPorEstado[$regionKey][] = $zonaCanon;
    }
  }

  if ($idSolicitudEditar > 0) {
    $sqlEdit = "
      SELECT *
      FROM pats_solicitudes_distribuidor
      WHERE id_solicitud = " . (int)$idSolicitudEditar . "
        AND activo = 1
      LIMIT 1
    ";
    $rsEdit = $conexionMod->query($sqlEdit);
    if ($rsEdit && $rsEdit->num_rows > 0) {
      $solicitudEditar = $rsEdit->fetch_assoc();
    }
    if ($rsEdit) $rsEdit->free();

    if ($solicitudEditar && strtoupper((string)($solicitudEditar['estatus'] ?? '')) === 'ENVIADA') {
      $idFranquiciaDefault = (int)($solicitudEditar['id_franquicia'] ?? $idFranquiciaDefault);
      $regionDefaultGet = trim((string)($solicitudEditar['region'] ?? $regionDefaultGet));
      $zonaDefaultGet   = trim((string)($solicitudEditar['zona'] ?? $zonaDefaultGet));

      $sqlDocs = "
        SELECT tipo_documento, archivo_path, archivo_nombre_original, mime_type, size_kb
        FROM pats_solicitudes_distribuidor_documentos
        WHERE id_solicitud = " . (int)$idSolicitudEditar . "
          AND vigente = 1
      ";
      $rsDocs = $conexionMod->query($sqlDocs);
      if ($rsDocs) {
        while ($row = $rsDocs->fetch_assoc()) {
          $docsActuales[strtoupper((string)$row['tipo_documento'])] = $row;
        }
        $rsDocs->free();
      }
    } else {
      $solicitudEditar = null;
      $idSolicitudEditar = 0;
    }
  }

  if ($idFranquiciaDefault > 0) {
    $sqlDef = "
      SELECT
        id_franquicia,
        nombre_franquicia,
        franquiciatario,
        pais,
        region,
        zona,
        unidad
      FROM pats_franquicias
      WHERE activo = 1
        AND id_franquicia = " . (int)$idFranquiciaDefault . "
      LIMIT 1
    ";
    $rsDef = $conexionMod->query($sqlDef);
    if ($rsDef && $rsDef->num_rows > 0) {
      $franquiciaInicial = $rsDef->fetch_assoc();
      $franquicias[] = $franquiciaInicial;
    }
    if ($rsDef) $rsDef->free();
  }

  $sql = "
    SELECT
      id_franquicia,
      nombre_franquicia,
      franquiciatario,
      pais,
      region,
      zona,
      unidad
    FROM pats_franquicias
    WHERE activo = 1
  ";

  if ($userRegion !== '') {
    $sql .= " AND region = '" . $conexionMod->real_escape_string($userRegion) . "'";
  }
  if ($userUnidad !== '') {
    $sql .= " AND unidad = '" . $conexionMod->real_escape_string($userUnidad) . "'";
  }
  if ($idFranquiciaDefault > 0) {
    $sql .= " AND id_franquicia <> " . (int)$idFranquiciaDefault;
  }

  $sql .= " ORDER BY nombre_franquicia ASC";

  $rs = $conexionMod->query($sql);
  if ($rs) {
    while ($row = $rs->fetch_assoc()) {
      $franquicias[] = $row;
    }
    $rs->free();
  }
}

if (!$franquiciaInicial) {
  $franquiciaInicial = $franquicias[0] ?? null;
}

$tipoPersona = strtoupper((string)($solicitudEditar['tipo_persona'] ?? 'FISICA'));
$modalidadPago = strtoupper((string)($solicitudEditar['modalidad_pago'] ?? 'CONTADO'));
$periodicidad = strtoupper((string)($solicitudEditar['periodicidad'] ?? 'MENSUAL'));
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Solicitud de distribuidor · PATS</title>
  <link rel="icon" type="image/x-icon" href="../../img/ez.ico" />
  <link rel="stylesheet" href="../../css/index.css?v=<?= $ver ?>">
  <link rel="stylesheet" href="../../css/gloval_responsive.css?v=<?= $ver ?>">
  <link rel="stylesheet" href="css/pats.css?v=<?= $ver ?>">
  <link rel="stylesheet" href="css/pats_responsive.css?v=<?= $ver ?>">
  <style>
    .solfrm-toast{
      position:fixed;
      top:16px;
      right:16px;
      z-index:99999;
      display:none;
      min-width:260px;
      max-width:340px;
      padding:12px 14px;
      border-radius:16px;
      color:#fff;
      font-size:13px;
      font-weight:800;
      box-shadow:0 18px 38px rgba(8,12,28,.24);
      background:linear-gradient(135deg, rgba(12,21,46,.96), rgba(36,28,74,.96));
    }
    .solfrm-toast.is-open{ display:block; }

    .sol-doc-current{
      margin-top:10px;
      display:flex;
      gap:8px;
      flex-wrap:wrap;
      align-items:center;
    }
    .sol-doc-current__label{
      font-size:12px;
      font-weight:800;
      color:#5e74a7;
    }
    .sol-doc-current__link{
      display:inline-flex;
      align-items:center;
      gap:6px;
      min-height:28px;
      padding:0 10px;
      border-radius:999px;
      font-size:11px;
      font-weight:900;
      letter-spacing:.03em;
      text-transform:uppercase;
      color:#264c90;
      background:rgba(74,103,167,.08);
      border:1px solid rgba(74,103,167,.12);
      text-decoration:none;
    }
    .sol-doc-current__link:hover{
      background:rgba(74,103,167,.12);
    }
    .sol-inline-note{
      margin-top:10px;
      font-size:12px;
      line-height:1.45;
      color:#62739f;
      font-weight:700;
    }
  </style>
</head>
<body>

<?php require_once '../../loglog/contador.php'; ?>

<div id="cabecera">
  <div class="bienvenido">PATS · Solicitud de distribuidor</div>
  <?php require_once '../nav/noti_user_pats.php'; ?>
</div>

<?php require_once '../nav/nav_mod.php'; ?>

<content class="back_content">
  <div class="pats-wrap pats-finance-form-wrap">

    <section class="pats-hero">
      <div class="pats-hero__left">
        <div class="pats-kicker">PATS · FRANQUICIA · SOLICITUD</div>
        <h1 class="pats-title"><?= $idSolicitudEditar > 0 ? 'Editar solicitud de distribuidor' : 'Nueva solicitud de distribuidor' ?></h1>
        <p class="pats-subtitle">
          <?= $idSolicitudEditar > 0
            ? 'Actualiza la solicitud antes de que pase a validación.'
            : 'Captura los datos, documentación y esquema financiero para revisión de ADMIN PATS.' ?>
        </p>
      </div>
    </section>

    <form id="frmSolicitudDistribuidor" class="pats-finance-form pats-wizard-form" enctype="multipart/form-data" novalidate>
      <input type="hidden" name="id_solicitud" id="id_solicitud" value="<?= (int)$idSolicitudEditar ?>">

      <article class="pats-card pats-wizard-shell">
        <div class="pats-card__head">
          <div>
            <h3><?= $idSolicitudEditar > 0 ? 'Editar solicitud' : 'Nueva solicitud' ?></h3>
            <p><?= $idSolicitudEditar > 0 ? 'Actualiza los datos manteniendo la nueva estructura por pasos.' : 'Completa los 5 pasos para registrar la solicitud de distribuidor.' ?></p>
          </div>
        </div>

        <div class="pats-wizard-steps" id="patsWizardSteps">
          <button type="button" class="pats-step is-active" data-step="1">
            <span class="pats-step__num">1</span>
            <span class="pats-step__label">Franquicia</span>
          </button>
          <button type="button" class="pats-step" data-step="2">
            <span class="pats-step__num">2</span>
            <span class="pats-step__label">Distribuidor</span>
          </button>
          <button type="button" class="pats-step" data-step="3">
            <span class="pats-step__num">3</span>
            <span class="pats-step__label">Datos bancarios</span>
          </button>
          <button type="button" class="pats-step" data-step="4">
            <span class="pats-step__num">4</span>
            <span class="pats-step__label">Documentación</span>
          </button>
          <button type="button" class="pats-step" data-step="5">
            <span class="pats-step__num">5</span>
            <span class="pats-step__label">Finanzas</span>
          </button>
        </div>

        <div class="pats-wizard-body">

          <!-- PASO 1 -->
          <section class="pats-wizard-panel is-active" data-step-panel="1">
            <div class="pats-step-block">
              <div class="pats-step-block__title">Franquicia y contexto</div>

              <div class="pats-inline-info">
                La solicitud quedará asociada a la franquicia seleccionada. Puedes ajustar estado y zona antes de enviar.
              </div>

              <div class="pats-finance-fields">
                <div class="pats-field pats-field--full">
                  <label for="id_franquicia">Franquicia</label>
                  <select id="id_franquicia" name="id_franquicia" required>
                    <option value="">Selecciona...</option>
                    <?php foreach ($franquicias as $f): ?>
                      <option
                        value="<?= (int)$f['id_franquicia'] ?>"
                        data-pais="<?= e($f['pais']) ?>"
                        data-region="<?= e($f['region']) ?>"
                        data-zona="<?= e($f['zona']) ?>"
                        data-unidad="<?= e($f['unidad']) ?>"
                        <?= ($franquiciaInicial && (int)$franquiciaInicial['id_franquicia'] === (int)$f['id_franquicia']) ? 'selected' : '' ?>
                      >
                        <?= e($f['nombre_franquicia']) ?> — <?= e($f['franquiciatario']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="pats-field">
                  <label for="pais">País</label>
                  <input type="text" id="pais" name="pais" readonly required>
                </div>

                <div class="pats-field">
                  <label for="region">Estado / Región</label>
                  <select id="region" name="region" required></select>
                </div>

                <div class="pats-field">
                  <label for="zona">Municipio / Zona</label>
                  <select id="zona" name="zona" required></select>
                </div>

                <div class="pats-field" id="zona_nueva_wrap" hidden>
                  <label for="zona_nueva">Nueva zona</label>
                  <input type="text" id="zona_nueva" name="zona_nueva" placeholder="Captura nueva zona">
                </div>

                <div class="pats-field">
                  <label for="unidad">Unidad</label>
                  <input type="text" id="unidad" name="unidad" readonly>
                </div>
              </div>
            </div>
          </section>

          <!-- PASO 2 -->
          <section class="pats-wizard-panel" data-step-panel="2" hidden>
            <div class="pats-step-block">
              <div class="pats-step-block__title">Datos del distribuidor</div>

              <div class="pats-inline-info">
                Captura los datos generales del distribuidor. La clasificación física o moral y la razón social se definen en documentación.
              </div>

              <div class="pats-finance-fields">
                <div class="pats-field pats-field--full">
                  <label for="nombre">Nombre del distribuidor</label>
                  <input type="text" id="nombre" name="nombre" value="<?= e($solicitudEditar['nombre'] ?? '') ?>" required>
                </div>

                <div class="pats-field">
                  <label for="rfc">RFC</label>
                  <input type="text" id="rfc" name="rfc" value="<?= e($solicitudEditar['rfc'] ?? '') ?>">
                </div>

                <div class="pats-field">
                  <label for="telefono">Teléfono</label>
                  <input type="text" id="telefono" name="telefono" maxlength="10" inputmode="numeric" value="<?= e($solicitudEditar['telefono'] ?? '') ?>" required>
                </div>

                <div class="pats-field pats-field--full">
                  <label for="correo">Correo</label>
                  <input type="email" id="correo" name="correo" value="<?= e($solicitudEditar['correo'] ?? '') ?>" required>
                </div>

                <div class="pats-field pats-field--full">
                  <label for="direccion">Dirección</label>
                  <textarea id="direccion" name="direccion" rows="3"><?= e($solicitudEditar['direccion'] ?? '') ?></textarea>
                </div>
              </div>
            </div>
          </section>

          <!-- PASO 3 -->
          <section class="pats-wizard-panel" data-step-panel="3" hidden>
            <div class="pats-step-block">
              <div class="pats-step-block__title">Datos bancarios</div>

              <div class="pats-inline-info">
                La carátula bancaria es obligatoria para poder enviar la solicitud.
              </div>

              <div class="pats-finance-fields">
                <div class="pats-field">
                  <label for="banco">Banco</label>
                  <input type="text" id="banco" name="banco" value="<?= e($solicitudEditar['banco'] ?? '') ?>">
                </div>

                <div class="pats-field">
                  <label for="numero_cuenta">Número de cuenta</label>
                  <input type="text" id="numero_cuenta" name="numero_cuenta" value="<?= e($solicitudEditar['numero_cuenta'] ?? '') ?>">
                </div>

                <div class="pats-field">
                  <label for="clabe">CLABE</label>
                  <input type="text" id="clabe" name="clabe" maxlength="18" inputmode="numeric" value="<?= e($solicitudEditar['clabe'] ?? '') ?>">
                </div>

                <div class="pats-field">
                  <label for="titular_cuenta">Titular de cuenta</label>
                  <input type="text" id="titular_cuenta" name="titular_cuenta" value="<?= e($solicitudEditar['titular_cuenta'] ?? '') ?>">
                </div>

                <div class="pats-field pats-field--full">
                  <label for="doc_caratula_bancaria">Carátula bancaria</label>
                  <label class="pats-file-upload">
                    <input type="file" id="doc_caratula_bancaria" name="doc_caratula_bancaria" accept=".pdf,.png,.jpg,.jpeg,.webp" class="pats-file-native">
                    <span class="pats-file-upload__button">Seleccionar archivo</span>
                    <span class="pats-file-upload__text">Ningún archivo seleccionado</span>
                  </label>
                  <?php if (!empty($docsActuales['CARATULA_BANCARIA']['archivo_path'])): ?>
                    <div class="sol-doc-current">
                      <span class="sol-doc-current__label">Actual:</span>
                      <a class="sol-doc-current__link"
                         data-doc-actual="doc_caratula_bancaria"
                         href="endpoints/solicitud_documento_ver.php?id_solicitud=<?= (int)$idSolicitudEditar ?>&tipo=CARATULA_BANCARIA"
                         target="_blank">Ver archivo</a>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </section>

          <!-- PASO 4 -->
          <section class="pats-wizard-panel" data-step-panel="4" hidden>
            <div class="pats-step-block">
              <div class="pats-step-block__title">Documentación</div>

              <div class="pats-inline-info">
                La documentación base es obligatoria. Si el distribuidor es persona moral, aparecerán documentos adicionales y se debe cargar al menos uno entre acta constitutiva y poder notarial.
              </div>

              <div class="pats-finance-fields">
                <div class="pats-field">
                  <label for="tipo_persona">Tipo de persona</label>
                  <select id="tipo_persona" name="tipo_persona" required>
                    <option value="FISICA" <?= $tipoPersona === 'FISICA' ? 'selected' : '' ?>>Física</option>
                    <option value="MORAL" <?= $tipoPersona === 'MORAL' ? 'selected' : '' ?>>Moral</option>
                  </select>
                </div>

                <div class="pats-field">
                  <label for="doc_ine">INE</label>
                  <label class="pats-file-upload">
                    <input type="file" id="doc_ine" name="doc_ine" accept=".pdf,.png,.jpg,.jpeg,.webp" class="pats-file-native">
                    <span class="pats-file-upload__button">Seleccionar archivo</span>
                    <span class="pats-file-upload__text">Ningún archivo seleccionado</span>
                  </label>
                  <?php if (!empty($docsActuales['INE']['archivo_path'])): ?>
                    <div class="sol-doc-current">
                      <span class="sol-doc-current__label">Actual:</span>
                      <a class="sol-doc-current__link"
                         data-doc-actual="doc_ine"
                         href="endpoints/solicitud_documento_ver.php?id_solicitud=<?= (int)$idSolicitudEditar ?>&tipo=INE"
                         target="_blank">Ver archivo</a>
                    </div>
                  <?php endif; ?>
                </div>

                <div class="pats-field">
                  <label for="doc_curp">CURP</label>
                  <label class="pats-file-upload">
                    <input type="file" id="doc_curp" name="doc_curp" accept=".pdf,.png,.jpg,.jpeg,.webp" class="pats-file-native">
                    <span class="pats-file-upload__button">Seleccionar archivo</span>
                    <span class="pats-file-upload__text">Ningún archivo seleccionado</span>
                  </label>
                  <?php if (!empty($docsActuales['CURP']['archivo_path'])): ?>
                    <div class="sol-doc-current">
                      <span class="sol-doc-current__label">Actual:</span>
                      <a class="sol-doc-current__link"
                         data-doc-actual="doc_curp"
                         href="endpoints/solicitud_documento_ver.php?id_solicitud=<?= (int)$idSolicitudEditar ?>&tipo=CURP"
                         target="_blank">Ver archivo</a>
                    </div>
                  <?php endif; ?>
                </div>

                <div class="pats-field">
                  <label for="doc_domicilio">Comprobante de domicilio</label>
                  <label class="pats-file-upload">
                    <input type="file" id="doc_domicilio" name="doc_domicilio" accept=".pdf,.png,.jpg,.jpeg,.webp" class="pats-file-native">
                    <span class="pats-file-upload__button">Seleccionar archivo</span>
                    <span class="pats-file-upload__text">Ningún archivo seleccionado</span>
                  </label>
                  <?php if (!empty($docsActuales['COMPROBANTE_DOMICILIO']['archivo_path'])): ?>
                    <div class="sol-doc-current">
                      <span class="sol-doc-current__label">Actual:</span>
                      <a class="sol-doc-current__link"
                         data-doc-actual="doc_domicilio"
                         href="endpoints/solicitud_documento_ver.php?id_solicitud=<?= (int)$idSolicitudEditar ?>&tipo=COMPROBANTE_DOMICILIO"
                         target="_blank">Ver archivo</a>
                    </div>
                  <?php endif; ?>
                </div>

                <div class="pats-field">
                  <label for="doc_cedula">Cédula fiscal</label>
                  <label class="pats-file-upload">
                    <input type="file" id="doc_cedula" name="doc_cedula" accept=".pdf,.png,.jpg,.jpeg,.webp" class="pats-file-native">
                    <span class="pats-file-upload__button">Seleccionar archivo</span>
                    <span class="pats-file-upload__text">Ningún archivo seleccionado</span>
                  </label>
                  <?php if (!empty($docsActuales['CEDULA_FISCAL']['archivo_path'])): ?>
                    <div class="sol-doc-current">
                      <span class="sol-doc-current__label">Actual:</span>
                      <a class="sol-doc-current__link"
                         data-doc-actual="doc_cedula"
                         href="endpoints/solicitud_documento_ver.php?id_solicitud=<?= (int)$idSolicitudEditar ?>&tipo=CEDULA_FISCAL"
                         target="_blank">Ver archivo</a>
                    </div>
                  <?php endif; ?>
                </div>
                <div
                id="bloqueDocumentosMoral"
                class="pats-field pats-field--full"
                style="<?= $tipoPersona === 'MORAL' ? '' : 'display:none;' ?>"
                >
                  <div class="pats-finance-fields" style="padding:0;">
                    <div class="pats-field pats-field--full">
                      <label for="razon_social">Razón social</label>
                      <input type="text" id="razon_social" name="razon_social" value="<?= e($solicitudEditar['razon_social'] ?? '') ?>">
                    </div>

                    <div class="pats-field">
                      <label for="doc_acta_constitutiva">Acta constitutiva</label>
                      <label class="pats-file-upload">
                        <input type="file" id="doc_acta_constitutiva" name="doc_acta_constitutiva" accept=".pdf,.png,.jpg,.jpeg,.webp" class="pats-file-native">
                        <span class="pats-file-upload__button">Seleccionar archivo</span>
                        <span class="pats-file-upload__text">Ningún archivo seleccionado</span>
                      </label>
                      <?php if (!empty($docsActuales['ACTA_CONSTITUTIVA']['archivo_path'])): ?>
                        <div class="sol-doc-current">
                          <span class="sol-doc-current__label">Actual:</span>
                          <a class="sol-doc-current__link"
                             data-doc-actual="doc_acta_constitutiva"
                             href="endpoints/solicitud_documento_ver.php?id_solicitud=<?= (int)$idSolicitudEditar ?>&tipo=ACTA_CONSTITUTIVA"
                             target="_blank">Ver archivo</a>
                        </div>
                      <?php endif; ?>
                    </div>

                    <div class="pats-field">
                      <label for="doc_poder_notarial">Poder notarial</label>
                      <label class="pats-file-upload">
                        <input type="file" id="doc_poder_notarial" name="doc_poder_notarial" accept=".pdf,.png,.jpg,.jpeg,.webp" class="pats-file-native">
                        <span class="pats-file-upload__button">Seleccionar archivo</span>
                        <span class="pats-file-upload__text">Ningún archivo seleccionado</span>
                      </label>
                      <?php if (!empty($docsActuales['PODER_NOTARIAL']['archivo_path'])): ?>
                        <div class="sol-doc-current">
                          <span class="sol-doc-current__label">Actual:</span>
                          <a class="sol-doc-current__link"
                             data-doc-actual="doc_poder_notarial"
                             href="endpoints/solicitud_documento_ver.php?id_solicitud=<?= (int)$idSolicitudEditar ?>&tipo=PODER_NOTARIAL"
                             target="_blank">Ver archivo</a>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>

                  <div class="sol-inline-note">
                    Para persona moral, se debe cargar al menos uno de estos dos documentos: <strong>acta constitutiva</strong> o <strong>poder notarial</strong>.
                  </div>
                </div>
              </div>
            </div>
          </section>

          <!-- PASO 5 -->
          <section class="pats-wizard-panel" data-step-panel="5" hidden>
            <div class="pats-step-block">
              <div class="pats-step-block__title">Finanzas</div>

              <div class="pats-inline-info">
                Define la modalidad de pago. El valor total se toma del catálogo y aquí se captura el esquema financiero.
              </div>

              <div class="pats-finance-fields">
                <div class="pats-field">
                  <label for="modalidad_pago">Modalidad de pago</label>
                  <select id="modalidad_pago" name="modalidad_pago" required>
                    <option value="CONTADO" <?= $modalidadPago === 'CONTADO' ? 'selected' : '' ?>>Contado</option>
                    <option value="ENGANCHE_DIFERIDO" <?= $modalidadPago === 'ENGANCHE_DIFERIDO' ? 'selected' : '' ?>>Enganche + diferido</option>
                    <option value="DIFERIDO" <?= $modalidadPago === 'DIFERIDO' ? 'selected' : '' ?>>Diferido</option>
                  </select>
                </div>

                <div class="pats-field">
                  <label for="valor_total">Valor total</label>
                  <input type="number" step="0.01" id="valor_total" name="valor_total" value="<?= e(number_format((float)($solicitudEditar['valor_total'] ?? $precioDistribucion), 2, '.', '')) ?>" readonly required>
                </div>

                <div class="pats-field">
                  <label for="enganche">Enganche</label>
                  <input type="number" step="0.01" id="enganche" name="enganche" value="<?= e(number_format((float)($solicitudEditar['enganche'] ?? 0), 2, '.', '')) ?>">
                </div>

                <div class="pats-field">
                  <label for="saldo_financiado">Saldo financiado</label>
                  <input type="number" step="0.01" id="saldo_financiado" name="saldo_financiado" value="<?= e(number_format((float)($solicitudEditar['saldo_financiado'] ?? 0), 2, '.', '')) ?>" readonly>
                </div>

                <div class="pats-field">
                  <label for="plazo_meses">Plazo (meses)</label>
                  <input type="number" id="plazo_meses" name="plazo_meses" value="<?= e((int)($solicitudEditar['plazo_meses'] ?? 0)) ?>">
                </div>

                <div class="pats-field">
                  <label for="periodicidad">Periodicidad</label>
                  <select id="periodicidad" name="periodicidad">
                    <option value="MENSUAL" <?= $periodicidad === 'MENSUAL' ? 'selected' : '' ?>>Mensual</option>
                    <option value="QUINCENAL" <?= $periodicidad === 'QUINCENAL' ? 'selected' : '' ?>>Quincenal</option>
                    <option value="SEMANAL" <?= $periodicidad === 'SEMANAL' ? 'selected' : '' ?>>Semanal</option>
                    <option value="UNICA" <?= $periodicidad === 'UNICA' ? 'selected' : '' ?>>Única</option>
                  </select>
                </div>

                <div class="pats-field">
                  <label for="fecha_inicio">Fecha de inicio</label>
                  <input type="date" id="fecha_inicio" name="fecha_inicio" value="<?= e($solicitudEditar['fecha_inicio'] ?? '') ?>" required>
                </div>

                <div class="pats-field">
                  <label for="fecha_primer_vencimiento">Primer vencimiento</label>
                  <input type="date" id="fecha_primer_vencimiento" name="fecha_primer_vencimiento" value="<?= e($solicitudEditar['fecha_primer_vencimiento'] ?? '') ?>">
                </div>

                <div class="pats-field pats-field--full">
                  <label for="doc_comprobante_pago">Comprobante de pago</label>
                  <label class="pats-file-upload">
                    <input type="file" id="doc_comprobante_pago" name="doc_comprobante_pago" accept=".pdf,.png,.jpg,.jpeg,.webp" class="pats-file-native">
                    <span class="pats-file-upload__button">Seleccionar archivo</span>
                    <span class="pats-file-upload__text">Ningún archivo seleccionado</span>
                  </label>
                  <?php if (!empty($docsActuales['COMPROBANTE_PAGO']['archivo_path'])): ?>
                    <div class="sol-doc-current">
                      <span class="sol-doc-current__label">Actual:</span>
                      <a class="sol-doc-current__link"
                         data-doc-actual="doc_comprobante_pago"
                         href="endpoints/solicitud_documento_ver.php?id_solicitud=<?= (int)$idSolicitudEditar ?>&tipo=COMPROBANTE_PAGO"
                         target="_blank">Ver archivo</a>
                    </div>
                  <?php endif; ?>
                </div>
              </div>

              <article class="pats-card pats-finance-preview" id="planPreviewCard" hidden style="margin-top:16px;">
                <div class="pats-card__head"><h3>Vista previa del plan</h3></div>
                <div id="planPreviewBody"></div>
              </article>
            </div>
          </section>

        </div>

        <div class="pats-wizard-footer">
          <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button type="button" class="pats-finance-btn pats-finance-btn--ghost" id="btnCancelarSolicitud">
              Cancelar y volver
            </button>
            <button type="button" class="pats-finance-btn pats-finance-btn--ghost" id="btnWizardPrev">
              Atrás
            </button>
          </div>

          <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button type="button" class="pats-finance-btn pats-finance-btn--primary" id="btnPreviewPlan" hidden>
              Vista previa
            </button>
            <button type="button" class="pats-finance-btn pats-finance-btn--primary" id="btnWizardNext">
              Siguiente
            </button>
            <button type="submit" class="pats-finance-btn pats-finance-btn--primary" id="btnWizardSubmit" hidden>
              <?= $idSolicitudEditar > 0 ? 'Guardar cambios' : 'Enviar solicitud' ?>
            </button>
          </div>
        </div>
      </article>
    </form>
  </div>
</content>

<div id="solfrmToast" class="solfrm-toast"></div>

<script>
window.PATS_SOL_CFG = {
  id_franquicia_default: <?= json_encode($idFranquiciaDefault, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  id_gestor_default: <?= json_encode($idGestorDefault, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  from_dashboard: <?= json_encode($fromDashboard, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  region_default: <?= json_encode($regionDefaultGet, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  zona_default: <?= json_encode($zonaDefaultGet, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  mes_default: <?= json_encode($mesDefaultGet, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  volver_href: <?= json_encode($volverHref, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  estados: <?= json_encode($estadosCatalogo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  zonas_por_estado: <?= json_encode($zonasPorEstado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  id_solicitud_editar: <?= json_encode($idSolicitudEditar, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  solicitud_editar: <?= json_encode($solicitudEditar, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  precio_distribucion: <?= json_encode((float)$precioDistribucion, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
};
</script>

<script src="js/solicitud_distribuidor.js?v=<?= $ver ?>"></script>

</body>
</html>