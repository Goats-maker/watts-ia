<?php
$tituloPagina = 'Reciclaje';
require __DIR__ . '/includes/header.php';

$hogarId = (int) $hogar['id'];

/* ---------------- Acciones del CRUD ---------------- */
$aviso = $_GET['ok'] ?? '';
$error = $_GET['error'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'agregar_residuo') {
        $material = trim((string) ($_POST['material'] ?? ''));
        $cantidad = str_replace(',', '.', (string) ($_POST['cantidad'] ?? ''));
        $unidad   = (string) ($_POST['unidad'] ?? 'unidad');
        $semana   = (string) ($_POST['semana'] ?? date('Y-m-d'));

        if ($material === '' || !is_numeric($cantidad) || (float) $cantidad <= 0 || !strtotime($semana)) {
            redir('reciclaje.php?error=' . urlencode('Revisá el material, la cantidad (mayor que cero) y la fecha.'));
        }
        if (!in_array($unidad, ['unidad', 'kg', 'litro'], true)) $unidad = 'unidad';

        $guia = guiaDe($material);
        if ($unidad === 'kg') {
            $kg = (float) $cantidad;
        } elseif ($guia && $guia['kg_aprox_por_unidad'] !== null) {
            $kg = round((float) $cantidad * (float) $guia['kg_aprox_por_unidad'], 2);
        } else {
            $kg = 0.0;
        }

        $ts = strtotime($semana);
        $lunes = date('Y-m-d', $ts - ((int) date('w', $ts) + 6) % 86400);

        db()->prepare(
            'INSERT INTO residuos (hogar_id, material, cantidad, unidad, kg_aprox, semana, origen)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([$hogarId, $material, (float) $cantidad, $unidad, $kg, $lunes, 'manual']);

        $aviso = 'Residuo registrado' . ($kg > 0 ? ' (≈ ' . num($kg, 1) . ' kg).' : '.');
    }

    if ($accion === 'borrar_residuo') {
        db()->prepare('DELETE FROM residuos WHERE id = ? AND hogar_id = ?')
            ->execute([(int) ($_POST['id'] ?? 0), $hogarId]);
        $aviso = 'Registro eliminado.';
    }

    if ($accion === 'agregar_centro') {
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $mats   = trim((string) ($_POST['materiales_aceptados'] ?? ''));
        $zona   = trim((string) ($_POST['zona'] ?? ''));
        $horario = trim((string) ($_POST['horario'] ?? ''));
        $contacto = trim((string) ($_POST['contacto_publico'] ?? ''));

        if ($nombre === '' || $mats === '' || $zona === '') {
            redir('reciclaje.php?error=' . urlencode('Para agregar un centro hace falta nombre, materiales y zona.'));
        }
        // Solo datos públicos de lugares: nada de personas.
        db()->prepare(
            'INSERT INTO centros_acopio (nombre, materiales_aceptados, zona, horario, contacto_publico)
             VALUES (?,?,?,?,?)'
        )->execute([
            $nombre, $mats, $zona,
            $horario !== '' ? $horario : null,
            $contacto !== '' ? $contacto : null,
        ]);
        $aviso = 'Centro de acopio agregado al directorio.';
    }

    redir('reciclaje.php' . ($aviso ? '?ok=' . urlencode($aviso) : ''));
}

/* ---------------- Datos ---------------- */
$residuos    = listaResiduos($hogarId);
$porSemana   = residuosPorSemana($hogarId, 8);
$porMaterial = residuosPorMaterial($hogarId);
$materiales  = $porMaterial['materiales'];
$totalKg     = (float) $porMaterial['total_kg'];
$equiv       = equivalenciasReciclaje($materiales);
$guias       = guiasReciclaje();
$centros     = centrosAcopio();
$ia          = new GeminiClient();

$stPlan = db()->prepare('SELECT * FROM planes_reciclaje WHERE hogar_id = ? ORDER BY id DESC LIMIT 1');
$stPlan->execute([$hogarId]);
$plan = $stPlan->fetch() ?: null;
$accionesPlan = $plan ? (json_decode((string) $plan['acciones'], true) ?: []) : [];

