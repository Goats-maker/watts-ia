<?php
$tituloPagina = 'Panel';
require __DIR__ . '/includes/header.php';

$hogarId   = (int) $hogar['id'];
$tarifa    = tarifaDe($hogar);
$historial = historial($hogarId);
$ultimo    = $historial ? $historial[array_key_last($historial)] : null;
$anomalia  = detectarAnomalia($historial);
$ranking   = rankingAparatos($hogarId, $tarifa, $ultimo ? (float) $ultimo['kwh'] : 0.0);
$comunidad = promedioComunidad();

$plan = db()->prepare('SELECT * FROM planes_ahorro WHERE hogar_id = ? ORDER BY id DESC LIMIT 1');
$plan->execute([$hogarId]);
$plan = $plan->fetch() ?: null;

// Meses que subieron más del 20 % respecto al anterior (para la alerta)
$alertasMes = [];
for ($i = 1; $i < count($historial); $i++) {
    $base = (float) $historial[$i - 1]['kwh'];
    if ($base > 0) {
        $suba = ((float) $historial[$i]['kwh'] - $base) / $base * 100;
        if ($suba > 20) {
            $alertasMes[] = ['periodo' => $historial[$i]['periodo'], 'pct' => $suba];
        }
    }
}

$datosGrafica = [
    'etiquetas' => array_column($historial, 'periodo'),
    'kwh'       => array_map(fn($r) => (float) $r['kwh'], $historial),
    'colores'   => array_map(function ($r, $i) use ($historial) {
        if ($i === 0) return '#2563eb';
        $base = (float) $historial[$i - 1]['kwh'];
        $pct  = $base > 0 ? ((float) $r['kwh'] - $base) / $base * 100 : 0;
        return $pct > 20 ? '#ef4444' : '#2563eb';
    }, $historial, array_keys($historial)),
];

$topAparato  = $ranking[0] ?? null;
$sumatoriaKwh = array_sum(array_column($ranking, 'kwh'));
?>

<?php if (isset($_GET['guardado'])): ?>
  <div class="alert alert-success border-0 shadow-sm">
    <i class="bi bi-check-circle-fill"></i> Recibo guardado. La imagen se descartó: solo quedaron los números.
  </div>
<?php endif; ?>

<?php if (!empty($_GET['aviso_cuadre'])): ?>
  <div class="alert alert-warning border-0 shadow-sm">
    <i class="bi bi-exclamation-triangle"></i> <?= e($_GET['aviso_cuadre']) ?>
    Revisá los números del recibo si algo no cuadra.
  </div>
<?php endif; ?>

<!-- Los tres pilares: energía, agua y reciclaje -->
<?php
$ultimoAgua = ultimoReciboAgua($hogarId);
$resumenMat = residuosPorMaterial($hogarId);
$kgTotal    = (float) $resumenMat['total_kg'];
?>
<div class="row g-3 mb-4">
  <div class="col-md-4">
    <a href="index.php" class="text-decoration-none">
      <div class="card card-tarifa h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <div class="etiqueta-ia text-secondary">Energía</div>
              <div class="kpi" style="color:var(--pilar-energia)"><?= $ultimo ? num($ultimo['kwh'], 1) : '—' ?><small class="fs-6"> kWh</small></div>
              <div class="small text-secondary"><?= $ultimo ? usd($ultimo['monto']) . ' este mes' : 'sin recibo todavía' ?></div>
            </div>
            <i class="bi bi-lightning-charge-fill fs-2" style="color:var(--pilar-energia)"></i>
          </div>
        </div>
      </div>
    </a>
  </div>
  <div class="col-md-4">
    <a href="agua.php" class="text-decoration-none">
      <div class="card card-tarifa h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <div class="etiqueta-ia text-secondary">Agua</div>
              <div class="kpi" style="color:var(--pilar-agua)"><?= $ultimoAgua ? num($ultimoAgua['m3'], 1) : '—' ?><small class="fs-6"> m³</small></div>
              <div class="small text-secondary"><?= $ultimoAgua ? usd($ultimoAgua['monto']) . ' este mes' : 'sin recibo todavía' ?></div>
            </div>
            <i class="bi bi-droplet-fill fs-2" style="color:var(--pilar-agua)"></i>
          </div>
        </div>
      </div>
    </a>
  </div>
  <div class="col-md-4">
    <a href="reciclaje.php" class="text-decoration-none">
      <div class="card card-tarifa h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <div class="etiqueta-ia text-secondary">Reciclaje</div>
              <div class="kpi" style="color:var(--pilar-reciclaje)"><?= num($kgTotal, 1) ?><small class="fs-6"> kg</small></div>
              <div class="small text-secondary"><?= count($resumenMat['materiales']) ?> materiales registrados</div>
            </div>
            <i class="bi bi-recycle fs-2" style="color:var(--pilar-reciclaje)"></i>
          </div>
        </div>
      </div>
    </a>
  </div>
