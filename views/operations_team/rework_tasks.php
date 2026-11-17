<?php
// 🚀 Performance aur Folder Upload Limits
ini_set('memory_limit', '512M');
ini_set('max_file_uploads', '500');
set_time_limit(300);

require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole('operations_team');
require_once "../../app/config/database.php";
require_once "../../bootstrap/app.php";

date_default_timezone_set('Asia/Kolkata');
$db = (new Database())->connect();
$user = $_SESSION['user_id'];

/* ================= Helper: resolve/create nested Drive subfolders =================
   Given a root folder id and an array of path segments (e.g. ['Assets','Logos']),
   walks down the tree using createSubFolder() for each segment, caching resolved
   ids per accumulated path so files sharing a folder don't create duplicates
   within the same request. */
function resolveDriveFolder($drive, $rootId, $pathSegments, &$cache) {
    $currentId = $rootId;
    $accumulatedPath = '';
    foreach ($pathSegments as $segment) {
        if ($segment === '') continue;
        $accumulatedPath .= '/' . $segment;
        if (isset($cache[$accumulatedPath])) {
            $currentId = $cache[$accumulatedPath];
            continue;
        }
        $sub = $drive->createSubFolder($currentId, $segment);
        $currentId = $sub['id'];
        $cache[$accumulatedPath] = $currentId;
    }
    return $currentId;
}

