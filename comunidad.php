<?php
$tituloPagina = 'Comunidad';
require __DIR__ . '/includes/header.php';

/* ---------------- Nueva campaña (solo datos de la jornada) ---------------- */
$aviso = $_GET['ok'] ?? '';
$error = $_GET['error'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'agregar_campana') {
        $titulo = trim((string) ($_POST['titulo'] ?? ''));
        $descr  = trim((string) ($_POST['descripcion'] ?? ''));
        $fecha  = (string) ($_POST['fecha'] ?? '');
        $zona   = trim((string) ($_POST['zona'] ?? ''));

        if ($titulo === '' || $descr === '') {
            redir('comunidad.php?error=' . urlencode('La campaña necesita título y descripción.'));
        }
        if ($fecha !== '' && !strtotime($fecha)) $fecha = null;

        db()->prepare('INSERT INTO campanas (titulo, descripcion, fecha, zona) VALUES (?,?,?,?)')
            ->execute([
                mb_substr($titulo, 0, 120),
                $descr,
                $fecha,
                $zona !== '' ? $zona : null,
            ]);
        $aviso = 'Campaña publicada en el panel comunitario.';
    }

    if ($accion === 'borrar_campana') {
        db()->prepare('DELETE FROM campanas WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
        $aviso = 'Campaña eliminada.';
    }

    redir('comunidad.php' . ($aviso ? '?ok=' . urlencode($aviso) : ''));
}

/* ---------------- Datos agregados y anónimos ---------------- */
$comunidad = resumenComunidad();
$centros   = centrosAcopio();
$listaCampanas = campanas();

// Ahorros equivalente a energía: kg de CO2 del ahorro estimado de todos los planes.
$ahorroEnergia = (float) db()->query(
    'SELECT COALESCE(SUM(t.ahorro_total), 0) FROM (
        SELECT hogar_id, MAX(id) AS max_id FROM planes_ahorro GROUP BY hogar_id
     ) ult
     JOIN planes_ahorro t ON t.id = ult.max_id'
)->fetchColumn();
$tarifaRef = max(0.01, (float) cfg('tarifa_default', 0.21));
$co2Ahorrado = kgCo2($ahorroEnergia / $tarifaRef);

$maxKg = $comunidad['total_kg'] > 0 ? $comunidad['total_kg'] : 1;
?>

<header class="pagina-titulo mb-4">
  <h1 class="h2 mb-2"><i class="bi bi-people-fill" style="color:var(--pilar-reciclaje)"></i> Panel comunitario</h1>
  <p class="mb-0">
    Todo lo que ves acá son <strong>números agregados y anónimos</strong>: no hay alias,
    direcciones ni nombres de familias. Sirve para comparar y organizar jornadas de limpieza.
  </p>
</header>

<?php if ($aviso): ?>
  <div class="alert alert-success border-0 shadow-sm"><i class="bi bi-check-circle-fill"></i> <?= e($aviso) ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-danger border-0 shadow-sm"><i class="bi bi-exclamation-triangle-fill"></i> <?= e($error) ?></div>
<?php endif; ?>

<!-- KPIs comunitarios -->
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3">
    <div class="card card-tarifa h-100"><div class="card-body">
      <div class="etiqueta-ia text-secondary">Hogares y negocios</div>
      <div class="kpi"><?= (int) $comunidad['hogares'] ?></div>
      <div class="small text-secondary">registrados en la comunidad</div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card card-tarifa h-100"><div class="card-body">
      <div class="etiqueta-ia text-secondary">Material reciclado</div>
      <div class="kpi" style="color:var(--pilar-reciclaje)"><?= num($comunidad['total_kg'], 1) ?><small class="fs-6"> kg</small></div>
      <div class="small text-secondary"><?= (int) $comunidad['registros'] ?> residuos registrados</div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card card-tarifa h-100"><div class="card-body">
      <div class="etiqueta-ia text-secondary">Agua ahorrada</div>
      <div class="kpi" style="color:var(--pilar-agua)"><?= num($comunidad['ahorro_m3'], 1) ?><small class="fs-6"> m³</small></div>
      <div class="small text-secondary">según los planes de agua</div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card card-tarifa h-100"><div class="card-body">
      <div class="etiqueta-ia text-secondary">Dinero ahorrado</div>
      <div class="kpi" style="color:var(--pilar-energia)"><?= usd($comunidad['ahorro_usd']) ?></div>
      <div class="small text-secondary">energía + agua, por mes</div>
    </div></div>
  </div>
</div>

<!-- Equivalencias comunitarias -->
<div class="card card-tarifa mb-4">
  <div class="card-body">
    <h2 class="h6 mb-3">Si toda la comunidad lo mantiene, en un año se evita…</h2>
    <div class="row g-3 text-center">
      <div class="col-md-3 col-6">
        <div class="kpi" style="color:var(--pilar-reciclaje)"><?= num($comunidad['total_kg'] * 12, 0) ?> kg</div>
        <div class="small text-secondary">material desviado de la basura</div>
      </div>
      <div class="col-md-3 col-6">
        <div class="kpi" style="color:var(--pilar-reciclaje)"><?= num($co2Ahorrado * 12 + $comunidad['total_kg'] * 1.2, 0) ?> kg</div>
        <div class="small text-secondary">CO₂ evitado (aproximado)</div>
      </div>
      <div class="col-md-3 col-6">
        <div class="kpi" style="color:var(--pilar-agua)"><?= num($comunidad['ahorro_m3'] * 12, 0) ?> m³</div>
        <div class="small text-secondary">agua no consumida</div>
      </div>
      <div class="col-md-3 col-6">
        <div class="kpi" style="color:var(--pilar-energia)"><?= usd($comunidad['ahorro_usd'] * 12) ?></div>
        <div class="small text-secondary">ahorro anual estimado</div>
      </div>
    </div>
    <p class="small text-secondary mt-3 mb-0">
      Cifras aproximadas a partir de los factores de <code>config.php</code> y de los
      planes generados: sirven para comparar, no son una auditoría.
    </p>
  </div>
