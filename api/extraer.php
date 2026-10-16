<?php
/**
 * Recibe la foto en base64, la manda a Gemini y devuelve JSON.
 * La imagen NO se guarda: se descarta al terminar la petición.
 * POST JSON: { "imagen": "data:image/jpeg;base64,...", "modo": "recibo" | "etiqueta" }
 */
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/GeminiClient.php';

ini_set('memory_limit', '256M');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['ok' => false, 'error' => 'Método no permitido.'], 405);
}

$entrada = json_decode((string) file_get_contents('php://input'), true) ?: [];

$imagen = $entrada['imagen'] ?? '';
$modo   = ($entrada['modo'] ?? 'recibo') === 'etiqueta' ? 'etiqueta' : 'recibo';

// 1. Validar el data URL
if (!is_string($imagen) || !preg_match('#^data:(image/(?:jpeg|png|webp));base64,#', $imagen, $m)) {
    jsonOut(['ok' => false, 'error' => 'La imagen no llega en formato base64 válido.'], 422);
}

$base64 = substr($imagen, strpos($imagen, ',') + 1);
$binario = base64_decode($base64, true);
if ($binario === false || strlen($binario) > 8 * 1024 * 1024) {
    jsonOut(['ok' => false, 'error' => 'Imagen vacía o mayor de 8 MB.'], 422);
}

// 2. Reducir el tamaño con GD si existe (menos tokens, más rápido)
$mime = $m[1];
if (function_exists('imagecreatefromstring')) {
    $img = @imagecreatefromstring($binario);
    if ($img) {
        $ancho = imagesx($img);
        $alto  = imagesy($img);
        $max   = 1400;
        if ($ancho > $max || $alto > $max) {
            $factor = $max / max($ancho, $alto);
            $img = imagescale($img, (int) ($ancho * $factor), (int) ($alto * $factor));
        }
        ob_start();
        imagejpeg($img, null, 82);
        $binario = (string) ob_get_clean();
        $base64  = base64_encode($binario);
        $mime    = 'image/jpeg';
        imagedestroy($img);
    }
}

// 3. Llamar a Gemini
$ia = new GeminiClient();
if (!$ia->disponible()) {
    jsonOut([
        'ok'    => false,
        'error' => 'Sin API key de Gemini configurada. Usá el ingreso manual (Plan B).',
        'plan_b' => true,
    ], 503);
}

try {
    $datos = $modo === 'etiqueta'
        ? $ia->extraerEtiqueta($base64, $mime)
        : $ia->extraerRecibo($base64, $mime);
} catch (Throwable $ex) {
    jsonOut(['ok' => false, 'error' => $ex->getMessage(), 'plan_b' => true], 502);
}

// 4. Respondemos y listo: $binario y $base64 se van de memoria al cerrar la petición.
jsonOut(['ok' => true, 'modo' => $modo, 'datos' => $datos]);
