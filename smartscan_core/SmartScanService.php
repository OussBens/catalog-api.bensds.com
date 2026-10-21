<?php
/**
 * Smart Scan (OCR des bons d'achat) — logique métier côté serveur BENS.
 *
 * Le serveur est la seule source de vérité : licence, forfait, quota et
 * décompte sont calculés ici, jamais à partir de valeurs envoyées par
 * l'application. La clé Mistral n'est connue que du transport (voir
 * routes.php) et n'apparaît dans aucune réponse.
 *
 * Décompte transactionnel :
 *   1. réservation (transaction + verrou sur le client) : licence, client
 *      actif, limite de débit, scans simultanés, quota → ligne ss_scans
 *      au statut "reserve" ;
 *   2. appel OCR ;
 *   3. succès → "reussi" (seul statut compté dans le quota) ;
 *      échec (réseau, timeout, erreur Mistral, texte vide…) → "echoue",
 *      non compté. Une réservation abandonnée (crash) expire seule.
 *
 * Le compteur mensuel est calculé à partir du journal (scans réussis du
 * mois civil en cours) : il repart à zéro chaque mois sans tâche planifiée
 * et les scans non utilisés ne sont jamais reportés.
 *
 * Deux plateformes :
 *   desktop  CaisseDZ Desktop, authentifié par sa licence BENS signée (X-Device-Id + X-License-Key,
 *            LicenceBens) ; ses Smart Scans sont comptés par bens_ds (RevBens) : scans inclus de la
 *            formule, de l'essai et des packs, chacun avec sa date de fin. Réservé avant l'OCR,
 *            confirmé si du texte est lu, libéré sinon (non compté) ;
 *   mobile   application Caisse DZ mobile, authentifiée par sa connexion
 *            Firebase (X-Firebase-Token, numéro de téléphone vérifié par SMS) ; ses Smart Scans
 *            sont comptés par bens_ds de la même façon, au compte de son numéro.
 * L'essai, les formules et les packs sont ceux de bens_ds (le tableau de bord BENS) ; les forfaits
 * mensuels ss_forfaits / ss_abonnements ne donnent plus de scans.
 */

class SmartScanException extends Exception
{
    /** @var int */
    public $httpCode;
    /** @var string */
    public $codeErreur;
    /** @var array */
    public $donnees;

    public function __construct($httpCode, $codeErreur, $message, array $donnees = [])
    {
        parent::__construct($message, $httpCode);
        $this->httpCode = $httpCode;
        $this->codeErreur = $codeErreur;
        $this->donnees = $donnees;
    }

    public function versReponse()
    {
        return array_merge([
            'success' => false,
            'error' => $this->codeErreur,
            'message' => $this->getMessage(),
        ], $this->donnees);
    }
}

class SmartScanService
{
    const RESERVE = 'reserve';
    const REUSSI = 'reussi';
    const ECHOUE = 'echoue';

    const MODELE_OCR = 'mistral-ocr-latest';
    const MODELES_EXTRACTION = ['mistral-small-latest', 'mistral-medium-latest', 'open-mistral-7b'];
    const TYPES_IMAGE = ['image/jpeg', 'image/png', 'image/webp'];

    /** @var PDO */
    private $db;
    /** @var array */
    private $config;
    /** @var callable fn(string $chemin, array $payload): array{0:int,1:string} */
    private $transport;
    /** @var callable fn(): int (timestamp) — injectable pour les tests */
    private $horloge;

    public function __construct(PDO $db, array $config, callable $transport, ?callable $horloge = null)
    {
        $this->db = $db;
        $this->config = array_merge([
            'secret_licence' => '', // empreintes des numéros (essais déjà utilisés)
            'cle_publique' => '', // LICENSE_PUBLIC_KEY : CaisseDZ Desktop dans le tableau de bord BENS
            'produit_licence' => 1, // LICENSE_PRODUCT : son numéro
            'rev' => null, // RevBens : les scans de CaisseDZ, comptés par bens_ds
            'firebase' => null, // FirebaseToken, pour l'application mobile
            'rate_par_minute' => 6,
            'max_simultanes' => 1,
            'reservation_expire_s' => 180,
            'taille_max_octets' => 8 * 1024 * 1024,
            'extractions_par_scan' => 6,
            'extraction_validite_s' => 1800,
            'doublon_fenetre_s' => 300,
            'plafond_global_mensuel' => 0, // 0 = désactivé
            'contact' => '',
        ], $config);
        $this->transport = $transport;
        $this->horloge = $horloge ?: function () { return time(); };
    }

    // ───────────────────────────────────────────────────────────── Dates

    private function maintenant()
    {
        return call_user_func($this->horloge);
    }

    private function dateHeure($ts = null)
    {
        return date('Y-m-d H:i:s', $ts === null ? $this->maintenant() : $ts);
    }

    private function jour($ts = null)
    {
        return date('Y-m-d', $ts === null ? $this->maintenant() : $ts);
    }

    private function debutMois()
    {
        return date('Y-m-01 00:00:00', $this->maintenant());
    }

    private function debutMoisSuivant()
    {
        return date('Y-m-d H:i:s', strtotime('first day of next month 00:00:00', $this->maintenant()));
    }

