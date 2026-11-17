<?php
date_default_timezone_set('Asia/Kolkata');

require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole('bde');

require_once "../../app/config/database.php";
require_once "../../bootstrap/app.php";

$db = (new Database())->connect();
$userId = $_SESSION['user_id'];

// Date selection & Range Filter
$fromDate = trim($_GET['from_date'] ?? '');
$toDate   = trim($_GET['to_date'] ?? '');
$hasRange = (!empty($fromDate) && !empty($toDate));

$selectedDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}

$dateType = $_GET['type'] ?? 'created';
$prevDate = date('Y-m-d', strtotime($selectedDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($selectedDate . ' +1 day'));
$isToday  = (!$hasRange && $selectedDate === date('Y-m-d'));

if ($hasRange) {
    if ($dateType === 'deadline') {
        $whereDate = "DATE(t.deadline) BETWEEN :from_date AND :to_date";
    } else {
        $whereDate = "(DATE(COALESCE(t.task_created_at, t.created_at)) BETWEEN :from_date AND :to_date)";
    }
    $params = [
        ':user'      => $userId,
        ':from_date' => $fromDate,
        ':to_date'   => $toDate
    ];
} else {
    if ($dateType === 'deadline') {
        $whereDate = "DATE(t.deadline) = :dt";
    } else {
        $whereDate = "(DATE(t.created_at) = :dt OR DATE(t.task_created_at) = :dt)";
    }
    $params = [
        ':user' => $userId,
        ':dt'   => $selectedDate
    ];
}

$stmt = $db->prepare("
    SELECT t.*, expert.name AS expert_name
    FROM tasks t
    LEFT JOIN users expert ON t.assigned_to = expert.id
    WHERE t.created_by = :user
    AND {$whereDate}
    ORDER BY t.id DESC
");
$stmt->execute($params);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Metrics
$totalDayTasks = count($tasks);
$dayCompleted  = 0;
$dayWorking    = 0;
$dayNew        = 0;
$dayUrgent     = 0;
$dayWords      = 0;

foreach ($tasks as $t) {
    $dayWords += (int)($t['word_count'] ?? 0);
    if (($t['urgent'] ?? '') === 'yes') $dayUrgent++;
    if ($t['status'] === 'completed' || $t['current_stage'] === 'completed') {
        $dayCompleted++;
    } elseif ($t['status'] === 'new') {
        $dayNew++;
    } else {
        $dayWorking++;
    }
}

function getBdeStatusBadge($status) {
    return match($status) {
        'completed'   => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        'approved'    => 'bg-purple-50 text-purple-700 border-purple-200',
        'in_progress' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
        'assigned'    => 'bg-amber-50 text-amber-700 border-amber-200',
        'rework'      => 'bg-rose-50 text-rose-700 border-rose-200',
        'new'         => 'bg-sky-50 text-sky-700 border-sky-200',
        default       => 'bg-slate-100 text-slate-700 border-slate-200'
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Today's Tasks | BDE Portal</title>
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

        /* Delivered Row Highlight */
        tr.row-delivered {
            background-color: #f0fdf4 !important;
            border-left: 4px solid #10b981 !important;
        }
        tr.row-delivered td {
            border-top: 1px solid #bbf7d0;
            border-bottom: 1px solid #bbf7d0;
        }
        tr.row-delivered:hover {
            background-color: #dcfce7 !important;
        }
    </style>
</head>
<body class="text-zinc-900 antialiased">

<div class="flex h-screen overflow-hidden">
    <?php include "partials/sidebar.php"; ?>

    <main class="flex-1 overflow-y-auto">
        <div class="p-6 lg:p-8 space-y-6 max-w-[1500px] mx-auto">

        <!-- Header & Date Controls -->
        <div class="flex flex-col xl:flex-row justify-between items-start xl:items-center gap-5 mb-8">
            <div class="flex items-center gap-3">
                <div class="w-11 h-11 rounded-2xl bg-[#eba940] text-slate-900 flex items-center justify-center shadow-lg shadow-amber-200">
                    <span class="material-icons-round text-2xl font-bold">calendar_today</span>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-2xl font-extrabold text-slate-800 tracking-tight">Today's Tasks Hub</h1>
                        <?php if ($isToday): ?>
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-black bg-emerald-100 text-emerald-800 uppercase tracking-wider animate-pulse">Today</span>
                        <?php elseif ($hasRange): ?>
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-black bg-indigo-100 text-indigo-800 uppercase tracking-wider">Date Range Filter</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs text-slate-500 font-medium mt-0.5">
                        <?php if ($hasRange): ?>
                            Showing tasks <?= $dateType === 'deadline' ? 'delivering between' : 'assigned/created between' ?> <span class="font-bold text-slate-700"><?= date('d M Y', strtotime($fromDate)) ?></span> and <span class="font-bold text-slate-700"><?= date('d M Y', strtotime($toDate)) ?></span>
                        <?php else: ?>
                            Showing tasks <?= $dateType === 'deadline' ? 'delivering on' : 'assigned/booked on' ?> <span class="font-bold text-slate-700"><?= date('l, d F Y', strtotime($selectedDate)) ?></span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <!-- Date Navigator & Range Filter Form -->
            <div class="flex flex-col sm:flex-row flex-wrap items-start sm:items-center gap-2.5 bg-white p-2 rounded-2xl border border-slate-200 shadow-sm">
                
                <!-- Single Day Shortcuts -->
                <div class="flex items-center gap-1">
                    <a href="?date=<?= $prevDate ?>&type=<?= $dateType ?>" 
                       class="px-2.5 py-1.5 rounded-xl text-xs font-bold text-slate-600 hover:bg-slate-100 transition-colors flex items-center gap-1">
                        <span class="material-icons-round text-base">chevron_left</span> Prev
                    </a>

                    <a href="?date=<?= date('Y-m-d') ?>&type=<?= $dateType ?>" 
                       class="px-2.5 py-1.5 rounded-xl text-xs font-bold transition-all <?= $isToday ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100' ?>">
                        Today
                    </a>

                    <a href="?date=<?= $nextDate ?>&type=<?= $dateType ?>" 
                       class="px-2.5 py-1.5 rounded-xl text-xs font-bold text-slate-600 hover:bg-slate-100 transition-colors flex items-center gap-1">
                        Next <span class="material-icons-round text-base">chevron_right</span>
                    </a>
                </div>

                <!-- Custom Date Range Form (From Date - To Date) -->
                <form method="GET" class="flex flex-wrap items-center gap-1.5 border-t sm:border-t-0 sm:border-l border-slate-200 pt-2 sm:pt-0 sm:pl-2 m-0">
                    <input type="hidden" name="type" value="<?= htmlspecialchars($dateType) ?>">
                    
                    <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider flex items-center gap-0.5">
                        <span class="material-icons-round text-xs">date_range</span> Range:
                    </span>
                    <input type="date" name="from_date" value="<?= htmlspecialchars($fromDate) ?>" 
                           placeholder="From"
                           class="px-2.5 py-1 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-700 outline-none focus:ring-1 focus:ring-indigo-500 cursor-pointer">
                    <span class="text-xs text-slate-400">to</span>
                    <input type="date" name="to_date" value="<?= htmlspecialchars($toDate) ?>" 
                           placeholder="To"
                           class="px-2.5 py-1 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-700 outline-none focus:ring-1 focus:ring-indigo-500 cursor-pointer">
                    
                    <button type="submit" class="bg-indigo-600 hover:bg-slate-900 text-white px-3 py-1 rounded-xl text-xs font-bold transition-all shadow-xs">
                        Filter
                    </button>

                    <?php if($hasRange): ?>
                        <a href="?date=<?= date('Y-m-d') ?>&type=<?= $dateType ?>" class="text-xs font-bold text-rose-600 hover:text-rose-800 px-1.5" title="Clear Date Range">
                            Reset
                        </a>
                    <?php endif; ?>
                </form>

                <!-- Filter Mode Toggle (Assigned vs Deadline) -->
                <div class="border-t sm:border-t-0 sm:border-l border-slate-200 pt-2 sm:pt-0 sm:pl-2 flex items-center gap-1.5 w-full sm:w-auto">
                    <span class="text-[10px] font-black uppercase text-slate-400 tracking-wider hidden lg:inline">Filter By:</span>
                    <a href="?<?= $hasRange ? "from_date={$fromDate}&to_date={$toDate}" : "date={$selectedDate}" ?>&type=created"
                       class="px-3 py-1.5 rounded-xl text-xs font-bold flex items-center gap-1.5 transition-all <?= $dateType === 'created' ? 'bg-indigo-600 text-white shadow-sm ring-2 ring-indigo-200' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                        <span class="material-icons-round text-sm">assignment</span> Created / Assigned
                    </a>
                    <a href="?<?= $hasRange ? "from_date={$fromDate}&to_date={$toDate}" : "date={$selectedDate}" ?>&type=deadline"
                       class="px-3 py-1.5 rounded-xl text-xs font-bold flex items-center gap-1.5 transition-all <?= $dateType === 'deadline' ? 'bg-rose-600 text-white shadow-sm ring-2 ring-rose-200' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                        <span class="material-icons-round text-sm">event</span> Deadline
                    </a>
                </div>
            </div>
        </div>

        <!-- Metric Cards -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3.5 mb-7">
            <div class="stat-card surface p-4 border-l-4 border-l-indigo-500">
                <span class="text-[10px] font-black uppercase tracking-widest text-slate-400">Total <?= $dateType === 'deadline' ? 'Deliveries' : 'Assigned' ?></span>
                <h3 class="text-2xl font-extrabold text-slate-800 mt-1"><?= $totalDayTasks ?></h3>
                <p class="text-[11px] text-slate-400 font-semibold mt-0.5"><?= $dateType === 'deadline' ? 'Due on this date' : 'Booked on this date' ?></p>
            </div>

            <div class="stat-card surface p-4 border-l-4 border-l-sky-400">
                <span class="text-[10px] font-black uppercase tracking-widest text-sky-500">New / Unassigned</span>
                <h3 class="text-2xl font-extrabold text-slate-800 mt-1"><?= $dayNew ?></h3>
                <p class="text-[11px] text-slate-400 font-semibold mt-0.5">In OM Queue</p>
            </div>

            <div class="stat-card surface p-4 border-l-4 border-l-amber-500">
                <span class="text-[10px] font-black uppercase tracking-widest text-amber-500">In Pipeline</span>
                <h3 class="text-2xl font-extrabold text-slate-800 mt-1"><?= $dayWorking ?></h3>
                <p class="text-[11px] text-slate-400 font-semibold mt-0.5">Assigned / QC</p>
            </div>

            <div class="stat-card surface p-4 border-l-4 border-l-emerald-500">
                <span class="text-[10px] font-black uppercase tracking-widest text-emerald-500">Completed</span>
                <h3 class="text-2xl font-extrabold text-emerald-600 mt-1"><?= $dayCompleted ?></h3>
                <p class="text-[11px] text-slate-400 font-semibold mt-0.5">Delivered</p>
            </div>

            <div class="stat-card surface p-4 bg-gradient-to-br from-slate-900 to-indigo-950 text-white border-none shadow-md">
                <span class="text-[10px] font-black uppercase tracking-widest text-amber-400">Total Volume</span>
                <h3 class="text-2xl font-extrabold text-white mt-1"><?= number_format($dayWords) ?></h3>
                <p class="text-[10px] text-slate-300 font-bold mt-0.5"><?= $dayUrgent > 0 ? "⚠️ {$dayUrgent} Urgent" : "Words volume" ?></p>
            </div>
        </div>

        <!-- Task Table -->
        <div class="surface overflow-hidden">
            <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                <div class="flex items-center gap-2">
                    <span class="material-icons-round text-amber-500 text-lg">format_list_bulleted</span>
                    <h3 class="font-bold text-slate-800 text-sm">
                        <?php if ($hasRange): ?>
                            <?= $dateType === 'deadline' ? 'Tasks Delivering Between ' : 'Tasks Created/Assigned Between ' ?>
                            <?= date('d M Y', strtotime($fromDate)) ?> - <?= date('d M Y', strtotime($toDate)) ?>
                        <?php else: ?>
                            <?= $dateType === 'deadline' ? 'Tasks Delivering on ' : 'Tasks Assigned on ' ?>
                            <?= date('d M Y', strtotime($selectedDate)) ?>
                        <?php endif; ?>
                    </h3>
                </div>
                <span class="text-xs text-slate-400 font-bold"><?= count($tasks) ?> record<?= count($tasks) === 1 ? '' : 's' ?></span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="text-[11px] font-extrabold uppercase tracking-wider text-slate-400 bg-slate-50 border-b border-slate-100">
                            <th class="px-4 py-4 text-center w-16">Delivered</th>
                            <th class="px-6 py-4">Task Key & Name</th>
                            <th class="px-6 py-4">Client / University</th>
                            <th class="px-6 py-4">Words</th>
                            <th class="px-6 py-4">Assigned & Delivery (Deadline)</th>
                            <th class="px-6 py-4 text-center">Drive Subfolders</th>
                            <th class="px-6 py-4 text-center">Status</th>
                            <th class="px-6 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-xs font-medium">
                        <?php if (count($tasks) > 0): ?>
                            <?php foreach ($tasks as $t): 
                                $clientLink = getDriveClientLink($t);
                                $opsLink    = getDriveOperationsLink($t);
                                $canDelete  = empty($t['assigned_to']) && $t['current_stage'] === 'operations_manager' && $t['status'] === 'new';
                                $assignedDt = $t['task_created_at'] ?? $t['created_at'];
                                $isTodayAssigned = (!empty($assignedDt) && date('Y-m-d', strtotime($assignedDt)) === date('Y-m-d'));
                                $isTodayDeadline = (!empty($t['deadline']) && date('Y-m-d', strtotime($t['deadline'])) === date('Y-m-d'));
                                $isDelivered = !empty($t['delivered_to_client']) && (int)$t['delivered_to_client'] === 1;
                            ?>
                            <tr class="hover:bg-slate-50/70 transition-colors <?= $isDelivered ? 'row-delivered' : '' ?>"
                                data-task-id="<?= $t['id'] ?>"
                                data-delivered="<?= $isDelivered ? '1' : '0' ?>">
                                
                                <!-- Mark as Delivered to Client Checkbox -->
                                <td class="px-4 py-4 text-center align-middle">
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

                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <span class="font-bold text-slate-800 text-sm tracking-tight">
                                            <?= htmlspecialchars($t['task_key']) ?>
                                        </span>
                                        <?php if (($t['urgent'] ?? '') === 'yes'): ?>
                                        <span class="px-1.5 py-0.5 bg-rose-100 text-rose-700 text-[9px] font-black rounded uppercase">Urgent</span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-[11px] text-slate-500 font-semibold truncate max-w-xs mt-0.5">
                                        <?= htmlspecialchars($t['task_name']) ?>
                                    </p>
                                </td>

                                <td class="px-6 py-4">
                                    <span class="font-bold text-slate-700"><?= htmlspecialchars($t['client_name'] ?? 'N/A') ?></span>
                                    <span class="block text-[10px] text-slate-400"><?= htmlspecialchars($t['university'] ?? 'N/A') ?></span>
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

                                <td class="px-6 py-4 text-center">
                                    <div class="inline-flex items-center gap-1.5 flex-wrap justify-center">
                                        <?php if (!empty($clientLink)): ?>
                                        <a href="<?= htmlspecialchars($clientLink) ?>" target="_blank"
                                           class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-blue-50 text-blue-700 hover:bg-blue-600 hover:text-white rounded-lg text-[10px] font-bold transition-all"
                                           title="Direct Client Files Subfolder">
                                            <span class="material-icons-round text-sm">folder_open</span> Client
                                        </a>
                                        <?php elseif(!empty($t['file_path']) && file_exists('../../' . $t['file_path'])): ?>
                                        <a href="../../<?= htmlspecialchars($t['file_path']) ?>" download
                                           class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-blue-50 text-blue-700 hover:bg-blue-600 hover:text-white rounded-lg text-[10px] font-bold transition-all"
                                           title="Download Client Brief Package">
                                            <span class="material-icons-round text-sm">download</span> Client
                                        </a>
                                        <?php endif; ?>

                                        <?php if (!empty($opsLink)): ?>
                                        <a href="<?= htmlspecialchars($opsLink) ?>" target="_blank"
                                           class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-emerald-50 text-emerald-700 hover:bg-emerald-600 hover:text-white rounded-lg text-[10px] font-bold transition-all"
                                           title="Direct Operations Files Subfolder">
                                            <span class="material-icons-round text-sm">inventory_2</span> Ops
                                        </a>
                                        <?php elseif(!empty($t['team_file']) && file_exists('../../' . $t['team_file'])): ?>
                                        <a href="../../<?= htmlspecialchars($t['team_file']) ?>" download
                                           class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-emerald-50 text-emerald-700 hover:bg-emerald-600 hover:text-white rounded-lg text-[10px] font-bold transition-all"
                                           title="Download Operations Solution Package">
                                            <span class="material-icons-round text-sm">download</span> Ops
                                        </a>
                                        <?php elseif(!empty($t['team_file']) && strpos($t['team_file'], 'http') === 0): ?>
                                        <a href="<?= htmlspecialchars($t['team_file']) ?>" target="_blank"
                                           class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-emerald-50 text-emerald-700 hover:bg-emerald-600 hover:text-white rounded-lg text-[10px] font-bold transition-all"
                                           title="Open Operations Solution">
                                            <span class="material-icons-round text-sm">inventory_2</span> Ops
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td class="px-6 py-4 text-center">
                                    <span class="px-2.5 py-1 border text-[10px] font-bold rounded-lg uppercase tracking-wider <?= getBdeStatusBadge($t['status']) ?>">
                                        <?= str_replace('_', ' ', $t['status']) ?>
                                    </span>
                                </td>

                                <td class="px-6 py-4 text-right">
                                    <div class="inline-flex items-center gap-1">
                                        <a href="preview_task.php?id=<?= $t['id'] ?>" 
                                           class="p-1.5 text-slate-400 hover:text-indigo-600 rounded-lg transition-colors" title="Preview Task">
                                            <span class="material-icons-round text-base">visibility</span>
                                        </a>

                                        <?php if ($canDelete): ?>
                                        <button onclick="confirmBdeDelete(<?= $t['id'] ?>, '<?= htmlspecialchars($t['task_key'], ENT_QUOTES) ?>')"
                                                class="p-1.5 text-slate-400 hover:text-rose-600 rounded-lg transition-colors" title="Delete Unassigned Task">
                                            <span class="material-icons-round text-base">delete</span>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center py-16">
                                    <div class="flex flex-col items-center justify-center">
                                        <div class="w-16 h-16 rounded-2xl bg-slate-50 border border-slate-100 flex items-center justify-center text-slate-300 mb-3">
                                            <span class="material-icons-round text-3xl">event_busy</span>
                                        </div>
                                        <h4 class="text-sm font-bold text-slate-700">
                                            <?= $hasRange ? "No tasks found between " . date('d M Y', strtotime($fromDate)) . " and " . date('d M Y', strtotime($toDate)) : "No tasks created on " . date('d M Y', strtotime($selectedDate)) ?>
                                        </h4>
                                        <p class="text-xs text-slate-400 mt-1">Ready to book a new task?</p>
                                        <a href="create_task.php" class="mt-4 px-4 py-2 bg-[#eba940] hover:bg-amber-500 text-slate-900 rounded-xl text-xs font-bold transition-colors shadow-sm">
                                            + Create New Task
                                        </a>
                                    </div>
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

<script>
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
            // Revert state on error
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
            const Toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 2000,
                timerProgressBar: false
            });
            Toast.fire({
                icon: 'success',
                title: isChecked ? 'Marked as Delivered to Client' : 'Marked as Pending'
            });
        }
    })
    .catch(err => {
        checkbox.disabled = false;
        checkbox.checked = !isChecked;
        Swal.fire({
            icon: 'error',
            title: 'Network Error',
            text: 'Connection failed. Please retry.',
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000
        });
    });
}

function confirmBdeDelete(taskId, taskKey) {
    Swal.fire({
        title: 'Delete Task?',
        html: `Are you sure you want to delete <strong>${taskKey}</strong>?<br><span class="text-xs text-slate-500">Since Operations Manager has not assigned it, you can safely remove it.</span>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e11d48',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, Delete'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = `delete_task.php?id=${taskId}`;
        }
    });
}
</script>

</body>
</html>
