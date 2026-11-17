<?php
ini_set('memory_limit', '512M');
ini_set('upload_max_filesize', '256M');
ini_set('post_max_size', '260M');
ini_set('max_file_uploads', '500');
set_time_limit(300);

require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole('operations_team');
require_once "../../app/config/database.php";
require_once "../../bootstrap/app.php";
require_once "../../app/services/TaskWorkflowService.php";

date_default_timezone_set('Asia/Kolkata');
$db = (new Database())->connect();
$user = $_SESSION['user_id'];

// Auto-withdraw any tasks assigned for 30+ days without submission
TaskWorkflowService::autoWithdrawStaleTasks($db);

/* ================= UPLOAD + SUBMIT HANDLER ================= */
/* Replaces TeamTaskController.php — uploads go straight to the task's
   "Operations Files" Drive folder, preserving folder structure from
   full_paths[]. No local storage is ever written. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* ================= EXPERT QUERY SUBMISSION ================= */
    if (isset($_POST['action_type']) && $_POST['action_type'] === 'submit_query') {
        $task_id    = $_POST['task_id'] ?? null;
        $task_key   = $_POST['task_key'] ?? null;
        $query_text = trim($_POST['query_text'] ?? '');

        if ($task_id && $task_key && $query_text !== '') {
            // Ensure task_queries table exists
            $db->exec("CREATE TABLE IF NOT EXISTS task_queries (
                id INT AUTO_INCREMENT PRIMARY KEY,
                task_id INT NOT NULL,
                task_key VARCHAR(100) NOT NULL,
                user_id INT NOT NULL,
                query_text TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");

            // Insert new query into task_queries table
            $stmtQ = $db->prepare("
                INSERT INTO task_queries (task_id, task_key, user_id, query_text, created_at)
                VALUES (:task_id, :task_key, :user_id, :query_text, NOW())
            ");
            $stmtQ->execute([
                ':task_id'    => $task_id,
                ':task_key'   => $task_key,
                ':user_id'    => $_SESSION['user_id'],
                ':query_text' => $query_text
            ]);

            // Clear any expert query feedback from tasks.feedback so it only stays in task_queries table
            $db->prepare("UPDATE tasks SET feedback = NULL WHERE id = :id AND feedback = :query")->execute([':id' => $task_id, ':query' => $query_text]);

            // Notify all Operations Managers
            $stmtMgr = $db->prepare("SELECT id FROM users WHERE role = 'operations_manager'");
            $stmtMgr->execute();
            $managers = $stmtMgr->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($managers)) {
                $stmtNotify = $db->prepare("
                    INSERT INTO notifications (user_id, task_id, title, type)
                    VALUES (:user, :task, :title, 'expert_query')
                ");

                $expertName = $_SESSION['username'] ?? 'Expert';
                $title = "🚨 Team Query on " . $task_key . " from " . $expertName;

                foreach ($managers as $mgr) {
                    $stmtNotify->execute([
                        ':user'  => $mgr['id'],
                        ':task'  => $task_id,
                        ':title' => $title
                    ]);
                }
            }

            header("Location: my_tasks.php?query_sent=1");
            exit;
        } else {
            header("Location: my_tasks.php?error=" . urlencode("Query text cannot be empty"));
            exit;
        }
    }

    $task_id  = $_POST['task_id'] ?? null;
    $task_key = $_POST['task_key'] ?? null;
    $notes    = $_POST['notes'] ?? '';

    if (!$task_id || !$task_key) {
        header("Location: my_tasks.php?error=" . urlencode("Missing task reference"));
        exit;
    }

    // 1. High-speed local staging (Preserves subfolder hierarchy)
    $safeTaskKey = preg_replace('/[^A-Za-z0-9\-_]/', '_', $task_key);
    $uploadDir = __DIR__ . "/../../uploads/team_work/" . $safeTaskKey . "/";
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $uploadedFiles = [];
    $fullPaths = $_POST['full_paths'] ?? [];

    if (!empty($_FILES['work_files']['tmp_name'])) {
        foreach ($_FILES['work_files']['tmp_name'] as $index => $tmpPath) {
            if ($_FILES['work_files']['error'][$index] !== UPLOAD_ERR_OK) {
                continue;
            }

            $relativePath = $fullPaths[$index] ?? $_FILES['work_files']['name'][$index];
            $relativePath = str_replace('\\', '/', $relativePath);
            
            $targetLocalPath = $uploadDir . $relativePath;
            $targetLocalDir  = dirname($targetLocalPath);
            if (!is_dir($targetLocalDir)) {
                mkdir($targetLocalDir, 0777, true);
            }

            if (move_uploaded_file($tmpPath, $targetLocalPath)) {
                $uploadedFiles[] = [
                    'local_path'    => $targetLocalPath,
                    'relative_path' => $relativePath,
                    'name'          => basename($relativePath)
                ];
            }
        }
    }

    if (empty($uploadedFiles)) {
        header("Location: my_tasks.php?error=" . urlencode("Please attach at least one valid file before deploying."));
        exit;
    }

    // 2. Build local ZIP backup for immediate 1-click download by QC / OM
    $zipRelativePath = null;
    if (class_exists('ZipArchive') && count($uploadedFiles) > 0) {
        $zipName = $safeTaskKey . "_completed_" . time() . ".zip";
        $zipFullPath = __DIR__ . "/../../uploads/team_work/" . $zipName;
        $zip = new ZipArchive();
        if ($zip->open($zipFullPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            foreach ($uploadedFiles as $f) {
                if (file_exists($f['local_path'])) {
                    $zip->addFile($f['local_path'], $f['relative_path']);
                }
            }
            $zip->close();
            $zipRelativePath = "uploads/team_work/" . $zipName;
        }
    }

    // 3. INSTANT HANDOVER: Update Task State in DB so QC gets it immediately
    $db->prepare("UPDATE tasks SET
            team_file = COALESCE(:team_file, team_file),
            team_notes = :notes,
            current_stage = 'qc'
        WHERE id = :id")
        ->execute([
            ':team_file' => $zipRelativePath,
            ':notes'     => $notes,
            ':id'        => $task_id
        ]);

    // Send Work Deployed Notification to QC and Operations Managers
    try {
        require_once "../../app/services/NotificationService.php";
        NotificationService::onTaskDeployedToQC((int)$task_id, $task_key, $_SESSION['username'] ?? 'Expert');
    } catch (Exception $eNotif) {
        error_log("Work deployed notification failed: " . $eNotif->getMessage());
    }

    // Send immediate response to browser if FastCGI is active (GoDaddy / Production)
    // Expert's page reloads to ?submitted=1 in ~0.2s while Drive syncs in background!
    if (function_exists('fastcgi_finish_request')) {
        header("Location: my_tasks.php?submitted=1");
        session_write_close();
        fastcgi_finish_request();
        ignore_user_abort(true);
        set_time_limit(300);
    }

    // 4. Safe Google Drive Sync (Non-blocking / Resilient)
    try {
        require_once "../../app/services/GoogleDriveService.php";
        $driveService = new GoogleDriveService();

        if ($driveService->isReady()) {
            $stmtTask = $db->prepare("SELECT drive_folder_id FROM tasks WHERE id = :id");
            $stmtTask->execute([':id' => $task_id]);
            $existingFolderId = $stmtTask->fetchColumn();

            $folderInfo = $driveService->createTaskFolder($task_key, $existingFolderId ?: null);

            if (!empty($folderInfo['success'])) {
                $operationsFolderId = $folderInfo['operations_folder']['id'];
                $folderCache = ['' => $operationsFolderId];

                foreach ($uploadedFiles as $uFile) {
                    $segments = explode('/', str_replace('\\', '/', $uFile['relative_path']));
                    $fileName = array_pop($segments);

                    $parentId = $operationsFolderId;
                    $pathKey = '';

                    foreach ($segments as $segment) {
                        if ($segment === '') continue;
                        $pathKey .= '/' . $segment;

                        if (!isset($folderCache[$pathKey])) {
                            $sub = $driveService->createSubFolder($parentId, $segment);
                            $folderCache[$pathKey] = $sub['id'];
                        }

                        $parentId = $folderCache[$pathKey];
                    }

                    $driveService->uploadFile($uFile['local_path'], $parentId, $fileName);
                }

                if (!empty($folderInfo['success'])) {
                    $db->prepare("UPDATE tasks SET 
                        drive_folder_link          = COALESCE(drive_folder_link, :link), 
                        drive_folder_id            = COALESCE(drive_folder_id, :fid),
                        drive_operations_folder_id = :ofid,
                        drive_operations_link      = :olink,
                        drive_client_folder_id     = COALESCE(drive_client_folder_id, :cfid),
                        drive_client_link          = COALESCE(drive_client_link, :clink),
                        drive_additional_folder_id = COALESCE(drive_additional_folder_id, :afid),
                        drive_additional_link      = COALESCE(drive_additional_link, :alink)
                    WHERE id = :id")->execute([
                        ':link'  => $folderInfo['task_folder_link'] ?? null,
                        ':fid'   => $folderInfo['task_folder_id'] ?? null,
                        ':ofid'  => $folderInfo['operations_folder']['id'] ?? null,
                        ':olink' => $folderInfo['operations_folder']['link'] ?? null,
                        ':cfid'  => $folderInfo['client_folder']['id'] ?? null,
                        ':clink' => $folderInfo['client_folder']['link'] ?? null,
                        ':afid'  => $folderInfo['additional_folder']['id'] ?? null,
                        ':alink' => $folderInfo['additional_folder']['link'] ?? null,
                        ':id'    => $task_id
                    ]);
                }
            }
        }
    } catch (\Exception $eDrive) {
        error_log("Google Drive background sync deferred for task {$task_key}: " . $eDrive->getMessage());
    }

    if (!headers_sent()) {
        header("Location: my_tasks.php?submitted=1");
    }
    exit;
}

/* ================= ACTIVE TASKS ================= */
$stmt = $db->prepare("
    SELECT t.*, u.username AS bde_username
    FROM tasks t
    JOIN users u ON t.created_by = u.id
    WHERE t.assigned_to = :user
    AND t.current_stage = 'operations_team'
    AND t.status IN ('assigned', 'rework')
    ORDER BY t.id DESC
");
$stmt->execute([':user'=>$user]);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ================= SUBMITTED TASKS (PENDING QC REVIEW) ================= */
$stmtPending = $db->prepare("
    SELECT t.*, u.username AS bde_username
    FROM tasks t
    JOIN users u ON t.created_by = u.id
    WHERE t.assigned_to = :user
    AND t.current_stage = 'qc'
    ORDER BY t.id DESC
");
$stmtPending->execute([':user'=>$user]);
$pendingQcTasks = $stmtPending->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Workflow | Expert Panel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
        .task-card { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .task-card:hover { transform: translateY(-4px); box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.05); }
        .input-focus:focus { border-color: #4f46e5; ring: 4px; ring-color: rgba(79, 70, 229, 0.1); outline: none; }
        .deadline-pulse { animation: pulse-red 2s infinite; }
        @keyframes pulse-red {
            0% { color: #ef4444; }
            50% { color: #991b1b; }
            100% { color: #ef4444; }
        }
        /* BDE Scrollbar style */
        .content-scroll::-webkit-scrollbar { width: 6px; }
        .content-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    </style>
</head>

<body class="text-slate-900">

<div class="flex min-h-screen">
    <?php include "partials/sidebar.php"; ?>

    <main class="flex-1 p-6 lg:p-10">
        
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-10 max-w-7xl mx-auto gap-4">
            <div>
                <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight flex items-center gap-3">
                    <div class="p-3 bg-indigo-600 rounded-2xl shadow-lg">
                        <span class="material-icons-round text-white">pending_actions</span>
                    </div>
                    Active Tasks Queue
                </h1>
                <p class="text-slate-500 mt-2 font-medium">Manage your assignments and submit your progress.</p>
            </div>
            
            <div class="bg-white px-5 py-3 rounded-2xl shadow-sm border border-slate-200 flex items-center gap-3">
                <span class="material-icons-round text-indigo-500">schedule</span>
                <span class="text-sm font-bold text-slate-600 tracking-tight"><?= date("d M, h:i A"); ?></span>
            </div>
        </div>

        <div class="max-w-7xl mx-auto space-y-8">
            <?php foreach($tasks as $t): ?>
            <div class="task-card bg-white rounded-[2.5rem] border border-slate-200 overflow-hidden shadow-sm">
                
                <div class="px-8 py-6 border-b border-slate-100 flex flex-wrap justify-between items-center bg-white">
                    <div class="flex items-center gap-4">
                        <div class="h-14 w-14 bg-slate-900 text-white rounded-2xl flex items-center justify-center font-black text-lg shadow-inner">
                            #<?= substr($t['task_key'], -3) ?>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h2 class="text-xl font-extrabold text-slate-800 tracking-tight"><?= htmlspecialchars($t['task_key']) ?></h2>
                                <?php if($t['urgent']=='yes'): ?>
                                <span class="bg-rose-100 text-rose-600 text-[10px] font-black px-2.5 py-1 rounded-lg uppercase tracking-widest border border-rose-200 animate-pulse">Urgent</span>
                                <?php endif; ?>
                                <?php if(!empty($t['additional_instructions'])): ?>
                                <span class="bg-amber-100 text-amber-900 border border-amber-300 text-[10px] font-black px-2.5 py-1 rounded-lg uppercase tracking-widest flex items-center gap-1 shadow-xs">
                                    <span class="material-icons-round text-xs text-amber-600">new_releases</span> Additional Info
                                </span>
                                <?php endif; ?>
                            </div>
                            <p class="text-sm text-slate-400 font-bold tracking-wide mt-0.5"><?= htmlspecialchars($t['task_name']) ?></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <span class="px-4 py-1.5 bg-indigo-50 text-indigo-600 rounded-xl text-[11px] font-black uppercase tracking-widest border border-indigo-100">
                            <?= $t['status'] ?>
                        </span>
                        <!-- Cross (❌) Button to return/drop task back to Operations Manager -->
                        <button type="button" 
                                onclick="confirmReturnTask(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['task_key'])) ?>')" 
                                class="inline-flex items-center gap-1 px-3 py-1.5 bg-rose-50 hover:bg-rose-600 text-rose-600 hover:text-white rounded-xl text-xs font-bold border border-rose-200 transition-all shadow-xs cursor-pointer group"
                                title="Release / Return this task back to Operations Manager">
                            <span class="material-icons-round text-sm group-hover:rotate-90 transition-transform">close</span>
                            <span>Return Task</span>
                        </button>
                    </div>
                </div>

<div class="p-8 grid md:grid-cols-4 gap-8 bg-white">
    <!-- COLUMN 1: VOLUME -->
    <div class="space-y-1">
        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest block">Volume</label>
        <p class="text-base font-bold text-slate-700"><?= number_format($t['word_count']) ?> Words</p>
    </div>

    <!-- COLUMN 2: UNIVERSITY & COUNTRY -->
<div class="space-y-1">
    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest block">Academic Info</label>
    <div class="text-[13px] font-bold text-slate-700 leading-tight">
        <!-- University -->
        <span class="text-indigo-500 font-black text-[9px] uppercase tracking-tighter">University:</span> 
        <?= !empty($t['university']) ? htmlspecialchars($t['university']) : 'N/A' ?>
        <br>

        <!-- Country -->
        <span class="text-indigo-500 font-black text-[9px] uppercase tracking-tighter">Country:</span> 
        <?= !empty($t['country']) ? htmlspecialchars($t['country']) : 'N/A' ?>
        <br>

        <!-- ✨ Client Name (Just niche add kiya) -->
        <span class="text-emerald-500 font-black text-[9px] uppercase tracking-tighter">Client:</span> 
        <span class="text-emerald-600 italic"><?= !empty($t['client_name']) ? htmlspecialchars($t['client_name']) : 'N/A' ?></span>
    </div>
</div>

    <!-- COLUMN 3: SUBJECT & DEADLINE -->
    <div class="space-y-1">
        <label class="text-[10px] font-black text-rose-400 uppercase tracking-widest block">Subject & Goal</label>
        <div class="text-[13px] font-bold text-slate-700 leading-tight">
            <span class="text-indigo-500 font-black text-[9px] uppercase tracking-tighter">Subject:</span> 
            <?= !empty($t['subject']) ? htmlspecialchars($t['subject']) : 'General' ?>
            <p class="text-sm font-black text-rose-600 deadline-pulse italic flex items-center gap-1 mt-1">
                <span class="material-icons-round text-base">timer</span>
                <?= date("d M, h:i A", strtotime($t['deadline'])) ?>
            </p>
        </div>
    </div>

   <!-- COLUMN 4: ACTION (BROWSE BRIEF) -->
<div class="flex flex-col justify-center items-end gap-2">
    
    <!-- 1. GOOGLE DRIVE - CLIENT FILES SUBFOLDER -->
    <?php $clientDrive = getDriveClientLink($t); ?>
    <?php if(!empty($clientDrive)): ?>
        <a href="<?= htmlspecialchars($clientDrive) ?>" 
           target="_blank" 
           title="Open Client Files in Drive"
           class="w-full flex items-center justify-center gap-2 bg-indigo-50 text-indigo-700 px-5 py-3 rounded-2xl text-xs font-bold hover:bg-indigo-600 hover:text-white transition-all shadow-sm border border-indigo-100">
            <span class="material-icons-round text-base">visibility</span>
            Browse Brief
        </a>
    <?php elseif(!empty($t['file_path']) && file_exists('../../' . $t['file_path'])): ?>
        <a href="../../<?= htmlspecialchars($t['file_path']) ?>" download
           title="Download Client Brief Package"
           class="w-full flex items-center justify-center gap-2 bg-indigo-50 text-indigo-700 px-5 py-3 rounded-2xl text-xs font-bold hover:bg-indigo-600 hover:text-white transition-all shadow-sm border border-indigo-100">
            <span class="material-icons-round text-base">download</span>
            Download Brief
        </a>
    <?php else: ?>
        <span class="text-[10px] font-bold text-slate-300 uppercase tracking-widest italic pr-4">No Brief Files</span>
    <?php endif; ?>

    <!-- 2. GOOGLE DRIVE - ADDITIONAL FILES LINK -->
    <?php $addDrive = getDriveAdditionalLink($t); ?>
    <?php if(!empty($addDrive)): ?>
        <a href="<?= htmlspecialchars($addDrive) ?>" target="_blank" 
           class="w-full flex items-center justify-center gap-2 bg-rose-50 text-rose-600 px-5 py-3 rounded-2xl text-xs font-bold hover:bg-rose-600 hover:text-white transition-all shadow-sm border border-rose-100"
           title="Open Additional Materials in Drive">
            <span class="material-icons-round text-base">folder_open</span>
            New Materials
        </a>
    <?php endif; ?>

</div>
</div>

                <div class="px-8 pb-8 grid grid-cols-1 md:grid-cols-2 gap-8 bg-white">
                    
                    <!-- BDE/Client Instructions -->
                    <div class="space-y-3">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest flex items-center gap-2 ml-1">
                            <span class="material-icons-round text-sm">description</span> Original Client Brief
                        </label>
                        <div class="p-5 rounded-[1.5rem] bg-slate-50 border border-slate-100 text-sm text-slate-600 leading-relaxed min-h-[100px] max-h-40 overflow-y-auto content-scroll shadow-inner">
                            <?= !empty($t['instructions']) ? nl2br(htmlspecialchars($t['instructions'])) : '<span class="text-slate-300 italic font-medium text-xs uppercase tracking-widest">No original instructions provided</span>' ?>
                        </div>

                        <!-- ⚡ Additional Instructions / Client Update (Highlighted) -->
                        <?php if (!empty($t['additional_instructions'])): ?>
                            <div class="p-5 rounded-[1.5rem] bg-amber-50/90 border-2 border-amber-300 shadow-sm relative overflow-hidden">
                                <span class="material-icons-round text-amber-200/50 absolute -right-2 -bottom-2 text-6xl pointer-events-none">notification_important</span>
                                <div class="flex items-center justify-between mb-2">
                                    <label class="text-[10px] font-black text-amber-900 uppercase tracking-widest flex items-center gap-1.5">
                                        <span class="material-icons-round text-sm text-amber-600">new_releases</span> Additional Information / Client Update
                                    </label>
                                    <span class="text-[9px] font-black uppercase px-2.5 py-0.5 rounded-full bg-amber-200 text-amber-900 border border-amber-300">
                                        ⚡ Updated
                                    </span>
                                </div>
                                <div class="text-xs text-amber-950 leading-relaxed font-semibold whitespace-pre-line relative z-10">
                                    <?= nl2br(htmlspecialchars($t['additional_instructions'])) ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Forwarded Client Feedback (From BDE via Operations Manager) -->
                        <?php if(!empty($t['feedback']) && ((int)($t['forwarded_feedback_to'] ?? 0) === (int)$_SESSION['user_id'] || (int)($t['assigned_to'] ?? 0) === (int)$_SESSION['user_id'])): ?>
                            <div class="p-5 rounded-[1.5rem] bg-indigo-50/70 border border-indigo-200/80 shadow-sm relative overflow-hidden">
                                <span class="material-icons-round text-indigo-200/50 absolute -right-2 -bottom-2 text-6xl pointer-events-none">rate_review</span>
                                <div class="flex items-center justify-between mb-2">
                                    <label class="text-[10px] font-black text-indigo-700 uppercase tracking-widest flex items-center gap-1.5">
                                        <span class="material-icons-round text-sm text-indigo-600">rate_review</span> Client Feedback
                                    </label>
                                    <span class="text-[9px] font-extrabold uppercase px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-800">Forwarded</span>
                                </div>
                                <p class="text-xs text-slate-700 leading-relaxed font-semibold italic whitespace-pre-line relative z-10">
                                    <?= nl2br(htmlspecialchars($t['feedback'])) ?>
                                </p>

                                <?php 
                                $fbFiles = [];
                                if (!empty($t['feedback_files'])) {
                                    $fbFiles = json_decode($t['feedback_files'], true) ?: [];
                                }
                                ?>
                                <?php if (!empty($fbFiles)): ?>
                                    <div class="mt-3 pt-2.5 border-t border-indigo-200/80 relative z-10">
                                        <p class="text-[9px] font-black uppercase tracking-wider text-indigo-800 mb-2 flex items-center gap-1">
                                            <span class="material-icons-round text-xs text-indigo-600">attach_file</span>
                                            Attached Files & Screenshots (<?= count($fbFiles) ?>)
                                        </p>
                                        
                                        <?php 
                                        $images = array_filter($fbFiles, fn($f) => !empty($f['is_image']));
                                        $docs = array_filter($fbFiles, fn($f) => empty($f['is_image']));
                                        ?>

                                        <?php if (!empty($images)): ?>
                                            <div class="flex flex-wrap gap-2 mb-2">
                                                <?php foreach ($images as $img): ?>
                                                    <div class="relative group/thumb rounded-xl overflow-hidden border border-indigo-200/90 bg-white shadow-xs hover:shadow-md transition-all">
                                                        <img src="../../<?= htmlspecialchars(ltrim($img['path'], '/')) ?>" 
                                                             alt="<?= htmlspecialchars($img['name']) ?>" 
                                                             onclick="openImagePreview('../../<?= htmlspecialchars(ltrim($img['path'], '/')) ?>', '<?= htmlspecialchars(addslashes($img['name'])) ?>')"
                                                             class="w-16 h-16 object-cover cursor-zoom-in group-hover/thumb:scale-105 transition-transform duration-200" 
                                                             loading="lazy">
                                                        <div class="absolute inset-0 bg-black/40 opacity-0 group-hover/thumb:opacity-100 transition-opacity flex items-center justify-center pointer-events-none">
                                                            <span class="material-icons-round text-white text-sm">zoom_in</span>
                                                        </div>
                                                        <a href="../../<?= htmlspecialchars(ltrim($img['path'], '/')) ?>" 
                                                           download="<?= htmlspecialchars($img['name']) ?>"
                                                           onclick="event.stopPropagation();"
                                                           class="absolute bottom-1 right-1 p-0.5 bg-white/90 hover:bg-white text-slate-700 rounded shadow-xs opacity-0 group-hover/thumb:opacity-100 transition-opacity"
                                                           title="Download Image">
                                                            <span class="material-icons-round text-[11px] block">download</span>
                                                        </a>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($docs)): ?>
                                            <div class="flex flex-col gap-1">
                                                <?php foreach ($docs as $doc): ?>
                                                    <a href="../../<?= htmlspecialchars(ltrim($doc['path'], '/')) ?>" 
                                                       download="<?= htmlspecialchars($doc['name']) ?>"
                                                       class="inline-flex items-center justify-between gap-2 px-2.5 py-1.5 rounded-lg bg-white/90 hover:bg-white border border-indigo-200 hover:border-indigo-300 transition group/doc text-slate-700 hover:text-indigo-900">
                                                        <span class="flex items-center gap-1.5 text-xs font-semibold truncate max-w-[200px]">
                                                            <span class="material-icons-round text-xs text-indigo-500">
                                                                <?= (in_array(strtolower($doc['ext'] ?? ''), ['pdf']) ? 'picture_as_pdf' : 'description') ?>
                                                            </span>
                                                            <span class="truncate"><?= htmlspecialchars($doc['name']) ?></span>
                                                        </span>
                                                        <span class="flex items-center gap-1 text-[9px] text-slate-400 group-hover/doc:text-indigo-600 font-bold shrink-0">
                                                            <span><?= htmlspecialchars($doc['size'] ?? '') ?></span>
                                                            <span class="material-icons-round text-xs">download</span>
                                                        </span>
                                                    </a>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Operations Manager Notes & Expert Query Box -->
                    <div class="space-y-3">
                        <label class="text-[10px] font-black text-indigo-500 uppercase tracking-widest flex items-center gap-2 ml-1">
                            <span class="material-icons-round text-sm">psychology</span> Manager's Specific Notes
                        </label>
                        <div class="p-5 rounded-[1.5rem] bg-indigo-50/30 border border-indigo-100 text-sm text-slate-700 leading-relaxed min-h-[90px] max-h-40 overflow-y-auto content-scroll font-medium shadow-inner">
                            <?= !empty($t['om_notes']) ? nl2br(htmlspecialchars($t['om_notes'])) : '<span class="text-indigo-300 italic font-medium text-xs uppercase tracking-widest">No specific manager notes</span>' ?>
                        </div>

                        <!-- Expert Query / Feedback Form -->
                        <div class="pt-2">
                            <form action="" method="POST" class="space-y-2">
                                <input type="hidden" name="action_type" value="submit_query">
                                <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                                <input type="hidden" name="task_key" value="<?= htmlspecialchars($t['task_key']) ?>">
                                
                                <label class="text-[10px] font-black text-amber-600 uppercase tracking-widest flex items-center gap-1.5 ml-1">
                                    <span class="material-icons-round text-sm text-amber-500">help_outline</span> Raise Query / Feedback to Manager
                                </label>
                                
                                <div class="flex gap-2">
                                    <input type="text" name="query_text" 
                                           placeholder="Type your query or note for Operations Manager..." 
                                           class="w-full bg-amber-50/40 border border-amber-200 px-4 py-2.5 rounded-2xl text-xs font-semibold text-slate-700 outline-none focus:border-amber-500 focus:bg-white transition-all shadow-sm" 
                                           required>
                                    <button type="submit" class="bg-amber-600 hover:bg-slate-900 text-white px-5 py-2.5 rounded-2xl text-xs font-black uppercase tracking-wider transition-all flex items-center gap-1.5 shadow-md shadow-amber-100 shrink-0">
                                        <span>Send</span>
                                        <span class="material-icons-round text-sm">send</span>
                                    </button>
                                </div>
                            </form>

                            <?php 
                            // Query previous expert queries for this task from task_queries table
                            $stmtLQ = $db->prepare("SELECT query_text, created_at FROM task_queries WHERE task_id = :tid AND user_id = :uid ORDER BY id DESC LIMIT 1");
                            $stmtLQ->execute([':tid' => $t['id'], ':uid' => $_SESSION['user_id']]);
                            $lastQuery = $stmtLQ->fetch(PDO::FETCH_ASSOC);
                            ?>
                            <?php if(!empty($lastQuery)): ?>
                                <div class="mt-2 p-3 rounded-2xl bg-amber-50 border border-amber-200/80 text-xs text-amber-900 flex items-start gap-2 shadow-sm">
                                    <span class="material-icons-round text-amber-500 text-base shrink-0 mt-0.5">chat_bubble</span>
                                    <div>
                                        <span class="text-[10px] font-black uppercase tracking-wider text-amber-700 block mb-0.5">Last Query Sent:</span>
                                        <p class="font-medium leading-relaxed"><?= nl2br(htmlspecialchars($lastQuery['query_text'])) ?></p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>

                <!-- BDE STYLE UPLOADER UI -->
                <div class="p-8 bg-slate-50/50 border-t border-slate-100">
                    <form action="" method="POST" enctype="multipart/form-data" class="space-y-8" id="form_<?= $t['id'] ?>" 
                          onsubmit="const btn = this.querySelector('button[type=submit]'); if (btn) { btn.disabled = true; btn.innerHTML = '<span class=\'material-icons-round animate-spin text-base\'>sync</span> Deploying to QC...'; }">
                        
                        <input type="hidden" name="task_id" value="<?= $t['id']?>">
                        <input type="hidden" name="task_key" value="<?= htmlspecialchars($t['task_key'])?>">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                            <!-- Notes Section -->
                            <div class="space-y-1">
                                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest block mb-2 ml-1">Work Notes (Optional)</label>
                                <textarea name="notes" rows="4" placeholder="Mention specific details about this submission or paste screenshots here..." 
                                          class="input-focus w-full bg-white border border-slate-200 p-5 rounded-[1.5rem] font-medium text-sm paste-receiver" 
                                          data-task-id="<?= $t['id'] ?>"></textarea>
                            </div>

                            <!-- Upload Section (BDE STYLE) -->
                            <div class="space-y-1">
                                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest block mb-2 ml-1">Attach Final Work (Files & Folders)</label>
                                <div class="p-8 bg-white rounded-[1.5rem] border-2 border-dashed border-slate-200 hover:border-indigo-400 transition-all flex flex-col items-center justify-center gap-4">
                                    <div class="flex flex-wrap justify-center gap-3">
                                        <label class="bg-indigo-600 hover:bg-slate-900 text-white px-6 py-3 rounded-2xl text-[11px] font-black cursor-pointer transition-all flex items-center gap-2 shadow-lg shadow-indigo-100">
                                            <span class="material-icons-round text-sm">upload_file</span> Choose Files
                                            <input type="file" multiple class="hidden" onchange="handleExpertSelection(this, <?= $t['id'] ?>)">
                                        </label>

                                        <label class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 px-6 py-3 rounded-2xl text-[11px] font-black cursor-pointer transition-all flex items-center gap-2 shadow-sm">
                                            <span class="material-icons-round text-sm text-indigo-600">drive_folder_upload</span> Upload Folder
                                            <input type="file" webkitdirectory directory multiple class="hidden" onchange="handleExpertSelection(this, <?= $t['id'] ?>)">
                                        </label>
                                    </div>
                                    <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest text-center">
                                        APK, SQL, ZIP Supported. Ctrl+V Active.
                                    </p>
                                    
                                    <!-- Preview Area inside dotted box -->
                                    <div id="file_preview_<?= $t['id'] ?>" class="w-full mt-2 space-y-2 max-h-40 overflow-y-auto content-scroll px-2"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <div class="flex justify-end">
                            <button type="submit" class="bg-emerald-600 hover:bg-slate-900 text-white px-12 py-4 rounded-2xl font-black text-sm uppercase tracking-widest shadow-xl shadow-emerald-100 transition-all hover:-translate-y-1 flex items-center gap-3">
                                Deploy to QC <span class="material-icons-round">rocket_launch</span>
                            </button>
                        </div>

                        <!-- Hidden Data Containers -->
                        <div id="path_inputs_<?= $t['id'] ?>"></div>
                        <div id="hidden_input_wrapper_<?= $t['id'] ?>" class="hidden"></div>
                    </form>
                </div>
            </div>
            <?php if(empty($tasks)): ?>
                <div class="bg-white rounded-[2.5rem] p-12 text-center border border-slate-200 shadow-sm">
                    <span class="material-icons-round text-6xl text-slate-300">task_alt</span>
                    <h3 class="text-lg font-bold text-slate-700 mt-3">All Clear! No Active Tasks</h3>
                    <p class="text-xs text-slate-400 font-medium mt-1">You have no tasks pending work right now.</p>
                </div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <!-- SUBMITTED TASKS (PENDING QC REVIEW) WITH RECALL / DELETE OPTION -->
        <?php if(!empty($pendingQcTasks)): ?>
        <div class="max-w-7xl mx-auto mt-16 space-y-6">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-2xl font-black text-slate-800 tracking-tight flex items-center gap-2">
                        <span class="material-icons-round text-amber-500">hourglass_top</span>
                        Submitted Tasks (Under QC Review)
                    </h2>
                    <p class="text-xs text-slate-400 font-medium mt-1">These tasks have been deployed to QC. You can recall & delete your submitted files anytime before QC approves.</p>
                </div>
                <span class="px-4 py-1.5 bg-amber-50 text-amber-700 rounded-xl text-xs font-black uppercase tracking-wider border border-amber-200 shadow-sm">
                    <?= count($pendingQcTasks) ?> In QC Review
                </span>
            </div>

            <div class="grid grid-cols-1 gap-4">
                <?php foreach($pendingQcTasks as $pt): ?>
                <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm flex flex-col md:flex-row items-start md:items-center justify-between gap-6 hover:border-amber-300 transition-all">
                    <div class="flex items-start gap-4">
                        <div class="h-12 w-12 bg-amber-50 text-amber-600 rounded-2xl flex items-center justify-center font-black text-sm border border-amber-100 shadow-inner">
                            <span class="material-icons-round text-amber-500">cloud_done</span>
                        </div>
                        <div>
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-sm font-black text-slate-900"><?= htmlspecialchars($pt['task_key']) ?></span>
                                <span class="text-xs font-bold text-slate-600">• <?= htmlspecialchars($pt['task_name']) ?></span>
                                <span class="px-2.5 py-0.5 bg-amber-100 text-amber-800 rounded-lg text-[10px] font-black uppercase tracking-wider">Awaiting QC</span>
                            </div>
                            <div class="flex items-center gap-4 mt-2 text-xs text-slate-400 font-medium">
                                <span><strong class="text-slate-600">Client:</strong> <?= htmlspecialchars($pt['client_name'] ?? 'N/A') ?></span>
                                <span><strong class="text-slate-600">Words:</strong> <?= number_format($pt['word_count']) ?></span>
                                <span><strong class="text-slate-600">BDE:</strong> <?= htmlspecialchars($pt['bde_username'] ?? '') ?></span>
                                <span><strong class="text-slate-600">Deadline:</strong> <?= date("d M, h:i A", strtotime($pt['deadline'])) ?></span>
                            </div>
                            <?php if(!empty($pt['team_notes'])): ?>
                                <p class="text-xs text-slate-500 mt-2 bg-slate-50 p-2.5 rounded-xl border border-slate-100 italic">
                                    <strong class="text-slate-700 not-italic">Your Submission Note:</strong> <?= htmlspecialchars($pt['team_notes']) ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="flex items-center gap-3 w-full md:w-auto justify-end flex-wrap">
                        <?php $opsDrive = getDriveOperationsLink($pt); ?>
                        <?php if(!empty($opsDrive)): ?>
                            <a href="<?= htmlspecialchars($opsDrive) ?>" target="_blank" title="View Submitted Files in Drive"
                               class="flex items-center gap-1.5 px-4 py-2.5 bg-emerald-50 text-emerald-700 hover:bg-emerald-600 hover:text-white rounded-xl text-xs font-bold transition-all border border-emerald-100">
                                <span class="material-icons-round text-sm">folder_open</span>
                                <span>Drive Files</span>
                            </a>
                        <?php endif; ?>

                        <!-- RECALL & DELETE SUBMISSION BUTTON -->
                        <button type="button" onclick="confirmRecall(<?= $pt['id'] ?>, '<?= htmlspecialchars($pt['task_key']) ?>')"
                                class="flex items-center gap-1.5 px-4 py-2.5 bg-rose-50 text-rose-600 hover:bg-rose-600 hover:text-white rounded-xl text-xs font-bold transition-all border border-rose-100 shadow-sm"
                                title="Recall and delete submitted work to upload again">
                            <span class="material-icons-round text-sm">delete_sweep</span>
                            <span>Recall & Re-upload</span>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </main>
</div>

<script>
let expertFilesStore = {};

function handleExpertSelection(input, taskId) {
    if (!expertFilesStore[taskId]) expertFilesStore[taskId] = [];
    for (let file of input.files) { expertFilesStore[taskId].push(file); }
    renderExpertUI(taskId);
}

// 📋 Paste Handler
document.querySelectorAll('.paste-receiver').forEach(receiver => {
    receiver.addEventListener('paste', function(e) {
        const taskId = this.getAttribute('data-task-id');
        const items = e.clipboardData.items;

        for (let i = 0; i < items.length; i++) {
            if (items[i].type.indexOf("image") !== -1) {
                const blob = items[i].getAsFile();
                const file = new File([blob], "expert_ss_" + Date.now() + ".png", { type: blob.type });
                
                if (!expertFilesStore[taskId]) expertFilesStore[taskId] = [];
                expertFilesStore[taskId].push(file);
                renderExpertUI(taskId);
            }
        }
    });
});

function renderExpertUI(taskId) {
    const preview = document.getElementById(`file_preview_${taskId}`);
    const pathContainer = document.getElementById(`path_inputs_${taskId}`);
    const hiddenWrapper = document.getElementById(`hidden_input_wrapper_${taskId}`);
    
    preview.innerHTML = "";
    pathContainer.innerHTML = "";
    hiddenWrapper.innerHTML = "";

    const dataTransfer = new DataTransfer();

    expertFilesStore[taskId].forEach((file, index) => {
        dataTransfer.items.add(file);
        let path = file.webkitRelativePath || file.name;
        
        pathContainer.innerHTML += `<input type="hidden" name="full_paths[]" value="${path}">`;
        
        preview.innerHTML += `
            <div class="flex justify-between items-center bg-slate-50 p-3 rounded-xl border border-slate-100 shadow-sm">
                <div class="flex items-center gap-3 truncate">
                    <span class="material-icons-round text-slate-400 text-sm">insert_drive_file</span>
                    <span class="text-[10px] font-bold text-slate-700 truncate">${path}</span>
                </div>
                <button type="button" onclick="removeExpertFile(${taskId}, ${index})" class="text-rose-500 hover:bg-rose-50 p-1.5 rounded-lg transition-all">
                    <span class="material-icons-round text-xs">close</span>
                </button>
            </div>`;
    });

    const finalInput = document.createElement("input");
    finalInput.type = "file";
    finalInput.name = "work_files[]";
    finalInput.multiple = true;
    finalInput.files = dataTransfer.files;
    hiddenWrapper.appendChild(finalInput);
}

function removeExpertFile(taskId, index) {
    expertFilesStore[taskId].splice(index, 1);
    renderExpertUI(taskId);
}

function confirmRecall(id, key) {
    Swal.fire({
        title: 'Recall Submission?',
        text: `Are you sure you want to recall ${key}? Your previous submitted files will be permanently deleted from QC and Google Drive, and the task will be moved back to your Active Queue so you can upload fresh files.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e11d48',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, Recall & Re-upload',
        cancelButtonText: 'Cancel',
        customClass: { popup: 'rounded-[2.5rem]' }
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = `recall_task.php?id=${id}`;
        }
    });
}

function confirmReturnTask(id, key) {
    Swal.fire({
        title: 'Return Task to Operations Manager?',
        html: `Are you sure you want to release and return <b>${key}</b> back to the Operations Manager?<br><span class="text-xs text-slate-500 mt-2 block">This task will be removed from your active queue and sent back to Operations Manager. Notifications will be sent to both you and the manager.</span>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e11d48',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, Return Task',
        cancelButtonText: 'Cancel',
        customClass: { popup: 'rounded-[2.5rem]' }
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = `return_task_action.php?id=${id}`;
        }
    });
}
</script>

<?php if(isset($_GET['msg']) && $_GET['msg'] === 'returned'): ?>
<script>
    Swal.fire({
        icon: 'success',
        title: 'Task Returned!',
        text: 'The task has been released and returned to the Operations Manager successfully.',
        confirmButtonColor: '#4f46e5',
        customClass: { popup: 'rounded-[2.5rem]' }
    });
    if (window.history.replaceState) {
        const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
        window.history.replaceState({ path: cleanUrl }, '', cleanUrl);
    }
</script>
<?php endif; ?>

<?php if(isset($_GET['msg']) && $_GET['msg'] === 'recalled'): ?>
<script>
    Swal.fire({
        icon: 'success',
        title: 'Submission Recalled!',
        text: 'Previous files have been removed from QC and Google Drive. The task is back in your Active Tasks Queue ready for re-upload.',
        confirmButtonColor: '#4f46e5',
        customClass: { popup: 'rounded-[2.5rem]' }
    });
</script>
<?php endif; ?>

<?php if(isset($_GET['query_sent']) || isset($_GET['submitted']) || isset($_GET['error'])): ?>
<script>
    // Clean the URL query parameters immediately so alerts don't re-trigger on refresh or back/forward navigation
    if (window.history.replaceState) {
        const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
        window.history.replaceState({ path: cleanUrl }, '', cleanUrl);
    }
</script>
<?php endif; ?>

<!-- Lightbox Modal for Image Preview -->
<div id="imageLightboxModal" class="fixed inset-0 z-50 hidden bg-black/80 backdrop-blur-sm flex items-center justify-center p-4" onclick="closeImageLightbox()">
    <div class="relative max-w-4xl max-h-[90vh] flex flex-col items-center" onclick="event.stopPropagation()">
        <button type="button" onclick="closeImageLightbox()" class="absolute -top-10 right-0 text-white/80 hover:text-white p-2">
            <span class="material-icons-round text-2xl">close</span>
        </button>
        <img id="lightboxImg" src="" alt="Preview" class="max-w-full max-h-[80vh] object-contain rounded-xl shadow-2xl">
        <div class="mt-3 flex items-center justify-between w-full text-white text-xs px-2">
            <span id="lightboxCaption" class="truncate font-medium"></span>
            <a id="lightboxDownload" href="" download class="inline-flex items-center gap-1 px-3 py-1 rounded bg-white/20 hover:bg-white/30 text-white font-bold transition">
                <span class="material-icons-round text-sm">download</span> Download
            </a>
        </div>
    </div>
</div>

<script>
function openImagePreview(src, name) {
    const modal = document.getElementById('imageLightboxModal');
    const img = document.getElementById('lightboxImg');
    const caption = document.getElementById('lightboxCaption');
    const dl = document.getElementById('lightboxDownload');
    if (!modal || !img) return;
    img.src = src;
    if (caption) caption.textContent = name || 'Image Preview';
    if (dl) { dl.href = src; dl.download = name || 'image'; }
    modal.classList.remove('hidden');
    document.body.classList.add('overflow-hidden');
}

function closeImageLightbox() {
    const modal = document.getElementById('imageLightboxModal');
    if (modal) {
        modal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    }
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeImageLightbox();
});
</script>

<?php if(isset($_GET['query_sent'])): ?>
<script>
    Swal.fire({
        icon: 'success',
        title: 'Query Sent!',
        text: 'Your query has been sent to the Operations Manager.',
        confirmButtonColor: '#d97706',
        customClass: { popup: 'rounded-[2.5rem]' }
    });
</script>
<?php endif; ?>

<?php if(isset($_GET['submitted'])): ?>
<script>
    Swal.fire({
        icon: 'success', title: 'Work Deployed!', text: 'Sent to QC Review.',
        confirmButtonColor: '#4f46e5', customClass: { popup: 'rounded-[2.5rem]' }
    });
</script>
<?php endif; ?>

<?php if(isset($_GET['error'])): ?>
<script>
    Swal.fire({
        icon: 'error',
        title: 'Action Failed',
        text: <?= json_encode($_GET['error'] === 'cannot_recall' ? 'This task has already been processed by QC and cannot be recalled.' : $_GET['error']) ?>,
        confirmButtonColor: '#e11d48',
        customClass: { popup: 'rounded-[2.5rem]' }
    });
</script>
<?php endif; ?>

</body>
</html>