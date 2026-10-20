-- ============================================================
--  Migración 03 — Reciclaje, planes por servicio y reto unificado
--  Todas las tablas nuevas de Guardianes del Planeta.
--  Las tablas existentes NO se tocan.
--  Ejecutar UNA sola vez sobre guardianes_del_planeta.
-- ============================================================

USE guardianes_del_planeta;

-- ------------------------- AGUA -------------------------
-- Plan de ahorro de agua (IA o pre-generado) + reto de 30 días.
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

-- ------------------------- RECICLAJE -------------------------
-- Registro de residuos del hogar (la IA propone, la persona confirma).
CREATE TABLE residuos (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hogar_id     INT UNSIGNED NOT NULL,
    material     VARCHAR(60)   NOT NULL,                    -- "botella PET", "lata", "cartón"...
    tipo_plastico VARCHAR(10)  NULL,                        -- símbolo si se vio (PET, HDPE, PVC...)
    reciclable   VARCHAR(20)   NOT NULL DEFAULT 'depende',  -- si / no / depende
    cantidad     DECIMAL(8,2)  NOT NULL DEFAULT 1,
    unidad       ENUM('unidad','kg','litro') NOT NULL DEFAULT 'unidad',
    kg_aprox     DECIMAL(8,2)  NOT NULL DEFAULT 0,          -- peso estimado para la gráfica
    semana       DATE          NOT NULL,                    -- lunes de la semana registrada
    origen       ENUM('ia','manual') NOT NULL DEFAULT 'manual',
    notas        VARCHAR(200)  NULL,
    creado_en    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_residuos_hogar FOREIGN KEY (hogar_id) REFERENCES hogares(id) ON DELETE CASCADE,
    INDEX idx_residuos_hogar_semana (hogar_id, semana)
) ENGINE=InnoDB;

-- Plan B del reciclaje: guías escritas a mano para los materiales más comunes.
-- (Si no hay internet, la app responde desde acá en vez de llamar a la IA.)
CREATE TABLE guias_reciclaje (
    material            VARCHAR(60) PRIMARY KEY,
    categoria           VARCHAR(30)  NOT NULL,
    reciclable          VARCHAR(20)  NOT NULL,
    pasos               JSON         NOT NULL,
    reutilizacion       JSON         NOT NULL,
    kg_aprox_por_unidad DECIMAL(6,3) NULL,
    destino             VARCHAR(80)  NOT NULL
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

-- Plan de reciclaje (IA o pre-generado).
CREATE TABLE planes_reciclaje (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hogar_id           INT UNSIGNED NOT NULL,
    origen             ENUM('ia','local') NOT NULL DEFAULT 'ia',
    resumen            TEXT         NOT NULL,
    acciones           JSON         NOT NULL,   -- [{titulo, accion, valor_estimado_usd}]
    meta_kg            DECIMAL(8,2) NOT NULL DEFAULT 0,
    kg_reciclado_hoy   DECIMAL(8,2) NOT NULL DEFAULT 0,
    creado_en          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_planes_reciclaje_hogar FOREIGN KEY (hogar_id) REFERENCES hogares(id) ON DELETE CASCADE,
    INDEX idx_planes_reciclaje_hogar (hogar_id)
) ENGINE=InnoDB;

-- ------------------------- PLAN UNIFICADO Y RETO -------------------------
-- Plan con IA de los TRES pilares a la vez.
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

-- ------------------------- Datos de demo -------------------------
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

-- Directorio comunitario de ejemplo (datos DEMO, agregar los reales desde la app).
INSERT INTO centros_acopio (nombre, materiales_aceptados, zona, horario, contacto_publico) VALUES
('Centro de acopio demo', 'Plásticos, papel, cartón, metales', 'Zona demo 1', 'Lun a Vie 8:00–16:00', 'Solo datos de ejemplo'),
('Punto verde demo',     'Vidrio, electrónicos, pilas',        'Zona demo 2', 'Sáb 9:00–12:00',    'Solo datos de ejemplo');

-- Campañas de ejemplo.
INSERT INTO campanas (titulo, descripcion, fecha, zona) VALUES
('Jornada de limpieza del río', 'Salimos del parque a recoger plásticos y cartón. Traé guantes y un costal.', '2026-11-07', 'Zona demo 1'),
('Semana del reciclaje escolar', 'Reto entre aulas: quién junta más kilos de plástico y papel.', '2026-11-16', 'Escuela');
