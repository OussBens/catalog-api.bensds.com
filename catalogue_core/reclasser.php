<?php
/**
 * Classe tout le catalogue par rayon (porc / alcool exclus, score de classement), en ligne de commande :
 *
 *   php catalogue_core/reclasser.php            installe les tables si besoin, puis classe tout
 *   php catalogue_core/reclasser.php 120000     reprend après l'id 120000
 *
 * À lancer après l'import de produits ou un changement de mots-clés (ou en tâche cron, la nuit).
 * Le même travail se fait aussi depuis admin_rayons.php, par lots.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/Rayons.php';
require_once __DIR__ . '/RayonsSchema.php';

foreach (file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $ligne) {
    if (strpos(trim($ligne), '#') !== 0 && strpos($ligne, '=') !== false) {
        [$cle, $valeur] = explode('=', $ligne, 2);
        $_ENV[trim($cle)] = trim($valeur, "\"' ");
    }
}
$db = new PDO(
    'mysql:host=' . ($_ENV['DB_HOST'] ?? 'localhost') . ';dbname=' . ($_ENV['DB_NAME'] ?? '') . ';charset=utf8mb4',
    $_ENV['DB_USER'] ?? '',
    $_ENV['DB_PASSWORD'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
RayonsSchema::installer($db);

$rayons = Rayons::preparer($db);
$depuis = (int)($argv[1] ?? 0);
$debut = microtime(true);
$n = 0;
while (($dernier = Rayons::reclasser($db, $depuis, 5000, $rayons)) !== null) {
    $n += 5000;
    $depuis = $dernier;
    fwrite(STDOUT, sprintf("… id %d (%d s)\n", $depuis, microtime(true) - $debut));
}
foreach (Rayons::liste($db)['rayons'] as $r) {
    fwrite(STDOUT, sprintf("%-22s %5d\n", $r['code'], $r['produits']));
}
fwrite(STDOUT, sprintf("Terminé en %d s.\n", microtime(true) - $debut));
