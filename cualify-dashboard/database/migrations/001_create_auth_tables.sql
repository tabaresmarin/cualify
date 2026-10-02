-- ===========================================================================
-- Migración 001 — autenticación del panel Cualify.
--
-- `users` YA EXISTE en el schema del bot (funciona.sql). Este archivo no
-- inventa una versión distinta: si la tabla no está, la crea idéntica a la
-- del dump para que el panel funcione sobre una base nueva. Si ya existe, el
-- `CREATE TABLE IF NOT EXISTS` no hace nada y no la toca.
--
-- El panel NO usa conversation_states: cada request abre su propia sesión PHP
-- y consulta directamente las tablas que el bot escribe (leads, jobs_queue,
-- conversation_history, pagespeed_cache). Ese schema no se recrea aquí.
--
-- Ejecutar UNA VEZ:
--   mariadb -h 127.0.0.1 -u USUARIO -p db_cualify \
--     < database/migrations/001_create_auth_tables.sql
--
-- Luego crear el primer usuario (el hash se genera en PHP, no en SQL):
--   php bin/crear_usuario.php "Nombre" "correo@eiso.com.co" "contrasena"
--
-- Si tu `users` came de una versión anterior del panel (columnas password_hash,
-- is_active, last_login_at y rol 'visor'), ejecuta además:
--   002_align_users_to_schema.sql
-- ===========================================================================

-- Espejo exacto de `users` en funciona.sql. Ojo a los nombres: el hash vive en
-- `password`, el último acceso en `last_login` y el enum de rol es
-- ('admin','viewer') en inglés, no 'visor'.
CREATE TABLE IF NOT EXISTS `users` (
  `id`         INT(11) NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(100) NOT NULL,
  `email`      VARCHAR(150) NOT NULL,
  `password`   VARCHAR(255) NOT NULL,
  `role`       ENUM('admin','viewer') NOT NULL DEFAULT 'admin',
  `last_login` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Tabla PROPIA del panel (no está en funciona.sql): historial de intentos de
-- login para el bloqueo anti fuerza bruta. Si no se crea, el panel funciona
-- igual pero sin límite de intentos: User::tieneLoginAttempts() lo detecta y
-- degrada en silencio.
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`        VARCHAR(190) NOT NULL,
  `ip_address`   VARCHAR(45) NOT NULL DEFAULT '',
  `successful`   TINYINT(1) NOT NULL DEFAULT 0,
  `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `login_attempts_lookup` (`email`, `successful`, `attempted_at`),
  KEY `login_attempts_gc` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
