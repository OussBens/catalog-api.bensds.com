<?php
namespace CaisseDZ\Catalog\Models;

use CaisseDZ\Catalog\Config\Database;

class Brand {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function findAll($page = 1, $limit = 50) {
        $offset = ($page - 1) * $limit;
        $stmt = $this->db->prepare("
            SELECT SQL_CALC_FOUND_ROWS * FROM catalog_brand 
            ORDER BY nom ASC LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        $brands = $stmt->fetchAll();

        $countStmt = $this->db->query("SELECT FOUND_ROWS()");
        $total = $countStmt->fetchColumn();

        return [
            'data' => $brands,
            'total' => (int)$total,
            'page' => $page,
            'limit' => $limit
        ];
    }

    public function findById($id) {
        $stmt = $this->db->prepare("SELECT * FROM catalog_brand WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function findByName($name) {
        $stmt = $this->db->prepare("SELECT * FROM catalog_brand WHERE nom LIKE ?");
        $stmt->execute(["%$name%"]);
        return $stmt->fetchAll();
    }

    public function create($data) {
        $stmt = $this->db->prepare("
            INSERT INTO catalog_brand (nom, logo, pays, description) 
            VALUES (?, ?, ?, ?)
        ");
        return $stmt->execute([
            $data['nom'],
            $data['logo'] ?? null,
            $data['pays'] ?? null,
            $data['description'] ?? null
        ]);
    }
    
    public function findOrCreate($name) {
        if (empty($name) || strlen(trim($name)) < 2) {
            return null;
        }

        // Nettoyer et normaliser le nom
        $name = $this->normalizeName($name);
        
        // Vérifier si la marque existe déjà
        $stmt = $this->db->prepare("SELECT id FROM catalog_brand WHERE nom = ?");
        $stmt->execute([$name]);
        $result = $stmt->fetch();
        
        if ($result) {
            return (int)$result['id'];
        }

        // Créer la marque
        $stmt = $this->db->prepare("
            INSERT INTO catalog_brand (nom, created_at) 
            VALUES (?, NOW())
        ");
        $stmt->execute([$name]);
        
        return (int)$this->db->lastInsertId();
    }

    /**
     * Normalise le nom d'une marque
     */
    private function normalizeName($name) {
        $name = trim($name);
        $name = preg_replace('/\s+/', ' ', $name); // Supprimer les espaces multiples
        $name = ucwords(strtolower($name)); // Mettre en majuscule chaque mot
        $name = preg_replace('/[^a-zA-Z0-9\s\-\.\']/', '', $name); // Filtrer caractères spéciaux
        return substr($name, 0, 150); // Limiter la longueur
    }
}