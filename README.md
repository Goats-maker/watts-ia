# WATTS·IA — Tu recibo de luz, explicado por la IA

Fotografiás el recibo de energía, la IA lo lee, grafica tu consumo, descubre qué aparato te
cuesta más y arma un plan de ahorro familiar con un reto de 30 días.

**Stack:** PHP 8.2 (puro) · MySQL · API REST de Gemini (multimodal) · Chart.js · Bootstrap 5
**Costo:** $0 · **ODS:** 7, 12 y 13

---

## Instalación (XAMPP)

1. **Copiar el proyecto** a `C:\xampp\htdocs\watts-ia`.
2. **Crear la base de datos:** abrir phpMyAdmin → *Importar* → elegir `database/schema.sql` → Continuar.
   Esto crea la base `watts_ia` con 4 tablas y datos de demo (1 hogar, 1 pupusería, 5 recibos, 7 aparatos).
   > Si lo hacés por consola, usá redirección directa para no dañar los acentos:
   > `cmd /c "C:\xampp\mysql\bin\mysql.exe -u root < database\schema.sql"`
   > (PowerShell con `Get-Content` lee el archivo como ANSI y rompe las tildes).
3. **Abrir** `http://localhost/watts-ia/index.php`.
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
| `config.php` | Base de datos, modelo de Gemini, tarifa por defecto, kg CO₂/kWh, precio de la pupusa |
| `config.local.php` | **Secretos**: API key de Gemini y contraseña de MySQL (no se sube a Git) |
| `database/schema.sql` | Esquema y datos de demo |

## Estructura

```
config.php              configuración central (BD, Gemini, constantes)
config.local.php        secretos locales (ignorado por Git)
.gitignore              qué archivos no suben a GitHub
database/schema.sql     esquema MySQL + datos de demo
includes/
  db.php                conexión PDO con consultas preparadas
  helpers.php           fórmulas (kWh, costo, semáforo, anomalía) + planes locales
  GeminiClient.php      cURL a Gemini con responseSchema (recibo, etiqueta, plan)
  header.php/footer.php layout Bootstrap
api/
  extraer.php           foto base64 → Gemini → JSON (la imagen se descarta)
  guardar_recibo.php    guarda solo cifras, después de la confirmación humana
  generar_plan.php      plan con IA, o plan local si no hay internet
  agregar_plantilla.php carga rápida de aparatos de negocio
  avanzar_reto.php      avanza un día en el reto de 30 días
index.php               panel: KPIs, gráfica mensual, alerta de anomalía, historial
recibos_subir.php       cámara → extracción → formulario editable → guardar
aparatos.php            CRUD de aparatos + calculadora + ranking + modo leyenda
plan.php                plan de ahorro + reto familiar de 30 días
negocios.php            modo negocio (pupuserías, tiendas, talleres)
assets/css/app.css      estilos propios
```

## Cálculos

```
kWh/mes = watts × horas al día × 30 ÷ 1000
Costo   = kWh × tarifa del recibo
% factura = kWh del aparato ÷ kWh total × 100
```

- **Semáforo:** rojo ≥ 25 %, amarillo ≥ 12 %, verde abajo.
- **Alerta de anomalía:** avisa cuando un mes sube más del 20 %.
- **Promedio de comunidad:** promedio del último recibo de cada hogar.

## Privacidad

- La foto viaja solo a Gemini y **no se almacena**: se descarta al terminar la petición.
- En MySQL solo hay números: sin nombre, dirección ni número de cliente.
- Nada se guarda sin que la persona confirme o corrija los campos (humano en el circuito).

## Plan B (sin internet)

- Recibo: ingreso manual en el mismo formulario de `recibos_subir.php`.
- Plan de ahorro: plantillas locales por rango de consumo (< 100, 100–200, ≥ 200 kWh).
- Todo corre en XAMPP local; solo se pierde la extracción por IA.

## Ruta de construcción sugerida

1. CRUD de aparatos + calculadora (ya está: `aparatos.php`).
2. Probar el prompt de extracción en Google AI Studio con una foto de recibo.
3. Foto → JSON editable → guardado (`recibos_subir.php`).
4. Gráfica con Chart.js e historial (`index.php`).
5. Plan con IA + reto de 30 días (`plan.php`).
