<?php
require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole('operations_team');

require_once "../../app/config/database.php";
require_once "../../bootstrap/app.php";
$db = (new Database())->connect();
$user = $_SESSION['user_id'];

/* FILTERS - Securely Binding */
$where = "WHERE assigned_to = :user";
$params = [':user' => $user];

$search = trim($_GET['search'] ?? '');
if(!empty($search)){
    $where .= " AND (task_key LIKE :search_key OR task_name LIKE :search_name OR client_name LIKE :search_client)";
    $params[':search_key'] = "%{$search}%";
    $params[':search_name'] = "%{$search}%";
    $params[':search_client'] = "%{$search}%";
}

$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
if(!empty($from) && !empty($to)){
    $where .= " AND DATE(task_created_at) BETWEEN :from AND :to";
    $params[':from'] = $from;
    $params[':to'] = $to;
} elseif(!empty($from)){
    $where .= " AND DATE(task_created_at) >= :from";
    $params[':from'] = $from;
} elseif(!empty($to)){
    $where .= " AND DATE(task_created_at) <= :to";
    $params[':to'] = $to;
}

/* STATS QUERIES */
// 1. Lifetime effort
$stmtTotal = $db->prepare("SELECT SUM(word_count) FROM tasks WHERE assigned_to = :user");
$stmtTotal->execute([':user' => $user]);
$totalWords = (int)$stmtTotal->fetchColumn();

// 2. Completed count
$stmtComp = $db->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to = :user AND status = 'completed'");
$stmtComp->execute([':user' => $user]);
$completed = (int)$stmtComp->fetchColumn();

// 3. Rework count
$stmtRew = $db->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to = :user AND status = 'rework'");
$stmtRew->execute([':user' => $user]);
$rework = (int)$stmtRew->fetchColumn();

// 4. Monthly Word Count (Current Month)
$stmtMonth = $db->prepare("
    SELECT SUM(word_count) 
    FROM tasks 
    WHERE assigned_to = :user 
      AND (
          (task_created_at IS NOT NULL AND MONTH(task_created_at) = MONTH(CURRENT_DATE()) AND YEAR(task_created_at) = YEAR(CURRENT_DATE()))
          OR (task_created_at IS NULL AND MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE()))
      )
");
$stmtMonth->execute([':user' => $user]);
$monthlyWords = (int)$stmtMonth->fetchColumn();

/* PAGINATION SETUP (10 tasks per page) */
$perPage = 10;
$stmtCount = $db->prepare("SELECT COUNT(*) FROM tasks $where");
$stmtCount->execute($params);
$totalRecords = (int)$stmtCount->fetchColumn();
$totalPages = max(1, (int)ceil($totalRecords / $perPage));

$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if($currentPage < 1) $currentPage = 1;
if($currentPage > $totalPages) $currentPage = $totalPages;

$offset = ($currentPage - 1) * $perPage;

