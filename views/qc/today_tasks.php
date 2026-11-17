<?php
date_default_timezone_set('Asia/Kolkata');

require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole(['qc', 'operations_manager']);

require_once "../../app/config/database.php";
require_once "../../bootstrap/app.php";

$db = (new Database())->connect();

$selectedDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}

$dateType = $_GET['type'] ?? 'created';
$prevDate = date('Y-m-d', strtotime($selectedDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($selectedDate . ' +1 day'));
$isToday  = ($selectedDate === date('Y-m-d'));

$whereDate = ($dateType === 'deadline') ? "DATE(t.deadline) = :dt" : "(DATE(t.created_at) = :dt OR DATE(t.task_created_at) = :dt)";

$stmt = $db->prepare("
    SELECT t.*, expert.name AS expert_name, bde.name AS bde_name
    FROM tasks t
    LEFT JOIN users expert ON t.assigned_to = expert.id
    LEFT JOIN users bde ON t.created_by = bde.id
    WHERE {$whereDate}
    ORDER BY t.id DESC
");
$stmt->execute([':dt' => $selectedDate]);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Metrics
$totalDayTasks = count($tasks);
$dayApproved   = 0;
$dayPendingQc  = 0;
$dayRework     = 0;
$dayUrgent     = 0;
$dayWords      = 0;

foreach ($tasks as $t) {
    $dayWords += (int)($t['word_count'] ?? 0);
    if (($t['urgent'] ?? '') === 'yes') $dayUrgent++;
    if ($t['status'] === 'completed' || $t['current_stage'] === 'completed') {
        $dayApproved++;
    } elseif ($t['current_stage'] === 'qc') {
        $dayPendingQc++;
    } elseif ($t['status'] === 'rework') {
        $dayRework++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Today's Tasks | QC Panel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        * { font-family: 'Plus Jakarta Sans', sans-serif; }
        body { background-color: #f8fafc; }
        .surface {
            background: #fff;
            border: 1px solid #eef0f3;
            border-radius: 20px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
        }
        .stat-card {
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 24px -8px rgba(15, 23, 42, 0.08);
        }
    </style>
</head>
<body class="text-slate-900">

<div class="flex min-h-screen">
    <?php include "partials/sidebar.php"; ?>

    <main class="flex-1 p-5 lg:p-8 max-w-[1440px] mx-auto w-full">

        <!-- Top Header & Date Navigation -->
        <div class="flex flex-col xl:flex-row justify-between items-start xl:items-center gap-5 mb-8">
            <div class="flex items-center gap-3">
                <div class="w-11 h-11 rounded-2xl bg-indigo-600 text-white flex items-center justify-center shadow-lg shadow-indigo-200">
                    <span class="material-icons-round text-2xl">fact_check</span>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-2xl font-extrabold text-slate-800 tracking-tight">QC Daily Task Hub</h1>
                        <?php if ($isToday): ?>
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-black bg-emerald-100 text-emerald-800 uppercase tracking-wider animate-pulse">Today</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs text-slate-500 font-medium mt-0.5">
                        Quality checks for <span class="font-bold text-slate-700"><?= date('l, d F Y', strtotime($selectedDate)) ?></span>
                    </p>
                </div>
            </div>

            <!-- Date Controls -->
            <div class="flex flex-wrap items-center gap-2.5 bg-white p-2 rounded-2xl border border-slate-200 shadow-sm">
                <a href="?date=<?= $prevDate ?>&type=<?= $dateType ?>" 
                   class="px-3 py-2 rounded-xl text-xs font-bold text-slate-600 hover:bg-slate-100 transition-colors flex items-center gap-1">
                    <span class="material-icons-round text-base">chevron_left</span> Prev
                </a>

                <a href="?date=<?= date('Y-m-d') ?>&type=<?= $dateType ?>" 
                   class="px-3 py-2 rounded-xl text-xs font-bold transition-all <?= $isToday ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100' ?>">
                    Today
                </a>

                <form method="GET" class="flex items-center gap-2 m-0">
                    <input type="hidden" name="type" value="<?= htmlspecialchars($dateType) ?>">
                    <input type="date" name="date" value="<?= htmlspecialchars($selectedDate) ?>" 
                           onchange="this.form.submit()"
                           class="px-3 py-1.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 cursor-pointer">
                </form>

                <a href="?date=<?= $nextDate ?>&type=<?= $dateType ?>" 
                   class="px-3 py-2 rounded-xl text-xs font-bold text-slate-600 hover:bg-slate-100 transition-colors flex items-center gap-1">
                    Next <span class="material-icons-round text-base">chevron_right</span>
                </a>

                <div class="border-l border-slate-200 pl-2 flex items-center gap-1.5">
                    <span class="text-[10px] font-black uppercase text-slate-400 tracking-wider hidden sm:inline">Filter By:</span>
                    <a href="?date=<?= $selectedDate ?>&type=created"
                       class="px-3 py-1.5 rounded-xl text-xs font-bold flex items-center gap-1.5 transition-all <?= $dateType === 'created' ? 'bg-indigo-600 text-white shadow-sm ring-2 ring-indigo-200' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                        <span class="material-icons-round text-sm">assignment</span> Assigned Date
                    </a>
                    <a href="?date=<?= $selectedDate ?>&type=deadline"
                       class="px-3 py-1.5 rounded-xl text-xs font-bold flex items-center gap-1.5 transition-all <?= $dateType === 'deadline' ? 'bg-rose-600 text-white shadow-sm ring-2 ring-rose-200' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                        <span class="material-icons-round text-sm">event</span> Delivery Deadline
                    </a>
                </div>
            </div>
        </div>

        <!-- Metrics Cards -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3.5 mb-7">
            <div class="stat-card surface p-4 border-l-4 border-l-indigo-500">
                <span class="text-[10px] font-black uppercase tracking-widest text-slate-400">Total <?= $dateType === 'deadline' ? 'Deliveries' : 'Assigned' ?></span>
                <h3 class="text-2xl font-extrabold text-slate-800 mt-1"><?= $totalDayTasks ?></h3>
                <p class="text-[11px] text-slate-400 font-semibold mt-0.5"><?= $dateType === 'deadline' ? 'Due on this date' : 'Tasks on this date' ?></p>
            </div>

            <div class="stat-card surface p-4 border-l-4 border-l-purple-500">
                <span class="text-[10px] font-black uppercase tracking-widest text-purple-500">In QC Stage</span>
                <h3 class="text-2xl font-extrabold text-slate-800 mt-1"><?= $dayPendingQc ?></h3>
                <p class="text-[11px] text-slate-400 font-semibold mt-0.5">Pending your review</p>
            </div>

            <div class="stat-card surface p-4 border-l-4 border-l-emerald-500">
                <span class="text-[10px] font-black uppercase tracking-widest text-emerald-500">Approved</span>
                <h3 class="text-2xl font-extrabold text-emerald-600 mt-1"><?= $dayApproved ?></h3>
                <p class="text-[11px] text-slate-400 font-semibold mt-0.5">Passed QC</p>
            </div>

            <div class="stat-card surface p-4 border-l-4 border-l-rose-500">
                <span class="text-[10px] font-black uppercase tracking-widest text-rose-500">Rework</span>
                <h3 class="text-2xl font-extrabold text-rose-600 mt-1"><?= $dayRework ?></h3>
                <p class="text-[11px] text-slate-400 font-semibold mt-0.5">Sent back</p>
            </div>

            <div class="stat-card surface p-4 bg-gradient-to-br from-indigo-900 to-slate-900 text-white border-none shadow-md">
                <span class="text-[10px] font-black uppercase tracking-widest text-indigo-300">Word Count</span>
                <h3 class="text-2xl font-extrabold text-white mt-1"><?= number_format($dayWords) ?></h3>
                <p class="text-[10px] text-indigo-200 font-bold mt-0.5"><?= $dayUrgent > 0 ? "⚠️ {$dayUrgent} Urgent" : "Regular volume" ?></p>
            </div>
        </div>

        <!-- Task Table -->
        <div class="surface overflow-hidden">
            <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                <div class="flex items-center gap-2">
                    <span class="material-icons-round text-indigo-500 text-lg">format_list_bulleted</span>
                    <h3 class="font-bold text-slate-800 text-sm">
                        <?= $dateType === 'deadline' ? 'Tasks Delivering on ' : 'QC Review Tasks for ' ?><?= date('d M Y', strtotime($selectedDate)) ?>
                    </h3>
                </div>
                <span class="text-xs text-slate-400 font-bold"><?= count($tasks) ?> record<?= count($tasks) === 1 ? '' : 's' ?></span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="text-[11px] font-extrabold uppercase tracking-wider text-slate-400 bg-slate-50 border-b border-slate-100">
                            <th class="px-6 py-4">Task Key & Name</th>
                            <th class="px-6 py-4">Words</th>
                            <th class="px-6 py-4">Assigned & Delivery (Deadline)</th>
                            <th class="px-6 py-4">Expert</th>
                            <th class="px-6 py-4 text-center">Google Drive Subfolders</th>
                            <th class="px-6 py-4 text-center">Stage / Status</th>
                            <th class="px-6 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-xs font-medium">
                        <?php if (count($tasks) > 0): ?>
                            <?php foreach ($tasks as $t): 
                                $clientLink = getDriveClientLink($t);
                                $opsLink    = getDriveOperationsLink($t);
                                $isPendingQc = ($t['current_stage'] === 'qc');
                                $isApproved  = ($t['status'] === 'completed' || $t['current_stage'] === 'completed');
                                $assignedDt = $t['task_created_at'] ?? $t['created_at'];
                                $isTodayAssigned = (!empty($assignedDt) && date('Y-m-d', strtotime($assignedDt)) === date('Y-m-d'));
                                $isTodayDeadline = (!empty($t['deadline']) && date('Y-m-d', strtotime($t['deadline'])) === date('Y-m-d'));
                            ?>
                            <tr class="hover:bg-slate-50/70 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <span class="font-bold text-slate-800 text-sm tracking-tight">
                                            <?= htmlspecialchars($t['task_key']) ?>
                                        </span>
                                        <?php if (($t['urgent'] ?? '') === 'yes'): ?>
                                        <span class="px-1.5 py-0.5 bg-rose-100 text-rose-700 text-[9px] font-black rounded uppercase">Urgent</span>
                                        <?php endif; ?>
                                        <?php if (!empty($t['additional_instructions'])): ?>
                                        <span class="px-1.5 py-0.5 bg-amber-100 text-amber-900 border border-amber-300 text-[9px] font-black rounded uppercase inline-flex items-center gap-0.5 shadow-xs" title="Additional instructions / updates from client">
                                            <span class="material-icons-round text-[11px] text-amber-600">new_releases</span> Extra Info
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-[11px] text-slate-500 font-semibold truncate max-w-xs mt-0.5">
                                        <?= htmlspecialchars($t['task_name']) ?>
                                    </p>
                                    <?php if (!empty($t['team_notes'])): ?>
                                    <div class="mt-1 flex items-start gap-1 max-w-xs">
                                        <span class="material-icons-round text-amber-500 text-xs shrink-0 mt-0.5">edit_note</span>
                                        <p class="text-[10px] text-amber-800 font-semibold truncate bg-amber-50 px-1.5 py-0.5 rounded border border-amber-200/60" title="Expert Note: <?= htmlspecialchars($t['team_notes'], ENT_QUOTES) ?>">
                                            <span class="font-extrabold text-amber-900">Note:</span> <?= htmlspecialchars($t['team_notes']) ?>
                                        </p>
                                    </div>
                                    <?php endif; ?>
                                </td>

                                <td class="px-6 py-4 font-bold text-slate-700">
                                    <?= number_format($t['word_count'] ?? 0) ?>
                                </td>

                                <td class="px-6 py-4">
                                    <!-- Assigned Date/Time -->
                                    <div class="flex items-center gap-1.5 mb-1.5">
                                        <span class="px-1.5 py-0.5 rounded text-[9px] font-extrabold uppercase <?= $isTodayAssigned ? 'bg-emerald-100 text-emerald-800 font-black' : 'bg-slate-100 text-slate-600' ?>">
                                            <?= $isTodayAssigned ? 'Today Assigned' : 'Assigned' ?>
                                        </span>
                                        <span class="text-xs font-semibold text-slate-700">
                                            <?= !empty($assignedDt) ? date('d M, h:i A', strtotime($assignedDt)) : 'N/A' ?>
                                        </span>
                                    </div>

                                    <!-- Delivery Deadline Date/Time -->
                                    <div class="flex items-center gap-1.5">
                                        <span class="px-1.5 py-0.5 rounded text-[9px] font-extrabold uppercase <?= $isTodayDeadline ? 'bg-rose-100 text-rose-700 font-black animate-pulse' : 'bg-amber-100 text-amber-800' ?>">
                                            <?= $isTodayDeadline ? 'Due Today' : 'Deliver By' ?>
                                        </span>
                                        <span class="text-xs font-bold <?= $isTodayDeadline ? 'text-rose-600' : 'text-slate-800' ?>">
                                            <?= !empty($t['deadline']) ? date('d M, h:i A', strtotime($t['deadline'])) : 'N/A' ?>
                                        </span>
                                    </div>
                                </td>

                                <td class="px-6 py-4">
                                    <span class="font-bold text-slate-700"><?= htmlspecialchars($t['expert_name'] ?? 'Unassigned') ?></span>
                                    <span class="block text-[10px] text-slate-400">BDE: <?= htmlspecialchars($t['bde_name'] ?? 'N/A') ?></span>
                                </td>

                                <td class="px-6 py-4 text-center">
                                    <div class="inline-flex items-center gap-1.5">
                                        <?php if (!empty($clientLink)): ?>
                                        <a href="<?= htmlspecialchars($clientLink) ?>" target="_blank"
                                           class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-blue-50 text-blue-700 hover:bg-blue-600 hover:text-white rounded-lg text-[10px] font-bold transition-all"
                                           title="Direct Client Files Subfolder">
                                            <span class="material-icons-round text-sm">folder_open</span> Client Files
                                        </a>
                                        <?php endif; ?>

                                        <?php if (!empty($opsLink)): ?>
                                        <a href="<?= htmlspecialchars($opsLink) ?>" target="_blank"
                                           class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-emerald-50 text-emerald-700 hover:bg-emerald-600 hover:text-white rounded-lg text-[10px] font-bold transition-all"
                                           title="Direct Operations Files Subfolder">
                                            <span class="material-icons-round text-sm">inventory_2</span> Operations Files
                                        </a>
                                        <?php endif; ?>

                                        <?php if (($_SESSION['role'] ?? '') === 'operations_manager'): ?>
                                        <!-- OM Edit/Re-upload Solution File -->
                                        <button type="button" onclick="openOmReuploadModal(<?= $t['id'] ?>, '<?= htmlspecialchars($t['task_key'], ENT_QUOTES) ?>')"
                                                class="inline-flex items-center gap-1 px-2 py-1.5 bg-amber-50 text-amber-700 hover:bg-amber-500 hover:text-white rounded-lg text-[10px] font-bold transition-all"
                                                title="OM: Edit/Re-upload Solution File (Clear & Sync Drive)">
                                            <span class="material-icons-round text-xs">drive_file_rename_outline</span> Edit
                                        </button>

                                        <?php if (!empty($t['team_file'])): ?>
                                        <!-- OM Delete Solution File -->
                                        <button type="button" onclick="confirmOmDeleteFile(<?= $t['id'] ?>, '<?= htmlspecialchars($t['task_key'], ENT_QUOTES) ?>')"
                                                class="inline-flex items-center gap-1 px-2 py-1.5 bg-rose-50 text-rose-700 hover:bg-rose-600 hover:text-white rounded-lg text-[10px] font-bold transition-all"
                                                title="OM: Delete Solution File from Local & Drive">
                                            <span class="material-icons-round text-xs">delete_forever</span> Delete
                                        </button>
                                        <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td class="px-6 py-4 text-center">
                                    <?php if ($isPendingQc): ?>
                                    <span class="px-2.5 py-1 bg-purple-50 text-purple-700 border border-purple-200 text-[10px] font-bold rounded-lg uppercase tracking-wider">
                                        Under QC Review
                                    </span>
                                    <?php elseif ($isApproved): ?>
                                    <span class="px-2.5 py-1 bg-emerald-50 text-emerald-700 border border-emerald-200 text-[10px] font-bold rounded-lg uppercase tracking-wider">
                                        Approved
                                    </span>
                                    <?php else: ?>
                                    <span class="px-2.5 py-1 bg-slate-100 text-slate-700 border border-slate-200 text-[10px] font-bold rounded-lg uppercase tracking-wider">
                                        <?= str_replace('_', ' ', $t['status']) ?>
                                    </span>
                                    <?php endif; ?>
                                </td>

                                <td class="px-6 py-4 text-right">
                                    <div class="inline-flex items-center gap-1.5">
                                        <?php if ($isPendingQc): ?>
                                        <a href="pending_tasks.php" 
                                           class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-bold transition-colors">
                                            Review Task
                                        </a>
                                        <?php elseif ($isApproved): ?>
                                        <button onclick="confirmQcRevert(<?= $t['id'] ?>, '<?= htmlspecialchars($t['task_key'], ENT_QUOTES) ?>')"
                                                class="px-2.5 py-1.5 bg-rose-50 hover:bg-rose-600 hover:text-white text-rose-700 rounded-lg text-xs font-bold transition-colors flex items-center gap-1">
                                            <span class="material-icons-round text-sm">undo</span> Recall
                                        </button>
                                        <?php else: ?>
                                        <a href="history.php" class="px-2.5 py-1.5 bg-slate-100 text-slate-600 rounded-lg text-xs font-bold">
                                            View
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-16">
                                    <div class="flex flex-col items-center justify-center">
                                        <div class="w-16 h-16 rounded-2xl bg-slate-50 border border-slate-100 flex items-center justify-center text-slate-300 mb-3">
                                            <span class="material-icons-round text-3xl">fact_check</span>
                                        </div>
                                        <h4 class="text-sm font-bold text-slate-700">No QC records for <?= date('d M Y', strtotime($selectedDate)) ?></h4>
                                        <p class="text-xs text-slate-400 mt-1">Review pending tasks or check another date.</p>
                                        <a href="pending_tasks.php" class="mt-4 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition-colors shadow-sm">
                                            View Pending QC Queue
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
</div>

<!-- ========================================================
     OM EDIT / RE-UPLOAD SOLUTION FILE MODAL (OPERATIONS MANAGER)
     ======================================================== -->
<?php if(($_SESSION['role'] ?? '') === 'operations_manager'): ?>
<div id="omReuploadModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-[2rem] max-w-lg w-full p-6 sm:p-8 shadow-2xl border border-slate-100 relative animate-in fade-in zoom-in-95 duration-150">
        <button type="button" onclick="closeOmReuploadModal()" class="absolute top-5 right-5 text-slate-400 hover:text-slate-600 w-8 h-8 rounded-full bg-slate-50 flex items-center justify-center">
            <span class="material-icons-round text-lg">close</span>
        </button>

        <div class="flex items-center gap-3 mb-4">
            <div class="w-12 h-12 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center">
                <span class="material-icons-round text-2xl">drive_file_rename_outline</span>
            </div>
            <div>
                <span class="text-[10px] font-black uppercase tracking-widest text-amber-600 bg-amber-50 px-2.5 py-0.5 rounded-full border border-amber-200">Operations Manager Action</span>
                <h3 class="text-lg font-black text-slate-800 tracking-tight mt-0.5">Edit & Replace Solution File</h3>
                <p id="omModalTaskKey" class="text-xs font-bold text-slate-400 uppercase tracking-wider">TASK-KEY</p>
            </div>
        </div>

        <p class="text-xs text-slate-500 leading-relaxed mb-5">
            Select the new/corrected solution file (e.g. corrected spacing, revised document). 
            <strong class="text-slate-700">The previous files in Google Drive's "Operations Files" subfolder will be cleared and replaced with this latest file automatically.</strong>
        </p>

        <form id="omReuploadForm" onsubmit="submitOmReupload(event)" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" id="omReuploadTaskId" name="task_id" value="">
            <input type="hidden" name="action" value="reupload_file">

            <div>
                <label class="block text-xs font-extrabold uppercase text-slate-600 mb-1.5">Select Solution File(s) *</label>
                <input type="file" name="solution_files[]" multiple required
                       class="w-full text-xs font-semibold text-slate-700 file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-black file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 border border-slate-200 rounded-2xl p-2 cursor-pointer bg-slate-50">
                <p class="text-[11px] text-slate-400 mt-1">Accepts .docx, .pdf, .zip, .xlsx or multiple files</p>
            </div>

            <div>
                <label class="block text-xs font-extrabold uppercase text-slate-600 mb-1.5">Operations Notes (Optional)</label>
                <textarea name="om_notes" rows="2" placeholder="e.g. Fixed spacing and references as discussed"
                          class="w-full text-xs font-medium border border-slate-200 rounded-2xl p-3 focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-slate-50"></textarea>
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="button" onclick="closeOmReuploadModal()" class="w-1/3 py-3 rounded-xl text-xs font-extrabold text-slate-500 hover:bg-slate-100 transition">
                    Cancel
                </button>
                <button type="submit" id="omSubmitBtn" class="w-2/3 py-3 rounded-xl text-xs font-extrabold bg-indigo-600 hover:bg-indigo-700 text-white shadow-lg shadow-indigo-200 transition flex items-center justify-center gap-1.5">
                    <span class="material-icons-round text-base">cloud_upload</span>
                    <span>Upload & Replace</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openOmReuploadModal(taskId, taskKey) {
    document.getElementById('omReuploadTaskId').value = taskId;
    document.getElementById('omModalTaskKey').textContent = taskKey;
    document.getElementById('omReuploadForm').reset();
    document.getElementById('omReuploadModal').classList.remove('hidden');
}

function closeOmReuploadModal() {
    document.getElementById('omReuploadModal').classList.add('hidden');
}

async function submitOmReupload(e) {
    e.preventDefault();
    const btn = document.getElementById('omSubmitBtn');
    const form = document.getElementById('omReuploadForm');
    const fd = new FormData(form);

    btn.disabled = true;
    btn.innerHTML = `<span class="material-icons-round text-base animate-spin">refresh</span> Uploading & Syncing Drive...`;

    try {
        const res = await fetch('om_file_action.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'File Replaced!',
                text: data.message,
                confirmButtonColor: '#4f46e5',
                customClass: { popup: 'rounded-[2rem]' }
            }).then(() => {
                location.reload();
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Upload Failed',
                text: data.error || 'Could not replace file',
                confirmButtonColor: '#e11d48',
                customClass: { popup: 'rounded-[2rem]' }
            });
        }
    } catch (err) {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: err.message || 'Network error occurred',
            confirmButtonColor: '#e11d48',
            customClass: { popup: 'rounded-[2rem]' }
        });
    } finally {
        btn.disabled = false;
        btn.innerHTML = `<span class="material-icons-round text-base">cloud_upload</span><span>Upload & Replace</span>`;
    }
}

function confirmOmDeleteFile(taskId, taskKey) {
    Swal.fire({
        title: 'Delete Solution File?',
        html: `Are you sure you want to delete the operations solution file for <strong>${taskKey}</strong>?<br><br><span class="text-xs text-rose-600 font-bold">This will remove the file from both local storage and the Google Drive Operations folder.</span>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e11d48',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, Delete File',
        cancelButtonText: 'Cancel',
        customClass: { popup: 'rounded-[2rem]' }
    }).then(async (result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Deleting...',
                text: 'Removing file from local and Google Drive',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            try {
                const fd = new FormData();
                fd.append('action', 'delete_file');
                fd.append('task_id', taskId);

                const res = await fetch('om_file_action.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Deleted!',
                        text: data.message,
                        confirmButtonColor: '#4f46e5',
                        customClass: { popup: 'rounded-[2rem]' }
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Delete Failed',
                        text: data.error || 'Could not delete file',
                        confirmButtonColor: '#e11d48',
                        customClass: { popup: 'rounded-[2rem]' }
                    });
                }
            } catch (err) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: err.message,
                    confirmButtonColor: '#e11d48',
                    customClass: { popup: 'rounded-[2rem]' }
                });
            }
        }
    });
}
</script>
<?php endif; ?>

<script>
function confirmQcRevert(taskId, taskKey) {
    Swal.fire({
        title: 'Recall QC Approval?',
        html: `Are you sure you want to recall approved task <strong>${taskKey}</strong>?<br><span class="text-xs text-slate-500">The task will return to your Pending QC queue for re-evaluation.</span>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e11d48',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, Revert to QC'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = `revert_task.php?id=${taskId}`;
        }
    });
}
</script>

</body>
</html>
