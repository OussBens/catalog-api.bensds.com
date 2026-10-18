<?php
namespace CaisseDZ\Catalog\Controllers;

use CaisseDZ\Catalog\Models\Brand;

class BrandController {
    private $brand;

    public function __construct() {
        $this->brand = new Brand();
    }

    public function getAll() {
        $page = (int)($_GET['page'] ?? 1);
        $limit = min((int)($_GET['limit'] ?? 50), 100);
        
        $result = $this->brand->findAll($page, $limit);
        return $this->success($result);
    }

    public function getById($id) {
        $brand = $this->brand->findById($id);
        
        if (!$brand) {
            return $this->error('Marque non trouvée', 404);
        }

        return $this->success($brand);
    }

    public function search() {
        $query = $_GET['q'] ?? '';
        $result = $this->brand->findByName($query);
        return $this->success($result);
    }

    public function create() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['nom'])) {
            return $this->error('Nom requis', 400);
        }

        try {
            $this->brand->create($data);
            return $this->success(['message' => 'Marque créée avec succès']);
        } catch (\PDOException $e) {
            if ($e->errorInfo[1] == 1062) {
                return $this->error('Marque déjà existante', 409);
            }
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