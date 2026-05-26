<?php
/*
 ez/pats/endpoints/pats_sync_comisiones_cache.php
 PATS · Sincronización segura de comisiones cache
 - Actualiza pats_franquicias.comision_*_periodo
 - Actualiza pats_distribuidores.comision_*_periodo
 - Actualiza pats_gestores.comision_*_periodo
 - No crea comisiones nuevas
 - No toca contratos ni ventas
 - Puede ejecutarse directo o incluirse desde dashboards
*/

if (!function_exists('pats_json')) {
  require_once __DIR__ . '/bootstrap.php';
}

if (!function_exists('pats_sync_num')) {
  function pats_sync_num($v): float {
    return round((float)($v ?? 0), 2);
  }
}

if (!function_exists('pats_sync_table_exists')) {
  function pats_sync_table_exists(mysqli $cx, string $table): bool {
    if (function_exists('pats_table_exists')) {
      return pats_table_exists($cx, $table);
    }
    $tableEsc = $cx->real_escape_string($table);
    $rs = $cx->query("SHOW TABLES LIKE '{$tableEsc}'");
    if (!$rs) return false;
    $ok = $rs->num_rows > 0;
    $rs->free();
    return $ok;
  }
}

if (!function_exists('pats_sync_column_exists')) {
  function pats_sync_column_exists(mysqli $cx, string $table, string $column): bool {
    if (function_exists('pats_column_exists')) {
      return pats_column_exists($cx, $table, $column);
    }
    $tableEsc = $cx->real_escape_string($table);
    $columnEsc = $cx->real_escape_string($column);
    $rs = $cx->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$columnEsc}'");
    if (!$rs) return false;
    $ok = $rs->num_rows > 0;
    $rs->free();
    return $ok;
  }
}

if (!function_exists('pats_sync_all')) {
  function pats_sync_all(mysqli $cx, string $sql): array {
    if (function_exists('pats_all')) {
      return pats_all($cx, $sql);
    }
    $out = [];
    $rs = $cx->query($sql);
    if (!$rs) return $out;
    while ($row = $rs->fetch_assoc()) $out[] = $row;
    $rs->free();
    return $out;
  }
}

if (!function_exists('pats_sync_one')) {
  function pats_sync_one(mysqli $cx, string $sql): array {
    if (function_exists('pats_one')) {
      return pats_one($cx, $sql);
    }
    $rs = $cx->query($sql);
    if (!$rs) return [];
    $row = $rs->fetch_assoc();
    $rs->free();
    return is_array($row) ? $row : [];
  }
}

if (!function_exists('pats_sync_split_franquicia')) {
  function pats_sync_split_franquicia(mysqli $cx, string $frecuencia): float {
    $f = strtolower(trim($frecuencia));
    if (function_exists('pats_pats_split')) {
      $split = pats_pats_split($cx, $f === 'anual' ? 'anual' : 'mensual');
      return pats_sync_num($split['franquicia'] ?? ($f === 'anual' ? 240 : 20));
    }
    return $f === 'anual' ? 240.00 : 20.00;
  }
}

if (!function_exists('pats_sync_split_distribuidor')) {
  function pats_sync_split_distribuidor(mysqli $cx, string $frecuencia): float {
    $f = strtolower(trim($frecuencia));
    if (function_exists('pats_pats_split')) {
      $split = pats_pats_split($cx, $f === 'anual' ? 'anual' : 'mensual');
      return pats_sync_num($split['distribuidor'] ?? ($f === 'anual' ? 960 : 80));
    }
    return $f === 'anual' ? 960.00 : 80.00;
  }
}

if (!function_exists('pats_sync_split_franquicia_por_pats')) {
  function pats_sync_split_franquicia_por_pats(mysqli $cx, string $frecuencia, bool $tieneDistribuidor): float {
    /*
      Si el PATS tiene franquicia y NO tiene distribuidor, la franquicia
      recibe también la bolsa que normalmente sería del distribuidor.
    */
    $baseFranq = pats_sync_split_franquicia($cx, $frecuencia);
    if ($tieneDistribuidor) {
      return pats_sync_num($baseFranq);
    }
    return pats_sync_num($baseFranq + pats_sync_split_distribuidor($cx, $frecuencia));
  }
}

