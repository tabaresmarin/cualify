<?php
if (php_sapi_name() !== 'cli') exit('Solo CLI');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

$pdo = obtenerConexion();
$deleted = $pdo->exec("DELETE FROM pagespeed_cache WHERE expires_at < NOW()");
error_log("[cleanup_cache] Eliminados $deleted registros expirados");