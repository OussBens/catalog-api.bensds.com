<?php
// Lancer : php -d extension=sodium smartscan_core/tests/smartscan_desktop_test.php
// CaisseDZ Desktop : licence BENS signée, scans comptés par bens_ds (simulé ici), OCR simulé.
require __DIR__ . '/../SmartScanService.php';
require __DIR__ . '/../SmartScanSchema.php';
require __DIR__ . '/../FirebaseToken.php';
require __DIR__ . '/../LicenceBens.php';
require __DIR__ . '/../RevBens.php';

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
    } catch (SmartScanException $e) {
        return $e;
    }
    return null;
}

// Une licence Avancé signée pour le poste PC-1 (graine de test, jamais celle du produit).
$pair = sodium_crypto_sign_seed_keypair(str_repeat("\x01", 32));
$pk = base64_encode(sodium_crypto_sign_publickey($pair));
$jour = fn ($d) => intdiv(strtotime("$d 00:00:00 UTC") - strtotime('2020-01-01 00:00:00 UTC'), 86400);
$cle = function ($poste, $palier = 1, $produit = 1) use ($pair, $jour) {
    $charge = pack('CnNCnn', 1, $produit, 1, $palier, $jour('2026-10-01'), 0) . LicenceBens::octetsPoste($poste) . "\0";
    return LicenceBens::encoder32($charge . sodium_crypto_sign_detached('BENS-LICENSE-1' . $charge, sodium_crypto_sign_secretkey($pair)));
};

// bens_ds simulé : un lot de 2 scans pour le poste PC-1 (Desktop), 1 pour le numéro +213770000020 (Mobile).
$rev = ['reste' => 2, 'mobile' => 1, 'reservations' => [], 'appels' => []];
$transportRev = function ($methode, $url, $corps, array $entetes) use (&$rev) {
    $rev['appels'][] = "$methode " . parse_url($url, PHP_URL_PATH);
    if (!in_array('Authorization: Bearer srv', $entetes, true) || !in_array('X-Requested-With: bens', $entetes, true)) {
        return [401, '{"error":"unauthorized"}'];
    }
    // Le compteur du compte : le poste PC-1 de Desktop, le numéro de Mobile ; null : inconnu.
    $compte = function (array $q) {
        if (($q['product'] ?? '') === 'caissedz-desktop' && ($q['device'] ?? '') === LicenceBens::codePoste('PC-1')) return 'reste';
        if (($q['product'] ?? '') === 'caissedz-mobile' && ($q['phone'] ?? '') === '+213770000020') return 'mobile';
        return null;
    };
    $solde = function ($k) use (&$rev) {
        $reserves = count(array_filter($rev['reservations'], fn ($r) => $r === ['reserved', $k]));
        $total = $k === 'reste' ? 2 : 1;
        return ['account' => '+213555112233', 'remaining' => $rev[$k] - $reserves, 'total' => $total, 'status' => 'active',
            'buckets' => [['kind' => 'included', 'scans' => $total, 'remaining' => $rev[$k] - $reserves, 'endsOn' => '2027-01-19']], 'expiringSoon' => []];
    };
    if ($methode === 'GET') {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        $k = $compte($q);
        return [200, json_encode($k !== null ? $solde($k) : ['account' => null, 'remaining' => 0, 'total' => 0, 'status' => 'none', 'buckets' => []])];
    }
    if (substr($url, -8) === '/reserve') {
        $k = $compte($corps);
        if ($k === null || $solde($k)['remaining'] <= 0) {
            return [409, '{"error":"no_scans"}'];
        }
        $id = 'r' . count($rev['reservations']);
        $rev['reservations'][$id] = ['reserved', $k];
        return [201, json_encode(['reservation' => $id] + $solde($k))];
    }
    preg_match('#/rev/scans/([^/]+)/(commit|release)$#', $url, $m);
    $k = $rev['reservations'][$m[1]][1];
    $rev['reservations'][$m[1]] = [$m[2] === 'commit' ? 'used' : 'released', $k];
    if ($m[2] === 'commit') {
        $rev[$k]--;
    }
    return [200, json_encode($solde($k))];
};

// Firebase simulé : le jeton « ok » est le compte u1, numéro +213770000020.
class FirebaseTest extends FirebaseToken
{
    public function __construct()
    {
    }

    public function verifier($jeton)
    {
        if ($jeton !== 'ok') throw new SmartScanException(401, 'AUTH_INVALID', 'Jeton refusé.');
        return ['uid' => 'u1', 'telephone' => '+213770000020'];
    }
}

// OCR simulé : du texte, sauf quand $ocrVide.
$ocrVide = false;
$transportOcr = function ($chemin, array $payload) use (&$ocrVide) {
    return [200, json_encode(['pages' => [['markdown' => $ocrVide ? '' : "LAIT 1L x12 95,00"]]])];
};

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
SmartScanSchema::installer($db);
$s = new SmartScanService($db, [
    'cle_publique' => $pk,
    'produit_licence' => 1,
    'rev' => new RevBens('https://bens.test/api', 'srv', $transportRev),
    'firebase' => new FirebaseTest(),
    'rate_par_minute' => 100,
], $transportOcr);
// Une image PNG de 16 × 16.
$image = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAAFklEQVR4nGNkYPhPCmJiYmBgYPhPOwAA/9MCJPxHIaMAAAAASUVORK5CYII=';

