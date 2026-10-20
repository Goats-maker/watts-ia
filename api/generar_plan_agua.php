<?php
/**
 * Genera el plan de AHORRO DE AGUA con IA.
 * Si no hay internet, usa las plantillas locales por rango de consumo.
 * POST: nada. Se llama por fetch desde agua.php.
 */
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/GeminiClient.php';

$hogarId = (int) (cfg('hogar_default') ?: 1);
$stH = db()->prepare('SELECT * FROM hogares WHERE id = ?');
$stH->execute([$hogarId]);
$hogar = $stH->fetch() ?: null;

$personas   = personasDe($hogar);
$tarifaAgua = tarifaAguaDe($hogar);

$ultimo = ultimoReciboAgua($hogarId);
// Si todavía no hay recibo de agua, usamos lo que salen las calculadoras del hogar.
$m3 = $ultimo
    ? (float) $ultimo['m3']
    : m3Mes(litrosDiaTotales($hogarId, $personas));

$litrosDia = litrosDiaTotales($hogarId, $personas);
$ranking   = rankingUsosAgua($hogarId, $personas, $tarifaAgua, $m3);

// Solo números: el diagnóstico que recibe la IA no lleva ningún dato personal.
$diagnostico = [
    'tipo_hogar'    => $hogar['tipo'] ?? 'hogar',
    'personas'      => $personas,
    'periodo'       => $ultimo['periodo'] ?? null,
    'm3_mes'        => round($m3, 2),
    'litros_dia'    => round($litrosDia, 0),
    'monto_mensual' => $ultimo ? (float) $ultimo['monto'] : round($m3 * $tarifaAgua, 2),
    'tarifa_usd_m3' => $tarifaAgua,
    'usos'          => array_map(fn($u) => [
        'nombre'     => $u['nombre'],
        'litros_dia' => round($u['litros_dia'], 0),
        'm3_mes'     => round($u['m3'], 2),
        'pct_total'  => round($u['pct'], 1),
    ], $ranking),
];

$ia = new GeminiClient();
$origen = 'ia';

if ($ia->disponible()) {
    try {
        $plan = $ia->generarPlanAgua($diagnostico);
    } catch (Throwable $ex) {
        $plan = planAguaLocal($m3, $ranking);
        $origen = 'local';
        $plan['aviso'] = 'La IA no respondió (' . $ex->getMessage() . '), usamos el plan sin internet.';
    }
} else {
    $plan = planAguaLocal($m3, $ranking);
    $origen = 'local';
    $plan['aviso'] = 'Modo sin internet: este plan sale de las plantillas por rango de consumo.';
}

// Guardar (solo números y texto del plan, nada personal)
$st = db()->prepare(
    'INSERT INTO planes_agua (hogar_id, origen, resumen, acciones, reto, ahorro_usd, ahorro_litros)
     VALUES (?,?,?,?,?,?,?)'
);
$st->execute([
    $hogarId,
    $origen,
    (string) ($plan['resumen'] ?? 'Plan de ahorro de agua.'),
    json_encode($plan['acciones'] ?? [], JSON_UNESCAPED_UNICODE),
    json_encode($plan['reto'] ?? [], JSON_UNESCAPED_UNICODE),
    (float) ($plan['ahorro_usd_total'] ?? 0),
    (int) ($plan['ahorro_litros_total'] ?? 0),
]);

jsonOut([
    'ok'     => true,
    'origen' => $origen,
    'aviso'  => $plan['aviso'] ?? null,
    'plan'   => $plan,
]);
