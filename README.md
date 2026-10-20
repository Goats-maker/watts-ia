# Guardianes del Planeta — Ahorrá energía, cuidá el agua, reciclá el resto

Sistema de tres pilares para familias y pequeños negocios de El Salvador:

- **Energía:** fotografiás el recibo de luz, la IA lo lee, grafica el consumo, descubre qué
  aparato te cuesta más y arma el plan de ahorro con su reto de 30 días.
- **Agua:** mismo flujo con el recibo de agua (m³), más una calculadora de usos del día a día
  (ducha, inodoro, lavado…) con ranking de «devoradores de agua» y su propio plan.
- **Reciclaje:** le sacás foto a un residuo y la IA dice qué es, cómo prepararlo y ideas de
  reutilización; se registra por semana en kilos y se arma el plan de 30 días. Incluye
  directorio de centros de acopio y panel comunitario anónimo.
- **Plan unificado:** un solo plan con IA que cubre los tres pilares, con puntos e insignias
  («Cazador de watts», «Guardián del agua», «Eco-héroe»…).

**Stack:** PHP 8.2 (puro) · MySQL · API REST de Gemini (multimodal) · Chart.js · Bootstrap 5
**Costo:** $0 · **ODS:** 7, 12 y 13

---

## Instalación (XAMPP)

1. **Copiar el proyecto** a `C:\xampp\htdocs\guardianes-del-planeta`.
2. **Crear la base de datos:** abrir phpMyAdmin → *Importar* → elegir `database/schema.sql` → Continuar.
   Esto crea la base `guardianes_del_planeta` con las tablas y datos de demo.
   > Si lo hacés por consola, usá redirección directa para no dañar los acentos:
   > `cmd /c "C:\xampp\mysql\bin\mysql.exe -u root < database\schema.sql"`
   > (PowerShell con `Get-Content` lee el archivo como ANSI y rompe las tildes).
3. **Abrir** `http://localhost/guardianes-del-planeta/index.php`.

   > **Ya tenés datos en `watts_ia`?** No importes el schema: ejecutá en su lugar, en orden:
   > `sql/migracion_01_renombre_bd.sql` (renombra la base sin tocar la vieja),
   > `sql/migracion_02_agua.sql` y `sql/migracion_03_reciclaje_y_planes.sql`.

