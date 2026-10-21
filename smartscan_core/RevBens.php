<?php
/**
 * Les Smart Scans de CaisseDZ (Desktop et Mobile), comptés par bens_ds (bensdsRev, docs/rev.md →
 * Smart Scan by scans) : les scans inclus de la formule, ceux de l'essai, les packs, chacun avec sa
 * date de fin, dépensés dans l'ordre (inclus d'abord, puis le pack qui expire le plus tôt). Le
 * serveur Smart Scan réserve un scan avant l'OCR, puis le confirme (texte lu) ou le libère (échec :
 * non compté). Le compte : le poste pour Desktop (son code CD-…), le numéro pour Mobile.
 *
 *   GET  {REV_API_URL}/rev/scans?product=caissedz-desktop&device=CD-…   solde
 *   GET  {REV_API_URL}/rev/scans?product=caissedz-mobile&phone=+213…
 *   POST {REV_API_URL}/rev/scans/reserve {product, device | phone, ref} 409 no_scans : plus rien
 *   POST {REV_API_URL}/rev/scans/{id}/commit | /release
 *
 * Authentification : Authorization: Bearer REV_SERVER_TOKEN (le même dans le .env de bens_ds).
 */
class RevBens
{
    /** @var string */
    private $url;
    /** @var string */
    private $jeton;
    /** @var callable fn(string $methode, string $url, ?array $corps, array $entetes): array{0:int,1:string} */
    private $transport;

    public function __construct($url, $jeton, ?callable $transport = null)
    {
        $this->url = rtrim((string)$url, '/');
        $this->jeton = (string)$jeton;
        $this->transport = $transport ?: [self::class, 'curl'];
    }

    /** Le compte d'un poste CaisseDZ Desktop (son code CD-…). */
    public static function poste($codePoste)
    {
        return ['product' => 'caissedz-desktop', 'device' => (string)$codePoste];
    }

    /** Le compte CaisseDZ Mobile d'un numéro (E.164, celui de la connexion Firebase). */
    public static function telephone($telephone)
    {
        return ['product' => 'caissedz-mobile', 'phone' => (string)$telephone];
    }

    public function configure()
    {
        return $this->url !== '' && $this->jeton !== '';
    }

    public static function curl($methode, $url, $corps, array $entetes)
    {
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => $entetes,
            CURLOPT_CUSTOMREQUEST => $methode,
        ];
        if ($corps !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($corps, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $options);
        $reponse = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $reponse === false ? [0, ''] : [$code, $reponse];
    }

    /** @return array{0:int,1:array} */
    private function appel($methode, $chemin, ?array $corps = null)
    {
        if (!$this->configure()) {
            throw new SmartScanException(503, 'SERVER_NOT_CONFIGURED', 'Smart Scan non configuré (REV_API_URL, REV_SERVER_TOKEN).');
        }
        $entetes = [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Requested-With: bens',
            'Authorization: Bearer ' . $this->jeton,
        ];
        list($code, $texte) = call_user_func($this->transport, $methode, $this->url . $chemin, $corps, $entetes);
        if ($code === 0 || $code >= 500) {
            error_log('[smartscan] bensdsRev ' . $methode . ' ' . $chemin . ' : HTTP ' . $code);
            throw new SmartScanException(503, 'SERVICE_UNAVAILABLE', 'Service Smart Scan momentanément indisponible.');
        }
        $json = json_decode((string)$texte, true);
        return [$code, is_array($json) ? $json : []];
    }

    /** Le solde du compte (poste() ou telephone()) : {account, remaining, total, status, buckets, expiringSoon}. */
    public function solde(array $compte)
    {
        list($code, $j) = $this->appel('GET', '/rev/scans?' . http_build_query($compte));
        if ($code !== 200) {
            error_log('[smartscan] bensdsRev solde : HTTP ' . $code . ' ' . ($j['error'] ?? ''));
            throw new SmartScanException(503, 'SERVICE_UNAVAILABLE', 'Service Smart Scan momentanément indisponible.');
        }
        return $j;
    }

    /** Réserve un scan ; null quand il n'en reste aucun (`409 no_scans`). */
    public function reserver(array $compte, $ref)
    {
        list($code, $j) = $this->appel('POST', '/rev/scans/reserve', $compte + ['ref' => (string)$ref]);
        if ($code === 409 && ($j['error'] ?? '') === 'no_scans') {
            return null;
        }
        if ($code !== 201 || empty($j['reservation'])) {
            error_log('[smartscan] bensdsRev réservation : HTTP ' . $code . ' ' . ($j['error'] ?? ''));
            throw new SmartScanException(503, 'SERVICE_UNAVAILABLE', 'Service Smart Scan momentanément indisponible.');
        }
        return $j;
    }

    /** Le scan a lu du texte : il compte. */
    public function confirmer($reservation)
    {
        return $this->fermer($reservation, 'commit');
    }

    /** Le scan a échoué : il ne compte pas. */
    public function liberer($reservation)
    {
        return $this->fermer($reservation, 'release');
    }

    private function fermer($reservation, $action)
    {
        list($code, $j) = $this->appel('POST', '/rev/scans/' . rawurlencode($reservation) . '/' . $action);
        if ($code !== 200) {
            error_log('[smartscan] bensdsRev ' . $action . ' ' . $reservation . ' : HTTP ' . $code . ' ' . ($j['error'] ?? ''));
            return null;
        }
        return $j;
    }
}
