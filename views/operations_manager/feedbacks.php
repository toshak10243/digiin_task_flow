<?php
require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole('operations_manager');

require_once "../../app/config/database.php";
require_once "../../bootstrap/app.php";
$db = (new Database())->connect();

// Ensure test queries in tasks.feedback are cleaned out so only BDE feedbacks remain
$db->exec("CREATE TABLE IF NOT EXISTS task_queries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    task_key VARCHAR(100) NOT NULL,
    user_id INT NOT NULL,
    query_text TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$db->exec("UPDATE tasks SET feedback = NULL WHERE feedback IS NOT NULL AND id IN (SELECT DISTINCT task_id FROM task_queries)");

// Auto ensure forward tracking column exists in tasks table
try {
    $db->exec("ALTER TABLE tasks ADD COLUMN forwarded_feedback_to INT NULL AFTER assigned_to");
} catch (Exception $e) {}

// Handle Feedback Forwarding to Operations Team Member
$successMsg = '';
$errorMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'forward_feedback') {
    $taskId = (int)($_POST['task_id'] ?? 0);
    $targetUserId = (int)($_POST['target_user_id'] ?? 0);
    $forwardNote = trim($_POST['forward_note'] ?? '');

    if ($taskId > 0 && $targetUserId > 0) {
        $stmtTask = $db->prepare("SELECT task_key, feedback, assigned_to FROM tasks WHERE id = :id");
        $stmtTask->execute([':id' => $taskId]);
        $taskRow = $stmtTask->fetch(PDO::FETCH_ASSOC);

        if ($taskRow) {
            // Update task forward target
            $updateStmt = $db->prepare("UPDATE tasks SET forwarded_feedback_to = :uid WHERE id = :id");
            $updateStmt->execute([':uid' => $targetUserId, ':id' => $taskId]);

            // Notify the selected Operations Team Member
            try {
                require_once "../../app/services/NotificationService.php";
                NotificationService::onFeedbackForwarded((int)$taskId, $taskRow['task_key'], (int)$targetUserId);
            } catch (Exception $e) {}

            $successMsg = "Feedback on " . htmlspecialchars($taskRow['task_key']) . " successfully forwarded to team member!";
        }
    } else {
        $errorMsg = "Please select a team member to forward.";
    }
}

