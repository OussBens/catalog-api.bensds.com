<?php
/**
 * INDEX SIMPLIFIÉ - Sans dépendances externes
 * Version stable pour CaisseDZ Catalog API
 */

// Activer les erreurs
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// ===== CHARGEMENT DU FICHIER .env =====
function loadEnvManually() {
    $envFile = __DIR__ . '/.env';
    if (!file_exists($envFile)) {
        // Créer un .env par défaut si absent
        $defaultEnv = <<<ENV
DB_HOST="localhost"
DB_NAME="bensds64_caissedz_catalogue"
DB_USER="bensds64_oussama"
DB_PASSWORD="VOTRE_MOT_DE_PASSE"
API_URL="https://catalog-api.bensds.com"
API_KEY=""
MISTRAL_API_KEY=""
LICENSE_SECRET=""
FIREBASE_PROJECT_ID=""
SMARTSCAN_CONTACT=""
SMARTSCAN_RATE_PAR_MINUTE="6"
SMARTSCAN_MAX_SIMULTANES="1"
SMARTSCAN_TAILLE_MAX_MO="8"
SMARTSCAN_EXTRACTIONS_PAR_SCAN="6"
SMARTSCAN_PLAFOND_GLOBAL_MENSUEL="0"
SMARTSCAN_HTTPS_OBLIGATOIRE="1"
ADMIN_PASSWORD=""
ENV;
        file_put_contents($envFile, $defaultEnv);
        echo "⚠️ Fichier .env créé par défaut. Modifiez-le avec vos identifiants.<br>";
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, '"\'');
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}
loadEnvManually();