</div>

<?php if (!$ultimo): ?>
  <header class="pagina-titulo text-center mb-5">
    <h1 class="h2 mb-2">Todavía no hay recibos</h1>
    <p class="mb-3">Empezá por fotografiar tu primer recibo de luz.</p>
    <a href="recibos_subir.php" class="btn btn-warning fw-semibold">
      <i class="bi bi-camera-fill"></i> Escanear recibo
    </a>
  </header>
<?php else: ?>

  <header class="pagina-titulo d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
    <div>
      <span class="chip"><?= e($hogar['alias']) ?> · <?= e($hogar['tipo']) ?></span>
      <h1 class="h2 mt-2 mb-1">Tu recibo de <?= e($ultimo['periodo']) ?> ya está explicado</h1>
      <p class="mb-0">
        Tarifa de <?= usd($tarifa) ?>/kWh · cargo fijo <?= usd($ultimo['cargo_fijo']) ?>.
        Último registro leído por <?= $ultimo['origen'] === 'ia' ? 'la IA' : 'ingreso manual' ?>.
      </p>
    </div>
    <a href="recibos_subir.php" class="btn btn-warning fw-semibold">
      <i class="bi bi-camera-fill"></i> Escanear otro recibo
    </a>
  </header>

  <?php if ($anomalia['hay']): ?>
    <div class="alerta-anomalia card card-tarifa mb-4">
      <div class="card-body">
        <h2 class="h6 mb-1"><i class="bi bi-exclamation-triangle-fill text-danger"></i>
          Algo pasó este mes</h2>
        <p class="mb-0">
          Tu consumo subió <strong><?= num($anomalia['pct']) ?>%</strong>
          respecto a <?= e($anomalia['anterior']['periodo']) ?>
          (de <?= num($anomalia['anterior']['kwh']) ?> a <?= num($anomalia['ultimo']['kwh']) ?> kWh).
          ¿Cambió algo en casa: visitas, calor, o algún aparato quedó encendido?
        </p>
      </div>
    </div>
  <?php endif; ?>

  <div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
      <div class="card card-tarifa h-100"><div class="card-body">
        <div class="etiqueta-ia text-secondary">Consumo</div>
        <div class="kpi"><?= num($ultimo['kwh']) ?><small class="fs-6"> kWh</small></div>
        <div class="small text-secondary">del período</div>
      </div></div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card card-tarifa h-100"><div class="card-body">
        <div class="etiqueta-ia text-secondary">A pagar</div>
        <div class="kpi"><?= usd($ultimo['monto']) ?></div>
        <div class="small text-secondary">incluye cargo fijo</div>
      </div></div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card card-tarifa h-100"><div class="card-body">
        <div class="etiqueta-ia text-secondary">Promedio comunidad</div>
        <div class="kpi"><?= num($comunidad['promedio'], 0) ?><small class="fs-6"> kWh</small></div>
        <div class="small text-secondary">
          <?php $dif = $comunidad['promedio'] > 0 ? ($ultimo['kwh'] - $comunidad['promedio']) / $comunidad['promedio'] * 100 : 0; ?>
          estás <strong><?= num(abs($dif), 0) ?>%</strong>
          <?= $dif >= 0 ? 'por encima' : 'por debajo' ?> del promedio de la comunidad
        </div>
      </div></div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card card-tarifa h-100"><div class="card-body">
        <div class="etiqueta-ia text-secondary">Plan de ahorro</div>
        <div class="kpi"><?= $plan ? usd($plan['ahorro_total']) : '—' ?></div>
        <div class="small text-secondary">
          <?= $plan ? 'ahorro mensual estimado' : 'aún sin generar' ?>
        </div>
      </div></div>
    </div>
  </div>

  <div class="row g-4 mb-4">
    <div class="col-lg-7">
      <div class="grafica-caja h-100">
        <h2 class="h6 mb-3">Consumo por mes</h2>
        <canvas id="graficaConsumo" height="220"></canvas>
        <?php if ($alertasMes): ?>
          <p class="small text-danger mt-2 mb-0">
            <i class="bi bi-exclamation-circle"></i>
            <?php foreach ($alertasMes as $a): ?>
              <?= e($a['periodo']) ?> subió <?= num($a['pct']) ?>% ·
            <?php endforeach; ?>
            avisamos cuando el aumento pasa del 20%.
          </p>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="card card-tarifa h-100">
        <div class="card-body">
          <h2 class="h6 mb-3">Tu hogar vs. la comunidad</h2>
          <?php foreach ($comunidad['hogares'] as $h): ?>
            <?php $pctBarra = $comunidad['promedio'] > 0 ? min(100, $h['kwh'] / max($comunidad['promedio'], 1) * 50) : 0; ?>
            <div class="mb-3">
              <div class="d-flex justify-content-between small">
                <span><?= e($h['nombre']) ?> <span class="text-secondary">(<?= e($h['tipo']) ?>)</span></span>
                <strong><?= num($h['kwh'], 0) ?> kWh</strong>
              </div>
              <div class="barra-fila mt-1">
                <span style="width:<?= (int) $pctBarra ?>%;background:<?= $h['kwh'] <= $comunidad['promedio'] ? '#22c55e' : '#f5b301' ?>"></span>
              </div>
            </div>
          <?php endforeach; ?>
          <p class="small text-secondary mb-0">
            Comparar con otros hogares ayuda a saber si tu consumo es normal en la comunidad.
          </p>
        </div>
      </div>
    </div>
  </div>

  <div class="card card-tarifa mb-4">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h6 mb-0">Historial de recibos</h2>
        <span class="chip">solo cifras · sin datos personales</span>
      </div>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Período</th><th class="text-end">kWh</th><th class="text-end">Cargo fijo</th>
              <th class="text-end">Monto</th><th class="text-end">Variación</th><th>Origen</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($historial as $i => $r): ?>
            <?php
              $var = null;
              if ($i > 0 && (float) $historial[$i - 1]['kwh'] > 0) {
                  $var = ((float) $r['kwh'] - (float) $historial[$i - 1]['kwh']) / (float) $historial[$i - 1]['kwh'] * 100;
              }
            ?>
            <tr>
              <td><?= e($r['periodo']) ?></td>
              <td class="text-end"><?= num($r['kwh']) ?></td>
              <td class="text-end"><?= usd($r['cargo_fijo']) ?></td>
              <td class="text-end fw-semibold"><?= usd($r['monto']) ?></td>
              <td class="text-end">
                <?php if ($var === null): ?>
                  <span class="text-secondary">—</span>
                <?php elseif ($var > 0): ?>
                  <span class="text-danger"><i class="bi bi-arrow-up"></i> <?= num($var) ?>%</span>
                <?php else: ?>
                  <span class="text-success"><i class="bi bi-arrow-down"></i> <?= num(abs($var)) ?>%</span>
                <?php endif; ?>
              </td>
              <td><span class="chip"><?= $r['origen'] === 'ia' ? 'IA' : 'manual' ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card card-tarifa">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h6 mb-0">Los que más te comen la luz</h2>
        <a href="aparatos.php" class="btn btn-outline-primary btn-sm">Ver aparatos</a>
      </div>

      <?php if (!$ranking): ?>
        <p class="text-secondary mb-0">Todavía no cargaste aparatos.
          <a href="aparatos.php">Agregá el primero</a>.</p>
      <?php else: ?>
        <?php foreach (array_slice($ranking, 0, 4) as $a): ?>
          <div class="mb-3">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <strong><?= e($a['nombre']) ?></strong>
                <span class="badge-sem semaforo-<?= $a['semaforo'] ?> ms-2">
                  <?= etiquetaSemaforo($a['semaforo']) ?>
                </span>
              </div>
              <div class="text-end">
                <strong><?= num($a['kwh']) ?> kWh</strong>
                <span class="text-secondary small"> · <?= usd($a['costo']) ?>/mes</span>
              </div>
            </div>
            <div class="barra-fila mt-1">
              <span style="width:<?= (int) min(100, $a['pct']) ?>%;
                background:<?= ['rojo' => '#ef4444', 'amarillo' => '#f5b301', 'verde' => '#22c55e'][$a['semaforo']] ?>"></span>
            </div>
            <div class="small text-secondary"><?= num($a['pct']) ?>% de tu factura</div>
          </div>
        <?php endforeach; ?>

        <?php if ($topAparato): ?>
          <p class="alert alert-light border mb-0">
            <i class="bi bi-lightbulb-fill text-warning"></i>
            Tu <strong><?= e($topAparato['nombre']) ?></strong> es el devorador de watts de la casa:
            son <strong><?= usd($topAparato['costo']) ?></strong> al mes, o sea
            <strong><?= num($topAparato['pct']) ?>%</strong> de tu factura.
          </p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <script type="application/json" id="datos-grafica"><?= json_encode($datosGrafica) ?></script>
