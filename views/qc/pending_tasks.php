<?php
require_once "../../app/middleware/AuthMiddleware.php";
require_once "../../app/middleware/RoleMiddleware.php";
allowRole(['qc','operations_manager']);

require_once "../../app/config/database.php";
require_once "../../bootstrap/app.php";
$db = (new Database())->connect();

/* ================= ACTION HANDLER ================= */
if($_SERVER['REQUEST_METHOD']=='POST'){
    $task_id = $_POST['task_id'];
    $notes = $_POST['qc_notes'] ?? '';

if(isset($_POST['approve'])){

    // Update task
    $db->prepare("
        UPDATE tasks SET
        qc_notes=:notes,
        current_stage='completed',
        status='completed'
        WHERE id=:id
    ")->execute([
        ':id'=>$task_id,
        ':notes'=>$notes
    ]);

    // Get task details
    $stmt = $db->prepare("
        SELECT created_by, assigned_to, task_key
        FROM tasks
        WHERE id=:id
    ");
    $stmt->execute([':id'=>$task_id]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);

    // Send QC Approved Notification to BDE & Operations Manager
    if ($task) {
        require_once "../../app/services/NotificationService.php";
        NotificationService::onQCApproved((int)$task_id, $task['task_key'], (int)$task['created_by']);
    }
}

    if(isset($_POST['rework'])){
        if(empty(trim($notes))){
            header("Location: pending_tasks.php?error=notes");
            exit;
        }

        // Get task details before rework
        $stmtTask = $db->prepare("SELECT assigned_to, task_key FROM tasks WHERE id = :id");
        $stmtTask->execute([':id' => $task_id]);
        $task = $stmtTask->fetch(PDO::FETCH_ASSOC);

        $db->prepare("UPDATE tasks SET 
            qc_notes=:notes,
            current_stage='operations_team',
            status='rework'
            WHERE id=:id")->execute([
                ':id'=>$task_id,
                ':notes'=>$notes
            ]);

        // Send QC Rework Notification to Expert & Operations Manager
        if ($task && !empty($task['assigned_to'])) {
            require_once "../../app/services/NotificationService.php";
            NotificationService::onQCRework((int)$task_id, $task['task_key'], (int)$task['assigned_to'], $notes);
        }
    }

    header("Location: pending_tasks.php?done=1");
    exit;
}

/* ================= FILTER ================= */
$where = "WHERE t.current_stage='qc'";
// [FIXED] $params must exist as an array even when no search is applied,
// and the query now goes through prepare()/execute($params) so :search
// actually gets bound — previously $db->query(...) never bound it.
$params = [];
if(!empty($_GET['search'])){
    $search = $_GET['search'];
    $where .= " AND t.task_key LIKE :search";
    $params[':search'] = "%$search%";
}

