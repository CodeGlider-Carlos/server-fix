<?php
/*
Landing PATS Distribuidores
Resuelve teléfono de contacto según token comercial.

Regla:
1. Si el token pertenece a un gestor activo → teléfono del gestor.
2. Si el token pertenece a una franquicia activa → teléfono de la franquicia.
3. Si no hay token o no coincide con gestor/franquicia → teléfono corporativo predeterminado.
*/

declare(strict_types=1);

require_once '../../varSQL/bd_pats.php';
require_once '../../varSQL/var_pats.php';

$db_pats = pdo_conn($servidor, $pats, $usuario, $password, 'PATS');

$tokenLanding = trim((string)($_GET['t'] ?? ''));

/* Teléfono corporativo predeterminado */
$telefonoAsesor = '+52 222 343 4609';
$telefonoHref   = '+522223434609';
$telefonoWa     = '522223434609';
$tipoContacto   = 'DEFAULT';

/**
 * Normaliza teléfono para:
 * - Mostrar al usuario
 * - Link tel:
 * - Link WhatsApp
 */
function pats_landing_set_phone(string $telDb, string &$telefonoAsesor, string &$telefonoHref, string &$telefonoWa): void
{
    $telDb = trim($telDb);

    if ($telDb === '') {
        return;
    }

    $telefonoAsesor = $telDb;

    $href = preg_replace('/[^0-9+]/', '', $telDb) ?: '';
    $wa   = preg_replace('/\D+/', '', $telDb) ?: '';

    if ($href !== '' && $href[0] !== '+') {
        $href = '+52' . $href;
    }

    if ($wa !== '' && strpos($wa, '52') !== 0) {
        $wa = '52' . $wa;
    }

    if ($href !== '') {
        $telefonoHref = $href;
    }

    if ($wa !== '') {
        $telefonoWa = $wa;
    }
}