// ===== CONNEXION À LA BASE DE DONNÉES =====
function getDbConnection() {
    try {
        $host = $_ENV['DB_HOST'] ?? 'localhost';
        $dbname = $_ENV['DB_NAME'] ?? '';
        $user = $_ENV['DB_USER'] ?? '';
        $password = $_ENV['DB_PASSWORD'] ?? '';

        $pdo = new PDO(
            "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
            $user,
            $password
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (Exception $e) {
        return null;
    }
}

function upsertLookup($db, $table, $nom) {
    if (empty($nom)) return null;
    $stmt = $db->prepare("SELECT id FROM $table WHERE nom = ?");
    $stmt->execute([$nom]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;
    $stmt = $db->prepare("INSERT INTO $table (nom) VALUES (?)");
    $stmt->execute([$nom]);
    return (int)$db->lastInsertId();
}

// ===== ROUTEUR SIMPLE =====
$request_uri = $_SERVER['REQUEST_URI'];
$script_name = $_SERVER['SCRIPT_NAME'];
$path = str_replace($script_name, '', $request_uri);
$path = $_SERVER['PATH_INFO'] ?? '';
$path_parts = explode('/', trim($path, '/'));

// ⚠️ AJOUT : Définir $method ici pour toutes les routes
$method = $_SERVER['REQUEST_METHOD'];

header('Content-Type: application/json');

// ===== AUTHENTIFICATION DES ÉCRITURES =====
// Les lectures (GET/HEAD/OPTIONS) restent publiques : la recherche par
// code-barres est utilisée par toutes les installations CaisseDZ. Toute
// requête qui modifie des données (POST/PUT/DELETE, upload, import) exige le
// header X-API-Key égal à API_KEY du .env. Si API_KEY n'est pas configurée,
// les écritures sont refusées (fail-closed) plutôt qu'ouvertes à tous.
function requireApiKey() {
    $expected = $_ENV['API_KEY'] ?? '';
    $provided = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($expected === '') {
        throw new Exception('API_KEY non configurée sur le serveur', 503);
    }
    if ($provided === '' || !hash_equals($expected, $provided)) {
        throw new Exception('Clé API invalide ou manquante', 401);
    }
}

// Smart Scan (OCR des bons) : voir smartscan_core/routes.php
require_once __DIR__ . '/smartscan_core/routes.php';
// Rayons proposés à l'accueil de CaisseDZ Desktop : voir catalogue_core/routes.php
require_once __DIR__ . '/catalogue_core/routes.php';

try {
    // Les routes /smartscan/* s'authentifient par la licence Caisse DZ du
    // poste (smartscan_core/routes.php), pas par la clé d'administration du catalogue.
    if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) && ($path_parts[0] ?? '') !== 'smartscan') {
        requireApiKey();
    }

    $db = getDbConnection();
    if (!$db) {
        throw new Exception('Erreur de connexion à la base de données. Vérifiez vos identifiants dans .env');
    }

    // ===== ROUTE PAR DÉFAUT =====
    if (empty($path_parts[0])) {
        echo json_encode([
            'success' => true,
            'message' => 'CaisseDZ Catalog API',
            'version' => '1.0.0',
            'database' => 'Connectée',
            'endpoints' => [
                'GET /' => 'Informations de l\'API',
                'GET /products' => 'Liste des produits',
                'GET /products/{id}' => 'Détail d\'un produit',
                'GET /products/barcode/{code}' => 'Recherche par code-barres',
                'POST /products' => 'Créer un produit',
                'PUT /products/{id}' => 'Mettre à jour un produit',
                'DELETE /products/{id}' => 'Supprimer un produit'
            ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $resource = $path_parts[0];
    $id = $path_parts[1] ?? null;
    $sub = $path_parts[2] ?? null;

    // ==========================================
    // ⚠️ ROUTES INDÉPENDANTES (HORS BLOC PRODUCTS)
    // ==========================================

    // ===== ROUTE UPLOAD PHOTO =====
    if ($resource === 'upload-photo' && $method === 'POST') {
        // Vérifier si un fichier a été envoyé
        if (empty($_FILES['photo'])) {
            throw new Exception('Aucune photo envoyée', 400);
        }

        $file = $_FILES['photo'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Erreur de téléchargement: ' . $file['error'], 400);
        }

        // Vérifier la taille (max 5MB)
        if ($file['size'] > 5 * 1024 * 1024) {
            throw new Exception('Photo trop volumineuse (max 5MB)', 400);
        }

        // Vérifier le type MIME réel
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedTypes)) {
            throw new Exception('Format non supporté. Utilisez JPG, PNG ou WEBP', 400);
        }

        // Générer un nom unique
        // L'extension vient du type réel du fichier, jamais du nom envoyé par le client.
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mimeType];
        $filename = time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;

        // Créer le dossier d'upload
        $uploadDir = __DIR__ . '/uploads/photos/' . date('Y') . '/' . date('m');
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $destination = $uploadDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new Exception('Erreur lors de l\'enregistrement de la photo', 500);
        }

        $apiUrl = rtrim($_ENV['API_URL'] ?? 'https://catalog-api.bensds.com', '/');
        $photoUrl = $apiUrl . '/uploads/photos/' . date('Y') . '/' . date('m') . '/' . $filename;

        echo json_encode([
            'success' => true,
            'data' => [
                'photo_url' => $photoUrl,
                'filename' => $filename
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ===== SMART SCAN (OCR des bons, quotas par client) =====
    if ($resource === 'smartscan') {
        smartscanRoute($db, $id, $method);
        exit;
    }

    // ===== RAYONS (sections du catalogue, 2 000 produits les mieux classés chacune) =====
    if ($resource === 'rayons') {
        rayonsRoute($db, $id, $sub, $method);
        exit;
    }

    // ===== ROUTE PRODUITS =====
    if ($resource === 'products') {
        // GET - Récupérer des produits
        if ($method === 'GET') {
            // Récupérer un produit par ID
            if ($id && is_numeric($id)) {
                // ✅ JOIN vers catalog_brand/catalog_category pour renvoyer
                // les NOMS (brand/category) et pas seulement brand_id/category_id.
                $stmt = $db->prepare("
                    SELECT p.*, br.nom AS brand, cat.nom AS category
                    FROM catalog_product p
                    LEFT JOIN catalog_brand br ON p.brand_id = br.id
                    LEFT JOIN catalog_category cat ON p.category_id = cat.id
                    WHERE p.id = ?
                ");
                $stmt->execute([$id]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$product) {
                    throw new Exception('Produit non trouvé', 404);
                }

                // Récupérer les codes-barres
                $stmt = $db->prepare("SELECT barcode FROM catalog_product_barcode WHERE product_id = ?");
                $stmt->execute([$id]);
                $barcodes = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $product['barcodes'] = $barcodes;

                echo json_encode(['success' => true, 'data' => $product], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // Recherche par code-barres
            if ($id === 'barcode' && $sub) {
                // ✅ Même JOIN ici : c'est cette route qu'utilise la cascade
                // de recherche IA (CatalogService.lookupByBarcode côté Flutter).
                $stmt = $db->prepare("
                    SELECT p.*, br.nom AS brand, cat.nom AS category
                    FROM catalog_product p
                    JOIN catalog_product_barcode b ON p.id = b.product_id
                    LEFT JOIN catalog_brand br ON p.brand_id = br.id
                    LEFT JOIN catalog_category cat ON p.category_id = cat.id
                    WHERE b.barcode = ?
                ");
                $stmt->execute([$sub]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$product) {
                    echo json_encode(['success' => false, 'error' => 'Produit non trouvé'], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                // Chaque recherche fait monter le produit dans le classement de son rayon.
                Rayons::compterConsultation($db, $product['id']);

                echo json_encode(['success' => true, 'data' => $product], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // Liste des produits
            $limit = 50;
            $page = (int)($_GET['page'] ?? 1);
            $offset = ($page - 1) * $limit;

            // Force les paramètres à être des entiers
            $limit = (int)$limit;
            $offset = (int)$offset;

            $stmt = $db->prepare("
                SELECT SQL_CALC_FOUND_ROWS * FROM catalog_product
                ORDER BY id DESC LIMIT :limit OFFSET :offset
            ");
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $db->query("SELECT FOUND_ROWS()");
            $total = $stmt->fetchColumn();

            echo json_encode([
                'success' => true,
                'data' => [
                    'products' => $products,
                    'total' => (int)$total,
                    'page' => $page,
                    'limit' => $limit,
                    'total_pages' => ceil($total / $limit)
                ]
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // POST /products/{id}/photo - upload de la photo principale
        if ($method === 'POST' && $id && is_numeric($id) && $sub === 'photo') {
            if (empty($_FILES['photo'])) {
                throw new Exception('Aucun fichier photo reçu (champ "photo" attendu)', 400);
            }

            $file = $_FILES['photo'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Erreur de téléversement (code ' . $file['error'] . ')', 400);
            }

            $maxSize = 5 * 1024 * 1024; // 5 Mo
            if ($file['size'] > $maxSize) {
                throw new Exception('Photo trop volumineuse (max 5 Mo)', 400);
            }

            // Valide le vrai contenu du fichier (pas juste l'extension/mime déclaré par le client)
            $imageInfo = @getimagesize($file['tmp_name']);
            if ($imageInfo === false) {
                throw new Exception('Le fichier envoyé n\'est pas une image valide', 400);
            }

            $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($allowedMimes[$imageInfo['mime']])) {
                throw new Exception('Format d\'image non supporté (jpg, png, webp uniquement)', 400);
            }
            $ext = $allowedMimes[$imageInfo['mime']];

            $stmt = $db->prepare("SELECT code_produit, photo FROM catalog_product WHERE id = ?");
            $stmt->execute([$id]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$product) {
                throw new Exception('Produit non trouvé', 404);
            }

            $uploadsDir = __DIR__ . '/uploads/photos';
            if (!is_dir($uploadsDir)) {
                mkdir($uploadsDir, 0755, true);
            }

            // Supprime l'ancienne photo si elle vient de notre dossier uploads
            if (!empty($product['photo']) && strpos($product['photo'], '/uploads/photos/') !== false) {
                $oldPath = $uploadsDir . '/' . basename($product['photo']);
                if (is_file($oldPath)) {
                    @unlink($oldPath);
                }
            }

            $fileName = $product['code_produit'] . '_' . time() . '.' . $ext;
            $destination = $uploadsDir . '/' . $fileName;

            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                throw new Exception('Échec de l\'enregistrement de la photo sur le serveur', 500);
            }

            $apiUrl = rtrim($_ENV['API_URL'] ?? 'https://catalog-api.bensds.com', '/');
            $photoUrl = $apiUrl . '/uploads/photos/' . $fileName;

            $stmt = $db->prepare("UPDATE catalog_product SET photo = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$photoUrl, $id]);

            echo json_encode(['success' => true, 'data' => ['photo' => $photoUrl]], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // POST - Créer un produit
        if ($method === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true);

            // ✅ Accepte soit 'barcode' (un seul code, rétrocompatible avec
            // l'appli mobile), soit 'barcodes' (tableau, produit multicode
            // envoyé par CaisseDZ desktop). Normalisé en une liste unique de
            // codes non vides, dans l'ordre reçu (le premier sera primaire).
            $barcodesInput = $data['barcodes'] ?? (isset($data['barcode']) ? [$data['barcode']] : []);
            $barcodesInput = array_values(array_unique(array_filter(array_map('trim', (array)$barcodesInput))));

            if (empty($data['nom']) || empty($data['code_produit']) || empty($barcodesInput)) {
                throw new Exception('Nom, code produit et au moins un code-barres sont requis', 400);
            }

            // ✅ Vérifie l'unicité de TOUS les codes soumis, à la fois dans
            // catalog_product_barcode (table normale des codes-barres) ET
            // dans catalog_product.code_produit (qui vaut désormais le
            // code-barres principal pour les produits créés via cette route
            // — cf. CatalogSyncService côté Flutter). Aucun enregistrement
            // n'est effectué si UN SEUL des codes est déjà utilisé, où que
            // ce soit — pas seulement le premier.
            $placeholders = implode(',', array_fill(0, count($barcodesInput), '?'));

            $stmt = $db->prepare("SELECT barcode FROM catalog_product_barcode WHERE barcode IN ($placeholders)");
            $stmt->execute($barcodesInput);
            $conflitsBarcode = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $stmt = $db->prepare("SELECT code_produit FROM catalog_product WHERE code_produit IN ($placeholders)");
            $stmt->execute($barcodesInput);
            $conflitsCodeProduit = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $conflits = array_values(array_unique(array_merge($conflitsBarcode, $conflitsCodeProduit)));
            if (!empty($conflits)) {
                throw new Exception('Code(s)-barres déjà utilisé(s): ' . implode(', ', $conflits), 409);
            }

            $brandId = upsertLookup($db, 'catalog_brand', $data['brand'] ?? null);
            $categoryId = upsertLookup($db, 'catalog_category', $data['category'] ?? null);

            $db->beginTransaction();
            try {
                $stmt = $db->prepare("
                    INSERT INTO catalog_product (code_produit, nom, description, brand_id, category_id, couleur, taille, photo, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $data['code_produit'],
                    $data['nom'],
                    $data['description'] ?? null,
                    $brandId,
                    $categoryId,
                    $data['couleur'] ?? null,
                    $data['taille'] ?? null,
                    $data['photo'] ?? null,
                ]);
                $productId = $db->lastInsertId();

                // ✅ Un INSERT par code-barres, le premier marqué is_primary.
                $stmt = $db->prepare("
                    INSERT INTO catalog_product_barcode (product_id, barcode, is_primary)
                    VALUES (?, ?, ?)
                ");
                foreach ($barcodesInput as $i => $code) {
                    $stmt->execute([$productId, $code, $i === 0 ? 1 : 0]);
                }

                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }

            echo json_encode([
                'success' => true,
                'data' => ['id' => (int)$productId, 'message' => 'Produit créé avec succès']
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // PUT - Mettre à jour
        if ($method === 'PUT') {
            if (!$id || !is_numeric($id)) {
                throw new Exception('ID du produit requis', 400);
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (empty($data['nom'])) {
                throw new Exception('Nom du produit requis', 400);
            }

            $brandId = upsertLookup($db, 'catalog_brand', $data['brand'] ?? null);
            $categoryId = upsertLookup($db, 'catalog_category', $data['category'] ?? null);

            $stmt = $db->prepare("
                UPDATE catalog_product
                SET nom = ?, description = ?, brand_id = ?, category_id = ?, couleur = ?, taille = ?, photo = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $data['nom'],
                $data['description'] ?? null,
                $brandId,
                $categoryId,
                $data['couleur'] ?? null,
                $data['taille'] ?? null,
                $data['photo'] ?? null,
                $id
            ]);

            echo json_encode([
                'success' => true,
                'data' => ['message' => 'Produit mis à jour avec succès']
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // DELETE - Supprimer
        if ($method === 'DELETE') {
            if (!$id || !is_numeric($id)) {
                throw new Exception('ID du produit requis', 400);
            }

            $stmt = $db->prepare("DELETE FROM catalog_product WHERE id = ?");
            $stmt->execute([$id]);

            echo json_encode([
                'success' => true,
                'data' => ['message' => 'Produit supprimé avec succès']
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        throw new Exception('Méthode non autorisée', 405);
    }

    // ===== ROUTE PRODUITS - VERSION MOBILE =====
    if ($resource === 'mobile-products' && $method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);

        // Champs requis
        if (empty($data['nom']) || empty($data['barcode']) || empty($data['code_produit'])) {
            throw new Exception('Nom, code-barres et code produit sont requis', 400);
        }

        // Vérifier les doublons
        $stmt = $db->prepare("SELECT id FROM catalog_product WHERE code_produit = ?");
        $stmt->execute([$data['code_produit']]);
        if ($stmt->fetch()) {
            throw new Exception('Ce code produit existe déjà', 409);
        }

        $stmt = $db->prepare("SELECT id FROM catalog_product_barcode WHERE barcode = ?");
        $stmt->execute([$data['barcode']]);
        if ($stmt->fetch()) {
            throw new Exception('Ce code-barres existe déjà', 409);
        }

        // Source_id = 1 pour CaisseDZ
        $source_id = 1;

        // Gérer la marque et catégorie
        $brandId = upsertLookup($db, 'catalog_brand', $data['brand'] ?? null);
        $categoryId = upsertLookup($db, 'catalog_category', $data['category'] ?? null);

        // Insérer le produit
        $stmt = $db->prepare("
            INSERT INTO catalog_product
            (code_produit, nom, description, brand_id, category_id, couleur, taille, photo, source_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $data['code_produit'],
            $data['nom'],
            $data['description'] ?? null,
            $brandId,
            $categoryId,
            $data['couleur'] ?? null,
            $data['taille'] ?? null,
            $data['photo'] ?? null,
            $source_id
        ]);
        $productId = $db->lastInsertId();

        // Insérer le code-barres
        $stmt = $db->prepare("
            INSERT INTO catalog_product_barcode (product_id, barcode, is_primary, source_id)
            VALUES (?, ?, 1, ?)
        ");
        $stmt->execute([$productId, $data['barcode'], $source_id]);

        // Log de l'opération
        $stmt = $db->prepare("
            INSERT INTO catalog_sync_log (product_id, action, source_id, success, message, created_at)
            VALUES (?, 'CREATE', ?, 1, 'Produit créé depuis l\'application mobile', NOW())
        ");
        $stmt->execute([$productId, $source_id]);

        // Récupérer le produit créé
        $stmt = $db->prepare("
            SELECT p.*, b.nom as brand_name, c.nom as category_name, s.nom as source_name
            FROM catalog_product p
            LEFT JOIN catalog_brand b ON p.brand_id = b.id
            LEFT JOIN catalog_category c ON p.category_id = c.id
            LEFT JOIN catalog_source s ON p.source_id = s.id
            WHERE p.id = ?
        ");
        $stmt->execute([$productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => [
                'product' => $product,
                'message' => 'Produit créé avec succès !'
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ===== ROUTE IMPORT CSV =====
    if ($resource === 'import' && $method === 'POST') {
        // Vérifier si un fichier a été uploadé
        if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Veuillez uploader un fichier CSV valide', 400);
        }

        $file = $_FILES['csv_file'];
        $csvContent = file_get_contents($file['tmp_name']);
        $lines = explode("\n", trim($csvContent));
        $headers = str_getcsv(array_shift($lines));

        // Vérifier les en-têtes obligatoires
        $required = ['code_produit', 'nom', 'barcode'];
        foreach ($required as $req) {
            if (!in_array($req, $headers)) {
                throw new Exception("Colonne '$req' manquante dans le CSV", 400);
            }
        }

        $results = [
            'imported' => 0,
            'skipped' => 0,
            'errors' => []
        ];

        $db->beginTransaction();

        foreach ($lines as $line) {
            if (empty(trim($line))) continue;

            try {
                $data = array_combine($headers, str_getcsv($line));

                // Vérifier les doublons
                $stmt = $db->prepare("SELECT id FROM catalog_product WHERE code_produit = ?");
                $stmt->execute([$data['code_produit']]);
                if ($stmt->fetch()) {
                    $results['skipped']++;
                    continue;
                }

                $stmt = $db->prepare("SELECT id FROM catalog_product_barcode WHERE barcode = ?");
                $stmt->execute([$data['barcode']]);
                if ($stmt->fetch()) {
                    $results['skipped']++;
                    continue;
                }

                // Utiliser votre fonction upsertLookup existante
                $brandId = upsertLookup($db, 'catalog_brand', $data['brand'] ?? null);
                $categoryId = upsertLookup($db, 'catalog_category', $data['category'] ?? null);

                // Insérer le produit
                $stmt = $db->prepare("
                    INSERT INTO catalog_product (code_produit, nom, description, brand_id, category_id, created_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $data['code_produit'],
                    $data['nom'],
                    $data['description'] ?? null,
                    $brandId,
                    $categoryId
                ]);
                $productId = $db->lastInsertId();

                // Insérer le code-barres
                $stmt = $db->prepare("
                    INSERT INTO catalog_product_barcode (product_id, barcode, is_primary)
                    VALUES (?, ?, 1)
                ");
                $stmt->execute([$productId, $data['barcode']]);

                $results['imported']++;

            } catch (Exception $e) {
                $results['errors'][] = [
                    'product' => $data['code_produit'] ?? 'unknown',
                    'error' => $e->getMessage()
                ];
            }
        }

        $db->commit();

        echo json_encode(['success' => true, 'data' => $results], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ===== ROUTE MARQUES =====
    if ($resource === 'brands') {
        $stmt = $db->query("SELECT * FROM catalog_brand ORDER BY nom");
        $brands = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $brands], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ===== ROUTE CATÉGORIES =====
    if ($resource === 'categories') {
        $stmt = $db->query("SELECT * FROM catalog_category ORDER BY nom");
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $categories], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ⚠️ Si aucune route n'est trouvée
    throw new Exception('Ressource non trouvée', 404);

} catch (Exception $e) {
    $code = $e->getCode() ?: 500;
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'code' => $code
    ], JSON_UNESCAPED_UNICODE);
}
