<?php
/**
 * Administration des rayons du catalogue — à côté de index.php, même mot de passe que admin_smartscan.php
 * (ADMIN_PASSWORD du .env, session + jeton CSRF).
 *
 * Rayons proposés à l'accueil de CaisseDZ Desktop : noms FR/AR/EN, icône, ordre, mots-clés de tri ;
 * aperçu des produits proposés ; reclassement du catalogue par lots (voir catalogue_core/Rayons.php).
 */
ini_set('display_errors', 0);
ini_set('log_errors', 1);
@set_time_limit(120);

foreach (@file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $ligne) {
    if (strpos(trim($ligne), '#') === 0 || strpos($ligne, '=') === false) continue;
    list($k, $v) = explode('=', $ligne, 2);
    $_ENV[trim($k)] = trim($v, " \t\"'");
}
require_once __DIR__ . '/smartscan_core/routes.php';
require_once __DIR__ . '/catalogue_core/routes.php';

session_set_cookie_params(['httponly' => true, 'secure' => smartscanEstHttps(), 'samesite' => 'Strict']);
session_start();
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: DENY');

function h($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function csrf()
{
    return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
}

$motDePasse = $_ENV['ADMIN_PASSWORD'] ?? '';
if ($motDePasse === '') {
    http_response_code(503);
    exit('Administration désactivée : définissez ADMIN_PASSWORD dans le fichier .env.');
}
if (smartscanEnv('SMARTSCAN_HTTPS_OBLIGATOIRE', '1') !== '0' && !smartscanEstHttps()) {
    http_response_code(403);
    exit('Connexion sécurisée (HTTPS) obligatoire.');
}
if (isset($_GET['deconnexion'])) {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}
if (empty($_SESSION['admin'])) {
    $erreur = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mot_de_passe'])) {
        if (hash_equals($motDePasse, (string)$_POST['mot_de_passe'])) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }
        sleep(2);
        $erreur = 'Mot de passe incorrect.';
        error_log('[rayons-admin] échec de connexion depuis ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    }
    ?><!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Rayons — Administration</title>
    <style>body{font-family:Segoe UI,Arial;background:#F6F4FD;display:flex;justify-content:center;align-items:center;height:100vh;margin:0}
    form{background:#fff;padding:32px;border-radius:16px;box-shadow:0 4px 20px rgba(117,93,179,.15);width:320px}
    h1{color:#755DB3;font-size:20px;margin:0 0 20px}input{width:100%;padding:10px;border:1px solid #CFCFCF;border-radius:10px;box-sizing:border-box}
    button{margin-top:14px;width:100%;padding:10px;background:#755DB3;color:#fff;border:0;border-radius:10px;cursor:pointer}.err{color:#D24728;margin-top:10px}</style></head>
    <body><form method="post"><h1>Rayons — Administration</h1>
    <input type="password" name="mot_de_passe" placeholder="Mot de passe" autofocus required>
    <button>Se connecter</button><?php if ($erreur) echo '<div class="err">' . h($erreur) . '</div>'; ?></form></body></html><?php
    exit;
}

try {
    $db = new PDO(
        $_ENV['DB_DSN'] ?? ('mysql:host=' . ($_ENV['DB_HOST'] ?? 'localhost') . ';dbname=' . ($_ENV['DB_NAME'] ?? '') . ';charset=utf8mb4'),
        $_ENV['DB_USER'] ?? '',
        $_ENV['DB_PASSWORD'] ?? ''
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    error_log('[rayons-admin] ' . $e->getMessage());
    exit('Connexion à la base impossible (voir .env).');
}
try {
    $db->query('SELECT rayon_code, exclu, consultations, score, classe_le FROM catalog_product LIMIT 1');
    $db->query('SELECT 1 FROM catalog_rayon LIMIT 1');
} catch (Exception $e) {
    RayonsSchema::installer($db);
}

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);
$page = $_GET['page'] ?? 'rayons';

// ── Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Jeton CSRF invalide.');
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'enregistrer') {
        $db->prepare('UPDATE catalog_rayon SET nom_fr = ?, nom_ar = ?, nom_en = ?, icone = ?, ordre = ?, prioritaire = ?,
                      secours = ?, actif = ?, mots_cles = ? WHERE code = ?')
           ->execute([
               trim($_POST['nom_fr']), trim($_POST['nom_ar']), trim($_POST['nom_en']), trim($_POST['icone']) ?: 'package',
               (int)$_POST['ordre'], isset($_POST['prioritaire']) ? 1 : 0, isset($_POST['secours']) ? 1 : 0,
               isset($_POST['actif']) ? 1 : 0, trim($_POST['mots_cles']), $_POST['code'],
           ]);
        $_SESSION['message'] = 'Rayon enregistré. Reclassez le catalogue pour appliquer de nouveaux mots-clés.';
    } elseif ($action === 'ajouter') {
        $code = preg_replace('/[^a-z0-9_]/', '', strtolower((string)$_POST['code']));
        if ($code !== '') {
            $db->prepare("INSERT INTO catalog_rayon (code, nom_fr, nom_ar, nom_en, icone, ordre, mots_cles)
                          VALUES (?, ?, ?, ?, 'package', 999, '')")->execute([$code, $code, $code, $code]);
            $_SESSION['message'] = "Rayon « $code » ajouté : complétez ses noms et mots-clés.";
        }
    } elseif ($action === 'installer') {
        RayonsSchema::installer($db);
        $_SESSION['message'] = 'Tables des rayons installées / mises à jour.';
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?page=' . urlencode($page)
        . (isset($_POST['code']) ? '&code=' . urlencode($_POST['code']) : ''));
    exit;
}

// ── Reclassement par lots (GET ?page=reclasser&depuis=…&csrf=…) : la page se recharge jusqu'à la fin.
$reclassement = null;
if ($page === 'reclasser' && hash_equals($_SESSION['csrf'] ?? '', (string)($_GET['csrf'] ?? ''))) {
    $depuis = (int)($_GET['depuis'] ?? 0);
    $rayons = Rayons::preparer($db);
    $fin = time() + 20;
    while (time() < $fin && ($dernier = Rayons::reclasser($db, $depuis, 5000, $rayons)) !== null) {
        $depuis = $dernier;
    }
    $reste = (int)$db->query("SELECT COUNT(*) FROM catalog_product WHERE id > $depuis")->fetchColumn();
    $reclassement = ['depuis' => $depuis, 'reste' => $reste];
}

$stats = $db->query('SELECT COUNT(*) AS total, SUM(exclu) AS exclus,
                            SUM(CASE WHEN rayon_code IS NULL AND exclu = 0 THEN 1 ELSE 0 END) AS sans_rayon,
                            SUM(CASE WHEN classe_le IS NULL THEN 1 ELSE 0 END) AS non_classes
                     FROM catalog_product')->fetch(PDO::FETCH_ASSOC);
$nb = [];
foreach ($db->query('SELECT rayon_code, COUNT(*) AS n FROM catalog_product WHERE exclu = 0 AND rayon_code IS NOT NULL
                     GROUP BY rayon_code')->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $nb[$r['rayon_code']] = (int)$r['n'];
}
$rayons = $db->query('SELECT * FROM catalog_rayon ORDER BY ordre, code')->fetchAll(PDO::FETCH_ASSOC);
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Rayons — Administration</title>
<?php if ($reclassement && $reclassement['reste'] > 0): ?>
<meta http-equiv="refresh" content="1;url=?page=reclasser&depuis=<?= $reclassement['depuis'] ?>&csrf=<?= h($_SESSION['csrf']) ?>">
<?php endif; ?>
<style>
body{font-family:Segoe UI,Arial;background:#F6F4FD;margin:0;color:#262626}
header{background:#755DB3;color:#fff;padding:12px 24px;display:flex;gap:18px;align-items:center}header a{color:#fff;text-decoration:none;opacity:.85}
header a.on{opacity:1;font-weight:700}main{padding:24px;max-width:1300px;margin:auto}
.carte{background:#fff;border-radius:14px;padding:18px;box-shadow:0 2px 10px rgba(117,93,179,.08);margin-bottom:18px}
table{width:100%;border-collapse:collapse;font-size:13px}th,td{text-align:left;padding:7px;border-bottom:1px solid #eee;vertical-align:top}
th{color:#858585;font-weight:600}input,textarea{border:1px solid #CFCFCF;border-radius:8px;padding:6px;font:inherit;box-sizing:border-box}
textarea{width:100%;height:220px}button,.bouton{background:#755DB3;color:#fff;border:0;border-radius:8px;padding:8px 14px;cursor:pointer;text-decoration:none;display:inline-block}
.sec{background:#fff;color:#755DB3;border:1px solid #755DB3}.msg{background:#EAF7EF;border-radius:10px;padding:10px 14px;margin-bottom:14px}
.chiffres{display:flex;gap:28px}.chiffre{font-size:24px;font-weight:700;color:#755DB3}small{color:#858585}img{width:40px;height:40px;object-fit:cover;border-radius:6px}
</style></head><body>
<header><b>Catalogue</b>
<a class="<?= $page === 'rayons' ? 'on' : '' ?>" href="?page=rayons">Rayons</a>
<a href="admin_smartscan.php">Smart Scan</a>
<span style="flex:1"></span><a href="?deconnexion=1">Déconnexion</a></header>
<main>
<?php if ($message): ?><div class="msg"><?= h($message) ?></div><?php endif; ?>

<?php if ($reclassement): ?>
<div class="carte">
  <?php if ($reclassement['reste'] > 0): ?>
    <b>Reclassement en cours…</b> dernier id <?= $reclassement['depuis'] ?>, encore <?= number_format($reclassement['reste'], 0, ',', ' ') ?> produits.
    Laissez cette page ouverte.
  <?php else: ?>
    <b>Reclassement terminé.</b> <a href="?page=rayons">Retour aux rayons</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($page === 'rayon' && isset($_GET['code'])):
    $r = array_values(array_filter($rayons, function ($x) { return $x['code'] === $_GET['code']; }))[0] ?? null;
    if (!$r) exit('Rayon inconnu.');
    $apercu = Rayons::produits($db, $r['code'], 1, 50); ?>
<div class="carte">
  <h3 style="margin-top:0"><?= h($r['nom_fr']) ?> <small><?= h($r['code']) ?> · <?= number_format($nb[$r['code']] ?? 0, 0, ',', ' ') ?> produits, <?= $apercu['total'] ?> proposés</small></h3>
  <form method="post"><?= csrf() ?><input type="hidden" name="action" value="enregistrer"><input type="hidden" name="code" value="<?= h($r['code']) ?>">
    <table><tr><th>Nom FR</th><th>Nom AR</th><th>Nom EN</th><th>Icône (Lucide)</th><th>Ordre</th><th></th></tr>
    <tr><td><input name="nom_fr" value="<?= h($r['nom_fr']) ?>"></td><td><input name="nom_ar" dir="rtl" value="<?= h($r['nom_ar']) ?>"></td>
      <td><input name="nom_en" value="<?= h($r['nom_en']) ?>"></td><td><input name="icone" value="<?= h($r['icone']) ?>"></td>
      <td><input name="ordre" type="number" style="width:70px" value="<?= (int)$r['ordre'] ?>"></td>
      <td><label><input type="checkbox" name="actif" <?= $r['actif'] ? 'checked' : '' ?>> actif</label>
        <label><input type="checkbox" name="prioritaire" <?= $r['prioritaire'] ? 'checked' : '' ?>> prioritaire</label>
        <label><input type="checkbox" name="secours" <?= $r['secours'] ? 'checked' : '' ?>> secours</label></td></tr></table>
    <p><b>Mots-clés</b> <small>un par ligne ; mot entier, sauf « * » final (début de mot) ; un mot arabe est cherché partout.
      Prioritaire : l'emporte dès qu'un mot apparaît. Secours : seulement si aucun autre rayon ne correspond.</small></p>
    <textarea name="mots_cles"><?= h($r['mots_cles']) ?></textarea>
    <p><button>Enregistrer</button> <a class="bouton sec" href="?page=rayons">Retour</a></p>
  </form>
</div>
<div class="carte"><h3 style="margin-top:0">Les 50 premiers proposés</h3>
  <table><tr><th></th><th>Produit</th><th>Marque</th><th>Code-barres</th></tr>
  <?php foreach ($apercu['products'] as $p): ?>
    <tr><td><?php if ($p['photo']): ?><img src="<?= h($p['photo']) ?>" loading="lazy" alt=""><?php endif; ?></td>
      <td><?= h($p['nom']) ?></td><td><?= h($p['marque']) ?></td><td><?= h($p['code_barres']) ?></td></tr>
  <?php endforeach; ?></table></div>

<?php else: ?>
<div class="carte">
  <div class="chiffres">
    <div><div class="chiffre"><?= number_format((int)$stats['total'], 0, ',', ' ') ?></div><small>produits</small></div>
    <div><div class="chiffre"><?= number_format((int)$stats['exclus'], 0, ',', ' ') ?></div><small>exclus (porc, alcool)</small></div>
    <div><div class="chiffre"><?= number_format((int)$stats['sans_rayon'], 0, ',', ' ') ?></div><small>sans rayon</small></div>
    <div><div class="chiffre"><?= number_format((int)$stats['non_classes'], 0, ',', ' ') ?></div><small>pas encore classés</small></div>
  </div>
  <p>
    <a class="bouton" href="?page=reclasser&depuis=0&csrf=<?= h($_SESSION['csrf']) ?>">Reclasser tout le catalogue</a>
    <small>(par lots de 20 s ; en ligne de commande : <code>php catalogue_core/reclasser.php</code>)</small>
  </p>
</div>
<div class="carte">
  <form method="post" style="float:right"><?= csrf() ?><input type="hidden" name="action" value="ajouter">
    <input name="code" placeholder="code (ex. fruits_legumes)" required> <button class="sec">Ajouter un rayon</button></form>
  <h3 style="margin-top:0">Rayons (<?= count($rayons) ?>)</h3>
  <table><tr><th>Ordre</th><th>Rayon</th><th>Arabe</th><th>Anglais</th><th>Icône</th><th>Produits</th><th>Proposés</th><th>Type</th><th>Actif</th></tr>
  <?php foreach ($rayons as $r): $n = $nb[$r['code']] ?? 0; ?>
    <tr><td><?= (int)$r['ordre'] ?></td>
      <td><a href="?page=rayon&code=<?= urlencode($r['code']) ?>"><?= h($r['nom_fr']) ?></a><br><small><?= h($r['code']) ?></small></td>
      <td dir="rtl"><?= h($r['nom_ar']) ?></td><td><?= h($r['nom_en']) ?></td><td><?= h($r['icone']) ?></td>
      <td><?= number_format($n, 0, ',', ' ') ?></td><td><?= min($n, Rayons::PLAFOND) ?></td>
      <td><?= $r['prioritaire'] ? 'prioritaire' : ($r['secours'] ? 'secours' : '') ?></td><td><?= $r['actif'] ? 'oui' : 'non' ?></td></tr>
  <?php endforeach; ?></table>
  <form method="post" style="margin-top:12px"><?= csrf() ?><input type="hidden" name="action" value="installer"><button class="sec">Installer / mettre à jour les tables</button></form>
</div>
<?php endif; ?>
</main></body></html>
