-- ============================================================
--  Guardianes del Planeta — esquema MySQL (XAMPP)
--  Importar desde phpMyAdmin → Importar → elegir este archivo.
--
--  Instalación NUEVA: esto crea la base guardianes_del_planeta
--  con todas las tablas y datos de demo.
--  Ya tenés datos en watts_ia? NO importes este archivo:
--  ejecutá en su lugar sql/migracion_01_renombre_bd.sql y después
--  las migraciones 02 y 03.
-- ============================================================

DROP DATABASE IF EXISTS guardianes_del_planeta;
CREATE DATABASE guardianes_del_planeta CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE guardianes_del_planeta;

-- Hogares / negocios. Solo se guardan NÚMEROS: nunca nombre, dirección ni cliente.
CREATE TABLE hogares (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    alias      VARCHAR(60)  NOT NULL,                 -- "Hogar 1", "Pupusería 1"
    tipo       ENUM('hogar','negocio') NOT NULL DEFAULT 'hogar',
    personas   SMALLINT UNSIGNED NOT NULL DEFAULT 4,  -- para los usos de agua por persona
    tarifa     DECIMAL(10,4) NOT NULL DEFAULT 0.2100, -- USD/kWh (último recibo de luz)
    tarifa_agua DECIMAL(10,4) NOT NULL DEFAULT 1.2000, -- USD/m³ (último recibo de agua)
    creado_en  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Recibos: GENERALIZADOS a los dos servicios (luz y agua).
-- Solo cifras, la imagen se descarta tras la extracción.
--   energía → kwh lleno, m3 y alcantarillado NULL
--   agua    → m3 lleno, kwh NULL
CREATE TABLE recibos (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hogar_id              INT UNSIGNED NOT NULL,
    tipo_servicio         ENUM('energia','agua') NOT NULL DEFAULT 'energia',
    periodo               VARCHAR(60)   NOT NULL,     -- "Septiembre 2026"
    fecha                 DATE          NOT NULL,
    kwh                   DECIMAL(10,2) NULL,
    m3                    DECIMAL(10,2) NULL,
    monto                 DECIMAL(10,2) NOT NULL,
    cargo_fijo            DECIMAL(10,2) NOT NULL DEFAULT 0,
    cargo_alcantarillado  DECIMAL(10,2) NULL,
    tarifa                DECIMAL(10,4) NOT NULL,
    origen                ENUM('ia','manual') NOT NULL DEFAULT 'manual',
    creado_en             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
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

-- Plan de ahorro de ENERGÍA (IA o pre-generado) + reto de 30 días.
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

-- Calculadora de AGUA: litros por uso × veces al día (× personas si aplica).
CREATE TABLE usos_agua (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hogar_id       INT UNSIGNED NOT NULL,
    nombre         VARCHAR(80)   NOT NULL,
    litros_por_uso DECIMAL(7,2)  NOT NULL,
    veces_dia      DECIMAL(5,2)  NOT NULL DEFAULT 1,
    por_persona    TINYINT(1)    NOT NULL DEFAULT 0,
    categoria      VARCHAR(30)   NOT NULL DEFAULT 'otro',
    creado_en      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_usos_agua_hogar FOREIGN KEY (hogar_id) REFERENCES hogares(id) ON DELETE CASCADE,
    INDEX idx_usos_agua_hogar (hogar_id)
) ENGINE=InnoDB;

-- Plan de ahorro de AGUA (IA o pre-generado) + reto de 30 días.
CREATE TABLE planes_agua (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hogar_id      INT UNSIGNED NOT NULL,
    origen        ENUM('ia','local') NOT NULL DEFAULT 'ia',
    resumen       TEXT          NOT NULL,
    acciones      JSON          NOT NULL,
    reto          JSON          NOT NULL,
    ahorro_usd    DECIMAL(10,2) NOT NULL DEFAULT 0,
    ahorro_litros INT UNSIGNED  NOT NULL DEFAULT 0,
    dias_hechos   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    meta_dias     TINYINT UNSIGNED NOT NULL DEFAULT 30,
    creado_en     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_planes_agua_hogar FOREIGN KEY (hogar_id) REFERENCES hogares(id) ON DELETE CASCADE,
    INDEX idx_planes_agua_hogar (hogar_id)
) ENGINE=InnoDB;

-- Registro de RECICLAJE (la IA propone, la persona confirma).
CREATE TABLE residuos (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hogar_id      INT UNSIGNED NOT NULL,
    material      VARCHAR(60)   NOT NULL,                    -- "botella PET", "lata"...
    tipo_plastico VARCHAR(10)   NULL,                        -- símbolo si se vio (PET, HDPE...)
    reciclable    VARCHAR(20)   NOT NULL DEFAULT 'depende',  -- si / no / depende
    cantidad      DECIMAL(8,2)  NOT NULL DEFAULT 1,
    unidad        ENUM('unidad','kg','litro') NOT NULL DEFAULT 'unidad',
    kg_aprox      DECIMAL(8,2)  NOT NULL DEFAULT 0,
    semana        DATE          NOT NULL,                    -- lunes de la semana
    origen        ENUM('ia','manual') NOT NULL DEFAULT 'manual',
    notas         VARCHAR(200)  NULL,
    creado_en     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_residuos_hogar FOREIGN KEY (hogar_id) REFERENCES hogares(id) ON DELETE CASCADE,
    INDEX idx_residuos_hogar_semana (hogar_id, semana)
) ENGINE=InnoDB;

-- Plan B del reciclaje: guías escritas para los materiales más comunes.
CREATE TABLE guias_reciclaje (
    material            VARCHAR(60) PRIMARY KEY,
    categoria           VARCHAR(30)  NOT NULL,
    reciclable          VARCHAR(20)  NOT NULL,
    pasos               JSON         NOT NULL,
    reutilizacion       JSON         NOT NULL,
    kg_aprox_por_unidad DECIMAL(6,3) NULL,
    destino             VARCHAR(80)  NOT NULL
) ENGINE=InnoDB;

-- Plan de reciclaje (IA o pre-generado).
CREATE TABLE planes_reciclaje (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hogar_id           INT UNSIGNED NOT NULL,
    origen             ENUM('ia','local') NOT NULL DEFAULT 'ia',
    resumen            TEXT         NOT NULL,
    acciones           JSON         NOT NULL,
    meta_kg            DECIMAL(8,2) NOT NULL DEFAULT 0,
    kg_reciclado_hoy   DECIMAL(8,2) NOT NULL DEFAULT 0,
    creado_en          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_planes_reciclaje_hogar FOREIGN KEY (hogar_id) REFERENCES hogares(id) ON DELETE CASCADE,
    INDEX idx_planes_reciclaje_hogar (hogar_id)
) ENGINE=InnoDB;

-- Directorio comunitario: SOLO datos públicos de lugares, nunca de personas.
CREATE TABLE centros_acopio (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre              VARCHAR(100) NOT NULL,
    materiales_aceptados VARCHAR(200) NOT NULL,
    zona                VARCHAR(60)  NOT NULL,
    horario             VARCHAR(100) NULL,
    contacto_publico    VARCHAR(100) NULL,
    creado_en           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Campañas y jornadas de limpieza del panel comunitario.
CREATE TABLE campanas (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    titulo      VARCHAR(120) NOT NULL,
    descripcion TEXT         NOT NULL,
    fecha       DATE         NULL,
    zona        VARCHAR(60)  NULL,
    creado_en   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Plan UNIFICADO: los tres pilares a la vez.
CREATE TABLE planes_unificados (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hogar_id      INT UNSIGNED NOT NULL,
    origen        ENUM('ia','local') NOT NULL DEFAULT 'ia',
    resumen       TEXT          NOT NULL,
    acciones      JSON          NOT NULL,   -- cada acción trae "pilar": energia|agua|reciclaje
    reto          JSON          NOT NULL,
    ahorro_usd    DECIMAL(10,2) NOT NULL DEFAULT 0,
    ahorro_litros INT UNSIGNED  NOT NULL DEFAULT 0,
    kg_meta       DECIMAL(8,2)  NOT NULL DEFAULT 0,
    creado_en     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_planes_unif_hogar FOREIGN KEY (hogar_id) REFERENCES hogares(id) ON DELETE CASCADE,
    INDEX idx_planes_unif_hogar (hogar_id)
) ENGINE=InnoDB;

-- Reto familiar de 30 días "Guardianes del Planeta" (tres pilares, puntos e insignias).
CREATE TABLE reto_guardianes (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hogar_id    INT UNSIGNED NOT NULL,
    inicio      DATE           NOT NULL,
    dias_hechos TINYINT UNSIGNED NOT NULL DEFAULT 0,
    meta_dias   TINYINT UNSIGNED NOT NULL DEFAULT 30,
    puntos      INT UNSIGNED   NOT NULL DEFAULT 0,
    insignias   JSON           NOT NULL,   -- ["Cazador de watts", "Guardián del agua"]
    metas       JSON           NOT NULL,   -- {usd: 12.5, litros: 3000, kg: 15}
    creado_en   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reto_guardianes_hogar FOREIGN KEY (hogar_id) REFERENCES hogares(id) ON DELETE CASCADE,
    INDEX idx_reto_guardianes_hogar (hogar_id)
) ENGINE=InnoDB;

-- ======================= Datos de demo =======================

INSERT INTO hogares (alias, tipo, personas, tarifa, tarifa_agua) VALUES
    ('Hogar demo',      'hogar',   4, 0.2100, 1.2000),
    ('Pupusería demo',  'negocio', 6, 0.2100, 1.2000);

-- Historial de luz: 5 meses con una subida del 15 % en el último (para la alerta).
INSERT INTO recibos (hogar_id, tipo_servicio, periodo, fecha, kwh, monto, cargo_fijo, tarifa, origen) VALUES
    (1, 'energia', 'Mayo 2026',      '2026-05-28', 118.00, 29.28, 4.10, 0.2100, 'manual'),
    (1, 'energia', 'Junio 2026',     '2026-06-27', 121.00, 29.91, 4.10, 0.2100, 'manual'),
    (1, 'energia', 'Julio 2026',     '2026-07-28', 134.00, 32.64, 4.10, 0.2100, 'manual'),
    (1, 'energia', 'Agosto 2026',    '2026-08-27', 146.00, 35.16, 4.10, 0.2100, 'ia'),
    (1, 'energia', 'Septiembre 2026','2026-09-27', 168.00, 39.38, 4.10, 0.2100, 'ia'),
    (2, 'energia', 'Septiembre 2026','2026-09-27', 412.00, 90.62, 4.10, 0.2100, 'ia');

-- Historial de agua: $1.20/m³ + cargo fijo $0.50 + alcantarillado $0.70.
INSERT INTO recibos (hogar_id, tipo_servicio, periodo, fecha, m3, monto, cargo_fijo, cargo_alcantarillado, tarifa, origen) VALUES
    (1, 'agua', 'Junio 2026',      '2026-06-25', 18.50, 23.40, 0.50, 0.70, 1.2000, 'manual'),
    (1, 'agua', 'Julio 2026',      '2026-07-25', 19.20, 24.24, 0.50, 0.70, 1.2000, 'manual'),
    (1, 'agua', 'Agosto 2026',     '2026-08-25', 21.00, 26.40, 0.50, 0.70, 1.2000, 'ia'),
    (1, 'agua', 'Septiembre 2026', '2026-09-25', 23.40, 29.28, 0.50, 0.70, 1.2000, 'ia'),
    (2, 'agua', 'Septiembre 2026', '2026-09-25', 32.00, 39.60, 0.50, 0.70, 1.2000, 'ia');

-- El refri viejo manda: 150 W × 12 h = 54 kWh de los 146 (37 %).
INSERT INTO aparatos (hogar_id, nombre, watts, horas_dia, categoria) VALUES
    (1, 'Refrigerador viejo',   150, 12.00, 'refrigeracion'),
    (1, 'Televisor',            110,  5.00, 'entretenimiento'),
    (1, 'Plancha',             1200,  0.50, 'lavado'),
    (1, 'Ventilador de pie',     75,  6.00, 'clima'),
    (1, 'Luz LED (5 bombillas)', 30,  7.00, 'iluminacion'),
    (1, 'Microondas',           900,  0.30, 'cocina'),
    (1, 'Router y cargadores',   25, 24.00, 'otro');

-- Usos de agua del hogar demo.
INSERT INTO usos_agua (hogar_id, nombre, litros_por_uso, veces_dia, por_persona, categoria) VALUES
    (1, 'Ducha',             60, 2, 1, 'higiene'),
    (1, 'Inodoro',            6, 4, 1, 'higiene'),
    (1, 'Lavado de ropa',    60, 1, 0, 'lavado'),
    (1, 'Lavado de trastes', 15, 2, 0, 'cocina'),
    (1, 'Riego del jardín',  10, 1, 0, 'exterior'),
    (2, 'Lavado de trastes', 15, 6, 0, 'cocina'),
    (2, 'Limpieza del local', 25, 2, 0, 'limpieza');

-- Catálogo de guías de reciclaje (Plan B sin internet).
INSERT INTO guias_reciclaje (material, categoria, reciclable, pasos, reutilizacion, kg_aprox_por_unidad, destino) VALUES
('Botella PET', 'plastico', 'si',
 '["Enjuagar con agua para quitar restos de jugo o refresco","Quitar la tapa y separarla (es otro plástico)","Aplastar para que ocupe menos espacio","Llevarla a un centro de acopio o al contenedor de plásticos"]',
 '["Maceta colgante: cortala en dos y pintala","Portalápices o florero","Organizador de cucharas y pinceles"]', 0.021, 'Centro de acopio de plásticos'),
('Lata de aluminio', 'metal', 'si',
 '["Vaciar y enjuagar","Aplastarla para que ocupe menos","Juntar varias en un costal y llevarlas al centro de acopio"]',
 '["Portavelas decorativo","Maceta pequeña para hierbas","Organizador de clips y tachuelas"]', 0.014, 'Centro de acopio de metales'),
('Cartón', 'carton', 'si',
 '["Quitar cintas, plásticos y etiquetas pegadas","Aplanar las cajas","Guardarlo seco: si se moja, no se puede reciclar"]',
 '["Caja de herramientas o juguetes","Cama o casa para mascotas","Separadores de cajones"]', 0.400, 'Punto de acopio de cartón'),
('Vidrio (frasco)', 'vidrio', 'si',
 '["Vaciar y enjuagar","Quitar la tapa metálica","Cuidado con los astillos: no rompas el frasco","Llevarlo entero al contenedor de vidrio"]',
 '["Tarro de especias","Vasos para lapiceros","Botes para conservar granos y frijoles"]', 0.250, 'Contenedor verde de vidrio'),
('Papel', 'papel', 'si',
 '["Quitar grapas y carpetas de plástico","Apilarlo seco","No reciclar papel grasiento ni servilletas usadas"]',
 '["Bloque de notas doble cara","Papel de regalo hecho a mano","Capsulas para semilleros"]', 0.005, 'Punto de acopio de papel'),
('Aceite usado de cocina', 'peligroso', 'no',
 '["Dejarlo enfriar en un frasco de plástico con tapa","NUNCA tirarlo al desagüe: tapa las cañerías y contamina el agua","Llevarlo a un punto de recolección o a un centro de acopio de aceites"]',
 '["Jabón artesanal casero (con ceniza de leña)","Combustión de biodiésel en plantas que lo reciben"]', 0.900, 'Punto de recolección de aceites'),
('Aparato electrónico', 'electronico', 'depende',
 '["Apagar y desenchufar","Guardar tornillos y cables en una bolsa","No desarmar baterías ni pantallas","Llevarlo a un taller o centro que reciba electrónicos"]',
 '["Piezas para proyectos escolares de robótica","Cables para manualidades o amarres"]', 1.500, 'Taller o centro de electrónicos'),
('Pilas y baterías', 'peligroso', 'no',
 '["Guardarlas en una caja de plástico o cartón seco","NUNCA mezclarlas con la basura común: suelen tener metales pesados","Llevarlas a los puntos de recolección en tiendas y colegios"]',
 '["No se reutilizan: solo se llevan a recolección especial"]', 0.030, 'Punto especial de pilas'),
('Tetra Pak (caja de jugo)', 'plastico', 'depende',
 '["Vaciar y aclarar la caja","Aplanarla","En algunos municipios la recogen en campañas especiales"]',
 '["Tarros de almacenamiento cortados a la mitad","Porta lápices"]', 0.020, 'Campaña de reciclaje de envases'),
('Ropa y textiles', 'textil', 'depende',
 '["Lavarla y doblarla","Separar la que ya no se usa","Donarla en buenas condiciones"]',
 '["Traperos y paños de cocina","Retas o pelotas de trapo","Bolsitas reutilizables cosidas a mano"]', 0.300, 'Donación o campaña de textiles'),
('Residuos orgánicos', 'organico', 'no',
 '["Separar cáscaras, hojas y restos de comida","No mezclarlos con plásticos ni papeles"]',
 '["Compost casero para las plantas","Abono para el jardín"]', 0.500, 'Compostera o bolsa de orgánicos'),
('Envolturas de plástico (bolsas)', 'plastico', 'no',
 '["No se reciclan en el contenedor común","Acumularlas y llevarlas a los puntos que aceptan plásticos de un solo uso"]',
 '["Bolsa de almacenamiento para otra compra","Manualidades con trenza de bolsas"]', 0.008, 'Punto de recolección de plásticos flexibles');

-- Directorio comunitario de ejemplo (datos DEMO; agregar los reales desde la app).
INSERT INTO centros_acopio (nombre, materiales_aceptados, zona, horario, contacto_publico) VALUES
('Centro de acopio demo', 'Plásticos, papel, cartón, metales', 'Zona demo 1', 'Lun a Vie 8:00–16:00', 'Solo datos de ejemplo'),
('Punto verde demo',     'Vidrio, electrónicos, pilas',        'Zona demo 2', 'Sáb 9:00–12:00',    'Solo datos de ejemplo');

-- Campañas de ejemplo.
INSERT INTO campanas (titulo, descripcion, fecha, zona) VALUES
('Jornada de limpieza del río', 'Salimos del parque a recoger plásticos y cartón. Traé guantes y un costal.', '2026-11-07', 'Zona demo 1'),
('Semana del reciclaje escolar', 'Reto entre aulas: quién junta más kilos de plástico y papel.', '2026-11-16', 'Escuela');