if ($tokenLanding !== '') {
    try {
        /*
         * 1) Primero buscar en gestores.
         * Si el token viene de gestor, debe contactar al gestor.
         */
        $stmt = $db_pats->prepare("
            SELECT telefono
            FROM pats_gestores
            WHERE public_checkout_token = :token
              AND public_checkout_activo = 1
              AND activo = 1
            LIMIT 1
        ");

        $stmt->execute([
            ':token' => $tokenLanding
        ]);

        $telGestor = trim((string)$stmt->fetchColumn());

        if ($telGestor !== '') {
            pats_landing_set_phone($telGestor, $telefonoAsesor, $telefonoHref, $telefonoWa);
            $tipoContacto = 'GESTOR';
        } else {
            /*
             * 2) Si no fue gestor, buscar en franquicias.
             * Si el token viene de franquicia, debe contactar a la franquicia.
             */
            $stmt = $db_pats->prepare("
                SELECT telefono
                FROM pats_franquicias
                WHERE public_checkout_token = :token
                  AND public_checkout_activo = 1
                  AND activo = 1
                LIMIT 1
            ");

            $stmt->execute([
                ':token' => $tokenLanding
            ]);

            $telFranquicia = trim((string)$stmt->fetchColumn());

            if ($telFranquicia !== '') {
                pats_landing_set_phone($telFranquicia, $telefonoAsesor, $telefonoHref, $telefonoWa);
                $tipoContacto = 'FRANQUICIA';
            }
        }
    } catch (Throwable $e) {
        /*
         * Fallback silencioso:
         * si falla BD o el token no puede resolverse,
         * la landing pública no se rompe y usa teléfono corporativo.
         */
        $tipoContacto = 'DEFAULT';
    }
}

$telefonoAsesorSafe = htmlspecialchars($telefonoAsesor, ENT_QUOTES, 'UTF-8');
$telefonoHrefSafe   = htmlspecialchars($telefonoHref, ENT_QUOTES, 'UTF-8');
$telefonoWaSafe     = htmlspecialchars($telefonoWa, ENT_QUOTES, 'UTF-8');
$tokenLandingSafe   = htmlspecialchars($tokenLanding, ENT_QUOTES, 'UTF-8');
$tipoContactoSafe   = htmlspecialchars($tipoContacto, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Sé Distribuidor PATS · 50 Doctors</title>

  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet" />

  <style>
    *,*::before,*::after{
      box-sizing:border-box;
      margin:0;
      padding:0;
    }

    :root{
      /* BASE */
      --bg-page:#EEF1F5;
      --bg-soft:#E6EBF1;
      --bg-panel:#F7F9FC;
      --bg-card:#FFFFFF;
      --bg-dark:#001e4e;
      --bg-dark-2:#001437;

      /* TEXT */
      --ink:#071528;
      --ink-2:#14233B;
      --text:#16243A;
      --text-soft:#5D6A7E;
      --text-faint:#8895A8;
      --white:#FFFFFF;

      /* COLD SOLID PALETTE */
      --blue:#165DFF;
      --blue-2:#0E49D8;
      --blue-soft:#DCE7FF;

      --indigo:#6366F1;
      --indigo-2:#5458D9;
      --indigo-soft:#E2E3FF;

      --teal:#184eff;
      --teal-2:#8affc8;
      --teal-soft:#D7EEF0;

      --slate:#31435E;
      --slate-soft:#DDE4EE;

      --mint:#58fcce;
      --mint-soft:#DDF5F0;

      --success:#5ef5d6;
      --success-soft:#E1F5F0;

      /* BORDERS */
      --border:#CCD6E3;
      --border-strong:#AAB9CC;
      --border-dark:rgba(255,255,255,.10);

      /* SHADOWS */
      --shadow-xs:0 4px 12px rgba(5,17,35,.05);
      --shadow-sm:0 12px 24px rgba(5,17,35,.08);
      --shadow-md:0 18px 38px rgba(5,17,35,.12);
      --shadow-lg:0 28px 60px rgba(5,17,35,.16);

      --shadow-3d-sm:0 12px 22px rgba(5,17,35,.08), 0 3px 0 rgba(5,17,35,.06);
      --shadow-3d-md:0 18px 34px rgba(5,17,35,.12), 0 4px 0 rgba(5,17,35,.07);
      --shadow-3d-lg:0 28px 58px rgba(5,17,35,.16), 0 4px 0 rgba(5,17,35,.08);

      --shadow-blue:0 18px 34px rgba(22,93,255,.18), 0 4px 0 #0E49D8;
      --shadow-indigo:0 18px 34px rgba(99,102,241,.17), 0 4px 0 #5458D9;
      --shadow-teal:0 18px 34px rgba(12,122,134,.16), 0 4px 0 #0A6671;
      --shadow-dark:0 24px 44px rgba(5,17,35,.25), 0 4px 0 rgba(5,17,35,.14);

      --bevel-light:inset 0 1px 0 rgba(255,255,255,.95);
      --bevel-soft:inset 0 1px 0 rgba(255,255,255,.8), inset 0 -1px 0 rgba(5,17,35,.04);

      --radius-sm:16px;
      --radius-md:22px;
      --radius-lg:28px;

      --ff-display:"Plus Jakarta Sans", Arial, sans-serif;
      --ff-body:"Plus Jakarta Sans", Arial, sans-serif;
    }

    html{
      scroll-behavior:smooth;
      font-size:16px;
    }

    body{
      font-family:var(--ff-body);
      background:var(--bg-page);
      color:var(--text);
      -webkit-font-smoothing:antialiased;
      overflow-x:hidden;
      perspective:1200px;
    }

    img,svg{
      display:block;
      max-width:100%;
    }

    a{
      color:inherit;
      text-decoration:none;
    }

    ul{ list-style:none; }

    .container{
      max-width:1200px;
      margin:0 auto;
      padding:0 1.5rem;
    }

    /* NAV */
    .nav{
      position:fixed;
      top:0;
      left:0;
      right:0;
      z-index:999;
      background:rgba(255,255,255,.92);
      backdrop-filter:blur(14px);
      border-bottom:1px solid var(--border);
      box-shadow:0 2px 14px rgba(5,17,35,.04);
      transition:box-shadow .25s ease, background .25s ease;
      background: radial-gradient(circle at 88% 20%, rgba(0,212,255,.20), transparent 34%), linear-gradient(135deg, #071438 0%, #17206b 52%, #632bd4 100%) !important;
  box-shadow: 0 22px 54px rgba(20,28,68,.18) !important;
    }

    .nav.scrolled{
      box-shadow:0 12px 30px rgba(5,17,35,.10);
      background:rgba(255,255,255,.96);
    }

    .nav-inner{
      max-width:1200px;
      margin:0 auto;
      padding:0 1.5rem;
      height:78px;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:2rem;
    }

    .nav-logo{
      font-family:var(--ff-display);
      font-size:1.42rem;
      font-weight:800;
      color:white;
      letter-spacing:-.045em;
      display:flex;
      align-items:center;
      gap:.35rem;
    }

    .nav-logo em{
      width:8px;
      height:8px;
      border-radius:999px;
      background:var(--blue);
      display:inline-block;
      font-style:normal;
      transform:translateY(1px);
      box-shadow:0 0 0 6px rgba(22,93,255,.10);
    }

    .nav-links{
      display:none;
      align-items:center;
      gap:2.15rem;
    }

    .nav-links a{
      color:white;
      font-size:.84rem;
      font-weight:800;
      transition:color .2s ease, transform .2s ease;
    }

    .nav-links a:hover{
      color:var(--blue);
      transform:translateY(-1px);
    }

    .nav-cta{
      background:var(--blue)!important;
      color:#fff!important;
      border:1px solid var(--blue-2);
      border-radius:14px;
      padding:.78rem 1.55rem;
      box-shadow:var(--shadow-blue), inset 0 1px 0 rgba(255,255,255,.22);
    }

    .nav-cta:hover{
      background:var(--blue-2)!important;
    }

    /* HERO */
    .hero{
      min-height:100svh;
      display:flex;
      align-items:center;
      padding-top:78px;
      background:
        linear-gradient(to right, #F2F4F8 0%, #F2F4F8 64%, #E8EDF3 64%, #E8EDF3 100%);
      position:relative;
      overflow:hidden;
      
    }

    .hero::before{
      content:"";
      position:absolute;
      inset:0;
      background:
        linear-gradient(90deg, rgba(20,35,59,.045) 1px, transparent 1px),
        linear-gradient(rgba(20,35,59,.045) 1px, transparent 1px);
      background-size:56px 56px;
      opacity:.28;
      pointer-events:none;
    }

    .hero::after{
      content:"";
      position:absolute;
      top:78px;
      bottom:0;
      left:64%;
      width:1px;
      background:rgba(20,35,59,.10);
      z-index:0;
    }

    .hero-inner{
      width:100%;
      display:grid;
      gap:3rem;
      align-items:center;
      padding:5.4rem 1.5rem 4.8rem;
      position:relative;
      z-index:1;
    }

    .hero-left{
      max-width:760px;
    }

    .hero-tag{
      display:inline-flex;
      align-items:center;
      gap:.55rem;
      border:1px solid var(--border-strong);
      background:#fff;
      color:var(--indigo);
      font-size:.72rem;
      font-weight:900;
      letter-spacing:.14em;
      text-transform:uppercase;
      padding:.58rem 1.18rem;
      border-radius:999px;
      margin-bottom:1.7rem;
      box-shadow:0 10px 20px rgba(5,17,35,.06), 0 2px 0 #D9E1EB, inset 0 1px 0 rgba(255,255,255,.95);
    }

    .hero-title{
      font-family:var(--ff-display);
      font-size:clamp(2.75rem,6.3vw,5.4rem);
      font-weight:800;
      line-height:1.01;
      color:var(--ink);
      letter-spacing:-.075em;
      margin-bottom:1.45rem;
    }

    .hero-title .accent{
      color:var(--blue);
    }

    .hero-lead{
      color:var(--text-soft);
      font-size:1.08rem;
      line-height:1.9;
      max-width:620px;
      margin-bottom:2.1rem;
    }

    .hero-badges{
      display:flex;
      flex-wrap:wrap;
      gap:.75rem;
      margin-bottom:2.1rem;
    }

    .badge{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-height:38px;
      padding:.5rem 1rem;
      border-radius:999px;
      font-size:.74rem;
      font-weight:900;
      border:1px solid;
      box-shadow:0 8px 18px rgba(5,17,35,.06), 0 2px 0 rgba(5,17,35,.05), inset 0 1px 0 rgba(255,255,255,.95);
    }

    .badge-blue{
      color:var(--blue);
      background:var(--blue-soft);
      border-color:#C5D5FF;
    }

    .badge-indigo{
      color:var(--indigo);
      background:var(--indigo-soft);
      border-color:#CBCBFF;
    }

    .badge-slate{
      color:var(--slate);
      background:var(--slate-soft);
      border-color:#CFD8E5;
    }

    .hero-actions{
      display:flex;
      flex-wrap:wrap;
      gap:1rem;
    }

    /* BUTTONS */
    .btn-primary,
    .btn-secondary{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:.65rem;
      padding:1rem 2.15rem;
      border-radius:16px;
      font-size:.95rem;
      font-weight:900;
      line-height:1;
      transition:transform .18s ease, box-shadow .18s ease, background .18s ease, border-color .18s ease;
      border:1px solid transparent;
      cursor:pointer;
      position:relative;
      top:0;
    }

    .btn-primary{
      background:var(--blue);
      color:#fff;
      border-color:var(--blue-2);
      box-shadow:var(--shadow-blue), inset 0 1px 0 rgba(255,255,255,.25);
    }

    .btn-primary:hover{
      transform:translateY(-2px);
      background:var(--blue-2);
    }

    .btn-primary:active{
      transform:translateY(2px);
      box-shadow:0 8px 18px rgba(22,93,255,.16),0 1px 0 #0E49D8;
    }

    .btn-secondary{
      background:#fff;
      color:var(--ink);
      border-color:var(--border-strong);
      box-shadow:0 10px 22px rgba(5,17,35,.07), 0 4px 0 #D8E0EA, inset 0 1px 0 rgba(255,255,255,.95);
    }

    .btn-secondary:hover{
      background:#F9FBFD;
      border-color:var(--blue);
      color:var(--blue);
      transform:translateY(-2px);
    }

    .btn-secondary:active{
      transform:translateY(2px);
      box-shadow:0 6px 12px rgba(5,17,35,.05), 0 1px 0 #D8E0EA;
    }

    /* HERO INVEST */
    .hero-invest{
      background:#fff;
      border:1px solid #B8C6D9;
      border-radius:28px;
      box-shadow:var(--shadow-3d-lg), var(--bevel-light);
      padding:2rem;
      display:flex;
      flex-direction:column;
      gap:1.25rem;
      position:relative;
      transform:rotateY(-4deg) rotateX(2deg);
      transform-style:preserve-3d;
      transition:transform .26s ease, box-shadow .26s ease;
      overflow:visible;
    }

    .hero-invest:hover{
      transform:translateY(-8px) rotateY(-2deg) rotateX(1deg);
      box-shadow:0 38px 72px rgba(5,17,35,.18),0 5px 0 rgba(5,17,35,.08), var(--bevel-light);
    }

    .hero-invest::before{
      content:"";
      position:absolute;
      top:0;
      left:0;
      right:0;
      height:10px;
      background:var(--bg-dark);
      border-top-left-radius:28px;
      border-top-right-radius:28px;
      box-shadow:inset 0 1px 0 rgba(255,255,255,.18);
    }

    .hero-invest::after{
      content:"";
      position:absolute;
      left:22px;
      right:22px;
      bottom:-14px;
      height:24px;
      background:rgba(5,17,35,.12);
      filter:blur(14px);
      border-radius:999px;
      z-index:-1;
    }

    .inv-label{
      font-size:.68rem;
      font-weight:900;
      letter-spacing:.14em;
      text-transform:uppercase;
      color:var(--text-faint);
      margin-bottom:.75rem;
    }

    .inv-amount{
      font-family:var(--ff-display);
      color:var(--blue);
      font-size:3.1rem;
      line-height:1;
      font-weight:800;
      letter-spacing:-.075em;
      margin-bottom:.45rem;
    }

    .inv-sub{
      color:var(--text-soft);
      font-size:.88rem;
      line-height:1.6;
    }

    .inv-sep{
      height:1px;
      background:var(--border);
    }

    .inv-note{
      display:flex;
      gap:.75rem;
      align-items:flex-start;
      background:#DCE3F4;
      border:1px solid #C5D0EB;
      border-radius:18px;
      padding:1rem;
      color:var(--blue);
      font-weight:900;
      font-size:.88rem;
      line-height:1.55;
      box-shadow:inset 0 1px 0 rgba(255,255,255,.85);
    }

    .inv-note svg{
      width:18px;
      height:18px;
      margin-top:.12rem;
      flex-shrink:0;
    }

    .invest-list{
      display:flex;
      flex-direction:column;
      gap:.82rem;
    }

    .invest-list-item{
      display:flex;
      align-items:center;
      gap:.7rem;
      color:var(--text-soft);
      font-weight:700;
      font-size:.88rem;
      padding:.82rem .9rem;
      background:#F5F7FA;
      border:1px solid var(--border);
      border-radius:15px;
      box-shadow:inset 0 1px 0 rgba(255,255,255,.95);
    }

    .invest-check{
      width:22px;
      height:22px;
      border-radius:999px;
      background:var(--mint-soft);
      color:var(--mint);
      display:flex;
      align-items:center;
      justify-content:center;
      flex-shrink:0;
      box-shadow:0 6px 12px rgba(14,159,140,.10), inset 0 1px 0 rgba(255,255,255,.85);
    }

    .invest-check svg{
      width:13px;
      height:13px;
    }

    /* SECTIONS */
    .section{
      padding:5.8rem 0;
      position:relative;
      z-index:1;
    }

    .section-white,
    #ingresos,
    #documentos{
      background:#F8FAFC;
    }

    .why,
    .proceso{
      background:#EAEFF4;
      border-top:1px solid rgba(5,17,35,.05);
      border-bottom:1px solid rgba(5,17,35,.05);
    }

    .com-section{
      background:var(--bg-dark);
      color:#fff;
    }

    .sec-header{
      text-align:center;
      margin-bottom:4rem;
    }

    .sec-label{
      display:inline-block;
      font-size:.72rem;
      font-weight:900;
      letter-spacing:.17em;
      text-transform:uppercase;
      color:var(--blue);
      margin-bottom:1rem;
    }

    .com-section .sec-label{
      color:#98A8FF;
    }

    .sec-title{
      font-family:var(--ff-display);
      color:var(--ink);
      font-size:clamp(2rem,3.8vw,3.15rem);
      line-height:1.09;
      font-weight:800;
      letter-spacing:-.065em;
      margin-bottom:1.15rem;
    }

    .com-section .sec-title{
      color:#fff;
    }

    .sec-lead{
      color:var(--text-soft);
      font-size:1rem;
      line-height:1.85;
      max-width:620px;
    }

    .com-section .sec-lead{
      color:rgba(255,255,255,.70);
    }

    .sec-header .sec-lead{
      margin:0 auto;
    }

    /* WHY */
    .why-grid{
      display:grid;
      gap:1rem;
    }

    .why-card{
      background:#fff;
      border:1px solid var(--border);
      border-radius:24px;
      padding:2.15rem 1.75rem 1.9rem;
      box-shadow:var(--shadow-3d-sm), var(--bevel-light);
      transition:transform .26s ease, box-shadow .26s ease, border-color .26s ease;
      transform-style:preserve-3d;
      position:relative;
      overflow:visible;
    }

    .why-card::before{
      content:"";
      position:absolute;
      top:0;
      left:0;
      right:0;
      height:10px;
      border-top-left-radius:24px;
      border-top-right-radius:24px;
      box-shadow:inset 0 1px 0 rgba(255,255,255,.24);
    }


    .why-card::after{
      content:"";
      position:absolute;
      left:16px;
      right:16px;
      bottom:-12px;
      height:18px;
      background:rgba(5,17,35,.08);
      filter:blur(10px);
      border-radius:999px;
      z-index:-1;
      pointer-events:none;
    }

    .why-card:hover{
      transform:translateY(-8px) rotateX(2deg) rotateY(-1deg);
      box-shadow:var(--shadow-3d-md), var(--bevel-light);
      border-color:var(--border-strong);
    }

    .wc-icon{
      width:56px;
      height:56px;
      border-radius:18px;
      display:flex;
      align-items:center;
      justify-content:center;
      margin-bottom:1.2rem;
      border:1px solid;
      box-shadow:0 12px 24px rgba(5,17,35,.10), 0 3px 0 rgba(5,17,35,.06), inset 0 1px 0 rgba(255,255,255,.82);
    }

    .why-card:nth-child(1) .wc-icon{
      background:var(--blue-soft);
      border-color:#C6D5FF;
      color:var(--blue);
    }

    .why-card:nth-child(2) .wc-icon{
      background:#1D2A40;
      border-color:#31435E;
      color:#6BD3DF;
      box-shadow:0 14px 24px rgba(5,17,35,.18), 0 3px 0 #24344F, inset 0 1px 0 rgba(255,255,255,.08);
    }

    .why-card:nth-child(3) .wc-icon{
      background:var(--indigo-soft);
      border-color:#CDCCFF;
      color:var(--indigo);
    }

    .why-card:nth-child(4) .wc-icon{
      background:var(--teal-soft);
      border-color:#BFDDE2;
      color:var(--teal);
    }

    .why-card:nth-child(5) .wc-icon{
      background:var(--slate-soft);
      border-color:#CDD7E3;
      color:var(--slate);
    }

    .why-card:nth-child(6) .wc-icon{
      background:var(--mint-soft);
      border-color:#C7EBE5;
      color:var(--mint);
    }

    .wc-icon svg{
      width:24px;
      height:24px;
    }

    .why-card h3{
      font-family:var(--ff-display);
      color:var(--ink);
      font-size:1.08rem;
      font-weight:800;
      letter-spacing:-.04em;
      margin-bottom:.55rem;
    }

    .why-card p{
      color:var(--text-soft);
      font-size:.9rem;
      line-height:1.78;
    }

    .why-card.featured-blue{
      background:var(--blue);
      border-color:var(--blue-2);
      box-shadow:var(--shadow-blue), inset 0 1px 0 rgba(255,255,255,.10);
    }

    .why-card.featured-blue h3,
    .why-card.featured-blue p{
      color:#fff;
    }

    .why-card.featured-blue p{
      color:rgba(255,255,255,.82);
    }

    .why-card.featured-blue .wc-icon{
      background:rgba(255,255,255,.12);
      border-color:rgba(255,255,255,.18);
      color:#fff;
      box-shadow:0 14px 28px rgba(0,0,0,.12), 0 3px 0 rgba(255,255,255,.10), inset 0 1px 0 rgba(255,255,255,.18);
    }

    .why-card.featured-dark{
      background:var(--bg-dark);
      border-color:var(--bg-dark);
      box-shadow:var(--shadow-dark), inset 0 1px 0 rgba(255,255,255,.05);
    }

    .why-card.featured-dark h3{ color:#fff; }
    .why-card.featured-dark p{ color:rgba(255,255,255,.74); }

    /* COMISIONES */
    .com-grid{
      display:grid;
      gap:3rem;
      align-items:center;
    }

    .com-recover{
      display:flex;
      gap:1.1rem;
      align-items:center;
      background:#a48cee;
      border:1px solid var(--border);
      border-radius:24px;
      padding:1.75rem 1.6rem 1.55rem;
      margin-top:1.75rem;
      color:var(--text);
      box-shadow:var(--shadow-3d-lg), var(--bevel-light);
      transform:rotateX(1deg);
      position:relative;
      overflow:visible;
      transition:transform .26s ease;
    }

    .com-recover:hover{
      transform:translateY(-6px) rotateX(1deg);
    }

    .recover-num{
      font-family:var(--ff-display);
      font-size:3rem;
      color:var(--mint);
      font-weight:800;
      letter-spacing:-.07em;
      line-height:1;
      flex-shrink:0;
    }

    .recover-text{
      color:var(--text-soft);
      line-height:1.65;
      font-size:.9rem;
      text-align:left;
    }

    .recover-text strong{
      display:block;
      color:var(--ink);
      font-size:.96rem;
      margin-bottom:.18rem;
    }

    .com-highlight{
      background:#d1ffc2;
      border:1px solid var(--border);
      border-radius:30px;
      padding:2.8rem 2.6rem 2.6rem;
      text-align:center;
      box-shadow:var(--shadow-3d-lg), var(--bevel-light);
      transform:rotateY(-4deg);
      position:relative;
      overflow:visible;
      transition:transform .26s ease;
    }

    .com-highlight:hover{
      transform:translateY(-8px) rotateY(-2deg);
    }

    .com-highlight::after{
      content:"";
      position:absolute;
      left:24px;
      right:24px;
      bottom:-16px;
      height:24px;
      background:rgba(5,17,35,.14);
      filter:blur(14px);
      border-radius:999px;
      z-index:-1;
    }

    .com-label{
      font-size:.72rem;
      color:var(--text-faint);
      font-weight:900;
      letter-spacing:.12em;
      text-transform:uppercase;
      margin-bottom:.65rem;
    }

    .com-amount{
      font-family:var(--ff-display);
      font-size:4.4rem;
      color:var(--blue);
      line-height:1;
      font-weight:800;
      letter-spacing:-.08em;
      margin-bottom:.5rem;
    }

    .com-note{
      color:var(--text-soft);
      font-size:.9rem;
      line-height:1.7;
      margin-top:.75rem;
    }

    /* SIMULADOR */
    .sim-card{
      background:#fff;
      border:1px solid #B7C5D8;
      border-radius:28px;
      padding:2rem;
      margin-bottom:3rem;
      box-shadow:var(--shadow-3d-lg), var(--bevel-light);
      position:relative;
      overflow:visible;
      transform:rotateX(1deg);
    }

    .sim-top{
      display:flex;
      justify-content:space-between;
      align-items:baseline;
      margin-bottom:.85rem;
      gap:1rem;
    }

    .sim-label{
      font-size:.72rem;
      font-weight:900;
      letter-spacing:.12em;
      text-transform:uppercase;
      color:var(--text-faint);
    }

    .sim-value{
      font-family:var(--ff-display);
      font-size:2rem;
      font-weight:800;
      color:var(--blue);
      letter-spacing:-.06em;
    }

    .range-wrap{
      margin-bottom:2rem;
    }

    input[type="range"]{
      width:100%;
      accent-color:var(--blue);
      cursor:pointer;
    }

    .range-scale{
      display:flex;
      justify-content:space-between;
      font-size:.72rem;
      color:var(--text-faint);
      margin-top:.45rem;
    }

    .sim-results{
      display:grid;
      grid-template-columns:repeat(auto-fit,minmax(150px,1fr));
      gap:1rem;
      margin-bottom:1.75rem;
    }

    .sim-result{
      background:#F4F7FA;
      border:1px solid var(--border);
      border-radius:18px;
      padding:1.2rem 1.25rem;
      box-shadow:0 10px 20px rgba(5,17,35,.06), 0 3px 0 #D9E1EB, inset 0 1px 0 rgba(255,255,255,.95);
      position:relative;
      overflow:hidden;
    }

    .sim-result::before{
      content:"";
      position:absolute;
      top:0;
      left:0;
      right:0;
      height:8px;
    }

    .sim-result-label{
      font-size:.68rem;
      font-weight:900;
      letter-spacing:.1em;
      text-transform:uppercase;
      color:var(--text-faint);
      margin-bottom:.45rem;
    }

    .sim-result-value{
      font-family:var(--ff-display);
      font-size:1.35rem;
      font-weight:800;
      color:var(--ink);
      letter-spacing:-.055em;
    }

    .sim-result.accent .sim-result-value{ color:var(--blue); }
    .sim-result.success .sim-result-value{ color:var(--teal); }

    .bar-row{
      display:flex;
      justify-content:space-between;
      font-size:.74rem;
      color:var(--text-soft);
      margin-bottom:.55rem;
      gap:1rem;
    }

    .bar-track{
      height:11px;
      background:#DDE4ED;
      border-radius:999px;
      overflow:hidden;
      box-shadow:inset 0 2px 4px rgba(5,17,35,.08);
    }

    .bar-fill{
      height:100%;
      border-radius:999px;
      background:var(--blue);
      box-shadow:0 8px 18px rgba(22,93,255,.18);
      transition:width .35s cubic-bezier(.4,0,.2,1);
    }

    .recovery-msg{
      margin-top:1rem;
      font-size:.82rem;
      color:var(--text-soft);
      line-height:1.65;
    }

    /* TABLE */
    .ingresos-table{
      background:#fff;
      border:1px solid #B7C5D8;
      border-radius:28px;
      overflow:hidden;
      box-shadow:var(--shadow-3d-lg), var(--bevel-light);
      position:relative;
      transform:rotateX(1deg);
    }

    .ingresos-table::after{
      content:"";
      position:absolute;
      left:22px;
      right:22px;
      bottom:-14px;
      height:22px;
      background:rgba(5,17,35,.10);
      filter:blur(12px);
      border-radius:999px;
      z-index:-1;
    }

    .ing-head{
      display:grid;
      grid-template-columns:1fr 1fr 1fr;
      gap:1rem;
      background:var(--blue);
      color:#fff;
      padding:1.05rem 1.5rem;
    }

    .ing-head span{
      font-size:.68rem;
      font-weight:900;
      letter-spacing:.11em;
      text-transform:uppercase;
      color:rgba(255,255,255,.88);
    }

    .ing-row{
      display:grid;
      grid-template-columns:1fr 1fr 1fr;
      gap:1rem;
      align-items:center;
      padding:1.35rem 1.5rem;
      border-top:1px solid var(--border);
      background:#fff;
      transition:background .2s ease;
    }

    .ing-row:hover{
      background:#F8FAFD;
    }

    .ing-row .num{
      font-family:var(--ff-display);
      color:var(--ink);
      font-weight:800;
      font-size:1.16rem;
      letter-spacing:-.035em;
    }

    .ing-row .com{
      color:var(--teal);
      font-weight:900;
      font-size:.94rem;
    }

    .ing-row .gan{
      font-family:var(--ff-display);
      background:#E5ECFF;
      color:var(--blue);
      display:inline-flex;
      align-items:center;
      width:max-content;
      padding:.4rem .78rem;
      border-radius:999px;
      border:1px solid #C7D5FF;
      font-weight:800;
      font-size:1.05rem;
      letter-spacing:-.035em;
      box-shadow:0 8px 16px rgba(22,93,255,.08), 0 2px 0 #C9D6F7, inset 0 1px 0 rgba(255,255,255,.84);
    }

    .table-note{
      margin-top:1rem;
      color:var(--text-faint);
      text-align:center;
      font-size:.78rem;
      line-height:1.65;
    }

    /* VIG CARDS */
    .vig-cards{
      display:grid;
      gap:1rem;
      margin-top:3rem;
    }

    .vig-card{
      background:#fff;
      border:1px solid var(--border);
      border-radius:24px;
      box-shadow:var(--shadow-3d-sm), var(--bevel-light);
      padding:2rem 1.8rem 1.75rem;
      transition:transform .26s ease, box-shadow .26s ease, border-color .26s ease;
      position:relative;
      overflow:visible;
      transform-style:preserve-3d;
    }

    .vig-card:hover{
      border-color:var(--border-strong);
      box-shadow:var(--shadow-3d-md), var(--bevel-light);
      transform:translateY(-8px) rotateX(2deg);
    }

    .vig-value{
      font-family:var(--ff-display);
      font-size:1.65rem;
      font-weight:800;
      letter-spacing:-.055em;
      line-height:1;
      margin-bottom:.55rem;
    }

    .vig-card:nth-child(1) .vig-value{ color:var(--blue); }
    .vig-card:nth-child(2) .vig-value{ color:var(--teal); }
    .vig-card:nth-child(3) .vig-value{ color:var(--indigo); }
    .vig-card:nth-child(4) .vig-value{ color:var(--ink); }

    .vig-card h3{
      font-family:var(--ff-display);
      color:var(--ink);
      font-size:1.03rem;
      font-weight:800;
      letter-spacing:-.04em;
      margin-bottom:.45rem;
    }

    .vig-card p{
      color:var(--text-soft);
      font-size:.9rem;
      line-height:1.75;
    }

    /* PROCESO */
    .proc-steps{
      display:grid;
      gap:1rem;
    }

    .proc-step{
      background:#fff;
      border:1px solid var(--border);
      border-radius:24px;
      padding:1.95rem 1.5rem 1.55rem;
      box-shadow:var(--shadow-3d-sm), var(--bevel-soft);
      transform-style:preserve-3d;
      transition:transform .26s ease, box-shadow .26s ease, border-color .26s ease;
      position:relative;
      overflow:visible;
    }

    .proc-step::before{
      content:"";
      position:absolute;
      top:0;
      left:0;
      right:0;
      height:10px;
      border-top-left-radius:24px;
      border-top-right-radius:24px;
    }

    .proc-step:nth-child(1)::before{ background:var(--blue); }
    .proc-step:nth-child(2)::before{ background:var(--teal); }
    .proc-step:nth-child(3)::before{ background:var(--indigo); }
    .proc-step.final::before{ background:var(--bg-dark); }

    .proc-step::after{
      content:"";
      position:absolute;
      left:16px;
      right:16px;
      bottom:-12px;
      height:18px;
      background:rgba(5,17,35,.08);
      filter:blur(10px);
      border-radius:999px;
      z-index:-1;
    }

    .proc-step:hover{
      transform:translateY(-8px) rotateX(2deg);
      box-shadow:var(--shadow-3d-md), var(--bevel-soft);
      border-color:var(--border-strong);
    }

    .proc-num{
      width:60px;
      height:60px;
      border-radius:20px;
      display:flex;
      align-items:center;
      justify-content:center;
      font-family:var(--ff-display);
      font-size:1.25rem;
      font-weight:800;
      letter-spacing:-.04em;
      line-height:1;
      margin-bottom:1.1rem;
      color:#fff;
    }

    .proc-step:nth-child(1) .proc-num{
      background:var(--blue);
      box-shadow:var(--shadow-blue), inset 0 1px 0 rgba(255,255,255,.22);
    }

    .proc-step:nth-child(2) .proc-num{
      background:var(--teal);
      box-shadow:var(--shadow-teal), inset 0 1px 0 rgba(255,255,255,.22);
    }

    .proc-step:nth-child(3) .proc-num{
      background:var(--indigo);
      box-shadow:var(--shadow-indigo), inset 0 1px 0 rgba(255,255,255,.22);
    }

    .proc-step.final .proc-num{
      background:var(--bg-dark);
      box-shadow:var(--shadow-dark), inset 0 1px 0 rgba(255,255,255,.14);
    }

    .proc-step h3{
      font-family:var(--ff-display);
      color:var(--ink);
      font-size:1.05rem;
      font-weight:800;
      letter-spacing:-.04em;
      margin-bottom:.4rem;
    }

    .proc-step p{
      color:var(--text-soft);
      font-size:.88rem;
      line-height:1.72;
    }

    /* DOCUMENTOS */
    .docs-grid{
      display:grid;
      gap:1rem;
    }

    .doc-item{
      display:flex;
      align-items:center;
      gap:1rem;
      background:#fff;
      border:1px solid var(--border);
      border-radius:18px;
      padding:1.35rem 1.35rem 1.15rem;
      box-shadow:var(--shadow-3d-sm), var(--bevel-light);
      transition:transform .26s ease, box-shadow .26s ease, border-color .26s ease;
      position:relative;
      overflow:visible;
      transform-style:preserve-3d;
    }

    .doc-item::before{
      content:"";
      position:absolute;
      top:0;
      left:0;
      right:0;
      height:8px;
      border-top-left-radius:18px;
      border-top-right-radius:18px;
      background:var(--blue);
    }

    .doc-item:nth-child(2)::before,
    .doc-item:nth-child(5)::before{ background:var(--teal); }

    .doc-item:nth-child(3)::before,
    .doc-item:nth-child(6)::before{ background:var(--indigo); }

    .doc-item:nth-child(4)::before,
    .doc-item:nth-child(7)::before{ background:var(--slate); }

    .doc-item:nth-child(8)::before{ background:var(--mint); }

    .doc-item::after{
      content:"";
      position:absolute;
      left:16px;
      right:16px;
      bottom:-10px;
      height:16px;
      background:rgba(5,17,35,.08);
      filter:blur(10px);
      border-radius:999px;
      z-index:-1;
    }

    .doc-item:hover{
      border-color:var(--border-strong);
      box-shadow:var(--shadow-3d-md), var(--bevel-light);
      transform:translateY(-6px) rotateX(1.5deg);
    }

    .doc-icon{
      width:44px;
      height:44px;
      border-radius:15px;
      display:flex;
      align-items:center;
      justify-content:center;
      flex-shrink:0;
      border:1px solid;
      box-shadow:0 10px 20px rgba(5,17,35,.08), 0 3px 0 rgba(5,17,35,.05), inset 0 1px 0 rgba(255,255,255,.88);
    }

    .doc-item:nth-child(1) .doc-icon,
    .doc-item:nth-child(4) .doc-icon{
      background:var(--blue-soft);
      border-color:#C6D5FF;
      color:var(--blue);
    }

    .doc-item:nth-child(2) .doc-icon,
    .doc-item:nth-child(5) .doc-icon{
      background:var(--teal-soft);
      border-color:#C2DDE2;
      color:var(--teal);
    }

    .doc-item:nth-child(3) .doc-icon,
    .doc-item:nth-child(6) .doc-icon{
      background:var(--indigo-soft);
      border-color:#D2D2FF;
      color:var(--indigo);
    }

    .doc-item:nth-child(7) .doc-icon{
      background:var(--slate-soft);
      border-color:#D0D9E5;
      color:var(--slate);
    }

    .doc-item:nth-child(8) .doc-icon{
      background:var(--mint-soft);
      border-color:#C9EBE6;
      color:var(--mint);
    }

    .doc-icon svg{
      width:20px;
      height:20px;
    }

    .doc-name{
      color:var(--ink);
      font-weight:900;
      font-size:.9rem;
      line-height:1.25;
    }

    .doc-sub{
      color:var(--text-soft);
      font-size:.76rem;
      line-height:1.45;
      margin-top:.12rem;
    }

    .doc-req{
      margin-left:auto;
      flex-shrink:0;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-height:28px;
      padding:.32rem .74rem;
      border-radius:999px;
      background:#E5ECFF;
      color:var(--blue);
      border:1px solid #C7D5FF;
      font-size:.63rem;
      font-weight:900;
      letter-spacing:.08em;
      text-transform:uppercase;
      box-shadow:0 8px 16px rgba(22,93,255,.08), 0 2px 0 #CBD6EA, inset 0 1px 0 rgba(255,255,255,.85);
    }

    .doc-item:nth-child(2) .doc-req,
    .doc-item:nth-child(5) .doc-req{
      background:var(--teal-soft);
      color:var(--teal);
      border-color:#C2DDE2;
    }

    .doc-item:nth-child(3) .doc-req,
    .doc-item:nth-child(6) .doc-req{
      background:var(--indigo-soft);
      color:var(--indigo);
      border-color:#D2D2FF;
    }

    .doc-item:nth-child(7) .doc-req{
      background:var(--slate-soft);
      color:var(--slate);
      border-color:#D0D9E5;
    }

    .doc-item:nth-child(8) .doc-req{
      background:var(--mint-soft);
      color:var(--mint);
      border-color:#C9EBE6;
    }

    .docs-note{
      margin-top:1.35rem;
      background:var(--bg-dark);
      border:1px solid #13233C;
      border-radius:22px;
      padding:1.25rem 1.35rem;
      color:rgba(255,255,255,.74);
      font-size:.84rem;
      line-height:1.7;
      box-shadow:var(--shadow-dark), inset 0 1px 0 rgba(255,255,255,.05);
      position:relative;
      overflow:hidden;
    }

    .docs-note::before{
      content:"";
      position:absolute;
      top:0;
      left:0;
      right:0;
      height:8px;
      background:var(--indigo);
    }

    /* CTA */
    .cta-section{
      background:var(--bg-dark);
      color:#fff;
      text-align:center;
      padding:6.4rem 0;
      position:relative;
      overflow:hidden;
    }

    .cta-section::before{
      content:"";
      position:absolute;
      inset:0;
      background:
        linear-gradient(90deg, rgba(255,255,255,.05) 1px, transparent 1px),
        linear-gradient(rgba(255,255,255,.05) 1px, transparent 1px);
      background-size:72px 72px;
      opacity:.16;
      pointer-events:none;
    }

    .cta-section::after{
      content:"";
      position:absolute;
      width:280px;
      height:280px;
      border-radius:42px;
      background:rgba(99,102,241,.16);
      right:-110px;
      top:80px;
      transform:rotate(18deg);
      box-shadow:0 30px 60px rgba(0,0,0,.16);
    }

    .cta-inner{
      position:relative;
      z-index:1;
    }

    .cta-pill{
      display:inline-flex;
      align-items:center;
      border:1px solid rgba(99,102,241,.28);
      background:rgba(99,102,241,.12);
      color:#AEB0FF;
      font-size:.72rem;
      font-weight:900;
      letter-spacing:.14em;
      text-transform:uppercase;
      padding:.55rem 1.1rem;
      border-radius:999px;
      margin-bottom:1.7rem;
      box-shadow:0 12px 26px rgba(0,0,0,.16), inset 0 1px 0 rgba(255,255,255,.08);
    }

    .cta-title{
      font-family:var(--ff-display);
      font-size:clamp(2.1rem,5vw,4.1rem);
      font-weight:800;
      line-height:1.05;
      color:#fff;
      letter-spacing:-.075em;
      margin-bottom:1.2rem;
    }

    .cta-lead{
      color:rgba(255,255,255,.68);
      max-width:560px;
      margin:0 auto 2.4rem;
      line-height:1.85;
      font-size:1rem;
    }

    .cta-actions{
      display:flex;
      justify-content:center;
      flex-wrap:wrap;
      gap:1rem;
      margin-bottom:1.25rem;
    }

    .cta-section .btn-primary{
      background:var(--indigo);
      border-color:var(--indigo-2);
      color:#fff;
      box-shadow:var(--shadow-indigo), inset 0 1px 0 rgba(255,255,255,.20);
    }

    .cta-section .btn-primary:hover{
      background:var(--indigo-2);
    }

    .cta-section .btn-secondary{
      background:rgba(255,255,255,.05);
      color:#fff;
      border-color:rgba(255,255,255,.18);
      box-shadow:0 14px 28px rgba(0,0,0,.12), 0 4px 0 rgba(255,255,255,.08), inset 0 1px 0 rgba(255,255,255,.10);
    }

    .cta-section .btn-secondary:hover{
      background:rgba(255,255,255,.09);
      border-color:rgba(255,255,255,.26);
      color:#fff;
    }

    .cta-note{
      color:rgba(255,255,255,.56);
      font-size:.76rem;
      letter-spacing:.03em;
    }

    /* FOOTER */
    .footer{
      background:#06101F;
      border-top:6px solid var(--blue);
      padding:3.8rem 0 1.5rem;
      position:relative;
      z-index:1;
    }

    .footer-grid{
      display:grid;
      gap:2.5rem;
      padding-bottom:2.5rem;
      border-bottom:1px solid rgba(255,255,255,.10);
    }

    .footer-brand .logo{
      font-family:var(--ff-display);
      font-size:1.28rem;
      font-weight:800;
      color:#fff;
      margin-bottom:.8rem;
      letter-spacing:-.055em;
    }

    .footer-brand p{
      color:rgba(255,255,255,.58);
      font-size:.84rem;
      line-height:1.75;
      max-width:300px;
    }

    .footer-nav h4{
      color:#AEB0FF;
      font-size:.7rem;
      font-weight:900;
      letter-spacing:.12em;
      text-transform:uppercase;
      margin-bottom:1rem;
    }

    .footer-nav ul{
      display:flex;
      flex-direction:column;
      gap:.65rem;
    }

    .footer-nav a,
    .footer-nav p{
      color:rgba(255,255,255,.58);
      font-size:.84rem;
      line-height:1.65;
      transition:color .2s ease;
    }

    .footer-nav a:hover{
      color:#fff;
    }

    .footer-phone{
      color:#AEB0FF !important;
      font-size:1rem !important;
      font-weight:900 !important;
    }

    .footer-bottom{
      display:flex;
      flex-wrap:wrap;
      justify-content:space-between;
      gap:.6rem;
      padding-top:1.25rem;
    }

    .footer-bottom p{
      color:rgba(255,255,255,.48);
      font-size:.73rem;
    }

    /* REVEAL */
    .reveal{
      opacity:0;
      transform:translateY(22px);
      transition:opacity .65s ease, transform .65s ease;
    }

    .reveal.vis{
      opacity:1;
      transform:translateY(0);
    }

    .com-recover{
      background: transparent;
      border:none;
      box-shadow :none;
    }
      #ingresos{
         background: rgb(239, 239, 239);
    }

/* FAQ PREMIUM DARK */
.faq-section{
  background:
    radial-gradient(circle at 12% 8%, rgba(88,252,206,.16), transparent 34%),
    radial-gradient(circle at 86% 18%, rgba(99,102,241,.22), transparent 36%),
    linear-gradient(135deg,#031028 0%,#071438 46%,#17175A 100%);
  color:#fff;
  position:relative;
  overflow:hidden;
  border-top:1px solid rgba(255,255,255,.08);
  border-bottom:1px solid rgba(255,255,255,.08);
}

.faq-section::before{
  content:"";
  position:absolute;
  inset:0;
  background:
    linear-gradient(90deg, rgba(255,255,255,.045) 1px, transparent 1px),
    linear-gradient(rgba(255,255,255,.045) 1px, transparent 1px);
  background-size:64px 64px;
  opacity:.15;
  pointer-events:none;
}

.faq-section::after{
  content:"";
  position:absolute;
  width:340px;
  height:340px;
  right:-140px;
  bottom:-120px;
  border-radius:42px;
  background:rgba(88,252,206,.10);
  transform:rotate(18deg);
  filter:blur(.2px);
  box-shadow:0 38px 90px rgba(0,0,0,.24);
  pointer-events:none;
}

.faq-shell{
  position:relative;
  z-index:1;
  display:grid;
  gap:2.4rem;
}

.faq-header{
  max-width:760px;
  margin:0 auto;
  text-align:center;
}

.faq-kicker{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:.5rem;
  padding:.55rem 1.1rem;
  border-radius:999px;
  background:rgba(255,255,255,.07);
  border:1px solid rgba(255,255,255,.14);
  color:#AEB0FF;
  font-size:.72rem;
  font-weight:900;
  letter-spacing:.15em;
  text-transform:uppercase;
  box-shadow:0 18px 34px rgba(0,0,0,.16), inset 0 1px 0 rgba(255,255,255,.08);
  margin-bottom:1.25rem;
}

.faq-header .sec-title{
  color:#fff;
}

.faq-header .sec-lead{
  color:rgba(255,255,255,.70);
  margin:0 auto;
}

.faq-layout{
  display:grid;
  gap:1.2rem;
  align-items:start;
}

.faq-panel{
  background:rgba(255,255,255,.06);
  border:1px solid rgba(255,255,255,.12);
  border-radius:30px;
  padding:1rem;
  box-shadow:
    0 34px 80px rgba(0,0,0,.28),
    inset 0 1px 0 rgba(255,255,255,.08);
  backdrop-filter:blur(14px);
}

.faq-list{
  display:grid;
  gap:.78rem;
}

.faq-item{
  border:1px solid rgba(255,255,255,.12);
  border-radius:22px;
  background:
    linear-gradient(145deg,rgba(255,255,255,.09),rgba(255,255,255,.045));
  box-shadow:0 16px 34px rgba(0,0,0,.18), inset 0 1px 0 rgba(255,255,255,.08);
  overflow:hidden;
  transition:border-color .22s ease, background .22s ease, transform .22s ease;
}

.faq-item:hover{
  transform:translateY(-2px);
  border-color:rgba(88,252,206,.34);
  background:
    linear-gradient(145deg,rgba(255,255,255,.11),rgba(255,255,255,.055));
}

.faq-item[hidden]{
  display:none;
}

.faq-question{
  width:100%;
  appearance:none;
  border:0;
  background:transparent;
  color:#fff;
  cursor:pointer;
  display:grid;
  grid-template-columns:auto 1fr auto;
  align-items:center;
  gap:1rem;
  padding:1.15rem 1.15rem;
  text-align:left;
  font-family:var(--ff-body);
}

.faq-num{
  width:34px;
  height:34px;
  border-radius:13px;
  display:flex;
  align-items:center;
  justify-content:center;
  flex-shrink:0;
  color:#58FCCE;
  background:rgba(88,252,206,.10);
  border:1px solid rgba(88,252,206,.22);
  font-size:.72rem;
  font-weight:900;
  box-shadow:inset 0 1px 0 rgba(255,255,255,.08);
}

.faq-q-text{
  font-size:.94rem;
  font-weight:900;
  line-height:1.35;
  letter-spacing:-.025em;
}

.faq-chevron{
  width:38px;
  height:38px;
  border-radius:14px;
  display:flex;
  align-items:center;
  justify-content:center;
  color:#AEB0FF;
  background:rgba(255,255,255,.07);
  border:1px solid rgba(255,255,255,.12);
  transition:transform .22s ease, background .22s ease, color .22s ease;
}

.faq-chevron svg{
  width:18px;
  height:18px;
}

.faq-item.open .faq-chevron{
  transform:rotate(180deg);
  color:#58FCCE;
  background:rgba(88,252,206,.10);
  border-color:rgba(88,252,206,.26);
}

.faq-answer{
  max-height:0;
  overflow:hidden;
  transition:max-height .32s cubic-bezier(.4,0,.2,1);
}

.faq-answer-inner{
  padding:0 1.15rem 1.2rem 4.95rem;
  color:rgba(255,255,255,.72);
  font-size:.9rem;
  line-height:1.78;
}

.faq-answer-inner ul,
.faq-answer-inner ol{
  margin:.55rem 0 0 1rem;
  padding-left:.85rem;
}

.faq-answer-inner li{
  margin:.28rem 0;
}

.faq-actions{
  display:flex;
  justify-content:center;
  margin-top:1rem;
}

.faq-more-btn{
  appearance:none;
  border:1px solid rgba(88,252,206,.30);
  background:
    linear-gradient(135deg,rgba(88,252,206,.16),rgba(99,102,241,.16));
  color:#fff;
  border-radius:16px;
  padding:1rem 1.45rem;
  min-width:230px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:.65rem;
  font-family:var(--ff-body);
  font-size:.88rem;
  font-weight:900;
  cursor:pointer;
  box-shadow:
    0 18px 38px rgba(0,0,0,.22),
    0 4px 0 rgba(88,252,206,.16),
    inset 0 1px 0 rgba(255,255,255,.12);
  transition:transform .2s ease, border-color .2s ease, background .2s ease;
}

.faq-more-btn:hover{
  transform:translateY(-2px);
  border-color:rgba(88,252,206,.48);
  background:
    linear-gradient(135deg,rgba(88,252,206,.22),rgba(99,102,241,.20));
}

.faq-more-btn:active{
  transform:translateY(2px);
}

.faq-more-btn svg{
  width:17px;
  height:17px;
  transition:transform .22s ease;
}

.faq-more-btn.expanded svg{
  transform:rotate(180deg);
}

.faq-side{
  background:
    linear-gradient(145deg,rgba(255,255,255,.10),rgba(255,255,255,.05));
  border:1px solid rgba(255,255,255,.13);
  border-radius:30px;
  padding:1.65rem;
  box-shadow:0 28px 64px rgba(0,0,0,.24), inset 0 1px 0 rgba(255,255,255,.08);
  position:relative;
  overflow:hidden;
}

.faq-side::before{
  content:"";
  position:absolute;
  top:0;
  left:0;
  right:0;
  height:9px;
  background:linear-gradient(90deg,#58FCCE,#165DFF,#6366F1);
}

.faq-side h3{
  color:#fff;
  font-family:var(--ff-display);
  font-size:1.22rem;
  font-weight:800;
  letter-spacing:-.045em;
  line-height:1.2;
  margin-bottom:.75rem;
}

.faq-side p{
  color:rgba(255,255,255,.68);
  font-size:.88rem;
  line-height:1.72;
  margin-bottom:1.15rem;
}

.faq-mini-list{
  display:grid;
  gap:.7rem;
  margin-bottom:1.25rem;
}

.faq-mini-item{
  display:flex;
  gap:.65rem;
  align-items:flex-start;
  color:rgba(255,255,255,.76);
  font-size:.82rem;
  line-height:1.55;
}

.faq-mini-dot{
  width:9px;
  height:9px;
  border-radius:999px;
  margin-top:.42rem;
  background:#58FCCE;
  box-shadow:0 0 0 6px rgba(88,252,206,.10);
  flex-shrink:0;
}

.faq-side .btn-primary{
  width:100%;
  background:#6366F1;
  border-color:#5458D9;
  box-shadow:var(--shadow-indigo), inset 0 1px 0 rgba(255,255,255,.20);
}

@media(min-width:960px){
  .faq-layout{
    grid-template-columns:minmax(0,1fr) 330px;
  }
}

@media(max-width:760px){
  .faq-question{
    grid-template-columns:auto 1fr;
  }

  .faq-chevron{
    grid-column:1 / -1;
    width:100%;
    height:34px;
  }

  .faq-answer-inner{
    padding:0 1.05rem 1.15rem 1.05rem;
  }

  .faq-side{
    padding:1.35rem;
  }
}
/* FAQ CENTER FIX */
.faq-section{
  padding:6.6rem 0;
}

.faq-shell{
  max-width:1180px;
  margin:0 auto;
}

.faq-layout{
  display:block;
  width:100%;
}

.faq-panel{
  width:min(100%, 1040px);
  margin:0 auto;
  padding:1.25rem;
  border-radius:32px;
}

.faq-list{
  width:100%;
}

.faq-item{
  width:100%;
}

.faq-question{
  grid-template-columns:44px minmax(0,1fr) 46px;
  padding:1.25rem 1.35rem;
}

.faq-answer-inner{
  padding:0 5.2rem 1.35rem 5.15rem;
  max-width:920px;
}

.faq-actions{
  margin-top:1.35rem;
}

@media(min-width:1200px){
  .faq-panel{
    width:1080px;
  }
}

@media(max-width:760px){
  .faq-section{
    padding:5rem 0;
  }

  .faq-panel{
    width:100%;
    padding:.85rem;
    border-radius:26px;
  }

  .faq-question{
    grid-template-columns:38px minmax(0,1fr) 40px;
    gap:.75rem;
    padding:1rem;
  }

  .faq-answer-inner{
    padding:0 1rem 1.15rem 1rem;
    max-width:none;
  }
}

/* =========================================
   INGRESOS DARK PREMIUM
========================================= */
#ingresos{
  position:relative;
  overflow:hidden;
  background:
    radial-gradient(circle at 12% 16%, rgba(88,252,206,.10), transparent 28%),
    radial-gradient(circle at 85% 10%, rgba(99,102,241,.16), transparent 34%),
    linear-gradient(135deg,#031028 0%, #071438 42%, #17175A 100%);
  color:#fff;
}

#ingresos::before{
  content:"";
  position:absolute;
  inset:0;
  background:
    linear-gradient(90deg, rgba(255,255,255,.04) 1px, transparent 1px),
    linear-gradient(rgba(255,255,255,.04) 1px, transparent 1px);
  background-size:64px 64px;
  opacity:.12;
  pointer-events:none;
}

#ingresos::after{
  content:"";
  position:absolute;
  width:340px;
  height:340px;
  right:-120px;
  bottom:-120px;
  border-radius:40px;
  background:rgba(88,252,206,.08);
  transform:rotate(16deg);
  box-shadow:0 30px 80px rgba(0,0,0,.24);
  pointer-events:none;
}

#ingresos .container{
  position:relative;
  z-index:1;
}

#ingresos .sec-kicker,
#ingresos .eyebrow{
  color:#7ea1ff !important;
}

/* Caja principal del simulador */
#ingresos .income-shell{
  width:min(100%, 1120px);
  margin:2.4rem auto 0;
  padding:2rem 2rem 1.7rem;
  border-radius:34px;
  position:relative;
  background:
    linear-gradient(180deg, rgba(12,20,54,.94) 0%, rgba(19,28,76,.96) 100%);
  border:1px solid rgba(255,255,255,.10);
  box-shadow:
    0 36px 90px rgba(0,0,0,.28),
    inset 0 1px 0 rgba(255,255,255,.08);
  backdrop-filter:blur(16px);
}

#ingresos .income-shell::after{
  content:"";
  position:absolute;
  inset:0;
  border-radius:34px;
  pointer-events:none;
  background:
    radial-gradient(circle at top left, rgba(255,255,255,.08), transparent 28%);
  opacity:.7;
}