    private function estMysql()
    {
        return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    // ─────────────────────────────────────────────────────────── Licence

    /**
     * Vérifie la licence du poste et renvoie le poste (avec son client).
     * Un poste inconnu est enregistré et rattaché à un nouveau client (nom
     * de l'entreprise saisi dans l'app) ; l'administration peut ensuite le
     * rattacher à un autre client pour partager le même compteur.
     */
    public function authentifier($deviceId, $cle, $entreprise = null)
    {
        if ($this->config['cle_publique'] === '') {
            throw new SmartScanException(503, 'SERVER_NOT_CONFIGURED', 'Service Smart Scan non configuré (LICENSE_PUBLIC_KEY).');
        }
        $deviceId = trim((string)$deviceId);
        $cle = trim((string)$cle);
        if ($deviceId === '' || $cle === '' || strlen($deviceId) > 64 || !preg_match('/^[A-Za-z0-9_-]+$/', $deviceId)) {
            throw new SmartScanException(401, 'LICENSE_INVALID', 'Licence Caisse DZ manquante ou invalide.');
        }
        // Licence BENS signée pour ce poste, en cours (essai ou formule) : son palier.
        $palierValide = LicenceBens::palier($deviceId, $cle, $this->config['cle_publique'], $this->config['produit_licence']);
        if ($palierValide === null) {
            throw new SmartScanException(401, 'LICENSE_INVALID', 'Licence Caisse DZ manquante ou invalide.');
        }

        return $this->enregistrerPoste($deviceId, 'desktop', $palierValide, $entreprise, null);
    }

    /**
     * Application mobile : jeton Firebase (connexion par SMS) au lieu d'une licence.
     * Le poste est le compte Firebase ("fb_" + uid), le contact son numéro.
     */
    public function authentifierMobile($jeton, $entreprise = null)
    {
        $firebase = $this->config['firebase'];
        if (!$firebase instanceof FirebaseToken) {
            throw new SmartScanException(503, 'SERVER_NOT_CONFIGURED', 'Connexion mobile non configurée (FIREBASE_PROJECT_ID).');
        }
        $compte = $firebase->verifier($jeton);
        return $this->enregistrerPoste(substr('fb_' . $compte['uid'], 0, 64), 'mobile', 'mobile', $entreprise, $compte['telephone']);
    }

    /**
     * Enregistre un poste inconnu (nouveau client de la plateforme, avec son essai)
     * ou met à jour un poste connu. Refuse un poste bloqué.
     */
    private function enregistrerPoste($deviceId, $plateforme, $palier, $entreprise, $contact)
    {
        $entreprise = $entreprise !== null ? mb_substr(trim($entreprise), 0, 120) : null;
        $poste = $this->poste($deviceId);
        if ($poste === null) {
            $this->db->beginTransaction();
            try {
                $nom = $entreprise ?: ($contact ?: ('Poste ' . substr($deviceId, 0, 8)));
                $essaiDebut = ($contact ? $this->essaiDejaUtilise($contact) : null) ?: $this->jour();
                $stmt = $this->db->prepare('INSERT INTO ss_clients (nom, contact, statut, cree_le, plateforme, essai_debut) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$nom, $contact, 'actif', $this->dateHeure(), $plateforme, $essaiDebut]);
                $clientId = (int)$this->db->lastInsertId();
                $stmt = $this->db->prepare('INSERT INTO ss_postes (device_id, client_id, entreprise, palier_licence, revoque, premier_appel, dernier_appel)
                    VALUES (?, ?, ?, ?, 0, ?, ?)');
                $stmt->execute([$deviceId, $clientId, $entreprise, $palier, $this->dateHeure(), $this->dateHeure()]);
                $this->db->commit();
            } catch (Exception $e) {
                $this->db->rollBack();
                // Course entre deux premiers appels simultanés : le poste existe déjà.
                if ($this->poste($deviceId) === null) throw $e;
            }
        } else {
            $stmt = $this->db->prepare('UPDATE ss_postes SET dernier_appel = ?, palier_licence = ?, entreprise = COALESCE(?, entreprise) WHERE device_id = ?');
            $stmt->execute([$this->dateHeure(), $palier, $entreprise ?: null, $deviceId]);
        }

        $poste = $this->poste($deviceId);
        if ((int)$poste['revoque'] === 1) {
            throw new SmartScanException(403, 'DEVICE_BLOCKED', 'Ce poste n\'est pas autorisé à utiliser Smart Scan.');
        }
        return $poste;
    }

    /** Empreinte d'un numéro de téléphone (HMAC : le numéro n'est pas retrouvable sans le secret). */
    private function empreinteTelephone($telephone)
    {
        return hash_hmac('sha256', $telephone, 'smartscan-essai|' . $this->config['secret_licence']);
    }

    /** Début de l'essai d'un ancien compte supprimé avec ce numéro, sinon null. */
    private function essaiDejaUtilise($telephone)
    {
        $stmt = $this->db->prepare('SELECT essai_debut FROM ss_essais_utilises WHERE empreinte = ?');
        $stmt->execute([$this->empreinteTelephone($telephone)]);
        $d = $stmt->fetchColumn();
        return $d ?: null;
    }

    /**
     * Suppression du compte demandée depuis l'application mobile (connexion Firebase vérifiée).
     * Supprime le poste, le journal des scans et les demandes ; le client est supprimé, ou
     * anonymisé s'il a des abonnements payés (pièces comptables). Garde seulement l'empreinte
     * du numéro et la date de l'essai. Sans poste connu : rien à supprimer (appel répétable).
     */
    public function supprimerCompteMobile($jeton)
    {
        $firebase = $this->config['firebase'];
        if (!$firebase instanceof FirebaseToken) {
            throw new SmartScanException(503, 'SERVER_NOT_CONFIGURED', 'Connexion mobile non configurée (FIREBASE_PROJECT_ID).');
        }
        $compte = $firebase->verifier($jeton);
        $poste = $this->poste(substr('fb_' . $compte['uid'], 0, 64));
        if ($poste === null) {
            return ['success' => true, 'supprime' => false];
        }
        $clientId = (int)$poste['client_id'];
        $client = $this->client($clientId);

        $this->db->beginTransaction();
        try {
            if (self::plateforme($client) === 'mobile') {
                $stmt = $this->db->prepare('SELECT COUNT(*) FROM ss_essais_utilises WHERE empreinte = ?');
                $empreinte = $this->empreinteTelephone($compte['telephone']);
                $stmt->execute([$empreinte]);
                if ((int)$stmt->fetchColumn() === 0) {
                    $debut = $client['essai_debut'] ?: substr($client['cree_le'], 0, 10);
                    $stmt = $this->db->prepare('INSERT INTO ss_essais_utilises (empreinte, essai_debut, supprime_le) VALUES (?, ?, ?)');
                    $stmt->execute([$empreinte, $debut, $this->dateHeure()]);
                }
            }
            $stmt = $this->db->prepare('DELETE FROM ss_postes WHERE device_id = ?');
            $stmt->execute([$poste['device_id']]);
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM ss_postes WHERE client_id = ?');
            $stmt->execute([$clientId]);
            // Un client partagé avec d'autres postes (rattachement manuel) garde ses données.
            if ((int)$stmt->fetchColumn() === 0) {
                foreach (['ss_scans', 'ss_demandes'] as $table) {
                    $stmt = $this->db->prepare("DELETE FROM $table WHERE client_id = ?");
                    $stmt->execute([$clientId]);
                }
                $stmt = $this->db->prepare('SELECT COUNT(*) FROM ss_abonnements WHERE client_id = ?');
                $stmt->execute([$clientId]);
                if ((int)$stmt->fetchColumn() === 0) {
                    $stmt = $this->db->prepare('DELETE FROM ss_clients WHERE id = ?');
                    $stmt->execute([$clientId]);
                } else {
                    $stmt = $this->db->prepare("UPDATE ss_clients SET nom = ?, contact = NULL, statut = 'supprime' WHERE id = ?");
                    $stmt->execute(['Compte supprimé #' . $clientId, $clientId]);
                }
            } else {
                $stmt = $this->db->prepare('DELETE FROM ss_scans WHERE device_id = ?');
                $stmt->execute([$poste['device_id']]);
                $stmt = $this->db->prepare('DELETE FROM ss_demandes WHERE device_id = ?');
                $stmt->execute([$poste['device_id']]);
            }
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
        return ['success' => true, 'supprime' => true];
    }

    private function poste($deviceId)
    {
        $stmt = $this->db->prepare('SELECT * FROM ss_postes WHERE device_id = ?');
        $stmt->execute([$deviceId]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        return $p ?: null;
    }

    private function client($clientId, $verrouiller = false)
    {
        $sql = 'SELECT * FROM ss_clients WHERE id = ?' . ($verrouiller && $this->estMysql() ? ' FOR UPDATE' : '');
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$clientId]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$c) {
            throw new SmartScanException(403, 'CLIENT_UNKNOWN', 'Client Smart Scan introuvable.');
        }
        return $c;
    }

    // ──────────────────────────────────────────────────── Forfait & quota

    private function forfait($code)
    {
        $stmt = $this->db->prepare('SELECT * FROM ss_forfaits WHERE code = ?');
        $stmt->execute([$code]);
        $f = $stmt->fetch(PDO::FETCH_ASSOC);
        return $f ?: null;
    }

    private function compterScans($clientId, $statut, $depuis, $jusqua = null)
    {
        $sql = 'SELECT COUNT(*) FROM ss_scans WHERE client_id = ? AND statut = ? AND cree_le >= ?';
        $params = [$clientId, $statut, $depuis];
        if ($jusqua !== null) {
            $sql .= ' AND cree_le < ?';
            $params[] = $jusqua;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    private static function plateforme(array $client)
    {
        return ($client['plateforme'] ?? '') === 'mobile' ? 'mobile' : 'desktop';
    }

    /** Offres payantes de la plateforme, la plus courte d'abord. */
    public function offres($plateforme)
    {
        $stmt = $this->db->prepare('SELECT code, nom, prix_da, quota_mensuel, duree_mois FROM ss_forfaits
            WHERE actif = 1 AND prix_da > 0 AND essai = 0 AND plateforme = ? ORDER BY duree_mois, prix_da');
        $stmt->execute([$plateforme]);
        return array_map(function ($o) {
            return [
                'code' => $o['code'],
                'nom' => $o['nom'],
                'prix_da' => (int)$o['prix_da'],
                'quota_mensuel' => (int)$o['quota_mensuel'],
                'duree_mois' => (int)$o['duree_mois'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * État du quota du client — renvoyé tel quel à l'application : ses Smart Scans chez bens_ds.
     * status : "active" (scans achetés), "trial" (seulement ceux de l'essai) ou "expired" (aucun).
     */
    public function quota($clientId, $deviceId = null)
    {
        return $this->quotaRev($this->client($clientId), $deviceId);
    }

    /** Les scans comptés par bens_ds. */
    private function rev()
    {
        $rev = $this->config['rev'];
        if (!$rev instanceof RevBens || !$rev->configure()) {
            throw new SmartScanException(503, 'SERVER_NOT_CONFIGURED', 'Smart Scan non configuré (REV_API_URL, REV_SERVER_TOKEN).');
        }
        return $rev;
    }

    /** Le poste à interroger : celui de la requête, sinon le dernier vu du client (administration). */
    private function posteDuClient($clientId, $deviceId)
    {
        if ($deviceId !== null) return $deviceId;
        $stmt = $this->db->prepare('SELECT device_id FROM ss_postes WHERE client_id = ? ORDER BY dernier_appel DESC LIMIT 1');
        $stmt->execute([(int)$clientId]);
        $d = $stmt->fetchColumn();
        return $d ?: null;
    }

    /**
     * Le compte du client chez bens_ds : le poste (Desktop : celui de la requête, sinon le dernier vu),
     * le numéro (Mobile). Null : aucun (rien à dépenser).
     */
    private function compteRev(array $client, $deviceId)
    {
        if (self::plateforme($client) === 'mobile') {
            return ($client['contact'] ?? '') !== '' ? RevBens::telephone($client['contact']) : null;
        }
        $deviceId = $this->posteDuClient((int)$client['id'], $deviceId);
        return $deviceId === null ? null : RevBens::poste(LicenceBens::codePoste($deviceId));
    }

    /**
     * Quota d'après bens_ds, sous la forme habituelle de l'application et de l'administration :
     * limit / used / remaining sur les scans en cours (essai, inclus et packs), le détail par lot
     * (buckets) et ceux qui expirent bientôt (expiring_soon).
     */
    private function quotaRev(array $client, $deviceId)
    {
        $compte = $this->compteRev($client, $deviceId);
        $s = $compte === null
            ? ['remaining' => 0, 'total' => 0, 'status' => 'none', 'buckets' => [], 'expiringSoon' => []]
            : $this->rev()->solde($compte);
        $total = (int)($s['total'] ?? 0);
        $reste = (int)($s['remaining'] ?? 0);
        $statut = ($s['status'] ?? 'none') === 'none' ? 'expired' : $s['status'];
        return [
            'client_id' => (int)$client['id'],
            'client_nom' => $client['nom'],
            'platform' => self::plateforme($client),
            'plan' => 'SCANS',
            'plan_nom' => 'Smart Scan',
            'period' => 'scans',
            'limit' => $total,
            'monthly_limit' => $total,
            'used' => $total - $reste,
            'remaining' => $reste,
            'limit_reached' => $reste <= 0,
            'renewal_date' => null,
            'trial_end' => null,
            'subscription_start' => null,
            'subscription_end' => null,
            'status' => $statut,
            'buckets' => $s['buckets'] ?? [],
            'expiring_soon' => $s['expiringSoon'] ?? [],
            'upgrade_available' => true,
            'offers' => [],
            'contact' => $this->config['contact'],
        ];
    }

    // ─────────────────────────────────────────────────────────────── Scan

    /** Valide l'image (data URL base64) : type réel, taille, dimensions. */
    private function validerImage($dataUrl)
    {
        if (!is_string($dataUrl) || !preg_match('#^data:(image/[a-z]+);base64,#', $dataUrl, $m)) {
            throw new SmartScanException(400, 'INVALID_IMAGE', 'Image manquante ou format non supporté.');
        }
        $binaire = base64_decode(substr($dataUrl, strlen($m[0])), true);
        if ($binaire === false || $binaire === '') {
            throw new SmartScanException(400, 'INVALID_IMAGE', 'Image illisible.');
        }
        if (strlen($binaire) > $this->config['taille_max_octets']) {
            throw new SmartScanException(413, 'IMAGE_TOO_LARGE', 'Image trop volumineuse.', [
                'max_mo' => round($this->config['taille_max_octets'] / 1048576, 1),
            ]);
        }
        $infos = @getimagesizefromstring($binaire);
        if ($infos === false || !in_array($infos['mime'], self::TYPES_IMAGE, true)) {
            throw new SmartScanException(400, 'INVALID_IMAGE', 'Format d\'image non supporté (JPG, PNG ou WEBP).');
        }
        if ($infos[0] < 16 || $infos[1] < 16 || $infos[0] > 10000 || $infos[1] > 10000) {
            throw new SmartScanException(400, 'INVALID_IMAGE', 'Dimensions d\'image invalides.');
        }
        // L'image n'est jamais conservée : seule son empreinte sert à
        // détecter un envoi répété du même bon.
        return ['mime' => $infos['mime'], 'taille' => strlen($binaire), 'empreinte' => hash('sha256', $binaire)];
    }

    /** Étape 1 : réservation sous verrou. Renvoie l'id du scan réservé. */
    private function reserver(array $poste, array $image)
    {
        $clientId = (int)$poste['client_id'];
        $this->db->beginTransaction();
        try {
            $client = $this->client($clientId, true);
            if ($client['statut'] !== 'actif') {
                throw new SmartScanException(403, 'CLIENT_SUSPENDED', 'Smart Scan est suspendu pour ce client.');
            }

            $maintenant = $this->maintenant();
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM ss_scans WHERE client_id = ? AND cree_le >= ?');
            $stmt->execute([$clientId, $this->dateHeure($maintenant - 60)]);
            if ((int)$stmt->fetchColumn() >= $this->config['rate_par_minute']) {
                throw new SmartScanException(429, 'RATE_LIMITED', 'Trop de scans en peu de temps. Réessayez dans une minute.');
            }

            $limiteReservation = $this->dateHeure($maintenant - $this->config['reservation_expire_s']);
            $enCours = $this->compterScans($clientId, self::RESERVE, $limiteReservation);
            if ($enCours >= $this->config['max_simultanes']) {
                throw new SmartScanException(429, 'SCAN_IN_PROGRESS', 'Un scan est déjà en cours pour ce client.');
            }

            // bens_ds réserve un scan dans le premier lot qui en a (essai et inclus d'abord, puis packs).
            $compte = $this->compteRev($client, $poste['device_id']);
            $r = $compte === null ? null : $this->rev()->reserver($compte, 'poste ' . substr($poste['device_id'], 0, 12));
            if ($r === null) {
                $quota = $this->quota($clientId, $poste['device_id']);
                throw new SmartScanException(429, 'SCAN_QUOTA_EXCEEDED', 'Vos Smart Scans sont épuisés ou expirés. Achetez un pack Smart Scan pour continuer.', [
                    'used' => $quota['used'],
                    'limit' => $quota['limit'],
                    'remaining' => 0,
                    'upgrade_available' => true,
                    'quota' => $quota,
                ]);
            }
            $reservation = $r['reservation'];

            // Après le quota : un client hors quota voit l'offre, pas « doublon ».
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM ss_scans WHERE client_id = ? AND statut = ? AND empreinte_image = ? AND cree_le >= ?');
            $stmt->execute([$clientId, self::REUSSI, $image['empreinte'], $this->dateHeure($maintenant - $this->config['doublon_fenetre_s'])]);
            if ((int)$stmt->fetchColumn() > 0) {
                if ($reservation !== null) $this->rev()->liberer($reservation);
                throw new SmartScanException(409, 'DUPLICATE_SCAN', 'Ce bon vient déjà d\'être scanné.');
            }

            $plafond = (int)$this->config['plafond_global_mensuel'];
            if ($plafond > 0) {
                $stmt = $this->db->prepare('SELECT COUNT(*) FROM ss_scans WHERE statut = ? AND cree_le >= ?');
                $stmt->execute([self::REUSSI, $this->debutMois()]);
                if ((int)$stmt->fetchColumn() >= $plafond) {
                    if ($reservation !== null) $this->rev()->liberer($reservation);
                    throw new SmartScanException(503, 'SERVICE_UNAVAILABLE', 'Service Smart Scan momentanément indisponible.');
                }
            }

            $stmt = $this->db->prepare('INSERT INTO ss_scans (client_id, device_id, cree_le, statut, modele, taille_image, empreinte_image, extractions, rev_reservation)
                VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)');
            $stmt->execute([$clientId, $poste['device_id'], $this->dateHeure(), self::RESERVE, self::MODELE_OCR, $image['taille'], $image['empreinte'], $reservation]);
            $scanId = (int)$this->db->lastInsertId();
            $this->db->commit();
            return $scanId;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function terminer($scanId, $statut, $dureeMs, $codeErreur = null, $messageErreur = null)
    {
        $stmt = $this->db->prepare('UPDATE ss_scans SET statut = ?, duree_ms = ?, code_erreur = ?, message_erreur = ? WHERE id = ?');
        $stmt->execute([$statut, $dureeMs, $codeErreur, $messageErreur !== null ? mb_substr($messageErreur, 0, 255) : null, $scanId]);
        // CaisseDZ Desktop : bens_ds compte le scan s'il a lu du texte, le rend sinon.
        $stmt = $this->db->prepare('SELECT rev_reservation FROM ss_scans WHERE id = ?');
        $stmt->execute([$scanId]);
        $reservation = $stmt->fetchColumn();
        if ($reservation) {
            try {
                $statut === self::REUSSI ? $this->rev()->confirmer($reservation) : $this->rev()->liberer($reservation);
            } catch (SmartScanException $e) {
                // Le scan est fait (ou a échoué) : une réservation non fermée cesse de compter au bout de 10 minutes.
                error_log('[smartscan] bensdsRev ' . $reservation . ' : ' . $e->codeErreur);
            }
        }
    }

    /**
     * OCR d'un bon : réservation → Mistral → décompte uniquement si succès.
     * Renvoie le texte extrait, l'id du scan (pour l'extraction des lignes)
     * et le quota à jour.
     */
    public function scanner(array $poste, $imageDataUrl)
    {
        $image = $this->validerImage($imageDataUrl);
        $scanId = $this->reserver($poste, $image);

        $debut = microtime(true);
        try {
            list($code, $corps) = call_user_func($this->transport, '/v1/ocr', [
                'model' => self::MODELE_OCR,
                'document' => ['type' => 'image_url', 'image_url' => $imageDataUrl],
                'include_image_base64' => false,
            ]);
        } catch (Exception $e) {
            $this->terminer($scanId, self::ECHOUE, (int)((microtime(true) - $debut) * 1000), 'NETWORK_ERROR', $e->getMessage());
            throw new SmartScanException(502, 'OCR_FAILED', 'Service OCR injoignable. Aucun scan n\'a été décompté.');
        }
        $dureeMs = (int)((microtime(true) - $debut) * 1000);

        if ($code !== 200) {
            $codeErreur = $code === 0 ? 'TIMEOUT' : 'MISTRAL_HTTP_' . $code;
            $this->terminer($scanId, self::ECHOUE, $dureeMs, $codeErreur, substr((string)$corps, 0, 255));
            if ($code === 429) {
                throw new SmartScanException(503, 'SERVICE_BUSY', 'Service OCR saturé, réessayez dans quelques instants. Aucun scan n\'a été décompté.');
            }
            throw new SmartScanException(502, 'OCR_FAILED', 'Le traitement OCR a échoué. Aucun scan n\'a été décompté.');
        }

        $reponse = json_decode($corps, true);
        $texte = '';
        foreach (($reponse['pages'] ?? []) as $page) {
            if (!empty($page['markdown'])) $texte .= $page['markdown'] . "\n";
        }
        if (trim($texte) === '') {
            $this->terminer($scanId, self::ECHOUE, $dureeMs, 'OCR_EMPTY', 'Aucun texte détecté');
            throw new SmartScanException(422, 'OCR_EMPTY', 'Aucun texte lisible sur l\'image. Aucun scan n\'a été décompté.');
        }

        $this->terminer($scanId, self::REUSSI, $dureeMs);
        return [
            'success' => true,
            'scan_id' => $scanId,
            'text' => $texte,
            'quota' => $this->quota((int)$poste['client_id'], $poste['device_id']),
        ];
    }

    /**
     * Extraction des lignes du bon à partir du texte OCR. Rattachée à un
     * scan réussi récent du même client (pas d'appel IA "libre"), avec un
     * nombre limité d'essais par scan. Ne consomme pas de quota : le scan a
     * déjà été décompté. Renvoie [code HTTP, corps] de la réponse Mistral.
     */
    public function extraire(array $poste, $scanId, array $corps)
    {
        $stmt = $this->db->prepare('SELECT * FROM ss_scans WHERE id = ? AND client_id = ?');
        $stmt->execute([(int)$scanId, (int)$poste['client_id']]);
        $scan = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$scan || $scan['statut'] !== self::REUSSI
            || strtotime($scan['cree_le']) < $this->maintenant() - $this->config['extraction_validite_s']) {
            throw new SmartScanException(403, 'SCAN_NOT_FOUND', 'Scan introuvable ou expiré.');
        }
        $modele = $corps['model'] ?? '';
        if (!in_array($modele, self::MODELES_EXTRACTION, true)) {
            throw new SmartScanException(400, 'MODEL_NOT_ALLOWED', 'Modèle non autorisé.');
        }
        if (!is_array($corps['messages'] ?? null)) {
            throw new SmartScanException(400, 'INVALID_REQUEST', 'Requête d\'analyse invalide.');
        }
        if ((int)$scan['extractions'] >= $this->config['extractions_par_scan']) {
            throw new SmartScanException(429, 'EXTRACTION_LIMIT', 'Nombre maximal d\'analyses atteint pour ce scan.');
        }

        $stmt = $this->db->prepare('UPDATE ss_scans SET extractions = extractions + 1 WHERE id = ?');
        $stmt->execute([(int)$scanId]);

        $payload = [
            'model' => $modele,
            'messages' => $corps['messages'],
            'temperature' => $corps['temperature'] ?? 0,
            'max_tokens' => min((int)($corps['max_tokens'] ?? 4096), 8192),
        ];
        if (isset($corps['response_format'])) {
            $payload['response_format'] = $corps['response_format'];
        }
        try {
            list($code, $reponse) = call_user_func($this->transport, '/v1/chat/completions', $payload);
        } catch (Exception $e) {
            $code = 0;
            $reponse = json_encode(['success' => false, 'error' => 'NETWORK_ERROR', 'message' => 'Service d\'analyse injoignable.']);
        }
        if ($code !== 200) {
            $stmt = $this->db->prepare('UPDATE ss_scans SET erreur_extraction = ? WHERE id = ?');
            $stmt->execute([$code === 0 ? 'NETWORK_ERROR' : 'MISTRAL_HTTP_' . $code, (int)$scanId]);
        }
        return [$code ?: 502, $reponse];
    }

    // ────────────────────────────────────────────────────── Demande d'offre

    public function demanderOffre(array $poste, $forfaitCode)
    {
        $forfait = $this->forfait((string)$forfaitCode);
        $client = $this->client((int)$poste['client_id']);
        if ($forfait === null || (int)$forfait['actif'] !== 1 || (int)$forfait['prix_da'] <= 0
            || ($forfait['plateforme'] ?? 'desktop') !== self::plateforme($client)) {
            throw new SmartScanException(400, 'OFFER_UNKNOWN', 'Offre inconnue.');
        }
        // Une seule demande en attente par client et par offre.
        $stmt = $this->db->prepare("SELECT id FROM ss_demandes WHERE client_id = ? AND forfait_code = ? AND statut = 'nouvelle'");
        $stmt->execute([(int)$poste['client_id'], $forfait['code']]);
        $existante = $stmt->fetchColumn();
        if (!$existante) {
            $stmt = $this->db->prepare("INSERT INTO ss_demandes (client_id, device_id, forfait_code, cree_le, statut) VALUES (?, ?, ?, ?, 'nouvelle')");
            $stmt->execute([(int)$poste['client_id'], $poste['device_id'], $forfait['code'], $this->dateHeure()]);
        }
        return [
            'success' => true,
            'deja_demandee' => (bool)$existante,
            'offre' => $forfait['nom'],
            'contact' => $this->config['contact'],
        ];
    }

    // ───────────────────────────────────────────────────── Administration

    /**
     * Active un forfait payant pour un client (paiement manuel aujourd'hui,
     * paiement en ligne plus tard : même point d'entrée). Un abonnement
     * actif existant est remplacé (annulé).
     */
    public function activerForfait($clientId, $forfaitCode, $debut = null, $mois = null, $note = null)
    {
        $forfait = $this->forfait($forfaitCode);
        if ($forfait === null) {
            throw new SmartScanException(400, 'OFFER_UNKNOWN', 'Forfait inconnu.');
        }
        $this->client($clientId);
        $debut = $debut ?: $this->jour();
        $mois = $mois ?: max(1, (int)$forfait['duree_mois']);
        $fin = date('Y-m-d', strtotime($debut . ' +' . (int)$mois . ' months -1 day'));

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("UPDATE ss_abonnements SET statut = 'cancelled' WHERE client_id = ? AND statut = 'active'");
            $stmt->execute([$clientId]);
            $stmt = $this->db->prepare("INSERT INTO ss_abonnements (client_id, forfait_code, debut, fin, statut, prix_da, note, cree_le)
                VALUES (?, ?, ?, ?, 'active', ?, ?, ?)");
            $stmt->execute([$clientId, $forfait['code'], $debut, $fin, (int)$forfait['prix_da'], $note, $this->dateHeure()]);
            $id = (int)$this->db->lastInsertId();
            $stmt = $this->db->prepare("UPDATE ss_demandes SET statut = 'traitee' WHERE client_id = ? AND forfait_code = ? AND statut = 'nouvelle'");
            $stmt->execute([$clientId, $forfait['code']]);
            $this->db->commit();
            return $id;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Valide une demande d'offre : active le forfait demandé pour son client. */
    public function approuverDemande($demandeId, $note = null)
    {
        $stmt = $this->db->prepare("SELECT * FROM ss_demandes WHERE id = ? AND statut = 'nouvelle'");
        $stmt->execute([(int)$demandeId]);
        $d = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$d) {
            throw new SmartScanException(404, 'NOT_FOUND', 'Demande introuvable ou déjà traitée.');
        }
        return $this->activerForfait((int)$d['client_id'], $d['forfait_code'], null, null, $note);
    }

    /** Redonne l'essai gratuit complet à partir d'aujourd'hui. */
    public function relancerEssai($clientId)
    {
        $this->client($clientId);
        $stmt = $this->db->prepare('UPDATE ss_clients SET essai_debut = ? WHERE id = ?');
        $stmt->execute([$this->jour(), $clientId]);
    }

    public function definirPlateforme($clientId, $plateforme)
    {
        $stmt = $this->db->prepare('UPDATE ss_clients SET plateforme = ? WHERE id = ?');
        $stmt->execute([$plateforme === 'mobile' ? 'mobile' : 'desktop', $clientId]);
    }

    public function annulerAbonnement($abonnementId)
    {
        $stmt = $this->db->prepare("UPDATE ss_abonnements SET statut = 'cancelled' WHERE id = ?");
        $stmt->execute([$abonnementId]);
    }

    public function prolongerAbonnement($abonnementId, $mois)
    {
        $stmt = $this->db->prepare('SELECT * FROM ss_abonnements WHERE id = ?');
        $stmt->execute([$abonnementId]);
        $a = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$a) {
            throw new SmartScanException(404, 'NOT_FOUND', 'Abonnement introuvable.');
        }
        $base = max($a['fin'], $this->jour());
        $fin = date('Y-m-d', strtotime($base . ' +' . (int)$mois . ' months'));
        $stmt = $this->db->prepare("UPDATE ss_abonnements SET fin = ?, statut = 'active' WHERE id = ?");
        $stmt->execute([$fin, $abonnementId]);
    }

    /** Quota exceptionnel pour le mois en cours uniquement (null = retirer). */
    public function definirQuotaException($clientId, $quota)
    {
        $stmt = $this->db->prepare('UPDATE ss_clients SET quota_exception = ?, quota_exception_mois = ? WHERE id = ?');
        $stmt->execute([
            $quota === null ? null : max(0, (int)$quota),
            $quota === null ? null : date('Y-m', $this->maintenant()),
            $clientId,
        ]);
    }

    public function rattacherPoste($deviceId, $clientId)
    {
        $this->client($clientId);
        $stmt = $this->db->prepare('UPDATE ss_postes SET client_id = ? WHERE device_id = ?');
        $stmt->execute([$clientId, $deviceId]);
    }

    public function majClient($clientId, $nom, $contact, $statut)
    {
        $stmt = $this->db->prepare('UPDATE ss_clients SET nom = ?, contact = ?, statut = ? WHERE id = ?');
        $stmt->execute([$nom, $contact, $statut === 'suspendu' ? 'suspendu' : 'actif', $clientId]);
    }

    public function bloquerPoste($deviceId, $bloque)
    {
        $stmt = $this->db->prepare('UPDATE ss_postes SET revoque = ? WHERE device_id = ?');
        $stmt->execute([$bloque ? 1 : 0, $deviceId]);
    }

    /** Vue d'ensemble des clients pour l'administration. */
    public function listeClients()
    {
        $clients = $this->db->query('SELECT * FROM ss_clients ORDER BY nom')->fetchAll(PDO::FETCH_ASSOC);
        $resultat = [];
        foreach ($clients as $c) {
            $q = $this->quota((int)$c['id']);
            $stmt = $this->db->prepare('SELECT COUNT(*), MAX(cree_le) FROM ss_scans WHERE client_id = ? AND statut = ?');
            $stmt->execute([(int)$c['id'], self::REUSSI]);
            list($total, $derniere) = $stmt->fetch(PDO::FETCH_NUM);
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM ss_postes WHERE client_id = ?');
            $stmt->execute([(int)$c['id']]);
            $resultat[] = $q + [
                'statut_client' => $c['statut'],
                'essai_debut' => $c['essai_debut'] ?: substr($c['cree_le'], 0, 10),
                'client_contact' => $c['contact'],
                'postes' => (int)$stmt->fetchColumn(),
                'total_scans' => (int)$total,
                'derniere_utilisation' => $derniere,
            ];
        }
        return $resultat;
    }

    /** Fiche complète d'un client : client, quota, postes, abonnements, demandes. */
    public function ficheClient($clientId)
    {
        $client = $this->client($clientId);
        $requete = function ($sql) use ($clientId) {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$clientId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        };
        return [
            'client' => $client,
            'quota' => $this->quota($clientId),
            'postes' => $requete('SELECT * FROM ss_postes WHERE client_id = ? ORDER BY dernier_appel DESC'),
            'abonnements' => $requete('SELECT * FROM ss_abonnements WHERE client_id = ? ORDER BY id DESC'),
            'demandes' => $requete('SELECT * FROM ss_demandes WHERE client_id = ? ORDER BY id DESC'),
            'consommation' => $this->consommationParMois($clientId),
        ];
    }

    public function clientsSimples()
    {
        return $this->db->query('SELECT id, nom FROM ss_clients ORDER BY nom')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function forfaits()
    {
        return $this->db->query('SELECT * FROM ss_forfaits ORDER BY plateforme, essai DESC, prix_da, quota_mensuel')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Crée ou modifie un forfait (prochain forfait : Smart Scan 500, packs…). */
    public function enregistrerForfait($code, $nom, $prixDa, $quotaMensuel, $dureeMois, $actif, $plateforme = 'desktop', $essai = false)
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', (string)$code));
        if ($code === '' || trim((string)$nom) === '' || (int)$quotaMensuel < 0) {
            throw new SmartScanException(400, 'INVALID_REQUEST', 'Forfait invalide.');
        }
        $params = [trim($nom), max(0, (int)$prixDa), (int)$quotaMensuel, max(0, (int)$dureeMois), $actif ? 1 : 0,
            $plateforme === 'mobile' ? 'mobile' : 'desktop', $essai ? 1 : 0, $code];
        if ($this->forfait($code) !== null) {
            $stmt = $this->db->prepare('UPDATE ss_forfaits SET nom = ?, prix_da = ?, quota_mensuel = ?, duree_mois = ?, actif = ?, plateforme = ?, essai = ? WHERE code = ?');
        } else {
            $stmt = $this->db->prepare('INSERT INTO ss_forfaits (nom, prix_da, quota_mensuel, duree_mois, actif, plateforme, essai, code) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        }
        $stmt->execute($params);
    }

    public function demandes($statut = 'nouvelle')
    {
        $stmt = $this->db->prepare('SELECT d.*, c.nom AS client_nom, c.contact AS client_contact, c.plateforme,
                f.nom AS forfait_nom, f.prix_da, f.duree_mois, f.quota_mensuel
            FROM ss_demandes d LEFT JOIN ss_clients c ON c.id = d.client_id LEFT JOIN ss_forfaits f ON f.code = d.forfait_code
            WHERE d.statut = ? ORDER BY d.id DESC');
        $stmt->execute([$statut]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function marquerDemande($demandeId, $statut)
    {
        $stmt = $this->db->prepare('UPDATE ss_demandes SET statut = ? WHERE id = ?');
        $stmt->execute([$statut === 'traitee' ? 'traitee' : 'refusee', $demandeId]);
    }

    public function consommationParMois($clientId)
    {
        $stmt = $this->db->prepare('SELECT cree_le, statut FROM ss_scans WHERE client_id = ? ORDER BY cree_le DESC');
        $stmt->execute([$clientId]);
        $mois = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $m = substr($s['cree_le'], 0, 7);
            if (!isset($mois[$m])) $mois[$m] = ['mois' => $m, 'reussis' => 0, 'echoues' => 0];
            if ($s['statut'] === self::REUSSI) $mois[$m]['reussis']++;
            if ($s['statut'] === self::ECHOUE) $mois[$m]['echoues']++;
        }
        return array_values($mois);
    }

    public function journal($clientId = null, $erreursSeulement = false, $limite = 200)
    {
        $sql = 'SELECT s.*, c.nom AS client_nom FROM ss_scans s LEFT JOIN ss_clients c ON c.id = s.client_id WHERE 1 = 1';
        $params = [];
        if ($clientId !== null) {
            $sql .= ' AND s.client_id = ?';
            $params[] = $clientId;
        }
        if ($erreursSeulement) {
            $sql .= " AND (s.statut = 'echoue' OR s.erreur_extraction IS NOT NULL)";
        }
        $sql .= ' ORDER BY s.id DESC LIMIT ' . (int)$limite;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
