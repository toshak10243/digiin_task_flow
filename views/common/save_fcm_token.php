<?php
require_once __DIR__ . '/../../bootstrap/app.php';
require_once __DIR__ . '/../../app/core/Session.php';
require_once __DIR__ . '/../../app/services/FCMService.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$token = trim($input['token'] ?? ($_POST['token'] ?? ''));

if (empty($token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing token']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

$saved = FCMService::saveToken($userId, $token, $userAgent);

echo json_encode(['success' => $saved]);
exit;

