<?php
$tituloPagina = 'Plan de ahorro';
require __DIR__ . '/includes/header.php';

$hogarId = (int) $hogar['id'];
$tarifa  = tarifaDe($hogar);
$ultimo  = ultimoRecibo($hogarId);

$st = db()->prepare('SELECT * FROM planes_ahorro WHERE hogar_id = ? ORDER BY id DESC LIMIT 1');
$st->execute([$hogarId]);
$plan = $st->fetch() ?: null;

$acciones = $plan ? (json_decode((string) $plan['acciones'], true) ?: []) : [];
$reto     = $plan ? (json_decode((string) $plan['reto'], true) ?: []) : [];
$ahorro   = $plan ? (float) $plan['ahorro_total'] : 0.0;
$ahorroAnual = $ahorro * 12;
$co2Anual   = kgCo2($ahorroAnual / max($tarifa, 0.01));
$ahorroPupusas = pupusas($ahorro);
$porcentaje   = (float) $ultimo['monto'] > 0 ? $ahorro / (float) $ultimo['monto'] * 100 : 0.0;
?>

<header class="pagina-titulo mb-4">
  <h1 class="h2 mb-2">Plan de ahorro<?= $plan ? '' : ' personalizado' ?></h1>
  <p class="mb-0">
    La IA arma el plan a partir de tu recibo y tus aparatos. Si no hay internet,
    se usan planes pre-generados por rango de consumo.
  </p>
</header>

<?php if (!$ultimo): ?>
  <div class="alert alert-warning">
    <i class="bi bi-info-circle"></i>
    Primero necesitamos un recibo. <a href="recibos_subir.php">Escanear recibo</a>.
  </div>