$datosGrafica = [
    'etiquetas' => array_map(fn($f) => date('d/m', strtotime($f['semana'])), $porSemana),
    'kg'        => array_map(fn($f) => (float) $f['kg'], $porSemana),
];

$semanaHoy = date('Y-m-d');
?>

<header class="pagina-titulo mb-4">
  <h1 class="h2 mb-2"><i class="bi bi-recycle" style="color:var(--pilar-reciclaje)"></i> Reciclaje</h1>
  <p class="mb-0">
    Sacale una foto a un residuo: la IA dice qué es, cómo prepararlo y qué ideas de
    reutilización tiene. Vos confirmás o corregís, y recién ahí se guarda.
    Sin internet, tenés el catálogo local de guías.
  </p>
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
      <div class="etiqueta-ia text-secondary">Registrado</div>
      <div class="kpi"><?= num($totalKg, 1) ?><small class="fs-6"> kg</small></div>
      <div class="small text-secondary"><?= count($residuos) ?> registros</div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card card-tarifa h-100"><div class="card-body">
      <div class="etiqueta-ia text-secondary">CO₂ evitado</div>
      <div class="kpi"><?= num($equiv['co2_kg'], 1) ?><small class="fs-6"> kg</small></div>
      <div class="small text-secondary">cálculo aproximado</div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card card-tarifa h-100"><div class="card-body">
      <div class="etiqueta-ia text-secondary">Valor potencial</div>
      <div class="kpi"><?= usd($equiv['valor_usd']) ?></div>
      <div class="small text-secondary">estimación si se vende</div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card card-tarifa h-100"><div class="card-body">
      <div class="etiqueta-ia text-secondary">Plan del mes</div>
      <div class="kpi"><?= $plan ? num($plan['meta_kg'], 1) : '—' ?><small class="fs-6"> kg</small></div>
      <div class="small text-secondary"><?= $plan ? ($plan['origen'] === 'ia' ? 'meta con IA' : 'meta sin internet') : 'sin plan todavía' ?></div>
    </div></div>
  </div>
</div>

<div class="row g-4 mb-4">
  <!-- Clasificar con IA -->
  <div class="col-lg-6">
    <div class="card card-tarifa h-100">
      <div class="card-body">
        <h2 class="h6">1 · Clasificar un residuo con la IA</h2>
        <input type="file" id="inputResiduo" accept="image/*" capture="environment" class="form-control mb-3">
        <img id="vistaResiduo" class="preview-recibo w-100 d-none" alt="Vista previa del residuo">
        <button id="btnClasificar" class="btn btn-warning w-100 fw-semibold" disabled>
          <i class="bi bi-stars"></i> Clasificar con la IA
        </button>
        <div id="estadoResiduo" class="small text-secondary mt-2"></div>

        <hr>
        <h3 class="h6">O usá el catálogo local (sin internet)</h3>
        <div class="input-group">
          <select id="selectGuia" class="form-select">
            <?php foreach ($guias as $g): ?>
              <option value="<?= e($g['material']) ?>"><?= e($g['material']) ?></option>
            <?php endforeach; ?>
          </select>
          <button id="btnGuia" class="btn btn-outline-primary fw-semibold">Usar guía</button>
        </div>

        <hr>
        <p class="small text-secondary mb-0">
          <i class="bi bi-shield-check"></i> La foto se descarta al terminar la lectura:
          nunca se guarda. La clasificación no se guarda hasta que vos la confirmás.
        </p>
      </div>
    </div>
  </div>

  <!-- Resultado editable -->
  <div class="col-lg-6">
    <div class="card card-tarifa h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h2 class="h6 mb-0">2 · Revisá y confirmá</h2>
          <span id="chipOrigenResiduo" class="chip">sin leer</span>
        </div>

        <div id="resultadoVacio" class="text-secondary small">
          Todavía no hay una clasificación. Tomá una foto o elegí un material del catálogo:
          el resultado aparece acá para que lo revises antes de guardarlo.
        </div>

        <form id="formResiduo" class="d-none">
          <div class="mb-2">
            <label class="form-label small mb-1">Material</label>
            <input class="form-control" id="rMaterial" required>
          </div>

          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label small mb-1">¿Reciclable?</label>
              <select class="form-select" id="rReciclable">
                <option value="si">Sí</option>
                <option value="no">No</option>
                <option value="depende">Depende</option>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label small mb-1">Plástico (si se ve)</label>
              <input class="form-control" id="rPlastico" placeholder="PET, HDPE…">
            </div>
          </div>

          <div class="mb-2">
            <label class="form-label small mb-1">Cómo prepararlo</label>
            <ul id="rPasos" class="small ps-4 mb-2"></ul>
          </div>

          <div class="mb-2">
            <label class="form-label small mb-1">Ideas de reutilización</label>
            <ul id="rIdeas" class="small ps-4 mb-2"></ul>
          </div>

          <div class="mb-2">
            <label class="form-label small mb-1">Dónde llevarlo</label>
            <input class="form-control" id="rDestino">
          </div>

          <div id="rNotas" class="alert alert-light border small d-none"></div>

          <div class="row g-2 mb-3">
            <div class="col-4">
              <label class="form-label small mb-1">Cantidad</label>
              <input type="number" step="0.1" min="0.1" class="form-control" id="rCantidad" value="1" required>
            </div>
            <div class="col-4">
              <label class="form-label small mb-1">Unidad</label>
              <select class="form-select" id="rUnidad">
                <option value="unidad">unidad</option>
                <option value="kg">kg</option>
                <option value="litro">litro</option>
              </select>
            </div>
            <div class="col-4">
              <label class="form-label small mb-1">Semana</label>
              <input type="date" class="form-control" id="rSemana" value="<?= $semanaHoy ?>">
            </div>
          </div>

          <button class="btn btn-primary w-100 fw-semibold">
            <i class="bi bi-save"></i> Confirmar y guardar residuo
          </button>
          <p class="form-text text-center mt-2 mb-0">
            Podés corregir el material o la cantidad: se guarda lo que vos dejes escrito.
          </p>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Gráfica + ranking de materiales -->
