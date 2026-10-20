<?php
declare(strict_types=1);

/**
 * Cliente mínimo de la API REST de Gemini (v1beta) con cURL.
 * Tres usos: leer un recibo, leer una etiqueta de aparato y redactar el plan.
 * Siempre pedimos JSON con responseSchema (esquema fijo).
 */
class GeminiClient
{
    private string $apiKey;
    private array  $modelos;
    private int    $timeout;

    public function __construct()
    {
        $this->apiKey  = (string) cfg('gemini.api_key', '');
        $this->modelos = array_values(array_filter(array_merge(
            [(string) cfg('gemini.model', 'gemini-3.5-flash-lite')],
            (array) cfg('gemini.fallbacks', [])
        )));
        $this->timeout = (int) cfg('gemini.timeout', 45);
    }

    public function disponible(): bool
    {
        return $this->apiKey !== '';
    }

    /* ---------------- Recibo de luz → JSON ---------------- */

    public function extraerRecibo(string $base64, string $mime): array
    {
        $prompt = <<<TXT
        Sos un lector de recibos de energía eléctrica. Mirá la imagen y devolvé SOLO un JSON
        con estos campos:
        - periodo: texto del período de facturación tal como aparece (ej. "Septiembre 2026" o "09/2026")
        - fecha: fecha de emisión en formato AAAA-MM-DD si aparece, si no null
        - kwh: consumo total del período en kWh (número)
        - tarifa: precio por kWh en USD (número). Si dice "cargo fijo" separamelo.
        - cargo_fijo: cargo fijo o derecho de conexión del período en USD (número, 0 si no tiene)
        - monto: monto total a pagar en USD (número)
        - lectura_anterior y lectura_actual: mediciones del medidor si aparecen, si no null
        - notas: una línea corta con cualquier cosa rara o ilegible
        Reglas: no inventes números que no estén en la imagen. Si un dato no aparece, usá null
        y explicalo en notas. Nunca incluyas nombre, dirección ni número de cliente.
        TXT;

        $schema = [
            'type' => 'OBJECT',
            'properties' => [
                'periodo'          => ['type' => 'STRING'],
                'fecha'            => ['type' => 'STRING', 'nullable' => true],
                'kwh'              => ['type' => 'NUMBER'],
                'tarifa'           => ['type' => 'NUMBER'],
                'cargo_fijo'       => ['type' => 'NUMBER'],
                'monto'            => ['type' => 'NUMBER'],
                'lectura_anterior' => ['type' => 'NUMBER', 'nullable' => true],
                'lectura_actual'   => ['type' => 'NUMBER', 'nullable' => true],
                'notas'            => ['type' => 'STRING'],
            ],
            'required' => ['periodo', 'kwh', 'tarifa', 'cargo_fijo', 'monto'],
        ];

        return $this->llamada($base64, $mime, $prompt, $schema);
    }

    /* ---------------- Etiqueta de aparato → watts ---------------- */

    public function extraerEtiqueta(string $base64, string $mime): array
    {
        $prompt = <<<TXT
        Esta es la etiqueta o placa de datos de un electrodoméstico. Devolvé SOLO un JSON con:
        - nombre: nombre del aparato (ej. "Refrigerador")
        - watts: potencia en watts. Si dice "amperios" o "A", convertí con W = A × 120.
        - categoria: una de estas: refrigeracion, lavado, cocina, clima, entretenimiento, iluminacion, otro
        - notas: una línea con cómo lo calculaste
        Si no se puede leer la potencia, devolvé watts en null y explicalo en notas.
        TXT;

        $schema = [
            'type' => 'OBJECT',
            'properties' => [
                'nombre'    => ['type' => 'STRING'],
                'watts'     => ['type' => 'NUMBER', 'nullable' => true],
                'categoria' => ['type' => 'STRING'],
                'notas'     => ['type' => 'STRING'],
            ],
            'required' => ['nombre', 'categoria'],
        ];

        return $this->llamada($base64, $mime, $prompt, $schema);
    }

