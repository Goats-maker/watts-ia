<?php
/**
 * Avanza un día en el reto de 30 días.
 * POST: id del plan
 */
require_once dirname(__DIR__) . '/includes/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redir('../plan.php');
}

$id     = (int) ($_POST['id'] ?? 0);
$planId = db()->prepare('SELECT * FROM planes_ahorro WHERE id = ?');
$planId->execute([$id]);
$plan = $planId->fetch();

if ($plan && (int) $plan['dias_hechos'] < (int) $plan['meta_dias']) {
    db()->prepare('UPDATE planes_ahorro SET dias_hechos = dias_hechos + 1 WHERE id = ?')
        ->execute([$id]);
}

redir('../plan.php');
