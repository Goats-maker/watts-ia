<?php
/**
 * Genera el plan de ahorro. Pide los datos del hogar a la IA y guarda el resultado.
 * Si no hay IA ni internet, usa el plan pre-generado (Plan B).
 * POST: opcional, nada. Se llama por fetch desde plan.php.
 */
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/GeminiClient.php';

$hogarId = (int) (cfg('hogar_default') ?: 1);
$hogar   = hogarActual();
$tarifa  = tarifaDe($hogar);

$ultimo = ultimoRecibo($hogarId);
if (!$ultimo) {
    jsonOut(['ok' => false, 'error' => 'Todavía no hay recibo cargado.'], 422);
}

$ranking = rankingAparatos($hogarId, $tarifa, (float) $ultimo['kwh']);

$diagnostico = [
    'tipo_hogar'       => $hogar['tipo'],
    'periodo'          => $ultimo['periodo'],
    'kwh_mes'          => (float) $ultimo['kwh'],
    'monto_mensual'    => (float) $ultimo['monto'],
    'tarifa_usd_kwh'   => $tarifa,
    'aparatos'         => array_map(fn($a) => [
        'nombre' => $a['nombre'],
        'watts'  => $a['watts'],
        'horas_dia' => $a['horas'],
        'kwh_mes' => round($a['kwh'], 2),
        'pct_factura' => round($a['pct'], 1),
    ], $ranking),
];

$ia = new GeminiClient();
$origen = 'ia';

if ($ia->disponible()) {
    try {
        $plan = $ia->generarPlan($diagnostico);
    } catch (Throwable $ex) {
        $plan = planLocal((float) $ultimo['kwh'], $ranking);
        $origen = 'local';
        $plan['aviso'] = 'La IA no respondió (' . $ex->getMessage() . '), usamos el plan sin internet.';
    }
} else {
    $plan = planLocal((float) $ultimo['kwh'], $ranking);
    $origen = 'local';
    $plan['aviso'] = 'Modo sin internet: este plan sale de las plantillas por rango de consumo.';
}

// Guardar (solo números y texto del plan, nada personal)
$st = db()->prepare(
    'INSERT INTO planes_ahorro (hogar_id, origen, resumen, acciones, reto, ahorro_total)
     VALUES (?,?,?,?,?,?)'
);
$st->execute([
    $hogarId,
    $origen,
    (string) ($plan['resumen'] ?? 'Plan de ahorro.'),
    json_encode($plan['acciones'] ?? [], JSON_UNESCAPED_UNICODE),
    json_encode($plan['reto'] ?? [], JSON_UNESCAPED_UNICODE),
    (float) ($plan['ahorro_total_usd'] ?? 0),
]);

jsonOut([
    'ok'      => true,
    'origen'  => $origen,
    'aviso'   => $plan['aviso'] ?? null,
    'plan'    => $plan,
    'co2_kg'  => round(kgCo2((float) ($plan['ahorro_total_usd'] ?? 0) / max($tarifa, 0.01)), 1),
]);