if (!function_exists('pats_sync_generated_commission')) {
  function pats_sync_generated_commission(
    mysqli $cx,
    string $beneficiarioTipo,
    int $beneficiarioId,
    ?string $tipoOrigen = null,
    ?int $idOrigen = null
  ): array {
    if (!pats_sync_table_exists($cx, 'pats_comisiones_generadas')) {
      return ['total' => 0.0, 'pagada' => 0.0, 'pendiente' => 0.0];
    }

    $benefTipo = $cx->real_escape_string(strtolower(trim($beneficiarioTipo)));
    $where = [
      "LOWER(TRIM(cg.beneficiario_tipo)) = '{$benefTipo}'",
      "cg.beneficiario_id = " . (int)$beneficiarioId
    ];

    if ($tipoOrigen !== null && trim($tipoOrigen) !== '') {
      $tipoOrigenEsc = $cx->real_escape_string(trim($tipoOrigen));
      $where[] = "cg.tipo_origen = '{$tipoOrigenEsc}'";
    }

    if ($idOrigen !== null) {
      $where[] = "cg.id_origen = " . (int)$idOrigen;
    }

    $row = pats_sync_one($cx, "
      SELECT
        COALESCE(SUM(cg.monto_comision),0) AS total,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(cg.estatus)) = 'pagado' THEN cg.monto_comision ELSE 0 END),0) AS pagada,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(cg.estatus)) IN ('por_pagar','pendiente','solicitado','en_revision','aprobado') THEN cg.monto_comision ELSE 0 END),0) AS pendiente
      FROM pats_comisiones_generadas cg
      WHERE " . implode(' AND ', $where) . "
    ");

    return [
      'total' => pats_sync_num($row['total'] ?? 0),
      'pagada' => pats_sync_num($row['pagada'] ?? 0),
      'pendiente' => pats_sync_num($row['pendiente'] ?? 0),
    ];
  }
}

