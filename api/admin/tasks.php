<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../app/config/database.php';
date_default_timezone_set('Asia/Kolkata');

try {
    $db = (new Database())->connect();

    // ─── Single Task Details ────────────────────────────────
    $taskId = (int)($_GET['id'] ?? 0);
    if ($taskId > 0) {
        $stmt = $db->prepare("
            SELECT t.*, 
                   u.name AS expert_name, u.username AS expert_username,
                   bde.name AS bde_name, bde.username AS bde_username,
                   fw.name AS forwarded_to_name
            FROM tasks t
            LEFT JOIN users u ON t.assigned_to = u.id
            LEFT JOIN users bde ON t.created_by = bde.id
            LEFT JOIN users fw ON t.forwarded_feedback_to = fw.id
            WHERE t.id = :id
        ");
        $stmt->execute([':id' => $taskId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$task) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Task not found']);
            exit;
        }

        // Parse feedback files if present
        $feedbackFiles = [];
        if (!empty($task['feedback_files'])) {
            $feedbackFiles = json_decode($task['feedback_files'], true) ?: [];
        }
        $task['feedback_files_parsed'] = $feedbackFiles;

        echo json_encode([
            'success' => true,
            'task'    => $task
        ]);
        exit;
    }

    // ─── List Tasks with Filters & Pagination ────────────────
    $date   = $_GET['date'] ?? null;
    $status = $_GET['status'] ?? 'all';
    $search = trim($_GET['search'] ?? '');
    $limit  = (int)($_GET['limit'] ?? 20);
    if ($limit <= 0) $limit = 20;
    $page   = (int)($_GET['page'] ?? 1);
    if ($page <= 0) $page = 1;
    $offset = ($page - 1) * $limit;

    $whereSql = "WHERE 1=1";
    $params = [];

    if (!empty($date) && $date !== 'all') {
        $whereSql .= " AND (DATE(t.created_at) = :dt OR DATE(t.task_created_at) = :dt)";
        $params[':dt'] = $date;
    }

    if ($status === 'urgent') {
        $whereSql .= " AND t.urgent = 'yes'";
    } elseif ($status !== 'all' && !empty($status)) {
        $whereSql .= " AND t.status = :st";
        $params[':st'] = $status;
    }

    if (!empty($search)) {
        $whereSql .= " AND (t.task_key LIKE :q OR t.task_name LIKE :q OR t.client_name LIKE :q OR u.name LIKE :q OR bde.name LIKE :q)";
        $params[':q'] = "%$search%";
    }

    // Total Count for Pagination
    $countStmt = $db->prepare("
        SELECT COUNT(*) 
        FROM tasks t
        LEFT JOIN users u ON t.assigned_to = u.id
        LEFT JOIN users bde ON t.created_by = bde.id
        $whereSql
    ");
    $countStmt->execute($params);
    $totalTasks = (int)$countStmt->fetchColumn();
    $totalPages = (int)ceil($totalTasks / $limit);

    $sql = "
        SELECT t.*, 
               u.name AS expert_name, u.username AS expert_username,
               bde.name AS bde_name, bde.username AS bde_username
        FROM tasks t
        LEFT JOIN users u ON t.assigned_to = u.id
        LEFT JOIN users bde ON t.created_by = bde.id
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
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}

