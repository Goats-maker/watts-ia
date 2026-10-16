<?php
$tituloPagina = 'Aparatos';
require __DIR__ . '/includes/header.php';

$hogarId  = (int) $hogar['id'];
$tarifa   = tarifaDe($hogar);
$ultimo   = ultimoRecibo($hogarId);
$kwhTotal = $ultimo ? (float) $ultimo['kwh'] : 0.0;

$ia = new GeminiClient();

/* ---------------- Acciones del CRUD ---------------- */
$aviso = $_GET['ok'] ?? '';
$error = $_GET['error'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'agregar' || $accion === 'editar') {
        $id       = (int) ($_POST['id'] ?? 0);
        $nombre   = trim((string) ($_POST['nombre'] ?? ''));
        $watts    = str_replace(',', '.', (string) ($_POST['watts'] ?? ''));
        $horas    = str_replace(',', '.', (string) ($_POST['horas_dia'] ?? ''));
        $categoria = preg_replace('/[^a-z_]/', '', strtolower((string) ($_POST['categoria'] ?? 'otro')));

        if ($nombre === '' || !is_numeric($watts) || (float) $watts <= 0
            || !is_numeric($horas) || (float) $horas < 0 || (float) $horas > 24) {
            redir('aparatos.php?error=' . urlencode('Revisá el nombre, los watts (mayor que cero) y las horas (0 a 24).'));
        }

        if ($accion === 'editar' && $id > 0) {
            db()->prepare('UPDATE aparatos SET nombre=?, watts=?, horas_dia=?, categoria=? WHERE id=? AND hogar_id=?')
                ->execute([$nombre, (int) $watts, (float) $horas, $categoria ?: 'otro', $id, $hogarId]);
            $aviso = 'Aparato actualizado.';
        } else {
            db()->prepare('INSERT INTO aparatos (hogar_id, nombre, watts, horas_dia, categoria) VALUES (?,?,?,?,?)')
                ->execute([$hogarId, $nombre, (int) $watts, (float) $horas, $categoria ?: 'otro']);
            $aviso = 'Aparato agregado.';
        }
    }

    if ($accion === 'borrar') {
        db()->prepare('DELETE FROM aparatos WHERE id=? AND hogar_id=?')
            ->execute([(int) ($_POST['id'] ?? 0), $hogarId]);
        $aviso = 'Aparato eliminado.';
    }

    redir('aparatos.php' . ($aviso ? '?ok=' . urlencode($aviso) : ''));
}

$editar = null;
if (isset($_GET['editar'])) {
    $st = db()->prepare('SELECT * FROM aparatos WHERE id=? AND hogar_id=?');
    $st->execute([(int) $_GET['editar'], $hogarId]);
    $editar = $st->fetch() ?: null;
}

$aparatos  = listaAparatos($hogarId);
$ranking   = rankingAparatos($hogarId, $tarifa, $kwhTotal);
$totalkwh  = array_sum(array_column($ranking, 'kwh'));
$costoTotal = array_sum(array_column($ranking, 'costo'));

$categorias = ['refrigeracion', 'lavado', 'cocina', 'clima', 'entretenimiento', 'iluminacion', 'otro'];
$etiquetasCat = [
    'refrigeracion' => 'Refrigeración', 'lavado' => 'Lavado y planchado', 'cocina' => 'Cocina',
    'clima' => 'Clima', 'entretenimiento' => 'Entretenimiento', 'iluminacion' => 'Iluminación', 'otro' => 'Otro',
];
?>

<header class="pagina-titulo mb-4">
  <h1 class="h2 mb-2">Inventario de aparatos</h1>
  <p class="mb-0">
    Sumá lo que tenés en casa con sus watts y horas de uso. La app calcula kWh/mes =
    watts × horas al día × 30 ÷ 1000, lo multiplica por tu tarifa
    (<?= usd($tarifa) ?>/kWh) y arma el ranking de «devoradores de watts».
  </p>
</header>

<?php if ($aviso): ?>
  <div class="alert alert-success border-0 shadow-sm"><i class="bi bi-check-circle-fill"></i> <?= e($aviso) ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-danger border-0 shadow-sm"><i class="bi bi-exclamation-triangle-fill"></i> <?= e($error) ?></div>
<?php endif; ?>

<?php if (!$kwhTotal): ?>
  <div class="alert alert-warning">
    <i class="bi bi-info-circle"></i>
    Todavía no hay recibo cargado, así que el ranking no puede calcular el % de la factura.
    <a href="recibos_subir.php">Escanear un recibo</a> o <a href="index.php">ir al panel</a>.
  </div>
