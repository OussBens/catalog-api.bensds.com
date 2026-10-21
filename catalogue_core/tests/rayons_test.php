<?php
// Lancer : php catalogue_core/tests/rayons_test.php
// Rayons : tri, exclusion porc / alcool, classement et pages servies à CaisseDZ Desktop (SQLite en mémoire).
require __DIR__ . '/../Rayons.php';
require __DIR__ . '/../RayonsSchema.php';
require __DIR__ . '/../Tendance.php';
require __DIR__ . '/../Contributions.php';

$ok = 0;
$ko = 0;
function check($label, $cond)
{
    global $ok, $ko;
    if ($cond) {
        $ok++;
    } else {
        $ko++;
        echo "ÉCHEC : $label\n";
    }
}

// ---- Tri ---------------------------------------------------------------------------------------
$r = Rayons::preparerParDefaut();
$cas = [
    // [nom, catégorie brute, rayon attendu]
    ['Eau ELkantara 250', '', 'boissons'],
    ['', 'Snacks, Snacks sucrés, Biscuits et gâteaux, Gâteaux, Madeleines', 'biscuits_confiserie'],
    ['', 'Plant-based foods and beverages, Beverages, Teas, Green teas', 'cafe_the'],
    ['', 'Dairies, Milks, Chocolate milks', 'laitiers'],
    ['', 'Snacks, Sweet snacks, Cocoa and its products, Chocolates, Milk chocolates', 'biscuits_confiserie'],
    ['', 'Baby foods, Cereals for babies', 'bebe'],
    ['', 'Hair, Shampoos, Shampoos for babies', 'bebe'],
    ['', 'Some-tag, Croquettes chat', 'animalerie'],
    ['', 'Dairies, Fats, Animal fats, Butters', 'laitiers'],
    ['', 'Sweeteners, Sugars, Granulated cane sugars', 'epicerie'],
    ['', 'Visage, Démaquillants, Laits démaquillants', 'cosmetiques'],
    ['', 'Body Wash, Cosmetics', 'hygiene'],
    ['', 'Corps, Laits pour le corps, Peaux sèches', 'cosmetiques'],
    ['', 'Dietary supplements, Vitamins', 'parapharmacie'],
    ['', 'Detergent, Laundry Detergent', 'entretien'],
    ['', 'Desserts, Frozen foods, Ice creams', 'surgeles'],
    ['', 'Canned foods, Condiments, Soups', 'conserves_condiments'],
    ['', 'Beef, Fresh meats', 'viandes_poissons'],
    ['', 'Alimentos y bebidas de origen vegetal, Cereales y patatas, Panes', 'epicerie'],
    ['Lait Candia 1L', 'Sans Categorie', 'laitiers'],
    ['حليب كانديا', '', 'laitiers'],
    ['زيت عافية', '', 'epicerie'],
    ['', '068488 075279', null],
];
foreach ($cas as [$nom, $cat, $attendu]) {
    $obtenu = Rayons::classer($nom, $cat === 'Sans Categorie' ? '' : $cat, $r);
    check("classer($nom | $cat) = " . var_export($attendu, true) . ', obtenu ' . var_export($obtenu, true), $obtenu === $attendu);
}

// ---- Exclusion porc / alcool --------------------------------------------------------------------
$exclus = [
    ['', 'Alcoholic beverages, Wines'],
    ['Heineken', '5% beer'],
    ['', 'en:Rosé wines'],
    ['Jambon cuit supérieur', 'Viandes, Charcuteries'],
    ['', 'Viandes, Porc, Côtes de porc'],
    ['Bacon fumé', ''],
    ['Bière sans alcool', 'Boissons'],
    ['Saucisson sec', ''],
    ['نبيذ أحمر', ''],
];
foreach ($exclus as [$nom, $cat]) {
    check("exclu : $nom | $cat", Rayons::estExclu($nom, $cat));
}
$gardes = [
    ['Jambon de dinde', 'Charcuteries'],
    ['Saucisson halal', ''],
    ['Cachir poulet', ''],
    ['Vinaigre de vin rouge', 'Condiments, Vinaigres'],
    ['Vinaigre d\'alcool', 'Condiments'],
    ['Parfum sans alcool', 'Cosmetics'],
    ['', 'Non-alcoholic beverages, Sodas'],
    ['Gel hydroalcoolique', 'Hygiène'],
    ['Ginger ale', 'Sodas'],
    ['Biscuits apéritif', 'Snacks salés'],
    ['Levure de bière', 'Compléments alimentaires'],
    ['Vinaigrette', ''],
];
foreach ($gardes as [$nom, $cat]) {
    check("gardé : $nom | $cat", !Rayons::estExclu($nom, $cat));
}