#ingresos .income-shell label,
#ingresos .income-shell .label,
#ingresos .income-shell .mini-label,
#ingresos .income-shell .range-label,
#ingresos .income-shell .meta-label{
  color:#93A4C7 !important;
  letter-spacing:.12em;
  text-transform:uppercase;
  font-weight:800;
}

#ingresos .income-shell .range-value,
#ingresos .income-shell .slider-value,
#ingresos .income-shell .main-value{
  color:#58FCCE !important;
  font-weight:900;
  text-shadow:0 0 24px rgba(88,252,206,.16);
}

#ingresos .income-shell .range-scale,
#ingresos .income-shell .range-marks,
#ingresos .income-shell .ticks,
#ingresos .income-shell small{
  color:rgba(255,255,255,.48);
}

#ingresos input[type="range"]{
  width:100%;
  appearance:none;
  background:transparent;
}

#ingresos input[type="range"]::-webkit-slider-runnable-track{
  height:10px;
  border-radius:999px;
  background:
    linear-gradient(90deg, rgba(47,103,255,.75), rgba(99,102,241,.9), rgba(34,199,223,.85));
  box-shadow:
    inset 0 1px 2px rgba(255,255,255,.10),
    0 0 0 1px rgba(255,255,255,.06);
}

#ingresos input[type="range"]::-webkit-slider-thumb{
  -webkit-appearance:none;
  width:24px;
  height:24px;
  border-radius:50%;
  background:#fff;
  border:3px solid #2f67ff;
  margin-top:-7px;
  box-shadow:
    0 8px 18px rgba(0,0,0,.28),
    0 0 0 6px rgba(47,103,255,.18);
  cursor:pointer;
}