$stmt = $db->prepare("SELECT t.*, u.username AS team_user
                     FROM tasks t
                     JOIN users u ON t.assigned_to=u.id
                     $where
                     ORDER BY t.id DESC");
$stmt->execute($params);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QC Review Panel | Elite Hub</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
        .review-card { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .review-card:hover { transform: scale(1.005); box-shadow: 0 20px 40px -15px rgba(0,0,0,0.05); }
        .btn-approve { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .btn-rework { background: linear-gradient(135deg, #f43f5e 0%, #e11d48 100%); }
        .feedback-input:focus { border-color: #6366f1; box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1); outline: none; }

        /* Google Drive button — same style/font used across the migrated
           Operations/QC pages (Inter font override on an otherwise
           Plus Jakarta Sans page). */
        .drive-btn { font-family: 'Inter', sans-serif; }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f1f1f1; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    </style>
</head>

<body class="text-slate-900">

<div class="flex min-h-screen">
    <?php include "partials/sidebar.php"; ?>

    <main class="flex-1 p-6 lg:p-10">
        
        <div class="max-w-6xl mx-auto mb-10 flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
            <div>
                <h1 class="text-3xl font-black text-slate-900 tracking-tight flex items-center gap-3">
                    <div class="p-3 bg-indigo-600 rounded-2xl shadow-xl shadow-indigo-100">
                        <span class="material-icons-round text-white text-2xl">rate_review</span>
                    </div>
                    Pending Quality Review
                </h1>
                <p class="text-slate-500 mt-2 font-medium">Carefully audit submissions before final delivery.</p>
            </div>
            
            <div class="bg-white px-6 py-3 rounded-2xl shadow-sm border border-slate-200">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest leading-none">Review Queue</p>
                <p class="text-xl font-black text-slate-800 mt-1"><?= count($tasks) ?> Tasks Pending</p>
            </div>
        </div>

        <?php if(count($tasks)==0): ?>
        <div class="max-w-6xl mx-auto bg-white border border-slate-200 rounded-[3rem] p-20 text-center shadow-sm">
            <div class="w-24 h-24 bg-emerald-50 rounded-full flex items-center justify-center mx-auto mb-6">
                <span class="material-icons-round text-emerald-500 text-5xl">task_alt</span>
            </div>
            <h2 class="text-2xl font-black text-slate-800">No Review Tasks</h2>
            <p class="text-slate-500 mt-2 font-medium">You have cleared the quality check queue. Good job!</p>
        </div>
        <?php endif; ?>

        <div class="max-w-6xl mx-auto space-y-10">
            <?php foreach($tasks as $t): ?>
            <div class="review-card bg-white rounded-[2.5rem] border border-slate-100 overflow-hidden shadow-sm">
                
                <div class="px-8 py-6 border-b border-slate-50 flex flex-wrap justify-between items-center bg-white">
                    <div class="flex items-center gap-4">
                        <div class="h-14 w-14 bg-slate-900 text-white rounded-2xl flex items-center justify-center font-black text-lg">
                            #<?= substr($t['task_key'], -3) ?>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h2 class="text-xl font-black text-slate-800 tracking-tight italic uppercase"><?= htmlspecialchars($t['task_key']) ?></h2>
                                <?php if($t['urgent']=='yes'): ?>
                                <span class="bg-rose-600 text-white text-[10px] font-black px-2 py-1 rounded-lg uppercase tracking-tighter animate-pulse">Urgent Action</span>
                                <?php endif; ?>
                            </div>
                            <p class="text-sm text-slate-500 font-bold uppercase tracking-wide mt-1"><?= htmlspecialchars($t['task_name']) ?></p>
                        </div>
                    </div>
                    
                    <div class="text-right">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Submission By</p>
                        <p class="text-sm font-extrabold text-indigo-600"><?= $t['team_user'] ?></p>
                    </div>
                </div>

                <div class="p-8 grid md:grid-cols-2 gap-6 bg-slate-50/50">
                    <!-- Client Files Card -->
                    <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm flex items-center justify-between group hover:border-indigo-500 transition-all">
                        <div class="flex items-center gap-4">
                            <div class="h-12 w-12 bg-indigo-50 text-indigo-600 rounded-2xl flex items-center justify-center group-hover:bg-indigo-600 group-hover:text-white transition-all">
                                <span class="material-icons-round">source</span>
                            </div>
                            <div>
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Client Brief Files</p>
                                <p class="text-xs font-bold text-slate-700">Instructions & References</p>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <?php if(!empty($t['file_path']) && file_exists('../../' . $t['file_path'])): ?>
                                <a href="../../<?= htmlspecialchars($t['file_path']) ?>" download title="Download Local Package"
                                   class="bg-indigo-50 text-indigo-700 p-2 rounded-xl hover:bg-indigo-600 hover:text-white transition-all flex items-center gap-1 text-xs font-bold px-3">
                                    <span class="material-icons-round text-sm">download</span>
                                    <span>Brief</span>
                                </a>
                            <?php endif; ?>
                            <?php $clientDrive = getDriveClientLink($t); ?>
                            <?php if(!empty($clientDrive)): ?>
                                <a href="<?= htmlspecialchars($clientDrive) ?>" target="_blank" title="Open Client Files in Drive"
                                   class="drive-btn bg-slate-100 text-slate-600 p-2 rounded-xl hover:bg-slate-900 hover:text-white transition-all">
                                    <span class="material-icons-round">folder_open</span>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Operations Files Card -->
                    <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm flex items-center justify-between group hover:border-emerald-500 transition-all">
                        <div class="flex items-center gap-4">
                            <div class="h-12 w-12 bg-emerald-50 text-emerald-600 rounded-2xl flex items-center justify-center group-hover:bg-emerald-600 group-hover:text-white transition-all">
                                <span class="material-icons-round">cloud_done</span>
                            </div>
                            <div>
                                <p class="text-[10px] font-black text-emerald-500 uppercase tracking-widest">Operations Files</p>
                                <p class="text-xs font-bold text-slate-700">Expert Submission</p>
                            </div>
                        </div>

                        <div class="flex items-center gap-1.5 flex-wrap justify-end">
                            <?php if(!empty($t['team_file']) && file_exists('../../' . $t['team_file'])): ?>
                                <a href="../../<?= htmlspecialchars($t['team_file']) ?>" download title="Download Submitted Package"
                                   class="bg-emerald-50 text-emerald-700 p-2 rounded-xl hover:bg-emerald-600 hover:text-white transition-all flex items-center gap-1 text-xs font-bold px-3">
                                    <span class="material-icons-round text-sm">download</span>
                                    <span>Download</span>
                                </a>
                            <?php endif; ?>
                            <?php $opsDrive = getDriveOperationsLink($t); ?>
                            <?php if(!empty($opsDrive)): ?>
                                <a href="<?= htmlspecialchars($opsDrive) ?>" target="_blank" title="Open Operations Files in Drive"
                                   class="drive-btn bg-emerald-100 text-emerald-700 p-2 rounded-xl hover:bg-emerald-600 hover:text-white transition-all">
                                    <span class="material-icons-round">folder_open</span>
                                </a>
                            <?php endif; ?>

                            <?php if(($_SESSION['role'] ?? '') === 'operations_manager'): ?>
                                <!-- OM Special Action: Edit / Re-upload Solution File -->
                                <button type="button" onclick="openOmReuploadModal(<?= $t['id'] ?>, '<?= htmlspecialchars($t['task_key'], ENT_QUOTES) ?>')"
                                        title="OM Action: Re-upload/Edit Solution File (Clears previous & syncs Drive)"
                                        class="bg-amber-50 text-amber-700 hover:bg-amber-500 hover:text-white p-2 rounded-xl transition-all flex items-center gap-1 text-xs font-bold px-2.5 shadow-xs">
                                    <span class="material-icons-round text-sm">drive_file_rename_outline</span>
                                    <span>Edit</span>
                                </button>

                                <?php if(!empty($t['team_file'])): ?>
                                <!-- OM Special Action: Delete Solution File -->
                                <button type="button" onclick="confirmOmDeleteFile(<?= $t['id'] ?>, '<?= htmlspecialchars($t['task_key'], ENT_QUOTES) ?>')"
                                        title="OM Action: Delete Solution File from Local & Drive"
                                        class="bg-rose-50 text-rose-700 hover:bg-rose-600 hover:text-white p-2 rounded-xl transition-all flex items-center gap-1 text-xs font-bold px-2.5 shadow-xs">
                                    <span class="material-icons-round text-sm">delete_forever</span>
                                    <span>Delete</span>
                                </button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Key Notes / Instructions Card -->
                    <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest flex items-center gap-1.5">
                            <span class="material-icons-round text-sm text-indigo-600">notes</span> Client Instructions / Brief
                        </label>
                        <div class="p-4 rounded-2xl bg-indigo-50/40 border border-indigo-100/60 text-sm text-slate-700 leading-relaxed max-h-40 overflow-y-auto custom-scrollbar">
                            <?= !empty($t['instructions']) ? nl2br(htmlspecialchars($t['instructions'])) : '<span class="text-slate-400 italic font-medium">No instructions provided</span>' ?>
                        </div>

                        <?php if (!empty($t['additional_instructions'])): ?>
                            <div class="p-4 rounded-2xl bg-amber-50/90 border-2 border-amber-300 text-sm text-amber-950 leading-relaxed mt-2 shadow-xs">
                                <div class="flex items-center justify-between mb-1.5">
                                    <span class="text-[10px] font-black uppercase text-amber-900 flex items-center gap-1">
                                        <span class="material-icons-round text-sm text-amber-600">new_releases</span> Additional Information / Client Update
                                    </span>
                                    <span class="text-[9px] font-black uppercase px-2 py-0.5 rounded-full bg-amber-200 text-amber-900 border border-amber-300">⚡ Updated</span>
                                </div>
                                <div class="font-semibold text-xs whitespace-pre-line leading-relaxed">
                                    <?= nl2br(htmlspecialchars($t['additional_instructions'])) ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Expert Submitted Work Notes Card -->
                    <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm space-y-2">
                        <label class="text-[10px] font-black text-amber-600 uppercase tracking-widest flex items-center gap-1.5">
                            <span class="material-icons-round text-sm text-amber-600">edit_note</span> Expert Submission Notes
                        </label>
                        <div class="p-4 rounded-2xl bg-amber-50/60 border border-amber-100 text-sm text-slate-800 leading-relaxed max-h-40 overflow-y-auto custom-scrollbar">
                            <?php if(!empty($t['team_notes'])): ?>
                                <?= nl2br(htmlspecialchars($t['team_notes'])) ?>
                            <?php else: ?>
                                <span class="text-slate-400 italic font-medium">No submission notes provided by expert</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if(!empty($t['om_notes'])): ?>
                    <!-- Operations Manager Notes Card -->
                    <div class="md:col-span-2 bg-white p-6 rounded-3xl border border-slate-200 shadow-sm space-y-2">
                        <label class="text-[10px] font-black text-indigo-500 uppercase tracking-widest flex items-center gap-1.5">
                            <span class="material-icons-round text-sm text-indigo-600">psychology</span> Manager Notes
                        </label>
                        <div class="p-4 rounded-2xl bg-indigo-50/40 border border-indigo-100/60 text-sm text-slate-700 leading-relaxed max-h-40 overflow-y-auto custom-scrollbar">
                            <?= nl2br(htmlspecialchars($t['om_notes'])) ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="px-8 py-4 bg-white border-y border-slate-100 grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="flex flex-col">
                        <span class="text-[10px] font-black text-slate-300 uppercase">Volume</span>
                        <span class="text-sm font-bold text-slate-700"><?= number_format($t['word_count']) ?> Words</span>
                    </div>
                    <div class="flex flex-col border-l border-slate-100 pl-4">
                        <span class="text-[10px] font-black text-slate-300 uppercase">Deadline</span>
                        <span class="text-sm font-bold text-rose-600 italic"><?= date("d M, h:i A", strtotime($t['deadline'])) ?></span>
                    </div>
                    <div class="flex flex-col border-l border-slate-100 pl-4">
                        <span class="text-[10px] font-black text-slate-300 uppercase">Status</span>
                        <span class="text-sm font-bold text-indigo-500 uppercase tracking-tighter"><?= $t['status'] ?></span>
                    </div>
                    <div class="flex flex-col border-l border-slate-100 pl-4">
                        <span class="text-[10px] font-black text-slate-300 uppercase">Source Date</span>
                        <span class="text-sm font-bold text-slate-400"><?= date("d/m/Y", strtotime($t['task_created_at'])) ?></span>
                    </div>
                </div>

                <div class="p-8 bg-slate-50/30">
                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                        
                        <div>
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">Feedback & Quality Notes</label>
                            <textarea name="qc_notes" placeholder="Enter rework instructions or approval comments..."
                                class="feedback-input w-full bg-white border border-slate-200 p-5 rounded-[1.5rem] text-sm font-medium transition-all min-h-[100px]"></textarea>
                        </div>

                        <div class="flex flex-col sm:flex-row gap-4">
                            <button name="approve" class="btn-approve flex-1 text-white py-4 rounded-2xl font-black text-sm uppercase tracking-widest shadow-xl shadow-emerald-100 hover:-translate-y-1 transition-all flex items-center justify-center gap-2">
                                <span class="material-icons-round">check_circle</span> Approve & Finalize
                            </button>

                            <button name="rework" class="btn-rework flex-1 text-white py-4 rounded-2xl font-black text-sm uppercase tracking-widest shadow-xl shadow-rose-100 hover:-translate-y-1 transition-all flex items-center justify-center gap-2">
                                <span class="material-icons-round">published_with_changes</span> Reject & Rework
                            </button>
                        </div>
                    </form>
                </div>

            </div>
            <?php endforeach; ?>
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

<?php if(isset($_GET['done'])): ?>
<script>
    Swal.fire({
        icon: 'success',
        title: 'Review Submitted',
        text: 'The task has been successfully moved to the next stage.',
        confirmButtonColor: '#4f46e5',
        customClass: { popup: 'rounded-[2rem]' }
    });
</script>
<?php endif; ?>

<?php if(isset($_GET['error'])): ?>
<script>
    Swal.fire({
        icon: 'error',
        title: 'Notes Required',
        text: 'You must provide feedback notes when sending a task for rework.',
        confirmButtonColor: '#e11d48',
        customClass: { popup: 'rounded-[2rem]' }
    });
</script>
<?php endif; ?>

</body>
</html>