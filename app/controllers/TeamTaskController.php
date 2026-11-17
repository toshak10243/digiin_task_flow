<?php
// Upload limits badhane ke liye (Bade folders ke liye zaroori hai)
ini_set('memory_limit', '512M');
ini_set('upload_max_filesize', '100M');
ini_set('post_max_size', '110M');
ini_set('max_file_uploads', '500');
set_time_limit(300);

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once "../config/database.php";

date_default_timezone_set('Asia/Kolkata');
$db = (new Database())->connect();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $task_id = $_POST['task_id'];
        $task_key = $_POST['task_key'];
        $notes = $_POST['notes'] ?? "";
        $fullPaths = $_POST['full_paths'] ?? []; // 📂 Folder structure ke liye

        /* ================= FETCH OLD FILE ================= */
        $oldTask = $db->prepare("SELECT team_file FROM tasks WHERE id = :id");
        $oldTask->execute([':id' => $task_id]);
        $old = $oldTask->fetch(PDO::FETCH_ASSOC);

        /* ================= UPLOAD DIR ================= */
        // Path fix: Controller se uploads folder tak ka raasta
        $uploadDir = "../../uploads/team_work/";
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $uploadedFiles = [];

        /* ================= MULTIPLE FILE UPLOAD ================= */
        if (isset($_FILES['work_files']) && !empty($_FILES['work_files']['name'][0])) {
            
            // 🛡️ Security: Sirf executable scripts block hain, baki sab allowed hai
            $blockedExt = ['php', 'phtml', 'php3', 'php4', 'php5', 'htaccess', 'cgi'];

            foreach ($_FILES['work_files']['name'] as $key => $name) {
                if ($_FILES['work_files']['error'][$key] != 0) continue;

                $tmpFile = $_FILES['work_files']['tmp_name'][$key];
                
                // 📂 FOLDER LOGIC: Agar full_path hai toh wo lo, warna normal name
                $originalRelativePath = $fullPaths[$key] ?? basename($name);
                $ext = strtolower(pathinfo($originalRelativePath, PATHINFO_EXTENSION));

                if (in_array($ext, $blockedExt)) {
                    throw new Exception("Security: .$ext files are not allowed.");
                }

                // Temp unique name for server storage
                $tempName = uniqid() . "_" . bin2hex(random_bytes(4));
                $targetPath = $uploadDir . $tempName;

                if (move_uploaded_file($tmpFile, $targetPath)) {
                    $uploadedFiles[] = [
                        "temp_path" => $targetPath,
                        "zip_path"  => $originalRelativePath // ZIP ke andar ye rasta banega
                    ];
                }
            }
        } else {
            throw new Exception("Please upload at least one file.");
        }

        /* ================= ZIP CREATE ================= */
        if (!class_exists('ZipArchive')) {
            throw new Exception("ZIP Extension not enabled on server.");
        }

        $safeTaskKey = preg_replace('/[^A-Za-z0-9\-_]/', '_', $task_key);
        $zipName = $safeTaskKey . "_completed_" . time() . ".zip";
        $zipFullPath = $uploadDir . $zipName;

        $zip = new ZipArchive();
        if ($zip->open($zipFullPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            foreach ($uploadedFiles as $file) {
                if (file_exists($file['temp_path'])) {
                    // 📁 addFile(asli_file, zip_ke_andar_ka_rasta)
                    $zip->addFile($file['temp_path'], $file['zip_path']);
                }
            }
            $zip->close();

            // Temp files cleanup
            foreach ($uploadedFiles as $file) {
                if (file_exists($file['temp_path'])) unlink($file['temp_path']);
            }
        } else {
            throw new Exception("Failed to create ZIP package.");
        }

        /* ================= DELETE OLD ZIP ================= */
        if (!empty($old['team_file'])) {
            $oldZipPath = "../../" . $old['team_file'];
            if (file_exists($oldZipPath)) unlink($oldZipPath);
        }

        /* ================= SAVE & UPDATE DB ================= */
        $filePathForDB = "uploads/team_work/" . $zipName;
        
        $stmt = $db->prepare("
            UPDATE tasks SET 
                team_file = :file, 
                team_notes = :notes, 
                current_stage = 'qc', 
                status = 'submitted' 
            WHERE id = :id
        ");

        $stmt->execute([
            ':file'  => $filePathForDB,
            ':notes' => $notes,
            ':id'    => $task_id
        ]);

        header("Location: ../../views/operations_team/my_tasks.php?submitted=1");
        exit;

    } catch (Exception $e) {
        die("Expert Controller Error: " . $e->getMessage());
    }
}