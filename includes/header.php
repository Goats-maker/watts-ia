<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/GeminiClient.php';

$tituloPagina = $tituloPagina ?? 'Inicio';
$hogar = $hogar ?? hogarActual();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($tituloPagina) ?> · Guardianes del Planeta</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php
// ?v=... fuerza al navegador a bajar el CSS nuevo cada vez que cambia (evita caché vieja)
$versionCss = @filemtime(__DIR__ . '/../assets/css/app.css') ?: 1;
?>
<link href="assets/css/app.css?v=<?= $versionCss ?>" rel="stylesheet">
</head>
<body>

<!-- Fondo animado: aura gris-plateada-púrpura con rayos. Solo decorativo.
     Los estilos en línea garantizan que, aunque el CSS falle o llegue viejo,
     la capa quede fija, detrás del contenido, recortada e inofensiva. -->
<div class="fondo-aura" aria-hidden="true"
     style="position:fixed;top:0;left:0;right:0;bottom:0;z-index:-2;overflow:hidden;pointer-events:none">
  <span class="aura-blob b1"></span>
  <span class="aura-blob b2"></span>
  <span class="aura-blob b3"></span>
  <span class="aura-blob b4"></span>

  <span class="anillo a1"></span>
  <span class="anillo a2"></span>
  <span class="anillo a3"></span>

  <svg class="rayo-defs" width="0" height="0" aria-hidden="true">
    <defs>
      <linearGradient id="gRayo" x1="0" y1="0" x2="1" y2="1">
        <stop offset="0%"   stop-color="#c084fc"/>
        <stop offset="55%"  stop-color="#a855f7"/>
        <stop offset="100%" stop-color="#e879f9"/>
      </linearGradient>
    </defs>
  </svg>

  <!-- width/height y fill van como atributo: si el CSS no carga, los rayos
       quedan chicos y violeta en vez de gigantes y negros. -->
  <svg class="rayo r1" width="92"  height="184" viewBox="0 0 60 120"><path fill="#a855f7" d="M38 0 4 68h22L16 120 56 46H32L38 0Z"/></svg>
  <svg class="rayo r2" width="64"  height="128" viewBox="0 0 60 120"><path fill="#a855f7" d="M38 0 4 68h22L16 120 56 46H32L38 0Z"/></svg>
  <svg class="rayo r3" width="120" height="240" viewBox="0 0 60 120"><path fill="#a855f7" d="M38 0 4 68h22L16 120 56 46H32L38 0Z"/></svg>
  <svg class="rayo r4" width="74"  height="148" viewBox="0 0 60 120"><path fill="#a855f7" d="M38 0 4 68h22L16 120 56 46H32L38 0Z"/></svg>
  <svg class="rayo r5" width="54"  height="108" viewBox="0 0 60 120"><path fill="#a855f7" d="M38 0 4 68h22L16 120 56 46H32L38 0Z"/></svg>
  <svg class="rayo r6" width="68"  height="136" viewBox="0 0 60 120"><path fill="#a855f7" d="M38 0 4 68h22L16 120 56 46H32L38 0Z"/></svg>

  <span class="destello d1"></span>
  <span class="destello d2"></span>
  <span class="destello d3"></span>
  <span class="destello d4"></span>
  <span class="destello d5"></span>
  <span class="destello d6"></span>
  <span class="destello d7"></span>
  <span class="destello d8"></span>
</div>

<nav class="navbar navbar-expand-lg navbar-dark sticky-top">
  <div class="container">
    <a class="navbar-brand fw-bold" href="index.php">
      <!-- Logo: tres íconos = los tres pilares (energía, agua, reciclaje) + nombre -->
      <i class="bi bi-lightning-charge-fill" style="color:var(--pilar-energia)"></i><i class="bi bi-droplet-fill ms-1" style="color:var(--pilar-agua)"></i><i class="bi bi-recycle ms-1" style="color:var(--pilar-reciclaje)"></i>
      <span class="ms-2">Guardianes del Planeta</span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#menu">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="menu">
      <ul class="navbar-nav ms-auto align-items-lg-center">
        <li class="nav-item"><a class="nav-link" href="index.php"><i class="bi bi-speedometer2"></i> Panel</a></li>
        <li class="nav-item"><a class="nav-link" href="recibos_subir.php"><i class="bi bi-camera"></i> Escanear recibo</a></li>
        <li class="nav-item"><a class="nav-link" href="aparatos.php"><i class="bi bi-plug-fill"></i> Aparatos</a></li>
        <li class="nav-item"><a class="nav-link" href="agua.php"><i class="bi bi-droplet"></i> Agua</a></li>
        <li class="nav-item"><a class="nav-link" href="reciclaje.php"><i class="bi bi-recycle"></i> Reciclaje</a></li>
        <li class="nav-item"><a class="nav-link" href="comunidad.php"><i class="bi bi-people"></i> Comunidad</a></li>
        <li class="nav-item"><a class="nav-link" href="plan.php"><i class="bi bi-clipboard-check"></i> Plan</a></li>
        <li class="nav-item"><a class="nav-link" href="negocios.php"><i class="bi bi-shop"></i> Negocios</a></li>
        <li class="nav-item ms-lg-2">
          <a class="btn btn-warning btn-sm fw-semibold" href="recibos_subir.php">
            <i class="bi bi-camera-fill"></i> Fotografiar
          </a>
        </li>
      </ul>
    </div>
  </div>
</nav>

<main class="container py-4">
