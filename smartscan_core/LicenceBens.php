<?php
/**
 * Licences BENS signées de CaisseDZ Desktop (bens_ds packages/bens_license/FORMAT.md) : la clé
 * collée ou reçue par le compte BENS, signée Ed25519 par le tableau de bord, liée au code du poste.
 * Vérifiée ici comme l'application la vérifie : signature (clé publique du produit), produit,
 * poste (dérivé de X-Device-Id), date de fin. Aucun secret : seule la clé publique est connue.
 */
class LicenceBens
{
    /** Base32 de Crockford : ni I, ni L, ni O, ni U. */
    const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** Signé avant la charge utile : aucune autre signature ne passe pour une licence. */
    const DOMAINE = 'BENS-LICENSE-1';

    const SLUG = 'caissedz-desktop';
    const PREFIXE = 'CD';

    /** Paliers signés (ceux du produit dans le tableau de bord, dans cet ordre). */
    const PALIERS = [0 => 'basic', 1 => 'avance', 2 => 'ultra'];

    /** Majuscules, lettres et chiffres seulement, O → 0 et I / L → 1 (les fautes de frappe courantes). */
    public static function nettoyer($texte)
    {
        return str_replace(['O', 'I', 'L'], ['0', '1', '1'], preg_replace('/[^0-9A-Z]/', '', strtoupper((string)$texte)));
    }

    public static function encoder32($octets)
    {
        $sortie = '';
        $tampon = 0;
        $bits = 0;
        foreach (str_split($octets) as $c) {
            $tampon = ($tampon << 8) | ord($c);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $sortie .= self::ALPHABET[($tampon >> $bits) & 31];
            }
            $tampon &= (1 << $bits) - 1;
        }
        if ($bits > 0) {
            $sortie .= self::ALPHABET[($tampon << (5 - $bits)) & 31];
        }
        return $sortie;
    }

    /** Les octets de [texte] (déjà nettoyé), ou null : caractère hors alphabet ou bits de bourrage non nuls. */
    public static function decoder32($texte)
    {
        $sortie = '';
        $tampon = 0;
        $bits = 0;
        foreach (str_split($texte) as $c) {
            $v = strpos(self::ALPHABET, $c);
            if ($c === '' || $v === false) {
                return null;
            }
            $tampon = ($tampon << 5) | $v;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $sortie .= chr(($tampon >> $bits) & 0xFF);
            }
            $tampon &= (1 << $bits) - 1;
        }
        return ($tampon !== 0 || $bits >= 5) ? null : $sortie;
    }

    /** Les 5 octets du code du poste : SHA-256("caissedz-desktop:device:<identifiant brut>"). */
    public static function octetsPoste($deviceId)
    {
        return substr(hash('sha256', self::SLUG . ':device:' . $deviceId, true), 0, 5);
    }

    /** Le code du poste tel que l'application l'affiche (`CD-XXXX-XXXX`). */
    public static function codePoste($deviceId)
    {
        return self::PREFIXE . '-' . implode('-', str_split(self::encoder32(self::octetsPoste($deviceId)), 4));
    }

    /**
     * Le palier ('basic', 'avance', 'ultra') d'une licence valable pour ce poste aujourd'hui, ou null.
     * [clePublique] : base64 (Licence products → CaisseDZ Desktop) ; [produit] : son numéro.
     */
    public static function palier($deviceId, $cle, $clePublique, $produit = 1, $aujourdhui = null)
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new SmartScanException(503, 'SERVER_NOT_CONFIGURED', 'Extension PHP sodium absente.');
        }
        $octets = self::decoder32(self::nettoyer($cle));
        if ($octets === null || strlen($octets) !== 82) {
            return null;
        }
        $charge = substr($octets, 0, 18);
        $pk = base64_decode((string)$clePublique, true);
        if ($pk === false || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return null;
        }
        if (!sodium_crypto_sign_verify_detached(substr($octets, 18), self::DOMAINE . $charge, $pk)) {
            return null;
        }
        $c = unpack('Cversion/nproduit/Nserie/Cpalier/njour/nfin', substr($charge, 0, 12));
        if ($c['version'] !== 1 || ord($charge[17]) !== 0 || $c['produit'] !== (int)$produit) {
            return null;
        }
        if (!hash_equals(self::octetsPoste($deviceId), substr($charge, 12, 5))) {
            return null;
        }
        if ($c['fin'] !== 0) {
            $fin = (new DateTimeImmutable('2020-01-01', new DateTimeZone('UTC')))->modify('+' . $c['fin'] . ' days');
            $jour = $aujourdhui ?: new DateTimeImmutable('today', new DateTimeZone('UTC'));
            if ($jour > $fin) {
                return null;
            }
        }
        return self::PALIERS[$c['palier']] ?? null;
    }
}
