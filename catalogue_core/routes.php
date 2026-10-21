<?php
/**
 * Routes des rayons, incluses par index.php (lecture publique, comme /products).
 *
 *   GET /rayons                              rayons actifs : {plafond, rayons: [{code, nom_fr, nom_ar, nom_en,
 *                                            icone, ordre, produits}]} (produits = au plus plafond)
 *   GET /rayons/{code}/products?page=&limit= une page des produits proposés par le rayon (les mieux classés,
 *                                            porc et alcool exclus) : {rayon, total, page, limit, total_pages,
 *                                            products: [{id, code_barres, codes_barres, nom, marque, photo}]}
 */

require_once __DIR__ . '/Rayons.php';
require_once __DIR__ . '/RayonsSchema.php';

function rayonsRoute(PDO $db, $code, $sub, $method)
{
    if ($method !== 'GET') {
        throw new Exception('Méthode non autorisée', 405);
    }
    if ($code === null || $code === '') {
        $donnees = Rayons::liste($db);
    } elseif ($sub === 'products') {
        $donnees = Rayons::produits($db, $code, $_GET['page'] ?? 1, $_GET['limit'] ?? Rayons::LOT_PAGE_MAX);
    } else {
        throw new Exception('Route non trouvée', 404);
    }
    header('Cache-Control: public, max-age=300');
    echo json_encode(['success' => true, 'data' => $donnees], JSON_UNESCAPED_UNICODE);
}
