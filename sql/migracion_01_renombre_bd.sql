-- ============================================================
--  Migración 01 — Renombre de base de datos
--  watts_ia  →  guardianes_del_planeta
--  Copia TODOS los datos; la base vieja queda intacta como
--  respaldo (podés borrarla a mano cuando quieras).
--  Ejecutar UNA sola vez: cmd /c "mysql.exe -u root -p < sql\migracion_01_renombre_bd.sql"
-- ============================================================

CREATE DATABASE IF NOT EXISTS guardianes_del_planeta
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Creamos las tablas destino con la misma estructura e índices...
CREATE TABLE IF NOT EXISTS guardianes_del_planeta.hogares       LIKE watts_ia.hogares;
CREATE TABLE IF NOT EXISTS guardianes_del_planeta.recibos       LIKE watts_ia.recibos;
CREATE TABLE IF NOT EXISTS guardianes_del_planeta.aparatos      LIKE watts_ia.aparatos;
CREATE TABLE IF NOT EXISTS guardianes_del_planeta.planes_ahorro LIKE watts_ia.planes_ahorro;

-- ...y copiamos los datos (solo si la tabla destino está vacía,
-- así el script se puede volver a correr sin duplicar filas).
INSERT INTO guardianes_del_planeta.hogares
    SELECT * FROM watts_ia.hogares
    WHERE (SELECT COUNT(*) FROM guardianes_del_planeta.hogares) = 0;

INSERT INTO guardianes_del_planeta.recibos
    SELECT * FROM watts_ia.recibos
    WHERE (SELECT COUNT(*) FROM guardianes_del_planeta.recibos) = 0;

INSERT INTO guardianes_del_planeta.aparatos
    SELECT * FROM watts_ia.aparatos
    WHERE (SELECT COUNT(*) FROM guardianes_del_planeta.aparatos) = 0;

INSERT INTO guardianes_del_planeta.planes_ahorro
    SELECT * FROM watts_ia.planes_ahorro
    WHERE (SELECT COUNT(*) FROM guardianes_del_planeta.planes_ahorro) = 0;

-- CREATE TABLE ... LIKE no copia las claves foráneas: las restauramos.
SET FOREIGN_KEY_CHECKS = 0;
ALTER TABLE guardianes_del_planeta.recibos
    ADD CONSTRAINT fk_recibos_hogar FOREIGN KEY (hogar_id)
    REFERENCES guardianes_del_planeta.hogares(id) ON DELETE CASCADE;
ALTER TABLE guardianes_del_planeta.aparatos
    ADD CONSTRAINT fk_aparatos_hogar FOREIGN KEY (hogar_id)
    REFERENCES guardianes_del_planeta.hogares(id) ON DELETE CASCADE;
ALTER TABLE guardianes_del_planeta.planes_ahorro
    ADD CONSTRAINT fk_planes_hogar FOREIGN KEY (hogar_id)
    REFERENCES guardianes_del_planeta.hogares(id) ON DELETE CASCADE;
SET FOREIGN_KEY_CHECKS = 1;

-- Verificación
SELECT 'guardianes_del_planeta.hogares' AS tabla, COUNT(*) AS filas FROM guardianes_del_planeta.hogares
UNION ALL
SELECT 'guardianes_del_planeta.recibos', COUNT(*) FROM guardianes_del_planeta.recibos
UNION ALL
SELECT 'guardianes_del_planeta.aparatos', COUNT(*) FROM guardianes_del_planeta.aparatos
UNION ALL
SELECT 'guardianes_del_planeta.planes_ahorro', COUNT(*) FROM guardianes_del_planeta.planes_ahorro;