<?php else: ?>

  <div class="text-center mb-4">
    <button id="btnGenerar" class="btn btn-warning btn-lg fw-semibold">
      <i class="bi bi-stars"></i>
      <?= $plan ? 'Generar un plan nuevo' : 'Generar mi plan con IA' ?>
    </button>
    <div id="estadoPlan" class="small text-secondary mt-2"></div>
  </div>

  <?php if ($plan): ?>

    <?php if ($plan['origen'] === 'local'): ?>
      <div class="alert alert-info border-0 shadow-sm">
        <i class="bi bi-wifi-off"></i> <strong>Plan sin internet.</strong>
        Generado con las plantillas locales por rango de consumo.
      </div>
    <?php else: ?>
      <div class="alert alert-success border-0 shadow-sm">
        <i class="bi bi-stars"></i> <strong>Plan redactado por la IA</strong>
        a partir de tu recibo y tus aparatos. Revisalo antes de comprometerte: es una propuesta, no una orden.
      </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
      <div class="col-6 col-lg-3">
        <div class="card card-tarifa h-100"><div class="card-body">
          <div class="etiqueta-ia text-secondary">Ahorro mensual</div>
          <div class="kpi"><?= usd($ahorro) ?></div>
          <div class="small text-secondary"><?= num($porcentaje, 0) ?>% de tu factura actual</div>
        </div></div>
      </div>
      <div class="col-6 col-lg-3">
        <div class="card card-tarifa h-100"><div class="card-body">
          <div class="etiqueta-ia text-secondary">Ahorro al año</div>
          <div class="kpi"><?= usd($ahorroAnual) ?></div>
          <div class="small text-secondary">si sostenés el plan</div>
        </div></div>
      </div>
      <div class="col-6 col-lg-3">
        <div class="card card-tarifa h-100"><div class="card-body">
          <div class="etiqueta-ia text-secondary">Menos emisiones</div>
          <div class="kpi"><?= num($co2Anual, 0) ?><small class="fs-6"> kg CO₂</small></div>
          <div class="small text-secondary">al año</div>
        </div></div>
      </div>
      <div class="col-6 col-lg-3">
        <div class="card card-tarifa h-100"><div class="card-body">
          <div class="etiqueta-ia text-secondary">Equivalencia</div>
          <div class="kpi"><?= num($ahorroPupusas, 0) ?><small class="fs-6"> pupusas</small></div>
          <div class="small text-secondary">por mes en tu bolsillo</div>
        </div></div>
      </div>
    </div>

    <div class="card card-tarifa mb-4">
      <div class="card-body">
        <h2 class="h6 mb-3">Acciones concretas</h2>
        <p class="alert alert-light border"><?= e($plan['resumen']) ?></p>

        <div class="table-responsive">
          <table class="table align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Acción</th>
                <th class="text-end">Costo estimado</th>
                <th class="text-end">Ahorro mensual</th>
                <th class="text-end">Retorno</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($acciones as $a): ?>
              <?php
                $ahorroA = (float) ($a['ahorro_mensual_usd'] ?? 0);
                $costoA  = (float) ($a['costo_estimado_usd'] ?? 0);
                $retorno = $ahorroA > 0 ? $costoA / $ahorroA : 0;
              ?>
              <tr>
                <td>
                  <strong><?= e($a['titulo'] ?? 'Acción') ?></strong>
                  <div class="small text-secondary"><?= e($a['accion'] ?? '') ?></div>
                </td>
                <td class="text-end"><?= $costoA > 0 ? usd($costoA) : 'gratis' ?></td>
                <td class="text-end fw-semibold text-success"><?= usd($ahorroA) ?></td>
                <td class="text-end small">
                  <?= $retorno > 0 ? 'se paga en ' . num($retorno, 1) . ' mes' . ($retorno >= 2 ? 'es' : '') : '—' ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light">
              <tr>
                <th>Total</th><th></th>
                <th class="text-end fw-bold"><?= usd($ahorro) ?></th><th></th>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>

    <!-- Reto de 30 días -->
    <div class="card card-tarifa mb-4">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
          <h2 class="h6 mb-0"><i class="bi bi-trophy-fill text-warning"></i>
            <?= e($reto['titulo'] ?? 'Reto familiar de 30 días') ?></h2>
          <span class="chip">meta: <?= usd($reto['meta_usd'] ?? $ahorro) ?>/mes</span>
        </div>

        <?php $pctReto = (int) min(100, round($plan['dias_hechos'] / max(1, $plan['meta_dias']) * 100)); ?>
        <div class="progress mb-2" style="height: 18px;">
          <div class="progress-bar bg-warning" role="progressbar"
               style="width: <?= $pctReto ?>%"
               aria-valuenow="<?= $pctReto ?>" aria-valuemin="0" aria-valuemax="100">
            <?= (int) $plan['dias_hechos'] ?> / <?= (int) $plan['meta_dias'] ?> días
          </div>
        </div>
        <p class="small text-secondary">
          Vas por el <?= num($pctReto, 0) ?>% del reto. Si lo completás, el ahorro estimado es
          <strong><?= usd($ahorro) ?></strong> al mes (<?= num(kgCo2($ahorroAnual / max($tarifa, 0.01)), 0) ?> kg de CO₂ al año).
        </p>

        <form method="post" action="api/avanzar_reto.php" class="mb-4">
          <input type="hidden" name="id" value="<?= (int) $plan['id'] ?>">
          <button class="btn btn-outline-warning fw-semibold" <?= $pctReto >= 100 ? 'disabled' : '' ?>>
            <i class="bi bi-check2-circle"></i> Hoy cumplí · avanzar un día
          </button>
        </form>

        <?php if (!empty($reto['pasos'])): ?>
          <h3 class="h6">Plan por semanas</h3>
          <ol class="list-unstyled mb-0">
            <?php foreach ($reto['pasos'] as $i => $paso): ?>
              <li class="paso-reto ps-3 mb-2">
                <strong>Semana <?= $i + 1 ?>:</strong> <?= e(pasoSinPrefijo((string) $paso)) ?>
              </li>
            <?php endforeach; ?>
          </ol>
        <?php endif; ?>
      </div>
    </div>

    <p class="small text-secondary text-center">
      El plan se guarda solo con números y texto. Nada de nombre, dirección ni número de cliente.
    </p>

  <?php else: ?>
    <div class="card card-tarifa">
      <div class="card-body text-center py-5">
        <i class="bi bi-clipboard-check display-4 text-secondary"></i>
        <p class="mt-3 mb-0 text-secondary">
          Todavía no generás tu plan. Tocá el botón de arriba: la IA va a mirar tu recibo
          y tu inventario de aparatos, y va a proponer acciones con su costo y su ahorro.
        </p>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<script>
(function () {
  const btn    = document.getElementById('btnGenerar');
  const estado = document.getElementById('estadoPlan');
  if (!btn) return;

  btn.addEventListener('click', async function () {
    btn.disabled = true;
    estado.textContent = 'La IA está armando tu plan… esto toma unos segundos.';
    try {
      const r = await fetch('api/generar_plan.php', { method: 'POST' });
      const res = await r.json();
      if (!res.ok) throw new Error(res.error || 'No se pudo generar el plan.');
      estado.textContent = res.aviso || 'Listo. Recargando…';
      setTimeout(function () { location.reload(); }, res.aviso ? 1800 : 600);
    } catch (err) {
      estado.innerHTML = '<span class="text-danger">' + err.message + '</span>';
      btn.disabled = false;
    }
  });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
