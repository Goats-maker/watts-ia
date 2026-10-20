-- ============================================================
--  Migración 02 — Módulo de AGUA
--  • La tabla `recibos` se GENERALIZA con tipo_servicio ('energia' | 'agua').
--    ¿Por qué una sola tabla? Porque el flujo es idéntico (foto → IA →
--    confirmación humana → guardar solo cifras) y así se reutilizan el
--    historial, la detección de anomalías, el plan y el panel sin duplicar
--    tablas ni endpoints. Lo único propio del agua son los campos m³.
--  • Nueva tabla `usos_agua` (calculadora de "devoradores de agua").
--  Ejecutar UNA sola vez sobre guardianes_del_planeta.
-- ============================================================

USE guardianes_del_planeta;

-- Personas del hogar (para los usos por persona) y tarifa del agua.
ALTER TABLE hogares
    ADD COLUMN personas    SMALLINT UNSIGNED NOT NULL DEFAULT 4 AFTER tipo,
    ADD COLUMN tarifa_agua DECIMAL(10,4) NOT NULL DEFAULT 1.2000 AFTER tarifa;

-- Recibos generalizados: un recibo es de energía O de agua.
-- kwh pasa a ser NULL en los de agua; m3 y alcantarillado, NULL en los de energía.
ALTER TABLE recibos
    ADD COLUMN tipo_servicio        ENUM('energia','agua') NOT NULL DEFAULT 'energia' AFTER hogar_id,
    ADD COLUMN m3                   DECIMAL(10,2) NULL AFTER kwh,
    ADD COLUMN cargo_alcantarillado DECIMAL(10,2) NULL AFTER cargo_fijo,
    MODIFY kwh DECIMAL(10,2) NULL;

-- Calculadora de agua: litros por uso × veces al día × (personas si aplica).
CREATE TABLE usos_agua (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hogar_id       INT UNSIGNED NOT NULL,
    nombre         VARCHAR(80)   NOT NULL,
    litros_por_uso DECIMAL(7,2)  NOT NULL,          -- litros cada vez que se usa
    veces_dia      DECIMAL(5,2)  NOT NULL DEFAULT 1,
    por_persona    TINYINT(1)    NOT NULL DEFAULT 0, -- 1 = se multiplica por las personas
    categoria      VARCHAR(30)   NOT NULL DEFAULT 'otro',
    creado_en      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_usos_agua_hogar FOREIGN KEY (hogar_id) REFERENCES hogares(id) ON DELETE CASCADE,
    INDEX idx_usos_agua_hogar (hogar_id)
) ENGINE=InnoDB;

-- ------------------------- Datos de demo -------------------------
-- Recibos de agua de 5 meses (tarifa $1.20/m³ + cargo fijo $0.50 + alcantarillado $0.70)
INSERT INTO recibos (hogar_id, tipo_servicio, periodo, fecha, kwh, m3, monto, cargo_fijo, cargo_alcantarillado, tarifa, origen) VALUES
    (1, 'agua', 'Junio 2026',      '2026-06-25', NULL, 18.50, 23.40, 0.50, 0.70, 1.2000, 'manual'),
    (1, 'agua', 'Julio 2026',      '2026-07-25', NULL, 19.20, 24.24, 0.50, 0.70, 1.2000, 'manual'),
    (1, 'agua', 'Agosto 2026',     '2026-08-25', NULL, 21.00, 26.40, 0.50, 0.70, 1.2000, 'ia'),
    (1, 'agua', 'Septiembre 2026', '2026-09-25', NULL, 23.40, 29.28, 0.50, 0.70, 1.2000, 'ia'),
    (2, 'agua', 'Septiembre 2026', '2026-09-25', NULL, 32.00, 39.60, 0.50, 0.70, 1.2000, 'ia');

-- Usos de agua del hogar demo (familia de 4)
INSERT INTO usos_agua (hogar_id, nombre, litros_por_uso, veces_dia, por_persona, categoria) VALUES
    (1, 'Ducha',             60, 2, 1, 'higiene'),
    (1, 'Inodoro',            6, 4, 1, 'higiene'),
    (1, 'Lavado de ropa',    60, 1, 0, 'lavado'),
    (1, 'Lavado de trastes', 15, 2, 0, 'cocina'),
    (1, 'Riego del jardín',  10, 1, 0, 'exterior'),
    (2, 'Lavado de trastes', 15, 6, 0, 'cocina'),
    (2, 'Limpieza del local', 25, 2, 0, 'limpieza');

UPDATE hogares SET tarifa_agua = 1.2000 WHERE id IN (1, 2);
