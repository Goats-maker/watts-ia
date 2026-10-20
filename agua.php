<?php
$tituloPagina = 'Agua';
require __DIR__ . '/includes/header.php';

$hogarId    = (int) $hogar['id'];
$personas   = personasDe($hogar);
$tarifaAgua = tarifaAguaDe($hogar);

/* ---------------- Acciones del CRUD de usos de agua ---------------- */
$aviso = $_GET['ok'] ?? '';
$error = $_GET['error'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'agregar' || $accion === 'editar') {
        $id       = (int) ($_POST['id'] ?? 0);
        $nombre   = trim((string) ($_POST['nombre'] ?? ''));
        $litros   = str_replace(',', '.', (string) ($_POST['litros_por_uso'] ?? ''));
        $veces    = str_replace(',', '.', (string) ($_POST['veces_dia'] ?? ''));
        $porPers  = isset($_POST['por_persona']) ? 1 : 0;
        $categoria = preg_replace('/[^a-z_]/', '', strtolower((string) ($_POST['categoria'] ?? 'otro')));

        if ($nombre === '' || !is_numeric($litros) || (float) $litros <= 0
            || !is_numeric($veces) || (float) $veces <= 0 || (float) $veces > 50) {
            redir('agua.php?error=' . urlencode('Revisá el nombre, los litros por uso (mayor que cero) y las veces al día (1 a 50).'));
        }

        if ($accion === 'editar' && $id > 0) {
            db()->prepare('UPDATE usos_agua SET nombre=?, litros_por_uso=?, veces_dia=?, por_persona=?, categoria=? WHERE id=? AND hogar_id=?')
                ->execute([$nombre, (float) $litros, (float) $veces, $porPers, $categoria ?: 'otro', $id, $hogarId]);
            $aviso = 'Uso de agua actualizado.';
        } else {
            db()->prepare('INSERT INTO usos_agua (hogar_id, nombre, litros_por_uso, veces_dia, por_persona, categoria) VALUES (?,?,?,?,?,?)')
                ->execute([$hogarId, $nombre, (float) $litros, (float) $veces, $porPers, $categoria ?: 'otro']);
            $aviso = 'Uso de agua agregado.';
        }
    }

    if ($accion === 'borrar') {
        db()->prepare('DELETE FROM usos_agua WHERE id=? AND hogar_id=?')
            ->execute([(int) ($_POST['id'] ?? 0), $hogarId]);
        $aviso = 'Uso de agua eliminado.';
    }

    redir('agua.php' . ($aviso ? '?ok=' . urlencode($aviso) : ''));
}

$editar = null;
if (isset($_GET['editar'])) {
    $st = db()->prepare('SELECT * FROM usos_agua WHERE id=? AND hogar_id=?');
    $st->execute([(int) $_GET['editar'], $hogarId]);
    $editar = $st->fetch() ?: null;
}

/* ---------------- Datos del módulo ---------------- */
$historial   = historialAgua($hogarId);
$ultimo      = $historial ? $historial[array_key_last($historial)] : null;
$anomalia    = detectarAnomalia($historial, 'm3');
$litrosDia   = litrosDiaTotales($hogarId, $personas);
$m3Estimado  = m3Mes($litrosDia);
$ranking     = rankingUsosAgua($hogarId, $personas, $tarifaAgua, $m3Estimado);
$costoTotal  = array_sum(array_column($ranking, 'costo'));

$stPlan = db()->prepare('SELECT * FROM planes_agua WHERE hogar_id = ? ORDER BY id DESC LIMIT 1');
$stPlan->execute([$hogarId]);
$plan = $stPlan->fetch() ?: null;

$acciones = $plan ? (json_decode((string) $plan['acciones'], true) ?: []) : [];
$reto     = $plan ? (json_decode((string) $plan['reto'], true) ?: []) : [];

