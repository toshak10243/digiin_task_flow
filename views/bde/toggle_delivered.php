<?php
require_once __DIR__ . "/../../bootstrap/app.php";
require_once __DIR__ . "/../../app/middleware/AuthMiddleware.php";
require_once __DIR__ . "/../../app/config/database.php";

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$db = (new Database())->connect();

// Auto check/add column if not exists
try {
    $db->query("SELECT delivered_to_client FROM tasks LIMIT 1");
} catch (\Exception $e) {
    try {
        $db->exec("ALTER TABLE tasks ADD COLUMN delivered_to_client TINYINT(1) NOT NULL DEFAULT 0");
    } catch (\Exception $ex) {}
}

$taskId = (int)($_POST['task_id'] ?? 0);
$delivered = isset($_POST['delivered']) ? (int)$_POST['delivered'] : 0;
$user = $_SESSION['user_id'] ?? 0;
$role = $_SESSION['role'] ?? 'bde';

if ($taskId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid task ID']);
    exit;
}

// Security: BDE can update their own tasks, Admin can update any task
if ($role === 'bde') {
    $stmt = $db->prepare("UPDATE tasks SET delivered_to_client = :del WHERE id = :id AND created_by = :uid");
    $stmt->execute([':del' => $delivered, ':id' => $taskId, ':uid' => $user]);
} else {
    $stmt = $db->prepare("UPDATE tasks SET delivered_to_client = :del WHERE id = :id");
    $stmt->execute([':del' => $delivered, ':id' => $taskId]);
}

echo json_encode([
    'success' => true,
    'task_id' => $taskId,
    'delivered' => $delivered
]);
exit;
