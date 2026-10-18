<?php
require_once __DIR__ . '/vendor/autoload.php';

use Firebase\JWT\JWT;

$dotenv = \Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Données de connexion (à remplacer par une vraie vérification)
$users = [
    'admin' => 'admin123',
    'app' => 'app123'
];

$username = $_POST['username'] ?? $_GET['username'] ?? null;
$password = $_POST['password'] ?? $_GET['password'] ?? null;

if ($username && $password && isset($users[$username]) && $users[$username] === $password) {
    $payload = [
        'username' => $username,
        'exp' => time() + 3600 * 24 // 24 heures
    ];
    
    $jwt = JWT::encode($payload, $_ENV['JWT_SECRET'], 'HS256');
    
    echo json_encode([
        'success' => true,
        'token' => $jwt
    ]);
} else {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Identifiants invalides'
    ]);
}