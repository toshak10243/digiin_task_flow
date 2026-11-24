<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-User-Id");
header("Content-Type: application/json; charset=UTF-8");

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../app/config/database.php';
date_default_timezone_set('Asia/Kolkata');

try {
    $db = (new Database())->connect();

    // Ensure delivered_to_client column exists
    try {
        $db->query("SELECT delivered_to_client FROM tasks LIMIT 1");
    } catch (\Exception $e) {
        try {
            $db->exec("ALTER TABLE tasks ADD COLUMN delivered_to_client TINYINT(1) NOT NULL DEFAULT 0");
        } catch (\Exception $ex) {}
    }

    // Determine current BDE user
    $userId = (int)($_GET['user_id'] ?? $_POST['user_id'] ?? $_SERVER['HTTP_X_USER_ID'] ?? 0);
    if ($userId <= 0) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $userId = (int)($_SESSION['user_id'] ?? 0);
    }

    if ($userId <= 0) {
        $bdeStmt = $db->query("SELECT id FROM users WHERE role = 'bde' AND status = 'active' ORDER BY id ASC LIMIT 1");
        $userId = (int)($bdeStmt->fetchColumn() ?: 0);
    }

    // ─── POST ACTIONS (e.g. toggle delivered) ───────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true) ?? $_POST;
        $action = $body['action'] ?? $_GET['action'] ?? '';

        if ($action === 'toggle_delivered') {
            $taskId = (int)($body['task_id'] ?? 0);
            if ($taskId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Valid task_id is required']);
                exit;
            }

            // Verify task belongs to this BDE
            $chk = $db->prepare("SELECT delivered_to_client FROM tasks WHERE id = :id AND created_by = :user");
            $chk->execute([':id' => $taskId, ':user' => $userId]);
            $currentDelivered = $chk->fetchColumn();

            if ($currentDelivered === false) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Task not found or access denied']);
                exit;
            }

            $newDelivered = $currentDelivered ? 0 : 1;
            $upd = $db->prepare("UPDATE tasks SET delivered_to_client = :del WHERE id = :id");
            $upd->execute([':del' => $newDelivered, ':id' => $taskId]);

            echo json_encode([
                'success'             => true,
                'task_id'             => $taskId,
                'delivered_to_client' => $newDelivered,
                'message'             => $newDelivered ? 'Task marked as delivered to client' : 'Task marked as undelivered'
            ]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown POST action']);
        exit;
    }

    // ─── GET: List Tasks with Filters & Pagination ──────────
    $date   = $_GET['date'] ?? null;
    $status = $_GET['status'] ?? 'all';
    $search = trim($_GET['search'] ?? '');
    $limit  = (int)($_GET['limit'] ?? 20);
    if ($limit <= 0) $limit = 20;
    $page   = (int)($_GET['page'] ?? 1);
    if ($page <= 0) $page = 1;
    $offset = ($page - 1) * $limit;

    $whereSql = "WHERE t.created_by = :user";
    $params = [':user' => $userId];

    if (!empty($date) && $date !== 'all') {
        $whereSql .= " AND (DATE(t.created_at) = :dt OR DATE(t.task_created_at) = :dt)";
        $params[':dt'] = $date;
    }

    if ($status === 'urgent') {
        $whereSql .= " AND t.urgent = 'yes'";
    } elseif ($status === 'delivered') {
        $whereSql .= " AND t.delivered_to_client = 1";
    } elseif ($status !== 'all' && !empty($status)) {
        $whereSql .= " AND t.status = :st";
        $params[':st'] = $status;
    }

    if (!empty($search)) {
        $whereSql .= " AND (t.task_key LIKE :q OR t.task_name LIKE :q OR t.client_name LIKE :q OR u.name LIKE :q)";
        $params[':q'] = "%$search%";
    }

    // Total Count
    $countStmt = $db->prepare("
        SELECT COUNT(*) 
        FROM tasks t
        LEFT JOIN users u ON t.assigned_to = u.id
        $whereSql
    ");
    $countStmt->execute($params);
    $totalTasks = (int)$countStmt->fetchColumn();
    $totalPages = (int)ceil($totalTasks / $limit);

    // Paginated list
    $sql = "
        SELECT t.*, 
               u.name AS expert_name, u.username AS expert_username
        FROM tasks t
        LEFT JOIN users u ON t.assigned_to = u.id
        $whereSql
        ORDER BY t.id DESC 
        LIMIT :limit OFFSET :offset
    ";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->execute();
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'     => true,
        'total'       => $totalTasks,
        'page'        => $page,
        'limit'       => $limit,
        'total_pages' => max(1, $totalPages),
        'count'       => count($tasks),
        'tasks'       => $tasks
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
