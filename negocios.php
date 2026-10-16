<?php
$tituloPagina = 'Modo negocio';
require __DIR__ . '/includes/header.php';

/**
 * Modo negocio: mismo cálculo, otro hogar (pupusería, tienda, taller).
 * Se cambia de hogar con ?hogar=ID y se guardan los recibos/aparatos de ese negocio.
 */

// Elegimos el negocio por parámetro (?negocio=ID), validado contra la tabla hogares
$hogarSolicitado = isset($_GET['negocio']) ? (int) $_GET['negocio'] : 0;

$st = db()->prepare("SELECT * FROM hogares WHERE tipo = 'negocio' ORDER BY id");
$st->execute();
$negocios = $st->fetchAll();

$hogarId = $hogarSolicitado;
if (!$hogarId) {
    $hogarId = $negocios ? (int) $negocios[0]['id'] : (int) cfg('hogar_default');
}

$st = db()->prepare('SELECT * FROM hogares WHERE id = ?');
$st->execute([$hogarId]);
$negocio = $st->fetch() ?: hogarActual();

$tarifa   = tarifaDe($negocio);
$ultimo   = ultimoRecibo((int) $negocio['id']);
$kwhTotal = $ultimo ? (float) $ultimo['kwh'] : 0.0;
$ranking  = rankingAparatos((int) $negocio['id'], $tarifa, $kwhTotal);
$costoDia = array_sum(array_column($ranking, 'costo')) / 30;

// Plantillas rápidas típicas de un pequeño negocio
$plantillas = [
    ['nombre' => 'Nevera de exhibición', 'watts' => 400, 'horas_dia' => 24, 'categoria' => 'refrigeracion'],
    ['nombre' => 'Plancha / comal',      'watts' => 1500, 'horas_dia' => 4,  'categoria' => 'cocina'],
    ['nombre' => 'Licuadora',            'watts' => 600, 'horas_dia' => 1,  'categoria' => 'cocina'],
    ['nombre' => 'Luz del local (LED)',  'watts' => 120, 'horas_dia' => 10, 'categoria' => 'iluminacion'],
    ['nombre' => 'Ventilador de techo',  'watts' => 90,  'horas_dia' => 10, 'categoria' => 'clima'],
    ['nombre' => 'Equipo de sonido',     'watts' => 150, 'horas_dia' => 8,  'categoria' => 'entretenimiento'],
];
?>

<header class="pagina-titulo mb-4">
  <h1 class="h2 mb-2">Modo negocio</h1>
  <p class="mb-0">
    Para pupuserías, tiendas y talleres: los mismos cálculos, pero con los aparatos y horarios del negocio.
    Un punto menos de consumo al día se nota en el margen del mes.
  </p>
</header>

<div class="mb-4 d-flex flex-wrap gap-2">
  <?php foreach ($negocios as $n): ?>
    <a href="negocios.php?negocio=<?= (int) $n['id'] ?>"
       class="btn btn-sm <?= (int) $n['id'] === (int) $negocio['id'] ? 'btn-warning fw-semibold' : 'btn-outline-secondary' ?>">
      <?= e($n['alias']) ?>
    </a>
  <?php endforeach; ?>
  <a href="index.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-house"></i> Volver al hogar
  </a>
</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3">
    <div class="card card-tarifa h-100"><div class="card-body">
      <div class="etiqueta-ia text-secondary">Consumo</div>
      <div class="kpi"><?= $ultimo ? num($ultimo['kwh'], 0) : '—' ?><small class="fs-6"> kWh</small></div>
      <div class="small text-secondary">último recibo</div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card card-tarifa h-100"><div class="card-body">
      <div class="etiqueta-ia text-secondary">Factura</div>
      <div class="kpi"><?= $ultimo ? usd($ultimo['monto']) : '—' ?></div>
      <div class="small text-secondary">por mes</div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card card-tarifa h-100"><div class="card-body">
      <div class="etiqueta-ia text-secondary">Costo por día</div>
      <div class="kpi"><?= usd($costoDia) ?></div>
      <div class="small text-secondary">siempre abierto</div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card card-tarifa h-100"><div class="card-body">
      <div class="etiqueta-ia text-secondary">Aparatos</div>
      <div class="kpi"><?= count($ranking) ?></div>
      <div class="small text-secondary">en el inventario</div>
    </div></div>
  </div>
