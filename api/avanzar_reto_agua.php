<?php
/**
 * Avanza un día en el reto del agua (mismo criterio que el reto de energía).
 * POST: id del plan de agua.
 */
require_once dirname(__DIR__) . '/includes/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redir('../agua.php');
}

$id = (int) ($_POST['id'] ?? 0);
db()->prepare('UPDATE planes_agua SET dias_hechos = LEAST(dias_hechos + 1, meta_dias) WHERE id = ?')
    ->execute([$id]);

redir('../agua.php');
