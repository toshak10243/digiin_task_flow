<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ⬇️ .ENV LOAD KARNE KE LIYE ZAROORI HAI ⬇️
require_once "../../bootstrap/app.php";

require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole('bde');
require_once "../../app/config/database.php";

 $db = (new Database())->connect();

// Auto-migrate: ensure additional_instructions column exists in tasks table
try {
    $db->exec("ALTER TABLE tasks ADD COLUMN additional_instructions TEXT NULL AFTER additional_files");
} catch (Exception $e) {}

 $task_id = $_GET['task_id'] ?? null;

if (!$task_id) { header("Location: my_tasks.php"); exit; }

// Fetch complete task details
 $stmt = $db->prepare("SELECT * FROM tasks WHERE id = ?");
 $stmt->execute([$task_id]);
 $t = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$t || $t['status'] == 'completed') { header("Location: my_tasks.php"); exit; }

/* ================= ADDITIONAL FILES & INSTRUCTIONS HANDLER ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $taskId  = $_POST['task_id'] ?? null;
        $taskKey = $_POST['task_key'] ?? '';
        $additionalInstructions = trim($_POST['additional_instructions'] ?? '');

        if (!$taskId || !$taskKey) {
            throw new Exception("Missing task reference");
        }

        $safeTaskKey = preg_replace('/[^A-Za-z0-9\-_]/', '_', trim($taskKey));
        $uploadDir = __DIR__ . "/../../uploads/additional_files/" . $safeTaskKey . "/";
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $stagedFiles = [];
        if (isset($_FILES['task_files']) && !empty($_FILES['task_files']['name'][0])) {
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
        }

        $hasFiles = !empty($stagedFiles);
        $hasInstructions = ($additionalInstructions !== '');

        if (!$hasFiles && !$hasInstructions) {
            throw new Exception("Please enter additional instructions or select at least one file to upload.");
        }

        // Fetch current task assigned_to for notification
        $stmtCurr = $db->prepare("SELECT assigned_to, drive_folder_id FROM tasks WHERE id = :id");
        $stmtCurr->execute([':id' => $taskId]);
        $currTask = $stmtCurr->fetch(PDO::FETCH_ASSOC);
        $assignedTo = (int)($currTask['assigned_to'] ?? 0);
        $existingFolderId = $currTask['drive_folder_id'] ?? null;

        if ($hasFiles) {
            // Create local ZIP backup
            $localZipPath = null;
            if (class_exists('ZipArchive') && count($stagedFiles) > 0) {
                $zipName = $safeTaskKey . "_additional_" . time() . ".zip";
                $zipFullPath = __DIR__ . "/../../uploads/additional_files/" . $zipName;
                $zip = new ZipArchive();
                if ($zip->open($zipFullPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                    foreach ($stagedFiles as $sf) {
                        if (file_exists($sf['local_path'])) {
                            $zip->addFile($sf['local_path'], $sf['relative_path']);
                        }
                    }
                    $zip->close();
                    $localZipPath = "uploads/additional_files/" . $zipName;
                }
            }

            // Enforce Google Drive Verification & Sync for Additional Files FIRST
            require_once "../../app/services/GoogleDriveService.php";
            $drive = new GoogleDriveService();
            if (!$drive->isReady()) {
                if ($localZipPath && file_exists(__DIR__ . "/../../" . $localZipPath)) {
                    @unlink(__DIR__ . "/../../" . $localZipPath);
                }
                http_response_code(500);
                echo json_encode(['error' => "Google Drive API Error: " . ($drive->getInitError() ?: "Drive service is not ready. Files were not uploaded.")]);
                exit;
            }

            try {
                $folderData = $drive->createTaskFolder($taskKey, $existingFolderId ?: null);
                if (empty($folderData['success'])) {
                    throw new Exception("Google Drive Folder Creation Failed: " . ($folderData['message'] ?? 'Could not access task folder in Drive'));
                }

                $additionalFolderId = $folderData['additional_folder']['id'];
                foreach ($stagedFiles as $sf) {
                    $driveFileName = $safeTaskKey . '_ADD_' . $sf['name'];
                    $upRes = $drive->uploadFile($sf['local_path'], $additionalFolderId, $driveFileName);
                    if (empty($upRes['success'])) {
                        throw new Exception("Google Drive Upload Failed: " . ($upRes['message'] ?? 'Could not upload file to Drive'));
                    }
                }

                // Update DB with both Drive link and instructions
                $db->prepare("UPDATE tasks SET 
                    additional_files           = :link,
                    additional_instructions    = :ai,
                    drive_folder_id            = COALESCE(drive_folder_id, :fid),
                    drive_folder_link          = COALESCE(drive_folder_link, :flink),
                    drive_additional_folder_id = :afid,
                    drive_additional_link      = :alink,
                    drive_client_folder_id     = COALESCE(drive_client_folder_id, :cfid),
                    drive_client_link          = COALESCE(drive_client_link, :clink),
                    drive_operations_folder_id = COALESCE(drive_operations_folder_id, :ofid),
                    drive_operations_link      = COALESCE(drive_operations_link, :olink)
                WHERE id = :id")->execute([
                    ':link'  => $folderData['additional_folder']['link'] ?? $localZipPath,
                    ':ai'    => $hasInstructions ? $additionalInstructions : ($t['additional_instructions'] ?? null),
                    ':fid'   => $folderData['task_folder_id'] ?? null,
                    ':flink' => $folderData['task_folder_link'] ?? null,
                    ':afid'  => $folderData['additional_folder']['id'] ?? null,
                    ':alink' => $folderData['additional_folder']['link'] ?? null,
                    ':cfid'  => $folderData['client_folder']['id'] ?? null,
                    ':clink' => $folderData['client_folder']['link'] ?? null,
                    ':ofid'  => $folderData['operations_folder']['id'] ?? null,
                    ':olink' => $folderData['operations_folder']['link'] ?? null,
                    ':id'    => $taskId
                ]);

            } catch (\Exception $eDrive) {
                if ($localZipPath && file_exists(__DIR__ . "/../../" . $localZipPath)) {
                    @unlink(__DIR__ . "/../../" . $localZipPath);
                }
                http_response_code(500);
                echo json_encode(['error' => "Google Drive Sync Failed: " . $eDrive->getMessage()]);
                exit;
            }
        } else {
            // Only updating additional instructions (fast local DB update)
            $db->prepare("UPDATE tasks SET additional_instructions = :ai WHERE id = :id")->execute([
                ':ai' => $additionalInstructions,
                ':id' => $taskId
            ]);
        }

        // Notify Operations Manager and Assigned Expert
        try {
            require_once "../../app/services/NotificationService.php";
            NotificationService::onAdditionalFilesUploaded((int)$taskId, $taskKey, $assignedTo, $hasInstructions);
        } catch (Exception $eNotif) {}

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
<title>Additional Files | <?= $t['task_key'] ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
    * { font-family: 'Inter', sans-serif; }
    body { background-color: #f4f4f5; }
    
    .f-label { 
        display: block; font-size: 0.6875rem; font-weight: 600; color: #71717a; 
        margin-bottom: 0.25rem; text-transform: uppercase; letter-spacing: 0.05em; 
    }
    .f-value { font-size: 0.8125rem; font-weight: 600; color: #18181b; }

    .drop-zone {
        border: 1px dashed #d4d4d8; border-radius: 0.375rem; padding: 1.5rem;
        background-color: rgba(250, 250, 250, 0.5); transition: all 0.2s ease;
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
                    <h1 class="text-xl font-semibold tracking-tight text-zinc-900">Additional Files</h1>
                    <p class="text-xs text-zinc-400 mt-0.5">Upload supplemental materials for <span class="font-mono font-semibold text-zinc-700"><?= $t['task_key'] ?></span></p>
                </div>
                <a href="my_tasks.php" class="text-xs text-zinc-500 hover:text-zinc-900 font-medium transition-colors flex items-center gap-1">
                    <span class="material-icons-round text-sm">arrow_back</span> Back
                </a>
            </div>

            <!-- 📄 COMPLETE TASK DETAILS VIEW MODE -->
            <div class="bg-white border border-zinc-200 rounded-md p-5 shadow-sm mb-5">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-x-6 gap-y-5 text-xs">
                    
                    <div>
                        <div class="f-label">Task Key</div>
                        <div class="f-value text-blue-600 font-mono"><?= $t['task_key'] ?></div>
                    </div>

                    <div>
                        <div class="f-label">Project Name</div>
                        <div class="f-value truncate"><?= htmlspecialchars($t['task_name']) ?></div>
                    </div>

                    <div>
                        <div class="f-label">Client Name</div>
                        <div class="f-value text-emerald-600"><?= htmlspecialchars($t['client_name']) ?></div>
                    </div>

                    <div>
                        <div class="f-label">Word Count</div>
                        <div class="f-value"><?= number_format($t['word_count']) ?> Words</div>
                    </div>

                    <div>
                        <div class="f-label">University & Country</div>
                        <div class="f-value"><?= $t['university'] ?: 'N/A' ?> (<?= $t['country'] ?: 'N/A' ?>)</div>
                    </div>

                    <div>
                        <div class="f-label">Deadline</div>
                        <div class="f-value text-rose-600"><?= date("d M Y, h:i A", strtotime($t['deadline'])) ?></div>
                    </div>

                    <div>
                        <div class="f-label">Original Brief</div>
                        <div class="mt-1">
                            <?php 
                            if (!empty($t['drive_folder_link'])) { ?>
                                <a href="<?= $t['drive_folder_link'] ?>" target="_blank" class="flex items-center gap-1.5 text-blue-600 hover:underline font-semibold">
                                    <span class="material-icons-round text-sm">folder_open</span> Open Drive
                                </a>
                            <?php } elseif (!empty($t['file_path'])) { ?>
                                <a href="../../<?= $t['file_path'] ?>" target="_blank" class="flex items-center gap-1.5 text-zinc-600 hover:underline font-semibold">
                                    <span class="material-icons-round text-sm">download</span> Download File
                                </a>
                            <?php } else { ?>
                                <span class="text-zinc-300">No initial file</span>
                            <?php } ?>
                        </div>
                    </div>

                    <div>
                        <div class="f-label">Existing Extras</div>
                        <div class="mt-1">
                            <?php 
                            if (!empty($t['additional_files'])) {
                                if (strpos($t['additional_files'], 'http') === 0) { ?>
                                    <a href="<?= $t['additional_files'] ?>" target="_blank" class="flex items-center gap-1.5 text-blue-600 hover:underline font-semibold">
                                        <span class="material-icons-round text-sm">folder_open</span> View in Drive
                                    </a>
                                <?php } else { ?>
                                    <a href="../../<?= $t['additional_files'] ?>" target="_blank" class="flex items-center gap-1.5 text-zinc-600 hover:underline font-semibold">
                                        <span class="material-icons-round text-sm">folder_zip</span> View Local File
                                    </a>
                                <?php }
                            } else { ?>
                                <span class="text-zinc-300">None</span>
                            <?php } ?>
                        </div>
                    </div>

                    <div class="md:col-span-4 border-t border-zinc-100 pt-4">
                        <div class="f-label">Original Instructions</div>
                        <p class="text-xs text-zinc-600 font-medium leading-relaxed bg-zinc-50 p-3 rounded border border-zinc-100 mt-1">
                            <?= htmlspecialchars($t['instructions']) ?: 'No instructions provided.' ?>
                        </p>
                    </div>

                    <?php if (!empty($t['additional_instructions'])): ?>
                    <div class="md:col-span-4 border-t border-amber-200/80 pt-4">
                        <div class="f-label text-amber-800 flex items-center gap-1.5 font-bold">
                            <span class="material-icons-round text-sm text-amber-600">new_releases</span> Current Additional Instructions / Updates
                        </div>
                        <div class="text-xs text-amber-950 font-semibold leading-relaxed bg-amber-50/80 p-3.5 rounded border border-amber-200 mt-1 whitespace-pre-line">
                            <?= nl2br(htmlspecialchars($t['additional_instructions'])) ?>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
            </div>

            <!-- 📤 UPLOADER FORM -->
            <form method="POST" enctype="multipart/form-data" id="uploadForm" class="space-y-5">
                <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                <input type="hidden" name="task_key" value="<?= $t['task_key'] ?>">

                <!-- 📝 ADDITIONAL INSTRUCTIONS / CLIENT UPDATE -->
                <div class="bg-white border border-zinc-200 rounded-md p-5 shadow-sm">
                    <div class="flex items-center justify-between mb-1.5">
                        <label for="additional_instructions" class="f-label mb-0 flex items-center gap-1.5 text-zinc-900 font-bold">
                            <span class="material-icons-round text-sm text-amber-500">rate_review</span>
                            Additional Instructions / Client Brief Update
                        </label>
                        <span class="text-[10px] text-zinc-400 font-medium">(Reaches OM & Team Directly)</span>
                    </div>
                    <p class="text-[11px] text-zinc-400 mb-3">
                        Agar client ne koi intro update, requirement change, chapter details, ya specific guidelines bheje hain, toh yahan enter karein:
                    </p>
                    <textarea name="additional_instructions" id="additional_instructions" rows="4"
                              placeholder="Write client intro update, additional guidelines, or instructions here..."
                              class="w-full text-xs text-zinc-800 bg-zinc-50 border border-zinc-200 rounded-md p-3.5 focus:outline-none focus:ring-2 focus:ring-zinc-900 focus:bg-white transition-all resize-y leading-relaxed font-medium"><?= htmlspecialchars($t['additional_instructions'] ?? '') ?></textarea>
                </div>

                <div class="bg-white border border-zinc-200 rounded-md p-5 shadow-sm">
                    <div class="flex items-center justify-between mb-3">
                        <span class="f-label mb-0">Upload Materials (Goes to Drive)</span>
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
                        <p class="text-[11px] text-zinc-400 mt-1.5 font-medium">Drag & drop additional files here</p>
                    </div>

                    <div id="pathInputs"></div>
                    <div id="filePreview" class="mt-1 border border-zinc-100 rounded-md max-h-36 overflow-y-auto clean-scroll bg-zinc-50/50"></div>
                </div>

                <!-- Footer Actions -->
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
                    
                    <button type="submit" id="submitBtn" class="bg-zinc-900 hover:bg-zinc-800 text-white px-6 py-2.5 rounded-md text-xs font-semibold flex items-center gap-2 transition-colors shadow-sm cursor-pointer">
                        Save & Push Updates
                        <span class="material-icons-round text-sm">cloud_done</span>
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

let uploadStartTime;

document.getElementById('uploadForm').addEventListener('submit', function(e) {
    e.preventDefault(); 
    const form = e.target;
    const additionalNotes = document.getElementById('additional_instructions').value.trim();

    // Check if both files and notes are empty
    if (allFiles.length === 0 && additionalNotes === '') {
        Swal.fire({
            icon: 'warning',
            title: 'Empty Submission',
            text: 'Please enter additional instructions or select at least one file to upload.',
            confirmButtonColor: '#18181b',
            customClass: { popup: 'rounded-xl' }
        });
        return false;
    }

    const formData = new FormData(form);
    const progressContainer = document.getElementById('uploadProgressContainer');
    const progressBar = document.getElementById('uploadProgressBar');
    const percentageText = document.getElementById('uploadPercentage');
    const speedText = document.getElementById('uploadSpeed');
    const submitBtn = document.getElementById('submitBtn');

    if (allFiles.length > 0) {
        progressContainer.classList.remove('hidden');
        submitBtn.disabled = true; 
        submitBtn.innerHTML = `<span class="animate-spin inline-block w-3.5 h-3.5 border-2 border-zinc-500 border-t-transparent rounded-full"></span> Syncing Drive...`;
        progressBar.style.width = '0%';
        percentageText.innerText = '0%';
        speedText.innerText = '0 KB/s';
    } else {
        submitBtn.disabled = true; 
        submitBtn.innerHTML = `<span class="animate-spin inline-block w-3.5 h-3.5 border-2 border-zinc-500 border-t-transparent rounded-full"></span> Saving...`;
    }

    uploadStartTime = new Date().getTime();

    const xhr = new XMLHttpRequest();
    
    if (allFiles.length > 0) {
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
    }

    xhr.onload = function () {
        if (xhr.status === 200) {
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
                title: allFiles.length > 0 ? 'Additional files & instructions saved' : 'Additional instructions updated'
            }).then(() => {
                window.location.href = "my_tasks.php?updated=1";
            });
        } else {
            Swal.fire({ icon: 'error', title: 'Upload Failed', text: xhr.responseText, confirmButtonColor: '#09090b' });
            submitBtn.disabled = false;
            submitBtn.innerHTML = `Save & Push Updates <span class="material-icons-round text-sm">cloud_done</span>`;
            progressContainer.classList.add('hidden');
        }
    }

    xhr.onerror = function() {
        Swal.fire('Network Error', 'Check your connection.', 'error');
        submitBtn.disabled = false;
        submitBtn.innerHTML = `Save & Push Updates <span class="material-icons-round text-sm">cloud_done</span>`;
        progressContainer.classList.add('hidden');
    };

    xhr.open('POST', window.location.href, true);
    xhr.send(formData);
});
</script>

</body>
</html>