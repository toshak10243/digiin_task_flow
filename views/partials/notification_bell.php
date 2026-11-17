<?php
/**
 * Shared Notification Bell Component
 * Expects:
 *   - $notifications: array of notification records (or null to auto-fetch)
 *   - $unread: integer count of unread notifications
 *   - $role: user's role (string)
 */

if (!function_exists('getNotifIconMeta')) {
    function getNotifIconMeta($type) {
        return match($type) {
            'urgent_task'    => ['icon' => 'priority_high', 'color' => 'text-rose-600',    'bg' => 'bg-rose-50',    'border' => 'border-rose-100',    'badge' => 'Urgent'],
            'task_created'   => ['icon' => 'add_task',      'color' => 'text-blue-600',    'bg' => 'bg-blue-50',    'border' => 'border-blue-100',    'badge' => 'New Task'],
            'task_assigned'  => ['icon' => 'assignment_ind','color' => 'text-indigo-600',  'bg' => 'bg-indigo-50',  'border' => 'border-indigo-100',  'badge' => 'Assigned'],
            'work_deployed'  => ['icon' => 'rocket_launch', 'color' => 'text-sky-600',     'bg' => 'bg-sky-50',     'border' => 'border-sky-100',     'badge' => 'Deployed'],
            'task_completed' => ['icon' => 'verified',      'color' => 'text-emerald-600', 'bg' => 'bg-emerald-50', 'border' => 'border-emerald-100', 'badge' => 'Completed'],
            'task_rework'    => ['icon' => 'sync_problem',  'color' => 'text-amber-600',   'bg' => 'bg-amber-50',   'border' => 'border-amber-100',   'badge' => 'Rework'],
            'feedback'       => ['icon' => 'rate_review',   'color' => 'text-purple-600',  'bg' => 'bg-purple-50',  'border' => 'border-purple-100',  'badge' => 'Feedback'],
            'additional_files'=>['icon' => 'folder_zip',    'color' => 'text-amber-600',   'bg' => 'bg-amber-50',   'border' => 'border-amber-100',   'badge' => 'Files'],
            'expert_query'   => ['icon' => 'contact_support','color' => 'text-orange-600', 'bg' => 'bg-orange-50',  'border' => 'border-orange-100',  'badge' => 'Query'],
            'task_withdrawn' => ['icon' => 'person_remove', 'color' => 'text-rose-600',    'bg' => 'bg-rose-50',    'border' => 'border-rose-100',    'badge' => 'Withdrawn'],
            'task_returned'  => ['icon' => 'assignment_return','color' => 'text-amber-600','bg' => 'bg-amber-50',   'border' => 'border-amber-100',   'badge' => 'Returned'],
            'task_auto_withdrawn' => ['icon' => 'alarm_off','color' => 'text-rose-600',   'bg' => 'bg-rose-50',    'border' => 'border-rose-100',    'badge' => 'Auto-Withdrawn'],
            default          => ['icon' => 'notifications', 'color' => 'text-slate-600',   'bg' => 'bg-slate-50',   'border' => 'border-slate-100',   'badge' => 'Notice']
        };
    }
}

if (!function_exists('formatNotifTime')) {
    function formatNotifTime($datetime) {
        if (empty($datetime)) return '';
        $diff = time() - strtotime($datetime);
        if ($diff < 60) return "just now";
        $mins = floor($diff / 60);
        if ($mins < 60) return $mins . "m ago";
        $hours = floor($mins / 60);
        if ($hours < 24) return $hours . "h ago";
        $days = floor($hours / 24);
        if ($days < 30) return $days . "d ago";
        return date("d M", strtotime($datetime));
    }
}

// Auto-fetch if not already passed
if (!isset($notifications) || !isset($unread)) {
    require_once __DIR__ . '/../../app/services/NotificationService.php';
    $notifData = NotificationService::getForUser((int)($_SESSION['user_id'] ?? 0), 15);
    $notifications = $notifData['notifications'];
    $unread = $notifData['unread'];
}

$unreadBadgeText = $unread > 9 ? '9+' : (string)$unread;
$markAllUrl = '../notifications_mark.php?all=1';
$historyUrl = 'notifications_history.php';
?>