<div class="row g-4 mb-4">
  <div class="col-lg-7">
    <div class="grafica-caja h-100">
      <h2 class="h6 mb-3">Kilos reciclados por semana</h2>
      <?php if ($porSemana): ?>
        <canvas id="graficaReciclaje" height="220"></canvas>
        <script type="application/json" id="datos-grafica"><?= json_encode($datosGrafica) ?></script>
      <?php else: ?>
        <p class="text-secondary mb-0">Todavía no hay residuos registrados: empezá arriba con la foto o la guía local.</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card card-tarifa h-100">
      <div class="card-body">
        <h2 class="h6 mb-3">Más frecuentes en tu casa</h2>
        <?php if (!$materiales): ?>
          <p class="text-secondary mb-0">Sin registros todavía.</p>
        <?php else: ?>
          <?php foreach ($materiales as $m): ?>
            <div class="mb-3">
              <div class="d-flex justify-content-between">
                <strong><?= e($m['material']) ?></strong>
                <span class="text-secondary"><?= num($m['kg'], 1) ?> kg · <?= num($m['pct'], 0) ?>%</span>
              </div>
              <div class="barra-fila mt-1">
                <span style="width:<?= (int) min(100, $m['pct']) ?>%;background:var(--pilar-reciclaje)"></span>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Registro manual + historial -->
