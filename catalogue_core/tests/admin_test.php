<?php
// Lancer : php catalogue_core/tests/admin_test.php
// L'administration du catalogue pour le tableau de bord BENS (GET/POST/PUT /admin/*) : vue, rayons, reclassement,
// tendances, produits à confirmer, jeton (SQLite en mémoire).
require __DIR__ . '/../routes.php';

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

function erreur(callable $f)
{
    try {
        $f();
        return null;
    } catch (Exception $e) {
        return $e->getCode();
    }
}

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE catalog_category (id INTEGER PRIMARY KEY, nom TEXT, parent_id INTEGER)');
$db->exec('CREATE TABLE catalog_brand (id INTEGER PRIMARY KEY, nom TEXT)');
$db->exec('CREATE TABLE catalog_product (id INTEGER PRIMARY KEY AUTOINCREMENT, code_produit TEXT, nom TEXT, brand_id INTEGER,
           category_id INTEGER, photo TEXT, source_id INTEGER)');
$db->exec('CREATE TABLE catalog_product_barcode (id INTEGER PRIMARY KEY, product_id INTEGER, barcode TEXT,
           is_primary INTEGER, source_id INTEGER)');
check('installer : à jour', Admin::installer($db) === ['a_jour' => true]);

$db->exec("INSERT INTO catalog_category VALUES (1, 'Beverages, Sodas', NULL)");
$ins = $db->prepare('INSERT INTO catalog_product (code_produit, nom, category_id, photo) VALUES (?, ?, 1, ?)');
for ($i = 1; $i <= 12; $i++) {
    $ins->execute([sprintf('30000000%05d', $i), "Soda $i", $i % 2 ? 'https://x/p.jpg' : null]);
}
$ins->execute(['3000000099999', 'Bière blonde', null]); // exclue

// ---- Reclassement par lots ----------------------------------------------------------------------
$r = Admin::reclasser($db, 0);
check('reclasser : tout en un lot (petite base)', $r['fini'] === true && $r['reste'] === 0 && $r['total'] === 13);
$v = Admin::vue($db);
$boissons = array_values(array_filter($v['rayons'], function ($x) { return $x['code'] === 'boissons'; }))[0];
check('vue : totaux', $v['totaux']['total'] === 13 && $v['totaux']['exclus'] === 1 && $v['totaux']['photos'] === 6 && $v['plafond'] === 5000);
check('vue : boissons, 12 produits proposés', $boissons['produits'] === 12 && $boissons['proposes'] === 12 && $boissons['actif'] === true);

// ---- Un rayon : lecture, modification, ajout ----------------------------------------------------------
$detail = Admin::rayon($db, 'boissons');
check('rayon : mots-clés en liste, aperçu', in_array('soda*', $detail['mots_cles'], true) && count($detail['apercu']) === 12);
check('rayon inconnu : 404', erreur(function () use ($db) { Admin::rayon($db, 'inconnu'); }) === 404);
$corps = ['nom_fr' => 'Boissons fraîches', 'nom_ar' => 'مشروبات', 'nom_en' => 'Cold drinks', 'icone' => 'cup-soda', 'ordre' => 5,
          'prioritaire' => false, 'secours' => false, 'actif' => true, 'mots_cles' => ['soda*', 'eau', 'soda*', ' ', 'jus']];
$apres = Admin::enregistrer($db, 'boissons', $corps);
check('enregistrer : noms, ordre, mots-clés dédoublonnés', $apres['nom_fr'] === 'Boissons fraîches' && $apres['ordre'] === 5
    && $apres['mots_cles'] === ['soda*', 'eau', 'jus']);
check('enregistrer : nom FR vide refusé', erreur(function () use ($db, $corps) { Admin::enregistrer($db, 'boissons', ['nom_fr' => ''] + $corps); }) === 400);
check('enregistrer : icône invalide refusée', erreur(function () use ($db, $corps) { Admin::enregistrer($db, 'boissons', ['icone' => 'Cup Soda!'] + $corps); }) === 400);
check('enregistrer : ordre invalide refusé', erreur(function () use ($db, $corps) { Admin::enregistrer($db, 'boissons', ['ordre' => '5'] + $corps); }) === 400);
$nouveau = Admin::ajouter($db, 'fruits_legumes');
check('ajouter : inactif, nom = code', $nouveau['actif'] === false && $nouveau['nom_fr'] === 'fruits_legumes' && $nouveau['apercu'] === []);
check('ajouter : déjà là → 409', erreur(function () use ($db) { Admin::ajouter($db, 'fruits_legumes'); }) === 409);
check('ajouter : code invalide → 400', erreur(function () use ($db) { Admin::ajouter($db, 'Fruits & légumes'); }) === 400);

