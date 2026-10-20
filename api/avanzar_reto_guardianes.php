<?php
/**
 * Avanza un día en el Reto Guardianes del Planeta y recalcula puntos e insignias.
 * Devuelve las insignias nuevas para que la interfaz las pueda celebrar.
 * POST: (opcional) id del reto; si no viene se usa el último del hogar.
 */
require_once dirname(__DIR__) . '/includes/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['ok' => false, 'error' => 'Método no permitido.'], 405);
}

$hogarId = (int) (cfg('hogar_default') ?: 1);
$reto = retoGuardianes($hogarId);

if (!$reto) {
    jsonOut(['ok' => false, 'error' => 'Primero generá el plan unificado: ahí empieza el reto.'], 422);
}

$dias = min((int) $reto['meta_dias'], (int) $reto['dias_hechos'] + 1);

// Insignias: se calculan con números de los tres pilares.
$datos = datosUnificados($hogarId);
$insigniasAntes = (array) $reto['insignias_lista'];
$insigniasAhora = insigniasDelReto($dias, $datos, $insigniasAntes);
$nuevas = array_values(array_diff($insigniasAhora, $insigniasAntes));

// Puntos: 10 por el día + 25 por cada insignia nueva.
$puntos = (int) $reto['puntos'] + 10 + (count($nuevas) * 25);

db()->prepare('UPDATE reto_guardianes SET dias_hechos = ?, puntos = ?, insignias = ? WHERE id = ?')
    ->execute([
        $dias,
        $puntos,
        json_encode($insigniasAhora, JSON_UNESCAPED_UNICODE),
        (int) $reto['id'],
    ]);

jsonOut([
    'ok'         => true,
    'dias'       => $dias,
    'meta_dias'  => (int) $reto['meta_dias'],
    'puntos'     => $puntos,
    'insignias'  => $insigniasAhora,
    'nuevas'     => $nuevas,
]);