<div class="row g-4 mb-4">
  <div class="col-lg-4">
    <div class="card card-tarifa">
      <div class="card-body">
        <h2 class="h6 mb-3">Registrar residuo a mano</h2>
        <form method="post" action="reciclaje.php" class="formulario-ia">
          <input type="hidden" name="accion" value="agregar_residuo">
          <div class="mb-3">
            <label class="form-label">Material</label>
            <input class="form-control" name="material" list="listaMateriales" placeholder="Ej. Botella PET" required>
            <datalist id="listaMateriales">
              <?php foreach ($guias as $g): ?><option value="<?= e($g['material']) ?>"></option><?php endforeach; ?>
            </datalist>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-6">
              <label class="form-label">Cantidad</label>
              <input type="number" step="0.1" min="0.1" class="form-control" name="cantidad" value="1" required>
            </div>
            <div class="col-6">
              <label class="form-label">Unidad</label>
              <select class="form-select" name="unidad">
                <option value="unidad">unidad</option>
                <option value="kg">kg</option>
                <option value="litro">litro</option>
              </select>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Semana</label>
            <input type="date" class="form-control" name="semana" value="<?= $semanaHoy ?>">
          </div>
          <button class="btn btn-primary w-100 fw-semibold"><i class="bi bi-plus-circle"></i> Registrar</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card card-tarifa h-100">
      <div class="card-body">
        <h2 class="h6 mb-3">Historial de residuos</h2>
        <?php if (!$residuos): ?>
          <p class="text-secondary mb-0">Sin registros todavía.</p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table align-middle mb-0">
              <thead class="table-light">
                <tr><th>Semana</th><th>Material</th><th class="text-end">Cant.</th><th class="text-end">Kg</th><th>Origen</th><th></th></tr>
              </thead>
              <tbody>
              <?php foreach ($residuos as $r): ?>
                <tr>
                  <td class="small"><?= date('d/m', strtotime($r['semana'])) ?></td>
                  <td>
                    <?= e($r['material']) ?>
                    <?php if ($r['reciclable'] === 'si'): ?><span class="badge-sem semaforo-verde ms-1">Reciclable</span>
                    <?php elseif ($r['reciclable'] === 'no'): ?><span class="badge-sem semaforo-rojo ms-1">No común</span>
                    <?php else: ?><span class="badge-sem semaforo-amarillo ms-1">Depende</span><?php endif; ?>
                  </td>
                  <td class="text-end"><?= num($r['cantidad'], 1) ?> <?= e($r['unidad']) ?></td>
                  <td class="text-end fw-semibold"><?= num($r['kg_aprox'], 1) ?></td>
                  <td><span class="chip"><?= $r['origen'] === 'ia' ? 'IA' : 'manual' ?></span></td>
                  <td class="text-end">
                    <form method="post" action="reciclaje.php" class="d-inline">
                      <input type="hidden" name="accion" value="borrar_residuo">
                      <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                      <button class="btn btn-link btn-sm text-danger p-0"
                              onclick="return confirm('¿Eliminar este registro?')">
                        <i class="bi bi-trash3"></i>
                      </button>
                    </form>
                  </td>
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

<!-- Plan de reciclaje -->
<div class="card card-tarifa mb-4">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
      <h2 class="h6 mb-0"><i class="bi bi-stars"></i> Plan de reciclaje · 30 días</h2>
      <?php if ($plan): ?>
        <span class="chip"><?= $plan['origen'] === 'ia' ? 'redactado por la IA' : 'plan sin internet' ?></span>
      <?php endif; ?>
    </div>

    <div class="text-center mb-3">
      <button id="btnPlanReciclaje" class="btn btn-warning fw-semibold">
        <i class="bi bi-stars"></i> <?= $plan ? 'Generar un plan nuevo' : 'Generar mi plan con IA' ?>
      </button>
      <div id="estadoPlanReciclaje" class="small text-secondary mt-2"></div>
    </div>

    <?php if ($plan): ?>
      <p class="alert alert-light border"><?= e($plan['resumen']) ?></p>

      <div class="table-responsive mb-3">
        <table class="table align-middle mb-0">
          <thead class="table-light">
            <tr><th>Acción</th><th class="text-end">Meta</th><th class="text-end">Valor estimado</th></tr>
          </thead>
          <tbody>
          <?php foreach ($accionesPlan as $a): ?>
            <tr>
              <td>
                <strong><?= e($a['titulo'] ?? 'Acción') ?></strong>
                <div class="small text-secondary"><?= e($a['accion'] ?? '') ?></div>
              </td>
              <td class="text-end fw-semibold"><?= num($a['kg_meta'] ?? 0, 1) ?> kg</td>
              <td class="text-end"><?= ((float) ($a['valor_estimado_usd'] ?? 0)) > 0 ? usd($a['valor_estimado_usd']) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot class="table-light">
            <tr>
              <th>Meta del mes</th>
              <th class="text-end fw-bold"><?= num($plan['meta_kg'], 1) ?> kg</th>
              <th class="text-end fw-bold"><?= usd($plan['ingreso_estimado'] ?? 0) ?></th>
            </tr>
          </tfoot>
        </table>
      </div>
      <p class="small text-secondary mb-0">
        <i class="bi bi-info-circle"></i> El valor de la venta de material es siempre
        una <strong>estimación</strong>: cambia según el centro de acopio y la zona.
      </p>
    <?php endif; ?>
  </div>
