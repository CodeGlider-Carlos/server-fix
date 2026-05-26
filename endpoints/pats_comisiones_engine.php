<?php
/*
 Archivo: ez/pats/endpoints/pats_comisiones_engine.php
 Módulo: PATS · Motor central de generación de comisiones reales
 Propósito:
 - Insertar filas oficiales en pats_comisiones_generadas.
 - Mantener idempotencia por tipo_origen + id_origen + beneficiario_tipo + beneficiario_id.
 - Aplicar reglas de ADMINPATS, franquicia, distribuidor, hospital/unidad y gestor/gerente de ventas.
 Responsabilidad:
 - Generar deltas faltantes, sin duplicar montos ya generados.
 - No recalcular cache; para eso se usa pats_sync_comisiones_cache.php.
 Conexiones:
 - bootstrap.php, pats_comisiones_generadas, pats_distribuidores, pats_franquicias,
   pats_pasaportes, pats_pagos_pasaporte, pats_contratos_actor, pats_pagos_actor,
   pats_gestor_franquicias.
 Tipo:
 - Endpoint/include específico de PATS. Puede abrirse directo con ?debug=1 o incluirse desde dashboards/PATSFIN.
*/

/* =========================================================
   BOOTSTRAP
========================================================= */
if (!isset($cx) || !($cx instanceof mysqli)) {
  $bootstrapPats = __DIR__ . '/bootstrap.php';
  if (is_file($bootstrapPats)) {
    require_once $bootstrapPats;
  }
}

/* =========================================================
   HELPERS BASE
========================================================= */
if (!function_exists('pce_num')) {
  function pce_num($v): float {
    return round((float)($v ?? 0), 2);
  }
}

if (!function_exists('pce_esc')) {
  function pce_esc(mysqli $cx, $v): string {
    return $cx->real_escape_string((string)($v ?? ''));
  }
}

if (!function_exists('pce_one')) {
  function pce_one(mysqli $cx, string $sql): array {
    $rs = $cx->query($sql);
    if (!$rs) return [];
    $row = $rs->fetch_assoc();
    $rs->free();
    return is_array($row) ? $row : [];
  }
}

if (!function_exists('pce_all')) {
  function pce_all(mysqli $cx, string $sql): array {
    $out = [];
    $rs = $cx->query($sql);
    if (!$rs) return $out;
    while ($row = $rs->fetch_assoc()) {
      $out[] = $row;
    }
    $rs->free();
    return $out;
  }
}

if (!function_exists('pce_table_exists')) {
  function pce_table_exists(mysqli $cx, string $table): bool {
    if (function_exists('fin_table_exists')) return fin_table_exists($cx, $table);
    if (function_exists('pats_table_exists')) return pats_table_exists($cx, $table);

    $t = pce_esc($cx, $table);
    $rs = $cx->query("SHOW TABLES LIKE '{$t}'");
    if (!$rs) return false;

    $ok = $rs->num_rows > 0;
    $rs->free();
    return $ok;
  }
}

if (!function_exists('pce_column_exists')) {
  function pce_column_exists(mysqli $cx, string $table, string $column): bool {
    if (function_exists('fin_column_exists')) return fin_column_exists($cx, $table, $column);
    if (function_exists('pats_column_exists')) return pats_column_exists($cx, $table, $column);

    $t = pce_esc($cx, $table);
    $c = pce_esc($cx, $column);
    $rs = $cx->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
    if (!$rs) return false;

    $ok = $rs->num_rows > 0;
    $rs->free();
    return $ok;
  }
}

if (!function_exists('pce_first_existing_col')) {
  function pce_first_existing_col(mysqli $cx, string $table, array $cols): string {
    foreach ($cols as $col) {
      if (pce_column_exists($cx, $table, $col)) return (string)$col;
    }
    return '';
  }
}

if (!function_exists('pce_id_expr')) {
  function pce_id_expr(mysqli $cx, string $table, string $alias, array $cols): string {
    $col = pce_first_existing_col($cx, $table, $cols);
    if ($col === '') return '0';
    return "COALESCE({$alias}.`{$col}`,0)";
  }
}

