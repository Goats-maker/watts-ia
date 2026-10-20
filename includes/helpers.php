<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';   // db() vive en db.php; se carga una sola vez

/* ------------------------------------------------------------------
 |  Configuración, salida y utilidades varias
 * ------------------------------------------------------------------ */

function cfg(string $ruta, mixed $def = null): mixed
{
    static $config = null;
    if ($config === null) {
        $config = require dirname(__DIR__) . '/config.php';
    }
    $valor = $config;
    foreach (explode('.', $ruta) as $parte) {
        if (!is_array($valor) || !array_key_exists($parte, $valor)) {
            return $def;
        }
        $valor = $valor[$parte];
    }
    return $valor;
}

function e(mixed $texto): string
{
    return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
}

function usd(float|int|string|null $n): string
{
    return '$' . number_format((float) $n, 2);
}

function num(float|int|string|null $n, int $dec = 1): string
{
    return number_format((float) $n, $dec, '.', ',');
}

function redir(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function jsonOut(array $datos, int $codigo = 200): never
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function esAjax(): bool
{
    return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
}

/* ------------------------------------------------------------------
 |  Fórmulas del proyecto
 * ------------------------------------------------------------------ */

/** kWh/mes = watts × horas al día × 30 ÷ 1000 */
function kwhMes(float $watts, float $horasDia): float
{
    return ($watts * $horasDia * 30) / 1000;
}

/** Costo = kWh × tarifa del recibo */
function costoDe(float $kwh, float $tarifa): float
{
    return $kwh * $tarifa;
}

/** % que representa un aparato sobre la factura */
function pctFactura(float $kwhAparato, float $kwhTotal): float
{
    return $kwhTotal > 0 ? ($kwhAparato / $kwhTotal) * 100 : 0.0;
}

/** Semáforo energético: rojo ≥25%, amarillo ≥12%, verde abajo */
function semaforo(float $pct): string
{
    if ($pct >= 25) return 'rojo';
    if ($pct >= 12) return 'amarillo';
    return 'verde';
}

function etiquetaSemaforo(string $s): string
{
    return ['rojo' => 'Rojo', 'amarillo' => 'Amarillo', 'verde' => 'Verde'][$s] ?? $s;
}

/** Equivalencias tangibles */
function kgCo2(float $kwh): float
{
    return $kwh * (float) cfg('co2_por_kwh', 0.42);
}

function pupusas(float $usd): float
{
    $precio = (float) cfg('precio_pupusa', 0.15);
    return $precio > 0 ? $usd / $precio : 0;
}

/* ------------------------------------------------------------------
 |  Datos
 * ------------------------------------------------------------------ */

function hogarActual(): ?array
{
    $id = (int) (cfg('hogar_default') ?: 1);
    $st = db()->prepare('SELECT * FROM hogares WHERE id = ?');
    $st->execute([$id]);
    $hogar = $st->fetch();
    return $hogar ?: null;
}

function tarifaDe(?array $hogar): float
{
    return $hogar ? (float) $hogar['tarifa'] : (float) cfg('tarifa_default', 0.21);
}

function historial(int $hogarId): array
{
    $st = db()->prepare('SELECT * FROM recibos WHERE hogar_id = ? ORDER BY fecha ASC, id ASC');
    $st->execute([$hogarId]);
    return $st->fetchAll();
}

function ultimoRecibo(int $hogarId): ?array
{
    $st = db()->prepare('SELECT * FROM recibos WHERE hogar_id = ? ORDER BY fecha DESC, id DESC LIMIT 1');
    $st->execute([$hogarId]);
    $r = $st->fetch();
    return $r ?: null;
}

function listaAparatos(int $hogarId): array
{
    $st = db()->prepare('SELECT * FROM aparatos WHERE hogar_id = ? ORDER BY nombre');
    $st->execute([$hogarId]);
    return $st->fetchAll();
}

/**
 * Ranking de "devoradores de watts": cada aparato con su kWh/mes,
 * costo, % de la factura y semáforo.
 */
function rankingAparatos(int $hogarId, float $tarifa, float $kwhFactura): array
{
    $filas = [];
    foreach (listaAparatos($hogarId) as $a) {
        $kwh = kwhMes((float) $a['watts'], (float) $a['horas_dia']);
        $pct = pctFactura($kwh, $kwhFactura);
        $filas[] = [
            'id'     => (int) $a['id'],
            'nombre' => $a['nombre'],
            'categoria' => $a['categoria'],
            'watts'  => (float) $a['watts'],
            'horas'  => (float) $a['horas_dia'],
            'kwh'    => $kwh,
            'costo'  => costoDe($kwh, $tarifa),
            'pct'    => $pct,
            'semaforo' => semaforo($pct),
        ];
    }
    usort($filas, fn($x, $y) => $y['kwh'] <=> $x['kwh']);
    return $filas;
}

/** Promedio de kWh del último recibo de cada hogar de la comunidad */
function promedioComunidad(): array
{
    $filas = db()->query(
        'SELECT h.id, h.alias, h.tipo,
                (SELECT kwh FROM recibos r WHERE r.hogar_id = h.id ORDER BY fecha DESC, id DESC LIMIT 1) AS kwh
         FROM hogares h'
    )->fetchAll();

    $valores = [];
    foreach ($filas as $f) {
        if ($f['kwh'] !== null) {
            $valores[] = ['nombre' => $f['alias'], 'tipo' => $f['tipo'], 'kwh' => (float) $f['kwh']];
        }
    }
    $promedio = $valores ? array_sum(array_column($valores, 'kwh')) / count($valores) : 0.0;

    return ['hogares' => $valores, 'promedio' => $promedio];
}

/**
 * Detecta si un mes subió más del 20% → alerta de anomalía.
 * $campo permite revisar 'kwh' (energía) o 'm3' (agua) sin duplicar código.
 * Devuelve ['hay' => bool, 'pct' => float, 'anterior' => array, 'ultimo' => array]
 */
function detectarAnomalia(array $historial, string $campo = 'kwh'): array
{
    $n = count($historial);
    if ($n < 2) {
        return ['hay' => false, 'pct' => 0.0];
    }
    $anterior = $historial[$n - 2];
    $ultimo   = $historial[$n - 1];
    $base = (float) ($anterior[$campo] ?? 0);
    $pct  = $base > 0 ? (((float) ($ultimo[$campo] ?? 0) - $base) / $base) * 100 : 0.0;

    return [
        'hay'      => $pct > 20,
        'pct'      => $pct,
        'anterior' => $anterior,
        'ultimo'   => $ultimo,
    ];
}

/** Los pasos pueden llegar con "Semana 1:" ya escrito (IA o local): evita repetirlo en la vista. */
function pasoSinPrefijo(string $paso): string
{
    return preg_replace('/^\s*semana\s*\d+\s*[:.-]\s*/iu', '', $paso) ?? $paso;
}

/* ------------------------------------------------------------------
 |  Plan B: planes de ahorro pre-generados por rango de consumo
 * ------------------------------------------------------------------ */

function planLocal(float $kwh, array $ranking): array
{
    $acc = [];

    if ($kwh >= 200) {
        $acc[] = ['titulo' => 'Revisar el refrigerador', 'accion' => 'Limpia el serpentín trasero y revisa la goma de la puerta; un frigo sucio gasta hasta un 30% más.', 'costo_estimado_usd' => 5, 'ahorro_mensual_usd' => 8.5];
        $acc[] = ['titulo' => 'Bajar el calentón al mínimo', 'accion' => 'Si tenés calentón de agua eléctrico, usá un temporizador y bájalo a 55 °C.', 'costo_estimado_usd' => 12, 'ahorro_mensual_usd' => 7.2];
        $acc[] = ['titulo' => 'Cambiar a LED lo que falte', 'accion' => 'Reemplazá los focos incandescentes por LED en los lugares de más uso.', 'costo_estimado_usd' => 15, 'ahorro_mensual_usd' => 4.1];
    } elseif ($kwh >= 100) {
        $acc[] = ['titulo' => 'Usar la plancha con cabeza', 'accion' => 'Planchá de una sola vez por semana y con la ropa algo húmeda.', 'costo_estimado_usd' => 0, 'ahorro_mensual_usd' => 3.4];
        $acc[] = ['titulo' => 'Ventilador en vez de aire', 'accion' => 'Un ventilador usa casi 10 veces menos que un aire acondicionado.', 'costo_estimado_usd' => 25, 'ahorro_mensual_usd' => 9.0];
        $acc[] = ['titulo' => 'Apagar los equipos en espera', 'accion' => 'TV, micro y cargadores en regleta con interruptor: cortan el consumo fantasma.', 'costo_estimado_usd' => 8, 'ahorro_mensual_usd' => 2.5];
    } else {
        $acc[] = ['titulo' => 'Mantener el buen hábito', 'accion' => 'Tu consumo ya es bajo. Revisá igual que nada se quede encendido de noche.', 'costo_estimado_usd' => 0, 'ahorro_mensual_usd' => 1.5];
        $acc[] = ['titulo' => 'Revisar la tarifa plana', 'accion' => 'Preguntá a la distribuidora si te conviene un plan con tarifa reducida.', 'costo_estimado_usd' => 0, 'ahorro_mensual_usd' => 2.0];
    }

    // Ajuste según el aparato más grande detectado
    $top = $ranking[0] ?? null;
    if ($top && $top['pct'] >= 30) {
        $acc[] = [
            'titulo'              => 'Atacar al más grande: ' . $top['nombre'],
            'accion'              => 'Es tu aparato más caro (' . number_format($top['pct'], 0) . '% de la factura). Revisalo, úsalo menos horas o planificá su reemplazo.',
            'costo_estimado_usd'  => 20,
            'ahorro_mensual_usd'  => round($top['costo'] * 0.20, 2),
        ];
    }

    $total = array_sum(array_column($acc, 'ahorro_mensual_usd'));

    return [
        'fuente'   => 'local',
        'resumen'  => 'Plan de ahorro generado sin internet (plan pre-generado por rango de consumo: '
            . number_format($kwh, 0) . ' kWh/mes).',
        'acciones' => $acc,
        'reto'     => [
            'titulo'  => 'Reto familiar de 30 días',
            'meta_usd' => round($total, 2),
            'pasos'   => [
                'Apagar todo lo que esté en espera al salir de casa.',
                'Planchar una sola vez por semana y bajar la temperatura del frigo.',
                'Cambiar a LED lo que falte en las luces de más uso.',
                'Medir qué tanto bajó la factura y celebrar en familia.',
            ],
        ],
        'ahorro_total_usd' => round($total, 2),
    ];
}

/* ------------------------------------------------------------------
 |  Módulo de AGUA — fórmulas y Plan B
 * ------------------------------------------------------------------ */

/** Historial de recibos de AGUA del hogar (más viejo primero). */
function historialAgua(int $hogarId): array
{
    $st = db()->prepare(
        "SELECT * FROM recibos WHERE hogar_id = ? AND tipo_servicio = 'agua' ORDER BY fecha ASC, id ASC"
    );
    $st->execute([$hogarId]);
    return $st->fetchAll();
}

/** Último recibo de AGUA del hogar. */
function ultimoReciboAgua(int $hogarId): ?array
{
    $st = db()->prepare(
        "SELECT * FROM recibos WHERE hogar_id = ? AND tipo_servicio = 'agua' ORDER BY fecha DESC, id DESC LIMIT 1"
    );
    $st->execute([$hogarId]);
    $r = $st->fetch();
    return $r ?: null;
}

/** Tarifa del agua del hogar (USD por m³). */
function tarifaAguaDe(?array $hogar): float
{
    return $hogar ? (float) $hogar['tarifa_agua'] : (float) cfg('tarifa_agua_default', 1.2);
}

/** Personas del hogar (para los usos que se multiplican por persona). */
function personasDe(?array $hogar): int
{
    return max(1, (int) ($hogar['personas'] ?? 4));
}

function listaUsosAgua(int $hogarId): array
{
    $st = db()->prepare('SELECT * FROM usos_agua WHERE hogar_id = ? ORDER BY nombre');
    $st->execute([$hogarId]);
    return $st->fetchAll();
}

/** Litros al día de un solo uso: litros por uso × veces al día (× personas si aplica). */
function litrosDiaUso(array $uso, int $personas): float
{
    $base = (float) $uso['litros_por_uso'] * (float) $uso['veces_dia'];
    return !empty($uso['por_persona']) ? $base * $personas : $base;
}

/** Litros al día de todos los usos del hogar. */
function litrosDiaTotales(int $hogarId, int $personas): float
{
    $total = 0.0;
    foreach (listaUsosAgua($hogarId) as $uso) {
        $total += litrosDiaUso($uso, $personas);
    }
    return $total;
}

/** m³ al mes = litros al día × 30 ÷ 1000 */
function m3Mes(float $litrosDia): float
{
    return ($litrosDia * 30) / 1000;
}

/**
 * Ranking de "devoradores de agua": cada uso con sus m³/mes,
 * costo, % del consumo y semáforo (mismos umbrales que el de energía).
 */
function rankingUsosAgua(int $hogarId, int $personas, float $tarifaAgua, float $m3Base): array
{
    $filas = [];
    foreach (listaUsosAgua($hogarId) as $u) {
        $litros = litrosDiaUso($u, $personas);
        $m3     = m3Mes($litros);
        $pct    = $m3Base > 0 ? ($m3 / $m3Base) * 100 : 0.0;
        $filas[] = [
            'id'        => (int) $u['id'],
            'nombre'    => $u['nombre'],
            'categoria' => $u['categoria'],
            'litros_uso'=> (float) $u['litros_por_uso'],
            'veces'     => (float) $u['veces_dia'],
            'por_persona' => !empty($u['por_persona']),
            'litros_dia'=> $litros,
            'm3'        => $m3,
            'costo'     => $m3 * $tarifaAgua,
            'pct'       => $pct,
            'semaforo'  => semaforo($pct),
        ];
    }
    usort($filas, fn($x, $y) => $y['m3'] <=> $x['m3']);
    return $filas;
}

/** Plan de agua sin internet: plantillas por rango de consumo (m³/mes). */
function planAguaLocal(float $m3, array $ranking): array
{
    $acc = [];

    if ($m3 >= 20) {
        $acc[] = ['titulo' => 'Revisar fugas de sanitarios', 'accion' => 'Echá un poco de colorante en el tanque: si el inodoro lo baja sin que lo uses, hay fuga.', 'costo_estimado_usd' => 2, 'ahorro_litros_mes' => 1200, 'ahorro_usd_mes' => 1.4];
        $acc[] = ['titulo' => 'Ducha de 5 minutos', 'accion' => 'Poné un temporizador o una canción: menos de 5 minutos por persona al día.', 'costo_estimado_usd' => 0, 'ahorro_litros_mes' => 1800, 'ahorro_usd_mes' => 2.2];
        $acc[] = ['titulo' => 'Regar temprano y con regadera', 'accion' => 'Regá temprano por la mañana y con regadera en vez de manguera.', 'costo_estimado_usd' => 6, 'ahorro_litros_mes' => 900, 'ahorro_usd_mes' => 1.1];
    } elseif ($m3 >= 12) {
        $acc[] = ['titulo' => 'Cubeta en vez de manguera', 'accion' => 'Lavar el carro y el patio con cubeta ahorra mucha agua frente a la manguera.', 'costo_estimado_usd' => 5, 'ahorro_litros_mes' => 800, 'ahorro_usd_mes' => 1.0];
        $acc[] = ['titulo' => 'Lavar ropa con más ropa por carga', 'accion' => 'Usá la lavadora llena y el ciclo corto: menos cargas, menos litros.', 'costo_estimado_usd' => 0, 'ahorro_litros_mes' => 600, 'ahorro_usd_mes' => 0.7];
        $acc[] = ['titulo' => 'Recoger el agua fría de la ducha', 'accion' => 'Mientras esperás el agua caliente, juntala en un balde y usala para el inodoro o las plantas.', 'costo_estimado_usd' => 8, 'ahorro_litros_mes' => 500, 'ahorro_usd_mes' => 0.6];
    } else {
        $acc[] = ['titulo' => 'Mantener el buen hábito', 'accion' => 'Tu consumo ya es bajo. Revisá que ninguna llave gotee y listo.', 'costo_estimado_usd' => 0, 'ahorro_litros_mes' => 300, 'ahorro_usd_mes' => 0.4];
        $acc[] = ['titulo' => 'Reutilizar el agua de enjuague', 'accion' => 'El agua de lavar frutas o trastes sirve para las plantas.', 'costo_estimado_usd' => 0, 'ahorro_litros_mes' => 200, 'ahorro_usd_mes' => 0.3];
    }

    $top = $ranking[0] ?? null;
    if ($top && $top['pct'] >= 30) {
        $acc[] = [
            'titulo'             => 'Atacar al más grande: ' . $top['nombre'],
            'accion'             => 'Es tu uso más caro (' . number_format($top['pct'], 0) . '% del consumo). Reducí su frecuencia o duración.',
            'costo_estimado_usd' => 0,
            'ahorro_litros_mes'  => round($top['m3'] * 1000 * 0.15, 0),
            'ahorro_usd_mes'     => round($top['costo'] * 0.15, 2),
        ];
    }

    $usdTotal    = array_sum(array_column($acc, 'ahorro_usd_mes'));
    $litrosTotal = array_sum(array_column($acc, 'ahorro_litros_mes'));

    return [
        'fuente'   => 'local',
        'resumen'  => 'Plan de agua generado sin internet (plantilla por rango de consumo: '
            . number_format($m3, 1) . ' m³/mes).',
        'acciones' => $acc,
        'reto'     => [
            'titulo'      => 'Reto del agua · 30 días',
            'meta_litros' => round($litrosTotal, 0),
            'pasos'       => [
                'Duchas de 5 minutos y llaves cerradas mientras nos enjabonamos.',
                'Usar cubeta y regadera en vez de manguera.',
                'Recoger el agua fría de la ducha y reutilizarla.',
                'Medir cuánto bajó el recibo de agua y celebrar en familia.',
            ],
        ],
        'ahorro_usd_total'    => round($usdTotal, 2),
        'ahorro_litros_total' => round($litrosTotal, 0),
    ];
}

/* ------------------------------------------------------------------
 |  Módulo de RECICLAJE — datos, equivalencias y Plan B
 * ------------------------------------------------------------------ */

/** Últimos residuos registrados por el hogar (más recientes primero). */
function listaResiduos(int $hogarId, int $limite = 50): array
{
    $st = db()->prepare(
        'SELECT r.*, g.categoria, g.destino
         FROM residuos r
         LEFT JOIN guias_reciclaje g ON g.material = r.material
         WHERE r.hogar_id = ?
         ORDER BY r.semana DESC, r.id DESC
         LIMIT ' . max(1, $limite)
    );
    $st->execute([$hogarId]);
    return $st->fetchAll();
}

/** Kilos reciclados por semana (para la gráfica). */
function residuosPorSemana(int $hogarId, int $semanas = 8): array
{
    $st = db()->prepare(
        'SELECT semana, SUM(kg_aprox) AS kg, COUNT(*) AS registros
         FROM residuos
         WHERE hogar_id = ? AND semana >= (CURDATE() - INTERVAL ? WEEK)
         GROUP BY semana ORDER BY semana ASC'
    );
    $st->execute([$hogarId, $semanas]);
    return $st->fetchAll();
}

/** Resumen por material: cuántos kg y qué % del total. */
function residuosPorMaterial(int $hogarId): array
{
    $st = db()->prepare(
        'SELECT r.material, g.categoria, SUM(r.kg_aprox) AS kg, SUM(r.cantidad) AS cantidad
         FROM residuos r
         LEFT JOIN guias_reciclaje g ON g.material = r.material
         WHERE r.hogar_id = ?
         GROUP BY r.material, g.categoria
         ORDER BY kg DESC'
    );
    $st->execute([$hogarId]);
    $filas = $st->fetchAll();
    $total = array_sum(array_column($filas, 'kg'));
    foreach ($filas as &$f) {
        $f['pct'] = $total > 0 ? ((float) $f['kg'] / $total) * 100 : 0.0;
    }
    unset($f);
    return ['materiales' => $filas, 'total_kg' => $total];
}

/** Catálogo completo de guías (Plan B). */
function guiasReciclaje(): array
{
    return db()->query('SELECT * FROM guias_reciclaje ORDER BY material')->fetchAll();
}

/** Guía de un material (busca exacta y después parecida). */
function guiaDe(string $material): ?array
{
    $st = db()->prepare('SELECT * FROM guias_reciclaje WHERE material = ?');
    $st->execute([$material]);
    if ($g = $st->fetch()) {
        return $g;
    }
    $st = db()->prepare('SELECT * FROM guias_reciclaje WHERE material LIKE ? LIMIT 1');
    $st->execute(['%' . $material . '%']);
    $g = $st->fetch();
    return $g ?: null;
}

/**
 * Clasificación sin internet (Plan B): responde desde el catálogo local
 * con la misma forma que devolvería la IA.
 */
function clasificacionLocal(string $material): array
{
    $g = guiaDe($material);
    if (!$g) {
        return [
            'material'         => $material,
            'tipo_plastico'    => null,
            'reciclable'       => 'depende',
            'preparacion'      => ['Sepáralo de la basura común', 'Preguntá en tu centro de acopio si lo reciben'],
            'reutilizacion'    => ['Buscale otra función antes de tirarlo'],
            'destino_sugerido' => 'Centro de acopio',
            'nivel_confianza'  => 0.4,
            'notas'            => 'Guía local: este material no está en el catálogo.',
            'fuente'           => 'local',
        ];
    }
    return [
        'material'         => $g['material'],
        'tipo_plastico'    => null,
        'reciclable'       => $g['reciclable'],
        'preparacion'      => json_decode((string) $g['pasos'], true) ?: [],
        'reutilizacion'    => json_decode((string) $g['reutilizacion'], true) ?: [],
        'destino_sugerido' => $g['destino'],
        'nivel_confianza'  => 0.8,
        'notas'            => 'Guía local del catálogo (no necesitó internet).',
        'fuente'           => 'local',
    ];
}

/**
 * Equivalencias tangibles del reciclaje.
 * Los factores son APROXIMADOS y configurables en config.php.
 */
function equivalenciasReciclaje(array $porMaterial): array
{
    $factoresCo2   = (array) cfg('co2_por_kg_reciclado', []);
    $factoresValor = (array) cfg('valor_usd_por_kg', []);

    $co2 = 0.0;
    $valor = 0.0;
    $detalle = [];

    foreach ($porMaterial as $fila) {
        $kg       = (float) ($fila['kg'] ?? 0);
        $material = (string) ($fila['material'] ?? '');
        $cat      = (string) ($fila['categoria'] ?? '');
        $cat      = $cat !== '' && isset($factoresCo2[$cat]) ? $cat : 'otro';

        $co2Fila   = $kg * (float) ($factoresCo2[$cat] ?? 0.5);
        $valorFila = $kg * (float) ($factoresValor[$cat] ?? 0.10);

        $co2   += $co2Fila;
        $valor += $valorFila;
        $detalle[] = [
            'material' => $material,
            'kg'       => $kg,
            'co2_kg'   => round($co2Fila, 2),
            'valor_usd'=> round($valorFila, 2),
        ];
    }

    return [
        'co2_kg'      => round($co2, 1),
        'valor_usd'   => round($valor, 2),
        'aproximado'  => true,
        'detalle'     => $detalle,
    ];
}

/** Plan de reciclaje sin internet: plantillas por kilos registrados al mes. */
function planReciclajeLocal(array $porMaterial): array
{
    $totalKg = (float) ($porMaterial['total_kg'] ?? 0);
    $materiales = $porMaterial['materiales'] ?? [];
    $top = $materiales[0] ?? null;

    $acc = [];
    if ($totalKg >= 15) {
        $acc[] = ['titulo' => 'Separar en casa', 'accion' => 'Poné dos bolsos: uno para plásticos y papel, otro para lo demás. Etiquetalos con cartón.', 'kg_meta' => 8, 'valor_estimado_usd' => 1.6];
        $acc[] = ['titulo' => 'Día fijo de entrega', 'accion' => 'Elegí un día del mes para llevar todo al centro de acopio y no acumular.', 'kg_meta' => 5, 'valor_estimado_usd' => 1.2];
        $acc[] = ['titulo' => 'Aprovechar el material más frecuente', 'accion' => 'Si el más repetido es el cartón, guardalo plano y seco para reutilizar cajas.', 'kg_meta' => 3, 'valor_estimado_usd' => 0.3];
    } elseif ($totalKg >= 5) {
        $acc[] = ['titulo' => 'Sumar un material más', 'accion' => 'Aparte de lo que ya separás, empezá con las botellas PET: enjuagá y aplastá.', 'kg_meta' => 3, 'valor_estimado_usd' => 0.8];
        $acc[] = ['titulo' => 'Reutilizar antes de reciclar', 'accion' => 'Cada frasco o caja que dure tres usos más ya no es residuo.', 'kg_meta' => 2, 'valor_estimado_usd' => 0.5];
    } else {
        $acc[] = ['titulo' => 'Empezar por lo más simple', 'accion' => 'Juntá papel y cartón durante una semana: es lo que más pesa y lo más fácil de llevar.', 'kg_meta' => 2, 'valor_estimado_usd' => 0.2];
        $acc[] = ['titulo' => 'Revisar la basura antes de tirar', 'accion' => 'Cada día, mirá qué tiraste: siempre hay algo reciclable o reutilizable.', 'kg_meta' => 1, 'valor_estimado_usd' => 0.2];
    }

    if ($top && (float) $top['pct'] >= 30) {
        $acc[] = [
            'titulo'             => 'Atacar al más repetido: ' . $top['material'],
            'accion'             => 'Es tu material más frecuente (' . number_format((float) $top['pct'], 0) . '% de lo que registraste). Buscale una salida fija.',
            'kg_meta'            => round(max(1, (float) $top['kg'] * 0.3), 1),
            'valor_estimado_usd' => 0.4,
        ];
    }

    $metaKg = round(array_sum(array_column($acc, 'kg_meta')), 1);

    return [
        'fuente'   => 'local',
        'resumen'  => 'Plan de reciclaje generado sin internet (plantilla por kilos registrados: '
            . number_format($totalKg, 1) . ' kg).',
        'acciones' => $acc,
        'pasos'    => [
            'Separar en casa un material nuevo por semana.',
            'Enjuagar y aplastar todo lo que salga reciclable.',
            'Anotar cuántos kilos juntaste cada fin de semana.',
            'Llevar el acopio al centro que corresponda y anotar qué se reutilizó.',
        ],
        'meta_kg'  => $metaKg,
        'ingreso_estimado_usd' => round(array_sum(array_column($acc, 'valor_estimado_usd')), 2),
    ];
}

/** Directorio comunitario de centros de acopio (solo datos públicos de lugares). */
function centrosAcopio(): array
{
    return db()->query('SELECT * FROM centros_acopio ORDER BY zona, nombre')->fetchAll();
}

/** Campañas y jornadas del panel comunitario. */
function campanas(): array
{
    return db()->query('SELECT * FROM campanas ORDER BY fecha IS NULL, fecha, id DESC')->fetchAll();
}

/**
 * Panel comunitario: datos agregados y anónimos (sin alias de hogares ni nada
 * que permita identificar a una familia).
 */
function resumenComunidad(): array
{
    // Kilos reciclados por material en toda la comunidad
    $porMaterial = db()->query(
        'SELECT r.material, SUM(r.kg_aprox) AS kg
         FROM residuos r
         GROUP BY r.material ORDER BY kg DESC LIMIT 10'
    )->fetchAll();

    $totalKg = array_sum(array_column($porMaterial, 'kg'));

    // Ahorros del último plan de cada hogar (energía y agua)
    $energia = (float) db()->query(
        'SELECT COALESCE(SUM(t.ahorro_total), 0) FROM (
            SELECT hogar_id, MAX(id) AS max_id FROM planes_ahorro GROUP BY hogar_id
         ) ult
         JOIN planes_ahorro t ON t.id = ult.max_id'
    )->fetchColumn();

    $agua = (float) db()->query(
        'SELECT COALESCE(SUM(t.ahorro_usd), 0) FROM (
            SELECT hogar_id, MAX(id) AS max_id FROM planes_agua GROUP BY hogar_id
         ) ult
         JOIN planes_agua t ON t.id = ult.max_id'
    )->fetchColumn();

    $litros = (float) db()->query(
        'SELECT COALESCE(SUM(t.ahorro_litros), 0) FROM (
            SELECT hogar_id, MAX(id) AS max_id FROM planes_agua GROUP BY hogar_id
         ) ult
         JOIN planes_agua t ON t.id = ult.max_id'
    )->fetchColumn();

    $hogares = (int) db()->query('SELECT COUNT(*) FROM hogares')->fetchColumn();
    $residuos = (int) db()->query('SELECT COUNT(*) FROM residuos')->fetchColumn();

    return [
        'materiales'      => $porMaterial,
        'total_kg'        => $totalKg,
        'ahorro_usd'      => round($energia + $agua, 2),
        'ahorro_litros'   => round($litros, 0),
        'ahorro_m3'       => round($litros / 1000, 1),
        'hogares'         => $hogares,
        'registros'       => $residuos,
    ];
}

