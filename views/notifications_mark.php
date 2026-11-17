<?php
require_once __DIR__ . "/../bootstrap/app.php";
require_once __DIR__ . "/../app/middleware/AuthMiddleware.php";
require_once __DIR__ . "/../app/config/database.php";
require_once __DIR__ . "/../app/services/NotificationService.php";

$db = (new Database())->connect();
$userId = (int)($_SESSION['user_id'] ?? 0);
$userRole = $_SESSION['role'] ?? '';

$id = (int)($_GET['id'] ?? 0);
$all = isset($_GET['all']) && $_GET['all'] == '1';

if ($all) {
    if ($userId > 0) {
        NotificationService::markAllRead($userId);
    }
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if (!empty($referer)) {
        header("Location: " . $referer);
    } else {
        header("Location: " . getRoleDashboardUrl($userRole));
    }
    exit;
}

function getRoleDashboardUrl($role) {
    return match($role) {
        'bde'                => 'bde/dashboard.php',
        'operations_manager' => 'operations_manager/dashboard.php',
        'operations_team'    => 'operations_team/dashboard.php',
        'qc'                 => 'qc/dashboard.php',
        'admin'              => 'admin/dashboard.php',
        'bdmanager'          => 'bdmanager/dashboard.php',
        default              => '../index.php'
    };
}

if ($id > 0 && $userId > 0) {
    // Fetch notification to determine destination
    $stmt = $db->prepare("SELECT n.*, t.current_stage, t.status FROM notifications n LEFT JOIN tasks t ON n.task_id = t.id WHERE n.id = :id AND n.user_id = :uid");
    $stmt->execute([':id' => $id, ':uid' => $userId]);
    $notif = $stmt->fetch(PDO::FETCH_ASSOC);

    NotificationService::markRead($id, $userId);

    if ($notif) {
        $taskId = (int)$notif['task_id'];
        $type = $notif['type'];

        // Role-based smart routing (all paths prefixed with role directory since this file is in /views/)
        if ($userRole === 'bde') {
            if ($type === 'task_completed' || $type === 'feedback') {
                header("Location: bde/my_tasks.php?feedback=" . $taskId);
            } else {
                header("Location: bde/my_tasks.php");
            }
            exit;
        }

        if ($userRole === 'operations_manager') {
            if ($type === 'expert_query') {
                header("Location: operations_manager/team_queries.php");
            } elseif ($type === 'feedback') {
                header("Location: operations_manager/feedbacks.php");
            } elseif ($type === 'additional_files') {
                header("Location: operations_manager/additional_repository.php?task_id=" . $taskId);
            } elseif ($type === 'urgent_task') {
                header("Location: operations_manager/incoming_tasks.php?filter=urgent");
            } elseif ($type === 'task_created') {
                header("Location: operations_manager/incoming_tasks.php");
            } elseif ($type === 'work_deployed') {
                header("Location: operations_manager/dashboard.php");
            } else {
                header("Location: operations_manager/dashboard.php");
            }
            exit;
        }

        if ($userRole === 'operations_team') {
            if ($type === 'feedback') {
                header("Location: operations_team/feedbacks.php");
            } elseif ($type === 'task_rework') {
                header("Location: operations_team/rework_tasks.php");
            } else {
                header("Location: operations_team/my_tasks.php");
            }
            exit;
        }

        if ($userRole === 'qc') {
            header("Location: qc/pending_tasks.php");
            exit;
        }

        if ($userRole === 'admin') {
            header("Location: admin/dashboard.php");
            exit;
        }

        if ($userRole === 'bdmanager') {
            header("Location: bdmanager/dashboard.php");
            exit;
        }
    }
}

$referer = $_SERVER['HTTP_REFERER'] ?? '';
if (!empty($referer)) {
    header("Location: " . $referer);
} else {
    header("Location: " . getRoleDashboardUrl($userRole));
}
exit;
