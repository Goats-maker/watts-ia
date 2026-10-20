-- Guardianes del Planeta — esquema MySQL (XAMPP)
-- Importar desde phpMyAdmin → Importar → elegir este archivo.
-- (Instalación nueva. Si ya tenés datos en watts_ia, usá sql/migracion_01_renombre_bd.sql)

DROP DATABASE IF EXISTS guardianes_del_planeta;
CREATE DATABASE guardianes_del_planeta CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE guardianes_del_planeta;

-- Hogares / negocios. Solo se guardan NÚMEROS: nunca nombre, dirección ni cliente.
CREATE TABLE hogares (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    alias      VARCHAR(60)  NOT NULL,                -- "Hogar 1", "Pupusería 1"
    tipo       ENUM('hogar','negocio') NOT NULL DEFAULT 'hogar',
    tarifa     DECIMAL(10,4) NOT NULL DEFAULT 0.2100, -- USD/kWh tomado del recibo
    creado_en  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Recibos: solo cifras, la imagen se descarta tras la extracción.
CREATE TABLE recibos (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hogar_id      INT UNSIGNED NOT NULL,
    periodo       VARCHAR(60)   NOT NULL,             -- "Septiembre 2026"
    fecha         DATE          NOT NULL,
    kwh           DECIMAL(10,2) NOT NULL,
    monto         DECIMAL(10,2) NOT NULL,
    cargo_fijo    DECIMAL(10,2) NOT NULL DEFAULT 0,
    tarifa        DECIMAL(10,4) NOT NULL,
    origen        ENUM('ia','manual') NOT NULL DEFAULT 'manual',
    creado_en     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_recibos_hogar FOREIGN KEY (hogar_id) REFERENCES hogares(id) ON DELETE CASCADE,
    INDEX idx_hogar_fecha (hogar_id, fecha)
) ENGINE=InnoDB;

-- Inventario de aparatos: la calculadora de "devoradores de watts".
CREATE TABLE aparatos (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hogar_id   INT UNSIGNED NOT NULL,
    nombre     VARCHAR(80)   NOT NULL,
    watts      SMALLINT UNSIGNED NOT NULL,
    horas_dia  DECIMAL(5,2)  NOT NULL DEFAULT 1.00,
    categoria  VARCHAR(30)   NOT NULL DEFAULT 'otro',
    creado_en  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_aparatos_hogar FOREIGN KEY (hogar_id) REFERENCES hogares(id) ON DELETE CASCADE,
    INDEX idx_hogar (hogar_id)
) ENGINE=InnoDB;

-- Plan de ahorro (IA o pre-generado) + reto de 30 días.
CREATE TABLE planes_ahorro (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hogar_id     INT UNSIGNED NOT NULL,
    origen       ENUM('ia','local') NOT NULL DEFAULT 'ia',
    resumen      TEXT          NOT NULL,
    acciones     JSON          NOT NULL,
    reto         JSON          NOT NULL,
    ahorro_total DECIMAL(10,2) NOT NULL DEFAULT 0,
    dias_hechos  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    meta_dias    TINYINT UNSIGNED NOT NULL DEFAULT 30,
    creado_en    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_planes_hogar FOREIGN KEY (hogar_id) REFERENCES hogares(id) ON DELETE CASCADE,
    INDEX idx_hogar (hogar_id)
) ENGINE=InnoDB;

-- ------------------------- Datos de demo -------------------------
INSERT INTO hogares (alias, tipo, tarifa) VALUES
    ('Hogar demo',      'hogar',   0.2100),
    ('Pupusería demo',  'negocio', 0.2100);

-- Historial de 5 meses con una subida del 15 % en el último (para la alerta).
INSERT INTO recibos (hogar_id, periodo, fecha, kwh, monto, cargo_fijo, tarifa, origen) VALUES
    (1, 'Mayo 2026',      '2026-05-28', 118.00, 29.28, 4.10, 0.2100, 'manual'),
    (1, 'Junio 2026',     '2026-06-27', 121.00, 29.91, 4.10, 0.2100, 'manual'),
    (1, 'Julio 2026',     '2026-07-28', 134.00, 32.64, 4.10, 0.2100, 'manual'),
    (1, 'Agosto 2026',    '2026-08-27', 146.00, 35.16, 4.10, 0.2100, 'ia'),
    (1, 'Septiembre 2026','2026-09-27', 168.00, 39.38, 4.10, 0.2100, 'ia'),
    (2, 'Septiembre 2026','2026-09-27', 412.00, 90.62, 4.10, 0.2100, 'ia');

-- El refri viejo manda: 150 W × 12 h = 54 kWh de los 146 (37 %).
INSERT INTO aparatos (hogar_id, nombre, watts, horas_dia, categoria) VALUES
    (1, 'Refrigerador viejo',   150, 12.00, 'refrigeracion'),
    (1, 'Televisor',            110,  5.00, 'entretenimiento'),
    (1, 'Plancha',             1200,  0.50, 'lavado'),
    (1, 'Ventilador de pie',     75,  6.00, 'clima'),
    (1, 'Luz LED (5 bombillas)', 30,  7.00, 'iluminacion'),
    (1, 'Microondas',           900,  0.30, 'cocina'),
    (1, 'Router y cargadores',   25, 24.00, 'otro');
