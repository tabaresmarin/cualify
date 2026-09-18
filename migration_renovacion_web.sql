-- Migración: flujo de calificación "Renovación de sitio web"
-- Ejecutar UNA VEZ sobre la base de datos existente (no reemplaza database.sql).
-- Uso: mysql -u USUARIO -p NOMBRE_BD < migration_renovacion_web.sql

ALTER TABLE `leads`
  ADD COLUMN `tiene_sitio_web`   ENUM('si','no') NULL             AFTER `presupuesto`,
  ADD COLUMN `antiguedad_sitio`  VARCHAR(30)      NULL             AFTER `tiene_sitio_web`,
  ADD COLUMN `url_sitio`         VARCHAR(255)     NULL             AFTER `antiguedad_sitio`,
  ADD COLUMN `pagespeed_score`   TINYINT UNSIGNED NULL             AFTER `url_sitio`,
  ADD COLUMN `clasificacion`     VARCHAR(30)      NULL             AFTER `pagespeed_score`,
  ADD COLUMN `cita_fecha`        DATE             NULL             AFTER `clasificacion`,
  ADD COLUMN `cita_hora`         TIME             NULL             AFTER `cita_fecha`,
  ADD COLUMN `google_event_id`   VARCHAR(255)     NULL             AFTER `cita_hora`;

-- Valores esperados de referencia (documentación, no se aplican como CHECK por compatibilidad MariaDB/MySQL amplia):
--   antiguedad_sitio: 'menos_1' | '1_a_2' | 'mas_2'
--   clasificacion:    'muy_necesitado' | 'oportunidad_mejora' | 'listo_marketing' | 'sin_sitio'