// ---- Base : installation, classement, pages -----------------------------------------------------
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE catalog_category (id INTEGER PRIMARY KEY, nom TEXT, parent_id INTEGER)');
$db->exec('CREATE TABLE catalog_brand (id INTEGER PRIMARY KEY, nom TEXT)');
$db->exec('CREATE TABLE catalog_product (id INTEGER PRIMARY KEY, code_produit TEXT, nom TEXT, brand_id INTEGER,
           category_id INTEGER, photo TEXT)');
$db->exec('CREATE TABLE catalog_product_barcode (id INTEGER PRIMARY KEY, product_id INTEGER, barcode TEXT)');
RayonsSchema::installer($db);
RayonsSchema::installer($db); // idempotent
check('16 rayons installés', (int)$db->query('SELECT COUNT(*) FROM catalog_rayon')->fetchColumn() === 16);

$db->exec("INSERT INTO catalog_category VALUES (1, 'Sans Categorie', NULL), (2, 'Beverages, Sodas', NULL),
           (3, 'Alcoholic beverages, Beers', NULL)");
$db->exec("INSERT INTO catalog_brand VALUES (1, 'Hamoud')");
$ins = $db->prepare('INSERT INTO catalog_product (id, code_produit, nom, brand_id, category_id, photo) VALUES (?, ?, ?, ?, ?, ?)');
$ins->execute([1, '6130001000011', 'Selecto 1L', 1, 2, 'https://x/1.jpg']); // algérien + photo = 60
$ins->execute([2, '3000000000002', 'Cola 33cl', null, 2, null]);              // 0
$ins->execute([3, '5000000000003', 'Pils', null, 3, null]);                   // exclu
$ins->execute([4, '3000000000004', 'Eau minérale', null, 1, 'https://x/4.jpg']); // photo = 10
$ins->execute([5, '', 'Limonade', null, 1, null]);                            // code-barres seulement dans la table
$db->exec("INSERT INTO catalog_product_barcode (product_id, barcode) VALUES (1, '6130001000011'), (1, '6130001000028'),
           (5, '6130009999990')");

$dernier = Rayons::reclasser($db, 0, 3);
check('premier lot : jusqu\'à l\'id 3', $dernier === 3);
check('second lot : jusqu\'à l\'id 5', Rayons::reclasser($db, $dernier, 3) === 5);
check('plus rien à classer', Rayons::reclasser($db, 5, 3) === null);

$p = $db->query('SELECT id, rayon_code, exclu, score FROM catalog_product ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
check('Selecto : boissons, score 60', $p[0]['rayon_code'] === 'boissons' && (int)$p[0]['score'] === 60);
check('Pils : exclu', (int)$p[2]['exclu'] === 1);
check('Limonade : algérienne par sa table de codes-barres', (int)$p[4]['score'] === 50);

Rayons::compterConsultation($db, 2);
check('une consultation : +100', (int)$db->query('SELECT score FROM catalog_product WHERE id = 2')->fetchColumn() === 100);

$liste = Rayons::liste($db);
$boissons = array_values(array_filter($liste['rayons'], function ($x) { return $x['code'] === 'boissons'; }))[0];
check('plafond 5000', $liste['plafond'] === 5000);
check('boissons : 4 produits (Pils exclu)', $boissons['produits'] === 4);
check('noms FR/AR/EN et icône', $boissons['nom_ar'] === 'المشروبات' && $boissons['icone'] === 'cup-soda');

$page = Rayons::produits($db, 'boissons', 1, 2);
check('page 1 : 2 produits sur 4, 2 pages', count($page['products']) === 2 && $page['total'] === 4 && $page['total_pages'] === 2);
check('classement : Cola (100) puis Selecto (60)', $page['products'][0]['nom'] === 'Cola 33cl' && $page['products'][1]['nom'] === 'Selecto 1L');
check('codes-barres de Selecto', $page['products'][1]['codes_barres'] === ['6130001000011', '6130001000028']);
check('marque', $page['products'][1]['marque'] === 'Hamoud');
$page2 = Rayons::produits($db, 'boissons', 2, 2);
check('page 2 : Limonade (code-barres de la table)', $page2['products'][0]['nom'] === 'Limonade'
    && $page2['products'][0]['code_barres'] === '6130009999990');
check('au-delà : page vide', Rayons::produits($db, 'boissons', 9, 2)['products'] === []);
try {
    Rayons::produits($db, 'inconnu');
    check('rayon inconnu : 404', false);
} catch (Exception $e) {
    check('rayon inconnu : 404', $e->getCode() === 404);
}

echo "$ok réussis, $ko échecs\n";
exit($ko === 0 ? 0 : 1);
