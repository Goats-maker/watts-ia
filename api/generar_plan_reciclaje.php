<?php
/**
 * Genera el plan de reciclaje de 30 días con IA.
 * Si no hay internet, usa plantillas locales según los kilos registrados.
 * POST: nada. Se llama por fetch desde reciclaje.php.
 */
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/GeminiClient.php';

$hogarId = (int) (cfg('hogar_default') ?: 1);

$resumen = residuosPorMaterial($hogarId);
$materiales = $resumen['materiales'];
$totalKg = (float) $resumen['total_kg'];

if (!$materiales) {
    jsonOut(['ok' => false, 'error' => 'Todavía no registraste residuos: primero clasificá o registrá uno.'], 422);
}

$equiv = equivalenciasReciclaje($materiales);

// Solo números y materiales: nunca datos personales.
$diagnostico = [
    'kg_total_mes'   => round($totalKg, 2),
    'materiales'     => array_map(fn($m) => [
        'material' => $m['material'],
        'kg'       => round((float) $m['kg'], 2),
        'pct'      => round((float) $m['pct'], 1),
    ], $materiales),
    'co2_evitado_kg' => $equiv['co2_kg'],
    'valor_estimado' => $equiv['valor_usd'],
];

$ia = new GeminiClient();
$origen = 'ia';

if ($ia->disponible()) {
    try {
        $plan = $ia->generarPlanReciclaje($diagnostico);
    } catch (Throwable $ex) {
        $plan = planReciclajeLocal($resumen);
        $origen = 'local';
        $plan['aviso'] = 'La IA no respondió (' . $ex->getMessage() . '), usamos el plan sin internet.';
    }
} else {
    $plan = planReciclajeLocal($resumen);
    $origen = 'local';
    $plan['aviso'] = 'Modo sin internet: este plan sale de las plantillas locales.';
}

$st = db()->prepare(
    'INSERT INTO planes_reciclaje (hogar_id, origen, resumen, acciones, meta_kg, kg_reciclado_hoy)
     VALUES (?,?,?,?,?,?)'
);
$st->execute([
    $hogarId,
    $origen,
    (string) ($plan['resumen'] ?? 'Plan de reciclaje.'),
    json_encode($plan['acciones'] ?? [], JSON_UNESCAPED_UNICODE),
    (float) ($plan['meta_kg'] ?? 0),
    $totalKg,
]);

jsonOut([
    'ok'     => true,
    'origen' => $origen,
    'aviso'  => $plan['aviso'] ?? null,
    'plan'   => $plan,
]);
