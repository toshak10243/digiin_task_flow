<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/config/database.php";
require_once "../../bootstrap/app.php";

 $db = (new Database())->connect();
 $user = $_SESSION['user_id'];
 $role = $_SESSION['role'] ?? 'bde'; // Fallback
 $userName = $_SESSION['name'] ?? $_SESSION['username'] ?? '';

// If name not in session, query from users table
if (empty($userName) && !empty($user)) {
    try {
        $uStmt = $db->prepare("SELECT name, username FROM users WHERE id = :id LIMIT 1");
        $uStmt->execute([':id' => $user]);
        $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
        if ($uRow) {
            $userName = !empty($uRow['name']) ? $uRow['name'] : ($uRow['username'] ?? 'BDE');
        }
    } catch (\Exception $e) {}
}
if (empty($userName)) {
    $userName = 'BDE User';
}

date_default_timezone_set('Asia/Kolkata');

// Ensure delivered_to_client column exists
try {
    $db->query("SELECT delivered_to_client FROM tasks LIMIT 1");
} catch (\Exception $e) {
    try {
        $db->exec("ALTER TABLE tasks ADD COLUMN delivered_to_client TINYINT(1) NOT NULL DEFAULT 0");
    } catch (\Exception $ex) {}
}

// ==========================================
// 1. ROLE BASED SQL QUERY
// ==========================================
$query = "SELECT * FROM tasks WHERE 1=1";
 $params = [];

if ($role === 'bde') {
    $query .= " AND created_by = :user";
    $params[':user'] = $user;
} elseif ($role === 'operations_manager' || $role === 'operations_team') {
    $query .= " AND assigned_to = :user";
    $params[':user'] = $user;
} elseif ($role === 'qc') {
    $query .= " AND current_stage = 'qc'";
}
// Admin gets all tasks (no WHERE clause added)

 $query .= " ORDER BY id DESC";

 $stmt = $db->prepare($query);
 $stmt->execute($params);
 $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ==========================================
// 2. STATS CALCULATION (Fast SQL Counts)
// ==========================================
 $statsQuery = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status NOT IN ('completed') THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
    SUM(CASE WHEN urgent = 'yes' THEN 1 ELSE 0 END) as urgent,
    SUM(CASE WHEN status = 'rework' THEN 1 ELSE 0 END) as rework
FROM tasks WHERE 1=1";

 $statsParams = [];
if ($role === 'bde') { $statsQuery .= " AND created_by = :user"; $statsParams[':user'] = $user; }
elseif ($role === 'operations_manager' || $role === 'operations_team') { $statsQuery .= " AND assigned_to = :user"; $statsParams[':user'] = $user; }
elseif ($role === 'qc') { $statsQuery .= " AND current_stage = 'qc'"; }

 $statsStmt = $db->prepare($statsQuery);
 $statsStmt->execute($statsParams);
 $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// ==========================================
// 2a. DRIVE SUB-FOLDER LINKS
// Subfolder links are read instantly from the database/helpers without
// blocking remote Google Drive API calls on every page load.
// ==========================================

// ==========================================
// 2b. CLEAN EXPORT DATA (for Excel export only)
// This does NOT affect the table rendering below in any way.
// ==========================================
 $exportRows = [];
foreach ($tasks as $t) {
    $isDriveSyncedRow = !empty($t['drive_folder_link']);
    $isFinalDriveLinkRow = !empty($t['team_file']) && strpos($t['team_file'], 'http') === 0;

    // Google Drive link
    $driveLinkExport = $isDriveSyncedRow ? $t['drive_folder_link'] : '';

    // Final file link/path
    if (!empty($t['team_file'])) {
        $finalFileExport = $isFinalDriveLinkRow ? $t['team_file'] : $t['team_file'];
    } else {
        $finalFileExport = '';
    }

    // Additional files link/path
    if (!empty($t['additional_files'])) {
        $additionalFilesExport = $t['additional_files'];
    } else {
        $additionalFilesExport = '';
    }

    // Deadline formatted
    $deadlineExport = !empty($t['deadline']) ? date("d M Y, H:i", strtotime($t['deadline'])) : '';

    $createdExport = !empty($t['task_created_at'] ?? $t['created_at']) ? date("d M Y", strtotime($t['task_created_at'] ?? $t['created_at'])) : '';

    $exportRows[] = [
        "_id"               => (int)$t['id'],
        "Task Key"          => (string)$t['task_key'],
        "Task Name"         => (string)$t['task_name'],
        "Client Name"       => $t['client_name'] ? (string)$t['client_name'] : 'N/A',
        "Subject"           => $t['subject'] ? (string)$t['subject'] : 'N/A',
        "Word Count"        => (int)$t['word_count'],
        "Created Date"      => $createdExport,
        "Deadline"          => $deadlineExport,
        "Status"            => ucwords(str_replace('_', ' ', (string)$t['status'])),
        "Stage"             => ucwords(str_replace('_', ' ', (string)$t['current_stage'])),
        "Priority"          => ($t['urgent'] === 'yes') ? 'Urgent' : 'Normal',
        "Google Drive Link" => $driveLinkExport,
        "Additional Files"  => $additionalFilesExport,
        "Final File"        => $finalFileExport,
        "Feedback"          => $t['feedback'] ? (string)$t['feedback'] : '',
    ];
}
$exportFilename = "Digiink_Task_Report_" . date("Y-m-d") . ".xlsx";

