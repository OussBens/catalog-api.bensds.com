<?php
/**
 * Routes Smart Scan, incluses par index.php (même base, même .env).
 *
 *   GET  /smartscan/offres?plateforme=mobile  offres publiques (sans authentification)
 *   GET  /smartscan/quota    état du quota du client (forfait, utilisés…)
 *   POST /smartscan/scan     OCR d'un bon {image: data URL, …}
 *   POST /smartscan/extract  analyse des lignes {scan_id, model, messages…}
 *   POST /smartscan/demande  demande d'offre {offre: "MOBILE_12M"}
 *   POST /smartscan/supprimer-compte  suppression du compte mobile (jeton Firebase)
 *
 * Authentification :
 *   desktop  X-Device-Id + X-License-Key (licence Caisse DZ du poste) ;
 *   mobile   X-Firebase-Token (jeton d'identité Firebase, connexion par SMS).
 * Nom de l'entreprise (facultatif) : X-Entreprise, encodé URL.
 */

require_once __DIR__ . '/SmartScanService.php';
require_once __DIR__ . '/SmartScanSchema.php';
require_once __DIR__ . '/FirebaseToken.php';

function smartscanEnv($cle, $defaut)
{
    $v = $_ENV[$cle] ?? '';
    return $v === '' ? $defaut : $v;
}

/** Service configuré depuis .env — partagé par les routes et l'administration. */
function smartscanService(PDO $db)
{
    $transport = function ($chemin, array $payload) {
        $cle = $_ENV['MISTRAL_API_KEY'] ?? '';
        if ($cle === '') {
            throw new SmartScanException(503, 'SERVER_NOT_CONFIGURED', 'Service Smart Scan non configuré.');
        }
        // SMARTSCAN_MISTRAL_URL (facultatif) : autre point d'accès, ex. préproduction / tests.
        $ch = curl_init(rtrim(smartscanEnv('SMARTSCAN_MISTRAL_URL', 'https://api.mistral.ai'), '/') . $chemin);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $cle],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $corps = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erreur = curl_error($ch);
        curl_close($ch);
        // Code 0 = réseau / timeout : traité comme un échec non décompté.
        return $corps === false ? [0, $erreur] : [$code, $corps];
    };

    return new SmartScanService($db, [
        'secret_licence' => $_ENV['LICENSE_SECRET'] ?? '',
        'firebase' => new FirebaseToken($_ENV['FIREBASE_PROJECT_ID'] ?? ''),
        'rate_par_minute' => (int)smartscanEnv('SMARTSCAN_RATE_PAR_MINUTE', 6),
        'max_simultanes' => (int)smartscanEnv('SMARTSCAN_MAX_SIMULTANES', 1),
        'taille_max_octets' => (int)((float)smartscanEnv('SMARTSCAN_TAILLE_MAX_MO', 8) * 1024 * 1024),
        'extractions_par_scan' => (int)smartscanEnv('SMARTSCAN_EXTRACTIONS_PAR_SCAN', 6),
        'plafond_global_mensuel' => (int)smartscanEnv('SMARTSCAN_PLAFOND_GLOBAL_MENSUEL', 0),
        'contact' => smartscanEnv('SMARTSCAN_CONTACT', ''),
    ], $transport);
}

function smartscanEstHttps()
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function smartscanRepondre($code, array $donnees)
{
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($donnees, JSON_UNESCAPED_UNICODE);
}

function smartscanRoute(PDO $db, $action, $method)
{
    $service = smartscanService($db);
    $debut = microtime(true);
    try {
        if (smartscanEnv('SMARTSCAN_HTTPS_OBLIGATOIRE', '1') !== '0' && !smartscanEstHttps()) {
            throw new SmartScanException(403, 'HTTPS_REQUIRED', 'Connexion sécurisée (HTTPS) obligatoire.');
        }

        if ($action === 'offres' && $method === 'GET') {
            $plateforme = ($_GET['plateforme'] ?? '') === 'mobile' ? 'mobile' : 'desktop';
            smartscanRepondre(200, ['success' => true, 'offers' => $service->offres($plateforme)]);
            return;
        }

        // Suppression du compte mobile : authentifiée par le jeton, sans (re)créer de poste.
        if ($action === 'supprimer-compte' && $method === 'POST') {
            smartscanRepondre(200, $service->supprimerCompteMobile($_SERVER['HTTP_X_FIREBASE_TOKEN'] ?? ''));
            return;
        }

        $entreprise = isset($_SERVER['HTTP_X_ENTREPRISE']) ? rawurldecode($_SERVER['HTTP_X_ENTREPRISE']) : null;
        $poste = !empty($_SERVER['HTTP_X_FIREBASE_TOKEN'])
            ? $service->authentifierMobile($_SERVER['HTTP_X_FIREBASE_TOKEN'], $entreprise)
            : $service->authentifier($_SERVER['HTTP_X_DEVICE_ID'] ?? '', $_SERVER['HTTP_X_LICENSE_KEY'] ?? '', $entreprise);

        if ($action === 'quota' && $method === 'GET') {
            smartscanRepondre(200, ['success' => true, 'quota' => $service->quota((int)$poste['client_id'])]);
            return;
        }
        if ($method !== 'POST') {
            throw new SmartScanException(404, 'NOT_FOUND', 'Ressource Smart Scan inconnue.');
        }

        // Taille annoncée contrôlée avant de lire le corps (image en base64 ≈ +35 %).
        $maxCorps = (int)((float)smartscanEnv('SMARTSCAN_TAILLE_MAX_MO', 8) * 1024 * 1024 * 1.4) + 1048576;
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxCorps) {
            throw new SmartScanException(413, 'IMAGE_TOO_LARGE', 'Image trop volumineuse.');
        }
        $corps = json_decode(file_get_contents('php://input'), true);
        if (!is_array($corps)) {
            throw new SmartScanException(400, 'INVALID_REQUEST', 'Requête invalide.');
        }

        if ($action === 'scan') {
            smartscanRepondre(200, $service->scanner($poste, $corps['image'] ?? null));
            return;
        }
        if ($action === 'extract') {
            list($code, $reponse) = $service->extraire($poste, (int)($corps['scan_id'] ?? 0), $corps);
            http_response_code($code);
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
            echo $reponse;
            return;
        }
        if ($action === 'demande') {
            smartscanRepondre(200, $service->demanderOffre($poste, $corps['offre'] ?? ''));
            return;
        }
        throw new SmartScanException(404, 'NOT_FOUND', 'Ressource Smart Scan inconnue.');
    } catch (SmartScanException $e) {
        if ($e->httpCode >= 500) {
            error_log('[smartscan] ' . $e->codeErreur . ' ' . $e->getMessage());
        }
        smartscanRepondre($e->httpCode, $e->versReponse());
    } catch (Exception $e) {
        // Détail technique dans les logs serveur uniquement.
        error_log('[smartscan] ' . get_class($e) . ': ' . $e->getMessage() . ' (' . round((microtime(true) - $debut) * 1000) . ' ms)');
        smartscanRepondre(500, ['success' => false, 'error' => 'SERVER_ERROR', 'message' => 'Erreur interne du service Smart Scan.']);
    }
}