</div>

<!-- Equivalencias -->
<div class="card card-tarifa mb-4">
  <div class="card-body">
    <h2 class="h6 mb-3"><i class="bi bi-globe-americas"></i> Qué se evitó con lo que reciclaste</h2>
    <div class="row g-3 text-center">
      <div class="col-md-4">
        <div class="kpi" style="color:var(--pilar-reciclaje)"><?= num($equiv['co2_kg'], 1) ?> kg</div>
        <div class="small text-secondary">CO₂ evitado (aproximado)</div>
      </div>
      <div class="col-md-4">
        <div class="kpi"><?= num($totalKg, 1) ?> kg</div>
        <div class="small text-secondary">material desviado de la basura</div>
      </div>
      <div class="col-md-4">
        <div class="kpi"><?= usd($equiv['valor_usd']) ?></div>
        <div class="small text-secondary">valor potencial (estimación)</div>
      </div>
    </div>
    <p class="small text-secondary mt-3 mb-0">
      Los factores de CO₂ y de valor por kilo son aproximados y los podés ajustar
      en <code>config.php</code>. No son cifras certificadas.
    </p>
  </div>
</div>

<!-- Directorio de centros de acopio -->
<div class="row g-4">
  <div class="col-lg-7">
    <div class="card card-tarifa h-100">
      <div class="card-body">
        <h2 class="h6 mb-3">Centros de acopio de la zona</h2>
        <?php if (!$centros): ?>
          <p class="text-secondary mb-0">Todavía no hay centros registrados. Agregá los de tu comunidad acá a la derecha.</p>
        <?php else: ?>
          <?php foreach ($centros as $c): ?>
            <div class="border rounded p-3 mb-2">
              <strong><?= e($c['nombre']) ?></strong>
              <span class="chip ms-2"><?= e($c['zona']) ?></span>
              <div class="small text-secondary mt-1">
                <i class="bi bi-box-seam"></i> <?= e($c['materiales_aceptados']) ?><br>
                <?php if ($c['horario']): ?><i class="bi bi-clock"></i> <?= e($c['horario']) ?><br><?php endif; ?>
                <?php if ($c['contacto_publico']): ?><i class="bi bi-geo-alt"></i> <?= e($c['contacto_publico']) ?><br><?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
        <p class="small text-secondary mt-2 mb-0">
          <i class="bi bi-shield-check"></i> El directorio solo guarda datos públicos
          del lugar (nada de personas). <a href="comunidad.php">Ver panel comunitario →</a>
        </p>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card card-tarifa h-100">
      <div class="card-body">
        <h2 class="h6 mb-3">Agregar un centro (líderes comunitarios)</h2>
        <form method="post" action="reciclaje.php" class="formulario-ia">
          <input type="hidden" name="accion" value="agregar_centro">
          <div class="mb-3">
            <label class="form-label">Nombre del lugar</label>
            <input class="form-control" name="nombre" placeholder="Ej. Patio de acopio del parque" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Materiales que acepta</label>
            <input class="form-control" name="materiales_aceptados" placeholder="Plásticos, papel, metales" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Zona</label>
            <input class="form-control" name="zona" placeholder="Ej. Colonia Escalón" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Horario (opcional)</label>
            <input class="form-control" name="horario" placeholder="Lun a Vie 8:00–16:00">
          </div>
          <div class="mb-3">
            <label class="form-label">Referencia pública (opcional)</label>
            <input class="form-control" name="contacto_publico" placeholder="Junto al parque, entrada norte">
            <div class="form-text">Solo datos del lugar: nunca nombres ni teléfonos de personas.</div>
          </div>
          <button class="btn btn-primary w-100 fw-semibold"><i class="bi bi-plus-circle"></i> Agregar al directorio</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php if ($porSemana): ?>
