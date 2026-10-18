<?php
/**
 * Diagnostic simple de l'API
 */

echo "<h1>🔍 Diagnostic de l'API</h1>";

// 1. Vérifier PHP
echo "<h2>1. Environnement PHP</h2>";
echo "<p>Version : " . phpversion() . "</p>";

// 2. Vérifier les fichiers
echo "<h2>2. Fichiers</h2>";
$files = ['composer.json', 'index.php', 'vendor/autoload.php'];
foreach ($files as $file) {
    echo "<p>" . ($file . ": " . (file_exists($file) ? '✅' : '❌')) . "</p>";
}

// 3. Vérifier la base de données
echo "<h2>3. Base de données</h2>";
try {
    // Lire le fichier .env
    $env = file_get_contents('.env');
    preg_match('/DB_NAME="?([^"\n]+)"?/', $env, $dbName);
    preg_match('/DB_USER="?([^"\n]+)"?/', $env, $dbUser);
    preg_match('/DB_PASSWORD="?([^"\n]+)"?/', $env, $dbPass);
    
    $dbname = $dbName[1] ?? 'non trouvé';
    $user = $dbUser[1] ?? 'non trouvé';
    $password = $dbPass[1] ?? 'non trouvé';
    
    echo "<p>Base : $dbname</p>";
    echo "<p>Utilisateur : $user</p>";
    
    // Test de connexion
    $pdo = new PDO("mysql:host=localhost;dbname=$dbname;charset=utf8mb4", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<p style='color:green'>✅ Connexion à la base de données réussie</p>";
    
} catch (Exception $e) {
    echo "<p style='color:red'>❌ Erreur : " . $e->getMessage() . "</p>";
}

// 4. Vérifier l'API
echo "<h2>4. Test de l'API</h2>";
echo "<p><a href='index.php' style='display:inline-block;padding:10px 20px;background:#4CAF50;color:white;text-decoration:none;border-radius:4px;'>▶️ Tester index.php</a></p>";