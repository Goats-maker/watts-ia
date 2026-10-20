<?php
/**
 * Genera el PLAN UNIFICADO (energía + agua + reciclaje) con IA.
 * Si no hay internet, usa la plantilla local con una acción por pilar.
 * También crea (o actualiza) el Reto Guardianes del Planeta de 30 días.
 * POST: nada. Se llama por fetch desde plan.php.
 */
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/GeminiClient.php';

$hogarId = (int) (cfg('hogar_default') ?: 1);

// Solo números de los tres pilares: nunca datos personales.
$diagnostico = datosUnificados($hogarId);

$ia = new GeminiClient();
$origen = 'ia';

if ($ia->disponible()) {
    try {
        $plan = $ia->generarPlanUnificado($diagnostico);
    } catch (Throwable $ex) {
        $plan = planUnificadoLocal($diagnostico);
        $origen = 'local';
        $plan['aviso'] = 'La IA no respondió (' . $ex->getMessage() . '), usamos el plan sin internet.';
    }
} else {
    $plan = planUnificadoLocal($diagnostico);
    $origen = 'local';
    $plan['aviso'] = 'Modo sin internet: este plan sale de las plantillas locales.';
}

// Totales: los tres se calculan desde las acciones para que siempre cuadren.
$ahorroUsd    = round((float) array_sum(array_column($plan['acciones'] ?? [], 'ahorro_usd_mes')), 2);
$ahorroLitros = (int) array_sum(array_column($plan['acciones'] ?? [], 'ahorro_litros_mes'));
$kgMeta       = (float) array_sum(array_column($plan['acciones'] ?? [], 'kg_meta'));

$st = db()->prepare(
    'INSERT INTO planes_unificados (hogar_id, origen, resumen, acciones, reto, ahorro_usd, ahorro_litros, kg_meta)
     VALUES (?,?,?,?,?,?,?,?)'
);
$st->execute([
    $hogarId,
    $origen,
    (string) ($plan['resumen'] ?? 'Plan Guardianes del Planeta.'),
    json_encode($plan['acciones'] ?? [], JSON_UNESCAPED_UNICODE),
    json_encode($plan['reto'] ?? [], JSON_UNESCAPED_UNICODE),
    $ahorroUsd,
    $ahorroLitros,
    $kgMeta,
]);

/* -------- Reto unificado de 30 días -------- */
$reto = retoGuardianes($hogarId);
$metas = [
    'usd'    => $ahorroUsd,
    'litros' => $ahorroLitros,
    'kg'     => $kgMeta,
];

if (!$reto) {
    db()->prepare(
        'INSERT INTO reto_guardianes (hogar_id, inicio, insignias, metas) VALUES (?, CURDATE(), ?, ?)'
    )->execute([
        $hogarId,
        json_encode(insigniasDelReto(0, $diagnostico, []), JSON_UNESCAPED_UNICODE),
        json_encode($metas, JSON_UNESCAPED_UNICODE),
    ]);
} else {
    // Se refrescan las metas; los días e insignias ganados se conservan.
    db()->prepare('UPDATE reto_guardianes SET metas = ? WHERE id = ?')
        ->execute([json_encode($metas, JSON_UNESCAPED_UNICODE), (int) $reto['id']]);
}

jsonOut([
    'ok'     => true,
    'origen' => $origen,
    'aviso'  => $plan['aviso'] ?? null,
    'plan'   => $plan,
]);