</div>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="card card-tarifa h-100">
      <div class="card-body">
        <h2 class="h6 mb-3">Ranking del negocio</h2>
        <?php if (!$ranking): ?>
          <p class="text-secondary mb-0">
            Este negocio todavía no tiene aparatos. Usá las plantillas de la derecha para cargarlos rápido.
          </p>
        <?php else: ?>
          <?php foreach ($ranking as $i => $a): ?>
            <div class="mb-3">
              <div class="d-flex justify-content-between">
                <div>
                  <span class="text-secondary"><?= $i + 1 ?>.</span> <strong><?= e($a['nombre']) ?></strong>
                  <span class="badge-sem semaforo-<?= $a['semaforo'] ?> ms-1"><?= etiquetaSemaforo($a['semaforo']) ?></span>
                </div>
                <div class="small text-end">
                  <strong><?= num($a['kwh']) ?> kWh</strong> · <?= usd($a['costo']) ?>/mes
                </div>
              </div>
              <div class="barra-fila mt-1">
                <span style="width:<?= (int) min(100, $a['pct'] ?: 10) ?>%;
                  background:<?= ['rojo' => '#ef4444', 'amarillo' => '#f5b301', 'verde' => '#22c55e'][$a['semaforo']] ?>"></span>
              </div>
              <div class="small text-secondary"><?= num($a['pct']) ?>% de la factura<?= $kwhTotal ? '' : ' (sin recibo cargado)' ?></div>
            </div>
          <?php endforeach; ?>

          <div class="alert alert-light border small mb-0">
            <i class="bi bi-lightbulb-fill text-warning"></i>
            Cambiando horarios o apagando lo que quede en standby se pueden ahorrar
            <strong><?= usd(array_sum(array_column($ranking, 'costo')) * 0.10) ?></strong> al mes
            (10% del inventario), sin comprar nada.
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card card-tarifa mb-4">
      <div class="card-body">
        <h2 class="h6 mb-3">Carga rápida (plantillas)</h2>
        <div class="d-grid gap-2">
          <?php foreach ($plantillas as $p): ?>
            <form method="post" action="api/agregar_plantilla.php" class="d-flex gap-2 align-items-center">
              <input type="hidden" name="hogar_id" value="<?= (int) $negocio['id'] ?>">
              <input type="hidden" name="nombre" value="<?= e($p['nombre']) ?>">
              <input type="hidden" name="watts" value="<?= (int) $p['watts'] ?>">
              <input type="hidden" name="horas_dia" value="<?= (float) $p['horas_dia'] ?>">
              <input type="hidden" name="categoria" value="<?= e($p['categoria']) ?>">
              <button class="btn btn-outline-primary btn-sm text-start flex-grow-1">
                <i class="bi bi-plus-circle"></i> <?= e($p['nombre']) ?>
                <span class="text-secondary small"> · <?= (int) $p['watts'] ?> W · <?= (float) $p['horas_dia'] ?> h/día</span>
              </button>
            </form>
          <?php endforeach; ?>
        </div>
        <p class="form-text mt-2 mb-0">
          ¿Falta algo? Cargalo en <a href="aparatos.php">Aparatos</a>.
        </p>
      </div>
    </div>

    <div class="card card-tarifa">
      <div class="card-body">
        <h2 class="h6 mb-3">Pasos del negocio</h2>
        <ol class="small ps-3 mb-0">
          <li>Foto del recibo del negocio → <a href="recibos_subir.php">escanear</a>.</li>
          <li>Cargá nevera, comal, luces y horarios.</li>
          <li>Mirá el ranking y apaguá lo que no se usa.</li>
          <li>Generá el <a href="plan.php">plan de ahorro</a> con metas en dólares.</li>
        </ol>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
