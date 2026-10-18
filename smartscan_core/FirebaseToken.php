<?php
/**
 * Vérification des jetons de connexion Firebase (application mobile Caisse DZ).
 *
 * L'application envoie son jeton d'identité Firebase (en-tête X-Firebase-Token) ;
 * on vérifie ici sa signature RS256 avec les certificats publics de Google, puis
 * le projet, l'émetteur, les dates et le numéro de téléphone. Aucune
 * bibliothèque externe : OpenSSL suffit.
 *
 * https://firebase.google.com/docs/auth/admin/verify-id-tokens#verify_id_tokens_using_a_third-party_jwt_library
 */
class FirebaseToken
{
    const CERTIFICATS = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';
    const TOLERANCE_S = 60;

    /** @var string */
    private $projet;
    /** @var callable fn(): array{0: array<string,string>, 1: int} certificats (kid => PEM) et durée de cache (s) */
    private $certificats;
    /** @var callable fn(): int */
    private $horloge;

    public function __construct($projet, ?callable $certificats = null, ?callable $horloge = null)
    {
        $this->projet = (string)$projet;
        $this->certificats = $certificats ?: [self::class, 'telechargerCertificats'];
        $this->horloge = $horloge ?: function () { return time(); };
    }

    /**
     * Renvoie ['uid' => …, 'telephone' => '+213…'] si le jeton est valide.
     * @throws SmartScanException 401 sinon.
     */
    public function verifier($jeton)
    {
        $refus = function ($raison) {
            error_log('[smartscan] jeton Firebase refusé : ' . $raison);
            return new SmartScanException(401, 'TOKEN_INVALID', 'Session expirée, reconnectez-vous.');
        };
        if ($this->projet === '') {
            throw new SmartScanException(503, 'SERVER_NOT_CONFIGURED', 'Connexion mobile non configurée (FIREBASE_PROJECT_ID).');
        }
        $parties = explode('.', (string)$jeton);
        if (count($parties) !== 3) throw $refus('format');
        list($h64, $p64, $s64) = $parties;
        $entete = json_decode(self::base64url($h64), true);
        $charge = json_decode(self::base64url($p64), true);
        $signature = self::base64url($s64);
        if (!is_array($entete) || !is_array($charge) || $signature === '') throw $refus('illisible');
        if (($entete['alg'] ?? '') !== 'RS256') throw $refus('algorithme');

        list($certs) = call_user_func($this->certificats);
        $pem = $certs[$entete['kid'] ?? ''] ?? null;
        if ($pem === null) throw $refus('kid inconnu');
        if (openssl_verify($h64 . '.' . $p64, $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) throw $refus('signature');

        $t = call_user_func($this->horloge);
        if (($charge['aud'] ?? '') !== $this->projet) throw $refus('aud');
        if (($charge['iss'] ?? '') !== 'https://securetoken.google.com/' . $this->projet) throw $refus('iss');
        if ((int)($charge['exp'] ?? 0) < $t - self::TOLERANCE_S) throw $refus('expiré');
        if ((int)($charge['iat'] ?? PHP_INT_MAX) > $t + self::TOLERANCE_S) throw $refus('iat');
        if ((int)($charge['auth_time'] ?? PHP_INT_MAX) > $t + self::TOLERANCE_S) throw $refus('auth_time');
        $uid = (string)($charge['sub'] ?? '');
        if ($uid === '' || strlen($uid) > 128 || !preg_match('/^[A-Za-z0-9_-]+$/', $uid)) throw $refus('sub');
        $telephone = (string)($charge['phone_number'] ?? '');
        if ($telephone === '') throw $refus('pas de numéro de téléphone');

        return ['uid' => $uid, 'telephone' => $telephone];
    }

    private static function base64url($v)
    {
        $d = base64_decode(strtr($v, '-_', '+/') . str_repeat('=', (4 - strlen($v) % 4) % 4), true);
        return $d === false ? '' : $d;
    }

    /** Certificats de Google, gardés en cache le temps indiqué par Cache-Control. */
    public static function telechargerCertificats()
    {
        $cache = sys_get_temp_dir() . '/smartscan_firebase_certs.json';
        $lu = @json_decode((string)@file_get_contents($cache), true);
        if (is_array($lu) && ($lu['expire'] ?? 0) > time() && is_array($lu['certs'] ?? null)) {
            return [$lu['certs'], $lu['expire'] - time()];
        }
        $entetes = [];
        $ch = curl_init(self::CERTIFICATS);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HEADERFUNCTION => function ($ch, $ligne) use (&$entetes) {
                $entetes[] = trim($ligne);
                return strlen($ligne);
            },
        ]);
        $corps = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $certs = $code === 200 ? json_decode((string)$corps, true) : null;
        if (!is_array($certs) || !$certs) {
            throw new SmartScanException(503, 'SERVICE_UNAVAILABLE', 'Vérification de connexion indisponible, réessayez.');
        }
        $duree = 3600;
        foreach ($entetes as $e) {
            if (preg_match('/^cache-control:.*max-age=(\d+)/i', $e, $m)) $duree = (int)$m[1];
        }
        @file_put_contents($cache, json_encode(['expire' => time() + $duree, 'certs' => $certs]), LOCK_EX);
        return [$certs, $duree];
    }
}