#ingresos input[type="range"]::-moz-range-track{
  height:10px;
  border-radius:999px;
  background:
    linear-gradient(90deg, rgba(47,103,255,.75), rgba(99,102,241,.9), rgba(34,199,223,.85));
}

#ingresos input[type="range"]::-moz-range-thumb{
  width:24px;
  height:24px;
  border-radius:50%;
  background:#fff;
  border:3px solid #2f67ff;
  box-shadow:
    0 8px 18px rgba(0,0,0,.28),
    0 0 0 6px rgba(47,103,255,.18);
  cursor:pointer;
}

#ingresos .income-grid{
  display:grid;
  grid-template-columns:repeat(4, minmax(0,1fr));
  gap:1rem;
  margin-top:1.5rem;
}

#ingresos .income-metric{
  position:relative;
  border-radius:22px;
  padding:1.1rem 1.15rem 1rem;
  background:
    linear-gradient(145deg, rgba(255,255,255,.08), rgba(255,255,255,.045));
  border:1px solid rgba(255,255,255,.10);
  box-shadow:
    0 16px 34px rgba(0,0,0,.16),
    inset 0 1px 0 rgba(255,255,255,.08);
  overflow:hidden;
}

#ingresos .income-metric::before{
  content:"";
  position:absolute;
  left:0;
  right:0;
  top:0;
  height:6px;
  background:linear-gradient(90deg,#2f67ff, #6366f1, #22c7df);
}