if (!function_exists('pats_sync_porcentaje_pagado_contrato')) {
  function pats_sync_porcentaje_pagado_contrato(mysqli $cx, string $actorTipo, int $actorId): float {
    if (!pats_sync_table_exists($cx, 'pats_contratos_actor') || !pats_sync_table_exists($cx, 'pats_pagos_actor')) {
      return 0.0;
    }

    $actorTipoEsc = $cx->real_escape_string(strtolower(trim($actorTipo)));
    $actorId = (int)$actorId;
    if ($actorId <= 0 || $actorTipoEsc === '') return 0.0;

    $contrato = pats_sync_one($cx, "
      SELECT id_contrato, valor_total
      FROM pats_contratos_actor
      WHERE LOWER(TRIM(actor_tipo)) = '{$actorTipoEsc}'
        AND actor_id = {$actorId}
        AND activo = 1
      ORDER BY id_contrato DESC
      LIMIT 1
    ");

    $idContrato = (int)($contrato['id_contrato'] ?? 0);
    $valorTotal = pats_sync_num($contrato['valor_total'] ?? 0);
    if ($idContrato <= 0 || $valorTotal <= 0) return 0.0;

    $pagado = pats_sync_one($cx, "
      SELECT COALESCE(SUM(monto_pago),0) AS total_pagado
      FROM pats_pagos_actor
      WHERE id_contrato = {$idContrato}
    ");

    $totalPagado = pats_sync_num($pagado['total_pagado'] ?? 0);
    if ($totalPagado <= 0) return 0.0;

    return min(1.0, max(0.0, $totalPagado / $valorTotal));
  }
}

if (!function_exists('pats_sync_comisiones_cache')) {
  function pats_sync_comisiones_cache(mysqli $cx, array $opts = []): array {
    $dryRun = !empty($opts['dry_run']);
    $debug = !empty($opts['debug']);

    $out = [
      'ok' => true,
      'dry_run' => $dryRun,
      'franquicias_actualizadas' => 0,
      'distribuidores_actualizados' => 0,
      'gestores_actualizados' => 0,
      'franquicias' => [],
      'distribuidores' => [],
      'gestores' => [],
      'warnings' => []
    ];

    foreach (['pats_franquicias', 'pats_distribuidores'] as $tbl) {
      if (!pats_sync_table_exists($cx, $tbl)) {
        $out['ok'] = false;
        $out['warnings'][] = "No existe tabla {$tbl}";
        return $out;
      }
      foreach (['comision_acumulada_periodo','comision_pagada_periodo','comision_por_pagar_periodo'] as $col) {
        if (!pats_sync_column_exists($cx, $tbl, $col)) {
          $out['ok'] = false;
          $out['warnings'][] = "Falta columna {$tbl}.{$col}";
          return $out;
        }
      }
    }

    $canSyncGestores = pats_sync_table_exists($cx, 'pats_gestores');
    if ($canSyncGestores) {
      foreach (['comision_acumulada_periodo','comision_pagada_periodo','comision_por_pagar_periodo'] as $col) {
        if (!pats_sync_column_exists($cx, 'pats_gestores', $col)) {
          $canSyncGestores = false;
          $out['warnings'][] = "Falta columna pats_gestores.{$col}; se omite sync de gestores";
          break;
        }
      }
    } else {
      $out['warnings'][] = 'No existe pats_gestores; se omite sync de gestores.';
    }

    $hasComisiones = pats_sync_table_exists($cx, 'pats_comisiones_generadas');
    $hasPasaportes = pats_sync_table_exists($cx, 'pats_pasaportes');

    if (!$hasComisiones) {
      $out['warnings'][] = 'No existe pats_comisiones_generadas; se usarán solo cálculos fallback.';
    }
    if (!$hasPasaportes) {
      $out['warnings'][] = 'No existe pats_pasaportes; no se podrá calcular comisión por PATS.';
    }

    /* =====================================================
       1) FRANQUICIAS
       - Comisión por venta de distribución asociada a franquicia
       - Comisión por PATS asociados a franquicia
       - PATS directos ADMINPATS sin id_franquicia NO se suman
    ===================================================== */
    $franquicias = pats_sync_all($cx, "
      SELECT id_franquicia, nombre_franquicia, franquiciatario, activo
      FROM pats_franquicias
      WHERE activo = 1
      ORDER BY id_franquicia ASC
    ");

    foreach ($franquicias as $f) {
      $idFranquicia = (int)($f['id_franquicia'] ?? 0);
      if ($idFranquicia <= 0) continue;

      $acum = 0.0;
      $pagada = 0.0;
      $porPagar = 0.0;
      $debugRow = [
        'id_franquicia' => $idFranquicia,
        'nombre' => (string)($f['nombre_franquicia'] ?? $f['franquiciatario'] ?? ''),
        'distribuciones' => [],
        'pats_franquicia' => 0.0,
        'generada' => 0.0,
        'fallback' => 0.0,
        'pagada' => 0.0,
        'por_pagar' => 0.0
      ];

      $dists = pats_sync_all($cx, "
        SELECT id_distribuidor, valor_distribucion, activo
        FROM pats_distribuidores
        WHERE activo = 1
          AND id_franquicia = {$idFranquicia}
        ORDER BY id_distribuidor ASC
      ");

      foreach ($dists as $d) {
        $idDist = (int)($d['id_distribuidor'] ?? 0);
        $valorDist = pats_sync_num($d['valor_distribucion'] ?? 0);
        if ($idDist <= 0) continue;

        $gen = pats_sync_generated_commission($cx, 'franquicia', $idFranquicia, 'venta_distribucion', $idDist);
        $genTotal = pats_sync_num($gen['total'] ?? 0);
        $genPagada = pats_sync_num($gen['pagada'] ?? 0);

        /*
          Fallback visual/operativo:
          Si aún no hay comisión generada para esa distribución,
          la franquicia recibe 50% de valor_distribucion.
          Para $20,000 => $10,000.
        */
        $fallback = $genTotal > 0 ? 0.0 : pats_sync_num($valorDist * 0.50);
        $usarTotal = $genTotal > 0 ? $genTotal : $fallback;
        $usarPagada = $genTotal > 0 ? $genPagada : 0.0;
        $usarPendiente = pats_sync_num(max(0, $usarTotal - $usarPagada));

        $acum = pats_sync_num($acum + $usarTotal);
        $pagada = pats_sync_num($pagada + $usarPagada);
        $porPagar = pats_sync_num($porPagar + $usarPendiente);

        $debugRow['generada'] = pats_sync_num($debugRow['generada'] + $genTotal);
        $debugRow['fallback'] = pats_sync_num($debugRow['fallback'] + $fallback);
        $debugRow['distribuciones'][] = [
          'id_distribuidor' => $idDist,
          'valor_distribucion' => $valorDist,
          'generada_total' => $genTotal,
          'fallback_usado' => $fallback,
          'comision_total_usada' => $usarTotal
        ];
      }

      if ($hasPasaportes) {
        $pats = pats_sync_all($cx, "
          SELECT
            frecuencia_pago,
            CASE WHEN COALESCE(id_distribuidor,0) > 0 THEN 1 ELSE 0 END AS tiene_distribuidor,
            COUNT(*) AS total
          FROM pats_pasaportes
          WHERE activo = 1
            AND COALESCE(id_franquicia,0) = {$idFranquicia}
          GROUP BY frecuencia_pago, CASE WHEN COALESCE(id_distribuidor,0) > 0 THEN 1 ELSE 0 END
        ");

        foreach ($pats as $p) {
          $freq = strtolower(trim((string)($p['frecuencia_pago'] ?? 'mensual')));
          $n = (int)($p['total'] ?? 0);
          if ($n <= 0) continue;

          $tieneDistribuidor = ((int)($p['tiene_distribuidor'] ?? 0)) === 1;
          $montoUnitario = pats_sync_split_franquicia_por_pats($cx, $freq, $tieneDistribuidor);
          $monto = pats_sync_num($n * $montoUnitario);

          $acum = pats_sync_num($acum + $monto);
          $porPagar = pats_sync_num($porPagar + $monto);
          $debugRow['pats_franquicia'] = pats_sync_num($debugRow['pats_franquicia'] + $monto);

          if ($debug) {
            if (!isset($debugRow['pats_detalle'])) $debugRow['pats_detalle'] = [];
            $debugRow['pats_detalle'][] = [
              'frecuencia' => $freq,
              'tiene_distribuidor' => $tieneDistribuidor,
              'total' => $n,
              'monto_unitario_franquicia' => $montoUnitario,
              'monto_total' => $monto
            ];
          }
        }
      }

      $porPagar = pats_sync_num(max(0, $acum - $pagada));
      $debugRow['pagada'] = $pagada;
      $debugRow['por_pagar'] = $porPagar;
      $debugRow['total_cache'] = $acum;

      if (!$dryRun) {
        $stmt = $cx->prepare("
          UPDATE pats_franquicias
          SET
            comision_acumulada_periodo = ?,
            comision_pagada_periodo = ?,
            comision_por_pagar_periodo = ?,
            updated_at = NOW()
          WHERE id_franquicia = ?
          LIMIT 1
        ");
        if (!$stmt) {
          throw new RuntimeException('No fue posible preparar update pats_franquicias: ' . $cx->error);
        }
        $stmt->bind_param('dddi', $acum, $pagada, $porPagar, $idFranquicia);
        if (!$stmt->execute()) {
          throw new RuntimeException('No fue posible actualizar pats_franquicias: ' . $stmt->error);
        }
        if ($stmt->affected_rows >= 0) $out['franquicias_actualizadas']++;
        $stmt->close();
      }

      if ($debug || $idFranquicia === (int)($opts['id_franquicia'] ?? 0)) {
        $out['franquicias'][] = $debugRow;
      }
    }

    /* =====================================================
       2) DISTRIBUIDORES
       - La venta de distribución NO es comisión para el distribuidor
       - El distribuidor acumula por PATS vendidos con su id_distribuidor
    ===================================================== */
    $distribuidores = pats_sync_all($cx, "
      SELECT id_distribuidor, id_franquicia, nombre, activo
      FROM pats_distribuidores
      WHERE activo = 1
      ORDER BY id_distribuidor ASC
    ");

    foreach ($distribuidores as $d) {
      $idDist = (int)($d['id_distribuidor'] ?? 0);
      if ($idDist <= 0) continue;

      $calcPats = 0.0;
      if ($hasPasaportes) {
        $pats = pats_sync_all($cx, "
          SELECT frecuencia_pago, COUNT(*) AS total
          FROM pats_pasaportes
          WHERE activo = 1
            AND COALESCE(id_distribuidor,0) = {$idDist}
          GROUP BY frecuencia_pago
        ");
        foreach ($pats as $p) {
          $freq = strtolower(trim((string)($p['frecuencia_pago'] ?? 'mensual')));
          $n = (int)($p['total'] ?? 0);
          if ($n <= 0) continue;
          $calcPats = pats_sync_num($calcPats + ($n * pats_sync_split_distribuidor($cx, $freq)));
        }
      }

      $gen = pats_sync_generated_commission($cx, 'distribuidor', $idDist, null, null);
      $genTotal = pats_sync_num($gen['total'] ?? 0);
      $genPagada = pats_sync_num($gen['pagada'] ?? 0);

      $acum = max($genTotal, $calcPats);
      $pagada = $genPagada;
      $porPagar = pats_sync_num(max(0, $acum - $pagada));

      if (!$dryRun) {
        $stmt = $cx->prepare("
          UPDATE pats_distribuidores
          SET
            comision_acumulada_periodo = ?,
            comision_pagada_periodo = ?,
            comision_por_pagar_periodo = ?,
            updated_at = NOW()
          WHERE id_distribuidor = ?
          LIMIT 1
        ");
        if (!$stmt) {
          throw new RuntimeException('No fue posible preparar update pats_distribuidores: ' . $cx->error);
        }
        $stmt->bind_param('dddi', $acum, $pagada, $porPagar, $idDist);
        if (!$stmt->execute()) {
          throw new RuntimeException('No fue posible actualizar pats_distribuidores: ' . $stmt->error);
        }
        if ($stmt->affected_rows >= 0) $out['distribuidores_actualizados']++;
        $stmt->close();
      }

      if ($debug || $idDist === (int)($opts['id_distribuidor'] ?? 0)) {
        $out['distribuidores'][] = [
          'id_distribuidor' => $idDist,
          'nombre' => (string)($d['nombre'] ?? ''),
          'calculada_por_pats' => $calcPats,
          'generada_total' => $genTotal,
          'pagada' => $pagada,
          'por_pagar' => $porPagar,
          'total_cache' => $acum,
          'nota' => 'La venta de distribución no es comisión del distribuidor; solo PATS vendidos con su id_distribuidor.'
        ];
      }
    }

    /* =====================================================
       3) GESTORES
       - Gestor acumula:
         a) Comisión por venta de franquicia asociada:
            $50,000 teóricos * porcentaje cobrado del contrato.
            Si ya existe comisión generada, se respeta la generada.
         b) Comisión por venta de distribución de franquicia asociada:
            $1,000 por distribución.
            Si ya existe comisión generada, se respeta la generada.
         c) Otras comisiones ya generadas para gestor, por ejemplo PATS directo.
       - No crea filas en pats_comisiones_generadas.
    ===================================================== */
    if ($canSyncGestores) {
      $gestores = pats_sync_all($cx, "
        SELECT id_gestor, nombre_gestor, activo
        FROM pats_gestores
        WHERE activo = 1
        ORDER BY id_gestor ASC
      ");

      foreach ($gestores as $g) {
        $idGestor = (int)($g['id_gestor'] ?? 0);
        if ($idGestor <= 0) continue;

        $acum = 0.0;
        $pagada = 0.0;
        $porPagar = 0.0;

        $eventKeys = [];

        $debugRow = [
          'id_gestor' => $idGestor,
          'nombre' => (string)($g['nombre_gestor'] ?? ''),
          'franquicias' => [],
          'distribuciones' => [],
          'otras_generadas' => 0.0,
          'generada' => 0.0,
          'fallback' => 0.0,
          'pagada' => 0.0,
          'por_pagar' => 0.0
        ];

        $franqRel = pats_sync_all($cx, "
          SELECT
            f.id_franquicia,
            f.nombre_franquicia,
            f.franquiciatario,
            f.valor_franquicia
          FROM pats_gestor_franquicias gf
          INNER JOIN pats_franquicias f
            ON f.id_franquicia = gf.id_franquicia
           AND f.activo = 1
          WHERE gf.id_gestor = {$idGestor}
            AND gf.activo = 1
          ORDER BY f.id_franquicia ASC
        ");

        foreach ($franqRel as $fr) {
          $idFranquicia = (int)($fr['id_franquicia'] ?? 0);
          if ($idFranquicia <= 0) continue;

          /* a) Comisión por venta de franquicia */
          $genFranq = pats_sync_generated_commission($cx, 'gestor', $idGestor, 'venta_franquicia', $idFranquicia);
          $genFranqTotal = pats_sync_num($genFranq['total'] ?? 0);
          $genFranqPagada = pats_sync_num($genFranq['pagada'] ?? 0);

          $pctPagado = pats_sync_porcentaje_pagado_contrato($cx, 'franquicia', $idFranquicia);
          $fallbackFranq = $genFranqTotal > 0 ? 0.0 : pats_sync_num(50000.00 * $pctPagado);

          /*
            Si no hay contrato/pago registrado, dejamos fallback en 0
            para no inventar comisión liberada sin cobro real.
            Cuando exista comisión generada oficial, se respeta.
          */
          $usarFranqTotal = $genFranqTotal > 0 ? $genFranqTotal : $fallbackFranq;
          $usarFranqPagada = $genFranqTotal > 0 ? $genFranqPagada : 0.0;

          $acum = pats_sync_num($acum + $usarFranqTotal);
          $pagada = pats_sync_num($pagada + $usarFranqPagada);

          $debugRow['generada'] = pats_sync_num($debugRow['generada'] + $genFranqTotal);
          $debugRow['fallback'] = pats_sync_num($debugRow['fallback'] + $fallbackFranq);
          $debugRow['franquicias'][] = [
            'id_franquicia' => $idFranquicia,
            'nombre' => (string)($fr['nombre_franquicia'] ?? $fr['franquiciatario'] ?? ''),
            'porcentaje_pagado_contrato' => round($pctPagado * 100, 2),
            'generada_total' => $genFranqTotal,
            'fallback_usado' => $fallbackFranq,
            'comision_total_usada' => $usarFranqTotal
          ];

          $eventKeys[] = "venta_franquicia:{$idFranquicia}";

          /* b) Comisión por distribuciones de esta franquicia */
          $distsGestor = pats_sync_all($cx, "
            SELECT id_distribuidor, valor_distribucion
            FROM pats_distribuidores
            WHERE activo = 1
              AND id_franquicia = {$idFranquicia}
            ORDER BY id_distribuidor ASC
          ");

          foreach ($distsGestor as $d) {
            $idDist = (int)($d['id_distribuidor'] ?? 0);
            if ($idDist <= 0) continue;

            $genDist = pats_sync_generated_commission($cx, 'gestor', $idGestor, 'venta_distribucion', $idDist);
            $genDistTotal = pats_sync_num($genDist['total'] ?? 0);
            $genDistPagada = pats_sync_num($genDist['pagada'] ?? 0);

            $fallbackDist = $genDistTotal > 0 ? 0.0 : 1000.00;
            $usarDistTotal = $genDistTotal > 0 ? $genDistTotal : $fallbackDist;
            $usarDistPagada = $genDistTotal > 0 ? $genDistPagada : 0.0;

            $acum = pats_sync_num($acum + $usarDistTotal);
            $pagada = pats_sync_num($pagada + $usarDistPagada);

            $debugRow['generada'] = pats_sync_num($debugRow['generada'] + $genDistTotal);
            $debugRow['fallback'] = pats_sync_num($debugRow['fallback'] + $fallbackDist);
            $debugRow['distribuciones'][] = [
              'id_distribuidor' => $idDist,
              'id_franquicia' => $idFranquicia,
              'valor_distribucion' => pats_sync_num($d['valor_distribucion'] ?? 0),
              'generada_total' => $genDistTotal,
              'fallback_usado' => $fallbackDist,
              'comision_total_usada' => $usarDistTotal
            ];

            $eventKeys[] = "venta_distribucion:{$idDist}";
          }
        }

        /*
          c) Otras comisiones generadas para gestor no incluidas en los eventos anteriores.
          Ejemplo: pago_pasaporte directo del gestor, ajustes manuales, etc.
        */
        if ($hasComisiones) {
          $allGestorRows = pats_sync_all($cx, "
            SELECT
              cg.tipo_origen,
              cg.id_origen,
              cg.monto_comision,
              cg.estatus
            FROM pats_comisiones_generadas cg
            WHERE LOWER(TRIM(cg.beneficiario_tipo)) = 'gestor'
              AND cg.beneficiario_id = {$idGestor}
          ");

          foreach ($allGestorRows as $cg) {
            $key = (string)($cg['tipo_origen'] ?? '') . ':' . (int)($cg['id_origen'] ?? 0);
            if (in_array($key, $eventKeys, true)) {
              continue;
            }

            $monto = pats_sync_num($cg['monto_comision'] ?? 0);
            if ($monto <= 0) continue;

            $acum = pats_sync_num($acum + $monto);
            if (strtolower(trim((string)($cg['estatus'] ?? ''))) === 'pagado') {
              $pagada = pats_sync_num($pagada + $monto);
            }
            $debugRow['otras_generadas'] = pats_sync_num($debugRow['otras_generadas'] + $monto);
          }
        }

        $porPagar = pats_sync_num(max(0, $acum - $pagada));

        $debugRow['pagada'] = $pagada;
        $debugRow['por_pagar'] = $porPagar;
        $debugRow['total_cache'] = $acum;

        if (!$dryRun) {
          $stmt = $cx->prepare("
            UPDATE pats_gestores
            SET
              comision_acumulada_periodo = ?,
              comision_pagada_periodo = ?,
              comision_por_pagar_periodo = ?,
              updated_at = NOW()
            WHERE id_gestor = ?
            LIMIT 1
          ");
          if (!$stmt) {
            throw new RuntimeException('No fue posible preparar update pats_gestores: ' . $cx->error);
          }
          $stmt->bind_param('dddi', $acum, $pagada, $porPagar, $idGestor);
          if (!$stmt->execute()) {
            throw new RuntimeException('No fue posible actualizar pats_gestores: ' . $stmt->error);
          }
          if ($stmt->affected_rows >= 0) $out['gestores_actualizados']++;
          $stmt->close();
        }

        if ($debug || $idGestor === (int)($opts['id_gestor'] ?? 0)) {
          $out['gestores'][] = $debugRow;
        }
      }
    }

    return $out;
  }
}

/* =========================================================
   Ejecución directa por navegador
========================================================= */
$__patsSyncDirect = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__);

if ($__patsSyncDirect) {
  try {
    $res = pats_sync_comisiones_cache($cx, [
      'dry_run' => !empty($_GET['dry_run']),
      'debug' => !empty($_GET['debug']),
      'id_franquicia' => (int)($_GET['id_franquicia'] ?? 0),
      'id_distribuidor' => (int)($_GET['id_distribuidor'] ?? 0),
      'id_gestor' => (int)($_GET['id_gestor'] ?? 0),
    ]);

    pats_json($res);
  } catch (Throwable $e) {
    pats_json([
      'ok' => false,
      'error' => $e->getMessage()
    ], 500);
  }
}