<?php endif; ?>

<?php if ($historial): ?>
<script>
(function () {
  const datos = JSON.parse(document.getElementById('datos-grafica').textContent);
  const ctx = document.getElementById('graficaConsumo');
  if (!ctx) return;

  function dibujar() {
    if (window.Chart) {
      new Chart(ctx, {
        type: 'bar',
        data: {
          labels: datos.etiquetas,
          datasets: [{
            label: 'kWh por mes',
            data: datos.kwh,
            backgroundColor: datos.colores,
            borderRadius: 6
          }]
        },
        options: {
          plugins: { legend: { display: false } },
          scales: { y: { beginAtZero: true, title: { display: true, text: 'kWh' } } }
        }
      });
      return;
    }

    // Sin CDN (Plan B sin internet): mismas barras, en CSS puro.
    const max = Math.max.apply(null, datos.kwh);
    const caja = document.createElement('div');
    caja.className = 'd-flex align-items-end gap-2';
    caja.style.height = '200px';
    datos.kwh.forEach(function (v, i) {
      const col = document.createElement('div');
      col.className = 'flex-grow-1 text-center';
      col.innerHTML =
        '<div class="small text-secondary mb-1">' + v.toFixed(0) + '</div>' +
        '<div style="height:' + Math.round((v / max) * 150) + 'px;background:' +
          datos.colores[i] + ';border-radius:6px 6px 0 0"></div>' +
        '<div class="small text-secondary mt-1" style="font-size:.68rem">' +
          datos.etiquetas[i] + '</div>';
      caja.appendChild(col);
    });
    ctx.replaceWith(caja);
  }

  // Chart.js se carga en el footer: esperamos a que todo el documento esté listo.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', dibujar);
  } else {
    dibujar();
  }
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
