<?php
$tituloPagina = 'Escanear recibo';
require __DIR__ . '/includes/header.php';

$error   = $_GET['error'] ?? '';
$guardado = isset($_GET['guardado']);

// Estado inicial del formulario (lo completa la IA o la persona a mano)
$datos = [
    'periodo'    => '',
    'fecha'      => '',
    'kwh'        => '',
    'tarifa'     => '',
    'cargo_fijo' => '',
    'monto'      => '',
    'notas'      => '',
];

$iaDisponible = (new GeminiClient())->disponible();
?>

<?php if ($guardado): ?>
  <div class="alert alert-success border-0 shadow-sm">
    <i class="bi bi-check-circle-fill"></i> Recibo guardado. La imagen se descartó: solo quedaron los números.
  </div>
<?php endif; ?>

<?php if ($error): ?>
  <div class="alert alert-danger border-0 shadow-sm">
    <i class="bi bi-exclamation-triangle-fill"></i> <?= e($error) ?>
  </div>
<?php endif; ?>

<header class="pagina-titulo mb-4">
  <h1 class="h2 mb-2">Fotografiá tu recibo de luz</h1>
  <p class="mb-0">
    La IA lo lee y llena los campos solos. Vos revisás, corregís si hace falta y recién ahí se guarda.
    Nunca guardamos la foto: solo los números.
  </p>
</header>