</div>

<div class="row g-4 mb-4">
  <!-- Materiales de la comunidad -->
  <div class="col-lg-6">
    <div class="card card-tarifa h-100">
      <div class="card-body">
        <h2 class="h6 mb-3">¿Qué recicla la comunidad?</h2>
        <?php if (!$comunidad['materiales']): ?>
          <p class="text-secondary mb-0">Aún no hay residuos registrados. <a href="reciclaje.php">Empezar en Reciclaje</a>.</p>
        <?php else: ?>
          <?php foreach ($comunidad['materiales'] as $m): ?>
            <div class="mb-3">
              <div class="d-flex justify-content-between">
                <strong><?= e($m['material']) ?></strong>
                <span class="text-secondary"><?= num($m['kg'], 1) ?> kg</span>
              </div>
              <div class="barra-fila mt-1">
                <span style="width:<?= (int) min(100, ((float) $m['kg'] / $maxKg) * 100) ?>%;background:var(--pilar-reciclaje)"></span>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Centros de acopio -->
  <div class="col-lg-6">
    <div class="card card-tarifa h-100">
      <div class="card-body">
        <h2 class="h6 mb-3">Puntos de acopio del directorio</h2>
        <?php if (!$centros): ?>
          <p class="text-secondary mb-0">Sin centros registrados todavía. <a href="reciclaje.php">Agregar desde Reciclaje</a>.</p>
        <?php else: ?>
          <?php foreach ($centros as $c): ?>
            <div class="border rounded p-3 mb-2">
              <strong><?= e($c['nombre']) ?></strong> <span class="chip ms-1"><?= e($c['zona']) ?></span>
              <div class="small text-secondary mt-1">
                <?= e($c['materiales_aceptados']) ?>
                <?php if ($c['horario']): ?> · <?= e($c['horario']) ?><?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
        <p class="small text-secondary mt-2 mb-0">
          <i class="bi bi-shield-check"></i> Solo datos públicos de lugares.
        </p>
      </div>
    </div>
  </div>
</div>

<!-- Campañas y jornadas -->
<div class="row g-4">
  <div class="col-lg-7">
    <div class="card card-tarifa h-100">
      <div class="card-body">
        <h2 class="h6 mb-3">Campañas y jornadas de limpieza</h2>
        <?php if (!$listaCampanas): ?>
          <p class="text-secondary mb-0">No hay campañas publicadas. La primera puede ser la tuya →</p>
        <?php else: ?>
          <?php foreach ($listaCampanas as $c): ?>
            <div class="border rounded p-3 mb-2">
              <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                <div>
                  <strong><?= e($c['titulo']) ?></strong>
                  <?php if ($c['fecha']): ?><span class="chip ms-2"><?= date('d/m/Y', strtotime($c['fecha'])) ?></span><?php endif; ?>
                  <?php if ($c['zona']): ?><span class="chip"><?= e($c['zona']) ?></span><?php endif; ?>
                </div>
                <form method="post" action="comunidad.php" class="m-0">
                  <input type="hidden" name="accion" value="borrar_campana">
                  <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                  <button class="btn btn-link btn-sm text-danger p-0"
                          onclick="return confirm('¿Eliminar la campaña?')">
                    <i class="bi bi-trash3"></i>
                  </button>
                </form>
              </div>
              <p class="small text-secondary mt-1 mb-0"><?= nl2br(e($c['descripcion'])) ?></p>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card card-tarifa h-100">
      <div class="card-body">
        <h2 class="h6 mb-3">Publicar una jornada</h2>
        <form method="post" action="comunidad.php" class="formulario-ia">
          <input type="hidden" name="accion" value="agregar_campana">
          <div class="mb-3">
            <label class="form-label">Título</label>
            <input class="form-control" name="titulo" placeholder="Ej. Jornada de limpieza del parque" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Descripción</label>
            <textarea class="form-control" name="descripcion" rows="3" placeholder="Dónde, qué se necesita llevar, a qué hora…" required></textarea>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-6">
              <label class="form-label">Fecha</label>
              <input type="date" class="form-control" name="fecha">
            </div>
            <div class="col-6">
              <label class="form-label">Zona</label>
              <input class="form-control" name="zona" placeholder="Ej. Zona 1">
            </div>
          </div>
          <button class="btn btn-primary w-100 fw-semibold"><i class="bi bi-megaphone"></i> Publicar</button>
          <p class="form-text mt-2 mb-0">
            No publiques nombres ni teléfonos de personas: solo lo de la jornada.
          </p>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
