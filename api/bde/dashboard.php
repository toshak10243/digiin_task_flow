<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
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

    // Determine current BDE user
    $userId = (int)($_GET['user_id'] ?? $_SERVER['HTTP_X_USER_ID'] ?? 0);
    if ($userId <= 0) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $userId = (int)($_SESSION['user_id'] ?? 0);
    }

    // If still 0, fallback to first active BDE user in database
    if ($userId <= 0) {
        $bdeStmt = $db->query("SELECT id FROM users WHERE role = 'bde' AND status = 'active' ORDER BY id ASC LIMIT 1");
        $userId = (int)($bdeStmt->fetchColumn() ?: 0);
    }

    // Current user info
    $userStmt = $db->prepare("SELECT id, name, username, email, role FROM users WHERE id = :u LIMIT 1");
    $userStmt->execute([':u' => $userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [
        'id'       => $userId,
        'name'     => 'BDE Executive',
        'username' => 'bde',
        'role'     => 'bde'
    ];

    // Greeting
    $hour = (int)date('G');
    if ($hour < 12) $greeting = "Good morning";
    elseif ($hour < 17) $greeting = "Good afternoon";
    else $greeting = "Good evening";

    $firstName = !empty($user['name']) ? explode(' ', trim($user['name']))[0] : 'there';

    // 1. Overall BDE Stats (Scoped to this BDE)
    $statsQuery = "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as new_count,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'rework' THEN 1 ELSE 0 END) as rework,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN urgent = 'yes' AND status != 'completed' THEN 1 ELSE 0 END) as urgent,
        SUM(CASE WHEN deadline < NOW() AND status != 'completed' THEN 1 ELSE 0 END) as overdue,
        COALESCE(SUM(word_count), 0) as total_words,
        COALESCE(SUM(CASE WHEN DATE_FORMAT(COALESCE(task_created_at, created_at), '%Y-%m') = DATE_FORMAT(CURRENT_DATE(), '%Y-%m') THEN word_count ELSE 0 END), 0) as monthly_words
    FROM tasks WHERE created_by = :user";

    $stmtStats = $db->prepare($statsQuery);
    $stmtStats->execute([':user' => $userId]);
    $stats = $stmtStats->fetch(PDO::FETCH_ASSOC);

    // 2. Pipeline Flow Stage Breakdown
    $stageOrder  = ['new', 'assigned', 'in_progress', 'rework', 'approved', 'completed'];
    $stageLabels = [
        'new'         => 'New',
        'assigned'    => 'Assigned',
        'in_progress' => 'Working',
        'rework'      => 'Rework',
        'approved'    => 'Approved',
        'completed'   => 'Completed',
    ];
    $stageColors = [
        'new'         => '#a1a1aa',
        'assigned'    => '#f2a93b',
        'in_progress' => '#3b6cf6',
        'rework'      => '#e11d48',
        'approved'    => '#7c5cf0',
        'completed'   => '#0f9d68',
    ];
    $stageCounts = array_fill_keys($stageOrder, 0);

    $stmtStage = $db->prepare("SELECT status, COUNT(*) as cnt FROM tasks WHERE created_by = :user GROUP BY status");
    $stmtStage->execute([':user' => $userId]);
    foreach ($stmtStage->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($stageCounts[$row['status']])) {
            $stageCounts[$row['status']] = (int)$row['cnt'];
        }
    }

    $pipeline = [];
    $pipelineTotal = array_sum($stageCounts) ?: 1;
    foreach ($stageOrder as $stg) {
        $count = $stageCounts[$stg];
        $pct = round(($count / $pipelineTotal) * 100, 1);
        $pipeline[] = [
            'stage'      => $stg,
            'label'      => $stageLabels[$stg],
            'color'      => $stageColors[$stg],
            'count'      => $count,
            'percentage' => $pct,
        ];
    }

    // 3. Daily Task Section (Date-Wise)
    $dashDate = $_GET['date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dashDate)) {
        $dashDate = date('Y-m-d');
    }

    $stmtDaily = $db->prepare("
        SELECT t.*, expert.name AS expert_name, expert.username AS expert_username
        FROM tasks t
        LEFT JOIN users expert ON t.assigned_to = expert.id
        WHERE t.created_by = :user
        AND (DATE(t.created_at) = :dt OR DATE(t.task_created_at) = :dt)
        ORDER BY t.id DESC
    ");
    $stmtDaily->execute([':user' => $userId, ':dt' => $dashDate]);
    $dailyTasks = $stmtDaily->fetchAll(PDO::FETCH_ASSOC);

    $dailyStats = [
        'total'     => count($dailyTasks),
        'completed' => 0,
        'working'   => 0,
        'new'       => 0,
        'words'     => 0,
        'urgent'    => 0
    ];
    foreach ($dailyTasks as $dt) {
        $dailyStats['words'] += (int)($dt['word_count'] ?? 0);
        if (($dt['urgent'] ?? '') === 'yes') $dailyStats['urgent']++;
        if ($dt['status'] === 'completed') $dailyStats['completed']++;
        elseif ($dt['status'] === 'new') $dailyStats['new']++;
        else $dailyStats['working']++;
    }

    // 4. Needs Attention Tasks (Urgent or Overdue, not completed)
    $stmtAttention = $db->prepare("
        SELECT t.*, expert.name AS expert_name
        FROM tasks t
        LEFT JOIN users expert ON t.assigned_to = expert.id
        WHERE t.created_by = :user 
        AND t.status != 'completed' 
        AND (t.deadline < NOW() OR t.urgent = 'yes')
        ORDER BY t.deadline ASC 
        LIMIT 6
    ");
    $stmtAttention->execute([':user' => $userId]);
    $attentionTasks = $stmtAttention->fetchAll(PDO::FETCH_ASSOC);

    // 5. Recent Activity (Last 6 tasks)
    $stmtRecent = $db->prepare("
        SELECT t.*, expert.name AS expert_name
        FROM tasks t
        LEFT JOIN users expert ON t.assigned_to = expert.id
        WHERE t.created_by = :user
        ORDER BY t.id DESC 
        LIMIT 6
    ");
    $stmtRecent->execute([':user' => $userId]);
    $recentTasks = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);

    // 6. Unread Notifications Count
    $stmtUnread = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :u AND is_read = 0");
    $stmtUnread->execute([':u' => $userId]);
    $unreadNotifications = (int)$stmtUnread->fetchColumn();

    echo json_encode([
        'success'   => true,
        'greeting'  => "$greeting, $firstName",
        'user'      => $user,
        'date'      => $dashDate,
        'stats'     => [
            'total'          => (int)($stats['total'] ?? 0),
            'new_count'      => (int)($stats['new_count'] ?? 0),
            'in_progress'    => (int)($stats['in_progress'] ?? 0),
            'rework'         => (int)($stats['rework'] ?? 0),
            'completed'      => (int)($stats['completed'] ?? 0),
            'urgent'         => (int)($stats['urgent'] ?? 0),
            'overdue'        => (int)($stats['overdue'] ?? 0),
            'total_words'    => (int)($stats['total_words'] ?? 0),
            'monthly_words'  => (int)($stats['monthly_words'] ?? 0),
            'unread_notifs'  => $unreadNotifications,
        ],
        'pipeline'       => $pipeline,
        'daily_stats'    => $dailyStats,
        'daily_tasks'    => $dailyTasks,
        'attention_tasks'=> $attentionTasks,
        'recent_tasks'   => $recentTasks
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