$datosGrafica = [
    'etiquetas' => array_column($historial, 'periodo'),
    'm3'        => array_map(fn($r) => (float) $r['m3'], $historial),
    'colores'   => array_map(function ($r, $i) use ($historial) {
        if ($i === 0) return '#0ea5e9';
        $base = (float) $historial[$i - 1]['m3'];
        $pct  = $base > 0 ? ((float) $r['m3'] - $base) / $base * 100 : 0;
        return $pct > 20 ? '#ef4444' : '#0ea5e9';
    }, $historial, array_keys($historial)),
];

$categorias = ['higiene', 'lavado', 'cocina', 'exterior', 'limpieza', 'otro'];
$etiquetasCat = [
    'higiene' => 'Higiene personal', 'lavado' => 'Lavado de ropa', 'cocina' => 'Cocina',
    'exterior' => 'Exterior y jardín', 'limpieza' => 'Limpieza', 'otro' => 'Otro',
];
?>

<header class="pagina-titulo mb-4">
  <h1 class="h2 mb-2"><i class="bi bi-droplet-fill" style="color:var(--pilar-agua)"></i> Agua</h1>
  <p class="mb-3">
    El mismo flujo que la luz: fotografiá el recibo de agua, la IA lo lee y vos confirmás.
    Después sumá tus usos del agua y mirá quién se lleva más litros.
  </p>
  <a href="recibos_subir.php?servicio=agua" class="btn btn-warning fw-semibold">
    <i class="bi bi-camera-fill"></i> Escanear recibo de agua
  </a>
</header>

<?php if ($aviso): ?>
  <div class="alert alert-success border-0 shadow-sm"><i class="bi bi-check-circle-fill"></i> <?= e($aviso) ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-danger border-0 shadow-sm"><i class="bi bi-exclamation-triangle-fill"></i> <?= e($error) ?></div>
<?php endif; ?>

<!-- KPIs -->
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3">
    <div class="card card-tarifa h-100"><div class="card-body">
      <div class="etiqueta-ia text-secondary">Consumo</div>
      <div class="kpi"><?= $ultimo ? num($ultimo['m3'], 1) : '—' ?><small class="fs-6"> m³</small></div>
      <div class="small text-secondary"><?= $ultimo ? 'del período (' . e($ultimo['periodo']) . ')' : 'sin recibo todavía' ?></div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card card-tarifa h-100"><div class="card-body">
      <div class="etiqueta-ia text-secondary">A pagar</div>
      <div class="kpi"><?= $ultimo ? usd($ultimo['monto']) : '—' ?></div>
      <div class="small text-secondary">incluye cargo fijo y alcantarillado</div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card card-tarifa h-100"><div class="card-body">
      <div class="etiqueta-ia text-secondary">Según tus usos</div>
      <div class="kpi"><?= num($m3Estimado, 1) ?><small class="fs-6"> m³</small></div>
      <div class="small text-secondary"><?= num($litrosDia, 0) ?> litros al día en <?= $personas ?> personas</div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card card-tarifa h-100"><div class="card-body">
      <div class="etiqueta-ia text-secondary">Plan de agua</div>
      <div class="kpi"><?= $plan ? usd($plan['ahorro_usd']) : '—' ?></div>
      <div class="small text-secondary">
        <?= $plan ? num($plan['ahorro_litros'], 0) . ' litros menos por mes' : 'aún sin generar' ?>
      </div>
    </div></div>
  </div>
</div>

<?php if ($anomalia['hay']): ?>
  <div class="alerta-anomalia card card-tarifa mb-4">
    <div class="card-body">
      <h2 class="h6 mb-1"><i class="bi bi-exclamation-triangle-fill text-danger"></i> Algo pasó este mes</h2>
      <p class="mb-0">
        Tu consumo de agua subió <strong><?= num($anomalia['pct']) ?>%</strong>
        respecto a <?= e($anomalia['anterior']['periodo']) ?>
        (de <?= num($anomalia['anterior']['m3'], 1) ?> a <?= num($anomalia['ultimo']['m3'], 1) ?> m³).
        ¿Se regó algo de más, hubo visitas o alguna llave goteó?
      </p>
    </div>
  </div>
