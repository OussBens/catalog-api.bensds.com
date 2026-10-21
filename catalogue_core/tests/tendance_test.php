<?php
// Lancer : php -d extension=sodium catalogue_core/tests/tendance_test.php
// Tendance : ventes des postes, fenêtre de 90 jours, seuils (boutiques ET tickets), fiches apportées par les
// boutiques (créées, complétées), statistiques du tableau de bord (SQLite en mémoire).
require __DIR__ . '/../Rayons.php';
require __DIR__ . '/../RayonsSchema.php';
require __DIR__ . '/../Tendance.php';
require __DIR__ . '/../Contributions.php';
require __DIR__ . '/../Confirmations.php';

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

function produit(PDO $db, $id)
{
    $s = $db->prepare('SELECT * FROM catalog_product WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC);
}

function parCode(PDO $db, $code)
{
    $ids = Tendance::produitsParCodeBarres($db, [$code]);
    return isset($ids[$code]) ? produit($db, $ids[$code]) : null;
}

function vente($jour, $code, $tickets)
{
    return ['jour' => $jour, 'code_barres' => $code, 'tickets' => $tickets];
}

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE catalog_category (id INTEGER PRIMARY KEY, nom TEXT, parent_id INTEGER)');
$db->exec('CREATE TABLE catalog_brand (id INTEGER PRIMARY KEY, nom TEXT)');
$db->exec('CREATE TABLE catalog_product (id INTEGER PRIMARY KEY, code_produit TEXT, nom TEXT, brand_id INTEGER,
           category_id INTEGER, photo TEXT, source_id INTEGER)');
$db->exec('CREATE TABLE catalog_product_barcode (id INTEGER PRIMARY KEY, product_id INTEGER, barcode TEXT,
           is_primary INTEGER, source_id INTEGER)');
RayonsSchema::installer($db);
RayonsSchema::installer($db); // idempotent
check('tables des ventes, contributions et postes créées',
    (int)$db->query('SELECT COUNT(*) FROM catalog_vente_jour')->fetchColumn()
    + (int)$db->query('SELECT COUNT(*) FROM catalog_contribution')->fetchColumn()
    + (int)$db->query('SELECT COUNT(*) FROM catalog_poste')->fetchColumn() === 0);

$db->exec("INSERT INTO catalog_category VALUES (1, 'Beverages, Sodas', NULL), (2, 'Sans Categorie', NULL)");
$ins = $db->prepare('INSERT INTO catalog_product (id, code_produit, nom, category_id, photo) VALUES (?, ?, ?, ?, ?)');
$ins->execute([1, '6130001000011', 'Selecto 1L', 1, 'https://x/1.jpg']);   // 60
$ins->execute([2, '3000000000002', 'Cola 33cl', 1, null]);                  // 0
$ins->execute([3, '3000000000003', 'مشروب غازي', 1, null]);                 // nom arabe
$ins->execute([4, '3000000000004', '', 2, null]);                           // fiche vide, sans rayon
$db->exec("INSERT INTO catalog_product_barcode (product_id, barcode) VALUES (1, '6130001000011'), (1, '6130001000028'),
           (2, '3000000000002')");
Rayons::reclasser($db, 0, 100);
check('nom arabe repris du nom', produit($db, 3)['nom_ar'] === 'مشروب غازي');
check('nom latin : pas de nom arabe', produit($db, 1)['nom_ar'] === null);
check('fiche vide : aucun rayon', produit($db, 4)['rayon_code'] === null);
check('seuils par défaut : 3 boutiques, 20 tickets', Tendance::postesMin() === 3 && Tendance::ticketsMin() === 20);
check('5 000 produits par rayon', Rayons::PLAFOND === 5000);

$tz = new DateTimeZone('Africa/Algiers');
$j = new DateTimeImmutable('2026-10-21', $tz);
$hier = '2026-10-20';

// ---- Une première boutique ----------------------------------------------------------------------
$r = Tendance::enregistrer($db, 'CD-AAAA-AAAA', [
    'ventes' => [
        vente('2026-10-21', '3000000000002', 2),
        vente($hier, '3000000000002', 80),          // plafonné à 50
        vente('2026-10-21', '9999999999999', 3),    // inconnu
        vente('2026-06-01', '3000000000002', 5),    // hors fenêtre
        vente('hier', '3000000000002', 5),          // illisible
        vente('2026-10-21', '6130001000011', 0),    // aucun ticket
    ],
], $j);
check('2 lignes enregistrées, 1 code-barres inconnu', $r['ventes'] === 2 && $r['inconnus'] === 1);
$p = produit($db, 2);
check('Cola : 1 boutique, 52 tickets (50 au plus par jour)', (int)$p['postes_90j'] === 1 && (int)$p['ventes_90j'] === 52);
check('Cola : 1 520 points ajoutés au score', (int)$p['points_tendance'] === 1520 && (int)$p['score'] === 1520);
check('une seule boutique : pas tendance', (int)$p['tendance'] === 0 && $p['tendance_le'] === null);

// Renvoyer un jour le remplace (le poste renvoie le jour en cours à chaque fois).
Tendance::enregistrer($db, 'CD-AAAA-AAAA', ['ventes' => [vente('2026-10-21', '3000000000002', 4)]], $j);
$p = produit($db, 2);
check('jour renvoyé : remplacé, pas ajouté', (int)$p['ventes_90j'] === 54 && (int)$p['score'] === 1540);

// Deux codes-barres du même produit le même jour : additionnés.
Tendance::enregistrer($db, 'CD-AAAA-AAAA', ['ventes' => [
    vente('2026-10-21', '6130001000011', 3),
    vente('2026-10-21', '6130001000028', 2),
]], $j);
check('Selecto : 5 tickets par ses deux codes-barres', (int)produit($db, 1)['ventes_90j'] === 5);

// ---- Tendance : 3 boutiques ET 20 tickets ---------------------------------------------------------
Tendance::enregistrer($db, 'CD-BBBB-BBBB', ['ventes' => [vente('2026-10-21', '3000000000002', 1), vente('2026-10-21', '6130001000011', 1)]], $j);
$p = produit($db, 2);
check('Cola : 2 boutiques, 55 tickets → pas encore tendance', (int)$p['postes_90j'] === 2 && (int)$p['tendance'] === 0);
Tendance::enregistrer($db, 'CD-CCCC-CCCC', ['ventes' => [vente('2026-10-21', '3000000000002', 1), vente('2026-10-21', '6130001000011', 1)]], $j);
$p = produit($db, 2);
check('Cola : 3 boutiques, 56 tickets → tendance', (int)$p['postes_90j'] === 3 && (int)$p['tendance'] === 1);
check('tendance depuis aujourd\'hui', strpos((string)$p['tendance_le'], '2026-10-21') === 0);
check('score : 3 boutiques, 56 tickets', (int)$p['score'] === 3560);
$s = produit($db, 1);
check('Selecto : 3 boutiques mais 7 tickets → pas tendance', (int)$s['postes_90j'] === 3 && (int)$s['ventes_90j'] === 7 && (int)$s['tendance'] === 0);
$_ENV['TENDANCE_TICKETS_MIN'] = '5';
Tendance::recalculer($db, [1], $j);
check('seuil de tickets à 5 (.env) : Selecto tendance', (int)produit($db, 1)['tendance'] === 1);
$_ENV['TENDANCE_TICKETS_MIN'] = '';
Tendance::recalculer($db, [1], $j);
check('seuil revenu à 20 : Selecto plus tendance', (int)produit($db, 1)['tendance'] === 0);
$le = produit($db, 2)['tendance_le'];
Tendance::enregistrer($db, 'CD-BBBB-BBBB', ['ventes' => [vente('2026-10-21', '3000000000002', 2)]], $j);
check('toujours tendance : même date', produit($db, 2)['tendance_le'] === $le);

$liste = Rayons::liste($db);
$boissons = array_values(array_filter($liste['rayons'], function ($x) { return $x['code'] === 'boissons'; }))[0];
check('boissons : 1 produit en tendance', $boissons['tendances'] === 1);

$page = Rayons::produits($db, 'boissons');
check('Cola en tête du rayon, marqué tendance', $page['products'][0]['nom'] === 'Cola 33cl' && $page['products'][0]['tendance'] === true);
check('nom arabe envoyé', $page['products'][2]['nom_ar'] === 'مشروب غازي' && $page['products'][0]['nom_ar'] === null);
$nouveaux = Rayons::produits($db, 'boissons', 1, 500, '2026-10-21');
check('depuis aujourd\'hui : seulement Cola', $nouveaux['total'] === 1 && $nouveaux['products'][0]['nom'] === 'Cola 33cl');
check('depuis demain : rien', Rayons::produits($db, 'boissons', 1, 500, '2026-10-22')['total'] === 0);
try {
    Rayons::produits($db, 'boissons', 1, 500, 'hier');
    check('depuis illisible : 400', false);
} catch (Exception $e) {
    check('depuis illisible : 400', $e->getCode() === 400);
}

// Le reclassement garde les points de tendance.
Rayons::reclasser($db, 0, 100);
check('reclassé : score avec la tendance', (int)produit($db, 2)['score'] === 3570);

// ---- Fiches apportées par les boutiques -----------------------------------------------------------
$r = Tendance::enregistrer($db, 'CD-AAAA-AAAA', [
    'produits' => [
        // Inconnu : créé tout de suite, rangé dans son rayon, ses ventes comptées dans le même envoi.
        ['code_barres' => '6130007000015', 'codes_barres' => ['6130007000015', '6130007000022', 'CDZ1'], 'nom' => ' Couscous  Mama 1kg ',
         'nom_ar' => 'كسكس ماما', 'marque' => 'Mama', 'categorie' => 'Pâtes, Couscous'],
        // Connu : seuls les champs vides sont complétés (nom arabe, marque) ; la catégorie reste.
        ['code_barres' => '3000000000002', 'nom' => 'Coca-Cola 33', 'nom_ar' => 'كولا', 'marque' => 'Coca', 'categorie' => 'Sodas'],
        // Connu et complet : rien.
        ['code_barres' => '3000000000003', 'nom' => 'Soda', 'nom_ar' => 'اسم آخر'],
        // Fiche vide du catalogue : nom et catégorie reçus, elle trouve un rayon.
        ['code_barres' => '3000000000004', 'nom' => 'Eau minérale Ifri 1,5L', 'categorie' => 'Eaux'],
        // Exclu (porc) mais gardé ; code interne et fiche sans nom ignorés ; nom arabe latin refusé.
        ['code_barres' => '3000000000099', 'nom' => 'Jambon de porc', 'nom_ar' => 'Jambon'],
        ['code_barres' => 'CDZ2610210001', 'nom' => 'Pain maison'],
        ['code_barres' => '3000000000098'],
    ],
    'ventes' => [vente('2026-10-21', '6130007000022', 3)],
], $j);
check('2 créés, 2 complétés', $r['crees'] === 2 && $r['completes'] === 2);
check('ventes du produit créé comptées', $r['ventes'] === 1 && $r['inconnus'] === 0);
$c = parCode($db, '6130007000015');
check('créé : nom nettoyé, nom arabe, boutique notée', $c['nom'] === 'Couscous Mama 1kg' && $c['nom_ar'] === 'كسكس ماما'
    && $c['contribue_par'] === 'CD-AAAA-AAAA' && $c['nom_ar_poste'] === 'CD-AAAA-AAAA' && (int)$c['source_id'] === 1);
check('créé : rangé dans l\'épicerie, code-barres algérien', $c['rayon_code'] === 'epicerie' && (int)$c['exclu'] === 0
    && (int)$c['score'] === 50 + (int)$c['points_tendance']);
check('créé : à confirmer, score de départ 2', (int)$c['a_confirmer'] === 1 && (int)$c['confirmation'] === 2);
check('créé : pas encore proposé dans son rayon', Rayons::produits($db, 'epicerie')['total'] === 0);
check('fiche complétée : pas à confirmer (le produit existait)', (int)produit($db, 4)['a_confirmer'] === 0);
check('créé : ses deux codes-barres, pas le code interne', (int)parCode($db, '6130007000022')['id'] === (int)$c['id']
    && parCode($db, 'CDZ1') === null);
check('créé : marque et catégorie', $db->query('SELECT nom FROM catalog_brand WHERE id = ' . (int)$c['brand_id'])->fetchColumn() === 'Mama'
    && $db->query('SELECT nom FROM catalog_category WHERE id = ' . (int)$c['category_id'])->fetchColumn() === 'Pâtes, Couscous');
$cola = produit($db, 2);
check('Cola complété : nom arabe et marque, nom et catégorie gardés', $cola['nom_ar'] === 'كولا' && $cola['brand_id'] !== null
    && $cola['nom'] === 'Cola 33cl' && (int)$cola['category_id'] === 1);
check('fiche complète : nom arabe existant gardé', produit($db, 3)['nom_ar'] === 'مشروب غازي');
$eau = produit($db, 4);
check('fiche vide complétée : nom, catégorie, rayon', $eau['nom'] === 'Eau minérale Ifri 1,5L' && $eau['rayon_code'] === 'boissons');
$jambon = parCode($db, '3000000000099');
check('porc : créé, exclu, sans nom arabe latin', $jambon !== null && (int)$jambon['exclu'] === 1 && $jambon['nom_ar'] === null);
check('code interne et fiche sans nom : ignorés', parCode($db, 'CDZ2610210001') === null && parCode($db, '3000000000098') === null);
$notes = $db->query('SELECT product_id, action, champs FROM catalog_contribution ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
check('contributions notées', array_column($notes, 'action') === ['cree', 'complete', 'complete', 'cree']);
check('champs notés', $notes[0]['champs'] === 'nom,nom_ar,marque,categorie,codes_barres' && $notes[1]['champs'] === 'nom_ar,marque'
    && $notes[2]['champs'] === 'nom,categorie' && $notes[3]['champs'] === 'nom');

// Une autre boutique ne remplace rien de ce qui est déjà renseigné.
$r = Tendance::enregistrer($db, 'CD-BBBB-BBBB', ['produits' => [
    ['code_barres' => '3000000000002', 'nom' => 'Pepsi', 'nom_ar' => 'بيبسي', 'marque' => 'Pepsi'],
    ['code_barres' => '6130007000015', 'nom' => 'Couscous', 'codes_barres' => ['6130007000039']],
]], $j);
check('rien remplacé, un code-barres ajouté', $r['crees'] === 0 && $r['completes'] === 1 && produit($db, 2)['nom_ar'] === 'كولا'
    && (int)parCode($db, '6130007000039')['id'] === (int)$c['id']);

// ---- Statistiques du tableau de bord -------------------------------------------------------------
$st = Tendance::stats($db, $j);
check('stats : seuils', $st['seuils'] === ['postes' => 3, 'tickets' => 20, 'jours' => 90, 'plafond' => 5000,
    'confirmation_depart' => 2, 'confirmation_seuil' => 5, 'confirmation_suppression' => 0]);
check('stats : 2 produits à confirmer', $st['totaux']['a_confirmer'] === 2 && $st['contributions'][4]['etat'] === 'a_confirmer'
    && $st['contributions'][4]['confirmation'] === 2);
check('stats : 3 boutiques, 1 en tendance, 2 créés, 3 complétés', $st['totaux']['postes'] === 3 && $st['totaux']['tendances'] === 1
    && $st['totaux']['crees'] === 2 && $st['totaux']['completes'] === 3);
check('stats : Cola en tête', $st['tendances'][0]['nom'] === 'Cola 33cl' && $st['tendances'][0]['tendance'] === true
    && $st['tendances'][0]['postes'] === 3);
$a = array_values(array_filter($st['postes'], function ($x) { return $x['poste'] === 'CD-AAAA-AAAA'; }))[0];
check('stats : boutique A (4 envois, 2 créés, 2 complétés)', $a['envois'] === 4 && $a['crees'] === 2 && $a['completes'] === 2
    && $a['tickets'] === 62);
check('stats : dernières contributions d\'abord', count($st['contributions']) === 5 && $st['contributions'][0]['poste'] === 'CD-BBBB-BBBB');
check('stats : rayons avec leurs tendances', array_values(array_filter($st['rayons'], function ($x) { return $x['code'] === 'boissons'; }))[0]['tendances'] === 1);

// ---- La fenêtre glisse ----------------------------------------------------------------------------
$plusTard = $j->modify('+89 days');
check('rafraîchir : 3 produits recalculés', Tendance::rafraichir($db, 300, $plusTard) === 3);
$p = produit($db, 2);
check('à J+89 : seul le jour J reste (3 boutiques, 7 tickets) → plus tendance',
    (int)$p['postes_90j'] === 3 && (int)$p['ventes_90j'] === 7 && (int)$p['tendance'] === 0 && $p['tendance_le'] === null);
check('déjà recalculé ce jour : rien à refaire', Tendance::rafraichir($db, 300, $plusTard) === 0);
Tendance::rafraichir($db, 300, $j->modify('+90 days'));
$p = produit($db, 2);
check('à J+90 : plus de vente', (int)$p['postes_90j'] === 0 && (int)$p['tendance'] === 0);
check('score revenu à ses consultations', (int)$p['score'] === 0 && (int)$p['points_tendance'] === 0);
Tendance::rafraichir($db, 300, $j->modify('+121 days'));
check('ventes de plus de 120 jours oubliées', (int)$db->query('SELECT COUNT(*) FROM catalog_vente_jour')->fetchColumn() === 0);

// ---- Limites --------------------------------------------------------------------------------------
try {
    Tendance::enregistrer($db, 'CD-AAAA-AAAA', ['ventes' => array_fill(0, Tendance::LIGNES_MAX + 1, ['jour' => '2026-10-21'])], $j);
    check('trop de lignes : 413', false);
} catch (Exception $e) {
    check('trop de lignes : 413', $e->getCode() === 413);
}
check('corps vide : rien', Tendance::enregistrer($db, 'CD-AAAA-AAAA', [], $j)
    === ['ventes' => 0, 'inconnus' => 0, 'crees' => 0, 'completes' => 0, 'votes' => 0, 'confirmes' => 0, 'supprimes' => 0]);
check('points : plafond de tickets', Tendance::points(3, 5000) === 3000 + Tendance::VENTES_MAX_COMPTEES * Tendance::POINTS_VENTE);

// ---- Confirmation par les autres boutiques ---------------------------------------------------------
$vote = function ($poste, $code, $v) use ($db, $j) {
    return Tendance::enregistrer($db, $poste, ['confirmations' => [['code_barres' => $code, 'vote' => $v]]], $j);
};
$couscous = '6130007000015';
check('la boutique qui l\'a créé ne vote pas', $vote('CD-AAAA-AAAA', $couscous, 1)['votes'] === 0
    && (int)parCode($db, $couscous)['confirmation'] === 2);
$r = Tendance::enregistrer($db, 'CD-BBBB-BBBB', ['confirmations' => [
    ['code_barres' => $couscous, 'vote' => 2],            // ni +1 ni −1
    ['code_barres' => 'CDZ123', 'vote' => 1],             // code interne
    ['code_barres' => '9999999999999', 'vote' => 1],      // inconnu
    ['code_barres' => '3000000000002', 'vote' => 1],      // Cola : jamais à confirmer
]], $j);
check('votes invalides ignorés', $r['votes'] === 0);
check('B : c\'est bien lui → 3', $vote('CD-BBBB-BBBB', $couscous, 1)['votes'] === 1 && (int)parCode($db, $couscous)['confirmation'] === 3);
check('B revote : remplacé, pas ajouté → 3', $vote('CD-BBBB-BBBB', '6130007000022', 1)['votes'] === 1
    && (int)parCode($db, $couscous)['confirmation'] === 3);
$vote('CD-CCCC-CCCC', $couscous, 1);
check('C → 4, toujours à confirmer', (int)parCode($db, $couscous)['confirmation'] === 4 && (int)parCode($db, $couscous)['a_confirmer'] === 1);
$r = $vote('CD-DDDD-DDDD', $couscous, 1);
$cc = parCode($db, $couscous);
check('D → 5 : confirmé', $r['confirmes'] === 1 && (int)$cc['a_confirmer'] === 0 && (int)$cc['confirmation'] === 5 && $cc['confirme_le'] !== null);
check('confirmé : proposé dans son rayon', Rayons::produits($db, 'epicerie')['products'][0]['nom'] === 'Couscous Mama 1kg');
check('confirmé : plus de vote', $vote('CD-EEEE-EEEE', $couscous, -1)['votes'] === 0 && (int)parCode($db, $couscous)['confirmation'] === 5);

// Jambon (à confirmer, 2) : deux « ce n'est pas ça » → 0 → supprimé, avec ses codes-barres et ses votes.
$jambon = '3000000000099';
$idJambon = (int)parCode($db, $jambon)['id'];
$vote('CD-BBBB-BBBB', $jambon, -1);
check('B : ce n\'est pas ça → 1', (int)parCode($db, $jambon)['confirmation'] === 1);
$r = $vote('CD-CCCC-CCCC', $jambon, -1);
check('C → 0 : supprimé du catalogue', $r['supprimes'] === 1 && parCode($db, $jambon) === null
    && (int)$db->query("SELECT COUNT(*) FROM catalog_confirmation WHERE product_id = $idJambon")->fetchColumn() === 0);
$dernier = $db->query("SELECT action, champs, poste FROM catalog_contribution ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check('suppression notée avec son nom', $dernier === ['action' => 'supprime', 'champs' => 'Jambon de porc', 'poste' => 'CD-CCCC-CCCC']);

// Un vote changé : +1 puis −1 de la même boutique compte −1.
Tendance::enregistrer($db, 'CD-BBBB-BBBB', ['produits' => [['code_barres' => '6130008000014', 'nom' => 'Harissa Maison']]], $j);
$vote('CD-CCCC-CCCC', '6130008000014', 1);
$vote('CD-CCCC-CCCC', '6130008000014', -1);
check('vote changé : 2 − 1 = 1', (int)parCode($db, '6130008000014')['confirmation'] === 1);

// Seuils du .env.
$_ENV['CONFIRMATION_SEUIL'] = '2';
$vote('CD-DDDD-DDDD', '6130008000014', 1);
check('seuil à 2 (.env) : confirmé à 2', (int)parCode($db, '6130008000014')['a_confirmer'] === 0);
$_ENV['CONFIRMATION_SEUIL'] = '';

$st = Tendance::stats($db, $j);
check('stats : 2 confirmés, 1 supprimé, plus rien à confirmer', $st['totaux']['confirmes'] === 2 && $st['totaux']['supprimes'] === 1
    && $st['totaux']['a_confirmer'] === 0);
$sup = array_values(array_filter($st['contributions'], function ($x) { return $x['action'] === 'supprime'; }))[0];
check('stats : la suppression avec le nom du produit', $sup['nom'] === 'Jambon de porc' && $sup['etat'] === 'supprime' && $sup['champs'] === []);

// ---- Authentification : licence BENS du poste (POST), jeton du tableau de bord (GET /tendance/stats) --
require __DIR__ . '/../routes.php';
$_ENV['REV_SERVER_TOKEN'] = '';
try {
    tendanceStats($db);
    check('stats sans jeton configuré : 503', false);
} catch (Exception $e) {
    check('stats sans jeton configuré : 503', $e->getCode() === 503);
}
$_ENV['REV_SERVER_TOKEN'] = 'jeton-partage';
$_SERVER['HTTP_X_SERVER_TOKEN'] = 'faux';
try {
    tendanceStats($db);
    check('stats mauvais jeton : 401', false);
} catch (Exception $e) {
    check('stats mauvais jeton : 401', $e->getCode() === 401);
}
$_SERVER['HTTP_X_SERVER_TOKEN'] = 'jeton-partage';
ob_start();
@tendanceStats($db);
$sortie = json_decode(ob_get_clean(), true);
check('stats avec le jeton : servies', $sortie['success'] === true && isset($sortie['data']['totaux'], $sortie['data']['postes']));
unset($_SERVER['HTTP_X_SERVER_TOKEN']);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer jeton-partage';
ob_start();
@tendanceStats($db);
check('stats avec Authorization: Bearer', json_decode(ob_get_clean(), true)['success'] === true);

if (function_exists('sodium_crypto_sign_seed_keypair')) {
    $pair = sodium_crypto_sign_seed_keypair(str_repeat("\x02", 32));
    $_ENV['LICENSE_PUBLIC_KEY'] = base64_encode(sodium_crypto_sign_publickey($pair));
    $_ENV['LICENSE_PRODUCT'] = '1';
    $jourN = intdiv(strtotime('2026-10-01 00:00:00 UTC') - strtotime('2020-01-01 00:00:00 UTC'), 86400);
    $charge = pack('CnNCnn', 1, 1, 7, 0, $jourN, 0) . LicenceBens::octetsPoste('PC-TENDANCE') . "\0"; // Basic, sans fin
    $cle = LicenceBens::encoder32($charge . sodium_crypto_sign_detached('BENS-LICENSE-1' . $charge, sodium_crypto_sign_secretkey($pair)));
    check('licence valable : code du poste', tendancePoste('PC-TENDANCE', $cle) === LicenceBens::codePoste('PC-TENDANCE'));
    foreach ([['autre-poste', $cle], ['PC-TENDANCE', 'ABCD'], ['', $cle], ['PC TENDANCE', $cle]] as [$poste, $k]) {
        try {
            tendancePoste($poste, $k);
            check("refusé : $poste", false);
        } catch (Exception $e) {
            check("refusé : $poste → 401", $e->getCode() === 401);
        }
    }
    $_ENV['LICENSE_PUBLIC_KEY'] = '';
    try {
        tendancePoste('PC-TENDANCE', $cle);
        check('sans clé publique : 503', false);
    } catch (Exception $e) {
        check('sans clé publique : 503', $e->getCode() === 503);
    }
} else {
    echo "(sodium absent : authentification du poste non testée, lancer avec php -d extension=sodium)\n";
}

echo "$ok réussis, $ko échecs\n";
exit($ko === 0 ? 0 : 1);