4. **API key de Gemini (opcional):**
   - Conseguir una en [Google AI Studio](https://aistudio.google.com/apikey) (nivel gratis).
   - Variable de entorno `GEMINI_API_KEY`, o bien crear `config.local.php` (ver *Secretos*, abajo).
   - **Sin key la app igual funciona:** lectura manual del recibo y planes pre-generados (Plan B).

## Secretos (no van a GitHub)

`config.php` sube al repositorio, así que **los secretos viven en `config.local.php`**, que está
en el `.gitignore` y por lo tanto nunca se sube. GitHub incluso bloquea los pushes que traen
llaves dentro del código (*Push Protection*).

```php
<?php // config.local.php
return [
    'gemini_api_key' => 'tu-key-de-ai-studio',
    'db_pass'        => 'tu-contraseña-de-mysql',
];
```

Si el archivo no existe, se recurre a las variables de entorno `GEMINI_API_KEY` y `DB_PASS`
(con XAMPP de fábrica, root sin contraseña, funciona sin tocar nada).

## Configuración

| Archivo | Qué toca |
|---|---|
| `config.php` | Base de datos, modelo de Gemini, tarifas (luz y agua), kg CO₂/kWh, factores de reciclaje |
| `config.local.php` | **Secretos**: API key de Gemini y contraseña de MySQL (no se sube a Git) |
| `database/schema.sql` | Esquema completo y datos de demo (instalación nueva) |
| `sql/` | Migraciones para instalaciones existentes (01 renombre, 02 agua, 03 reciclaje) |

## Estructura

```
config.php              configuración central (BD, Gemini, constantes)
config.local.php        secretos locales (ignorado por Git)
.gitignore              qué archivos no suben a GitHub
database/schema.sql     esquema MySQL + datos de demo (instalación nueva)
sql/                    migraciones para instalaciones existentes
prompts/                prompts y esquemas JSON que se le mandan a la IA
includes/
  db.php                conexión PDO con consultas preparadas
  helpers.php           fórmulas (kWh, m³, kg, semáforo, anomalía) + planes locales
  GeminiClient.php      cURL a Gemini con responseSchema (recibo luz/agua, etiqueta, residuo, planes)
  header.php/footer.php layout Bootstrap
prompts/
  recibo_agua.json      prompt + esquema del recibo de agua
  residuo.json          prompt + esquema de la clasificación de residuos
  plan_agua.json        prompt + esquema del plan de agua
  plan_reciclaje.json   prompt + esquema del plan de reciclaje
  plan_unificado.json   prompt + esquema del plan de los tres pilares
api/
  extraer.php           foto base64 → Gemini → JSON (recibo, etiqueta o residuo; la imagen se descarta)
  guardar_recibo.php    guarda solo cifras, después de la confirmación humana (luz o agua)
  guardar_residuo.php   guarda el residuo confirmado por la persona
  clasificar_local.php  Plan B: clasificación desde el catálogo guias_reciclaje
  generar_plan.php      plan de energía con IA (o local)
  generar_plan_agua.php plan de agua con IA (o local)
  generar_plan_reciclaje.php  plan de reciclaje con IA (o local)
  generar_plan_unificado.php  plan de los tres pilares + reto Guardianes
  agregar_plantilla.php carga rápida de aparatos de negocio
  avanzar_reto.php      avanza un día en el reto de energía
  avanzar_reto_agua.php avanza un día en el reto del agua
  avanzar_reto_guardianes.php avanza el reto unificado (puntos e insignias)
index.php               panel: tres pilares, KPIs, gráfica, alerta de anomalía, historial
recibos_subir.php       cámara → extracción → formulario editable → guardar (luz y agua)
aparatos.php            CRUD de aparatos + calculadora + ranking + modo leyenda
agua.php                historial de agua + calculadora de usos + ranking + plan
reciclaje.php           clasificar residuo con IA/guía + registro semanal + plan + centros
comunidad.php           panel comunitario anónimo + campañas y jornadas
plan.php                plan de energía + plan unificado + retos de 30 días
negocios.php            modo negocio (pupuserías, tiendas, talleres)
assets/css/app.css      estilos propios (fondo animado con rayos)
```

## Cálculos

```
Energía:  kWh/mes = watts × horas al día × 30 ÷ 1000
          Costo   = kWh × tarifa del recibo
          % factura = kWh del aparato ÷ kWh total × 100
Agua:     litros/día = litros por uso × veces al día (× personas si aplica)
          m³/mes = litros/día × 30 ÷ 1000
          Costo = m³ × tarifa del agua
Reciclaje: kg = cantidad × peso aproximado del material (guias_reciclaje)
           CO₂ evitado = kg × factor por categoría (config.php, aproximado)
```

- **Semáforo:** rojo ≥ 25 %, amarillo ≥ 12 %, verde abajo.
- **Alerta de anomalía:** avisa cuando un mes sube más del 20 %.
- **Promedio de comunidad:** promedio del último recibo de cada hogar.

## Privacidad

- La foto viaja solo a Gemini y **no se almacena**: se descarta al terminar la petición.
- En MySQL solo hay números: sin nombre, dirección ni número de cliente.
- Nada se guarda sin que la persona confirme o corrija los campos (humano en el circuito).
- El directorio de centros de acopio y el panel comunitario solo guardan datos de **lugares**
  y agregados anónimos, nunca datos de personas.
- El plan unificado recibe únicamente números de los tres pilares.

## Plan B (sin internet)

- Recibo de luz o de agua: ingreso manual en el mismo formulario de `recibos_subir.php`.
- Planes: plantillas locales por rango de consumo (energía, agua, reciclaje y general).
- Reciclaje: catálogo `guias_reciclaje` con 12 materiales comunes (pasos y reutilización).
- Todo corre en XAMPP local; solo se pierde la extracción por IA.

## Ruta de construcción sugerida

1. CRUD de aparatos + calculadora (ya está: `aparatos.php`).
2. Probar el prompt de extracción en Google AI Studio con una foto de recibo.
3. Foto → JSON editable → guardado (`recibos_subir.php`).
4. Gráfica con Chart.js e historial (`index.php`).
5. Plan con IA + reto de 30 días (`plan.php`).
