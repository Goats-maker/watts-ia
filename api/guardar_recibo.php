<?php
/**
 * Guarda el recibo DESPUÉS de que la persona lo confirmó o corrigió.
 * Nunca recibe ni almacena imágenes: solo cifras.
 * POST: hogar_id, periodo, fecha, kwh, monto, cargo_fijo, tarifa, origen
 */
require_once dirname(__DIR__) . '/includes/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redir('../recibos_subir.php');
}

$hogarId   = (int) ($_POST['hogar_id'] ?? cfg('hogar_default'));
$periodo   = trim((string) ($_POST['periodo'] ?? ''));
$fecha     = (string) ($_POST['fecha'] ?? '');
$kwh       = str_replace(',', '.', (string) ($_POST['kwh'] ?? ''));
$monto     = str_replace(',', '.', (string) ($_POST['monto'] ?? ''));
$cargoFijo = str_replace(',', '.', (string) ($_POST['cargo_fijo'] ?? '0'));
$tarifa    = str_replace(',', '.', (string) ($_POST['tarifa'] ?? ''));
$origen    = ($_POST['origen'] ?? 'manual') === 'ia' ? 'ia' : 'manual';

$errores = [];
if ($periodo === '')               $errores[] = 'Falta el período.';
if ($fecha === '' || !strtotime($fecha)) $errores[] = 'La fecha no es válida.';
if (!is_numeric($kwh) || (float) $kwh <= 0)     $errores[] = 'Los kWh deben ser un número mayor que cero.';
if (!is_numeric($monto) || (float) $monto < 0)  $errores[] = 'El monto no es válido.';
if (!is_numeric($tarifa) || (float) $tarifa <= 0) $errores[] = 'La tarifa debe ser mayor que cero.';
$cargoFijo = is_numeric($cargoFijo) ? (float) $cargoFijo : 0.0;

if ($errores) {
    redir('../recibos_subir.php?error=' . urlencode(implode(' ', $errores)));
}

$st = db()->prepare(
    'INSERT INTO recibos (hogar_id, periodo, fecha, kwh, monto, cargo_fijo, tarifa, origen)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$st->execute([
    $hogarId, $periodo, $fecha,
    (float) $kwh, (float) $monto, $cargoFijo, (float) $tarifa, $origen,
]);

// La tarifa del hogar se actualiza a la del recibo más reciente.
db()->prepare('UPDATE hogares SET tarifa = ? WHERE id = ?')
    ->execute([(float) $tarifa, $hogarId]);

redir('../index.php?guardado=1');
