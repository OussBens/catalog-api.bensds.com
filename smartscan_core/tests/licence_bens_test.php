<?php
// Lancer : php -d extension=sodium smartscan_core/tests/licence_bens_test.php (license-vectors.json : copie de bens_ds api/tests/fixtures).
// Vérifie LicenceBens (catalog-api) contre les vecteurs partagés de bens_ds (mêmes clés que le Dart).
class SmartScanException extends Exception {
    public $codeErreur;
    public function __construct($c, $code, $m) { parent::__construct($m); $this->codeErreur = $code; }
}
require __DIR__ . '/../LicenceBens.php';

$v = json_decode(file_get_contents(__DIR__ . '/license-vectors.json'), true);
$ok = 0; $ko = 0;
function check($label, $cond) { global $ok, $ko; if ($cond) { $ok++; } else { $ko++; echo "ÉCHEC : $label\n"; } }

$desk = array_values(array_filter($v['licences'], fn ($l) => $l['slug'] === 'caissedz-desktop'));
check('un vecteur caissedz-desktop', count($desk) === 1);
$l = $desk[0];
echo "vecteur : produit {$l['product']}, palier {$l['tier']}, poste {$l['rawDeviceId']} → {$l['deviceCode']}, fin " . json_encode($l['expiresOn']) . "\n";
check('code du poste', LicenceBens::codePoste($l['rawDeviceId']) === $l['deviceCode']);
$jour = new DateTimeImmutable('2026-10-20', new DateTimeZone('UTC'));
$attendu = LicenceBens::PALIERS[$l['tier']];
check('clé valable → palier', LicenceBens::palier($l['rawDeviceId'], $l['key'], $l['publicKey'], $l['product'], $jour) === $attendu);
check('clé sans tirets, en minuscules', LicenceBens::palier($l['rawDeviceId'], strtolower(str_replace('-', '', $l['key'])), $l['publicKey'], $l['product'], $jour) === $attendu);
check('autre poste', LicenceBens::palier('autre-poste', $l['key'], $l['publicKey'], $l['product'], $jour) === null);
check('autre produit', LicenceBens::palier($l['rawDeviceId'], $l['key'], $l['publicKey'], 7, $jour) === null);
check('autre clé publique', LicenceBens::palier($l['rawDeviceId'], $l['key'], $v['licences'][0]['publicKey'], $l['product'], $jour) === null);
$k = $l['key']; $k[5] = $k[5] === 'A' ? 'B' : 'A';
check('clé modifiée', LicenceBens::palier($l['rawDeviceId'], $k, $l['publicKey'], $l['product'], $jour) === null);
check('ancienne clé SHA-256', LicenceBens::palier($l['rawDeviceId'], str_repeat('f', 64), $l['publicKey'], $l['product'], $jour) === null);
if ($l['expiresOn'] !== null) {
    $apres = (new DateTimeImmutable(substr($l['expiresOn'], 0, 10), new DateTimeZone('UTC')))->modify('+1 day');
    check('expirée le lendemain', LicenceBens::palier($l['rawDeviceId'], $l['key'], $l['publicKey'], $l['product'], $apres) === null);
}

// Une clé signée ici pour un palier Ultra avec une fin : expirée ou non selon le jour.
$seed = str_repeat("\x01", 32);
$pair = sodium_crypto_sign_seed_keypair($seed);
$pk = base64_encode(sodium_crypto_sign_publickey($pair));
$jourN = fn ($d) => intdiv(strtotime("$d 00:00:00 UTC") - strtotime('2020-01-01 00:00:00 UTC'), 86400);
$charge = pack('CnNCnn', 1, 1, 9, 2, $jourN('2026-10-01'), $jourN('2026-11-02')) . LicenceBens::octetsPoste('PC-1') . "\0";
$cle = LicenceBens::encoder32($charge . sodium_crypto_sign_detached('BENS-LICENSE-1' . $charge, sodium_crypto_sign_secretkey($pair)));
check('Ultra avant sa fin', LicenceBens::palier('PC-1', $cle, $pk, 1, new DateTimeImmutable('2026-11-02', new DateTimeZone('UTC'))) === 'ultra');
check('Ultra après sa fin', LicenceBens::palier('PC-1', $cle, $pk, 1, new DateTimeImmutable('2026-11-03', new DateTimeZone('UTC'))) === null);
check('décodage aller-retour', LicenceBens::decoder32(LicenceBens::encoder32('abcde')) === 'abcde');

echo "$ok réussis, $ko échoués\n";
exit($ko === 0 ? 0 : 1);