#ingresos .income-metric .kpi-label,
#ingresos .income-metric .metric-label,
#ingresos .income-metric .mini-title{
  color:#94A5C9 !important;
  font-size:.84rem;
  font-weight:800;
  letter-spacing:.09em;
  text-transform:uppercase;
  margin-bottom:.55rem;
}

#ingresos .income-metric .kpi-value,
#ingresos .income-metric .metric-value,
#ingresos .income-metric strong{
  font-size:1.16rem;
  font-weight:900;
  color:#fff !important;
  line-height:1.15;
}

#ingresos .income-metric.is-primary .kpi-value,
#ingresos .income-metric.is-primary .metric-value{
  color:#7EA1FF !important;
}

#ingresos .income-metric.is-accent .kpi-value,
#ingresos .income-metric.is-accent .metric-value{
  color:#58FCCE !important;
}

#ingresos .income-progress-block{
  margin-top:1.35rem;
  padding-top:1.2rem;
}

#ingresos .income-progress-block .progress-head,
#ingresos .income-progress-block .progress-meta,
#ingresos .income-progress-block .progress-label{
  color:rgba(255,255,255,.70) !important;
}

#ingresos .progress-track,
#ingresos .income-progress-track,
#ingresos .bar-track{
  width:100%;
  height:14px;
  border-radius:999px;
  background:rgba(255,255,255,.10);
  box-shadow:inset 0 2px 6px rgba(0,0,0,.24);
  overflow:hidden;
}

#ingresos .progress-fill,
#ingresos .income-progress-fill,
#ingresos .bar-fill{
  height:100%;
  border-radius:999px;
  background:linear-gradient(90deg,#2f67ff 0%, #6366f1 58%, #22c7df 100%);
  box-shadow:0 0 18px rgba(47,103,255,.28);
}

#ingresos .progress-percent,
#ingresos .income-progress-percent{
  color:#58FCCE !important;
  font-weight:800;
}

#ingresos .income-note,
#ingresos .sim-note,
#ingresos .summary-line,
#ingresos .income-shell p{
  color:rgba(255,255,255,.72);
}

@media(max-width:980px){
  #ingresos .income-grid{
    grid-template-columns:repeat(2, minmax(0,1fr));
  }

  #ingresos .income-shell{
    padding:1.6rem 1.2rem 1.35rem;
  }
}

