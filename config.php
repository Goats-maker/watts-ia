<?php
/**
 * Guardianes del Planeta — configuración central.
 *
 * ATENCIÓN: este archivo SÍ sube a GitHub, así que acá NO van secretos.
 * La API key de Gemini y la contraseña de MySQL viven en `config.local.php`,
 * que está marcada en .gitignore y por eso nunca se sube.
 *
 * Si `config.local.php` no existe (alguien que clona el repo), se recurre a
 * variables de entorno (GEMINI_API_KEY / DB_PASS) o a los valores por defecto:
 * XAMPP trae a MySQL con root sin contraseña, así que en una instalación
 * nueva funciona sin tocar nada.
 */

$local = is_file(__DIR__ . '/config.local.php')
    ? include __DIR__ . '/config.local.php'
    : [];
if (!is_array($local)) {
    $local = [];
}

// Secreto = archivo local > variable de entorno > vacío
$geminiKey = $local['gemini_api_key'] ?? '';
if ($geminiKey === '') {
    $geminiKey = getenv('GEMINI_API_KEY') ?: '';
}

$dbPass = $local['db_pass'] ?? '';
if ($dbPass === '' && getenv('DB_PASS') !== false) {
    $dbPass = getenv('DB_PASS');
}

return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'guardianes_del_planeta',
        'user'    => 'root',
        'pass'    => $dbPass,           // ver config.local.php
        'charset' => 'utf8mb4',
    ],

    'gemini' => [
        'api_key'   => $geminiKey,      // ver config.local.php
        'model'     => 'gemini-3.5-flash-lite',   // probado: responde con responseSchema
        'fallbacks' => ['gemini-3.8-flash', 'gemini-flash-latest'], // si falla, se prueban estos
        'timeout'   => 45,
    ],

    // Hogar que se usa cuando no hay sesión (demo / feria)
    'hogar_default' => 1,

    // Constantes de cálculo
    'tarifa_default' => 0.21,   // USD/kWh si el recibo no trae tarifa
    'co2_por_kwh'    => 0.42,   // kg CO2 por kWh (matriz eléctrica regional)
    'precio_pupusa'  => 0.15,   // USD — para la equivalencia simpática
];
