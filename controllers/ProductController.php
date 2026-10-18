<?php
namespace CaisseDZ\Catalog\Controllers;

use CaisseDZ\Catalog\Models\Product;

class ProductController {
    private $product;

    public function __construct() {
        $this->product = new Product();
    }

    public function getByBarcode($barcode) {
        if (empty($barcode)) {
            return $this->error('Code-barres requis', 400);
        }

        $product = $this->product->findByBarcode($barcode);
        
        if (!$product) {
            return $this->error('Produit non trouvé', 404);
        }

        return $this->success($product);
    }

    public function getById($id) {
        $product = $this->product->findById($id);
        
        if (!$product) {
            return $this->error('Produit non trouvé', 404);
        }

        return $this->success($product);
    }

    public function search() {
        $query = $_GET['q'] ?? '';
        $page = (int)($_GET['page'] ?? 1);
        $limit = min((int)($_GET['limit'] ?? 20), 100);
        
        $filters = [];
        if (!empty($_GET['brand_id'])) $filters['brand_id'] = (int)$_GET['brand_id'];
        if (!empty($_GET['category_id'])) $filters['category_id'] = (int)$_GET['category_id'];

        $result = $this->product->search($query, $filters, $page, $limit);
        return $this->success($result);
    }

   public function create() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Validation des champs requis
        if (empty($data['nom'])) {
            return $this->error('Nom du produit requis', 400);
        }
        if (empty($data['code_produit'])) {
            return $this->error('Code produit requis', 400);
        }
        if (empty($data['barcode'])) {
            return $this->error('Code-barres requis', 400);
        }

        // Validation des longueurs
        if (strlen($data['nom']) > 255) {
            return $this->error('Nom trop long (max 255 caractères)', 400);
        }
        if (strlen($data['code_produit']) > 60) {
            return $this->error('Code produit trop long (max 60 caractères)', 400);
        }
        if (strlen($data['barcode']) > 50) {
            return $this->error('Code-barres trop long (max 50 caractères)', 400);
        }

        try {
            // Utiliser la méthode avec upsert
            $id = $this->product->createWithUpsert($data);
            
            // Récupérer le produit créé
            $product = $this->product->findById($id);
            
            return $this->success([
                'id' => $id,
                'product' => $product,
                'message' => 'Produit créé avec succès'
            ]);
            
        } catch (\PDOException $e) {
            if ($e->errorInfo[1] == 1062) {
                return $this->error('Code produit ou code-barres déjà existant', 409);
            }
            error_log("Erreur création produit: " . $e->getMessage());
            return $this->error('Erreur serveur lors de la création', 500);
        }
    }

    public function update($id) {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['nom'])) {
            return $this->error('Nom du produit requis', 400);
        }

        try {
            $this->product->updateWithUpsert($id, $data);
            
            // Récupérer le produit mis à jour
            $product = $this->product->findById($id);
            
            return $this->success([
                'product' => $product,
                'message' => 'Produit mis à jour avec succès'
            ]);
            
        } catch (\PDOException $e) {
            error_log("Erreur mise à jour produit: " . $e->getMessage());
            return $this->error('Erreur serveur lors de la mise à jour', 500);
        }
    }

    public function delete($id) {
        try {
            $this->product->delete($id);
            return $this->success(['message' => 'Produit supprimé avec succès']);
        } catch (\PDOException $e) {
            return $this->error('Erreur serveur', 500);
        }
    }

    private function success($data) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'data' => $data
        ]);
        exit;
    }

    private function error($message, $code = 400) {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $message
        ]);
        exit;
    }
}