// Fetch all active Operations Team members for dropdown
$opsTeam = $db->query("
    SELECT id, name, username 
    FROM users 
    WHERE role = 'operations_team' AND status = 'active' 
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Join query for BDE Member Name & Feedback (excluding expert queries)
$tasks = $db->query("
    SELECT t.*, u.name as member_name, 
           assigned_user.name as assigned_expert_name,
           fw_user.name as forwarded_to_name
    FROM tasks t
    LEFT JOIN users u ON t.created_by = u.id
    LEFT JOIN users assigned_user ON t.assigned_to = assigned_user.id
    LEFT JOIN users fw_user ON t.forwarded_feedback_to = fw_user.id
    WHERE t.feedback IS NOT NULL 
    AND t.feedback != ''
    AND t.id NOT IN (SELECT DISTINCT task_id FROM task_queries)
    ORDER BY t.id DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feedbacks Panel | Digiink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
        .feedback-card { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .feedback-card:hover { transform: translateY(-3px); shadow: 0 20px 25px -5px rgb(0 0 0 / 0.1); }
        .custom-shadow { box-shadow: 0 4px 15px rgba(0,0,0,0.05); }

        /* Google Drive button — same style used in incoming_tasks.php /
           completed_tasks.php / rework_tasks.php (Inter font, zinc chip). */
        .drive-btn { font-family: 'Inter', sans-serif; }
    </style>
</head>

<body class="text-slate-900">

<div class="flex min-h-screen">
    <?php include "partials/sidebar.php"; ?>

    <main class="flex-1 p-6 lg:p-10">
        
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-10">
            <div>
                <h1 class="text-3xl font-extrabold tracking-tight text-slate-900 flex items-center gap-3">
                    <span class="p-2 bg-indigo-600 rounded-xl shadow-lg shadow-indigo-100">
                        <span class="material-icons-round text-white">reviews</span>
                    </span>
                    Feedbacks Panel
                </h1>
                <p class="text-slate-500 font-medium mt-1">Review and forward client comments to the operations team.</p>
            </div>
            
            <div class="w-full md:w-80 relative">
                <span class="material-icons-round absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">search</span>
                <input type="text" id="search" placeholder="Search task, BDE or status..." 
                    class="w-full pl-10 pr-4 py-3 bg-white border border-slate-200 rounded-2xl text-sm focus:ring-2 focus:ring-indigo-500 outline-none transition-all custom-shadow">
            </div>
        </div>

        <?php if(!empty($successMsg)): ?>
            <div class="mb-6 p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 flex items-center gap-3 shadow-sm">
                <span class="material-icons-round text-emerald-600 text-xl">check_circle</span>
                <span class="text-xs font-bold"><?= $successMsg ?></span>
            </div>
        <?php elseif(!empty($errorMsg)): ?>
            <div class="mb-6 p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 flex items-center gap-3 shadow-sm">
                <span class="material-icons-round text-rose-600 text-xl">error_outline</span>
                <span class="text-xs font-bold"><?= $errorMsg ?></span>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-2 gap-8" id="feedbackContainer">

            <?php foreach($tasks as $t): 
                $statusClass = match($t['status']) {
                    'completed'   => 'bg-emerald-100 text-emerald-700 border-emerald-200',
                    'in_progress' => 'bg-amber-100 text-amber-700 border-amber-200',
                    'rework'      => 'bg-rose-100 text-rose-700 border-rose-200',
                    default       => 'bg-slate-100 text-slate-600 border-slate-200'
                };
            ?>
            
            <div class="bg-white rounded-[2rem] border border-slate-100 p-7 feedback-card custom-shadow relative group">
                
                <div class="flex justify-between items-start mb-6">
                    <div class="space-y-2">
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] font-black uppercase tracking-widest text-indigo-600 bg-indigo-50 px-2.5 py-1 rounded-lg">
                                <?= $t['task_key'] ?>
                            </span>
                            
                            <?php if(!empty($t['urgent']) && $t['urgent'] == 'yes'): ?>
                            <span class="text-[10px] font-bold px-2.5 py-1 rounded-lg bg-rose-50 text-rose-600 uppercase border border-rose-100 animate-pulse">
                                Urgent
                            </span>
                            <?php endif; ?>
                        </div>
                        <h2 class="text-lg font-bold text-slate-800 leading-tight">
                            <?= htmlspecialchars($t['task_name']) ?>
                        </h2>
                    </div>
                    <?php if(!empty($t['assigned_expert_name'])): ?>
                    <div class="text-right">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Assigned Expert</p>
                        <div class="flex items-center justify-end gap-2">
                            <span class="text-sm font-bold text-slate-700"><?= htmlspecialchars($t['assigned_expert_name']) ?></span>
                            <div class="w-7 h-7 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center text-[10px] font-bold">
                                <?= strtoupper(substr($t['assigned_expert_name'], 0, 1)) ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="flex items-center gap-4 mb-6">
                    <div class="flex items-center gap-1.5 bg-indigo-50 text-indigo-600 px-3 py-1.5 rounded-xl border border-indigo-100">
                        <span class="material-icons-round text-sm">event</span>
                        <span class="text-xs font-bold"><?= date("d M, Y", strtotime($t['deadline'])) ?></span>
                    </div>
                    
                    <span class="text-[10px] font-black px-4 py-1.5 rounded-xl border uppercase tracking-widest <?= $statusClass ?>">
                        <?= str_replace('_', ' ', $t['status']) ?>
                    </span>

                    <div class="ml-auto text-slate-400 flex items-center gap-1">
                        <span class="material-icons-round text-sm">sticky_note_2</span>
                        <span class="text-xs font-bold"><?= number_format($t['word_count']) ?> Words</span>
                    </div>
                </div>

                <div class="flex gap-3 mb-6">
                    <?php 
                    $clientDrive = getDriveClientLink($t);
                    $opsDrive    = getDriveOperationsLink($t);
                    ?>
                    <?php if(!empty($clientDrive) || !empty($opsDrive)): ?>
                        <?php if(!empty($clientDrive)): ?>
                            <a href="<?= htmlspecialchars($clientDrive) ?>" target="_blank"
                               class="drive-btn flex-1 flex items-center justify-center gap-2 py-2.5 bg-zinc-100 hover:bg-zinc-200 text-zinc-800 rounded-xl text-[11px] font-bold transition-all border border-zinc-200">
                                <span class="material-icons-round text-sm text-blue-500">folder_open</span> Client Files
                            </a>
                        <?php endif; ?>
                        <?php if(!empty($opsDrive)): ?>
                            <a href="<?= htmlspecialchars($opsDrive) ?>" target="_blank"
                               class="drive-btn flex-1 flex items-center justify-center gap-2 py-2.5 bg-zinc-100 hover:bg-zinc-200 text-zinc-800 rounded-xl text-[11px] font-bold transition-all border border-zinc-200">
                                <span class="material-icons-round text-sm text-emerald-500">folder_shared</span> Operations Files
                            </a>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="flex-1 text-center text-xs font-medium text-slate-400 italic py-2.5">Not synced with Google Drive</span>
                    <?php endif; ?>
                </div>

                <div class="bg-slate-50 rounded-2xl p-6 border border-slate-100 relative overflow-hidden mb-6">
                    <span class="material-icons-round text-slate-200 absolute -right-2 -bottom-2 text-6xl opacity-40">format_quote</span>
                    
                    <p class="text-[10px] font-black text-indigo-500 uppercase mb-3 flex items-center gap-1">
                        <span class="material-icons-round text-xs">chat_bubble</span> Client Feedback
                    </p>
                    
                    <p class="text-sm text-slate-600 leading-relaxed italic font-medium pr-10" id="feedbackText-<?= $t['id'] ?>">
                        <?= nl2br(htmlspecialchars($t['feedback'])) ?>
                    </p>

                    <?php 
                    $fbFiles = [];
                    if (!empty($t['feedback_files'])) {
                        $fbFiles = json_decode($t['feedback_files'], true) ?: [];
                    }
                    ?>
                    <?php if (!empty($fbFiles)): ?>
                        <div class="mt-4 pt-3 border-t border-slate-200/80 relative z-10">
                            <p class="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-2.5 flex items-center gap-1.5">
                                <span class="material-icons-round text-xs text-indigo-500">attach_file</span>
                                Attached Screenshots & Files (<?= count($fbFiles) ?>)
                            </p>
                            
                            <?php 
                            $images = array_filter($fbFiles, fn($f) => !empty($f['is_image']));
                            $docs = array_filter($fbFiles, fn($f) => empty($f['is_image']));
                            ?>

                            <?php if (!empty($images)): ?>
                                <div class="flex flex-wrap gap-2.5 mb-2.5">
                                    <?php foreach ($images as $img): ?>
                                        <div class="relative group/thumb rounded-xl overflow-hidden border border-slate-200 bg-white shadow-xs hover:shadow-md transition-all">
                                            <img src="../../<?= htmlspecialchars(ltrim($img['path'], '/')) ?>" 
                                                 alt="<?= htmlspecialchars($img['name']) ?>" 
                                                 onclick="openImagePreview('../../<?= htmlspecialchars(ltrim($img['path'], '/')) ?>', '<?= htmlspecialchars(addslashes($img['name'])) ?>')"
                                                 class="w-20 h-20 object-cover cursor-zoom-in group-hover/thumb:scale-105 transition-transform duration-200" 
                                                 loading="lazy">
                                            <div class="absolute inset-0 bg-black/40 opacity-0 group-hover/thumb:opacity-100 transition-opacity flex items-center justify-center gap-1 pointer-events-none">
                                                <span class="material-icons-round text-white text-base">zoom_in</span>
                                            </div>
                                            <a href="../../<?= htmlspecialchars(ltrim($img['path'], '/')) ?>" 
                                               download="<?= htmlspecialchars($img['name']) ?>"
                                               onclick="event.stopPropagation();"
                                               class="absolute bottom-1 right-1 p-1 bg-white/90 hover:bg-white text-slate-700 rounded shadow-xs text-[10px] opacity-0 group-hover/thumb:opacity-100 transition-opacity"
                                               title="Download Image">
                                                <span class="material-icons-round text-xs block">download</span>
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($docs)): ?>
                                <div class="flex flex-col gap-1.5">
                                    <?php foreach ($docs as $doc): ?>
                                        <a href="../../<?= htmlspecialchars(ltrim($doc['path'], '/')) ?>" 
                                           download="<?= htmlspecialchars($doc['name']) ?>"
                                           class="inline-flex items-center justify-between gap-2 px-3 py-1.5 rounded-lg bg-white hover:bg-indigo-50 border border-slate-200 hover:border-indigo-200 transition group/doc text-slate-700 hover:text-indigo-900">
                                            <span class="flex items-center gap-2 text-xs font-semibold truncate max-w-[240px]">
                                                <span class="material-icons-round text-sm text-indigo-500">
                                                    <?= (in_array(strtolower($doc['ext'] ?? ''), ['pdf']) ? 'picture_as_pdf' : 'description') ?>
                                                </span>
                                                <span class="truncate"><?= htmlspecialchars($doc['name']) ?></span>
                                            </span>
                                            <span class="flex items-center gap-1 text-[10px] text-slate-400 group-hover/doc:text-indigo-600 font-bold shrink-0">
                                                <span><?= htmlspecialchars($doc['size'] ?? '') ?></span>
                                                <span class="material-icons-round text-xs">download</span>
                                            </span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <button onclick="copyFeedback(<?= $t['id'] ?>, this)"
                        class="absolute top-4 right-4 p-2 bg-white hover:bg-indigo-600 hover:text-white text-slate-400 rounded-xl shadow-sm border border-slate-200 transition-all active:scale-95 group/btn">
                        <span class="material-icons-round text-sm block group-hover/btn:scale-110">content_copy</span>
                    </button>
                </div>

                <!-- 🚀 FORWARD FEEDBACK TO OPERATIONS TEAM -->
                <div class="pt-4 border-t border-slate-100">
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center gap-2">
                            <span class="p-1.5 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center">
                                <span class="material-icons-round text-sm">forward_to_inbox</span>
                            </span>
                            <span class="text-xs font-extrabold text-slate-800">Forward to Operations Team</span>
                        </div>
                        <?php if(!empty($t['forwarded_to_name'])): ?>
                            <span class="inline-flex items-center gap-1 text-[11px] font-bold px-2.5 py-1 rounded-lg bg-emerald-50 text-emerald-700 border border-emerald-100">
                                <span class="material-icons-round text-xs text-emerald-500">check_circle</span>
                                Sent to: <?= htmlspecialchars($t['forwarded_to_name']) ?>
                            </span>
                        <?php elseif(!empty($t['assigned_expert_name'])): ?>
                            <span class="text-[10px] text-slate-400 font-semibold">
                                Assigned Expert: <?= htmlspecialchars($t['assigned_expert_name']) ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <form action="" method="POST" class="space-y-3">
                        <input type="hidden" name="action" value="forward_feedback">
                        <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                        
                        <div class="flex flex-col sm:flex-row items-center gap-2.5">
                            <div class="relative w-full sm:flex-1">
                                <select name="target_user_id" required 
                                        class="w-full appearance-none bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-xs font-bold text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:bg-white transition-all cursor-pointer">
                                    <option value="">-- Select Operations Team Member --</option>
                                    <?php 
                                    $defaultTarget = $t['forwarded_feedback_to'] ?: $t['assigned_to'];
                                    foreach($opsTeam as $member): 
                                        $isSelected = ((int)$member['id'] === (int)$defaultTarget);
                                    ?>
                                        <option value="<?= $member['id'] ?>" <?= $isSelected ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($member['name']) ?> (<?= htmlspecialchars($member['username']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="material-icons-round absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm pointer-events-none">expand_more</span>
                            </div>

                            <button type="submit" 
                                    class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-indigo-600 hover:bg-slate-900 text-white rounded-xl text-xs font-black uppercase tracking-wider transition-all shadow-md shadow-indigo-100 active:scale-95 shrink-0">
                                <span>Forward</span>
                                <span class="material-icons-round text-sm">send</span>
                            </button>
                        </div>
                    </form>
                </div>

            </div>
            <?php endforeach; ?>

        </div>

        <?php if(count($tasks) == 0): ?>
            <div class="flex flex-col items-center justify-center py-32 bg-white rounded-[3rem] border-2 border-dashed border-slate-200 shadow-inner">
                <div class="p-6 bg-slate-50 rounded-full mb-6">
                    <span class="material-icons-round text-slate-300 text-7xl">auto_awesome_motion</span>
                </div>
                <h3 class="text-xl font-bold text-slate-700 uppercase tracking-widest">Inbox is Clear</h3>
                <p class="text-slate-400 text-sm mt-2 font-medium">No feedback yet.</p>
            </div>
        <?php endif; ?>

    </main>
</div>

<!-- Lightbox Modal for Image Preview -->
<div id="imageLightboxModal" class="fixed inset-0 z-50 hidden bg-black/80 backdrop-blur-sm flex items-center justify-center p-4" onclick="closeImageLightbox()">
    <div class="relative max-w-4xl max-h-[90vh] flex flex-col items-center" onclick="event.stopPropagation()">
        <button type="button" onclick="closeImageLightbox()" class="absolute -top-10 right-0 text-white/80 hover:text-white p-2">
            <span class="material-icons-round text-2xl">close</span>
        </button>
        <img id="lightboxImg" src="" alt="Preview" class="max-w-full max-h-[80vh] object-contain rounded-xl shadow-2xl">
        <div class="mt-3 flex items-center justify-between w-full text-white text-xs px-2">
            <span id="lightboxCaption" class="truncate font-medium"></span>
            <a id="lightboxDownload" href="" download class="inline-flex items-center gap-1 px-3 py-1 rounded bg-white/20 hover:bg-white/30 text-white font-bold transition">
                <span class="material-icons-round text-sm">download</span> Download
            </a>
        </div>
    </div>
</div>

<script>
function openImagePreview(src, name) {
    const modal = document.getElementById('imageLightboxModal');
    const img = document.getElementById('lightboxImg');
    const caption = document.getElementById('lightboxCaption');
    const dl = document.getElementById('lightboxDownload');
    if (!modal || !img) return;
    img.src = src;
    if (caption) caption.textContent = name || 'Image Preview';
    if (dl) { dl.href = src; dl.download = name || 'image'; }
    modal.classList.remove('hidden');
    document.body.classList.add('overflow-hidden');
}

function closeImageLightbox() {
    const modal = document.getElementById('imageLightboxModal');
    if (modal) {
        modal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    }
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeImageLightbox();
});

document.getElementById("search").addEventListener("input", function() {
    const val = this.value.toLowerCase();
    const cards = document.querySelectorAll(".feedback-card");
    cards.forEach(card => {
        const text = card.innerText.toLowerCase();
        card.style.display = text.includes(val) ? "block" : "none";
    });
});

function copyFeedback(id, btn) {
    const text = document.getElementById(`feedbackText-${id}`).innerText;
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