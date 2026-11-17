<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
    require_once __DIR__ . '/../../bootstrap/app.php';
    require_once __DIR__ . '/../../app/core/Session.php';
    require_once __DIR__ . '/../../app/config/database.php';
    require_once __DIR__ . '/../../app/services/FCMService.php';
} catch (Throwable $t) {
    die("FATAL INIT ERROR: " . $t->getMessage() . " on line " . $t->getLine() . " in " . $t->getFile());
}

if (empty($_SESSION['user_id'])) {
    die("Please log in to Digiink first, then open this page.");
}

$db = (new Database())->connect();
FCMService::ensureSchema();

$userId = (int)$_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'User';

// Check service account json
$credPath = __DIR__ . '/../../config/credentials/firebase_service_account.json';
$credExists = file_exists($credPath);
$credValid = false;
if ($credExists) {
    $json = json_decode(file_get_contents($credPath), true);
    if (!empty($json['client_email']) && !empty($json['private_key'])) {
        $credValid = true;
    }
}

// Check registered tokens for this user
$stmt = $db->prepare("SELECT id, token, user_agent, created_at, updated_at FROM user_fcm_tokens WHERE user_id = :uid");
$stmt->execute([':uid' => $userId]);
$tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);

$testResult = null;
if (isset($_POST['send_test'])) {
    if (empty($tokens)) {
        $testResult = ['success' => false, 'error' => 'No FCM browser tokens registered for your account yet. Please click "Enable Notifications" below first.'];
    } else {
        $sentCount = FCMService::sendToUser($userId, "🚀 Test Desktop Notification!", "WhatsApp-style desktop notifications are working perfectly on Digiink!", null, "/");
        $testResult = [
            'success' => $sentCount > 0,
            'sent_count' => $sentCount,
            'message' => $sentCount > 0 ? "Notification dispatched to {$sentCount} browser device(s)!" : "FCM dispatch attempted, but Google returned an error. Check server logs."
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Firebase Web Push Diagnostic | Digiink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Plus Jakarta Sans', sans-serif; background: #0f172a; }</style>
</head>
<body class="text-slate-100 p-8 min-h-screen flex items-center justify-center">

<div class="max-w-xl w-full bg-slate-900 border border-slate-800 rounded-3xl p-8 shadow-2xl space-y-6">
    <div class="flex items-center justify-between border-b border-slate-800 pb-5">
        <div>
            <h1 class="text-xl font-extrabold text-white flex items-center gap-2">
                <span class="material-icons-round text-amber-400">notifications_active</span>
                Firebase Push Diagnostic
            </h1>
            <p class="text-xs text-slate-400 mt-1">Logged in as: <strong class="text-indigo-400"><?= htmlspecialchars($username) ?></strong> (ID: <?= $userId ?>)</p>
        </div>
        <a href="../../index.php" class="text-xs text-slate-400 hover:text-white">← Dashboard</a>
    </div>

    <!-- Diagnostic Checks -->
    <div class="space-y-3">
        <!-- 1. Credentials File -->
        <div class="flex items-center justify-between p-4 rounded-2xl bg-slate-800/60 border border-slate-700/50">
            <div>
                <p class="text-xs font-bold text-slate-200">Firebase Service Account JSON</p>
                <p class="text-[10px] text-slate-400 font-mono mt-0.5">config/credentials/firebase_service_account.json</p>
            </div>
            <?php if($credExists && $credValid): ?>
                <span class="px-2.5 py-1 bg-emerald-500/20 text-emerald-400 rounded-lg text-[10px] font-black uppercase">Found & Valid</span>
            <?php else: ?>
                <span class="px-2.5 py-1 bg-rose-500/20 text-rose-400 rounded-lg text-[10px] font-black uppercase">Missing / Invalid</span>
            <?php endif; ?>
        </div>

        <!-- 2. Registered Tokens -->
        <div class="flex items-center justify-between p-4 rounded-2xl bg-slate-800/60 border border-slate-700/50">
            <div>
                <p class="text-xs font-bold text-slate-200">Browser FCM Tokens in Database</p>
                <p class="text-[10px] text-slate-400 mt-0.5">user_fcm_tokens table</p>
            </div>
            <?php if(count($tokens) > 0): ?>
                <span class="px-2.5 py-1 bg-emerald-500/20 text-emerald-400 rounded-lg text-[10px] font-black uppercase"><?= count($tokens) ?> Token(s) Active</span>
            <?php else: ?>
                <span class="px-2.5 py-1 bg-amber-500/20 text-amber-400 rounded-lg text-[10px] font-black uppercase">0 Tokens (Not Registered Yet)</span>
            <?php endif; ?>
        </div>

        <!-- 3. Browser Permission State -->
        <div class="flex items-center justify-between p-4 rounded-2xl bg-slate-800/60 border border-slate-700/50">
            <div>
                <p class="text-xs font-bold text-slate-200">This Browser Permission State</p>
                <p class="text-[10px] text-slate-400 mt-0.5" id="browserPermText">Detecting...</p>
            </div>
            <span id="browserPermBadge" class="px-2.5 py-1 bg-slate-700 text-slate-300 rounded-lg text-[10px] font-black uppercase">Checking...</span>
        </div>
    </div>

    <!-- Permission Button (If not granted) -->
    <div id="grantSection" class="p-5 rounded-2xl bg-indigo-950/40 border border-indigo-800/60 space-y-3">
        <p class="text-xs text-indigo-200 font-semibold leading-relaxed">
            Agar aapke browser ne abhi tak notification allow nahi kiya hai, toh neeche button click karein aur browser popup me <strong>"Allow"</strong> par click karein:
        </p>
        <button id="btnRequestPermission" type="button" 
                class="w-full py-3 px-4 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl text-xs font-black uppercase tracking-wider flex items-center justify-center gap-2 transition-all shadow-lg shadow-indigo-600/30">
            <span class="material-icons-round text-base">notifications_active</span>
            Click Here to Allow Notifications in this Browser
        </button>
        <p id="permStatusMsg" class="text-[11px] text-slate-400 text-center font-medium"></p>
    </div>

    <!-- Send Test Notification -->
    <form method="POST" class="pt-2">
        <button type="submit" name="send_test" 
                class="w-full py-3.5 px-4 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-xs font-black uppercase tracking-wider flex items-center justify-center gap-2 transition-all shadow-lg shadow-emerald-600/30">
            <span class="material-icons-round text-base">send</span>
            Send Test Push Notification Now
        </button>
    </form>

    <?php if($testResult !== null): ?>
    <div class="p-4 rounded-2xl <?= $testResult['success'] ? 'bg-emerald-500/20 border border-emerald-500/40 text-emerald-200' : 'bg-rose-500/20 border border-rose-500/40 text-rose-200' ?> text-xs leading-relaxed">
        <strong><?= $testResult['success'] ? 'Success: ' : 'Notice: ' ?></strong>
        <?= htmlspecialchars($testResult['message'] ?? ($testResult['error'] ?? '')) ?>
    </div>
    <?php endif; ?>
</div>

<!-- Load Firebase Compat SDK -->
<script src="https://www.gstatic.com/firebasejs/9.23.0/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/9.23.0/firebase-messaging-compat.js"></script>

<script>
const FCM_VAPID_KEY = "BEobGG6AfalPU-G0S0ARU17jL-xrV37PEjldz4AS98XRov3vklxH9e1DOA3zsXdGVrzmGUX18SMJNdcGwlvNZyY";

const firebaseConfig = {
    apiKey: "AIzaSyBkUNRoPr30LQQZ-uJI7UPYbwvqBJqKwK4",
    authDomain: "digiink-taskflow.firebaseapp.com",
    projectId: "digiink-taskflow",
    storageBucket: "digiink-taskflow.firebasestorage.app",
    messagingSenderId: "104305634643",
    appId: "1:104305634643:web:b42bfb0776e641143dc5a6",
    measurementId: "G-X4TF1B7PV9"
};

if (!firebase.apps.length) {
    firebase.initializeApp(firebaseConfig);
}

function updatePermUI() {
    const perm = Notification.permission;
    const txt = document.getElementById('browserPermText');
    const badge = document.getElementById('browserPermBadge');

    txt.innerText = 'Current permission: ' + perm;
    if (perm === 'granted') {
        badge.className = 'px-2.5 py-1 bg-emerald-500/20 text-emerald-400 rounded-lg text-[10px] font-black uppercase';
        badge.innerText = 'GRANTED';
        
        <?php if(count($tokens) === 0): ?>
            registerThisDevice();
        <?php else: ?>
            document.getElementById('permStatusMsg').innerHTML = '✅ Permission is granted & your device token is active!';
        <?php endif; ?>
    } else if (perm === 'denied') {
        badge.className = 'px-2.5 py-1 bg-rose-500/20 text-rose-400 rounded-lg text-[10px] font-black uppercase';
        badge.innerText = 'BLOCKED IN BROWSER';
        document.getElementById('permStatusMsg').innerHTML = '⚠️ Notifications are blocked in Chrome. Click the tune/lock icon in address bar to Allow.';
    } else {
        badge.className = 'px-2.5 py-1 bg-amber-500/20 text-amber-400 rounded-lg text-[10px] font-black uppercase';
        badge.innerText = 'DEFAULT (NOT ALLOWED YET)';
        document.getElementById('permStatusMsg').innerHTML = 'Please click the button above to grant permission.';
    }
}

function registerThisDevice() {
    const statusMsg = document.getElementById('permStatusMsg');
    statusMsg.innerHTML = '<span class="text-amber-400 font-bold">⏳ Generating FCM registration token...</span>';

    if (!('serviceWorker' in navigator) || !('Notification' in window)) {
        statusMsg.innerHTML = '<span class="text-rose-400">❌ ServiceWorker not supported</span>';
        return;
    }

    const swPath = window.location.pathname.startsWith('/digiink-task-flow') 
        ? '/digiink-task-flow/firebase-messaging-sw.js' 
        : '/firebase-messaging-sw.js';

    navigator.serviceWorker.register(swPath).then((registration) => {
        const messaging = firebase.messaging();
        messaging.getToken({
            vapidKey: FCM_VAPID_KEY,
            serviceWorkerRegistration: registration
        }).then((token) => {
            if (!token) {
                statusMsg.innerHTML = '<span class="text-rose-400">❌ Failed to obtain token from Firebase</span>';
                return;
            }
            statusMsg.innerHTML = '<span class="text-indigo-400 font-bold">⏳ Saving token to database...</span>';
            
            fetch('save_fcm_token.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ token: token })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    statusMsg.innerHTML = '<span class="text-emerald-400 font-black">✅ Token registered successfully in database! Reloading...</span>';
                    setTimeout(() => location.reload(), 1500);
                } else {
                    statusMsg.innerHTML = '<span class="text-rose-400 font-bold">❌ Server error: ' + (data.error || 'Failed to save') + '</span>';
                }
            })
            .catch(err => {
                statusMsg.innerHTML = '<span class="text-rose-400">❌ Network error: ' + err.message + '</span>';
            });
        }).catch((err) => {
            statusMsg.innerHTML = '<span class="text-rose-400 font-bold">❌ Token error: ' + err.message + '</span>';
        });
    }).catch((err) => {
        statusMsg.innerHTML = '<span class="text-rose-400 font-bold">❌ Service Worker error: ' + err.message + '</span>';
    });
}

document.addEventListener('DOMContentLoaded', updatePermUI);

document.getElementById('btnRequestPermission').addEventListener('click', function() {
    Notification.requestPermission().then(function(p) {
        updatePermUI();
        if (p === 'granted') {
            registerThisDevice();
        }
    });
});
</script>

</body>
</html>
