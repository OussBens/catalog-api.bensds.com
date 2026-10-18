<?php
/**
 * Analyse la structure du fichier CSV
 * Accédez à : https://bensds.com/catalog-api/analyze-csv.php?key=import2026
 */

$password = 'import2026';
if (!isset($_GET['key']) || $_GET['key'] !== $password) {
    die("🔒 Accès protégé");
}

echo "<h1>🔍 Analyse du fichier CSV</h1>";

$csvFile = __DIR__ . '/fr.openfoodfacts.org.products.csv';
if (!file_exists($csvFile)) {
    die("❌ Fichier non trouvé : " . htmlspecialchars($csvFile));
}

$fileSize = round(filesize($csvFile) / 1024 / 1024 / 1024, 2);
echo "<p>📊 Fichier : " . basename($csvFile) . " (" . $fileSize . " GB)</p>";

// Ouvrir le fichier
$handle = fopen($csvFile, 'r');
if (!$handle) {
    die("❌ Impossible d'ouvrir le fichier");
}

// Lire les 5 premières lignes
echo "<h2>📋 En-têtes détectés :</h2>";

// Essayer différents délimiteurs
$delimiters = ["\t", ",", ";", "|"];
$foundDelimiter = null;
$headers = null;

foreach ($delimiters as $delim) {
    rewind($handle);
    $testLine = fgetcsv($handle, 0, $delim);
    if ($testLine && count($testLine) > 5) {
        $foundDelimiter = $delim;
        $headers = $testLine;
        break;
    }
}

if (!$headers) {
    // Lire la première ligne brute
    rewind($handle);
    $firstLine = fgets($handle);
    echo "<p>Première ligne brute :</p>";
    echo "<pre>" . htmlspecialchars($firstLine) . "</pre>";
    echo "<p>⚠️ Aucun délimiteur standard trouvé</p>";
    exit;
}

echo "<p>✅ Délimiteur trouvé : <code>" . htmlspecialchars($foundDelimiter) . "</code></p>";
echo "<p>Nombre de colonnes : " . count($headers) . "</p>";

echo "<h3>Colonnes (40 premières) :</h3>";
echo "<ul>";
foreach (array_slice($headers, 0, 40) as $index => $col) {
    $col = trim($col);
    echo "<li><strong>$index</strong> : " . htmlspecialchars($col) . "</li>";
}
echo "</ul>";

// Chercher les colonnes spécifiques
$searchCols = ['code', 'product_name_fr', 'brands', 'categories', 'generic_name_fr'];
echo "<h3>Recherche des colonnes :</h3>";
foreach ($searchCols as $search) {
    $found = array_search($search, array_map('trim', $headers));
    if ($found !== false) {
        echo "<p>✅ <strong>$search</strong> trouvé à l'index $found</p>";
    } else {
        echo "<p>❌ <strong>$search</strong> non trouvé</p>";
    }
}

// Afficher 3 lignes d'exemple
echo "<h3>Exemple de données (3 lignes) :</h3>";
$count = 0;
while (($row = fgetcsv($handle, 0, $foundDelimiter)) !== false && $count < 3) {
    echo "<h4>Ligne " . ($count + 1) . " :</h4>";
    echo "<pre>" . htmlspecialchars(implode(" | ", array_slice($row, 0, 10))) . "</pre>";
    $count++;
}

fclose($handle);

echo "<h2>💡 Propositions :</h2>";
echo "<ol>";
echo "<li>Vérifiez que vous avez bien téléchargé le fichier depuis <code>https://fr.openfoodfacts.org/data/fr.openfoodfacts.org.products.csv</code></li>";
echo "<li>Le fichier peut être encodé en UTF-8 avec BOM. Essayez de le ré-encoder.</li>";
echo "<li>Si les colonnes sont différentes, adaptez le script d'import.</li>";
echo "</ol>";