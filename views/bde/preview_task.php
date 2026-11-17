<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ⬇️ .ENV LOAD KARNE KE LIYE ZAROORI HAI ⬇️
require_once "../../bootstrap/app.php";

require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/config/database.php";

 $db = (new Database())->connect();

if (!isset($_GET['id'])) {
    header("Location: my_tasks.php");
    exit;
}

 $stmt = $db->prepare("SELECT * FROM tasks WHERE id = ?");
 $stmt->execute([$_GET['id']]);
 $t = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$t) {
    die("Task not found!");
}

// Safely fetch Drive Folders (Idempotent - Returns existing if already created)
 $driveFolders = null;
if (!empty($t['drive_folder_id'])) {
    require_once "../../app/services/GoogleDriveService.php";
    $drive = new GoogleDriveService();
    $res = $drive->createTaskFolder($t['task_key']);
    
    if (isset($res['success'])) {
        $driveFolders = $res;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>View Task | Digiink Workflow</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
    * { font-family: 'Inter', sans-serif; }
    body { background-color: #f4f4f5; }
    
    .f-input { 
        width: 100%; padding: 0.45rem 0.6rem; font-size: 0.8125rem; 
        border: 1px solid #e4e4e7; border-radius: 0.375rem; 
        background: #fafafa; color: #3f3f46; cursor: default;
    }
    .f-label { 
        display: block; font-size: 0.6875rem; font-weight: 600; color: #71717a; 
        margin-bottom: 0.375rem; text-transform: uppercase; letter-spacing: 0.05em; 
    }
    .chip { 
        display: inline-flex; align-items: center; gap: 0.25rem; 
        padding: 0.15rem 0.5rem; border-radius: 0.25rem; 
        font-size: 0.625rem; font-weight: 600; text-transform: uppercase; 
        letter-spacing: 0.025em; border-left: 3px solid; 
    }
    .chip-new { background: #f4f4f5; color: #52525b; border-color: #a1a1aa; }
    .chip-assigned { background: #fef3c7; color: #92400e; border-color: #f59e0b; }
    .chip-in_progress { background: #dbeafe; color: #1e40af; border-color: #3b82f6; }
    .chip-completed { background: #d1fae5; color: #065f46; border-color: #10b981; }
    .chip-rework { background: #ffe4e6; color: #9f1239; border-color: #f43f5e; }
</style>
</head>

<body class="text-zinc-900 antialiased">

<div class="flex h-screen overflow-hidden">
    <?php include "partials/sidebar.php"; ?>

    <main class="flex-1 overflow-y-auto">
        <div class="max-w-5xl mx-auto p-6 lg:p-10 space-y-5">
            
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="text-[10px] font-mono font-bold text-zinc-400 bg-zinc-100 px-2 py-0.5 rounded"><?= $t['task_key'] ?></span>
                        <h1 class="text-xl font-semibold tracking-tight text-zinc-900"><?= htmlspecialchars($t['task_name']) ?></h1>
                    </div>
                </div>
                <a href="javascript:history.back()" class="text-xs text-zinc-500 hover:text-zinc-900 font-medium transition-colors flex items-center gap-1">
                    <span class="material-icons-round text-sm">arrow_back</span> Back
                </a>
            </div>

            <!-- Meta Grid -->
            <div class="bg-white border border-zinc-200 rounded-md p-5 shadow-sm">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-xs">
                    <div>
                        <div class="f-label">Client Name</div>
                        <div class="font-semibold text-zinc-800"><?= htmlspecialchars($t['client_name'] ?: 'N/A') ?></div>
                    </div>
                    <div>
                        <div class="f-label">Deadline</div>
                        <div class="font-semibold text-rose-600"><?= date("d M Y, H:i", strtotime($t['deadline'])) ?></div>
                    </div>
                    <div>
                        <div class="f-label">Words</div>
                        <div class="font-semibold text-zinc-800 font-mono"><?= number_format($t['word_count']) ?></div>
                    </div>
                    <div class="flex items-end gap-3">
                        <div>
                            <div class="f-label">Priority</div>
                            <span class="chip chip-<?= $t['urgent'] == 'yes' ? 'rework' : 'new' ?>">
                                <?= $t['urgent'] == 'yes' ? 'Urgent' : 'Normal' ?>
                            </span>
                        </div>
                        <div>
                            <div class="f-label">Status</div>
                            <span class="chip chip-<?= $t['status'] ?>">
                                <?= str_replace('_', ' ', $t['status']) ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Details -->
            <div class="bg-white border border-zinc-200 rounded-md p-5 shadow-sm">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                    <div>
                        <div class="f-label">University</div>
                        <div class="f-input"><?= htmlspecialchars($t['university'] ?: 'N/A') ?></div>
                    </div>
                    <div>
                        <div class="f-label">Country</div>
                        <div class="f-input"><?= htmlspecialchars($t['country'] ?: 'N/A') ?></div>
                    </div>
                    <div>
                        <div class="f-label">Subject</div>
                        <div class="f-input"><?= htmlspecialchars($t['subject'] ?: 'N/A') ?></div>
                    </div>
                    <div>
                        <div class="f-label">Created At</div>
                        <div class="f-input"><?= date('d M Y, h:i A', strtotime($t['task_created_at'])) ?></div>
                    </div>
                    <div class="md:col-span-2">
                        <div class="f-label">Instructions</div>
                        <div class="f-input min-h-[80px] whitespace-pre-wrap"><?= htmlspecialchars($t['instructions'] ?: 'No instructions provided.') ?></div>
                    </div>
                </div>
            </div>

            <!-- 📂 GOOGLE DRIVE REPOSITORY (3 EXPLICIT FOLDERS) -->
            <div class="bg-white border border-zinc-200 rounded-md p-5 shadow-sm">
                <div class="f-label mb-3">Google Drive Repository</div>
                
                <?php if($driveFolders): ?>
                <div class="grid grid-cols-3 gap-3">
                    <a href="<?= $driveFolders['client_folder']['link'] ?>" target="_blank" class="flex flex-col items-center gap-2 p-4 border border-zinc-200 rounded-md hover:border-blue-500 hover:bg-blue-50 transition-colors text-center group">
                        <span class="material-icons-round text-blue-500 group-hover:scale-110 transition-transform">description</span>
                        <span class="text-xs font-semibold text-zinc-700">Client Files</span>
                    </a>
                    <a href="<?= $driveFolders['additional_folder']['link'] ?>" target="_blank" class="flex flex-col items-center gap-2 p-4 border border-zinc-200 rounded-md hover:border-amber-500 hover:bg-amber-50 transition-colors text-center group">
                        <span class="material-icons-round text-amber-500 group-hover:scale-110 transition-transform">add_circle</span>
                        <span class="text-xs font-semibold text-zinc-700">Additional Files</span>
                    </a>
                    <a href="<?= $driveFolders['operations_folder']['link'] ?>" target="_blank" class="flex flex-col items-center gap-2 p-4 border border-zinc-200 rounded-md hover:border-emerald-500 hover:bg-emerald-50 transition-colors text-center group">
                        <span class="material-icons-round text-emerald-500 group-hover:scale-110 transition-transform">engineering</span>
                        <span class="text-xs font-semibold text-zinc-700">Operations Files</span>
                    </a>
                </div>
                <?php else: ?>
                <div class="text-xs text-zinc-400 text-center py-8 font-medium uppercase tracking-widest">
                    Not synced with Google Drive
                </div>
                <?php endif; ?>
            </div>

            <!-- WORKFLOW NOTES (Dynamic Rendering) -->
            <?php if(!empty($t['om_notes']) || !empty($t['team_notes']) || !empty($t['qc_notes'])): ?>
            <div class="bg-white border border-zinc-200 rounded-md p-5 shadow-sm">
                <div class="f-label mb-4">Workflow Notes</div>
                <div class="space-y-4">
                    
                    <?php if (!empty($t['om_notes'])): ?>
                    <div>
                        <div class="flex items-center gap-2 mb-1.5">
                            <span class="material-icons-round text-[10px] text-blue-500">sticky_note_2</span>
                            <span class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Ops Manager Notes</span>
                        </div>
                        <div class="bg-blue-50 border border-blue-100 p-3 rounded text-xs text-zinc-700 leading-relaxed whitespace-pre-wrap"><?= htmlspecialchars($t['om_notes']) ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if(!empty($t['team_notes'])): ?>
                    <div>
                        <div class="flex items-center gap-2 mb-1.5">
                            <span class="material-icons-round text-[10px] text-amber-500">edit_note</span>
                            <span class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Ops Team Notes</span>
                        </div>
                        <div class="bg-amber-50 border border-amber-100 p-3 rounded text-xs text-zinc-700 leading-relaxed whitespace-pre-wrap"><?= htmlspecialchars($t['team_notes']) ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if(!empty($t['qc_notes'])): ?>
                    <div>
                        <div class="flex items-center gap-2 mb-1.5">
                            <span class="material-icons-round text-[10px] text-emerald-500">fact_check</span>
                            <span class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">QC Notes</span>
                        </div>
                        <div class="bg-emerald-50 border border-emerald-100 p-3 rounded text-xs text-zinc-700 leading-relaxed whitespace-pre-wrap"><?= htmlspecialchars($t['qc_notes']) ?></div>
                    </div>
                    <?php endif; ?>

                </div>
            </div>
            <?php endif; ?>

            <!-- FEEDBACK (Only if completed and has feedback) -->
            <?php if (!empty($t['feedback']) && $t['status'] == 'completed'): ?>
            <div class="bg-emerald-50 border border-emerald-200 rounded-md p-5 shadow-sm">
                <div class="flex items-center gap-2 mb-2">
                    <span class="material-icons-round text-emerald-600 text-sm">rate_review</span>
                    <div class="f-label mb-0">Client Feedback</div>
                </div>
                <div class="bg-white border border-emerald-200 p-4 rounded text-xs text-zinc-800 leading-relaxed whitespace-pre-wrap"><?= htmlspecialchars($t['feedback']) ?></div>
            </div>
            <?php endif; ?>

            <!-- Back Button -->
            <div class="flex justify-end pt-2">
                <a href="javascript:history.back()" class="bg-zinc-900 hover:bg-zinc-800 text-white px-5 py-2 rounded-md text-xs font-semibold flex items-center gap-2 transition-colors shadow-sm">
                    <span class="material-icons-round text-sm">arrow_back</span> Return
                </a>
            </div>

        </div>
    </main>
</div>

</body>
</html>