<script>
(function () {
  const datos = JSON.parse(document.getElementById('datos-grafica').textContent);
  const ctx = document.getElementById('graficaReciclaje');
  if (!ctx) return;

  function dibujar() {
    if (window.Chart) {
      new Chart(ctx, {
        type: 'bar',
        data: {
          labels: datos.etiquetas,
          datasets: [{ label: 'kg reciclados', data: datos.kg, backgroundColor: '#16a34a', borderRadius: 6 }]
        },
        options: {
          plugins: { legend: { display: false } },
          scales: { y: { beginAtZero: true, title: { display: true, text: 'kg' } } }
        }
      });
      return;
    }
    // Sin CDN (Plan B): mismas barras en CSS.
    const max = Math.max.apply(null, datos.kg) || 1;
    const caja = document.createElement('div');
    caja.className = 'd-flex align-items-end gap-2';
    caja.style.height = '200px';
    datos.kg.forEach(function (v, i) {
      const col = document.createElement('div');
      col.className = 'flex-grow-1 text-center';
      col.innerHTML =
        '<div class="small text-secondary mb-1">' + v.toFixed(1) + '</div>' +
        '<div style="height:' + Math.round((v / max) * 150) + 'px;background:#16a34a;border-radius:6px 6px 0 0"></div>' +
        '<div class="small text-secondary mt-1" style="font-size:.68rem">' + datos.etiquetas[i] + '</div>';
      caja.appendChild(col);
    });
    ctx.replaceWith(caja);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', dibujar);
  } else { dibujar(); }
})();
</script>
<?php endif; ?>

