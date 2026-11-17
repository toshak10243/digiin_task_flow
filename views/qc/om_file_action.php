<?php
/**
 * OM Solution File Action Handler (QC Dashboard & QC Task Lists)
 * Operations Manager Only: Re-upload (Edit) and Delete Operations Solution Files
 * - Replaces or deletes local team_file & uploads/team_work/{task_key}
 * - Automatically clears & replaces Google Drive "Operations Files" subfolder
 * - Ensures ONLY the latest file proceeds to QC & client delivery
 */
require_once "../../bootstrap/app.php";
require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole('operations_manager');

require_once "../../app/config/database.php";
require_once "../../app/services/GoogleDriveService.php";

header('Content-Type: application/json');

$db = (new Database())->connect();
$myId = (int)($_SESSION['user_id'] ?? 0);
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$taskId = (int)($_POST['task_id'] ?? ($_GET['task_id'] ?? 0));

if ($taskId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid task ID']);
    exit;
}

// Fetch task
$stmt = $db->prepare("SELECT * FROM tasks WHERE id = :id");
$stmt->execute([':id' => $taskId]);
$task = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$task) {
    echo json_encode(['success' => false, 'error' => 'Task not found']);
    exit;
}

$taskKey = $task['task_key'];
$safeKey = preg_replace('/[^A-Za-z0-9\-_]/', '_', $taskKey);

/* ========================================================
   ACTION: DELETE OPERATIONS FILE
   ======================================================== */
