<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/GoogleDriveService.php';

class TaskController 
{

    public function createTask($data)
    {
        $db = (new Database())->connect();

        $sql = "INSERT INTO tasks 
        (task_key, task_name, client_name, task_created_at, deadline, word_count, university, country, subject, instructions, urgent, created_by) 
        VALUES (:task_key, :task_name, :client_name, :task_created_at, :deadline, :word_count, :university, :country, :subject, :instructions, :urgent, :created_by)";

        $stmt = $db->prepare($sql);

        $created = $stmt->execute([
            ':task_key'        => $data['task_key'],
            ':task_name'       => $data['task_name'],
            ':client_name'     => $data['client_name'],
            ':task_created_at' => $data['task_created_at'],
            ':deadline'        => $data['deadline'],
            ':word_count'      => $data['word_count'],
            ':university'      => $data['university'],
            ':country'         => $data['country'],
            ':subject'         => $data['subject'],
            ':instructions'    => $data['instructions'],
            ':urgent'          => $data['urgent'],
            ':created_by'      => $_SESSION['user_id']
        ]);

        if (!$created) {
            return false;
        }

        $taskId = $db->lastInsertId();

        // Send Notification to Operations Managers and Admins
        try {
            require_once __DIR__ . '/../services/NotificationService.php';
            NotificationService::onTaskCreated(
                (int)$taskId,
                $data['task_key'],
                $data['task_name'],
                $_SESSION['username'] ?? 'BDE',
                ($data['urgent'] === 'yes')
            );
        } catch (Exception $eNotif) {
            error_log("Task creation notification failed: " . $eNotif->getMessage());
        }

        // 1. Instant local staging & ZIP backup generation
        $stagedFiles = $this->stageClientFilesLocally($data['task_key'], $taskId);

        // 2. Strict Google Drive Verification & Sync
        // If Google Drive API fails or is not ready, task creation MUST abort and rollback
        $drive = new GoogleDriveService();
        if (!$drive->isReady()) {
            // Rollback inserted task from database
            $db->prepare("DELETE FROM tasks WHERE id = :id")->execute([':id' => $taskId]);
            // Clean up staged files
            $this->cleanupStagedClientFiles($data['task_key'], $stagedFiles);
            throw new Exception("Google Drive API Error: " . ($drive->getInitError() ?: "Drive service is not ready. Task was not uploaded."));
        }

        try {
            $folder = $drive->createTaskFolder($data['task_key']);
            
            if (empty($folder['success'])) {
                $db->prepare("DELETE FROM tasks WHERE id = :id")->execute([':id' => $taskId]);
                $this->cleanupStagedClientFiles($data['task_key'], $stagedFiles);
                throw new Exception("Google Drive Folder Creation Failed: " . ($folder['message'] ?? 'Unable to create task folder in Google Drive.'));
            }

            $clientFolderId = $folder['client_folder']['id'] ?? null;
            $update = $db->prepare("
                UPDATE tasks
                SET
                    drive_folder_id             = :folder_id,
                    drive_folder_name           = :folder_name,
                    drive_folder_link           = :folder_link,
                    drive_client_folder_id      = :client_fid,
                    drive_client_link           = :client_link,
                    drive_operations_folder_id  = :ops_fid,
                    drive_operations_link       = :ops_link,
                    drive_additional_folder_id  = :add_fid,
                    drive_additional_link       = :add_link
                WHERE id = :id
            ");

            $update->execute([
                ':folder_id'   => $folder['task_folder_id'],
                ':folder_name' => $folder['task_folder_name'],
                ':folder_link' => $folder['task_folder_link'],
                ':client_fid'  => $folder['client_folder']['id'] ?? null,
                ':client_link' => $folder['client_folder']['link'] ?? null,
                ':ops_fid'     => $folder['operations_folder']['id'] ?? null,
                ':ops_link'    => $folder['operations_folder']['link'] ?? null,
                ':add_fid'     => $folder['additional_folder']['id'] ?? null,
                ':add_link'    => $folder['additional_folder']['link'] ?? null,
                ':id'          => $taskId
            ]);

            // If files were selected, upload each to Client Files folder and verify
            if ($clientFolderId && !empty($stagedFiles)) {
                $safeTaskKey = preg_replace('/[^A-Za-z0-9\-_]/', '_', $data['task_key']);
                foreach ($stagedFiles as $sf) {
                    $driveFileName = $safeTaskKey . '_' . $sf['name'];
                    $upRes = $drive->uploadFile($sf['local_path'], $clientFolderId, $driveFileName);
                    if (empty($upRes['success'])) {
                        $db->prepare("DELETE FROM tasks WHERE id = :id")->execute([':id' => $taskId]);
                        $this->cleanupStagedClientFiles($data['task_key'], $stagedFiles);
                        throw new Exception("Google Drive File Upload Failed: " . ($upRes['message'] ?? 'Could not sync file to Drive.'));
                    }
                }
            }

        } catch (\Exception $eDrive) {
            $db->prepare("DELETE FROM tasks WHERE id = :id")->execute([':id' => $taskId]);
            $this->cleanupStagedClientFiles($data['task_key'], $stagedFiles);
            throw new Exception("Google Drive Sync Failed: " . $eDrive->getMessage());
        }

        return true;
    }

    /**
     * Helper to clean up staged client files upon failure
     */
    private function cleanupStagedClientFiles($taskKey, $stagedFiles)
    {
        if (!empty($stagedFiles)) {
            foreach ($stagedFiles as $sf) {
                if (!empty($sf['local_path']) && file_exists($sf['local_path'])) {
                    @unlink($sf['local_path']);
                }
            }
        }
        $safeTaskKey = preg_replace('/[^A-Za-z0-9\-_]/', '_', $taskKey);
        $clientUploadDir = __DIR__ . "/../../uploads/client_files/" . $safeTaskKey;
        if (is_dir($clientUploadDir)) {
            $leftovers = glob($clientUploadDir . '/*');
            if ($leftovers) {
                foreach ($leftovers as $f) {
                    if (is_file($f)) @unlink($f);
                }
            }
            @rmdir($clientUploadDir);
        }
    }

    /**
     * Stage Client Files Locally and generate ZIP package immediately
     */
    private function stageClientFilesLocally($taskKey, $taskId = null)
    {
        if (!isset($_FILES['task_files']) || empty($_FILES['task_files']['name'][0])) {
            return [];
        }

        $safeTaskKey = preg_replace('/[^A-Za-z0-9\-_]/', '_', $taskKey);
        $clientUploadDir = __DIR__ . "/../../uploads/client_files/" . $safeTaskKey . "/";
        if (!is_dir($clientUploadDir)) {
            mkdir($clientUploadDir, 0777, true);
        }

        $stagedFiles = [];

        foreach ($_FILES['task_files']['name'] as $key => $name) {
            if ($_FILES['task_files']['error'][$key] !== UPLOAD_ERR_OK) {
                continue;
            }

            $tmpPath = $_FILES['task_files']['tmp_name'][$key];
            $originalPath = $_POST['full_paths'][$key] ?? $name;
            $relativePath = str_replace('\\', '/', $originalPath);

            $targetLocalPath = $clientUploadDir . $relativePath;
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

        // Create local ZIP backup for immediate download
        if (class_exists('ZipArchive') && count($stagedFiles) > 0 && $taskId) {
            $zipName = $safeTaskKey . "_client_files_" . time() . ".zip";
            $zipFullPath = __DIR__ . "/../../uploads/client_files/" . $zipName;
            $zip = new ZipArchive();
            if ($zip->open($zipFullPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                foreach ($stagedFiles as $sf) {
                    if (file_exists($sf['local_path'])) {
                        $zip->addFile($sf['local_path'], $sf['relative_path']);
                    }
                }
                $zip->close();
                $db = (new Database())->connect();
                $db->prepare("UPDATE tasks SET file_path = :fp WHERE id = :id")->execute([':fp' => "uploads/client_files/" . $zipName, ':id' => $taskId]);
            }
        }

        return $stagedFiles;
    }

    public function getMyTasks($userId)
    {
        $db = (new Database())->connect();
        $stmt = $db->prepare("SELECT * FROM tasks WHERE created_by=:id ORDER BY id DESC");
        $stmt->execute([':id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function reassignTask($taskId, $newUserId, $notes) 
    {
        $db = (new Database())->connect();
        
        // Pehle check karo ki task completed toh nahi hai (Enterprise Security)
        $check = $db->prepare("SELECT current_stage FROM tasks WHERE id = :id");
        $check->execute([':id' => $taskId]);
        $task = $check->fetch();

        if ($task && $task['current_stage'] !== 'completed') {
            $stmt = $db->prepare("UPDATE tasks SET assigned_to = :assigned_to, om_notes = :notes, assigned_at = NOW() WHERE id = :id");
            return $stmt->execute([
                ':assigned_to' => $newUserId,
                ':notes' => $notes,
                ':id' => $taskId
            ]);
        }
        return false;
    }
}