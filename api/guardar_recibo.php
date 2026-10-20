<?php
/**
 * Guarda el recibo DESPUÉS de que la persona lo confirmó o corrigió.
 * Nunca recibe ni almacena imágenes: solo cifras.
 * Soporta los dos servicios: 'energia' (kWh) y 'agua' (m³).
 * POST: hogar_id, tipo_servicio, periodo, fecha, kwh, m3, monto,
 *       cargo_fijo, cargo_alcantarillado, tarifa, origen
 */
require_once dirname(__DIR__) . '/includes/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redir('../recibos_subir.php');
}

$hogarId   = (int) ($_POST['hogar_id'] ?? cfg('hogar_default'));
$tipo      = ($_POST['tipo_servicio'] ?? 'energia') === 'agua' ? 'agua' : 'energia';
$periodo   = trim((string) ($_POST['periodo'] ?? ''));
$fecha     = (string) ($_POST['fecha'] ?? '');
$kwh       = str_replace(',', '.', (string) ($_POST['kwh'] ?? ''));
$m3        = str_replace(',', '.', (string) ($_POST['m3'] ?? ''));
$monto     = str_replace(',', '.', (string) ($_POST['monto'] ?? ''));
$cargoFijo = str_replace(',', '.', (string) ($_POST['cargo_fijo'] ?? '0'));
$cargoAlc  = str_replace(',', '.', (string) ($_POST['cargo_alcantarillado'] ?? ''));
$tarifa    = str_replace(',', '.', (string) ($_POST['tarifa'] ?? ''));
$origen    = ($_POST['origen'] ?? 'manual') === 'ia' ? 'ia' : 'manual';

$errores = [];
if ($periodo === '')                       $errores[] = 'Falta el período.';
if ($fecha === '' || !strtotime($fecha))   $errores[] = 'La fecha no es válida.';
if (!is_numeric($monto) || (float) $monto < 0) $errores[] = 'El monto no es válido.';
$cargoFijo = is_numeric($cargoFijo) ? (float) $cargoFijo : 0.0;

// El hogar se consulta una vez: sirve para la tarifa por defecto del agua.
$stHogar = db()->prepare('SELECT * FROM hogares WHERE id = ?');
$stHogar->execute([$hogarId]);
$hogarBD = $stHogar->fetch() ?: null;

// Validaciones distintas según el servicio.
$kwhValor = null;
$m3Valor   = null;
$cargoAlcValor = null;
$tarifaValor   = null;

if ($tipo === 'agua') {
    if (!is_numeric($m3) || (float) $m3 <= 0) {
        $errores[] = 'Los metros cúbicos deben ser un número mayor que cero.';
    } else {
        $m3Valor = (float) $m3;
    }
    // La tarifa por m³ puede no aparecer en el recibo: si está vacía se usa la del hogar.
    $tarifaValor = (is_numeric($tarifa) && (float) $tarifa > 0)
        ? (float) $tarifa
        : tarifaAguaDe($hogarBD);
    $cargoAlcValor = is_numeric($cargoAlc) ? (float) $cargoAlc : null;
} else {
    if (!is_numeric($kwh) || (float) $kwh <= 0) {
        $errores[] = 'Los kWh deben ser un número mayor que cero.';
    } else {
        $kwhValor = (float) $kwh;
    }
    if (!is_numeric($tarifa) || (float) $tarifa <= 0) {
        $errores[] = 'La tarifa debe ser mayor que cero.';
    } else {
        $tarifaValor = (float) $tarifa;
    }
}

if ($errores) {
    redir('../recibos_subir.php?servicio=' . $tipo . '&error=' . urlencode(implode(' ', $errores)));
}

$st = db()->prepare(
    'INSERT INTO recibos (hogar_id, tipo_servicio, periodo, fecha, kwh, m3, monto, cargo_fijo, cargo_alcantarillado, tarifa, origen)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$st->execute([
    $hogarId, $tipo, $periodo, $fecha,
    $kwhValor, $m3Valor, (float) $monto, $cargoFijo, $cargoAlcValor, (float) $tarifaValor, $origen,
]);

// La tarifa del hogar se actualiza con la del recibo más reciente (por servicio).
if ($tipo === 'agua') {
    db()->prepare('UPDATE hogares SET tarifa_agua = ? WHERE id = ?')
        ->execute([(float) $tarifaValor, $hogarId]);
} else {
    db()->prepare('UPDATE hogares SET tarifa = ? WHERE id = ?')
        ->execute([(float) $tarifaValor, $hogarId]);
}

// Aviso de coherencia: si el monto no cuadra con consumo × tarifa, lo avisamos
// pero igual guardamos (la persona confirmó los números).
$aviso = null;
$consumoBase = $tipo === 'agua' ? $m3Valor : $kwhValor;
if ($consumoBase !== null && $tarifaValor !== null) {
    $esperado = $consumoBase * $tarifaValor + $cargoFijo
              + ($tipo === 'agua' ? (float) ($cargoAlcValor ?? 0) : 0);
    if ($esperado > 0.5 && abs((float) $monto - $esperado) / $esperado > 0.35) {
        $aviso = 'El monto no cuadra con el consumo × tarifa (más o menos debería ser ' . usd($esperado) . ').';
    }
}

redir('../index.php?guardado=1' . ($aviso ? '&aviso_cuadre=' . urlencode($aviso) : ''));