/* FETCH PAGINATED TASKS */
$querySql = "SELECT * FROM tasks $where ORDER BY id DESC LIMIT :limit OFFSET :offset";
$stmt = $db->prepare($querySql);
foreach($params as $key => $val){
    $stmt->bindValue($key, $val);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper for pagination links
function getPaginationUrl($pageNum) {
    $queryParams = $_GET;
    $queryParams['page'] = $pageNum;
    return 'work_history.php?' . http_build_query($queryParams);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Work History | Expert Archive</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
        .stat-card { transition: all 0.3s ease; }
        .stat-card:hover { transform: translateY(-4px); }
        .resource-btn { transition: all 0.2s ease; }
        .resource-btn:hover { transform: scale(1.04); }
        .details-row { transition: all 0.25s ease-in-out; }
        .date-chip { transition: all 0.15s ease; }
        .date-chip:hover { transform: translateY(-1px); }
    </style>
</head>

<body class="text-slate-900">

<div class="flex min-h-screen">
    <?php include "partials/sidebar.php"; ?>

    <main class="flex-1 p-6 lg:p-10 min-w-0">
        
        <!-- HEADER & FILTER BAR -->
        <div class="max-w-7xl mx-auto mb-8">
            <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-6">
                <div>
                    <h1 class="text-3xl font-black text-slate-900 tracking-tight flex items-center gap-3">
                        <div class="p-3 bg-indigo-600 rounded-2xl shadow-lg shadow-indigo-100">
                            <span class="material-icons-round text-white">history_edu</span>
                        </div>
                        Work History Archive
                    </h1>
                    <p class="text-slate-500 mt-2 font-medium italic">Complete log of assignments, manager notes, QC feedback, and revisions.</p>
                </div>

                <!-- Comprehensive Filter Form -->
                <form id="filterForm" method="GET" class="flex flex-wrap items-center gap-2.5 bg-white p-2.5 rounded-2xl shadow-sm border border-slate-200 w-full lg:w-auto">
                    <!-- Search Input -->
                    <div class="relative flex-1 sm:flex-none">
                        <span class="material-icons-round absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">search</span>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Task Key / Title / Client..." 
                               class="pl-9 pr-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold focus:ring-2 focus:ring-indigo-500 w-full sm:w-48 transition-all">
                    </div>
                    
                    <!-- Date Range Input -->
                    <div class="flex items-center gap-1.5 bg-slate-50 px-2.5 py-1.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-600">
                        <span class="material-icons-round text-slate-400 text-sm">date_range</span>
                        <input type="date" id="dateFrom" name="from" value="<?= htmlspecialchars($from) ?>" class="bg-transparent border-none text-xs font-bold text-slate-700 outline-none p-0">
                        <span class="text-slate-300 font-normal">to</span>
                        <input type="date" id="dateTo" name="to" value="<?= htmlspecialchars($to) ?>" class="bg-transparent border-none text-xs font-bold text-slate-700 outline-none p-0">
                    </div>

                    <button type="submit" class="bg-indigo-600 text-white px-5 py-2 rounded-xl font-bold text-xs hover:bg-slate-900 transition-all flex items-center gap-1.5 shadow-md shadow-indigo-100">
                        Filter <span class="material-icons-round text-xs">tune</span>
                    </button>

                    <?php if(!empty($search) || !empty($from) || !empty($to)): ?>
                        <a href="work_history.php" class="p-2 bg-slate-100 hover:bg-rose-50 hover:text-rose-600 text-slate-500 rounded-xl transition-all" title="Reset Filters">
                            <span class="material-icons-round text-sm">restart_alt</span>
                        </a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Quick Date Filter Chips (Today, Yesterday, Last 3 Days, This Month) -->
            <div class="flex flex-wrap items-center gap-2 mt-4 pt-2">
                <span class="text-[11px] font-extrabold uppercase text-slate-400 tracking-wider flex items-center gap-1 mr-1">
                    <span class="material-icons-round text-xs">flash_on</span> Quick Filters:
                </span>
                
                <button type="button" onclick="setDateRange('today')" 
                        class="date-chip text-xs font-bold px-3 py-1 rounded-lg border <?= ($from === date('Y-m-d') && $to === date('Y-m-d')) ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-slate-600 border-slate-200 hover:border-indigo-400' ?>">
                    Today
                </button>
                <button type="button" onclick="setDateRange('yesterday')" 
                        class="date-chip text-xs font-bold px-3 py-1 rounded-lg border <?= ($from === date('Y-m-d', strtotime('-1 day')) && $to === date('Y-m-d', strtotime('-1 day'))) ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-slate-600 border-slate-200 hover:border-indigo-400' ?>">
                    Yesterday
                </button>
                <button type="button" onclick="setDateRange('3days')" 
                        class="date-chip text-xs font-bold px-3 py-1 rounded-lg border <?= ($from === date('Y-m-d', strtotime('-2 days')) && $to === date('Y-m-d')) ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-slate-600 border-slate-200 hover:border-indigo-400' ?>">
                    Last 3 Days
                </button>
                <button type="button" onclick="setDateRange('7days')" 
                        class="date-chip text-xs font-bold px-3 py-1 rounded-lg border <?= ($from === date('Y-m-d', strtotime('-6 days')) && $to === date('Y-m-d')) ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-slate-600 border-slate-200 hover:border-indigo-400' ?>">
                    Last 7 Days
                </button>
                <button type="button" onclick="setDateRange('month')" 
                        class="date-chip text-xs font-bold px-3 py-1 rounded-lg border <?= ($from === date('Y-m-01') && $to === date('Y-m-t')) ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-slate-600 border-slate-200 hover:border-indigo-400' ?>">
                    This Month (<?= date('M') ?>)
                </button>
            </div>
        </div>

        <!-- STATS CARDS GRID (Includes Monthly Word Count) -->
        <div class="max-w-7xl mx-auto grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
            
            <!-- 1. MONTHLY WORD COUNT -->
            <div class="stat-card bg-white p-5 rounded-[2rem] border border-slate-100 flex items-center gap-4 shadow-sm border-l-4 border-l-sky-500">
                <div class="w-12 h-12 bg-sky-50 text-sky-600 rounded-2xl flex items-center justify-center flex-shrink-0">
                    <span class="material-icons-round text-2xl">date_range</span>
                </div>
                <div class="min-w-0">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest truncate"><?= date('F') ?> Words</p>
                    <p class="text-2xl font-black text-slate-800 tracking-tight"><?= number_format($monthlyWords) ?> <span class="text-xs font-semibold text-slate-400">Words</span></p>
                </div>
            </div>

            <!-- 2. LIFETIME WORD COUNT -->
            <div class="stat-card bg-white p-5 rounded-[2rem] border border-slate-100 flex items-center gap-4 shadow-sm border-l-4 border-l-indigo-500">
                <div class="w-12 h-12 bg-indigo-50 text-indigo-600 rounded-2xl flex items-center justify-center flex-shrink-0">
                    <span class="material-icons-round text-2xl">analytics</span>
                </div>
                <div class="min-w-0">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest truncate">Lifetime Effort</p>
                    <p class="text-2xl font-black text-slate-800 tracking-tight"><?= number_format($totalWords) ?> <span class="text-xs font-semibold text-slate-400">Words</span></p>
                </div>
            </div>

            <!-- 3. COMPLETED TASKS -->
            <div class="stat-card bg-white p-5 rounded-[2rem] border border-slate-100 flex items-center gap-4 shadow-sm border-l-4 border-l-emerald-500">
                <div class="w-12 h-12 bg-emerald-50 text-emerald-600 rounded-2xl flex items-center justify-center flex-shrink-0">
                    <span class="material-icons-round text-2xl">verified</span>
                </div>
                <div class="min-w-0">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest truncate">Success Deliveries</p>
                    <p class="text-2xl font-black text-slate-800 tracking-tight"><?= number_format($completed) ?> <span class="text-xs font-semibold text-slate-400">Completed</span></p>
                </div>
            </div>

            <!-- 4. REWORK HITS -->
            <div class="stat-card bg-white p-5 rounded-[2rem] border border-slate-100 flex items-center gap-4 shadow-sm border-l-4 border-l-amber-500">
                <div class="w-12 h-12 bg-amber-50 text-amber-600 rounded-2xl flex items-center justify-center flex-shrink-0">
                    <span class="material-icons-round text-2xl">published_with_changes</span>
                </div>
                <div class="min-w-0">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest truncate">Rework Revisions</p>
                    <p class="text-2xl font-black text-slate-800 tracking-tight"><?= number_format($rework) ?> <span class="text-xs font-semibold text-slate-400">Tasks</span></p>
                </div>
            </div>

        </div>

        <!-- TABLE SECTION -->
        <div class="max-w-7xl mx-auto bg-white rounded-[2.5rem] border border-slate-200 shadow-sm overflow-hidden mb-6">
            
            <!-- Table Header Stats Info -->
            <div class="px-8 py-5 bg-slate-50/70 border-b border-slate-100 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2">
                <div class="flex items-center gap-2">
                    <span class="material-icons-round text-slate-400 text-sm">folder</span>
                    <span class="text-xs font-extrabold uppercase text-slate-500 tracking-wider">Assignment History Logs</span>
                    <span class="bg-indigo-100 text-indigo-700 text-[11px] font-black px-2.5 py-0.5 rounded-full">
                        <?= number_format($totalRecords) ?> Total Tasks
                    </span>
                </div>
                <span class="text-xs text-slate-400 font-medium">
                    Page <?= $currentPage ?> of <?= $totalPages ?> (Showing <?= count($tasks) ?> tasks)
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-slate-50/40 border-b border-slate-100 text-[11px] font-black text-slate-400 uppercase tracking-widest">
                            <th class="px-6 py-4">Task Info</th>
                            <th class="px-5 py-4">Word Count</th>
                            <th class="px-6 py-4">Workflow Notes & Feedback</th>
                            <th class="px-6 py-4 text-center">Cloud Assets</th>
                            <th class="px-6 py-4 text-right">Status / Stage</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach($tasks as $t): 
                            $hasInstructions = !empty($t['instructions']);
                            $hasOmNotes      = !empty($t['om_notes']);
                            $hasQcNotes      = !empty($t['qc_notes']);
                            $hasTeamNotes    = !empty($t['team_notes']);
                            $hasFeedback     = !empty($t['feedback']);
                            $notesCount      = ($hasInstructions?1:0) + ($hasOmNotes?1:0) + ($hasQcNotes?1:0) + ($hasTeamNotes?1:0) + ($hasFeedback?1:0);
                            $uniqueId        = 'details_' . $t['id'];
                        ?>
                        <tr class="hover:bg-slate-50/70 transition-all duration-150">
                            
                            <!-- Task Identity Column -->
                            <td class="px-6 py-5 align-top">
                                <div class="space-y-1">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-extrabold text-slate-800 tracking-tight"><?= htmlspecialchars($t['task_key']) ?></span>
                                        <?php if(isset($t['urgent']) && $t['urgent'] === 'yes'): ?>
                                            <span class="px-1.5 py-0.5 bg-rose-100 text-rose-700 text-[9px] font-extrabold uppercase rounded">Urgent</span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-xs font-semibold text-slate-700 line-clamp-1 max-w-xs" title="<?= htmlspecialchars($t['task_name']) ?>">
                                        <?= htmlspecialchars($t['task_name']) ?>
                                    </p>
                                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 pt-0.5 text-[11px] font-bold text-slate-400">
                                        <span class="text-emerald-600 flex items-center gap-1">
                                            <span class="material-icons-round text-xs">person</span>
                                            <?= htmlspecialchars($t['client_name'] ?: 'N/A') ?>
                                        </span>
                                        <span class="flex items-center gap-1">
                                            <span class="material-icons-round text-xs">calendar_today</span>
                                            <?= !empty($t['task_created_at']) ? date("d M Y", strtotime($t['task_created_at'])) : date("d M Y", strtotime($t['created_at'])) ?>
                                        </span>
                                    </div>
                                </div>
                            </td>

                            <!-- Specs (Word Count & Subject) -->
                            <td class="px-5 py-5 align-top">
                                <div class="space-y-0.5">
                                    <div class="flex items-baseline gap-1">
                                        <span class="text-sm font-black text-slate-800"><?= number_format($t['word_count']) ?></span>
                                        <span class="text-[10px] font-bold text-slate-400 uppercase">Words</span>
                                    </div>
                                    <?php if(!empty($t['subject'])): ?>
                                        <span class="inline-block text-[10px] font-semibold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded-md truncate max-w-[130px]" title="<?= htmlspecialchars($t['subject']) ?>">
                                            <?= htmlspecialchars($t['subject']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <!-- Notes, Feedback & Reason Summary -->
                            <td class="px-6 py-5 align-top max-w-md">
                                <div class="space-y-2">
                                    
                                    <!-- Primary visible badge preview -->
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <?php if($hasOmNotes): ?>
                                            <span class="px-2 py-0.5 bg-blue-50 text-blue-700 border border-blue-200 rounded text-[10px] font-bold flex items-center gap-1" title="Manager Note available">
                                                <span class="material-icons-round text-[11px]">sticky_note_2</span> Manager Note
                                            </span>
                                        <?php endif; ?>
                                        <?php if($hasQcNotes): ?>
                                            <span class="px-2 py-0.5 bg-amber-50 text-amber-700 border border-amber-200 rounded text-[10px] font-bold flex items-center gap-1" title="QC Notes / Feedback available">
                                                <span class="material-icons-round text-[11px]">fact_check</span> QC Note
                                            </span>
                                        <?php endif; ?>
                                        <?php if($hasFeedback): ?>
                                            <span class="px-2 py-0.5 bg-purple-50 text-purple-700 border border-purple-200 rounded text-[10px] font-bold flex items-center gap-1" title="Client Feedback available">
                                                <span class="material-icons-round text-[11px]">rate_review</span> Feedback
                                            </span>
                                        <?php endif; ?>
                                        <?php if($hasInstructions): ?>
                                            <span class="px-2 py-0.5 bg-slate-100 text-slate-600 rounded text-[10px] font-bold flex items-center gap-1">
                                                <span class="material-icons-round text-[11px]">description</span> Brief
                                            </span>
                                        <?php endif; ?>

                                        <?php if($notesCount === 0): ?>
                                            <span class="text-[11px] text-slate-400 italic">No notes recorded</span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Recent Snippet Preview -->
                                    <?php 
                                    $previewSnippet = '';
                                    $snippetLabel = '';
                                    if ($hasQcNotes) {
                                        $snippetLabel = 'QC Feedback:';
                                        $previewSnippet = $t['qc_notes'];
                                    } elseif ($hasOmNotes) {
                                        $snippetLabel = 'Manager Note:';
                                        $previewSnippet = $t['om_notes'];
                                    } elseif ($hasFeedback) {
                                        $snippetLabel = 'Client Feedback:';
                                        $previewSnippet = $t['feedback'];
                                    } elseif ($hasInstructions) {
                                        $snippetLabel = 'Brief:';
                                        $previewSnippet = $t['instructions'];
                                    }
                                    ?>
                                    <?php if(!empty($previewSnippet)): ?>
                                        <p class="text-xs text-slate-600 line-clamp-2 italic bg-slate-50/80 p-2 rounded-lg border border-slate-100">
                                            <strong class="font-bold text-slate-700 not-italic"><?= $snippetLabel ?></strong> <?= htmlspecialchars($previewSnippet) ?>
                                        </p>
                                    <?php endif; ?>

                                    <!-- Toggle button to open expandable full notes drawer -->
                                    <?php if($notesCount > 0): ?>
                                        <button type="button" onclick="toggleDetails('<?= $uniqueId ?>')" 
                                                class="text-[11px] font-bold text-indigo-600 hover:text-indigo-800 flex items-center gap-1 transition">
                                            <span id="btn_icon_<?= $uniqueId ?>" class="material-icons-round text-xs">unfold_more</span>
                                            <span id="btn_text_<?= $uniqueId ?>">View All Notes & Feedback (<?= $notesCount ?>)</span>
                                        </button>
                                    <?php endif; ?>

                                </div>
                            </td>

                            <!-- Cloud Assets / Google Drive Links -->
                            <td class="px-6 py-5 align-top text-center">
                                <div class="flex flex-col items-center gap-1.5">
                                    <?php 
                                    $clientDrive = getDriveClientLink($t);
                                    $opsDrive    = getDriveOperationsLink($t);
                                    ?>
                                    <?php if(!empty($clientDrive) || !empty($opsDrive)): ?>
                                        <?php if(!empty($clientDrive)): ?>
                                            <a href="<?= htmlspecialchars($clientDrive) ?>" target="_blank"
                                               class="resource-btn w-full max-w-[130px] flex items-center justify-center gap-1.5 bg-indigo-50 text-indigo-700 px-2.5 py-1.5 rounded-lg border border-indigo-100 hover:bg-indigo-600 hover:text-white transition-all shadow-xs"
                                               title="Open Client Folder in Google Drive">
                                                <span class="material-icons-round text-xs">folder_open</span>
                                                <span class="text-[10px] font-extrabold uppercase">Client Files</span>
                                            </a>
                                        <?php endif; ?>

                                        <?php if(!empty($opsDrive)): ?>
                                            <a href="<?= htmlspecialchars($opsDrive) ?>" target="_blank"
                                               class="resource-btn w-full max-w-[130px] flex items-center justify-center gap-1.5 bg-emerald-50 text-emerald-700 px-2.5 py-1.5 rounded-lg border border-emerald-100 hover:bg-emerald-600 hover:text-white transition-all shadow-xs"
                                               title="Open Uploaded Work in Google Drive">
                                                <span class="material-icons-round text-xs">folder_shared</span>
                                                <span class="text-[10px] font-extrabold uppercase">Work Files</span>
                                            </a>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-[10px] font-bold text-slate-300 uppercase italic">No Drive Synced</span>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <!-- Status & Actions Column -->
                            <td class="px-6 py-5 align-top text-right">
                                <?php if($t['current_stage'] === 'qc'): ?>
                                    <div class="flex flex-col items-end gap-1.5">
                                        <span class="px-3 py-1 bg-amber-50 text-amber-800 rounded-lg text-[10px] font-black uppercase tracking-wider border border-amber-200">
                                            Under QC Review
                                        </span>
                                        <button type="button" onclick="confirmRecall(<?= $t['id'] ?>, '<?= htmlspecialchars($t['task_key']) ?>')"
                                                class="px-2.5 py-1 bg-rose-50 text-rose-600 hover:bg-rose-600 hover:text-white rounded-lg text-[10px] font-black uppercase tracking-wider border border-rose-100 transition shadow-xs flex items-center gap-1"
                                                title="Recall & Delete Submission to re-upload">
                                            <span class="material-icons-round text-xs">delete_sweep</span> Recall
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <?php 
                                        $statusMap = [
                                            'completed' => ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-700', 'border' => 'border-emerald-200', 'label' => 'Delivered'],
                                            'rework'    => ['bg' => 'bg-rose-50',    'text' => 'text-rose-700',    'border' => 'border-rose-200',    'label' => 'Rework / Revision'],
                                            'assigned'  => ['bg' => 'bg-indigo-50',  'text' => 'text-indigo-700',  'border' => 'border-indigo-200',  'label' => 'Assigned'],
                                            'in_progress'=>['bg' => 'bg-sky-50',     'text' => 'text-sky-700',     'border' => 'border-sky-200',     'label' => 'In Progress']
                                        ];
                                        $st = $statusMap[$t['status']] ?? ['bg' => 'bg-slate-50', 'text' => 'text-slate-700', 'border' => 'border-slate-200', 'label' => ucfirst($t['status'])];
                                    ?>
                                    <span class="inline-block px-3 py-1.5 <?= $st['bg'] ?> <?= $st['text'] ?> <?= $st['border'] ?> border rounded-lg text-[10px] font-black uppercase tracking-wider">
                                        <?= $st['label'] ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                        </tr>

                        <!-- EXPANDABLE FULL DETAILS ROW FOR NOTES / FEEDBACK -->
                        <?php if($notesCount > 0): ?>
                        <tr id="<?= $uniqueId ?>" class="hidden bg-slate-50/50 border-b border-slate-100">
                            <td colspan="5" class="px-8 py-5">
                                <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-4">
                                    
                                    <div class="flex items-center justify-between border-b border-slate-100 pb-2">
                                        <h4 class="text-xs font-black text-slate-800 uppercase tracking-wider flex items-center gap-2">
                                            <span class="material-icons-round text-indigo-600 text-sm">history_edu</span>
                                            Complete Notes & Revisions History for <?= htmlspecialchars($t['task_key']) ?>
                                        </h4>
                                        <button type="button" onclick="toggleDetails('<?= $uniqueId ?>')" class="text-slate-400 hover:text-slate-700 text-xs font-bold">
                                            Close ✕
                                        </button>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        
                                        <!-- Client Instructions / Brief -->
                                        <?php if($hasInstructions): ?>
                                        <div class="p-3.5 rounded-xl bg-slate-50 border border-slate-100 space-y-1">
                                            <div class="flex items-center gap-1.5 text-[10px] font-black text-slate-500 uppercase tracking-widest">
                                                <span class="material-icons-round text-xs text-slate-400">description</span> Original Client Instructions
                                            </div>
                                            <p class="text-xs text-slate-700 leading-relaxed whitespace-pre-wrap font-medium"><?= htmlspecialchars($t['instructions']) ?></p>
                                        </div>
                                        <?php endif; ?>

                                        <!-- Operations Manager Notes -->
                                        <?php if($hasOmNotes): ?>
                                        <div class="p-3.5 rounded-xl bg-blue-50/70 border border-blue-100 space-y-1">
                                            <div class="flex items-center gap-1.5 text-[10px] font-black text-blue-700 uppercase tracking-widest">
                                                <span class="material-icons-round text-xs text-blue-500">sticky_note_2</span> Operations Manager Note
                                            </div>
                                            <p class="text-xs text-slate-800 leading-relaxed whitespace-pre-wrap font-semibold"><?= htmlspecialchars($t['om_notes']) ?></p>
                                        </div>
                                        <?php endif; ?>

                                        <!-- QC Feedback Notes (Reason for rework or approval comments) -->
                                        <?php if($hasQcNotes): ?>
                                        <div class="p-3.5 rounded-xl bg-amber-50/70 border border-amber-200 space-y-1 md:col-span-2">
                                            <div class="flex items-center gap-1.5 text-[10px] font-black text-amber-800 uppercase tracking-widest">
                                                <span class="material-icons-round text-xs text-amber-600">fact_check</span> QC Inspection Feedback / Rework Instructions
                                            </div>
                                            <p class="text-xs text-amber-950 leading-relaxed whitespace-pre-wrap font-bold bg-white/80 p-2.5 rounded-lg border border-amber-200"><?= htmlspecialchars($t['qc_notes']) ?></p>
                                        </div>
                                        <?php endif; ?>

                                        <!-- Operations Team Submitted Notes -->
                                        <?php if($hasTeamNotes): ?>
                                        <div class="p-3.5 rounded-xl bg-emerald-50/70 border border-emerald-100 space-y-1">
                                            <div class="flex items-center gap-1.5 text-[10px] font-black text-emerald-700 uppercase tracking-widest">
                                                <span class="material-icons-round text-xs text-emerald-500">edit_note</span> My Submitted Notes to QC
                                            </div>
                                            <p class="text-xs text-slate-700 leading-relaxed whitespace-pre-wrap"><?= htmlspecialchars($t['team_notes']) ?></p>
                                        </div>
                                        <?php endif; ?>

                                        <!-- Forwarded Client Feedback -->
                                        <?php if($hasFeedback): ?>
                                        <div class="p-3.5 rounded-xl bg-purple-50/70 border border-purple-100 space-y-1 <?= $hasTeamNotes ? '' : 'md:col-span-2' ?>">
                                            <div class="flex items-center gap-1.5 text-[10px] font-black text-purple-700 uppercase tracking-widest">
                                                <span class="material-icons-round text-xs text-purple-500">rate_review</span> Final Client Feedback
                                            </div>
                                            <p class="text-xs text-purple-900 leading-relaxed whitespace-pre-wrap font-semibold"><?= htmlspecialchars($t['feedback']) ?></p>
                                        </div>
                                        <?php endif; ?>

                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>

                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- EMPTY STATE -->
            <?php if(empty($tasks)): ?>
                <div class="p-16 text-center">
                    <div class="w-16 h-16 bg-slate-100 text-slate-400 rounded-2xl flex items-center justify-center mx-auto mb-3">
                        <span class="material-icons-round text-3xl">search_off</span>
                    </div>
                    <p class="text-base font-black text-slate-700">No Assignments Found</p>
                    <p class="text-xs font-semibold text-slate-400 mt-1 max-w-sm mx-auto">There are no work records matching your search or date filters. Try adjusting the filter range.</p>
                    <a href="work_history.php" class="mt-4 inline-block px-4 py-2 bg-indigo-600 text-white rounded-xl text-xs font-bold hover:bg-slate-900 transition">
                        Reset All Filters
                    </a>
                </div>
            <?php endif; ?>

            <!-- PAGINATION BAR (1..2..3.. Page Buttons) -->
            <?php if($totalPages > 1): ?>
            <div class="px-8 py-5 bg-slate-50/80 border-t border-slate-100 flex flex-col sm:flex-row justify-between items-center gap-4">
                <div class="text-xs font-semibold text-slate-500">
                    Showing <span class="font-bold text-slate-800"><?= $offset + 1 ?></span> to <span class="font-bold text-slate-800"><?= min($offset + $perPage, $totalRecords) ?></span> of <span class="font-bold text-slate-800"><?= number_format($totalRecords) ?></span> assignments
                </div>

                <div class="flex items-center gap-1.5">
                    
                    <!-- Prev Button -->
                    <?php if($currentPage > 1): ?>
                        <a href="<?= getPaginationUrl($currentPage - 1) ?>" class="px-3 py-1.5 bg-white border border-slate-200 text-slate-600 hover:bg-indigo-50 hover:text-indigo-600 hover:border-indigo-200 rounded-xl text-xs font-bold transition flex items-center gap-1">
                            <span class="material-icons-round text-xs">arrow_back_ios</span> Prev
                        </a>
                    <?php else: ?>
                        <span class="px-3 py-1.5 bg-slate-100 text-slate-300 border border-slate-200 rounded-xl text-xs font-bold cursor-not-allowed flex items-center gap-1">
                            <span class="material-icons-round text-xs">arrow_back_ios</span> Prev
                        </span>
                    <?php endif; ?>

                    <!-- Page Number Buttons (1, 2, 3...) -->
                    <?php
                    $startPage = max(1, $currentPage - 2);
                    $endPage   = min($totalPages, $currentPage + 2);

                    if($startPage > 1): ?>
                        <a href="<?= getPaginationUrl(1) ?>" class="w-8 h-8 flex items-center justify-center bg-white border border-slate-200 text-slate-600 hover:bg-indigo-50 hover:text-indigo-600 rounded-xl text-xs font-bold transition">1</a>
                        <?php if($startPage > 2): ?>
                            <span class="px-1 text-slate-400 font-bold">...</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for($p = $startPage; $p <= $endPage; $p++): ?>
                        <?php if($p == $currentPage): ?>
                            <span class="w-8 h-8 flex items-center justify-center bg-indigo-600 text-white rounded-xl text-xs font-black shadow-md shadow-indigo-100">
                                <?= $p ?>
                            </span>
                        <?php else: ?>
                            <a href="<?= getPaginationUrl($p) ?>" class="w-8 h-8 flex items-center justify-center bg-white border border-slate-200 text-slate-600 hover:bg-indigo-50 hover:text-indigo-600 rounded-xl text-xs font-bold transition">
                                <?= $p ?>
                            </a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if($endPage < $totalPages): ?>
                        <?php if($endPage < $totalPages - 1): ?>
                            <span class="px-1 text-slate-400 font-bold">...</span>
                        <?php endif; ?>
                        <a href="<?= getPaginationUrl($totalPages) ?>" class="w-8 h-8 flex items-center justify-center bg-white border border-slate-200 text-slate-600 hover:bg-indigo-50 hover:text-indigo-600 rounded-xl text-xs font-bold transition"><?= $totalPages ?></a>
                    <?php endif; ?>

                    <!-- Next Button -->
                    <?php if($currentPage < $totalPages): ?>
                        <a href="<?= getPaginationUrl($currentPage + 1) ?>" class="px-3 py-1.5 bg-white border border-slate-200 text-slate-600 hover:bg-indigo-50 hover:text-indigo-600 hover:border-indigo-200 rounded-xl text-xs font-bold transition flex items-center gap-1">
                            Next <span class="material-icons-round text-xs">arrow_forward_ios</span>
                        </a>
                    <?php else: ?>
                        <span class="px-3 py-1.5 bg-slate-100 text-slate-300 border border-slate-200 rounded-xl text-xs font-bold cursor-not-allowed flex items-center gap-1">
                            Next <span class="material-icons-round text-xs">arrow_forward_ios</span>
                        </span>
                    <?php endif; ?>

                </div>
            </div>
            <?php endif; ?>

        </div>
    </main>
</div>

<script>
// Toggle full notes & details drawer
function toggleDetails(rowId) {
    const row = document.getElementById(rowId);
    const btnIcon = document.getElementById('btn_icon_' + rowId);
    const btnText = document.getElementById('btn_text_' + rowId);
    
    if (row.classList.contains('hidden')) {
        row.classList.remove('hidden');
        if (btnIcon) btnIcon.textContent = 'unfold_less';
        if (btnText) btnText.textContent = 'Hide Notes & Feedback';
    } else {
        row.classList.add('hidden');
        if (btnIcon) btnIcon.textContent = 'unfold_more';
        if (btnText) btnText.textContent = 'View All Notes & Feedback';
    }
}

// Quick Date Filter selection helper
function setDateRange(rangeType) {
    const fromInput = document.getElementById('dateFrom');
    const toInput   = document.getElementById('dateTo');
    const today = new Date();
    
    const formatDate = (date) => {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    };

    if (rangeType === 'today') {
        const todayStr = formatDate(today);
        fromInput.value = todayStr;
        toInput.value   = todayStr;
    } else if (rangeType === 'yesterday') {
        const yest = new Date();
        yest.setDate(today.getDate() - 1);
        const yestStr = formatDate(yest);
        fromInput.value = yestStr;
        toInput.value   = yestStr;
    } else if (rangeType === '3days') {
        const past = new Date();
        past.setDate(today.getDate() - 2);
        fromInput.value = formatDate(past);
        toInput.value   = formatDate(today);
    } else if (rangeType === '7days') {
        const past = new Date();
        past.setDate(today.getDate() - 6);
        fromInput.value = formatDate(past);
        toInput.value   = formatDate(today);
    } else if (rangeType === 'month') {
        const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
        const lastDay  = new Date(today.getFullYear(), today.getMonth() + 1, 0);
        fromInput.value = formatDate(firstDay);
        toInput.value   = formatDate(lastDay);
    }

    document.getElementById('filterForm').submit();
}

function confirmRecall(id, key) {
    Swal.fire({
        title: 'Recall Submission?',
        text: `Are you sure you want to recall ${key}? Your previous submitted files will be permanently deleted from QC and Google Drive, and the task will be moved back to your Active Queue so you can upload fresh files.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e11d48',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, Recall & Re-upload',
        cancelButtonText: 'Cancel',
        customClass: { popup: 'rounded-[2.5rem]' }
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = `recall_task.php?id=${id}`;
        }
    });
}
</script>

<?php if(isset($_GET['msg']) && $_GET['msg'] === 'recalled'): ?>
<script>
    if (window.history.replaceState) {
        const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
        window.history.replaceState({ path: cleanUrl }, '', cleanUrl);
    }
    Swal.fire({
        icon: 'success',
        title: 'Submission Recalled!',
        text: 'Previous files have been removed from QC and Google Drive. The task is back in your Active Tasks Queue ready for re-upload.',
        confirmButtonColor: '#4f46e5',
        customClass: { popup: 'rounded-[2.5rem]' }
    });
</script>
<?php endif; ?>

</body>
</html>