/* ================= BACKEND: RESUBMIT REWORK LOGIC ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $task_id   = $_POST['task_id'] ?? null;
        $notes     = $_POST['team_notes'] ?? '';
        $taskKey   = $_POST['task_key'] ?? '';
        $fullPaths = $_POST['full_paths'] ?? [];

        $blockedExt = ['php', 'phtml', 'php3', 'php4', 'php5', 'htaccess', 'cgi'];

        if (!isset($_FILES['work_files']) || empty($_FILES['work_files']['name'][0])) {
            throw new Exception("Upload at least one file.");
        }

        // 1. High-speed local staging (Preserves subfolder hierarchy)
        $safeTaskKey = preg_replace('/[^A-Za-z0-9\-_]/', '_', $taskKey);
        $uploadDir   = __DIR__ . "/../../uploads/team_work/" . $safeTaskKey . "/";
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $uploadedFiles = [];
        foreach ($_FILES['work_files']['name'] as $key => $name) {
            if ($_FILES['work_files']['error'][$key] != 0) continue;

            $tmp = $_FILES['work_files']['tmp_name'][$key];
            $originalRelativePath = $fullPaths[$key] ?? basename($name);
            $ext = strtolower(pathinfo($originalRelativePath, PATHINFO_EXTENSION));

            if (in_array($ext, $blockedExt)) {
                throw new Exception("Security: .$ext files are restricted.");
            }

            $relativePath = str_replace('\\', '/', $originalRelativePath);
            $targetLocalPath = $uploadDir . $relativePath;
            $targetLocalDir  = dirname($targetLocalPath);
            if (!is_dir($targetLocalDir)) {
                mkdir($targetLocalDir, 0777, true);
            }

            if (move_uploaded_file($tmp, $targetLocalPath)) {
                $uploadedFiles[] = [
                    'local_path'    => $targetLocalPath,
                    'relative_path' => $relativePath,
                    'name'          => basename($relativePath)
                ];
            }
        }

        if (empty($uploadedFiles)) {
            throw new Exception("Upload at least one valid file.");
        }

        // 2. Build local ZIP backup for immediate 1-click download by QC
        $zipRelativePath = null;
        if (class_exists('ZipArchive') && count($uploadedFiles) > 0) {
            $zipName = $safeTaskKey . "_rework_" . time() . ".zip";
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

        // 3. INSTANT UPDATE: Set current_stage to 'qc' and update notes
        $stmt = $db->prepare("UPDATE tasks SET 
                team_notes = :notes, 
                current_stage = 'qc',
                team_file = COALESCE(:team_file, team_file)
            WHERE id = :id");
        $stmt->execute([
            ':notes'     => $notes, 
            ':team_file' => $zipRelativePath,
            ':id'        => $task_id
        ]);

        // Send immediate response to browser if FastCGI is active (GoDaddy / Production)
        if (function_exists('fastcgi_finish_request')) {
            header("Location: rework_tasks.php?done=1");
            session_write_close();
            fastcgi_finish_request();
            ignore_user_abort(true);
            set_time_limit(300);
        }

        // 4. Safe Google Drive Sync (Non-blocking / Resilient)
        try {
            require_once "../../app/services/GoogleDriveService.php";
            $drive = new GoogleDriveService();

            if ($drive->isReady()) {
                $stmtTask = $db->prepare("SELECT drive_folder_id FROM tasks WHERE id = :id");
                $stmtTask->execute([':id' => $task_id]);
                $existingFolderId = $stmtTask->fetchColumn();

                $folders = $drive->createTaskFolder($taskKey, $existingFolderId ?: null);

                if (!empty($folders['success'])) {
                    $operationsFolderId = $folders['operations_folder']['id'];
                    $folderIdCache = ['' => $operationsFolderId];

                    foreach ($uploadedFiles as $uFile) {
                        $segments = explode('/', $uFile['relative_path']);
                        $fileName = array_pop($segments);
                        $targetFolderId = $operationsFolderId;

                        if (!empty($segments)) {
                            $targetFolderId = resolveDriveFolder($drive, $operationsFolderId, $segments, $folderIdCache);
                        }

                        $drive->uploadFile($uFile['local_path'], $targetFolderId, $fileName);
                    }

                    if (!empty($folders['success'])) {
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
                            ':link'  => $folders['task_folder_link'] ?? null,
                            ':fid'   => $folders['task_folder_id'] ?? null,
                            ':ofid'  => $folders['operations_folder']['id'] ?? null,
                            ':olink' => $folders['operations_folder']['link'] ?? null,
                            ':cfid'  => $folders['client_folder']['id'] ?? null,
                            ':clink' => $folders['client_folder']['link'] ?? null,
                            ':afid'  => $folders['additional_folder']['id'] ?? null,
                            ':alink' => $folders['additional_folder']['link'] ?? null,
                            ':id'    => $task_id
                        ]);
                    }
                }
            }
        } catch (\Exception $eDrive) {
            error_log("Google Drive background sync note for rework {$taskKey}: " . $eDrive->getMessage());
        }

        if (!headers_sent()) {
            header("Location: rework_tasks.php?done=1");
        }
        exit;
    } catch (Exception $e) { $error = $e->getMessage(); }
}

/* ================= FETCH TASKS ================= */
$tasks = $db->query("SELECT * FROM tasks WHERE assigned_to=$user AND status='rework' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rework tasks | Digiink Expert</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
        .rework-card { transition: all 0.3s ease; border-radius: 2.5rem; overflow: hidden; border: 1px solid #e2e8f0; background: white; }
        .rework-card:hover { transform: translateY(-5px); box-shadow: 0 25px 50px -12px rgba(244, 63, 94, 0.1); }
        .qc-feedback { background: linear-gradient(135deg, #fff1f2 0%, #fff5f5 100%); }
        .content-scroll::-webkit-scrollbar { width: 6px; }
        .content-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    </style>
</head>

<body class="text-slate-900">
<div class="flex min-h-screen">
    <?php include "partials/sidebar.php"; ?>

    <main class="flex-1 p-6 lg:p-12">
        <div class="max-w-6xl mx-auto mb-10 flex flex-col md:flex-row justify-between items-center gap-6">
            <div class="flex items-center gap-5">
                <div class="w-14 h-14 bg-rose-600 rounded-2xl shadow-lg shadow-rose-200 flex items-center justify-center">
                    <span class="material-icons-round text-white text-3xl">history_edu</span>
                </div>
                <div>
                    <h1 class="text-3xl font-black text-slate-900 tracking-tight">Rework Queue</h1>
                    <p class="text-slate-500 font-medium italic">Address auditor feedback and resubmit assets.</p>
                </div>
            </div>
            <div class="bg-rose-50 border border-rose-100 px-6 py-3 rounded-2xl font-bold text-rose-600 uppercase text-sm">
                <?= count($tasks) ?> Corrections Pending
            </div>
        </div>

        <div class="space-y-10">
            <?php foreach($tasks as $t): ?>
            <div class="rework-card shadow-sm">
                <!-- Header -->
<div class="px-8 py-5 border-b border-slate-50 flex justify-between items-center">
    <div class="flex items-center gap-4">
        <div class="h-12 w-12 bg-slate-900 text-white rounded-2xl flex items-center justify-center font-black">
            #<?= substr($t['task_key'], -3) ?>
        </div>
        <div>
            <div class="flex items-center gap-2">
                <h2 class="text-xl font-extrabold text-slate-800"><?= $t['task_key'] ?></h2>
                <span class="bg-rose-600 text-white text-[10px] font-black px-2 py-1 rounded-lg uppercase tracking-tighter">Rework Mode</span>
            </div>
            <!-- ✨ Task Name aur Client Name combination -->
            <p class="text-xs text-slate-400 font-bold uppercase flex items-center gap-2 mt-0.5">
                <?= htmlspecialchars($t['task_name']) ?>
                <span class="text-slate-200">|</span>
                <span class="text-indigo-500 italic">Client: <?= htmlspecialchars($t['client_name'] ?: 'N/A') ?></span>
            </p>
        </div>
    </div>
</div>

                <!-- Feedback Area -->
                <div class="px-8 py-6 qc-feedback border-b border-rose-100">
                    <label class="text-[10px] font-black text-rose-400 uppercase tracking-widest block mb-2">Auditor Feedback</label>
                    <div class="text-sm font-bold text-rose-900 italic leading-relaxed pl-4 border-l-4 border-rose-200">
                        "<?= nl2br(htmlspecialchars($t['qc_notes'])) ?>"
                    </div>
                </div>

                <!-- BDE STYLE UPLOADER FORM -->
                <div class="p-8">
                    <form method="POST" enctype="multipart/form-data" class="space-y-8" id="form_<?= $t['id'] ?>">
                        <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                        <input type="hidden" name="task_key" value="<?= $t['task_key'] ?>">

                        <div class="grid lg:grid-cols-2 gap-8">
                            <!-- Notes -->
                            <div class="space-y-2">
                                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Expert's Response</label>
                                <textarea name="team_notes" required placeholder="What have you corrected? (Ctrl+V to paste screenshots)" 
                                          class="w-full bg-slate-50 border border-slate-200 p-5 rounded-[1.5rem] text-sm font-medium h-full min-h-[200px] outline-none focus:border-rose-400 transition-all paste-receiver" 
                                          data-task-id="<?= $t['id'] ?>"></textarea>
                            </div>

                            <!-- Dotted Uploader -->
                            <div class="space-y-2">
                                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Attach Materials</label>
                                <div class="p-8 bg-slate-50 rounded-[1.5rem] border-2 border-dashed border-slate-200 hover:border-rose-400 transition-all flex flex-col items-center justify-center gap-4 min-h-[200px]">
                                    <div class="flex gap-4">
                                        <label class="bg-indigo-600 hover:bg-slate-900 text-white px-6 py-3 rounded-2xl text-[11px] font-black cursor-pointer transition-all flex items-center gap-2 shadow-lg">
                                            <span class="material-icons-round text-sm">upload_file</span> Files
                                            <input type="file" multiple class="hidden" onchange="handleSelection(this, <?= $t['id'] ?>)">
                                        </label>
                                        <label class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 px-6 py-3 rounded-2xl text-[11px] font-black cursor-pointer transition-all flex items-center gap-2">
                                            <span class="material-icons-round text-sm text-indigo-600">folder</span> Folder
                                            <input type="file" webkitdirectory directory multiple class="hidden" onchange="handleSelection(this, <?= $t['id'] ?>)">
                                        </label>
                                    </div>
                                    <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest">Supports APK, SQL, ZIP, EXE.</p>
                                    
                                    <!-- Inside Box Preview -->
                                    <div id="preview_<?= $t['id'] ?>" class="w-full mt-2 space-y-2 max-h-40 overflow-y-auto content-scroll px-2"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Buttons -->
                        <div class="flex justify-between items-center pt-6 border-t border-slate-50">
                            <?php if(!empty($t['drive_folder_link'])): ?>
                                <!-- [MODIFIED] Google Drive migration — ZIP Viewer removed.
                                     Opens the task's existing Google Drive folder. -->
                                <a href="<?= htmlspecialchars($t['drive_folder_link']) ?>" target="_blank" class="flex items-center gap-2 bg-slate-100 text-slate-600 px-5 py-3 rounded-2xl text-xs font-bold hover:bg-slate-900 hover:text-white transition-all">
                                    <span class="material-icons-round text-sm">folder_open</span> View Google Drive Folder
                                </a>
                            <?php else: ?>
                                <span class="text-xs font-medium text-slate-400 italic">Not synced with Google Drive</span>
                            <?php endif; ?>
                            
                            <button type="submit" class="bg-rose-600 hover:bg-slate-900 text-white px-12 py-4 rounded-2xl font-black text-sm uppercase tracking-widest shadow-xl shadow-rose-100 transition-all hover:-translate-y-1 flex items-center gap-3">
                                Resubmit Work <span class="material-icons-round">send</span>
                            </button>
                        </div>

                        <!-- Hidden Logic Containers -->
                        <div id="path_inputs_<?= $t['id'] ?>"></div>
                        <div id="hidden_wrapper_<?= $t['id'] ?>" class="hidden"></div>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </main>
</div>

<script>
let fileStore = {};

function handleSelection(input, taskId) {
    if (!fileStore[taskId]) fileStore[taskId] = [];
    for (let file of input.files) { fileStore[taskId].push(file); }
    renderUI(taskId);
}

// 📋 Paste Screenshot
document.querySelectorAll('.paste-receiver').forEach(area => {
    area.addEventListener('paste', function(e) {
        const taskId = this.getAttribute('data-task-id');
        const items = e.clipboardData.items;
        for (let i = 0; i < items.length; i++) {
            if (items[i].type.indexOf("image") !== -1) {
                const blob = items[i].getAsFile();
                const file = new File([blob], "rework_pasted_" + Date.now() + ".png", { type: blob.type });
                if (!fileStore[taskId]) fileStore[taskId] = [];
                fileStore[taskId].push(file);
                renderUI(taskId);
            }
        }
    });
});

function renderUI(taskId) {
    const preview = document.getElementById(`preview_${taskId}`);
    const pathContainer = document.getElementById(`path_inputs_${taskId}`);
    const hiddenWrapper = document.getElementById(`hidden_wrapper_${taskId}`);
    
    preview.innerHTML = "";
    pathContainer.innerHTML = "";
    hiddenWrapper.innerHTML = "";
    const dataTransfer = new DataTransfer();

    fileStore[taskId].forEach((file, index) => {
        dataTransfer.items.add(file);
        let path = file.webkitRelativePath || file.name;
        pathContainer.innerHTML += `<input type="hidden" name="full_paths[]" value="${path}">`;
        
        preview.innerHTML += `
            <div class="flex justify-between items-center bg-white p-3 rounded-xl border border-slate-100 shadow-sm">
                <div class="flex items-center gap-3 truncate">
                    <span class="material-icons-round text-slate-300 text-sm">insert_drive_file</span>
                    <span class="text-[10px] font-bold text-slate-700 truncate">${path}</span>
                </div>
                <button type="button" onclick="removeFile(${taskId}, ${index})" class="text-rose-500 hover:bg-rose-50 p-1 rounded-lg transition-all">
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

function removeFile(taskId, index) {
    fileStore[taskId].splice(index, 1);
    renderUI(taskId);
}
</script>

<?php if(isset($_GET['done'])): ?>
<script>
    if (window.history.replaceState) {
        const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
        window.history.replaceState({ path: cleanUrl }, '', cleanUrl);
    }
    Swal.fire({ icon:'success', title:'Success', text:'Resubmitted to QC!', confirmButtonColor:'#e11d48' });
</script>
<?php endif; ?>

</body>
</html>