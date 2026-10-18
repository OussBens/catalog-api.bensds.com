<?php
namespace CaisseDZ\Catalog\Models;

use CaisseDZ\Catalog\Config\Database;
use PDO;

class Product {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function findById($id) {
        $stmt = $this->db->prepare("
            SELECT p.*, 
                   b.nom as brand_name, 
                   c.nom as category_name,
                   s.nom as source_name
            FROM catalog_product p
            LEFT JOIN catalog_brand b ON p.brand_id = b.id
            LEFT JOIN catalog_category c ON p.category_id = c.id
            LEFT JOIN catalog_source s ON p.source_id = s.id
            WHERE p.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function findByBarcode($barcode) {
        $stmt = $this->db->prepare("
            SELECT p.*, 
                   b.nom as brand_name, 
                   c.nom as category_name,
                   GROUP_CONCAT(DISTINCT cb.barcode) as barcodes
            FROM catalog_product p
            LEFT JOIN catalog_brand b ON p.brand_id = b.id
            LEFT JOIN catalog_category c ON p.category_id = c.id
            LEFT JOIN catalog_product_barcode cb ON p.id = cb.product_id
            WHERE cb.barcode = ?
            GROUP BY p.id
        ");
        $stmt->execute([$barcode]);
        return $stmt->fetch();
    }

    public function search($query, $filters = [], $page = 1, $limit = 20) {
        $offset = ($page - 1) * $limit;
        $params = [];
        $where = [];

        $sql = "SELECT SQL_CALC_FOUND_ROWS p.*, 
                       b.nom as brand_name, 
                       c.nom as category_name,
                       GROUP_CONCAT(DISTINCT cb.barcode) as barcodes
                FROM catalog_product p
                LEFT JOIN catalog_brand b ON p.brand_id = b.id
                LEFT JOIN catalog_category c ON p.category_id = c.id
                LEFT JOIN catalog_product_barcode cb ON p.id = cb.product_id
                WHERE 1=1";

        if (!empty($query)) {
            $sql .= " AND (p.nom LIKE ? OR p.description LIKE ? OR cb.barcode LIKE ?)";
            $searchTerm = "%$query%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        if (!empty($filters['brand_id'])) {
            $sql .= " AND p.brand_id = ?";
            $params[] = $filters['brand_id'];
        }

        if (!empty($filters['category_id'])) {
            $sql .= " AND p.category_id = ?";
            $params[] = $filters['category_id'];
        }

        $sql .= " GROUP BY p.id";
        $sql .= " ORDER BY p.created_at DESC";
        $sql .= " LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll();

        // Get total count
        $countStmt = $this->db->query("SELECT FOUND_ROWS()");
        $total = $countStmt->fetchColumn();

        return [
            'data' => $products,
            'total' => (int)$total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => ceil($total / $limit)
        ];
    }

    public function create($data) {
        $sql = "INSERT INTO catalog_product 
                (code_produit, nom, description, brand_id, category_id, 
                 couleur, taille, photo, source_id) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $data['code_produit'],
            $data['nom'],
            $data['description'] ?? null,
            $data['brand_id'] ?? null,
            $data['category_id'] ?? null,
            $data['couleur'] ?? null,
            $data['taille'] ?? null,
            $data['photo'] ?? null,
            $data['source_id'] ?? 1 // CAISSEDZ par défaut
        ]);

        $id = $this->db->lastInsertId();

        // Ajouter le code-barres
        if (!empty($data['barcode'])) {
            $this->addBarcode($id, $data['barcode'], true);
        }

        return $id;
    }