<div class="row g-4">
  <!-- Columna: cámara -->
  <div class="col-lg-5">
    <div class="card card-tarifa">
      <div class="card-body">
        <h2 class="h6">1 · La foto</h2>
        <input type="file" id="inputFoto" accept="image/*" capture="environment" class="form-control mb-3">
        <img id="vistaPrevia" class="preview-recibo w-100 d-none" alt="Vista previa del recibo">

        <button id="btnLeer" class="btn btn-warning w-100 fw-semibold" disabled>
          <i class="bi bi-stars"></i> Leer con la IA
        </button>

        <div id="estadoIA" class="small text-secondary mt-2"></div>

        <?php if (!$iaDisponible): ?>
          <div class="alert alert-warning small mt-3 mb-0">
            <i class="bi bi-wifi-off"></i> <strong>Modo sin internet activo.</strong>
            Completá los datos a mano acá abajo: es el Plan B.
          </div>
        <?php endif; ?>

        <hr>
        <p class="small text-secondary mb-0">
          <i class="bi bi-shield-check"></i> Para proteger tu privacidad, tapá nombre, dirección y número
          de cliente antes de fotografiar. Aun así, la app nunca los guarda.
        </p>
      </div>
    </div>
  </div>

  <!-- Columna: formulario editable -->
  <div class="col-lg-7">
    <div class="card card-tarifa">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h2 class="h6 mb-0">2 · Confirmá los datos</h2>
          <span id="chipOrigen" class="chip">sin leer</span>
        </div>

        <form method="post" action="api/guardar_recibo.php" class="formulario-ia" id="formRecibo">
          <input type="hidden" name="hogar_id" value="<?= (int) $hogar['id'] ?>">
          <input type="hidden" name="origen" id="campoOrigen" value="manual">

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Período</label>
              <input class="form-control" name="periodo" id="fPeriodo" value="<?= e($datos['periodo']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Fecha de emisión</label>
              <input type="date" class="form-control" name="fecha" id="fFecha" value="<?= e($datos['fecha']) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">kWh consumidos</label>
              <input type="number" step="0.01" min="0" class="form-control" name="kwh" id="fKwh"
                     value="<?= e($datos['kwh']) ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Tarifa ($/kWh)</label>
              <input type="number" step="0.0001" min="0" class="form-control" name="tarifa" id="fTarifa"
                     value="<?= e($datos['tarifa']) ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Cargo fijo ($)</label>
              <input type="number" step="0.01" min="0" class="form-control" name="cargo_fijo" id="fCargo"
                     value="<?= e($datos['cargo_fijo']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Monto total ($)</label>
              <input type="number" step="0.01" min="0" class="form-control" name="monto" id="fMonto"
                     value="<?= e($datos['monto']) ?>" required>
            </div>
            <div class="col-md-6 d-flex align-items-end">
              <div class="form-text">
                <i class="bi bi-info-circle"></i> La tarifa sale del propio recibo;
                si el campo queda vacío se usa <?= usd(cfg('tarifa_default')) ?>/kWh.
              </div>
            </div>
          </div>

          <div id="notaIA" class="alert alert-light border small mt-3 d-none"></div>

          <button type="submit" class="btn btn-primary w-100 fw-semibold mt-3">
            <i class="bi bi-save"></i> Confirmar y guardar recibo
          </button>
          <p class="form-text text-center mt-2">
            Nada se guarda sin que vos lo confirmes. Podés editar cualquier campo.
          </p>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  const input    = document.getElementById('inputFoto');
  const vista    = document.getElementById('vistaPrevia');
  const btnLeer  = document.getElementById('btnLeer');
  const estado   = document.getElementById('estadoIA');
  const chip     = document.getElementById('chipOrigen');
  const origen   = document.getElementById('campoOrigen');
  const notaIA   = document.getElementById('notaIA');

  const campos = {
    periodo:    document.getElementById('fPeriodo'),
    fecha:      document.getElementById('fFecha'),
    kwh:        document.getElementById('fKwh'),
    tarifa:     document.getElementById('fTarifa'),
    cargo_fijo: document.getElementById('fCargo'),
    monto:      document.getElementById('fMonto')
  };

  let dataURL = null;

  input.addEventListener('change', function () {
    const archivo = this.files && this.files[0];
    if (!archivo) return;
    const lector = new FileReader();
    lector.onload = function (e) {
      dataURL = e.target.result;
      vista.src = dataURL;
      vista.classList.remove('d-none');
      btnLeer.disabled = false;
      estado.textContent = 'Foto lista. Tocá "Leer con la IA".';
      chip.textContent = 'foto cargada';
    };
    lector.readAsDataURL(archivo);
  });

  btnLeer.addEventListener('click', async function () {
    if (!dataURL) return;
    btnLeer.disabled = true;
    estado.textContent = 'Leyendo el recibo… esto toma pocos segundos.';
    chip.textContent = 'leyendo…';

    try {
      const r = await fetch('api/extraer.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ imagen: dataURL, modo: 'recibo' })
      });
      const res = await r.json();

      if (!res.ok) {
        throw new Error(res.error || 'No se pudo leer el recibo.');
      }

      const d = res.datos || {};
      Object.keys(campos).forEach(function (k) {
        if (d[k] !== undefined && d[k] !== null) campos[k].value = d[k];
      });
      if (!campos.fecha.value && d.periodo) {
        // sin fecha exacta: usamos el fin de mes como aproximación editable
        const hoy = new Date();
        campos.fecha.value = hoy.toISOString().slice(0, 10);
      }

      notaIA.innerHTML = '<i class="bi bi-stars text-warning"></i> <strong>Leído por la IA.</strong> '
        + (d.notas ? e(d.notas) + ' · ' : '')
        + 'Revisá los números antes de guardar.';
      notaIA.classList.remove('d-none');

      origen.value = 'ia';
      chip.textContent = 'leído por la IA';
      estado.textContent = 'Listo. Corregí lo que haga falta y guardá.';
    } catch (err) {
      estado.innerHTML = '<span class="text-danger">' + err.message + '</span> '
        + 'Podés completar los campos a mano: es el Plan B.';
      chip.textContent = 'modo manual';
    } finally {
      btnLeer.disabled = false;
    }
  });

  function e(t) {
    const d = document.createElement('div');
    d.textContent = t;
    return d.innerHTML;
  }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
