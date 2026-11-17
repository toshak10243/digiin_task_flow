/**
 * Digiink Task Flow - Firebase Cloud Messaging (Web Push) Client
 * Requests browser permission, registers Service Worker, sends FCM token to server,
 * and handles real-time foreground/background desktop notifications like WhatsApp.
 */

(function () {
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

    // Helper to dynamically load external scripts sequentially
    function loadScript(src, callback) {
        if (document.querySelector(`script[src="${src}"]`)) {
            if (callback) callback();
            return;
        }
        const s = document.createElement('script');
        s.src = src;
        s.onload = callback;
        document.head.appendChild(s);
    }

    // Init FCM once Firebase scripts are ready
    function initFCM() {
        if (!('Notification' in window) || !('serviceWorker' in navigator)) {
            console.log('[FCM] Browser does not support Desktop Notifications or Service Workers.');
            return;
        }

        if (!firebase.apps.length) {
            firebase.initializeApp(firebaseConfig);
        }

        const messaging = firebase.messaging();

        // 1. Register Service Worker with absolute path from web root
        // Handles both localhost:80/digiink-task-flow and virtual hosts
        const swPath = window.location.pathname.startsWith('/digiink-task-flow') 
            ? '/digiink-task-flow/firebase-messaging-sw.js' 
            : '/firebase-messaging-sw.js';

        navigator.serviceWorker.register(swPath)
            .then((registration) => {
                console.log('[FCM] Service Worker registered successfully:', registration.scope);

                // Auto-request or obtain token if already granted
                if (Notification.permission === 'granted') {
                    getAndSaveToken(messaging, registration);
                } else if (Notification.permission !== 'denied') {
                    showEnableNotificationBanner(messaging, registration);
                }
            })
            .catch((err) => {
                console.error('[FCM] Service Worker registration failed: ', err);
            });

        // 2. Foreground Message Handler (Tab is active/visible)
        messaging.onMessage((payload) => {
            console.log('[FCM] Foreground message received: ', payload);

            const title = (payload.notification && payload.notification.title) || (payload.data && payload.data.title) || 'Digiink Notification';
            const body  = (payload.notification && payload.notification.body)  || (payload.data && payload.data.body)  || 'New task update';
            const url   = (payload.data && payload.data.url) || '#';

            // Play a gentle alert sound
            try {
                const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                osc.connect(gain);
                gain.connect(audioCtx.destination);
                osc.frequency.setValueAtTime(587.33, audioCtx.currentTime); // D5
                osc.frequency.setValueAtTime(880, audioCtx.currentTime + 0.1); // A5
                gain.gain.setValueAtTime(0.15, audioCtx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.35);
                osc.start(audioCtx.currentTime);
                osc.stop(audioCtx.currentTime + 0.35);
            } catch (eAudio) {}

            // If user has tab hidden/minimized (e.g. working on WhatsApp), show OS Desktop Notification!
            if (document.hidden && Notification.permission === 'granted') {
                const n = new Notification(title, {
                    body: body,
                    icon: '/favicon.png',
                    badge: '/favicon.png',
                    requireInteraction: true,
                    data: { url: url }
                });
                n.onclick = function(e) {
                    e.preventDefault();
                    window.focus();
                    if (url && url !== '#') {
                        window.location.href = url;
                    }
                    n.close();
                };
            }

            // Also show SweetAlert Toast inside tab
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: title,
                    text: body,
                    icon: 'info',
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 5000,
                    timerProgressBar: true,
                    background: '#ffffff',
                    iconColor: '#4f46e5',
                    customClass: { popup: 'rounded-2xl shadow-xl border border-slate-100 cursor-pointer' }
                });
            }
        });
    }

    function showEnableNotificationBanner(messaging, registration) {
        if (document.getElementById('digiinkFcmPill') || Notification.permission === 'granted') return;

        const banner = document.createElement('div');
        banner.id = 'digiinkFcmPill';
        banner.style.cssText = `
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 99999;
            background: linear-gradient(135deg, #1e1b4b, #312e81);
            color: #ffffff;
            padding: 12px 18px;
            border-radius: 16px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3), 0 8px 10px -6px rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(129, 140, 248, 0.3);
            display: flex;
            align-items: center;
            gap: 12px;
            font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
            font-size: 13px;
            font-weight: 600;
            animation: slideUpFade 0.4s ease-out;
        `;

        banner.innerHTML = `
            <div style="background: rgba(255, 255, 255, 0.15); width: 32px; height: 32px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 16px;">
                🔔
            </div>
            <div style="line-height: 1.3;">
                <div style="font-weight: 800; font-size: 12px; letter-spacing: 0.05em; text-transform: uppercase; color: #a5b4fc;">Desktop Alerts</div>
                <div>Enable WhatsApp-style popups?</div>
            </div>
            <button id="btnAllowFcmNow" style="background: #4f46e5; hover:background: #4338ca; color: #ffffff; border: none; padding: 7px 14px; border-radius: 10px; font-weight: 700; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; margin-left: 6px; box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.3);">
                Allow
            </button>
            <button id="btnDismissFcmPill" style="background: transparent; border: none; color: #94a3b8; font-size: 16px; cursor: pointer; padding: 0 4px;">
                ✕
            </button>
        `;

        document.body.appendChild(banner);

        document.getElementById('btnAllowFcmNow').addEventListener('click', function() {
            requestNotificationPermission(messaging, registration);
            banner.remove();
        });

        document.getElementById('btnDismissFcmPill').addEventListener('click', function() {
            banner.remove();
        });
    }

    function requestNotificationPermission(messaging, registration) {
        Notification.requestPermission().then((permission) => {
            if (permission === 'granted') {
                console.log('[FCM] Notification permission granted.');
                getAndSaveToken(messaging, registration);
            } else {
                console.log('[FCM] Notification permission denied.');
            }
        });
    }

    function getAndSaveToken(messaging, registration) {
        messaging.getToken({
            vapidKey: FCM_VAPID_KEY,
            serviceWorkerRegistration: registration
        }).then((currentToken) => {
            if (currentToken) {
                console.log('[FCM] Browser registration token obtained.');
                sendTokenToServer(currentToken);
            } else {
                console.warn('[FCM] No registration token available. Request permission to generate one.');
            }
        }).catch((err) => {
            console.error('[FCM] An error occurred while retrieving token: ', err);
        });
    }

    function sendTokenToServer(token) {
        // Find correct endpoint path
        const basePath = window.location.pathname.startsWith('/digiink-task-flow') ? '/digiink-task-flow' : '';
        const endpoint = basePath + '/views/common/save_fcm_token.php';

        fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ token: token })
        })
        .then((res) => res.json())
        .then((data) => {
            if (data && data.success) {
                console.log('[FCM] Token synchronized with server successfully.');
            }
        })
        .catch((err) => {
            console.error('[FCM] Failed to sync token with server:', err);
        });
    }

    // Load Firebase Compat CDN
    loadScript('https://www.gstatic.com/firebasejs/9.23.0/firebase-app-compat.js', function () {
        loadScript('https://www.gstatic.com/firebasejs/9.23.0/firebase-messaging-compat.js', function () {
            initFCM();
        });
    });
})();

