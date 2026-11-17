<?php
require_once __DIR__ . '/../../bootstrap/app.php';
require_once __DIR__ . '/../../app/middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../app/config/database.php';

date_default_timezone_set('Asia/Kolkata');

$myId       = (int)$_SESSION['user_id'];
$myRole     = $_SESSION['role'] ?? 'user';
$myName     = $_SESSION['name'] ?? $_SESSION['username'] ?? 'User';
$myUsername = $_SESSION['username'] ?? 'user';
$myProfileImage = $_SESSION['profile_image'] ?? null;

if ($myProfileImage === null) {
    try {
        $dbTop = (new Database())->connect();
        $hasImgCol = $dbTop->query("SHOW COLUMNS FROM `users` LIKE 'profile_image'")->fetch();
        if ($hasImgCol) {
            $stmtImg = $dbTop->prepare("SELECT profile_image FROM users WHERE id = :id");
            $stmtImg->execute([':id' => $myId]);
            $myProfileImage = $stmtImg->fetchColumn() ?: '';
            $_SESSION['profile_image'] = $myProfileImage;
        }
    } catch (\Exception $e) {}
}

// Determine dashboard return URL based on role
$dashboardUrl = "../" . strtolower(str_replace(' ', '_', $myRole)) . "/dashboard.php";

// Helper role badge color
function getRoleBadgeClass($role) {
    return match($role) {
        'admin'              => 'bg-rose-50 text-rose-700 border-rose-200',
        'operations_manager' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
        'operations_team'    => 'bg-amber-50 text-amber-700 border-amber-200',
        'bdmanager'          => 'bg-purple-50 text-purple-700 border-purple-200',
        'bde'                => 'bg-sky-50 text-sky-700 border-sky-200',
        'qc'                 => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        default              => 'bg-slate-100 text-slate-700 border-slate-200'
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="format-detection" content="telephone=no">
    <title>DigiChat | Digiink Task Flow</title>
    <link rel="icon" type="image/png" href="../../assets/images/favicon.png">
    <link rel="shortcut icon" href="../../favicon.ico">
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Material Icons Round & Outlined -->
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <!-- Google Fonts: Plus Jakarta Sans & Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Cropper.js (WhatsApp-style circular DP crop) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js"></script>

    <style>
        * {
            font-family: 'Plus Jakarta Sans', sans-serif;
            box-sizing: border-box;
        }

        .chat-text {
            font-family: 'Inter', sans-serif;
        }

        html, body {
            height: 100%;
            height: 100dvh;
            width: 100%;
            width: 100vw;
            margin: 0;
            padding: 0;
            overscroll-behavior-y: none;
            -webkit-text-size-adjust: 100%;
        }

        body {
            background-color: #f1f5f9;
            color: #0f172a;
            overflow: hidden;
            position: fixed;
            inset: 0;
        }

        /* Prevent iPhone iOS Safari from auto-zooming into inputs/textareas */
        input, textarea, select {
            font-size: 16px !important;
        }
        @media (min-width: 640px) {
            input, textarea, select {
                font-size: inherit !important;
            }
        }

        /* Modern subtle scrollbar */
        ::-webkit-scrollbar {
            width: 5px;
            height: 5px;
        }
        ::-webkit-scrollbar-track {
            background: transparent;
        }
        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 9999px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* Message Bubbles */
        .bubble-sent {
            background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%);
            color: #ffffff;
            border-radius: 18px 18px 4px 18px;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.15);
        }

        .bubble-received {
            background: #ffffff;
            color: #0f172a;
            border-radius: 18px 18px 18px 4px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
        }

        /* Multi-Select Message Mode Styles (WhatsApp Web Aesthetic) */
        .msg-select-active .message-row {
            cursor: pointer !important;
            user-select: none;
        }
        .msg-select-active .msg-action-btn {
            display: none !important;
        }
        .msg-row-selected {
            background-color: rgba(99, 102, 241, 0.08);
            border-radius: 1.25rem;
        }

        /* Reply Preview Box on Message Bubble */
        .reply-bubble-preview {
            border-left: 3.5px solid #4f46e5;
            background: rgba(0, 0, 0, 0.04);
            border-radius: 8px;
            padding: 4px 8px;
            margin-bottom: 6px;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .reply-bubble-preview:hover {
            background: rgba(0, 0, 0, 0.07);
        }
        .bubble-sent .reply-bubble-preview {
            border-left-color: #ffffff;
            background: rgba(255, 255, 255, 0.18);
            color: #ffffff;
        }
        .bubble-sent .reply-bubble-preview:hover {
            background: rgba(255, 255, 255, 0.26);
        }

        /* Emoji Reactions Floating Badge on Message */
        .reaction-badge-group {
            display: inline-flex;
            align-items: center;
            gap: 2px;
            position: absolute;
            bottom: -11px;
            z-index: 10;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 9999px;
            padding: 1px 5px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06);
            cursor: pointer;
            transition: transform 0.15s ease;
            user-select: none;
        }
        .reaction-badge-group:hover {
            transform: scale(1.06);
        }
        .reaction-badge-item {
            display: inline-flex;
            align-items: center;
            gap: 2px;
            font-size: 11px;
            line-height: 1;
            padding: 1px 2px;
            border-radius: 9999px;
        }
        .reaction-badge-item.active-mine {
            background-color: #e0e7ff;
            color: #4338ca;
            font-weight: 700;
        }

        /* Quick Reaction Picker Bar on Hover */
        .quick-reaction-bar {
            display: none;
            position: absolute;
            top: -34px;
            z-index: 30;
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(226, 232, 240, 0.9);
            border-radius: 9999px;
            padding: 3px 6px;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.12);
            align-items: center;
            gap: 3px;
            animation: reactionPop 0.15s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            user-select: none;
        }
        .message-row:hover .quick-reaction-bar {
            display: inline-flex;
        }
        @keyframes reactionPop {
            0% { opacity: 0; transform: translateY(6px) scale(0.9); }
            100% { opacity: 1; transform: translateY(0) scale(1); }
        }
        .quick-react-btn {
            font-size: 17px;
            line-height: 1;
            padding: 2px 4px;
            border-radius: 9999px;
            transition: transform 0.15s ease;
            cursor: pointer;
        }
        .quick-react-btn:hover {
            transform: scale(1.35);
        }

        /* DP Zoom & Lightbox Animations */
        .dp-zoom-avatar {
            cursor: pointer !important;
            transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.2s ease;
        }
        .dp-zoom-avatar:hover {
            transform: scale(1.06);
            filter: brightness(1.05);
        }
        @keyframes dpZoomIn {
            0% { opacity: 0; transform: scale(0.7) translateY(20px); }
            100% { opacity: 1; transform: scale(1) translateY(0); }
        }
        .animate-dp-zoom {
            animation: dpZoomIn 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        /* WhatsApp Style Circular Crop Styles (Clean White Theme) */
        .cropper-view-box,
        .cropper-face {
            border-radius: 50% !important;
            outline: 0 !important;
        }
        .cropper-view-box {
            box-shadow: 0 0 0 2px #ffffff, 0 0 0 4000px rgba(0, 0, 0, 0.7) !important;
        }
        .cropper-modal {
            background-color: rgba(0, 0, 0, 0.75) !important;
        }
        .cropper-point {
            background-color: #ffffff !important;
            width: 8px !important;
            height: 8px !important;
            border-radius: 50% !important;
            box-shadow: 0 0 4px rgba(0,0,0,0.5) !important;
        }
        .cropper-line {
            background-color: rgba(255, 255, 255, 0.7) !important;
        }

        /* Wallpaper Cropper Specific: Rectangular / Free-form crop box */
        #wallpaperCropModal .cropper-view-box,
        #wallpaperCropModal .cropper-face {
            border-radius: 12px !important;
        }
        #wallpaperCropModal .cropper-view-box {
            box-shadow: 0 0 0 2px #6366f1, 0 0 0 4000px rgba(0, 0, 0, 0.75) !important;
        }
        #wallpaperCropModal .cropper-point {
            background-color: #6366f1 !important;
            width: 10px !important;
            height: 10px !important;
            border-radius: 2px !important;
            box-shadow: 0 0 6px rgba(99, 102, 241, 0.8) !important;
        }

        /* Resizable Panels Divider Styles */
        .panel-resizer {
            width: 5px;
            background-color: #f1f5f9;
            cursor: col-resize;
            position: relative;
            z-index: 25;
            flex-shrink: 0;
            transition: background-color 0.15s ease;
            user-select: none;
        }
        .panel-resizer:hover,
        .panel-resizer.is-resizing {
            background-color: #6366f1;
        }
        .panel-resizer::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 3px;
            height: 24px;
            border-radius: 2px;
            background-color: #cbd5e1;
            transition: background-color 0.15s ease;
        }
        .panel-resizer:hover::after,
        .panel-resizer.is-resizing::after {
            background-color: #ffffff;
        }

        /* Clean UI: Completely eliminate horizontal/vertical sliders/scrollbars */
        .no-scrollbar::-webkit-scrollbar,
        #messageInput::-webkit-scrollbar {
            display: none !important;
            width: 0 !important;
            height: 0 !important;
        }
        .no-scrollbar,
        #messageInput {
            -ms-overflow-style: none !important;
            scrollbar-width: none !important;
        }
        #messageInput {
            overflow-y: hidden;
            resize: none !important;
        }

        /* Contact active item */
        .contact-item {
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .contact-item:hover {
            background-color: #f8fafc;
        }
        .contact-active {
            background-color: #eef2ff !important;
            border-left: 4px solid #4f46e5;
        }

        /* Subtle modern chat wallpaper (dot matrix) */
        .digichat-canvas {
            background-color: #f8fafc;
            background-image: radial-gradient(#e2e8f0 1.2px, transparent 1.2px);
            background-size: 24px 24px;
        }

        /* Pulse badge */
        @keyframes pulse-soft {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        .badge-pulse {
            animation: pulse-soft 2.5s infinite ease-in-out;
        }

        /* Custom Emoji Grid */
        .emoji-btn {
            font-size: 1.35rem;
            line-height: 2.2rem;
            text-align: center;
            border-radius: 0.5rem;
            cursor: pointer;
            transition: transform 0.15s ease, background-color 0.15s ease;
            user-select: none;
        }
        .emoji-btn:hover {
            background-color: #f1f5f9;
            transform: scale(1.22);
        }

        /* Message action hover button */
        .msg-action-btn {
            opacity: 0;
            transition: opacity 0.15s ease, transform 0.15s ease;
        }
        .message-row:hover .msg-action-btn {
            opacity: 1;
        }

        /* Mobile Responsive Enhancements (Phones <640px) - Desktop Unaffected */
        @media (max-width: 639.98px) {
            /* Active chat open state on mobile: hide sidebar, show main chat area */
            body.mobile-chat-open #chatSidebar {
                display: none !important;
            }
            body.mobile-chat-open #mainChatArea {
                display: flex !important;
                width: 100% !important;
                min-width: 100% !important;
                max-width: 100% !important;
            }

            /* No chat active on mobile: show sidebar full width, hide empty/chat main panel */
            body:not(.mobile-chat-open) #chatSidebar {
                display: flex !important;
                width: 100% !important;
                min-width: 100% !important;
                max-width: 100% !important;
            }
            body:not(.mobile-chat-open) #mainChatArea {
                display: none !important;
            }

            /* Profile Right Panel on mobile overlays as full screen modal */
            #contactInfoRightPanel:not(.hidden) {
                position: fixed !important;
                inset: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
                height: 100% !important;
                z-index: 50 !important;
            }

            /* Message actions on mobile: visible without hover since touch devices have no hover */
            .msg-action-btn {
                opacity: 0.8 !important;
            }

            /* iPhone Bottom Safe Area Padding for Chat Input Bar */
            #chatInputBar {
                padding-bottom: max(0.5rem, env(safe-area-inset-bottom)) !important;
            }
            
            /* iPhone Notch Safe Area for Top Header */
            header {
                padding-top: env(safe-area-inset-top) !important;
                height: calc(3.5rem + env(safe-area-inset-top)) !important;
            }
        }
    </style>
</head>
<body class="flex flex-col select-none antialiased">

    <!-- TOP APP BAR -->
    <header class="h-14 sm:h-16 bg-white border-b border-slate-200/80 px-3 sm:px-6 flex items-center justify-between z-30 shadow-xs flex-shrink-0">
        <!-- Brand & Title + Quick Action Icons (Exit & Logout) -->
        <div class="flex items-center gap-2 sm:gap-3.5">
            <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-xl sm:rounded-2xl bg-gradient-to-tr from-indigo-600 to-indigo-500 flex items-center justify-center text-white shadow-md shadow-indigo-200 flex-shrink-0">
                <span class="material-icons-round text-xl sm:text-2xl">forum</span>
            </div>
            <div>
                <div class="flex items-center gap-1.5 sm:gap-2">
                    <h1 class="text-base sm:text-lg font-extrabold tracking-tight text-slate-900 leading-none">DigiChat</h1>
                </div>
                <p class="text-xs text-slate-400 font-medium hidden sm:block">Role-Protected Team Messenger</p>
            </div>

            <!-- Divider between Brand and Quick Nav Actions -->
            <div class="h-6 w-px bg-slate-200/90 mx-1 sm:mx-2 hidden xs:block"></div>

            <!-- Quick Action Icon Buttons (Exit to Dashboard & Logout) with Hover Tooltip -->
            <div class="flex items-center gap-1.5">
                <!-- Exit to Dashboard (Icon Only + Tooltip) -->
                <div class="relative group">
                    <a href="<?= htmlspecialchars($dashboardUrl) ?>" 
                       title="Exit to Dashboard"
                       class="w-8 h-8 sm:w-9 sm:h-9 flex items-center justify-center bg-slate-100 hover:bg-slate-900 text-slate-700 hover:text-white rounded-xl transition shadow-2xs hover:shadow-xs active:scale-95">
                        <span class="material-icons-round text-base sm:text-lg">dashboard</span>
                    </a>
                    <!-- Hover Tooltip Floating Below -->
                    <div class="absolute left-1/2 -translate-x-1/2 top-full mt-1.5 px-2.5 py-1 bg-slate-900 text-white text-[11px] font-bold rounded-lg shadow-lg whitespace-nowrap opacity-0 group-hover:opacity-100 pointer-events-none transition duration-150 z-50">
                        Exit to Dashboard
                        <div class="absolute -top-1 left-1/2 -translate-x-1/2 w-2 h-2 bg-slate-900 rotate-45"></div>
                    </div>
                </div>

                <!-- Direct Logout (Icon Only + Tooltip) -->
                <div class="relative group">
                    <a href="../../logout.php" 
                       title="Logout"
                       class="w-8 h-8 sm:w-9 sm:h-9 flex items-center justify-center bg-rose-50 hover:bg-rose-600 text-rose-600 hover:text-white border border-rose-200/80 hover:border-rose-600 rounded-xl transition shadow-2xs hover:shadow-xs active:scale-95">
                        <span class="material-icons-round text-base sm:text-lg">logout</span>
                    </a>
                    <!-- Hover Tooltip Floating Below -->
                    <div class="absolute left-1/2 -translate-x-1/2 top-full mt-1.5 px-2.5 py-1 bg-rose-600 text-white text-[11px] font-bold rounded-lg shadow-lg whitespace-nowrap opacity-0 group-hover:opacity-100 pointer-events-none transition duration-150 z-50">
                        Logout
                        <div class="absolute -top-1 left-1/2 -translate-x-1/2 w-2 h-2 bg-rose-600 rotate-45"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Middle / Header Center: Salary Countdown & Motivation Widget (Expanded to utilize empty space) -->
        <div id="salaryCountdownWidget" class="hidden md:flex items-center gap-3 px-4 py-2 bg-gradient-to-r from-emerald-50 via-teal-50/70 to-indigo-50 border border-emerald-200/90 rounded-2xl shadow-2xs cursor-pointer hover:shadow-xs hover:border-emerald-300 transition group mx-4 flex-1 max-w-[640px]" onclick="showSalaryMotivationModal()" title="Click for Salary Motivation & Full Breakdown">
            <div class="w-8 h-8 rounded-xl bg-emerald-600 text-white flex items-center justify-center flex-shrink-0 shadow-xs group-hover:scale-105 transition">
                <span class="material-icons-round text-lg">payments</span>
            </div>
            <div class="leading-tight text-left min-w-0 flex-1">
                <div class="flex items-center gap-2 mb-0.5">
                    <span class="text-[11px] font-black text-emerald-800 tracking-tight flex items-center gap-1.5 flex-shrink-0">
                        <span>Salary Day (7th):</span>
                        <span id="salaryDaysLeftText" class="px-2 py-0.5 rounded-md bg-emerald-600 text-white text-[10px] font-extrabold animate-pulse shadow-2xs">Calculating...</span>
                    </span>
                    <span class="text-[10px] text-slate-400 font-bold hidden lg:inline">• Motivation</span>
                </div>
                <p id="salaryMotivationQuote" class="text-[11px] font-medium text-slate-600 truncate italic">
                    "Work hard in silence, let your success make the noise!"
                </p>
            </div>
            <span class="material-icons-round text-emerald-500 text-base group-hover:translate-x-0.5 transition flex-shrink-0">sentiment_very_satisfied</span>
        </div>

        <!-- Right Side: User Profile Avatar Only (Clickable -> Opens My Profile Drawer) -->
        <div class="flex items-center gap-2 sm:gap-3 flex-shrink-0">
            <div class="relative group">
                <button type="button" id="myProfilePill" onclick="openMyProfileModal()" title="View and customize My Profile"
                    class="relative flex items-center justify-center p-0.5 rounded-full hover:ring-2 hover:ring-indigo-400 focus:outline-none transition cursor-pointer">
                    <div class="relative">
                        <div id="myTopAvatar" class="w-9 h-9 sm:w-10 sm:h-10 rounded-full bg-gradient-to-tr from-indigo-600 to-indigo-500 text-white flex items-center justify-center text-xs sm:text-sm font-black shadow-sm overflow-hidden dp-zoom-avatar border-2 border-white ring-1 ring-slate-200 group-hover:ring-indigo-400 transition">
                            <?php if (!empty($myProfileImage)): ?>
                                <img src="<?= htmlspecialchars($myProfileImage) ?>" alt="<?= htmlspecialchars($myName) ?>" class="w-full h-full object-cover rounded-full" />
                            <?php else: ?>
                                <span><?= strtoupper(substr($myName, 0, 1)) ?></span>
                            <?php endif; ?>
                        </div>
                        <!-- Online Status Indicator -->
                        <span class="absolute bottom-0 right-0 w-2.5 h-2.5 sm:w-3 sm:h-3 rounded-full bg-emerald-500 border-2 border-white shadow-xs"></span>
                    </div>
                    <!-- Hidden helper element preserved for JavaScript bindings -->
                    <span id="myTopName" class="hidden"><?= htmlspecialchars($myName) ?></span>
                </button>
                <!-- Profile Tooltip -->
                <div class="absolute right-0 top-full mt-1.5 px-2.5 py-1 bg-slate-900 text-white text-[11px] font-bold rounded-lg shadow-lg whitespace-nowrap opacity-0 group-hover:opacity-100 pointer-events-none transition duration-150 z-50">
                    <?= htmlspecialchars($myName) ?> (My Profile)
                    <div class="absolute -top-1 right-3 w-2 h-2 bg-slate-900 rotate-45"></div>
                </div>
            </div>
        </div>
    </header>

    <!-- MAIN CHAT CONTAINER -->
    <div class="flex-1 flex overflow-hidden relative">

        <!-- ========================================================
             LEFT SIDEBAR: CONTACT LIST & SEARCH
             ======================================================== -->
        <aside id="chatSidebar" class="w-full sm:w-[320px] md:w-[350px] lg:w-[360px] min-w-[260px] max-w-[550px] bg-white border-r border-slate-200/80 flex flex-col flex-shrink-0 z-20">
            
            <!-- Sidebar Header & Action -->
            <div class="p-4 pb-2 border-b border-slate-100">
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center gap-2">
                        <h2 class="text-lg font-extrabold text-slate-900 tracking-tight">Conversations</h2>
                        <span id="contactCountLabel" class="text-[11px] font-bold text-slate-500 bg-slate-100 px-2 py-0.5 rounded-full border border-slate-200">0</span>
                        <span id="unreadTotalBadge" class="hidden px-2 py-0.5 bg-indigo-600 text-white text-[11px] font-bold rounded-full badge-pulse">0</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <button onclick="openCreateGroupModal()" title="Create New Group" class="px-2.5 py-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 rounded-xl transition flex items-center gap-1 text-xs font-bold border border-indigo-200/80 active:scale-95 shadow-2xs">
                            <span class="material-icons-round text-base text-indigo-600">group_add</span>
                            <span>New Group</span>
                        </button>
                        <button onclick="refreshContacts()" title="Refresh Contacts" class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-slate-50 rounded-xl transition">
                            <span class="material-icons-round text-lg">sync</span>
                        </button>
                    </div>
                </div>

                <!-- Modern Search Bar -->
                <div class="relative mb-3">
                    <span class="material-icons-round absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm">search</span>
                    <input type="text" id="contactSearchInput" placeholder="Search members, groups or roles..." 
                        class="w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-200/80 rounded-xl text-xs focus:bg-white focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 outline-none transition font-medium text-slate-800 placeholder-slate-400">
                </div>

                <!-- Category Filters (All / Direct / Groups / Unread / Calls) - Clean 5-column grid without scrollbar -->
                <div class="grid grid-cols-5 gap-1 text-xs font-bold no-scrollbar select-none">
                    <button type="button" onclick="filterCategory('all')" id="btnFilterAll" 
                        class="py-1.5 px-1 rounded-xl bg-indigo-600 text-white shadow-xs transition text-center truncate">
                        All
                    </button>
                    <button type="button" onclick="filterCategory('direct')" id="btnFilterDirect" 
                        class="py-1.5 px-1 rounded-xl bg-slate-100 text-slate-600 hover:bg-slate-200 transition text-center truncate">
                        Direct
                    </button>
                    <button type="button" onclick="filterCategory('groups')" id="btnFilterGroups" 
                        class="py-1.5 px-1 rounded-xl bg-slate-100 text-slate-600 hover:bg-slate-200 transition flex items-center justify-center gap-0.5 truncate">
                        <span>Groups</span>
                        <span id="groupTabCount" class="hidden text-[10px] bg-indigo-100 text-indigo-700 px-1 rounded-full font-black">0</span>
                    </button>
                    <button type="button" onclick="filterCategory('unread')" id="btnFilterUnread" 
                        class="py-1.5 px-1 rounded-xl bg-slate-100 text-slate-600 hover:bg-slate-200 transition text-center truncate">
                        Unread
                    </button>
                    <button type="button" onclick="filterCategory('calls')" id="btnFilterCalls" 
                        class="py-1.5 px-1 rounded-xl bg-slate-100 text-slate-600 hover:bg-slate-200 transition flex items-center justify-center gap-1 truncate">
                        <span class="material-icons-round text-xs text-emerald-600">call</span>
                        <span>Calls</span>
                    </button>
                </div>
            </div>

            <!-- Contacts Scroll List -->
            <div id="contactsContainer" class="flex-1 overflow-y-auto divide-y divide-slate-100">
                <!-- Contacts rendered by JS -->
                <div class="py-20 text-center text-slate-400 text-xs">
                    <span class="material-icons-round animate-spin text-2xl text-indigo-500 mb-2">sync</span>
                    <p class="font-medium">Loading your authorized team members...</p>
                </div>
            </div>

            <!-- Role Matrix & E2EE Security Footer Notice -->
            <div class="p-3 bg-slate-50/80 border-t border-slate-200/80 text-center text-[10px] text-slate-400 font-semibold flex items-center justify-center gap-1.5">
                <span class="material-icons-round text-xs text-emerald-600">lock</span>
                <span>End-to-End Encrypted • AES-256 Cipher Hash DB Protection</span>
            </div>
        </aside>

        <!-- Left Sidebar Resizer Divider (Drag to resize conversations list) -->
        <div id="leftPanelResizer" class="hidden sm:block panel-resizer" title="Drag to adjust sidebar width"></div>

        <!-- ========================================================
             RIGHT PANEL: ACTIVE CHAT SCREEN
             ======================================================== -->
        <main id="mainChatArea" class="flex-1 min-w-0 flex flex-col bg-[#f8fafc] relative overflow-hidden">

            <!-- DRAG & DROP OVERLAY DOCK -->
            <div id="chatDropzoneOverlay" class="hidden absolute inset-0 z-50 bg-indigo-600/90 backdrop-blur-md flex flex-col items-center justify-center text-white border-4 border-dashed border-white/60 m-4 rounded-3xl transition-all pointer-events-none">
                <div class="w-20 h-20 rounded-full bg-white/20 flex items-center justify-center mb-4 animate-bounce">
                    <span class="material-icons-round text-5xl text-white">cloud_upload</span>
                </div>
                <h3 class="text-xl font-extrabold tracking-tight">Drop file to attach</h3>
                <p class="text-xs text-indigo-100 mt-1 font-semibold">Release to attach file to current chat</p>
            </div>

            <!-- 1. EMPTY PLACEHOLDER STATE (When no contact is selected) -->
            <div id="emptyChatState" class="flex-1 flex flex-col items-center justify-center p-8 text-center digichat-canvas">
                <div class="w-20 h-20 rounded-3xl bg-white border border-slate-200 shadow-xl shadow-slate-200/50 flex items-center justify-center mb-6">
                    <span class="material-icons-round text-4xl text-indigo-600">chat_bubble_outline</span>
                </div>
                <h3 class="text-xl font-extrabold text-slate-800 tracking-tight">Welcome to DigiChat</h3>
                <p class="text-sm text-slate-500 max-w-md mt-2 leading-relaxed font-medium">
                    Select an authorized team member from the left panel to start a secure, real-time enterprise conversation.
                </p>

                <div class="mt-8 grid grid-cols-1 sm:grid-cols-3 gap-4 max-w-xl text-left">
                    <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-xs">
                        <span class="material-icons-round text-emerald-600 text-xl mb-1">lock</span>
                        <h4 class="text-xs font-bold text-slate-800">End-to-End Encrypted</h4>
                        <p class="text-[11px] text-slate-500 mt-0.5">Stored in AES-256 cipher hash format in MySQL.</p>
                    </div>
                    <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-xs">
                        <span class="material-icons-round text-indigo-600 text-xl mb-1">done_all</span>
                        <h4 class="text-xs font-bold text-slate-800">Real-Time Ticks</h4>
                        <p class="text-[11px] text-slate-500 mt-0.5">Single tick, double tick, and live blue ticks.</p>
                    </div>
                    <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-xs">
                        <span class="material-icons-round text-amber-600 text-xl mb-1">videocam</span>
                        <h4 class="text-xs font-bold text-slate-800">Voice & Video Calls</h4>
                        <p class="text-[11px] text-slate-500 mt-0.5">WebRTC calls with filters & screen fit.</p>
                    </div>
                </div>
            </div>

            <!-- 2. ACTIVE CHAT TOP BAR WITH REAL-TIME ONLINE / LAST SEEN -->
            <div id="activeChatHeader" class="hidden h-16 bg-white border-b border-slate-200/80 px-4 sm:px-6 flex items-center justify-between z-10 shadow-xs flex-shrink-0 gap-2 min-w-0">
                <div class="flex items-center gap-2.5 sm:gap-3.5 min-w-0 flex-1">
                    <!-- Mobile Back button -->
                    <button onclick="closeActiveChat()" class="sm:hidden p-2 text-slate-400 hover:text-slate-700 -ml-2 rounded-xl flex-shrink-0">
                        <span class="material-icons-round text-lg">arrow_back</span>
                    </button>

                    <!-- Contact Avatar & Info (Clickable -> Opens Profile Modal) -->
                    <div id="activeContactClickableArea" onclick="handleActiveHeaderProfileClick()" 
                         title="View user profile details"
                         class="flex items-center gap-2.5 sm:gap-3 cursor-pointer group p-1 -m-1 rounded-2xl hover:bg-slate-50/80 transition min-w-0 flex-1">
                        <!-- Contact Avatar with Dynamic Online/Offline Dot -->
                        <div class="relative flex-shrink-0" onclick="event.stopPropagation(); zoomActiveContactDp()" title="Click to zoom / view full profile picture">
                            <div id="activeAvatar" class="w-10 h-10 sm:w-11 sm:h-11 rounded-full bg-indigo-50 border-2 border-white ring-1 ring-slate-200 text-indigo-700 flex items-center justify-center font-black text-sm shadow-xs overflow-hidden dp-zoom-avatar">
                                U
                            </div>
                            <span id="activeAvatarDot" class="absolute bottom-0 right-0 w-3 h-3 rounded-full bg-slate-300 border-2 border-white"></span>
                        </div>

                        <!-- Contact Info + Dynamic Real-Time Presence Status -->
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-1.5 min-w-0">
                                <h2 id="activeName" class="text-sm sm:text-base font-extrabold text-slate-900 group-hover:text-indigo-600 transition leading-tight truncate">Team Member</h2>
                                <span id="activeRoleBadge" class="hidden flex-shrink-0"></span>
                            </div>
                            <!-- Real-time Presence Container (Online / Last seen today at ...) -->
                            <div id="activePresenceArea" class="mt-0.5 truncate">
                                <div class="flex items-center gap-1 text-slate-400 text-[11px] font-medium truncate">
                                    <span class="material-icons-round text-xs flex-shrink-0">schedule</span>
                                    <span class="truncate">Loading status...</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Actions: Voice Call, Video Call, Close -->
                <div class="flex items-center gap-0.5 sm:gap-1.5 flex-shrink-0 ml-1 sm:ml-2">
                    <!-- Group Info Button (Visible only in Group Chat) -->
                    <button type="button" id="groupInfoBtn" onclick="openGroupInfoModal()" title="Group Info & Members" 
                        class="hidden p-1.5 sm:px-3 sm:py-2 text-indigo-700 hover:text-indigo-800 bg-indigo-50 hover:bg-indigo-100 rounded-xl transition flex items-center gap-1.5 font-bold text-xs border border-indigo-200/90 shadow-2xs">
                        <span class="material-icons-round text-base text-indigo-600">groups</span>
                        <span class="hidden 2xl:inline">Group Info</span>
                    </button>

                    <!-- Voice Call Button -->
                    <button type="button" id="voiceCallBtn" onclick="startCall('voice')" title="Start Voice Call" 
                        class="p-1.5 sm:px-2.5 sm:py-1.5 text-slate-700 hover:text-emerald-700 hover:bg-emerald-50 rounded-xl transition flex items-center gap-1 font-bold text-xs border border-slate-200/90 hover:border-emerald-300 shadow-2xs active:scale-95">
                        <span class="material-icons-round text-base text-emerald-600">call</span>
                        <span class="hidden 2xl:inline">Voice</span>
                    </button>

                    <!-- Video Call Button -->
                    <button type="button" id="videoCallBtn" onclick="startCall('video')" title="Start Video Call" 
                        class="p-1.5 sm:px-2.5 sm:py-1.5 text-slate-700 hover:text-indigo-700 hover:bg-indigo-50 rounded-xl transition flex items-center gap-1 font-bold text-xs border border-slate-200/90 hover:border-indigo-300 shadow-2xs active:scale-95">
                        <span class="material-icons-round text-base text-indigo-600">videocam</span>
                        <span class="hidden 2xl:inline">Video</span>
                    </button>

                    <!-- Set / Edit Nickname Button (Direct Chat) -->
                    <button type="button" id="headerNicknameBtn" onclick="openNicknameModal(activeContactId)" title="Add / Edit Contact Nickname" 
                        class="p-1.5 sm:px-2.5 sm:py-1.5 text-slate-700 hover:text-amber-700 hover:bg-amber-50 rounded-xl transition flex items-center gap-1 font-bold text-xs border border-slate-200/90 hover:border-amber-300 shadow-2xs active:scale-95">
                        <span class="material-icons-round text-base text-amber-500">badge</span>
                        <span class="hidden 2xl:inline">Nickname</span>
                    </button>

                    <!-- Chat Wallpaper / Cover Photo Button (WhatsApp Style) -->
                    <button type="button" id="headerWallpaperBtn" onclick="openWallpaperModal()" title="Change Chat Wallpaper / Background" 
                        class="p-1.5 sm:px-2.5 sm:py-1.5 text-slate-700 hover:text-indigo-700 hover:bg-indigo-50 rounded-xl transition flex items-center gap-1 font-bold text-xs border border-slate-200/90 hover:border-indigo-300 shadow-2xs active:scale-95">
                        <span class="material-icons-round text-base text-indigo-600">wallpaper</span>
                        <span class="hidden 2xl:inline">Wallpaper</span>
                    </button>

                    <!-- Hidden helper buttons preserved for JS binding (no styling/dimensions) -->
                    <div class="hidden">
                        <button type="button" id="blockContactBtn" onclick="toggleBlockContact()"></button>
                        <button type="button" id="inChatSearchToggleBtn" onclick="toggleInChatSearch()"></button>
                        <button type="button" id="selectMessagesHeaderBtn" onclick="enterMessageSelectMode()"></button>
                    </div>

                    <div class="h-5 w-px bg-slate-200/80 mx-0.5 hidden sm:block"></div>

                    <!-- Close Chat (Desktop only, mobile has arrow_back) -->
                    <button type="button" onclick="closeActiveChat()" title="Close conversation" class="hidden sm:flex p-1.5 sm:p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-xl transition items-center justify-center">
                        <span class="material-icons-round text-lg">close</span>
                    </button>
                </div>
            </div>

            <!-- IN-CHAT MESSAGE SEARCH BAR (WhatsApp Style) -->
            <div id="inChatSearchBar" class="hidden h-14 bg-white border-b border-slate-200/90 px-4 sm:px-6 flex items-center justify-between z-20 shadow-xs flex-shrink-0 animate-in fade-in slide-in-from-top-1 duration-150">
                <div class="flex items-center gap-3 flex-1 max-w-xl">
                    <span class="material-icons-round text-slate-400 text-lg">search</span>
                    <input type="text" id="inChatSearchInput" 
                        placeholder="Search messages in this chat..." 
                        oninput="handleInChatSearchInput(this.value)"
                        onkeydown="handleInChatSearchKeydown(event)"
                        class="w-full bg-slate-100/80 focus:bg-white text-xs text-slate-800 placeholder-slate-400 font-medium px-3.5 py-2 rounded-xl border border-transparent focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100 transition" />
                </div>
                <div class="flex items-center gap-2">
                    <span id="inChatSearchCount" class="text-xs font-semibold text-slate-400 px-2 select-none">0 results</span>
                    <button type="button" id="inChatSearchPrevBtn" onclick="navigateInChatSearch('prev')" title="Previous match (Shift+Enter)" 
                        class="p-1.5 text-slate-500 hover:text-indigo-600 hover:bg-slate-100 rounded-lg transition disabled:opacity-30 disabled:pointer-events-none">
                        <span class="material-icons-round text-lg">keyboard_arrow_up</span>
                    </button>
                    <button type="button" id="inChatSearchNextBtn" onclick="navigateInChatSearch('next')" title="Next match (Enter)" 
                        class="p-1.5 text-slate-500 hover:text-indigo-600 hover:bg-slate-100 rounded-lg transition disabled:opacity-30 disabled:pointer-events-none">
                        <span class="material-icons-round text-lg">keyboard_arrow_down</span>
                    </button>
                    <div class="h-4 w-px bg-slate-200 mx-1"></div>
                    <button type="button" onclick="closeInChatSearch()" title="Close search (Esc)" 
                        class="p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition">
                        <span class="material-icons-round text-lg">close</span>
                    </button>
                </div>
            </div>

            <!-- WHATSAPP STYLE MULTI-SELECT ACTION TOOLBAR -->
            <div id="chatSelectionBar" class="hidden h-16 bg-slate-900 border-b border-slate-800 text-white px-4 sm:px-6 flex items-center justify-between z-20 shadow-md flex-shrink-0 animate-in fade-in duration-150">
                <div class="flex items-center gap-3">
                    <!-- Close selection mode -->
                    <button type="button" onclick="exitMessageSelectMode()" title="Cancel selection (Esc)" 
                        class="p-2 text-slate-400 hover:text-white hover:bg-slate-800 rounded-xl transition flex items-center justify-center">
                        <span class="material-icons-round text-xl">close</span>
                    </button>
                    <!-- Selected Count -->
                    <div class="flex items-center gap-2">
                        <span id="selectedCountBadge" class="px-2.5 py-0.5 rounded-full bg-indigo-600 text-white text-xs font-black">1</span>
                        <span id="selectedCountText" class="text-sm font-bold text-slate-100">selected</span>
                    </div>
                </div>
                
                <div class="flex items-center gap-2 sm:gap-2.5">
                    <!-- Select All / Deselect All Toggle -->
                    <button type="button" id="selectAllBtn" onclick="toggleSelectAllMessages()" title="Select all messages"
                        class="px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs font-bold text-slate-200 transition flex items-center gap-1.5 border border-slate-700/80 active:scale-95">
                        <span class="material-icons-round text-base text-indigo-400" id="selectAllIcon">select_all</span>
                        <span id="selectAllText" class="hidden sm:inline">Select All</span>
                    </button>

                    <div class="h-6 w-px bg-slate-800 mx-0.5 sm:mx-1"></div>

                    <!-- Copy Selected Messages -->
                    <button type="button" onclick="copySelectedMessages()" title="Copy selected text"
                        class="px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs font-bold text-slate-200 transition flex items-center gap-1.5 border border-slate-700/80 active:scale-95">
                        <span class="material-icons-round text-base text-emerald-400">content_copy</span>
                        <span>Copy</span>
                    </button>

                    <!-- Forward Selected Messages -->
                    <button type="button" onclick="forwardSelectedMessages()" title="Forward selected messages"
                        class="px-3.5 py-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-xs font-bold text-white transition flex items-center gap-1.5 shadow-sm active:scale-95">
                        <span class="material-icons-round text-base">shortcut</span>
                        <span>Forward</span>
                    </button>
                </div>
            </div>

            <!-- BLOCKED CONTACT NOTIFICATION BANNER -->
            <div id="blockedBanner" class="hidden bg-rose-50/95 border-b border-rose-200/80 px-6 py-2.5 flex items-center justify-between text-xs text-rose-800 z-10 shadow-2xs">
                <div class="flex items-center gap-2">
                    <span class="material-icons-round text-base text-rose-600">block</span>
                    <span id="blockedBannerText" class="font-semibold">You have blocked this contact. Unblock them to send messages or start calls.</span>
                </div>
                <button type="button" id="bannerUnblockBtn" onclick="toggleBlockContact()" class="px-3 py-1 bg-rose-600 hover:bg-rose-700 text-white rounded-lg text-xs font-bold transition shadow-xs">
                    Unblock
                </button>
            </div>

            <!-- ACTIVE GROUP CALL BANNER (Real-time Join) -->
            <div id="activeGroupCallBanner" class="hidden bg-emerald-600 text-white px-5 sm:px-6 py-2.5 flex items-center justify-between text-xs z-10 shadow-sm border-b border-emerald-700">
                <div class="flex items-center gap-3">
                    <span class="w-8 h-8 rounded-xl bg-emerald-500/80 flex items-center justify-center shadow-xs">
                        <span id="activeGroupCallIcon" class="material-icons-round text-lg text-white animate-bounce">ring_volume</span>
                    </span>
                    <div>
                        <div class="flex items-center gap-2">
                            <span id="activeGroupCallTitle" class="font-bold text-white">Group Call is Live</span>
                            <span class="w-2 h-2 rounded-full bg-emerald-300 animate-ping"></span>
                        </div>
                        <span id="activeGroupCallSubtitle" class="text-emerald-100 text-[11px] block mt-0.5">A call is currently in progress in this group</span>
                    </div>
                </div>
                <button type="button" onclick="joinActiveGroupCall()" class="px-3.5 py-1.5 bg-white text-emerald-800 hover:bg-emerald-50 rounded-xl text-xs font-black transition shadow-sm flex items-center gap-1.5 active:scale-95">
                    <span class="material-icons-round text-sm text-emerald-700">call</span>
                    <span>Join Call</span>
                </button>
            </div>

            <!-- 3. MESSAGES SCROLL CONTAINER (Date-grouped & action menus) -->
            <div id="messagesContainer" class="hidden flex-1 overflow-y-auto p-4 sm:p-6 space-y-3.5 digichat-canvas">
                <!-- Messages appended dynamically -->
            </div>

            <!-- WhatsApp Style Typing Indicator Floating Pill -->
            <div id="typingBubble" class="hidden px-6 py-2 bg-transparent z-10 animate-in fade-in duration-150">
                <div class="inline-flex items-center gap-2 bg-white/95 backdrop-blur-md border border-slate-200/90 px-3.5 py-1.5 rounded-2xl rounded-bl-sm shadow-sm">
                    <div class="flex items-center gap-1">
                        <span class="w-1.5 h-1.5 rounded-full bg-indigo-600 animate-bounce" style="animation-delay: 0ms"></span>
                        <span class="w-1.5 h-1.5 rounded-full bg-indigo-600 animate-bounce" style="animation-delay: 150ms"></span>
                        <span class="w-1.5 h-1.5 rounded-full bg-indigo-600 animate-bounce" style="animation-delay: 300ms"></span>
                    </div>
                    <span class="text-xs font-semibold text-slate-500" id="typingBubbleText">typing...</span>
                </div>
            </div>

            <!-- WhatsApp Style Reply Preview Dock (When replying to a message) -->
            <div id="replyPreviewBox" class="hidden px-4 sm:px-6 py-2.5 bg-slate-50 border-t border-slate-200/90 flex items-center justify-between gap-3 animate-in fade-in duration-150 z-20">
                <div class="flex items-center gap-3 min-w-0 flex-1 border-l-4 border-indigo-600 pl-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-1.5 mb-0.5">
                            <span class="material-icons-round text-xs text-indigo-600">reply</span>
                            <span id="replyPreviewSender" class="text-xs font-black text-indigo-700 truncate">Replying to...</span>
                        </div>
                        <p id="replyPreviewText" class="text-xs text-slate-600 font-medium truncate max-w-xl">Message text preview</p>
                    </div>
                </div>
                <button type="button" onclick="cancelReply()" title="Cancel Reply" class="p-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-xl transition flex-shrink-0">
                    <span class="material-icons-round text-base">close</span>
                </button>
            </div>

            <!-- 4. ATTACHMENT PREVIEW DOCK (If file chosen) -->
            <div id="attachmentPreviewBox" class="hidden px-6 py-3 bg-white border-t border-slate-200 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                        <span class="material-icons-round text-xl">insert_drive_file</span>
                    </div>
                    <div>
                        <p id="attachmentPreviewName" class="text-xs font-bold text-slate-800 truncate max-w-xs">document.pdf</p>
                        <p id="attachmentPreviewSize" class="text-[10px] text-slate-400 font-medium">0 KB</p>
                    </div>
                </div>
                <button onclick="cancelAttachment()" class="p-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-xl transition">
                    <span class="material-icons-round text-lg">close</span>
                </button>
            </div>

            <!-- 5. COMPREHENSIVE POPUP EMOJI PICKER -->
            <div id="emojiPicker" class="hidden absolute bottom-16 sm:bottom-20 left-2 right-2 sm:right-auto sm:left-6 z-50 max-w-[calc(100vw-1rem)] sm:w-96 bg-white rounded-2xl shadow-2xl border border-slate-200/90 overflow-hidden flex flex-col transition-all">
                
                <!-- Category Tabs -->
                <div class="flex items-center justify-between bg-slate-50 border-b border-slate-200 px-3 py-2 text-xs font-bold text-slate-600">
                    <div class="flex items-center gap-1">
                        <button type="button" onclick="switchEmojiCategory('smileys')" id="tab-smileys" class="px-2.5 py-1 rounded-lg bg-indigo-600 text-white font-bold transition">😀</button>
                        <button type="button" onclick="switchEmojiCategory('gestures')" id="tab-gestures" class="px-2.5 py-1 rounded-lg hover:bg-slate-200 transition">👍</button>
                        <button type="button" onclick="switchEmojiCategory('reactions')" id="tab-reactions" class="px-2.5 py-1 rounded-lg hover:bg-slate-200 transition">❤️</button>
                        <button type="button" onclick="switchEmojiCategory('work')" id="tab-work" class="px-2.5 py-1 rounded-lg hover:bg-slate-200 transition">💼</button>
                        <button type="button" onclick="switchEmojiCategory('status')" id="tab-status" class="px-2.5 py-1 rounded-lg hover:bg-slate-200 transition">⭐</button>
                    </div>
                    <button type="button" onclick="toggleEmojiPicker()" class="p-1 text-slate-400 hover:text-slate-600 rounded-lg">
                        <span class="material-icons-round text-base">close</span>
                    </button>
                </div>

                <!-- Emoji Search Bar -->
                <div class="p-2 border-b border-slate-100 bg-white">
                    <div class="relative">
                        <span class="material-icons-round absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm">search</span>
                        <input type="text" id="emojiSearchInput" placeholder="Search emojis..." 
                            class="w-full pl-8 pr-3 py-1.5 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:bg-white focus:ring-1 focus:ring-indigo-500 outline-none transition font-medium">
                    </div>
                </div>

                <!-- Emoji Grid Container -->
                <div id="emojiGridContainer" class="p-3 max-h-56 overflow-y-auto grid grid-cols-7 sm:grid-cols-8 gap-1.5">
                    <!-- Dynamic emojis populated by JS -->
                </div>
            </div>

            <!-- 6. CHAT INPUT BAR -->
            <div id="chatInputBar" class="hidden p-2 sm:p-3.5 bg-white border-t border-slate-200/80 flex items-center gap-1 sm:gap-2 z-20 flex-shrink-0 min-w-0">
                
                <!-- Emoji toggle button -->
                <button type="button" id="emojiToggleBtn" onclick="toggleEmojiPicker()" title="Choose Emoji" 
                    class="p-1.5 sm:p-2 text-slate-400 hover:text-indigo-600 hover:bg-slate-100 rounded-xl transition flex-shrink-0">
                    <span class="material-icons-round text-lg sm:text-xl">sentiment_satisfied_alt</span>
                </button>

                <!-- Hidden file input -->
                <input type="file" id="fileInput" class="hidden" onchange="handleFileSelected(this)">

                <!-- Attachment button -->
                <button type="button" id="fileAttachmentBtn" onclick="document.getElementById('fileInput').click()" title="Attach Document / Image" 
                    class="p-1.5 sm:p-2 text-slate-400 hover:text-indigo-600 hover:bg-slate-100 rounded-xl transition flex-shrink-0">
                    <span class="material-icons-round text-lg sm:text-xl">attach_file</span>
                </button>

                <!-- Camera Quick Photo Button (WhatsApp Style) -->
                <button type="button" onclick="openCameraModal()" title="Take Photo with Camera" 
                    class="hidden xs:flex sm:flex p-1.5 sm:p-2 text-slate-400 hover:text-indigo-600 hover:bg-slate-100 rounded-xl transition flex-shrink-0 items-center justify-center">
                    <span class="material-icons-round text-lg sm:text-xl">photo_camera</span>
                </button>

                <!-- View Once Mode Toggle for text/messages (WhatsApp 1-icon) -->
                <button type="button" id="chatViewOnceBtn" onclick="toggleChatViewOnce()" title="Send as View Once (Disappears after opening)" 
                    class="hidden xs:flex sm:flex w-7 h-7 sm:w-8 sm:h-8 rounded-full border border-slate-300 text-slate-400 hover:text-indigo-600 hover:border-indigo-400 items-center justify-center font-black text-xs transition select-none flex-shrink-0">
                    <span class="w-4 h-4 sm:w-5 sm:h-5 rounded-full border border-current flex items-center justify-center text-[9px] sm:text-[10px] font-bold">1</span>
                </button>

                <!-- Text message input (Multi-line Shift+Enter support) -->
                <div class="flex-1 min-w-[50px] relative flex items-center">
                    <textarea id="messageInput" rows="1" placeholder="Message..." autocomplete="off"
                        class="chat-text w-full py-2 sm:py-2.5 px-3 sm:px-4 bg-slate-50 border border-slate-200/80 rounded-2xl text-base sm:text-sm focus:bg-white focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 outline-none transition font-medium text-slate-800 placeholder-slate-400 resize-none max-h-32 leading-relaxed overflow-y-auto"></textarea>
                </div>

                <!-- Mic / Voice Note button -->
                <button type="button" id="voiceRecordBtn" onclick="startVoiceRecording()" title="Record Voice Note" 
                    class="p-1.5 sm:p-2 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-xl transition flex items-center justify-center flex-shrink-0">
                    <span class="material-icons-round text-lg sm:text-xl">mic</span>
                </button>

                <!-- Send button -->
                <button type="button" id="sendBtn" onclick="sendMessage()" title="Send Message"
                    class="p-2 sm:p-2.5 bg-indigo-600 hover:bg-indigo-700 active:scale-95 text-white rounded-xl sm:rounded-2xl shadow-md shadow-indigo-200 transition flex items-center justify-center disabled:opacity-50 flex-shrink-0">
                    <span class="material-icons-round text-base sm:text-lg">send</span>
                </button>
            </div>

            <!-- 7. ACTIVE VOICE NOTE RECORDING BAR (WhatsApp Style) -->
            <div id="voiceRecordingBar" class="hidden p-3.5 bg-rose-50/90 border-t border-rose-200/80 flex items-center justify-between gap-3 z-20 flex-shrink-0 animate-in fade-in duration-200">
                <div class="flex items-center gap-3">
                    <span class="relative flex h-3 w-3">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-rose-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-3 w-3 bg-rose-500"></span>
                    </span>
                    <span class="text-xs font-bold text-rose-700 uppercase tracking-wider flex items-center gap-1">
                        Recording Audio
                    </span>
                    <span id="recordingTimer" class="font-mono text-sm font-extrabold text-slate-800 bg-white px-2.5 py-1 rounded-xl border border-rose-200 shadow-xs">00:00</span>
                    <div class="flex items-center gap-0.5 h-4">
                        <span class="w-1 bg-rose-400 rounded-full animate-pulse h-3"></span>
                        <span class="w-1 bg-rose-500 rounded-full animate-pulse h-4"></span>
                        <span class="w-1 bg-rose-400 rounded-full animate-pulse h-2"></span>
                        <span class="w-1 bg-rose-600 rounded-full animate-pulse h-4"></span>
                        <span class="w-1 bg-rose-400 rounded-full animate-pulse h-3"></span>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="cancelVoiceRecording()" title="Cancel Recording"
                        class="px-3.5 py-2 text-xs font-bold text-slate-600 hover:text-rose-700 hover:bg-rose-100/80 rounded-xl transition flex items-center gap-1">
                        <span class="material-icons-round text-base">delete</span> Cancel
                    </button>
                    <button type="button" onclick="stopAndSendVoiceRecording()" title="Send Voice Note"
                        class="px-4 py-2 bg-gradient-to-r from-rose-500 to-indigo-600 hover:from-rose-600 hover:to-indigo-700 text-white text-xs font-extrabold rounded-xl shadow-md transition flex items-center gap-1.5 active:scale-95">
                        <span class="material-icons-round text-base">send</span> Send Voice
                    </button>
                </div>
            </div>

        </main>

        <!-- Right Contact Info Resizer Divider (Drag to adjust profile drawer width) -->
        <div id="rightPanelResizer" class="hidden panel-resizer" title="Drag to adjust profile width"></div>

        <!-- ========================================================
             WHATSAPP-STYLE RIGHT SIDE PANEL: CONTACT INFO / PROFILE
             ======================================================== -->
        <aside id="contactInfoRightPanel" class="hidden w-full sm:w-[320px] md:w-[340px] lg:w-[360px] min-w-[280px] max-w-[550px] bg-white border-l border-slate-200/90 flex flex-col h-full z-30 flex-shrink-0 animate-in slide-in-from-right duration-200 shadow-xl overflow-hidden">
            
            <!-- Drawer Header (Contact info + Close + Edit DP) -->
            <div class="h-16 px-5 border-b border-slate-100 flex items-center justify-between bg-white flex-shrink-0">
                <div class="flex items-center gap-3">
                    <button type="button" onclick="closeContactInfoPanel()" title="Close contact info" class="p-2 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-xl transition">
                        <span class="material-icons-round text-xl">close</span>
                    </button>
                    <h3 class="text-base font-extrabold text-slate-900 tracking-tight">Contact info</h3>
                </div>
                <div class="flex items-center gap-1">
                    <!-- Edit DP pencil (only shown when viewing own profile) -->
                    <button type="button" id="rightPanelEditDpBtn" onclick="triggerDpFilePicker()" title="Change profile picture" class="hidden p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-xl transition">
                        <span class="material-icons-round text-lg">edit</span>
                    </button>
                </div>
            </div>

            <!-- Drawer Scrollable Body -->
            <div class="flex-1 overflow-y-auto divide-y divide-slate-100 custom-scrollbar">
                
                <!-- 1. Top Hero Section: Big Circular Avatar + Name + Phone / Username -->
                <div class="p-6 flex flex-col items-center text-center bg-white">
                    <!-- Big Circular Avatar with WhatsApp styling & click-to-zoom -->
                    <div class="relative group my-2">
                        <div id="rightPanelBigAvatar" onclick="zoomRightPanelDp()" title="Click to zoom profile photo" class="w-32 h-32 rounded-full bg-slate-900 text-white font-black text-4xl flex items-center justify-center shadow-lg border-4 border-white ring-2 ring-slate-200/80 overflow-hidden transition cursor-pointer hover:ring-indigo-500 hover:scale-[1.03] active:scale-95 dp-zoom-avatar">
                            <span id="rightPanelBigInitial">U</span>
                        </div>

                        <!-- Live Online Status Indicator -->
                        <span id="rightPanelOnlineDot" class="absolute bottom-2 right-2 w-5 h-5 rounded-full bg-emerald-500 border-2 border-white ring-2 ring-emerald-200"></span>

                        <!-- Subtle camera badge button in corner for own profile to change DP -->
                        <button type="button" id="rightPanelEditDpBadge" onclick="triggerDpFilePicker()" title="Change Profile Picture"
                                class="hidden absolute bottom-1 left-2 w-8 h-8 rounded-full bg-indigo-600 hover:bg-indigo-700 text-white shadow-md border-2 border-white flex items-center justify-center transition hover:scale-110 active:scale-90">
                            <span class="material-icons-round text-base">photo_camera</span>
                        </button>
                    </div>

                    <!-- Quick buttons under avatar for mobile/touch screens -->
                    <div id="rightPanelDpButtonsRow" class="hidden mt-2 flex items-center gap-2">
                        <button type="button" onclick="triggerDpFilePicker()" 
                            class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition flex items-center gap-1.5">
                            <span class="material-icons-round text-sm text-indigo-600">file_upload</span>
                            <span>Upload DP</span>
                        </button>
                        <button type="button" onclick="openDpCameraCapture()" 
                            class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition flex items-center gap-1.5">
                            <span class="material-icons-round text-sm text-indigo-600">photo_camera</span>
                            <span>Camera</span>
                        </button>
                    </div>

                    <!-- Contact Name & Sub-details -->
                    <div class="mt-4 flex items-center justify-center gap-1.5 flex-wrap">
                        <h2 id="rightPanelName" class="text-xl font-extrabold text-slate-900 leading-snug">Full Name</h2>
                        <button type="button" id="rightPanelEditNicknameBtn" onclick="openNicknameModal(activeProfileUserId)" title="Edit contact nickname" 
                            class="p-1 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition inline-flex items-center justify-center">
                            <span class="material-icons-round text-base">edit</span>
                        </button>
                    </div>
                    <p id="rightPanelRealNameSubtext" class="hidden text-xs font-medium text-slate-400 mt-0.5">Real name: ...</p>
                    <!-- Shows the nickname this user has set for you when viewing your own profile or mutual chat -->
                    <div id="rightPanelGivenNicknameBanner" class="hidden mt-1.5 px-2.5 py-1 bg-amber-50 border border-amber-200/90 rounded-full text-[11px] font-bold text-amber-800 items-center gap-1 shadow-2xs">
                        <span class="material-icons-round text-xs text-amber-500">badge</span>
                        <span id="rightPanelGivenNicknameText">Nickname: ...</span>
                    </div>
                    <p id="rightPanelSubtext" class="text-xs font-semibold text-slate-400 mt-0.5">@username</p>
                    <div class="mt-2.5 flex items-center justify-center gap-2 flex-wrap">
                        <span id="rightPanelRoleBadge" class="text-[10px] font-extrabold px-3 py-0.5 rounded-full border uppercase tracking-wider bg-slate-100 text-slate-700 border-slate-200">
                            Role
                        </span>
                        <span id="rightPanelPresenceBadge" class="inline-flex items-center gap-1 text-[11px] font-bold px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200/90 shadow-2xs">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                            <span id="rightPanelPresenceText">Online</span>
                        </span>
                    </div>

                    <!-- Quick Action Buttons (Voice, Video, Search) - WhatsApp screenshot exact replica -->
                    <div class="mt-6 flex items-center justify-center gap-6 w-full max-w-xs">
                        <!-- Voice button -->
                        <button type="button" onclick="handleRightPanelCall('voice')" class="flex flex-col items-center gap-1.5 group cursor-pointer focus:outline-none">
                            <div class="w-12 h-12 rounded-full bg-slate-100 group-hover:bg-emerald-50 text-slate-700 group-hover:text-emerald-600 border border-slate-200 group-hover:border-emerald-300 flex items-center justify-center transition shadow-2xs group-active:scale-95">
                                <span class="material-icons-round text-xl">call</span>
                            </div>
                            <span class="text-xs font-bold text-slate-600 group-hover:text-emerald-700 transition">Voice</span>
                        </button>

                        <!-- Video button -->
                        <button type="button" onclick="handleRightPanelCall('video')" class="flex flex-col items-center gap-1.5 group cursor-pointer focus:outline-none">
                            <div class="w-12 h-12 rounded-full bg-slate-100 group-hover:bg-indigo-50 text-slate-700 group-hover:text-indigo-600 border border-slate-200 group-hover:border-indigo-300 flex items-center justify-center transition shadow-2xs group-active:scale-95">
                                <span class="material-icons-round text-xl">videocam</span>
                            </div>
                            <span class="text-xs font-bold text-slate-600 group-hover:text-indigo-700 transition">Video</span>
                        </button>

                        <!-- Search button (in-chat search) -->
                        <button type="button" onclick="handleRightPanelSearch()" class="flex flex-col items-center gap-1.5 group cursor-pointer focus:outline-none">
                            <div class="w-12 h-12 rounded-full bg-slate-100 group-hover:bg-sky-50 text-slate-700 group-hover:text-sky-600 border border-slate-200 group-hover:border-sky-300 flex items-center justify-center transition shadow-2xs group-active:scale-95">
                                <span class="material-icons-round text-xl">search</span>
                            </div>
                            <span class="text-xs font-bold text-slate-600 group-hover:text-sky-700 transition">Search</span>
                        </button>
                    </div>
                </div>

                <!-- 2. About & Bio Section (WhatsApp Style Editable for Self) -->
                <div class="p-5 bg-white">
                    <div class="flex items-center justify-between mb-1.5">
                        <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">About</p>
                        <!-- Edit About Button (Visible only on own profile) -->
                        <button type="button" id="rightPanelEditAboutBtn" onclick="toggleEditAboutMode(true)" title="Edit about status" 
                            class="hidden p-1 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition inline-flex items-center justify-center">
                            <span class="material-icons-round text-base">edit</span>
                        </button>
                    </div>

                    <!-- View Mode -->
                    <div id="rightPanelAboutViewMode">
                        <p id="rightPanelAboutText" class="text-sm font-semibold text-slate-800 leading-relaxed break-words">Available</p>
                    </div>

                    <!-- Inline Edit Mode (For own profile) -->
                    <div id="rightPanelAboutEditMode" class="hidden mt-2 space-y-2">
                        <div class="relative">
                            <textarea id="rightPanelAboutInput" rows="2" maxlength="140" placeholder="Set your about status..." oninput="updateAboutCharCount()" 
                                class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs font-semibold text-slate-800 placeholder-slate-400 focus:bg-white focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 outline-none transition resize-none"></textarea>
                            <span id="rightPanelAboutCharCount" class="absolute right-2.5 bottom-2 text-[10px] text-slate-400 font-bold">140</span>
                        </div>

                        <!-- Quick WhatsApp-style About Presets -->
                        <div class="flex items-center gap-1.5 flex-wrap pt-0.5">
                            <button type="button" onclick="selectAboutPreset('Available')" class="px-2 py-0.5 rounded-lg bg-slate-100 hover:bg-indigo-50 hover:text-indigo-600 text-slate-600 text-[10.5px] font-semibold transition">Available</button>
                            <button type="button" onclick="selectAboutPreset('Busy 🔴')" class="px-2 py-0.5 rounded-lg bg-slate-100 hover:bg-indigo-50 hover:text-indigo-600 text-slate-600 text-[10.5px] font-semibold transition">Busy 🔴</button>
                            <button type="button" onclick="selectAboutPreset('In a meeting 📞')" class="px-2 py-0.5 rounded-lg bg-slate-100 hover:bg-indigo-50 hover:text-indigo-600 text-slate-600 text-[10.5px] font-semibold transition">In a meeting</button>
                            <button type="button" onclick="selectAboutPreset('Working remotely 💻')" class="px-2 py-0.5 rounded-lg bg-slate-100 hover:bg-indigo-50 hover:text-indigo-600 text-slate-600 text-[10.5px] font-semibold transition">Remote 💻</button>
                            <button type="button" onclick="selectAboutPreset('Urgent calls only ⚡')" class="px-2 py-0.5 rounded-lg bg-slate-100 hover:bg-indigo-50 hover:text-indigo-600 text-slate-600 text-[10.5px] font-semibold transition">Urgent only ⚡</button>
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex items-center justify-end gap-2 pt-1">
                            <button type="button" onclick="toggleEditAboutMode(false)" class="px-3 py-1.5 rounded-xl text-xs font-bold text-slate-600 hover:bg-slate-100 transition">
                                Cancel
                            </button>
                            <button type="button" id="btnSaveAbout" onclick="submitSaveAbout()" class="px-4 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-extrabold shadow-sm shadow-indigo-200 transition flex items-center gap-1 active:scale-95">
                                <span class="material-icons-round text-sm">check</span>
                                <span>Save</span>
                            </button>
                        </div>
                    </div>

                    <p id="rightPanelLastSeenDesc" class="text-[11px] text-slate-400 mt-2">DigiInk Team Member</p>
                </div>

                <!-- 3. Media, Links and Docs Section (WhatsApp Style with Count & Grid) -->
                <div class="p-5 bg-white">
                    <div class="flex items-center justify-between mb-3.5">
                        <div class="flex items-center gap-2">
                            <span class="material-icons-round text-slate-500 text-lg">perm_media</span>
                            <span class="text-sm font-bold text-slate-800">Media, links and docs</span>
                        </div>
                        <span id="rightPanelMediaCount" class="text-xs font-extrabold text-slate-500 px-2 py-0.5 rounded-md bg-slate-100">0</span>
                    </div>

                    <!-- Media Thumbnail Grid (4 columns) -->
                    <div id="rightPanelMediaGrid" class="grid grid-cols-4 gap-2">
                        <!-- Populated dynamically via JS -->
                        <div class="col-span-4 py-6 text-center text-slate-400 text-xs">
                            <span class="material-icons-round text-2xl text-slate-300 mb-1">photo_library</span>
                            <p>No shared media found</p>
                        </div>
                    </div>
                </div>

                <!-- 4. Team & Enterprise Credentials Card (Strictly Read-Only) -->
                <div class="p-5 bg-slate-50/70 space-y-2.5">
                    <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2">Member Details (Read-only)</p>
                    
                    <!-- User ID Card -->
                    <div class="bg-white p-3 rounded-xl border border-slate-200/80 shadow-2xs flex items-center justify-between">
                        <div class="flex items-center gap-2.5">
                            <span class="material-icons-round text-indigo-600 text-base">badge</span>
                            <div>
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Employee ID</p>
                                <p id="rightPanelUserId" class="text-xs font-extrabold text-slate-800">#00</p>
                            </div>
                        </div>
                        <span class="text-[10px] font-bold text-slate-400 bg-slate-100 px-1.5 py-0.5 rounded">ID</span>
                    </div>

                    <!-- Email Card -->
                    <div class="bg-white p-3 rounded-xl border border-slate-200/80 shadow-2xs flex items-center justify-between">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <span class="material-icons-round text-indigo-600 text-base">mail</span>
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Work Email</p>
                                <p id="rightPanelEmail" class="text-xs font-extrabold text-slate-800 truncate max-w-[180px]">email@digiink.com</p>
                            </div>
                        </div>
                        <span class="material-icons-round text-slate-300 text-sm">lock</span>
                    </div>

                    <!-- Joined Card -->
                    <div class="bg-white p-3 rounded-xl border border-slate-200/80 shadow-2xs flex items-center justify-between">
                        <div class="flex items-center gap-2.5">
                            <span class="material-icons-round text-indigo-600 text-base">calendar_today</span>
                            <div>
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Joined Date</p>
                                <p id="rightPanelJoined" class="text-xs font-extrabold text-slate-800">Oct 2026</p>
                            </div>
                        </div>
                        <span class="material-icons-round text-slate-300 text-sm">security</span>
                    </div>

                    <!-- End-to-End Encryption Notice Pill -->
                    <div class="p-3 bg-emerald-50/80 border border-emerald-200/90 rounded-xl flex items-center gap-2.5">
                        <span class="material-icons-round text-emerald-600 text-base">lock</span>
                        <div>
                            <p class="text-xs font-bold text-emerald-900">End-to-End Encrypted</p>
                            <p class="text-[10.5px] text-emerald-700">Messages and calls are encrypted with AES-256 cipher DB storage.</p>
                        </div>
                    </div>
                </div>

                <!-- 5. Profile Action Controls: Select Messages & Block Contact -->
                <div class="p-5 bg-white space-y-2">
                    <!-- Select Messages Button -->
                    <button type="button" onclick="closeContactInfoPanel(); enterMessageSelectMode();" 
                        class="w-full py-2.5 px-3 bg-slate-50 hover:bg-indigo-50 hover:text-indigo-700 text-slate-700 border border-slate-200 hover:border-indigo-200 rounded-xl font-bold text-xs transition flex items-center justify-center gap-2 shadow-2xs active:scale-95">
                        <span class="material-icons-round text-indigo-600 text-base">checklist</span>
                        <span>Select Messages</span>
                    </button>

                    <!-- Change Wallpaper Button (WhatsApp Style) -->
                    <button type="button" onclick="openWallpaperModal()" 
                        class="w-full py-2.5 px-3 bg-indigo-50/70 hover:bg-indigo-100/90 text-indigo-700 border border-indigo-200/90 rounded-xl font-bold text-xs transition flex items-center justify-center gap-2 shadow-2xs active:scale-95">
                        <span class="material-icons-round text-indigo-600 text-base">wallpaper</span>
                        <span>Chat Wallpaper & Theme</span>
                    </button>

                    <!-- Block / Unblock Contact Button -->
                    <button type="button" id="rightPanelBlockBtn" onclick="toggleBlockContact()" 
                        class="w-full py-2.5 px-3 bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-200/80 rounded-xl font-bold text-xs transition flex items-center justify-center gap-2 shadow-2xs active:scale-95">
                        <span id="rightPanelBlockIcon" class="material-icons-round text-rose-600 text-base">block</span>
                        <span id="rightPanelBlockText">Block Contact</span>
                    </button>
                </div>

            </div>
        </aside>

    </div>

    <!-- Hidden file input for DP gallery/disk picker -->
    <input type="file" id="profileDpFileInput" accept="image/*" class="hidden" onchange="handleDpFileSelected(event)">

    <!-- ========================================================
         ATTACHMENT / IMAGE PREVIEW MODAL (WhatsApp Style)
         ======================================================== -->
    <div id="attachmentModal" class="hidden fixed inset-0 z-50 bg-slate-900/80 backdrop-blur-md flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 w-full max-w-lg p-6 overflow-hidden animate-in fade-in zoom-in-95 duration-150 flex flex-col max-h-[92vh]">
            
            <!-- Header -->
            <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
                <div class="flex items-center gap-2">
                    <span class="p-2 bg-indigo-50 text-indigo-600 rounded-xl material-icons-round text-lg">image</span>
                    <div>
                        <h3 id="attachmentModalTitle" class="font-extrabold text-slate-900 text-base">Send Image / File</h3>
                        <p id="attachmentModalSubtitle" class="text-[11px] text-slate-400 font-medium">Preview before sending to conversation</p>
                    </div>
                </div>
                <button type="button" onclick="closeAttachmentModal()" class="text-slate-400 hover:text-slate-600 rounded-xl p-1.5 hover:bg-slate-100 transition">
                    <span class="material-icons-round text-xl">close</span>
                </button>
            </div>

            <!-- Preview Media Container -->
            <div class="flex-1 min-h-[220px] max-h-[360px] bg-slate-950/95 rounded-2xl overflow-hidden flex items-center justify-center relative p-3 border border-slate-800">
                <!-- Image Preview -->
                <img id="attachmentModalImgPreview" src="" class="hidden max-h-[340px] max-w-full object-contain rounded-xl shadow-lg">

                <!-- Non-Image File Card Preview -->
                <div id="attachmentModalFilePreview" class="hidden flex flex-col items-center justify-center p-6 text-center text-white">
                    <div class="w-16 h-16 rounded-2xl bg-white/10 text-indigo-300 flex items-center justify-center mb-3 shadow-inner border border-white/10">
                        <span id="attachmentModalFileIcon" class="material-icons-round text-4xl">insert_drive_file</span>
                    </div>
                    <p id="attachmentModalFileName" class="text-sm font-bold truncate max-w-xs text-white">document.pdf</p>
                    <p id="attachmentModalFileSize" class="text-xs text-slate-400 font-medium mt-1">0 KB</p>
                </div>
            </div>

            <!-- Caption Input + Actions -->
            <div class="mt-4 space-y-3">
                <div class="relative flex items-center gap-2">
                    <div class="relative flex-1">
                        <input type="text" id="attachmentCaptionInput" placeholder="Add a caption... (optional)" autocomplete="off"
                            class="chat-text w-full py-3 pl-4 pr-12 bg-slate-50 border border-slate-200/90 rounded-2xl text-sm focus:bg-white focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 outline-none transition font-medium text-slate-800 placeholder-slate-400">
                    </div>

                    <!-- View Once Toggle Button for Attachment -->
                    <button type="button" id="attachmentViewOnceBtn" onclick="toggleAttachmentViewOnce()" title="View Once (Photo / file disappears after opening)"
                        class="w-10 h-10 rounded-full border border-slate-300 text-slate-400 hover:text-indigo-600 hover:border-indigo-400 flex items-center justify-center font-black text-xs transition select-none flex-shrink-0">
                        <span class="w-5 h-5 rounded-full border border-current flex items-center justify-center text-[10px] font-bold">1</span>
                    </button>

                    <button type="button" onclick="sendAttachmentFromModal()" id="attachmentSendBtn" title="Send"
                        class="p-3 bg-indigo-600 hover:bg-indigo-700 active:scale-95 text-white rounded-2xl shadow-md transition flex items-center justify-center flex-shrink-0">
                        <span class="material-icons-round text-lg">send</span>
                    </button>
                </div>

                <div class="flex items-center justify-between text-xs pt-1">
                    <span id="attachmentModalFileInfo" class="text-slate-400 font-medium text-[11px] truncate max-w-[260px]"></span>
                    <button type="button" onclick="closeAttachmentModal()" class="text-slate-500 hover:text-rose-600 font-bold px-3 py-1.5 rounded-xl hover:bg-rose-50 transition">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================
         MEDIA QUALITY DOWNLOAD MODAL (HD vs Normal with sizes)
         ======================================================== -->
    <div id="mediaDownloadModal" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 w-full max-w-sm p-6 overflow-hidden animate-in fade-in zoom-in-95 duration-150">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-2.5">
                    <div class="w-10 h-10 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                        <span class="material-icons-round text-2xl" id="mediaDownloadIcon">download</span>
                    </div>
                    <div>
                        <h3 class="font-extrabold text-slate-900 text-sm" id="mediaDownloadTitle">Download Media</h3>
                        <p class="text-[11px] text-slate-400 font-medium truncate max-w-[210px]" id="mediaDownloadFileName">Choose quality</p>
                    </div>
                </div>
                <button type="button" onclick="closeMediaDownloadModal()" class="text-slate-400 hover:text-slate-600 p-1.5 rounded-xl hover:bg-slate-100 transition">
                    <span class="material-icons-round text-lg">close</span>
                </button>
            </div>

            <p class="text-xs text-slate-500 mb-4 leading-relaxed">
                Choose the download quality preference for this attachment:
            </p>

            <div class="space-y-2.5 mb-5">
                <!-- HD Option -->
                <button type="button" onclick="triggerExecuteMediaDownload('hd')" 
                    class="w-full p-3.5 rounded-2xl border-2 border-indigo-500 bg-indigo-50/60 hover:bg-indigo-100/80 text-left transition flex items-center justify-between group cursor-pointer shadow-xs active:scale-[0.99]">
                    <div class="flex items-center gap-3">
                        <span class="w-9 h-9 rounded-xl bg-indigo-600 text-white flex items-center justify-center shadow-sm">
                            <span class="material-icons-round text-lg">hd</span>
                        </span>
                        <div>
                            <span class="text-xs font-black text-slate-900 block group-hover:text-indigo-700 transition">Download in HD</span>
                            <span class="text-[11px] font-bold text-indigo-600" id="mediaDownloadHdSize">Calculating size...</span>
                        </div>
                    </div>
                    <span class="material-icons-round text-indigo-600 group-hover:translate-y-0.5 transition">file_download</span>
                </button>

                <!-- Normal Option -->
                <button type="button" onclick="triggerExecuteMediaDownload('normal')" 
                    class="w-full p-3.5 rounded-2xl border border-slate-200 hover:border-slate-300 bg-slate-50/80 hover:bg-slate-100/90 text-left transition flex items-center justify-between group cursor-pointer active:scale-[0.99]">
                    <div class="flex items-center gap-3">
                        <span class="w-9 h-9 rounded-xl bg-slate-200 text-slate-700 flex items-center justify-center">
                            <span class="material-icons-round text-lg">sd</span>
                        </span>
                        <div>
                            <span class="text-xs font-bold text-slate-800 block group-hover:text-slate-900 transition">Download Normal</span>
                            <span class="text-[11px] font-medium text-slate-500" id="mediaDownloadNormalSize">Calculating size...</span>
                        </div>
                    </div>
                    <span class="material-icons-round text-slate-500 group-hover:translate-y-0.5 transition">file_download</span>
                </button>
            </div>

            <button type="button" onclick="closeMediaDownloadModal()" class="w-full py-2.5 px-4 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition">
                Cancel
            </button>
        </div>
    </div>

    <!-- ========================================================
         CONTACT BLOCK CONFIRMATION MODAL
         ======================================================== -->
    <div id="blockConfirmModal" class="hidden fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 w-full max-w-sm p-6 overflow-hidden animate-in fade-in zoom-in-95 duration-150 text-center">
            <div class="w-12 h-12 rounded-2xl bg-rose-50 text-rose-600 flex items-center justify-center mx-auto mb-3 shadow-xs">
                <span class="material-icons-round text-2xl" id="blockModalIcon">block</span>
            </div>
            <h3 id="blockModalTitle" class="font-extrabold text-slate-900 text-base">Block this contact?</h3>
            <p id="blockModalDesc" class="text-xs text-slate-500 mt-1 mb-5 leading-relaxed">Blocked contacts will no longer be able to send you messages or make voice/video calls. You can unblock them at any time.</p>
            <div class="flex items-center gap-2">
                <button type="button" onclick="closeBlockModal()" class="flex-1 py-2.5 px-4 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition">
                    Cancel
                </button>
                <button type="button" id="confirmBlockBtn" onclick="submitBlockAction()" class="flex-1 py-2.5 px-4 bg-rose-600 hover:bg-rose-700 text-white rounded-xl text-xs font-bold transition shadow-sm">
                    Block Contact
                </button>
            </div>
        </div>
    </div>

    <!-- ========================================================
         MESSAGE RIGHT-CLICK CONTEXT MENU (WhatsApp Style)
         ======================================================== -->
    <div id="msgContextMenu" class="hidden fixed z-50 bg-white/95 backdrop-blur-md rounded-2xl shadow-2xl border border-slate-200/90 py-1.5 w-52 text-xs font-semibold text-slate-700 transition-all select-none">
        <!-- Quick Reaction Emoji Row at top of context menu -->
        <div class="px-3 py-1.5 border-b border-slate-100 flex items-center justify-between gap-1">
            <button type="button" onclick="handleMenuReaction('👍')" title="Thumbs up" class="hover:scale-135 transition text-base leading-none p-1 rounded-full hover:bg-slate-100">👍</button>
            <button type="button" onclick="handleMenuReaction('❤️')" title="Heart" class="hover:scale-135 transition text-base leading-none p-1 rounded-full hover:bg-slate-100">❤️</button>
            <button type="button" onclick="handleMenuReaction('😂')" title="Laugh" class="hover:scale-135 transition text-base leading-none p-1 rounded-full hover:bg-slate-100">😂</button>
            <button type="button" onclick="handleMenuReaction('😮')" title="Surprised" class="hover:scale-135 transition text-base leading-none p-1 rounded-full hover:bg-slate-100">😮</button>
            <button type="button" onclick="handleMenuReaction('😢')" title="Sad" class="hover:scale-135 transition text-base leading-none p-1 rounded-full hover:bg-slate-100">😢</button>
            <button type="button" onclick="handleMenuReaction('🙏')" title="Thanks / Pray" class="hover:scale-135 transition text-base leading-none p-1 rounded-full hover:bg-slate-100">🙏</button>
        </div>
        <!-- Reply Option (Primary Action) -->
        <button type="button" onclick="handleMenuAction('reply')" class="w-full px-3.5 py-2 flex items-center gap-2.5 hover:bg-indigo-50 hover:text-indigo-600 transition text-left">
            <span class="material-icons-round text-base text-indigo-600">reply</span>
            <span class="font-bold text-slate-900">Reply</span>
        </button>
        <button type="button" onclick="handleMenuAction('info')" class="w-full px-3.5 py-2 flex items-center gap-2.5 hover:bg-indigo-50 hover:text-indigo-600 transition text-left">
            <span class="material-icons-round text-base text-indigo-500">info</span>
            <span>Message info</span>
        </button>
        <button type="button" onclick="handleMenuAction('forward')" class="w-full px-3.5 py-2 flex items-center gap-2.5 hover:bg-indigo-50 hover:text-indigo-600 transition text-left">
            <span class="material-icons-round text-base text-sky-500">shortcut</span>
            <span>Forward</span>
        </button>
        <button type="button" onclick="handleMenuAction('copy')" id="menuCopyBtn" class="w-full px-3.5 py-2 flex items-center gap-2.5 hover:bg-indigo-50 hover:text-indigo-600 transition text-left">
            <span class="material-icons-round text-base text-slate-400">content_copy</span>
            <span>Copy text</span>
        </button>
        <div class="h-px bg-slate-100 my-1"></div>
        <button type="button" onclick="handleMenuAction('select')" class="w-full px-3.5 py-2 flex items-center gap-2.5 hover:bg-indigo-50 hover:text-indigo-600 transition text-left">
            <span class="material-icons-round text-base text-indigo-500">check_box</span>
            <span>Select messages</span>
        </button>
    </div>

    <!-- ========================================================
         CHAT WALLPAPER / COVER PHOTO MODAL (WhatsApp Style)
         ======================================================== -->
    <div id="chatWallpaperModal" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 w-full max-w-md p-6 overflow-hidden animate-in fade-in zoom-in-95 duration-150">
            <div class="flex items-center justify-between mb-4 border-b border-slate-100 pb-3">
                <div class="flex items-center gap-2.5">
                    <div class="w-10 h-10 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                        <span class="material-icons-round text-2xl">wallpaper</span>
                    </div>
                    <div>
                        <h3 class="font-extrabold text-slate-900 text-sm">Chat Wallpaper</h3>
                        <p class="text-[11px] text-slate-400 font-medium" id="wallpaperModalSubtitle">Customize background for this chat</p>
                    </div>
                </div>
                <button type="button" onclick="closeWallpaperModal()" class="text-slate-400 hover:text-slate-600 p-1.5 rounded-xl hover:bg-slate-100 transition">
                    <span class="material-icons-round text-lg">close</span>
                </button>
            </div>

            <!-- Upload Custom Photo / Wallpaper Option -->
            <div class="mb-5">
                <label class="block text-xs font-bold text-slate-700 mb-2">Upload From Device (Photo / Wallpaper)</label>
                
                <!-- Dropzone Area -->
                <div id="wallpaperUploadDropzone" onclick="document.getElementById('wallpaperFileInput').click()" 
                    class="border-2 border-dashed border-indigo-200 hover:border-indigo-500 rounded-2xl p-4 text-center cursor-pointer transition bg-indigo-50/40 hover:bg-indigo-50/80 group">
                    <span class="material-icons-round text-3xl text-indigo-500 group-hover:scale-110 transition mb-1">add_photo_alternate</span>
                    <p class="text-xs font-bold text-slate-700">Choose custom photo or background</p>
                    <p class="text-[10px] text-slate-400 mt-0.5">JPG, PNG, WEBP (Supports high resolution)</p>
                </div>
                <input type="file" id="wallpaperFileInput" accept="image/*" class="hidden" onchange="handleWallpaperFileSelected(event)">

                <!-- Image Selected Live Preview & Apply Card -->
                <div id="wallpaperPreviewBox" class="hidden mt-3 p-3 bg-slate-50 border border-slate-200 rounded-2xl">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-[11px] font-bold text-slate-600">Selected Photo Preview:</span>
                        <button type="button" onclick="cancelWallpaperPreview()" class="text-rose-600 hover:text-rose-700 text-[11px] font-bold flex items-center gap-0.5">
                            <span class="material-icons-round text-xs">close</span> Change Photo
                        </button>
                    </div>
                    <div class="w-full h-36 rounded-xl overflow-hidden bg-slate-900 border border-slate-200 mb-3 relative shadow-inner">
                        <img id="wallpaperPreviewImg" src="" class="w-full h-full object-cover">
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="openWallpaperCropFromPreview()" 
                            class="flex-1 py-2.5 px-3 bg-white hover:bg-slate-100 text-slate-800 border border-slate-300 rounded-xl text-xs font-bold transition flex items-center justify-center gap-1.5 active:scale-95 shadow-xs">
                            <span class="material-icons-round text-base text-indigo-600">crop</span>
                            <span>Crop / Adjust</span>
                        </button>
                        <button type="button" id="btnApplyCustomWallpaper" onclick="submitApplyWallpaper()" 
                            class="flex-1 py-2.5 px-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-black shadow-md shadow-indigo-200 transition flex items-center justify-center gap-1.5 active:scale-95">
                            <span class="material-icons-round text-base">check</span>
                            <span>Apply</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Curated WhatsApp & Aesthetic Presets -->
            <div class="mb-5">
                <label class="block text-xs font-bold text-slate-700 mb-2">Or Choose a Preset Theme</label>
                <div class="grid grid-cols-4 gap-2.5">
                    <!-- Default Dotted Canvas -->
                    <button type="button" onclick="selectPresetWallpaper('default')" title="Default DigiChat Doodle" 
                        class="aspect-[4/3] rounded-xl border-2 border-indigo-500 relative overflow-hidden transition hover:scale-105 active:scale-95 shadow-xs flex items-center justify-center bg-slate-50">
                        <span class="text-[9px] font-black text-slate-600">Default</span>
                    </button>
                    <!-- Dark Slate -->
                    <button type="button" onclick="selectPresetWallpaper('dark_slate')" title="Dark Slate" 
                        class="aspect-[4/3] rounded-xl border border-slate-200 relative overflow-hidden transition hover:scale-105 active:scale-95 shadow-xs bg-[#0f172a] flex items-center justify-center">
                        <span class="text-[9px] font-black text-white/90">Dark</span>
                    </button>
                    <!-- WhatsApp Classic Doodle Green -->
                    <button type="button" onclick="selectPresetWallpaper('whatsapp_classic')" title="WhatsApp Classic Soft" 
                        class="aspect-[4/3] rounded-xl border border-slate-200 relative overflow-hidden transition hover:scale-105 active:scale-95 shadow-xs bg-[#efeae2] flex items-center justify-center">
                        <span class="text-[9px] font-black text-emerald-800">Classic</span>
                    </button>
                    <!-- Indigo Gradient -->
                    <button type="button" onclick="selectPresetWallpaper('indigo_aurora')" title="Indigo Aurora" 
                        class="aspect-[4/3] rounded-xl border border-slate-200 relative overflow-hidden transition hover:scale-105 active:scale-95 shadow-xs bg-gradient-to-br from-indigo-100 via-purple-50 to-pink-100 flex items-center justify-center">
                        <span class="text-[9px] font-black text-indigo-700">Aurora</span>
                    </button>
                    <!-- Midnight Blue -->
                    <button type="button" onclick="selectPresetWallpaper('midnight_blue')" title="Midnight Blue" 
                        class="aspect-[4/3] rounded-xl border border-slate-200 relative overflow-hidden transition hover:scale-105 active:scale-95 shadow-xs bg-gradient-to-b from-[#0a192f] to-[#172a45] flex items-center justify-center">
                        <span class="text-[9px] font-black text-sky-300">Midnight</span>
                    </button>
                    <!-- Warm Sand / Rose -->
                    <button type="button" onclick="selectPresetWallpaper('warm_sand')" title="Warm Sand" 
                        class="aspect-[4/3] rounded-xl border border-slate-200 relative overflow-hidden transition hover:scale-105 active:scale-95 shadow-xs bg-gradient-to-br from-[#fdfbf7] via-[#f7eee4] to-[#f4e2d8] flex items-center justify-center">
                        <span class="text-[9px] font-black text-amber-800">Sand</span>
                    </button>
                    <!-- Emerald Zen -->
                    <button type="button" onclick="selectPresetWallpaper('emerald_zen')" title="Emerald Zen" 
                        class="aspect-[4/3] rounded-xl border border-slate-200 relative overflow-hidden transition hover:scale-105 active:scale-95 shadow-xs bg-gradient-to-br from-[#064e3b] via-[#047857] to-[#0f766e] flex items-center justify-center">
                        <span class="text-[9px] font-black text-emerald-200">Emerald</span>
                    </button>
                    <!-- Clean Minimal White -->
                    <button type="button" onclick="selectPresetWallpaper('clean_white')" title="Clean Pure" 
                        class="aspect-[4/3] rounded-xl border border-slate-200 relative overflow-hidden transition hover:scale-105 active:scale-95 shadow-xs bg-white flex items-center justify-center">
                        <span class="text-[9px] font-black text-slate-400">Minimal</span>
                    </button>
                </div>
            </div>

            <!-- Footer Actions -->
            <div class="flex items-center justify-between gap-2 pt-2 border-t border-slate-100">
                <button type="button" onclick="resetToDefaultWallpaper()" class="py-2.5 px-3 rounded-xl text-xs font-bold text-rose-600 hover:bg-rose-50 transition flex items-center gap-1">
                    <span class="material-icons-round text-sm">restart_alt</span>
                    <span>Reset Default</span>
                </button>
                <button type="button" onclick="closeWallpaperModal()" class="py-2.5 px-5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition">
                    Close
                </button>
            </div>
        </div>
    </div>

    <!-- ========================================================
         WALLPAPER CROP & ADJUST MODAL (WhatsApp Style)
         ======================================================== -->
    <div id="wallpaperCropModal" class="hidden fixed inset-0 z-50 bg-slate-950/90 backdrop-blur-md flex flex-col justify-between p-4 sm:p-6 select-none">
        <!-- Top Toolbar -->
        <div class="w-full max-w-2xl mx-auto flex items-center justify-between text-white z-10 py-2">
            <div class="flex items-center gap-2.5">
                <span class="w-9 h-9 rounded-xl bg-white/10 flex items-center justify-center">
                    <span class="material-icons-round text-xl text-indigo-400">crop</span>
                </span>
                <div>
                    <h3 class="font-extrabold text-sm text-white">Crop & Adjust Wallpaper</h3>
                    <p class="text-[11px] text-slate-400 font-medium">Drag to reposition or resize the crop box</p>
                </div>
            </div>
            
            <button type="button" onclick="cancelWallpaperCropModal()" class="w-9 h-9 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition">
                <span class="material-icons-round text-lg">close</span>
            </button>
        </div>

        <!-- Center Crop Canvas Area -->
        <div class="relative flex-1 w-full max-w-2xl mx-auto flex items-center justify-center my-3 overflow-hidden rounded-2xl bg-black border border-white/10 shadow-2xl">
            <div class="w-full h-full max-h-[60vh] flex items-center justify-center">
                <img id="wallpaperCropImageElement" src="" class="max-w-full max-h-full block">
            </div>
        </div>

        <!-- Bottom Controls & Action Bar -->
        <div class="w-full max-w-2xl mx-auto z-10 flex flex-col gap-3">
            <div class="flex items-center justify-center gap-2">
                <button type="button" onclick="rotateWallpaperCrop(-90)" title="Rotate Left" class="p-2.5 bg-white/10 hover:bg-white/20 text-white rounded-xl transition flex items-center gap-1.5 text-xs font-bold">
                    <span class="material-icons-round text-base">rotate_left</span>
                    <span>Rotate</span>
                </button>
                <button type="button" onclick="rotateWallpaperCrop(90)" title="Rotate Right" class="p-2.5 bg-white/10 hover:bg-white/20 text-white rounded-xl transition flex items-center gap-1.5 text-xs font-bold">
                    <span class="material-icons-round text-base">rotate_right</span>
                </button>
                <button type="button" onclick="resetWallpaperCrop()" title="Reset Box" class="p-2.5 bg-white/10 hover:bg-white/20 text-white rounded-xl transition flex items-center gap-1.5 text-xs font-bold">
                    <span class="material-icons-round text-base">aspect_ratio</span>
                    <span>Reset</span>
                </button>
            </div>

            <div class="flex items-center justify-between gap-3 pt-2 border-t border-white/10">
                <button type="button" onclick="cancelWallpaperCropModal()" class="py-2.5 px-5 bg-white/10 hover:bg-white/20 text-white rounded-xl text-xs font-bold transition">
                    Cancel
                </button>
                <button type="button" id="btnConfirmWallpaperCrop" onclick="submitCropAndApplyWallpaper()" 
                    class="py-2.5 px-6 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-black shadow-lg shadow-indigo-500/30 transition flex items-center gap-2 active:scale-95">
                    <span class="material-icons-round text-base">check</span>
                    <span>Done & Apply Wallpaper</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ========================================================
         SALARY COUNTDOWN & MOTIVATION POPUP MODAL
         ======================================================== -->
    <div id="salaryMotivationModal" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 w-full max-w-md p-6 overflow-hidden animate-in fade-in zoom-in-95 duration-150">
            <div class="flex items-center justify-between mb-4 border-b border-slate-100 pb-3">
                <div class="flex items-center gap-2.5">
                    <div class="w-10 h-10 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                        <span class="material-icons-round text-2xl">account_balance_wallet</span>
                    </div>
                    <div>
                        <h3 class="font-extrabold text-slate-900 text-sm">Monthly Salary Countdown</h3>
                        <p class="text-[11px] text-slate-400 font-medium">Credited on the 7th of every month</p>
                    </div>
                </div>
                <button type="button" onclick="closeSalaryMotivationModal()" class="text-slate-400 hover:text-slate-600 p-1.5 rounded-xl hover:bg-slate-100 transition">
                    <span class="material-icons-round text-lg">close</span>
                </button>
            </div>

            <!-- Big Visual Countdown Card -->
            <div class="p-5 rounded-2xl bg-gradient-to-br from-emerald-500 via-teal-600 to-indigo-700 text-white text-center shadow-lg shadow-emerald-500/20 mb-4 relative overflow-hidden">
                <div class="absolute -right-4 -bottom-4 w-28 h-28 rounded-full bg-white/10 blur-xl pointer-events-none"></div>
                <span class="px-2.5 py-1 bg-white/20 backdrop-blur-md rounded-full text-[10px] font-black uppercase tracking-wider mb-2 inline-block">
                    Next Payday: <span id="modalNextPaydayDate">7th of Next Month</span>
                </span>
                <div class="flex items-center justify-center gap-2 my-2">
                    <span class="text-4xl sm:text-5xl font-black tracking-tight" id="modalSalaryDaysNumber">--</span>
                    <div class="text-left leading-tight">
                        <span class="block text-sm font-bold uppercase tracking-wider text-emerald-100">Days</span>
                        <span class="block text-[11px] font-medium text-emerald-200">Remaining</span>
                    </div>
                </div>
                <p id="modalSalaryStatusNote" class="text-xs font-semibold text-emerald-100 mt-2">
                    Hang tight! Good things take consistency and patience.
                </p>
            </div>

            <!-- Motivated Line Card -->
            <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200/80 mb-5 text-left">
                <div class="flex items-center gap-1.5 text-xs font-bold text-slate-800 mb-1">
                    <span class="material-icons-round text-amber-500 text-sm">auto_awesome</span>
                    <span>Daily Motivation For You:</span>
                </div>
                <blockquote id="modalFullMotivationQuote" class="text-xs text-slate-600 font-medium italic leading-relaxed border-l-2 border-emerald-500 pl-3 my-1.5">
                    "Success isn't always about greatness. It's about consistency. Consistent hard work leads to success. The greatness will come."
                </blockquote>
                <div class="flex justify-end">
                    <button type="button" onclick="refreshSalaryMotivationQuote()" class="text-[11px] text-indigo-600 hover:text-indigo-700 font-bold flex items-center gap-1">
                        <span class="material-icons-round text-xs">refresh</span>
                        <span>New Quote</span>
                    </button>
                </div>
            </div>

            <!-- Close Button -->
            <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                <button type="button" onclick="closeSalaryMotivationModal()" class="w-full py-2.5 px-5 bg-slate-900 hover:bg-slate-800 text-white rounded-xl text-xs font-bold transition">
                    Keep Hustling 🚀
                </button>
            </div>
        </div>
    </div>

    <!-- ========================================================
         MESSAGE INFO MODAL (Delivered & Seen/Read Timestamps)
         ======================================================== -->
    <div id="messageInfoModal" class="hidden fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 w-full max-w-md p-6 overflow-hidden animate-in fade-in zoom-in-95 duration-150">
            <div class="flex items-center justify-between mb-4 border-b border-slate-100 pb-3">
                <div class="flex items-center gap-2">
                    <span class="p-2 bg-indigo-50 text-indigo-600 rounded-xl material-icons-round text-lg">info</span>
                    <div>
                        <h3 class="font-extrabold text-slate-900 text-base">Message Info</h3>
                        <p class="text-[11px] text-slate-400 font-medium">Delivery and seen status timestamps</p>
                    </div>
                </div>
                <button onclick="closeMessageInfoModal()" class="text-slate-400 hover:text-slate-600 rounded-xl p-1 transition">
                    <span class="material-icons-round">close</span>
                </button>
            </div>

            <!-- Message Preview -->
            <div class="mb-5 bg-slate-50 border border-slate-200/80 rounded-2xl p-3.5">
                <div id="infoPreviewForwarded" class="hidden flex items-center gap-1 text-[10px] italic text-indigo-600 font-bold mb-1">
                    <span class="material-icons-round text-xs">shortcut</span> Forwarded
                </div>
                <p id="infoPreviewText" class="chat-text text-xs text-slate-800 font-medium leading-relaxed max-h-28 overflow-y-auto whitespace-pre-wrap"></p>
                <div id="infoPreviewFile" class="hidden mt-2 text-xs font-bold text-slate-600 flex items-center gap-2">
                    <span class="material-icons-round text-indigo-500">attach_file</span>
                    <span id="infoPreviewFileName"></span>
                </div>
            </div>

            <!-- Status Timeline -->
            <div class="space-y-4">
                <!-- Read / Seen -->
                <div class="flex items-start gap-3">
                    <div class="w-8 h-8 rounded-xl bg-sky-50 text-[#38bdf8] flex items-center justify-center flex-shrink-0 shadow-2xs">
                        <span class="material-icons-round text-lg font-black">done_all</span>
                    </div>
                    <div class="flex-1">
                        <div class="flex items-center justify-between">
                            <h4 class="text-xs font-extrabold text-slate-800">Read / Seen</h4>
                            <span id="infoReadBadge" class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-100 text-slate-600">Pending</span>
                        </div>
                        <p id="infoReadTime" class="text-[11px] text-slate-500 mt-0.5">Not read yet</p>
                    </div>
                </div>

                <!-- Delivered -->
                <div class="flex items-start gap-3">
                    <div class="w-8 h-8 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center flex-shrink-0 shadow-2xs">
                        <span class="material-icons-round text-lg">done_all</span>
                    </div>
                    <div class="flex-1">
                        <div class="flex items-center justify-between">
                            <h4 class="text-xs font-extrabold text-slate-800">Delivered</h4>
                            <span id="infoDeliveredBadge" class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-100 text-slate-600">Pending</span>
                        </div>
                        <p id="infoDeliveredTime" class="text-[11px] text-slate-500 mt-0.5">Not delivered yet</p>
                    </div>
                </div>

                <!-- Sent -->
                <div class="flex items-start gap-3">
                    <div class="w-8 h-8 rounded-xl bg-slate-100 text-slate-600 flex items-center justify-center flex-shrink-0 shadow-2xs">
                        <span class="material-icons-round text-lg">done</span>
                    </div>
                    <div class="flex-1">
                        <div class="flex items-center justify-between">
                            <h4 class="text-xs font-extrabold text-slate-800">Sent</h4>
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700">Sent</span>
                        </div>
                        <p id="infoSentTime" class="text-[11px] text-slate-500 mt-0.5">--</p>
                    </div>
                </div>
            </div>

            <div class="mt-6 flex justify-end">
                <button type="button" onclick="closeMessageInfoModal()" class="px-5 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold transition">
                    Close
                </button>
            </div>
        </div>
    </div>

    <!-- ========================================================
         FORWARD MESSAGE MODAL
         ======================================================== -->
    <div id="forwardMessageModal" class="hidden fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 w-full max-w-md p-6 overflow-hidden animate-in fade-in zoom-in-95 duration-150 flex flex-col max-h-[85vh]">
            <div class="flex items-center justify-between mb-3 border-b border-slate-100 pb-3">
                <div class="flex items-center gap-2">
                    <span class="p-2 bg-sky-50 text-sky-600 rounded-xl material-icons-round text-lg">shortcut</span>
                    <div>
                        <h3 class="font-extrabold text-slate-900 text-base">Forward Message</h3>
                        <p class="text-[11px] text-slate-400 font-medium">Select an authorized contact to forward to</p>
                    </div>
                </div>
                <button onclick="closeForwardModal()" class="text-slate-400 hover:text-slate-600 rounded-xl p-1 transition">
                    <span class="material-icons-round">close</span>
                </button>
            </div>

            <!-- Forward Preview -->
            <div class="mb-3 p-3 bg-slate-50 rounded-2xl border border-slate-200/80 text-xs text-slate-600 truncate">
                <span class="font-bold text-slate-800">Forwarding:</span> <span id="forwardPreviewText"></span>
            </div>

            <!-- Search input inside forward modal -->
            <div class="relative mb-3">
                <span class="material-icons-round absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">search</span>
                <input type="text" id="forwardSearchInput" placeholder="Search contacts..." 
                    class="w-full pl-9 pr-3 py-2 bg-slate-50 border border-slate-200/80 rounded-xl text-xs focus:bg-white focus:ring-1 focus:ring-sky-500 outline-none transition font-medium">
            </div>

            <!-- Contact List -->
            <input type="hidden" id="forwardMsgId">
            <div id="forwardContactsList" class="flex-1 overflow-y-auto space-y-1.5 divide-y divide-slate-100 pr-1">
                <!-- Populated via JS -->
            </div>

            <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-end">
                <button type="button" onclick="closeForwardModal()" class="px-4 py-2 rounded-xl text-xs font-bold text-slate-600 hover:bg-slate-100 transition">
                    Cancel
                </button>
            </div>
        </div>
    </div>

    <!-- ========================================================
         CREATE GROUP MODAL (WhatsApp Style with Custom Name & Multi-select)
         ======================================================== -->
    <div id="createGroupModal" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 w-full max-w-md p-6 overflow-hidden animate-in fade-in zoom-in-95 duration-150 flex flex-col max-h-[90vh]">
            
            <!-- Modal Header -->
            <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
                <div class="flex items-center gap-2.5">
                    <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-indigo-600 to-violet-600 text-white flex items-center justify-center shadow-md shadow-indigo-200">
                        <span class="material-icons-round text-xl">group_add</span>
                    </div>
                    <div>
                        <h3 class="font-extrabold text-slate-900 text-base leading-tight">Create New Group</h3>
                        <p class="text-[11px] text-slate-400 font-medium">WhatsApp-style team group with custom name</p>
                    </div>
                </div>
                <button type="button" onclick="closeCreateGroupModal()" class="text-slate-400 hover:text-slate-600 rounded-xl p-1.5 hover:bg-slate-100 transition">
                    <span class="material-icons-round text-xl">close</span>
                </button>
            </div>

            <form id="createGroupForm" onsubmit="submitCreateGroup(event)" class="flex flex-col flex-1 overflow-hidden space-y-4">
                <!-- Group Name Input (Custom Name per user request) -->
                <div>
                    <label for="groupNameInput" class="block text-xs font-bold text-slate-700 mb-1">
                        Group Name <span class="text-rose-500">*</span>
                    </label>
                    <input type="text" id="groupNameInput" required maxlength="100" placeholder="e.g. Design Team, Sales Discussion, Sprint Q3..." 
                        class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-800 placeholder-slate-400 focus:bg-white focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 outline-none transition">
                </div>

                <!-- Description (Optional) -->
                <div>
                    <label for="groupDescInput" class="block text-xs font-bold text-slate-700 mb-1">
                        Group Topic / Description <span class="text-slate-400 font-normal">(Optional)</span>
                    </label>
                    <input type="text" id="groupDescInput" maxlength="250" placeholder="Purpose or topic of this group conversation..." 
                        class="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-medium text-slate-800 placeholder-slate-400 focus:bg-white focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 outline-none transition">
                </div>

                <!-- Member Selection Area -->
                <div class="flex-1 flex flex-col min-h-[190px] overflow-hidden">
                    <div class="flex items-center justify-between mb-2">
                        <label class="text-xs font-bold text-slate-700">
                            Select Members <span id="selectedMembersCountBadge" class="ml-1 text-[11px] font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded-full">0 selected</span>
                        </label>
                        <span class="text-[10px] text-slate-400 font-semibold">You are auto-added as Admin</span>
                    </div>

                    <!-- Search Input for members -->
                    <div class="relative mb-2">
                        <span class="material-icons-round absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">search</span>
                        <input type="text" id="groupMemberSearchInput" oninput="filterGroupMembersChecklist()" placeholder="Search team members by name or role..." 
                            class="w-full pl-8 pr-3 py-1.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-medium focus:bg-white focus:ring-1 focus:ring-indigo-500 outline-none transition">
                    </div>

                    <!-- Member checklist -->
                    <div id="groupMembersChecklist" class="flex-1 overflow-y-auto divide-y divide-slate-100 border border-slate-200/80 rounded-2xl p-1 bg-slate-50/50">
                        <!-- Populated by JS -->
                    </div>
                </div>

                <!-- Footer buttons -->
                <div class="pt-2 border-t border-slate-100 flex items-center justify-end gap-2">
                    <button type="button" onclick="closeCreateGroupModal()" class="px-4 py-2.5 rounded-xl text-xs font-bold text-slate-600 hover:bg-slate-100 transition">
                        Cancel
                    </button>
                    <button type="submit" id="btnSubmitCreateGroup" class="px-5 py-2.5 bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-700 hover:to-violet-700 text-white rounded-xl text-xs font-extrabold shadow-md shadow-indigo-200 transition flex items-center gap-1.5 active:scale-95">
                        <span class="material-icons-round text-base">group_add</span>
                        <span>Create Group</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================
         ADD / EDIT NICKNAME MODAL (WhatsApp Style Contact Alias)
         ======================================================== -->
    <div id="nicknameModal" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 w-full max-w-sm p-6 overflow-hidden animate-in fade-in zoom-in-95 duration-150 flex flex-col">
            <!-- Modal Header -->
            <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-amber-50 text-amber-600 border border-amber-200/80 flex items-center justify-center shadow-xs">
                        <span class="material-icons-round text-xl">badge</span>
                    </div>
                    <div>
                        <h3 id="nicknameModalTitle" class="font-extrabold text-slate-900 text-sm sm:text-base leading-tight">Edit Nickname</h3>
                        <p class="text-[11px] text-slate-400 font-medium">Only visible to you on DigiChat</p>
                    </div>
                </div>
                <button type="button" onclick="closeNicknameModal()" class="text-slate-400 hover:text-slate-600 rounded-xl p-1.5 hover:bg-slate-100 transition">
                    <span class="material-icons-round text-xl">close</span>
                </button>
            </div>

            <!-- Modal Body -->
            <form id="nicknameForm" onsubmit="submitSaveNickname(event)" class="space-y-4">
                <input type="hidden" id="nicknameContactId" value="">

                <!-- Contact Real Info Box -->
                <div class="p-3 bg-slate-50 rounded-2xl border border-slate-200/80 flex items-center gap-3">
                    <div id="nicknameModalAvatar" class="w-10 h-10 rounded-xl bg-indigo-100 text-indigo-700 font-black text-sm flex items-center justify-center flex-shrink-0">
                        U
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Original Name</p>
                        <p id="nicknameModalRealName" class="text-xs font-extrabold text-slate-800 truncate">Contact Name</p>
                        <p id="nicknameModalUsername" class="text-[11px] font-semibold text-slate-500 truncate">@username</p>
                    </div>
                </div>

                <!-- Input -->
                <div>
                    <label for="nicknameInput" class="block text-xs font-bold text-slate-700 mb-1.5">
                        Nickname / Custom Name
                    </label>
                    <div class="relative">
                        <input type="text" id="nicknameInput" maxlength="50" placeholder="e.g. Alex (Frontend), Bhai, Boss..." 
                            class="w-full px-3.5 py-2.5 bg-white border border-slate-300 rounded-xl text-xs font-semibold text-slate-800 placeholder-slate-400 focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 outline-none transition">
                        <button type="button" onclick="clearNicknameInput()" title="Clear" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1">
                            <span class="material-icons-round text-sm">clear</span>
                        </button>
                    </div>
                    <p class="text-[10.5px] text-slate-400 font-medium mt-1.5">
                        💡 Leave empty and click "Remove Nickname" to restore the original name.
                    </p>
                </div>

                <!-- Buttons -->
                <div class="pt-2 border-t border-slate-100 flex items-center justify-between gap-2">
                    <button type="button" id="btnRemoveNickname" onclick="submitRemoveNickname()" class="px-3 py-2 text-rose-600 hover:bg-rose-50 rounded-xl text-xs font-bold transition flex items-center gap-1">
                        <span class="material-icons-round text-sm">delete_outline</span>
                        <span>Remove</span>
                    </button>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="closeNicknameModal()" class="px-3.5 py-2 rounded-xl text-xs font-bold text-slate-600 hover:bg-slate-100 transition">
                            Cancel
                        </button>
                        <button type="submit" id="btnSaveNickname" class="px-4 py-2 bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-white rounded-xl text-xs font-extrabold shadow-md shadow-amber-200 transition flex items-center gap-1 active:scale-95">
                            <span class="material-icons-round text-sm">check</span>
                            <span>Save Nickname</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================
         GROUP INFO & MEMBERS MODAL
         ======================================================== -->
    <div id="groupInfoModal" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 w-full max-w-md p-6 overflow-hidden animate-in fade-in zoom-in-95 duration-150 flex flex-col max-h-[90vh]">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-3">
                <div class="flex items-center gap-2.5">
                    <div class="w-10 h-10 rounded-2xl bg-indigo-600 text-white flex items-center justify-center shadow-md shadow-indigo-200">
                        <span class="material-icons-round text-xl">groups</span>
                    </div>
                    <div>
                        <h3 id="groupInfoTitle" class="font-extrabold text-slate-900 text-base leading-tight">Group Info</h3>
                        <p class="text-[11px] text-slate-400 font-medium">Group details & members</p>
                    </div>
                </div>
                <button type="button" onclick="closeGroupInfoModal()" class="text-slate-400 hover:text-slate-600 rounded-xl p-1.5 hover:bg-slate-100 transition">
                    <span class="material-icons-round text-xl">close</span>
                </button>
            </div>

            <div class="flex-1 overflow-y-auto space-y-4 pr-1">
                <!-- Group Details Box -->
                <div class="p-4 bg-slate-50 border border-slate-200/80 rounded-2xl text-center">
                    <div class="w-16 h-16 rounded-3xl bg-gradient-to-tr from-indigo-600 to-violet-600 text-white flex items-center justify-center mx-auto mb-2 text-2xl font-black shadow-lg shadow-indigo-200">
                        <span class="material-icons-round text-3xl">groups</span>
                    </div>
                    <h4 id="groupInfoName" class="text-base font-black text-slate-900">Group Name</h4>
                    <p id="groupInfoDesc" class="text-xs text-slate-500 mt-0.5 font-medium"></p>
                    <div class="mt-2.5 flex items-center justify-center gap-2 text-[11px] text-slate-400 font-semibold">
                        <span id="groupInfoCreator">Created by Admin</span>
                        <span>•</span>
                        <span id="groupInfoDate">Oct 2026</span>
                    </div>
                </div>

                <!-- Members Header with Add Member Button -->
                <div class="flex items-center justify-between">
                    <h5 class="text-xs font-black text-slate-800 uppercase tracking-wider flex items-center gap-1.5">
                        <span>Members</span>
                        <span id="groupInfoMemberCountBadge" class="text-[11px] bg-indigo-100 text-indigo-700 font-bold px-2 py-0.5 rounded-full">0</span>
                    </h5>
                    <button type="button" id="btnAddMembersToGroupBtn" onclick="openAddMembersModal()" class="hidden text-xs font-bold text-indigo-600 hover:text-indigo-800 flex items-center gap-1 bg-indigo-50 hover:bg-indigo-100 px-2.5 py-1 rounded-lg transition">
                        <span class="material-icons-round text-sm">person_add</span> Add Member
                    </button>
                </div>

                <!-- Members List -->
                <div id="groupInfoMembersList" class="divide-y divide-slate-100 border border-slate-200/80 rounded-2xl bg-white p-1 max-h-56 overflow-y-auto">
                    <!-- Rendered via JS -->
                </div>
            </div>

            <div class="pt-3 border-t border-slate-100 flex items-center justify-between">
                <button type="button" onclick="leaveCurrentGroup()" class="px-3.5 py-2 text-rose-600 hover:bg-rose-50 rounded-xl text-xs font-bold transition flex items-center gap-1">
                    <span class="material-icons-round text-base">logout</span>
                    <span>Leave Group</span>
                </button>
                <button type="button" onclick="closeGroupInfoModal()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition">
                    Close
                </button>
            </div>
        </div>
    </div>

    <!-- ========================================================
         ADD MEMBERS TO EXISTING GROUP MODAL
         ======================================================== -->
    <div id="addMembersModal" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 w-full max-w-md p-6 overflow-hidden animate-in fade-in zoom-in-95 duration-150 flex flex-col max-h-[85vh]">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-3">
                <div class="flex items-center gap-2">
                    <span class="p-2 bg-indigo-50 text-indigo-600 rounded-xl material-icons-round text-lg">person_add</span>
                    <div>
                        <h3 class="font-extrabold text-slate-900 text-base">Add Members</h3>
                        <p class="text-[11px] text-slate-400 font-medium">Select team members to add to this group</p>
                    </div>
                </div>
                <button type="button" onclick="closeAddMembersModal()" class="text-slate-400 hover:text-slate-600 rounded-xl p-1 transition">
                    <span class="material-icons-round">close</span>
                </button>
            </div>

            <div class="relative mb-2">
                <span class="material-icons-round absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">search</span>
                <input type="text" id="addMemberSearchInput" oninput="filterAddMembersChecklist()" placeholder="Search members..." 
                    class="w-full pl-8 pr-3 py-1.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-medium focus:bg-white outline-none transition">
            </div>

            <div id="addMembersChecklist" class="flex-1 overflow-y-auto divide-y divide-slate-100 border border-slate-200/80 rounded-2xl p-1 max-h-60 bg-slate-50/50">
                <!-- Rendered by JS -->
            </div>

            <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-end gap-2">
                <button type="button" onclick="closeAddMembersModal()" class="px-4 py-2 rounded-xl text-xs font-bold text-slate-600 hover:bg-slate-100 transition">
                    Cancel
                </button>
                <button type="button" onclick="submitAddMembersToGroup()" id="btnConfirmAddMembers" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-extrabold shadow-sm transition">
                    Add Selected
                </button>
            </div>
        </div>
    </div>

    <!-- ========================================================
         OUTGOING CALL MODAL
         ======================================================== -->
    <div id="outgoingCallModal" class="hidden fixed inset-0 z-50 bg-slate-900/70 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 w-full max-w-sm p-6 text-center overflow-hidden animate-in fade-in zoom-in-95 duration-200">
            <!-- Pulsing Avatar -->
            <div class="relative w-24 h-24 mx-auto mb-4">
                <span class="animate-ping absolute inset-0 rounded-full bg-indigo-400 opacity-40"></span>
                <div id="outgoingCallAvatar" class="relative w-24 h-24 rounded-full bg-gradient-to-tr from-indigo-600 to-indigo-500 text-white flex items-center justify-center text-3xl font-black shadow-xl">
                    U
                </div>
            </div>

            <h3 id="outgoingCallName" class="text-lg font-black text-slate-900">Calling...</h3>
            <p id="outgoingCallRole" class="text-xs text-slate-400 font-semibold uppercase tracking-wider mt-0.5">Role</p>
            
            <div class="mt-2.5 inline-flex items-center gap-1.5 px-3 py-1 bg-indigo-50 text-indigo-700 rounded-full text-xs font-bold border border-indigo-200/80">
                <span id="outgoingCallTypeIcon" class="material-icons-round text-sm">call</span>
                <span id="outgoingCallTypeText">Voice Call • Ringing...</span>
            </div>

            <!-- Dedicated Busy Notice Card -->
            <div id="outgoingBusyBox" class="hidden mt-4 p-3.5 bg-amber-50 border border-amber-200/90 rounded-2xl text-left animate-in fade-in duration-200">
                <div class="flex items-start gap-2.5">
                    <span class="material-icons-round text-amber-600 text-lg flex-shrink-0 mt-0.5">phone_in_talk</span>
                    <div>
                        <p class="text-xs font-black text-amber-900">User is on another call</p>
                        <p class="text-[11px] text-amber-700 font-medium leading-tight mt-0.5">
                            Aap jis vyakti ko call kar rahe hain, woh doosre call par vyast hain. Kripya pratiksha karein.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Dedicated Offline / Net Off Notice Card -->
            <div id="outgoingOfflineBox" class="hidden mt-4 p-3.5 bg-slate-100 border border-slate-300/90 rounded-2xl text-left animate-in fade-in duration-200">
                <div class="flex items-start gap-2.5">
                    <span class="material-icons-round text-slate-600 text-lg flex-shrink-0 mt-0.5">wifi_off</span>
                    <div>
                        <p class="text-xs font-black text-slate-900">User is Offline / Internet Off</p>
                        <p class="text-[11px] text-slate-600 font-medium leading-tight mt-0.5">
                            Aap jis vyakti ko call kar rahe hain, woh abhi offline hain. Kripya unke online aane ka wait karein.
                        </p>
                    </div>
                </div>
            </div>

            <div class="mt-8 flex justify-center">
                <button type="button" onclick="cancelOutgoingCall()" class="w-14 h-14 rounded-full bg-rose-600 hover:bg-rose-700 text-white flex items-center justify-center shadow-lg shadow-rose-200 transition active:scale-95" title="End Call">
                    <span class="material-icons-round text-2xl">call_end</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ========================================================
         INCOMING CALL MODAL
         ======================================================== -->
    <div id="incomingCallModal" class="hidden fixed inset-0 z-50 bg-slate-900/80 backdrop-blur-md flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 w-full max-w-sm p-6 text-center overflow-hidden animate-in fade-in zoom-in-95 duration-200">
            <!-- Ringing Avatar -->
            <div class="relative w-24 h-24 mx-auto mb-4">
                <span class="animate-ping absolute inset-0 rounded-full bg-emerald-400 opacity-60"></span>
                <div id="incomingCallAvatar" class="relative w-24 h-24 rounded-full bg-gradient-to-tr from-emerald-600 to-teal-500 text-white flex items-center justify-center text-3xl font-black shadow-xl">
                    U
                </div>
            </div>

            <h3 id="incomingCallName" class="text-lg font-black text-slate-900">Incoming Call</h3>
            <p id="incomingCallRole" class="text-xs text-slate-400 font-semibold uppercase tracking-wider mt-0.5">Role</p>
            
            <div class="mt-2.5 inline-flex items-center gap-1.5 px-3.5 py-1 bg-emerald-50 text-emerald-700 rounded-full text-xs font-bold border border-emerald-200">
                <span id="incomingCallTypeIcon" class="material-icons-round text-sm">videocam</span>
                <span id="incomingCallTypeText">Incoming Video Call</span>
            </div>

            <!-- Action Buttons: Accept / Decline -->
            <div class="mt-8 flex items-center justify-center gap-8">
                <!-- Decline (Red) -->
                <button type="button" onclick="declineIncomingCall()" class="flex flex-col items-center gap-1 group">
                    <div class="w-14 h-14 rounded-full bg-rose-500 group-hover:bg-rose-600 text-white flex items-center justify-center shadow-lg shadow-rose-200 transition active:scale-95">
                        <span class="material-icons-round text-2xl">call_end</span>
                    </div>
                    <span class="text-[11px] font-bold text-slate-500 group-hover:text-rose-600">Decline</span>
                </button>

                <!-- Accept (Green) -->
                <button type="button" onclick="acceptIncomingCall()" class="flex flex-col items-center gap-1 group">
                    <div class="w-14 h-14 rounded-full bg-emerald-500 group-hover:bg-emerald-600 text-white flex items-center justify-center shadow-lg shadow-emerald-200 transition active:scale-95 animate-bounce">
                        <span class="material-icons-round text-2xl">call</span>
                    </div>
                    <span class="text-[11px] font-bold text-slate-500 group-hover:text-emerald-600">Accept</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ========================================================
         ACTIVE CALL SCREEN MODAL (Full Voice / Video Calling UI)
         ======================================================== -->
    <div id="activeCallModal" class="hidden fixed inset-0 z-50 bg-black overflow-hidden select-none">
        
        <!-- Active Call Floating Header (Absolute Overlay - No Height Cut) -->
        <div class="absolute top-0 inset-x-0 z-30 flex items-center justify-between p-3.5 sm:p-5 bg-gradient-to-b from-black/80 via-black/30 to-transparent pointer-events-auto">
            <div class="flex items-center gap-3 text-white">
                <div class="w-10 h-10 rounded-full bg-white/10 backdrop-blur-md flex items-center justify-center font-black text-sm border border-white/20 shadow-md" id="activeCallPeerInitial">
                    U
                </div>
                <div>
                    <h3 id="activeCallPeerName" class="text-sm sm:text-base font-extrabold leading-tight drop-shadow-sm">Team Member</h3>
                    <div class="flex items-center gap-2 mt-0.5">
                        <span id="activeCallTypeBadge" class="text-[10px] font-bold uppercase tracking-wider text-emerald-400 bg-emerald-950/80 px-2 py-0.5 rounded-full border border-emerald-500/30">Video Call</span>
                        <span id="callDurationTimer" class="font-mono text-xs text-white font-bold bg-white/20 backdrop-blur-md px-2 py-0.5 rounded-full shadow-xs">00:00</span>
                        <!-- Recording Badge Indicator -->
                        <span id="callRecordingBadge" class="hidden items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-rose-300 bg-rose-950/80 px-2 py-0.5 rounded-full border border-rose-500/40 animate-pulse">
                            <span class="w-2 h-2 rounded-full bg-rose-500"></span>
                            <span>REC</span>
                        </span>
                    </div>
                    <div id="activeCallParticipantsBar" class="hidden flex items-center gap-1.5 mt-1 overflow-x-auto max-w-sm"></div>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <!-- Quick Screen Recording Button in Top Bar -->
                <button type="button" id="btnHeaderRecordCall" onclick="toggleCallRecording()" class="px-3 py-1.5 rounded-full bg-white/15 hover:bg-rose-600 text-white backdrop-blur-md text-xs font-bold border border-white/20 transition flex items-center gap-1.5 active:scale-95 shadow-md" title="Record this Video Call & Download Recording">
                    <span id="iconHeaderRecord" class="material-icons-round text-base text-rose-400">fiber_manual_record</span>
                    <span id="textHeaderRecord" class="hidden sm:inline">Record Call</span>
                </button>
                <!-- Fullscreen Quick Button -->
                <button type="button" onclick="toggleCallFullScreen()" class="px-3 py-1.5 rounded-full bg-white/15 hover:bg-white/25 text-white backdrop-blur-md text-xs font-bold border border-white/20 transition flex items-center gap-1.5 active:scale-95 shadow-md" title="Toggle Full Screen">
                    <span id="iconHeaderFullScreen" class="material-icons-round text-base">fullscreen</span>
                    <span class="hidden sm:inline">Full Screen</span>
                </button>
                <!-- P2P Encrypted Badge -->
                <div class="flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-white/15 backdrop-blur-md text-emerald-400 text-xs font-bold border border-white/20 shadow-md">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span class="text-[11px] hidden sm:inline">Encrypted P2P</span>
                </div>
            </div>
        </div>

        <!-- Active Call Stage / Viewport (100% Full Bleed - ZERO Waste Space) -->
        <div class="absolute inset-0 flex items-center justify-center overflow-hidden bg-black">
            
            <!-- Floating Screen Sharing Status Banner -->
            <div id="screenShareBanner" class="hidden absolute top-16 sm:top-20 z-30 left-1/2 transform -translate-x-1/2 transition-all duration-200">
                <div class="flex items-center gap-2.5 px-4 py-2 rounded-2xl bg-indigo-600/95 hover:bg-indigo-600 text-white backdrop-blur-md shadow-2xl border border-indigo-400/40 text-xs font-bold animate-in fade-in zoom-in-95 duration-150">
                    <span class="material-icons-round text-base animate-pulse">screen_share</span>
                    <span>You are sharing your screen</span>
                    <button type="button" onclick="stopScreenShare()" class="ml-2 px-2.5 py-1 bg-white/20 hover:bg-white/30 rounded-xl text-[11px] font-extrabold uppercase tracking-wider transition active:scale-95 shadow-sm">
                        Stop Sharing
                    </button>
                </div>
            </div>

            <!-- Video Mode: Ambient Blurred Background (Cinematic fill in case aspect ratio differs) -->
            <video id="remoteVideoBg" autoplay playsinline muted class="absolute inset-0 w-full h-full object-cover blur-3xl opacity-50 scale-110 pointer-events-none transition-all duration-300"></video>

            <!-- Video Mode: Main Remote Stream Video (100% Full Cover - Poori screen cover karega bina black borders ke!) -->
            <video id="remoteVideo" autoplay playsinline class="relative z-10 w-full h-full object-cover transition-all duration-300"></video>

            <!-- Video Mode: Local Stream PiP Preview (Floating in corner with smooth drag/position) -->
            <div id="localVideoContainer" class="absolute bottom-24 right-4 sm:right-6 w-36 h-48 sm:w-52 sm:h-72 rounded-2xl overflow-hidden shadow-2xl border-2 border-white/40 bg-slate-900 z-20 transition-all hover:scale-105">
                <video id="localVideo" autoplay playsinline muted class="w-full h-full object-cover transform -scale-x-100 transition-all duration-300"></video>
                <div class="absolute bottom-1.5 left-2 px-1.5 py-0.5 rounded bg-black/60 text-[9px] font-bold text-white flex items-center gap-1">
                    <span id="localVideoLabel">You</span>
                    <span id="localFilterBadge" class="hidden text-[8px] text-emerald-400 font-semibold">• Filter on</span>
                </div>
            </div>

            <!-- Voice Mode: Pulsing Audio Avatar Graphic -->
            <div id="voiceCallStage" class="hidden flex flex-col items-center justify-center text-center z-10">
                <div class="relative w-36 h-36 sm:w-44 sm:h-44 mb-6 flex items-center justify-center">
                    <span class="animate-ping absolute inset-0 rounded-full bg-indigo-500/30"></span>
                    <span class="animate-pulse absolute -inset-4 rounded-full bg-indigo-500/20"></span>
                    <div id="voiceAvatarCircle" class="relative w-36 h-36 sm:w-44 sm:h-44 rounded-full bg-gradient-to-tr from-indigo-600 to-purple-600 text-white flex items-center justify-center text-5xl font-black shadow-2xl border-4 border-white/20">
                        U
                    </div>
                </div>
                <h2 id="voicePeerName" class="text-xl sm:text-2xl font-black text-white">Team Member</h2>
                <p id="voiceSubtext" class="text-xs text-indigo-300 font-semibold mt-1">DigiChat Voice Call in progress...</p>
                <div id="voiceGroupRoster" class="hidden mt-4 flex flex-wrap items-center justify-center gap-2 max-w-md"></div>
            </div>

            <!-- Remote Audio Output Element -->
            <audio id="remoteAudio" autoplay></audio>
        </div>

        <!-- Video Filters Drawer Panel (Floating above bottom bar) -->
        <div id="videoFiltersDrawer" class="hidden absolute bottom-24 sm:bottom-28 z-30 left-1/2 transform -translate-x-1/2 w-[92%] max-w-lg transition-all duration-300">
            <div class="bg-slate-900/95 backdrop-blur-2xl p-4 rounded-3xl border border-white/20 shadow-2xl text-white">
                <div class="flex items-center justify-between mb-3 px-1">
                    <div class="flex items-center gap-2">
                        <span class="material-icons-round text-emerald-400 text-lg">auto_fix_high</span>
                        <span class="text-xs font-extrabold uppercase tracking-wider text-slate-200">Video Effects & Filters</span>
                    </div>
                    <button type="button" onclick="toggleFiltersDrawer()" class="text-slate-400 hover:text-white p-1 rounded-full hover:bg-white/10 transition">
                        <span class="material-icons-round text-base">close</span>
                    </button>
                </div>
                
                <!-- Filter Chips Grid / Carousel -->
                <div class="flex items-center gap-2.5 overflow-x-auto pb-2 scrollbar-none">
                    <!-- Filter: Normal -->
                    <button type="button" onclick="applyVideoFilter('normal')" data-filter="normal" class="filter-chip active flex flex-col items-center gap-1.5 p-2 rounded-2xl bg-white/10 hover:bg-white/20 border-2 border-emerald-400 min-w-[68px] transition active:scale-95 flex-shrink-0">
                        <div class="w-10 h-10 rounded-xl bg-slate-700 flex items-center justify-center text-xs font-bold text-slate-200">
                            <span class="material-icons-round text-base">block</span>
                        </div>
                        <span class="text-[10px] font-bold text-slate-300">Normal</span>
                    </button>

                    <!-- Filter: Beauty Glow -->
                    <button type="button" onclick="applyVideoFilter('beauty')" data-filter="beauty" class="filter-chip flex flex-col items-center gap-1.5 p-2 rounded-2xl bg-white/10 hover:bg-white/20 border-2 border-transparent min-w-[68px] transition active:scale-95 flex-shrink-0">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-pink-500 via-rose-500 to-amber-300 flex items-center justify-center text-base shadow-sm">
                            ✨
                        </div>
                        <span class="text-[10px] font-bold text-slate-300">Glow</span>
                    </button>

                    <!-- Filter: Warm Sunset -->
                    <button type="button" onclick="applyVideoFilter('warm')" data-filter="warm" class="filter-chip flex flex-col items-center gap-1.5 p-2 rounded-2xl bg-white/10 hover:bg-white/20 border-2 border-transparent min-w-[68px] transition active:scale-95 flex-shrink-0">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-amber-500 to-orange-500 flex items-center justify-center text-base shadow-sm">
                            ☀️
                        </div>
                        <span class="text-[10px] font-bold text-slate-300">Warm</span>
                    </button>

                    <!-- Filter: Studio Cool -->
                    <button type="button" onclick="applyVideoFilter('cool')" data-filter="cool" class="filter-chip flex flex-col items-center gap-1.5 p-2 rounded-2xl bg-white/10 hover:bg-white/20 border-2 border-transparent min-w-[68px] transition active:scale-95 flex-shrink-0">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-cyan-500 to-blue-600 flex items-center justify-center text-base shadow-sm">
                            ❄️
                        </div>
                        <span class="text-[10px] font-bold text-slate-300">Studio</span>
                    </button>

                    <!-- Filter: Vintage Film -->
                    <button type="button" onclick="applyVideoFilter('vintage')" data-filter="vintage" class="filter-chip flex flex-col items-center gap-1.5 p-2 rounded-2xl bg-white/10 hover:bg-white/20 border-2 border-transparent min-w-[68px] transition active:scale-95 flex-shrink-0">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-amber-800 to-yellow-700 flex items-center justify-center text-base shadow-sm">
                            🎞️
                        </div>
                        <span class="text-[10px] font-bold text-slate-300">Vintage</span>
                    </button>

                    <!-- Filter: Classic B&W -->
                    <button type="button" onclick="applyVideoFilter('noir')" data-filter="noir" class="filter-chip flex flex-col items-center gap-1.5 p-2 rounded-2xl bg-white/10 hover:bg-white/20 border-2 border-transparent min-w-[68px] transition active:scale-95 flex-shrink-0">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-slate-900 to-slate-400 flex items-center justify-center text-base shadow-sm">
                            🖤
                        </div>
                        <span class="text-[10px] font-bold text-slate-300">Noir B&W</span>
                    </button>

                    <!-- Filter: Vivid HD -->
                    <button type="button" onclick="applyVideoFilter('vibrant')" data-filter="vibrant" class="filter-chip flex flex-col items-center gap-1.5 p-2 rounded-2xl bg-white/10 hover:bg-white/20 border-2 border-transparent min-w-[68px] transition active:scale-95 flex-shrink-0">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-emerald-400 to-violet-600 flex items-center justify-center text-base shadow-sm">
                            💎
                        </div>
                        <span class="text-[10px] font-bold text-slate-300">Vivid HD</span>
                    </button>
                </div>

                <div class="mt-2.5 pt-2 border-t border-white/10 flex items-center justify-between text-[11px] text-slate-300">
                    <span class="text-slate-300 flex items-center gap-1">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                        <span>Live Sync: Filter will be visible to both sides in real-time</span>
                    </span>
                    <button type="button" onclick="applyVideoFilter('normal')" class="text-[11px] text-rose-400 hover:text-rose-300 underline font-bold transition">
                        Reset Filter
                    </button>
                </div>
            </div>
        </div>

        <!-- Active Call Bottom Action Floating Control Bar (Absolute Overlay) -->
        <div class="absolute bottom-0 inset-x-0 z-30 flex items-center justify-center p-4 sm:p-6 bg-gradient-to-t from-black/85 via-black/40 to-transparent pointer-events-auto">
            <div class="flex items-center gap-2 sm:gap-3.5 bg-black/40 backdrop-blur-2xl p-2.5 sm:p-3 rounded-full border border-white/20 shadow-2xl">
                <!-- Mute / Unmute Mic -->
                <button type="button" id="btnToggleMic" onclick="toggleMicrophone()" class="w-11 h-11 sm:w-12 sm:h-12 rounded-full bg-white/20 hover:bg-white/30 text-white flex items-center justify-center transition active:scale-95 shadow-md" title="Mute/Unmute Mic">
                    <span id="iconMic" class="material-icons-round text-xl sm:text-2xl">mic</span>
                </button>

                <!-- Toggle Camera -->
                <button type="button" id="btnToggleCam" onclick="toggleCamera()" class="w-11 h-11 sm:w-12 sm:h-12 rounded-full bg-white/20 hover:bg-white/30 text-white flex items-center justify-center transition active:scale-95 shadow-md" title="Turn Camera On/Off">
                    <span id="iconCam" class="material-icons-round text-xl sm:text-2xl">videocam</span>
                </button>

                <!-- Screen Share Button -->
                <button type="button" id="btnToggleScreenShare" onclick="toggleScreenShare()" class="w-11 h-11 sm:w-12 sm:h-12 rounded-full bg-white/20 hover:bg-white/30 text-white flex items-center justify-center transition active:scale-95 shadow-md" title="Share Screen / Window">
                    <span id="iconScreenShare" class="material-icons-round text-xl sm:text-2xl">screen_share</span>
                </button>

                <!-- Call Screen Recording Button -->
                <button type="button" id="btnRecordCall" onclick="toggleCallRecording()" class="w-11 h-11 sm:w-12 sm:h-12 rounded-full bg-white/20 hover:bg-rose-600 text-white flex items-center justify-center transition active:scale-95 shadow-md group" title="Record Call (Save video)">
                    <span id="iconRecordCall" class="material-icons-round text-xl sm:text-2xl text-rose-400 group-hover:text-white">fiber_manual_record</span>
                </button>

                <!-- Video Filters Button -->
                <button type="button" id="btnToggleFilters" onclick="toggleFiltersDrawer()" class="w-11 h-11 sm:w-12 sm:h-12 rounded-full bg-white/20 hover:bg-white/30 text-white flex items-center justify-center transition active:scale-95 shadow-md" title="Video Filters & Effects">
                    <span id="iconFilters" class="material-icons-round text-xl sm:text-2xl text-emerald-400">auto_fix_high</span>
                </button>

                <!-- Fit Screen (No Cut) vs Fill Screen Toggle -->
                <button type="button" id="btnAspectFit" onclick="toggleVideoAspectMode()" class="w-11 h-11 sm:w-12 sm:h-12 rounded-full bg-white/20 hover:bg-white/30 text-white flex items-center justify-center transition active:scale-95 shadow-md" title="Fit Entire Video (No Cut) / Fill Screen">
                    <span id="iconAspectFit" class="material-icons-round text-xl sm:text-2xl">crop_free</span>
                </button>

                <!-- Fullscreen Button -->
                <button type="button" id="btnToggleFullScreen" onclick="toggleCallFullScreen()" class="w-11 h-11 sm:w-12 sm:h-12 rounded-full bg-white/20 hover:bg-white/30 text-white flex items-center justify-center transition active:scale-95 shadow-md" title="Toggle Full Screen">
                    <span id="iconFullScreen" class="material-icons-round text-xl sm:text-2xl">fullscreen</span>
                </button>

                <!-- End Call -->
                <button type="button" onclick="endCurrentCall()" class="w-12 h-12 sm:w-14 sm:h-14 rounded-full bg-rose-600 hover:bg-rose-700 text-white flex items-center justify-center transition active:scale-95 shadow-lg shadow-rose-900/50" title="End Call">
                    <span class="material-icons-round text-xl sm:text-2xl">call_end</span>
                </button>
            </div>
        </div>

        <!-- Call Ended / Peer Left Call Overlay -->
        <div id="callEndedOverlay" class="hidden absolute inset-0 z-50 bg-slate-950/92 backdrop-blur-lg flex flex-col items-center justify-center p-6 text-center animate-in fade-in zoom-in-95 duration-200">
            <div class="relative w-24 h-24 mb-5 flex items-center justify-center">
                <span class="absolute inset-0 rounded-full bg-rose-500/20 animate-ping"></span>
                <div class="relative w-20 h-20 rounded-full bg-gradient-to-tr from-rose-600 to-rose-500 flex items-center justify-center text-white shadow-2xl shadow-rose-900/60">
                    <span class="material-icons-round text-3xl">call_end</span>
                </div>
            </div>
            <h3 id="callEndedTitle" class="text-2xl font-black text-white tracking-tight">Team Member left the call</h3>
            <p id="callEndedSubtitle" class="text-xs sm:text-sm text-slate-400 font-medium mt-1">Call has ended</p>
            <div class="mt-4 inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-white/10 text-slate-200 text-xs font-mono font-bold border border-white/15">
                <span class="material-icons-round text-sm text-indigo-400">schedule</span>
                <span id="callEndedDuration">Duration: 00:00</span>
            </div>
            <div class="mt-8">
                <button type="button" onclick="closeCallEndedOverlay()" class="px-6 py-2.5 rounded-2xl bg-indigo-600 hover:bg-indigo-700 active:scale-95 text-white font-extrabold text-xs shadow-lg shadow-indigo-600/30 transition flex items-center gap-2">
                    <span class="material-icons-round text-base">arrow_back</span>
                    <span>Return to Chat</span>
                </button>
            </div>
        </div>
    </div>

    <!-- JAVASCRIPT LOGIC -->
    <script>
        // Global Error Boundary & Crash Protection
        window.addEventListener('error', function(e) {
            console.warn('[DigiChat SafeGuard] Non-fatal error intercepted:', e.message, e.filename, e.lineno);
            // Prevent fatal browser alert/crash loop
        });
        window.addEventListener('unhandledrejection', function(e) {
            console.warn('[DigiChat SafeGuard] Unhandled promise rejection intercepted:', e.reason);
            // Prevent unhandled promise rejection from halting the thread
        });

        // Safe JSON Parse Helper
        function safeJsonParse(data, fallback = null) {
            if (data === null || data === undefined) return fallback;
            if (typeof data !== 'string') return data;
            try {
                return JSON.parse(data);
            } catch (err) {
                console.warn('[DigiChat SafeGuard] JSON.parse error prevented:', err, data);
                return fallback;
            }
        }

        const myUserId = <?= $myId ?>;
        const myUserRole = "<?= $myRole ?>";
        let activeContactId = null;
        let activeGroupId = null;
        let activeGroupCallData = null;
        let lastMessageId = 0;
        let contactsData = [];
        let groupsData = [];
        let activeCategory = 'all';
        let isPolling = false;
        let selectedFile = null;
        let activeEmojiTab = 'smileys';
        let lastRenderedDate = null; // for date-wise grouping
        let messagesDataMap = new Map();
        let selectedContextMenuMsgId = null;
        let activeReplyToMsg = null;
        let isMessageSelectMode = false;
        let selectedMessageIds = new Set();
        let forwardTargetMsgIds = [];
        let callsHistoryData = [];

        /* Full Categorized Emoji Dataset */
        const emojiCategories = {
            smileys: {
                name: "Smileys & Faces",
                emojis: ["😀","😃","😄","😁","😆","😅","😂","🤣","🥲","🥹","😊","😇","🙂","🙃","😉","😌","😍","🥰","😘","😗","😚","😋","😛","😜","🤪","😝","🤑","🤗","🤭","🤫","🤔","🫡","🤐","🤨","😐","😑","😶","🫥","😏","😒","🙄","😬","🤥","😌","😔","😪","🤤","😴","😷","🤒","🤕","🤢","🤮","🤧","🥵","🥶","🥴","😵","🤯","🤠","🥳","🥸","😎","🤓","🧐","😕","😟","🙁","😮","😯","😲","😳","🥺","😦","😧","😨","😰","😥","😢","😭","😱","😖","😣","😞","😓","😩","😫","🥱","😤","😡","😠","🤬","😈","👿","💀","☠️","💩","🤡","👻","👽","🤖"]
            },
            gestures: {
                name: "People & Gestures",
                emojis: ["👋","🤚","🖐️","✋","🖖","🫱","🫲","🫳","🫴","👌","🤌","🤏","✌️","🤞","🫰","🤟","🤘","🤙","👈","👉","👆","🖕","👇","☝️","🫵","👍","👎","✊","👊","🤛","🤜","👏","🙌","🫶","👐","🤲","🤝","🙏","✍️","💅","🤳","💪","🦾","🧑‍💻","👨‍💼","👩‍💼","🧑‍💼","🕵️","🧑‍🏫","🧑‍🔬"]
            },
            reactions: {
                name: "Hearts & Reactions",
                emojis: ["❤️","🧡","💛","💚","💙","💜","🖤","🤍","🤎","💔","❤️‍🔥","❤️‍🩹","❣️","💕","💞","💓","💗","💖","💘","💝","💟","💯","🔥","✨","💥","💢","💫","💦","💨","🕊️","⭐","🌟","🎉","🎊","🎁"]
            },
            work: {
                name: "Work & Office",
                emojis: ["💼","📁","📂","📄","📑","📊","📈","📉","💻","🖥️","⌨️","🖱️","📱","☎️","📞","📟","📠","✉️","📧","📨","📩","📤","📥","📦","🏷️","✏️","✒️","📝","🗒️","🗓️","📅","📆","📌","📍","📎","🖇️","🔒","🔓","🔏","🔐","🔑"]
            },
            status: {
                name: "Symbols & Status",
                emojis: ["✅","✔️","☑️","❌","❎","➕","➖","➗","✖️","⚠️","⛔","🚫","🛑","🔴","🟢","🟡","🔵","🟣","🟠","⚫","⚪","💬","💭","🗯️","📢","🔔","🔕","⏳","⌛","⏰","⏱️","💡","⚡","🎯","🏆","🥇","🥈"]
            }
        };

        /* Notification Chime Sound */
        function playChimeSound() {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.type = 'sine';
                osc.frequency.setValueAtTime(587.33, ctx.currentTime);
                osc.frequency.exponentialRampToValueAtTime(880, ctx.currentTime + 0.12);
                gain.gain.setValueAtTime(0.15, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.28);
                osc.start();
                osc.stop(ctx.currentTime + 0.3);
            } catch(e) {}
        }

        /* Fetch all authorized contacts & groups */
        async function fetchContacts() {
            try {
                const res = await fetch('api.php?action=get_contacts');
                const data = await res.json();
                if (data.success) {
                    contactsData = data.contacts || [];
                    groupsData = data.groups || [];
                    renderContactsList();

                    // Update Groups tab count
                    const grpTabBadge = document.getElementById('groupTabCount');
                    if (grpTabBadge) {
                        if (groupsData.length > 0) {
                            grpTabBadge.textContent = groupsData.length;
                            grpTabBadge.classList.remove('hidden');
                        } else {
                            grpTabBadge.classList.add('hidden');
                        }
                    }

                    // Update Top unread badge
                    const totalBadge = document.getElementById('unreadTotalBadge');
                    if (data.total_unread > 0) {
                        totalBadge.innerText = data.total_unread > 99 ? '99+' : data.total_unread;
                        totalBadge.classList.remove('hidden');
                    } else {
                        totalBadge.classList.add('hidden');
                    }
                }
            } catch(err) {
                console.error("Fetch contacts error:", err);
            }
        }

        /* Render contacts & groups list in sidebar */
        function renderContactsList() {
            const container = document.getElementById('contactsContainer');
            const searchVal = document.getElementById('contactSearchInput').value.toLowerCase().trim();

            if (activeCategory === 'calls') {
                renderCallsList();
                return;
            }

            let filteredContacts = contactsData.filter(c => {
                const matchSearch = (c.name && c.name.toLowerCase().includes(searchVal)) || 
                                    (c.real_name && c.real_name.toLowerCase().includes(searchVal)) ||
                                    (c.nickname && c.nickname.toLowerCase().includes(searchVal)) ||
                                    (c.username && c.username.toLowerCase().includes(searchVal)) ||
                                    (c.role_label && c.role_label.toLowerCase().includes(searchVal));
                if (!matchSearch) return false;
                if (activeCategory === 'unread') return c.unread_count > 0;
                return true;
            });

            let filteredGroups = groupsData.filter(g => {
                const matchSearch = g.name.toLowerCase().includes(searchVal) || 
                                    (g.description && g.description.toLowerCase().includes(searchVal));
                if (!matchSearch) return false;
                if (activeCategory === 'unread') return g.unread_count > 0;
                return true;
            });

            let combinedList = [];
            if (activeCategory === 'direct') {
                combinedList = filteredContacts;
            } else if (activeCategory === 'groups') {
                combinedList = filteredGroups;
            } else {
                combinedList = [...filteredContacts, ...filteredGroups];
            }

            // Pinned chats appear at the top, then sorted by most recent message/time
            combinedList.sort((a, b) => {
                const pinA = a.is_pinned ? 1 : 0;
                const pinB = b.is_pinned ? 1 : 0;
                if (pinA !== pinB) {
                    return pinB - pinA; // Pinned items first
                }
                const timeA = a.last_time ? new Date(a.last_time).getTime() : 0;
                const timeB = b.last_time ? new Date(b.last_time).getTime() : 0;
                return timeB - timeA;
            });

            const totalCount = combinedList.length;
            document.getElementById('contactCountLabel').innerText = `${totalCount} ${activeCategory === 'groups' ? 'group' : 'chat'}${totalCount === 1 ? '' : 's'}`;

            if (combinedList.length === 0) {
                container.innerHTML = `
                    <div class="py-16 text-center text-slate-400 text-xs px-6">
                        <span class="material-icons-round text-3xl mb-2 text-slate-300">search_off</span>
                        <p class="font-bold text-slate-700">No chats found</p>
                        <p class="mt-1 text-[11px]">${activeCategory === 'groups' ? 'No groups yet. Click "+ New Group" above to create one!' : 'Matching members or groups will appear here.'}</p>
                    </div>`;
                return;
            }

            let html = '';
            combinedList.forEach(item => {
                if (item.is_group) {
                    html += renderGroupSidebarItem(item);
                } else {
                    html += renderContactSidebarItem(item);
                }
            });

            container.innerHTML = html;
        }

        function renderContactSidebarItem(c) {
            const isActive = (c.id === activeContactId && !activeGroupId);
            const hasUnread = c.unread_count > 0;
            const formattedTime = formatTimestamp(c.last_time);
            const roleBadgeClass = getRoleClass(c.role);

            // Tick icon on last message (Single gray, double gray, double blue)
            let tickHtml = '';
            if (c.last_is_mine) {
                if (c.last_is_read) {
                    tickHtml = `<span class="material-icons-round text-[15px] text-[#38bdf8] font-black mr-1" title="Read">done_all</span>`;
                } else if (c.last_is_delivered) {
                    tickHtml = `<span class="material-icons-round text-[15px] text-slate-400 font-bold mr-1" title="Delivered">done_all</span>`;
                } else {
                    tickHtml = `<span class="material-icons-round text-[15px] text-slate-400 mr-1" title="Sent">done</span>`;
                }
            }

            // Presence indicator dot on avatar
            const avatarDotHtml = c.is_online
                ? `<span class="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full bg-emerald-500 border-2 border-white ring-1 ring-emerald-300 animate-pulse" title="Online"></span>`
                : `<span class="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full bg-slate-300 border-2 border-white" title="${escapeHtml(c.last_seen_text || 'Offline')}"></span>`;

            // Pin icon indicator
            const pinIconHtml = c.is_pinned 
                ? `<span class="material-icons-round text-sm text-indigo-600 -rotate-45" title="Pinned chat">push_pin</span>` 
                : '';

            return `
                <div onclick="selectContact(${c.id})" 
                     oncontextmenu="showChatContextMenu(event, 'direct', ${c.id}, ${c.is_pinned ? 'true' : 'false'})"
                     class="contact-item flex items-center gap-3.5 px-4 py-3.5 cursor-pointer border-b border-slate-100 ${isActive ? 'contact-active' : ''} ${c.is_pinned ? 'bg-slate-50/70' : ''}">
                    
                    <!-- Avatar with Online Dot (Click to Zoom Profile Photo) -->
                    <div class="relative flex-shrink-0 cursor-pointer" onclick="event.stopPropagation(); openDpZoomModal('${escapeHtml(c.profile_image || '')}', '${escapeHtml(c.name)}', '${escapeHtml(c.role_label || c.role || '')}')" title="Click to zoom ${escapeHtml(c.name)}'s Photo">
                        <div class="w-11 h-11 rounded-full ${isActive ? 'bg-indigo-600 text-white shadow-md shadow-indigo-200' : 'bg-slate-100 text-slate-700'} font-black text-sm flex items-center justify-center transition overflow-hidden hover:ring-2 hover:ring-indigo-400 dp-zoom-avatar border border-slate-200/60">
                            ${c.profile_image 
                                ? `<img src="${escapeHtml(c.profile_image)}" alt="${escapeHtml(c.name)}" class="w-full h-full object-cover rounded-full" onerror="this.remove()" />` 
                                : `<span>${escapeHtml(c.name.substring(0, 1).toUpperCase())}</span>`}
                        </div>
                        ${avatarDotHtml}
                    </div>

                    <!-- Info & Last Message -->
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between mb-0.5">
                            <h3 class="text-sm font-bold text-slate-900 truncate flex items-center gap-1.5">
                                <span class="truncate">${escapeHtml(c.name)}</span>
                                ${c.nickname ? `<span class="material-icons-round text-xs text-amber-500" title="Custom nickname">badge</span>` : ''}
                                ${pinIconHtml}
                            </h3>
                            <span class="text-[11px] ${hasUnread ? 'text-indigo-600 font-extrabold' : 'text-slate-400'} font-medium">${formattedTime}</span>
                        </div>
                        ${c.nickname && c.real_name ? `<p class="text-[10px] text-slate-400 font-medium truncate mb-0.5">${escapeHtml(c.real_name)}</p>` : ''}

                        <div class="flex items-center justify-between text-xs">
                            <div class="chat-text flex items-center text-slate-500 truncate pr-2 font-normal">
                                ${tickHtml}
                                <span class="truncate ${hasUnread ? 'text-slate-900 font-bold' : ''}">${escapeHtml(c.last_message || (c.is_online ? 'Active now' : c.last_seen_text))}</span>
                            </div>
                            ${hasUnread ? `<span class="bg-indigo-600 text-white text-[10px] font-black px-2 py-0.5 rounded-full min-w-4 text-center shadow-xs">${c.unread_count}</span>` : ''}
                        </div>

                        <div class="mt-1.5 flex items-center justify-between">
                            <div class="flex items-center gap-1.5">
                                <span class="text-[9px] font-extrabold uppercase tracking-wider px-2 py-0.5 rounded-md border ${roleBadgeClass}">
                                    ${escapeHtml(c.role_label)}
                                </span>
                                ${c.is_blocked_by_me ? `<span class="text-[9px] font-extrabold uppercase tracking-wider px-1.5 py-0.5 rounded-md bg-rose-50 text-rose-600 border border-rose-200">Blocked</span>` : ''}
                            </div>
                            ${c.is_online ? `<span class="text-[10px] font-bold text-emerald-600 flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500 inline-block animate-ping"></span>Online</span>` : ''}
                        </div>
                    </div>
                </div>`;
        }

        function renderGroupSidebarItem(g) {
            const isGrpActive = (parseInt(g.id) === parseInt(activeGroupId));
            const hasGrpUnread = g.unread_count > 0;
            const formattedGrpTime = formatTimestamp(g.last_time);

            // Pin icon indicator
            const pinIconHtml = g.is_pinned 
                ? `<span class="material-icons-round text-sm text-indigo-600 -rotate-45" title="Pinned chat">push_pin</span>` 
                : '';

            return `
                <div onclick="selectGroup(${g.id})" 
                     oncontextmenu="showChatContextMenu(event, 'group', ${g.id}, ${g.is_pinned ? 'true' : 'false'})"
                     class="contact-item flex items-center gap-3.5 px-4 py-3.5 cursor-pointer border-b border-slate-100 ${isGrpActive ? 'contact-active' : ''} ${g.is_pinned ? 'bg-slate-50/70' : ''}">
                    
                    <!-- Group Avatar with Group Icon -->
                    <div class="relative flex-shrink-0">
                        <div class="w-11 h-11 rounded-full ${isGrpActive ? 'bg-gradient-to-tr from-indigo-600 to-violet-600 text-white shadow-md shadow-indigo-200' : 'bg-gradient-to-tr from-indigo-100 to-indigo-50 text-indigo-700 border border-indigo-200/80'} font-black text-sm flex items-center justify-center transition border border-slate-200/60">
                            <span class="material-icons-round text-xl">groups</span>
                        </div>
                        <span class="absolute bottom-0 right-0 w-3.5 h-3.5 rounded-full bg-indigo-600 border-2 border-white flex items-center justify-center text-[7px] text-white font-black" title="Group">G</span>
                    </div>

                    <!-- Info & Last Message -->
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between mb-1">
                            <h3 class="text-sm font-bold text-slate-900 truncate flex items-center gap-1.5">
                                <span class="truncate">${escapeHtml(g.name)}</span>
                                ${pinIconHtml}
                            </h3>
                            <span class="text-[11px] ${hasGrpUnread ? 'text-indigo-600 font-extrabold' : 'text-slate-400'} font-medium">${formattedGrpTime}</span>
                        </div>

                        <div class="flex items-center justify-between text-xs">
                            <div class="chat-text flex items-center text-slate-500 truncate pr-2 font-normal">
                                <span class="truncate ${hasGrpUnread ? 'text-slate-900 font-bold' : ''}">${escapeHtml(g.last_message || 'Group created')}</span>
                            </div>
                            ${hasGrpUnread ? `<span class="bg-indigo-600 text-white text-[10px] font-black px-2 py-0.5 rounded-full min-w-4 text-center shadow-xs">${g.unread_count}</span>` : ''}
                        </div>

                        <div class="mt-1.5 flex items-center justify-between">
                            <div class="flex items-center gap-1.5">
                                <span class="text-[9px] font-extrabold uppercase tracking-wider px-2 py-0.5 rounded-md border bg-indigo-50 text-indigo-700 border-indigo-200">
                                    👥 ${g.member_count} members
                                </span>
                                ${g.my_role === 'admin' ? `<span class="text-[9px] font-extrabold uppercase tracking-wider px-1.5 py-0.5 rounded-md bg-amber-50 text-amber-700 border border-amber-200">Admin</span>` : ''}
                            </div>
                        </div>
                    </div>
                </div>`;
        }

        /* ========================================================
           WHATSAPP-STYLE CALLS HISTORY LOG
           ======================================================== */
        async function fetchCallsHistory() {
            try {
                const res = await fetch('api.php?action=get_call_history');
                const data = await res.json();
                if (data.success && Array.isArray(data.calls)) {
                    callsHistoryData = data.calls;
                    if (activeCategory === 'calls') {
                        renderCallsList();
                    }
                }
            } catch(e) {
                console.error("Fetch call history error:", e);
            }
        }

        function renderCallsList() {
            const container = document.getElementById('contactsContainer');
            const searchVal = document.getElementById('contactSearchInput').value.toLowerCase().trim();

            let filteredCalls = callsHistoryData.filter(c => {
                return c.peer_name.toLowerCase().includes(searchVal) ||
                       c.peer_role.toLowerCase().includes(searchVal) ||
                       (c.call_type && c.call_type.toLowerCase().includes(searchVal));
            });

            const totalCount = filteredCalls.length;
            document.getElementById('contactCountLabel').innerText = `${totalCount} call${totalCount === 1 ? '' : 's'}`;

            if (filteredCalls.length === 0) {
                container.innerHTML = `
                    <div class="py-20 text-center text-slate-400 text-xs px-6">
                        <div class="w-14 h-14 rounded-3xl bg-slate-100 text-slate-400 flex items-center justify-center mx-auto mb-3 shadow-inner">
                            <span class="material-icons-round text-3xl">phone_missed</span>
                        </div>
                        <h4 class="font-bold text-slate-700 text-sm">No call history</h4>
                        <p class="mt-1 text-[11px] text-slate-400 leading-relaxed max-w-xs mx-auto">Incoming, outgoing and missed voice & video calls will be logged here, just like WhatsApp.</p>
                    </div>`;
                return;
            }

            let html = '';
            filteredCalls.forEach(c => {
                const initial = c.peer_name.substring(0, 1).toUpperCase();
                const isGroup = c.is_group;
                
                // Direction icon & color (WhatsApp style arrows)
                let dirIcon = 'call_made';
                let dirColor = 'text-indigo-500';
                if (c.is_missed) {
                    dirIcon = 'call_missed';
                    dirColor = 'text-rose-500';
                } else if (c.direction === 'incoming') {
                    dirIcon = 'call_received';
                    dirColor = 'text-emerald-500';
                }

                const callTypeIcon = (c.call_type === 'video') ? 'videocam' : 'call';
                const callBackAction = `startQuickCall('${c.peer_type}', ${c.peer_id}, '${c.call_type}')`;

                html += `
                    <div onclick="openCallContact('${c.peer_type}', ${c.peer_id})" 
                         class="contact-item flex items-center justify-between gap-3 px-4 py-3.5 cursor-pointer border-b border-slate-100 hover:bg-slate-50 transition group">
                        <div class="flex items-center gap-3.5 min-w-0 flex-1">
                            <div class="relative flex-shrink-0">
                                <div class="w-11 h-11 rounded-2xl ${isGroup ? 'bg-indigo-50 text-indigo-700 border border-indigo-200' : 'bg-slate-100 text-slate-700 border border-slate-200'} font-black text-sm flex items-center justify-center shadow-xs">
                                    ${isGroup ? '<span class="material-icons-round text-lg">groups</span>' : initial}
                                </div>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-1.5 mb-1">
                                    <h4 class="text-xs font-bold ${c.is_missed ? 'text-rose-600' : 'text-slate-800'} truncate">${escapeHtml(c.peer_name)}</h4>
                                    <span class="text-[9px] font-bold text-slate-400 bg-slate-100 px-1.5 py-0.2 rounded border border-slate-200 truncate uppercase">${escapeHtml(c.peer_role)}</span>
                                </div>
                                <div class="flex items-center gap-1.5 text-[11px] text-slate-500 font-medium">
                                    <span class="material-icons-round text-sm ${dirColor}">${dirIcon}</span>
                                    <span class="${c.is_missed ? 'text-rose-600 font-bold' : ''}">${escapeHtml(c.time_formatted)}</span>
                                    <span class="text-slate-300">•</span>
                                    <span class="text-[10.5px] text-slate-400">${escapeHtml(c.duration_text)}</span>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0">
                            ${c.has_recording ? `
                                <button type="button" onclick="event.stopPropagation(); playCallRecording('${escapeHtml(c.recording_url)}', '${escapeHtml(c.peer_name)}', '${escapeHtml(c.time_formatted)}', '${c.call_type}')" 
                                    title="Play Call Recording" 
                                    class="px-2.5 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded-xl text-[11px] font-bold transition flex items-center gap-1.5 shadow-2xs active:scale-95">
                                    <span class="material-icons-round text-sm text-rose-600 animate-pulse">play_circle</span>
                                    <span>Rec</span>
                                </button>
                            ` : ''}
                            <button type="button" onclick="event.stopPropagation(); ${callBackAction}" title="Call ${escapeHtml(c.peer_name)}" 
                                class="p-2.5 rounded-xl ${c.call_type === 'video' ? 'text-indigo-600 hover:bg-indigo-50 hover:text-indigo-700' : 'text-emerald-600 hover:bg-emerald-50 hover:text-emerald-700'} transition flex items-center justify-center flex-shrink-0 active:scale-90 shadow-2xs border border-transparent hover:border-slate-200">
                                <span class="material-icons-round text-xl">${callTypeIcon}</span>
                            </button>
                        </div>
                    </div>
                `;
            });

            container.innerHTML = html;
        }

        async function startQuickCall(peerType, peerId, type) {
            if (peerType === 'group') {
                await selectGroup(peerId);
                startCall(type);
            } else {
                await selectContact(peerId);
                startCall(type);
            }
        }

        function openCallContact(peerType, peerId) {
            if (peerType === 'group') {
                selectGroup(peerId);
            } else {
                selectContact(peerId);
            }
        }

        /* Filter Conversations by Category (All, Direct, Groups, Unread, Calls) */
        function filterCategory(cat) {
            activeCategory = cat;
            const tabIds = ['btnFilterAll', 'btnFilterDirect', 'btnFilterGroups', 'btnFilterUnread', 'btnFilterCalls'];
            tabIds.forEach(id => {
                const el = document.getElementById(id);
                if (!el) return;
                const isGroup = (id === 'btnFilterGroups');
                const isCall = (id === 'btnFilterCalls');
                el.className = "py-1.5 px-1 rounded-xl bg-slate-100 text-slate-600 hover:bg-slate-200 transition text-center truncate" + (isGroup || isCall ? " flex items-center justify-center gap-0.5" : "");
            });
            const activeMap = {
                all: 'btnFilterAll',
                direct: 'btnFilterDirect',
                groups: 'btnFilterGroups',
                unread: 'btnFilterUnread',
                calls: 'btnFilterCalls'
            };
            const activeBtn = document.getElementById(activeMap[cat]);
            if (activeBtn) {
                const isGroup = (cat === 'groups');
                const isCall = (cat === 'calls');
                activeBtn.className = "py-1.5 px-1 rounded-xl bg-indigo-600 text-white shadow-xs transition text-center truncate" + (isGroup || isCall ? " flex items-center justify-center gap-0.5" : "");
            }
            if (cat === 'calls') {
                fetchCallsHistory();
            }
            renderContactsList();
        }

        /* Render Presence Indicator in Active Header */
        function renderActivePresence(isOnline, lastSeenText) {
            const area = document.getElementById('activePresenceArea');
            const dot = document.getElementById('activeAvatarDot');

            if (isOnline) {
                dot.className = "absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full bg-emerald-500 border-2 border-white ring-1 ring-emerald-300 animate-pulse";
                area.innerHTML = `
                    <div class="flex items-center gap-1.5 text-emerald-600 font-extrabold text-[11px] tracking-wider uppercase">
                        <span class="relative flex h-2 w-2">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                        </span>
                        <span>Online</span>
                    </div>`;
            } else {
                dot.className = "absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full bg-slate-300 border-2 border-white";
                area.innerHTML = `
                    <div class="flex items-center gap-1 text-slate-500 text-[11px] font-medium">
                        <span class="material-icons-round text-xs text-slate-400">schedule</span>
                        <span>${escapeHtml(lastSeenText || 'Offline')}</span>
                    </div>`;
            }
        }

        /* Generate Date Divider Pill */
        function renderDateDivider(dtString) {
            if (!dtString) return '';
            const msgDate = new Date(dtString);
            const today = new Date();
            const yesterday = new Date();
            yesterday.setDate(today.getDate() - 1);

            let label = '';
            if (msgDate.toDateString() === today.toDateString()) {
                label = 'Today';
            } else if (msgDate.toDateString() === yesterday.toDateString()) {
                label = 'Yesterday';
            } else {
                label = msgDate.toLocaleDateString([], { day: 'numeric', month: 'short', year: 'numeric' });
            }

            const div = document.createElement('div');
            div.className = "flex justify-center my-3 select-none";
            div.innerHTML = `
                <span class="px-3.5 py-1 rounded-xl bg-white border border-slate-200/90 text-slate-500 text-[11px] font-bold shadow-xs uppercase tracking-wider">
                    ${label}
                </span>`;
            return div;
        }

        /* ========================================================
           WHATSAPP STYLE GROUPS SYSTEM (Creation & Management)
           ======================================================== */
        let selectedGroupMemberIds = new Set();

        function openCreateGroupModal() {
            selectedGroupMemberIds.clear();
            const nameEl = document.getElementById('groupNameInput');
            if (nameEl) nameEl.value = '';
            const descEl = document.getElementById('groupDescInput');
            if (descEl) descEl.value = '';
            const searchEl = document.getElementById('groupMemberSearchInput');
            if (searchEl) searchEl.value = '';
            updateSelectedMembersCount();
            renderGroupMembersChecklist();
            const modal = document.getElementById('createGroupModal');
            if (modal) modal.classList.remove('hidden');
            setTimeout(() => { if (nameEl) nameEl.focus(); }, 120);
        }

        function closeCreateGroupModal() {
            const modal = document.getElementById('createGroupModal');
            if (modal) modal.classList.add('hidden');
        }

        function updateSelectedMembersCount() {
            const badge = document.getElementById('selectedMembersCountBadge');
            if (badge) {
                badge.innerText = `${selectedGroupMemberIds.size} selected`;
            }
        }

        function toggleGroupMemberSelect(userId) {
            const uid = parseInt(userId);
            if (selectedGroupMemberIds.has(uid)) {
                selectedGroupMemberIds.delete(uid);
            } else {
                selectedGroupMemberIds.add(uid);
            }
            updateSelectedMembersCount();
            const cb = document.getElementById(`chk-grp-member-${uid}`);
            if (cb) cb.checked = selectedGroupMemberIds.has(uid);
        }

        function renderGroupMembersChecklist() {
            const container = document.getElementById('groupMembersChecklist');
            if (!container) return;
            const search = (document.getElementById('groupMemberSearchInput')?.value || '').toLowerCase().trim();
            const filtered = contactsData.filter(c => {
                return c.name.toLowerCase().includes(search) ||
                       c.username.toLowerCase().includes(search) ||
                       c.role_label.toLowerCase().includes(search);
            });

            if (filtered.length === 0) {
                container.innerHTML = `<div class="p-6 text-center text-xs text-slate-400">No team members match search</div>`;
                return;
            }

            let html = '';
            filtered.forEach(c => {
                const isSelected = selectedGroupMemberIds.has(parseInt(c.id));
                html += `
                    <div onclick="toggleGroupMemberSelect(${c.id})" class="flex items-center justify-between p-2.5 hover:bg-white rounded-xl cursor-pointer transition select-none">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-xl bg-slate-100 text-slate-700 flex items-center justify-center font-bold text-xs">
                                ${escapeHtml(c.name.substring(0, 1).toUpperCase())}
                            </div>
                            <div>
                                <h4 class="text-xs font-bold text-slate-900 leading-tight">${escapeHtml(c.name)}</h4>
                                <span class="text-[10px] text-slate-400 font-semibold">${escapeHtml(c.role_label)}</span>
                            </div>
                        </div>
                        <input type="checkbox" id="chk-grp-member-${c.id}" value="${c.id}" 
                               ${isSelected ? 'checked' : ''} 
                               onclick="event.stopPropagation(); toggleGroupMemberSelect(${c.id})"
                               class="w-4 h-4 rounded text-indigo-600 focus:ring-indigo-500 border-slate-300 pointer-events-auto">
                    </div>
                `;
            });
            container.innerHTML = html;
        }

        function filterGroupMembersChecklist() {
            renderGroupMembersChecklist();
        }

        async function submitCreateGroup(e) {
            e.preventDefault();
            const nameInput = document.getElementById('groupNameInput');
            const name = nameInput ? nameInput.value.trim() : '';
            if (!name) {
                alert('Please enter a group name');
                if (nameInput) nameInput.focus();
                return;
            }

            const desc = document.getElementById('groupDescInput')?.value.trim() || '';
            const members = Array.from(selectedGroupMemberIds);

            const btn = document.getElementById('btnSubmitCreateGroup');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = `<span class="material-icons-round animate-spin text-base">sync</span> Creating...`;
            }

            const fd = new FormData();
            fd.append('action', 'create_group');
            fd.append('name', name);
            fd.append('description', desc);
            members.forEach(id => {
                fd.append('members[]', id);
                fd.append('member_ids[]', id);
            });

            try {
                const res = await fetch('api.php', { method: 'POST', body: fd });
                const data = await res.json();
                const newGroupId = data.group?.id || data.group_id;
                if (data.success && newGroupId) {
                    closeCreateGroupModal();
                    await fetchContacts();
                    selectGroup(newGroupId);
                } else {
                    alert(data.error || 'Failed to create group');
                }
            } catch(err) {
                console.error("Create group error:", err);
                alert('Network error while creating group.');
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = `<span class="material-icons-round text-base">group_add</span><span>Create Group</span>`;
                }
            }
        }

        /* Select a Group and load its conversation */
        async function selectGroup(groupId) {
            exitMessageSelectMode();
            activeGroupId = parseInt(groupId);
            activeContactId = null;

            pauseCurrentAudio();
            cancelVoiceRecording();
            cancelReply();
            hideTypingIndicator();
            const vBar = document.getElementById('voiceRecordingBar');
            if (vBar) vBar.classList.add('hidden');

            renderContactsList();

            // Toggle screens
            document.body.classList.add('mobile-chat-open');
            document.getElementById('emptyChatState').classList.add('hidden');
            document.getElementById('activeChatHeader').classList.remove('hidden');
            document.getElementById('messagesContainer').classList.remove('hidden');
            document.getElementById('chatInputBar').classList.remove('hidden');

            // Load and apply this group's custom wallpaper background
            loadChatWallpaper('group', activeGroupId);

            // Enable Group Calling (Voice & Video calls are available in Groups)
            const vBtn = document.getElementById('voiceCallBtn');
            const vidBtn = document.getElementById('videoCallBtn');
            if (vBtn) {
                vBtn.classList.remove('hidden');
                vBtn.setAttribute('title', 'Start Group Voice Call');
            }
            if (vidBtn) {
                vidBtn.classList.remove('hidden');
                vidBtn.setAttribute('title', 'Start Group Video Call');
            }
            document.getElementById('blockContactBtn').classList.add('hidden');
            const nickBtn = document.getElementById('headerNicknameBtn');
            if (nickBtn) nickBtn.classList.add('hidden');
            document.getElementById('groupInfoBtn').classList.remove('hidden');
            const banner = document.getElementById('blockedBanner');
            if (banner) banner.classList.add('hidden');

            const container = document.getElementById('messagesContainer');
            container.innerHTML = `
                <div class="py-24 text-center text-slate-400 text-xs">
                    <span class="material-icons-round animate-spin text-2xl text-indigo-600 mb-2">sync</span>
                    <p class="font-medium text-slate-600">Loading group conversation...</p>
                </div>`;

            try {
                const res = await fetch(`api.php?action=get_group_messages&group_id=${groupId}`);
                const data = await res.json();

                if (!data.success) {
                    alert(data.error || "Unable to open group");
                    closeActiveChat();
                    return;
                }

                // Check and display Active Group Call Banner
                const grpBanner = document.getElementById('activeGroupCallBanner');
                if (data.active_call && ['calling', 'ringing', 'connected'].includes(data.active_call.status)) {
                    activeGroupCallData = data.active_call;
                    const title = document.getElementById('activeGroupCallTitle');
                    const subtitle = document.getElementById('activeGroupCallSubtitle');
                    const icon = document.getElementById('activeGroupCallIcon');
                    if (title) title.textContent = `Group ${data.active_call.call_type === 'video' ? 'Video' : 'Voice'} Call is Live`;
                    if (subtitle) subtitle.textContent = `Started by ${data.active_call.caller_name || 'Member'} • Click Join to participate`;
                    if (icon) icon.textContent = data.active_call.call_type === 'video' ? 'videocam' : 'call';
                    if (grpBanner) grpBanner.classList.remove('hidden');
                } else {
                    activeGroupCallData = null;
                    if (grpBanner) grpBanner.classList.add('hidden');
                }

                // Set Header details
                document.getElementById('activeName').innerText = data.group.name;
                const badge = document.getElementById('activeRoleBadge');
                if (badge) {
                    badge.innerText = `Group • ${data.group.member_count} members`;
                    badge.className = `text-[10px] font-extrabold px-2.5 py-0.5 rounded-full border bg-indigo-50 text-indigo-700 border-indigo-200 uppercase tracking-wider`;
                }

                const avatarEl = document.getElementById('activeAvatar');
                avatarEl.innerHTML = `<span class="material-icons-round text-lg">groups</span>`;
                avatarEl.className = `w-10 h-10 sm:w-11 sm:h-11 rounded-full bg-gradient-to-tr from-indigo-600 to-violet-600 text-white font-black text-sm flex items-center justify-center shadow-md shadow-indigo-200 border-2 border-white ring-1 ring-slate-200`;

                // Subtitle / presence area
                const presenceArea = document.getElementById('activePresenceArea');
                const dot = document.getElementById('activeAvatarDot');
                if (dot) dot.className = "hidden";
                if (presenceArea) {
                    presenceArea.innerHTML = `
                        <div class="flex items-center gap-1 text-slate-500 text-[11px] font-medium truncate max-w-sm">
                            <span class="material-icons-round text-xs text-indigo-500">info</span>
                            <span class="truncate">${escapeHtml(data.group.description || 'Tap "Group Info" for details & roster')}</span>
                        </div>`;
                }

                // WhatsApp-style Security notice pill
                container.innerHTML = `
                    <div class="flex justify-center my-3 select-none">
                        <div class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl bg-indigo-50/90 border border-indigo-200/80 text-indigo-950 text-[11px] font-semibold shadow-xs max-w-md text-center leading-relaxed">
                            <span class="material-icons-round text-base text-indigo-600 flex-shrink-0">lock</span>
                            <span>This group conversation is end-to-end encrypted with AES-256 cipher DB storage. Only members of this group can read these messages.</span>
                        </div>
                    </div>
                `;
                messagesDataMap.clear();
                lastMessageId = 0;
                lastRenderedDate = null;

                data.messages.forEach(msg => {
                    appendMessageBubble(msg);
                    if (parseInt(msg.id) > lastMessageId) {
                        lastMessageId = parseInt(msg.id);
                    }
                });

                scrollToBottom();
                document.getElementById('messageInput').focus();

                // Refresh unread counts in sidebar
                fetchContacts();
            } catch(e) {
                console.error("Load group messages error:", e);
            }
        }

        /* Group Info Modal */
        let currentGroupInfoData = null;
        async function openGroupInfoModal() {
            if (!activeGroupId) return;
            const modal = document.getElementById('groupInfoModal');
            if (modal) modal.classList.remove('hidden');

            try {
                const res = await fetch(`api.php?action=get_group_info&group_id=${activeGroupId}`);
                const data = await res.json();
                if (!data.success) {
                    alert(data.error || 'Failed to load group info');
                    closeGroupInfoModal();
                    return;
                }

                currentGroupInfoData = data;
                const membersList = data.members || data.group?.members || [];
                const isAdmin = !!(data.is_admin || data.group?.is_admin || (data.group?.my_role === 'admin'));

                document.getElementById('groupInfoName').innerText = data.group.name;
                document.getElementById('groupInfoDesc').innerText = data.group.description || 'No description provided';
                document.getElementById('groupInfoCreator').innerText = `Created by ${data.group.creator_name || 'Admin'}`;
                document.getElementById('groupInfoDate').innerText = formatTimestamp(data.group.created_at);
                document.getElementById('groupInfoMemberCountBadge').innerText = `${membersList.length} members`;

                const addBtn = document.getElementById('btnAddMembersToGroupBtn');
                if (isAdmin) {
                    addBtn.classList.remove('hidden');
                } else {
                    addBtn.classList.add('hidden');
                }

                // Render members roster
                const roster = document.getElementById('groupInfoMembersList');
                let html = '';
                membersList.forEach(m => {
                    const mUid = parseInt(m.user_id || m.id);
                    const isMe = (mUid === myUserId);
                    const isOnline = parseInt(m.is_online) === 1;
                    html += `
                        <div class="p-2.5 flex items-center justify-between hover:bg-slate-50 transition rounded-xl">
                            <div class="flex items-center gap-3">
                                <div class="relative">
                                    <div class="w-8 h-8 rounded-xl bg-slate-100 text-slate-700 flex items-center justify-center font-bold text-xs">
                                        ${escapeHtml((m.name || m.username || 'U').substring(0, 1).toUpperCase())}
                                    </div>
                                    <span class="absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 rounded-full ${isOnline ? 'bg-emerald-500' : 'bg-slate-300'} border border-white"></span>
                                </div>
                                <div>
                                    <h4 class="text-xs font-bold text-slate-800 leading-tight">
                                        ${escapeHtml(m.name || m.username)} ${isMe ? '<span class="text-[10px] text-indigo-600 font-extrabold">(You)</span>' : ''}
                                    </h4>
                                    <span class="text-[10px] text-slate-400 font-semibold">${escapeHtml(m.role_label || m.role)}</span>
                                </div>
                            </div>
                            <div>
                                ${m.group_role === 'admin' 
                                    ? '<span class="text-[9px] font-extrabold text-amber-700 bg-amber-50 border border-amber-200 px-2 py-0.5 rounded-md uppercase">Admin</span>' 
                                    : '<span class="text-[9px] font-bold text-slate-500 bg-slate-100 px-1.5 py-0.5 rounded-md">Member</span>'}
                            </div>
                        </div>
                    `;
                });
                roster.innerHTML = html;
            } catch(e) {
                console.error("Group info error:", e);
            }
        }

        function closeGroupInfoModal() {
            const modal = document.getElementById('groupInfoModal');
            if (modal) modal.classList.add('hidden');
        }

        /* Add Members to Group Modal */
        let addMembersSelected = new Set();
        function openAddMembersModal() {
            if (!currentGroupInfoData) return;
            addMembersSelected.clear();
            const searchEl = document.getElementById('addMemberSearchInput');
            if (searchEl) searchEl.value = '';
            renderAddMembersChecklist();
            const modal = document.getElementById('addMembersModal');
            if (modal) modal.classList.remove('hidden');
        }

        function closeAddMembersModal() {
            const modal = document.getElementById('addMembersModal');
            if (modal) modal.classList.add('hidden');
        }

        function renderAddMembersChecklist() {
            const container = document.getElementById('addMembersChecklist');
            if (!container) return;
            const search = (document.getElementById('addMemberSearchInput')?.value || '').toLowerCase().trim();
            const membersList = currentGroupInfoData?.members || currentGroupInfoData?.group?.members || [];
            const existingMemberIds = new Set(membersList.map(m => parseInt(m.user_id || m.id)));

            const candidates = contactsData.filter(c => {
                if (existingMemberIds.has(parseInt(c.id))) return false;
                return c.name.toLowerCase().includes(search) || 
                       c.username.toLowerCase().includes(search) ||
                       c.role_label.toLowerCase().includes(search);
            });

            if (candidates.length === 0) {
                container.innerHTML = `<div class="p-6 text-center text-xs text-slate-400">All available team members are already in this group</div>`;
                return;
            }

            let html = '';
            candidates.forEach(c => {
                const isSelected = addMembersSelected.has(parseInt(c.id));
                html += `
                    <div onclick="toggleAddMemberSelect(${c.id})" class="flex items-center justify-between p-2.5 hover:bg-white rounded-xl cursor-pointer transition select-none">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-xl bg-slate-100 text-slate-700 flex items-center justify-center font-bold text-xs">
                                ${escapeHtml(c.name.substring(0, 1).toUpperCase())}
                            </div>
                            <div>
                                <h4 class="text-xs font-bold text-slate-900 leading-tight">${escapeHtml(c.name)}</h4>
                                <span class="text-[10px] text-slate-400 font-semibold">${escapeHtml(c.role_label)}</span>
                            </div>
                        </div>
                        <input type="checkbox" id="chk-add-member-${c.id}" value="${c.id}" 
                               ${isSelected ? 'checked' : ''} 
                               onclick="event.stopPropagation(); toggleAddMemberSelect(${c.id})"
                               class="w-4 h-4 rounded text-indigo-600 focus:ring-indigo-500 border-slate-300 pointer-events-auto">
                    </div>
                `;
            });
            container.innerHTML = html;
        }

        function toggleAddMemberSelect(id) {
            const uid = parseInt(id);
            if (addMembersSelected.has(uid)) {
                addMembersSelected.delete(uid);
            } else {
                addMembersSelected.add(uid);
            }
            const cb = document.getElementById(`chk-add-member-${uid}`);
            if (cb) cb.checked = addMembersSelected.has(uid);
        }

        function filterAddMembersChecklist() {
            renderAddMembersChecklist();
        }

        async function submitAddMembersToGroup() {
            if (!activeGroupId || addMembersSelected.size === 0) {
                alert('Please select at least one member to add');
                return;
            }

            const btn = document.getElementById('btnConfirmAddMembers');
            if (btn) {
                btn.disabled = true;
                btn.innerText = 'Adding...';
            }

            const fd = new FormData();
            fd.append('action', 'add_group_members');
            fd.append('group_id', activeGroupId);
            Array.from(addMembersSelected).forEach(id => {
                fd.append('members[]', id);
                fd.append('member_ids[]', id);
            });

            try {
                const res = await fetch('api.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    closeAddMembersModal();
                    openGroupInfoModal();
                    selectGroup(activeGroupId);
                    fetchContacts();
                } else {
                    alert(data.error || 'Failed to add members');
                }
            } catch(e) {
                console.error("Add members error:", e);
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerText = 'Add Selected';
                }
            }
        }

        async function leaveCurrentGroup() {
            if (!activeGroupId) return;
            if (!confirm("Are you sure you want to leave this group?")) return;

            const fd = new FormData();
            fd.append('action', 'leave_group');
            fd.append('group_id', activeGroupId);

            try {
                const res = await fetch('api.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    closeGroupInfoModal();
                    closeActiveChat();
                    await fetchContacts();
                } else {
                    alert(data.error || 'Failed to leave group');
                }
            } catch(e) {
                console.error("Leave group error:", e);
            }
        }

        /* Select a contact and load message thread */
        async function selectContact(contactId) {
            exitMessageSelectMode();
            activeContactId = contactId;
            activeGroupId = null;

            pauseCurrentAudio();
            cancelVoiceRecording();
            cancelReply();
            hideTypingIndicator();
            const vBar = document.getElementById('voiceRecordingBar');
            if (vBar) vBar.classList.add('hidden');

            renderContactsList();

            // If contact info drawer is open for a different contact, update it to the new contact
            const panel = document.getElementById('contactInfoRightPanel');
            if (panel && !panel.classList.contains('hidden') && activeProfileUserId && activeProfileUserId !== myUserId) {
                openUserProfileModal(contactId);
            }

            // Toggle screens
            document.body.classList.add('mobile-chat-open');
            document.getElementById('emptyChatState').classList.add('hidden');
            document.getElementById('activeChatHeader').classList.remove('hidden');
            document.getElementById('messagesContainer').classList.remove('hidden');
            document.getElementById('chatInputBar').classList.remove('hidden');

            // Load and apply this contact's custom wallpaper background
            loadChatWallpaper('direct', contactId);

            // Restore 1-on-1 direct UI buttons
            const vBtn = document.getElementById('voiceCallBtn');
            const vidBtn = document.getElementById('videoCallBtn');
            if (vBtn) {
                vBtn.classList.remove('hidden');
                vBtn.setAttribute('title', 'Start Voice Call');
            }
            if (vidBtn) {
                vidBtn.classList.remove('hidden');
                vidBtn.setAttribute('title', 'Start Video Call');
            }
            document.getElementById('blockContactBtn').classList.remove('hidden');
            const nickBtn = document.getElementById('headerNicknameBtn');
            if (nickBtn) nickBtn.classList.remove('hidden');
            document.getElementById('groupInfoBtn').classList.add('hidden');
            activeGroupCallData = null;
            const grpBanner = document.getElementById('activeGroupCallBanner');
            if (grpBanner) grpBanner.classList.add('hidden');
            const dot = document.getElementById('activeAvatarDot');
            if (dot) dot.classList.remove('hidden');
            const avatarEl = document.getElementById('activeAvatar');
            avatarEl.className = "w-10 h-10 sm:w-11 sm:h-11 rounded-full bg-indigo-600 text-white font-black text-sm flex items-center justify-center shadow-md shadow-indigo-200 border-2 border-white ring-1 ring-slate-200";

            const container = document.getElementById('messagesContainer');
            container.innerHTML = `
                <div class="py-24 text-center text-slate-400 text-xs">
                    <span class="material-icons-round animate-spin text-2xl text-indigo-600 mb-2">sync</span>
                    <p class="font-medium text-slate-600">Loading conversation history...</p>
                </div>`;

            try {
                const res = await fetch(`api.php?action=get_messages&contact_id=${contactId}`);
                const data = await res.json();

                if (!data.success) {
                    alert(data.error || "Unable to open conversation");
                    closeActiveChat();
                    return;
                }

                // Set Header details
                document.getElementById('activeName').innerText = data.contact.name;
                const badge = document.getElementById('activeRoleBadge');
                if (badge) {
                    badge.innerText = data.contact.role_label;
                    badge.className = `text-[10px] font-extrabold px-2.5 py-0.5 rounded-full border uppercase tracking-wider ${getRoleClass(data.contact.role)}`;
                }
                
                const activeAv = document.getElementById('activeAvatar');
                if (data.contact.profile_image) {
                    activeAv.innerHTML = `<img src="${escapeHtml(data.contact.profile_image)}" alt="${escapeHtml(data.contact.name)}" class="w-full h-full object-cover rounded-full" onerror="this.parentElement.innerText='${escapeHtml(data.contact.name.substring(0, 1).toUpperCase())}'" />`;
                } else {
                    activeAv.innerText = data.contact.name.substring(0, 1).toUpperCase();
                }

                // Render dynamic online / last seen status
                renderActivePresence(data.contact.is_online, data.contact.last_seen_text);

                // Update Blocked State in UI
                updateBlockedUI(data.contact.is_blocked_by_me, data.contact.is_blocked_by_them);

                // Render Messages with Date Separation and WhatsApp-style E2EE Security Pill
                container.innerHTML = `
                    <div class="flex justify-center my-3 select-none">
                        <div class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl bg-amber-50/90 border border-amber-200/80 text-amber-900 text-[11px] font-semibold shadow-xs max-w-md text-center leading-relaxed">
                            <span class="material-icons-round text-base text-amber-600 flex-shrink-0">lock</span>
                            <span>Messages and calls are end-to-end encrypted. Database records are stored in AES-256 cipher hash format. No one outside of this chat can read them.</span>
                        </div>
                    </div>
                `;
                messagesDataMap.clear();
                lastMessageId = 0;
                lastRenderedDate = null;

                data.messages.forEach(msg => {
                    appendMessageBubble(msg);
                    if (parseInt(msg.id) > lastMessageId) {
                        lastMessageId = parseInt(msg.id);
                    }
                });

                scrollToBottom();
                document.getElementById('messageInput').focus();

                // Refresh unread counts in sidebar
                fetchContacts();
            } catch(e) {
                console.error("Load messages error:", e);
            }
        }

        /* Helper to get tick HTML */
        function getTickHtml(msg, isMine) {
            if (!isMine) return '';
            if (parseInt(msg.is_deleted_for_everyone) === 1) return '';

            if (parseInt(msg.is_read) === 1) {
                // Double Blue Ticks
                return `<span id="tick-${msg.id}" class="material-icons-round text-[15px] text-[#38bdf8] font-black ml-1" title="Read">done_all</span>`;
            } else if (parseInt(msg.is_delivered) === 1) {
                // Double Gray Ticks
                return `<span id="tick-${msg.id}" class="material-icons-round text-[15px] text-indigo-200 ml-1" title="Delivered">done_all</span>`;
            } else {
                // Single Gray Tick
                return `<span id="tick-${msg.id}" class="material-icons-round text-[15px] text-indigo-200 ml-1" title="Sent">done</span>`;
            }
        }

        /* Append Message Bubble */
        function appendMessageBubble(msg) {
            const container = document.getElementById('messagesContainer');
            if (!container) return;

            // Handle system messages (e.g. group created, member joined, etc.)
            if (parseInt(msg.is_system) === 1 || msg.message_type === 'system') {
                const sysDiv = document.createElement('div');
                sysDiv.className = "flex justify-center my-2 select-none";
                sysDiv.innerHTML = `
                    <span class="px-3.5 py-1 rounded-xl bg-slate-200/90 text-slate-600 text-[11px] font-semibold shadow-2xs">
                        ${escapeHtml(msg.message)}
                    </span>`;
                container.appendChild(sysDiv);
                return;
            }

            const isMine = (parseInt(msg.sender_id) === myUserId);
            const isDeleted = (parseInt(msg.is_deleted_for_everyone) === 1);
            const isEdited = (parseInt(msg.is_edited) === 1);
            const isForwarded = (parseInt(msg.is_forwarded) === 1);

            messagesDataMap.set(parseInt(msg.id), msg);

            // Check Date Divider
            if (msg.created_at) {
                const msgDateStr = new Date(msg.created_at).toDateString();
                if (msgDateStr !== lastRenderedDate) {
                    container.appendChild(renderDateDivider(msg.created_at));
                    lastRenderedDate = msgDateStr;
                }
            }

            const isSelected = selectedMessageIds.has(parseInt(msg.id));
            const bubbleDiv = document.createElement('div');
            bubbleDiv.id = `msg-row-${msg.id}`;
            bubbleDiv.setAttribute('data-msg-id', msg.id);
            bubbleDiv.className = `message-row flex w-full group relative ${isMine ? 'justify-end' : 'justify-start'} items-center gap-2.5 transition-all duration-150 py-0.5 ${isSelected ? 'msg-row-selected' : ''}`;

            // Clicking message row in select mode toggles selection
            bubbleDiv.addEventListener('click', (e) => {
                if (isMessageSelectMode) {
                    e.preventDefault();
                    e.stopPropagation();
                    toggleSelectMessage(msg.id);
                }
            });

            // Attach Right-Click Context Menu Listener on message row
            bubbleDiv.addEventListener('contextmenu', (e) => {
                e.preventDefault();
                if (isMessageSelectMode) {
                    toggleSelectMessage(msg.id);
                } else {
                    openContextMenu(e, msg.id);
                }
            });

            const timeStr = formatMessageTime(msg.created_at);
            const tickHtml = getTickHtml(msg, isMine);

            const isViewOnce = (parseInt(msg.is_view_once) === 1);
            const isViewOnceOpened = (parseInt(msg.view_once_opened) === 1);

            let attachmentHtml = '';
            if (isViewOnce) {
                // WhatsApp Style View Once Bubble
                const viewOnceLabel = msg.file_type === 'image' ? 'Photo' : (msg.file_type === 'audio' ? 'Voice note' : (msg.file_path ? 'File' : 'Message'));
                if (isViewOnceOpened) {
                    attachmentHtml = `
                        <div class="inline-flex items-center gap-2 py-2 px-3.5 rounded-2xl ${isMine ? 'bg-white/15 text-white/90' : 'bg-slate-100 text-slate-600'} text-xs font-semibold select-none border ${isMine ? 'border-white/20' : 'border-slate-200'}">
                            <span class="w-5 h-5 rounded-full border ${isMine ? 'border-white/50 text-white/80' : 'border-slate-400 text-slate-500'} flex items-center justify-center text-[10px] font-black">1</span>
                            <span class="font-bold">Opened</span>
                        </div>`;
                } else if (isMine) {
                    // Sender's view: Cannot open their own View Once message (WhatsApp style)
                    attachmentHtml = `
                        <div title="View once sent (Receiver will see it once)"
                            class="inline-flex items-center gap-2 py-2 px-3.5 rounded-2xl bg-white/20 text-white text-xs font-bold select-none shadow-xs border border-white/25 cursor-default">
                            <span class="w-5 h-5 rounded-full border-2 border-white text-white flex items-center justify-center text-[10px] font-black">1</span>
                            <span>${viewOnceLabel}</span>
                            <span class="material-icons-round text-xs opacity-75" title="Sent as View Once">lock</span>
                        </div>`;
                } else {
                    // Receiver's view: Can click once to view
                    attachmentHtml = `
                        <button type="button" onclick="openViewOnceMessage(${msg.id})" title="Click to view once"
                            class="inline-flex items-center gap-2 py-2 px-3.5 rounded-2xl bg-indigo-50 hover:bg-indigo-100 text-indigo-700 text-xs font-bold transition select-none shadow-xs border border-indigo-200 group/vo active:scale-95">
                            <span class="w-5 h-5 rounded-full border-2 border-indigo-600 text-indigo-600 flex items-center justify-center text-[10px] font-black">1</span>
                            <span>${viewOnceLabel}</span>
                            <span class="material-icons-round text-sm opacity-60 group-hover/vo:opacity-100">visibility</span>
                        </button>`;
                }
            } else if (msg.file_path && !isDeleted) {
                const rawPathEsc = escapeHtml(msg.file_path).replace(/'/g, "\\'");
                const rawNameEsc = escapeHtml(msg.file_name || 'attachment').replace(/'/g, "\\'");

                if (msg.file_type === 'image') {
                    attachmentHtml = `
                        <div class="mb-2 rounded-xl overflow-hidden border ${isMine ? 'border-white/20' : 'border-slate-200'} relative group/img">
                            <a href="../../${escapeHtml(msg.file_path)}" target="_blank" class="block">
                                <img src="../../${escapeHtml(msg.file_path)}" class="max-w-xs max-h-60 object-cover hover:opacity-95 transition">
                            </a>
                            <!-- Download Overlay Button -->
                            <button type="button" 
                                onclick="openMediaDownloadPrompt('${rawPathEsc}', '${rawNameEsc}', 'image', ${parseInt(msg.file_size) || 0})"
                                title="Download Image (HD / Normal)"
                                class="absolute top-2 right-2 w-8 h-8 rounded-full bg-slate-900/70 hover:bg-slate-900/90 text-white flex items-center justify-center backdrop-blur-xs transition shadow-md active:scale-95 group-hover/img:opacity-100 opacity-90">
                                <span class="material-icons-round text-base">download</span>
                            </button>
                        </div>`;
                } else if (msg.file_type === 'video') {
                    attachmentHtml = `
                        <div class="mb-2 rounded-xl overflow-hidden border ${isMine ? 'border-white/20' : 'border-slate-200'} relative group/vid bg-black/40">
                            <video src="../../${escapeHtml(msg.file_path)}" controls class="max-w-xs max-h-60 rounded-xl bg-black" preload="metadata"></video>
                            <div class="p-2 flex items-center justify-between text-xs ${isMine ? 'bg-white/10 text-white' : 'bg-slate-50 text-slate-800'} border-t ${isMine ? 'border-white/10' : 'border-slate-200'}">
                                <div class="min-w-0 flex-1 pr-2">
                                    <p class="font-bold truncate text-[11px]">${escapeHtml(msg.file_name || 'Video')}</p>
                                    <p class="text-[10px] ${isMine ? 'text-indigo-200' : 'text-slate-400'}">${formatFileSize(msg.file_size)}</p>
                                </div>
                                <button type="button" 
                                    onclick="openMediaDownloadPrompt('${rawPathEsc}', '${rawNameEsc}', 'video', ${parseInt(msg.file_size) || 0})"
                                    title="Download Video (HD / Normal)"
                                    class="p-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white flex items-center justify-center transition shadow-xs active:scale-95">
                                    <span class="material-icons-round text-sm">download</span>
                                </button>
                            </div>
                        </div>`;
                } else if (msg.file_type === 'audio') {
                    attachmentHtml = renderVoiceNotePlayer(msg, isMine);
                } else {
                    const icon = getFileIcon(msg.file_type);
                    attachmentHtml = `
                        <a href="../../${escapeHtml(msg.file_path)}" download="${escapeHtml(msg.file_name)}" 
                            class="mb-2 p-3 rounded-xl ${isMine ? 'bg-white/10 hover:bg-white/20 text-white' : 'bg-slate-50 hover:bg-slate-100 text-slate-800'} flex items-center gap-3 transition text-xs border ${isMine ? 'border-white/10' : 'border-slate-200'}">
                            <span class="material-icons-round text-2xl ${isMine ? 'text-indigo-200' : 'text-indigo-600'}">${icon}</span>
                            <div class="min-w-0 flex-1">
                                <p class="font-bold truncate max-w-[200px]">${escapeHtml(msg.file_name)}</p>
                                <p class="text-[10px] ${isMine ? 'text-indigo-200' : 'text-slate-400'}">${formatFileSize(msg.file_size)}</p>
                            </div>
                            <span class="material-icons-round text-base opacity-70">download</span>
                        </a>`;
                }
            }

            // WhatsApp Style colored sender name header inside group chats
            let groupSenderHeaderHtml = '';
            if (activeGroupId && !isMine && msg.sender_name) {
                const senderPalette = [
                    'text-indigo-600',
                    'text-emerald-600',
                    'text-violet-600',
                    'text-amber-600',
                    'text-pink-600',
                    'text-cyan-600',
                    'text-teal-600'
                ];
                const sColor = senderPalette[(parseInt(msg.sender_id) || 0) % senderPalette.length];
                const sRole = msg.sender_role ? `<span class="text-[9px] font-bold text-slate-400 bg-slate-100 px-1.5 py-0.2 rounded border border-slate-200">${escapeHtml(msg.sender_role)}</span>` : '';
                groupSenderHeaderHtml = `
                    <div class="flex items-center gap-1.5 mb-1 pb-0.5 border-b border-slate-100">
                        <span class="text-xs font-black ${sColor}">${escapeHtml(msg.sender_name)}</span>
                        ${sRole}
                    </div>`;
            }

            // Forwarded Indicator (WhatsApp Style)
            let forwardedHtml = '';
            if (isForwarded && !isDeleted) {
                forwardedHtml = `
                    <div class="flex items-center gap-1 text-[10.5px] ${isMine ? 'text-indigo-200' : 'text-slate-400'} italic font-bold mb-1 select-none">
                        <span class="material-icons-round text-xs">shortcut</span>
                        <span>Forwarded</span>
                    </div>`;
            }

            // Reply preview quote inside bubble
            let replyQuoteHtml = '';
            if (msg.reply_to && !isDeleted) {
                const rSender = msg.reply_to.sender_name || 'User';
                const rText = msg.reply_to.message ? escapeHtml(msg.reply_to.message) : (msg.reply_to.file_name ? `📎 ${escapeHtml(msg.reply_to.file_name)}` : 'Attachment');
                replyQuoteHtml = `
                    <div class="reply-bubble-preview text-xs" onclick="scrollToMessage(${msg.reply_to.id})">
                        <div class="font-bold text-[11px] truncate flex items-center gap-1 ${isMine ? 'text-indigo-100' : 'text-indigo-600'}">
                            <span class="material-icons-round text-[13px]">reply</span>
                            <span>${escapeHtml(rSender)}</span>
                        </div>
                        <div class="truncate text-[11px] ${isMine ? 'text-white/80' : 'text-slate-500'} font-medium mt-0.5">${rText}</div>
                    </div>`;
            }

            // Message content
            let contentHtml = '';
            if (isDeleted) {
                contentHtml = `<p class="chat-text text-xs italic ${isMine ? 'text-indigo-200' : 'text-slate-400'} flex items-center gap-1.5 py-0.5"><span class="material-icons-round text-sm">block</span> This message was deleted</p>`;
            } else if (isViewOnce) {
                // View once messages don't render plaintext on regular bubble unless sender or already open
                contentHtml = '';
            } else if (msg.message && msg.file_type !== 'audio') {
                contentHtml = `<p id="msg-text-${msg.id}" class="chat-text leading-relaxed whitespace-pre-wrap font-medium">${formatMessageWithLinks(msg.message, isMine)}</p>`;
            }

            // Reactions badges floating under bubble
            let reactionsHtml = '';
            if (msg.reactions && msg.reactions.length > 0 && !isDeleted) {
                const itemsHtml = msg.reactions.map(r => `
                    <span class="reaction-badge-item ${r.reacted_by_me ? 'active-mine' : ''}" title="${r.users ? r.users.map(u => escapeHtml(u.name)).join(', ') : ''}">
                        <span>${r.emoji}</span>
                        ${r.count > 1 ? `<span class="text-[10px] font-bold text-slate-500">${r.count}</span>` : ''}
                    </span>
                `).join('');
                reactionsHtml = `
                    <div class="reaction-badge-group ${isMine ? 'right-3' : 'left-3'}" onclick="openReactionsDetailModal(${msg.id})">
                        ${itemsHtml}
                    </div>`;
            }

            // Quick reaction bar on hover
            let quickReactionsHtml = '';
            if (!isDeleted) {
                quickReactionsHtml = `
                    <div class="quick-reaction-bar ${isMine ? 'right-0' : 'left-0'}">
                        <button type="button" class="quick-react-btn" onclick="sendReaction(${msg.id}, '👍')" title="Thumbs up">👍</button>
                        <button type="button" class="quick-react-btn" onclick="sendReaction(${msg.id}, '❤️')" title="Love">❤️</button>
                        <button type="button" class="quick-react-btn" onclick="sendReaction(${msg.id}, '😂')" title="Laugh">😂</button>
                        <button type="button" class="quick-react-btn" onclick="sendReaction(${msg.id}, '😮')" title="Wow">😮</button>
                        <button type="button" class="quick-react-btn" onclick="sendReaction(${msg.id}, '😢')" title="Sad">😢</button>
                        <button type="button" class="quick-react-btn" onclick="sendReaction(${msg.id}, '🙏')" title="Pray">🙏</button>
                        <button type="button" onclick="startReplyMessage(${msg.id})" title="Reply" class="p-1 hover:text-indigo-600 text-slate-400 rounded-full transition flex items-center justify-center">
                            <span class="material-icons-round text-sm">reply</span>
                        </button>
                    </div>`;
            }

            // Action menu buttons on hover (Reply, Info, Forward, More options)
            let actionButtonsHtml = '';
            if (!isDeleted) {
                actionButtonsHtml = `
                    <div class="msg-action-btn flex items-center gap-0.5 absolute top-1 ${isMine ? '-left-24' : '-right-24'} z-10 bg-white/95 backdrop-blur-xs p-1 rounded-xl shadow-xs border border-slate-200">
                        <button type="button" onclick="startReplyMessage(${msg.id})" title="Reply" class="p-1 hover:text-indigo-600 text-slate-400 rounded-lg transition">
                            <span class="material-icons-round text-sm">reply</span>
                        </button>
                        <button type="button" onclick="openMessageInfoModal(${msg.id})" title="Message info" class="p-1 hover:text-indigo-600 text-slate-400 rounded-lg transition">
                            <span class="material-icons-round text-sm">info</span>
                        </button>
                        <button type="button" onclick="openForwardModal(${msg.id})" title="Forward" class="p-1 hover:text-sky-600 text-slate-400 rounded-lg transition">
                            <span class="material-icons-round text-sm">shortcut</span>
                        </button>
                        <button type="button" onclick="openContextMenuFromBtn(event, ${msg.id})" title="More Options" class="p-1 hover:text-slate-800 text-slate-400 rounded-lg transition">
                            <span class="material-icons-round text-sm">more_vert</span>
                        </button>
                    </div>`;
            }

            const selectCheckHtml = `
                <div class="msg-select-box ${isMessageSelectMode ? '' : 'hidden'} flex-shrink-0 flex items-center justify-center select-none cursor-pointer p-1">
                    <div id="select-check-${msg.id}" class="w-5 h-5 rounded-md border-2 flex items-center justify-center transition-all duration-150 ${isSelected ? 'bg-indigo-600 border-indigo-600 text-white shadow-xs' : 'border-slate-300 bg-white hover:border-indigo-400 text-transparent'}">
                        <span class="material-icons-round text-sm font-bold ${isSelected ? '' : 'hidden'}">check</span>
                    </div>
                </div>`;

            bubbleDiv.innerHTML = `
                ${selectCheckHtml}
                <div id="msg-bubble-${msg.id}" class="relative max-w-[85%] sm:max-w-[70%] transition-all duration-150 ${isSelected ? 'ring-2 ring-indigo-500 ring-offset-2 ring-offset-slate-100 rounded-2xl' : ''}">
                    ${quickReactionsHtml}
                    ${actionButtonsHtml}
                    <div class="px-4 py-2.5 text-sm ${isMine ? 'bubble-sent' : 'bubble-received'}">
                        ${groupSenderHeaderHtml}
                        ${forwardedHtml}
                        ${replyQuoteHtml}
                        ${attachmentHtml}
                        ${contentHtml}
                        <div class="flex items-center justify-end text-[10px] ${isMine ? 'text-indigo-200' : 'text-slate-400'} mt-1 space-x-1.5 font-semibold">
                            <span id="msg-edited-${msg.id}" class="${isEdited ? '' : 'hidden'} text-[9px] opacity-80 italic mr-0.5">edited</span>
                            <span>${timeStr}</span>
                            ${tickHtml}
                        </div>
                    </div>
                    ${reactionsHtml}
                </div>`;

            container.appendChild(bubbleDiv);
        }

        /* Send Message */
        async function sendMessage() {
            if (!activeContactId && !activeGroupId) return;

            const input = document.getElementById('messageInput');
            const message = input.value.trim();

            if (!message && !selectedFile) return;

            if (activeContactId) {
                if (currentContactIsBlockedByMe) {
                    alert('You have blocked this contact. Unblock them to send messages.');
                    return;
                }
                if (currentContactIsBlockedByThem) {
                    alert('You cannot send messages to this contact.');
                    return;
                }

                // Stop typing status immediately
                if (isTypingActive) {
                    isTypingActive = false;
                    clearTimeout(typingDebounceTimer);
                    const fdType = new FormData();
                    fdType.append('action', 'stop_typing');
                    fdType.append('receiver_id', activeContactId);
                    fetch('api.php', { method: 'POST', body: fdType }).catch(()=>{});
                }
            }

            const sendBtn = document.getElementById('sendBtn');
            sendBtn.disabled = true;

            const formData = new FormData();
            if (activeGroupId) {
                formData.append('action', 'send_group_message');
                formData.append('group_id', activeGroupId);
            } else {
                formData.append('action', 'send_message');
                formData.append('receiver_id', activeContactId);
            }
            formData.append('message', message);
            if (isChatViewOnceActive) {
                formData.append('is_view_once', 1);
            }

            if (selectedFile) {
                formData.append('attachment', selectedFile);
            }
            if (activeReplyToMsg) {
                formData.append('reply_to_id', activeReplyToMsg.id);
            }

            try {
                const res = await fetch('api.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();

                if (data.success && data.message) {
                    appendMessageBubble(data.message);
                    if (parseInt(data.message.id) > lastMessageId) {
                        lastMessageId = parseInt(data.message.id);
                    }
                    input.value = '';
                    if (isChatViewOnceActive) {
                        toggleChatViewOnce(); // Reset view-once button after single send
                    }
                    autoResizeMessageInput();
                    cancelAttachment();
                    cancelReply();
                    scrollToBottom();
                    fetchContacts(); // Update sidebar last message
                } else {
                    alert(data.error || "Failed to send message");
                }
            } catch(err) {
                console.error("Send message error:", err);
            } finally {
                sendBtn.disabled = false;
                input.focus();
            }
        }

        /* Real-Time Live Polling (Every 2.5s) */
        let pollCount = 0;
        async function startLivePolling() {
            if (isPolling) return;
            isPolling = true;

            setInterval(async () => {
                pollCount++;
                try {
                    const url = `api.php?action=poll_updates&contact_id=${activeContactId || 0}&group_id=${activeGroupId || 0}&last_id=${lastMessageId}`;
                    const res = await fetch(url);
                    const data = await res.json();

                    if (data.success) {
                        // 1. Dynamic Online/Offline & Typing status for active conversation
                        if (activeContactId) {
                            if (data.is_typing) {
                                showTypingIndicator();
                            } else {
                                hideTypingIndicator();
                                if (data.active_contact_presence) {
                                    renderActivePresence(data.active_contact_presence.is_online, data.active_contact_presence.text);
                                }
                            }

                            // Synchronize with contacts list in real time
                            if (data.active_contact_presence) {
                                const c = contactsData.find(x => parseInt(x.id) === parseInt(activeContactId));
                                if (c) {
                                    const wasOnline = c.is_online;
                                    c.is_online = data.active_contact_presence.is_online;
                                    c.last_seen_text = data.active_contact_presence.text;
                                    if (wasOnline !== c.is_online) {
                                        renderContactsList();
                                    }
                                }
                            }

                            // Synchronize Block State in real time
                            if (data.is_blocked_by_me !== undefined) {
                                if (data.is_blocked_by_me !== currentContactIsBlockedByMe || data.is_blocked_by_them !== currentContactIsBlockedByThem) {
                                    updateBlockedUI(data.is_blocked_by_me, data.is_blocked_by_them);
                                }
                            }
                        }

                        // 2. Real-Time Update of Message Ticks & Edits/Deletes for active chat
                        if (data.recent_statuses && data.recent_statuses.length > 0) {
                            data.recent_statuses.forEach(s => {
                                const tickEl = document.getElementById(`tick-${s.id}`);
                                if (tickEl) {
                                    if (parseInt(s.is_read) === 1) {
                                        tickEl.className = "material-icons-round text-[15px] text-[#38bdf8] font-black ml-1";
                                        tickEl.title = "Read";
                                        tickEl.innerText = "done_all";
                                    } else if (parseInt(s.is_delivered) === 1) {
                                        tickEl.className = "material-icons-round text-[15px] text-indigo-200 ml-1";
                                        tickEl.title = "Delivered";
                                        tickEl.innerText = "done_all";
                                    } else {
                                        tickEl.className = "material-icons-round text-[15px] text-indigo-200 ml-1";
                                        tickEl.title = "Sent";
                                        tickEl.innerText = "done";
                                    }
                                }

                                // Check if edited
                                const editedBadge = document.getElementById(`msg-edited-${s.id}`);
                                if (editedBadge && parseInt(s.is_edited) === 1) {
                                    editedBadge.classList.remove('hidden');
                                    const textEl = document.getElementById(`msg-text-${s.id}`);
                                    if (textEl && s.message) {
                                        textEl.innerText = s.message;
                                    }
                                }

                                // Check if deleted for everyone
                                if (parseInt(s.is_deleted_for_everyone) === 1) {
                                    const row = document.getElementById(`msg-row-${s.id}`);
                                    if (row && !row.dataset.deleted) {
                                        row.dataset.deleted = "true";
                                        const textEl = document.getElementById(`msg-text-${s.id}`);
                                        if (textEl) {
                                            textEl.innerHTML = `<span class="italic text-slate-400 flex items-center gap-1.5"><span class="material-icons-round text-sm">block</span> This message was deleted</span>`;
                                        }
                                        if (tickEl) tickEl.remove();
                                    }
                                }

                                // Live Reactions synchronization
                                if (s.reactions !== undefined) {
                                    const msgObj = messagesDataMap.get(parseInt(s.id));
                                    if (msgObj) {
                                        msgObj.reactions = s.reactions;
                                    }
                                    renderReactionsOnBubble(s.id, s.reactions);
                                }
                            });
                        }

                        // 3. Any new incoming messages
                        if (data.new_messages && data.new_messages.length > 0) {
                            let shouldPlaySound = false;
                            data.new_messages.forEach(msg => {
                                appendMessageBubble(msg);
                                if (parseInt(msg.id) > lastMessageId) {
                                    lastMessageId = parseInt(msg.id);
                                }
                                if (parseInt(msg.sender_id) !== myUserId) {
                                    shouldPlaySound = true;
                                }
                            });
                            hideTypingIndicator();
                            scrollToBottom();
                            if (shouldPlaySound) playChimeSound();
                        }

                        // 4. Update unread by group in local state
                        if (data.unread_by_group) {
                            groupsData.forEach(g => {
                                const gid = parseInt(g.id);
                                if (data.unread_by_group[gid] !== undefined) {
                                    g.unread_count = data.unread_by_group[gid];
                                }
                            });
                        }

                        // 5. Update Total unread badge
                        const totalBadge = document.getElementById('unreadTotalBadge');
                        if (data.total_unread > 0) {
                            totalBadge.innerText = data.total_unread > 99 ? '99+' : data.total_unread;
                            totalBadge.classList.remove('hidden');
                        } else {
                            totalBadge.classList.add('hidden');
                        }

                        // 5b. Real-Time Active Group Call Banner Monitor
                        if (activeGroupId) {
                            const grpBanner = document.getElementById('activeGroupCallBanner');
                            if (data.active_group_call && ['calling', 'ringing', 'connected'].includes(data.active_group_call.status)) {
                                activeGroupCallData = data.active_group_call;
                                const title = document.getElementById('activeGroupCallTitle');
                                const subtitle = document.getElementById('activeGroupCallSubtitle');
                                const icon = document.getElementById('activeGroupCallIcon');
                                if (title) title.textContent = `Group ${data.active_group_call.call_type === 'video' ? 'Video' : 'Voice'} Call is Live`;
                                if (subtitle) subtitle.textContent = `Started by ${data.active_group_call.caller_name || 'Member'} • Click Join to participate`;
                                if (icon) icon.textContent = data.active_group_call.call_type === 'video' ? 'videocam' : 'call';
                                if (grpBanner) grpBanner.classList.remove('hidden');
                            } else {
                                activeGroupCallData = null;
                                if (grpBanner) grpBanner.classList.add('hidden');
                            }
                        }

                        // 6. Real-Time Incoming Call Detector
                        if (data.incoming_call && (!currentCall || currentCall.id !== parseInt(data.incoming_call.id))) {
                            handleIncomingCall(data.incoming_call);
                        } else if (!data.incoming_call && currentCall && !currentCall.isCaller && currentCall.status === 'ringing') {
                            const incModal = document.getElementById('incomingCallModal');
                            if (incModal && !incModal.classList.contains('hidden')) {
                                cleanupCall();
                            }
                        }

                        // Every 7.5 seconds (3 polls), refresh all contacts & groups
                        if (pollCount % 3 === 0) {
                            fetchContacts();
                            if (activeCategory === 'calls') {
                                fetchCallsHistory();
                            }
                        }
                    }
                } catch(e) {}
            }, 2500);
        }

        /* ========================================================
           RIGHT-CLICK CONTEXT MENU (WhatsApp Style)
           ======================================================== */
        function openContextMenu(e, msgId) {
            const msg = messagesDataMap.get(parseInt(msgId));
            if (!msg) return;

            selectedContextMenuMsgId = parseInt(msgId);
            const menu = document.getElementById('msgContextMenu');
            const isMine = (parseInt(msg.sender_id) === myUserId);
            const isDeleted = (parseInt(msg.is_deleted_for_everyone) === 1);

            if (isDeleted) return;

            // Show / Hide Copy button
            const copyBtn = document.getElementById('menuCopyBtn');
            if (copyBtn) {
                if (msg.message && msg.file_type !== 'audio') {
                    copyBtn.classList.remove('hidden');
                } else {
                    copyBtn.classList.add('hidden');
                }
            }

            menu.classList.remove('hidden');

            // Smart Screen positioning
            const menuWidth = 210;
            const menuHeight = 220;
            let left = e.clientX;
            let top = e.clientY;

            if (left + menuWidth > window.innerWidth) {
                left = window.innerWidth - menuWidth - 10;
            }
            if (top + menuHeight > window.innerHeight) {
                top = window.innerHeight - menuHeight - 10;
            }

            menu.style.left = `${left}px`;
            menu.style.top = `${top}px`;
        }

        function openContextMenuFromBtn(e, msgId) {
            e.stopPropagation();
            const btn = e.currentTarget;
            const rect = btn.getBoundingClientRect();
            openContextMenu({ clientX: rect.right, clientY: rect.bottom }, msgId);
        }

        function closeContextMenu() {
            const menu = document.getElementById('msgContextMenu');
            if (menu) menu.classList.add('hidden');
        }

        // Close context menu on click anywhere
        document.addEventListener('click', (e) => {
            const menu = document.getElementById('msgContextMenu');
            if (menu && !menu.classList.contains('hidden') && !menu.contains(e.target)) {
                closeContextMenu();
            }
        });

        function handleMenuReaction(emoji) {
            const msgId = selectedContextMenuMsgId;
            closeContextMenu();
            if (!msgId) return;
            sendReaction(msgId, emoji);
        }

        function handleMenuAction(action) {
            const msgId = selectedContextMenuMsgId;
            closeContextMenu();
            if (!msgId) return;
            const msg = messagesDataMap.get(msgId);

            if (action === 'reply') {
                startReplyMessage(msgId);
            } else if (action === 'info') {
                openMessageInfoModal(msgId);
            } else if (action === 'forward') {
                openForwardModal(msgId);
            } else if (action === 'copy') {
                if (msg && msg.message) {
                    navigator.clipboard.writeText(msg.message).then(() => {
                        showQuickToast('Message copied to clipboard');
                    }).catch(() => {
                        showQuickToast('Failed to copy');
                    });
                }
            } else if (action === 'select') {
                enterMessageSelectMode(msgId);
            }
        }

        function showQuickToast(text) {
            let toast = document.getElementById('digichatMiniToast');
            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'digichatMiniToast';
                toast.className = 'fixed bottom-20 left-1/2 -translate-x-1/2 z-50 bg-slate-900/90 backdrop-blur-md text-white px-4 py-2 rounded-2xl text-xs font-bold shadow-xl transition-all duration-200 pointer-events-none opacity-0';
                document.body.appendChild(toast);
            }
            toast.textContent = text;
            toast.classList.remove('opacity-0');
            clearTimeout(toast._timer);
            toast._timer = setTimeout(() => toast.classList.add('opacity-0'), 2500);
        }

        /* ========================================================
           REPLY TO MESSAGE FUNCTIONALITY
           ======================================================== */
        function startReplyMessage(msgId) {
            closeContextMenu();
            const msg = messagesDataMap.get(parseInt(msgId));
            if (!msg) return;

            activeReplyToMsg = msg;
            const box = document.getElementById('replyPreviewBox');
            const senderEl = document.getElementById('replyPreviewSender');
            const textEl = document.getElementById('replyPreviewText');

            const isMine = (parseInt(msg.sender_id) === myUserId);
            const senderName = isMine ? 'You' : (msg.sender_name || 'User');
            const previewText = msg.message || (msg.file_name ? `📎 ${msg.file_name}` : 'Attachment');

            if (senderEl) senderEl.textContent = `Replying to ${senderName}`;
            if (textEl) textEl.textContent = previewText;
            if (box) box.classList.remove('hidden');

            const input = document.getElementById('messageInput');
            if (input) input.focus();
        }

        function cancelReply() {
            activeReplyToMsg = null;
            const box = document.getElementById('replyPreviewBox');
            if (box) box.classList.add('hidden');
        }

        function scrollToMessage(msgId) {
            const row = document.getElementById(`msg-row-${msgId}`);
            if (row) {
                row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                row.classList.add('ring-2', 'ring-indigo-500', 'ring-offset-2');
                setTimeout(() => {
                    row.classList.remove('ring-2', 'ring-indigo-500', 'ring-offset-2');
                }, 1500);
            }
        }

        /* ========================================================
           EMOJI REACTIONS FUNCTIONALITY
           ======================================================== */
        async function sendReaction(msgId, emoji) {
            closeContextMenu();
            const msg = messagesDataMap.get(parseInt(msgId));
            if (!msg) return;

            const fd = new FormData();
            fd.append('action', 'react_message');
            fd.append('message_id', msgId);
            fd.append('reaction', emoji);

            try {
                const res = await fetch('api.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    msg.reactions = data.reactions;
                    renderReactionsOnBubble(msgId, data.reactions);
                } else {
                    showQuickToast(data.error || 'Failed to update reaction');
                }
            } catch(e) {
                console.error("Reaction error:", e);
            }
        }

        function renderReactionsOnBubble(msgId, reactions) {
            const bubbleDiv = document.getElementById(`msg-bubble-${msgId}`);
            if (!bubbleDiv) return;

            // Remove existing badge group if any
            const existing = bubbleDiv.querySelector('.reaction-badge-group');
            if (existing) existing.remove();

            if (!reactions || reactions.length === 0) return;

            const msg = messagesDataMap.get(parseInt(msgId));
            const isMine = msg ? (parseInt(msg.sender_id) === myUserId) : false;

            const badgeGroup = document.createElement('div');
            badgeGroup.className = `reaction-badge-group ${isMine ? 'right-3' : 'left-3'}`;
            badgeGroup.onclick = (e) => {
                e.stopPropagation();
                openReactionsDetailModal(msgId);
            };

            const itemsHtml = reactions.map(r => `
                <span class="reaction-badge-item ${r.reacted_by_me ? 'active-mine' : ''}" title="${r.users ? r.users.map(u => escapeHtml(u.name)).join(', ') : ''}">
                    <span>${r.emoji}</span>
                    ${r.count > 1 ? `<span class="text-[10px] font-bold text-slate-500">${r.count}</span>` : ''}
                </span>
            `).join('');

            badgeGroup.innerHTML = itemsHtml;
            bubbleDiv.appendChild(badgeGroup);
        }

        function openReactionsDetailModal(msgId) {
            const msg = messagesDataMap.get(parseInt(msgId));
            if (!msg || !msg.reactions || msg.reactions.length === 0) return;

            let modal = document.getElementById('reactionsDetailModal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'reactionsDetailModal';
                modal.className = 'fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-xs flex items-center justify-center p-4';
                modal.innerHTML = `
                    <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 w-full max-w-sm p-5 animate-in fade-in zoom-in-95 duration-150">
                        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-3">
                            <h3 class="font-bold text-slate-900 text-sm">Message Reactions</h3>
                            <button onclick="document.getElementById('reactionsDetailModal').classList.add('hidden')" class="p-1 text-slate-400 hover:text-slate-600 rounded-lg">
                                <span class="material-icons-round text-base">close</span>
                            </button>
                        </div>
                        <div id="reactionsDetailList" class="space-y-2 max-h-60 overflow-y-auto"></div>
                    </div>`;
                document.body.appendChild(modal);
                modal.addEventListener('click', (e) => {
                    if (e.target === modal) modal.classList.add('hidden');
                });
            }

            const list = document.getElementById('reactionsDetailList');
            list.innerHTML = '';

            msg.reactions.forEach(r => {
                if (r.users && r.users.length > 0) {
                    r.users.forEach(u => {
                        const item = document.createElement('div');
                        item.className = 'flex items-center justify-between p-2 rounded-xl hover:bg-slate-50 transition text-xs font-semibold';
                        item.innerHTML = `
                            <span class="text-slate-800">${escapeHtml(u.name)}${u.user_id === myUserId ? ' (You)' : ''}</span>
                            <span class="text-base">${r.emoji}</span>`;
                        list.appendChild(item);
                    });
                }
            });

            modal.classList.remove('hidden');
        }

        /* ========================================================
           MESSAGE INFO MODAL CONTROLLER (Delivered & Seen/Read)
           ======================================================== */
        async function openMessageInfoModal(msgId) {
            closeContextMenu();
            const modal = document.getElementById('messageInfoModal');
            modal.classList.remove('hidden');

            const msg = messagesDataMap.get(parseInt(msgId));
            if (msg) {
                document.getElementById('infoPreviewText').textContent = msg.message || (msg.file_name ? 'Attachment' : '');
                const previewFile = document.getElementById('infoPreviewFile');
                if (msg.file_name) {
                    document.getElementById('infoPreviewFileName').textContent = msg.file_name;
                    previewFile.classList.remove('hidden');
                } else {
                    previewFile.classList.add('hidden');
                }
                const fwdBadge = document.getElementById('infoPreviewForwarded');
                if (parseInt(msg.is_forwarded) === 1) {
                    fwdBadge.classList.remove('hidden');
                } else {
                    fwdBadge.classList.add('hidden');
                }
            }

            try {
                const res = await fetch(`api.php?action=message_info&message_id=${msgId}`);
                const data = await res.json();
                if (data.success && data.info) {
                    const info = data.info;
                    document.getElementById('infoSentTime').textContent = info.sent_at || '--';

                    const delTimeEl = document.getElementById('infoDeliveredTime');
                    const delBadge = document.getElementById('infoDeliveredBadge');
                    if (info.is_delivered && info.delivered_at) {
                        delTimeEl.textContent = info.delivered_at;
                        delBadge.textContent = 'Delivered';
                        delBadge.className = 'text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700';
                    } else {
                        delTimeEl.textContent = 'Not delivered yet';
                        delBadge.textContent = 'Pending';
                        delBadge.className = 'text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-100 text-slate-600';
                    }

                    const readTimeEl = document.getElementById('infoReadTime');
                    const readBadge = document.getElementById('infoReadBadge');
                    if (info.is_read && info.read_at) {
                        readTimeEl.textContent = info.read_at;
                        readBadge.textContent = 'Read / Seen';
                        readBadge.className = 'text-[10px] font-bold px-2 py-0.5 rounded-full bg-sky-50 text-sky-700';
                    } else {
                        readTimeEl.textContent = 'Not read yet';
                        readBadge.textContent = 'Unread';
                        readBadge.className = 'text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-100 text-slate-600';
                    }
                }
            } catch(e) {
                console.error("Message info error:", e);
            }
        }

        function closeMessageInfoModal() {
            document.getElementById('messageInfoModal').classList.add('hidden');
        }

        /* ========================================================
           FORWARD MESSAGE MODAL CONTROLLER
           ======================================================== */
        let forwardTargetMsgId = null;

        function openForwardModal(msgId) {
            closeContextMenu();
            forwardTargetMsgId = parseInt(msgId);
            forwardTargetMsgIds = [forwardTargetMsgId];
            const msg = messagesDataMap.get(forwardTargetMsgId);
            if (!msg) return;

            document.getElementById('forwardMsgId').value = forwardTargetMsgId;
            const previewText = msg.message || (msg.file_name ? `📎 ${msg.file_name}` : 'Attachment');
            document.getElementById('forwardPreviewText').textContent = previewText;
            document.getElementById('forwardSearchInput').value = '';

            renderForwardContacts();
            document.getElementById('forwardMessageModal').classList.remove('hidden');
        }

        function closeForwardModal() {
            document.getElementById('forwardMessageModal').classList.add('hidden');
            forwardTargetMsgId = null;
            forwardTargetMsgIds = [];
        }

        function renderForwardContacts() {
            const listContainer = document.getElementById('forwardContactsList');
            const searchVal = document.getElementById('forwardSearchInput').value.toLowerCase().trim();

            let cList = contactsData.filter(c => {
                return c.name.toLowerCase().includes(searchVal) || 
                       c.username.toLowerCase().includes(searchVal) || 
                       c.role_label.toLowerCase().includes(searchVal);
            });

            let gList = groupsData.filter(g => {
                return g.name.toLowerCase().includes(searchVal) || 
                       (g.description && g.description.toLowerCase().includes(searchVal));
            });

            if (cList.length === 0 && gList.length === 0) {
                listContainer.innerHTML = `<div class="p-6 text-center text-xs text-slate-400">No matching contacts or groups found</div>`;
                return;
            }

            let html = '';
            if (gList.length > 0) {
                html += `<div class="px-2 py-1 text-[11px] font-black uppercase tracking-wider text-slate-400 bg-slate-50 rounded-lg">Groups</div>`;
                gList.forEach(g => {
                    html += `
                        <div class="py-2 px-3 flex items-center justify-between hover:bg-slate-50 rounded-xl transition">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-xl bg-indigo-50 text-indigo-700 flex items-center justify-center font-bold text-xs border border-indigo-200">
                                    <span class="material-icons-round text-sm">groups</span>
                                </div>
                                <div>
                                    <h4 class="text-xs font-bold text-slate-800 leading-tight">${escapeHtml(g.name)}</h4>
                                    <span class="text-[10px] text-slate-400 font-semibold">${g.member_count} members</span>
                                </div>
                            </div>
                            <button type="button" onclick="submitForwardMessage(null, ${g.id})" class="px-3.5 py-1.5 bg-indigo-50 hover:bg-indigo-600 hover:text-white text-indigo-700 text-xs font-bold rounded-xl border border-indigo-200 transition flex items-center gap-1 active:scale-95">
                                <span class="material-icons-round text-sm">send</span>
                                <span>Forward</span>
                            </button>
                        </div>
                    `;
                });
            }

            if (cList.length > 0) {
                html += `<div class="px-2 py-1 text-[11px] font-black uppercase tracking-wider text-slate-400 bg-slate-50 rounded-lg mt-2">Direct Contacts</div>`;
                cList.forEach(c => {
                    const initial = c.name.substring(0, 1).toUpperCase();
                    html += `
                        <div class="py-2 px-3 flex items-center justify-between hover:bg-slate-50 rounded-xl transition">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-xl bg-slate-100 text-slate-700 flex items-center justify-center font-bold text-xs border border-slate-200">
                                    ${initial}
                                </div>
                                <div>
                                    <h4 class="text-xs font-bold text-slate-800 leading-tight">${escapeHtml(c.name)}</h4>
                                    <span class="text-[10px] text-slate-400 font-semibold uppercase tracking-wider">${escapeHtml(c.role_label)}</span>
                                </div>
                            </div>
                            <button type="button" onclick="submitForwardMessage(${c.id}, null)" class="px-3.5 py-1.5 bg-sky-50 hover:bg-sky-600 hover:text-white text-sky-700 text-xs font-bold rounded-xl border border-sky-200 transition flex items-center gap-1 active:scale-95">
                                <span class="material-icons-round text-sm">send</span>
                                <span>Forward</span>
                            </button>
                        </div>
                    `;
                });
            }

            listContainer.innerHTML = html;
        }

        document.getElementById('forwardSearchInput')?.addEventListener('input', renderForwardContacts);

        async function submitForwardMessage(receiverId, groupId) {
            const idsToForward = (forwardTargetMsgIds && forwardTargetMsgIds.length > 0) 
                ? forwardTargetMsgIds 
                : (forwardTargetMsgId ? [forwardTargetMsgId] : []);

            if (idsToForward.length === 0 || (!receiverId && !groupId)) return;

            const fd = new FormData();
            fd.append('action', 'forward_message');
            fd.append('message_ids', JSON.stringify(idsToForward));
            fd.append('message_id', idsToForward[0]);
            if (groupId) {
                fd.append('group_id', groupId);
            } else {
                fd.append('receiver_id', receiverId);
            }

            try {
                const res = await fetch('api.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    const count = idsToForward.length;
                    showQuickToast(count > 1 ? `${count} messages forwarded successfully` : `Forwarded successfully`);
                    closeForwardModal();
                    exitMessageSelectMode();
                    if (groupId && parseInt(groupId) === parseInt(activeGroupId)) {
                        selectGroup(activeGroupId);
                    } else if (receiverId && parseInt(receiverId) === parseInt(activeContactId)) {
                        selectContact(activeContactId);
                    }
                    fetchContacts();
                } else {
                    alert(data.error || "Failed to forward message");
                }
            } catch(e) {
                console.error("Forward error:", e);
            }
        }

        /* ========================================================
           MULTI-SELECT MESSAGE MODE (WhatsApp / Telegram Style)
           ======================================================== */
        function enterMessageSelectMode(initialMsgId = null) {
            closeContextMenu();
            isMessageSelectMode = true;
            selectedMessageIds.clear();

            if (initialMsgId) {
                selectedMessageIds.add(parseInt(initialMsgId));
            }

            // Swap Top Header: hide activeChatHeader, show chatSelectionBar
            const actHeader = document.getElementById('activeChatHeader');
            const selBar = document.getElementById('chatSelectionBar');
            if (actHeader) actHeader.classList.add('hidden');
            if (selBar) selBar.classList.remove('hidden');

            // Show all checkbox containers
            document.querySelectorAll('.msg-select-box').forEach(el => el.classList.remove('hidden'));

            const container = document.getElementById('messagesContainer');
            if (container) container.classList.add('msg-select-active');

            updateMessageSelectionUI();
        }

        function exitMessageSelectMode() {
            isMessageSelectMode = false;
            selectedMessageIds.clear();

            // Restore header if chat is active
            const actHeader = document.getElementById('activeChatHeader');
            const selBar = document.getElementById('chatSelectionBar');
            if (selBar) selBar.classList.add('hidden');
            if (actHeader && (activeContactId || activeGroupId)) {
                actHeader.classList.remove('hidden');
            }

            // Hide checkboxes
            document.querySelectorAll('.msg-select-box').forEach(el => el.classList.add('hidden'));

            const container = document.getElementById('messagesContainer');
            if (container) container.classList.remove('msg-select-active');

            // Reset highlights and checks
            document.querySelectorAll('.message-row').forEach(row => {
                row.classList.remove('msg-row-selected');
                const id = parseInt(row.getAttribute('data-msg-id'));
                if (id) {
                    const checkEl = document.getElementById(`select-check-${id}`);
                    const bubbleEl = document.getElementById(`msg-bubble-${id}`);
                    if (checkEl) {
                        checkEl.className = 'w-5 h-5 rounded-md border-2 flex items-center justify-center transition-all duration-150 border-slate-300 bg-white hover:border-indigo-400 text-transparent';
                        const icon = checkEl.querySelector('.material-icons-round');
                        if (icon) icon.classList.add('hidden');
                    }
                    if (bubbleEl) {
                        bubbleEl.classList.remove('ring-2', 'ring-indigo-500', 'ring-offset-2', 'ring-offset-slate-100', 'rounded-2xl');
                    }
                }
            });
        }

        function toggleSelectMessage(msgId) {
            msgId = parseInt(msgId);
            if (!msgId) return;

            if (selectedMessageIds.has(msgId)) {
                selectedMessageIds.delete(msgId);
            } else {
                selectedMessageIds.add(msgId);
            }

            updateMessageSelectionUI();
        }

        function toggleSelectAllMessages() {
            const allIds = [];
            messagesDataMap.forEach((msg, id) => {
                if (parseInt(msg.is_system) !== 1) {
                    allIds.push(id);
                }
            });

            if (selectedMessageIds.size > 0 && selectedMessageIds.size === allIds.length) {
                // Deselect all
                selectedMessageIds.clear();
            } else {
                // Select all
                selectedMessageIds.clear();
                allIds.forEach(id => selectedMessageIds.add(id));
            }

            updateMessageSelectionUI();
        }

        function updateMessageSelectionUI() {
            const count = selectedMessageIds.size;
            const countBadge = document.getElementById('selectedCountBadge');
            const countText = document.getElementById('selectedCountText');
            if (countBadge) countBadge.textContent = count;
            if (countText) countText.textContent = (count === 1) ? 'selected' : 'selected';

            const totalSelectable = messagesDataMap.size;
            const selectAllText = document.getElementById('selectAllText');
            const selectAllIcon = document.getElementById('selectAllIcon');
            if (selectAllText && selectAllIcon) {
                if (totalSelectable > 0 && count === totalSelectable) {
                    selectAllText.textContent = 'Deselect All';
                    selectAllIcon.textContent = 'deselect';
                } else {
                    selectAllText.textContent = 'Select All';
                    selectAllIcon.textContent = 'select_all';
                }
            }

            document.querySelectorAll('.message-row').forEach(row => {
                const id = parseInt(row.getAttribute('data-msg-id'));
                if (!id) return;
                const isSelected = selectedMessageIds.has(id);
                const checkEl = document.getElementById(`select-check-${id}`);
                const bubbleEl = document.getElementById(`msg-bubble-${id}`);

                if (isSelected) {
                    row.classList.add('msg-row-selected');
                    if (checkEl) {
                        checkEl.className = 'w-5 h-5 rounded-md border-2 flex items-center justify-center transition-all duration-150 bg-indigo-600 border-indigo-600 text-white shadow-xs';
                        const icon = checkEl.querySelector('.material-icons-round');
                        if (icon) icon.classList.remove('hidden');
                    }
                    if (bubbleEl) {
                        bubbleEl.classList.add('ring-2', 'ring-indigo-500', 'ring-offset-2', 'ring-offset-slate-100', 'rounded-2xl');
                    }
                } else {
                    row.classList.remove('msg-row-selected');
                    if (checkEl) {
                        checkEl.className = 'w-5 h-5 rounded-md border-2 flex items-center justify-center transition-all duration-150 border-slate-300 bg-white hover:border-indigo-400 text-transparent';
                        const icon = checkEl.querySelector('.material-icons-round');
                        if (icon) icon.classList.add('hidden');
                    }
                    if (bubbleEl) {
                        bubbleEl.classList.remove('ring-2', 'ring-indigo-500', 'ring-offset-2', 'ring-offset-slate-100', 'rounded-2xl');
                    }
                }
            });
        }

        function copySelectedMessages() {
            if (selectedMessageIds.size === 0) {
                showQuickToast('No messages selected');
                return;
            }

            const sortedIds = Array.from(selectedMessageIds).sort((a, b) => a - b);
            const lines = [];

            sortedIds.forEach(id => {
                const msg = messagesDataMap.get(id);
                if (!msg) return;
                if (parseInt(msg.is_deleted_for_everyone) === 1) return;

                const text = msg.message || (msg.file_name ? `[File: ${msg.file_name}]` : '');
                if (!text) return;

                if (sortedIds.length === 1) {
                    lines.push(text);
                } else {
                    const isMine = (parseInt(msg.sender_id) === myUserId);
                    const sender = isMine ? 'You' : (msg.sender_name || 'Contact');
                    const time = formatMessageTime(msg.created_at);
                    lines.push(`[${time}] ${sender}: ${text}`);
                }
            });

            if (lines.length === 0) {
                showQuickToast('No text available to copy');
                return;
            }

            const textToCopy = lines.join('\n');
            navigator.clipboard.writeText(textToCopy).then(() => {
                showQuickToast(`${sortedIds.length} ${sortedIds.length === 1 ? 'message' : 'messages'} copied to clipboard`);
            }).catch(() => {
                showQuickToast('Failed to copy');
            });
        }

        function forwardSelectedMessages() {
            if (selectedMessageIds.size === 0) {
                showQuickToast('Please select at least one message to forward');
                return;
            }

            forwardTargetMsgIds = Array.from(selectedMessageIds).sort((a, b) => a - b);
            const count = forwardTargetMsgIds.length;

            const previewEl = document.getElementById('forwardPreviewText');
            if (previewEl) {
                if (count === 1) {
                    const msg = messagesDataMap.get(forwardTargetMsgIds[0]);
                    previewEl.textContent = msg ? (msg.message || (msg.file_name ? `📎 ${msg.file_name}` : 'Attachment')) : '1 message';
                } else {
                    previewEl.textContent = `${count} selected messages`;
                }
            }

            const searchInp = document.getElementById('forwardSearchInput');
            if (searchInp) searchInp.value = '';

            renderForwardContacts();
            document.getElementById('forwardMessageModal').classList.remove('hidden');
        }

        // Close select mode on Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && isMessageSelectMode) {
                exitMessageSelectMode();
            }
        });

        /* ========================================================
           WEB AUDIO SYNTHESIZER (Calling Ringtone & Beeps)
           ======================================================== */
        let callToneCtx = null;
        let callSoundInterval = null;

        function getCallAudioContext() {
            try {
                if (!callToneCtx) {
                    const AudioCtxClass = window.AudioContext || window.webkitAudioContext;
                    if (AudioCtxClass) {
                        callToneCtx = new AudioCtxClass();
                    }
                }
                if (callToneCtx && callToneCtx.state === 'suspended') {
                    callToneCtx.resume().catch(()=>{});
                }
            } catch (e) {
                console.warn("[DigiChat AudioContext] AudioContext initialization deferred until interaction:", e);
            }
            return callToneCtx;
        }

        function playCallingSound() {
            stopCallSounds();
            const ctx = getCallAudioContext();

            function triggerRingBurst() {
                try {
                    const now = ctx.currentTime;
                    const osc1 = ctx.createOscillator();
                    const osc2 = ctx.createOscillator();
                    const gain = ctx.createGain();

                    osc1.type = 'sine';
                    osc1.frequency.setValueAtTime(440, now);
                    osc2.type = 'sine';
                    osc2.frequency.setValueAtTime(480, now);

                    gain.gain.setValueAtTime(0.08, now);
                    gain.gain.setValueAtTime(0.08, now + 1.8);
                    gain.gain.exponentialRampToValueAtTime(0.0001, now + 2.0);

                    osc1.connect(gain);
                    osc2.connect(gain);
                    gain.connect(ctx.destination);

                    osc1.start(now);
                    osc2.start(now);
                    osc1.stop(now + 2.0);
                    osc2.stop(now + 2.0);
                } catch(e) {}
            }

            triggerRingBurst();
            callSoundInterval = setInterval(triggerRingBurst, 4500);
        }

        function playRingtoneSound() {
            stopCallSounds();
            const ctx = getCallAudioContext();

            function triggerMelody() {
                try {
                    const notes = [
                        { f: 659.25, t: 0, d: 0.15 },
                        { f: 880.00, t: 0.18, d: 0.15 },
                        { f: 1108.73, t: 0.36, d: 0.25 },
                        { f: 1318.51, t: 0.65, d: 0.35 },
                        { f: 1108.73, t: 1.1, d: 0.15 },
                        { f: 1318.51, t: 1.3, d: 0.4 }
                    ];
                    const now = ctx.currentTime;
                    notes.forEach(n => {
                        const osc = ctx.createOscillator();
                        const gain = ctx.createGain();
                        osc.type = 'sine';
                        osc.frequency.setValueAtTime(n.f, now + n.t);
                        gain.gain.setValueAtTime(0.12, now + n.t);
                        gain.gain.exponentialRampToValueAtTime(0.0001, now + n.t + n.d);
                        osc.connect(gain);
                        gain.connect(ctx.destination);
                        osc.start(now + n.t);
                        osc.stop(now + n.t + n.d);
                    });
                } catch(e) {}
            }

            triggerMelody();
            callSoundInterval = setInterval(triggerMelody, 3200);
        }

        function playHangupSound() {
            stopCallSounds();
            try {
                const ctx = getCallAudioContext();
                const now = ctx.currentTime;
                [0, 0.2, 0.4].forEach(offset => {
                    const osc = ctx.createOscillator();
                    const gain = ctx.createGain();
                    osc.type = 'sine';
                    osc.frequency.setValueAtTime(425, now + offset);
                    gain.gain.setValueAtTime(0.12, now + offset);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + offset + 0.12);
                    osc.connect(gain);
                    gain.connect(ctx.destination);
                    osc.start(now + offset);
                    osc.stop(now + offset + 0.12);
                });
            } catch(e) {}
        }

        let busyToneInterval = null;
        let busyNoticeTimeout = null;
        let callEndedTimeout = null;

        function playBusyToneSound() {
            stopCallSounds();
            try {
                const ctx = getCallAudioContext();
                function triggerBusyPulse() {
                    try {
                        const now = ctx.currentTime;
                        // Indian telecom operator busy tone: 400Hz/480Hz dual tone (375ms tone, 375ms pause)
                        const osc1 = ctx.createOscillator();
                        const osc2 = ctx.createOscillator();
                        const gain = ctx.createGain();

                        osc1.type = 'sine';
                        osc1.frequency.setValueAtTime(400, now);
                        osc2.type = 'sine';
                        osc2.frequency.setValueAtTime(480, now);

                        gain.gain.setValueAtTime(0.12, now);
                        gain.gain.setValueAtTime(0.12, now + 0.35);
                        gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.375);

                        osc1.connect(gain);
                        osc2.connect(gain);
                        gain.connect(ctx.destination);

                        osc1.start(now);
                        osc2.start(now);
                        osc1.stop(now + 0.375);
                        osc2.stop(now + 0.375);
                    } catch(e) {}
                }
                triggerBusyPulse();
                busyToneInterval = setInterval(triggerBusyPulse, 750);
            } catch(e) {}
        }

        function speakTelecomBusyNotice() {
            if (!('speechSynthesis' in window)) return;
            try {
                window.speechSynthesis.cancel();
                // Announcement in Hindi IVR format as requested:
                const noticeText = "आप जिस व्यक्ति को कॉल कर रहे हैं, वह दूसरे कॉल पर व्यस्त हैं। कृपया प्रतीक्षा करें।";
                const utterance = new SpeechSynthesisUtterance(noticeText);
                utterance.rate = 0.95; // Clear natural pace
                utterance.pitch = 1.0;
                utterance.lang = 'hi-IN';

                const voices = window.speechSynthesis.getVoices();
                const matchedVoice = voices.find(v => v.lang.toLowerCase().includes('hi-in') || v.lang.toLowerCase().startsWith('hi')) 
                                  || voices.find(v => v.name.toLowerCase().includes('hindi') || v.name.toLowerCase().includes('swara') || v.name.toLowerCase().includes('madhur') || v.name.toLowerCase().includes('kalpana'))
                                  || voices.find(v => v.lang.toLowerCase().includes('en-in'));

                if (matchedVoice) {
                    utterance.voice = matchedVoice;
                }
                window.speechSynthesis.speak(utterance);
            } catch(e) {
                console.warn("Speech synthesis notice error:", e);
            }
        }

        function speakTelecomOfflineNotice() {
            if (!('speechSynthesis' in window)) return;
            try {
                window.speechSynthesis.cancel();
                // Announcement in Hindi IVR format for offline / unreachable users:
                const noticeText = "आप जिस व्यक्ति को कॉल कर रहे हैं, वह अभी ऑफलाइन हैं। कृपया उनके ऑनलाइन आने का इंतज़ार करें।";
                const utterance = new SpeechSynthesisUtterance(noticeText);
                utterance.rate = 0.92; // Clear, polite telecom announcement pace
                utterance.pitch = 1.05;
                utterance.lang = 'hi-IN';

                const voices = window.speechSynthesis.getVoices();
                const matchedVoice = voices.find(v => v.lang.toLowerCase().includes('hi-in') || v.lang.toLowerCase().startsWith('hi')) 
                                  || voices.find(v => v.name.toLowerCase().includes('hindi') || v.name.toLowerCase().includes('swara') || v.name.toLowerCase().includes('madhur') || v.name.toLowerCase().includes('kalpana'))
                                  || voices.find(v => v.lang.toLowerCase().includes('en-in'));

                if (matchedVoice) {
                    utterance.voice = matchedVoice;
                }
                window.speechSynthesis.speak(utterance);
            } catch(e) {
                console.warn("Speech synthesis offline notice error:", e);
            }
        }

        function stopCallSounds() {
            if (callSoundInterval) {
                clearInterval(callSoundInterval);
                callSoundInterval = null;
            }
            if (busyToneInterval) {
                clearInterval(busyToneInterval);
                busyToneInterval = null;
            }
            if ('speechSynthesis' in window) {
                try { window.speechSynthesis.cancel(); } catch(e) {}
            }
            clearTimeout(busyNoticeTimeout);
        }

        /* ========================================================
           WEBRTC VOICE & VIDEO CALLING ENGINE
           ======================================================== */
        let currentCall = null;
        let peerConnection = null;
        let localMediaStream = null;
        let remoteMediaStream = null;
        let screenStream = null;
        let isScreenSharing = false;
        let callTimerInterval = null;
        let callStartTime = 0;
        let callStatusPollInterval = null;
        let pendingLocalCandidates = [];
        let pendingRemoteCandidates = [];
        let lastPolledCandidateId = 0;

        const rtcConfiguration = {
            iceServers: [
                { urls: 'stun:stun.l.google.com:19302' },
                { urls: 'stun:stun1.l.google.com:19302' },
                { urls: 'stun:stun2.l.google.com:19302' },
                { urls: 'stun:stun3.l.google.com:19302' },
                { urls: 'stun:stun4.l.google.com:19302' },
                { urls: 'stun:global.stun.twilio.com:3478' },
                { urls: 'stun:stun.services.mozilla.com' },
                { urls: 'stun:stun.cloudflare.com:3478' },
                {
                    urls: [
                        'turn:openrelay.metered.ca:80',
                        'turn:openrelay.metered.ca:443',
                        'turn:openrelay.metered.ca:443?transport=tcp'
                    ],
                    username: 'openrelayproject',
                    credential: 'openrelayproject'
                }
            ],
            iceCandidatePoolSize: 10
        };

        // Helper to send ICE candidate to backend
        function sendIceCandidateToServer(callId, candidate) {
            if (!callId || !candidate) return;
            const fd = new FormData();
            fd.append('action', 'send_ice_candidate');
            fd.append('call_id', callId);
            fd.append('candidate', JSON.stringify(candidate));
            fetch('api.php', { method: 'POST', body: fd }).catch(err => {
                console.warn("Candidate transmission error:", err);
            });
        }

        // 1. Start Outgoing Call (Direct 1-on-1 or Group)
        async function startCall(type) {
            if (!activeContactId && !activeGroupId) {
                alert('Please select a contact or group first.');
                return;
            }

            if (activeGroupId) {
                startGroupCall(type, activeGroupId);
                return;
            }

            if (currentContactIsBlockedByMe) {
                alert('You have blocked this contact. Unblock them to make calls.');
                return;
            }
            if (currentContactIsBlockedByThem) {
                alert('Calls are not permitted with this contact.');
                return;
            }
            if (currentCall) {
                alert('You are already in a call.');
                return;
            }

            const contact = contactsData.find(c => parseInt(c.id) === parseInt(activeContactId));
            if (!contact) return;

            // Reset state & buffers
            pendingLocalCandidates = [];
            pendingRemoteCandidates = [];
            lastPolledCandidateId = 0;
            remoteMediaStream = null;

            try {
                const isVideo = (type === 'video');
                localMediaStream = await navigator.mediaDevices.getUserMedia({
                    audio: {
                        echoCancellation: true,
                        noiseSuppression: true,
                        autoGainControl: true
                    },
                    video: isVideo ? { width: { ideal: 1280 }, height: { ideal: 720 }, facingMode: 'user' } : false
                });

                // Attach local preview
                const localVid = document.getElementById('localVideo');
                if (localVid) {
                    localVid.srcObject = localMediaStream;
                    localVid.muted = true;
                    localVid.play().catch(()=>{});
                }

                // Show Outgoing Modal
                document.getElementById('outgoingCallName').textContent = contact.name;
                document.getElementById('outgoingCallRole').textContent = contact.role_label;
                document.getElementById('outgoingCallAvatar').textContent = contact.name.charAt(0).toUpperCase();
                document.getElementById('outgoingCallTypeIcon').textContent = isVideo ? 'videocam' : 'call';
                document.getElementById('outgoingCallTypeText').textContent = (isVideo ? 'Video Call' : 'Voice Call') + ' • Ringing...';
                document.getElementById('outgoingCallModal').classList.remove('hidden');

                playCallingSound();

                // Setup PeerConnection
                peerConnection = new RTCPeerConnection(rtcConfiguration);

                localMediaStream.getTracks().forEach(track => {
                    peerConnection.addTrack(track, localMediaStream);
                });

                setupPeerConnectionListeners();

                // Create WebRTC Offer (Always ready for audio + video/screen share)
                const offer = await peerConnection.createOffer({
                    offerToReceiveAudio: true,
                    offerToReceiveVideo: true
                });
                await peerConnection.setLocalDescription(offer);

                // Send offer to backend
                const fd = new FormData();
                fd.append('action', 'initiate_call');
                fd.append('receiver_id', activeContactId);
                fd.append('call_type', type);
                fd.append('offer', JSON.stringify(offer));

                const res = await fetch('api.php', { method: 'POST', body: fd });
                const data = await res.json();

                if (!data.success) {
                    if (data.is_offline) {
                        handleUserOfflineNotice(contact.name || data.receiver_name);
                        return;
                    }
                    if (data.is_busy) {
                        handleUserBusyNotice(contact.name || data.receiver_name);
                        return;
                    }
                    alert(data.error || 'Call could not be started');
                    cleanupCall();
                    return;
                }

                currentCall = {
                    id: parseInt(data.call_id),
                    type: type,
                    peerId: activeContactId,
                    peerName: contact.name,
                    isGroup: false,
                    isCaller: true,
                    status: 'calling'
                };

                // FLUSH all local ICE candidates gathered during offer creation
                if (pendingLocalCandidates.length > 0) {
                    for (const cand of pendingLocalCandidates) {
                        sendIceCandidateToServer(currentCall.id, cand);
                    }
                    pendingLocalCandidates = [];
                }

                // Start polling call status
                startCallStatusPolling(currentCall.id);

            } catch(err) {
                console.error("Start call error:", err);
                alert("Microphone/Camera permission denied or not supported: " + (err.message || err));
                cleanupCall();
            }
        }

        // 1b. Start Group Voice / Video Call
        async function startGroupCall(type, groupId) {
            if (currentCall) {
                alert('You are already in a call.');
                return;
            }

            const grp = groupsData.find(g => parseInt(g.id) === parseInt(groupId));
            const groupName = grp ? grp.name : 'Group Call';

            // Reset state & buffers
            pendingLocalCandidates = [];
            pendingRemoteCandidates = [];
            lastPolledCandidateId = 0;
            remoteMediaStream = null;

            try {
                const isVideo = (type === 'video');
                localMediaStream = await navigator.mediaDevices.getUserMedia({
                    audio: {
                        echoCancellation: true,
                        noiseSuppression: true,
                        autoGainControl: true
                    },
                    video: isVideo ? { width: { ideal: 1280 }, height: { ideal: 720 }, facingMode: 'user' } : false
                });

                // Attach local preview
                const localVid = document.getElementById('localVideo');
                if (localVid) {
                    localVid.srcObject = localMediaStream;
                    localVid.muted = true;
                    localVid.play().catch(()=>{});
                }

                // Show Outgoing Modal tailored for Group
                document.getElementById('outgoingCallName').textContent = groupName;
                document.getElementById('outgoingCallRole').textContent = grp ? `Group Call • ${grp.member_count || ''} Members` : 'Group Call';
                document.getElementById('outgoingCallAvatar').textContent = groupName.charAt(0).toUpperCase();
                document.getElementById('outgoingCallTypeIcon').textContent = isVideo ? 'videocam' : 'groups';
                document.getElementById('outgoingCallTypeText').textContent = (isVideo ? 'Group Video Call' : 'Group Voice Call') + ' • Ringing Members...';
                document.getElementById('outgoingCallModal').classList.remove('hidden');

                playCallingSound();

                // Setup PeerConnection
                peerConnection = new RTCPeerConnection(rtcConfiguration);
                localMediaStream.getTracks().forEach(track => {
                    peerConnection.addTrack(track, localMediaStream);
                });

                setupPeerConnectionListeners();

                // Create WebRTC Offer (Always ready for audio + video/screen share)
                const offer = await peerConnection.createOffer({
                    offerToReceiveAudio: true,
                    offerToReceiveVideo: true
                });
                await peerConnection.setLocalDescription(offer);

                // Send offer to backend
                const fd = new FormData();
                fd.append('action', 'initiate_call');
                fd.append('group_id', groupId);
                fd.append('call_type', type);
                fd.append('offer', JSON.stringify(offer));

                const res = await fetch('api.php', { method: 'POST', body: fd });
                const data = await res.json();

                if (!data.success) {
                    alert(data.error || 'Group call could not be started');
                    cleanupCall();
                    return;
                }

                currentCall = {
                    id: parseInt(data.call_id),
                    type: type,
                    groupId: groupId,
                    peerName: groupName,
                    isGroup: true,
                    isCaller: true,
                    status: 'calling'
                };

                if (data.already_active) {
                    showQuickToast('Joining live call in this group');
                }

                // Flush local ICE candidates
                if (pendingLocalCandidates.length > 0) {
                    for (const cand of pendingLocalCandidates) {
                        sendIceCandidateToServer(currentCall.id, cand);
                    }
                    pendingLocalCandidates = [];
                }

                // Start polling call status
                startCallStatusPolling(currentCall.id);

            } catch(err) {
                console.error("Start group call error:", err);
                alert("Microphone/Camera permission denied or not supported: " + (err.message || err));
                cleanupCall();
            }
        }

        // 1c. Join Existing Group Call from Banner
        function joinActiveGroupCall() {
            if (!activeGroupCallData || !activeGroupId) {
                showQuickToast('No active group call found');
                return;
            }
            joinGroupCall(activeGroupCallData.id, activeGroupId, activeGroupCallData.call_type || 'voice');
        }

        async function joinGroupCall(callId, groupId, callType) {
            if (currentCall) {
                alert('You are already in a call.');
                return;
            }

            const grp = groupsData.find(g => parseInt(g.id) === parseInt(groupId));
            const groupName = grp ? grp.name : 'Group Call';

            pendingLocalCandidates = [];
            pendingRemoteCandidates = [];
            lastPolledCandidateId = 0;
            remoteMediaStream = null;

            try {
                // Fetch call info and offer from backend
                const stRes = await fetch(`api.php?action=get_call_status&call_id=${callId}`);
                const stData = await stRes.json();
                if (!stData.success || !stData.offer) {
                    alert("Group call is no longer active.");
                    return;
                }

                const isVideo = (stData.call_type === 'video');
                localMediaStream = await navigator.mediaDevices.getUserMedia({
                    audio: {
                        echoCancellation: true,
                        noiseSuppression: true,
                        autoGainControl: true
                    },
                    video: isVideo ? { width: { ideal: 1280 }, height: { ideal: 720 }, facingMode: 'user' } : false
                });

                const localVid = document.getElementById('localVideo');
                if (localVid) {
                    localVid.srcObject = localMediaStream;
                    localVid.muted = true;
                    localVid.play().catch(()=>{});
                }

                peerConnection = new RTCPeerConnection(rtcConfiguration);
                localMediaStream.getTracks().forEach(track => {
                    peerConnection.addTrack(track, localMediaStream);
                });
                setupPeerConnectionListeners();

                // Set Remote Description (Host Offer)
                const offerObj = safeJsonParse(stData.offer, stData.offer);
                if (offerObj) {
                    await peerConnection.setRemoteDescription(new RTCSessionDescription(offerObj));
                }

                // Create Answer
                const answer = await peerConnection.createAnswer();
                await peerConnection.setLocalDescription(answer);

                // Send Answer to backend
                const fd = new FormData();
                fd.append('action', 'answer_call');
                fd.append('call_id', callId);
                fd.append('answer', JSON.stringify(answer));
                await fetch('api.php', { method: 'POST', body: fd });

                currentCall = {
                    id: parseInt(callId),
                    type: stData.call_type,
                    peerName: stData.group_name || groupName,
                    groupId: groupId,
                    isGroup: true,
                    isCaller: false,
                    status: 'connected'
                };

                // Flush local candidates
                if (pendingLocalCandidates.length > 0) {
                    for (const cand of pendingLocalCandidates) {
                        sendIceCandidateToServer(currentCall.id, cand);
                    }
                    pendingLocalCandidates = [];
                }

                openActiveCallScreen(currentCall.peerName, currentCall.type);
                startCallStatusPolling(currentCall.id);

            } catch(err) {
                console.error("Join group call error:", err);
                alert("Permission or connection error joining group call: " + (err.message || err));
                cleanupCall();
            }
        }

        function setupPeerConnectionListeners() {
            if (!peerConnection) return;

            peerConnection.onicecandidate = (event) => {
                if (event.candidate) {
                    if (currentCall && currentCall.id) {
                        sendIceCandidateToServer(currentCall.id, event.candidate);
                    } else {
                        pendingLocalCandidates.push(event.candidate);
                    }
                }
            };

            peerConnection.ontrack = (event) => {
                console.log("Remote track event received:", event.track.kind);
                const rVid = document.getElementById('remoteVideo');
                const rVidBg = document.getElementById('remoteVideoBg');
                const rAud = document.getElementById('remoteAudio');

                if (event.streams && event.streams[0]) {
                    remoteMediaStream = event.streams[0];
                } else {
                    if (!remoteMediaStream) {
                        remoteMediaStream = new MediaStream();
                    }
                    if (!remoteMediaStream.getTracks().find(t => t.id === event.track.id)) {
                        remoteMediaStream.addTrack(event.track);
                    }
                }

                // If remote video track received (camera or screen share)
                if (event.track.kind === 'video') {
                    if (rVid) rVid.classList.remove('hidden');
                    if (rVidBg) rVidBg.classList.remove('hidden');
                    const voiceStage = document.getElementById('voiceCallStage');
                    if (voiceStage) voiceStage.classList.add('hidden');

                    const restoreVoiceStageIfVoiceCall = () => {
                        if (currentCall && currentCall.type === 'voice') {
                            if (rVid) rVid.classList.add('hidden');
                            if (rVidBg) rVidBg.classList.add('hidden');
                            const vStage = document.getElementById('voiceCallStage');
                            if (vStage) vStage.classList.remove('hidden');
                        }
                    };
                    event.track.onmute = restoreVoiceStageIfVoiceCall;
                    event.track.onended = restoreVoiceStageIfVoiceCall;
                }

                if (rVid) {
                    if (rVid.srcObject !== remoteMediaStream) {
                        rVid.srcObject = remoteMediaStream;
                    }
                    rVid.muted = false;
                    rVid.play().catch(e => {
                        console.warn('rVid play err:', e);
                        // Retry muted on autoplay policy restriction
                        rVid.muted = true;
                        rVid.play().then(() => { rVid.muted = false; }).catch(()=>{});
                    });
                }

                if (rVidBg) {
                    if (rVidBg.srcObject !== remoteMediaStream) {
                        rVidBg.srcObject = remoteMediaStream;
                    }
                    rVidBg.muted = true;
                    rVidBg.play().catch(()=>{});
                }

                if (rAud) {
                    if (rAud.srcObject !== remoteMediaStream) {
                        rAud.srcObject = remoteMediaStream;
                    }
                    rAud.muted = false;
                    rAud.play().catch(e => console.warn('rAud play err:', e));
                }
            };

            peerConnection.oniceconnectionstatechange = () => {
                console.log("ICE Connection State changed:", peerConnection.iceConnectionState);
                if (peerConnection.iceConnectionState === 'connected' || peerConnection.iceConnectionState === 'completed') {
                    const rVid = document.getElementById('remoteVideo');
                    const rAud = document.getElementById('remoteAudio');
                    if (rVid && remoteMediaStream) {
                        if (rVid.srcObject !== remoteMediaStream) rVid.srcObject = remoteMediaStream;
                        if (rVid.paused) rVid.play().catch(()=>{});
                    }
                    if (rAud && remoteMediaStream) {
                        if (rAud.srcObject !== remoteMediaStream) rAud.srcObject = remoteMediaStream;
                        if (rAud.paused) rAud.play().catch(()=>{});
                    }
                }
            };

            peerConnection.onconnectionstatechange = () => {
                console.log("PeerConnection State:", peerConnection.connectionState);
                if (peerConnection.connectionState === 'connected') {
                    const rVid = document.getElementById('remoteVideo');
                    if (rVid && remoteMediaStream) {
                        if (rVid.srcObject !== remoteMediaStream) rVid.srcObject = remoteMediaStream;
                        if (rVid.paused) rVid.play().catch(()=>{});
                    }
                }
            };
        }

        function handleUserBusyNotice(name) {
            stopCallSounds();
            
            // Release local media tracks immediately so camera/mic turn off
            if (localMediaStream) {
                try { localMediaStream.getTracks().forEach(t => t.stop()); } catch(e) {}
                localMediaStream = null;
            }
            if (peerConnection) {
                try { peerConnection.close(); } catch(e) {}
                peerConnection = null;
            }

            // Update Outgoing Call Modal to display Busy status
            const modal = document.getElementById('outgoingCallModal');
            const nameEl = document.getElementById('outgoingCallName');
            const roleEl = document.getElementById('outgoingCallRole');
            const typeIcon = document.getElementById('outgoingCallTypeIcon');
            const typeText = document.getElementById('outgoingCallTypeText');
            const busyBox = document.getElementById('outgoingBusyBox');
            const offlineBox = document.getElementById('outgoingOfflineBox');

            if (offlineBox) offlineBox.classList.add('hidden');
            if (nameEl) nameEl.textContent = name || 'User';
            if (roleEl) roleEl.textContent = 'Doosre Call Par Vyast Hain';
            if (typeIcon) typeIcon.textContent = 'phone_in_talk';
            if (typeText) typeText.textContent = 'User is busy on another call';

            if (busyBox) busyBox.classList.remove('hidden');
            if (modal) modal.classList.remove('hidden');

            // Play telecom busy tone & speak aloud Hindi IVR
            playBusyToneSound();
            speakTelecomBusyNotice();

            showQuickToast(`${name || 'User'} doosre call par vyast hain`);

            // Auto-close modal after 5.5 seconds (allowing full speech & tone)
            clearTimeout(busyNoticeTimeout);
            busyNoticeTimeout = setTimeout(() => {
                cancelOutgoingCall();
            }, 5500);
        }

        function handleUserOfflineNotice(name) {
            stopCallSounds();
            
            // Release local media tracks immediately so camera/mic turn off
            if (localMediaStream) {
                try { localMediaStream.getTracks().forEach(t => t.stop()); } catch(e) {}
                localMediaStream = null;
            }
            if (peerConnection) {
                try { peerConnection.close(); } catch(e) {}
                peerConnection = null;
            }

            // Update Outgoing Call Modal to display Offline status
            const modal = document.getElementById('outgoingCallModal');
            const nameEl = document.getElementById('outgoingCallName');
            const roleEl = document.getElementById('outgoingCallRole');
            const typeIcon = document.getElementById('outgoingCallTypeIcon');
            const typeText = document.getElementById('outgoingCallTypeText');
            const busyBox = document.getElementById('outgoingBusyBox');
            const offlineBox = document.getElementById('outgoingOfflineBox');

            if (busyBox) busyBox.classList.add('hidden');
            if (nameEl) nameEl.textContent = name || 'User';
            if (roleEl) roleEl.textContent = 'Currently Offline / Internet Off';
            if (typeIcon) typeIcon.textContent = 'wifi_off';
            if (typeText) typeText.textContent = 'User is Offline • Not Reachable';

            if (offlineBox) offlineBox.classList.remove('hidden');
            if (modal) modal.classList.remove('hidden');

            // Play standard telecom disconnected tone & speak aloud Hindi IVR
            playBusyToneSound();
            speakTelecomOfflineNotice();

            showQuickToast(`${name || 'User'} abhi offline hain`);

            // Auto-close modal after 6 seconds (allowing full speech to finish)
            clearTimeout(busyNoticeTimeout);
            busyNoticeTimeout = setTimeout(() => {
                cancelOutgoingCall();
            }, 6000);
        }

        function cancelOutgoingCall() {
            clearTimeout(busyNoticeTimeout);
            const busyBox = document.getElementById('outgoingBusyBox');
            if (busyBox) busyBox.classList.add('hidden');
            const offlineBox = document.getElementById('outgoingOfflineBox');
            if (offlineBox) offlineBox.classList.add('hidden');

            if (currentCall && currentCall.id) {
                const fd = new FormData();
                fd.append('action', 'end_call');
                fd.append('call_id', currentCall.id);
                fetch('api.php', { method: 'POST', body: fd }).catch(()=>{});
            }
            playHangupSound();
            cleanupCall();
        }

        // 2. Handle Incoming Call Notification (Direct & Group)
        function handleIncomingCall(call) {
            if (currentCall) return; // Busy

            const isGroup = !!(call.group_id && parseInt(call.group_id) > 0);
            const peerName = isGroup ? (call.group_name || 'Group Call') : (call.caller_name || 'Team Member');

            currentCall = {
                id: parseInt(call.id),
                type: call.call_type,
                peerId: parseInt(call.caller_id),
                peerName: peerName,
                callerName: call.caller_name || 'Team Member',
                groupId: isGroup ? parseInt(call.group_id) : null,
                groupName: isGroup ? call.group_name : null,
                isGroup: isGroup,
                offer: call.offer,
                isCaller: false,
                status: 'ringing'
            };

            if (isGroup) {
                document.getElementById('incomingCallName').textContent = call.group_name || 'Group Call';
                document.getElementById('incomingCallRole').textContent = `${call.caller_name || 'Member'} started a Group Call`;
                document.getElementById('incomingCallAvatar').textContent = (call.group_name || 'G').charAt(0).toUpperCase();
                document.getElementById('incomingCallTypeIcon').textContent = (call.call_type === 'video') ? 'videocam' : 'groups';
                document.getElementById('incomingCallTypeText').textContent = (call.call_type === 'video') ? 'Incoming Group Video Call' : 'Incoming Group Voice Call';
            } else {
                document.getElementById('incomingCallName').textContent = currentCall.peerName;
                document.getElementById('incomingCallRole').textContent = (call.caller_role || 'Member').toUpperCase();
                document.getElementById('incomingCallAvatar').textContent = currentCall.peerName.charAt(0).toUpperCase();
                document.getElementById('incomingCallTypeIcon').textContent = (call.call_type === 'video') ? 'videocam' : 'call';
                document.getElementById('incomingCallTypeText').textContent = (call.call_type === 'video') ? 'Incoming Video Call' : 'Incoming Voice Call';
            }

            document.getElementById('incomingCallModal').classList.remove('hidden');
            playRingtoneSound();
        }

        // 3. Accept Incoming Call
        async function acceptIncomingCall() {
            if (!currentCall || !currentCall.id) return;
            stopCallSounds();
            document.getElementById('incomingCallModal').classList.add('hidden');
            currentCall.status = 'connected';

            pendingLocalCandidates = [];
            pendingRemoteCandidates = [];
            lastPolledCandidateId = 0;
            remoteMediaStream = null;

            try {
                const isVideo = (currentCall.type === 'video');
                localMediaStream = await navigator.mediaDevices.getUserMedia({
                    audio: {
                        echoCancellation: true,
                        noiseSuppression: true,
                        autoGainControl: true
                    },
                    video: isVideo ? { width: { ideal: 1280 }, height: { ideal: 720 }, facingMode: 'user' } : false
                });

                const localVid = document.getElementById('localVideo');
                if (localVid) {
                    localVid.srcObject = localMediaStream;
                    localVid.muted = true;
                    localVid.play().catch(()=>{});
                }

                peerConnection = new RTCPeerConnection(rtcConfiguration);
                localMediaStream.getTracks().forEach(track => {
                    peerConnection.addTrack(track, localMediaStream);
                });

                setupPeerConnectionListeners();

                // Set Remote Description (Caller Offer)
                const offerObj = safeJsonParse(currentCall.offer, currentCall.offer);
                if (offerObj) {
                    await peerConnection.setRemoteDescription(new RTCSessionDescription(offerObj));
                }

                // Drain any pending remote candidates that arrived before setRemoteDescription
                if (pendingRemoteCandidates.length > 0) {
                    for (const cand of pendingRemoteCandidates) {
                        try {
                            await peerConnection.addIceCandidate(new RTCIceCandidate(cand));
                        } catch(e) {
                            console.warn("Queued candidate add error:", e);
                        }
                    }
                    pendingRemoteCandidates = [];
                }

                // Create Answer
                const answer = await peerConnection.createAnswer();
                await peerConnection.setLocalDescription(answer);

                // Send Answer to backend
                const fd = new FormData();
                fd.append('action', 'answer_call');
                fd.append('call_id', currentCall.id);
                fd.append('answer', JSON.stringify(answer));
                await fetch('api.php', { method: 'POST', body: fd });

                // Flush any local candidates gathered
                if (pendingLocalCandidates.length > 0) {
                    for (const cand of pendingLocalCandidates) {
                        sendIceCandidateToServer(currentCall.id, cand);
                    }
                    pendingLocalCandidates = [];
                }

                // Launch Active Call Screen
                openActiveCallScreen(currentCall.peerName, currentCall.type);
                startCallStatusPolling(currentCall.id);

            } catch(err) {
                console.error("Accept call error:", err);
                alert("Microphone/Camera permission required to answer: " + (err.message || err));
                declineIncomingCall();
            }
        }

        // 4. Decline Incoming Call
        function declineIncomingCall() {
            if (currentCall && currentCall.id) {
                const fd = new FormData();
                fd.append('action', 'reject_call');
                fd.append('call_id', currentCall.id);
                fetch('api.php', { method: 'POST', body: fd }).catch(()=>{});
            }
            playHangupSound();
            cleanupCall();
        }

        // 5. Open Active Call UI
        function openActiveCallScreen(peerName, type) {
            if (currentCall) {
                currentCall.status = 'connected';
            }
            document.getElementById('outgoingCallModal').classList.add('hidden');
            document.getElementById('incomingCallModal').classList.add('hidden');
            document.getElementById('activeCallModal').classList.remove('hidden');

            const isVideo = (type === 'video');
            const isGroup = !!(currentCall && currentCall.isGroup);
            const callBadgeText = isGroup 
                ? (isVideo ? 'Group Video Call' : 'Group Voice Call') 
                : (isVideo ? 'Video Call' : 'Voice Call');

            document.getElementById('activeCallPeerName').textContent = peerName;
            document.getElementById('activeCallPeerInitial').textContent = peerName.charAt(0).toUpperCase();
            document.getElementById('activeCallTypeBadge').textContent = callBadgeText;
            document.getElementById('callDurationTimer').textContent = '00:00';

            const localContainer = document.getElementById('localVideoContainer');
            const remoteVid = document.getElementById('remoteVideo');
            const remoteAud = document.getElementById('remoteAudio');
            const voiceStage = document.getElementById('voiceCallStage');
            const btnCam = document.getElementById('btnToggleCam');

            const btnFilters = document.getElementById('btnToggleFilters');
            const btnAspect = document.getElementById('btnAspectFit');
            const btnScreenShare = document.getElementById('btnToggleScreenShare');
            const remoteVidBg = document.getElementById('remoteVideoBg');

            if (isVideo) {
                localContainer.classList.remove('hidden');
                remoteVid.classList.remove('hidden');
                if (remoteVidBg) remoteVidBg.classList.remove('hidden');
                voiceStage.classList.add('hidden');
                btnCam.classList.remove('hidden');
                if (btnFilters) btnFilters.classList.remove('hidden');
                if (btnAspect) btnAspect.classList.remove('hidden');
                if (btnScreenShare) btnScreenShare.classList.remove('hidden');
                // For video calls, show manual record button (user can choose to record their screen locally)
                const btnRecBottom = document.getElementById('btnRecordCall');
                const btnRecHeader = document.getElementById('btnHeaderRecordCall');
                if (btnRecBottom) btnRecBottom.classList.remove('hidden');
                if (btnRecHeader) btnRecHeader.classList.remove('hidden');
            } else {
                localContainer.classList.add('hidden');
                remoteVid.classList.add('hidden');
                if (remoteVidBg) remoteVidBg.classList.add('hidden');
                voiceStage.classList.remove('hidden');
                btnCam.classList.add('hidden');
                if (btnFilters) btnFilters.classList.add('hidden');
                if (btnAspect) btnAspect.classList.add('hidden');
                if (btnScreenShare) btnScreenShare.classList.remove('hidden');
                // For voice calls, hide manual record button because voice calls are auto-recorded for logs
                const btnRecBottom = document.getElementById('btnRecordCall');
                const btnRecHeader = document.getElementById('btnHeaderRecordCall');
                if (btnRecBottom) btnRecBottom.classList.add('hidden');
                if (btnRecHeader) btnRecHeader.classList.add('hidden');
                document.getElementById('voicePeerName').textContent = peerName;
                document.getElementById('voiceAvatarCircle').textContent = peerName.charAt(0).toUpperCase();
                const subtext = document.getElementById('voiceSubtext');
                if (subtext) {
                    subtext.textContent = isGroup ? 'DigiChat Group Voice Call in progress...' : 'DigiChat Voice Call in progress...';
                }
            }

            // Ensure media plays cleanly
            if (remoteMediaStream) {
                if (remoteVid) {
                    remoteVid.srcObject = remoteMediaStream;
                    remoteVid.muted = false;
                    remoteVid.play().catch(()=>{});
                }
                if (remoteVidBg) {
                    remoteVidBg.srcObject = remoteMediaStream;
                    remoteVidBg.muted = true;
                    remoteVidBg.play().catch(()=>{});
                }
                if (remoteAud) {
                    remoteAud.srcObject = remoteMediaStream;
                    remoteAud.muted = false;
                    remoteAud.play().catch(()=>{});
                }
            }

            // Start Call Duration Timer
            callStartTime = Date.now();
            clearInterval(callTimerInterval);
            callTimerInterval = setInterval(() => {
                const sec = Math.floor((Date.now() - callStartTime) / 1000);
                const m = Math.floor(sec / 60);
                const s = sec % 60;
                document.getElementById('callDurationTimer').textContent = `${m < 10 ? '0' : ''}${m}:${s < 10 ? '0' : ''}${s}`;
            }, 1000);

            // Start Automatic Voice Call Recording for Call Logs (Voice Calls Only)
            // Video calls are NOT auto-recorded; user can manually record screen with button if desired
            if (type === 'voice' && currentCall && currentCall.id) {
                setTimeout(() => {
                    startAutoVoiceRecording(currentCall.id);
                }, 500);
            }
        }

        // 6. Poll Call Status & ICE Candidates
        function startCallStatusPolling(callId) {
            clearInterval(callStatusPollInterval);

            callStatusPollInterval = setInterval(async () => {
                if (!currentCall || currentCall.id !== callId) {
                    clearInterval(callStatusPollInterval);
                    return;
                }

                try {
                    const res = await fetch(`api.php?action=get_call_status&call_id=${callId}&last_candidate_id=${lastPolledCandidateId}`);
                    const data = await res.json();
                    if (!data.success) return;

                    // Group calls: live participants roster update
                    if (data.is_group && Array.isArray(data.participants)) {
                        renderCallParticipants(data.participants);
                    }

                    // Caller side: wait for receiver to answer and connect
                    if (currentCall.isCaller && data.status === 'connected' && currentCall.status !== 'connected') {
                        currentCall.status = 'connected';
                        stopCallSounds();
                        if (data.answer && peerConnection && peerConnection.signalingState !== 'stable') {
                            const ansObj = safeJsonParse(data.answer, data.answer);
                            if (ansObj) {
                                await peerConnection.setRemoteDescription(new RTCSessionDescription(ansObj));
                            }

                            // Drain any queued remote candidates
                            if (pendingRemoteCandidates.length > 0) {
                                for (const cand of pendingRemoteCandidates) {
                                    try {
                                        await peerConnection.addIceCandidate(new RTCIceCandidate(cand));
                                    } catch(e) {
                                        console.warn("Queued candidate add error:", e);
                                    }
                                }
                                pendingRemoteCandidates = [];
                            }
                        }
                        openActiveCallScreen(currentCall.peerName, currentCall.type);
                    }

                    // Process remote ICE candidates from new_candidates array
                    if (Array.isArray(data.new_candidates) && data.new_candidates.length > 0) {
                        for (const item of data.new_candidates) {
                            const candId = parseInt(item.id);
                            if (candId > lastPolledCandidateId) {
                                lastPolledCandidateId = candId;
                            }
                            try {
                                const candObj = safeJsonParse(item.candidate, item.candidate);
                                if (candObj) {
                                    if (peerConnection && peerConnection.remoteDescription && peerConnection.remoteDescription.type) {
                                        await peerConnection.addIceCandidate(new RTCIceCandidate(candObj));
                                    } else {
                                        pendingRemoteCandidates.push(candObj);
                                    }
                                }
                            } catch(err) {
                                console.warn("ICE candidate add error:", err);
                            }
                        }
                    }

                    // Sync peer's video filter so both sides see the filter applied!
                    if (currentCall && currentCall.type === 'video') {
                        const peerFilter = currentCall.isCaller ? (data.receiver_filter || 'normal') : (data.caller_filter || 'normal');
                        applyRemotePeerFilter(peerFilter);
                    }

                    // Check call termination
                    if (currentCall && currentCall.status === 'connected') {
                        // Once connected, the call will ONLY end when a user explicitly clicks End Call or host terminates it
                        if (data.status === 'ended') {
                            handleCallEndedByPeer(currentCall.isGroup ? (data.group_name || currentCall.peerName) : currentCall.peerName, data.duration_text);
                        }
                    } else {
                        // Before connect: handle rejected or missed
                        if (['rejected', 'ended', 'missed'].includes(data.status)) {
                            if (data.status === 'rejected') {
                                showQuickToast('Call declined');
                            } else if (data.status === 'missed') {
                                showQuickToast('Call timed out');
                            } else {
                                showQuickToast('Call ended');
                            }
                            playHangupSound();
                            cleanupCall();
                            if (activeContactId) selectContact(activeContactId);
                            else if (activeGroupId) selectGroup(activeGroupId);
                        }
                    }
                } catch(e) {
                    console.warn("Call status poll error:", e);
                }
            }, 600);
        }

        // 6b. Render Live Group Call Participants Roster
        function renderCallParticipants(participants) {
            const bar = document.getElementById('activeCallParticipantsBar');
            const roster = document.getElementById('voiceGroupRoster');

            if (bar && Array.isArray(participants) && participants.length > 0) {
                bar.classList.remove('hidden');
                bar.innerHTML = `
                    <span class="text-[10px] font-bold text-slate-300 flex items-center gap-1 flex-shrink-0">
                        <span class="material-icons-round text-xs text-emerald-400">group</span>
                        <span>${participants.length} in call:</span>
                    </span>
                    <div class="flex items-center gap-1 overflow-x-auto">
                        ${participants.map(p => `
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-white/10 text-white border border-white/15 flex-shrink-0">
                                <span class="w-1.5 h-1.5 rounded-full ${p.participant_role === 'caller' ? 'bg-amber-400' : 'bg-emerald-400'}"></span>
                                <span class="truncate max-w-[80px]">${escapeHtml(p.name)}</span>
                            </span>
                        `).join('')}
                    </div>
                `;
            }

            if (roster && Array.isArray(participants) && participants.length > 0) {
                roster.classList.remove('hidden');
                roster.innerHTML = participants.map(p => `
                    <div class="flex items-center gap-2 px-3 py-1.5 rounded-2xl bg-white/10 backdrop-blur-md text-white border border-white/15 shadow-sm">
                        <div class="w-7 h-7 rounded-full bg-gradient-to-tr from-indigo-500 to-purple-500 flex items-center justify-center text-xs font-black shadow-xs">
                            ${escapeHtml((p.name || 'U').charAt(0).toUpperCase())}
                        </div>
                        <div class="text-left">
                            <p class="text-xs font-bold leading-none text-white">${escapeHtml(p.name)}</p>
                            <p class="text-[9px] text-emerald-300 font-semibold leading-none mt-1">${p.participant_role === 'caller' ? 'Host' : 'Member'}</p>
                        </div>
                    </div>
                `).join('');
            }
        }

        // 7. Toggle Mic & Camera
        let isMicMuted = false;
        let isCamOff = false;

        function toggleMicrophone() {
            if (!localMediaStream) return;
            const audioTrack = localMediaStream.getAudioTracks()[0];
            if (audioTrack) {
                isMicMuted = !isMicMuted;
                audioTrack.enabled = !isMicMuted;
                const icon = document.getElementById('iconMic');
                const btn = document.getElementById('btnToggleMic');
                if (isMicMuted) {
                    icon.textContent = 'mic_off';
                    btn.classList.add('bg-rose-500/80', 'text-white');
                    btn.classList.remove('bg-white/20');
                } else {
                    icon.textContent = 'mic';
                    btn.classList.remove('bg-rose-500/80');
                    btn.classList.add('bg-white/20');
                }
            }
        }

        function toggleCamera() {
            if (!localMediaStream) return;
            const videoTrack = localMediaStream.getVideoTracks()[0];
            if (videoTrack) {
                isCamOff = !isCamOff;
                videoTrack.enabled = !isCamOff;
                const icon = document.getElementById('iconCam');
                const btn = document.getElementById('btnToggleCam');
                if (isCamOff) {
                    icon.textContent = 'videocam_off';
                    btn.classList.add('bg-rose-500/80', 'text-white');
                    btn.classList.remove('bg-white/20');
                } else {
                    icon.textContent = 'videocam';
                    btn.classList.remove('bg-rose-500/80');
                    btn.classList.add('bg-white/20');
                }
            }
        }

        // 7b. Screen Sharing Engine
        async function toggleScreenShare() {
            if (isScreenSharing) {
                stopScreenShare();
            } else {
                startScreenShare();
            }
        }

        async function startScreenShare() {
            if (!peerConnection || !currentCall) {
                showQuickToast('Call is not active');
                return;
            }

            if (!navigator.mediaDevices || !navigator.mediaDevices.getDisplayMedia) {
                alert('Screen sharing is not supported on this browser or platform.');
                return;
            }

            try {
                screenStream = await navigator.mediaDevices.getDisplayMedia({
                    video: {
                        cursor: 'always',
                        displaySurface: 'monitor'
                    },
                    audio: false
                });

                const screenTrack = screenStream.getVideoTracks()[0];
                if (!screenTrack) {
                    throw new Error('No video track found for screen share.');
                }

                // If user clicks browser native "Stop sharing" bar
                screenTrack.onended = () => {
                    stopScreenShare();
                };

                // Replace outgoing video track in WebRTC PeerConnection
                const senders = peerConnection.getSenders();
                const videoSender = senders.find(s => s.track && s.track.kind === 'video') || senders.find(s => s.kind === 'video');

                if (videoSender) {
                    await videoSender.replaceTrack(screenTrack);
                } else {
                    peerConnection.addTrack(screenTrack, screenStream);
                }

                isScreenSharing = true;

                // Ensure local PiP preview container is visible (even in Voice Calls)
                const localContainer = document.getElementById('localVideoContainer');
                if (localContainer) localContainer.classList.remove('hidden');

                // Update local PiP preview so user sees what is being shared (un-mirrored)
                const localVid = document.getElementById('localVideo');
                if (localVid) {
                    localVid.srcObject = screenStream;
                    localVid.classList.remove('-scale-x-100');
                    localVid.play().catch(()=>{});
                }

                const localLabel = document.getElementById('localVideoLabel');
                if (localLabel) {
                    localLabel.textContent = 'Screen (You)';
                }

                // Update Button State
                const btn = document.getElementById('btnToggleScreenShare');
                const icon = document.getElementById('iconScreenShare');
                if (btn) {
                    btn.classList.add('bg-indigo-600', 'hover:bg-indigo-700', 'ring-2', 'ring-indigo-400');
                    btn.classList.remove('bg-white/20', 'hover:bg-white/30');
                    btn.title = "Stop Screen Sharing";
                }
                if (icon) {
                    icon.textContent = 'stop_screen_share';
                }

                // Show top screen share floating indicator
                const banner = document.getElementById('screenShareBanner');
                if (banner) {
                    banner.classList.remove('hidden');
                }

                showQuickToast('🖥️ Screen sharing active');

            } catch (err) {
                console.warn('Screen share error or cancelled:', err);
                if (err.name !== 'NotAllowedError') {
                    showQuickToast('Could not share screen: ' + (err.message || err));
                }
                stopScreenShare();
            }
        }

        async function stopScreenShare() {
            if (!isScreenSharing && !screenStream) return;
            isScreenSharing = false;

            // Stop screen tracks
            if (screenStream) {
                try {
                    screenStream.getTracks().forEach(t => t.stop());
                } catch(e) {}
                screenStream = null;
            }

            // Restore camera video track
            try {
                let cameraTrack = null;
                if (localMediaStream) {
                    cameraTrack = localMediaStream.getVideoTracks()[0];
                }

                // If camera track was ended or not available, re-acquire camera if in video call
                if ((!cameraTrack || cameraTrack.readyState === 'ended') && currentCall && currentCall.type === 'video') {
                    try {
                        const newCamStream = await navigator.mediaDevices.getUserMedia({
                            video: { width: { ideal: 1280 }, height: { ideal: 720 }, facingMode: 'user' }
                        });
                        const newCamTrack = newCamStream.getVideoTracks()[0];
                        if (localMediaStream) {
                            localMediaStream.addTrack(newCamTrack);
                        } else {
                            localMediaStream = newCamStream;
                        }
                        cameraTrack = newCamTrack;
                    } catch(e) {
                        console.warn('Could not re-acquire camera:', e);
                    }
                }

                if (peerConnection && cameraTrack) {
                    const senders = peerConnection.getSenders();
                    const videoSender = senders.find(s => s.track && s.track.kind === 'video') || senders.find(s => s.kind === 'video');
                    if (videoSender) {
                        await videoSender.replaceTrack(cameraTrack);
                    }
                }

                if (currentCall && currentCall.type === 'voice') {
                    // Hide local PiP preview since voice call doesn't have camera
                    const localContainer = document.getElementById('localVideoContainer');
                    if (localContainer) localContainer.classList.add('hidden');
                    const localVid = document.getElementById('localVideo');
                    if (localVid) localVid.srcObject = null;
                    if (peerConnection) {
                        const senders = peerConnection.getSenders();
                        const videoSender = senders.find(s => s.track && s.track.kind === 'video') || senders.find(s => s.kind === 'video');
                        if (videoSender) {
                            try { await videoSender.replaceTrack(null); } catch(e) {}
                        }
                    }
                } else {
                    // Restore local PiP preview with natural selfie mirroring
                    const localVid = document.getElementById('localVideo');
                    if (localVid && localMediaStream) {
                        localVid.srcObject = localMediaStream;
                        localVid.classList.add('-scale-x-100');
                        localVid.play().catch(()=>{});
                    }

                    const localLabel = document.getElementById('localVideoLabel');
                    if (localLabel) {
                        localLabel.textContent = 'You';
                    }
                }
            } catch(err) {
                console.error('Error reverting from screen share:', err);
            }

            // Reset Button State
            const btn = document.getElementById('btnToggleScreenShare');
            const icon = document.getElementById('iconScreenShare');
            if (btn) {
                btn.classList.remove('bg-indigo-600', 'hover:bg-indigo-700', 'ring-2', 'ring-indigo-400');
                btn.classList.add('bg-white/20', 'hover:bg-white/30');
                btn.title = "Share Screen / Window";
            }
            if (icon) {
                icon.textContent = 'screen_share';
            }

            // Hide top banner
            const banner = document.getElementById('screenShareBanner');
            if (banner) {
                banner.classList.add('hidden');
            }

            showQuickToast('Screen sharing stopped');
        }

        // 8. End Call Handlers
        function handleCallEndedByPeer(peerName, durationText) {
            stopCallSounds();
            clearInterval(callTimerInterval);
            clearInterval(callStatusPollInterval);

            // Stop local media tracks immediately
            if (localMediaStream) {
                try { localMediaStream.getTracks().forEach(t => t.stop()); } catch(e) {}
                localMediaStream = null;
            }
            if (screenStream) {
                try { screenStream.getTracks().forEach(t => t.stop()); } catch(e) {}
                screenStream = null;
            }
            if (peerConnection) {
                try { peerConnection.close(); } catch(e) {}
                peerConnection = null;
            }

            playHangupSound();

            const overlay = document.getElementById('callEndedOverlay');
            const title = document.getElementById('callEndedTitle');
            const subtitle = document.getElementById('callEndedSubtitle');
            const durEl = document.getElementById('callEndedDuration');

            const name = peerName || (currentCall ? currentCall.peerName : 'Participant');
            const dur = durationText || (document.getElementById('callDurationTimer') ? document.getElementById('callDurationTimer').textContent : '00:00');

            if (currentCall && currentCall.isGroup) {
                if (title) title.textContent = `${name} call ended`;
                if (subtitle) subtitle.textContent = 'Group call has concluded';
            } else {
                if (title) title.textContent = `${name} left the call`;
                if (subtitle) subtitle.textContent = 'Call has ended';
            }
            if (durEl) durEl.textContent = `Duration: ${dur}`;
            if (overlay) overlay.classList.remove('hidden');

            showQuickToast(currentCall && currentCall.isGroup ? `${name} call ended` : `${name} left the call`);

            // Auto close overlay after 3.2 seconds
            clearTimeout(callEndedTimeout);
            callEndedTimeout = setTimeout(() => {
                closeCallEndedOverlay();
            }, 3200);
        }

        function closeCallEndedOverlay() {
            clearTimeout(callEndedTimeout);
            const overlay = document.getElementById('callEndedOverlay');
            if (overlay) overlay.classList.add('hidden');
            cleanupCall();
            if (activeContactId) selectContact(activeContactId);
            else if (activeGroupId) selectGroup(activeGroupId);
        }

        function endCurrentCall() {
            const timerVal = document.getElementById('callDurationTimer') ? document.getElementById('callDurationTimer').textContent : '00:00';
            const wasConnected = currentCall && currentCall.status === 'connected';

            if (currentCall && currentCall.id) {
                const fd = new FormData();
                fd.append('action', 'end_call');
                fd.append('call_id', currentCall.id);
                fetch('api.php', { method: 'POST', body: fd }).catch(()=>{});
            }
            playHangupSound();

            if (wasConnected) {
                if (localMediaStream) {
                    try { localMediaStream.getTracks().forEach(t => t.stop()); } catch(e) {}
                    localMediaStream = null;
                }
                if (screenStream) {
                    try { screenStream.getTracks().forEach(t => t.stop()); } catch(e) {}
                    screenStream = null;
                }
                if (peerConnection) {
                    try { peerConnection.close(); } catch(e) {}
                    peerConnection = null;
                }
                clearInterval(callTimerInterval);
                clearInterval(callStatusPollInterval);

                const overlay = document.getElementById('callEndedOverlay');
                const title = document.getElementById('callEndedTitle');
                const subtitle = document.getElementById('callEndedSubtitle');
                const durEl = document.getElementById('callEndedDuration');

                if (title) title.textContent = currentCall && currentCall.isGroup ? 'You left the group call' : 'You left the call';
                if (subtitle) subtitle.textContent = 'Call has ended';
                if (durEl) durEl.textContent = `Duration: ${timerVal}`;
                if (overlay) overlay.classList.remove('hidden');

                showQuickToast('Call ended');

                clearTimeout(callEndedTimeout);
                callEndedTimeout = setTimeout(() => {
                    closeCallEndedOverlay();
                }, 1400);
            } else {
                cleanupCall();
                if (activeContactId) {
                    selectContact(activeContactId);
                } else if (activeGroupId) {
                    selectGroup(activeGroupId);
                }
            }
        }

        function cleanupCall() {
            stopCallSounds();
            clearInterval(callTimerInterval);
            clearInterval(callStatusPollInterval);
            clearTimeout(callEndedTimeout);
            clearTimeout(busyNoticeTimeout);

            const overlay = document.getElementById('callEndedOverlay');
            if (overlay) overlay.classList.add('hidden');
            const busyBox = document.getElementById('outgoingBusyBox');
            if (busyBox) busyBox.classList.add('hidden');

            const pBar = document.getElementById('activeCallParticipantsBar');
            if (pBar) {
                pBar.innerHTML = '';
                pBar.classList.add('hidden');
            }
            const roster = document.getElementById('voiceGroupRoster');
            if (roster) {
                roster.innerHTML = '';
                roster.classList.add('hidden');
            }

            if (screenStream) {
                try { screenStream.getTracks().forEach(t => t.stop()); } catch(e) {}
                screenStream = null;
            }
            isScreenSharing = false;

            const banner = document.getElementById('screenShareBanner');
            if (banner) banner.classList.add('hidden');

            const btnScreenShare = document.getElementById('btnToggleScreenShare');
            if (btnScreenShare) {
                btnScreenShare.classList.remove('bg-indigo-600', 'hover:bg-indigo-700', 'ring-2', 'ring-indigo-400');
                btnScreenShare.classList.add('bg-white/20', 'hover:bg-white/30');
                btnScreenShare.title = "Share Screen / Window";
            }
            const iconScreenShare = document.getElementById('iconScreenShare');
            if (iconScreenShare) iconScreenShare.textContent = 'screen_share';

            const localLabel = document.getElementById('localVideoLabel');
            if (localLabel) localLabel.textContent = 'You';

            // Stop auto voice call recording (uploads to server)
            stopAutoVoiceRecording();

            // Stop manual video recording if user was recording (prompts local download)
            if (isRecordingCall) {
                stopManualVideoRecording();
            }

            if (localMediaStream) {
                localMediaStream.getTracks().forEach(t => t.stop());
                localMediaStream = null;
            }

            if (remoteMediaStream) {
                remoteMediaStream.getTracks().forEach(t => t.stop());
                remoteMediaStream = null;
            }

            // Reset video filters
            lastAppliedRemoteFilter = null;
            activeVideoFilter = 'normal';
            const localVidEl = document.getElementById('localVideo');
            if (localVidEl) localVidEl.style.filter = 'none';
            const remoteVidEl = document.getElementById('remoteVideo');
            if (remoteVidEl) remoteVidEl.style.filter = 'none';
            const remoteVidBgEl = document.getElementById('remoteVideoBg');
            if (remoteVidBgEl) remoteVidBgEl.style.filter = 'none';
            const localFiltBadge = document.getElementById('localFilterBadge');
            if (localFiltBadge) localFiltBadge.classList.add('hidden');

            if (peerConnection) {
                try {
                    peerConnection.close();
                } catch(e) {}
                peerConnection = null;
            }

            const rVid = document.getElementById('remoteVideo');
            if (rVid) rVid.srcObject = null;
            const rVidBg = document.getElementById('remoteVideoBg');
            if (rVidBg) rVidBg.srcObject = null;
            const lVid = document.getElementById('localVideo');
            if (lVid) lVid.srcObject = null;
            const rAud = document.getElementById('remoteAudio');
            if (rAud) rAud.srcObject = null;

            // Reset video filters and drawers
            const filtersDrawer = document.getElementById('videoFiltersDrawer');
            if (filtersDrawer) filtersDrawer.classList.add('hidden');
            applyVideoFilter('normal');

            // Exit fullscreen if active
            if (document.fullscreenElement) {
                try { document.exitFullscreen(); } catch(e) {}
            }

            isMicMuted = false;
            isCamOff = false;
            currentCall = null;
            pendingLocalCandidates = [];
            pendingRemoteCandidates = [];
            lastPolledCandidateId = 0;

            document.getElementById('outgoingCallModal').classList.add('hidden');
            document.getElementById('incomingCallModal').classList.add('hidden');
            document.getElementById('activeCallModal').classList.add('hidden');
            fetchCallsHistory();
        }

        /* ========================================================
           VIDEO CALL FILTERS & FULLSCREEN ENGINE
           ======================================================== */
        let activeVideoFilter = 'normal';
        let activeFilterTarget = 'self'; // 'self' or 'both'
        let isVideoFitMode = false; // default: full cover (zero waste space, fills entire viewport)

        const VIDEO_FILTERS = {
            normal:  'none',
            beauty:  'brightness(1.08) contrast(1.06) saturate(1.22)',
            warm:    'sepia(0.28) saturate(1.35) brightness(1.06) hue-rotate(-8deg)',
            cool:    'contrast(1.12) brightness(1.04) saturate(1.18) hue-rotate(15deg)',
            vintage: 'sepia(0.55) contrast(1.15) brightness(0.95) saturate(1.25)',
            noir:    'grayscale(1) contrast(1.25) brightness(1.05)',
            vibrant: 'contrast(1.22) saturate(1.45) brightness(1.04)'
        };

        function toggleFiltersDrawer() {
            const drawer = document.getElementById('videoFiltersDrawer');
            if (!drawer) return;
            drawer.classList.toggle('hidden');
        }

        let lastAppliedRemoteFilter = null;

        function applyRemotePeerFilter(filterKey) {
            if (!filterKey) filterKey = 'normal';
            if (lastAppliedRemoteFilter === filterKey) return;
            lastAppliedRemoteFilter = filterKey;

            const filterCss = VIDEO_FILTERS[filterKey] || 'none';
            const remoteVid = document.getElementById('remoteVideo');
            const remoteVidBg = document.getElementById('remoteVideoBg');
            if (remoteVid) {
                remoteVid.style.filter = filterCss;
            }
            if (remoteVidBg) {
                remoteVidBg.style.filter = filterCss;
            }
        }

        function applyVideoFilter(filterKey) {
            activeVideoFilter = filterKey;
            const filterCss = VIDEO_FILTERS[filterKey] || 'none';

            // Highlight selected filter chip
            document.querySelectorAll('.filter-chip').forEach(btn => {
                if (btn.getAttribute('data-filter') === filterKey) {
                    btn.classList.add('border-emerald-400', 'bg-white/20');
                    btn.classList.remove('border-transparent');
                } else {
                    btn.classList.remove('border-emerald-400', 'bg-white/20');
                    btn.classList.add('border-transparent');
                }
            });

            // Badge indicator on local video preview
            const badge = document.getElementById('localFilterBadge');
            if (badge) {
                if (filterKey !== 'normal') {
                    badge.classList.remove('hidden');
                } else {
                    badge.classList.add('hidden');
                }
            }

            // Apply filter to local video
            const localVid = document.getElementById('localVideo');
            if (localVid) {
                localVid.style.filter = filterCss;
            }

            // Sync filter to remote peer via API so they see this filter on your camera stream too!
            if (currentCall && currentCall.id) {
                const fd = new FormData();
                fd.append('action', 'update_call_filter');
                fd.append('call_id', currentCall.id);
                fd.append('filter', filterKey);
                fetch('api.php', { method: 'POST', body: fd }).catch(() => {});
            }
        }

        function switchFilterTarget(target) {
            activeFilterTarget = target;
            applyVideoFilter(activeVideoFilter);
        }

        function toggleVideoAspectMode() {
            isVideoFitMode = !isVideoFitMode;
            const rVid = document.getElementById('remoteVideo');
            const icon = document.getElementById('iconAspectFit');
            const btn = document.getElementById('btnAspectFit');
            
            if (rVid) {
                if (isVideoFitMode) {
                    rVid.classList.remove('object-cover');
                    rVid.classList.add('object-contain');
                    if (icon) icon.textContent = 'fit_screen';
                    if (btn) btn.title = 'Current: Fit Screen (No Cut) - Click to Fill';
                    showQuickToast('Video Fit (No cut)');
                } else {
                    rVid.classList.remove('object-contain');
                    rVid.classList.add('object-cover');
                    if (icon) icon.textContent = 'crop_free';
                    if (btn) btn.title = 'Current: Fill Screen (Zoomed) - Click to Fit';
                    showQuickToast('Video Fill Screen');
                }
            }
        }

        function toggleCallFullScreen() {
            const modal = document.getElementById('activeCallModal');
            const icon1 = document.getElementById('iconFullScreen');
            const icon2 = document.getElementById('iconHeaderFullScreen');

            if (!document.fullscreenElement) {
                if (modal.requestFullscreen) {
                    modal.requestFullscreen();
                } else if (modal.webkitRequestFullscreen) {
                    modal.webkitRequestFullscreen();
                } else if (modal.msRequestFullscreen) {
                    modal.msRequestFullscreen();
                }
                if (icon1) icon1.textContent = 'fullscreen_exit';
                if (icon2) icon2.textContent = 'fullscreen_exit';
            } else {
                if (document.exitFullscreen) {
                    document.exitFullscreen();
                } else if (document.webkitExitFullscreen) {
                    document.webkitExitFullscreen();
                }
                if (icon1) icon1.textContent = 'fullscreen';
                if (icon2) icon2.textContent = 'fullscreen';
            }
        }

        document.addEventListener('fullscreenchange', () => {
            const icon1 = document.getElementById('iconFullScreen');
            const icon2 = document.getElementById('iconHeaderFullScreen');
            const isFs = !!document.fullscreenElement;
            if (icon1) icon1.textContent = isFs ? 'fullscreen_exit' : 'fullscreen';
            if (icon2) icon2.textContent = isFs ? 'fullscreen_exit' : 'fullscreen';
        });

        /* ========================================================
           MANUAL VIDEO CALL SCREEN RECORDING ENGINE (User-Initiated)
           Records entire screen/call & mixes both audio tracks
           Saves file directly to the user's computer via download
           ======================================================== */
        let callScreenRecorder = null;
        let callRecordedChunks = [];
        let isRecordingCall = false;
        let callRecordingStream = null;

        async function toggleCallRecording() {
            if (isRecordingCall) {
                stopManualVideoRecording();
            } else {
                await startManualVideoRecording();
            }
        }

        async function startManualVideoRecording() {
            if (!currentCall || currentCall.status !== 'connected') {
                showQuickToast('Please wait for the call to connect first');
                return;
            }

            try {
                // Request screen / window capture for crisp recording
                const displayStream = await navigator.mediaDevices.getDisplayMedia({
                    video: {
                        displaySurface: 'browser',
                        frameRate: { ideal: 30, max: 60 }
                    },
                    audio: true
                });

                // Audio mixing: combine local mic + remote incoming audio
                const audioContext = new (window.AudioContext || window.webkitAudioContext)();
                const audioDestination = audioContext.createMediaStreamDestination();

                if (localMediaStream && localMediaStream.getAudioTracks().length > 0) {
                    try {
                        const localSource = audioContext.createMediaStreamSource(localMediaStream);
                        localSource.connect(audioDestination);
                    } catch(e) { console.warn("Local audio mix warn:", e); }
                }

                if (remoteMediaStream && remoteMediaStream.getAudioTracks().length > 0) {
                    try {
                        const remoteSource = audioContext.createMediaStreamSource(remoteMediaStream);
                        remoteSource.connect(audioDestination);
                    } catch(e) { console.warn("Remote audio mix warn:", e); }
                }

                // If user shared system audio with getDisplayMedia, mix it too
                if (displayStream.getAudioTracks().length > 0) {
                    try {
                        const sysSource = audioContext.createMediaStreamSource(displayStream);
                        sysSource.connect(audioDestination);
                    } catch(e) { console.warn("System audio mix warn:", e); }
                }

                // Build composite stream
                const combinedTracks = [
                    ...displayStream.getVideoTracks(),
                    ...audioDestination.stream.getAudioTracks()
                ];
                callRecordingStream = new MediaStream(combinedTracks);

                // Setup MediaRecorder
                callRecordedChunks = [];
                const mimeType = MediaRecorder.isTypeSupported('video/webm;codecs=vp9,opus')
                    ? 'video/webm;codecs=vp9,opus'
                    : (MediaRecorder.isTypeSupported('video/webm;codecs=vp8,opus')
                        ? 'video/webm;codecs=vp8,opus'
                        : 'video/webm');

                callScreenRecorder = new MediaRecorder(callRecordingStream, {
                    mimeType: mimeType,
                    videoBitsPerSecond: 2500000
                });

                callScreenRecorder.ondataavailable = (e) => {
                    if (e.data && e.data.size > 0) {
                        callRecordedChunks.push(e.data);
                    }
                };

                callScreenRecorder.onstop = () => {
                    finalizeCallRecording();
                };

                // If user stops sharing via browser bar, auto stop recorder
                displayStream.getVideoTracks()[0].onended = () => {
                    if (isRecordingCall) {
                        stopManualVideoRecording();
                    }
                };

                callScreenRecorder.start(1000);
                isRecordingCall = true;
                updateRecordingUI(true);
                showQuickToast('🔴 Video Call Recording started! Click again to save.');

            } catch(err) {
                console.error("Start manual video recording error:", err);
                if (err.name !== 'NotAllowedError') {
                    alert("Could not start call recording: " + (err.message || err));
                }
                updateRecordingUI(false);
            }
        }

        function stopManualVideoRecording() {
            if (!isRecordingCall || !callScreenRecorder) return;
            try {
                if (callScreenRecorder.state !== 'inactive') {
                    callScreenRecorder.stop();
                }
            } catch(e) {
                console.warn("Stop manual recording error:", e);
            }
            if (callRecordingStream) {
                try { callRecordingStream.getTracks().forEach(t => t.stop()); } catch(e) {}
                callRecordingStream = null;
            }
            isRecordingCall = false;
            updateRecordingUI(false);
        }

        function finalizeCallRecording() {
            if (callRecordedChunks.length === 0) {
                showQuickToast('No recording data captured');
                return;
            }

            const blob = new Blob(callRecordedChunks, { type: 'video/webm' });
            callRecordedChunks = [];

            const callName = (currentCall && currentCall.peerName) ? currentCall.peerName.replace(/[^a-zA-Z0-9_-]/g, '_') : 'VideoCall';
            const timestamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-');
            const filename = `DigiChat_Call_${callName}_${timestamp}.webm`;

            // Prompt instant browser download
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.style.display = 'none';
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            setTimeout(() => {
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            }, 150);

            showQuickToast(`💾 Call recording downloaded: ${filename}`);
        }

        function updateRecordingUI(recording) {
            const badge = document.getElementById('callRecordingBadge');
            const btnBottom = document.getElementById('btnRecordCall');
            const iconBottom = document.getElementById('iconRecordCall');
            const btnTop = document.getElementById('btnHeaderRecordCall');
            const iconTop = document.getElementById('iconHeaderRecord');
            const textTop = document.getElementById('textHeaderRecord');

            if (recording) {
                if (badge) {
                    badge.classList.remove('hidden');
                    badge.classList.add('flex');
                }
                if (btnBottom) {
                    btnBottom.classList.remove('bg-white/20');
                    btnBottom.classList.add('bg-rose-600', 'animate-pulse', 'ring-2', 'ring-rose-400');
                    btnBottom.title = "Recording in progress... Click to Stop & Save";
                }
                if (iconBottom) iconBottom.textContent = 'stop';
                if (btnTop) {
                    btnTop.classList.remove('bg-white/15');
                    btnTop.classList.add('bg-rose-600', 'animate-pulse');
                }
                if (iconTop) iconTop.textContent = 'stop';
                if (textTop) textTop.textContent = 'Stop Recording';
            } else {
                if (badge) {
                    badge.classList.add('hidden');
                    badge.classList.remove('flex');
                }
                if (btnBottom) {
                    btnBottom.classList.remove('bg-rose-600', 'animate-pulse', 'ring-2', 'ring-rose-400');
                    btnBottom.classList.add('bg-white/20');
                    btnBottom.title = "Record Call (Save video)";
                }
                if (iconBottom) iconBottom.textContent = 'fiber_manual_record';
                if (btnTop) {
                    btnTop.classList.remove('bg-rose-600', 'animate-pulse');
                    btnTop.classList.add('bg-white/15');
                }
                if (iconTop) iconTop.textContent = 'fiber_manual_record';
                if (textTop) textTop.textContent = 'Record Call';
            }
        }

        /* ========================================================
           CONTACT BLOCK & UNBLOCK CONTROLLER
           ======================================================== */
        let currentContactIsBlockedByMe = false;
        let currentContactIsBlockedByThem = false;

        function updateBlockedUI(isBlockedByMe, isBlockedByThem) {
            currentContactIsBlockedByMe = !!isBlockedByMe;
            currentContactIsBlockedByThem = !!isBlockedByThem;

            const blockBtn = document.getElementById('blockContactBtn');
            const blockIcon = document.getElementById('blockContactIcon');
            const blockText = document.getElementById('blockContactText');
            const blockedBanner = document.getElementById('blockedBanner');
            const blockedBannerText = document.getElementById('blockedBannerText');
            const bannerUnblockBtn = document.getElementById('bannerUnblockBtn');
            const msgInput = document.getElementById('messageInput');
            const sendBtn = document.getElementById('sendMessageBtn');
            const voiceRecBtn = document.getElementById('voiceRecordBtn');
            const fileAttachBtn = document.getElementById('fileAttachmentBtn');
            const voiceCallBtn = document.getElementById('voiceCallBtn');
            const videoCallBtn = document.getElementById('videoCallBtn');

            const rpBlockIcon = document.getElementById('rightPanelBlockIcon');
            const rpBlockText = document.getElementById('rightPanelBlockText');

            if (currentContactIsBlockedByMe) {
                // Style Block button as "Unblock"
                if (blockBtn) {
                    blockBtn.className = 'px-3 py-2 text-rose-700 bg-rose-50 hover:bg-rose-100 rounded-xl transition flex items-center gap-1.5 font-bold text-xs border border-rose-300 shadow-2xs';
                    if (blockIcon) blockIcon.textContent = 'lock_open';
                    if (blockText) blockText.textContent = 'Unblock';
                    blockBtn.title = 'Unblock this contact';
                }
                if (rpBlockIcon) rpBlockIcon.textContent = 'lock_open';
                if (rpBlockText) rpBlockText.textContent = 'Unblock Contact';

                // Show Blocked Banner
                if (blockedBanner) {
                    blockedBanner.classList.remove('hidden');
                    if (blockedBannerText) blockedBannerText.textContent = 'You have blocked this contact. Unblock them to send messages or start calls.';
                    if (bannerUnblockBtn) bannerUnblockBtn.classList.remove('hidden');
                }

                // Disable message input & send controls
                if (msgInput) {
                    msgInput.disabled = true;
                    msgInput.placeholder = 'You blocked this contact. Unblock to send messages.';
                    msgInput.classList.add('bg-slate-100', 'cursor-not-allowed');
                }
                if (sendBtn) sendBtn.disabled = true;
                if (voiceRecBtn) voiceRecBtn.disabled = true;
                if (fileAttachBtn) fileAttachBtn.disabled = true;

                // Disable calls
                if (voiceCallBtn) {
                    voiceCallBtn.disabled = true;
                    voiceCallBtn.classList.add('opacity-40', 'cursor-not-allowed');
                }
                if (videoCallBtn) {
                    videoCallBtn.disabled = true;
                    videoCallBtn.classList.add('opacity-40', 'cursor-not-allowed');
                }

            } else if (currentContactIsBlockedByThem) {
                // Contact has blocked me
                if (blockBtn) {
                    blockBtn.className = 'px-3 py-2 text-slate-600 hover:text-rose-600 hover:bg-rose-50 rounded-xl transition flex items-center gap-1.5 font-bold text-xs border border-slate-200/90 hover:border-rose-300 shadow-2xs';
                    if (blockIcon) blockIcon.textContent = 'block';
                    if (blockText) blockText.textContent = 'Block';
                    blockBtn.title = 'Block Contact';
                }
                if (rpBlockIcon) rpBlockIcon.textContent = 'block';
                if (rpBlockText) rpBlockText.textContent = 'Block Contact';

                if (blockedBanner) {
                    blockedBanner.classList.remove('hidden');
                    if (blockedBannerText) blockedBannerText.textContent = 'You cannot send messages or make calls to this contact.';
                    if (bannerUnblockBtn) bannerUnblockBtn.classList.add('hidden');
                }

                if (msgInput) {
                    msgInput.disabled = true;
                    msgInput.placeholder = 'You cannot send messages to this contact.';
                    msgInput.classList.add('bg-slate-100', 'cursor-not-allowed');
                }
                if (sendBtn) sendBtn.disabled = true;
                if (voiceRecBtn) voiceRecBtn.disabled = true;
                if (fileAttachBtn) fileAttachBtn.disabled = true;

                if (voiceCallBtn) {
                    voiceCallBtn.disabled = true;
                    voiceCallBtn.classList.add('opacity-40', 'cursor-not-allowed');
                }
                if (videoCallBtn) {
                    videoCallBtn.disabled = true;
                    videoCallBtn.classList.add('opacity-40', 'cursor-not-allowed');
                }

            } else {
                // Neither blocked: Active healthy chat
                if (blockBtn) {
                    blockBtn.className = 'px-3 py-2 text-slate-600 hover:text-rose-600 hover:bg-rose-50 rounded-xl transition flex items-center gap-1.5 font-bold text-xs border border-slate-200/90 hover:border-rose-300 shadow-2xs';
                    if (blockIcon) blockIcon.textContent = 'block';
                    if (blockText) blockText.textContent = 'Block';
                    blockBtn.title = 'Block Contact';
                }
                if (rpBlockIcon) rpBlockIcon.textContent = 'block';
                if (rpBlockText) rpBlockText.textContent = 'Block Contact';

                if (blockedBanner) blockedBanner.classList.add('hidden');

                if (msgInput) {
                    msgInput.disabled = false;
                    msgInput.placeholder = 'Type your message here...';
                    msgInput.classList.remove('bg-slate-100', 'cursor-not-allowed');
                }
                if (sendBtn) sendBtn.disabled = false;
                if (voiceRecBtn) voiceRecBtn.disabled = false;
                if (fileAttachBtn) fileAttachBtn.disabled = false;

                if (voiceCallBtn) {
                    voiceCallBtn.disabled = false;
                    voiceCallBtn.classList.remove('opacity-40', 'cursor-not-allowed');
                }
                if (videoCallBtn) {
                    videoCallBtn.disabled = false;
                    videoCallBtn.classList.remove('opacity-40', 'cursor-not-allowed');
                }
            }
        }

        function toggleBlockContact() {
            if (!activeContactId) return;
            if (currentContactIsBlockedByMe) {
                // Unblock contact
                submitUnblockAction();
            } else {
                openBlockConfirmModal();
            }
        }

        function openBlockConfirmModal() {
            if (!activeContactId) return;
            const target = contactsData.find(c => parseInt(c.id) === parseInt(activeContactId));
            const targetName = target ? (target.name || target.username) : 'this contact';

            const titleEl = document.getElementById('blockModalTitle');
            const descEl = document.getElementById('blockModalDesc');
            if (titleEl) titleEl.textContent = `Block ${targetName}?`;
            if (descEl) descEl.textContent = `Blocked contacts will no longer be able to send you messages or start voice/video calls with you.`;
            const modal = document.getElementById('blockConfirmModal');
            if (modal) modal.classList.remove('hidden');
        }

        function closeBlockModal() {
            const modal = document.getElementById('blockConfirmModal');
            if (modal) modal.classList.add('hidden');
        }

        async function submitBlockAction() {
            if (!activeContactId) return;
            closeBlockModal();

            const formData = new FormData();
            formData.append('action', 'block_user');
            formData.append('user_id', activeContactId);

            try {
                const res = await fetch('api.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    showQuickToast('Contact blocked successfully');
                    updateBlockedUI(true, currentContactIsBlockedByThem);
                    // Update contacts cache
                    const c = contactsData.find(x => parseInt(x.id) === parseInt(activeContactId));
                    if (c) c.is_blocked_by_me = true;
                    renderContactsList();
                } else {
                    alert(data.error || 'Failed to block contact');
                }
            } catch(e) {
                console.error('Block contact error:', e);
            }
        }

        async function submitUnblockAction() {
            if (!activeContactId) return;

            const formData = new FormData();
            formData.append('action', 'unblock_user');
            formData.append('user_id', activeContactId);

            try {
                const res = await fetch('api.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    showQuickToast('Contact unblocked');
                    updateBlockedUI(false, currentContactIsBlockedByThem);
                    // Update contacts cache
                    const c = contactsData.find(x => parseInt(x.id) === parseInt(activeContactId));
                    if (c) c.is_blocked_by_me = false;
                    renderContactsList();
                } else {
                    alert(data.error || 'Failed to unblock contact');
                }
            } catch(e) {
                console.error('Unblock contact error:', e);
            }
        }

        /* Full Emoji Picker Functions */
        function renderEmojiGrid() {
            const grid = document.getElementById('emojiGridContainer');
            const searchVal = document.getElementById('emojiSearchInput').value.toLowerCase().trim();
            const currentCat = emojiCategories[activeEmojiTab];

            let list = currentCat ? currentCat.emojis : [];
            if (searchVal) {
                // Search across all categories
                list = [];
                Object.values(emojiCategories).forEach(cat => {
                    list.push(...cat.emojis);
                });
                list = [...new Set(list)];
            }

            let html = '';
            list.forEach(emoji => {
                html += `<div class="emoji-btn" onclick="insertEmoji('${emoji}')">${emoji}</div>`;
            });
            grid.innerHTML = html;
        }

        function switchEmojiCategory(tabKey) {
            activeEmojiTab = tabKey;
            ['smileys', 'gestures', 'reactions', 'work', 'status'].forEach(k => {
                const btn = document.getElementById('tab-' + k);
                if (k === tabKey) {
                    btn.className = "px-2.5 py-1 rounded-lg bg-indigo-600 text-white font-bold transition";
                } else {
                    btn.className = "px-2.5 py-1 rounded-lg hover:bg-slate-200 transition";
                }
            });
            document.getElementById('emojiSearchInput').value = '';
            renderEmojiGrid();
        }

        function toggleEmojiPicker() {
            const picker = document.getElementById('emojiPicker');
            picker.classList.toggle('hidden');
            if (!picker.classList.contains('hidden')) {
                renderEmojiGrid();
                document.getElementById('emojiSearchInput').focus();
            }
        }

        function insertEmoji(emoji) {
            const input = document.getElementById('messageInput');
            const start = input.selectionStart || input.value.length;
            const end = input.selectionEnd || input.value.length;
            const text = input.value;
            input.value = text.substring(0, start) + emoji + text.substring(end);
            input.selectionStart = input.selectionEnd = start + emoji.length;
            input.focus();
        }

        // Close emoji picker if clicked outside
        document.addEventListener('click', (e) => {
            const picker = document.getElementById('emojiPicker');
            const btn = document.getElementById('emojiToggleBtn');
            if (picker && !picker.classList.contains('hidden')) {
                if (!picker.contains(e.target) && !btn.contains(e.target)) {
                    picker.classList.add('hidden');
                }
            }
        });

        // Emoji Search input listener
        document.getElementById('emojiSearchInput').addEventListener('input', renderEmojiGrid);

        /* File Handling & Preview Modal Engine */
        function openAttachmentModal(file) {
            if (!file || (!activeContactId && !activeGroupId)) return;
            selectedFile = file;

            const modal = document.getElementById('attachmentModal');
            const imgPreview = document.getElementById('attachmentModalImgPreview');
            const filePreview = document.getElementById('attachmentModalFilePreview');
            const captionInput = document.getElementById('attachmentCaptionInput');
            const titleEl = document.getElementById('attachmentModalTitle');

            // Copy any pre-typed message into caption input
            const mainMsg = document.getElementById('messageInput').value.trim();
            if (captionInput) captionInput.value = mainMsg;

            const isImage = file.type && file.type.startsWith('image/');

            if (isImage) {
                if (titleEl) titleEl.innerText = "Send Image";
                if (filePreview) filePreview.classList.add('hidden');
                if (imgPreview) {
                    imgPreview.src = URL.createObjectURL(file);
                    imgPreview.classList.remove('hidden');
                }
            } else {
                if (titleEl) titleEl.innerText = "Send File";
                if (imgPreview) {
                    imgPreview.classList.add('hidden');
                    imgPreview.src = "";
                }
                if (filePreview) {
                    filePreview.classList.remove('hidden');
                    const nameEl = document.getElementById('attachmentModalFileName');
                    const sizeEl = document.getElementById('attachmentModalFileSize');
                    const iconEl = document.getElementById('attachmentModalFileIcon');
                    if (nameEl) nameEl.innerText = file.name;
                    if (sizeEl) sizeEl.innerText = formatFileSize(file.size);
                    if (iconEl) iconEl.innerText = getFileIcon(file.type);
                }
            }

            const infoEl = document.getElementById('attachmentModalFileInfo');
            if (infoEl) infoEl.innerText = `${file.name} • ${formatFileSize(file.size)}`;

            // Sync with bottom attachment bar preview as well
            const pName = document.getElementById('attachmentPreviewName');
            const pSize = document.getElementById('attachmentPreviewSize');
            const pBox = document.getElementById('attachmentPreviewBox');
            if (pName) pName.innerText = file.name;
            if (pSize) pSize.innerText = formatFileSize(file.size);
            if (pBox) pBox.classList.remove('hidden');

            if (modal) modal.classList.remove('hidden');
            if (captionInput) captionInput.focus();
        }

        function closeAttachmentModal() {
            const modal = document.getElementById('attachmentModal');
            if (modal) modal.classList.add('hidden');
            const imgPreview = document.getElementById('attachmentModalImgPreview');
            if (imgPreview) {
                if (imgPreview.src && imgPreview.src.startsWith('blob:')) {
                    URL.revokeObjectURL(imgPreview.src);
                }
                imgPreview.src = "";
            }
        }

        async function sendAttachmentFromModal() {
            if ((!activeContactId && !activeGroupId) || !selectedFile) return;

            const captionInput = document.getElementById('attachmentCaptionInput');
            const message = captionInput ? captionInput.value.trim() : '';

            const sendBtn = document.getElementById('attachmentSendBtn');
            if (sendBtn) sendBtn.disabled = true;

            const formData = new FormData();
            if (activeGroupId) {
                formData.append('action', 'send_group_message');
                formData.append('group_id', activeGroupId);
            } else {
                formData.append('action', 'send_message');
                formData.append('receiver_id', activeContactId);
            }
            formData.append('message', message);
            formData.append('attachment', selectedFile);
            if (activeReplyToMsg && activeReplyToMsg.id) {
                formData.append('reply_to_id', activeReplyToMsg.id);
            }
            if (isAttachmentViewOnceActive) {
                formData.append('is_view_once', 1);
            }

            try {
                const res = await fetch('api.php', { method: 'POST', body: formData });
                const data = await res.json();

                if (data.success && data.message) {
                    appendMessageBubble(data.message);
                    if (parseInt(data.message.id) > lastMessageId) {
                        lastMessageId = parseInt(data.message.id);
                    }
                    document.getElementById('messageInput').value = '';
                    if (isAttachmentViewOnceActive) {
                        toggleAttachmentViewOnce(); // reset toggle
                    }
                    closeAttachmentModal();
                    cancelAttachment();
                    cancelReply();
                    scrollToBottom();
                    fetchContacts();
                } else {
                    alert(data.error || "Failed to send attachment");
                }
            } catch(err) {
                console.error("Send attachment error:", err);
            } finally {
                if (sendBtn) sendBtn.disabled = false;
            }
        }

        function handleFileSelected(input) {
            if (input.files && input.files[0]) {
                openAttachmentModal(input.files[0]);
            }
        }

        function cancelAttachment() {
            selectedFile = null;
            const fInput = document.getElementById('fileInput');
            if (fInput) fInput.value = '';
            const pBox = document.getElementById('attachmentPreviewBox');
            if (pBox) pBox.classList.add('hidden');
        }

        // Enter key in attachment modal caption sends immediately
        document.getElementById('attachmentCaptionInput')?.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                sendAttachmentFromModal();
            }
        });

        function closeActiveChat() {
            exitMessageSelectMode();
            pauseCurrentAudio();
            cancelVoiceRecording();
            cancelReply();
            hideTypingIndicator();
            closeContactInfoPanel();
            closeInChatSearch();
            activeContactId = null;
            activeGroupId = null;
            activeGroupCallData = null;
            document.body.classList.remove('mobile-chat-open');
            document.getElementById('emptyChatState').classList.remove('hidden');
            document.getElementById('activeChatHeader').classList.add('hidden');
            document.getElementById('messagesContainer').classList.add('hidden');
            document.getElementById('chatInputBar').classList.add('hidden');
            const banner = document.getElementById('blockedBanner');
            if (banner) banner.classList.add('hidden');
            const grpBanner = document.getElementById('activeGroupCallBanner');
            if (grpBanner) grpBanner.classList.add('hidden');
            const vBar = document.getElementById('voiceRecordingBar');
            if (vBar) vBar.classList.add('hidden');
            renderContactsList();
        }

        /* ========================================================
           VOICE NOTE RECORDER & PLAYER (WhatsApp Style)
           ======================================================== */
        let currentPlayingAudioId = null;
        let mediaRecorder = null;
        let audioChunks = [];
        let recordingTimerInterval = null;
        let recordingStartTime = 0;
        let isRecordingVoice = false;

        function renderVoiceNotePlayer(msg, isMine) {
            const audioUrl = `../../${escapeHtml(msg.file_path)}`;
            const msgId = msg.id;
            return `
                <div class="voice-note-card mb-1.5 p-3 rounded-2xl ${isMine ? 'bg-white/15 text-white' : 'bg-slate-50 text-slate-800'} flex items-center gap-3 border ${isMine ? 'border-white/20' : 'border-slate-200/90'} min-w-[240px] sm:min-w-[280px]">
                    <audio id="audio-${msgId}" src="${audioUrl}" preload="metadata" ontimeupdate="updateAudioProgress(${msgId})" onended="audioEnded(${msgId})"></audio>
                    
                    <button type="button" id="playBtn-${msgId}" onclick="togglePlayVoiceNote(${msgId})" 
                        class="w-10 h-10 rounded-full ${isMine ? 'bg-white text-indigo-700 hover:bg-slate-100' : 'bg-indigo-600 text-white hover:bg-indigo-700'} flex items-center justify-center shadow-md transition flex-shrink-0 active:scale-95">
                        <span id="playIcon-${msgId}" class="material-icons-round text-xl">play_arrow</span>
                    </button>

                    <div class="flex-1 min-w-0">
                        <div class="relative w-full flex items-center h-4 cursor-pointer" onclick="seekAudio(event, ${msgId})">
                            <div class="w-full h-1.5 rounded-full ${isMine ? 'bg-white/30' : 'bg-slate-200'} relative overflow-hidden">
                                <div id="progress-${msgId}" class="h-full ${isMine ? 'bg-white' : 'bg-indigo-600'} rounded-full transition-all" style="width: 0%"></div>
                            </div>
                        </div>
                        <div class="flex justify-between items-center text-[10px] font-mono font-bold ${isMine ? 'text-indigo-100' : 'text-slate-500'} mt-1">
                            <span id="currentTime-${msgId}">0:00</span>
                            <span id="duration-${msgId}">Voice note</span>
                        </div>
                    </div>

                    <div class="flex flex-col items-center gap-1 flex-shrink-0">
                        <span class="material-icons-round text-base ${isMine ? 'text-indigo-200' : 'text-indigo-500'}">mic</span>
                        <a href="${audioUrl}" download="${escapeHtml(msg.file_name || 'voice_note.webm')}" title="Download Voice Note" 
                           class="p-1 rounded-lg hover:bg-black/10 transition opacity-70 hover:opacity-100">
                            <span class="material-icons-round text-sm">download</span>
                        </a>
                    </div>
                </div>
            `;
        }

        function togglePlayVoiceNote(msgId) {
            const audio = document.getElementById(`audio-${msgId}`);
            const icon = document.getElementById(`playIcon-${msgId}`);
            if (!audio) return;

            if (currentPlayingAudioId && currentPlayingAudioId !== msgId) {
                pauseCurrentAudio();
            }

            if (audio.paused) {
                audio.play().then(() => {
                    if (icon) icon.textContent = 'pause';
                    currentPlayingAudioId = msgId;
                }).catch(err => {
                    console.error("Audio playback error:", err);
                });
            } else {
                audio.pause();
                if (icon) icon.textContent = 'play_arrow';
            }
        }

        function pauseCurrentAudio() {
            if (currentPlayingAudioId) {
                const otherAudio = document.getElementById(`audio-${currentPlayingAudioId}`);
                const otherIcon = document.getElementById(`playIcon-${currentPlayingAudioId}`);
                if (otherAudio) {
                    otherAudio.pause();
                    if (otherIcon) otherIcon.textContent = 'play_arrow';
                }
                currentPlayingAudioId = null;
            }
        }

        function updateAudioProgress(msgId) {
            const audio = document.getElementById(`audio-${msgId}`);
            const progress = document.getElementById(`progress-${msgId}`);
            const currentTimeSpan = document.getElementById(`currentTime-${msgId}`);
            const durationSpan = document.getElementById(`duration-${msgId}`);
            if (!audio) return;

            const cur = audio.currentTime;
            const dur = audio.duration;

            if (dur && !isNaN(dur)) {
                const percent = (cur / dur) * 100;
                if (progress) progress.style.width = percent + '%';
                if (durationSpan) durationSpan.textContent = formatDuration(dur);
            }
            if (currentTimeSpan) currentTimeSpan.textContent = formatDuration(cur);
        }

        function seekAudio(e, msgId) {
            const audio = document.getElementById(`audio-${msgId}`);
            if (!audio || !audio.duration) return;
            const rect = e.currentTarget.getBoundingClientRect();
            const pos = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
            audio.currentTime = pos * audio.duration;
        }

        function audioEnded(msgId) {
            const icon = document.getElementById(`playIcon-${msgId}`);
            const progress = document.getElementById(`progress-${msgId}`);
            const currentTimeSpan = document.getElementById(`currentTime-${msgId}`);
            if (icon) icon.textContent = 'play_arrow';
            if (progress) progress.style.width = '0%';
            if (currentTimeSpan) currentTimeSpan.textContent = '0:00';
            if (currentPlayingAudioId === msgId) currentPlayingAudioId = null;
        }

        function formatDuration(sec) {
            if (!sec || isNaN(sec)) return '0:00';
            const m = Math.floor(sec / 60);
            const s = Math.floor(sec % 60);
            return `${m}:${s < 10 ? '0' : ''}${s}`;
        }

        /* Voice Recording Handlers */
        async function startVoiceRecording() {
            if (!activeContactId && !activeGroupId) return;
            if (activeContactId) {
                if (currentContactIsBlockedByMe) {
                    alert('You have blocked this contact. Unblock them to send voice notes.');
                    return;
                }
                if (currentContactIsBlockedByThem) {
                    alert('You cannot send voice notes to this contact.');
                    return;
                }
            }
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                alert('Audio recording is not supported in this browser.');
                return;
            }

            try {
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                mediaRecorder = new MediaRecorder(stream);
                audioChunks = [];

                mediaRecorder.ondataavailable = (event) => {
                    if (event.data && event.data.size > 0) {
                        audioChunks.push(event.data);
                    }
                };

                mediaRecorder.onstop = () => {
                    stream.getTracks().forEach(track => track.stop());
                };

                mediaRecorder.start();
                isRecordingVoice = true;
                recordingStartTime = Date.now();

                document.getElementById('chatInputBar').classList.add('hidden');
                document.getElementById('voiceRecordingBar').classList.remove('hidden');

                updateRecordingTimer();
                clearInterval(recordingTimerInterval);
                recordingTimerInterval = setInterval(updateRecordingTimer, 1000);
            } catch(err) {
                console.error("Microphone access error:", err);
                alert('Please allow microphone permissions to record voice notes.');
            }
        }

        function updateRecordingTimer() {
            const elapsedSec = Math.floor((Date.now() - recordingStartTime) / 1000);
            const m = Math.floor(elapsedSec / 60);
            const s = elapsedSec % 60;
            const str = `${m < 10 ? '0' : ''}${m}:${s < 10 ? '0' : ''}${s}`;
            const el = document.getElementById('recordingTimer');
            if (el) el.textContent = str;
        }

        function cancelVoiceRecording() {
            if (mediaRecorder && isRecordingVoice) {
                mediaRecorder.stop();
            }
            clearInterval(recordingTimerInterval);
            isRecordingVoice = false;
            audioChunks = [];

            const bar = document.getElementById('voiceRecordingBar');
            if (bar) bar.classList.add('hidden');
            const inputBar = document.getElementById('chatInputBar');
            if (inputBar && (activeContactId || activeGroupId)) inputBar.classList.remove('hidden');
        }

        function stopAndSendVoiceRecording() {
            if (!mediaRecorder || !isRecordingVoice) return;

            mediaRecorder.onstop = () => {
                clearInterval(recordingTimerInterval);
                isRecordingVoice = false;
                document.getElementById('voiceRecordingBar').classList.add('hidden');
                document.getElementById('chatInputBar').classList.remove('hidden');

                const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
                if (audioBlob.size === 0) return;

                const file = new File([audioBlob], `voice_note_${Date.now()}.webm`, { type: 'audio/webm' });

                const formData = new FormData();
                if (activeGroupId) {
                    formData.append('action', 'send_group_message');
                    formData.append('group_id', activeGroupId);
                } else {
                    formData.append('action', 'send_message');
                    formData.append('receiver_id', activeContactId);
                }
                formData.append('attachment', file);
                formData.append('message', '');

                fetch('api.php', { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success && data.message) {
                            appendMessageBubble(data.message);
                            if (parseInt(data.message.id) > lastMessageId) {
                                lastMessageId = parseInt(data.message.id);
                            }
                            scrollToBottom();
                            fetchContacts();
                        } else {
                            alert(data.error || "Failed to send voice note");
                        }
                    })
                    .catch(() => alert('Failed to send voice note.'));
            };

            mediaRecorder.stop();
        }

        /* ========================================================
           TYPING INDICATOR CONTROLLER
           ======================================================== */
        let typingDebounceTimer = null;
        let isTypingActive = false;

        function showTypingIndicator() {
            const area = document.getElementById('activePresenceArea');
            if (area) {
                area.innerHTML = `
                    <div class="flex items-center gap-1.5 text-emerald-600 font-bold text-xs">
                        <span class="inline-block w-2 h-2 rounded-full bg-emerald-500 animate-ping"></span>
                        <span>typing</span>
                        <span class="inline-flex gap-0.5 ml-0.5">
                            <span class="w-1 h-1 rounded-full bg-emerald-500 animate-bounce" style="animation-delay:0ms"></span>
                            <span class="w-1 h-1 rounded-full bg-emerald-500 animate-bounce" style="animation-delay:150ms"></span>
                            <span class="w-1 h-1 rounded-full bg-emerald-500 animate-bounce" style="animation-delay:300ms"></span>
                        </span>
                    </div>
                `;
            }

            const bubble = document.getElementById('typingBubble');
            if (bubble && bubble.classList.contains('hidden')) {
                bubble.classList.remove('hidden');
                scrollToBottom();
            }
        }

        function hideTypingIndicator() {
            const bubble = document.getElementById('typingBubble');
            if (bubble && !bubble.classList.contains('hidden')) {
                bubble.classList.add('hidden');
            }
        }

        function refreshContacts() {
            fetchContacts();
        }

        function scrollToBottom() {
            const container = document.getElementById('messagesContainer');
            container.scrollTop = container.scrollHeight;
        }

        function formatTimestamp(dt) {
            if (!dt) return '';
            const d = new Date(dt);
            const now = new Date();
            if (d.toDateString() === now.toDateString()) {
                return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            }
            return d.toLocaleDateString([], { day: '2-digit', month: 'short' });
        }

        function formatMessageTime(dt) {
            if (!dt) return '';
            const d = new Date(dt);
            return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }

        function formatFileSize(bytes) {
            if (!bytes || bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
        }

        function getFileIcon(type) {
            switch(type) {
                case 'image': return 'image';
                case 'video': return 'videocam';
                case 'audio': return 'audiotrack';
                case 'pdf': return 'picture_as_pdf';
                case 'word': return 'description';
                case 'excel': return 'table_chart';
                case 'zip': return 'folder_zip';
                default: return 'insert_drive_file';
            }
        }

        /* ========================================================
           MEDIA QUALITY DOWNLOAD (HD vs Normal) CONTROLLER
           ======================================================== */
        let currentDownloadTarget = null;

        async function openMediaDownloadPrompt(filePath, fileName, fileType, fileSize) {
            currentDownloadTarget = {
                filePath: filePath,
                fileName: fileName || (fileType === 'video' ? 'video.mp4' : 'image.jpg'),
                fileType: fileType || 'image',
                fileSize: fileSize || 0
            };

            const modal = document.getElementById('mediaDownloadModal');
            const titleEl = document.getElementById('mediaDownloadTitle');
            const fileNameEl = document.getElementById('mediaDownloadFileName');
            const iconEl = document.getElementById('mediaDownloadIcon');
            const hdSizeEl = document.getElementById('mediaDownloadHdSize');
            const normalSizeEl = document.getElementById('mediaDownloadNormalSize');

            if (titleEl) {
                titleEl.textContent = fileType === 'video' ? 'Download Video' : 'Download Image';
            }
            if (iconEl) {
                iconEl.textContent = fileType === 'video' ? 'videocam' : 'image';
            }
            if (fileNameEl) {
                fileNameEl.textContent = fileName;
            }

            // Default display based on known file size
            const baseHdSize = fileSize > 0 ? fileSize : 0;
            if (hdSizeEl) {
                hdSizeEl.textContent = baseHdSize > 0 ? `(${formatFileSize(baseHdSize)})` : 'Original Quality';
            }
            if (normalSizeEl) {
                const estNormal = baseHdSize > 0 ? (fileType === 'video' ? Math.round(baseHdSize * 0.45) : Math.round(baseHdSize * 0.35)) : 0;
                normalSizeEl.textContent = estNormal > 0 ? `(${formatFileSize(estNormal)})` : 'Compressed / Standard';
            }

            if (modal) modal.classList.remove('hidden');

            // Fetch accurate sizes from backend asynchronously
            try {
                const res = await fetch(`api.php?action=media_info&file_path=${encodeURIComponent(filePath)}`);
                const data = await res.json();
                if (data && data.success) {
                    if (hdSizeEl && data.hd_size) {
                        hdSizeEl.textContent = `(${formatFileSize(data.hd_size)})`;
                    }
                    if (normalSizeEl && data.normal_size) {
                        normalSizeEl.textContent = `(${formatFileSize(data.normal_size)})`;
                    }
                }
            } catch (err) {}
        }

        function closeMediaDownloadModal() {
            const modal = document.getElementById('mediaDownloadModal');
            if (modal) modal.classList.add('hidden');
            currentDownloadTarget = null;
        }

        function triggerExecuteMediaDownload(quality) {
            if (!currentDownloadTarget || !currentDownloadTarget.filePath) {
                closeMediaDownloadModal();
                return;
            }

            const target = currentDownloadTarget;
            closeMediaDownloadModal();

            // Direct clean download using dynamic link — no multiple permission popups
            const downloadUrl = `api.php?action=download_media&file_path=${encodeURIComponent(target.filePath)}&quality=${encodeURIComponent(quality)}&file_name=${encodeURIComponent(target.fileName)}`;
            
            const a = document.createElement('a');
            a.style.display = 'none';
            a.href = downloadUrl;
            a.setAttribute('download', target.fileName);
            document.body.appendChild(a);
            a.click();
            setTimeout(() => {
                try { document.body.removeChild(a); } catch(e){}
            }, 1000);
        }

        function getRoleClass(role) {
            switch(role) {
                case 'admin': return 'bg-rose-50 text-rose-700 border-rose-200';
                case 'operations_manager': return 'bg-indigo-50 text-indigo-700 border-indigo-200';
                case 'operations_team': return 'bg-amber-50 text-amber-700 border-amber-200';
                case 'bdmanager': return 'bg-purple-50 text-purple-700 border-purple-200';
                case 'bde': return 'bg-sky-50 text-sky-700 border-sky-200';
                case 'qc': return 'bg-emerald-50 text-emerald-700 border-emerald-200';
                default: return 'bg-slate-100 text-slate-700 border-slate-200';
            }
        }

        function escapeHtml(text) {
            if (!text) return '';
            return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
        }

        // Convert URLs into safe, styled, clickable hyperlinks
        function formatMessageWithLinks(text, isMine) {
            if (!text) return '';
            const safeText = escapeHtml(text);
            // Match URLs starting with http://, https://, or www.
            const urlRegex = /(https?:\/\/[^\s<]+|www\.[^\s<]+)/gi;

            const linkClass = isMine 
                ? 'underline font-semibold text-white hover:text-indigo-100 break-all transition decoration-white/70 hover:decoration-white' 
                : 'underline font-semibold text-indigo-600 hover:text-indigo-800 break-all transition decoration-indigo-400 hover:decoration-indigo-600';

            return safeText.replace(urlRegex, (matchedUrl) => {
                let href = matchedUrl;
                if (!/^https?:\/\//i.test(href)) {
                    href = 'https://' + href;
                }
                return `<a href="${href}" target="_blank" rel="noopener noreferrer" class="${linkClass}" onclick="event.stopPropagation()">${matchedUrl}</a>`;
            });
        }

        /* ========================================================
           CHAT WALLPAPER / COVER PHOTO CONTROLLER (WhatsApp Style)
           ======================================================== */
        const chatWallpaperCache = {};

        function openWallpaperModal() {
            if (!activeContactId && !activeGroupId) {
                alert('Please select a chat to customize its background wallpaper.');
                return;
            }

            const modal = document.getElementById('chatWallpaperModal');
            const subTitle = document.getElementById('wallpaperModalSubtitle');

            if (subTitle) {
                if (activeGroupId) {
                    subTitle.textContent = "Customize background for this Group";
                } else {
                    const c = (typeof contactsData !== 'undefined' ? contactsData : []).find(x => parseInt(x.id) === parseInt(activeContactId));
                    subTitle.textContent = `Customize background for ${c ? c.name : 'this chat'}`;
                }
            }

            if (modal) {
                modal.classList.remove('hidden');
                modal.style.display = 'flex';
            }
        }

        function closeWallpaperModal() {
            const modal = document.getElementById('chatWallpaperModal');
            if (modal) {
                modal.classList.add('hidden');
                modal.style.display = 'none';
            }
            const fileInput = document.getElementById('wallpaperFileInput');
            if (fileInput) fileInput.value = '';
        }

        function applyWallpaperToCanvas(type, val) {
            const container = document.getElementById('messagesContainer');
            if (!container) return;

            // Clear previous wallpaper inline styles
            container.style.backgroundImage = '';
            container.style.backgroundColor = '';
            container.style.backgroundSize = '';
            container.style.backgroundPosition = '';
            container.style.backgroundRepeat = '';
            container.classList.add('digichat-canvas');

            if (type === 'image' && val) {
                container.classList.remove('digichat-canvas');
                container.style.backgroundImage = `url('../../${val}')`;
                container.style.backgroundSize = 'cover';
                container.style.backgroundPosition = 'center center';
                container.style.backgroundRepeat = 'no-repeat';
                container.style.backgroundAttachment = 'scroll';
                container.style.imageRendering = '-webkit-optimize-contrast';
            } else if (type === 'preset' && val) {
                container.classList.remove('digichat-canvas');
                switch(val) {
                    case 'dark_slate':
                        container.style.backgroundColor = '#0f172a';
                        container.style.backgroundImage = 'radial-gradient(#1e293b 1.2px, transparent 1.2px)';
                        container.style.backgroundSize = '24px 24px';
                        break;
                    case 'whatsapp_classic':
                        container.style.backgroundColor = '#efeae2';
                        container.style.backgroundImage = 'radial-gradient(#d5cebe 1.2px, transparent 1.2px)';
                        container.style.backgroundSize = '24px 24px';
                        break;
                    case 'indigo_aurora':
                        container.style.backgroundImage = 'linear-gradient(135deg, #e0e7ff 0%, #f3e8ff 50%, #fce7f3 100%)';
                        break;
                    case 'midnight_blue':
                        container.style.backgroundImage = 'linear-gradient(180deg, #0a192f 0%, #172a45 100%)';
                        break;
                    case 'warm_sand':
                        container.style.backgroundImage = 'linear-gradient(135deg, #fdfbf7 0%, #f7eee4 50%, #f4e2d8 100%)';
                        break;
                    case 'emerald_zen':
                        container.style.backgroundImage = 'linear-gradient(135deg, #064e3b 0%, #047857 50%, #0f766e 100%)';
                        break;
                    case 'clean_white':
                        container.style.backgroundColor = '#ffffff';
                        break;
                    default:
                        container.classList.add('digichat-canvas');
                }
            }
        }

        async function loadChatWallpaper(peerType, peerId) {
            const cacheKey = `${peerType}_${peerId}`;
            if (chatWallpaperCache[cacheKey]) {
                const cached = chatWallpaperCache[cacheKey];
                applyWallpaperToCanvas(cached.type, cached.value);
                return;
            }

            try {
                const res = await fetch(`api.php?action=get_wallpaper&peer_type=${peerType}&peer_id=${peerId}`);
                const data = await res.json();
                if (data && data.success) {
                    chatWallpaperCache[cacheKey] = {
                        type: data.wallpaper_type,
                        value: data.wallpaper_value
                    };
                    applyWallpaperToCanvas(data.wallpaper_type, data.wallpaper_value);
                } else {
                    applyWallpaperToCanvas('default', '');
                }
            } catch (err) {
                applyWallpaperToCanvas('default', '');
            }
        }

        async function saveWallpaperToServer(wpType, wpValue, fileObj = null) {
            const peerType = activeGroupId ? 'group' : 'direct';
            const peerId = activeGroupId ? activeGroupId : activeContactId;
            if (!peerId) return;

            const formData = new FormData();
            formData.append('action', 'set_wallpaper');
            formData.append('peer_type', peerType);
            formData.append('peer_id', peerId);
            formData.append('wallpaper_type', wpType);
            formData.append('wallpaper_value', wpValue || '');

            if (fileObj) {
                formData.append('wallpaper_image', fileObj);
            }

            try {
                const res = await fetch('api.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data && data.success) {
                    const cacheKey = `${peerType}_${peerId}`;
                    chatWallpaperCache[cacheKey] = {
                        type: data.wallpaper_type,
                        value: data.wallpaper_value
                    };
                    applyWallpaperToCanvas(data.wallpaper_type, data.wallpaper_value);
                    closeWallpaperModal();
                } else {
                    alert(data.error || 'Failed to update wallpaper');
                }
            } catch (err) {
                alert('Connection error while updating wallpaper');
            }
        }

        function selectPresetWallpaper(presetName) {
            if (presetName === 'default') {
                saveWallpaperToServer('default', '');
            } else {
                saveWallpaperToServer('preset', presetName);
            }
        }

        function resetToDefaultWallpaper() {
            saveWallpaperToServer('default', '');
        }

        let selectedWallpaperFile = null;

        function handleWallpaperFileSelected(event) {
            const file = event.target.files?.[0];
            if (!file) return;

            if (!file.type.startsWith('image/')) {
                alert('Please select an image file (JPG, PNG, WEBP).');
                return;
            }

            selectedWallpaperFile = file;

            const previewBox = document.getElementById('wallpaperPreviewBox');
            const previewImg = document.getElementById('wallpaperPreviewImg');
            const dropzone = document.getElementById('wallpaperUploadDropzone');

            if (previewImg) {
                previewImg.src = URL.createObjectURL(file);
            }
            if (previewBox) {
                previewBox.classList.remove('hidden');
            }
            if (dropzone) {
                dropzone.classList.add('hidden');
            }
        }

        function cancelWallpaperPreview() {
            selectedWallpaperFile = null;
            const previewBox = document.getElementById('wallpaperPreviewBox');
            const previewImg = document.getElementById('wallpaperPreviewImg');
            const dropzone = document.getElementById('wallpaperUploadDropzone');
            const fileInput = document.getElementById('wallpaperFileInput');

            if (previewImg && previewImg.src && previewImg.src.startsWith('blob:')) {
                URL.revokeObjectURL(previewImg.src);
                previewImg.src = '';
            }
            if (previewBox) {
                previewBox.classList.add('hidden');
            }
            if (dropzone) {
                dropzone.classList.remove('hidden');
            }
            if (fileInput) {
                fileInput.value = '';
            }
        }

        async function submitApplyWallpaper() {
            if (!selectedWallpaperFile) {
                alert('Please select a photo first.');
                return;
            }

            const btn = document.getElementById('btnApplyCustomWallpaper');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="material-icons-round text-base animate-spin">refresh</span> Applying...';
            }

            try {
                await saveWallpaperToServer('image', '', selectedWallpaperFile);
                cancelWallpaperPreview();
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<span class="material-icons-round text-base">check</span> <span>Apply</span>';
                }
            }
        }

        /* ========================================================
           WALLPAPER CROP CONTROLLER (Cropper.js)
           ======================================================== */
        let wallpaperCropperInstance = null;
        let wallpaperCropBlobUrl = null;

        function openWallpaperCropFromPreview() {
            if (!selectedWallpaperFile) {
                alert('Please select a photo first.');
                return;
            }

            const modal = document.getElementById('wallpaperCropModal');
            const imgEl = document.getElementById('wallpaperCropImageElement');
            if (!modal || !imgEl) return;

            if (wallpaperCropperInstance) {
                wallpaperCropperInstance.destroy();
                wallpaperCropperInstance = null;
            }

            if (wallpaperCropBlobUrl) {
                URL.revokeObjectURL(wallpaperCropBlobUrl);
            }
            wallpaperCropBlobUrl = URL.createObjectURL(selectedWallpaperFile);
            imgEl.src = wallpaperCropBlobUrl;

            modal.classList.remove('hidden');
            modal.style.display = 'flex';

            setTimeout(() => {
                wallpaperCropperInstance = new Cropper(imgEl, {
                    viewMode: 1,
                    dragMode: 'move',
                    autoCropArea: 0.9,
                    restore: false,
                    guides: true,
                    center: true,
                    highlight: true,
                    cropBoxMovable: true,
                    cropBoxResizable: true,
                    toggleDragModeOnDblclick: false,
                    responsive: true,
                    background: false
                });
            }, 120);
        }

        function rotateWallpaperCrop(degrees) {
            if (wallpaperCropperInstance) {
                wallpaperCropperInstance.rotate(degrees);
            }
        }

        function resetWallpaperCrop() {
            if (wallpaperCropperInstance) {
                wallpaperCropperInstance.reset();
            }
        }

        function cancelWallpaperCropModal() {
            const modal = document.getElementById('wallpaperCropModal');
            if (modal) {
                modal.classList.add('hidden');
                modal.style.display = 'none';
            }
            if (wallpaperCropperInstance) {
                wallpaperCropperInstance.destroy();
                wallpaperCropperInstance = null;
            }
            if (wallpaperCropBlobUrl) {
                URL.revokeObjectURL(wallpaperCropBlobUrl);
                wallpaperCropBlobUrl = null;
            }
        }

        async function submitCropAndApplyWallpaper() {
            if (!wallpaperCropperInstance) return;

            const confirmBtn = document.getElementById('btnConfirmWallpaperCrop');
            if (confirmBtn) {
                confirmBtn.disabled = true;
                confirmBtn.innerHTML = '<span class="material-icons-round text-base animate-spin">refresh</span> <span>Processing Ultra-HD Wallpaper...</span>';
            }

            try {
                // Determine original image data / natural dimensions to prevent downsampling or blurriness
                const imgData = wallpaperCropperInstance.getImageData();
                const cropBoxData = wallpaperCropperInstance.getCropBoxData();
                
                // Export at the image's true native resolution with high-end bicubic smoothing
                const canvas = wallpaperCropperInstance.getCroppedCanvas({
                    imageSmoothingEnabled: true,
                    imageSmoothingQuality: 'high',
                    maxWidth: 4096,
                    maxHeight: 4096
                });

                if (!canvas) {
                    alert('Could not crop image. Please try again.');
                    if (confirmBtn) {
                        confirmBtn.disabled = false;
                        confirmBtn.innerHTML = '<span class="material-icons-round text-base">check</span> <span>Done & Apply Wallpaper</span>';
                    }
                    return;
                }

                // Preserve original mime-type (PNG / JPEG / WEBP) or use maximum 0.98 quality to avoid compression artifacts
                const mimeType = (selectedWallpaperFile && selectedWallpaperFile.type === 'image/png') ? 'image/png' : 'image/jpeg';
                const quality = mimeType === 'image/png' ? undefined : 0.98;

                canvas.toBlob(async (blob) => {
                    if (!blob) {
                        alert('Error processing image crop.');
                        if (confirmBtn) {
                            confirmBtn.disabled = false;
                            confirmBtn.innerHTML = '<span class="material-icons-round text-base">check</span> <span>Done & Apply Wallpaper</span>';
                        }
                        return;
                    }

                    const ext = mimeType === 'image/png' ? 'png' : 'jpg';
                    const croppedFile = new File([blob], `wallpaper_crop_${Date.now()}.${ext}`, { type: mimeType });
                    selectedWallpaperFile = croppedFile;

                    // Update live preview thumbnail in wallpaper modal
                    const previewImg = document.getElementById('wallpaperPreviewImg');
                    if (previewImg) {
                        previewImg.src = URL.createObjectURL(croppedFile);
                    }

                    // Directly apply wallpaper to server & chat background
                    await saveWallpaperToServer('image', '', croppedFile);

                    // Close crop modal
                    cancelWallpaperCropModal();

                    if (confirmBtn) {
                        confirmBtn.disabled = false;
                        confirmBtn.innerHTML = '<span class="material-icons-round text-base">check</span> <span>Done & Apply Wallpaper</span>';
                    }
                }, mimeType, quality);

            } catch (err) {
                console.error("Error cropping wallpaper:", err);
                alert("Failed to crop and apply wallpaper.");
                if (confirmBtn) {
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = '<span class="material-icons-round text-base">check</span> <span>Done & Apply Wallpaper</span>';
                }
            }
        }

        /* ========================================================
           MONTHLY SALARY COUNTDOWN & MOTIVATION CONTROLLER
           Payday: 7th of Every Month
           ======================================================== */
        const salaryMotivations = [
            "Mehnat itni khamoshi se karo ki tumhari kamyabi shor macha de! 7th is coming soon!",
            "Consistent hard work always leads to greatness. Keep your momentum high!",
            "Small daily improvements over time lead to stunning results. Payday is ahead!",
            "Work with pride, deliver with passion. The 7th reward is on its way!",
            "Your dedication today builds the lifestyle of tomorrow. Keep pushing!",
            "Success is the sum of small efforts repeated day in and day out. Salary day loading!",
            "Stay focused on your goals, every single task you complete makes a difference!",
            "Every effort you put in counts. Keep hustling with a positive mindset!",
            "Jo log mehnat se dosti karte hain, waqt unka ghulam ban jata hai! Payday ahead!",
            "Dream big, work hard, stay humble. The 7th is getting closer every day!",
            "Mushkilein hamesha behtareen logon ke hisse aati hain, kyunki wo use behtareen tarike se anjaam dete hain!",
            "Don't stop when you're tired, stop when you're done! Payday reward on the 7th!",
            "Khwahishon se nahi girtay phool jholi mein, karm ki shaakh ko hilana hoga!",
            "Great things never come from comfort zones. Give your 100% today!",
            "Safar me dhoop to hogi jo chal sako to chalo! 7th salary day reward is waiting!",
            "Your only limit is your mind. Keep crushing tasks and achieving goals!",
            "Paseene ki syahi se jo likhte hain kismat, unke panne kabhi kore nahi hote!",
            "Discipline is choosing between what you want now and what you want most!",
            "Zindagi me kamyabi unhi ko milti hai jo har chunauti ka samna muskura kar karte hain!",
            "Believe you can and you're halfway there. Keep smiling & working smart!",
            "Kamyabi ka rasta thoda mushkil zaroor hota hai, par manzil lajawab hoti hai!",
            "Hustle in silence, celebrate with your loved ones on salary day!",
            "Apni mehnat par bharosa rakho, kismat ka sikka bhi zaroor chamkega!"
        ];

        // Pick a fresh random quote on every page refresh
        let currentMotivationIndex = Math.floor(Math.random() * salaryMotivations.length);

        function calculateSalaryCountdown() {
            const now = new Date();
            const currentYear = now.getFullYear();
            const currentMonth = now.getMonth(); // 0-indexed (0 = Jan, 9 = Oct)
            const currentDate = now.getDate();

            // Next 7th calculation:
            let targetDate = new Date(currentYear, currentMonth, 7, 0, 0, 0);

            // If today is past the 7th, payday is 7th of next month
            if (currentDate > 7) {
                targetDate = new Date(currentYear, currentMonth + 1, 7, 0, 0, 0);
            }

            const diffMs = targetDate - now;
            const diffDays = Math.ceil(diffMs / (1000 * 60 * 60 * 24));

            let countdownBadgeText = '';
            let modalStatusNote = '';

            if (currentDate === 7) {
                countdownBadgeText = "🎉 Today is Salary Day!";
                modalStatusNote = "Congratulations! Today is Payday! Hard work paid off!";
            } else if (diffDays === 1) {
                countdownBadgeText = "🔥 Tomorrow is Salary Day!";
                modalStatusNote = "Just 1 day left! Keep your energy up!";
            } else {
                countdownBadgeText = `⏳ ${diffDays} Days to Go`;
                modalStatusNote = `${diffDays} days left until the 7th. Stay consistent & productive!`;
            }

            // Update Header widget
            const headerBadge = document.getElementById('salaryDaysLeftText');
            if (headerBadge) {
                headerBadge.textContent = countdownBadgeText;
            }

            // Update Modal numbers & payday string
            const modalDays = document.getElementById('modalSalaryDaysNumber');
            if (modalDays) {
                modalDays.textContent = currentDate === 7 ? "0" : diffDays;
            }

            const modalNote = document.getElementById('modalSalaryStatusNote');
            if (modalNote) {
                modalNote.textContent = modalStatusNote;
            }

            const modalPaydayDate = document.getElementById('modalNextPaydayDate');
            if (modalPaydayDate) {
                const monthNames = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
                modalPaydayDate.textContent = `7th ${monthNames[targetDate.getMonth()]} ${targetDate.getFullYear()}`;
            }

            // Random daily quote
            const quote = salaryMotivations[currentMotivationIndex % salaryMotivations.length];
            const headerQuote = document.getElementById('salaryMotivationQuote');
            if (headerQuote) headerQuote.textContent = `"${quote}"`;

            const modalQuote = document.getElementById('modalFullMotivationQuote');
            if (modalQuote) modalQuote.textContent = `"${quote}"`;
        }

        function refreshSalaryMotivationQuote() {
            currentMotivationIndex = Math.floor(Math.random() * salaryMotivations.length);
            const quote = salaryMotivations[currentMotivationIndex];

            const headerQuote = document.getElementById('salaryMotivationQuote');
            if (headerQuote) headerQuote.textContent = `"${quote}"`;

            const modalQuote = document.getElementById('modalFullMotivationQuote');
            if (modalQuote) modalQuote.textContent = `"${quote}"`;
        }

        function showSalaryMotivationModal() {
            calculateSalaryCountdown();
            const modal = document.getElementById('salaryMotivationModal');
            if (modal) {
                modal.classList.remove('hidden');
                modal.style.display = 'flex';
            }
        }

        function closeSalaryMotivationModal() {
            const modal = document.getElementById('salaryMotivationModal');
            if (modal) {
                modal.classList.add('hidden');
                modal.style.display = 'none';
            }
        }

        // Initialize countdown on boot and update every hour
        calculateSalaryCountdown();
        setInterval(calculateSalaryCountdown, 60000 * 60);

        // Search listener
        document.getElementById('contactSearchInput').addEventListener('input', renderContactsList);

        // Auto-expand textarea helper
        function autoResizeMessageInput() {
            const input = document.getElementById('messageInput');
            if (!input) return;
            input.style.height = 'auto';
            const newHeight = Math.min(input.scrollHeight, 128);
            input.style.height = newHeight + 'px';
            input.style.overflowY = input.scrollHeight > 128 ? 'auto' : 'hidden';
        }

        // Enter key to send message vs Shift+Enter for new line in same chat box
        document.getElementById('messageInput').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                if (e.shiftKey) {
                    // Shift + Enter: Allow natural new line in same chat box
                    setTimeout(autoResizeMessageInput, 0);
                    return;
                } else {
                    // Enter alone: Send message
                    e.preventDefault();
                    sendMessage();
                }
            }
        });

        // Real-Time Typing Input Debounce Listener
        document.getElementById('messageInput').addEventListener('input', function() {
            autoResizeMessageInput();
            if (!activeContactId) return;

            if (!isTypingActive) {
                isTypingActive = true;
                const fd = new FormData();
                fd.append('action', 'typing');
                fd.append('receiver_id', activeContactId);
                fetch('api.php', { method: 'POST', body: fd }).catch(()=>{});
            }

            clearTimeout(typingDebounceTimer);
            typingDebounceTimer = setTimeout(() => {
                if (isTypingActive) {
                    isTypingActive = false;
                    const fd = new FormData();
                    fd.append('action', 'stop_typing');
                    fd.append('receiver_id', activeContactId);
                    fetch('api.php', { method: 'POST', body: fd }).catch(()=>{});
                }
            }, 2500);
        });

        /* Instant Offline Signal on Tab / Browser Close */
        function sendOfflineBeacon() {
            try {
                if (navigator.sendBeacon) {
                    const fd = new FormData();
                    fd.append('action', 'set_offline');
                    navigator.sendBeacon('api.php', fd);
                } else {
                    fetch('api.php?action=set_offline', { method: 'POST', keepalive: true }).catch(function(){});
                }
            } catch(e) {}
        }

        window.addEventListener('beforeunload', sendOfflineBeacon);
        window.addEventListener('pagehide', sendOfflineBeacon);

        /* Instant Online Refresh when Tab Regains Focus */
        window.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible' && navigator.onLine !== false) {
                fetch('api.php?action=heartbeat').then(() => {
                    fetchContacts();
                }).catch(function(){});
            }
        });

        // Initialize on load
        window.addEventListener('DOMContentLoaded', () => {
            fetchContacts();
            fetchCallsHistory();
            startLivePolling();
            renderEmojiGrid();

            // Pre-warm SpeechSynthesis voices for instant telecom announcement
            if ('speechSynthesis' in window) {
                try {
                    window.speechSynthesis.getVoices();
                    if (window.speechSynthesis.onvoiceschanged !== undefined) {
                        window.speechSynthesis.onvoiceschanged = () => {
                            window.speechSynthesis.getVoices();
                        };
                    }
                } catch(e) {}
            }

            // Check for call in URL parameters (e.g. redirected from dashboard notification)
            const urlParams = new URLSearchParams(window.location.search);
            const autoCallId = urlParams.get('call_id');
            const autoAnswer = urlParams.get('auto_answer');
            if (autoCallId && autoAnswer === '1') {
                setTimeout(async () => {
                    try {
                        const res = await fetch(`api.php?action=get_call_status&call_id=${autoCallId}`);
                        const d = await res.json();
                        if (d.success && d.status === 'calling') {
                            handleIncomingCall({
                                id: autoCallId,
                                call_type: d.call_type,
                                caller_id: d.caller_id,
                                caller_name: d.caller_name || 'Team Member',
                                caller_role: d.caller_role || 'Member',
                                offer: d.offer
                            });
                            acceptIncomingCall();
                        }
                    } catch(e) {}
                }, 600);
            }
        });

        /* ========================================================
           DRAG & DROP + CLIPBOARD PASTE (CTRL+C / CTRL+V) ENGINE
           ======================================================== */
        (function setupDragDropAndPaste() {
            const dropOverlay = document.getElementById('chatDropzoneOverlay');
            let dragCounter = 0;

            function isChatOpen() {
                return !!(activeContactId || activeGroupId);
            }

            function isFileDrag(e) {
                if (!e.dataTransfer || !e.dataTransfer.types) return false;
                const types = Array.from(e.dataTransfer.types);
                return types.includes('Files');
            }

            // Global Drag Over events
            window.addEventListener('dragenter', (e) => {
                if (!isChatOpen()) return;
                if (isFileDrag(e)) {
                    e.preventDefault();
                    dragCounter++;
                    if (dropOverlay) {
                        dropOverlay.classList.remove('hidden');
                        dropOverlay.style.pointerEvents = 'auto'; // Catch drop events properly
                    }
                }
            });

            window.addEventListener('dragover', (e) => {
                if (!isChatOpen()) return;
                if (isFileDrag(e)) {
                    e.preventDefault();
                    if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy';
                }
            });

            window.addEventListener('dragleave', (e) => {
                if (!isChatOpen()) return;
                if (isFileDrag(e)) {
                    e.preventDefault();
                    dragCounter--;
                    if (dragCounter <= 0) {
                        dragCounter = 0;
                        if (dropOverlay) {
                            dropOverlay.classList.add('hidden');
                            dropOverlay.style.pointerEvents = 'none';
                        }
                    }
                }
            });

            window.addEventListener('drop', (e) => {
                if (!isChatOpen()) return;
                if (isFileDrag(e)) {
                    e.preventDefault();
                    dragCounter = 0;
                    if (dropOverlay) {
                        dropOverlay.classList.add('hidden');
                        dropOverlay.style.pointerEvents = 'none';
                    }

                    const files = e.dataTransfer.files;
                    if (files && files.length > 0) {
                        attachPastedOrDroppedFile(files[0]);
                    }
                }
            });

            // If user drops on the dropzone overlay directly
            if (dropOverlay) {
                dropOverlay.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy';
                });
                dropOverlay.addEventListener('drop', (e) => {
                    e.preventDefault();
                    dragCounter = 0;
                    dropOverlay.classList.add('hidden');
                    dropOverlay.style.pointerEvents = 'none';
                    const files = e.dataTransfer.files;
                    if (files && files.length > 0) {
                        attachPastedOrDroppedFile(files[0]);
                    }
                });
            }

            // Clipboard Paste Listener (Ctrl+V / Paste image or file)
            document.addEventListener('paste', (e) => {
                if (!isChatOpen()) return;

                const clipboardData = e.clipboardData || window.clipboardData;
                if (!clipboardData) return;

                // 1. Direct files in clipboard
                if (clipboardData.files && clipboardData.files.length > 0) {
                    e.preventDefault();
                    attachPastedOrDroppedFile(clipboardData.files[0]);
                    return;
                }

                // 2. Clipboard Items (pasted screenshot from Snipping Tool / PrintScreen / images)
                if (clipboardData.items && clipboardData.items.length > 0) {
                    for (let i = 0; i < clipboardData.items.length; i++) {
                        const item = clipboardData.items[i];
                        if (item.kind === 'file') {
                            const blob = item.getAsFile();
                            if (blob) {
                                e.preventDefault();
                                const ext = blob.type ? (blob.type.split('/')[1] || 'png') : 'png';
                                const fileName = (blob.name && blob.name !== 'image.png') 
                                    ? blob.name 
                                    : `pasted_image_${Date.now()}.${ext}`;
                                const file = new File([blob], fileName, { type: blob.type || 'image/png' });
                                attachPastedOrDroppedFile(file);
                                return;
                            }
                        }
                    }
                }
            });

            function attachPastedOrDroppedFile(file) {
                if (!file) return;
                openAttachmentModal(file);
            }
        })();

        /* ========================================================
           AUTOMATIC VOICE CALL RECORDING ENGINE (Server Synced)
           Automatically records WebRTC audio for both parties
           Uploads to server upon call end so it appears in Call Logs
           ======================================================== */
        let callMediaRecorder = null;
        let callRecordingChunks = [];
        let callRecordingCallId = null;

        function startAutoVoiceRecording(callId) {
            if (!callId || (callMediaRecorder && callMediaRecorder.state !== 'inactive')) return;
            callRecordingCallId = callId;
            callRecordingChunks = [];

            try {
                const combinedStream = new MediaStream();

                // Mix Audio tracks (caller & receiver) via Web Audio API
                try {
                    const AudioCtx = window.AudioContext || window.webkitAudioContext;
                    if (AudioCtx) {
                        const audioCtx = new AudioCtx();
                        const dest = audioCtx.createMediaStreamDestination();

                        if (localMediaStream && localMediaStream.getAudioTracks().length > 0) {
                            const localSrc = audioCtx.createMediaStreamSource(localMediaStream);
                            localSrc.connect(dest);
                        }
                        if (remoteMediaStream && remoteMediaStream.getAudioTracks().length > 0) {
                            const remoteSrc = audioCtx.createMediaStreamSource(remoteMediaStream);
                            remoteSrc.connect(dest);
                        }

                        dest.stream.getAudioTracks().forEach(track => combinedStream.addTrack(track));
                    }
                } catch (audioErr) {
                    if (localMediaStream) localMediaStream.getAudioTracks().forEach(t => combinedStream.addTrack(t));
                    if (remoteMediaStream) remoteMediaStream.getAudioTracks().forEach(t => combinedStream.addTrack(t));
                }

                if (combinedStream.getTracks().length === 0 && localMediaStream) {
                    localMediaStream.getAudioTracks().forEach(t => combinedStream.addTrack(t));
                }

                if (combinedStream.getTracks().length === 0) return;

                let options = { mimeType: 'audio/webm;codecs=opus' };
                if (!window.MediaRecorder || !MediaRecorder.isTypeSupported(options.mimeType)) {
                    if (window.MediaRecorder && MediaRecorder.isTypeSupported('audio/webm')) {
                        options = { mimeType: 'audio/webm' };
                    } else if (window.MediaRecorder && MediaRecorder.isTypeSupported('audio/ogg')) {
                        options = { mimeType: 'audio/ogg' };
                    } else {
                        options = {};
                    }
                }

                callMediaRecorder = new MediaRecorder(combinedStream, options);

                callMediaRecorder.ondataavailable = (e) => {
                    if (e.data && e.data.size > 0) {
                        callRecordingChunks.push(e.data);
                    }
                };

                callMediaRecorder.onstop = () => {
                    const recId = callRecordingCallId;
                    const chunks = [...callRecordingChunks];
                    callRecordingChunks = [];
                    callRecordingCallId = null;

                    if (chunks.length > 0 && recId) {
                        const blob = new Blob(chunks, { type: callMediaRecorder.mimeType || 'audio/webm' });
                        uploadCallRecording(recId, blob);
                    }
                };

                callMediaRecorder.start(1000);
            } catch(e) {
                console.warn("Could not start auto voice recording:", e);
            }
        }

        function stopAutoVoiceRecording() {
            if (callMediaRecorder && callMediaRecorder.state !== 'inactive') {
                try {
                    callMediaRecorder.stop();
                } catch(e) {}
            }
        }

        async function uploadCallRecording(callId, blob) {
            if (!callId || !blob || blob.size < 100) return;
            const fd = new FormData();
            fd.append('action', 'upload_call_recording');
            fd.append('call_id', callId);
            const isVideo = blob.type.includes('video');
            fd.append('recording', blob, `call_rec_${callId}.${isVideo ? 'webm' : 'webm'}`);

            try {
                const res = await fetch('api.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    showQuickToast('💾 Call Recording Saved to Calls Log');
                    fetchCallsHistory();
                }
            } catch(e) {
                console.error("Upload call recording error:", e);
            }
        }

        function playCallRecording(url, peerName, timeStr, callType) {
            if (!url) return;
            const modal = document.getElementById('callRecordingModal');
            const videoWrapper = document.getElementById('recVideoWrapper');
            const voiceWrapper = document.getElementById('recVoiceWrapper');
            const videoPlayer = document.getElementById('callRecordingPlayer');
            const audioPlayer = document.getElementById('callRecordingAudioPlayer');
            const title = document.getElementById('recModalTitle');
            const sub = document.getElementById('recModalSubtitle');
            const badge = document.getElementById('recModalTypeBadge');
            const icon = document.getElementById('recModalIcon');
            const iconWrap = document.getElementById('recModalIconWrapper');
            const downloadBtn = document.getElementById('recDownloadBtn');
            const voicePeer = document.getElementById('recVoicePeerName');
            const voiceAvatar = document.getElementById('recVoiceAvatar');
            const voiceMeta = document.getElementById('recVoiceMeta');

            const cleanPeerName = peerName || 'Team Member';
            if (title) title.textContent = `Call Recording - ${cleanPeerName}`;
            if (sub) sub.textContent = timeStr || 'Recorded Call';
            if (downloadBtn) {
                downloadBtn.href = url;
                downloadBtn.download = `recording_${cleanPeerName.replace(/\s+/g, '_')}_${Date.now()}.webm`;
            }

            const isVideo = (callType === 'video');

            if (badge) {
                badge.textContent = isVideo ? 'Video Call' : 'Voice Call';
                if (isVideo) {
                    badge.className = 'text-[10px] font-bold uppercase tracking-wider text-indigo-700 bg-indigo-50 px-2 py-0.5 rounded-md border border-indigo-100';
                } else {
                    badge.className = 'text-[10px] font-bold uppercase tracking-wider text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-md border border-emerald-100';
                }
            }

            if (icon && iconWrap) {
                if (isVideo) {
                    icon.textContent = 'videocam';
                    iconWrap.className = 'w-11 h-11 rounded-2xl bg-indigo-50 text-indigo-600 border border-indigo-100 flex items-center justify-center shadow-xs';
                } else {
                    icon.textContent = 'call';
                    iconWrap.className = 'w-11 h-11 rounded-2xl bg-emerald-50 text-emerald-600 border border-emerald-100 flex items-center justify-center shadow-xs';
                }
            }

            if (isVideo) {
                if (voiceWrapper) voiceWrapper.classList.add('hidden');
                if (videoWrapper) videoWrapper.classList.remove('hidden');
                if (audioPlayer) {
                    audioPlayer.pause();
                    audioPlayer.src = "";
                }
                if (videoPlayer) {
                    videoPlayer.src = url;
                    videoPlayer.play().catch(()=>{});
                }
            } else {
                if (videoWrapper) videoWrapper.classList.add('hidden');
                if (voiceWrapper) voiceWrapper.classList.remove('hidden');
                if (voicePeer) voicePeer.textContent = cleanPeerName;
                if (voiceAvatar) voiceAvatar.textContent = cleanPeerName.charAt(0).toUpperCase();
                if (voiceMeta) voiceMeta.textContent = `${timeStr || ''} • Voice Call Recording`;

                if (videoPlayer) {
                    videoPlayer.pause();
                    videoPlayer.src = "";
                }
                if (audioPlayer) {
                    audioPlayer.src = url;
                    audioPlayer.play().catch(()=>{});
                }
            }

            if (modal) modal.classList.remove('hidden');
        }

        function closeCallRecordingModal() {
            const modal = document.getElementById('callRecordingModal');
            const videoPlayer = document.getElementById('callRecordingPlayer');
            const audioPlayer = document.getElementById('callRecordingAudioPlayer');
            if (videoPlayer) {
                videoPlayer.pause();
                videoPlayer.src = "";
            }
            if (audioPlayer) {
                audioPlayer.pause();
                audioPlayer.src = "";
            }
            if (modal) modal.classList.add('hidden');
        }

        /* ========================================================
           CHAT RIGHT-CLICK CONTEXT MENU (PIN / UNPIN)
           ======================================================== */
        let contextMenuTarget = { peerType: null, peerId: null, isPinned: false };

        function showChatContextMenu(e, peerType, peerId, isPinned) {
            e.preventDefault();
            e.stopPropagation();

            contextMenuTarget = { peerType, peerId, isPinned };
            const menu = document.getElementById('chatContextMenu');
            const pinIcon = document.getElementById('ctxMenuPinIcon');
            const pinText = document.getElementById('ctxMenuPinText');

            if (isPinned) {
                pinText.textContent = "Unpin chat";
                pinIcon.textContent = "push_pin";
                pinIcon.classList.add('text-indigo-600');
                pinIcon.classList.remove('text-slate-400');
            } else {
                pinText.textContent = "Pin chat";
                pinIcon.textContent = "push_pin";
                pinIcon.classList.remove('text-indigo-600');
                pinIcon.classList.add('text-slate-400');
            }

            const nickItem = document.getElementById('ctxMenuNicknameBtn');
            if (nickItem) {
                if (peerType === 'direct') {
                    nickItem.classList.remove('hidden');
                } else {
                    nickItem.classList.add('hidden');
                }
            }

            // Viewport clamping
            const menuWidth = 180;
            const menuHeight = peerType === 'direct' ? 120 : 85;
            let x = e.clientX;
            let y = e.clientY;

            if (x + menuWidth > window.innerWidth) {
                x = window.innerWidth - menuWidth - 10;
            }
            if (y + menuHeight > window.innerHeight) {
                y = window.innerHeight - menuHeight - 10;
            }

            menu.style.left = `${x}px`;
            menu.style.top = `${y}px`;
            menu.classList.remove('hidden');
        }

        function hideChatContextMenu() {
            const menu = document.getElementById('chatContextMenu');
            if (menu) menu.classList.add('hidden');
        }

        function handleContextViewProfile() {
            hideChatContextMenu();
            if (contextMenuTarget.peerType === 'direct' && contextMenuTarget.peerId) {
                openUserProfileModal(contextMenuTarget.peerId);
            } else if (contextMenuTarget.peerType === 'group') {
                openGroupInfoModal();
            }
        }

        async function toggleContextPinChat() {
            hideChatContextMenu();
            if (!contextMenuTarget.peerType || !contextMenuTarget.peerId) return;

            const fd = new FormData();
            fd.append('action', 'toggle_pin_chat');
            fd.append('peer_type', contextMenuTarget.peerType);
            fd.append('peer_id', contextMenuTarget.peerId);

            try {
                const res = await fetch('api.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    showQuickToast(data.message || (data.is_pinned ? "Chat pinned to top" : "Chat unpinned"));
                    // Update cache immediately
                    if (contextMenuTarget.peerType === 'direct') {
                        const target = contactsData.find(c => parseInt(c.id) === parseInt(contextMenuTarget.peerId));
                        if (target) target.is_pinned = data.is_pinned;
                    } else if (contextMenuTarget.peerType === 'group') {
                        const target = groupsData.find(g => parseInt(g.id) === parseInt(contextMenuTarget.peerId));
                        if (target) target.is_pinned = data.is_pinned;
                    }
                    renderContactsList();
                } else {
                    alert(data.error || "Failed to update pin status");
                }
            } catch(err) {
                console.error("Toggle pin chat error:", err);
            }
        }

        function handleContextSetNickname() {
            hideChatContextMenu();
            if (contextMenuTarget.peerType === 'direct' && contextMenuTarget.peerId) {
                openNicknameModal(contextMenuTarget.peerId);
            }
        }

        /* ========================================================
           NICKNAME / CONTACT ALIAS MODAL CONTROLLER
           ======================================================== */
        let activeNicknameContactId = null;

        function openNicknameModal(contactId) {
            contactId = parseInt(contactId);
            if (!contactId || contactId === myUserId) return;

            activeNicknameContactId = contactId;
            const contact = contactsData.find(c => parseInt(c.id) === contactId);

            const modal = document.getElementById('nicknameModal');
            const contactIdInput = document.getElementById('nicknameContactId');
            const nameInput = document.getElementById('nicknameInput');
            const realNameEl = document.getElementById('nicknameModalRealName');
            const usernameEl = document.getElementById('nicknameModalUsername');
            const avatarEl = document.getElementById('nicknameModalAvatar');
            const removeBtn = document.getElementById('btnRemoveNickname');
            const titleEl = document.getElementById('nicknameModalTitle');

            if (!modal) return;

            contactIdInput.value = contactId;

            const originalName = contact ? (contact.real_name || contact.name) : 'Contact';
            const username = contact ? contact.username : '';
            const existingNick = contact ? (contact.nickname || '') : '';
            const initial = originalName.substring(0, 1).toUpperCase();

            titleEl.textContent = existingNick ? "Edit Nickname" : "Set Nickname";
            realNameEl.textContent = originalName;
            usernameEl.textContent = username ? `@${username}` : '';
            nameInput.value = existingNick;

            if (avatarEl) {
                if (contact && contact.profile_image) {
                    avatarEl.innerHTML = `<img src="${escapeHtml(contact.profile_image)}" alt="${escapeHtml(originalName)}" class="w-full h-full object-cover rounded-xl" onerror="this.parentElement.innerText='${escapeHtml(initial)}'" />`;
                } else {
                    avatarEl.innerHTML = `<span>${escapeHtml(initial)}</span>`;
                }
            }

            if (removeBtn) {
                if (existingNick) {
                    removeBtn.classList.remove('hidden');
                } else {
                    removeBtn.classList.add('hidden');
                }
            }

            modal.classList.remove('hidden');
            setTimeout(() => {
                nameInput.focus();
                nameInput.select();
            }, 50);
        }

        function closeNicknameModal() {
            const modal = document.getElementById('nicknameModal');
            if (modal) modal.classList.add('hidden');
            activeNicknameContactId = null;
        }

        function clearNicknameInput() {
            const inp = document.getElementById('nicknameInput');
            if (inp) {
                inp.value = '';
                inp.focus();
            }
        }

        async function submitSaveNickname(e) {
            if (e) e.preventDefault();

            const contactId = parseInt(document.getElementById('nicknameContactId').value || activeNicknameContactId);
            const nicknameVal = document.getElementById('nicknameInput').value.trim();

            if (!contactId) return;

            const saveBtn = document.getElementById('btnSaveNickname');
            if (saveBtn) {
                saveBtn.disabled = true;
                saveBtn.innerHTML = `<span class="material-icons-round text-sm animate-spin">sync</span><span>Saving...</span>`;
            }

            try {
                const fd = new FormData();
                fd.append('action', 'save_nickname');
                fd.append('contact_id', contactId);
                fd.append('nickname', nicknameVal);

                const res = await fetch('api.php', { method: 'POST', body: fd });
                const data = await res.json();

                if (data.success) {
                    applyUpdatedNickname(contactId, data.nickname, data.display_name, data.real_name);
                    showQuickToast(data.message || "Nickname updated");
                    closeNicknameModal();
                } else {
                    alert(data.error || "Failed to save nickname");
                }
            } catch (err) {
                console.error("Save nickname error:", err);
                alert("Something went wrong saving nickname");
            } finally {
                if (saveBtn) {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = `<span class="material-icons-round text-sm">check</span><span>Save Nickname</span>`;
                }
            }
        }

        async function submitRemoveNickname() {
            const contactId = parseInt(document.getElementById('nicknameContactId').value || activeNicknameContactId);
            if (!contactId) return;

            if (!confirm("Are you sure you want to remove the nickname and restore the original name?")) {
                return;
            }

            const remBtn = document.getElementById('btnRemoveNickname');
            if (remBtn) {
                remBtn.disabled = true;
                remBtn.innerHTML = `<span class="material-icons-round text-sm animate-spin">sync</span><span>Removing...</span>`;
            }

            try {
                const fd = new FormData();
                fd.append('action', 'save_nickname');
                fd.append('contact_id', contactId);
                fd.append('nickname', '');

                const res = await fetch('api.php', { method: 'POST', body: fd });
                const data = await res.json();

                if (data.success) {
                    applyUpdatedNickname(contactId, '', data.display_name, data.real_name);
                    showQuickToast("Nickname removed");
                    closeNicknameModal();
                } else {
                    alert(data.error || "Failed to remove nickname");
                }
            } catch (err) {
                console.error("Remove nickname error:", err);
                alert("Something went wrong removing nickname");
            } finally {
                if (remBtn) {
                    remBtn.disabled = false;
                    remBtn.innerHTML = `<span class="material-icons-round text-sm">delete_outline</span><span>Remove</span>`;
                }
            }
        }

        function applyUpdatedNickname(contactId, newNickname, displayName, realName) {
            contactId = parseInt(contactId);

            // 1. Update contact in local contactsData
            const c = contactsData.find(x => parseInt(x.id) === contactId);
            if (c) {
                c.nickname = newNickname;
                c.name = displayName;
                if (realName) c.real_name = realName;
            }

            // 2. Re-render contact list
            renderContactsList();

            // 3. Update active conversation header if currently chatting with this contact
            if (parseInt(activeContactId) === contactId) {
                const nameEl = document.getElementById('activeName');
                if (nameEl) nameEl.textContent = displayName;
            }

            // 4. Update right side panel drawer if open for this contact
            if (parseInt(activeProfileUserId) === contactId) {
                const rpName = document.getElementById('rightPanelName');
                if (rpName) rpName.textContent = displayName;

                const realSub = document.getElementById('rightPanelRealNameSubtext');
                if (realSub) {
                    if (newNickname && realName && newNickname !== realName) {
                        realSub.textContent = `Original Name: ${realName}`;
                        realSub.classList.remove('hidden');
                    } else {
                        realSub.classList.add('hidden');
                    }
                }
            }
        }

        document.addEventListener('click', hideChatContextMenu);

        /* ========================================================
           WHATSAPP-STYLE LIVE CAMERA SNAPSHOT & SEND / DP ENGINE
           ======================================================== */
        let cameraMediaStream = null;
        let capturedPhotoBlob = null;
        let currentCameraFacingMode = 'user';
        let cameraPurpose = 'chat'; // 'chat' or 'dp'

        async function openCameraModal(purpose = 'chat') {
            cameraPurpose = purpose;
            if (cameraPurpose === 'chat' && !activeContactId && !activeGroupId) {
                showQuickToast("Please select a chat first");
                return;
            }

            capturedPhotoBlob = null;
            const modal = document.getElementById('cameraModal');
            const liveView = document.getElementById('cameraLiveView');
            const previewView = document.getElementById('cameraPreviewView');
            const captionInput = document.getElementById('cameraCaptionInput');
            const captionRow = document.getElementById('cameraCaptionRow');
            const cameraModalTitle = document.getElementById('cameraModalTitle');
            const cameraModalDesc = document.getElementById('cameraModalDesc');
            const cameraSendBtnText = document.getElementById('cameraSendBtnText');
            const cameraSendBtnIcon = document.getElementById('cameraSendBtnIcon');

            if (captionInput) captionInput.value = '';

            if (cameraPurpose === 'dp') {
                if (captionRow) captionRow.classList.add('hidden');
                if (cameraModalTitle) cameraModalTitle.innerText = "Take Profile Picture";
                if (cameraModalDesc) cameraModalDesc.innerText = "Capture a selfie or photo for your profile";
                if (cameraSendBtnText) cameraSendBtnText.innerText = "Set as Profile DP";
                if (cameraSendBtnIcon) cameraSendBtnIcon.innerText = "check_circle";
            } else {
                if (captionRow) captionRow.classList.remove('hidden');
                if (cameraModalTitle) cameraModalTitle.innerText = "Take Photo";
                if (cameraModalDesc) cameraModalDesc.innerText = "Capture & send directly to chat";
                if (cameraSendBtnText) cameraSendBtnText.innerText = "Send Photo";
                if (cameraSendBtnIcon) cameraSendBtnIcon.innerText = "send";
            }

            liveView.classList.remove('hidden');
            previewView.classList.add('hidden');
            modal.classList.remove('hidden');

            startCameraStream();
        }

        async function startCameraStream() {
            stopCameraStream();
            try {
                cameraMediaStream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: currentCameraFacingMode,
                        width: { ideal: 1280 },
                        height: { ideal: 720 }
                    },
                    audio: false
                });
                const videoEl = document.getElementById('cameraStreamVideo');
                if (videoEl) {
                    videoEl.srcObject = cameraMediaStream;
                    videoEl.play().catch(()=>{});
                }
            } catch(err) {
                console.error("Camera access error:", err);
                alert("Could not access camera. Please allow camera permissions in your browser.");
                closeCameraModal();
            }
        }

        function stopCameraStream() {
            if (cameraMediaStream) {
                cameraMediaStream.getTracks().forEach(t => t.stop());
                cameraMediaStream = null;
            }
            const videoEl = document.getElementById('cameraStreamVideo');
            if (videoEl) videoEl.srcObject = null;
        }

        function switchCameraFacing() {
            currentCameraFacingMode = (currentCameraFacingMode === 'user') ? 'environment' : 'user';
            startCameraStream();
        }

        function takeCameraSnapshot() {
            const videoEl = document.getElementById('cameraStreamVideo');
            const canvas = document.getElementById('cameraCanvas');
            if (!videoEl || !canvas) return;

            canvas.width = videoEl.videoWidth || 640;
            canvas.height = videoEl.videoHeight || 480;
            const ctx = canvas.getContext('2d');

            // Mirror if user front facing
            if (currentCameraFacingMode === 'user') {
                ctx.translate(canvas.width, 0);
                ctx.scale(-1, 1);
            }
            ctx.drawImage(videoEl, 0, 0, canvas.width, canvas.height);

            canvas.toBlob((blob) => {
                if (!blob) return;
                capturedPhotoBlob = blob;
                const imgEl = document.getElementById('cameraCapturedImg');
                if (imgEl) imgEl.src = URL.createObjectURL(blob);

                document.getElementById('cameraLiveView').classList.add('hidden');
                document.getElementById('cameraPreviewView').classList.remove('hidden');
                if (cameraPurpose === 'chat') {
                    document.getElementById('cameraCaptionInput')?.focus();
                }
                stopCameraStream();
            }, 'image/jpeg', 0.92);
        }

        function retakeCameraSnapshot() {
            capturedPhotoBlob = null;
            document.getElementById('cameraPreviewView').classList.add('hidden');
            document.getElementById('cameraLiveView').classList.remove('hidden');
            startCameraStream();
        }

        async function sendCameraPhoto() {
            if (!capturedPhotoBlob) return;

            // Handle Profile DP update mode
            if (cameraPurpose === 'dp') {
                await uploadCapturedPhotoAsDp(capturedPhotoBlob);
                return;
            }

            // Normal Chat Send Mode
            if (!activeContactId && !activeGroupId) return;

            const caption = (document.getElementById('cameraCaptionInput')?.value || '').trim();
            const photoFile = new File([capturedPhotoBlob], `camera_${Date.now()}.jpg`, { type: 'image/jpeg' });

            const sendBtn = document.getElementById('cameraSendBtn');
            if (sendBtn) sendBtn.disabled = true;

            const formData = new FormData();
            if (activeGroupId) {
                formData.append('action', 'send_group_message');
                formData.append('group_id', activeGroupId);
            } else {
                formData.append('action', 'send_message');
                formData.append('receiver_id', activeContactId);
            }
            formData.append('message', caption);
            formData.append('attachment', photoFile);
            if (isCameraViewOnceActive) {
                formData.append('is_view_once', 1);
            }

            try {
                const res = await fetch('api.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success && data.message) {
                    appendMessageBubble(data.message);
                    if (parseInt(data.message.id) > lastMessageId) {
                        lastMessageId = parseInt(data.message.id);
                    }
                    scrollToBottom();
                    fetchContacts();
                    if (isCameraViewOnceActive) {
                        toggleCameraViewOnce(); // reset toggle
                    }
                    closeCameraModal();
                    showQuickToast("Photo sent successfully");
                } else {
                    alert(data.error || "Failed to send photo");
                }
            } catch(err) {
                console.error("Send camera photo error:", err);
            } finally {
                if (sendBtn) sendBtn.disabled = false;
            }
        }

        function closeCameraModal() {
            stopCameraStream();
            capturedPhotoBlob = null;
            const modal = document.getElementById('cameraModal');
            if (modal) modal.classList.add('hidden');
        }

        /* ========================================================
           VIEW ONCE (WhatsApp Style) CONTROLLER
           ======================================================== */
        let isChatViewOnceActive = false;
        let isAttachmentViewOnceActive = false;
        let isCameraViewOnceActive = false;
        let activeViewOnceMediaUrl = null;

        function toggleChatViewOnce() {
            isChatViewOnceActive = !isChatViewOnceActive;
            const btn = document.getElementById('chatViewOnceBtn');
            if (!btn) return;
            if (isChatViewOnceActive) {
                btn.className = "w-9 h-9 rounded-full bg-indigo-600 text-white border-2 border-indigo-600 flex items-center justify-center font-black text-xs transition select-none flex-shrink-0 shadow-sm";
                showQuickToast("View once turned ON");
            } else {
                btn.className = "w-9 h-9 rounded-full border border-slate-300 text-slate-400 hover:text-indigo-600 hover:border-indigo-400 flex items-center justify-center font-black text-xs transition select-none flex-shrink-0";
            }
        }

        function toggleAttachmentViewOnce() {
            isAttachmentViewOnceActive = !isAttachmentViewOnceActive;
            const btn = document.getElementById('attachmentViewOnceBtn');
            if (!btn) return;
            if (isAttachmentViewOnceActive) {
                btn.className = "w-10 h-10 rounded-full bg-indigo-600 text-white border-2 border-indigo-600 flex items-center justify-center font-black text-xs transition select-none flex-shrink-0 shadow-sm";
                showQuickToast("View once photo turned ON");
            } else {
                btn.className = "w-10 h-10 rounded-full border border-slate-300 text-slate-400 hover:text-indigo-600 hover:border-indigo-400 flex items-center justify-center font-black text-xs transition select-none flex-shrink-0";
            }
        }

        function toggleCameraViewOnce() {
            isCameraViewOnceActive = !isCameraViewOnceActive;
            const btn = document.getElementById('cameraViewOnceBtn');
            if (!btn) return;
            if (isCameraViewOnceActive) {
                btn.className = "w-9 h-9 rounded-full bg-indigo-600 text-white border-2 border-indigo-600 flex items-center justify-center font-black text-xs transition select-none flex-shrink-0 shadow-sm";
                showQuickToast("View once photo turned ON");
            } else {
                btn.className = "w-9 h-9 rounded-full border border-slate-300 text-slate-400 hover:text-indigo-600 hover:border-indigo-400 flex items-center justify-center font-black text-xs transition select-none flex-shrink-0";
            }
        }

        async function openViewOnceMessage(messageId) {
            if (!messageId) return;

            const fd = new FormData();
            fd.append('action', 'open_view_once');
            fd.append('message_id', messageId);

            try {
                const res = await fetch('api.php', { method: 'POST', body: fd });
                const data = await res.json();

                if (!data.success) {
                    alert(data.error || "Cannot view message.");
                    // Mark as opened in UI if expired
                    if (data.is_expired) {
                        updateViewOnceBubbleToOpened(messageId);
                    }
                    return;
                }

                // Update bubble immediately to "Opened" state
                updateViewOnceBubbleToOpened(messageId);

                // Show in View Once Modal Lightbox
                openViewOnceModal(data);
            } catch (err) {
                console.error("View once open error:", err);
                alert("Failed to open view once message.");
            }
        }

        function updateViewOnceBubbleToOpened(messageId) {
            const msgObj = messagesDataMap.get(parseInt(messageId));
            if (msgObj) {
                msgObj.view_once_opened = 1;
            }

            const row = document.getElementById(`msg-row-${messageId}`);
            if (row) {
                const isMine = row.classList.contains('justify-end');
                const btn = row.querySelector('button[onclick*="openViewOnceMessage"]');
                if (btn) {
                    const openedDiv = document.createElement('div');
                    openedDiv.className = `inline-flex items-center gap-2 py-2 px-3.5 rounded-2xl ${isMine ? 'bg-white/15 text-white/90' : 'bg-slate-100 text-slate-600'} text-xs font-semibold select-none border ${isMine ? 'border-white/20' : 'border-slate-200'}`;
                    openedDiv.innerHTML = `
                        <span class="w-5 h-5 rounded-full border ${isMine ? 'border-white/50 text-white/80' : 'border-slate-400 text-slate-500'} flex items-center justify-center text-[10px] font-black">1</span>
                        <span class="font-bold">Opened</span>`;
                    btn.parentNode.replaceChild(openedDiv, btn);
                }
            }
        }

        function openViewOnceModal(data) {
            const modal = document.getElementById('viewOnceModal');
            const imgEl = document.getElementById('viewOnceImg');
            const audioEl = document.getElementById('viewOnceAudio');
            const textEl = document.getElementById('viewOnceText');
            const descEl = document.getElementById('viewOnceDesc');

            if (!modal) return;

            // Reset containers
            if (imgEl) { imgEl.classList.add('hidden'); imgEl.src = ''; }
            if (audioEl) { audioEl.classList.add('hidden'); audioEl.pause(); audioEl.src = ''; }
            if (textEl) { textEl.classList.add('hidden'); textEl.innerText = ''; }

            const fileUrl = data.file_path ? `../../${data.file_path}` : null;

            if (data.file_type === 'image' && fileUrl) {
                if (imgEl) {
                    imgEl.src = fileUrl;
                    imgEl.classList.remove('hidden');
                }
                if (descEl) descEl.innerText = data.message ? data.message : "View once photo";
            } else if (data.file_type === 'audio' && fileUrl) {
                if (audioEl) {
                    audioEl.src = fileUrl;
                    audioEl.classList.remove('hidden');
                    audioEl.play().catch(()=>{});
                }
                if (descEl) descEl.innerText = "View once audio";
            } else if (data.message) {
                if (textEl) {
                    textEl.innerText = data.message;
                    textEl.classList.remove('hidden');
                }
                if (descEl) descEl.innerText = "View once secret message";
            } else if (fileUrl) {
                // Fallback for other file types
                if (textEl) {
                    textEl.innerHTML = `<a href="${fileUrl}" target="_blank" class="text-indigo-600 font-bold underline">${escapeHtml(data.file_name || 'View File')}</a>`;
                    textEl.classList.remove('hidden');
                }
                if (descEl) descEl.innerText = "View once file";
            }

            modal.classList.remove('hidden');
        }

        function handleViewOnceBackdropClick(e) {
            if (e.target.id === 'viewOnceModal') {
                closeViewOnceModal();
            }
        }

        function closeViewOnceModal() {
            const modal = document.getElementById('viewOnceModal');
            if (modal) modal.classList.add('hidden');

            const imgEl = document.getElementById('viewOnceImg');
            if (imgEl) imgEl.src = '';

            const audioEl = document.getElementById('viewOnceAudio');
            if (audioEl) {
                audioEl.pause();
                audioEl.src = '';
            }
        }

        /* ========================================================
           WHATSAPP-STYLE RIGHT PANEL CONTACT INFO & PROFILE CONTROLLER
           ======================================================== */
        let activeProfileUserId = null;
        let isViewingMyProfile = false;

        function handleActiveHeaderProfileClick() {
            if (activeGroupId) {
                openGroupInfoModal();
            } else if (activeContactId) {
                openUserProfileModal(activeContactId);
            }
        }

        function openMyProfileModal() {
            openUserProfileModal(myUserId);
        }

        async function openUserProfileModal(userId) {
            activeProfileUserId = parseInt(userId);
            isViewingMyProfile = (activeProfileUserId === myUserId);

            const panel = document.getElementById('contactInfoRightPanel');
            if (!panel) return;

            // Reset Right Panel UI to loading state
            document.getElementById('rightPanelName').innerText = "Loading...";
            document.getElementById('rightPanelSubtext').innerText = "...";
            document.getElementById('rightPanelAboutText').innerText = "Loading status...";
            document.getElementById('rightPanelLastSeenDesc').innerText = "DigiInk Team Member";
            document.getElementById('rightPanelUserId').innerText = `#${activeProfileUserId}`;
            document.getElementById('rightPanelEmail').innerText = "...";
            document.getElementById('rightPanelJoined').innerText = "...";
            document.getElementById('rightPanelMediaCount').innerText = "0";

            const mediaGrid = document.getElementById('rightPanelMediaGrid');
            if (mediaGrid) {
                mediaGrid.innerHTML = `
                    <div class="col-span-4 py-6 text-center text-slate-400 text-xs">
                        <span class="material-icons-round animate-spin text-xl text-indigo-500 mb-1">sync</span>
                        <p>Loading media...</p>
                    </div>`;
            }

            const bigAvatar = document.getElementById('rightPanelBigAvatar');
            if (bigAvatar) {
                bigAvatar.innerHTML = `<span class="material-icons-round animate-spin text-3xl text-white">sync</span>`;
            }

            // Toggle DP upload controls (only for own profile)
            const editPencilBtn = document.getElementById('rightPanelEditDpBtn');
            const editBadge = document.getElementById('rightPanelEditDpBadge');
            const dpBtnRow = document.getElementById('rightPanelDpButtonsRow');
            if (isViewingMyProfile) {
                if (editPencilBtn) editPencilBtn.classList.remove('hidden');
                if (editBadge) editBadge.classList.remove('hidden');
                if (dpBtnRow) dpBtnRow.classList.remove('hidden');
            } else {
                if (editPencilBtn) editPencilBtn.classList.add('hidden');
                if (editBadge) editBadge.classList.add('hidden');
                if (dpBtnRow) dpBtnRow.classList.add('hidden');
            }

            // Show right side panel drawer and resizer
            panel.classList.remove('hidden');
            const rightResizer = document.getElementById('rightPanelResizer');
            if (rightResizer && window.innerWidth >= 640) {
                rightResizer.classList.remove('hidden');
            }

            try {
                const res = await fetch(`api.php?action=get_user_profile&user_id=${activeProfileUserId}`);
                const data = await res.json();
                if (!data.success || !data.user) {
                    alert(data.error || "Could not load user profile");
                    closeContactInfoPanel();
                    return;
                }

                const u = data.user;
                document.getElementById('rightPanelName').innerText = u.name;
                const realSub = document.getElementById('rightPanelRealNameSubtext');
                const editNickBtn = document.getElementById('rightPanelEditNicknameBtn');

                if (isViewingMyProfile) {
                    if (realSub) realSub.classList.add('hidden');
                    if (editNickBtn) editNickBtn.classList.add('hidden');
                    const givenBanner = document.getElementById('rightPanelGivenNicknameBanner');
                    if (givenBanner) givenBanner.classList.add('hidden');
                } else {
                    if (editNickBtn) editNickBtn.classList.remove('hidden');
                    if (realSub) {
                        if (u.nickname && u.real_name && u.nickname !== u.real_name) {
                            realSub.innerText = `Original Name: ${u.real_name}`;
                            realSub.classList.remove('hidden');
                        } else {
                            realSub.classList.add('hidden');
                        }
                    }

                    // If the other person set a nickname for me, display it
                    const givenBanner = document.getElementById('rightPanelGivenNicknameBanner');
                    const givenText = document.getElementById('rightPanelGivenNicknameText');
                    if (givenBanner && givenText) {
                        if (u.nickname_by_them && u.nickname_by_them.trim() !== '') {
                            givenText.innerText = `Calls you: "${u.nickname_by_them}"`;
                            givenBanner.classList.remove('hidden');
                            givenBanner.classList.add('inline-flex');
                        } else {
                            givenBanner.classList.add('hidden');
                            givenBanner.classList.remove('inline-flex');
                        }
                    }
                }

                document.getElementById('rightPanelSubtext').innerText = `@${u.username}`;
                document.getElementById('rightPanelUserId').innerText = `#${u.id}`;
                document.getElementById('rightPanelEmail').innerText = u.email;
                document.getElementById('rightPanelJoined').innerText = u.member_since;

                // About section text
                const aboutEl = document.getElementById('rightPanelAboutText');
                const aboutInput = document.getElementById('rightPanelAboutInput');
                const editAboutBtn = document.getElementById('rightPanelEditAboutBtn');

                const currentAbout = u.about || 'Available';
                if (aboutEl) {
                    aboutEl.innerText = currentAbout;
                }
                if (aboutInput) {
                    aboutInput.value = currentAbout;
                }

                // Toggle edit about button (only visible when viewing own profile)
                if (editAboutBtn) {
                    if (isViewingMyProfile) {
                        editAboutBtn.classList.remove('hidden');
                    } else {
                        editAboutBtn.classList.add('hidden');
                    }
                }
                toggleEditAboutMode(false);

                const lastSeenDesc = document.getElementById('rightPanelLastSeenDesc');
                if (lastSeenDesc) {
                    lastSeenDesc.innerText = u.is_online ? 'Active now' : (u.last_seen_text || 'DigiInk Team Member');
                }

                // Role badge
                const rBadge = document.getElementById('rightPanelRoleBadge');
                if (rBadge) {
                    rBadge.innerText = u.role_label;
                    rBadge.className = `text-[10px] font-extrabold px-3 py-0.5 rounded-full border uppercase tracking-wider ${getRoleClass(u.role)}`;
                }

                // Presence badge & dot
                const pDot = document.getElementById('rightPanelOnlineDot');
                const pText = document.getElementById('rightPanelPresenceText');
                const pBadge = document.getElementById('rightPanelPresenceBadge');
                if (u.is_online) {
                    if (pDot) pDot.className = "absolute bottom-2 right-2 w-5 h-5 rounded-full bg-emerald-500 border-2 border-white ring-2 ring-emerald-200 animate-pulse";
                    if (pText) pText.innerText = "Online";
                    if (pBadge) pBadge.className = "inline-flex items-center gap-1 text-[11px] font-bold px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200/90 shadow-2xs";
                } else {
                    if (pDot) pDot.className = "absolute bottom-2 right-2 w-5 h-5 rounded-full bg-slate-400 border-2 border-white";
                    if (pText) pText.innerText = u.last_seen_text || "Offline";
                    if (pBadge) pBadge.className = "inline-flex items-center gap-1 text-[11px] font-bold px-2.5 py-0.5 rounded-full bg-slate-100 text-slate-600 border border-slate-200 shadow-2xs";
                }

                // Big Avatar
                renderRightPanelBigAvatar(u.profile_image, u.name);

                // Media, links and docs section
                renderRightPanelMedia(data.media || [], data.media_count || 0);

            } catch (err) {
                console.error("Profile load error:", err);
                alert("Failed to load profile details.");
                closeContactInfoPanel();
            }
        }

        function renderRightPanelBigAvatar(imgUrl, name) {
            const bigAvatar = document.getElementById('rightPanelBigAvatar');
            if (!bigAvatar) return;
            if (imgUrl) {
                bigAvatar.innerHTML = `<img id="rightPanelBigImg" src="${escapeHtml(imgUrl)}" alt="${escapeHtml(name)}" class="w-full h-full object-cover" onerror="renderRightPanelAvatarFallback('${escapeHtml(name.substring(0, 1).toUpperCase())}')" />`;
            } else {
                renderRightPanelAvatarFallback(name.substring(0, 1).toUpperCase());
            }
        }

        function renderRightPanelAvatarFallback(initial) {
            const bigAvatar = document.getElementById('rightPanelBigAvatar');
            if (bigAvatar) {
                bigAvatar.innerHTML = `<span id="rightPanelBigInitial" class="text-4xl font-black text-white select-none">${escapeHtml(initial)}</span>`;
            }
        }

        function renderRightPanelMedia(mediaItems, totalCount) {
            const countEl = document.getElementById('rightPanelMediaCount');
            if (countEl) countEl.innerText = totalCount;

            const gridEl = document.getElementById('rightPanelMediaGrid');
            if (!gridEl) return;

            if (!mediaItems || mediaItems.length === 0) {
                gridEl.innerHTML = `
                    <div class="col-span-4 py-8 text-center text-slate-400 text-xs">
                        <span class="material-icons-round text-3xl text-slate-300 mb-1">perm_media</span>
                        <p class="font-medium">No media, links or docs</p>
                    </div>`;
                return;
            }

            let html = '';
            mediaItems.forEach(item => {
                const filePath = `../../${escapeHtml(item.file_path)}`;
                const fileName = escapeHtml(item.file_name || 'attachment');
                const fileType = item.file_type || 'file';
                const rawPathEsc = escapeHtml(item.file_path || '').replace(/'/g, "\\'");
                const rawNameEsc = escapeHtml(item.file_name || 'attachment').replace(/'/g, "\\'");
                const fSize = parseInt(item.file_size) || 0;

                if (fileType === 'image') {
                    html += `
                        <div class="group relative aspect-square rounded-xl bg-slate-900 overflow-hidden shadow-2xs hover:shadow-md transition cursor-pointer"
                            onclick="openMediaDownloadPrompt('${rawPathEsc}', '${rawNameEsc}', 'image', ${fSize})" title="Download ${fileName}">
                            <img src="${filePath}" alt="${fileName}" class="w-full h-full object-cover group-hover:scale-105 transition duration-200" loading="lazy" />
                            <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition flex items-center justify-center text-white">
                                <span class="material-icons-round text-lg drop-shadow">download</span>
                            </div>
                        </div>`;
                } else if (fileType === 'video') {
                    html += `
                        <div class="group relative aspect-square rounded-xl bg-slate-800 overflow-hidden shadow-2xs hover:shadow-md transition flex items-center justify-center text-white cursor-pointer"
                            onclick="openMediaDownloadPrompt('${rawPathEsc}', '${rawNameEsc}', 'video', ${fSize})" title="Download ${fileName}">
                            <span class="material-icons-round text-2xl text-slate-300 group-hover:text-white transition">videocam</span>
                            <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition flex items-center justify-center text-white">
                                <span class="material-icons-round text-lg drop-shadow">download</span>
                            </div>
                        </div>`;
                } else {
                    html += `
                        <a href="${filePath}" target="_blank" download="${fileName}" title="${fileName}"
                            class="group relative aspect-square rounded-xl bg-indigo-50 border border-indigo-100/90 overflow-hidden shadow-2xs hover:shadow-md transition flex flex-col items-center justify-center text-indigo-700 p-1">
                            <span class="material-icons-round text-xl text-indigo-600 mb-0.5">${getFileIcon(fileType)}</span>
                            <span class="text-[9px] font-bold truncate max-w-full text-center px-1 text-slate-600">${fileName}</span>
                            <div class="absolute inset-0 bg-indigo-900/60 opacity-0 group-hover:opacity-100 transition flex items-center justify-center text-white">
                                <span class="material-icons-round text-lg drop-shadow">download</span>
                            </div>
                        </a>`;
                }
            });

            gridEl.innerHTML = html;
        }

        function closeContactInfoPanel() {
            const panel = document.getElementById('contactInfoRightPanel');
            if (panel) panel.classList.add('hidden');
            const rightResizer = document.getElementById('rightPanelResizer');
            if (rightResizer) rightResizer.classList.add('hidden');
            activeProfileUserId = null;
        }

        function closeUserProfileModal() {
            closeContactInfoPanel();
        }

        /* ========================================================
           ABOUT STATUS CONTROLLER (WhatsApp Style Editable Status)
           ======================================================== */
        function toggleEditAboutMode(show) {
            const viewMode = document.getElementById('rightPanelAboutViewMode');
            const editMode = document.getElementById('rightPanelAboutEditMode');
            if (!viewMode || !editMode) return;

            if (show) {
                viewMode.classList.add('hidden');
                editMode.classList.remove('hidden');
                const inp = document.getElementById('rightPanelAboutInput');
                if (inp) {
                    const currentTxt = document.getElementById('rightPanelAboutText')?.innerText?.trim();
                    if (currentTxt && currentTxt !== 'Loading status...') {
                        inp.value = currentTxt;
                    }
                    inp.focus();
                    updateAboutCharCount();
                }
            } else {
                viewMode.classList.remove('hidden');
                editMode.classList.add('hidden');
            }
        }

        function selectAboutPreset(text) {
            const inp = document.getElementById('rightPanelAboutInput');
            if (inp) {
                inp.value = text;
                updateAboutCharCount();
            }
        }

        function updateAboutCharCount() {
            const inp = document.getElementById('rightPanelAboutInput');
            const counter = document.getElementById('rightPanelAboutCharCount');
            if (inp && counter) {
                const max = parseInt(inp.getAttribute('maxlength') || '140', 10);
                const remaining = max - (inp.value ? inp.value.length : 0);
                counter.textContent = remaining;
                if (remaining < 0) {
                    counter.className = "absolute right-2.5 bottom-2 text-[10px] text-rose-500 font-bold";
                } else {
                    counter.className = "absolute right-2.5 bottom-2 text-[10px] text-slate-400 font-bold";
                }
            }
        }

        async function submitSaveAbout() {
            const inp = document.getElementById('rightPanelAboutInput');
            if (!inp) return;

            let aboutVal = inp.value.trim();
            if (!aboutVal) {
                aboutVal = 'Available';
            }

            const btn = document.getElementById('btnSaveAbout');
            const origHtml = btn ? btn.innerHTML : '';
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="material-icons-round text-sm animate-spin">sync</span><span>Saving...</span>';
            }

            try {
                const fd = new FormData();
                fd.append('action', 'update_about');
                fd.append('about', aboutVal);

                const res = await fetch('api.php', {
                    method: 'POST',
                    body: fd
                });
                const data = await res.json();

                if (data.success) {
                    const finalAbout = data.about || aboutVal;
                    const aboutTextEl = document.getElementById('rightPanelAboutText');
                    if (aboutTextEl) {
                        aboutTextEl.innerText = finalAbout;
                    }
                    inp.value = finalAbout;
                    toggleEditAboutMode(false);

                    // Update cached user object if applicable
                    if (typeof allContacts !== 'undefined' && Array.isArray(allContacts)) {
                        const meContact = allContacts.find(c => String(c.id) === String(myUserId));
                        if (meContact) meContact.about = finalAbout;
                    }
                } else {
                    alert(data.error || 'Failed to update about status');
                }
            } catch (err) {
                console.error("Error updating about status:", err);
                alert("Network error updating about status. Please try again.");
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = origHtml || '<span class="material-icons-round text-sm">check</span><span>Save</span>';
                }
            }
        }

        /* Call buttons on Right Panel Drawer */
        function handleRightPanelCall(type) {
            if (activeContactId && activeProfileUserId === activeContactId) {
                startCall(type);
            } else if (activeProfileUserId) {
                // Select contact first then call
                selectContact(activeProfileUserId).then(() => {
                    startCall(type);
                });
            } else {
                startCall(type);
            }
        }

        /* Search button on Right Panel Drawer */
        function handleRightPanelSearch() {
            toggleInChatSearch(true);
        }

        /* ========================================================
           IN-CHAT MESSAGE SEARCH CONTROLLER
           ======================================================== */
        let inChatSearchMatches = [];
        let inChatSearchCurrentIndex = -1;

        function toggleInChatSearch(forceOpen = false) {
            const searchBar = document.getElementById('inChatSearchBar');
            const searchInput = document.getElementById('inChatSearchInput');
            if (!searchBar) return;

            if (forceOpen || searchBar.classList.contains('hidden')) {
                searchBar.classList.remove('hidden');
                if (searchInput) {
                    searchInput.focus();
                    if (searchInput.value.trim()) {
                        handleInChatSearchInput(searchInput.value);
                    }
                }
            } else {
                closeInChatSearch();
            }
        }

        function closeInChatSearch() {
            const searchBar = document.getElementById('inChatSearchBar');
            const searchInput = document.getElementById('inChatSearchInput');
            if (searchBar) searchBar.classList.add('hidden');
            if (searchInput) searchInput.value = '';
            clearInChatSearchHighlights();
            inChatSearchMatches = [];
            inChatSearchCurrentIndex = -1;
            updateInChatSearchCountUI();
        }

        function handleInChatSearchKeydown(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                navigateInChatSearch(e.shiftKey ? 'prev' : 'next');
            } else if (e.key === 'Escape') {
                e.preventDefault();
                closeInChatSearch();
            }
        }

        function handleInChatSearchInput(val) {
            clearInChatSearchHighlights();
            inChatSearchMatches = [];
            inChatSearchCurrentIndex = -1;

            const query = (val || '').trim().toLowerCase();
            if (!query) {
                updateInChatSearchCountUI();
                return;
            }

            const container = document.getElementById('messagesContainer');
            if (!container) return;

            // Find all rendered message text bubbles
            const textNodes = container.querySelectorAll('.chat-text');
            textNodes.forEach(node => {
                const row = node.closest('.message-row');
                const originalText = node.innerText;
                const lowerText = originalText.toLowerCase();

                if (lowerText.includes(query)) {
                    // Highlight query in text node
                    const regex = new RegExp(`(${query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
                    node.innerHTML = escapeHtml(originalText).replace(regex, '<mark class="bg-amber-300 text-slate-900 rounded-xs px-0.5 font-bold in-chat-highlight">$1</mark>');
                    if (row) inChatSearchMatches.push(row);
                }
            });

            if (inChatSearchMatches.length > 0) {
                inChatSearchCurrentIndex = inChatSearchMatches.length - 1; // start with latest match
                highlightCurrentSearchMatch();
            }

            updateInChatSearchCountUI();
        }

        function navigateInChatSearch(direction) {
            if (inChatSearchMatches.length === 0) return;

            if (direction === 'next') {
                inChatSearchCurrentIndex = (inChatSearchCurrentIndex + 1) % inChatSearchMatches.length;
            } else {
                inChatSearchCurrentIndex = (inChatSearchCurrentIndex - 1 + inChatSearchMatches.length) % inChatSearchMatches.length;
            }

            highlightCurrentSearchMatch();
            updateInChatSearchCountUI();
        }

        function highlightCurrentSearchMatch() {
            if (inChatSearchMatches.length === 0 || inChatSearchCurrentIndex < 0) return;

            // Remove active focus ring from all rows
            document.querySelectorAll('.in-chat-active-match').forEach(el => el.classList.remove('in-chat-active-match', 'ring-2', 'ring-amber-500'));

            const targetRow = inChatSearchMatches[inChatSearchCurrentIndex];
            if (targetRow) {
                targetRow.classList.add('in-chat-active-match', 'ring-2', 'ring-amber-500', 'rounded-2xl');
                targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        function clearInChatSearchHighlights() {
            document.querySelectorAll('.in-chat-highlight').forEach(mark => {
                const parent = mark.parentNode;
                if (parent) {
                    parent.innerText = parent.innerText; // strips marks
                }
            });
            document.querySelectorAll('.in-chat-active-match').forEach(el => el.classList.remove('in-chat-active-match', 'ring-2', 'ring-amber-500'));
        }

        function updateInChatSearchCountUI() {
            const countEl = document.getElementById('inChatSearchCount');
            const prevBtn = document.getElementById('inChatSearchPrevBtn');
            const nextBtn = document.getElementById('inChatSearchNextBtn');

            if (!countEl) return;

            if (inChatSearchMatches.length === 0) {
                countEl.innerText = "0 results";
                if (prevBtn) prevBtn.disabled = true;
                if (nextBtn) nextBtn.disabled = true;
            } else {
                countEl.innerText = `${inChatSearchCurrentIndex + 1} of ${inChatSearchMatches.length}`;
                if (prevBtn) prevBtn.disabled = false;
                if (nextBtn) nextBtn.disabled = false;
            }
        }

        /* ========================================================
           WHATSAPP STYLE DP CROP CONTROLLER (Cropper.js)
           ======================================================== */
        let dpCropperInstance = null;
        let dpCropSourceBlobUrl = null;

        /* Open selected image file in Cropper Modal */
        function openDpCropModalWithImage(imgUrl) {
            const modal = document.getElementById('dpCropModal');
            const imgEl = document.getElementById('dpCropImageElement');
            if (!modal || !imgEl) return;

            // Destroy any previous instance
            if (dpCropperInstance) {
                dpCropperInstance.destroy();
                dpCropperInstance = null;
            }

            imgEl.src = imgUrl;
            modal.classList.remove('hidden');

            // Initialize Cropper with WhatsApp circular avatar aspect ratio
            setTimeout(() => {
                dpCropperInstance = new Cropper(imgEl, {
                    aspectRatio: 1,
                    viewMode: 1,
                    dragMode: 'move',
                    autoCropArea: 0.85,
                    restore: false,
                    guides: true,
                    center: true,
                    highlight: false,
                    cropBoxMovable: true,
                    cropBoxResizable: true,
                    toggleDragModeOnDblclick: false,
                    responsive: true,
                    background: false
                });
            }, 100);
        }

        function rotateDpCrop(degrees) {
            if (dpCropperInstance) {
                dpCropperInstance.rotate(degrees);
            }
        }

        function cancelDpCrop() {
            const modal = document.getElementById('dpCropModal');
            if (modal) modal.classList.add('hidden');
            if (dpCropperInstance) {
                dpCropperInstance.destroy();
                dpCropperInstance = null;
            }
            if (dpCropSourceBlobUrl) {
                URL.revokeObjectURL(dpCropSourceBlobUrl);
                dpCropSourceBlobUrl = null;
            }
            const fileInp = document.getElementById('profileDpFileInput');
            if (fileInp) fileInp.value = '';
        }

        async function confirmAndSaveCroppedDp() {
            if (!dpCropperInstance) return;

            const saveBtn = document.getElementById('dpCropSaveBtn');
            const btnText = document.getElementById('dpCropSaveBtnText');
            if (saveBtn) saveBtn.disabled = true;
            if (btnText) btnText.innerText = 'Saving...';

            try {
                // Export cropped image at 512x512 resolution (clean circular avatar)
                const canvas = dpCropperInstance.getCroppedCanvas({
                    width: 512,
                    height: 512,
                    imageSmoothingEnabled: true,
                    imageSmoothingQuality: 'high'
                });

                canvas.toBlob(async (blob) => {
                    if (!blob) {
                        alert("Could not process cropped image.");
                        if (saveBtn) saveBtn.disabled = false;
                        if (btnText) btnText.innerText = 'Set Profile Photo';
                        return;
                    }

                    const croppedFile = new File([blob], `dp_crop_${Date.now()}.jpg`, { type: 'image/jpeg' });
                    const formData = new FormData();
                    formData.append('action', 'upload_profile_image');
                    formData.append('profile_image', croppedFile);

                    try {
                        await sendDpUploadRequest(formData);
                        cancelDpCrop();
                    } finally {
                        if (saveBtn) saveBtn.disabled = false;
                        if (btnText) btnText.innerText = 'Set Profile Photo';
                    }
                }, 'image/jpeg', 0.92);

            } catch (err) {
                console.error("Crop save error:", err);
                alert("Failed to crop image.");
                if (saveBtn) saveBtn.disabled = false;
                if (btnText) btnText.innerText = 'Set Profile Photo';
            }
        }

        /* Trigger gallery / disk file picker */
        function triggerDpFilePicker() {
            if (!isViewingMyProfile) return;
            const fileInp = document.getElementById('profileDpFileInput');
            if (fileInp) {
                fileInp.value = '';
                fileInp.click();
            }
        }

        /* Handle user selected image file from Gallery/Computer -> Routes to Cropper */
        async function handleDpFileSelected(e) {
            const file = e.target.files?.[0];
            if (!file) return;

            if (!file.type.startsWith('image/')) {
                alert("Please select a valid image file (JPG, PNG, WEBP)");
                return;
            }

            if (file.size > 15 * 1024 * 1024) {
                alert("Image size should be less than 15MB");
                return;
            }

            if (dpCropSourceBlobUrl) {
                URL.revokeObjectURL(dpCropSourceBlobUrl);
            }
            dpCropSourceBlobUrl = URL.createObjectURL(file);
            openDpCropModalWithImage(dpCropSourceBlobUrl);
        }

        /* Open camera specifically for capturing a new DP selfie */
        function openDpCameraCapture() {
            if (!isViewingMyProfile) return;
            openCameraModal('dp');
        }

        /* Upload photo blob taken with Camera -> Routes to Cropper */
        async function uploadCapturedPhotoAsDp(blob) {
            closeCameraModal();
            if (dpCropSourceBlobUrl) {
                URL.revokeObjectURL(dpCropSourceBlobUrl);
            }
            dpCropSourceBlobUrl = URL.createObjectURL(blob);
            openDpCropModalWithImage(dpCropSourceBlobUrl);
        }

        /* Submit DP to server and update all UI places immediately */
        async function sendDpUploadRequest(formData) {
            const bigAvatar = document.getElementById('rightPanelBigAvatar');
            if (bigAvatar) {
                bigAvatar.innerHTML = `
                    <div class="flex flex-col items-center justify-center text-white">
                        <span class="material-icons-round animate-spin text-3xl">sync</span>
                        <span class="text-[10px] font-bold mt-1">Uploading...</span>
                    </div>`;
            }

            try {
                const res = await fetch('api.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success && data.image_url) {
                    const newImageUrl = data.image_url;

                    // 1. Update right panel avatar immediately
                    const myName = document.getElementById('myTopName')?.innerText || 'User';
                    renderRightPanelBigAvatar(newImageUrl, myName);

                    // 2. Update Top Bar Header avatar
                    const topAv = document.getElementById('myTopAvatar');
                    if (topAv) {
                        topAv.innerHTML = `<img src="${escapeHtml(newImageUrl)}" alt="My Profile" class="w-full h-full object-cover rounded-full" />`;
                    }

                    // 3. Update Active Chat Header avatar if my own chat or reflected
                    if (activeContactId === myUserId) {
                        const actAv = document.getElementById('activeAvatar');
                        if (actAv) {
                            actAv.innerHTML = `<img src="${escapeHtml(newImageUrl)}" alt="My Profile" class="w-full h-full object-cover rounded-full" />`;
                        }
                    }

                    // 4. Update contactsData cache and re-render sidebar
                    const myContactItem = contactsData.find(c => parseInt(c.id) === myUserId);
                    if (myContactItem) {
                        myContactItem.profile_image = newImageUrl;
                    }
                    renderContactsList();

                    showQuickToast("Profile picture updated successfully! ✨");
                } else {
                    alert(data.error || "Failed to update profile picture");
                    openUserProfileModal(myUserId); // reload original
                }
            } catch (err) {
                console.error("DP upload error:", err);
                alert("Failed to upload profile picture. Please try again.");
                openUserProfileModal(myUserId);
            }
        }

        /* ========================================================
           PROFILE PICTURE (DP) FULLSCREEN ZOOM LIGHTBOX
           ======================================================== */
        function openDpZoomModal(imgUrl, name, subtitle) {
            const modal = document.getElementById('dpZoomModal');
            const imgEl = document.getElementById('dpZoomImg');
            const fallbackEl = document.getElementById('dpZoomFallback');
            const titleEl = document.getElementById('dpZoomTitle');
            const subEl = document.getElementById('dpZoomSub');
            const downloadBtn = document.getElementById('dpZoomDownloadBtn');

            if (!modal) return;

            const displayName = name || 'Profile Photo';
            if (titleEl) titleEl.innerText = displayName;
            if (subEl) subEl.innerText = subtitle || 'DigiInk Team Member';

            if (imgUrl && typeof imgUrl === 'string' && imgUrl.trim() !== '') {
                if (imgEl) {
                    imgEl.src = imgUrl;
                    imgEl.classList.remove('hidden');
                }
                if (fallbackEl) fallbackEl.classList.add('hidden');
            } else {
                if (imgEl) {
                    imgEl.src = '';
                    imgEl.classList.add('hidden');
                }
                if (fallbackEl) {
                    const initial = (displayName.charAt(0) || 'U').toUpperCase();
                    fallbackEl.innerText = initial;
                    fallbackEl.classList.remove('hidden');
                }
            }

            modal.classList.remove('hidden');
        }

        function closeDpZoomModal() {
            const modal = document.getElementById('dpZoomModal');
            if (modal) modal.classList.add('hidden');
            const imgEl = document.getElementById('dpZoomImg');
            if (imgEl) imgEl.src = '';
        }

        function handleDpZoomBackdropClick(e) {
            if (e.target && e.target.id === 'dpZoomModal') {
                closeDpZoomModal();
            }
        }

        function zoomActiveContactDp() {
            if (activeGroupId) {
                openGroupInfoModal();
                return;
            }
            if (!activeContactId) return;
            const contact = contactsData.find(c => parseInt(c.id) === parseInt(activeContactId));
            const name = contact ? contact.name : (document.getElementById('activeName')?.innerText || 'Team Member');
            const role = contact ? (contact.role_label || contact.role) : 'DigiInk Member';
            const img = contact ? contact.profile_image : null;
            openDpZoomModal(img, name, role);
        }

        function zoomMyDp() {
            const myName = document.getElementById('myTopName')?.innerText || 'My Profile';
            const topImg = document.querySelector('#myTopAvatar img')?.getAttribute('src');
            openDpZoomModal(topImg, myName, 'Your Profile');
        }

        function zoomRightPanelDp() {
            const name = document.getElementById('rightPanelName')?.innerText || 'Profile';
            const sub = document.getElementById('rightPanelSubtext')?.innerText || 'DigiInk Member';
            const imgEl = document.getElementById('rightPanelBigImg');
            const img = imgEl ? imgEl.getAttribute('src') : null;
            openDpZoomModal(img, name, sub);
        }

        /* ========================================================
           INTERACTIVE SPLITTER DRAG RESIZING (Left Sidebar & Right Profile Panel)
           ======================================================== */
        (function initPanelResizers() {
            const leftResizer = document.getElementById('leftPanelResizer');
            const rightResizer = document.getElementById('rightPanelResizer');
            const sidebar = document.getElementById('chatSidebar');
            const rightPanel = document.getElementById('contactInfoRightPanel');

            // Restore saved widths if any
            const savedLeftW = localStorage.getItem('digichat_sidebar_w');
            if (savedLeftW && sidebar && window.innerWidth >= 640) {
                sidebar.style.width = `${Math.min(550, Math.max(260, parseInt(savedLeftW)))}px`;
            }

            const savedRightW = localStorage.getItem('digichat_right_panel_w');
            if (savedRightW && rightPanel && window.innerWidth >= 640) {
                rightPanel.style.width = `${Math.min(550, Math.max(280, parseInt(savedRightW)))}px`;
            }

            // --- Left Sidebar Resizer ---
            if (leftResizer && sidebar) {
                let isDraggingLeft = false;

                leftResizer.addEventListener('mousedown', (e) => {
                    if (window.innerWidth < 640) return;
                    isDraggingLeft = true;
                    leftResizer.classList.add('is-resizing');
                    document.body.style.cursor = 'col-resize';
                    document.body.style.userSelect = 'none';
                    e.preventDefault();
                });

                document.addEventListener('mousemove', (e) => {
                    if (!isDraggingLeft) return;
                    const containerLeft = sidebar.parentElement.getBoundingClientRect().left;
                    let newWidth = e.clientX - containerLeft;

                    // Min 260px, Max 550px or 45% of screen
                    const maxAllowed = Math.min(550, window.innerWidth * 0.45);
                    newWidth = Math.max(260, Math.min(newWidth, maxAllowed));

                    sidebar.style.width = `${newWidth}px`;
                });

                document.addEventListener('mouseup', () => {
                    if (isDraggingLeft) {
                        isDraggingLeft = false;
                        leftResizer.classList.remove('is-resizing');
                        document.body.style.cursor = '';
                        document.body.style.userSelect = '';
                        localStorage.setItem('digichat_sidebar_w', parseInt(sidebar.style.width));
                    }
                });
            }

            // --- Right Profile Drawer Resizer ---
            if (rightResizer && rightPanel) {
                let isDraggingRight = false;

                rightResizer.addEventListener('mousedown', (e) => {
                    if (window.innerWidth < 640) return;
                    isDraggingRight = true;
                    rightResizer.classList.add('is-resizing');
                    document.body.style.cursor = 'col-resize';
                    document.body.style.userSelect = 'none';
                    e.preventDefault();
                });

                document.addEventListener('mousemove', (e) => {
                    if (!isDraggingRight) return;
                    let newWidth = window.innerWidth - e.clientX;

                    // Min 280px, Max 550px or 45% of screen
                    const maxAllowed = Math.min(550, window.innerWidth * 0.45);
                    newWidth = Math.max(280, Math.min(newWidth, maxAllowed));

                    rightPanel.style.width = `${newWidth}px`;
                });

                document.addEventListener('mouseup', () => {
                    if (isDraggingRight) {
                        isDraggingRight = false;
                        rightResizer.classList.remove('is-resizing');
                        document.body.style.cursor = '';
                        document.body.style.userSelect = '';
                        localStorage.setItem('digichat_right_panel_w', parseInt(rightPanel.style.width));
                    }
                });
            }
        })();

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                hideChatContextMenu();
                closeCameraModal();
                closeViewOnceModal();
                closeDpZoomModal();
                cancelDpCrop();
                closeContactInfoPanel();
                closeInChatSearch();
            }
        });
    </script>

    <!-- ========================================================
         CALL RECORDING PLAYBACK MODAL (Clean White Theme UI)
         ======================================================== -->
    <div id="callRecordingModal" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl shadow-2xl max-w-xl w-full overflow-hidden border border-slate-200/80 animate-in fade-in zoom-in-95 duration-200">
            <!-- Modal Header (Clean White) -->
            <div class="px-6 py-4 bg-white flex items-center justify-between border-b border-slate-100">
                <div class="flex items-center gap-3.5">
                    <div id="recModalIconWrapper" class="w-11 h-11 rounded-2xl bg-indigo-50 text-indigo-600 border border-indigo-100 flex items-center justify-center shadow-xs">
                        <span id="recModalIcon" class="material-icons-round text-2xl">videocam</span>
                    </div>
                    <div>
                        <h3 id="recModalTitle" class="text-sm font-bold text-slate-900 leading-tight">Call Recording</h3>
                        <div class="flex items-center gap-2 mt-0.5">
                            <span id="recModalSubtitle" class="text-xs text-slate-500 font-medium">Recorded Call</span>
                            <span id="recModalTypeBadge" class="text-[10px] font-bold uppercase tracking-wider text-indigo-700 bg-indigo-50 px-2 py-0.5 rounded-md border border-indigo-100">Voice Call</span>
                        </div>
                    </div>
                </div>
                <button type="button" onclick="closeCallRecordingModal()" class="w-9 h-9 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-800 flex items-center justify-center transition active:scale-95" title="Close">
                    <span class="material-icons-round text-lg">close</span>
                </button>
            </div>
            
            <!-- Video Call Player Wrapper -->
            <div id="recVideoWrapper" class="p-5 bg-slate-50/50 flex flex-col items-center justify-center">
                <div class="w-full bg-black rounded-2xl overflow-hidden shadow-inner border border-slate-200 flex items-center justify-center">
                    <video id="callRecordingPlayer" controls class="w-full max-h-[380px] object-contain"></video>
                </div>
            </div>

            <!-- Voice Call Audio Player Wrapper (Clean White & Audio Waveform Card) -->
            <div id="recVoiceWrapper" class="hidden p-6 bg-slate-50/50 flex flex-col items-center justify-center">
                <div class="w-full bg-white rounded-2xl p-6 border border-slate-200/80 shadow-xs flex flex-col items-center text-center">
                    <!-- Avatar circle with glowing ring -->
                    <div class="relative mb-3">
                        <div id="recVoiceAvatar" class="w-16 h-16 rounded-2xl bg-gradient-to-tr from-emerald-500 to-teal-400 text-white font-black text-2xl flex items-center justify-center shadow-md">
                            A
                        </div>
                        <div class="absolute -bottom-1 -right-1 w-6 h-6 rounded-full bg-white shadow-xs border border-slate-200 flex items-center justify-center text-emerald-600">
                            <span class="material-icons-round text-xs">call</span>
                        </div>
                    </div>

                    <h4 id="recVoicePeerName" class="text-sm font-bold text-slate-900">User</h4>
                    <p id="recVoiceMeta" class="text-[11px] text-slate-500 mt-0.5">DigiChat Voice Call Recording</p>

                    <!-- Animated Sound Bars Visualizer -->
                    <div class="flex items-center gap-1.5 my-4 h-7">
                        <span class="w-1 bg-emerald-500 rounded-full animate-pulse h-3"></span>
                        <span class="w-1 bg-emerald-600 rounded-full animate-pulse h-6"></span>
                        <span class="w-1 bg-emerald-400 rounded-full animate-pulse h-4"></span>
                        <span class="w-1 bg-emerald-500 rounded-full animate-pulse h-7"></span>
                        <span class="w-1 bg-emerald-600 rounded-full animate-pulse h-5"></span>
                        <span class="w-1 bg-emerald-400 rounded-full animate-pulse h-3"></span>
                        <span class="w-1 bg-emerald-500 rounded-full animate-pulse h-6"></span>
                    </div>

                    <!-- Clean Audio Player -->
                    <div class="w-full bg-slate-50 rounded-xl p-2 border border-slate-200/60 shadow-inner">
                        <audio id="callRecordingAudioPlayer" controls class="w-full"></audio>
                    </div>
                </div>
            </div>

            <!-- Modal Footer (Clean White) -->
            <div class="px-6 py-4 bg-white border-t border-slate-100 flex items-center justify-between">
                <div class="flex items-center gap-1.5 text-xs text-slate-500 font-medium">
                    <span class="material-icons-round text-sm text-emerald-600">verified</span>
                    <span>Saved in Calls Log</span>
                </div>
                <div class="flex items-center gap-2.5">
                    <a id="recDownloadBtn" href="#" download="call_recording.webm" target="_blank" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs rounded-xl transition shadow-xs flex items-center gap-1.5 active:scale-95">
                        <span class="material-icons-round text-sm">download</span>
                        <span>Download</span>
                    </a>
                    <button type="button" onclick="closeCallRecordingModal()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs rounded-xl transition active:scale-95">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================
         CHAT RIGHT-CLICK CONTEXT MENU (WhatsApp Style)
         ======================================================== -->
    <div id="chatContextMenu" class="hidden fixed z-50 bg-white/95 backdrop-blur-md rounded-2xl shadow-xl border border-slate-200/80 py-1.5 min-w-[170px] animate-in fade-in zoom-in-95 duration-100 select-none">
        <button type="button" onclick="handleContextViewProfile()" class="w-full px-4 py-2 text-left text-xs font-semibold text-slate-700 hover:bg-indigo-50 hover:text-indigo-600 transition flex items-center gap-2.5">
            <span class="material-icons-round text-base text-indigo-500">account_circle</span>
            <span>View Profile</span>
        </button>
        <button type="button" id="ctxMenuNicknameBtn" onclick="handleContextSetNickname()" class="w-full px-4 py-2 text-left text-xs font-semibold text-slate-700 hover:bg-amber-50 hover:text-amber-700 transition flex items-center gap-2.5">
            <span class="material-icons-round text-base text-amber-500">badge</span>
            <span id="ctxMenuNicknameText">Set Nickname</span>
        </button>
        <button type="button" onclick="toggleContextPinChat()" class="w-full px-4 py-2 text-left text-xs font-semibold text-slate-700 hover:bg-indigo-50 hover:text-indigo-600 transition flex items-center gap-2.5">
            <span id="ctxMenuPinIcon" class="material-icons-round text-base text-slate-400">push_pin</span>
            <span id="ctxMenuPinText">Pin chat</span>
        </button>
    </div>

    <!-- ========================================================
         WHATSAPP-STYLE LIVE CAMERA MODAL (Clean White UI)
         ======================================================== -->
    <div id="cameraModal" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl shadow-2xl max-w-lg w-full overflow-hidden border border-slate-200/80 animate-in fade-in zoom-in-95 duration-200">
            <!-- Modal Header -->
            <div class="px-6 py-4 bg-white flex items-center justify-between border-b border-slate-100">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-indigo-50 text-indigo-600 border border-indigo-100 flex items-center justify-center shadow-xs">
                        <span class="material-icons-round text-xl">photo_camera</span>
                    </div>
                    <div>
                        <h3 id="cameraModalTitle" class="text-sm font-bold text-slate-900">Take Photo</h3>
                        <p id="cameraModalDesc" class="text-xs text-slate-500">Capture & send directly to chat</p>
                    </div>
                </div>
                <button type="button" onclick="closeCameraModal()" class="w-9 h-9 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-800 flex items-center justify-center transition active:scale-95" title="Close">
                    <span class="material-icons-round text-lg">close</span>
                </button>
            </div>

            <!-- Modal Body: Live View or Captured Preview -->
            <div class="p-5 bg-slate-50/50 flex flex-col items-center justify-center">
                
                <!-- 1. Live Viewfinder -->
                <div id="cameraLiveView" class="w-full flex flex-col items-center">
                    <div class="relative w-full aspect-[4/3] bg-black rounded-2xl overflow-hidden shadow-inner border border-slate-200 flex items-center justify-center">
                        <video id="cameraStreamVideo" autoplay playsinline muted class="w-full h-full object-cover transform -scale-x-100"></video>
                        <canvas id="cameraCanvas" class="hidden"></canvas>
                        
                        <!-- Switch Camera Flip Button -->
                        <button type="button" onclick="switchCameraFacing()" title="Flip Camera" 
                            class="absolute top-3 right-3 w-9 h-9 rounded-full bg-black/40 hover:bg-black/60 text-white backdrop-blur-md flex items-center justify-center transition active:scale-90">
                            <span class="material-icons-round text-lg">flip_camera_ios</span>
                        </button>
                    </div>

                    <!-- Big Circular Shutter Button -->
                    <div class="mt-5 flex items-center justify-center gap-4">
                        <button type="button" onclick="takeCameraSnapshot()" title="Capture Photo" 
                            class="w-16 h-16 rounded-full bg-white border-4 border-indigo-600 shadow-lg flex items-center justify-center transition hover:scale-105 active:scale-95">
                            <span class="w-12 h-12 rounded-full bg-indigo-600 flex items-center justify-center text-white">
                                <span class="material-icons-round text-2xl">photo_camera</span>
                            </span>
                        </button>
                    </div>
                </div>

                <!-- 2. Captured Photo Review View -->
                <div id="cameraPreviewView" class="hidden w-full flex flex-col items-center">
                    <div class="w-full aspect-[4/3] bg-black rounded-2xl overflow-hidden shadow-inner border border-slate-200 flex items-center justify-center">
                        <img id="cameraCapturedImg" src="" alt="Captured Photo" class="w-full h-full object-contain" />
                    </div>

                    <!-- Caption Input & View Once Toggle -->
                    <div id="cameraCaptionRow" class="w-full mt-3.5 flex items-center gap-2">
                        <input type="text" id="cameraCaptionInput" placeholder="Add a caption..." autocomplete="off"
                            class="flex-1 py-2.5 px-4 bg-white border border-slate-200/80 rounded-xl text-xs focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 outline-none transition font-medium text-slate-800 placeholder-slate-400">

                        <!-- View Once Toggle Button for Camera -->
                        <button type="button" id="cameraViewOnceBtn" onclick="toggleCameraViewOnce()" title="View Once (Photo disappears after opening)"
                            class="w-9 h-9 rounded-full border border-slate-300 text-slate-400 hover:text-indigo-600 hover:border-indigo-400 flex items-center justify-center font-black text-xs transition select-none flex-shrink-0">
                            <span class="w-5 h-5 rounded-full border border-current flex items-center justify-center text-[10px] font-bold">1</span>
                        </button>
                    </div>

                    <!-- Actions Bar: Retake & Send -->
                    <div class="w-full mt-3 flex items-center justify-between">
                        <button type="button" onclick="retakeCameraSnapshot()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs rounded-xl transition flex items-center gap-1.5 active:scale-95">
                            <span class="material-icons-round text-sm">replay</span>
                            <span>Retake</span>
                        </button>

                        <button type="button" id="cameraSendBtn" onclick="sendCameraPhoto()" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs rounded-xl transition shadow-md flex items-center gap-1.5 active:scale-95">
                            <span id="cameraSendBtnText">Send Photo</span>
                            <span id="cameraSendBtnIcon" class="material-icons-round text-sm">send</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================
         VIEW ONCE MEDIA LIGHTBOX MODAL (Clean White & Disappearing)
         ======================================================== -->
    <div id="viewOnceModal" onclick="handleViewOnceBackdropClick(event)" class="hidden fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
        <div id="viewOnceCard" onclick="event.stopPropagation()" class="bg-white rounded-3xl shadow-2xl max-w-lg w-full overflow-hidden border border-slate-200 animate-in fade-in zoom-in-95 duration-200 flex flex-col">
            <!-- Modal Header -->
            <div class="px-6 py-4 bg-white flex items-center justify-between border-b border-slate-100">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-indigo-50 text-indigo-600 border border-indigo-100 flex items-center justify-center shadow-xs">
                        <span class="w-5 h-5 rounded-full border-2 border-indigo-600 flex items-center justify-center text-[10px] font-black">1</span>
                    </div>
                    <div>
                        <h3 class="text-sm font-bold text-slate-900">View Once</h3>
                        <p id="viewOnceDesc" class="text-xs text-slate-500">This photo/media will disappear once closed</p>
                    </div>
                </div>
                <button type="button" onclick="closeViewOnceModal()" class="w-9 h-9 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-800 flex items-center justify-center transition active:scale-95" title="Close">
                    <span class="material-icons-round text-lg">close</span>
                </button>
            </div>

            <!-- Modal Content (Media View) -->
            <div class="p-6 bg-slate-50/50 flex flex-col items-center justify-center min-h-[260px] max-h-[70vh] overflow-y-auto">
                <img id="viewOnceImg" src="" alt="View once photo" class="hidden max-h-[60vh] max-w-full rounded-2xl shadow-lg object-contain border border-slate-200 select-none pointer-events-none" />
                
                <audio id="viewOnceAudio" controls class="hidden w-full max-w-sm"></audio>
                
                <div id="viewOnceText" class="hidden p-6 bg-white rounded-2xl border border-slate-200 shadow-sm text-center font-bold text-slate-800 text-base max-w-md break-words whitespace-pre-wrap"></div>
            </div>

            <!-- Modal Footer -->
            <div class="px-6 py-3.5 bg-white border-t border-slate-100 flex items-center justify-between">
                <span class="text-xs text-slate-400 font-medium flex items-center gap-1.5">
                    <span class="material-icons-round text-sm text-amber-500">lock</span>
                    Protected & Single View
                </span>
                <button type="button" onclick="closeViewOnceModal()" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs rounded-xl transition shadow-md flex items-center gap-1.5 active:scale-95">
                    <span>Close & Disappear</span>
                    <span class="material-icons-round text-sm">visibility_off</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ========================================================
         PROFILE PICTURE (DP) FULLSCREEN ZOOM LIGHTBOX MODAL
         ======================================================== -->
    <div id="dpZoomModal" onclick="handleDpZoomBackdropClick(event)" class="hidden fixed inset-0 z-50 bg-black/85 backdrop-blur-md flex items-center justify-center p-4 select-none animate-in fade-in duration-200">
        <!-- Floating Close Button Top Right -->
        <button type="button" onclick="closeDpZoomModal()" title="Close (Esc)"
                class="absolute top-5 right-5 w-11 h-11 rounded-full bg-white/10 hover:bg-white/25 text-white/90 hover:text-white flex items-center justify-center transition active:scale-90 z-20 backdrop-blur-md border border-white/20 shadow-lg">
            <span class="material-icons-round text-2xl">close</span>
        </button>

        <div onclick="event.stopPropagation()" class="relative flex flex-col items-center justify-center animate-dp-zoom max-w-lg w-full">
            <!-- Name & Subtitle Overlay (Floating above image) -->
            <div class="mb-3.5 text-center px-4">
                <h3 id="dpZoomTitle" class="text-lg font-black text-white drop-shadow-md tracking-tight">Profile Photo</h3>
                <p id="dpZoomSub" class="text-xs font-semibold text-slate-300 drop-shadow-sm mt-0.5">DigiInk Team Member</p>
            </div>

            <!-- Full-size / Large High Quality Image Card -->
            <div class="relative w-72 h-72 sm:w-96 sm:h-96 rounded-3xl overflow-hidden shadow-2xl border-4 border-white/90 ring-1 ring-black/40 bg-slate-900 flex items-center justify-center">
                <img id="dpZoomImg" src="" alt="Profile Photo" class="w-full h-full object-cover transition-transform duration-300 hover:scale-105 select-none" />
                <div id="dpZoomFallback" class="hidden w-full h-full bg-gradient-to-tr from-indigo-600 to-violet-600 flex items-center justify-center text-8xl font-black text-white">
                    U
                </div>
            </div>
        </div>
    </div>
    <!-- ========================================================
         WHATSAPP STYLE DP CROP MODAL (Clean White Theme)
         ======================================================== -->
    <div id="dpCropModal" class="hidden fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4 select-none animate-in fade-in duration-200">
        <div onclick="event.stopPropagation()" class="bg-white rounded-3xl shadow-2xl max-w-lg w-full overflow-hidden border border-slate-200 animate-dp-zoom flex flex-col">
            <!-- Modal Header (Clean White) -->
            <div class="px-5 py-4 bg-white flex items-center justify-between border-b border-slate-100">
                <div class="flex items-center gap-3">
                    <button type="button" onclick="cancelDpCrop()" title="Cancel (Esc)" class="p-1.5 text-slate-500 hover:text-slate-800 rounded-xl hover:bg-slate-100 transition">
                        <span class="material-icons-round text-xl">arrow_back</span>
                    </button>
                    <div>
                        <h3 class="text-sm font-extrabold text-slate-900">Adjust & Crop Profile Photo</h3>
                        <p class="text-[11px] text-slate-400 font-medium">Drag and scale within the circle</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="rotateDpCrop(-90)" title="Rotate Left" class="p-2 text-slate-500 hover:text-slate-800 hover:bg-slate-100 rounded-xl transition">
                        <span class="material-icons-round text-lg">rotate_left</span>
                    </button>
                    <button type="button" onclick="rotateDpCrop(90)" title="Rotate Right" class="p-2 text-slate-500 hover:text-slate-800 hover:bg-slate-100 rounded-xl transition">
                        <span class="material-icons-round text-lg">rotate_right</span>
                    </button>
                </div>
            </div>

            <!-- Cropper Canvas Container -->
            <div class="relative bg-slate-950 p-4 flex items-center justify-center min-h-[340px] max-h-[60vh] overflow-hidden">
                <div class="max-w-full max-h-[50vh]">
                    <img id="dpCropImageElement" src="" alt="To Crop" class="max-w-full max-h-[50vh] block" />
                </div>
            </div>

            <!-- Modal Footer / Controls (Clean White) -->
            <div class="px-5 py-4 bg-white border-t border-slate-100 flex items-center justify-between gap-4">
                <button type="button" onclick="cancelDpCrop()" class="px-5 py-2.5 rounded-xl border border-slate-200 text-slate-600 hover:text-slate-900 hover:bg-slate-50 text-xs font-bold transition active:scale-95">
                    Cancel
                </button>
                <button type="button" id="dpCropSaveBtn" onclick="confirmAndSaveCroppedDp()" class="px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-extrabold rounded-xl shadow-md shadow-indigo-200 flex items-center gap-2 transition active:scale-95">
                    <span class="material-icons-round text-base">check</span>
                    <span id="dpCropSaveBtnText">Set Profile Photo</span>
                </button>
            </div>
        </div>
    </div>
</body>
</html>