/* ------------------------------------------------------------------
 |  PLAN UNIFICADO Y RETO GUARDIANES (los tres pilares a la vez)
 * ------------------------------------------------------------------ */

/**
 * Diagnóstico de los tres pilares para el plan unificado.
 * SOLO números: nunca alias, nombres ni datos personales.
 */
function datosUnificados(int $hogarId): array
{
    $stH = db()->prepare('SELECT * FROM hogares WHERE id = ?');
    $stH->execute([$hogarId]);
    $hogar = $stH->fetch() ?: null;

    $personas    = personasDe($hogar);
    $tarifa      = tarifaDe($hogar);
    $tarifaAgua  = tarifaAguaDe($hogar);

    $ultimoE  = ultimoRecibo($hogarId);
    $ultimoA  = ultimoReciboAgua($hogarId);
    $resumenR = residuosPorMaterial($hogarId);
    $equiv    = equivalenciasReciclaje($resumenR['materiales']);

    $litrosDia = litrosDiaTotales($hogarId, $personas);
    $rankingAp = rankingAparatos($hogarId, $tarifa, $ultimoE ? (float) $ultimoE['kwh'] : 0.0);

    // Último plan de cada pilar (si existe) para saber cuánto ya se comprometió.
    $stE = db()->prepare('SELECT ahorro_total FROM planes_ahorro WHERE hogar_id = ? ORDER BY id DESC LIMIT 1');
    $stE->execute([$hogarId]);
    $ahorroPlanE = (float) ($stE->fetchColumn() ?: 0);

    $stA = db()->prepare('SELECT ahorro_usd, ahorro_litros FROM planes_agua WHERE hogar_id = ? ORDER BY id DESC LIMIT 1');
    $stA->execute([$hogarId]);
    $planA = $stA->fetch() ?: null;

    return [
        'tipo_hogar' => $hogar['tipo'] ?? 'hogar',
        'personas'   => $personas,
        'energia'    => [
            'kwh_mes'      => $ultimoE ? (float) $ultimoE['kwh'] : 0.0,
            'monto_mes'    => $ultimoE ? (float) $ultimoE['monto'] : 0.0,
            'tarifa'       => $tarifa,
            'ahorro_plan'  => $ahorroPlanE,
            'top_devorador'=> ($rankingAp[0]['nombre'] ?? '') . ' (' . round($rankingAp[0]['pct'] ?? 0) . '% de la factura)',
        ],
        'agua'       => [
            'm3_mes'       => $ultimoA ? (float) $ultimoA['m3'] : round(m3Mes($litrosDia), 2),
            'monto_mes'    => $ultimoA ? (float) $ultimoA['monto'] : 0.0,
            'tarifa'       => $tarifaAgua,
            'litros_dia'   => round($litrosDia, 0),
            'ahorro_plan'  => $planA ? (float) $planA['ahorro_usd'] : 0.0,
            'ahorro_litros'=> $planA ? (int) $planA['ahorro_litros'] : 0,
        ],
        'reciclaje'  => [
            'kg_mes'         => round((float) $resumenR['total_kg'], 2),
            'co2_evitado_kg' => $equiv['co2_kg'],
            'valor_estimado' => $equiv['valor_usd'],
            'materiales_top' => array_slice(array_map(fn($m) => $m['material'], $resumenR['materiales']), 0, 3),
        ],
    ];
}