<?php endif; ?>

<div class="row g-4">
  <!-- Formulario -->
  <div class="col-lg-4">
    <div class="card card-tarifa mb-4">
      <div class="card-body">
        <h2 class="h6"><?= $editar ? 'Editar aparato' : 'Agregar aparato' ?></h2>
        <form method="post" action="aparatos.php" class="formulario-ia">
          <input type="hidden" name="accion" value="<?= $editar ? 'editar' : 'agregar' ?>">
          <?php if ($editar): ?><input type="hidden" name="id" value="<?= (int) $editar['id'] ?>"><?php endif; ?>

          <div class="mb-3">
            <label class="form-label">Nombre</label>
            <input class="form-control" name="nombre" id="aNombre"
                   value="<?= e($editar['nombre'] ?? '') ?>" placeholder="Ej. Refrigerador viejo" required>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-6">
              <label class="form-label">Watts</label>
              <input type="number" step="1" min="1" class="form-control" name="watts" id="aWatts"
                     value="<?= e($editar['watts'] ?? '') ?>" placeholder="150" required>
            </div>
            <div class="col-6">
              <label class="form-label">Horas/día</label>
              <input type="number" step="0.5" min="0" max="24" class="form-control" name="horas_dia" id="aHoras"
                     value="<?= e($editar['horas_dia'] ?? '1') ?>" required>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Categoría</label>
            <select class="form-select" name="categoria" id="aCategoria">
              <?php foreach ($categorias as $c): ?>
                <option value="<?= $c ?>" <?= ($editar['categoria'] ?? '') === $c ? 'selected' : '' ?>>
                  <?= $etiquetasCat[$c] ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="d-flex gap-2">
            <button class="btn btn-primary fw-semibold flex-grow-1">
              <i class="bi bi-plus-lg"></i> <?= $editar ? 'Guardar cambios' : 'Agregar' ?>
            </button>
            <?php if ($editar): ?>
              <a href="aparatos.php" class="btn btn-outline-secondary">Cancelar</a>
            <?php endif; ?>
          </div>

          <p class="form-text mt-2">kWh estimados:
            <strong id="vistaKwh">—</strong> · costo: <strong id="vistaCosto">—</strong></p>
        </form>
      </div>
    </div>

    <!-- Modo leyenda -->
    <div class="card card-tarifa">
      <div class="card-body">
        <h2 class="h6"><i class="bi bi-upc-scan"></i> Modo leyenda</h2>
        <p class="small text-secondary">
          ¿No sabés cuántos watts tiene? Fotografiá la etiqueta del aparato (la pegatina con los datos)
          y la IA saca los watts solos.
        </p>
        <input type="file" id="inputEtiqueta" accept="image/*" capture="environment" class="form-control mb-2">
        <button id="btnEtiqueta" class="btn btn-outline-primary w-100" disabled>
          <i class="bi bi-stars"></i> Leer watts
        </button>
        <div id="estadoEtiqueta" class="small text-secondary mt-2"></div>
      </div>
    </div>
  </div>

  <!-- Ranking + tabla -->
  <div class="col-lg-8">
    <div class="card card-tarifa mb-4">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h2 class="h6 mb-0">Devoradores de watts</h2>
          <span class="chip"><?= num($totalkwh) ?> kWh/mes · <?= usd($costoTotal) ?></span>
        </div>

        <?php if (!$ranking): ?>
          <p class="text-secondary mb-0">Agregá tu primer aparato con el formulario de la izquierda.</p>
        <?php else: ?>
          <?php foreach ($ranking as $i => $a): ?>
            <div class="mb-3">
              <div class="d-flex justify-content-between align-items-center flex-wrap gap-1">
                <div>
                  <span class="text-secondary"><?= $i + 1 ?>.</span>
                  <strong><?= e($a['nombre']) ?></strong>
                  <span class="badge-sem semaforo-<?= $a['semaforo'] ?> ms-1"><?= etiquetaSemaforo($a['semaforo']) ?></span>
                </div>
                <div class="text-end small">
                  <strong><?= num($a['kwh']) ?> kWh</strong> · <?= usd($a['costo']) ?>/mes
                  <?php if ($kwhTotal): ?>
                    · <?= num($a['pct']) ?>% de la factura
                  <?php endif; ?>
                </div>
              </div>
              <div class="barra-fila mt-1">
                <span style="width:<?= (int) min(100, $a['pct'] ?: 10) ?>%;
                  background:<?= ['rojo' => '#ef4444', 'amarillo' => '#f5b301', 'verde' => '#22c55e'][$a['semaforo']] ?>"></span>
              </div>
            </div>
          <?php endforeach; ?>

          <?php if ($totalkwh > $kwhTotal && $kwhTotal): ?>
            <div class="alert alert-warning small mb-0">
              <i class="bi bi-exclamation-triangle"></i>
              Tus aparatos suman <?= num($totalkwh) ?> kWh y el recibo dice <?= num($kwhTotal) ?> kWh.
              Revisá las horas de uso: probablemente estén de más.
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="card card-tarifa">
      <div class="card-body">
        <h2 class="h6 mb-3">Todos tus aparatos</h2>
        <?php if (!$aparatos): ?>
          <p class="text-secondary mb-0">Sin aparatos cargados todavía.</p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Aparato</th><th class="text-end">Watts</th><th class="text-end">Horas/día</th>
                  <th class="text-end">kWh/mes</th><th class="text-end">Costo</th><th></th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($aparatos as $a): ?>
                <?php $k = kwhMes((float) $a['watts'], (float) $a['horas_dia']); ?>
                <tr>
                  <td>
                    <?= e($a['nombre']) ?>
                    <div class="small text-secondary"><?= e($etiquetasCat[$a['categoria']] ?? $a['categoria']) ?></div>
                  </td>
                  <td class="text-end"><?= num($a['watts'], 0) ?></td>
                  <td class="text-end"><?= num($a['horas_dia']) ?></td>
                  <td class="text-end"><?= num($k) ?></td>
                  <td class="text-end"><?= usd(costoDe($k, $tarifa)) ?></td>
                  <td class="text-end text-nowrap">
                    <a class="btn btn-sm btn-outline-secondary" href="aparatos.php?editar=<?= (int) $a['id'] ?>">
                      <i class="bi bi-pencil"></i>
                    </a>
                    <form method="post" action="aparatos.php" class="d-inline"
                          onsubmit="return confirm('¿Borrar <?= e(addslashes($a['nombre'])) ?>?')">
                      <input type="hidden" name="accion" value="borrar">
                      <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                      <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
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

