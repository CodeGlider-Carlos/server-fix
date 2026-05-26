<?php
/*
Archivo: ez/pats/public_checkout_resolver.php
Módulo: PATS
Propósito: Resolver tokens públicos de checkout para distribuidores, gestores y franquicias.
Responsabilidad:
- Identificar el actor comercial dueño de un token público.
- Permitir tokens de distribuidores activos.
- Permitir tokens de franquicias activas.
- Permitir tokens de gestores activos, estén o no asociados a una franquicia.
Conexiones:
- pats_distribuidores
- pats_gestores
- pats_gestor_franquicias
- pats_franquicias
Tipo: Archivo específico PATS / resolver público de origen comercial.
*/

declare(strict_types=1);

function pats_public_checkout_fetch_distribuidor(mysqli $cx, string $token): ?array {
  $stmt = $cx->prepare("
    SELECT
      'DISTRIBUIDOR' AS actor_tipo_publico,
      'DISTRIBUIDOR' AS tipo_origen,
      d.id_distribuidor,
      0 AS id_gestor,
      d.id_franquicia,
      d.nombre AS nombre_actor_publico,
      d.correo,
      d.telefono,
      d.region,
      d.zona,
      d.unidad,
      d.public_checkout_token AS token_publico,
      d.public_checkout_activo AS token_activo,
      d.public_checkout_updated_at AS token_updated_at,
      f.nombre_franquicia,
      COALESCE(f.pais, 'México') AS pais
    FROM pats_distribuidores d
    LEFT JOIN pats_franquicias f
      ON f.id_franquicia = d.id_franquicia
    WHERE d.public_checkout_token = ?
      AND d.public_checkout_activo = 1
      AND d.activo = 1
    LIMIT 1
  ");

  if (!$stmt) {
    throw new RuntimeException('No fue posible preparar token de distribuidor: ' . $cx->error);
  }

  $stmt->bind_param('s', $token);
  $stmt->execute();

  $rs = $stmt->get_result();
  $row = $rs ? $rs->fetch_assoc() : null;

  $stmt->close();

  return $row ?: null;
}

function pats_public_checkout_fetch_gestor(mysqli $cx, string $token): ?array {
  /*
    IMPORTANTE:
    Antes este resolver usaba INNER JOIN contra pats_gestor_franquicias y pats_franquicias.
    Eso hacía inválido el token de un gestor si no tenía franquicia asociada.

    Nueva regla:
    - Gestor activo + token activo = token válido.
    - Si tiene franquicia activa relacionada, se conserva id_franquicia/región/zona/unidad.
    - Si no tiene franquicia activa, entra como gestor directo sin franquicia.
  */
  $sql = "
    SELECT
      'GESTOR' AS actor_tipo_publico,
      'GESTOR' AS tipo_origen,
      0 AS id_distribuidor,
      g.id_gestor,
      COALESCE(f.id_franquicia, 0) AS id_franquicia,
      g.nombre_gestor AS nombre_actor_publico,
      g.correo,
      g.telefono,
      COALESCE(f.region, '') AS region,
      COALESCE(f.zona, '') AS zona,
      COALESCE(f.unidad, '') AS unidad,
      g.public_checkout_token AS token_publico,
      g.public_checkout_activo AS token_activo,
      g.public_checkout_updated_at AS token_updated_at,
      COALESCE(f.nombre_franquicia, '') AS nombre_franquicia,
      COALESCE(f.pais, 'México') AS pais
    FROM pats_gestores g
    LEFT JOIN pats_gestor_franquicias rel
      ON rel.id_gestor = g.id_gestor
     AND rel.activo = 1
    LEFT JOIN pats_franquicias f
      ON f.id_franquicia = rel.id_franquicia
     AND f.activo = 1
    WHERE g.public_checkout_token = ?
      AND g.public_checkout_activo = 1
      AND g.activo = 1
    ORDER BY
      CASE WHEN f.id_franquicia IS NULL THEN 1 ELSE 0 END ASC,
      rel.id_relacion DESC
    LIMIT 1
  ";

  $stmt = $cx->prepare($sql);

  if (!$stmt) {
    throw new RuntimeException('No fue posible preparar token de gestor: ' . $cx->error);
  }

  $stmt->bind_param('s', $token);
  $stmt->execute();

  $rs = $stmt->get_result();
  $row = $rs ? $rs->fetch_assoc() : null;

  $stmt->close();

  return $row ?: null;
}

function pats_public_checkout_fetch_franquicia(mysqli $cx, string $token): ?array {
  $stmt = $cx->prepare("
    SELECT
      'FRANQUICIA' AS actor_tipo_publico,
      'FRANQUICIA' AS tipo_origen,
      0 AS id_distribuidor,
      0 AS id_gestor,
      f.id_franquicia,
      f.nombre_franquicia AS nombre_actor_publico,
      f.correo,
      f.telefono,
      f.region,
      f.zona,
      f.unidad,
      f.public_checkout_token AS token_publico,
      f.public_checkout_activo AS token_activo,
      f.public_checkout_updated_at AS token_updated_at,
      f.nombre_franquicia,
      COALESCE(f.pais, 'México') AS pais
    FROM pats_franquicias f
    WHERE f.public_checkout_token = ?
      AND f.public_checkout_activo = 1
      AND f.activo = 1
    LIMIT 1
  ");

  if (!$stmt) {
    throw new RuntimeException('No fue posible preparar token de franquicia: ' . $cx->error);
  }

  $stmt->bind_param('s', $token);
  $stmt->execute();

  $rs = $stmt->get_result();
  $row = $rs ? $rs->fetch_assoc() : null;

  $stmt->close();

  return $row ?: null;
}

function pats_resolve_public_checkout_token(mysqli $cx, string $token): ?array {
  $token = trim($token);

  if ($token === '') {
    return null;
  }

  /*
    Orden intencional:
    1. Distribuidor
    2. Gestor
    3. Franquicia

    No se cambia para no romper links existentes.
  */
  $ctx = pats_public_checkout_fetch_distribuidor($cx, $token);

  if (!$ctx) {
    $ctx = pats_public_checkout_fetch_gestor($cx, $token);
  }

  if (!$ctx) {
    $ctx = pats_public_checkout_fetch_franquicia($cx, $token);
  }

  if (!$ctx) {
    return null;
  }

  $ctx['actor_tipo_publico'] = (string)($ctx['actor_tipo_publico'] ?? '');
  $ctx['tipo_origen'] = (string)($ctx['tipo_origen'] ?? $ctx['actor_tipo_publico']);

  $ctx['id_distribuidor'] = (int)($ctx['id_distribuidor'] ?? 0);
  $ctx['id_gestor'] = (int)($ctx['id_gestor'] ?? 0);
  $ctx['id_franquicia'] = (int)($ctx['id_franquicia'] ?? 0);

  $ctx['nombre_actor_publico'] = (string)($ctx['nombre_actor_publico'] ?? '');
  $ctx['correo'] = (string)($ctx['correo'] ?? '');
  $ctx['telefono'] = (string)($ctx['telefono'] ?? '');

  $ctx['region'] = (string)($ctx['region'] ?? '');
  $ctx['zona'] = (string)($ctx['zona'] ?? '');
  $ctx['unidad'] = (string)($ctx['unidad'] ?? '');

  $ctx['token_publico'] = (string)($ctx['token_publico'] ?? '');
  $ctx['token_activo'] = (int)($ctx['token_activo'] ?? 0);
  $ctx['token_updated_at'] = (string)($ctx['token_updated_at'] ?? '');

  $ctx['nombre_franquicia'] = (string)($ctx['nombre_franquicia'] ?? '');
  $ctx['pais'] = (string)($ctx['pais'] ?? 'México');

  /*
    Alias esperados por otros archivos PATS.
  */
  $ctx['public_checkout_token'] = $ctx['token_publico'];
  $ctx['public_checkout_activo'] = $ctx['token_activo'];
  $ctx['public_checkout_updated_at'] = $ctx['token_updated_at'];

  $ctx['nombre_distribuidor'] = $ctx['actor_tipo_publico'] === 'DISTRIBUIDOR'
    ? $ctx['nombre_actor_publico']
    : '';

  $ctx['nombre_gestor'] = $ctx['actor_tipo_publico'] === 'GESTOR'
    ? $ctx['nombre_actor_publico']
    : '';

  $ctx['nombre_franquiciatario'] = $ctx['actor_tipo_publico'] === 'FRANQUICIA'
    ? $ctx['nombre_actor_publico']
    : '';

  /*
    Regla defensiva:
    Si por alguna razón tipo_origen viene vacío, se reconstruye desde IDs.
  */
  if ($ctx['tipo_origen'] === '') {
    if ($ctx['id_distribuidor'] > 0) {
      $ctx['tipo_origen'] = 'DISTRIBUIDOR';
    } elseif ($ctx['id_gestor'] > 0) {
      $ctx['tipo_origen'] = 'GESTOR';
    } elseif ($ctx['id_franquicia'] > 0) {
      $ctx['tipo_origen'] = 'FRANQUICIA';
    } else {
      $ctx['tipo_origen'] = 'ADMINPATS';
    }
  }

  return $ctx;
}