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

    // 1. Overall System Stats
    $totalUsers = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $totalTasks = (int)$db->query("SELECT COUNT(*) FROM tasks")->fetchColumn();
    $inProgress = (int)$db->query("SELECT COUNT(*) FROM tasks WHERE status='in_progress'")->fetchColumn();
    $completed  = (int)$db->query("SELECT COUNT(*) FROM tasks WHERE status='completed'")->fetchColumn();
    $rework     = (int)$db->query("SELECT COUNT(*) FROM tasks WHERE status='rework'")->fetchColumn();
    $urgent     = (int)$db->query("SELECT COUNT(*) FROM tasks WHERE urgent='yes'")->fetchColumn();
    $totalWords = (int)$db->query("SELECT COALESCE(SUM(word_count),0) FROM tasks")->fetchColumn();

    $currentMonth = date('Y-m');
    $stmtMonthWords = $db->prepare("SELECT COALESCE(SUM(word_count),0) FROM tasks WHERE DATE_FORMAT(COALESCE(task_created_at, created_at), '%Y-%m') = :currMonth");
    $stmtMonthWords->execute([':currMonth' => $currentMonth]);
    $monthlyWords = (int)$stmtMonthWords->fetchColumn();

    // 2. Date-Wise Filter Stats
    $dashDate = $_GET['date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dashDate)) {
        $dashDate = date('Y-m-d');
    }

    $stmtDaily = $db->prepare("
        SELECT t.*, u.name AS team_name, bde.name AS bde_name
        FROM tasks t
        LEFT JOIN users u ON t.assigned_to = u.id
        LEFT JOIN users bde ON t.created_by = bde.id
        WHERE (DATE(t.created_at) = :dt OR DATE(t.task_created_at) = :dt)
        ORDER BY t.id DESC
    ");
    $stmtDaily->execute([':dt' => $dashDate]);
    $dailyTasks = $stmtDaily->fetchAll(PDO::FETCH_ASSOC);

    $dailyStats = [
        'total'     => count($dailyTasks),
        'completed' => 0,
        'working'   => 0,
        'rework'    => 0,
        'words'     => 0
    ];
    foreach ($dailyTasks as $dt) {
        $dailyStats['words'] += (int)($dt['word_count'] ?? 0);
        if ($dt['status'] === 'completed' || ($dt['current_stage'] ?? '') === 'completed') $dailyStats['completed']++;
        elseif ($dt['status'] === 'rework') $dailyStats['rework']++;
        else $dailyStats['working']++;
    }

    // 3. Top BDE Performers
    $topBDE = $db->query("
        SELECT u.id, u.name, u.username, COUNT(t.id) total_tasks, COALESCE(SUM(t.word_count), 0) words
        FROM users u
        JOIN tasks t ON u.id = t.created_by
        WHERE u.role = 'bde'
        GROUP BY u.id
        ORDER BY total_tasks DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 4. Top Operations Team Performers
    $topTeam = $db->query("
        SELECT u.id, u.name, u.username, COUNT(t.id) completed_tasks, COALESCE(SUM(t.word_count), 0) words
        FROM users u
        JOIN tasks t ON u.id = t.assigned_to
        WHERE u.role = 'operations_team' AND t.status = 'completed'
        GROUP BY u.id
        ORDER BY completed_tasks DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 5. Recent 5 Tasks
    $recentTasks = $db->query("
        SELECT t.id, t.task_key, t.task_name, t.client_name, t.status, t.current_stage, t.deadline, t.word_count, t.urgent,
               u.name as expert_name, bde.name as bde_name
        FROM tasks t
        LEFT JOIN users u ON t.assigned_to = u.id
        LEFT JOIN users bde ON t.created_by = bde.id
        ORDER BY t.id DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 6. Role breakdown
    $roleCounts = $db->query("
        SELECT role, COUNT(*) as count 
        FROM users 
        WHERE status = 'active' 
        GROUP BY role
    ")->fetchAll(PDO::FETCH_KEY_PAIR);

    echo json_encode([
        'success' => true,
        'system_stats' => [
            'total_users'   => $totalUsers,
            'total_tasks'   => $totalTasks,
            'in_progress'   => $inProgress,
            'completed'     => $completed,
            'rework'        => $rework,
            'urgent'        => $urgent,
            'total_words'   => $totalWords,
            'monthly_words' => $monthlyWords,
            'role_counts'   => $roleCounts
        ],
        'selected_date' => $dashDate,
        'daily_stats'   => $dailyStats,
        'top_bde'       => $topBDE,
        'top_team'      => $topTeam,
        'recent_tasks'  => $recentTasks,
        'daily_tasks'   => $dailyTasks
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}