    /* ---------------- Plan de ahorro redactado por IA ---------------- */

    public function generarPlan(array $datos): array
    {
        $json = json_encode($datos, JSON_UNESCAPED_UNICODE);
        $prompt = <<<TXT
        Sos un asesor de ahorro energético para familias y pequeños negocios de Centroamérica.
        Recibís este diagnóstico en JSON: {$json}
        Devolvé SOLO un JSON con:
        - resumen: 2 frases habladas, motivadoras y sin clichés, mencionando los números reales.
        - acciones: 4 o 5 objetos con:
            titulo (corto), accion (qué hacer exactamente, en 1 o 2 frases),
            costo_estimado_usd (cuánto cuesta hacerlo, número, 0 si es gratis),
            ahorro_mensual_usd (cuánto se ahorra al mes, número, realista)
        - reto: objeto con titulo ("Reto familiar de 30 días"), meta_usd (suma de ahorro),
          pasos (4 strings, uno por semana)
        El ahorro mensual debe ser coherente con el consumo indicado: nunca supere el 35% del gasto
        mensual total. Hablá como persona, sin lenguaje corporativo.
        TXT;

        $schema = [
            'type' => 'OBJECT',
            'properties' => [
                'resumen'  => ['type' => 'STRING'],
                'acciones' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'titulo'             => ['type' => 'STRING'],
                            'accion'             => ['type' => 'STRING'],
                            'costo_estimado_usd' => ['type' => 'NUMBER'],
                            'ahorro_mensual_usd' => ['type' => 'NUMBER'],
                        ],
                        'required' => ['titulo', 'accion', 'costo_estimado_usd', 'ahorro_mensual_usd'],
                    ],
                ],
                'reto' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'titulo'   => ['type' => 'STRING'],
                        'meta_usd' => ['type' => 'NUMBER'],
                        'pasos'    => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                    ],
                    'required' => ['titulo', 'meta_usd', 'pasos'],
                ],
            ],
            'required' => ['resumen', 'acciones', 'reto'],
        ];

        $plan = $this->llamada('', '', $prompt, $schema);
        $plan['fuente'] = 'ia';
        $plan['ahorro_total_usd'] = round((float) array_sum(array_column($plan['acciones'] ?? [], 'ahorro_mensual_usd')), 2);
        return $plan;
    }

    /* ---------------- Recibo de agua → JSON ---------------- */

    public function extraerReciboAgua(string $base64, string $mime): array
    {
        [$prompt, $schema] = self::cargarPrompt('recibo_agua');
        return $this->llamada($base64, $mime, $prompt, $schema);
    }

    /* ---------------- Residuo → clasificación de reciclaje ---------------- */

    public function clasificarResiduo(string $base64, string $mime): array
    {
        [$prompt, $schema] = self::cargarPrompt('residuo');
        return $this->llamada($base64, $mime, $prompt, $schema);
    }

    /* ---------------- Plan de ahorro de agua ---------------- */

    public function generarPlanAgua(array $datos): array
    {
        [$prompt, $schema] = self::cargarPrompt('plan_agua');
        $prompt = str_replace('{DIAGNOSTICO}', json_encode($datos, JSON_UNESCAPED_UNICODE), $prompt);

        $plan = $this->llamada('', '', $prompt, $schema);
        $plan['fuente'] = 'ia';
        $plan['ahorro_usd_total']    = round((float) array_sum(array_column($plan['acciones'] ?? [], 'ahorro_usd_mes')), 2);
        $plan['ahorro_litros_total'] = round((float) array_sum(array_column($plan['acciones'] ?? [], 'ahorro_litros_mes')), 0);
        return $plan;
    }

    /* ---------------- Plan de reciclaje ---------------- */

    public function generarPlanReciclaje(array $datos): array
    {
        [$prompt, $schema] = self::cargarPrompt('plan_reciclaje');
        $prompt = str_replace('{DIAGNOSTICO}', json_encode($datos, JSON_UNESCAPED_UNICODE), $prompt);

        $plan = $this->llamada('', '', $prompt, $schema);
        $plan['fuente'] = 'ia';
        $plan['ingreso_estimado_usd'] = round((float) array_sum(array_column($plan['acciones'] ?? [], 'valor_estimado_usd')), 2);
        return $plan;
    }

    /* ---------------- Plan unificado (energía + agua + reciclaje) ---------------- */

    public function generarPlanUnificado(array $datos): array
    {
        [$prompt, $schema] = self::cargarPrompt('plan_unificado');
        $prompt = str_replace('{DIAGNOSTICO}', json_encode($datos, JSON_UNESCAPED_UNICODE), $prompt);

        $plan = $this->llamada('', '', $prompt, $schema);
        $plan['fuente'] = 'ia';
        $plan['ahorro_usd_total'] = round((float) array_sum(array_column($plan['acciones'] ?? [], 'ahorro_usd_mes')), 2);
        return $plan;
    }

    /** Carga un prompt + su esquema JSON desde /prompts/<clave>.json */
    private static function cargarPrompt(string $clave): array
    {
        $ruta = dirname(__DIR__) . '/prompts/' . basename($clave) . '.json';
        if (!is_file($ruta)) {
            throw new RuntimeException("Falta el archivo de prompt: prompts/{$clave}.json");
        }
        $datos = json_decode((string) file_get_contents($ruta), true);
        if (!is_array($datos) || empty($datos['prompt']) || empty($datos['schema'])) {
            throw new RuntimeException("El prompt prompts/{$clave}.json está mal formado.");
        }
        return [(string) $datos['prompt'], $datos['schema']];
    }

    /* ---------------- Llamada HTTP ---------------- */

    private function llamada(string $base64, string $mime, string $prompt, array $schema): array
    {
        if (!$this->disponible()) {
            throw new RuntimeException('Falta la API key de Gemini (config.php → gemini.api_key).');
        }

        $parts = [];
        if ($base64 !== '') {
            $parts[] = ['inline_data' => ['mime_type' => $mime, 'data' => $base64]];
        }
        $parts[] = ['text' => $prompt];

        $payload = [
            'contents'        => [['parts' => $parts]],
            'generationConfig' => [
                'temperature'       => 0.2,
                'responseMimeType'  => 'application/json',
                'responseSchema'    => $schema,
            ],
        ];

        $errores = [];
        foreach ($this->modelos as $modelo) {
            $resultado = $this->contra($modelo, $payload);
            if (is_array($resultado)) {
                return $resultado;
            }
            $errores[] = $modelo . ': ' . $resultado;
        }

        throw new RuntimeException('Gemini no respondió (' . implode(' | ', $errores) . ').');
    }

    /**
     * Intenta un modelo. Devuelve el JSON o una cadena con el error.
     * Los 404 y 503 disparan el siguiente modelo de la lista.
     */
    private function contra(string $modelo, array $payload): array|string
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
             . rawurlencode($modelo) . ':generateContent?key=' . rawurlencode($this->apiKey);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $respuesta = curl_exec($ch);
        $error     = curl_error($ch);
        $status    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($respuesta === false) {
            return 'sin conexión: ' . $error;
        }

        $datos = json_decode((string) $respuesta, true);
        if ($status !== 200) {
            $msg = $datos['error']['message'] ?? ('HTTP ' . $status);
            if (in_array($status, [404, 503], true)) {
                return 'modelo no disponible (' . $status . ')';   // → probamos el siguiente
            }
            return $msg;                                          // error real: no insiste
        }

        $texto = $datos['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $json  = json_decode((string) $texto, true);
        if (!is_array($json)) {
            return 'no devolvió JSON válido';
        }
        return $json;
    }
}
