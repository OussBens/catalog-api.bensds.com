<?php
/**
 * Création MANUELLE de l'autoloader
 * Utilisation en dernier recours
 */

echo "=== CRÉATION MANUELLE DE L'AUTOLOADER ===\n\n";

// Créer le dossier vendor
if (!is_dir('vendor')) {
    mkdir('vendor', 0755, true);
    echo "✅ vendor créé\n";
}

// Créer un autoloader simple
$autoloadContent = <<<'PHP'
<?php
/**
 * Autoloader manuel pour CaisseDZ Catalog API
 * Généré automatiquement
 */

spl_autoload_register(function ($className) {
    // Préfixe du namespace
    $prefix = 'CaisseDZ\\Catalog\\';
    $baseDir = __DIR__ . '/../';
    
    // Vérifier si la classe utilise notre namespace
    $len = strlen($prefix);
    if (strncmp($prefix, $className, $len) !== 0) {
        return;
    }
    
    // Obtenir le nom relatif de la classe
    $relativeClass = substr($className, $len);
    
    // Remplacer les backslashes par des slashes
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    
    // Inclure le fichier s'il existe
    if (file_exists($file)) {
        require $file;
        return true;
    }
    
    return false;
});

// Charger les dépendances si elles existent
$vendorDir = __DIR__ . '/';
$dependencies = [
    'firebase/php-jwt/src/JWT.php',
    'vlucas/phpdotenv/src/Dotenv.php',
    'monolog/monolog/src/Logger.php'
];

foreach ($dependencies as $dep) {
    $file = $vendorDir . $dep;
    if (file_exists($file)) {
        require_once $file;
    }
}

PHP;

file_put_contents('vendor/autoload.php', $autoloadContent);
echo "✅ vendor/autoload.php créé\n";

// Vérifier
if (file_exists('vendor/autoload.php')) {
    echo "\n🎉 SUCCÈS ! Autoloader créé.\n";
    echo "👉 Testez : https://bensds.com/catalog-api/diagnostic.php\n";
} else {
    echo "\n❌ Échec de la création.\n";
}