<div class="relative notif-bell-root" id="sharedNotifRoot">
    <!-- Bell Button -->
    <button id="sharedNotifBtn"
            type="button"
            class="h-10 w-10 flex items-center justify-center rounded-xl bg-white shadow-sm border border-slate-200 relative group transition-all hover:bg-slate-50 hover:border-slate-300 active:scale-95"
            aria-label="Notifications">
        <span class="material-icons-round text-slate-600 text-xl group-hover:rotate-12 transition-transform">notifications</span>
        
        <?php if($unread > 0): ?>
        <span id="sharedNotifBadge"
              class="absolute -top-1.5 -right-1.5 bg-gradient-to-r from-red-500 to-rose-600 text-white text-[9px] font-black px-1.5 py-0.5 rounded-full border-2 border-white leading-none shadow-sm animate-pulse">
            <?= $unreadBadgeText ?>
        </span>
        <?php endif; ?>
    </button>

    <!-- Dropdown Panel -->
    <div id="sharedNotifDropdown"
         class="hidden absolute right-0 mt-2.5 w-[380px] sm:w-[420px] bg-white rounded-2xl border border-slate-200 shadow-2xl z-[999] overflow-hidden">

        <!-- Header -->
        <div class="px-4 py-3 border-b border-slate-100 bg-slate-50/70 flex justify-between items-center">
            <div class="flex items-center gap-2">
                <div class="w-7 h-7 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center">
                    <span class="material-icons-round text-[16px]">notifications</span>
                </div>
                <h3 class="text-xs font-extrabold text-slate-800 uppercase tracking-wide">Notifications</h3>
                <?php if($unread > 0): ?>
                <span class="bg-indigo-100 text-indigo-700 text-[10px] font-black px-2 py-0.5 rounded-full">
                    <?= $unread ?> new
                </span>
                <?php endif; ?>
            </div>
            
            <div class="flex items-center gap-2">
                <?php if($unread > 0): ?>
                <a href="<?= $markAllUrl ?>"
                   class="text-[11px] font-bold text-indigo-600 hover:text-indigo-800 hover:underline transition-colors flex items-center gap-1">
                    <span class="material-icons-round text-[14px]">done_all</span> Mark all read
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Enable Desktop Push Prompt Banner (shown if not granted) -->
        <div id="desktopPushBanner" class="hidden px-4 py-2 bg-gradient-to-r from-indigo-50 to-indigo-100/80 border-b border-indigo-100 flex items-center justify-between gap-2">
            <div class="flex items-center gap-1.5 min-w-0">
                <span class="material-icons-round text-indigo-600 text-sm">notifications_active</span>
                <span class="text-[11px] font-bold text-indigo-900 truncate">Enable desktop popup alerts</span>
            </div>
            <button type="button" id="btnEnableDesktopPush" class="px-2.5 py-1 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-[10px] font-black uppercase tracking-tight shrink-0 transition-all shadow-xs cursor-pointer">
                Allow
            </button>
        </div>

        <!-- Notification List -->
        <div class="max-h-[380px] overflow-y-auto divide-y divide-slate-50 clean-scroll">
            <?php if(count($notifications) > 0): ?>
                <?php foreach($notifications as $n):
                    $meta = getNotifIconMeta($n['type'] ?? '');
                    $isUnread = empty($n['is_read']);
                ?>
                <a href="../notifications_mark.php?id=<?= (int)$n['id'] ?>"
                   class="block p-3.5 hover:bg-slate-50/80 transition-all group <?= $isUnread ? 'bg-indigo-50/20' : '' ?>">
                    <div class="flex items-start gap-3">
                        <div class="w-8 h-8 rounded-xl <?= $meta['bg'] ?> <?= $meta['border'] ?> border flex items-center justify-center shrink-0 mt-0.5">
                            <span class="material-icons-round text-[16px] <?= $meta['color'] ?>"><?= $meta['icon'] ?></span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between gap-2 mb-1">
                                <span class="text-[9px] font-black uppercase tracking-wider px-1.5 py-0.2 rounded <?= $meta['bg'] ?> <?= $meta['color'] ?>">
                                    <?= $meta['badge'] ?>
                                </span>
                                <span class="text-[10px] text-slate-400 font-medium flex items-center gap-0.5">
                                    <span class="material-icons-round text-[11px]">schedule</span>
                                    <?= formatNotifTime($n['created_at']) ?>
                                </span>
                            </div>
                            <p class="text-xs font-bold text-slate-800 leading-snug group-hover:text-indigo-600 transition-colors">
                                <?= htmlspecialchars($n['title']) ?>
                            </p>
                        </div>
                        <?php if($isUnread): ?>
                        <span class="w-2 h-2 rounded-full bg-indigo-500 shrink-0 mt-2"></span>
                        <?php endif; ?>
                    </div>
                </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="py-12 px-4 text-center">
                    <div class="w-12 h-12 rounded-2xl bg-slate-50 text-slate-300 flex items-center justify-center mx-auto mb-2">
                        <span class="material-icons-round text-2xl">notifications_off</span>
                    </div>
                    <p class="text-xs font-bold text-slate-500">No notifications yet</p>
                    <p class="text-[11px] text-slate-400 mt-0.5">All updates and task activities will appear here.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <div class="p-2.5 bg-slate-50/70 border-t border-slate-100 flex items-center justify-center">
            <a href="<?= $historyUrl ?>"
               class="text-xs font-bold text-slate-600 hover:text-indigo-600 flex items-center gap-1.5 transition-colors py-1 px-3 rounded-lg hover:bg-slate-100">
                <span>View Full Notification History</span>
                <span class="material-icons-round text-sm">arrow_forward</span>
            </a>
        </div>
    </div>
</div>

<script>
(function() {
    const btn = document.getElementById('sharedNotifBtn');
    const dropdown = document.getElementById('sharedNotifDropdown');
    if (!btn || !dropdown) return;

    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        dropdown.classList.toggle('hidden');
    });

    document.addEventListener('click', function(e) {
        if (!dropdown.contains(e.target) && !btn.contains(e.target)) {
            dropdown.classList.add('hidden');
        }
    });

    // Check desktop notification permission
    if ('Notification' in window && Notification.permission !== 'granted') {
        const banner = document.getElementById('desktopPushBanner');
        if (banner) banner.classList.remove('hidden');
        document.getElementById('btnEnableDesktopPush')?.addEventListener('click', function(e) {
            e.stopPropagation();
            Notification.requestPermission().then(function(p) {
                if (p === 'granted') {
                    if (banner) banner.classList.add('hidden');
                    location.reload();
                }
            });
        });
    }
})();
</script>