<script>
(function () {
  // Vista previa de kWh y costo mientras se escribe
  const watts = document.getElementById('aWatts');
  const horas = document.getElementById('aHoras');
  const vkwh  = document.getElementById('vistaKwh');
  const vcost = document.getElementById('vistaCosto');
  const tarifa = <?= json_encode((float) $tarifa) ?>;

  function recalcular() {
    const w = parseFloat(watts.value) || 0;
    const h = parseFloat(horas.value) || 0;
    const kwh = (w * h * 30) / 1000;
    vkwh.textContent = kwh.toFixed(1) + ' kWh';
    vcost.textContent = '$' + (kwh * tarifa).toFixed(2);
  }
  watts.addEventListener('input', recalcular);
  horas.addEventListener('input', recalcular);
  recalcular();

  // Modo leyenda: etiqueta → watts
  const inputEtq   = document.getElementById('inputEtiqueta');
  const btnEtq     = document.getElementById('btnEtiqueta');
  const estadoEtq  = document.getElementById('estadoEtiqueta');
  let fotoEtiqueta = null;

  inputEtq.addEventListener('change', function () {
    const archivo = this.files && this.files[0];
    if (!archivo) return;
    const lector = new FileReader();
    lector.onload = function (e) {
      fotoEtiqueta = e.target.result;
      btnEtq.disabled = false;
      estadoEtq.textContent = 'Etiqueta lista. Tocá "Leer watts".';
    };
    lector.readAsDataURL(archivo);
  });

  btnEtq.addEventListener('click', async function () {
    if (!fotoEtiqueta) return;
    btnEtq.disabled = true;
    estadoEtq.textContent = 'Leyendo la etiqueta…';
    try {
      const r = await fetch('api/extraer.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ imagen: fotoEtiqueta, modo: 'etiqueta' })
      });
      const res = await r.json();
      if (!res.ok) throw new Error(res.error || 'No se pudo leer la etiqueta.');
      const d = res.datos;
      document.getElementById('aNombre').value = d.nombre || '';
      if (d.watts) document.getElementById('aWatts').value = d.watts;
      if (d.categoria) document.getElementById('aCategoria').value = d.categoria;
      recalcular();
      estadoEtq.innerHTML = '<span class="text-success">Listo:</span> ' + (d.notas || 'completado.');
    } catch (err) {
      estadoEtq.innerHTML = '<span class="text-danger">' + err.message + '</span> Cargá los watts a mano.';
    } finally {
      btnEtq.disabled = false;
    }
  });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
