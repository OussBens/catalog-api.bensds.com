<?php
/**
 * Routes des rayons, incluses par index.php (lecture publique, comme /products).
 *
 *   GET /rayons                              rayons actifs : {plafond, rayons: [{code, nom_fr, nom_ar, nom_en,
 *                                            icone, ordre, produits, tendances}]} (au plus plafond)
 *   GET /rayons/{code}/products?page=&limit=[&depuis=AAAA-MM-JJ]
 *                                            une page des produits proposés par le rayon (les mieux classés,
 *                                            porc et alcool exclus ; depuis : seulement ceux devenus tendance
 *                                            depuis ce jour) : {rayon, total, page, limit, total_pages,
 *                                            products: [{id, code_barres, codes_barres, nom, nom_ar, tendance,
 *                                            marque, photo}]}
 *   POST /tendance                           envoi d'un poste CaisseDZ Desktop (X-Device-Id + X-License-Key,
 *                                            sa licence BENS signée) : {produits: [{code_barres, codes_barres, nom,
 *                                            nom_ar, marque, categorie}], ventes: [{jour, code_barres, tickets}]}
 *                                            → {ventes, inconnus, crees, completes}
 *   GET  /tendance/stats                     tableau de bord BENS (X-Server-Token = REV_SERVER_TOKEN)
 */

require_once __DIR__ . '/Rayons.php';
require_once __DIR__ . '/RayonsSchema.php';
require_once __DIR__ . '/Tendance.php';
require_once __DIR__ . '/Contributions.php';
require_once __DIR__ . '/../smartscan_core/SmartScanService.php';
require_once __DIR__ . '/../smartscan_core/LicenceBens.php';

function rayonsRoute(PDO $db, $code, $sub, $method)
{
    if ($method !== 'GET') {
        throw new Exception('Méthode non autorisée', 405);
    }
    if ($code === null || $code === '') {
        $donnees = Rayons::liste($db);
    } elseif ($sub === 'products') {
        $depuis = isset($_GET['depuis']) && $_GET['depuis'] !== '' ? $_GET['depuis'] : null;
        $donnees = Rayons::produits($db, $code, $_GET['page'] ?? 1, $_GET['limit'] ?? Rayons::LOT_PAGE_MAX, $depuis);
    } else {
        throw new Exception('Route non trouvée', 404);
    }
    header('Cache-Control: public, max-age=300');
    echo json_encode(['success' => true, 'data' => $donnees], JSON_UNESCAPED_UNICODE);
}

/**
 * Le code (CD-XXXX-XXXX) du poste dont la licence BENS signée est valable aujourd'hui, ou une exception 401.
 * Même vérification que Smart Scan (LICENSE_PUBLIC_KEY, LICENSE_PRODUCT du .env).
 */
function tendancePoste($deviceId, $cle)
{
    $clePublique = trim((string)($_ENV['LICENSE_PUBLIC_KEY'] ?? ''));
    if ($clePublique === '') {
        throw new Exception('LICENSE_PUBLIC_KEY non configurée', 503);
    }
    $deviceId = trim((string)$deviceId);
    if ($deviceId === '' || strlen($deviceId) > 64 || !preg_match('/^[A-Za-z0-9_-]+$/', $deviceId)) {
        throw new Exception('Licence Caisse DZ manquante ou invalide', 401);
    }
    $produit = (int)(($_ENV['LICENSE_PRODUCT'] ?? '') === '' ? 1 : $_ENV['LICENSE_PRODUCT']);
    try {
        $palier = LicenceBens::palier($deviceId, trim((string)$cle), $clePublique, $produit);
    } catch (SmartScanException $e) {
        throw new Exception($e->getMessage(), 503);
    }
    if ($palier === null) {
        throw new Exception('Licence Caisse DZ manquante ou invalide', 401);
    }
    return LicenceBens::codePoste($deviceId);
}

/** Le tableau de bord BENS : `X-Server-Token` (ou `Authorization: Bearer`) = REV_SERVER_TOKEN, le jeton partagé avec bens_ds. */
function tendanceStats(PDO $db)
{
    $attendu = (string)($_ENV['REV_SERVER_TOKEN'] ?? '');
    if ($attendu === '') {
        throw new Exception('REV_SERVER_TOKEN non configuré', 503);
    }
    // X-Server-Token d'abord : Apache (CGI / FPM) ne transmet pas toujours Authorization à PHP.
    $entete = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    $jeton = trim((string)($_SERVER['HTTP_X_SERVER_TOKEN'] ?? ''));
    if ($jeton === '' && stripos($entete, 'Bearer ') === 0) {
        $jeton = trim(substr($entete, 7));
    }
    if ($jeton === '' || !hash_equals($attendu, $jeton)) {
        throw new Exception('Jeton invalide', 401);
    }
    header('Cache-Control: no-store');
    echo json_encode(['success' => true, 'data' => Tendance::stats($db)], JSON_UNESCAPED_UNICODE);
}

function tendanceRoute(PDO $db, $method, $action = null)
{
    if ($action === 'stats' && $method === 'GET') {
        tendanceStats($db);
        return;
    }
    if ($method !== 'POST' || ($action !== null && $action !== '')) {
        throw new Exception('Méthode non autorisée', 405);
    }
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 2 * 1024 * 1024) {
        throw new Exception('Envoi trop volumineux', 413);
    }
    $poste = tendancePoste($_SERVER['HTTP_X_DEVICE_ID'] ?? '', $_SERVER['HTTP_X_LICENSE_KEY'] ?? '');
    $corps = json_decode(file_get_contents('php://input'), true);
    if (!is_array($corps)) {
        throw new Exception('Requête invalide', 400);
    }
    header('Cache-Control: no-store');
    echo json_encode(['success' => true, 'data' => Tendance::enregistrer($db, $poste, $corps)], JSON_UNESCAPED_UNICODE);
}
