<?php
namespace CaisseDZ\Catalog\Controllers;

use CaisseDZ\Catalog\Models\Category;

class CategoryController {
    private $category;

    public function __construct() {
        $this->category = new Category();
    }

    public function getAll() {
        $page = (int)($_GET['page'] ?? 1);
        $limit = min((int)($_GET['limit'] ?? 50), 100);
        
        $result = $this->category->findAll($page, $limit);
        return $this->success($result);
    }

    public function getTree() {
        $tree = $this->category->getTree();
        return $this->success($tree);
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