// ---- Tendances recalculées d'un coup, séries du tableau de bord ---------------------------------------
$j = new DateTimeImmutable('today', new DateTimeZone('Africa/Algiers'));
foreach (['CD-AAAA-AAAA', 'CD-BBBB-BBBB', 'CD-CCCC-CCCC'] as $poste) {
    Tendance::enregistrer($db, $poste, ['ventes' => [['jour' => $j->format('Y-m-d'), 'code_barres' => '3000000000001', 'tickets' => 10]]], $j);
}
check('3 boutiques, 30 tickets : tendance', (int)$db->query("SELECT tendance FROM catalog_product WHERE code_produit = '3000000000001'")->fetchColumn() === 1);
$_ENV['TENDANCE_TICKETS_MIN'] = '40';
check('recalculer : forcé, même déjà fait aujourd\'hui', Admin::recalculer($db, $j) === ['produits' => 1]
    && (int)$db->query("SELECT tendance FROM catalog_product WHERE code_produit = '3000000000001'")->fetchColumn() === 0);
$_ENV['TENDANCE_TICKETS_MIN'] = '';
Admin::recalculer($db, $j);
$st = Tendance::stats($db, $j);
check('stats : série par jour', $st['jours'] === [['jour' => $j->format('Y-m-d'), 'tickets' => 30, 'postes' => 3, 'produits' => 1]]);

// ---- Produits à confirmer : liste, votes, confirmation et suppression à la main -----------------------
Tendance::enregistrer($db, 'CD-AAAA-AAAA', ['produits' => [
    ['code_barres' => '6130007000015', 'nom' => 'Couscous Mama 1kg', 'marque' => 'Mama'],
    ['code_barres' => '6130008000014', 'nom' => 'Harissa Maison'],
]], $j);
Tendance::enregistrer($db, 'CD-BBBB-BBBB', ['confirmations' => [['code_barres' => '6130007000015', 'vote' => 1]]], $j);
Tendance::enregistrer($db, 'CD-CCCC-CCCC', ['confirmations' => [['code_barres' => '6130007000015', 'vote' => -1]]], $j);
$liste = Admin::aConfirmer($db);
check('à confirmer : 2, les plus récents d\'abord', $liste['total'] === 2 && $liste['produits'][0]['nom'] === 'Harissa Maison');
$couscous = $liste['produits'][1];
check('à confirmer : votes, score, marque, créateur', $couscous['pour'] === 1 && $couscous['contre'] === 1 && $couscous['confirmation'] === 2
    && $couscous['marque'] === 'Mama' && $couscous['contribue_par'] === 'CD-AAAA-AAAA' && $couscous['cree_le'] !== null);
check('vue : 2 à confirmer', Admin::vue($db)['totaux']['a_confirmer'] === 2);
check('modérer : action inconnue → 400', erreur(function () use ($db, $couscous) { Admin::moderer($db, $couscous['id'], 'valider'); }) === 400);
check('modérer : confirmer', Admin::moderer($db, $couscous['id'], 'confirmer') === ['id' => $couscous['id'], 'action' => 'confirmer']
    && (int)$db->query('SELECT a_confirmer FROM catalog_product WHERE id = ' . $couscous['id'])->fetchColumn() === 0);
