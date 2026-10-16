<?php
/**
 * Agrega un aparato desde una plantilla de negocio.
 * POST: hogar_id, nombre, watts, horas_dia, categoria
 */
require_once dirname(__DIR__) . '/includes/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redir('../negocios.php');
}

$hogarId  = (int) ($_POST['hogar_id'] ?? 0);
$nombre   = trim((string) ($_POST['nombre'] ?? ''));
$watts    = (int) ($_POST['watts'] ?? 0);
$horas    = (float) ($_POST['horas_dia'] ?? 1);
$categoria = preg_replace('/[^a-z_]/', '', strtolower((string) ($_POST['categoria'] ?? 'otro')));

// Solo se permiten hogares existentes (nada de datos personales en la petición)
$st = db()->prepare('SELECT id FROM hogares WHERE id = ?');
$st->execute([$hogarId]);

if ($st->fetch() && $nombre !== '' && $watts > 0 && $horas >= 0 && $horas <= 24) {
    db()->prepare('INSERT INTO aparatos (hogar_id, nombre, watts, horas_dia, categoria) VALUES (?,?,?,?,?)')
        ->execute([$hogarId, $nombre, $watts, $horas, $categoria ?: 'otro']);
}

redir('../negocios.php?negocio=' . $hogarId);
