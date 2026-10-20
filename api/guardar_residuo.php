<?php
/**
 * Guarda el residuo DESPUÉS de que la persona confirmó o corrigió la
 * clasificación que propuso la IA. Nunca guarda imágenes: solo cifras y texto.
 * POST: hogar_id, material, tipo_plastico, reciclable, cantidad, unidad,
 *       semana, origen, notas
 */
require_once dirname(__DIR__) . '/includes/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['ok' => false, 'error' => 'Método no permitido.'], 405);
}

$entrada = json_decode((string) file_get_contents('php://input'), true)
        ?: $_POST;

$hogarId = (int) ($entrada['hogar_id'] ?? cfg('hogar_default'));
$material = trim((string) ($entrada['material'] ?? ''));
$tipoPlastico = trim((string) ($entrada['tipo_plastico'] ?? ''));
$reciclable = strtolower(trim((string) ($entrada['reciclable'] ?? 'depende')));
$cantidad = str_replace(',', '.', (string) ($entrada['cantidad'] ?? '1'));
$unidad = (string) ($entrada['unidad'] ?? 'unidad');
$semana = (string) ($entrada['semana'] ?? date('Y-m-d'));
$origen = ($entrada['origen'] ?? 'manual') === 'ia' ? 'ia' : 'manual';
$notas = trim((string) ($entrada['notas'] ?? ''));

$errores = [];
if ($material === '') $errores[] = 'Falta el material.';
if (!in_array($reciclable, ['si', 'no', 'depende'], true)) {
    $reciclable = 'depende';
}
if (!in_array($unidad, ['unidad', 'kg', 'litro'], true)) $unidad = 'unidad';
if (!is_numeric($cantidad) || (float) $cantidad <= 0) {
    $errores[] = 'La cantidad debe ser un número mayor que cero.';
}
if (!strtotime($semana)) $errores[] = 'La fecha de la semana no es válida.';

if ($errores) {
    jsonOut(['ok' => false, 'error' => implode(' ', $errores)], 422);
}

// La semana se guarda normalizada al lunes: así las gráficas cuadran.
$ts = strtotime($semana);
$semanaLunes = date('Y-m-d', $ts - ((int) date('w', $ts) + 6) % 86400);

// Peso aproximado para la gráfica de kilos:
//  • si la unidad ya es kg, se usa tal cual;
//  • si es por unidad, se multiplica por el peso promedio de la guía local.
$guia = guiaDe($material);
if ($unidad === 'kg') {
    $kg = (float) $cantidad;
} elseif ($guia && $guia['kg_aprox_por_unidad'] !== null) {
    $kg = round((float) $cantidad * (float) $guia['kg_aprox_por_unidad'], 2);
} else {
    $kg = 0.0;
}

$st = db()->prepare(
    'INSERT INTO residuos (hogar_id, material, tipo_plastico, reciclable, cantidad,
                           unidad, kg_aprox, semana, origen, notas)
     VALUES (?,?,?,?,?,?,?,?,?,?)'
);
$st->execute([
    $hogarId,
    $material,
    $tipoPlastico !== '' ? $tipoPlastico : null,
    $reciclable,
    (float) $cantidad,
    $unidad,
    $kg,
    $semanaLunes,
    $origen,
    $notas !== '' ? mb_substr($notas, 0, 200) : null,
]);

jsonOut(['ok' => true, 'kg' => $kg, 'semana' => $semanaLunes]);