/** Plan unificado sin internet: una acción por pilar + reto de 4 semanas. */
function planUnificadoLocal(array $d): array
{
    $acc = [];

    // Energía
    $acc[] = [
        'pilar' => 'energia', 'titulo' => 'Apagar lo que está en espera',
        'accion' => 'Sacá de la corriente cargadores y TV cuando no se usan: es lo más fácil y no cuesta nada.',
        'costo_estimado_usd' => 0, 'ahorro_usd_mes' => 2.5, 'ahorro_litros_mes' => 0, 'kg_meta' => 0,
    ];
    if (!empty($d['energia']['top_devorador'])) {
        $acc[] = [
            'pilar' => 'energia', 'titulo' => 'Bajar el ritmo de ' . $d['energia']['top_devorador'],
            'accion' => 'Es lo que más consume: recudile 30 minutos de uso al día.',
            'costo_estimado_usd' => 0, 'ahorro_usd_mes' => 3.0, 'ahorro_litros_mes' => 0, 'kg_meta' => 0,
        ];
    }

    // Agua
    $acc[] = [
        'pilar' => 'agua', 'titulo' => 'Duchas de 5 minutos',
        'accion' => 'Poné un temporizador y cerrá la llave mientras te enjabonás.',
        'costo_estimado_usd' => 0, 'ahorro_usd_mes' => 2.2, 'ahorro_litros_mes' => 1800, 'kg_meta' => 0,
    ];
    $acc[] = [
        'pilar' => 'agua', 'titulo' => 'Revisar fugas de sanitarios',
        'accion' => 'Un gotero constante desperdicia miles de litros al mes: revisalo con colorante en el tanque.',
        'costo_estimado_usd' => 2, 'ahorro_usd_mes' => 1.5, 'ahorro_litros_mes' => 1200, 'kg_meta' => 0,
    ];

    // Reciclaje
    $acc[] = [
        'pilar' => 'reciclaje', 'titulo' => 'Separar plástico y cartón',
        'accion' => 'Dos bolsos en casa y enjuagar todo lo que salga reciclable.',
        'costo_estimado_usd' => 0, 'ahorro_usd_mes' => 0.5, 'ahorro_litros_mes' => 0, 'kg_meta' => 6,
    ];
    $acc[] = [
        'pilar' => 'reciclaje', 'titulo' => 'Reutilizar antes de tirar',
        'accion' => 'Cada frasco o caja que dure tres usos más ya no es basura.',
        'costo_estimado_usd' => 0, 'ahorro_usd_mes' => 0.5, 'ahorro_litros_mes' => 0, 'kg_meta' => 3,
    ];

    return [
        'fuente'   => 'local',
        'resumen'  => 'Plan general generado sin internet (plantilla con una acción por pilar). '
            . 'Energía: ' . usd($d['energia']['monto_mes']) . ' al mes · '
            . 'Agua: ' . num($d['agua']['m3_mes'], 1) . ' m³/mes · '
            . 'Reciclaje: ' . num($d['reciclaje']['kg_mes'], 1) . ' kg registrados.',
        'acciones' => $acc,
        'reto'     => [
            'titulo' => 'Reto Guardianes del Planeta · 30 días',
            'pasos'  => [
                'Semana 1: apagá lo que esté en espera y medí cuánto se nota.',
                'Semana 2: duchas de 5 minutos y revisá las fugas del baño.',
                'Semana 3: separá plástico y cartón, y llevá el acopio.',
                'Semana 4: compará los números con los del mes anterior y celebrá.',
            ],
        ],
        'ahorro_usd_total'    => round(array_sum(array_column($acc, 'ahorro_usd_mes')), 2),
        'ahorro_litros_total' => (int) array_sum(array_column($acc, 'ahorro_litros_mes')),
        'kg_meta_total'       => (float) array_sum(array_column($acc, 'kg_meta')),
    ];
}

