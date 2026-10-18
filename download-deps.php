<?php
/**
 * Téléchargement manuel des dépendances
 */

echo "=== Téléchargement manuel des dépendances ===\n";

// Créer le dossier vendor
if (!is_dir('vendor')) {
    mkdir('vendor', 0755, true);
}

// Fonction pour télécharger une bibliothèque
function downloadLibrary($url, $path) {
    echo "Téléchargement de $path...\n";
    $content = file_get_contents($url);
    if ($content) {
        // Créer le dossier
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $content);
        echo "✅ $path téléchargé\n";
        return true;
    } else {
        echo "❌ Échec du téléchargement de $path\n";
        return false;
    }
}

// Télécharger vlucas/phpdotenv
downloadLibrary(
    'https://raw.githubusercontent.com/vlucas/phpdotenv/v5.5.0/src/Dotenv.php',
    'vendor/vlucas/phpdotenv/src/Dotenv.php'
);

// Télécharger monolog/monolog
downloadLibrary(
    'https://raw.githubusercontent.com/Seldaek/monolog/2.0.0/src/Logger.php',
    'vendor/monolog/monolog/src/Logger.php'
);

echo "\n✅ Téléchargement terminé.\n";
echo "Mais pour une installation complète, il est recommandé d'utiliser Composer.\n";