<?php endif; ?>

<!-- Gráfica + historial -->
<div class="row g-4 mb-4">
  <div class="col-lg-7">
    <div class="grafica-caja h-100">
      <h2 class="h6 mb-3">Consumo de agua por mes (m³)</h2>
      <?php if ($historial): ?>
        <canvas id="graficaAgua" height="220"></canvas>
        <script type="application/json" id="datos-grafica"><?= json_encode($datosGrafica) ?></script>
      <?php else: ?>
        <p class="text-secondary mb-0">
          Todavía no hay recibos de agua. <a href="recibos_subir.php?servicio=agua">Escanear el primero</a>.
        </p>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card card-tarifa h-100">
      <div class="card-body">
        <h2 class="h6 mb-3">Historial de recibos de agua</h2>
        <?php if (!$historial): ?>
          <p class="text-secondary mb-0">Sin recibos cargados todavía.</p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table align-middle mb-0">
              <thead class="table-light">
                <tr><th>Período</th><th class="text-end">m³</th><th class="text-end">Monto</th><th>Origen</th></tr>
              </thead>
              <tbody>
              <?php foreach ($historial as $r): ?>
                <tr>
                  <td><?= e($r['periodo']) ?></td>
                  <td class="text-end"><?= num($r['m3'], 1) ?></td>
                  <td class="text-end fw-semibold"><?= usd($r['monto']) ?></td>
                  <td><span class="chip"><?= $r['origen'] === 'ia' ? 'IA' : 'manual' ?></span></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Calculadora de usos del agua -->
<div class="row g-4 mb-4">
  <div class="col-lg-4">
    <div class="card card-tarifa">
      <div class="card-body">
        <h2 class="h6 mb-3"><?= $editar ? 'Editar uso' : 'Agregar uso de agua' ?></h2>
        <form method="post" action="agua.php" class="formulario-ia">
          <input type="hidden" name="accion" value="<?= $editar ? 'editar' : 'agregar' ?>">
          <?php if ($editar): ?><input type="hidden" name="id" value="<?= (int) $editar['id'] ?>"><?php endif; ?>

          <div class="mb-3">
            <label class="form-label">Nombre</label>
            <input class="form-control" name="nombre"
                   value="<?= e($editar['nombre'] ?? '') ?>" placeholder="Ej. Ducha" required>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-6">
              <label class="form-label">Litros por uso</label>
              <input type="number" step="0.1" min="0.1" class="form-control" name="litros_por_uso"
                     value="<?= e($editar['litros_por_uso'] ?? '60') ?>" required>
            </div>
            <div class="col-6">
              <label class="form-label">Veces al día</label>
              <input type="number" step="0.5" min="0.1" class="form-control" name="veces_dia"
                     value="<?= e($editar['veces_dia'] ?? '1') ?>" required>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Categoría</label>
            <select class="form-select" name="categoria">
              <?php foreach ($categorias as $c): ?>
                <option value="<?= $c ?>" <?= ($editar['categoria'] ?? '') === $c ? 'selected' : '' ?>>
                  <?= e($etiquetasCat[$c]) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="por_persona" id="porPersona"
                   <?= !empty($editar['por_persona']) ? 'checked' : '' ?>>
            <label class="form-check-label small" for="porPersona">
              Se multiplica por cada persona de la casa (<?= $personas ?>)
            </label>
          </div>
          <button class="btn btn-primary w-100 fw-semibold">
            <i class="bi bi-save"></i> <?= $editar ? 'Guardar cambios' : 'Agregar' ?>
          </button>
          <?php if ($editar): ?>
            <a href="agua.php" class="btn btn-outline-secondary w-100 mt-2">Cancelar</a>
          <?php endif; ?>
        </form>

        <hr>
        <p class="small text-secondary mb-0">
          <i class="bi bi-info-circle"></i> Litros/día = litros por uso × veces al día (× personas si aplica).
          m³/mes = litros/día × 30 ÷ 1000.
        </p>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card card-tarifa h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
          <h2 class="h6 mb-0">Devoradores de agua</h2>
          <span class="chip"><?= usd($costoTotal) ?>/mes en total · tarifa <?= usd($tarifaAgua) ?>/m³</span>
        </div>

        <?php if (!$ranking): ?>
          <p class="text-secondary mb-0">Todavía no cargaste usos de agua.
            <a href="agua.php">Agregá el primero</a> (ducha, inodoro, lavado…).</p>
        <?php else: ?>
          <?php foreach ($ranking as $u): ?>
            <div class="mb-3">
              <div class="d-flex justify-content-between align-items-center">
                <div>
                  <strong><?= e($u['nombre']) ?></strong>
                  <span class="badge-sem semaforo-<?= $u['semaforo'] ?> ms-2"><?= etiquetaSemaforo($u['semaforo']) ?></span>
                </div>
                <div class="text-end">
                  <strong><?= num($u['m3'], 1) ?> m³</strong>
                  <span class="text-secondary small"> · <?= usd($u['costo']) ?>/mes</span>
                </div>
              </div>
              <div class="barra-fila mt-1">
                <span style="width:<?= (int) min(100, $u['pct']) ?>%;
                  background:<?= ['rojo' => '#ef4444', 'amarillo' => '#f5b301', 'verde' => '#22c55e'][$u['semaforo']] ?>"></span>
              </div>
              <div class="small text-secondary">
                <?= num($u['litros_dia'], 0) ?> litros al día · <?= num($u['pct']) ?>% del consumo
                <?php if ($u['por_persona']): ?> · por persona<?php endif; ?>
                <form method="post" action="agua.php" class="d-inline ms-2">
                  <input type="hidden" name="accion" value="borrar">
                  <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                  <button class="btn btn-link btn-sm text-danger p-0 align-baseline"
                          onclick="return confirm('¿Eliminar este uso?')">
                    <i class="bi bi-trash3"></i>
                  </button>
                </form>
                <a href="agua.php?editar=<?= (int) $u['id'] ?>" class="btn btn-link btn-sm p-0 align-baseline">
                  <i class="bi bi-pencil"></i>
                </a>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Plan de agua -->
