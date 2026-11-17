<?php
require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole('operations_manager');

require_once "../../app/config/database.php";
$db = (new Database())->connect();

// 🔔 FETCH URGENT TASKS
$tasks = $db->query("SELECT t.*, u.username AS bde_user
                     FROM tasks t
                     JOIN users u ON t.created_by=u.id
                     WHERE t.urgent='yes'
                     AND t.current_stage='operations_manager'
                     ORDER BY t.id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Urgent Notifications | Digiink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #fef2f2; }
        .alert-card { background: #ffffff; border-radius: 24px; border: 1px solid #fee2e2; }
        .urgent-row { transition: all 0.2s; border-bottom: 1px solid #fee2e2; }
        .urgent-row:hover { background-color: #fff1f1; transform: scale(1.002); }
        .pulse-icon { animation: pulse-red 2s infinite; }
        @keyframes pulse-red {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(220, 38, 38, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(220, 38, 38, 0); }
        }
    </style>
</head>

<body class="text-slate-900">

<div class="flex min-h-screen">
    <?php include "partials/sidebar.php"; ?>

    <main class="flex-1 p-6 lg:p-10 space-y-8">

        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
            <div class="flex items-center gap-4">
                <div class="w-16 h-16 bg-red-600 rounded-2xl flex items-center justify-center text-white shadow-lg shadow-red-200 pulse-icon">
                    <span class="material-icons-round text-3xl">notifications_active</span>
                </div>
                <div>
                    <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight">Urgent Task Notifications</h1>
                    <p class="text-red-500 font-semibold flex items-center gap-1">
                        <span class="material-icons-round text-sm">priority_high</span>
                        Immediate attention required
                    </p>
                </div>
            </div>

            <a href="dashboard.php" class="bg-white text-slate-700 px-6 py-3 rounded-2xl font-bold border border-slate-200 shadow-sm hover:bg-slate-50 transition-all flex items-center gap-2">
                <span class="material-icons-round text-sm">arrow_back</span>
                Back to Dashboard
            </a>
        </div>

        <?php if(count($tasks) == 0): ?>
        <div class="alert-card p-20 text-center">
            <div class="w-24 h-24 bg-emerald-50 text-emerald-500 rounded-full flex items-center justify-center mx-auto mb-6">
                <span class="material-icons-round text-5xl">task_alt</span>
            </div>
            <h2 class="text-2xl font-black text-slate-800 uppercase tracking-tight">No Urgent Tasks 🎉</h2>
            <p class="text-slate-500 font-medium mt-2 italic">Everything is under control. Have a coffee and a smile! 😊</p>
        </div>
        <?php else: ?>

<div class="alert-card shadow-2xl shadow-red-100 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
            <thead class="bg-red-600 text-white uppercase text-[11px] font-black tracking-widest">
                <tr>
                    <th class="p-5">Task Key</th>
                    <th class="p-5">Task Name</th>
                    <th class="p-5">Words</th>
                    <th class="p-5">Deadline</th>
                    <th class="p-5">Status</th>
                    <th class="p-5 text-center">Action</th>
                </tr>
            </thead>

            <tbody class="text-sm">
            <?php foreach($tasks as $t): ?>
                <tr class="urgent-row">
                    <td class="p-5">
                        <span class="font-black text-red-600 italic uppercase tracking-tighter text-base">
                            <?= $t['task_key'] ?>
                        </span>
                    </td>

                    <td class="p-5 font-bold text-slate-700">
                        <?= htmlspecialchars($t['task_name']) ?>
                    </td>

                    <td class="p-5 font-black text-slate-800">
                        <?= number_format($t['word_count']) ?>
                    </td>

                    <td class="p-5">
                        <div class="flex flex-col">
                            <span class="text-red-600 font-black flex items-center gap-1">
                                <span class="material-icons-round text-xs">alarm</span>
                                <?= date("d M, H:i", strtotime($t['deadline'])) ?>
                            </span>
                            <span class="text-[10px] text-slate-400 font-bold uppercase tracking-tighter">Target Time</span>
                        </div>
                    </td>

                    <td class="p-5">
                        <span class="bg-red-600 text-white px-3 py-1 rounded-lg text-[10px] font-black uppercase tracking-widest">
                            High Priority
                        </span>
                    </td>

                    <td class="p-5 text-center">
                        <a href="incoming_tasks.php" class="inline-flex items-center gap-2 bg-slate-900 text-white px-5 py-2.5 rounded-xl font-black text-[11px] uppercase tracking-widest hover:bg-red-600 transition-all shadow-lg shadow-slate-200">
                            Assign Now
                            <span class="material-icons-round text-sm">send</span>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Footer same rahega -->
    <div class="bg-red-50 p-4 border-t border-red-100 flex items-center justify-between">
        <p class="text-[10px] font-black text-red-400 uppercase tracking-widest italic">
            * Urgent tasks consider as high priority.
        </p>
        <p class="text-[10px] font-bold text-slate-400 uppercase">
            Total Alerts: <?= count($tasks) ?>
        </p>
    </div>
</div>

        <?php endif; ?>

    </main>
</div>

</body>
</html>