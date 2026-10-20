<?php
$tituloPagina = 'Plan de ahorro';
require __DIR__ . '/includes/header.php';

$hogarId = (int) $hogar['id'];
$tarifa  = tarifaDe($hogar);
$ultimo  = ultimoRecibo($hogarId);

// Plan unificado (energía + agua + reciclaje) y su reto de 30 días
$stU = db()->prepare('SELECT * FROM planes_unificados WHERE hogar_id = ? ORDER BY id DESC LIMIT 1');
$stU->execute([$hogarId]);
$unificado = $stU->fetch() ?: null;
$accionesU = $unificado ? (json_decode((string) $unificado['acciones'], true) ?: []) : [];
$retoU     = $unificado ? (json_decode((string) $unificado['reto'], true) ?: []) : [];
$retoG     = retoGuardianes($hogarId);

$coloresPilar = ['energia' => 'var(--pilar-energia)', 'agua' => 'var(--pilar-agua)', 'reciclaje' => 'var(--pilar-reciclaje)'];
$iconosPilar  = ['energia' => 'bi-lightning-charge-fill', 'agua' => 'bi-droplet-fill', 'reciclaje' => 'bi-recycle'];
$etiqPilar    = ['energia' => 'Energía', 'agua' => 'Agua', 'reciclaje' => 'Reciclaje'];

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

<!-- ==================== PLAN UNIFICADO: LOS TRES PILARES ==================== -->
<div class="card card-tarifa mt-4 mb-4">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
      <h2 class="h5 mb-0"><i class="bi bi-shield-shaded"></i> Guardianes del Planeta · un solo plan</h2>
      <?php if ($unificado): ?>
        <span class="chip"><?= $unificado['origen'] === 'ia' ? 'redactado por la IA' : 'plan sin internet' ?></span>
      <?php endif; ?>
    </div>
    <p class="small text-secondary">
      Los tres pilares a la vez: energía, agua y reciclaje. La IA recibe solo números
      (sin nombres ni datos personales) y arma el plan con su reto de 30 días.
    </p>

    <div class="text-center mb-3">
      <button id="btnPlanUnificado" class="btn btn-warning fw-semibold">
        <i class="bi bi-stars"></i> <?= $unificado ? 'Generar un plan general nuevo' : 'Generar mi plan Guardianes con IA' ?>
      </button>
      <div id="estadoPlanUnificado" class="small text-secondary mt-2"></div>
    </div>

    <?php if ($unificado): ?>

      <p class="alert alert-light border"><?= e($unificado['resumen']) ?></p>

      <div class="row g-3 text-center mb-3">
        <div class="col-4">
          <div class="kpi" style="color:var(--pilar-energia)"><?= usd($unificado['ahorro_usd']) ?></div>
          <div class="small text-secondary">ahorro mensual estimado</div>
        </div>
        <div class="col-4">
          <div class="kpi" style="color:var(--pilar-agua)"><?= num($unificado['ahorro_litros'], 0) ?><small class="fs-6"> L</small></div>
          <div class="small text-secondary">agua ahorrada al mes</div>
        </div>
        <div class="col-4">
          <div class="kpi" style="color:var(--pilar-reciclaje)"><?= num($unificado['kg_meta'], 1) ?><small class="fs-6"> kg</small></div>
          <div class="small text-secondary">meta de reciclaje mensual</div>
        </div>
      </div>

      <div class="table-responsive mb-4">
        <table class="table align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Pilar</th><th>Acción</th>
              <th class="text-end">Costo</th><th class="text-end">Ahorro</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($accionesU as $a):
              $p = (string) ($a['pilar'] ?? 'energia');
              if (!isset($coloresPilar[$p])) $p = 'energia';
              $ahorroU = (float) ($a['ahorro_usd_mes'] ?? 0);
          ?>
            <tr>
              <td class="text-nowrap">
                <span class="chip" style="color:<?= $coloresPilar[$p] ?>;border-color:<?= $coloresPilar[$p] ?>;background:transparent">
                  <i class="bi <?= $iconosPilar[$p] ?>"></i> <?= e($etiqPilar[$p]) ?>
                </span>
              </td>
              <td>
                <strong><?= e($a['titulo'] ?? 'Acción') ?></strong>
                <div class="small text-secondary"><?= e($a['accion'] ?? '') ?></div>
                <?php if ((float) ($a['ahorro_litros_mes'] ?? 0) > 0): ?>
                  <div class="small" style="color:var(--pilar-agua)">
                    <i class="bi bi-droplet"></i> <?= num($a['ahorro_litros_mes'], 0) ?> litros al mes
                  </div>
                <?php endif; ?>
                <?php if ((float) ($a['kg_meta'] ?? 0) > 0): ?>
                  <div class="small" style="color:var(--pilar-reciclaje)">
                    <i class="bi bi-recycle"></i> <?= num($a['kg_meta'], 1) ?> kg por mes
                  </div>
                <?php endif; ?>
              </td>
              <td class="text-end"><?= ((float) ($a['costo_estimado_usd'] ?? 0)) > 0 ? usd($a['costo_estimado_usd']) : 'gratis' ?></td>
              <td class="text-end fw-semibold text-success"><?= usd($ahorroU) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Reto unificado -->
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <h3 class="h6 mb-0">
          <i class="bi bi-trophy-fill text-warning"></i>
          <?= e($retoU['titulo'] ?? 'Reto Guardianes del Planeta · 30 días') ?>
        </h3>
        <?php if ($retoG): ?>
          <span class="chip"><?= (int) $retoG['puntos'] ?> puntos</span>
        <?php endif; ?>
      </div>

      <?php if ($retoG):
          $pctRetoG = (int) min(100, round($retoG['dias_hechos'] / max(1, $retoG['meta_dias']) * 100));
          $metasG = $retoG['metas_lista'];
      ?>
        <div class="progress mb-2" style="height: 18px;">
          <div class="progress-bar" role="progressbar"
               style="width: <?= $pctRetoG ?>%; background: linear-gradient(90deg, var(--pilar-energia), var(--pilar-agua), var(--pilar-reciclaje))"
               aria-valuenow="<?= $pctRetoG ?>" aria-valuemin="0" aria-valuemax="100">
            <?= (int) $retoG['dias_hechos'] ?> / <?= (int) $retoG['meta_dias'] ?> días
          </div>
        </div>

        <p class="small text-secondary">
          Empezó el <?= date('d/m/Y', strtotime($retoG['inicio'])) ?>. Metas:
          <strong style="color:var(--pilar-energia)"><?= usd($metasG['usd'] ?? 0) ?></strong> de ahorro,
          <strong style="color:var(--pilar-agua)"><?= num($metasG['litros'] ?? 0, 0) ?> litros</strong> de agua
          y <strong style="color:var(--pilar-reciclaje)"><?= num($metasG['kg'] ?? 0, 1) ?> kg</strong> reciclados por mes.
        </p>

        <form id="formRetoGuardianes" class="mb-3">
          <button class="btn btn-outline-primary fw-semibold" <?= $pctRetoG >= 100 ? 'disabled' : '' ?>>
            <i class="bi bi-check2-circle"></i> Hoy cuidé los tres pilares · avanzar un día
          </button>
        </form>
        <div id="estadoReto" class="small mb-3"></div>

        <?php if ($retoG['insignias_lista']): ?>
          <h3 class="h6">Insignias ganadas</h3>
          <div class="d-flex flex-wrap gap-2 mb-3">
            <?php foreach ($retoG['insignias_lista'] as $ins): ?>
              <span class="chip"><i class="bi bi-trophy-fill text-warning"></i> <?= e($ins) ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <?php if (!empty($retoU['pasos'])): ?>
        <h3 class="h6">Cómo va el mes, semana por semana</h3>
        <ol class="list-unstyled mb-0">
          <?php foreach ($retoU['pasos'] as $i => $paso): ?>
            <li class="paso-reto ps-3 mb-2">
              <strong>Semana <?= $i + 1 ?>:</strong> <?= e(pasoSinPrefijo((string) $paso)) ?>
            </li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>

    <?php else: ?>
      <p class="text-secondary text-center mb-0">
        Todavía no hay plan general. Tocá el botón de arriba: la IA mira tus tres números
        (kWh, m³ y kilos reciclados) y arma un plan con metas de cada pilar.
      </p>
    <?php endif; ?>
  </div>