if ($action === 'delete_file') {
    try {
        // 1. Delete local team_file zip
        if (!empty($task['team_file'])) {
            $localZip = __DIR__ . '/../../' . $task['team_file'];
            if (file_exists($localZip)) {
                @unlink($localZip);
            }
        }

        // 2. Clear extracted directory if any
        $localTeamDir = __DIR__ . '/../../uploads/team_work/' . $safeKey;
        if (is_dir($localTeamDir)) {
            $files = glob($localTeamDir . '/*');
            if ($files) {
                foreach ($files as $f) {
                    if (is_file($f)) @unlink($f);
                }
            }
            @rmdir($localTeamDir);
        }

        // 3. Clear Google Drive Operations Files Subfolder
        try {
            $drive = new GoogleDriveService();
            if ($drive->isReady() && !empty($task['drive_folder_id'])) {
                $drive->clearOperationsFolder($task['drive_folder_id'], $task['drive_operations_folder_id'] ?? null);
            }
        } catch (\Exception $eDrive) {
            error_log("Google Drive delete operations files error: " . $eDrive->getMessage());
        }

        // 4. Update Task in DB: Clear team_file
        $db->prepare("UPDATE tasks SET team_file = NULL WHERE id = :id")->execute([':id' => $taskId]);

        echo json_encode([
            'success' => true,
            'message' => 'Operations solution file has been deleted successfully. You can now re-upload a clean file.'
        ]);
        exit;
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

/* ========================================================
   ACTION: RE-UPLOAD / REPLACE OPERATIONS FILE
   ======================================================== */
if ($action === 'reupload_file') {
    if (empty($_FILES['solution_files']) || !is_array($_FILES['solution_files']['name'])) {
        echo json_encode(['success' => false, 'error' => 'Please select at least one solution file to upload']);
        exit;
    }

    $omNotes = trim($_POST['om_notes'] ?? '');
    $files = $_FILES['solution_files'];
    $fileCount = count($files['name']);

    if ($fileCount === 0 || empty($files['name'][0])) {
        echo json_encode(['success' => false, 'error' => 'No valid files selected']);
        exit;
    }

    try {
        // 1. Clean previous local team_file and staging directory
        if (!empty($task['team_file'])) {
            $oldLocalZip = __DIR__ . '/../../' . $task['team_file'];
            if (file_exists($oldLocalZip)) {
                @unlink($oldLocalZip);
            }
        }

        $localTeamDir = __DIR__ . '/../../uploads/team_work/' . $safeKey;
        if (is_dir($localTeamDir)) {
            $existing = glob($localTeamDir . '/*');
            if ($existing) {
                foreach ($existing as $f) {
                    if (is_file($f)) @unlink($f);
                }
            }
        } else {
            @mkdir($localTeamDir, 0777, true);
        }

        // 2. Save new uploaded files
        $savedFiles = [];
        for ($i = 0; $i < $fileCount; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $origName = basename($files['name'][$i]);
                $cleanName = preg_replace('/[^A-Za-z0-9._\-]/', '_', $origName);
                $destPath = $localTeamDir . '/' . $cleanName;
                if (move_uploaded_file($files['tmp_name'][$i], $destPath)) {
                    $savedFiles[] = [
                        'name' => $cleanName,
                        'path' => $destPath
                    ];
                }
            }
        }

        if (empty($savedFiles)) {
            echo json_encode(['success' => false, 'error' => 'Failed to save uploaded files']);
            exit;
        }

        // 3. Package into clean Zip if multiple files or zip container
        $zipRelativePath = null;
        if (count($savedFiles) === 1 && strtolower(pathinfo($savedFiles[0]['name'], PATHINFO_EXTENSION)) === 'zip') {
            // Already a zip file
            $zipRelativePath = 'uploads/team_work/' . $safeKey . '/' . $savedFiles[0]['name'];
        } else {
            // Create zip bundle
            $zipFileName = 'Solution_' . $safeKey . '_' . time() . '.zip';
            $zipFullPath = __DIR__ . '/../../uploads/team_work/' . $safeKey . '/' . $zipFileName;
            
            $zip = new ZipArchive();
            if ($zip->open($zipFullPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                foreach ($savedFiles as $sf) {
                    $zip->addFile($sf['path'], $sf['name']);
                }
                $zip->close();
                $zipRelativePath = 'uploads/team_work/' . $safeKey . '/' . $zipFileName;
            } else {
                $zipRelativePath = 'uploads/team_work/' . $safeKey . '/' . $savedFiles[0]['name'];
            }
        }

        // 4. Clean and Re-upload to Google Drive (Operations Files Subfolder)
        $newOpsDriveLink = null;
        try {
            $drive = new GoogleDriveService();
            if ($drive->isReady()) {
                // Ensure task folder exists
                $folderInfo = $drive->createTaskFolder($taskKey, $task['drive_folder_id'] ?: null);
                if (!empty($folderInfo['success'])) {
                    $taskFolderId = $folderInfo['folder']['id'] ?? $task['drive_folder_id'];
                    $opsFolderId  = $folderInfo['operations_folder']['id'] ?? $task['drive_operations_folder_id'];

                    // Clear previous files from Operations Files subfolder
                    $drive->clearOperationsFolder($taskFolderId, $opsFolderId);

                    // Upload new files into Operations Files subfolder
                    foreach ($savedFiles as $sf) {
                        $drive->uploadFile($sf['path'], $opsFolderId, $sf['name']);
                    }

                    $newOpsDriveLink = $folderInfo['operations_folder']['link'] ?? ("https://drive.google.com/drive/folders/" . $opsFolderId);

                    // Update drive links in DB if changed
                    $db->prepare("UPDATE tasks SET
                            drive_folder_id = :dfid,
                            drive_operations_folder_id = :dofid,
                            drive_operations_link = :dolink
                        WHERE id = :id")->execute([
                            ':dfid'   => $taskFolderId,
                            ':dofid'  => $opsFolderId,
                            ':dolink' => $newOpsDriveLink,
                            ':id'     => $taskId
                        ]);
                }
            }
        } catch (\Exception $eDrive) {
            error_log("Google Drive reupload operations files error: " . $eDrive->getMessage());
        }

        // 5. Update Task in DB: Set new team_file, optional om_notes
        $updateSql = "UPDATE tasks SET team_file = :tf";
        $updateParams = [':tf' => $zipRelativePath, ':id' => $taskId];

        if (!empty($omNotes)) {
            $updateSql .= ", om_notes = :notes";
            $updateParams[':notes'] = $omNotes;
        }
        $updateSql .= " WHERE id = :id";
        $db->prepare($updateSql)->execute($updateParams);

        echo json_encode([
            'success'   => true,
            'message'   => 'Latest solution file replaced successfully! The updated file is now live for QC.',
            'file_path' => $zipRelativePath,
            'drive_link'=> $newOpsDriveLink
        ]);
        exit;
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
