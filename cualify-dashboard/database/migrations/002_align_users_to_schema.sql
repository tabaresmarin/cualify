-- ===========================================================================
-- Migración 002 — alinea `users` con el schema del bot (funciona.sql).
--
-- Solo hace falta si tu `users` fue creada por la versión ANTERIOR del panel,
-- que usaba nombres de columna propios. En una instalación nueva, o si tu
-- `users` ya viene del bot, este archivo no cambia nada: cada bloque está
-- condicionado a que la columna antigua exista de verdad.
--
-- Diferencias que corrige:
--   password_hash  -> password     (el hash vive en `password`)
--   last_login_at  -> last_login
--   is_active      -> (se elimina; el schema del bot no lo tiene)
--   rol 'visor'    -> 'viewer'     (el enum es en inglés)
--
-- Ejecutar UNA VEZ:
--   mariadb -h 127.0.0.1 -u USUARIO -p db_cualify \
--     < database/migrations/002_align_users_to_schema.sql
--
-- Es idempotente: se puede volver a ejecutar sin efectos.
-- ===========================================================================

-- ---------------------------------------------------------------------------
-- 1. `password_hash` -> `password`.
--    CHANGE (y no RENAME) para fijar el tipo en el del dump, VARCHAR(255).
--    Los hashes bcrypt de PHP caben de sobra, así que no se pierde nada.
-- ---------------------------------------------------------------------------
SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'password_hash')
    AND NOT EXISTS(SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'password'),
    'ALTER TABLE `users` CHANGE `password_hash` `password` VARCHAR(255) NOT NULL',
    'SELECT ''users.password ya existe, nada que hacer'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. `last_login_at` -> `last_login`.
-- ---------------------------------------------------------------------------
SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'last_login_at')
    AND NOT EXISTS(SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'last_login'),
    'ALTER TABLE `users` CHANGE `last_login_at` `last_login` DATETIME NULL DEFAULT NULL',
    'SELECT ''users.last_login ya existe, nada que hacer'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3. Traducir el rol ANTES de tocar el enum.
--    Si se intentara el MODIFY con un 'visor' presente, MariaDB en modo estricto
--    aborta el ALTER (o trunca el valor), y nos quedamos sin tabla.
-- ---------------------------------------------------------------------------
UPDATE `users` SET `role` = 'viewer' WHERE `role` = 'visor';

-- ---------------------------------------------------------------------------
-- 4. Enum de rol al del dump: ENUM('admin','viewer').
-- ---------------------------------------------------------------------------
SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role')
    AND NOT EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
          AND COLUMN_TYPE = 'enum(''admin'',''viewer'')'),
    'ALTER TABLE `users` MODIFY `role` ENUM(''admin'',''viewer'') NOT NULL DEFAULT ''admin''',
    'SELECT ''users.role ya es enum(admin,viewer), nada que hacer'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 5. `is_active` no existe en el schema del bot y el panel ya no lo consulta:
--    se elimina. Si tienes usuarios desactivados a mano, márcalos borrando la
--    fila antes de correr esto, o pásalos a rol 'viewer' después.
-- ---------------------------------------------------------------------------
SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_active'),
    'ALTER TABLE `users` DROP COLUMN `is_active`',
    'SELECT ''users.is_active no existe, nada que hacer'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