@media(max-width:640px){
  #ingresos .income-grid{
    grid-template-columns:1fr;
  }

  #ingresos .income-shell{
    border-radius:26px;
  }

  #ingresos .income-shell::before{
    border-radius:26px 26px 0 0;
  }
}

    /* RESPONSIVE */
    @media(min-width:640px){
      .why-grid{grid-template-columns:repeat(2,1fr)}
      .docs-grid{grid-template-columns:repeat(2,1fr)}
      .vig-cards{grid-template-columns:repeat(2,1fr)}
      .proc-steps{grid-template-columns:repeat(2,1fr)}
      .footer-grid{grid-template-columns:repeat(2,1fr)}
    }

    @media(min-width:860px){
      .nav-links{display:flex}
    }

    @media(min-width:900px){
      .hero-inner{grid-template-columns:minmax(0,1fr) 390px}
      .why-grid{grid-template-columns:repeat(3,1fr)}
      .com-grid{grid-template-columns:1fr 1fr}
      .footer-grid{grid-template-columns:1.8fr 1fr 1fr 1.4fr}
    }

    @media(min-width:1100px){
      .proc-steps{grid-template-columns:repeat(4,1fr)}
    }

    @media(max-width:900px){
      .hero{
        background:#F2F4F8;
      }

      .hero::after{
        display:none;
      }

      .hero-invest,
      .com-highlight,
      .sim-card,
      .ingresos-table{
        transform:none;
      }

      .hero-invest:hover,
      .com-highlight:hover{
        transform:translateY(-6px);
      }
    }

    @media(max-width:760px){
      .nav-inner{height:68px}
      .hero{padding-top:68px}
      .hero-inner{padding-top:3.4rem}
      .hero-title{font-size:clamp(2.35rem,12vw,4rem)}

      .hero-actions,
      .cta-actions{
        flex-direction:column;
      }

      .btn-primary,
      .btn-secondary{
        width:100%;
      }

      .ing-head{ display:none; }

      .ing-row{
        grid-template-columns:1fr;
        gap:.35rem;
      }

      .doc-item{
        align-items:flex-start;
        flex-wrap:wrap;
      }

      .doc-item > div:nth-child(2){
        flex:1;
        min-width:180px;
      }

      .doc-req{
        margin-left:0;
      }

      .com-recover{
        align-items:flex-start;
      }

      .recover-num{
        font-size:2.5rem;
      }

      .sim-top{
        flex-direction:column;
        align-items:flex-start;
      }
    }

    :root{
      --bg-page:#EEF2F7;
      --bg-soft:#E8EEF6;
      --bg-panel:#F6F8FC;
      --bg-card:#FFFFFF;
      --bg-dark:#061434;
      --bg-dark-2:#020A1D;
      --ink:#071528;
      --ink-2:#12213A;
      --text:#17253C;
      --text-soft:#5A687D;
      --text-faint:#8390A4;
      --blue:#1B63FF;
      --blue-2:#0B46C8;
      --blue-soft:#E2EAFF;
      --indigo:#6868F4;
      --indigo-2:#4E51D4;
      --indigo-soft:#E8E8FF;
      --teal:#14B8C8;
      --teal-2:#0C7D89;
      --teal-soft:#DCF6F8;
      --mint:#40E0C0;
      --mint-soft:#DDF8F1;
      --slate:#2E405C;
      --slate-soft:#E1E8F1;
      --border:#CBD6E5;
      --border-strong:#AEBED2;
      --shadow-xs:0 4px 12px rgba(5,17,35,.05);
      --shadow-sm:0 12px 24px rgba(5,17,35,.075);
      --shadow-md:0 18px 38px rgba(5,17,35,.105);
      --shadow-lg:0 26px 58px rgba(5,17,35,.14);
      --shadow-blue:0 16px 30px rgba(27,99,255,.20), 0 3px 0 #0B46C8;
      --shadow-indigo:0 16px 30px rgba(104,104,244,.18), 0 3px 0 #4E51D4;
      --shadow-teal:0 16px 30px rgba(20,184,200,.15), 0 3px 0 #0C7D89;
      --shadow-dark:0 22px 46px rgba(2,10,29,.26), inset 0 1px 0 rgba(255,255,255,.06);
    }

    body{
      background:
        radial-gradient(circle at 12% -8%, rgba(104,104,244,.12), transparent 32%),
        radial-gradient(circle at 86% 8%, rgba(20,184,200,.10), transparent 30%),
        var(--bg-page);
    }

    .nav{
      background:
        radial-gradient(circle at 88% 20%, rgba(20,184,200,.22), transparent 34%),
        linear-gradient(135deg,#061434 0%,#101D58 52%,#5524B8 100%) !important;
      border-bottom:1px solid rgba(255,255,255,.10);
      box-shadow:0 22px 54px rgba(20,28,68,.20) !important;
    }

    .nav.scrolled{
      background:
        radial-gradient(circle at 88% 20%, rgba(20,184,200,.18), transparent 32%),
        linear-gradient(135deg,#041026 0%,#0B1747 54%,#431C95 100%) !important;
      box-shadow:0 18px 42px rgba(2,10,29,.30) !important;
    }

    .nav-logo em{
      background:var(--mint);
      box-shadow:0 0 0 6px rgba(64,224,192,.14), 0 0 20px rgba(64,224,192,.45);
    }

    .nav-links a:hover{ color:#BFEFFF; }

    .nav-cta{
      background:linear-gradient(135deg,var(--blue),var(--indigo)) !important;
      border-color:rgba(255,255,255,.22);
      box-shadow:0 14px 30px rgba(27,99,255,.28), inset 0 1px 0 rgba(255,255,255,.22);
    }

    .hero{
      background:
        radial-gradient(circle at 18% 20%, rgba(27,99,255,.08), transparent 30%),
        radial-gradient(circle at 76% 28%, rgba(104,104,244,.11), transparent 34%),
        linear-gradient(to right,#F7F9FC 0%,#F7F9FC 64%,#E9EFF7 64%,#E9EFF7 100%);
    }

    .hero::before{ opacity:.34; }

    .hero-tag,
    .cta-pill{
      background:rgba(255,255,255,.82);
      border-color:#C7D4E8;
      color:var(--indigo);
      box-shadow:0 12px 26px rgba(5,17,35,.07), inset 0 1px 0 rgba(255,255,255,.95);
    }

    .hero-title .accent,
    .com-amount{
      background:linear-gradient(135deg,var(--blue) 0%,var(--indigo) 55%,var(--teal) 100%);
      -webkit-background-clip:text;
      background-clip:text;
      color:transparent;
    }

    .btn-primary{
      background:linear-gradient(135deg,var(--blue),var(--indigo));
      border-color:rgba(11,70,200,.72);
      box-shadow:0 18px 34px rgba(27,99,255,.22),0 3px 0 #0B46C8,inset 0 1px 0 rgba(255,255,255,.25);
    }

    .btn-primary:hover{ background:linear-gradient(135deg,var(--blue-2),var(--indigo-2)); }

    .btn-secondary{
      background:rgba(255,255,255,.86);
      backdrop-filter:blur(10px);
      border-color:#B8C8DC;
    }

    .hero-invest,
    .com-highlight,
    .sim-card,
    .ingresos-table{
      transform:rotateY(-2deg) rotateX(1deg);
      border-color:#B4C4DA;
      box-shadow:0 26px 56px rgba(5,17,35,.14), 0 3px 0 rgba(5,17,35,.06), inset 0 1px 0 rgba(255,255,255,.96);
    }

    .invest-check{
      background:linear-gradient(135deg,#E3FFF8,#DDF6FF);
      color:#0E9F8C;
    }

    .section-white,
    #documentos{
      background:
        radial-gradient(circle at 12% 8%, rgba(27,99,255,.055), transparent 32%),
        #F8FAFC;
    }

    .why,
    .proceso{
      background:
        radial-gradient(circle at 18% 0%, rgba(104,104,244,.09), transparent 30%),
        linear-gradient(180deg,#EAF0F7 0%,#F4F7FB 100%);
    }

    #ingresos{
      background:
        radial-gradient(circle at 15% 10%, rgba(104,104,244,.10), transparent 32%),
        radial-gradient(circle at 88% 18%, rgba(20,184,200,.08), transparent 28%),
        linear-gradient(180deg,#F8FAFC 0%,#EAF0F7 100%);
    }

    .com-section{
      background:
        radial-gradient(circle at 16% 18%, rgba(27,99,255,.24), transparent 32%),
        radial-gradient(circle at 84% 22%, rgba(104,104,244,.24), transparent 30%),
        linear-gradient(135deg,#061434 0%,#0B1747 46%,#20115F 100%);
      position:relative;
      overflow:hidden;
    }

    .com-section::before{
      content:"";
      position:absolute;
      inset:0;
      background:
        linear-gradient(90deg, rgba(255,255,255,.045) 1px, transparent 1px),
        linear-gradient(rgba(255,255,255,.045) 1px, transparent 1px);
      background-size:68px 68px;
      opacity:.18;
      pointer-events:none;
    }

    .com-section .container{ position:relative; z-index:1; }

    .com-highlight{
      background:linear-gradient(145deg,rgba(255,255,255,.96) 0%,#EEF3FF 100%);
      border:1px solid rgba(201,214,236,.95);
      color:var(--ink);
    }

    .com-highlight::before{
      content:"";
      position:absolute;
      top:0;
      left:0;
      right:0;
      height:10px;
      border-top-left-radius:30px;
      border-top-right-radius:30px;
    }

    .com-label{ color:#6A7690; }

    .why-card,
    .vig-card,
    .proc-step,
    .doc-item,
    .sim-result,
    .invest-list-item{
      box-shadow:0 14px 30px rgba(5,17,35,.08), inset 0 1px 0 rgba(255,255,255,.94);
    }

    .why-card:hover,
    .vig-card:hover,
    .proc-step:hover,
    .doc-item:hover{
      transform:translateY(-6px);
      box-shadow:0 22px 44px rgba(5,17,35,.12), inset 0 1px 0 rgba(255,255,255,.94);
    }

    .why-card.featured-blue{
      background:linear-gradient(145deg,var(--blue),var(--indigo));
      border-color:rgba(255,255,255,.18);
    }

    .why-card.featured-dark{
      background:
        radial-gradient(circle at 84% 18%, rgba(20,184,200,.18), transparent 30%),
        linear-gradient(145deg,#07142F,#111C50 58%,#2A1769 100%);
      border-color:rgba(255,255,255,.10);
    }

    .wc-icon,
    .doc-icon,
    .proc-num{
      box-shadow:0 12px 24px rgba(5,17,35,.10), inset 0 1px 0 rgba(255,255,255,.82);
    }

    .sim-card{ background:linear-gradient(145deg,#FFFFFF 0%,#F2F6FC 100%); }

    .sim-result{
      background:rgba(255,255,255,.72);
      border-color:#D1DAE8;
    }

    .bar-fill{
      background:linear-gradient(90deg,var(--blue),var(--indigo),var(--teal));
      box-shadow:0 8px 18px rgba(27,99,255,.20);
    }

    .vig-card{ background:linear-gradient(145deg,#FFFFFF 0%,#F5F8FC 100%); }

    .docs-note{
      background:
        radial-gradient(circle at 90% 20%, rgba(20,184,200,.16), transparent 32%),
        linear-gradient(145deg,#061434,#111C50);
      border-color:rgba(255,255,255,.10);
    }

    .cta-section{
      background:
        radial-gradient(circle at 18% 24%, rgba(27,99,255,.24), transparent 30%),
        radial-gradient(circle at 82% 18%, rgba(20,184,200,.16), transparent 32%),
        linear-gradient(135deg,#061434 0%,#0D1A50 52%,#25116A 100%);
    }

    .cta-section .btn-primary{
      background:linear-gradient(135deg,var(--blue),var(--indigo));
      border-color:rgba(255,255,255,.22);
    }

    .footer{
      background:linear-gradient(180deg,#06101F 0%,#020813 100%);
      border-top:6px solid transparent;
      border-image:linear-gradient(90deg,var(--blue),var(--indigo),var(--teal)) 1;
    }

    @media(max-width:900px){
      .hero{
        background:
          radial-gradient(circle at 18% 18%, rgba(27,99,255,.08), transparent 34%),
          #F7F9FC;
      }
      .hero-invest,
      .com-highlight,
      .sim-card,
      .ingresos-table{ transform:none; }
    }

  </style>
</head>

<body>
  <header class="nav" id="navbar">
    <div class="nav-inner">
      <a href="#inicio" class="nav-logo" aria-label="Inicio PATS Distribuidores">
        PATS <em></em> Distribuidores
      </a>

      <nav class="nav-links" aria-label="Navegación principal">
        <a href="#por-que">¿Por qué?</a>
        <a href="#ingresos">Ingresos</a>
        <a href="#proceso">Proceso</a>
        <a href="#documentos">Requisitos</a>
        <a href="#solicitar" class="nav-cta js-solicitar">Solicitar alta</a>
      </nav>
    </div>
  </header>

  <main>
    <section class="hero" id="inicio">
      <div class="hero-inner container">
        <div class="hero-left">
          <div class="hero-tag reveal">Canal de distribución autorizado</div>

          <h1 class="hero-title reveal">
            Forma parte del<br />
            negocio que<br />
            <span class="accent">revolucionará</span><br />
            la salud en México
          </h1>

          <p class="hero-lead reveal">
            Genera ingresos recurrentes mensuales
            y construye un canal comercial respaldado por la red médica y hospitalaria
            de Fifty Doctors.
          </p>

          <div class="hero-badges reveal"></div>

          <div class="hero-actions reveal">
            <a href="#" class="btn-primary js-solicitar">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M9 12l2 2 4-4M21 12a9 9 0 11-18 0 9 9 0 0118 0z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
              </svg>
              Solicitar alta como distribuidor
            </a>

            <a href="#ingresos" class="btn-secondary">
              Ver escenarios de ingreso
            </a>
          </div>
        </div>

        <aside class="hero-invest reveal" aria-label="Resumen de inversión">
          <div>
            <div class="inv-label">Inversión</div>
            <div class="inv-amount">$20,000</div>
            <div class="inv-sub">MXN · IVA incluido</div>
          </div>

          <div class="inv-sep"></div>

          <div class="invest-list">
            <div class="invest-list-item">
              <span class="invest-check">
                <svg viewBox="0 0 24 24" fill="none"><path d="M6 12.5l4 4L18 8" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
              </span>
              Ingresos recurrentes mensuales
            </div>

            <div class="invest-list-item">
              <span class="invest-check">
                <svg viewBox="0 0 24 24" fill="none"><path d="M6 12.5l4 4L18 8" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
              </span>
              Panel de control y reportes
            </div>

            <div class="invest-list-item">
              <span class="invest-check">
                <svg viewBox="0 0 24 24" fill="none"><path d="M6 12.5l4 4L18 8" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
              </span>
              Capacitación y materiales incluidos
            </div>
          </div>
        </aside>
      </div>
    </section>

    <section class="section why" id="por-que">
      <div class="container">
        <div class="sec-header">
          <span class="sec-label reveal">¿Por qué ser distribuidor?</span>
          <h2 class="sec-title reveal">Un modelo de negocio sostenible y escalable</h2>
          <p class="sec-lead reveal">
            Forma parte de un modelo que transforma la manera en que las personas acceden a la salud en México de la mano de la red de Hospitales Fifty Doctors.
          </p>
        </div>

        <div class="why-grid">
          <article class="why-card featured-blue reveal">
            <div class="wc-icon">
              <svg viewBox="0 0 24 24" fill="none"><path d="M12 2v20M2 12h20" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            </div>
            <h3>Ingresos recurrentes</h3>
            <p>Ganas $80 MXN por cada Pasaporte activo cada mes. Entre más clientes, mayor ingreso pasivo mensual sin límite de crecimiento.</p>
          </article>

          <article class="why-card featured-dark reveal">
            <div class="wc-icon">
              <svg viewBox="0 0 24 24" fill="none"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            </div>
            <h3>Garantía Fifty Doctors</h3>
            <p>Detrás de cada atención hay una red hospitalaria comprometida con el bienestar y la salud de los pacientes.</p>
          </article>

          <article class="why-card reveal">
            <div class="wc-icon">
              <svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.5"/><path d="M12 8v4l3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            </div>
            <h3>Meta alcanzable</h3>
            <p>Con solo 21 pasaportes vendidos recuperas tu inversión inicial de $20,000 MXN en un año. A partir de ese punto, tus comisiones recurrentes representan utilidad directa.</p>
          </article>

          <article class="why-card reveal">
            <div class="wc-icon">
              <svg viewBox="0 0 24 24" fill="none"><rect x="2" y="3" width="20" height="14" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M8 21h8M12 17v4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            </div>
            <h3>Alta digital</h3>
            <p>Registro guiado en línea con firma digital, selfie biométrica y pago seguro. Sin trámites presenciales ni burocracia.</p>
          </article>

          <article class="why-card reveal">
            <div class="wc-icon">
              <svg viewBox="0 0 24 24" fill="none"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z" stroke="currentColor" stroke-width="1.5"/><circle cx="12" cy="10" r="3" stroke="currentColor" stroke-width="1.5"/></svg>
            </div>
            <h3>Múltiples ubicaciones</h3>
            <p>La red médica está distribuida estratégicamente para poder atender a todos los usuarios.</p>
          </article>

          <article class="why-card reveal">
            <div class="wc-icon">
              <svg viewBox="0 0 24 24" fill="none"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zM23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            </div>
            <h3>Ideal para todos</h3>
            <p>El Pasaporte a tu Salud está dirigido a niños, jóvenes, adultos y adultos mayores que buscan atención médica privada a precios accesibles.</p>
          </article>
        </div>
      </div>
    </section>

    <section class="section com-section" id="comisiones">
      <div class="container">
        <div class="com-grid">
          <div>
            <span class="sec-label reveal">Tu comisión</span>
            <h2 class="sec-title reveal">$80 MXN por cada Pasaporte, cada mes</h2>
            <p class="sec-lead reveal">
              Vende Pasaportes a $800 MXN/mes al usuario final. Tú te llevas
              $80 MXN mensuales por cada uno activo en tu cartera, de forma
              recurrente y automática.
            </p>

            <div class="com-recover reveal"></div>
          </div>

          <div class="com-highlight reveal">
            <div class="com-label">Pasaportes vendidos para recuperar tu inversión</div>
            <div class="com-amount">21</div>
            <div class="com-note">Con 21 pasaportes activos generas $1,680 MXN/mes → en 12 meses
              recuperas los $20,000 MXN de inversión inicial.</div>
          </div>
        </div>
      </div>
    </section>

    <section class="section ingresos-dark" id="ingresos">
      <div class="container">
        <div class="sec-header">
          <span class="sec-label reveal">Escenarios de ingresos</span>
          <h2 class="sec-title reveal">Entre más pasaportes activos, más ganas</h2>
          <p class="sec-lead reveal">
            Mueve el slider y calcula tu ganancia mensual, tu ganancia anual y el tiempo estimado para recuperar tu inversión.
          </p>
        </div>

        <div class="sim-card income-shell reveal barra">
          <div class="range-wrap">
            <div class="sim-top">
              <span class="sim-label meta-label">Pasaportes vendidos</span>
              <span class="sim-value main-value" id="sl-val">100</span>
            </div>

            <input type="range" id="sl-passport" min="1" max="1000" value="100" step="1" />

            <div class="range-scale">
              <span>1</span>
              <span>250</span>
              <span>500</span>
              <span>750</span>
              <span>1,000</span>
            </div>
          </div>

          <div class="sim-results income-grid">
            <div class="sim-result income-metric">
              <div class="sim-result-label metric-label">Comisión / Pasaporte</div>
              <div class="sim-result-value metric-value">$80 MXN</div>
            </div>

            <div class="sim-result income-metric accent is-primary">
              <div class="sim-result-label metric-label">Ganancia mensual</div>
              <div class="sim-result-value metric-value" id="c-mensual">$8,000 MXN</div>
            </div>

            <div class="sim-result income-metric accent is-primary">
              <div class="sim-result-label metric-label">Ganancia anual</div>
              <div class="sim-result-value metric-value" id="c-anual">$96,000 MXN</div>
            </div>

            <div class="sim-result income-metric success is-accent">
              <div class="sim-result-label metric-label">Recuperas inversión en</div>
              <div class="sim-result-value metric-value" id="c-recup">3 meses</div>
            </div>
          </div>

          <div class="income-progress-block">
            <div class="bar-row progress-head">
              <span>Progreso hacia recuperación de inversión ($20,000 MXN)</span>
              <span class="progress-percent" id="bar-pct">40%</span>
            </div>

            <div class="bar-track income-progress-track">
              <div class="bar-fill income-progress-fill" id="bar-fill" style="width:40%"></div>
            </div>

            <p class="recovery-msg income-note" id="recovery-msg">
              Con 100 pasaportes generas $8,000 MXN/mes y recuperas tu inversión en 3 meses.
            </p>
          </div>
        </div>

        <p class="table-note income-table-note reveal">
          *Comisiones sobre pago recurrente mensual de cada pasaporte activo.
        </p>

        <div class="vig-cards income-info-grid">
          <article class="vig-card income-info-card reveal">
            <div class="vig-value">3 años</div>
            <h3>Vigencia del contrato</h3>
            <p>Tu contrato de distribución tiene una vigencia inicial de 3 años, renovable.</p>
          </article>

          <article class="vig-card income-info-card reveal">
            <div class="vig-value">$800/mes</div>
            <h3>Precio de venta al público</h3>
            <p>Precio oficial del Pasaporte a tu Salud, IVA incluido, sin margen de confusión.</p>
          </article>

          <article class="vig-card income-info-card reveal">
            <div class="vig-value">$20,000</div>
            <h3>Inversión</h3>
            <p>Pago de contado o en mensualidades sin intereses, sujeto a las opciones disponibles.</p>
          </article>

          <article class="vig-card income-info-card reveal">
            <div class="vig-value">21</div>
            <h3>Pasaportes vendidos</h3>
            <p>Generas $1,680 MXN/mes y puedes recuperar los $20,000 MXN de inversión inicial en 12 meses.</p>
          </article>
        </div>
      </div>
    </section>

    <section class="section proceso" id="proceso">
      <div class="container">
        <div class="sec-header">
          <span class="sec-label reveal">Proceso de alta</span>
          <h2 class="sec-title reveal">En 4 pasos, listo para vender</h2>
          <p class="sec-lead reveal">
            Alta 100% digital. Registro guiado, firma digital y pago seguro en un solo flujo.
          </p>
        </div>

        <div class="proc-steps">
          <article class="proc-step reveal">
            <div class="proc-num">01</div>
            <h3>Datos generales</h3>
            <p>Completa tu información personal, indica si eres persona física o moral y añade tus datos bancarios.</p>
          </article>

          <article class="proc-step reveal">
            <div class="proc-num">02</div>
            <h3>Documentación</h3>
            <p>Sube tus documentos requeridos: INE, CURP, comprobante de domicilio, cédula fiscal y carátula bancaria.</p>
          </article>

          <article class="proc-step reveal">
            <div class="proc-num">03</div>
            <h3>Identidad y contrato</h3>
            <p>Toma tu fotografía, firma el contrato y acepta los términos y condiciones.</p>
          </article>

          <article class="proc-step final reveal">
            <div class="proc-num">04</div>
            <h3>Pago y activación</h3>
            <p>Elige tu forma de pago. Espera la confirmación vía correo electrónico, accede a la plataforma y comienza a vender.</p>
          </article>
        </div>
      </div>
    </section>

    <section class="section" id="documentos">
      <div class="container">
        <div class="sec-header">
          <span class="sec-label reveal">Requisitos</span>
          <h2 class="sec-title reveal">Documentos necesarios para el alta</h2>
          <p class="sec-lead reveal">
            Todos los archivos se transmiten de forma cifrada. Formatos aceptados: PDF, JPG y PNG.
          </p>
        </div>

        <div class="docs-grid">
          <article class="doc-item reveal">
            <div class="doc-icon">
              <svg viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.5"/><circle cx="12" cy="10" r="3" stroke="currentColor" stroke-width="1.5"/><path d="M6 20c0-2.21 2.69-4 6-4s6 1.79 6 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            </div>
            <div>
              <div class="doc-name">INE / Pasaporte</div>
              <div class="doc-sub">Identificación oficial vigente</div>
            </div>
            <span class="doc-req">Requerido</span>
          </article>

          <article class="doc-item reveal">
            <div class="doc-icon">
              <svg viewBox="0 0 24 24" fill="none"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </div>
            <div>
              <div class="doc-name">CURP</div>
              <div class="doc-sub">Clave Única de Registro de Población</div>
            </div>
            <span class="doc-req">Requerido</span>
          </article>

          <article class="doc-item reveal">
            <div class="doc-icon">
              <svg viewBox="0 0 24 24" fill="none"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </div>
            <div>
              <div class="doc-name">Comprobante de domicilio</div>
              <div class="doc-sub">Reciente, máximo 3 meses</div>
            </div>
            <span class="doc-req">Requerido</span>
          </article>

          <article class="doc-item reveal">
            <div class="doc-icon">
              <svg viewBox="0 0 24 24" fill="none"><rect x="5" y="3" width="14" height="18" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M9 7h6M9 11h6M9 15h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            </div>
            <div>
              <div class="doc-name">Cédula fiscal</div>
              <div class="doc-sub">Constancia de situación fiscal SAT</div>
            </div>
            <span class="doc-req">Requerido</span>
          </article>

          <article class="doc-item reveal">
            <div class="doc-icon">
              <svg viewBox="0 0 24 24" fill="none"><path d="M3 5a2 2 0 012-2h14a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V5z" stroke="currentColor" stroke-width="1.5"/><path d="M3 10h18M7 15h.01M12 15h.01" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            </div>
            <div>
              <div class="doc-name">Carátula bancaria</div>
              <div class="doc-sub">Cuenta donde recibirás comisiones</div>
            </div>
            <span class="doc-req">Requerido</span>
          </article>

          <article class="doc-item reveal">
            <div class="doc-icon">
              <svg viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6z" stroke="currentColor" stroke-width="1.5"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            </div>
            <div>
              <div class="doc-name">Acta constitutiva</div>
              <div class="doc-sub">Solo persona moral</div>
            </div>
            <span class="doc-req">Condicional</span>
          </article>

          <article class="doc-item reveal">
            <div class="doc-icon">
              <svg viewBox="0 0 24 24" fill="none"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            </div>
            <div>
              <div class="doc-name">Poder notarial</div>
              <div class="doc-sub">Solo persona moral</div>
            </div>
            <span class="doc-req">Condicional</span>
          </article>

          <article class="doc-item reveal">
            <div class="doc-icon">
              <svg viewBox="0 0 24 24" fill="none"><path d="M12 2a5 5 0 110 10 5 5 0 010-10zM4 20a8 8 0 0116 0" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            </div>
            <div>
              <div class="doc-name">Fotografía del titular</div>
              <div class="doc-sub">Foto desde cámara o subida</div>
            </div>
            <span class="doc-req">Requerido</span>
          </article>
        </div>

        <p class="docs-note">
          Para persona moral se requiere el acta constitutiva y/o el poder notarial.
          Todos los documentos se procesan de forma segura y cifrada.
        </p>
      </div>
    </section>

    <section class="section faq-section" id="preguntas">
      <div class="container faq-shell">
        <div class="faq-header">
          <div class="faq-kicker reveal">Preguntas frecuentes</div>
          <h2 class="sec-title reveal">Todo lo que necesitas saber antes de iniciar</h2>
          <p class="sec-lead reveal">
            Resolvemos las dudas más importantes sobre la distribución, el modelo de ingresos,
            la operación comercial y el proceso de alta.
          </p>
        </div>

        <div class="faq-layout">
          <div class="faq-panel reveal">
            <div class="faq-list" id="faq-list">

              <article class="faq-item open">
                <button class="faq-question" type="button" aria-expanded="true">
                  <span class="faq-num">01</span>
                  <span class="faq-q-text">¿Qué es el Pasaporte a tu Salud?</span>
                  <span class="faq-chevron" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </span>
                </button>
                <div class="faq-answer">
                  <div class="faq-answer-inner">
                    Es una tarjeta de descuento médico que permite a los usuarios acceder a servicios médicos privados de alta calidad de forma accesible, clara y sin restricciones dentro de la red de hospitales 50 Doctors y médicos afiliados al programa Pasaporte a tu Salud.
                  </div>
                </div>
              </article>

              <article class="faq-item">
                <button class="faq-question" type="button" aria-expanded="false">
                  <span class="faq-num">02</span>
                  <span class="faq-q-text">¿Cuál es la diferencia entre un seguro y el Pasaporte a tu Salud?</span>
                  <span class="faq-chevron" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </span>
                </button>
                <div class="faq-answer">
                  <div class="faq-answer-inner">
                    Un seguro implica pólizas, deducibles, restricciones y aprobación de siniestros. Pasaporte a tu Salud funciona como un sistema de acceso directo a descuentos, sin trámites complejos ni autorizaciones.
                  </div>
                </div>
              </article>

              <article class="faq-item">
                <button class="faq-question" type="button" aria-expanded="false">
                  <span class="faq-num">03</span>
                  <span class="faq-q-text">¿Para quién está dirigido el Pasaporte a tu Salud?</span>
                  <span class="faq-chevron" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </span>
                </button>
                <div class="faq-answer">
                  <div class="faq-answer-inner">
                    Está dirigido a personas sin seguro médico, familias que buscan reducir gastos médicos, empresas que desean apoyar a sus colaboradores, niños, jóvenes, adultos y adultos mayores que quieren acceso inmediato a atención privada.
                  </div>
                </div>
              </article>

              <article class="faq-item">
                <button class="faq-question" type="button" aria-expanded="false">
                  <span class="faq-num">04</span>
                  <span class="faq-q-text">¿Cuáles son los beneficios del Pasaporte a tu Salud?</span>
                  <span class="faq-chevron" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </span>
                </button>
                <div class="faq-answer">
                  <div class="faq-answer-inner">
                    Ofrece acceso a médicos privados a menor costo, claridad en precios, red médica validada, descuentos en cirugías, laboratorio, imagenología y medicamentos seleccionados dentro del programa.
                  </div>
                </div>
              </article>

              <article class="faq-item">
                <button class="faq-question" type="button" aria-expanded="false">
                  <span class="faq-num">05</span>
                  <span class="faq-q-text">¿Dónde se atienden los pacientes del Pasaporte a tu Salud?</span>
                  <span class="faq-chevron" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </span>
                </button>
                <div class="faq-answer">
                  <div class="faq-answer-inner">
                    Los pacientes se atienden en la red de hospitales 50 Doctors de Puebla para servicios de urgencias, consultas generales y atención hospitalaria. Para consultas de especialidad, podrán ser atendidos en los consultorios de médicos afiliados al programa.
                  </div>
                </div>
              </article>

              <article class="faq-item faq-extra" hidden>
                <button class="faq-question" type="button" aria-expanded="false">
                  <span class="faq-num">06</span>
                  <span class="faq-q-text">¿Cuánto debo invertir?</span>
                  <span class="faq-chevron" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </span>
                </button>
                <div class="faq-answer">
                  <div class="faq-answer-inner">
                    La inversión para adquirir una distribución es de $20,000 MXN, IVA incluido.
                  </div>
                </div>
              </article>

              <article class="faq-item faq-extra" hidden>
                <button class="faq-question" type="button" aria-expanded="false">
                  <span class="faq-num">07</span>
                  <span class="faq-q-text">¿Hay financiamiento para adquirir una distribución?</span>
                  <span class="faq-chevron" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </span>
                </button>
                <div class="faq-answer">
                  <div class="faq-answer-inner">
                    Sí. Puede existir la posibilidad de adquirir la distribución a 3, 6, 9 o 12 meses sin intereses pagando con tarjeta de crédito.
                  </div>
                </div>
              </article>

              <article class="faq-item faq-extra" hidden>
                <button class="faq-question" type="button" aria-expanded="false">
                  <span class="faq-num">08</span>
                  <span class="faq-q-text">¿Qué incluye la inversión?</span>
                  <span class="faq-chevron" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </span>
                </button>
                <div class="faq-answer">
                  <div class="faq-answer-inner">
                    Incluye licencia de distribución, derecho a comercializar el Pasaporte a tu Salud, acceso a manuales, capacitación, material comercial, sistema informático para control de actividad comercial y uso autorizado de marca.
                  </div>
                </div>
              </article>

              <article class="faq-item faq-extra" hidden>
                <button class="faq-question" type="button" aria-expanded="false">
                  <span class="faq-num">09</span>
                  <span class="faq-q-text">¿Qué beneficios obtengo al ser distribuidor?</span>
                  <span class="faq-chevron" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </span>
                </button>
                <div class="faq-answer">
                  <div class="faq-answer-inner">
                    Obtienes ingresos por venta directa de Pasaportes, comisiones recurrentes, soporte comercial, capacitación y acceso a herramientas para administrar tu actividad.
                  </div>
                </div>
              </article>

              <article class="faq-item faq-extra" hidden>
                <button class="faq-question" type="button" aria-expanded="false">
                  <span class="faq-num">10</span>
                  <span class="faq-q-text">¿Cuándo recupero mi inversión?</span>
                  <span class="faq-chevron" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </span>
                </button>
                <div class="faq-answer">
                  <div class="faq-answer-inner">
                    Depende del número de Pasaportes vendidos. Como referencia, con 21 Pasaportes activos puedes recuperar la inversión inicial en un año.
                  </div>
                </div>
              </article>

              <article class="faq-item faq-extra" hidden>
                <button class="faq-question" type="button" aria-expanded="false">
                  <span class="faq-num">11</span>
                  <span class="faq-q-text">¿Cuál es el proceso para adquirir una distribución?</span>
                  <span class="faq-chevron" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </span>
                </button>
                <div class="faq-answer">
                  <div class="faq-answer-inner">
                    El proceso incluye recepción de información, carga de documentos, firma de contrato, pago, validación corporativa, activación de usuario en plataforma y capacitación para iniciar la comercialización.
                  </div>
                </div>
              </article>

              <article class="faq-item faq-extra" hidden>
                <button class="faq-question" type="button" aria-expanded="false">
                  <span class="faq-num">12</span>
                  <span class="faq-q-text">¿Qué documentos necesito como persona física?</span>
                  <span class="faq-chevron" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </span>
                </button>
                <div class="faq-answer">
                  <div class="faq-answer-inner">
                    Si radicas en México: identificación oficial, RFC, comprobante de domicilio, CURP, carátula bancaria, teléfono y correo electrónico. Si radicas en el extranjero: identificación oficial, comprobante de domicilio, carátula bancaria, teléfono y correo electrónico.
                  </div>
                </div>
              </article>

              <article class="faq-item faq-extra" hidden>
                <button class="faq-question" type="button" aria-expanded="false">
                  <span class="faq-num">13</span>
                  <span class="faq-q-text">¿Puedo adquirir mi distribución como persona moral?</span>
                  <span class="faq-chevron" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </span>
                </button>
                <div class="faq-answer">
                  <div class="faq-answer-inner">
                    Sí. Es posible adquirir una distribución tanto como persona física como persona moral.
                  </div>
                </div>
              </article>

              <article class="faq-item faq-extra" hidden>
                <button class="faq-question" type="button" aria-expanded="false">
                  <span class="faq-num">14</span>
                  <span class="faq-q-text">¿Qué documentos necesito como persona moral?</span>
                  <span class="faq-chevron" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </span>
                </button>
                <div class="faq-answer">
                  <div class="faq-answer-inner">
                    Se requiere acta constitutiva, constancia de situación fiscal, comprobante de domicilio, INE del representante legal, carátula bancaria, teléfono y correo electrónico.
                  </div>
                </div>
              </article>

              <article class="faq-item faq-extra" hidden>
                <button class="faq-question" type="button" aria-expanded="false">
                  <span class="faq-num">15</span>
                  <span class="faq-q-text">¿Qué tipo de capacitación recibo?</span>
                  <span class="faq-chevron" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </span>
                </button>
                <div class="faq-answer">
                  <div class="faq-answer-inner">
                    Recibes capacitación comercial, operativa y de uso de plataforma para que puedas iniciar la comercialización de forma ordenada.
                  </div>
                </div>
              </article>

              <article class="faq-item faq-extra" hidden>
                <button class="faq-question" type="button" aria-expanded="false">
                  <span class="faq-num">16</span>
                  <span class="faq-q-text">¿Cuándo cobro mis comisiones?</span>
                  <span class="faq-chevron" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </span>
                </button>
                <div class="faq-answer">
                  <div class="faq-answer-inner">
                    Las comisiones se liquidan a mes vencido tras el envío de la factura correspondiente a través de la plataforma. Cuentas con 5 días hábiles para solicitarlas; si no lo haces, se acumularán para el siguiente periodo.
                  </div>
                </div>
              </article>

              <article class="faq-item faq-extra" hidden>
                <button class="faq-question" type="button" aria-expanded="false">
                  <span class="faq-num">17</span>
                  <span class="faq-q-text">¿A quién le puedo vender un Pasaporte a tu Salud?</span>
                  <span class="faq-chevron" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </span>
                </button>
                <div class="faq-answer">
                  <div class="faq-answer-inner">
                    Puedes venderlo a niños, jóvenes, adultos y adultos mayores. La contratación por una persona mayor de 65 años está sujeta a la contratación por otros dos usuarios menores de 65 años.
                  </div>
                </div>
              </article>

              <article class="faq-item faq-extra" hidden>
                <button class="faq-question" type="button" aria-expanded="false">
                  <span class="faq-num">18</span>
                  <span class="faq-q-text">¿Cómo se puede pagar el Pasaporte a tu Salud?</span>
                  <span class="faq-chevron" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </span>
                </button>
                <div class="faq-answer">
                  <div class="faq-answer-inner">
                    Puede pagarse mediante pago recurrente mensual con tarjeta de crédito, pago domiciliado mensual o anual, y pago en efectivo a través de tiendas de conveniencia sujetas a convenio con la pasarela de pagos.
                  </div>
                </div>
              </article>

              <article class="faq-item faq-extra" hidden>
                <button class="faq-question" type="button" aria-expanded="false">
                  <span class="faq-num">19</span>
                  <span class="faq-q-text">¿Hay alguna restricción para vender el Pasaporte?</span>
                  <span class="faq-chevron" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </span>
                </button>
                <div class="faq-answer">
                  <div class="faq-answer-inner">
                    No existen restricciones generales, ya que está dirigido a niños, jóvenes, adultos y adultos mayores. La única condición especial es para usuarios mayores de 65 años, cuya contratación requiere dos usuarios menores de 65 años asociados.
                  </div>
                </div>
              </article>

              <article class="faq-item faq-extra" hidden>
                <button class="faq-question" type="button" aria-expanded="false">
                  <span class="faq-num">20</span>
                  <span class="faq-q-text">¿Cómo puede adquirirlo un menor de edad?</span>
                  <span class="faq-chevron" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </span>
                </button>
                <div class="faq-answer">
                  <div class="faq-answer-inner">
                    A través de un tutor, quien se encarga de facilitar la documentación necesaria para el alta del menor y efectuar los pagos correspondientes.
                  </div>
                </div>
              </article>

              <article class="faq-item faq-extra" hidden>
                <button class="faq-question" type="button" aria-expanded="false">
                  <span class="faq-num">21</span>
                  <span class="faq-q-text">¿Cómo puede adquirirlo una persona mayor de 65 años?</span>
                  <span class="faq-chevron" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </span>
                </button>
                <div class="faq-answer">
                  <div class="faq-answer-inner">
                    Debe aportar al momento de la contratación dos nuevos usuarios menores de 65 años, cuyos contratos quedarán asociados como condición para mantener los beneficios del usuario mayor de 65 años.
                  </div>
                </div>
              </article>

              <article class="faq-item faq-extra" hidden>
                <button class="faq-question" type="button" aria-expanded="false">
                  <span class="faq-num">22</span>
                  <span class="faq-q-text">Como usuario, ¿a quién me dirijo para obtener información?</span>
                  <span class="faq-chevron" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </span>
                </button>
                <div class="faq-answer">
                  <div class="faq-answer-inner">
                    Para temas administrativos o de contratación, el usuario puede dirigirse al distribuidor asignado. Para cuestiones médicas, contará con apoyo del concierge en hospitales 50 Doctors y acceso a información de la red médica desde la aplicación.
                  </div>
                </div>
              </article>

              <article class="faq-item faq-extra" hidden>
                <button class="faq-question" type="button" aria-expanded="false">
                  <span class="faq-num">23</span>
                  <span class="faq-q-text">¿Qué pasa si el usuario deja de pagar mensualmente?</span>
                  <span class="faq-chevron" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </span>
                </button>
                <div class="faq-answer">
                  <div class="faq-answer-inner">
                    Se suspende el servicio y el usuario pierde acceso a los beneficios. Puede restablecerse mediante el pago de las cuotas pendientes, con un incremento de $100 MXN por cada mes impagado.
                  </div>
                </div>
              </article>

            </div>

            <div class="faq-actions">
              <button class="faq-more-btn" type="button" id="faq-more-btn" aria-expanded="false">
                <span>Ver todas las preguntas</span>
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                  <path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
              </button>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="cta-section" id="solicitar">
      <div class="container cta-inner">
        <div class="cta-pill reveal">Forma parte de la red</div>

        <h2 class="cta-title reveal">
          ¿Listo para ser<br />
          Distribuidor de Pasaporte a tu Salud?
        </h2>

        <p class="cta-lead reveal">
          Revisamos tu solicitud en menos de 48 horas.
        </p>

        <div class="cta-actions reveal">
          <a href="#" class="btn-primary js-solicitar">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            Iniciar solicitud de alta
          </a>

          <a href="tel:<?= $telefonoHrefSafe ?>" class="btn-secondary">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.95 10.5a19.79 19.79 0 01-3.07-8.63A2 2 0 012.88 0h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L7.09 7.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z" stroke="currentColor" stroke-width="1.5"/>
            </svg>
            Hablar con un asesor
          </a>
        </div>

        <p class="cta-note reveal">
          Alta digital segura · Protección de información · Pago seguro · Respaldado por Fifty Doctors
        </p>
      </div>
    </section>
  </main>

  <footer class="footer">
    <div class="container">
      <div class="footer-grid">
        <div class="footer-brand">
          <div class="logo">PATS · Distribuidores</div>
          <p>
            Canal de distribución autorizado del programa Pasaporte a tu Salud,
            respaldado por la red hospitalaria Fifty Doctors.
          </p>
        </div>

        <nav class="footer-nav">
          <h4>Distribución</h4>
          <ul>
            <li><a href="#por-que">¿Por qué unirse?</a></li>
            <li><a href="#ingresos">Escenarios de ingreso</a></li>
            <li><a href="#proceso">Proceso de alta</a></li>
            <li><a href="#documentos">Requisitos</a></li>
          </ul>
        </nav>

        <nav class="footer-nav">
          <h4>Programa</h4>
          <ul>
            <li><a href="pasaporte.html">Pasaporte a tu Salud</a></li>
            <li><a href="#comisiones">Comisiones</a></li>
            <li><a href="#solicitar">Solicitar alta</a></li>
          </ul>
        </nav>

        <div class="footer-nav">
          <h4>Contacto</h4>
          <p>¿Tienes preguntas sobre la distribución? Nuestro equipo comercial te orienta.</p>
          <a href="https://wa.me/<?= $telefonoWaSafe ?>" target="_blank" rel="noopener" class="footer-phone">
            WhatsApp: <?= $telefonoAsesorSafe ?>
          </a>
        </div>
      </div>

      <div class="footer-bottom">
        <p>&copy; 2026 Pasaporte a tu Salud · 50 Doctors. Todos los derechos reservados.</p>
        <p>Red de distribución autorizada · México</p>
      </div>
    </div>
  </footer>

  <script>
    /* ── NAV SCROLL ── */
    const nav = document.getElementById("navbar");
    window.addEventListener("scroll", () => {
      nav.classList.toggle("scrolled", window.scrollY > 20);
    }, { passive: true });

    /* ── REVEAL ── */
    const obs = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.classList.add("vis");
          obs.unobserve(entry.target);
        }
      });
    }, { threshold: 0.1, rootMargin: "0px 0px -40px 0px" });
    document.querySelectorAll(".reveal").forEach(el => obs.observe(el));

    /* ── SMOOTH SCROLL ── */
    document.querySelectorAll('a[href^="#"]').forEach(a => {
      a.addEventListener("click", e => {
        const selector = a.getAttribute("href");
        if (selector === "#") return;
        const target = document.querySelector(selector);
        if (!target) return;
        e.preventDefault();
        window.scrollTo({
          top: target.getBoundingClientRect().top + window.scrollY - 84,
          behavior: "smooth"
        });
      });
    });

    /* ── TOKEN → BOTONES DE SOLICITUD ── */
    (function () {
      const params  = new URLSearchParams(window.location.search);
      const token   = params.get("t") || "";
      const base    = "https://admin-pats.50d.com.mx/distribucion/solicitud";
      const destUrl = token ? base + "?t=" + encodeURIComponent(token) : base;

      document.querySelectorAll(".js-solicitar").forEach(a => {
        a.href   = destUrl;
        a.target = "_blank";
        a.rel    = "noopener noreferrer";
      });
    })();

    /* ── SIMULADOR ── */
    (function () {
      const COM = 80;
      const INV = 20000;
      const fmtMXN = n => "$" + Math.round(n).toLocaleString("es-MX") + " MXN";

      function update() {
        const input = document.getElementById("sl-passport");
        if (!input) return;

        const val     = parseInt(input.value, 10);
        const mensual = val * COM;
        const anual   = mensual * 12;
        const meses   = mensual > 0 ? Math.ceil(INV / mensual) : "—";
        const pct     = Math.min(100, Math.round((mensual / INV) * 100));

        document.getElementById("sl-val").textContent    = val.toLocaleString("es-MX");
        document.getElementById("c-mensual").textContent = fmtMXN(mensual);
        document.getElementById("c-anual").textContent   = fmtMXN(anual);
        document.getElementById("c-recup").textContent   =
          mensual >= INV ? "¡Ya recuperada!" : meses === 1 ? "1 mes" : meses + " meses";

        document.getElementById("bar-fill").style.width  = pct + "%";
        document.getElementById("bar-pct").textContent   = pct + "%";

        document.getElementById("recovery-msg").textContent =
          mensual >= INV
            ? "✓ Con " + val.toLocaleString("es-MX") + " pasaportes ya superaste la inversión inicial de $20,000 MXN."
            : "Con " + val.toLocaleString("es-MX") + " pasaportes generas " + fmtMXN(mensual) + "/mes y recuperas tu inversión en " + meses + (meses === 1 ? " mes." : " meses.");
      }

      const slider = document.getElementById("sl-passport");
      if (slider) {
        slider.addEventListener("input", update);
        update();
      }
    })();

    /* ── FAQ ── */
    (function () {
      const faqItems  = document.querySelectorAll(".faq-item");
      const moreBtn   = document.getElementById("faq-more-btn");
      const extraItems = document.querySelectorAll(".faq-extra");

      function closeItem(item) {
        const btn    = item.querySelector(".faq-question");
        const answer = item.querySelector(".faq-answer");
        item.classList.remove("open");
        if (btn)    btn.setAttribute("aria-expanded", "false");
        if (answer) answer.style.maxHeight = "0px";
      }

      function openItem(item) {
        const btn    = item.querySelector(".faq-question");
        const answer = item.querySelector(".faq-answer");
        item.classList.add("open");
        if (btn)    btn.setAttribute("aria-expanded", "true");
        if (answer) answer.style.maxHeight = answer.scrollHeight + "px";
      }

      faqItems.forEach(item => {
        const btn = item.querySelector(".faq-question");
        if (!btn) return;
        btn.addEventListener("click", () => {
          const isOpen = item.classList.contains("open");
          faqItems.forEach(other => { if (other !== item) closeItem(other); });
          isOpen ? closeItem(item) : openItem(item);
        });
      });

      document.querySelectorAll(".faq-item.open").forEach(openItem);

      if (moreBtn) {
        moreBtn.addEventListener("click", () => {
          const expanded = moreBtn.classList.toggle("expanded");
          moreBtn.setAttribute("aria-expanded", expanded ? "true" : "false");
          extraItems.forEach(item => {
            item.hidden = !expanded;
            if (!expanded) closeItem(item);
          });
          const label = moreBtn.querySelector("span");
          if (label) label.textContent = expanded ? "Ver menos preguntas" : "Ver todas las preguntas";
        });
      }

      window.addEventListener("resize", () => {
        document.querySelectorAll(".faq-item.open .faq-answer").forEach(answer => {
          answer.style.maxHeight = answer.scrollHeight + "px";
        });
      }, { passive: true });
    })();
  </script>
</body>
</html>