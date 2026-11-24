<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/models/User.php';

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?? $_POST;

$username = trim($data['username'] ?? '');
$password = trim($data['password'] ?? '');

if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Username and password are required'
    ]);
    exit;
}

try {
    $userModel = new User();
    $user = $userModel->getUserByUsername($username);

    if ($user && password_verify($password, $user['password'])) {
        if (($user['status'] ?? 'active') === 'inactive') {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Your account is deactivated. Contact administrator.'
            ]);
            exit;
        }

        // Generate a simple API token
        $token = bin2hex(random_bytes(32));

        // Return user data (excluding password)
        unset($user['password']);

        echo json_encode([
            'success' => true,
            'message' => 'Login successful',
            'token'   => $token,
            'user'    => [
                'id'       => (int)$user['id'],
                'name'     => $user['name'] ?: $user['username'],
                'username' => $user['username'],
                'email'    => $user['email'] ?? '',
                'role'     => $user['role'],
                'status'   => $user['status'] ?? 'active'
            ]
        ]);
    } else {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid username or password'
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}

