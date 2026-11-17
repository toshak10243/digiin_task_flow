<?php
// Performance aur Folder Upload Limits
ini_set('memory_limit', '512M');
ini_set('upload_max_filesize', '256M');
ini_set('post_max_size', '256M');
ini_set('max_file_uploads', 300);
set_time_limit(300);

require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole('operations_team');

require_once "../../app/config/database.php";
require_once "../../bootstrap/app.php";

$db = (new Database())->connect();

// Auto-migrate: Ensure columns exist if not added yet on production
try {
    $db->exec("ALTER TABLE tasks ADD COLUMN forwarded_feedback_to INT NULL AFTER assigned_to");
} catch (Exception $e) {}
try {
    $db->exec("ALTER TABLE tasks ADD COLUMN feedback_read TINYINT(1) NOT NULL DEFAULT 0 AFTER feedback");
} catch (Exception $e) {}

/*
|--------------------------------------------------------------------------
| POST: Handle Mark Feedback as Read / Unread
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'mark_feedback_read') {
    header('Content-Type: application/json');
    $task_id = (int)($_POST['task_id'] ?? 0);
    $is_read = isset($_POST['is_read']) ? (int)$_POST['is_read'] : 1;

    if ($task_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid task ID']);
        exit;
    }

    try {
        $stmt = $db->prepare("UPDATE tasks SET feedback_read = :rd WHERE id = :id AND (assigned_to = :u OR forwarded_feedback_to = :u)");
        $stmt->execute([':rd' => $is_read, ':id' => $task_id, ':u' => $_SESSION['user_id']]);

        // Recalculate unread feedback count for this user
        $feedbackCount = $db->prepare("
            SELECT COUNT(*)
            FROM tasks
            WHERE (assigned_to = ? OR forwarded_feedback_to = ?)
            AND feedback IS NOT NULL
            AND feedback != ''
            AND COALESCE(feedback_read, 0) = 0
        ");
        $feedbackCount->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
        $newCount = (int)$feedbackCount->fetchColumn();

        echo json_encode([
            'success'     => true,
            'task_id'     => $task_id,
            'is_read'     => $is_read,
            'unread_count'=> $newCount
        ]);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/*
|--------------------------------------------------------------------------
| POST: Handle Feedback Work Submission (Option 2 - Version History in Drive)
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'submit_feedback_work') {
    $task_id   = (int)($_POST['task_id'] ?? 0);
    $task_key  = trim($_POST['task_key'] ?? '');
    $notes     = trim($_POST['notes'] ?? '');

    // Verify task belongs to current expert
    $stmtCheck = $db->prepare("SELECT * FROM tasks WHERE id = :id AND (assigned_to = :u OR forwarded_feedback_to = :u)");
    $stmtCheck->execute([':id' => $task_id, ':u' => $_SESSION['user_id']]);
    $task = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        header("Location: feedbacks.php?error=" . urlencode("Task not found or access denied."));
        exit;
    }

    $uploadedFiles = [];
    $safeTaskKey = preg_replace('/[^A-Za-z0-9\-_]/', '_', $task_key);
    $teamUploadDir = __DIR__ . "/../../uploads/team_work/" . $safeTaskKey . "_feedback/";

    // 1. Stage Uploaded Files locally
    if (isset($_FILES['work_files']) && !empty($_FILES['work_files']['name'][0])) {
        if (!is_dir($teamUploadDir)) {
            mkdir($teamUploadDir, 0777, true);
        }

        foreach ($_FILES['work_files']['name'] as $key => $name) {
            if ($_FILES['work_files']['error'][$key] === UPLOAD_ERR_OK) {
                $tmpPath = $_FILES['work_files']['tmp_name'][$key];
                $originalPath = $_POST['full_paths'][$key] ?? $name;
                $relativePath = str_replace('\\', '/', $originalPath);

                $targetPath = $teamUploadDir . $relativePath;
                $targetDir  = dirname($targetPath);
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0777, true);
                }

                if (move_uploaded_file($tmpPath, $targetPath)) {
                    $uploadedFiles[] = [
                        'local_path'    => $targetPath,
                        'relative_path' => $relativePath,
                        'name'          => basename($relativePath)
                    ];
                }
            }
        }
    }

    // 2. Package files into a standalone ZIP for backup
    $zipRelativePath = null;
    if (!empty($uploadedFiles)) {
        $zipName = "feedback_work_" . $safeTaskKey . "_" . time() . ".zip";
        $zipFullPath = __DIR__ . "/../../uploads/team_work/" . $zipName;

        $zip = new ZipArchive();
        if ($zip->open($zipFullPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            foreach ($uploadedFiles as $f) {
                if (file_exists($f['local_path'])) {
                    $zip->addFile($f['local_path'], $f['relative_path']);
                }
            }
            $zip->close();
            $zipRelativePath = "uploads/team_work/" . $zipName;
        }
    }

    // 3. Database Update: Move task to QC stage for review, update team notes and work file
    $appendNote = !empty($notes) ? "\n[Feedback Update " . date('d M Y') . "]: " . $notes : "";
    $stmtUpdate = $db->prepare("
        UPDATE tasks SET
            team_file     = COALESCE(:team_file, team_file),
            team_notes    = CONCAT(COALESCE(team_notes, ''), :append_note),
            current_stage = 'qc'
        WHERE id = :id
    ");
    $stmtUpdate->execute([
        ':team_file'   => $zipRelativePath,
        ':append_note' => $appendNote,
        ':id'          => $task_id
    ]);

    // Send notifications to Operations Manager & QC
    try {
        require_once "../../app/services/NotificationService.php";
        NotificationService::onFeedbackWorkSubmitted((int)$task_id, $task_key, $_SESSION['username'] ?? 'Expert');
    } catch (Exception $eNotif) {
        error_log("Feedback work notification failed: " . $eNotif->getMessage());
    }

    // FastCGI early flush if available (GoDaddy / Production instant response)
    if (function_exists('fastcgi_finish_request')) {
        header("Location: feedbacks.php?submitted=1");
        session_write_close();
        fastcgi_finish_request();
        ignore_user_abort(true);
        set_time_limit(300);
    }

    // 4. GOOGLE DRIVE SYNC (OPTION 2: Version History Backup)
    try {
        require_once "../../app/services/GoogleDriveService.php";
        $driveService = new GoogleDriveService();

        if ($driveService->isReady()) {
            $stmtTask = $db->prepare("SELECT drive_folder_id, drive_operations_folder_id FROM tasks WHERE id = :id");
            $stmtTask->execute([':id' => $task_id]);
            $taskFolderRow = $stmtTask->fetch(PDO::FETCH_ASSOC);

            $existingFolderId = $taskFolderRow['drive_folder_id'] ?? null;
            $folderInfo = $driveService->createTaskFolder($task_key, $existingFolderId ?: null);

            if (!empty($folderInfo['success'])) {
                $operationsFolderId = $folderInfo['operations_folder']['id'];

                // 🌟 OPTION 2: Archive previous existing files inside "Previous_Work_Before_Feedback_YYYY-MM-DD"
                $archiveFolderName = "Previous_Work_Before_Feedback_" . date('Y-m-d_Hi');
                $driveService->archiveFolderContents($operationsFolderId, $archiveFolderName);

                // Upload NEW fresh feedback files directly into Operations Files root folder
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

                // Update folder links in DB if missing
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
    } catch (\Exception $eDrive) {
        error_log("Google Drive background sync for feedback {$task_key}: " . $eDrive->getMessage());
    }

    if (!headers_sent()) {
        header("Location: feedbacks.php?submitted=1");
    }
    exit;
}

/*
|--------------------------------------------------------------------------
| Fetch ONLY Logged-in Expert Feedback
|--------------------------------------------------------------------------
*/
try {
    $stmt = $db->prepare("
        SELECT
            t.*,
            u.name AS member_name
        FROM tasks t
        LEFT JOIN users u
            ON t.created_by = u.id
        WHERE
            (t.assigned_to = :user OR t.forwarded_feedback_to = :user)
            AND t.feedback IS NOT NULL
            AND t.feedback != ''
        ORDER BY t.id DESC
    ");
    $stmt->execute([':user' => $_SESSION['user_id']]);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback if column does not exist
    $stmt = $db->prepare("
        SELECT
            t.*,
            u.name AS member_name
        FROM tasks t
        LEFT JOIN users u
            ON t.created_by = u.id
        WHERE
            t.assigned_to = :user
            AND t.feedback IS NOT NULL
            AND t.feedback != ''
        ORDER BY t.id DESC
    ");
    $stmt->execute([':user' => $_SESSION['user_id']]);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Feedbacks | Digiink Task Flow</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #f8fafc;
        }
        .feedback-card {
            transition: .30s;
        }
        .feedback-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 20px 30px rgba(0, 0, 0, .06);
        }
        .custom-shadow {
            box-shadow: 0 4px 15px rgba(0, 0, 0, .05);
        }
        .drive-btn {
            font-family: 'Inter', sans-serif;
            transition: all 0.2s ease;
        }
        .drive-btn:hover {
            transform: scale(1.02);
        }
    </style>
</head>

<body class="text-slate-900">

    <div class="flex min-h-screen">

        <?php include "partials/sidebar.php"; ?>

        <main class="flex-1 p-6 lg:p-10 min-w-0">

            <!-- Page Header -->
            <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-5 mb-8">

                <div>
                    <h1 class="text-3xl font-extrabold tracking-tight flex items-center gap-3">
                        <span class="p-2.5 bg-indigo-600 rounded-2xl shadow-lg shadow-indigo-100">
                            <span class="material-icons-round text-white">rate_review</span>
                        </span>
                        My Feedbacks & Revisions
                    </h1>
                    <p class="text-slate-500 mt-2 font-medium">
                        Review client feedback on assignments, upload updated files, and deploy directly to Operations Files.
                    </p>
                </div>

                <div class="relative w-full lg:w-80">
                    <span class="material-icons-round absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">search</span>
                    <input
                        id="search"
                        type="text"
                        placeholder="Search task or feedback..."
                        class="w-full bg-white border border-slate-200 rounded-2xl py-3 pl-10 pr-4 focus:ring-2 focus:ring-indigo-500 outline-none custom-shadow text-xs font-semibold">
                </div>

            </div>

            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">

                <div class="bg-white rounded-3xl p-6 border border-slate-200 custom-shadow border-l-4 border-l-indigo-500">
                    <p class="text-slate-400 text-xs font-bold uppercase tracking-wider">Total Feedbacks</p>
                    <h2 class="text-3xl font-black text-slate-800 mt-2">
                        <?= count($tasks) ?>
                    </h2>
                </div>

                <div class="bg-white rounded-3xl p-6 border border-slate-200 custom-shadow border-l-4 border-l-emerald-500">
                    <p class="text-slate-400 text-xs font-bold uppercase tracking-wider">Under QC Review</p>
                    <h2 class="text-3xl font-black text-emerald-600 mt-2">
                        <?= count(array_filter($tasks, fn($t) => $t['current_stage'] === 'qc')) ?>
                    </h2>
                </div>

                <div class="bg-white rounded-3xl p-6 border border-slate-200 custom-shadow border-l-4 border-l-amber-500">
                    <p class="text-slate-400 text-xs font-bold uppercase tracking-wider">Pending Action</p>
                    <h2 class="text-3xl font-black text-amber-600 mt-2">
                        <?= count(array_filter($tasks, fn($t) => $t['current_stage'] !== 'qc')) ?>
                    </h2>
                </div>

            </div>

            <!-- Feedback Cards Container -->
            <div id="feedbackContainer" class="grid grid-cols-1 xl:grid-cols-2 gap-8">

                <?php foreach ($tasks as $t):

                    $isUnderQc = ($t['current_stage'] === 'qc');
                    $clientDrive = getDriveClientLink($t);
                    $opsDrive    = getDriveOperationsLink($t);
                ?>

                    <div class="feedback-card bg-white rounded-[2rem] border border-slate-200 p-7 custom-shadow relative flex flex-col justify-between">

                        <div>
                            <!-- Card Header -->
                            <div class="flex justify-between items-start mb-5">

                                <div>
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="bg-indigo-50 text-indigo-700 text-[11px] font-black uppercase tracking-wider px-3 py-1 rounded-lg border border-indigo-100">
                                            <?= htmlspecialchars($t['task_key']) ?>
                                        </span>
                                        <?php if ($t['urgent'] == "yes"): ?>
                                            <span class="bg-rose-50 border border-rose-200 text-rose-600 text-[10px] font-extrabold uppercase px-2.5 py-0.5 rounded-lg animate-pulse">
                                                Urgent
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($t['forwarded_feedback_to']) && (int)$t['forwarded_feedback_to'] === (int)$_SESSION['user_id']): ?>
                                            <span class="bg-purple-50 border border-purple-200 text-purple-700 text-[10px] font-extrabold uppercase px-2.5 py-0.5 rounded-lg">
                                                Forwarded to you
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <h2 class="text-lg font-extrabold text-slate-800 leading-snug">
                                        <?= htmlspecialchars($t['task_name']) ?>
                                    </h2>
                                </div>

                                <div class="text-right">
                                    <p class="text-[10px] uppercase tracking-widest text-slate-400 font-black">Stage</p>
                                    <?php if ($isUnderQc): ?>
                                        <span class="inline-block mt-1 px-3 py-1 rounded-xl border text-[10px] font-black uppercase bg-amber-50 text-amber-700 border-amber-200">
                                            Under QC Review
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-block mt-1 px-3 py-1 rounded-xl border text-[10px] font-black uppercase bg-emerald-50 text-emerald-700 border-emerald-200">
                                            Action Needed
                                        </span>
                                    <?php endif; ?>
                                </div>

                            </div>

                            <!-- Client & Specs Info -->
                            <div class="flex flex-wrap items-center gap-4 mb-5 text-xs text-slate-600">
                                <div class="flex items-center gap-1.5 font-bold text-emerald-600 bg-emerald-50 px-2.5 py-1 rounded-lg">
                                    <span class="material-icons-round text-sm">person</span>
                                    <span><?= htmlspecialchars($t['client_name'] ?: 'N/A') ?></span>
                                </div>

                                <div class="flex items-center gap-1.5 font-bold text-slate-600 bg-slate-50 px-2.5 py-1 rounded-lg">
                                    <span class="material-icons-round text-sm text-slate-400">description</span>
                                    <span><?= number_format($t['word_count']) ?> Words</span>
                                </div>

                                <div class="flex items-center gap-1.5 font-bold text-indigo-600 bg-indigo-50 px-2.5 py-1 rounded-lg ml-auto">
                                    <span class="material-icons-round text-sm">event</span>
                                    <span><?= date("d M, Y", strtotime($t['deadline'])) ?></span>
                                </div>
                            </div>

                            <!-- Google Drive Buttons -->
                            <div class="flex gap-2.5 mb-6">
                                <?php if (!empty($clientDrive)): ?>
                                    <a href="<?= htmlspecialchars($clientDrive) ?>" target="_blank"
                                       class="drive-btn flex-1 flex items-center justify-center gap-1.5 py-2.5 bg-indigo-50 hover:bg-indigo-600 hover:text-white text-indigo-700 rounded-xl text-xs font-bold transition-all border border-indigo-100 shadow-xs">
                                        <span class="material-icons-round text-sm">folder_open</span>
                                        <span>Client Files</span>
                                    </a>
                                <?php endif; ?>

                                <?php if (!empty($opsDrive)): ?>
                                    <a href="<?= htmlspecialchars($opsDrive) ?>" target="_blank"
                                       class="drive-btn flex-1 flex items-center justify-center gap-1.5 py-2.5 bg-emerald-50 hover:bg-emerald-600 hover:text-white text-emerald-700 rounded-xl text-xs font-bold transition-all border border-emerald-100 shadow-xs">
                                        <span class="material-icons-round text-sm">folder_shared</span>
                                        <span>Operations Files</span>
                                    </a>
                                <?php endif; ?>
                            </div>

                            <!-- Client Feedback Section (Full view) -->
                            <div class="bg-slate-50 rounded-2xl border border-slate-200 p-5 relative overflow-hidden mb-5">
                                <span class="material-icons-round absolute -right-3 -bottom-3 text-[90px] text-slate-200 opacity-20 pointer-events-none">
                                    format_quote
                                </span>

                                <div class="flex justify-between items-center mb-3">
                                    <p class="text-[10px] font-black uppercase tracking-[1.5px] text-indigo-700 flex items-center gap-1.5">
                                        <span class="material-icons-round text-sm">reviews</span>
                                        Client Feedback
                                    </p>
                                    <button onclick="copyFeedback(<?= $t['id'] ?>, this)"
                                            class="p-1.5 rounded-lg border border-slate-200 bg-white hover:bg-indigo-600 hover:text-white transition"
                                            title="Copy feedback text">
                                        <span class="material-icons-round text-xs">content_copy</span>
                                    </button>
                                </div>

                                <div id="feedbackText-<?= $t['id'] ?>" class="text-xs leading-relaxed text-slate-700 whitespace-pre-line font-medium pr-6">
                                    <?= htmlspecialchars($t['feedback']) ?>
                                </div>

                                <?php 
                                $fbFiles = [];
                                if (!empty($t['feedback_files'])) {
                                    $fbFiles = json_decode($t['feedback_files'], true) ?: [];
                                }
                                ?>
                                <?php if (!empty($fbFiles)): ?>
                                    <div class="mt-4 pt-3 border-t border-slate-200/80 relative z-10">
                                        <p class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-2.5 flex items-center gap-1.5">
                                            <span class="material-icons-round text-xs text-indigo-500">attach_file</span>
                                            Attached Screenshots & Files (<?= count($fbFiles) ?>)
                                        </p>
                                        
                                        <?php 
                                        $images = array_filter($fbFiles, fn($f) => !empty($f['is_image']));
                                        $docs = array_filter($fbFiles, fn($f) => empty($f['is_image']));
                                        ?>

                                        <?php if (!empty($images)): ?>
                                            <div class="flex flex-wrap gap-2.5 mb-2.5">
                                                <?php foreach ($images as $img): ?>
                                                    <div class="relative group/thumb rounded-xl overflow-hidden border border-slate-200 bg-white shadow-xs hover:shadow-md transition-all">
                                                        <img src="../../<?= htmlspecialchars(ltrim($img['path'], '/')) ?>" 
                                                             alt="<?= htmlspecialchars($img['name']) ?>" 
                                                             onclick="openImagePreview('../../<?= htmlspecialchars(ltrim($img['path'], '/')) ?>', '<?= htmlspecialchars(addslashes($img['name'])) ?>')"
                                                             class="w-20 h-20 object-cover cursor-zoom-in group-hover/thumb:scale-105 transition-transform duration-200" 
                                                             loading="lazy">
                                                        <div class="absolute inset-0 bg-black/40 opacity-0 group-hover/thumb:opacity-100 transition-opacity flex items-center justify-center gap-1 pointer-events-none">
                                                            <span class="material-icons-round text-white text-base">zoom_in</span>
                                                        </div>
                                                        <a href="../../<?= htmlspecialchars(ltrim($img['path'], '/')) ?>" 
                                                           download="<?= htmlspecialchars($img['name']) ?>"
                                                           onclick="event.stopPropagation();"
                                                           class="absolute bottom-1 right-1 p-1 bg-white/90 hover:bg-white text-slate-700 rounded shadow-xs text-[10px] opacity-0 group-hover/thumb:opacity-100 transition-opacity"
                                                           title="Download Image">
                                                            <span class="material-icons-round text-xs block">download</span>
                                                        </a>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($docs)): ?>
                                            <div class="flex flex-col gap-1.5">
                                                <?php foreach ($docs as $doc): ?>
                                                    <a href="../../<?= htmlspecialchars(ltrim($doc['path'], '/')) ?>" 
                                                       download="<?= htmlspecialchars($doc['name']) ?>"
                                                       class="inline-flex items-center justify-between gap-2 px-3 py-1.5 rounded-lg bg-white hover:bg-indigo-50 border border-slate-200 hover:border-indigo-200 transition group/doc text-slate-700 hover:text-indigo-900">
                                                        <span class="flex items-center gap-2 text-xs font-semibold truncate max-w-[240px]">
                                                            <span class="material-icons-round text-sm text-indigo-500">
                                                                <?= (in_array(strtolower($doc['ext'] ?? ''), ['pdf']) ? 'picture_as_pdf' : 'description') ?>
                                                            </span>
                                                            <span class="truncate"><?= htmlspecialchars($doc['name']) ?></span>
                                                        </span>
                                                        <span class="flex items-center gap-1 text-[10px] text-slate-400 group-hover/doc:text-indigo-600 font-bold shrink-0">
                                                            <span><?= htmlspecialchars($doc['size'] ?? '') ?></span>
                                                            <span class="material-icons-round text-xs">download</span>
                                                        </span>
                                                    </a>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Mark as Read / Unread Action -->
                                <?php $isFeedbackRead = !empty($t['feedback_read']) && (int)$t['feedback_read'] === 1; ?>
                                <div class="mt-4 pt-3 border-t border-slate-200/80 flex items-center justify-between">
                                    <div class="flex items-center gap-1.5" id="readBadge-<?= $t['id'] ?>">
                                        <?php if ($isFeedbackRead): ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider bg-emerald-100 text-emerald-800">
                                                <span class="material-icons-round text-xs">done_all</span> Read
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider bg-rose-100 text-rose-700 animate-pulse">
                                                <span class="material-icons-round text-xs">mark_email_unread</span> New Feedback
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <button type="button"
                                            onclick="toggleFeedbackRead(<?= $t['id'] ?>, this)"
                                            data-is-read="<?= $isFeedbackRead ? '1' : '0' ?>"
                                            id="readBtn-<?= $t['id'] ?>"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold transition-all shadow-xs <?= $isFeedbackRead ? 'bg-slate-100 text-slate-600 hover:bg-slate-200' : 'bg-emerald-600 text-white hover:bg-emerald-700' ?>"
                                            title="<?= $isFeedbackRead ? 'Mark as Unread' : 'Mark as Read (Remove notification badge)' ?>">
                                        <span class="material-icons-round text-sm"><?= $isFeedbackRead ? 'undo' : 'check_circle' ?></span>
                                        <span class="read-btn-text"><?= $isFeedbackRead ? 'Mark Unread' : 'Mark as Read' ?></span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Card Action: Upload Solution or Review Status -->
                        <div class="pt-2 border-t border-slate-100">
                            <?php if ($isUnderQc): ?>
                                <div class="flex items-center justify-between bg-amber-50/70 p-3.5 rounded-2xl border border-amber-200">
                                    <div class="flex items-center gap-2">
                                        <span class="material-icons-round text-amber-600 text-lg">hourglass_top</span>
                                        <div>
                                            <p class="text-xs font-bold text-amber-900">Work Submitted for QC Review</p>
                                            <p class="text-[11px] text-amber-700">Previous work archived in Drive & updated work uploaded.</p>
                                        </div>
                                    </div>
                                    <button type="button" onclick="openUploadModal(<?= htmlspecialchars(json_encode([
                                        'id' => $t['id'],
                                        'task_key' => $t['task_key'],
                                        'task_name' => $t['task_name']
                                    ])) ?>)" class="text-xs font-black text-indigo-600 hover:text-indigo-800 underline">
                                        Re-upload Files
                                    </button>
                                </div>
                            <?php else: ?>
                                <button type="button" 
                                        onclick="openUploadModal(<?= htmlspecialchars(json_encode([
                                            'id' => $t['id'],
                                            'task_key' => $t['task_key'],
                                            'task_name' => $t['task_name']
                                        ])) ?>)"
                                        class="w-full bg-indigo-600 hover:bg-slate-900 text-white py-3.5 px-6 rounded-2xl font-bold text-xs uppercase tracking-wider shadow-lg shadow-indigo-100 transition-all flex items-center justify-center gap-2">
                                    <span class="material-icons-round text-sm">cloud_upload</span>
                                    Upload Feedback Work to Drive
                                </button>
                            <?php endif; ?>
                        </div>

                    </div>

                <?php endforeach; ?>

                <!-- Empty State -->
                <?php if (count($tasks) == 0): ?>
                    <div class="col-span-full">
                        <div class="bg-white rounded-[3rem] border-2 border-dashed border-slate-200 py-28 flex flex-col items-center justify-center custom-shadow">
                            <div class="w-20 h-20 rounded-full bg-slate-100 flex items-center justify-center mb-4 text-slate-400">
                                <span class="material-icons-round text-5xl">rate_review</span>
                            </div>
                            <h2 class="text-xl font-bold text-slate-700">No Feedback Received</h2>
                            <p class="text-slate-400 text-xs mt-2 font-medium">
                                You haven't received any feedback on your assignments yet.
                            </p>
                        </div>
                    </div>
                <?php endif; ?>

            </div>

        </main>

    </div>

    <!-- UPLOAD WORK MODAL (Option 2 - Backs up old files and puts fresh files in Operations Folder) -->
    <div id="uploadModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-2xl rounded-[2.5rem] p-7 shadow-2xl border border-slate-100 transform transition-all animate-in fade-in zoom-in duration-200 max-h-[90vh] overflow-y-auto">
            
            <div class="flex justify-between items-start mb-5 pb-3 border-b border-slate-100">
                <div>
                    <span id="modalTaskKey" class="text-xs font-black uppercase tracking-wider text-indigo-600 bg-indigo-50 px-2.5 py-1 rounded-md">
                        TASK-KEY
                    </span>
                    <h3 class="text-lg font-black text-slate-800 mt-2" id="modalTaskName">Task Name</h3>
                    <p class="text-xs text-slate-400 font-medium mt-0.5">Upload corrected files based on feedback. Previous files will be safely archived into a backup folder in Google Drive.</p>
                </div>
                <button type="button" onclick="closeUploadModal()" class="p-2 text-slate-400 hover:text-slate-700 rounded-xl hover:bg-slate-100 transition">
                    <span class="material-icons-round">close</span>
                </button>
            </div>

            <form id="feedbackUploadForm" action="" method="POST" enctype="multipart/form-data" class="space-y-5">
                <input type="hidden" name="action_type" value="submit_feedback_work">
                <input type="hidden" name="task_id" id="modalTaskId">
                <input type="hidden" name="task_key" id="modalTaskKeyInput">

                <!-- Work Notes -->
                <div class="space-y-1.5">
                    <label class="text-[11px] font-black text-slate-500 uppercase tracking-wider block">
                        Work Notes / Explanation of Changes
                    </label>
                    <textarea name="notes" id="modalNotes" rows="3" 
                              placeholder="Mention what feedback changes you incorporated (e.g. Added section 3, revised citations, fixed calculation)..." 
                              class="w-full bg-slate-50 border border-slate-200 p-3.5 rounded-2xl text-xs font-medium focus:ring-2 focus:ring-indigo-500 outline-none"></textarea>
                </div>

                <!-- Dropzone / Attach Final Work -->
                <div class="space-y-1.5">
                    <label class="text-[11px] font-black text-slate-500 uppercase tracking-wider block">
                        Attach Corrected Files / Folders <span class="text-rose-500">*</span>
                    </label>

                    <div class="p-6 bg-slate-50 rounded-2xl border-2 border-dashed border-slate-200 hover:border-indigo-400 transition-all flex flex-col items-center justify-center gap-3">
                        <div class="flex flex-wrap justify-center gap-2.5">
                            <label class="bg-indigo-600 hover:bg-slate-900 text-white px-5 py-2.5 rounded-xl text-xs font-black cursor-pointer transition-all flex items-center gap-1.5 shadow-md shadow-indigo-100">
                                <span class="material-icons-round text-sm">upload_file</span> Choose Files
                                <input type="file" multiple class="hidden" onchange="handleModalFileSelection(this)">
                            </label>

                            <label class="bg-white border border-slate-200 hover:bg-slate-100 text-slate-700 px-5 py-2.5 rounded-xl text-xs font-black cursor-pointer transition-all flex items-center gap-1.5 shadow-xs">
                                <span class="material-icons-round text-sm text-indigo-600">drive_folder_upload</span> Upload Folder
                                <input type="file" webkitdirectory directory multiple class="hidden" onchange="handleModalFileSelection(this)">
                            </label>
                        </div>
                        <p class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">
                            Files will be moved to Operations Files & previous files archived into "Previous_Work_Before_Feedback"
                        </p>
                        
                        <!-- Preview Container -->
                        <div id="modalFilePreview" class="w-full space-y-2 max-h-40 overflow-y-auto px-1"></div>
                    </div>
                </div>

                <!-- Hidden Containers for dynamic DataTransfer inputs -->
                <div id="modalPathInputs"></div>
                <div id="modalHiddenWrapper" class="hidden"></div>

                <!-- Modal Actions -->
                <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                    <button type="button" onclick="closeUploadModal()" class="px-5 py-2.5 text-xs font-bold text-slate-500 hover:text-slate-800 rounded-xl hover:bg-slate-100 transition">
                        Cancel
                    </button>
                    <button type="submit" id="submitFeedbackBtn" class="bg-emerald-600 hover:bg-slate-900 text-white px-7 py-3 rounded-xl font-black text-xs uppercase tracking-wider shadow-lg shadow-emerald-100 transition-all flex items-center gap-2">
                        <span>Deploy Work to Drive</span>
                        <span class="material-icons-round text-sm">rocket_launch</span>
                    </button>
                </div>
            </form>

        </div>
    </div>

    <script>
        // Search Functionality
        const search = document.getElementById("search");
        search.addEventListener("keyup", function () {
            const value = this.value.toLowerCase();
            const cards = document.querySelectorAll(".feedback-card");
            cards.forEach(card => {
                const text = card.innerText.toLowerCase();
                card.style.display = text.includes(value) ? "flex" : "none";
            });
        });

        // Copy Feedback
        function copyFeedback(id, button) {
            const text = document.getElementById("feedbackText-" + id).innerText;
            navigator.clipboard.writeText(text);

            const old = button.innerHTML;
            button.innerHTML = `<span class="material-icons-round text-xs text-emerald-600">done</span>`;

            setTimeout(() => {
                button.innerHTML = old;
            }, 1800);
        }

        // Toggle Feedback Read / Unread
        function toggleFeedbackRead(taskId, button) {
            const currentIsRead = button.dataset.isRead === '1';
            const nextIsRead = currentIsRead ? 0 : 1;
            const badgeContainer = document.getElementById('readBadge-' + taskId);

            button.disabled = true;
            const originalHtml = button.innerHTML;
            button.innerHTML = `<span class="material-icons-round text-xs animate-spin">refresh</span> <span>Updating...</span>`;

            const formData = new FormData();
            formData.append('action_type', 'mark_feedback_read');
            formData.append('task_id', taskId);
            formData.append('is_read', nextIsRead);

            fetch('feedbacks.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                button.disabled = false;
                if (data.success) {
                    button.dataset.isRead = nextIsRead ? '1' : '0';

                    if (nextIsRead) {
                        button.className = 'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold transition-all shadow-xs bg-slate-100 text-slate-600 hover:bg-slate-200';
                        button.innerHTML = `<span class="material-icons-round text-sm">undo</span><span class="read-btn-text">Mark Unread</span>`;
                        button.title = 'Mark as Unread';
                        if (badgeContainer) {
                            badgeContainer.innerHTML = `<span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider bg-emerald-100 text-emerald-800"><span class="material-icons-round text-xs">done_all</span> Read</span>`;
                        }
                    } else {
                        button.className = 'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold transition-all shadow-xs bg-emerald-600 text-white hover:bg-emerald-700';
                        button.innerHTML = `<span class="material-icons-round text-sm">check_circle</span><span class="read-btn-text">Mark as Read</span>`;
                        button.title = 'Mark as Read (Remove notification badge)';
                        if (badgeContainer) {
                            badgeContainer.innerHTML = `<span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider bg-rose-100 text-rose-700 animate-pulse"><span class="material-icons-round text-xs">mark_email_unread</span> New Feedback</span>`;
                        }
                    }

                    // Dynamically update Sidebar badge if present on screen
                    const sidebarBadge = document.querySelector('a[href="feedbacks.php"] .bg-indigo-500, a[href="feedbacks.php"] span[class*="rounded-full"]');
                    if (sidebarBadge) {
                        if (data.unread_count > 0) {
                            sidebarBadge.textContent = data.unread_count;
                            sidebarBadge.classList.remove('hidden');
                        } else {
                            sidebarBadge.classList.add('hidden');
                        }
                    }

                    const Toast = Swal.mixin({
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 2000
                    });
                    Toast.fire({
                        icon: 'success',
                        title: nextIsRead ? 'Marked as Read' : 'Marked as Unread'
                    });
                } else {
                    button.innerHTML = originalHtml;
                    Swal.fire({
                        icon: 'error',
                        title: 'Update Failed',
                        text: data.error || 'Could not update read status.',
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 3000
                    });
                }
            })
            .catch(err => {
                button.disabled = false;
                button.innerHTML = originalHtml;
                Swal.fire({
                    icon: 'error',
                    title: 'Network Error',
                    text: 'Please retry.',
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 3000
                });
            });
        }

        // Modal State & Files Store
        let modalFilesStore = [];

        function openUploadModal(taskData) {
            document.getElementById('modalTaskId').value = taskData.id;
            document.getElementById('modalTaskKeyInput').value = taskData.task_key;
            document.getElementById('modalTaskKey').textContent = taskData.task_key;
            document.getElementById('modalTaskName').textContent = taskData.task_name;
            document.getElementById('modalNotes').value = '';
            
            modalFilesStore = [];
            renderModalUI();

            document.getElementById('uploadModal').classList.remove('hidden');
        }

        function closeUploadModal() {
            document.getElementById('uploadModal').classList.add('hidden');
            modalFilesStore = [];
            renderModalUI();
        }

        function handleModalFileSelection(input) {
            for (let file of input.files) {
                modalFilesStore.push(file);
            }
            renderModalUI();
        }

        function renderModalUI() {
            const preview = document.getElementById('modalFilePreview');
            const pathContainer = document.getElementById('modalPathInputs');
            const hiddenWrapper = document.getElementById('modalHiddenWrapper');

            preview.innerHTML = '';
            pathContainer.innerHTML = '';
            hiddenWrapper.innerHTML = '';

            const dataTransfer = new DataTransfer();

            modalFilesStore.forEach((file, index) => {
                dataTransfer.items.add(file);
                let path = file.webkitRelativePath || file.name;

                pathContainer.innerHTML += `<input type="hidden" name="full_paths[]" value="${path}">`;

                preview.innerHTML += `
                    <div class="flex justify-between items-center bg-white p-2.5 rounded-xl border border-slate-200 shadow-xs">
                        <div class="flex items-center gap-2 truncate">
                            <span class="material-icons-round text-slate-400 text-sm">insert_drive_file</span>
                            <span class="text-[11px] font-bold text-slate-700 truncate">${path}</span>
                        </div>
                        <button type="button" onclick="removeModalFile(${index})" class="text-rose-500 hover:bg-rose-50 p-1 rounded-lg transition-all">
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

        function removeModalFile(index) {
            modalFilesStore.splice(index, 1);
            renderModalUI();
        }

        // Validate on submit
        document.getElementById('feedbackUploadForm').addEventListener('submit', function(e) {
            if (modalFilesStore.length === 0) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'No Files Attached',
                    text: 'Please select at least one corrected file to upload.',
                    confirmButtonColor: '#4f46e5'
                });
                return false;
            }

            const btn = document.getElementById('submitFeedbackBtn');
            btn.disabled = true;
            btn.innerHTML = `<span class="material-icons-round text-sm animate-spin">refresh</span> Uploading & Archiving in Drive...`;
        });
    </script>

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

    <?php if(isset($_GET['submitted'])): ?>
    <script>
        if (window.history.replaceState) {
            const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
            window.history.replaceState({ path: cleanUrl }, '', cleanUrl);
        }
        Swal.fire({
            icon: 'success',
            title: 'Feedback Work Deployed!',
            text: 'Your updated work files have been uploaded to Operations Files in Google Drive, and previous files were safely archived into a backup folder.',
            confirmButtonColor: '#10b981',
            customClass: { popup: 'rounded-[2.5rem]' }
        });
    </script>
    <?php endif; ?>

    <?php if(isset($_GET['error'])): ?>
    <script>
        if (window.history.replaceState) {
            const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
            window.history.replaceState({ path: cleanUrl }, '', cleanUrl);
        }
        Swal.fire({
            icon: 'error',
            title: 'Submission Failed',
            text: <?= json_encode($_GET['error']) ?>,
            confirmButtonColor: '#e11d48',
            customClass: { popup: 'rounded-[2.5rem]' }
        });
    </script>
    <?php endif; ?>

</body>

</html>