<div class="card card-tarifa mb-4">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
      <h2 class="h6 mb-0"><i class="bi bi-stars"></i> Plan de ahorro de agua</h2>
      <?php if ($plan): ?>
        <span class="chip"><?= $plan['origen'] === 'ia' ? 'redactado por la IA' : 'plan sin internet' ?></span>
      <?php endif; ?>
    </div>

    <div class="text-center mb-3">
      <button id="btnPlanAgua" class="btn btn-warning fw-semibold">
        <i class="bi bi-stars"></i> <?= $plan ? 'Generar un plan nuevo' : 'Generar mi plan con IA' ?>
      </button>
      <div id="estadoPlanAgua" class="small text-secondary mt-2"></div>
    </div>

    <?php if ($plan): ?>
      <p class="alert alert-light border"><?= e($plan['resumen']) ?></p>

      <div class="table-responsive mb-4">
        <table class="table align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Acción</th><th class="text-end">Costo</th>
              <th class="text-end">Ahorro (litros)</th><th class="text-end">Ahorro ($)</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($acciones as $a): ?>
            <tr>
              <td>
                <strong><?= e($a['titulo'] ?? 'Acción') ?></strong>
                <div class="small text-secondary"><?= e($a['accion'] ?? '') ?></div>
              </td>
              <td class="text-end"><?= ((float) ($a['costo_estimado_usd'] ?? 0)) > 0 ? usd($a['costo_estimado_usd']) : 'gratis' ?></td>
              <td class="text-end fw-semibold"><?= num($a['ahorro_litros_mes'] ?? 0, 0) ?> L</td>
              <td class="text-end fw-semibold text-success"><?= usd($a['ahorro_usd_mes'] ?? 0) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot class="table-light">
            <tr>
              <th>Total</th><th></th>
              <th class="text-end fw-bold"><?= num($plan['ahorro_litros'], 0) ?> L</th>
              <th class="text-end fw-bold"><?= usd($plan['ahorro_usd']) ?></th>
            </tr>
          </tfoot>
        </table>
      </div>

      <!-- Reto del agua -->
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <h3 class="h6 mb-0"><i class="bi bi-trophy-fill text-warning"></i> <?= e($reto['titulo'] ?? 'Reto del agua · 30 días') ?></h3>
        <span class="chip">meta: <?= num($reto['meta_litros'] ?? $plan['ahorro_litros'], 0) ?> litros/mes</span>
      </div>

      <?php $pctReto = (int) min(100, round($plan['dias_hechos'] / max(1, $plan['meta_dias']) * 100)); ?>
      <div class="progress mb-2" style="height: 18px;">
        <div class="progress-bar" role="progressbar" style="width: <?= $pctReto ?>%; background:var(--pilar-agua)"
             aria-valuenow="<?= $pctReto ?>" aria-valuemin="0" aria-valuemax="100">
          <?= (int) $plan['dias_hechos'] ?> / <?= (int) $plan['meta_dias'] ?> días
        </div>
      </div>
      <p class="small text-secondary mb-3">
        Vas por el <?= num($pctReto, 0) ?>% del reto. Completarlo equivale a
        <?= num(($reto['meta_litros'] ?? $plan['ahorro_litros']) / 2, 0) ?> botellas de 2 litros al mes.
      </p>

      <form method="post" action="api/avanzar_reto_agua.php" class="mb-2">
        <input type="hidden" name="id" value="<?= (int) $plan['id'] ?>">
        <button class="btn btn-outline-primary fw-semibold" <?= $pctReto >= 100 ? 'disabled' : '' ?>>
          <i class="bi bi-check2-circle"></i> Hoy cuidé el agua · avanzar un día
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($historial): ?>
<script>
(function () {
  const datos = JSON.parse(document.getElementById('datos-grafica').textContent);
  const ctx = document.getElementById('graficaAgua');
  if (!ctx) return;

  function dibujar() {
    if (window.Chart) {
      new Chart(ctx, {
        type: 'bar',
        data: {
          labels: datos.etiquetas,
          datasets: [{ label: 'm³ por mes', data: datos.m3, backgroundColor: datos.colores, borderRadius: 6 }]
        },
        options: {
          plugins: { legend: { display: false } },
          scales: { y: { beginAtZero: true, title: { display: true, text: 'm³' } } }
        }
      });
      return;
    }
    // Sin CDN (Plan B): mismas barras en CSS puro.
    const max = Math.max.apply(null, datos.m3);
    const caja = document.createElement('div');
    caja.className = 'd-flex align-items-end gap-2';
    caja.style.height = '200px';
    datos.m3.forEach(function (v, i) {
      const col = document.createElement('div');
      col.className = 'flex-grow-1 text-center';
      col.innerHTML =
        '<div class="small text-secondary mb-1">' + v.toFixed(1) + '</div>' +
        '<div style="height:' + Math.round((v / max) * 150) + 'px;background:' + datos.colores[i] + ';border-radius:6px 6px 0 0"></div>' +
        '<div class="small text-secondary mt-1" style="font-size:.68rem">' + datos.etiquetas[i] + '</div>';
      caja.appendChild(col);
    });
    ctx.replaceWith(caja);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', dibujar);
  } else {
    dibujar();
  }
})();
</script>
<?php endif; ?>

<script>
(function () {
  const btn = document.getElementById('btnPlanAgua');
  const estado = document.getElementById('estadoPlanAgua');
  if (!btn) return;

  btn.addEventListener('click', async function () {
    btn.disabled = true;
    estado.textContent = 'La IA está armando tu plan de agua… toma unos segundos.';
    try {
      const r = await fetch('api/generar_plan_agua.php', { method: 'POST' });
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
