<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ⬇️ .ENV LOAD KARNE KE LIYE ZAROORI HAI ⬇️
require_once "../../bootstrap/app.php";

require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole('bde');

require_once "../../app/config/database.php";

date_default_timezone_set('Asia/Kolkata');

 $db = (new Database())->connect();
 $user = $_SESSION['user_id'];

/* ================= CHECK ID ================= */
if (!isset($_GET['id'])) {
    header("Location: my_tasks.php");
    exit;
}

 $id = $_GET['id'];

/* ================= FETCH TASK ================= */
 $stmt = $db->prepare("SELECT * FROM tasks WHERE id = :id AND created_by = :user");
 $stmt->execute([':id' => $id, ':user' => $user]);
 $task = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$task) {
    header("Location: my_tasks.php");
    exit;
}

/* ================= LOCK AFTER ASSIGNMENT ================= */
if ($task['current_stage'] != 'operations_manager') {
    header("Location: my_tasks.php");
    exit;
}

/* ================= UPDATE LOGIC (GOOGLE DRIVE INTEGRATED) ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // 1. Update Text Fields in DB
        $stmt = $db->prepare("
            UPDATE tasks SET
            task_key = :task_key,
            task_name = :task_name,
            client_name = :client_name, 
            task_created_at = :task_created_at,
            deadline = :deadline,
            word_count = :word_count,
            university = :university,
            country = :country,
            subject = :subject,
            instructions = :instructions,
            urgent = :urgent
            WHERE id = :id
        ");

        $stmt->execute([
            ':task_key' => $_POST['task_key'],
            ':task_name' => $_POST['task_name'],
            ':client_name' => $_POST['client_name'],
            ':task_created_at' => $_POST['task_created_at'],
            ':deadline' => $_POST['deadline'],
            ':word_count' => $_POST['word_count'],
            ':university' => $_POST['university'],
            ':country' => $_POST['country'],
            ':subject' => $_POST['subject'],
            ':instructions' => $_POST['instructions'],
            ':urgent' => $_POST['urgent'],
            ':id' => $id
        ]);

        // 2. Handle File Upload (Stage Locally First + Safe Google Drive Sync)
        if (isset($_FILES['task_files']) && !empty($_FILES['task_files']['name'][0])) {
            $safeTaskKey = preg_replace('/[^A-Za-z0-9\-_]/', '_', trim($_POST['task_key']));
            $uploadDir = __DIR__ . "/../../uploads/client_files/" . $safeTaskKey . "/";
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $stagedFiles = [];
            foreach ($_FILES['task_files']['name'] as $key => $name) {
                if ($_FILES['task_files']['error'][$key] !== UPLOAD_ERR_OK) continue;

                $tmpPath = $_FILES['task_files']['tmp_name'][$key];
                $originalPath = $_POST['full_paths'][$key] ?? $name;
                $relativePath = str_replace('\\', '/', $originalPath);

                $targetLocalPath = $uploadDir . $relativePath;
                $targetLocalDir  = dirname($targetLocalPath);
                if (!is_dir($targetLocalDir)) {
                    mkdir($targetLocalDir, 0777, true);
                }

                if (move_uploaded_file($tmpPath, $targetLocalPath)) {
                    $stagedFiles[] = [
                        'local_path'    => $targetLocalPath,
                        'relative_path' => $relativePath,
                        'name'          => basename($relativePath)
                    ];
                }
            }

            // Send instant response so BDE gets updated immediately
            // Enforce Google Drive Verification & Sync for New Files
            require_once "../../app/services/GoogleDriveService.php";
            $drive = new GoogleDriveService();
            if (!$drive->isReady()) {
                foreach ($stagedFiles as $sf) {
                    if (file_exists($sf['local_path'])) @unlink($sf['local_path']);
                }
                http_response_code(500);
                echo json_encode(['error' => "Google Drive API Error: " . ($drive->getInitError() ?: "Drive service is not ready. Files were not uploaded.")]);
                exit;
            }

            try {
                $stmtTask = $db->prepare("SELECT drive_folder_id FROM tasks WHERE id = :id");
                $stmtTask->execute([':id' => $id]);
                $existingFolderId = $stmtTask->fetchColumn();

                $folderData = $drive->createTaskFolder($_POST['task_key'], $existingFolderId ?: null);
                if (empty($folderData['success'])) {
                    throw new Exception("Google Drive Folder Creation Failed: " . ($folderData['message'] ?? 'Could not access task folder in Drive'));
                }

                $clientFolderId = $folderData['client_folder']['id'];
                foreach ($stagedFiles as $sf) {
                    $driveFileName = $safeTaskKey . '_EDIT_' . $sf['name'];
                    $upRes = $drive->uploadFile($sf['local_path'], $clientFolderId, $driveFileName);
                    if (empty($upRes['success'])) {
                        throw new Exception("Google Drive Upload Failed: " . ($upRes['message'] ?? 'Could not upload file to Drive'));
                    }
                }

                $db->prepare("UPDATE tasks SET 
                    drive_folder_id             = COALESCE(drive_folder_id, :fid),
                    drive_folder_link           = COALESCE(drive_folder_link, :flink),
                    drive_client_folder_id      = :cfid,
                    drive_client_link           = :clink,
                    drive_operations_folder_id  = COALESCE(drive_operations_folder_id, :ofid),
                    drive_operations_link       = COALESCE(drive_operations_link, :olink),
                    drive_additional_folder_id  = COALESCE(drive_additional_folder_id, :afid),
                    drive_additional_link       = COALESCE(drive_additional_link, :alink)
                WHERE id = :id")->execute([
                    ':fid'   => $folderData['task_folder_id'] ?? null,
                    ':flink' => $folderData['task_folder_link'] ?? null,
                    ':cfid'  => $folderData['client_folder']['id'] ?? null,
                    ':clink' => $folderData['client_folder']['link'] ?? null,
                    ':ofid'  => $folderData['operations_folder']['id'] ?? null,
                    ':olink' => $folderData['operations_folder']['link'] ?? null,
                    ':afid'  => $folderData['additional_folder']['id'] ?? null,
                    ':alink' => $folderData['additional_folder']['link'] ?? null,
                    ':id'    => $id
                ]);
            } catch (\Exception $eDrive) {
                foreach ($stagedFiles as $sf) {
                    if (file_exists($sf['local_path'])) @unlink($sf['local_path']);
                }
                http_response_code(500);
                echo json_encode(['error' => "Google Drive Sync Failed: " . $eDrive->getMessage()]);
                exit;
            }
        }

        echo json_encode(['success' => true]);
        exit;

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Task | Digiink Workflow</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
    * { font-family: 'Inter', sans-serif; }
    body { background-color: #f4f4f5; }
    
    .f-input { 
        width: 100%; 
        padding: 0.45rem 0.6rem; 
        font-size: 0.8125rem; 
        border: 1px solid #e4e4e7; 
        border-radius: 0.375rem; 
        background: #fafafa; 
        transition: all 0.15s ease; 
        outline: none; 
        color: #09090b;
    }
    .f-input:focus { 
        border-color: #18181b; 
        background: #fff;
        box-shadow: 0 0 0 1px #18181b; 
    }
    .f-input::placeholder { color: #a1a1aa; }
    
    .f-label { 
        display: block; 
        font-size: 0.6875rem; 
        font-weight: 600; 
        color: #71717a; 
        margin-bottom: 0.375rem; 
        text-transform: uppercase; 
        letter-spacing: 0.05em; 
    }

    .drop-zone {
        border: 1px dashed #d4d4d8;
        border-radius: 0.375rem;
        padding: 1.5rem;
        background-color: rgba(250, 250, 250, 0.5);
        transition: all 0.2s ease;
    }
    .drop-zone.active { border-color: #18181b; background-color: #f4f4f5; }
    
    .file-row {
        display: flex; align-items: center; justify-content: space-between;
        padding: 0.35rem 0.5rem; border-bottom: 1px solid #f4f4f5; font-size: 0.8125rem;
    }
    .file-row:last-child { border-bottom: none; }
    
    .clean-scroll::-webkit-scrollbar { width: 3px; }
    .clean-scroll::-webkit-scrollbar-thumb { background: #d4d4d8; border-radius: 10px; }
</style>
</head>

<body class="text-zinc-900 antialiased">

<div class="flex h-screen overflow-hidden">
    <?php include "partials/sidebar.php"; ?>

    <main class="flex-1 overflow-y-auto">
        <div class="max-w-5xl mx-auto p-6 lg:p-10">
            
            <div class="flex items-center justify-between mb-8">
                <div>
                    <h1 class="text-xl font-semibold tracking-tight text-zinc-900">Edit Task</h1>
                    <p class="text-xs text-zinc-400 mt-0.5">Modify details or replace source materials.</p>
                </div>
                <div class="text-xs text-zinc-400 font-mono bg-white px-2.5 py-1 border border-zinc-200 rounded shadow-sm"><?= date("d M Y"); ?></div>
            </div>

            <form method="POST" enctype="multipart/form-data" id="taskForm" class="space-y-5">
                
                <!-- Section 1: Meta -->
                <div class="bg-white border border-zinc-200 rounded-md p-5 shadow-sm">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="f-label">Task Key <span class="text-red-500">*</span></label>
                            <input type="text" name="task_key" value="<?= htmlspecialchars($task['task_key']) ?>" required class="f-input font-mono">
                        </div>
                        <div>
                            <label class="f-label">Task Name <span class="text-red-500">*</span></label>
                            <input type="text" name="task_name" value="<?= htmlspecialchars($task['task_name']) ?>" required class="f-input">
                        </div>
                        <div>
                            <label class="f-label">Client Name <span class="text-red-500">*</span></label>
                            <input type="text" name="client_name" value="<?= htmlspecialchars($task['client_name']) ?>" required class="f-input">
                        </div>
                    </div>
                </div>

                <!-- Section 2: Details -->
                <div class="bg-white border border-zinc-200 rounded-md p-5 shadow-sm">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="f-label text-red-500">Deadline <span class="text-red-500">*</span></label>
                            <input type="datetime-local" name="deadline" value="<?= date('Y-m-d\TH:i',strtotime($task['deadline'])) ?>" required class="f-input border-red-200 focus:border-red-500">
                        </div>
                        <div>
                            <label class="f-label">Words <span class="text-red-500">*</span></label>
                            <input type="number" name="word_count" value="<?= $task['word_count'] ?>" required class="f-input font-mono">
                        </div>
                        <div>
                            <label class="f-label">Priority</label>
                            <select name="urgent" class="f-input bg-white cursor-pointer">
                                <option value="no" <?= $task['urgent']=='no'?'selected':'' ?>>Normal</option>
                                <option value="yes" <?= $task['urgent']=='yes'?'selected':'' ?>>Urgent</option>
                            </select>
                        </div>
                        <div>
                            <label class="f-label">Created</label>
                            <input type="datetime-local" name="task_created_at" value="<?= date('Y-m-d\TH:i',strtotime($task['task_created_at'])) ?>" required class="f-input">
                        </div>
                        <div>
                            <label class="f-label">University</label>
                            <input type="text" name="university" value="<?= htmlspecialchars($task['university']) ?>" class="f-input">
                        </div>
                        <div>
                            <label class="f-label">Country</label>
                            <input type="text" name="country" value="<?= htmlspecialchars($task['country']) ?>" class="f-input">
                        </div>
                        <div class="md:col-span-2">
                            <label class="f-label">Subject</label>
                            <input type="text" name="subject" value="<?= htmlspecialchars($task['subject']) ?>" class="f-input">
                        </div>
                    </div>
                    <div class="mt-4">
                        <label class="f-label">Instructions</label>
                        <textarea name="instructions" rows="2" class="f-input resize-none"><?= htmlspecialchars($task['instructions']) ?></textarea>
                    </div>
                </div>

                <!-- Section 3: Current File Status & Drive Replacement -->
                <div class="bg-white border border-zinc-200 rounded-md p-5 shadow-sm space-y-4">
                    
                    <!-- Current File Info -->
                    <div class="flex items-center justify-between p-3 bg-zinc-50 border border-zinc-100 rounded">
                        <div>
                            <div class="text-[9px] font-bold text-zinc-400 uppercase tracking-widest">Current Status</div>
                            <?php if(!empty($task['drive_folder_link'])): ?>
                                <div class="text-xs text-zinc-700 mt-0.5 font-medium">Synced with Google Drive</div>
                            <?php elseif(!empty($task['file_path'])): ?>
                                <div class="text-xs text-zinc-700 mt-0.5 font-medium truncate max-w-xs">Local: <?= basename($task['file_path']) ?></div>
                            <?php else: ?>
                                <div class="text-xs text-zinc-400 mt-0.5">No initial file attached</div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="flex gap-2">
                            <?php if(!empty($task['drive_folder_link'])): ?>
                                <a href="<?= $task['drive_folder_link'] ?>" target="_blank" class="flex items-center gap-1.5 text-xs font-medium text-zinc-600 hover:text-zinc-900 bg-white border border-zinc-200 px-3 py-1.5 rounded transition-colors">
                                    <span class="material-icons-round text-sm text-blue-500">folder_open</span> Open Drive
                                </a>
                            <?php elseif(!empty($task['file_path'])): ?>
                                <a href="../../<?= $task['file_path'] ?>" target="_blank" class="flex items-center gap-1.5 text-xs font-medium text-zinc-600 hover:text-zinc-900 bg-white border border-zinc-200 px-3 py-1.5 rounded transition-colors">
                                    <span class="material-icons-round text-sm">visibility</span> View File
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Uploader -->
                    <div>
                        <span class="f-label">Replace / Add Materials (Uploads to Drive)</span>
                        <div class="flex gap-1.5 mb-3">
                            <label class="cursor-pointer px-2 py-1 border border-zinc-200 rounded text-[11px] font-medium text-zinc-500 hover:bg-zinc-50 hover:text-zinc-700 flex items-center gap-1 transition-colors">
                                <span class="material-icons-round text-xs">note_add</span> Files
                                <input type="file" id="fileInput" multiple class="hidden">
                            </label>
                            <label class="cursor-pointer px-2 py-1 border border-zinc-200 rounded text-[11px] font-medium text-zinc-500 hover:bg-zinc-50 hover:text-zinc-700 flex items-center gap-1 transition-colors">
                                <span class="material-icons-round text-xs">folder_open</span> Folder
                                <input type="file" id="folderInput" webkitdirectory directory multiple class="hidden">
                            </label>
                        </div>

                        <div id="dropArea" class="drop-zone text-center">
                            <span class="material-icons-round text-zinc-300 text-3xl">cloud_upload</span>
                            <p class="text-[11px] text-zinc-400 mt-1.5 font-medium">Drag & drop replacement files here</p>
                        </div>

                        <div id="pathInputs"></div>
                        <div id="filePreview" class="mt-1 border border-zinc-100 rounded-md max-h-36 overflow-y-auto clean-scroll bg-zinc-50/50"></div>
                    </div>
                </div>

                <!-- Footer Actions -->
                <div class="flex items-center justify-between pt-1">
                    <a href="my_tasks.php" id="backLink" class="text-xs text-zinc-500 hover:text-zinc-900 font-medium transition-colors">← Back to Tasks</a>
                    
                    <div class="flex items-center gap-4">
                        <!-- Progress Bar -->
                        <div id="uploadProgressContainer" class="hidden w-48">
                            <div class="flex justify-between mb-0.5">
                                <span id="uploadSpeed" class="text-[9px] font-mono text-zinc-400">0 KB/s</span>
                                <span id="uploadPercentage" class="text-[9px] font-mono font-semibold text-zinc-800">0%</span>
                            </div>
                            <div class="w-full bg-zinc-100 rounded-full h-1 overflow-hidden">
                                <div id="uploadProgressBar" class="bg-zinc-800 h-full w-0 transition-all duration-150"></div>
                            </div>
                        </div>

                        <button type="submit" id="submitBtn" class="bg-zinc-900 hover:bg-zinc-800 text-white px-5 py-2 rounded-md text-xs font-semibold flex items-center gap-2 transition-colors shadow-sm">
                            Update Task
                            <span class="material-icons-round text-sm">save</span>
                        </button>
                    </div>
                </div>

                <div id="hiddenFilesContainer" class="hidden"></div>
            </form>
        </div>
    </main>
</div>

<script>
let allFiles = [];
const fileInput = document.getElementById("fileInput");
const folderInput = document.getElementById("folderInput");
const preview = document.getElementById("filePreview");
const pathContainer = document.getElementById("pathInputs");
const dropArea = document.getElementById("dropArea");

fileInput.addEventListener("change", (e) => handleSelection(e.target.files));
folderInput.addEventListener("change", (e) => handleSelection(e.target.files));

['dragenter', 'dragover'].forEach(eventName => {
    dropArea.addEventListener(eventName, (e) => { e.preventDefault(); dropArea.classList.add('active'); });
});
['dragleave', 'drop'].forEach(eventName => {
    dropArea.addEventListener(eventName, (e) => { e.preventDefault(); dropArea.classList.remove('active'); });
});
dropArea.addEventListener('drop', (e) => handleSelection(e.dataTransfer.files));

document.addEventListener("paste", (e) => {
    for (let i = 0; i < e.clipboardData.items.length; i++) {
        if (e.clipboardData.items[i].type.indexOf("image") !== -1) {
            const file = e.clipboardData.items[i].getAsFile();
            handleSelection([new File([file], "clipboard_" + Date.now() + ".png", { type: file.type })]);
        }
    }
});

function handleSelection(files) {
    for (let file of files) { allFiles.push(file); }
    renderFiles();
}

function renderFiles() {
    preview.innerHTML = "";
    pathContainer.innerHTML = "";
    const dataTransfer = new DataTransfer();

    allFiles.forEach((file, index) => {
        dataTransfer.items.add(file);
        let path = file.webkitRelativePath || file.name;
        pathContainer.innerHTML += `<input type="hidden" name="full_paths[]" value="${path}">`;
        
        preview.innerHTML += `
            <div class="file-row group">
                <div class="flex items-center gap-2 overflow-hidden">
                    <span class="material-icons-round text-zinc-300 text-sm">description</span>
                    <span class="text-xs text-zinc-600 truncate">${path}</span>
                </div>
                <button type="button" onclick="removeFile(${index})" class="text-zinc-300 hover:text-red-500 opacity-0 group-hover:opacity-100 transition-all p-0.5">
                    <span class="material-icons-round text-sm">close</span>
                </button>
            </div>`;
    });

    const oldInput = document.getElementById("finalFiles");
    if(oldInput) oldInput.remove();

    const finalInput = document.createElement("input");
    finalInput.type = "file";
    finalInput.name = "task_files[]";
    finalInput.multiple = true;
    finalInput.files = dataTransfer.files;
    finalInput.id = "finalFiles";
    finalInput.classList.add("hidden");
    document.getElementById("hiddenFilesContainer").appendChild(finalInput);
    
    dropArea.style.display = allFiles.length > 0 ? 'none' : 'block';
}

function removeFile(index) {
    allFiles.splice(index, 1);
    renderFiles();
}

// ==========================================
// XHR SUBMIT WITH PROGRESS & TOAST
// ==========================================
let uploadStartTime;

document.getElementById('taskForm').addEventListener('submit', function(e) {
    e.preventDefault(); 
    const form = e.target;
    const formData = new FormData(form);
    
    const progressContainer = document.getElementById('uploadProgressContainer');
    const progressBar = document.getElementById('uploadProgressBar');
    const percentageText = document.getElementById('uploadPercentage');
    const speedText = document.getElementById('uploadSpeed');
    const submitBtn = document.getElementById('submitBtn');
    const backLink = document.getElementById('backLink');

    // UI State
    progressContainer.classList.remove('hidden');
    submitBtn.disabled = true; 
    submitBtn.innerHTML = `<span class="animate-spin inline-block w-3.5 h-3.5 border-2 border-zinc-500 border-t-transparent rounded-full"></span> Updating...`;
    backLink.style.display = 'none'; // Hide back button during upload
    progressBar.style.width = '0%';
    percentageText.innerText = '0%';
    speedText.innerText = '0 KB/s';

    uploadStartTime = new Date().getTime();

    const xhr = new XMLHttpRequest();
    
    xhr.upload.addEventListener('progress', function(e) {
        if (e.lengthComputable) {
            const percentComplete = Math.round((e.loaded / e.total) * 100);
            progressBar.style.width = percentComplete + '%';
            percentageText.innerText = percentComplete + '%';

            const timeElapsed = (new Date().getTime() - uploadStartTime) / 1000;
            if (timeElapsed > 0) {
                const kbps = ((e.loaded / timeElapsed) / 1024).toFixed(1);
                speedText.innerText = kbps > 1024 ? (kbps / 1024).toFixed(1) + " MB/s" : kbps + " KB/s";
            }
            
            if(percentComplete === 100) {
                percentageText.innerText = "Syncing Drive...";
                speedText.innerText = "Finalizing...";
            }
        }
    });

    xhr.onload = function () {
        if (xhr.status === 200) {
            // Success Toast Notification
            const Toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 2000,
                timerProgressBar: true,
                didOpen: (toast) => {
                    toast.onmouseenter = Swal.stopTimer;
                    toast.onmouseleave = Swal.resumeTimer;
                }
            });
            
            Toast.fire({
                icon: 'success',
                title: 'Task updated successfully'
            }).then(() => {
                window.location.href = "my_tasks.php?updated=1";
            });
        } else {
            Swal.fire({ icon: 'error', title: 'Update Failed', text: xhr.responseText, confirmButtonColor: '#09090b' });
            submitBtn.disabled = false;
            submitBtn.innerHTML = `Update Task <span class="material-icons-round text-sm">save</span>`;
            backLink.style.display = 'block';
            progressContainer.classList.add('hidden');
        }
    }

    xhr.onerror = function() {
        Swal.fire('Network Error', 'Check your connection.', 'error');
        submitBtn.disabled = false;
        submitBtn.innerHTML = `Update Task <span class="material-icons-round text-sm">save</span>`;
        backLink.style.display = 'block';
        progressContainer.classList.add('hidden');
    };

    xhr.open('POST', form.action || window.location.href, true);
    xhr.send(formData);
});
</script>

</body>
</html>