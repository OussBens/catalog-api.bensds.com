<?php
namespace CaisseDZ\Catalog\Models;

use CaisseDZ\Catalog\Config\Database;

class Category {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function findAll($page = 1, $limit = 50) {
        $offset = ($page - 1) * $limit;
        $stmt = $this->db->prepare("
            SELECT SQL_CALC_FOUND_ROWS 
                   c1.*, c2.nom as parent_name 
            FROM catalog_category c1
            LEFT JOIN catalog_category c2 ON c1.parent_id = c2.id
            ORDER BY c1.nom ASC LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        $categories = $stmt->fetchAll();

        $countStmt = $this->db->query("SELECT FOUND_ROWS()");
        $total = $countStmt->fetchColumn();

        return [
            'data' => $categories,
            'total' => (int)$total,
            'page' => $page,
            'limit' => $limit
        ];
    }

    public function getTree() {
        $stmt = $this->db->query("
            SELECT * FROM catalog_category ORDER BY parent_id, nom
        ");
        $categories = $stmt->fetchAll();

        $tree = [];
        foreach ($categories as $cat) {
            if ($cat['parent_id'] === null) {
                $tree[$cat['id']] = $cat;
                $tree[$cat['id']]['children'] = [];
            }
        }
        foreach ($categories as $cat) {
            if ($cat['parent_id'] !== null && isset($tree[$cat['parent_id']])) {
                $tree[$cat['parent_id']]['children'][] = $cat;
            }
        }
        return array_values($tree);
    }
    
     public function findOrCreate($name, $parentId = null) {
        if (empty($name) || strlen(trim($name)) < 2) {
            return null;
        }

        // Nettoyer et normaliser le nom
        $name = $this->normalizeName($name);
        
        // Vérifier si la catégorie existe déjà
        $sql = "SELECT id FROM catalog_category WHERE nom = ?";
        $params = [$name];
        
        if ($parentId !== null) {
            $sql .= " AND parent_id = ?";
            $params[] = $parentId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        
        if ($result) {
            return (int)$result['id'];
        }

        // Créer la catégorie
        $stmt = $this->db->prepare("
            INSERT INTO catalog_category (nom, parent_id, created_at) 
            VALUES (?, ?, NOW())
        ");
        $stmt->execute([$name, $parentId]);
        
        return (int)$this->db->lastInsertId();
    }

    /**
     * Normalise le nom d'une catégorie
     */
    private function normalizeName($name) {
        $name = trim($name);
        $name = preg_replace('/\s+/', ' ', $name);
        $name = ucwords(strtolower($name));
        $name = preg_replace('/[^a-zA-Z0-9\s\-\.\'\&]/', '', $name);
        return substr($name, 0, 150);
    }

    /**
     * Traite une chaîne de catégories hiérarchiques (ex: "Électronique > Téléphones > Smartphones")
     * @param string $path Chemin des catégories séparé par >
     * @return int ID de la dernière catégorie
     */
    public function findOrCreatePath($path) {
        if (empty($path)) {
            return null;
        }

        $categories = array_map('trim', explode('>', $path));
        $parentId = null;
        $lastId = null;

        foreach ($categories as $categoryName) {
            if (empty($categoryName)) continue;
            $id = $this->findOrCreate($categoryName, $parentId);
            if ($id) {
                $parentId = $id;
                $lastId = $id;
            }
        }

        return $lastId;
    }
    
    
}