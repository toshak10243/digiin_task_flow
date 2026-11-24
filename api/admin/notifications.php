<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
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
    $method = $_SERVER['REQUEST_METHOD'];

    // ─── GET: Fetch Notifications ────────────────────────────
    if ($method === 'GET') {
        $userId = (int)($_GET['user_id'] ?? 0);
        $role   = strtolower(trim($_GET['role'] ?? 'admin'));
        $type   = trim($_GET['type'] ?? 'all');
        $search = trim($_GET['search'] ?? '');
        $limit  = (int)($_GET['limit'] ?? 100);
        $offset = (int)($_GET['offset'] ?? 0);
        if ($limit <= 0) $limit = 100;
        if ($offset < 0) $offset = 0;

        $whereSql = "WHERE 1=1";
        $params = [];

        // If not admin and user_id is provided, filter by user_id
        if ($userId > 0 && $role !== 'admin') {
            $whereSql .= " AND n.user_id = :uid";
            $params[':uid'] = $userId;
        }

        if ($type !== 'all' && !empty($type)) {
            if ($type === 'urgent') {
                $whereSql .= " AND (n.type = 'urgent_task' OR n.title LIKE '%urgent%' OR n.title LIKE '%priority%')";
            } elseif ($type === 'feedback') {
                $whereSql .= " AND (n.type = 'feedback' OR n.title LIKE '%feedback%')";
            } elseif ($type === 'task') {
                $whereSql .= " AND n.type IN ('task_created', 'task_assigned', 'task_completed', 'task_rework', 'additional_files')";
            } else {
                $whereSql .= " AND n.type = :type";
                $params[':type'] = $type;
            }
        }

        if (!empty($search)) {
            $whereSql .= " AND (n.title LIKE :s OR t.task_key LIKE :s OR t.task_name LIKE :s)";
            $params[':s'] = "%{$search}%";
        }

        // Count total matching
        $countSql = "SELECT COUNT(*) FROM notifications n LEFT JOIN tasks t ON n.task_id = t.id {$whereSql}";
        $stmtCount = $db->prepare($countSql);
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetchColumn();

        // Fetch notifications
        $sql = "
            SELECT n.id, n.user_id, n.task_id, n.title, n.type, n.is_read, n.created_at,
                   t.task_key, t.task_name, t.status AS task_status,
                   u.name AS recipient_name, u.role AS recipient_role
            FROM notifications n
            LEFT JOIN tasks t ON n.task_id = t.id
            LEFT JOIN users u ON n.user_id = u.id
            {$whereSql}
            ORDER BY n.id DESC
            LIMIT :limit OFFSET :offset
        ";
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Stats summary
        $stmtStats = $db->query("
            SELECT 
                COUNT(*) as total,
                COALESCE(SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END), 0) as unread,
                COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END), 0) as today
            FROM notifications
        ");
        $stats = $stmtStats->fetch(PDO::FETCH_ASSOC);

        // Typecast items
        $cleaned = array_map(function($n) {
            return [
                'id'             => (int)$n['id'],
                'user_id'        => (int)$n['user_id'],
                'task_id'        => $n['task_id'] ? (int)$n['task_id'] : null,
                'title'          => (string)$n['title'],
                'type'           => (string)($n['type'] ?? 'system'),
                'is_read'        => (int)($n['is_read'] ?? 0) === 1,
                'created_at'     => (string)$n['created_at'],
                'task_key'       => $n['task_key'] ?? null,
                'task_name'      => $n['task_name'] ?? null,
                'task_status'    => $n['task_status'] ?? null,
                'recipient_name' => $n['recipient_name'] ?? null,
            ];
        }, $notifications);

        echo json_encode([
            'success'       => true,
            'total'         => $total,
            'count'         => count($cleaned),
            'stats'         => [
                'total'  => (int)($stats['total'] ?? 0),
                'unread' => (int)($stats['unread'] ?? 0),
                'today'  => (int)($stats['today'] ?? 0),
            ],
            'notifications' => $cleaned
        ]);
        exit;
    }

    // ─── POST: Mark as Read / Mark All Read ──────────────────
    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?? $_POST;
        $action = $data['action'] ?? 'mark_read';
        $notifId = (int)($data['id'] ?? 0);

        if ($action === 'mark_all_read') {
            $db->exec("UPDATE notifications SET is_read = 1 WHERE is_read = 0");
            echo json_encode(['success' => true, 'message' => 'All notifications marked as read']);
            exit;
        }

        if ($notifId > 0) {
            $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = :id");
            $stmt->execute([':id' => $notifId]);
            echo json_encode(['success' => true, 'message' => 'Notification marked as read']);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Invalid action or missing notification id']);
        exit;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Notifications error: ' . $e->getMessage()
    ]);
}