// Licence : signée pour ce poste, sinon refusée.
check('clé d’un autre poste refusée', erreur(fn () => $s->authentifier('PC-2', $cle('PC-1')))?->codeErreur === 'LICENSE_INVALID');
check('clé d’un autre produit refusée', erreur(fn () => $s->authentifier('PC-1', $cle('PC-1', 1, 2)))?->codeErreur === 'LICENSE_INVALID');
check('ancienne clé SHA-256 refusée', erreur(fn () => $s->authentifier('PC-1', str_repeat('A', 64)))?->codeErreur === 'LICENSE_INVALID');
$poste = $s->authentifier('PC-1', $cle('PC-1'), 'Supérette Haddad');
check('poste enregistré, palier avancé', $poste['palier_licence'] === 'avance');

// Quota : celui de bens_ds.
$q = $s->quota((int)$poste['client_id'], 'PC-1');
check('quota de bens_ds', [$q['limit'], $q['remaining'], $q['status'], $q['period']] === [2, 2, 'active', 'scans']);

// Un scan lu : réservé puis confirmé (compté).
$r = $s->scanner($poste, $image);
check('scan réussi', $r['success'] === true && $r['text'] !== '');
check('confirmé chez bens_ds', $rev['reservations']['r0'] === ['used', 'reste'] && $r['quota']['remaining'] === 1);

// Un scan sans texte : libéré (non compté).
$ocrVide = true;
$db->exec("UPDATE ss_scans SET empreinte_image = 'x'"); // pas « doublon » pour la même image
$e = erreur(fn () => $s->scanner($poste, $image));
check('OCR vide → erreur OCR_EMPTY', $e?->codeErreur === 'OCR_EMPTY');
check('libéré chez bens_ds', $rev['reservations']['r1'] === ['released', 'reste'] && $s->quota((int)$poste['client_id'], 'PC-1')['remaining'] === 1);

// Le dernier scan, puis plus rien : SCAN_QUOTA_EXCEEDED avec le quota.
$ocrVide = false;
$db->exec("UPDATE ss_scans SET empreinte_image = 'x'"); // pas « doublon » pour la même image
$s->scanner($poste, $image);
$db->exec("UPDATE ss_scans SET empreinte_image = 'x'");
$e = erreur(fn () => $s->scanner($poste, $image));
check('épuisé → 429 SCAN_QUOTA_EXCEEDED', $e?->httpCode === 429 && $e->codeErreur === 'SCAN_QUOTA_EXCEEDED' && $e->donnees['quota']['remaining'] === 0);

// Le même bon renvoyé tout de suite : « doublon », et la réservation est rendue.
$rev['reste'] = 5;
$s->scanner($poste, $image);
$avant = count($rev['reservations']);
$e = erreur(fn () => $s->scanner($poste, $image));
check('doublon → 409, réservation libérée', $e?->codeErreur === 'DUPLICATE_SCAN' && end($rev['reservations']) === ['released', 'reste'] && count($rev['reservations']) === $avant + 1);

// CaisseDZ Mobile : les scans du compte de son numéro, chez bens_ds aussi.
$mobile = $s->authentifierMobile('ok', 'Épicerie Amine');
$q = $s->quota((int)$mobile['client_id']);
check('mobile : quota de bens_ds par numéro', [$q['platform'], $q['limit'], $q['remaining'], $q['period']] === ['mobile', 1, 1, 'scans']);
$db->exec("UPDATE ss_scans SET empreinte_image = 'x'");
$r = $s->scanner($mobile, $image);
check('mobile : scan confirmé au compte du numéro', $r['success'] === true && end($rev['reservations']) === ['used', 'mobile'] && $r['quota']['remaining'] === 0);
$db->exec("UPDATE ss_scans SET empreinte_image = 'x'");
$e = erreur(fn () => $s->scanner($mobile, $image));
check('mobile : épuisé → 429', $e?->codeErreur === 'SCAN_QUOTA_EXCEEDED' && $e->donnees['quota']['platform'] === 'mobile');
check('le poste garde ses scans', $s->quota((int)$poste['client_id'], 'PC-1')['remaining'] === $rev['reste']);

// bens_ds non configuré : 503, jamais un scan gratuit.
$sans = new SmartScanService($db, ['cle_publique' => $pk, 'rev' => new RevBens('', ''), 'rate_par_minute' => 100], $transportOcr);
check('bens_ds non configuré → 503', erreur(fn () => $sans->quota((int)$poste['client_id'], 'PC-1'))?->codeErreur === 'SERVER_NOT_CONFIGURED');

echo "$ok réussis, $ko échoués\n";
exit($ko === 0 ? 0 : 1);