check('modérer : déjà confirmé → 404', erreur(function () use ($db, $couscous) { Admin::moderer($db, $couscous['id'], 'supprimer'); }) === 404);
$harissa = $liste['produits'][0]['id'];
Admin::moderer($db, $harissa, 'supprimer');
check('modérer : supprimé', (int)$db->query("SELECT COUNT(*) FROM catalog_product WHERE id = $harissa")->fetchColumn() === 0);
$log = $db->query("SELECT action, poste FROM catalog_contribution WHERE action IN ('confirme', 'supprime') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
check('modération notée (ADMIN)', $log === [['action' => 'confirme', 'poste' => 'ADMIN'], ['action' => 'supprime', 'poste' => 'ADMIN']]);
check('stats : contributions par jour', $st = Tendance::stats($db, $j) and $st['contributions_jours'][0]['cree'] === 2
    && $st['contributions_jours'][0]['confirme'] === 1 && $st['contributions_jours'][0]['supprime'] === 1);

// ---- Une boutique de cosmétiques : rayons sans tendance, comparaison, import -----------------------------
$db->exec("INSERT INTO catalog_category VALUES (2, 'Cosmetics, Lipsticks', NULL)");
$ins = $db->prepare('INSERT INTO catalog_product (code_produit, nom, category_id, photo) VALUES (?, ?, 2, ?)');
$ins->execute(['3600000000011', 'Rouge à lèvres Velours', 'https://x/r.jpg']);
$ins->execute(['3600000000028', 'Mascara Volume', null]);
Admin::reclasser($db, 0);
foreach (['CD-AAAA-AAAA', 'CD-BBBB-BBBB', 'CD-CCCC-CCCC'] as $poste) {
    Tendance::enregistrer($db, $poste, ['ventes' => [['jour' => $j->format('Y-m-d'), 'code_barres' => '3600000000028', 'tickets' => 10]]], $j);
}
$tendanceDe = function ($code) use ($db) {
    return (int)$db->query("SELECT tendance FROM catalog_product WHERE code_produit = '$code'")->fetchColumn();
};
check('mascara vendu par 3 boutiques : tendance', $tendanceDe('3600000000028') === 1);
$st = Admin::sansTendance($db, ['cosmetiques', 'hygiene']);
check('sans tendance : les 2 produits des cosmétiques', $st['produits'] === 2 && $tendanceDe('3600000000028') === 0);
Admin::recalculer($db, $j);
check('sans tendance : gardé au recalcul malgré les ventes', $tendanceDe('3600000000028') === 0);
check('sans tendance : les autres rayons restent calculés', $db->query("SELECT COUNT(*) FROM catalog_product WHERE rayon_code <> 'cosmetiques' AND tendance_forcee IS NOT NULL")->fetchColumn() == 0);
check('sans tendance : rayon inconnu → 404', erreur(function () use ($db) { Admin::sansTendance($db, ['inconnu']); }) === 404);
check('sans tendance : pas de liste → 400', erreur(function () use ($db) { Admin::sansTendance($db, 'cosmetiques'); }) === 400);

$avantConsult = (int)$db->query("SELECT consultations FROM catalog_product WHERE code_produit = '3600000000011'")->fetchColumn();
$cmp = Admin::comparer($db, ['3600000000011', '3600000000028', '6130000000017', '09660', '3600000000011']);
check('comparer : existants (photo ou non)', count($cmp['existants']) === 2 && $cmp['existants'][0]['photo'] === true
    && $cmp['existants'][1]['photo'] === false && $cmp['existants'][0]['rayon'] === 'cosmetiques');
check('comparer : absents, codes internes à part', $cmp['absents'] === ['6130000000017'] && $cmp['invalides'] === ['09660']);
check('comparer : aucune recherche comptée', (int)$db->query("SELECT consultations FROM catalog_product WHERE code_produit = '3600000000011'")->fetchColumn() === $avantConsult);
check('comparer : lot trop gros → 400', erreur(function () use ($db) { Admin::comparer($db, array_fill(0, Admin::LOT_BOUTIQUE + 1, '3600000000011')); }) === 400);

$nomAvant = $db->query("SELECT nom FROM catalog_product WHERE code_produit = '3600000000028'")->fetchColumn();
$imp = Admin::importer($db, [
    ['code_barres' => '3600000000028', 'nom' => 'MASCARA VOL'],
    ['code_barres' => '6130000000017', 'nom' => 'Vernis à ongles Rose'],
    ['code_barres' => '09660', 'nom' => 'Code interne'],
    ['code_barres' => '6130000000024'],
]);
check('importer : 1 créé, codes interne et sans nom ignorés', count($imp['crees']) === 1 && $imp['crees'][0]['code_barres'] === '6130000000017'
    && $imp['ignores'] === ['09660', '6130000000024'] && $imp['tendances'] === 2);
$vernis = $db->query("SELECT * FROM catalog_product WHERE code_produit = '6130000000017'")->fetch(PDO::FETCH_ASSOC);
check('importer : créé confirmé, rangé, en tendance', (int)$vernis['a_confirmer'] === 0 && $vernis['confirme_le'] !== null
    && $vernis['contribue_par'] === 'ADMIN' && $vernis['rayon_code'] === 'cosmetiques' && (int)$vernis['tendance'] === 1
    && $vernis['tendance_le'] !== null && (int)$vernis['points_tendance'] === Tendance::points(3, 20));
check('importer : produit existant pas modifié, mis en tendance', $tendanceDe('3600000000028') === 1
    && $db->query("SELECT nom FROM catalog_product WHERE code_produit = '3600000000028'")->fetchColumn() === $nomAvant);
Admin::recalculer($db, $j);
check('importer : tendance gardée au recalcul', $tendanceDe('3600000000028') === 1 && $tendanceDe('6130000000017') === 1);
$nouveautes = Rayons::produits($db, 'cosmetiques', 1, 50, $j->format('Y-m-d'));
check('importer : proposé dans les nouveautés du rayon', in_array('6130000000017', array_column($nouveautes['products'], 'code_barres'), true));
$imp = Admin::importer($db, [
    ['code_barres' => '6130000000031', 'nom' => 'TENT ANDREA BN'],
    ['code_barres' => '6130000000048', 'nom' => 'LAIT DEMAQ EVOLUDERM 250ML'],
    ['code_barres' => '6130000000055', 'nom' => 'SHAMPOING DOUX 400ML'],
], ['cosmetiques', 'hygiene', 'parapharmacie']);
$rayonDe = function ($code) use ($db) {
    return $db->query("SELECT rayon_code FROM catalog_product WHERE code_produit = '$code'")->fetchColumn();
};
check('importer avec rayons : sans rayon ou hors beauté → cosmétiques, hygiène gardée', count($imp['crees']) === 3
    && $rayonDe('6130000000031') === 'cosmetiques' && $rayonDe('6130000000048') === 'cosmetiques' && $rayonDe('6130000000055') === 'hygiene');
Admin::reclasser($db, 0);
check('importer avec rayons : le reclassement les laisse', $rayonDe('6130000000031') === 'cosmetiques'
    && $rayonDe('6130000000048') === 'cosmetiques' && $rayonDe('6130000000055') === 'hygiene');
check('importer : rayon inconnu → 404', erreur(function () use ($db) { Admin::importer($db, [], ['inconnu']); }) === 404);
check('importer : rien de neuf au second passage', Admin::importer($db, [['code_barres' => '6130000000017', 'nom' => 'Vernis']])['crees'] === []);

// ---- Le jeton et les routes ------------------------------------------------------------------------
$_ENV['REV_SERVER_TOKEN'] = 'jeton-partage';
$_SERVER['HTTP_X_SERVER_TOKEN'] = 'faux';
check('route : mauvais jeton → 401', erreur(function () use ($db) { adminRoute($db, 'GET', 'catalogue', null, null); }) === 401);
$_SERVER['HTTP_X_SERVER_TOKEN'] = 'jeton-partage';
ob_start();
@adminRoute($db, 'GET', 'catalogue', null, null);
$sortie = json_decode(ob_get_clean(), true);
check('route GET catalogue : servie', $sortie['success'] === true && count($sortie['data']['rayons']) === 17);
ob_start();
@adminRoute($db, 'GET', 'rayons', 'boissons', null);
check('route GET rayons/boissons', json_decode(ob_get_clean(), true)['data']['nom_fr'] === 'Boissons fraîches');
check('route inconnue → 404', erreur(function () use ($db) { adminRoute($db, 'DELETE', 'rayons', 'boissons', null); }) === 404);

echo "$ok réussis, $ko échecs\n";
exit($ko === 0 ? 0 : 1);
