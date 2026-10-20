<?php
/**
 * Clasificación SIN internet (Plan B): responde desde el catálogo local
 * guias_reciclaje con la misma forma que devolvería la IA.
 * GET: material=<nombre del catálogo>
 */
require_once dirname(__DIR__) . '/includes/helpers.php';

$material = trim((string) ($_GET['material'] ?? ''));
if ($material === '') {
    jsonOut(['ok' => false, 'error' => 'Falta el material.'], 422);
}

jsonOut(['ok' => true, 'datos' => clasificacionLocal($material)]);