/* =========================================================
   SPLIT OFICIAL PATS
========================================================= */
if (!function_exists('pce_split_pats')) {
  function pce_split_pats(string $frecuencia): array {
    $f = strtolower(trim($frecuencia));

    if ($f === 'anual') {
      return [
        'nominal' => 9600.00,
        'admin_asociado' => 2400.00,
        'admin_directo' => 3600.00,
        'unidad' => 6000.00,
        'franquicia' => 240.00,
        'distribuidor' => 960.00,
      ];
    }

    return [
      'nominal' => 800.00,
      'admin_asociado' => 200.00,
      'admin_directo' => 300.00,
      'unidad' => 500.00,
      'franquicia' => 20.00,
      'distribuidor' => 80.00,
    ];
  }
}

/* =========================================================
   IDEMPOTENCIA / INSERT DELTA
========================================================= */
if (!function_exists('pce_total_existente')) {
  function pce_total_existente(
    mysqli $cx,
    string $tipoOrigen,
    int $idOrigen,
    string $beneficiarioTipo,
    ?int $beneficiarioId
  ): float {
    $tipoOrigen = pce_esc($cx, $tipoOrigen);
    $beneficiarioTipo = pce_esc($cx, strtolower(trim($beneficiarioTipo)));
    $idOrigen = (int)$idOrigen;

    $whereBenef = $beneficiarioId === null
      ? 'beneficiario_id IS NULL'
      : 'beneficiario_id = ' . (int)$beneficiarioId;

    $row = pce_one($cx, "
      SELECT COALESCE(SUM(monto_comision),0) AS total
      FROM pats_comisiones_generadas
      WHERE tipo_origen = '{$tipoOrigen}'
        AND id_origen = {$idOrigen}
        AND LOWER(TRIM(beneficiario_tipo)) = '{$beneficiarioTipo}'
        AND {$whereBenef}
        AND LOWER(TRIM(estatus)) NOT IN ('cancelada','cancelado','anulada','anulado')
    ");

    return pce_num($row['total'] ?? 0);
  }
}

if (!function_exists('pce_insertar_delta')) {
  function pce_insertar_delta(
    mysqli $cx,
    string $tipoOrigen,
    int $idOrigen,
    ?int $idRegla,
    string $beneficiarioTipo,
    ?int $beneficiarioId,
    float $montoEsperado,
    string $observaciones,
    string $estatus = 'por_pagar',
    string $estatusOperativo = 'GENERADA'
  ): float {
    $montoEsperado = pce_num($montoEsperado);

    if ($montoEsperado <= 0 || $idOrigen <= 0 || trim($beneficiarioTipo) === '') {
      return 0.0;
    }

    $existente = pce_total_existente($cx, $tipoOrigen, $idOrigen, $beneficiarioTipo, $beneficiarioId);
    $delta = pce_num($montoEsperado - $existente);

    if ($delta <= 0) {
      return 0.0;
    }

    $montoAplicadoDeuda = 0.0;
    $montoLiberado = $delta;
    $moneda = 'MXN';

    $stmt = $cx->prepare("
      INSERT INTO pats_comisiones_generadas
      (
        tipo_origen,
        id_origen,
        id_regla,
        beneficiario_tipo,
        beneficiario_id,
        id_contrato_compensado,
        monto_comision,
        monto_aplicado_deuda,
        monto_liberado,
        moneda,
        fecha_generacion,
        fecha_pago,
        referencia_pago,
        evidencia_pago,
        user_pago,
        estatus,
        estatus_operativo,
        observaciones,
        created_at,
        updated_at
      )
      VALUES
      (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, NOW(), NULL, NULL, NULL, NULL, ?, ?, ?, NOW(), NOW())
    ");

    if (!$stmt) {
      throw new RuntimeException('No fue posible preparar insert comisión: ' . $cx->error);
    }

    $stmt->bind_param(
      'siisidddssss',
      $tipoOrigen,
      $idOrigen,
      $idRegla,
      $beneficiarioTipo,
      $beneficiarioId,
      $delta,
      $montoAplicadoDeuda,
      $montoLiberado,
      $moneda,
      $estatus,
      $estatusOperativo,
      $observaciones
    );

    if (!$stmt->execute()) {
      $err = $stmt->error;
      $stmt->close();
      throw new RuntimeException('No fue posible insertar comisión: ' . $err);
    }

    $stmt->close();
    return $delta;
  }
}

/* =========================================================
   RESOLUCIÓN DE GESTOR / GERENTE
========================================================= */
if (!function_exists('pce_gestor_directo_de_row')) {
  function pce_gestor_directo_de_row(array $row): int {
    foreach (['id_gestor', 'id_gerente', 'id_gestor_asociado', 'id_gerente_asociado', 'id_gestor_venta', 'id_gerente_venta'] as $col) {
      if (isset($row[$col]) && (int)$row[$col] > 0) {
        return (int)$row[$col];
      }
    }

    if (isset($row['id_gestor_directo']) && (int)$row['id_gestor_directo'] > 0) {
      return (int)$row['id_gestor_directo'];
    }

    return 0;
  }
}

if (!function_exists('pce_gestor_por_franquicia')) {
  function pce_gestor_por_franquicia(mysqli $cx, int $idFranquicia): int {
    $idFranquicia = (int)$idFranquicia;
    if ($idFranquicia <= 0) return 0;

    /* 1) Primero revisar si la franquicia guarda el gestor directamente. */
    if (pce_table_exists($cx, 'pats_franquicias')) {
      $gestorCol = pce_first_existing_col($cx, 'pats_franquicias', [
        'id_gestor',
        'id_gerente',
        'id_gestor_asociado',
        'id_gerente_asociado',
        'id_gestor_venta',
        'id_gerente_venta'
      ]);

      if ($gestorCol !== '') {
        $row = pce_one($cx, "
          SELECT COALESCE(`{$gestorCol}`,0) AS id_gestor
          FROM pats_franquicias
          WHERE id_franquicia = {$idFranquicia}
          LIMIT 1
        ");

        if ((int)($row['id_gestor'] ?? 0) > 0) {
          return (int)$row['id_gestor'];
        }
      }
    }

    /* 2) Relación gestor-franquicia. */
    if (!pce_table_exists($cx, 'pats_gestor_franquicias')) {
      return 0;
    }

    $order = pce_column_exists($cx, 'pats_gestor_franquicias', 'id_gestor_franquicia')
      ? 'ORDER BY id_gestor_franquicia DESC'
      : '';

    $row = pce_one($cx, "
      SELECT id_gestor
      FROM pats_gestor_franquicias
      WHERE id_franquicia = {$idFranquicia}
        AND activo = 1
      {$order}
      LIMIT 1
    ");

    return (int)($row['id_gestor'] ?? 0);
  }
}

if (!function_exists('pce_porcentaje_pagado_actor')) {
  function pce_porcentaje_pagado_actor(mysqli $cx, string $actorTipo, int $actorId): float {
    $actorTipo = strtolower(trim($actorTipo));
    $actorId = (int)$actorId;

    if ($actorId <= 0 || $actorTipo === '' || !pce_table_exists($cx, 'pats_contratos_actor')) {
      return 0.0;
    }

    $actorTipoEsc = pce_esc($cx, $actorTipo);
    $row = pce_one($cx, "
      SELECT id_contrato, valor_total
      FROM pats_contratos_actor
      WHERE LOWER(TRIM(actor_tipo)) = '{$actorTipoEsc}'
        AND actor_id = {$actorId}
        AND activo = 1
      ORDER BY id_contrato DESC
      LIMIT 1
    ");

    $idContrato = (int)($row['id_contrato'] ?? 0);
    $valorTotal = pce_num($row['valor_total'] ?? 0);

    if ($idContrato <= 0 || $valorTotal <= 0 || !pce_table_exists($cx, 'pats_pagos_actor')) {
      return 0.0;
    }

    $pagado = pce_one($cx, "
      SELECT COALESCE(SUM(monto_pago),0) AS total_pagado
      FROM pats_pagos_actor
      WHERE id_contrato = {$idContrato}
    ");

    return min(1.0, max(0.0, pce_num($pagado['total_pagado'] ?? 0) / $valorTotal));
  }
}

/* =========================================================
   DISTRIBUCIONES
   Reglas:
   - Distribución corporativa directa sin gestor: AdminPATS conserva todo.
   - Distribución directa de gestor/gerente sin franquicia: Gestor $2,000, AdminPATS $18,000 si valor $20,000.
   - Distribución asociada a franquicia sin gestor: AdminPATS 50%, Franquicia 50%.
   - Distribución asociada a franquicia con gestor por relación previa: se conserva regla histórica 9,000 / 10,000 / 1,000.
========================================================= */
if (!function_exists('pats_generar_comisiones_distribuciones_pendientes')) {
  function pats_generar_comisiones_distribuciones_pendientes(mysqli $cx, array $opts = []): array {
    $out = [
      'procesadas' => 0,
      'insertado_admin' => 0.0,
      'insertado_franquicia' => 0.0,
      'insertado_gestor' => 0.0,
      'items' => []
    ];

    if (!pce_table_exists($cx, 'pats_distribuidores') || !pce_table_exists($cx, 'pats_comisiones_generadas')) {
      return $out;
    }

    $gestorDistExpr = pce_id_expr($cx, 'pats_distribuidores', 'd', [
      'id_gestor',
      'id_gerente',
      'id_gestor_asociado',
      'id_gerente_asociado',
      'id_gestor_venta',
      'id_gerente_venta'
    ]);

    $rows = pce_all($cx, "
      SELECT
        d.id_distribuidor,
        COALESCE(d.id_franquicia,0) AS id_franquicia,
        COALESCE(d.valor_distribucion,0) AS valor_distribucion,
        {$gestorDistExpr} AS id_gestor_directo,
        d.pais,
        d.region,
        d.zona,
        d.unidad,
        d.fecha_alta,
        d.created_at,
        COALESCE(f.tiene_gestor,0) AS tiene_gestor
      FROM pats_distribuidores d
      LEFT JOIN pats_franquicias f
        ON f.id_franquicia = d.id_franquicia
      WHERE d.activo = 1
        AND COALESCE(d.valor_distribucion,0) > 0
      ORDER BY d.id_distribuidor ASC
    ");

    foreach ($rows as $d) {
      $idDist = (int)($d['id_distribuidor'] ?? 0);
      $idFranq = (int)($d['id_franquicia'] ?? 0);
      $valor = pce_num($d['valor_distribucion'] ?? 0);
      $gestorDirecto = (int)($d['id_gestor_directo'] ?? 0);

      if ($idDist <= 0 || $valor <= 0) continue;

      $gestorId = $gestorDirecto > 0
        ? $gestorDirecto
        : ($idFranq > 0 ? pce_gestor_por_franquicia($cx, $idFranq) : 0);

      $obsBase = 'Generado por motor PATS al sincronizar venta de distribución #' . $idDist;

      $admin = 0.0;
      $franq = 0.0;
      $gestor = 0.0;
      $regla = '';

      if ($idFranq <= 0 && $gestorId > 0) {
        /* Nueva regla: distribución directa de gestor. */
        $gestor = min(2000.00, $valor);
        $admin = max(0.00, pce_num($valor - $gestor));
        $regla = 'DISTRIBUCION_DIRECTA_GESTOR';
      } elseif ($idFranq <= 0) {
        /* Distribución corporativa directa. */
        $admin = $valor;
        $regla = 'DISTRIBUCION_DIRECTA_ADMINPATS';
      } elseif ($gestorId > 0) {
        /* Regla histórica asociada a franquicia con gestor. */
        $admin = min(9000.00, $valor);
        $franq = min(10000.00, max(0, $valor - $admin));
        $gestor = min(1000.00, max(0, $valor - $admin - $franq));
        $regla = 'DISTRIBUCION_FRANQUICIA_CON_GESTOR_HISTORICA';
      } else {
        $admin = pce_num($valor * 0.50);
        $franq = pce_num($valor * 0.50);
        $regla = 'DISTRIBUCION_FRANQUICIA_SIN_GESTOR';
      }

      $insAdmin = pce_insertar_delta(
        $cx,
        'venta_distribucion',
        $idDist,
        null,
        'admin',
        1,
        $admin,
        $obsBase . ' · AdminPATS · ' . $regla,
        'no_pagable',
        'REGISTRO_INTERNO'
      );

      $insFranq = 0.0;
      if ($idFranq > 0 && $franq > 0) {
        $insFranq = pce_insertar_delta(
          $cx,
          'venta_distribucion',
          $idDist,
          null,
          'franquicia',
          $idFranq,
          $franq,
          $obsBase . ' · Franquicia · ' . $regla,
          'por_pagar',
          'GENERADA'
        );
      }

      $insGestor = 0.0;
      if ($gestorId > 0 && $gestor > 0) {
        $insGestor = pce_insertar_delta(
          $cx,
          'venta_distribucion',
          $idDist,
          null,
          'gestor',
          $gestorId,
          $gestor,
          $obsBase . ' · Gestor · ' . $regla,
          'por_pagar',
          'GENERADA'
        );
      }

      $out['procesadas']++;
      $out['insertado_admin'] = pce_num($out['insertado_admin'] + $insAdmin);
      $out['insertado_franquicia'] = pce_num($out['insertado_franquicia'] + $insFranq);
      $out['insertado_gestor'] = pce_num($out['insertado_gestor'] + $insGestor);

      if (!empty($opts['debug'])) {
        $out['items'][] = [
          'id_distribuidor' => $idDist,
          'id_franquicia' => $idFranq,
          'id_gestor' => $gestorId,
          'id_gestor_directo' => $gestorDirecto,
          'valor' => $valor,
          'regla' => $regla,
          'esperado_admin' => $admin,
          'esperado_franquicia' => $franq,
          'esperado_gestor' => $gestor,
          'insertado_admin' => $insAdmin,
          'insertado_franquicia' => $insFranq,
          'insertado_gestor' => $insGestor,
        ];
      }
    }

    return $out;
  }
}

/* =========================================================
   PASAPORTES
   Reglas:
   - Corporativo directo: Hospital 500, AdminPATS 300.
   - Franquicia sin distribuidor: Hospital 500, AdminPATS 200, Franquicia 100.
   - Franquicia con distribuidor: Hospital 500, AdminPATS 200, Franquicia 20, Distribuidor 80.
   - Gestor directo sin franquicia ni distribuidor: Hospital 500, AdminPATS 200, Gestor 100.
========================================================= */
if (!function_exists('pats_generar_comisiones_pats_pendientes')) {
  function pats_generar_comisiones_pats_pendientes(mysqli $cx, array $opts = []): array {
    $out = [
      'procesados' => 0,
      'insertado_admin' => 0.0,
      'insertado_unidad' => 0.0,
      'insertado_franquicia' => 0.0,
      'insertado_distribuidor' => 0.0,
      'insertado_gestor' => 0.0,
      'items' => []
    ];

    if (!pce_table_exists($cx, 'pats_pasaportes') || !pce_table_exists($cx, 'pats_comisiones_generadas')) {
      return $out;
    }

    $gestorPatsExpr = pce_id_expr($cx, 'pats_pasaportes', 'p', [
      'id_gestor',
      'id_gerente',
      'id_gestor_asociado',
      'id_gerente_asociado',
      'id_gestor_venta',
      'id_gerente_venta'
    ]);

    $idPagoCol = '';
    $fechaPagoCol = '';
    $hasPagos = pce_table_exists($cx, 'pats_pagos_pasaporte') && pce_column_exists($cx, 'pats_pagos_pasaporte', 'id_pasaporte');

    if ($hasPagos) {
      foreach (['id_pago_pasaporte','id_pago','id'] as $c) {
        if (pce_column_exists($cx, 'pats_pagos_pasaporte', $c)) {
          $idPagoCol = $c;
          break;
        }
      }

      foreach (['fecha_pago','created_at'] as $c) {
        if (pce_column_exists($cx, 'pats_pagos_pasaporte', $c)) {
          $fechaPagoCol = $c;
          break;
        }
      }
    }

    if ($hasPagos && $idPagoCol !== '') {
      $fechaSelect = $fechaPagoCol !== '' ? "pp.{$fechaPagoCol} AS fecha_base" : "p.created_at AS fecha_base";

      $rows = pce_all($cx, "
        SELECT
          p.id_pasaporte AS id_origen,
          p.id_pasaporte,
          COALESCE(p.id_franquicia,0) AS id_franquicia,
          COALESCE(p.id_distribuidor,0) AS id_distribuidor,
          {$gestorPatsExpr} AS id_gestor,
          p.frecuencia_pago,
          p.unidad,
          p.id_unidad,
          {$fechaSelect},
          'pago_confirmado' AS fuente_motor
        FROM pats_pagos_pasaporte pp
        INNER JOIN pats_pasaportes p
          ON p.id_pasaporte = pp.id_pasaporte
        WHERE p.activo = 1
          AND LOWER(TRIM(COALESCE(pp.estatus_pago,''))) = 'confirmado'

        UNION

        SELECT
          p.id_pasaporte AS id_origen,
          p.id_pasaporte,
          COALESCE(p.id_franquicia,0) AS id_franquicia,
          COALESCE(p.id_distribuidor,0) AS id_distribuidor,
          {$gestorPatsExpr} AS id_gestor,
          p.frecuencia_pago,
          p.unidad,
          p.id_unidad,
          p.created_at AS fecha_base,
          'pasaporte_activo_sin_pago_confirmado' AS fuente_motor
        FROM pats_pasaportes p
        WHERE p.activo = 1
          AND LOWER(TRIM(COALESCE(p.estatus,''))) IN ('activo','vigente')
          AND NOT EXISTS (
            SELECT 1
            FROM pats_pagos_pasaporte pp2
            WHERE pp2.id_pasaporte = p.id_pasaporte
              AND LOWER(TRIM(COALESCE(pp2.estatus_pago,''))) = 'confirmado'
          )

        ORDER BY id_pasaporte ASC
      ");
    } else {
      $rows = pce_all($cx, "
        SELECT
          p.id_pasaporte AS id_origen,
          p.id_pasaporte,
          COALESCE(p.id_franquicia,0) AS id_franquicia,
          COALESCE(p.id_distribuidor,0) AS id_distribuidor,
          {$gestorPatsExpr} AS id_gestor,
          p.frecuencia_pago,
          p.unidad,
          p.id_unidad,
          p.created_at AS fecha_base,
          'pasaporte_activo_fallback' AS fuente_motor
        FROM pats_pasaportes p
        WHERE p.activo = 1
          AND LOWER(TRIM(COALESCE(p.estatus,''))) IN ('activo','vigente')
        ORDER BY p.id_pasaporte ASC
      ");
    }

    foreach ($rows as $p) {
      $idOrigen = (int)($p['id_origen'] ?? 0);
      $idFranq = (int)($p['id_franquicia'] ?? 0);
      $idDist = (int)($p['id_distribuidor'] ?? 0);
      $idGestor = (int)($p['id_gestor'] ?? 0);

      if ($idOrigen <= 0) continue;

      $freq = strtolower(trim((string)($p['frecuencia_pago'] ?? 'mensual')));
      $split = pce_split_pats($freq === 'anual' ? 'anual' : 'mensual');

      $directoAdmin = ($idFranq <= 0 && $idDist <= 0 && $idGestor <= 0);
      $directoGestor = ($idGestor > 0 && $idFranq <= 0 && $idDist <= 0);
      $franquiciaSinDistribuidor = ($idFranq > 0 && $idDist <= 0);
      $distribuidorSinFranquicia = ($idFranq <= 0 && $idDist > 0);

      $admin = $split['admin_asociado'];
      $unidad = $split['unidad'];
      $franq = 0.0;
      $dist = 0.0;
      $gestor = 0.0;
      $regla = '';

      if ($directoGestor) {
        /* Nueva regla: gestor directo conserva la bolsa comercial completa 20+80 = 100 mensual. */
        $admin = $split['admin_asociado'];
        $gestor = pce_num($split['franquicia'] + $split['distribuidor']);
        $regla = 'PATS_DIRECTO_GESTOR';
      } elseif ($directoAdmin) {
        $admin = $split['admin_directo'];
        $regla = 'PATS_DIRECTO_ADMINPATS';
      } elseif ($franquiciaSinDistribuidor) {
        $franq = pce_num($split['franquicia'] + $split['distribuidor']);
        $regla = 'PATS_DIRECTO_FRANQUICIA';
      } elseif ($idFranq > 0 && $idDist > 0) {
        $franq = $split['franquicia'];
        $dist = $split['distribuidor'];
        $regla = 'PATS_FRANQUICIA_DISTRIBUIDOR';
      } elseif ($distribuidorSinFranquicia) {
        $admin = pce_num($split['admin_asociado'] + $split['franquicia']);
        $dist = $split['distribuidor'];
        $regla = 'PATS_DISTRIBUIDOR_SIN_FRANQUICIA';
      }

      $obsBase = 'Generado por motor PATS al sincronizar pago PATS origen #' . $idOrigen;

      $insAdmin = pce_insertar_delta(
        $cx,
        'pago_pasaporte',
        $idOrigen,
        null,
        'admin',
        1,
        $admin,
        $obsBase . ' · AdminPATS · ' . $regla,
        'no_pagable',
        'REGISTRO_INTERNO'
      );

      $insUnidad = pce_insertar_delta(
        $cx,
        'pago_pasaporte',
        $idOrigen,
        null,
        'unidad',
        null,
        $unidad,
        $obsBase . ' · Unidad/Hospital · ' . $regla,
        'por_pagar',
        'GENERADA'
      );

      $insFranq = 0.0;
      if ($franq > 0 && $idFranq > 0) {
        $insFranq = pce_insertar_delta(
          $cx,
          'pago_pasaporte',
          $idOrigen,
          null,
          'franquicia',
          $idFranq,
          $franq,
          $obsBase . ' · Franquicia · ' . $regla,
          'por_pagar',
          'GENERADA'
        );
      }

      $insDist = 0.0;
      if ($dist > 0 && $idDist > 0) {
        $insDist = pce_insertar_delta(
          $cx,
          'pago_pasaporte',
          $idOrigen,
          null,
          'distribuidor',
          $idDist,
          $dist,
          $obsBase . ' · Distribuidor · ' . $regla,
          'por_pagar',
          'GENERADA'
        );
      }

      $insGestor = 0.0;
      if ($gestor > 0 && $idGestor > 0) {
        $insGestor = pce_insertar_delta(
          $cx,
          'pago_pasaporte',
          $idOrigen,
          null,
          'gestor',
          $idGestor,
          $gestor,
          $obsBase . ' · Gestor · ' . $regla,
          'por_pagar',
          'GENERADA'
        );
      }

      $out['procesados']++;
      $out['insertado_admin'] = pce_num($out['insertado_admin'] + $insAdmin);
      $out['insertado_unidad'] = pce_num($out['insertado_unidad'] + $insUnidad);
      $out['insertado_franquicia'] = pce_num($out['insertado_franquicia'] + $insFranq);
      $out['insertado_distribuidor'] = pce_num($out['insertado_distribuidor'] + $insDist);
      $out['insertado_gestor'] = pce_num($out['insertado_gestor'] + $insGestor);

      if (!empty($opts['debug'])) {
        $out['items'][] = [
          'id_origen' => $idOrigen,
          'id_pasaporte' => (int)($p['id_pasaporte'] ?? 0),
          'id_franquicia' => $idFranq,
          'id_distribuidor' => $idDist,
          'id_gestor' => $idGestor,
          'directo_admin' => $directoAdmin,
          'directo_gestor' => $directoGestor,
          'franquicia_sin_distribuidor' => $franquiciaSinDistribuidor,
          'distribuidor_sin_franquicia' => $distribuidorSinFranquicia,
          'fuente_motor' => (string)($p['fuente_motor'] ?? ''),
          'frecuencia' => $freq,
          'regla' => $regla,
          'esperado_admin' => $admin,
          'esperado_unidad' => $unidad,
          'esperado_franquicia' => $franq,
          'esperado_distribuidor' => $dist,
          'esperado_gestor' => $gestor,
          'insertado_admin' => $insAdmin,
          'insertado_unidad' => $insUnidad,
          'insertado_franquicia' => $insFranq,
          'insertado_distribuidor' => $insDist,
          'insertado_gestor' => $insGestor,
        ];
      }
    }

    return $out;
  }
}

/* =========================================================
   FRANQUICIAS
   Regla gestor venta de franquicia:
   - Gestor: $50,000 proporcional a cobro real si existe contrato/pagos.
   - Si la franquicia no tiene contrato/pagos registrados, no se fuerza pago automático.
========================================================= */
if (!function_exists('pats_generar_comisiones_franquicias_pendientes')) {
  function pats_generar_comisiones_franquicias_pendientes(mysqli $cx, array $opts = []): array {
    $out = [
      'procesadas' => 0,
      'insertado_gestor' => 0.0,
      'items' => []
    ];

    if (!pce_table_exists($cx, 'pats_franquicias') || !pce_table_exists($cx, 'pats_comisiones_generadas')) {
      return $out;
    }

    $gestorFranqExpr = pce_id_expr($cx, 'pats_franquicias', 'f', [
      'id_gestor',
      'id_gerente',
      'id_gestor_asociado',
      'id_gerente_asociado',
      'id_gestor_venta',
      'id_gerente_venta'
    ]);

    $franquicias = pce_all($cx, "
      SELECT
        f.id_franquicia,
        f.valor_franquicia,
        f.tiene_gestor,
        f.activo,
        {$gestorFranqExpr} AS id_gestor_directo
      FROM pats_franquicias f
      WHERE f.activo = 1
        AND COALESCE(f.valor_franquicia,0) > 0
      ORDER BY f.id_franquicia ASC
    ");

    foreach ($franquicias as $f) {
      $idFranq = (int)($f['id_franquicia'] ?? 0);
      if ($idFranq <= 0) continue;

      $gestorDirecto = (int)($f['id_gestor_directo'] ?? 0);
      $gestorId = $gestorDirecto > 0 ? $gestorDirecto : pce_gestor_por_franquicia($cx, $idFranq);

      if ($gestorId <= 0) continue;

      $porcentaje = pce_porcentaje_pagado_actor($cx, 'franquicia', $idFranq);
      $esperado = pce_num(50000.00 * $porcentaje);

      if ($esperado <= 0) continue;

      $insertado = pce_insertar_delta(
        $cx,
        'venta_franquicia',
        $idFranq,
        null,
        'gestor',
        $gestorId,
        $esperado,
        'Generado por motor PATS al sincronizar venta de franquicia #' . $idFranq . ' proporcional a cobro real',
        'por_pagar',
        'GENERADA'
      );

      $out['procesadas']++;
      $out['insertado_gestor'] = pce_num($out['insertado_gestor'] + $insertado);

      if (!empty($opts['debug'])) {
        $out['items'][] = [
          'id_franquicia' => $idFranq,
          'id_gestor' => $gestorId,
          'id_gestor_directo' => $gestorDirecto,
          'porcentaje_pagado' => round($porcentaje * 100, 2),
          'esperado_gestor' => $esperado,
          'insertado_gestor' => $insertado,
        ];
      }
    }

    return $out;
  }
}

/* =========================================================
   ORQUESTADOR
========================================================= */
if (!function_exists('pats_generar_comisiones_todo')) {
  function pats_generar_comisiones_todo(mysqli $cx, array $opts = []): array {
    $out = [
      'ok' => true,
      'distribuciones' => [],
      'pats' => [],
      'franquicias' => [],
      'warnings' => []
    ];

    try {
      if (!pce_table_exists($cx, 'pats_comisiones_generadas')) {
        return [
          'ok' => false,
          'warnings' => ['No existe pats_comisiones_generadas']
        ];
      }

      $out['distribuciones'] = pats_generar_comisiones_distribuciones_pendientes($cx, $opts);
      $out['pats'] = pats_generar_comisiones_pats_pendientes($cx, $opts);
      $out['franquicias'] = pats_generar_comisiones_franquicias_pendientes($cx, $opts);
    } catch (Throwable $e) {
      $out['ok'] = false;
      $out['warnings'][] = $e->getMessage();
    }

    return $out;
  }
}

/* =========================================================
   EJECUCIÓN DIRECTA
========================================================= */
$__pceDirect = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__);

if ($__pceDirect) {
  $res = pats_generar_comisiones_todo($cx, [
    'debug' => !empty($_GET['debug'])
  ]);

  if (function_exists('fin_json')) {
    fin_json($res);
  }

  if (function_exists('pats_json')) {
    pats_json($res);
  }

  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
