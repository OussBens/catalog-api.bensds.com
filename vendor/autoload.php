<?php
/**
 * Autoloader manuel
 */
spl_autoload_register(function ($className) {
    $prefix = 'CaisseDZ\\Catalog\\';
    $baseDir = __DIR__ . '/';
    $len = strlen($prefix);
    if (strncmp($prefix, $className, $len) !== 0) return;
    $relativeClass = substr($className, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) require $file;
});
// Charger Dotenv manuellement
$dotenvFile = __DIR__ . '/vendor/vlucas/phpdotenv/src/Dotenv.php';
if (file_exists($dotenvFile)) require_once $dotenvFile;