// Base64 Logo for PDF Generation
$logoBase64 = '';
$logoPath = __DIR__ . '/../../assets/images/logo.png';
if (file_exists($logoPath)) {
    $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Task Inventory | Digiink Workflow</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
    * { font-family: 'Inter', sans-serif; }
    body { background-color: #f4f4f5; }
    
    /* Enterprise Data Table */
    .data-table { border-collapse: collapse; width: 100%; min-width: 1200px; }
    .data-table th, .data-table td { 
        border-bottom: 1px solid #e4e4e7; 
        border-right: 1px solid #f4f4f5;
        padding: 0.6rem 0.75rem; 
        font-size: 0.75rem; 
        text-align: left;
    }
    .data-table th:last-child, .data-table td:last-child { border-right: none; }
    .data-table thead { background-color: #fafafa; position: sticky; top: 0; z-index: 10; }
    .data-table thead th { 
        font-weight: 600; 
        color: #71717a; 
        text-transform: uppercase; 
        letter-spacing: 0.05em; 
        font-size: 0.625rem; 
        border-bottom: 1px solid #e4e4e7;
    }
    .data-table tbody tr { transition: background-color 0.1s ease; }
    .data-table tbody tr:hover { background-color: #fafafa; }
    
    /* Premium Chips */
    .chip { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.15rem 0.5rem; border-radius: 0.25rem; font-size: 0.625rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.025em; }
    .chip-assigned { background: #fef3c7; color: #92400e; border-left: 3px solid #f59e0b; }
    .chip-in_progress { background: #dbeafe; color: #1e40af; border-left: 3px solid #3b82f6; }
    .chip-completed { background: #d1fae5; color: #065f46; border-left: 3px solid #10b981; }
    .chip-rework { background: #ffe4e6; color: #9f1239; border-left: 3px solid #f43f5e; }
    .chip-new { background: #f4f4f5; color: #52525b; border-left: 3px solid #a1a1aa; }

    /* Delivered Row Highlight */
    .data-table tbody tr.row-delivered {
        background-color: #f0fdf4 !important;
        border-left: 4px solid #10b981 !important;
    }
    .data-table tbody tr.row-delivered td {
        border-top: 1px solid #bbf7d0;
        border-bottom: 1px solid #bbf7d0;
    }
    .data-table tbody tr.row-delivered:hover {
        background-color: #dcfce7 !important;
    }

    /* Dropdown */
    .dropdown { position: relative; }
    /* FIX: dropdown-menu is now position:fixed and positioned dynamically via JS
       so it can never be clipped by the table's overflow-x/overflow-y containers,
       and always renders above every element via a very high z-index. */
    .dropdown-menu {
        display: none;
        position: fixed;
        background: white;
        border: 1px solid #e4e4e7;
        border-radius: 0.375rem;
        box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
        z-index: 99999;
        min-width: 160px;
        overflow: hidden;
        opacity: 0;
        transform: translateY(-4px);
        transition: opacity 0.12s ease, transform 0.12s ease;
    }
    .dropdown-menu.dropdown-menu-visible {
        display: block;
        opacity: 1;
        transform: translateY(0);
    }
    .dropdown-item { display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 0.75rem; font-size: 0.75rem; color: #3f3f46; transition: 0.15s; }
    .dropdown-item:hover { background: #f4f4f5; color: #09090b; }

    /* Modern, thicker, easier-to-grab horizontal scrollbar (visual/behavior only) */
    .clean-scroll { scroll-behavior: smooth; }
    .clean-scroll::-webkit-scrollbar { height: 12px; width: 12px; }
    .clean-scroll::-webkit-scrollbar-track { background: #f4f4f5; border-radius: 10px; }
    .clean-scroll::-webkit-scrollbar-thumb { background: #c4c4c9; border-radius: 10px; border: 3px solid #f4f4f5; background-clip: padding-box; }
    .clean-scroll::-webkit-scrollbar-thumb:hover { background: #a1a1aa; background-clip: padding-box; }
    .clean-scroll::-webkit-scrollbar-thumb:active { background: #71717a; background-clip: padding-box; }
    .clean-scroll { scrollbar-width: auto; scrollbar-color: #c4c4c9 #f4f4f5; }
    
    .action-btn { width: 1.75rem; height: 1.75rem; display: inline-flex; align-items: center; justify-content: center; border-radius: 0.25rem; color: #a1a1aa; transition: 0.15s; }
    .action-btn:hover { background: #18181b; color: white; }
    .action-btn.danger:hover { background: #dc2626; }
</style>
</head>

<body class="text-zinc-900 antialiased">

<div class="flex h-screen overflow-hidden">
    <?php include "partials/sidebar.php"; ?>

    <main class="flex-1 overflow-y-auto">
        <div class="p-5 lg:p-6 space-y-4">
            
            <!-- Header -->
            <div class="flex items-start justify-between">
                <div>
                    <h1 class="text-xl font-semibold tracking-tight text-zinc-900">Task Inventory</h1>
                    <p class="text-xs text-zinc-400 mt-0.5">Monitor workflow statuses and manage project files.</p>
                </div>
                <div class="text-xs text-zinc-400 font-mono bg-white px-2.5 py-1 border border-zinc-200 rounded shadow-sm"><?= date("d M Y, h:i A"); ?></div>
            </div>

            <!-- Stats Row -->
            <div class="grid grid-cols-5 gap-3">
                <div class="bg-white border border-zinc-200 rounded-md p-3 shadow-sm">
                    <div class="text-[10px] font-semibold text-zinc-400 uppercase">Total Tasks</div>
                    <div class="text-xl font-bold text-zinc-900 mt-1"><?= $stats['total'] ?></div>
                </div>
                <div class="bg-white border border-zinc-200 rounded-md p-3 shadow-sm">
                    <div class="text-[10px] font-semibold text-zinc-400 uppercase">Active</div>
                    <div class="text-xl font-bold text-blue-600 mt-1"><?= $stats['active'] ?></div>
                </div>
                <div class="bg-white border border-zinc-200 rounded-md p-3 shadow-sm">
                    <div class="text-[10px] font-semibold text-zinc-400 uppercase">Completed</div>
                    <div class="text-xl font-bold text-emerald-600 mt-1"><?= $stats['completed'] ?></div>
                </div>
                <div class="bg-white border border-zinc-200 rounded-md p-3 shadow-sm">
                    <div class="text-[10px] font-semibold text-zinc-400 uppercase">Urgent</div>
                    <div class="text-xl font-bold text-rose-600 mt-1"><?= $stats['urgent'] ?></div>
                </div>
                <div class="bg-white border border-zinc-200 rounded-md p-3 shadow-sm">
                    <div class="text-[10px] font-semibold text-zinc-400 uppercase">Rework</div>
                    <div class="text-xl font-bold text-orange-600 mt-1"><?= $stats['rework'] ?></div>
                </div>
            </div>

            <!-- Advanced Filters -->
            <div class="bg-white border border-zinc-200 rounded-md p-3 shadow-sm flex flex-wrap gap-2 items-center">
                <div class="relative flex-1 min-w-[200px]">
                    <span class="material-icons-round absolute left-2 top-1/2 -translate-y-1/2 text-zinc-400 text-sm">search</span>
                    <input type="text" id="searchInput" placeholder="Search anything..." class="w-full pl-8 pr-3 py-1.5 bg-zinc-50 border border-zinc-200 rounded text-xs outline-none focus:border-zinc-400">
                </div>

                <select id="filterDate" class="bg-zinc-50 border border-zinc-200 rounded px-2 py-1.5 text-xs text-zinc-600 outline-none cursor-pointer">
                    <option value="">📅 Date Shortcuts</option>
                    <option value="today">Today</option>
                    <option value="week">This Week</option>
                    <option value="overdue">Overdue</option>
                </select>

                <div class="flex items-center gap-1 bg-zinc-50 border border-zinc-200 rounded px-2 py-1 text-xs text-zinc-600">
                    <span class="text-[10px] font-semibold text-zinc-400 uppercase">Created:</span>
                    <input type="date" id="filterFromDate" class="bg-transparent border-none text-xs text-zinc-700 outline-none p-0 cursor-pointer" title="From Created Date">
                    <span class="text-zinc-400">to</span>
                    <input type="date" id="filterToDate" class="bg-transparent border-none text-xs text-zinc-700 outline-none p-0 cursor-pointer" title="To Created Date">
                </div>

                <select id="filterStatus" class="bg-zinc-50 border border-zinc-200 rounded px-2 py-1.5 text-xs text-zinc-600 outline-none cursor-pointer">
                    <option value="">Status</option>
                    <option value="new">New</option>
                    <option value="assigned">Assigned</option>
                    <option value="in_progress">Working</option>
                    <option value="completed">Completed</option>
                    <option value="rework">Rework</option>
                </select>

                <select id="filterStage" class="bg-zinc-50 border border-zinc-200 rounded px-2 py-1.5 text-xs text-zinc-600 outline-none cursor-pointer">
                    <option value="">Stage</option>
                    <option value="operations_manager">Ops Manager</option>
                    <option value="operations_team">Ops Team</option>
                    <option value="qc">QC</option>
                </select>

                <select id="filterPriority" class="bg-zinc-50 border border-zinc-200 rounded px-2 py-1.5 text-xs text-zinc-600 outline-none cursor-pointer">
                    <option value="">Priority</option>
                    <option value="yes">Urgent</option>
                    <option value="no">Normal</option>
                </select>

                <select id="filterDelivered" class="bg-zinc-50 border border-zinc-200 rounded px-2 py-1.5 text-xs text-zinc-600 outline-none cursor-pointer">
                    <option value="">Delivered Status</option>
                    <option value="1">Delivered to Client</option>
                    <option value="0">Pending Delivery</option>
                </select>

                <button onclick="resetFilters()" class="text-xs text-zinc-500 hover:text-zinc-900 font-medium px-2 py-1.5">Reset</button>

                <div class="ml-auto flex items-center gap-2">
                    <button onclick="exportExcel()" 
                            class="flex items-center gap-1.5 bg-emerald-700 hover:bg-emerald-800 text-white px-3 py-1.5 rounded text-xs font-semibold transition-colors shadow-xs"
                            title="Export filtered/selected tasks to Excel">
                        <span class="material-icons-round text-sm">table_view</span> Excel
                    </button>
                    <button onclick="exportPDF()" 
                            class="flex items-center gap-1.5 bg-rose-700 hover:bg-rose-800 text-white px-3 py-1.5 rounded text-xs font-semibold transition-colors shadow-xs"
                            title="Export filtered/selected tasks to PDF with date range">
                        <span class="material-icons-round text-sm">picture_as_pdf</span> PDF
                    </button>
                </div>
            </div>

            <!-- Data Grid -->
            <div class="bg-white border border-zinc-200 rounded-md shadow-sm overflow-hidden">
                <div id="tableScrollWrap" class="overflow-x-auto clean-scroll" style="max-height: calc(100vh - 230px); min-height: 480px;">
                    <table id="taskTable" class="data-table">
                        <thead>
                            <tr>
                                <th class="text-center" style="width: 48px;" title="Delivered to Client">Delivered</th>
                                <th>Key</th>
                                <th>Task / Client</th>
                                <th>Subject</th>
                                <th class="text-center">Words</th>
                                <th>Deadline</th>
                                <th class="text-center">Status</th>
                                <th class="text-center">Google Drive</th>
                                <th class="text-center">Files</th>
                                <th class="text-center">Extra</th>
                                <th class="text-center">Feedback</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                            <?php if(count($tasks) > 0): ?>
                                <?php foreach($tasks as $t): 
                                    // Determine Link Logic
                                    $isDriveSynced = !empty($t['drive_folder_link']);
                                    $isFinalDriveLink = !empty($t['team_file']) && strpos($t['team_file'], 'http') === 0;
                                    $isDelivered = !empty($t['delivered_to_client']) && (int)$t['delivered_to_client'] === 1;
                                    $createdDateOnly = date('Y-m-d', strtotime($t['task_created_at'] ?? $t['created_at']));
                                ?>
                                <tr class="<?= $isDelivered ? 'row-delivered' : '' ?>"
                                    data-task-id="<?= $t['id'] ?>"
                                    data-delivered="<?= $isDelivered ? '1' : '0' ?>"
                                    data-status="<?= $t['status'] ?>" 
                                    data-stage="<?= $t['current_stage'] ?>" 
                                    data-priority="<?= $t['urgent'] ?>" 
                                    data-deadline="<?= $t['deadline'] ?>"
                                    data-created="<?= $createdDateOnly ?>">
                                    
                                    <!-- Mark as Delivered to Client Checkbox -->
                                    <td class="text-center align-middle" style="width: 48px;">
                                        <label class="inline-flex flex-col items-center justify-center cursor-pointer select-none group" title="<?= $isDelivered ? 'Delivered to Client (Click to uncheck)' : 'Mark as Delivered to Client' ?>">
                                            <input type="checkbox"
                                                   class="delivered-checkbox w-4 h-4 rounded border-zinc-300 text-emerald-600 focus:ring-emerald-500 cursor-pointer transition-transform group-hover:scale-110"
                                                   data-task-id="<?= $t['id'] ?>"
                                                   <?= $isDelivered ? 'checked' : '' ?>
                                                   onchange="toggleDelivered(this)">
                                            <span class="text-[9px] font-semibold mt-0.5 delivered-status-text <?= $isDelivered ? 'text-emerald-700 font-bold' : 'text-zinc-400' ?>">
                                                <?= $isDelivered ? 'Delivered' : 'Pending' ?>
                                            </span>
                                        </label>
                                    </td>

                                    <td class="font-mono font-semibold text-zinc-800"><?= $t['task_key'] ?></td>
                                    
                                    <td>
                                        <div class="font-medium text-zinc-900 truncate max-w-[180px]"><?= htmlspecialchars($t['task_name']) ?></div>
                                        <div class="text-[10px] text-zinc-400 flex items-center gap-1 mt-0.5">
                                            <span class="material-icons-round" style="font-size: 10px;">person</span>
                                            <?= htmlspecialchars($t['client_name'] ?: 'N/A') ?>
                                        </div>
                                    </td>

                                    <td>
                                        <span class="font-medium text-zinc-700 bg-zinc-100 px-2 py-0.5 rounded text-[11px] inline-block truncate max-w-[140px]" title="<?= htmlspecialchars($t['subject'] ?? '') ?>">
                                            <?= htmlspecialchars(!empty($t['subject']) ? $t['subject'] : 'N/A') ?>
                                        </span>
                                    </td>

                                    <td class="text-center text-zinc-500 font-mono"><?= number_format($t['word_count']) ?></td>

                                    <td class="text-rose-600 font-medium text-[11px] whitespace-nowrap">
                                        <?= date("d M, H:i", strtotime($t['deadline'])) ?>
                                    </td>

                                    <td class="text-center">
                                        <span class="chip chip-<?= $t['status'] ?>">
                                            <?= str_replace('_', ' ', $t['status']) ?>
                                        </span>
                                        <div class="text-[8px] text-zinc-400 font-mono uppercase mt-0.5 tracking-wider"><?= str_replace('_',' ',$t['current_stage']) ?></div>
                                    </td>

                                    <!-- Google Drive Column -->
                                    <td class="text-center">
                                        <?php if($isDriveSynced): ?>
                                        <div class="dropdown inline-block">
                                            <button type="button" class="flex items-center gap-1 text-[10px] font-semibold text-zinc-800 bg-zinc-100 hover:bg-zinc-200 px-2 py-1 rounded transition-colors">
                                                <span class="material-icons-round text-sm text-blue-500">folder_open</span> Drive
                                            </button>
                                            <?php
                                                $clientFolderLink     = getDriveClientLink($t) ?? "https://drive.google.com/drive/folders/{$t['drive_folder_id']}";
                                                $additionalFolderLink = getDriveAdditionalLink($t) ?? "https://drive.google.com/drive/folders/{$t['drive_folder_id']}";
                                                $operationsFolderLink = getDriveOperationsLink($t) ?? "https://drive.google.com/drive/folders/{$t['drive_folder_id']}";
                                            ?>
                                            <div class="dropdown-menu text-left">
                                                <a href="<?= $t['drive_folder_link'] ?>" target="_blank" class="dropdown-item">
                                                    <span class="material-icons-round text-sm text-zinc-400">folder</span> Main Folder
                                                </a>
                                                <a href="<?= $clientFolderLink ?>" target="_blank" class="dropdown-item">
                                                    <span class="material-icons-round text-sm text-blue-400">description</span> Client Files
                                                </a>
                                                <a href="<?= $additionalFolderLink ?>" target="_blank" class="dropdown-item">
                                                    <span class="material-icons-round text-sm text-amber-400">add_circle</span> Additional
                                                </a>
                                                <a href="<?= $operationsFolderLink ?>" target="_blank" class="dropdown-item">
                                                    <span class="material-icons-round text-sm text-emerald-400">engineering</span> Operations
                                                </a>
                                            </div>
                                        </div>
                                        <?php else: ?>
                                            <span class="text-[9px] font-semibold text-zinc-300 bg-zinc-50 px-2 py-0.5 rounded">Not Synced</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Initial / Final Files -->
                                    <td class="text-center">
                                        <div class="flex items-center justify-center gap-1">
                                            <?php if($isDriveSynced): ?>
                                                <!-- New System: Drive Link -->
                                                <a href="<?= $t['drive_folder_link'] ?>" target="_blank" class="action-btn" title="Open in Drive">
                                                    <span class="material-icons-round text-sm">link</span>
                                                </a>
                                            <?php elseif(!empty($t['file_path'])): ?>
                                                <!-- Old System: Local -->
                                                <?php $ext = strtolower(pathinfo($t['file_path'], PATHINFO_EXTENSION)); ?>
                                                <?php if($ext === 'zip'): ?>
                                                    <a href="../common/zip_viewer.php?file=../../<?= urlencode($t['file_path']) ?>" class="action-btn" title="View ZIP"><span class="material-icons-round text-sm">folder_zip</span></a>
                                                <?php else: ?>
                                                    <a href="../../<?= $t['file_path'] ?>" target="_blank" class="action-btn" title="View File"><span class="material-icons-round text-sm">visibility</span></a>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-zinc-200 text-xs">—</span>
                                            <?php endif; ?>

                                            <?php 
                                            $opsDriveLink = getDriveOperationsLink($t);
                                            ?>
                                            <?php if(!empty($opsDriveLink)): ?>
                                                <a href="<?= htmlspecialchars($opsDriveLink) ?>" target="_blank" class="action-btn text-emerald-500 hover:!bg-emerald-600" title="Final Work (Operations Drive)">
                                                    <span class="material-icons-round text-sm">cloud_download</span>
                                                </a>
                                            <?php elseif(!empty($t['team_file'])): ?>
                                                <?php if($isFinalDriveLink): ?>
                                                    <a href="<?= $t['team_file'] ?>" target="_blank" class="action-btn text-emerald-500 hover:!bg-emerald-600" title="Final File (Drive)">
                                                        <span class="material-icons-round text-sm">file_download</span>
                                                    </a>
                                                <?php else: ?>
                                                    <a href="../common/zip_viewer.php?file=../../<?= urlencode($t['team_file']) ?>" class="action-btn text-emerald-500 hover:!bg-emerald-600" title="Final File">
                                                        <span class="material-icons-round text-sm">file_download</span>
                                                    </a>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <!-- Additional Files -->
                                    <td class="text-center">
                                        <?php if($t['status'] !== 'completed' && in_array($role, ['bde', 'admin'])): ?>
                                            <a href="additional_files.php?task_id=<?= $t['id'] ?>" class="action-btn text-amber-500 hover:!bg-amber-500 relative" title="<?= !empty($t['additional_instructions']) ? 'Update Additional Info / Files' : 'Upload Extra Info / Files' ?>">
                                                <span class="material-icons-round text-sm"><?= !empty($t['additional_instructions']) ? 'edit_note' : 'add_circle' ?></span>
                                                <?php if(!empty($t['additional_instructions'])): ?>
                                                    <span class="w-2 h-2 rounded-full bg-amber-500 absolute -top-0.5 -right-0.5 ring-2 ring-white"></span>
                                                <?php endif; ?>
                                            </a>
                                        <?php elseif(!empty($t['additional_files'])): ?>
                                            <?php if(strpos($t['additional_files'], 'http') === 0): ?>
                                                <a href="<?= $t['additional_files'] ?>" target="_blank" class="action-btn text-zinc-500" title="View Extra"><span class="material-icons-round text-sm">folder_zip</span></a>
                                            <?php else: ?>
                                                <a href="../../<?= $t['additional_files'] ?>" target="_blank" class="action-btn text-zinc-500" title="View Extra"><span class="material-icons-round text-sm">folder_zip</span></a>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-zinc-200 text-xs">—</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Feedback -->
                                    <td class="text-center">
                                        <?php if($t['status']=='completed'): ?>
                                            <?php 
                                            $hasFb = !empty($t['feedback']) || !empty($t['feedback_files']);
                                            $fbFilesJson = !empty($t['feedback_files']) ? $t['feedback_files'] : '[]';
                                            ?>
                                            <?php if(!$hasFb): ?>
                                                <button onclick="openFeedbackModal(<?= $t['id'] ?>)" class="action-btn text-blue-500 hover:!bg-blue-600" title="Add Feedback & Screenshots">
                                                    <span class="material-icons-round text-sm">rate_review</span>
                                                </button>
                                            <?php else: ?>
                                                <button onclick='viewFeedbackModal(<?= $t['id'] ?>, <?= json_encode($t['feedback'] ?? "") ?>, <?= htmlspecialchars($fbFilesJson, ENT_QUOTES) ?>)' class="action-btn text-emerald-500" title="View Feedback & Files">
                                                    <span class="material-icons-round text-sm">rate_review</span>
                                                </button>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-zinc-200 text-xs">—</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Actions -->
                                    <td class="text-center">
                                        <div class="flex items-center justify-center gap-1">
                                            <?php 
                                            $canBdeModify = ($t['current_stage'] === 'operations_manager' && empty($t['assigned_to']) && $t['status'] === 'new' && in_array($role, ['bde', 'admin']));
                                            ?>
                                            <?php if($canBdeModify): ?>
                                                <a href="edit_task.php?id=<?= $t['id'] ?>" class="action-btn" title="Edit"><span class="material-icons-round text-sm">edit</span></a>
                                                <button onclick="confirmDelete(<?= $t['id'] ?>)" class="action-btn danger" title="Delete"><span class="material-icons-round text-sm">delete</span></button>
                                            <?php else: ?>
                                                <span class="action-btn !text-zinc-300 !cursor-not-allowed" title="<?= !empty($t['assigned_to']) ? 'Task assigned by Operations Manager - Locked' : 'Stage Locked' ?>"><span class="material-icons-round text-sm">lock</span></span>
                                            <?php endif; ?>
                                            <a href="preview_task.php?id=<?= $t['id'] ?>" class="action-btn" title="Preview Details"><span class="material-icons-round text-sm">visibility</span></a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="12" class="text-center py-20 text-zinc-300">
                                        <span class="material-icons-round text-5xl block mb-3">inventory_2</span>
                                        <span class="text-xs font-semibold uppercase tracking-widest">No tasks found</span>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>
</div>

<!-- Enhanced Feedback Modal (Text + Screenshots + Files + Paste Support) -->
<div id="feedbackModal" class="fixed inset-0 bg-black/60 hidden items-center justify-center z-[100] p-4 backdrop-blur-xs">
    <div class="bg-white rounded-2xl w-full max-w-lg p-6 shadow-2xl border border-zinc-200 overflow-hidden max-h-[90vh] flex flex-col">
        <div class="flex items-center justify-between pb-3 border-b border-zinc-100 mb-4">
            <div class="flex items-center gap-2.5">
                <span class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center shadow-xs">
                    <span class="material-icons-round text-base">rate_review</span>
                </span>
                <div>
                    <h2 class="text-sm font-bold text-zinc-800" id="modalTitle">Task Feedback</h2>
                    <p class="text-[11px] text-zinc-400" id="modalSubtitle">Send feedback and attachments to Operations</p>
                </div>
            </div>
            <button type="button" onclick="closeModal()" class="text-zinc-400 hover:text-zinc-700 p-1.5 rounded-lg hover:bg-zinc-100 transition">
                <span class="material-icons-round text-base">close</span>
            </button>
        </div>

        <form method="POST" action="submit_feedback.php" enctype="multipart/form-data" id="feedbackForm" class="flex-1 overflow-y-auto space-y-4 pr-1">
            <input type="hidden" name="task_id" id="modalTaskId">

            <div>
                <label class="block text-[11px] font-bold text-zinc-500 uppercase tracking-wider mb-1">Feedback Notes</label>
                <textarea name="feedback" id="feedbackInput" 
                          class="w-full border border-zinc-200 p-3 rounded-xl text-xs h-28 resize-none outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 font-medium leading-relaxed" 
                          placeholder="Write client feedback or revision notes... (Tip: You can press Ctrl+V to paste a screenshot here directly!)"></textarea>
            </div>

            <!-- Existing Files Container (shown in View mode or if files exist) -->
            <div id="existingFilesSection" class="hidden">
                <label class="block text-[11px] font-bold text-zinc-500 uppercase tracking-wider mb-1.5 flex items-center gap-1">
                    <span class="material-icons-round text-xs text-blue-500">attachment</span> Attached Files & Screenshots
                </label>
                <div id="existingFilesList" class="grid grid-cols-2 gap-2 max-h-48 overflow-y-auto p-1"></div>
            </div>

            <!-- Upload Zone (shown in Add / Edit mode) -->
            <div id="uploadZoneSection" class="space-y-2">
                <label class="block text-[11px] font-bold text-zinc-500 uppercase tracking-wider">
                    Add Screenshots / Files <span class="text-zinc-400 font-normal normal-case">(Images, PDF, DOCX, ZIP)</span>
                </label>
                
                <div id="dropArea" 
                     onclick="document.getElementById('feedbackFileInput').click()"
                     class="border-2 border-dashed border-zinc-200 hover:border-blue-400 bg-zinc-50/50 hover:bg-blue-50/30 rounded-xl p-4 text-center cursor-pointer transition group">
                    <input type="file" name="feedback_files[]" id="feedbackFileInput" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.zip,.txt,.rar" class="hidden" onchange="handleFeedbackFileSelect(this)">
                    <div class="flex flex-col items-center gap-1">
                        <span class="material-icons-round text-2xl text-zinc-400 group-hover:text-blue-500 transition">cloud_upload</span>
                        <p class="text-xs font-bold text-zinc-700 group-hover:text-blue-600">Click to Browse or Drag & Drop Files</p>
                        <p class="text-[10px] text-zinc-400">💡 Or press <kbd class="px-1.5 py-0.5 bg-zinc-200 rounded font-mono text-[9px] text-zinc-700">Ctrl + V</kbd> to paste screenshot directly</p>
                    </div>
                </div>

                <!-- Selected Files Staging Preview List -->
                <div id="stagedFilesContainer" class="space-y-1.5 max-h-36 overflow-y-auto"></div>
            </div>

            <div class="flex items-center justify-between pt-3 border-t border-zinc-100">
                <button type="button" onclick="closeModal()" class="px-4 py-2 text-xs font-bold bg-zinc-100 text-zinc-700 rounded-xl hover:bg-zinc-200 transition">Close</button>
                
                <div class="flex items-center gap-2">
                    <button type="button" id="editFeedbackBtn" onclick="switchToEditMode()" class="hidden px-4 py-2 text-xs font-bold bg-blue-50 text-blue-600 border border-blue-200 rounded-xl hover:bg-blue-100 transition flex items-center gap-1">
                        <span class="material-icons-round text-sm">edit</span> Edit / Add More Files
                    </button>
                    <button type="submit" id="saveBtn" class="px-5 py-2 text-xs font-bold bg-zinc-900 text-white rounded-xl hover:bg-zinc-800 transition shadow-sm flex items-center gap-1">
                        <span class="material-icons-round text-sm">save</span> Submit Feedback
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Image Lightbox Preview Modal -->
<div id="imageLightboxModal" class="fixed inset-0 bg-black/80 hidden items-center justify-center z-[200] p-4 backdrop-blur-sm" onclick="closeImageLightbox()">
    <div class="relative max-w-4xl max-h-[90vh] flex flex-col items-center" onclick="event.stopPropagation()">
        <button type="button" onclick="closeImageLightbox()" class="absolute -top-10 right-0 text-white text-2xl hover:text-zinc-300 transition">✕</button>
        <img id="lightboxImg" src="" alt="Screenshot" class="max-w-full max-h-[82vh] rounded-xl object-contain shadow-2xl border border-white/20">
        <p id="lightboxCaption" class="text-xs text-white/80 mt-2 text-center font-medium truncate max-w-md"></p>
    </div>
</div>

<script>
/* ==========================================
   CLEAN EXPORT DATA (generated server-side from the same $tasks used to
   render the table). Used ONLY by exportExcel() below.
   ========================================== */
const exportData = <?= json_encode($exportRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const exportFilename = <?= json_encode($exportFilename) ?>;
const appLogoBase64 = <?= json_encode($logoBase64) ?>;
const currentUserName = <?= json_encode($userName) ?>;

/* ==========================================
   ENTERPRISE JAVASCRIPT FILTERS (NO RELOAD)
   ========================================== */
const searchInput = document.getElementById('searchInput');
const filterStatus = document.getElementById('filterStatus');
const filterStage = document.getElementById('filterStage');
const filterPriority = document.getElementById('filterPriority');
const filterDelivered = document.getElementById('filterDelivered');
const filterDate = document.getElementById('filterDate');
const filterFromDate = document.getElementById('filterFromDate');
const filterToDate = document.getElementById('filterToDate');

function applyFilters() {
    const search = searchInput.value.toLowerCase();
    const status = filterStatus.value;
    const stage = filterStage.value;
    const priority = filterPriority.value;
    const deliveredFilter = filterDelivered ? filterDelivered.value : '';
    const dateFilter = filterDate.value;
    const fromDate = filterFromDate ? filterFromDate.value : '';
    const toDate = filterToDate ? filterToDate.value : '';
    
    const rows = document.querySelectorAll("#tableBody tr[data-status]");
    const today = new Date().toISOString().split('T')[0];

    rows.forEach(row => {
        let show = true;
        const text = row.innerText.toLowerCase();
        
        // Text Search
        if (search && !text.includes(search)) show = false;
        
        // Status Filter
        if (status && row.dataset.status !== status) show = false;
        
        // Stage Filter
        if (stage && row.dataset.stage !== stage) show = false;
        
        // Priority Filter
        if (priority && row.dataset.priority !== priority) show = false;

        // Delivered Filter
        if (deliveredFilter !== '' && row.dataset.delivered !== deliveredFilter) show = false;

        // Created Date Range Filter (e.g. 7 Oct - 15 Oct)
        if (fromDate || toDate) {
            const created = row.dataset.created;
            if (fromDate && created < fromDate) show = false;
            if (toDate && created > toDate) show = false;
        }

        // Date Filter (Deadline Shortcuts)
        if (dateFilter) {
            const deadline = row.dataset.deadline;
            if (dateFilter === 'today' && deadline !== today) show = false;
            if (dateFilter === 'overdue' && deadline >= today) show = false;
            if (dateFilter === 'week') {
                const weekAgo = new Date();
                weekAgo.setDate(weekAgo.getDate() - 7);
                if (deadline < weekAgo.toISOString().split('T')[0]) show = false;
            }
        }

        row.style.display = show ? "" : "none";
    });
}

[searchInput, filterStatus, filterStage, filterPriority, filterDelivered, filterDate, filterFromDate, filterToDate].forEach(el => {
    if(!el) return;
    el.addEventListener('change', applyFilters);
    if(el.type === 'text') el.addEventListener('keyup', applyFilters);
});

function resetFilters() {
    searchInput.value = '';
    filterStatus.value = '';
    filterStage.value = '';
    filterPriority.value = '';
    if (filterDelivered) filterDelivered.value = '';
    filterDate.value = '';
    if (filterFromDate) filterFromDate.value = '';
    if (filterToDate) filterToDate.value = '';
    applyFilters();
}

/* ==========================================
   MARK AS DELIVERED TO CLIENT (INSTANT AJAX + BORDER TOGGLE)
   ========================================== */
function toggleDelivered(checkbox) {
    const row = checkbox.closest('tr');
    const taskId = checkbox.dataset.taskId;
    const isChecked = checkbox.checked ? 1 : 0;
    const labelSpan = row.querySelector('.delivered-status-text');

    // Optimistic UI updates
    if (isChecked) {
        row.classList.add('row-delivered');
        row.dataset.delivered = '1';
        if (labelSpan) {
            labelSpan.textContent = 'Delivered';
            labelSpan.className = 'text-[9px] font-semibold mt-0.5 delivered-status-text text-emerald-700 font-bold';
        }
    } else {
        row.classList.remove('row-delivered');
        row.dataset.delivered = '0';
        if (labelSpan) {
            labelSpan.textContent = 'Pending';
            labelSpan.className = 'text-[9px] font-semibold mt-0.5 delivered-status-text text-zinc-400';
        }
    }

    checkbox.disabled = true;

    const formData = new FormData();
    formData.append('task_id', taskId);
    formData.append('delivered', isChecked);

    fetch('toggle_delivered.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        checkbox.disabled = false;
        if (!data.success) {
            revertCheckboxState();
            Swal.fire({
                icon: 'error',
                title: 'Update Failed',
                text: data.error || 'Could not update delivery status.',
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000
            });
        } else {
            // Success Toast
            const Toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 2000,
                timerProgressBar: false
            });
            Toast.fire({
                icon: isChecked ? 'success' : 'info',
                title: isChecked ? 'Marked as Delivered to Client' : 'Marked as Pending Delivery'
            });
        }
    })
    .catch(err => {
        checkbox.disabled = false;
        revertCheckboxState();
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Network error while updating delivery status.',
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000
        });
    });

    function revertCheckboxState() {
        checkbox.checked = !isChecked;
        if (!isChecked) {
            row.classList.add('row-delivered');
            row.dataset.delivered = '1';
            if (labelSpan) {
                labelSpan.textContent = 'Delivered';
                labelSpan.className = 'text-[9px] font-semibold mt-0.5 delivered-status-text text-emerald-700 font-bold';
            }
        } else {
            row.classList.remove('row-delivered');
            row.dataset.delivered = '0';
            if (labelSpan) {
                labelSpan.textContent = 'Pending';
                labelSpan.className = 'text-[9px] font-semibold mt-0.5 delivered-status-text text-zinc-400';
            }
        }
    }
}

/* ==========================================
   GOOGLE DRIVE DROPDOWN — VISIBILITY FIX ONLY
   The menu itself is unchanged (same items, same design). We only
   control WHERE it renders (via position:fixed + JS coordinates) so it
   can never be clipped by the table's overflow-x/overflow-y containers,
   and always sits above every element via a very high z-index.
   ========================================== */
(function setupDropdowns(){
    const scrollWrap = document.getElementById('tableScrollWrap');
    const dropdowns = document.querySelectorAll('.dropdown');
    let hideTimer = null;
    let activeMenu = null;

    function positionMenu(dd, menu){
        const btn = dd.querySelector('button');
        const rect = btn.getBoundingClientRect();

        // Make it measurable first (kept invisible) to get real dimensions
        menu.style.left = '0px';
        menu.style.top = '0px';
        menu.style.display = 'block';
        const menuWidth = menu.offsetWidth || 160;
        const menuHeight = menu.offsetHeight || 0;

        let left = rect.right - menuWidth;
        if (left < 4) left = 4;
        if (left + menuWidth > window.innerWidth - 4) left = window.innerWidth - menuWidth - 4;

        let top = rect.bottom + 6;
        if (top + menuHeight > window.innerHeight - 4) {
            // not enough space below, flip above the button
            top = rect.top - menuHeight - 6;
        }

        menu.style.left = left + 'px';
        menu.style.top = top + 'px';
    }

    function showMenu(dd){
        clearTimeout(hideTimer);
        // hide any other open menu first
        if (activeMenu && activeMenu !== dd.querySelector('.dropdown-menu')) {
            activeMenu.classList.remove('dropdown-menu-visible');
            activeMenu.style.display = 'none';
        }
        const menu = dd.querySelector('.dropdown-menu');
        if (!menu) return;
        positionMenu(dd, menu);
        requestAnimationFrame(() => menu.classList.add('dropdown-menu-visible'));
        activeMenu = menu;
    }

    function scheduleHide(){
        clearTimeout(hideTimer);
        hideTimer = setTimeout(() => {
            if (activeMenu) {
                activeMenu.classList.remove('dropdown-menu-visible');
                activeMenu.style.display = 'none';
                activeMenu = null;
            }
        }, 150);
    }

    dropdowns.forEach(dd => {
        const menu = dd.querySelector('.dropdown-menu');
        if (!menu) return;

        dd.addEventListener('mouseenter', () => showMenu(dd));
        dd.addEventListener('mouseleave', scheduleHide);
        menu.addEventListener('mouseenter', () => clearTimeout(hideTimer));
        menu.addEventListener('mouseleave', scheduleHide);
    });

    // Reposition / hide on scroll or resize so the menu never drifts away
    // from its button while the table is scrolled.
    function hideActive(){
        if (activeMenu) {
            activeMenu.classList.remove('dropdown-menu-visible');
            activeMenu.style.display = 'none';
            activeMenu = null;
        }
    }
    if (scrollWrap) scrollWrap.addEventListener('scroll', hideActive, { passive: true });
    window.addEventListener('scroll', hideActive, { passive: true });
    window.addEventListener('resize', hideActive);
})();

/* HELPER: Get currently visible/filtered tasks based on active table rows */
function getActiveTasks() {
    const visibleRowIds = new Set();
    const rows = document.querySelectorAll("#tableBody tr[data-status]");
    rows.forEach(r => {
        if (r.style.display !== 'none') {
            const id = parseInt(r.dataset.taskId, 10);
            if (id) visibleRowIds.add(id);
        }
    });

    // Return exportData matching visible row IDs (or all if no filter applied)
    if (visibleRowIds.size === 0) return [];
    return exportData.filter(item => visibleRowIds.has(item._id));
}

/* HELPER: Get formatted Date Range label */
function getActiveDateRangeLabel() {
    const fromVal = filterFromDate ? filterFromDate.value : '';
    const toVal = filterToDate ? filterToDate.value : '';
    
    if (fromVal && toVal) {
        return `${formatDisplayDate(fromVal)} to ${formatDisplayDate(toVal)}`;
    } else if (fromVal) {
        return `From ${formatDisplayDate(fromVal)}`;
    } else if (toVal) {
        return `Up to ${formatDisplayDate(toVal)}`;
    }
    return 'All Dates';
}

function formatDisplayDate(dateStr) {
    if (!dateStr) return '';
    try {
        const d = new Date(dateStr + 'T00:00:00');
        return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    } catch(e) {
        return dateStr;
    }
}

/* EXPORT TO EXCEL — exports currently filtered tasks with date range in filename */
function exportExcel(){
    const activeData = getActiveTasks();
    if (!activeData || !activeData.length) {
        if (window.Swal) {
            Swal.fire({ icon: 'info', title: 'Nothing to export', text: 'There are no visible tasks to export with the current filter.' });
        } else {
            alert('There are no visible tasks to export.');
        }
        return;
    }

    // Prepare clean rows without internal `_id`
    const cleanRows = activeData.map(item => {
        const row = { ...item };
        delete row._id;
        return row;
    });

    const ws = XLSX.utils.json_to_sheet(cleanRows);

    ws['!cols'] = [
        { wch: 18 }, // Task Key
        { wch: 30 }, // Task Name
        { wch: 20 }, // Client Name
        { wch: 18 }, // Subject
        { wch: 12 }, // Word Count
        { wch: 14 }, // Created Date
        { wch: 18 }, // Deadline
        { wch: 14 }, // Status
        { wch: 18 }, // Stage
        { wch: 10 }, // Priority
        { wch: 35 }, // Google Drive Link
        { wch: 35 }, // Additional Files
        { wch: 35 }, // Final File
        { wch: 30 }, // Feedback
    ];

    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Tasks");
    
    // File name with date range if selected
    const fromVal = filterFromDate ? filterFromDate.value : '';
    const toVal = filterToDate ? filterToDate.value : '';
    let fileName = "Digiink_Tasks_";
    if (fromVal && toVal) {
        fileName += `${fromVal}_to_${toVal}.xlsx`;
    } else if (fromVal) {
        fileName += `from_${fromVal}.xlsx`;
    } else {
        fileName += `${new Date().toISOString().split('T')[0]}.xlsx`;
    }

    XLSX.writeFile(wb, fileName);
}

/* EXPORT TO PDF — generates high-end corporate report with company logo and executive styling */
function exportPDF(){
    const activeData = getActiveTasks();
    if (!activeData || !activeData.length) {
        if (window.Swal) {
            Swal.fire({ icon: 'info', title: 'Nothing to export', text: 'There are no visible tasks to export with the current filter.' });
        } else {
            alert('There are no visible tasks to export.');
        }
        return;
    }

    const { jsPDF } = window.jspdf || {};
    if (!jsPDF) {
        Swal.fire({ icon: 'error', title: 'PDF Error', text: 'PDF library is not loaded properly. Please refresh and try again.' });
        return;
    }

    // Landscape orientation A4 (841.89 x 595.28 pt)
    const doc = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
    const pageWidth = doc.internal.pageSize.getWidth();
    const pageHeight = doc.internal.pageSize.getHeight();
    const dateRangeLabel = getActiveDateRangeLabel();
    const generatedOn = new Date().toLocaleString('en-IN', { timeZone: 'Asia/Kolkata', dateStyle: 'medium', timeStyle: 'short' });

    // Calculate dynamic stats from activeData
    let totalWords = 0;
    let completedCount = 0;
    let urgentCount = 0;
    let activeCount = 0;

    activeData.forEach(item => {
        totalWords += (item['Word Count'] || 0);
        const st = (item['Status'] || '').toLowerCase();
        if (st === 'completed') completedCount++;
        else activeCount++;
        if ((item['Priority'] || '').toLowerCase() === 'urgent') urgentCount++;
    });

    // 1. Premium Top Banner (Deep Navy Gradient feel)
    doc.setFillColor(15, 23, 42); // #0f172a (slate-900)
    doc.rect(0, 0, pageWidth, 72, 'F');

    // Accent line at bottom of header (Emerald / Teal line)
    doc.setFillColor(16, 185, 129); // emerald-500
    doc.rect(0, 70, pageWidth, 2.5, 'F');

    // 2. Digiink Logo in Top Left
    let headerTextX = 32;
    if (appLogoBase64) {
        try {
            // Draw clean logo with white rounded container
            doc.setFillColor(255, 255, 255);
            doc.roundedRect(30, 11, 50, 50, 6, 6, 'F');
            doc.addImage(appLogoBase64, 'PNG', 34, 15, 42, 42);
            headerTextX = 92;
        } catch(e) {
            headerTextX = 32;
        }
    }

    // 3. Organization & Report Titles
    doc.setTextColor(255, 255, 255);
    doc.setFontSize(15);
    doc.setFont('helvetica', 'bold');
    doc.text('DIGIINK SOLUTIONS', headerTextX, 30);

    doc.setFontSize(10.5);
    doc.setFont('helvetica', 'normal');
    doc.setTextColor(203, 213, 225); // slate-300
    doc.text('Task Inventory & Work Allocation Report', headerTextX, 45);

    doc.setFontSize(8.5);
    doc.setTextColor(52, 211, 153); // emerald-400 font
    doc.setFont('helvetica', 'bold');
    doc.text(`DATE RANGE (ASSIGNED / CREATED): ${dateRangeLabel.toUpperCase()}`, headerTextX, 60);

    // 4. Header Right Side Metadata Card
    const metaRightX = pageWidth - 32;
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(8.5);
    doc.setTextColor(148, 163, 184); // slate-400
    doc.text(`Generated By: ${currentUserName || 'BDE'}`, metaRightX, 28, { align: 'right' });
    doc.text(`Generated On: ${generatedOn}`, metaRightX, 42, { align: 'right' });

    doc.setTextColor(255, 255, 255);
    doc.setFont('helvetica', 'bold');
    doc.text(`Total Selected Tasks: ${activeData.length}`, metaRightX, 58, { align: 'right' });

    // 5. Executive Summary Metric Badges (Cards)
    const cardY = 82;
    const cardHeight = 36;
    const cardGap = 12;
    const cardWidth = (pageWidth - 64 - (cardGap * 3)) / 4;

    const cards = [
        { label: 'TOTAL TASKS', val: activeData.length.toString(), bg: [241, 245, 249], border: [203, 213, 225], text: [15, 23, 42] },
        { label: 'TOTAL WORD COUNT', val: totalWords.toLocaleString() + ' W', bg: [238, 242, 255], border: [199, 210, 254], text: [67, 56, 202] },
        { label: 'ACTIVE / WORKING', val: activeCount.toString(), bg: [254, 243, 199], border: [253, 230, 138], text: [146, 64, 14] },
        { label: 'COMPLETED', val: completedCount.toString(), bg: [236, 253, 245], border: [167, 243, 208], text: [4, 120, 87] }
    ];

    cards.forEach((c, idx) => {
        const cx = 32 + idx * (cardWidth + cardGap);
        doc.setFillColor(c.bg[0], c.bg[1], c.bg[2]);
        doc.setDrawColor(c.border[0], c.border[1], c.border[2]);
        doc.setLineWidth(0.75);
        doc.roundedRect(cx, cardY, cardWidth, cardHeight, 4, 4, 'FD');

        doc.setFont('helvetica', 'bold');
        doc.setFontSize(7);
        doc.setTextColor(100, 116, 139); // slate-500
        doc.text(c.label, cx + 10, cardY + 14);

        doc.setFontSize(12);
        doc.setTextColor(c.text[0], c.text[1], c.text[2]);
        doc.text(c.val, cx + 10, cardY + 30);
    });

    // 6. Table Data Mapping
    const tableColumns = [
        { header: 'Key', dataKey: 'key' },
        { header: 'Task Name', dataKey: 'name' },
        { header: 'Client', dataKey: 'client' },
        { header: 'Subject', dataKey: 'subject' },
        { header: 'Words', dataKey: 'words' },
        { header: 'Created', dataKey: 'created' },
        { header: 'Deadline', dataKey: 'deadline' },
        { header: 'Status', dataKey: 'status' },
        { header: 'Stage', dataKey: 'stage' },
        { header: 'Priority', dataKey: 'priority' }
    ];

    const tableRows = activeData.map(item => ({
        key: item['Task Key'] || '',
        name: (item['Task Name'] || '').length > 34 ? (item['Task Name'] || '').substring(0, 32) + '...' : (item['Task Name'] || ''),
        client: item['Client Name'] || 'N/A',
        subject: (item['Subject'] || 'N/A').length > 20 ? (item['Subject'] || '').substring(0, 18) + '...' : (item['Subject'] || 'N/A'),
        words: (item['Word Count'] || 0).toLocaleString(),
        created: item['Created Date'] || '-',
        deadline: item['Deadline'] || '-',
        status: item['Status'] || '-',
        stage: item['Stage'] || '-',
        priority: item['Priority'] || 'Normal'
    }));

    doc.autoTable({
        columns: tableColumns,
        body: tableRows,
        startY: 128,
        margin: { left: 32, right: 32, bottom: 35 },
        theme: 'striped',
        styles: {
            fontSize: 7.5,
            cellPadding: 4.5,
            textColor: [39, 39, 42],
            lineColor: [228, 228, 231],
            lineWidth: 0.5,
            overflow: 'linebreak',
            valign: 'middle'
        },
        headStyles: {
            fillColor: [30, 41, 59], // slate-800
            textColor: [255, 255, 255],
            fontStyle: 'bold',
            fontSize: 8,
            halign: 'left',
            cellPadding: 5.5
        },
        alternateRowStyles: {
            fillColor: [248, 250, 252] // slate-50
        },
        columnStyles: {
            key: { cellWidth: 55, fontStyle: 'bold', textColor: [15, 23, 42] },
            name: { cellWidth: 140 },
            client: { cellWidth: 75 },
            subject: { cellWidth: 75 },
            words: { cellWidth: 48, halign: 'right', fontStyle: 'bold' },
            created: { cellWidth: 62, halign: 'center' },
            deadline: { cellWidth: 88, textColor: [190, 18, 60], fontStyle: 'bold' }, // rose-700
            status: { cellWidth: 65, halign: 'center' },
            stage: { cellWidth: 70, halign: 'center' },
            priority: { cellWidth: 45, halign: 'center', fontStyle: 'bold' }
        },
        didParseCell: function(data) {
            // Priority coloring
            if (data.column.dataKey === 'priority' && data.section === 'body') {
                if (data.cell.raw === 'Urgent') {
                    data.cell.styles.textColor = [225, 29, 72]; // rose-600
                } else {
                    data.cell.styles.textColor = [100, 116, 139]; // slate-500
                }
            }
            // Status coloring
            if (data.column.dataKey === 'status' && data.section === 'body') {
                const s = (data.cell.raw || '').toLowerCase();
                if (s.includes('completed')) {
                    data.cell.styles.textColor = [5, 150, 105]; // emerald-600
                } else if (s.includes('rework')) {
                    data.cell.styles.textColor = [234, 88, 12]; // orange-600
                } else {
                    data.cell.styles.textColor = [37, 99, 235]; // blue-600
                }
            }
        },
        didDrawPage: function (data) {
            // Footer on each page
            const totalPages = doc.internal.getNumberOfPages();
            const currentPage = data.pageNumber;

            // Footer separator line
            doc.setDrawColor(226, 232, 240); // slate-200
            doc.setLineWidth(0.75);
            doc.line(32, pageHeight - 22, pageWidth - 32, pageHeight - 22);

            // Left footer note with BDE name
            doc.setFontSize(7.5);
            doc.setFont('helvetica', 'normal');
            doc.setTextColor(148, 163, 184); // slate-400
            doc.text(`Digiink Solutions • Prepared by: ${currentUserName || 'BDE'} • Filtered by Created Range: ${dateRangeLabel}`, 32, pageHeight - 11);

            // Right footer page numbering
            doc.setFont('helvetica', 'bold');
            doc.text(`Page ${currentPage}`, pageWidth - 32, pageHeight - 11, { align: 'right' });
        }
    });

    // Clean File Name with from/to date
    const fromVal = filterFromDate ? filterFromDate.value : '';
    const toVal = filterToDate ? filterToDate.value : '';
    let pdfFileName = "Digiink_Tasks_";
    if (fromVal && toVal) {
        pdfFileName += `${fromVal}_to_${toVal}.pdf`;
    } else if (fromVal) {
        pdfFileName += `from_${fromVal}.pdf`;
    } else {
        pdfFileName += `${new Date().toISOString().split('T')[0]}.pdf`;
    }

    doc.save(pdfFileName);
}

/* ENHANCED FEEDBACK MODAL WITH SCREENSHOTS & FILES */
let stagedFeedbackFiles = [];
let currentFeedbackTaskId = 0;

function resetFeedbackModalState() {
    stagedFeedbackFiles = [];
    document.getElementById("modalTaskId").value = "";
    document.getElementById("feedbackInput").value = "";
    document.getElementById("feedbackInput").readOnly = false;
    document.getElementById("feedbackFileInput").value = "";
    document.getElementById("stagedFilesContainer").innerHTML = "";
    document.getElementById("existingFilesList").innerHTML = "";
    document.getElementById("existingFilesSection").classList.add("hidden");
    document.getElementById("uploadZoneSection").classList.remove("hidden");
    document.getElementById("saveBtn").classList.remove("hidden");
    document.getElementById("editFeedbackBtn").classList.add("hidden");
}

function openFeedbackModal(taskId){
    resetFeedbackModalState();
    currentFeedbackTaskId = taskId;
    const modal = document.getElementById("feedbackModal");
    modal.classList.remove("hidden"); modal.classList.add("flex");
    document.getElementById("modalTaskId").value = taskId;
    document.getElementById("modalTitle").innerText = "Add Client Feedback";
    document.getElementById("modalSubtitle").innerText = "Attach screenshots, PDFs or notes for Operations";
    document.getElementById("feedbackInput").placeholder = "Write client feedback or revision notes... (Tip: You can press Ctrl+V to paste a screenshot here directly!)";
}

function viewFeedbackModal(taskId, feedback, filesJson){
    resetFeedbackModalState();
    currentFeedbackTaskId = taskId;
    const modal = document.getElementById("feedbackModal");
    modal.classList.remove("hidden"); modal.classList.add("flex");
    document.getElementById("modalTaskId").value = taskId;
    document.getElementById("modalTitle").innerText = "View Task Feedback";
    document.getElementById("modalSubtitle").innerText = "Client feedback submitted for Operations";
    
    const textarea = document.getElementById("feedbackInput");
    textarea.value = feedback || ""; 
    textarea.readOnly = true;

    // Parse & render existing files
    let files = [];
    try {
        files = (typeof filesJson === 'string') ? JSON.parse(filesJson) : (filesJson || []);
    } catch(e) { files = []; }

    const existingSection = document.getElementById("existingFilesSection");
    const existingList = document.getElementById("existingFilesList");
    existingList.innerHTML = "";

    if (Array.isArray(files) && files.length > 0) {
        existingSection.classList.remove("hidden");
        files.forEach(f => {
            const card = document.createElement("div");
            if (f.is_image) {
                card.className = "flex items-center gap-2 p-2 bg-zinc-50 border border-zinc-200 rounded-xl hover:border-blue-300 transition group";
                card.innerHTML = `
                    <img src="../../${f.path}" class="w-10 h-10 object-cover rounded-lg shrink-0 cursor-pointer border border-zinc-200" 
                         onclick="openImagePreview('../../${f.path}', '${f.name}')">
                    <div class="min-w-0 flex-1">
                        <p class="text-[11px] font-bold text-zinc-700 truncate cursor-pointer hover:text-blue-600" onclick="openImagePreview('../../${f.path}', '${f.name}')">${f.name}</p>
                        <p class="text-[9px] text-zinc-400 font-medium">${f.size || ''}</p>
                    </div>
                    <a href="../../${f.path}" download class="p-1.5 text-zinc-400 hover:text-blue-600 transition" title="Download">
                        <span class="material-icons-round text-sm">download</span>
                    </a>
                `;
            } else {
                card.className = "flex items-center gap-2 p-2 bg-zinc-50 border border-zinc-200 rounded-xl hover:border-blue-300 transition group";
                card.innerHTML = `
                    <div class="w-10 h-10 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center shrink-0">
                        <span class="material-icons-round text-base">description</span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-[11px] font-bold text-zinc-700 truncate">${f.name}</p>
                        <p class="text-[9px] text-zinc-400 font-medium">${f.size || ''}</p>
                    </div>
                    <a href="../../${f.path}" download class="p-1.5 text-zinc-400 hover:text-blue-600 transition" title="Download">
                        <span class="material-icons-round text-sm">download</span>
                    </a>
                `;
            }
            existingList.appendChild(card);
        });
    }

    // In view mode, hide upload zone & show Edit button
    document.getElementById("uploadZoneSection").classList.add("hidden");
    document.getElementById("saveBtn").classList.add("hidden");
    document.getElementById("editFeedbackBtn").classList.remove("hidden");
}

function switchToEditMode() {
    document.getElementById("modalTitle").innerText = "Edit Feedback & Add Files";
    document.getElementById("modalSubtitle").innerText = "Update client feedback or append new screenshots";
    document.getElementById("feedbackInput").readOnly = false;
    document.getElementById("feedbackInput").focus();
    document.getElementById("uploadZoneSection").classList.remove("hidden");
    document.getElementById("saveBtn").classList.remove("hidden");
    document.getElementById("editFeedbackBtn").classList.add("hidden");
}

function handleFeedbackFileSelect(input) {
    if (!input.files || input.files.length === 0) return;
    for (let i = 0; i < input.files.length; i++) {
        stagedFeedbackFiles.push(input.files[i]);
    }
    syncStagedFilesToInput();
    renderStagedFiles();
}

function renderStagedFiles() {
    const container = document.getElementById("stagedFilesContainer");
    container.innerHTML = "";

    stagedFeedbackFiles.forEach((file, index) => {
        const item = document.createElement("div");
        item.className = "flex items-center justify-between p-2 rounded-xl bg-zinc-100/80 border border-zinc-200 text-xs";
        
        const isImg = file.type.startsWith("image/");
        const iconName = isImg ? "image" : "description";
        const iconColor = isImg ? "text-emerald-600 bg-emerald-50" : "text-blue-600 bg-blue-50";

        item.innerHTML = `
            <div class="flex items-center gap-2 min-w-0">
                <span class="w-7 h-7 rounded-lg ${iconColor} flex items-center justify-center shrink-0">
                    <span class="material-icons-round text-sm">${iconName}</span>
                </span>
                <div class="truncate">
                    <p class="font-bold text-zinc-800 text-[11px] truncate">${file.name}</p>
                    <p class="text-[9px] text-zinc-400 font-semibold">${(file.size / 1024).toFixed(1)} KB</p>
                </div>
            </div>
            <button type="button" onclick="removeStagedFile(${index})" class="text-zinc-400 hover:text-rose-600 p-1 rounded transition" title="Remove">
                <span class="material-icons-round text-sm">close</span>
            </button>
        `;
        container.appendChild(item);
    });
}

function removeStagedFile(index) {
    stagedFeedbackFiles.splice(index, 1);
    syncStagedFilesToInput();
    renderStagedFiles();
}

function syncStagedFilesToInput() {
    const dt = new DataTransfer();
    stagedFeedbackFiles.forEach(f => dt.items.add(f));
    document.getElementById("feedbackFileInput").files = dt.files;
}

// Clipboard Paste Handler (Ctrl + V for Instant Screenshot Upload)
window.addEventListener('paste', function(e) {
    const modal = document.getElementById("feedbackModal");
    if (!modal || modal.classList.contains("hidden")) return;

    if (e.clipboardData && e.clipboardData.items) {
        const items = e.clipboardData.items;
        for (let i = 0; i < items.length; i++) {
            if (items[i].type.indexOf("image") !== -1) {
                const blob = items[i].getAsFile();
                if (blob) {
                    const ext = blob.type.split("/")[1] || "png";
                    const file = new File([blob], `screenshot_${Date.now()}.${ext}`, { type: blob.type });
                    stagedFeedbackFiles.push(file);
                    syncStagedFilesToInput();
                    renderStagedFiles();

                    // Show SweetAlert toast
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Screenshot Pasted!',
                            text: file.name + ' added to attachments',
                            toast: true,
                            position: 'top-end',
                            timer: 2000,
                            showConfirmButton: false
                        });
                    }
                }
            }
        }
    }
});

function openImagePreview(src, caption) {
    const modal = document.getElementById("imageLightboxModal");
    const img = document.getElementById("lightboxImg");
    const cap = document.getElementById("lightboxCaption");
    if (!modal || !img) return;

    img.src = src;
    if (cap) cap.innerText = caption || "";
    modal.classList.remove("hidden");
    modal.classList.add("flex");
}

function closeImageLightbox() {
    const modal = document.getElementById("imageLightboxModal");
    if (modal) {
        modal.classList.add("hidden");
        modal.classList.remove("flex");
    }
}

function closeModal(){
    const modal = document.getElementById("feedbackModal");
    modal.classList.add("hidden"); modal.classList.remove("flex");
    resetFeedbackModalState();
}

document.getElementById("feedbackModal").addEventListener("click", function(e){
    if(e.target === this) closeModal();
});

function confirmDelete(id) {
    Swal.fire({
        title: 'Delete Task?',
        text: "This action cannot be undone and will erase all task data.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#09090b',
        cancelButtonColor: '#71717a',
        confirmButtonText: 'Delete',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) window.location.href = `delete_task.php?id=${id}`;
    });
}
</script>

<?php if(isset($_GET['msg']) && $_GET['msg'] === 'locked_cannot_delete'): ?>
<script>
Swal.fire({
    icon: 'error',
    title: 'Deletion Restricted',
    text: 'This task has already been assigned or delivered by Operations Manager and cannot be deleted.',
    confirmButtonColor: '#09090b'
});
</script>
<?php endif; ?>

<?php if(isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?>
<script>
Swal.fire({
    icon: 'success',
    title: 'Task Deleted',
    text: 'Task and its Drive folder have been removed successfully.',
    confirmButtonColor: '#09090b'
});
</script>
<?php endif; ?>

</body>
</html>