/**
 * Insignias del reto unificado: se calculan con números, nunca con datos personales.
 * $tiene es la lista ya guardada; se devuelve la unión con las nuevas ganadas.
 */
function insigniasDelReto(int $dias, array $d, array $tiene): array
{
    $nuevas = [];

    if ($dias >= 1)  $nuevas[] = 'Guardián del planeta';
    if ($dias >= 7)  $nuevas[] = 'Racha de 7 días';
    if ($dias >= 15) $nuevas[] = 'A la mitad del camino';
    if ($dias >= 30) $nuevas[] = 'Maestro Guardian';
    if (($d['energia']['ahorro_plan'] ?? 0) > 0)    $nuevas[] = 'Cazador de watts';
    if (($d['agua']['ahorro_plan'] ?? 0) > 0)       $nuevas[] = 'Guardián del agua';
    if (($d['reciclaje']['kg_mes'] ?? 0) >= 5)      $nuevas[] = 'Eco-héroe';
    if (($d['reciclaje']['kg_mes'] ?? 0) >= 15)     $nuevas[] = 'Campeón del reciclaje';

    return array_values(array_unique(array_merge($tiene, $nuevas)));
}

/** Último reto Guardianes del Planeta del hogar (o null). */
function retoGuardianes(int $hogarId): ?array
{
    $st = db()->prepare('SELECT * FROM reto_guardianes WHERE hogar_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$hogarId]);
    $reto = $st->fetch() ?: null;
    if (!$reto) {
        return null;
    }
    $reto['insignias_lista'] = json_decode((string) $reto['insignias'], true) ?: [];
    $reto['metas_lista']     = json_decode((string) $reto['metas'], true) ?: [];
    return $reto;
}
