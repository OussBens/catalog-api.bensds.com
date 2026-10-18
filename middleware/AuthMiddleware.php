<?php
namespace CaisseDZ\Catalog\Middleware;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthMiddleware {
    private $apiKey;
    private $jwtSecret;

    public function __construct() {
        $this->apiKey = $_ENV['API_KEY'] ?? '';
        $this->jwtSecret = $_ENV['JWT_SECRET'] ?? '';
    }

    public function authenticate() {
        // Vérifier la clé API
        $headers = getallheaders();
        $apiKey = $headers['X-API-Key'] ?? $_GET['api_key'] ?? null;

        if ($apiKey && $apiKey === $this->apiKey) {
            return true;
        }

        // Vérifier le JWT
        $auth = $headers['Authorization'] ?? null;
        if ($auth && strpos($auth, 'Bearer ') === 0) {
            $token = substr($auth, 7);
            try {
                JWT::decode($token, new Key($this->jwtSecret, 'HS256'));
                return true;
            } catch (\Exception $e) {
                return false;
            }
        }

        return false;
    }
}