    public function update($id, $data) {
        $sql = "UPDATE catalog_product SET 
                nom = ?, description = ?, brand_id = ?, 
                category_id = ?, couleur = ?, taille = ?, 
                photo = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            $data['nom'],
            $data['description'] ?? null,
            $data['brand_id'] ?? null,
            $data['category_id'] ?? null,
            $data['couleur'] ?? null,
            $data['taille'] ?? null,
            $data['photo'] ?? null,
            $id
        ]);
    }


    public function delete($id) {
        $stmt = $this->db->prepare("DELETE FROM catalog_product WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
     public function createWithUpsert($data) {
        try {
            $this->db->beginTransaction();

            // Traiter la marque
            $brandId = null;
            if (!empty($data['brand'])) {
                $brandId = $this->brandModel->findOrCreate($data['brand']);
            }

            // Traiter la catégorie
            $categoryId = null;
            if (!empty($data['category'])) {
                // Vérifier si c'est un chemin hiérarchique
                if (strpos($data['category'], '>') !== false) {
                    $categoryId = $this->categoryModel->findOrCreatePath($data['category']);
                } else {
                    $categoryId = $this->categoryModel->findOrCreate($data['category']);
                }
            }

            // Créer le produit
            $sql = "INSERT INTO catalog_product 
                    (code_produit, nom, description, brand_id, category_id, 
                     couleur, taille, photo, source_id) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $data['code_produit'],
                $data['nom'],
                $data['description'] ?? null,
                $brandId,
                $categoryId,
                $data['couleur'] ?? null,
                $data['taille'] ?? null,
                $data['photo'] ?? null,
                $data['source_id'] ?? 1 // CAISSEDZ par défaut
            ]);

            $productId = $this->db->lastInsertId();

            // Ajouter le code-barres
            if (!empty($data['barcode'])) {
                $this->addBarcode($productId, $data['barcode'], true);
            }

            // Ajouter d'autres codes-barres si présents
            if (!empty($data['barcodes']) && is_array($data['barcodes'])) {
                foreach ($data['barcodes'] as $barcode) {
                    if (!empty($barcode)) {
                        $this->addBarcode($productId, $barcode, false);
                    }
                }
            }

            $this->db->commit();

            // Log de l'opération
            $this->logSync($productId, 'CREATE', true, 'Produit créé avec upsert');

            return $productId;

        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Met à jour un produit avec upsert automatique
     */
    public function updateWithUpsert($id, $data) {
        try {
            $this->db->beginTransaction();

            // Traiter la marque
            $brandId = null;
            if (!empty($data['brand'])) {
                $brandId = $this->brandModel->findOrCreate($data['brand']);
            } elseif (isset($data['brand']) && $data['brand'] === null) {
                $brandId = null;
            }

            // Traiter la catégorie
            $categoryId = null;
            if (!empty($data['category'])) {
                if (strpos($data['category'], '>') !== false) {
                    $categoryId = $this->categoryModel->findOrCreatePath($data['category']);
                } else {
                    $categoryId = $this->categoryModel->findOrCreate($data['category']);
                }
            } elseif (isset($data['category']) && $data['category'] === null) {
                $categoryId = null;
            }

            // Mettre à jour le produit
            $sql = "UPDATE catalog_product SET 
                    nom = ?, 
                    description = ?, 
                    brand_id = ?, 
                    category_id = ?, 
                    couleur = ?, 
                    taille = ?, 
                    photo = ?,
                    updated_at = NOW()
                    WHERE id = ?";
            
            $stmt = $this->db->prepare($sql);
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

            // Ajouter de nouveaux codes-barres si présents
            if (!empty($data['new_barcodes']) && is_array($data['new_barcodes'])) {
                foreach ($data['new_barcodes'] as $barcode) {
                    if (!empty($barcode)) {
                        $this->addBarcode($id, $barcode, false);
                    }
                }
            }

            $this->db->commit();

            // Log de l'opération
            $this->logSync($id, 'UPDATE', true, 'Produit mis à jour avec upsert');

            return true;

        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Ajoute un code-barres à un produit
     */
    private function addBarcode($productId, $barcode, $isPrimary = false) {
        // Vérifier si le code-barres existe déjà
        $stmt = $this->db->prepare("SELECT id FROM catalog_product_barcode WHERE barcode = ?");
        $stmt->execute([$barcode]);
        if ($stmt->fetch()) {
            return false; // Code-barres déjà existant
        }

        $sql = "INSERT INTO catalog_product_barcode 
                (product_id, barcode, is_primary, source_id) 
                VALUES (?, ?, ?, 1)";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$productId, $barcode, $isPrimary ? 1 : 0]);
    }

    /**
     * Log les opérations de synchronisation
     */
    private function logSync($productId, $action, $success, $message) {
        $sql = "INSERT INTO catalog_sync_log 
                (product_id, action, source_id, success, message) 
                VALUES (?, ?, 1, ?, ?)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$productId, $action, $success ? 1 : 0, $message]);
    }
    
    
}