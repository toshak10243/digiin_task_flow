<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
require_once "../../bootstrap/app.php";
allowRole('bde');

require_once "../../app/controllers/TaskController.php";

date_default_timezone_set('Asia/Kolkata');

// Real-time AJAX Endpoint to check duplicate task key
if (isset($_GET['action']) && $_GET['action'] === 'check_task_key') {
    header('Content-Type: application/json');
    $key = trim($_GET['task_key'] ?? '');
    if (empty($key)) {
        echo json_encode(['exists' => false]);
        exit;
    }

    try {
        require_once "../../app/config/database.php";
        $db = (new Database())->connect();
        $stmt = $db->prepare("SELECT id, task_name, created_at FROM tasks WHERE LOWER(TRIM(task_key)) = LOWER(TRIM(:k)) LIMIT 1");
        $stmt->execute([':k' => $key]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            echo json_encode([
                'exists'     => true,
                'task_name'  => $existing['task_name'],
                'created_at' => $existing['created_at'] ? date('d M Y', strtotime($existing['created_at'])) : null
            ]);
        } else {
            echo json_encode(['exists' => false]);
        }
    } catch (\Exception $e) {
        echo json_encode(['exists' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

 $success = "";
 $error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $taskKey = trim($_POST['task_key'] ?? '');

        // Server-side check for duplicate task_key
        require_once "../../app/config/database.php";
        $dbCheck = (new Database())->connect();
        $stmtCheck = $dbCheck->prepare("SELECT id, task_name FROM tasks WHERE LOWER(TRIM(task_key)) = LOWER(TRIM(:k)) LIMIT 1");
        $stmtCheck->execute([':k' => $taskKey]);
        $dupTask = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if ($dupTask) {
            http_response_code(409);
            echo "Task Key '$taskKey' already exists in the system (Task: {$dupTask['task_name']}). Please use a unique Task Key.";
            exit;
        }

        $taskController = new TaskController();
        
        $result = $taskController->createTask([
            'task_key'        => $taskKey,
            'task_name'       => $_POST['task_name'],
            'client_name'     => $_POST['client_name'] ?? null,
            'task_created_at' => date('Y-m-d H:i:s'),
            'deadline'        => $_POST['deadline'],
            'word_count'      => $_POST['word_count'],
            'university'      => $_POST['university'],
            'country'         => $_POST['country'],
            'subject'         => $_POST['subject'],
            'instructions'    => $_POST['instructions'],
            'urgent'          => $_POST['urgent']
        ]);

        // ✅ FIXED: Controller returns true, not array. So only check boolean.
        if (!$result) {
            throw new Exception("Task creation failed.");
        }

        $success = "created";

    } catch (Exception $e) {
        $error = $e->getMessage();
        http_response_code(400);
        echo $error;
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>New Task | Digiink Workflow</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
    * { font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
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
    .drop-zone.active {
        border-color: #18181b;
        background-color: #f4f4f5;
    }
    
    .file-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.35rem 0.5rem;
        border-bottom: 1px solid #f4f4f5;
        font-size: 0.8125rem;
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
                    <h1 class="text-xl font-semibold tracking-tight text-zinc-900">Create Task</h1>
                    <p class="text-xs text-zinc-400 mt-1">Fill in the details to initiate a new workflow.</p>
                </div>
                <div class="text-xs text-zinc-400 font-mono bg-white px-2.5 py-1 border border-zinc-200 rounded shadow-sm"><?= date("d M Y"); ?></div>
            </div>

            <form method="POST" enctype="multipart/form-data" id="taskForm" class="space-y-5">
                
                <div class="bg-white border border-zinc-200 rounded-md p-5 shadow-sm">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <div class="flex items-center justify-between">
                                <label class="f-label">Task Key <span class="text-red-500">*</span></label>
                                <span id="taskKeyStatusBadge" class="hidden text-[10px] font-semibold px-1.5 py-0.2 rounded"></span>
                            </div>
                            <div class="relative">
                                <input type="text" id="taskKeyInput" name="task_key" required placeholder="DI/MAR/RAY/002" class="f-input font-mono pr-8">
                                <span id="taskKeySpinner" class="hidden absolute right-2.5 top-1/2 -translate-y-1/2">
                                    <span class="animate-spin inline-block w-3.5 h-3.5 border-2 border-zinc-400 border-t-transparent rounded-full"></span>
                                </span>
                                <span id="taskKeyIcon" class="hidden absolute right-2.5 top-1/2 -translate-y-1/2 material-icons-round text-sm"></span>
                            </div>
                            <p id="taskKeyFeedback" class="hidden text-[11px] mt-1 font-medium leading-tight"></p>
                        </div>
                        <div>
                            <label class="f-label">Task Name <span class="text-red-500">*</span></label>
                            <input type="text" name="task_name" required placeholder="RAY Research Report" class="f-input">
                        </div>
                        <div>
                            <label class="f-label">Client Name <span class="text-red-500">*</span></label>
                            <input type="text" name="client_name" required placeholder="Harsh Maheshwari" class="f-input">
                        </div>
                    </div>
                </div>

                <div class="bg-white border border-zinc-200 rounded-md p-5 shadow-sm">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="f-label text-red-500">Deadline <span class="text-red-500">*</span></label>
                            <input type="datetime-local" name="deadline" required class="f-input border-red-200 focus:border-red-500 focus:ring-red-50">
                        </div>
                        <div>
                            <label class="f-label">Words <span class="text-red-500">*</span></label>
                            <input type="number" name="word_count" required placeholder="2500" class="f-input font-mono">
                        </div>
                        <div>
                            <label class="f-label">Priority</label>
                            <select name="urgent" class="f-input bg-white cursor-pointer">
                                <option value="no">Normal</option>
                                <option value="yes">Urgent</option>
                            </select>
                        </div>
                        <div>
                            <label class="f-label">Created</label>
                            <input type="text" value="<?= date('d M Y h:i A'); ?>" readonly class="f-input bg-zinc-50 text-zinc-500 cursor-not-allowed border-zinc-100">
                        </div>
                        <div>
                            <label class="f-label">University</label>
                            <input type="text" name="university" placeholder="Univ of Toronto" class="f-input">
                        </div>
                        <div>
                            <label class="f-label">Country</label>
                            <input type="text" name="country" placeholder="Canada" class="f-input">
                        </div>
                        <div class="md:col-span-2">
                            <label class="f-label">Subject</label>
                            <input type="text" name="subject" placeholder="Business Management" class="f-input">
                        </div>
                    </div>
                    <div class="mt-4">
                        <label class="f-label">Instructions</label>
                        <textarea name="instructions" rows="2" placeholder="Specific project requirements..." class="f-input resize-none"></textarea>
                    </div>
                </div>

                <div class="bg-white border border-zinc-200 rounded-md p-5 shadow-sm">
                    <div class="flex items-center justify-between mb-3">
                        <span class="f-label mb-0">Source Materials</span>
                        <div class="flex gap-1.5">
                            <label class="cursor-pointer px-2 py-1 border border-zinc-200 rounded text-[11px] font-medium text-zinc-500 hover:bg-zinc-50 hover:text-zinc-700 flex items-center gap-1 transition-colors">
                                <span class="material-icons-round text-xs">note_add</span> Files
                                <input type="file" id="fileInput" multiple class="hidden">
                            </label>
                            <label class="cursor-pointer px-2 py-1 border border-zinc-200 rounded text-[11px] font-medium text-zinc-500 hover:bg-zinc-50 hover:text-zinc-700 flex items-center gap-1 transition-colors">
                                <span class="material-icons-round text-xs">folder_open</span> Folder
                                <input type="file" id="folderInput" webkitdirectory directory multiple class="hidden">
                            </label>
                        </div>
                    </div>

                    <div id="dropArea" class="drop-zone text-center">
                        <span class="material-icons-round text-zinc-300 text-3xl">cloud_upload</span>
                        <p class="text-[11px] text-zinc-400 mt-1.5 font-medium">Drag & drop files or use actions above</p>
                    </div>

                    <div id="pathInputs"></div>
                    <div id="filePreview" class="mt-1 border border-zinc-100 rounded-md max-h-36 overflow-y-auto clean-scroll bg-zinc-50/50"></div>
                </div>

                <div class="flex items-center justify-between pt-1">
                    <div class="flex items-center">
                        <div id="uploadProgressContainer" class="hidden w-48">
                            <div class="flex justify-between mb-0.5">
                                <span id="uploadSpeed" class="text-[9px] font-mono text-zinc-400">0 KB/s</span>
                                <span id="uploadPercentage" class="text-[9px] font-mono font-semibold text-zinc-800">0%</span>
                            </div>
                            <div class="w-full bg-zinc-100 rounded-full h-1 overflow-hidden">
                                <div id="uploadProgressBar" class="bg-zinc-800 h-full w-0 transition-all duration-150"></div>
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" id="submitBtn" class="bg-zinc-900 hover:bg-zinc-800 text-white px-5 py-2 rounded-md text-xs font-semibold flex items-center gap-2 transition-colors shadow-sm">
                        Create Task
                        <span class="material-icons-round text-sm">arrow_forward</span>
                    </button>
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
    dropArea.addEventListener(eventName, (e) => {
        e.preventDefault();
        dropArea.classList.add('active');
    });
});
['dragleave', 'drop'].forEach(eventName => {
    dropArea.addEventListener(eventName, (e) => {
        e.preventDefault();
        dropArea.classList.remove('active');
    });
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

let isKeyDuplicate = false;
let keyCheckTimeout = null;

const taskKeyInput = document.getElementById('taskKeyInput');
const taskKeySpinner = document.getElementById('taskKeySpinner');
const taskKeyIcon = document.getElementById('taskKeyIcon');
const taskKeyFeedback = document.getElementById('taskKeyFeedback');
const taskKeyStatusBadge = document.getElementById('taskKeyStatusBadge');

if (taskKeyInput) {
    taskKeyInput.addEventListener('input', function() {
        const val = this.value.trim();
        clearTimeout(keyCheckTimeout);

        if (!val) {
            resetKeyValidation();
            isKeyDuplicate = false;
            return;
        }

        // Show checking spinner
        taskKeySpinner.classList.remove('hidden');
        taskKeyIcon.classList.add('hidden');
        taskKeyFeedback.classList.add('hidden');
        taskKeyStatusBadge.classList.add('hidden');

        keyCheckTimeout = setTimeout(() => {
            checkDuplicateTaskKey(val);
        }, 350);
    });

    taskKeyInput.addEventListener('blur', function() {
        const val = this.value.trim();
        if (val) {
            checkDuplicateTaskKey(val);
        }
    });
}

function resetKeyValidation() {
    if (!taskKeyInput) return;
    taskKeySpinner.classList.add('hidden');
    taskKeyIcon.classList.add('hidden');
    taskKeyFeedback.classList.add('hidden');
    taskKeyStatusBadge.classList.add('hidden');
    taskKeyInput.classList.remove('border-red-500', 'border-emerald-500', 'bg-red-50/50', 'bg-emerald-50/50', 'text-red-900', 'text-emerald-900');
}

async function checkDuplicateTaskKey(key) {
    try {
        const res = await fetch(`create_task.php?action=check_task_key&task_key=${encodeURIComponent(key)}`);
        const data = await res.json();

        taskKeySpinner.classList.add('hidden');

        if (data.exists) {
            isKeyDuplicate = true;
            taskKeyInput.classList.remove('border-emerald-500', 'bg-emerald-50/50', 'text-emerald-900');
            taskKeyInput.classList.add('border-red-500', 'bg-red-50/50', 'text-red-900');

            taskKeyIcon.className = "absolute right-2.5 top-1/2 -translate-y-1/2 material-icons-round text-base text-red-500";
            taskKeyIcon.innerText = "cancel";
            taskKeyIcon.classList.remove('hidden');

            taskKeyStatusBadge.className = "text-[10px] font-bold px-2 py-0.5 rounded bg-red-100 text-red-700 border border-red-200 animate-pulse";
            taskKeyStatusBadge.innerText = "Already Exists";
            taskKeyStatusBadge.classList.remove('hidden');

            const taskDetail = data.task_name ? ` (Used in: "${data.task_name}")` : '';
            taskKeyFeedback.className = "text-[11px] mt-1 font-semibold text-red-600 flex items-center gap-1";
            taskKeyFeedback.innerHTML = `<span class="material-icons-round text-xs">error_outline</span> This Task Key is already taken${taskDetail}. Please use a unique key.`;
            taskKeyFeedback.classList.remove('hidden');
        } else {
            isKeyDuplicate = false;
            taskKeyInput.classList.remove('border-red-500', 'bg-red-50/50', 'text-red-900');
            taskKeyInput.classList.add('border-emerald-500', 'bg-emerald-50/50', 'text-emerald-900');

            taskKeyIcon.className = "absolute right-2.5 top-1/2 -translate-y-1/2 material-icons-round text-base text-emerald-500";
            taskKeyIcon.innerText = "check_circle";
            taskKeyIcon.classList.remove('hidden');

            taskKeyStatusBadge.className = "text-[10px] font-bold px-2 py-0.5 rounded bg-emerald-100 text-emerald-700 border border-emerald-200";
            taskKeyStatusBadge.innerText = "Available";
            taskKeyStatusBadge.classList.remove('hidden');

            taskKeyFeedback.className = "text-[11px] mt-1 font-semibold text-emerald-600 flex items-center gap-1";
            taskKeyFeedback.innerHTML = `<span class="material-icons-round text-xs">check</span> Task Key is unique and ready to use.`;
            taskKeyFeedback.classList.remove('hidden');
        }
    } catch (e) {
        taskKeySpinner.classList.add('hidden');
    }
}

let uploadStartTime;

document.getElementById('taskForm').addEventListener('submit', function(e) {
    e.preventDefault(); 

    if (isKeyDuplicate) {
        Swal.fire({
            icon: 'error',
            title: 'Duplicate Task Key',
            text: 'This Task Key already exists in the system. Please change the Task Key to proceed.',
            confirmButtonColor: '#09090b'
        });
        taskKeyInput.focus();
        return;
    }

    const form = e.target;
    const formData = new FormData(form);
    
    const progressContainer = document.getElementById('uploadProgressContainer');
    const progressBar = document.getElementById('uploadProgressBar');
    const percentageText = document.getElementById('uploadPercentage');
    const speedText = document.getElementById('uploadSpeed');
    const submitBtn = document.getElementById('submitBtn');

    progressContainer.classList.remove('hidden');
    submitBtn.disabled = true; 
    submitBtn.innerHTML = `<span class="animate-spin inline-block w-3.5 h-3.5 border-2 border-zinc-500 border-t-transparent rounded-full"></span> Processing...`;
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
            window.location.href = window.location.pathname.replace('create_task.php', 'my_tasks.php?msg=created');
        } else {
            Swal.fire({ icon: 'error', title: 'Process Failed', text: xhr.responseText, confirmButtonColor: '#09090b' });
            submitBtn.disabled = false;
            submitBtn.innerHTML = `Create Task <span class="material-icons-round text-sm">arrow_forward</span>`;
        }
    }

    xhr.onerror = function() {
        Swal.fire('Network Error', 'Check your connection.', 'error');
        submitBtn.disabled = false;
        submitBtn.innerHTML = `Create Task <span class="material-icons-round text-sm">arrow_forward</span>`;
    };

    xhr.open('POST', form.action || window.location.href, true);
    xhr.send(formData);
});
</script>

</body>
</html>