<script>
(function () {
  /* ---------- Clasificación: foto → IA o catálogo local ---------- */
  const input = document.getElementById('inputResiduo');
  const vista = document.getElementById('vistaResiduo');
  const btnIA = document.getElementById('btnClasificar');
  const estado = document.getElementById('estadoResiduo');
  const chip = document.getElementById('chipOrigenResiduo');
  const form = document.getElementById('formResiduo');
  const vacio = document.getElementById('resultadoVacio');
  const btnGuia = document.getElementById('btnGuia');
  const selectGuia = document.getElementById('selectGuia');

  let dataURL = null;
  let origenActual = 'manual';

  input.addEventListener('change', function () {
    const archivo = this.files && this.files[0];
    if (!archivo) return;
    const lector = new FileReader();
    lector.onload = function (ev) {
      dataURL = ev.target.result;
      vista.src = dataURL;
      vista.classList.remove('d-none');
      btnIA.disabled = false;
      estado.textContent = 'Foto lista. Tocá "Clasificar con la IA".';
    };
    lector.readAsDataURL(archivo);
  });

  function pintar(d, origen) {
    document.getElementById('rMaterial').value = d.material || '';
    document.getElementById('rReciclable').value = ['si', 'no', 'depende'].includes(d.reciclable) ? d.reciclable : 'depende';
    document.getElementById('rPlastico').value = d.tipo_plastico || '';
    document.getElementById('rDestino').value = d.destino_sugerido || '';

    const ulPasos = document.getElementById('rPasos');
    ulPasos.innerHTML = '';
    (d.preparacion || []).forEach(function (p) {
      const li = document.createElement('li'); li.textContent = p; ulPasos.appendChild(li);
    });

    const ulIdeas = document.getElementById('rIdeas');
    ulIdeas.innerHTML = '';
    (d.reutilizacion || []).forEach(function (p) {
      const li = document.createElement('li'); li.textContent = p; ulIdeas.appendChild(li);
    });

    const notas = document.getElementById('rNotas');
    if (d.notas) { notas.innerHTML = '<i class="bi bi-stars text-warning"></i> ' + esc(d.notas); notas.classList.remove('d-none'); }
    else { notas.classList.add('d-none'); }

    if (typeof d.nivel_confianza === 'number') {
      const pct = Math.round(d.nivel_confianza * 100);
      chip.textContent = (origen === 'ia' ? 'IA · ' : 'guía local · ') + pct + '% seguro';
    } else {
      chip.textContent = origen === 'ia' ? 'IA' : 'guía local';
    }
    origenActual = origen;
    vacio.classList.add('d-none');
    form.classList.remove('d-none');
  }

  btnIA.addEventListener('click', async function () {
    if (!dataURL) return;
    btnIA.disabled = true;
    estado.textContent = 'Leyendo el residuo… pocos segundos.';
    chip.textContent = 'leyendo…';
    try {
      const r = await fetch('api/extraer.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ imagen: dataURL, modo: 'residuo' })
      });
      const res = await r.json();
      if (!res.ok) throw new Error(res.error || 'No se pudo clasificar.');
      pintar(res.datos || {}, 'ia');
      estado.textContent = 'Listo. Revisá la clasificación antes de guardar.';
    } catch (err) {
      estado.innerHTML = '<span class="text-danger">' + esc(err.message) + '</span> ' +
        'Usá el catálogo local de la izquierda: es el Plan B.';
      chip.textContent = 'modo manual';
    } finally {
      btnIA.disabled = false;
    }
  });

  btnGuia.addEventListener('click', async function () {
    const material = selectGuia.value;
    btnGuia.disabled = true;
    estado.textContent = 'Buscando la guía local de ' + material + '…';
    try {
      const r = await fetch('api/clasificar_local.php?material=' + encodeURIComponent(material));
      const res = await r.json();
      if (!res.ok) throw new Error(res.error || 'No está esa guía.');
      pintar(res.datos || {}, 'local');
      estado.textContent = 'Guía cargada desde el catálogo (no usó internet).';
    } catch (err) {
      estado.innerHTML = '<span class="text-danger">' + esc(err.message) + '</span>';
    } finally {
      btnGuia.disabled = false;
    }
  });

  /* ---------- Confirmación humana → guardar ---------- */
  form.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    const btn = form.querySelector('button[type="submit"], button.btn-primary');
    if (btn) btn.disabled = true;
    try {
      const r = await fetch('api/guardar_residuo.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          hogar_id: <?= (int) $hogarId ?>,
          material: document.getElementById('rMaterial').value,
          tipo_plastico: document.getElementById('rPlastico').value,
          reciclable: document.getElementById('rReciclable').value,
          cantidad: document.getElementById('rCantidad').value,
          unidad: document.getElementById('rUnidad').value,
          semana: document.getElementById('rSemana').value,
          origen: origenActual === 'ia' ? 'ia' : 'manual',
          notas: document.getElementById('rNotas').textContent
        })
      });
      const res = await r.json();
      if (!res.ok) throw new Error(res.error || 'No se pudo guardar.');
      estado.textContent = 'Residuo guardado. Recargando…';
      setTimeout(function () { location.reload(); }, 700);
    } catch (err) {
      estado.innerHTML = '<span class="text-danger">' + esc(err.message) + '</span>';
      if (btn) btn.disabled = false;
    }
  });

  /* ---------- Plan con IA ---------- */
  const btnPlan = document.getElementById('btnPlanReciclaje');
  const estadoPlan = document.getElementById('estadoPlanReciclaje');
  if (btnPlan) {
    btnPlan.addEventListener('click', async function () {
      btnPlan.disabled = true;
      estadoPlan.textContent = 'La IA está armando el plan de reciclaje…';
      try {
        const r = await fetch('api/generar_plan_reciclaje.php', { method: 'POST' });
        const res = await r.json();
        if (!res.ok) throw new Error(res.error || 'No se pudo generar el plan.');
        estadoPlan.textContent = res.aviso || 'Listo. Recargando…';
        setTimeout(function () { location.reload(); }, res.aviso ? 1800 : 600);
      } catch (err) {
        estadoPlan.innerHTML = '<span class="text-danger">' + esc(err.message) + '</span>';
        btnPlan.disabled = false;
      }
    });
  }

  function esc(t) { const d = document.createElement('div'); d.textContent = t || ''; return d.innerHTML; }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