</div>

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

<script>
(function () {
  /* ---------- Plan unificado (tres pilares) ---------- */
  const btnU = document.getElementById('btnPlanUnificado');
  const estadoU = document.getElementById('estadoPlanUnificado');
  if (btnU) {
    btnU.addEventListener('click', async function () {
      btnU.disabled = true;
      estadoU.textContent = 'La IA está mirando tus tres pilares (kWh, m³ y kilos)…';
      try {
        const r = await fetch('api/generar_plan_unificado.php', { method: 'POST' });
        const res = await r.json();
        if (!res.ok) throw new Error(res.error || 'No se pudo generar el plan.');
        estadoU.textContent = res.aviso || 'Listo. Recargando…';
        setTimeout(function () { location.reload(); }, res.aviso ? 1800 : 600);
      } catch (err) {
        estadoU.innerHTML = '<span class="text-danger">' + err.message + '</span>';
        btnU.disabled = false;
      }
    });
  }

  /* ---------- Reto unificado ---------- */
  const formReto = document.getElementById('formRetoGuardianes');
  const estadoReto = document.getElementById('estadoReto');
  if (formReto) {
    formReto.addEventListener('submit', async function (ev) {
      ev.preventDefault();
      const btn = formReto.querySelector('button');
      if (btn) btn.disabled = true;
      try {
        const r = await fetch('api/avanzar_reto_guardianes.php', { method: 'POST' });
        const res = await r.json();
        if (!res.ok) throw new Error(res.error || 'No se pudo avanzar el reto.');
        let txt = 'Día ' + res.dias + ' de ' + res.meta_dias + ' · ' + res.puntos + ' puntos.';
        if (res.nuevas && res.nuevas.length) {
          txt += ' ¡Insignia nueva: ' + res.nuevas.join(', ') + '!';
        }
        estadoReto.innerHTML = '<i class="bi bi-trophy-fill text-warning"></i> ' + txt + ' Recargando…';
        setTimeout(function () { location.reload(); }, 1500);
      } catch (err) {
        estadoReto.innerHTML = '<span class="text-danger">' + err.message + '</span>';
        if (btn) btn.disabled = false;
      }
    });
  }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
