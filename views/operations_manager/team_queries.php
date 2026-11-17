<?php
require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole('operations_manager');

require_once "../../app/config/database.php";
require_once "../../bootstrap/app.php";

date_default_timezone_set('Asia/Kolkata');
$db = (new Database())->connect();

// Ensure task_queries table exists
$db->exec("CREATE TABLE IF NOT EXISTS task_queries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    task_key VARCHAR(100) NOT NULL,
    user_id INT NOT NULL,
    query_text TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$queries = $db->query("
    SELECT q.*, 
           t.task_name, t.client_name, t.subject, t.university, t.deadline, t.word_count, t.status, t.drive_folder_link,
           u.name AS expert_name, u.username AS expert_username
    FROM task_queries q
    JOIN tasks t ON q.task_id = t.id
    JOIN users u ON q.user_id = u.id
    ORDER BY q.id DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Queries | Operations Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
        .query-card { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .query-card:hover { transform: translateY(-3px); box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.08); }
        .custom-shadow { box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .drive-btn { font-family: 'Inter', sans-serif; }
    </style>
</head>

<body class="text-slate-900">

<div class="flex min-h-screen">
    <?php include "partials/sidebar.php"; ?>

    <main class="flex-1 p-6 lg:p-10">
        
        <!-- Header Section -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-10">
            <div>
                <h1 class="text-3xl font-extrabold tracking-tight text-slate-900 flex items-center gap-3">
                    <span class="p-3 bg-amber-500 rounded-2xl shadow-lg shadow-amber-200">
                        <span class="material-icons-round text-white">help_outline</span>
                    </span>
                    Team Queries
                </h1>
                <p class="text-slate-500 font-medium mt-1">Review and address queries raised by experts on assigned workflow.</p>
            </div>
            
            <div class="w-full md:w-80 relative">
                <span class="material-icons-round absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">search</span>
                <input type="text" id="querySearch" placeholder="Search by Task Key, Expert, or Query..." 
                    class="w-full pl-10 pr-4 py-3 bg-white border border-slate-200 rounded-2xl text-sm focus:ring-2 focus:ring-amber-500 outline-none transition-all custom-shadow font-medium">
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-8" id="queryContainer">

            <?php foreach($queries as $q): 
                $statusClass = match($q['status']) {
                    'completed'   => 'bg-emerald-100 text-emerald-700 border-emerald-200',
                    'in_progress' => 'bg-amber-100 text-amber-700 border-amber-200',
                    'rework'      => 'bg-rose-100 text-rose-700 border-rose-200',
                    default       => 'bg-slate-100 text-slate-600 border-slate-200'
                };
            ?>
            
            <div class="bg-white rounded-[2rem] border border-slate-200/80 p-7 query-card custom-shadow relative group">
                
                <!-- Task Key & Expert Info -->
                <div class="flex justify-between items-start mb-5 pb-4 border-b border-slate-100">
                    <div class="space-y-1.5">
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] font-black uppercase tracking-widest text-amber-700 bg-amber-50 px-3 py-1 rounded-xl border border-amber-200/80">
                                <?= htmlspecialchars($q['task_key']) ?>
                            </span>
                            <span class="text-[10px] font-bold px-2.5 py-1 rounded-xl border uppercase tracking-widest <?= $statusClass ?>">
                                <?= str_replace('_', ' ', $q['status']) ?>
                            </span>
                        </div>
                        <h2 class="text-base font-bold text-slate-800 leading-tight">
                            <?= htmlspecialchars($q['task_name']) ?>
                        </h2>
                    </div>

                    <div class="text-right">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Expert Name</p>
                        <div class="flex items-center justify-end gap-2">
                            <span class="text-sm font-extrabold text-slate-800"><?= htmlspecialchars($q['expert_name'] ?: $q['expert_username']) ?></span>
                            <div class="w-8 h-8 rounded-xl bg-indigo-600 text-white flex items-center justify-center text-xs font-black shadow-md shadow-indigo-100">
                                <?= strtoupper(substr($q['expert_name'] ?: $q['expert_username'], 0, 1)) ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Query Timestamp & Project Details -->
                <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
                    <div class="flex items-center gap-1.5 bg-amber-50 text-amber-800 px-3.5 py-1.5 rounded-xl border border-amber-200/80 text-xs font-extrabold">
                        <span class="material-icons-round text-sm text-amber-600">schedule</span>
                        <span><?= date("d M, Y • h:i A", strtotime($q['created_at'])) ?></span>
                    </div>

                    <div class="text-slate-400 flex items-center gap-3 text-xs font-semibold">
                        <span>Client: <strong class="text-slate-700"><?= htmlspecialchars($q['client_name'] ?: 'N/A') ?></strong></span>
                        <span>•</span>
                        <span>Word Count: <strong class="text-slate-700"><?= number_format($q['word_count']) ?></strong></span>
                    </div>
                </div>

                <!-- Query Text Quote Box -->
                <div class="bg-amber-50/60 rounded-2xl p-5 border border-amber-200/70 relative overflow-hidden mb-6">
                    <span class="material-icons-round text-amber-200/80 absolute -right-2 -bottom-2 text-6xl opacity-50">help</span>
                    
                    <p class="text-[10px] font-black text-amber-700 uppercase mb-2 flex items-center gap-1.5">
                        <span class="material-icons-round text-xs">contact_support</span> Raised Query / Expert Note:
                    </p>
                    
                    <p class="text-sm text-slate-800 leading-relaxed font-semibold pr-10" id="queryText-<?= $q['id'] ?>">
                        <?= nl2br(htmlspecialchars($q['query_text'])) ?>
                    </p>

                    <button onclick="copyQuery(<?= $q['id'] ?>, this)"
                        class="absolute top-4 right-4 p-2 bg-white hover:bg-amber-600 hover:text-white text-slate-400 rounded-xl shadow-sm border border-amber-200 transition-all active:scale-95 group/btn"
                        title="Copy Query Text">
                        <span class="material-icons-round text-sm block group-hover/btn:scale-110">content_copy</span>
                    </button>
                </div>

                <!-- Drive Folder Action -->
                <div class="flex items-center justify-between pt-2 border-t border-slate-100">
                    <?php if(!empty($q['drive_folder_link'])): ?>
                        <a href="<?= htmlspecialchars($q['drive_folder_link']) ?>" target="_blank"
                           class="drive-btn flex items-center gap-2 px-4 py-2 bg-slate-100 hover:bg-indigo-600 hover:text-white text-slate-700 rounded-xl text-xs font-bold transition-all border border-slate-200">
                            <span class="material-icons-round text-sm text-blue-500 group-hover:text-white">folder_open</span> Open Google Drive Folder
                        </a>
                    <?php else: ?>
                        <span class="text-xs font-medium text-slate-400 italic">Not synced with Google Drive</span>
                    <?php endif; ?>
                </div>

            </div>
            <?php endforeach; ?>

        </div>

        <?php if(count($queries) == 0): ?>
            <div class="flex flex-col items-center justify-center py-32 bg-white rounded-[3rem] border-2 border-dashed border-slate-200 shadow-inner">
                <div class="p-6 bg-amber-50 rounded-full mb-6">
                    <span class="material-icons-round text-amber-400 text-7xl">all_inbox</span>
                </div>
                <h3 class="text-xl font-bold text-slate-700 uppercase tracking-widest">No Team Queries</h3>
                <p class="text-slate-400 text-sm mt-2 font-medium">All expert assignments are currently proceeding smoothly without open queries.</p>
            </div>
        <?php endif; ?>

    </main>
</div>

<script>
document.getElementById("querySearch").addEventListener("input", function() {
    const val = this.value.toLowerCase();
    const cards = document.querySelectorAll(".query-card");
    cards.forEach(card => {
        const text = card.innerText.toLowerCase();
        card.style.display = text.includes(val) ? "block" : "none";
    });
});

function copyQuery(id, btn) {
    const text = document.getElementById(`queryText-${id}`).innerText;
    const originalIcon = btn.innerHTML;
    navigator.clipboard.writeText(text).then(() => {
        btn.innerHTML = '<span class="material-icons-round text-sm">done</span>';
        btn.classList.add('bg-emerald-500', 'text-white', 'border-emerald-500');
        setTimeout(() => {
            btn.innerHTML = originalIcon;
            btn.classList.remove('bg-emerald-500', 'text-white', 'border-emerald-500');
        }, 2000);
    });
}
</script>

</body>
</html>
