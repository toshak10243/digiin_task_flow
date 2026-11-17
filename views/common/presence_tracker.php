<?php
/**
 * Global DigiChat Enterprise Presence & Real-Time Notification Tracker
 * 1. Keeps users marked as "Online" while active on any page.
 * 2. Transmits instant beacon to mark "Offline" on tab/browser close.
 * 3. Real-time DigiChat Unread Badge in Sidebar (shows 1 to 9+).
 * 4. Real-time DigiChat Notification in Header Dropdown & Floating Toast Alert.
 */
if (!empty($_SESSION['user_id'])):
    $isLocal = (strpos($_SERVER['REQUEST_URI'] ?? '', '/digiink-task-flow') !== false);
    $baseAssetUrl = $isLocal ? '/digiink-task-flow' : '';
    $chatApiEndpoint = $baseAssetUrl . '/views/chat/api.php';
    $chatPageUrl     = $baseAssetUrl . '/views/chat/index.php';
    $faviconPngUrl   = $baseAssetUrl . '/assets/images/favicon.png';
?>
<script>
// Instantly enforce Digiink Logo Favicon across all role dashboard tabs
(function setDigiinkFavicon() {
    try {
        const iconUrl = '<?= $faviconPngUrl ?>';
        let link = document.querySelector("link[rel*='icon']");
        if (!link) {
            link = document.createElement('link');
            link.rel = 'shortcut icon';
            document.head.appendChild(link);
        }
        link.type = 'image/png';
        link.href = iconUrl;
    } catch(e) {}
})();

(function() {
    const PRESENCE_API = '<?= $chatApiEndpoint ?>';
    const CHAT_PAGE_URL = '<?= $chatPageUrl ?>';
    let lastNotifiedMsgId = null;
    let audioCtx = null;

    // Gentle Slack/iOS style synthesizer chime (no external mp3 needed)
    function playChatChime() {
        try {
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            if (!AudioContext) return;
            if (!audioCtx) audioCtx = new AudioContext();
            if (audioCtx.state === 'suspended') {
                audioCtx.resume();
            }
            const now = audioCtx.currentTime;
            
            // First note (587.33 Hz - D5)
            const osc1 = audioCtx.createOscillator();
            const gain1 = audioCtx.createGain();
            osc1.type = 'sine';
            osc1.frequency.setValueAtTime(587.33, now);
            gain1.gain.setValueAtTime(0.08, now);
            gain1.gain.exponentialRampToValueAtTime(0.001, now + 0.18);
            osc1.connect(gain1);
            gain1.connect(audioCtx.destination);
            osc1.start(now);
            osc1.stop(now + 0.18);

            // Second note (880 Hz - A5)
            const osc2 = audioCtx.createOscillator();
            const gain2 = audioCtx.createGain();
            osc2.type = 'sine';
            osc2.frequency.setValueAtTime(880, now + 0.1);
            gain2.gain.setValueAtTime(0.12, now + 0.1);
            gain2.gain.exponentialRampToValueAtTime(0.001, now + 0.35);
            osc2.connect(gain2);
            gain2.connect(audioCtx.destination);
            osc2.start(now + 0.1);
            osc2.stop(now + 0.35);
        } catch(e) {}
    }

    // Update Sidebar Badges (1 to 9+)
    function updateSidebarBadges(count, badgeText) {
        const badges = document.querySelectorAll('.digichat-unread-badge');
        badges.forEach(b => {
            if (count > 0) {
                b.textContent = badgeText;
                b.classList.remove('hidden');
                b.style.display = 'inline-flex';
            } else {
                b.classList.add('hidden');
                b.style.display = 'none';
            }
        });
    }

    // Integrate DigiChat into Header Notification Dropdown
    function updateNotificationDropdown(count, badgeText, latest) {
        // Find notification container if on page
        const dropdown = document.getElementById('notificationDropdown') || document.getElementById('notifDropdown');
        if (!dropdown) return;

        let chatCard = document.getElementById('digichatNotifCard');
        if (count > 0) {
            const senderName = latest ? escapeHtml(latest.sender_name) : 'DigiChat Team';
            const previewMsg = latest ? (latest.message ? escapeHtml(latest.message) : 'Sent a file attachment') : 'You have unread messages';
            const initial = senderName.charAt(0).toUpperCase();

            if (!chatCard) {
                chatCard = document.createElement('a');
                chatCard.id = 'digichatNotifCard';
                chatCard.href = CHAT_PAGE_URL;
                chatCard.className = 'block mx-3 my-2.5 p-3 bg-gradient-to-r from-indigo-500/10 via-purple-500/10 to-pink-500/5 border border-indigo-500/30 rounded-xl hover:border-indigo-500/60 transition shadow-sm group';
                
                // Find list container inside dropdown
                const listContainer = dropdown.querySelector('.clean-scroll') || 
                                      dropdown.querySelector('.notif-scroll') || 
                                      dropdown.querySelector('.max-h-96') || 
                                      dropdown.querySelector('.max-h-\\[340px\\]') || 
                                      dropdown.querySelector('.max-h-\\[380px\\]') || 
                                      dropdown;
                if (listContainer) {
                    listContainer.prepend(chatCard);
                }
            }

            chatCard.innerHTML = `
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg bg-indigo-600 text-white flex items-center justify-center font-bold text-xs shadow-sm flex-shrink-0 group-hover:scale-105 transition-transform">
                        ${initial}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-bold text-slate-800">DigiChat</span>
                            <span class="bg-rose-500 text-white text-[10px] font-black px-1.5 py-0.5 rounded-full shadow-xs leading-none">${badgeText} new</span>
                        </div>
                        <p class="text-[11px] text-slate-600 truncate mt-0.5"><strong class="text-indigo-600">${senderName}:</strong> ${previewMsg}</p>
                    </div>
                </div>
            `;
            chatCard.style.display = 'block';
        } else if (chatCard) {
            chatCard.style.display = 'none';
        }
    }

    // Floating WhatsApp / Slack style alert Toast on new incoming message with Instant Reply & Mark Seen
    function showChatToast(latest, badgeText) {
        if (!latest) return;
        let toast = document.getElementById('digichatFloatingToast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'digichatFloatingToast';
            toast.className = 'fixed bottom-5 right-5 z-[9999] max-w-sm w-full bg-slate-900/95 text-white p-4 rounded-3xl shadow-2xl border border-indigo-500/40 backdrop-blur-md transition-all duration-300 transform translate-y-8 opacity-0';
            document.body.appendChild(toast);
        }

        const senderId = parseInt(latest.sender_id, 10);
        const senderName = escapeHtml(latest.sender_name);
        const preview = latest.message ? escapeHtml(latest.message) : 'Sent a file';
        const initial = senderName.charAt(0).toUpperCase();

        toast.innerHTML = `
            <div class="flex items-start gap-3">
                <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-indigo-500 to-purple-600 flex items-center justify-center font-bold text-white shadow-sm flex-shrink-0 text-sm">
                    ${initial}
                </div>
                <div class="flex-1 min-w-0 cursor-pointer" onclick="window.location.href='${CHAT_PAGE_URL}?contact_id=${senderId}'">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-bold text-indigo-400 uppercase tracking-wider flex items-center gap-1">
                            <span class="inline-block w-1.5 h-1.5 rounded-full bg-rose-500 animate-ping"></span> DigiChat
                        </span>
                        <span class="text-[10px] text-slate-400">Just now</span>
                    </div>
                    <h5 class="text-xs font-bold text-white truncate mt-0.5">${senderName}</h5>
                    <p class="text-xs text-slate-300 truncate">${preview}</p>
                </div>
                <button type="button" onclick="closeChatToast()" class="text-slate-400 hover:text-white p-1 rounded-lg hover:bg-slate-800 transition" title="Close">
                    <span class="material-icons-round text-base">close</span>
                </button>
            </div>

            <!-- Action Bar: Mark Seen & Quick Reply Toggle -->
            <div class="mt-3 pt-2.5 border-t border-slate-800/80 flex items-center justify-between gap-2" id="toastActionsRow">
                <button type="button" onclick="markChatToastSeen(${senderId}, this)" class="inline-flex items-center gap-1 text-[11px] font-bold text-slate-400 hover:text-emerald-400 transition">
                    <span class="material-icons-round text-xs">done_all</span> Mark as Seen
                </button>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="toggleToastReplyBox()" class="px-3 py-1 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold rounded-xl transition shadow-xs flex items-center gap-1">
                        <span class="material-icons-round text-xs">reply</span> Reply
                    </button>
                    <a href="${CHAT_PAGE_URL}?contact_id=${senderId}" class="text-[11px] text-indigo-400 hover:text-indigo-300 font-bold underline">
                        Open Chat
                    </a>
                </div>
            </div>

            <!-- Inline Quick Reply Box -->
            <form id="toastReplyForm" onsubmit="sendToastReply(event, ${senderId})" class="hidden mt-3 pt-2.5 border-t border-slate-800/80">
                <div class="flex items-center gap-2">
                    <input type="text" id="toastReplyInput" placeholder="Type a reply to ${senderName}..." 
                           class="flex-1 bg-slate-800/90 border border-slate-700 text-white text-xs px-3 py-2 rounded-xl focus:outline-none focus:ring-1 focus:ring-indigo-500 placeholder-slate-400"
                           autocomplete="off">
                    <button type="submit" id="toastReplySendBtn" class="bg-indigo-600 hover:bg-indigo-500 text-white p-2 rounded-xl transition flex items-center justify-center shrink-0">
                        <span class="material-icons-round text-sm">send</span>
                    </button>
                </div>
            </form>
        `;

        requestAnimationFrame(() => {
            toast.classList.remove('opacity-0', 'translate-y-8', 'pointer-events-none');
            toast.classList.add('opacity-100', 'translate-y-0');
        });

        // Auto dismiss after 14 seconds (gives enough time to click reply)
        clearTimeout(toast._timeout);
        toast._timeout = setTimeout(() => {
            const replyInput = document.getElementById('toastReplyInput');
            // If user is actively typing reply, don't dismiss
            if (replyInput && document.activeElement === replyInput) return;
            closeChatToast();
        }, 14000);
    }

    // Explicit Close handler (Band karne ke liye)
    window.closeChatToast = function() {
        const toast = document.getElementById('digichatFloatingToast');
        if (toast) {
            clearTimeout(toast._timeout);
            toast.classList.remove('opacity-100', 'translate-y-0');
            toast.classList.add('opacity-0', 'translate-y-8', 'pointer-events-none');
        }
    };

    // Toggle Reply Input directly under toast
    window.toggleToastReplyBox = function() {
        const form = document.getElementById('toastReplyForm');
        const input = document.getElementById('toastReplyInput');
        if (form) {
            const isHidden = form.classList.contains('hidden');
            if (isHidden) {
                form.classList.remove('hidden');
                setTimeout(() => input && input.focus(), 50);
            } else {
                form.classList.add('hidden');
            }
        }
    };

    // Mark as Seen from Toast
    window.markChatToastSeen = function(senderId, btn) {
        if (!senderId) return;
        if (btn) {
            btn.innerHTML = `<span class="material-icons-round text-xs animate-spin">refresh</span> Seen`;
            btn.disabled = true;
        }

        const fd = new FormData();
        fd.append('sender_id', senderId);

        fetch(PRESENCE_API + '?action=mark_read', {
            method: 'POST',
            body: fd
        })
        .then(r => r.json())
        .then(data => {
            if (btn) {
                btn.innerHTML = `<span class="material-icons-round text-xs text-emerald-400">done_all</span> Seen`;
            }
            // Update sidebar badge
            checkPresenceAndMessages();
            setTimeout(() => {
                closeChatToast();
            }, 600);
        })
        .catch(() => {
            closeChatToast();
        });
    };

    // Send Quick Reply directly from toast
    window.sendToastReply = function(e, receiverId) {
        e.preventDefault();
        const input = document.getElementById('toastReplyInput');
        const sendBtn = document.getElementById('toastReplySendBtn');
        const message = (input ? input.value : '').trim();
        if (!message || !receiverId) return;

        if (sendBtn) {
            sendBtn.disabled = true;
            sendBtn.innerHTML = `<span class="material-icons-round text-xs animate-spin">refresh</span>`;
        }

        const fd = new FormData();
        fd.append('receiver_id', receiverId);
        fd.append('message', message);

        fetch(PRESENCE_API + '?action=send_message', {
            method: 'POST',
            body: fd
        })
        .then(r => r.json())
        .then(data => {
            if (data && data.success) {
                if (input) input.value = '';
                const form = document.getElementById('toastReplyForm');
                if (form) {
                    form.innerHTML = `
                        <div class="flex items-center gap-1.5 text-xs text-emerald-400 font-bold py-1">
                            <span class="material-icons-round text-sm">check_circle</span> Reply Sent Successfully!
                        </div>
                    `;
                }
                // Mark received message as read since user replied
                markChatToastSeen(receiverId, null);
                setTimeout(() => {
                    closeChatToast();
                }, 1200);
            } else {
                alert(data.error || 'Could not send message');
                if (sendBtn) {
                    sendBtn.disabled = false;
                    sendBtn.innerHTML = `<span class="material-icons-round text-sm">send</span>`;
                }
            }
        })
        .catch(err => {
            alert('Failed to send reply');
            if (sendBtn) {
                sendBtn.disabled = false;
                sendBtn.innerHTML = `<span class="material-icons-round text-sm">send</span>`;
            }
        });
    };

    // Floating Call Alert Toast for incoming voice / video call while on any page
    let activeCallAlertId = null;
    function showCallAlertToast(call) {
        if (!call || activeCallAlertId === parseInt(call.id, 10)) return;
        activeCallAlertId = parseInt(call.id, 10);

        let toast = document.getElementById('digichatCallAlertToast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'digichatCallAlertToast';
            toast.className = 'fixed top-5 right-5 z-[10000] max-w-sm w-full bg-slate-900/95 text-white p-4 rounded-3xl shadow-2xl border-2 border-emerald-500/70 flex items-center gap-3.5 backdrop-blur-md transition-all duration-300 transform -translate-y-8 opacity-0';
            document.body.appendChild(toast);
        }

        const callerName = escapeHtml(call.caller_name);
        const callerRole = escapeHtml(call.caller_role || 'Team Member');
        const isVideo = (call.call_type === 'video');
        const typeLabel = isVideo ? 'Incoming Video Call' : 'Incoming Voice Call';
        const typeIcon = isVideo ? 'videocam' : 'call';
        const answerUrl = `${CHAT_PAGE_URL}?call_id=${call.id}&auto_answer=1`;

        toast.innerHTML = `
            <div class="relative w-12 h-12 rounded-2xl bg-gradient-to-tr from-emerald-500 to-teal-600 flex items-center justify-center font-black text-white text-base shadow-md flex-shrink-0">
                <span class="animate-ping absolute inset-0 rounded-2xl bg-emerald-400 opacity-60"></span>
                ${callerName.charAt(0).toUpperCase()}
            </div>
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-emerald-400">
                    <span class="material-icons-round text-xs">${typeIcon}</span>
                    <span>${typeLabel}</span>
                </div>
                <h4 class="text-sm font-extrabold text-white truncate mt-0.5">${callerName}</h4>
                <p class="text-[11px] text-slate-300 uppercase tracking-wider">${callerRole}</p>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
                <button type="button" onclick="declineCallFromTracker(${call.id})" class="p-2.5 bg-rose-600 hover:bg-rose-700 text-white rounded-xl shadow-md transition active:scale-95" title="Decline">
                    <span class="material-icons-round text-base">call_end</span>
                </button>
                <a href="${answerUrl}" class="p-2.5 bg-emerald-500 hover:bg-emerald-600 text-white rounded-xl shadow-md transition active:scale-95 animate-bounce" title="Answer">
                    <span class="material-icons-round text-base">call</span>
                </a>
            </div>
        `;

        requestAnimationFrame(() => {
            toast.classList.remove('opacity-0', '-translate-y-8');
            toast.classList.add('opacity-100', 'translate-y-0');
        });
    }

    window.declineCallFromTracker = function(callId) {
        const toast = document.getElementById('digichatCallAlertToast');
        if (toast) {
            toast.classList.add('opacity-0', '-translate-y-8');
        }
        fetch(PRESENCE_API + '?action=reject_call&call_id=' + callId, { method: 'POST' }).catch(()=>{});
        activeCallAlertId = null;
    };

    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Heartbeat function: sends presence, receives unread chat count & latest message
    function checkPresenceAndMessages() {
        if (document.visibilityState === 'visible' && navigator.onLine !== false) {
            fetch(PRESENCE_API + '?action=heartbeat', { method: 'GET', keepalive: true })
                .then(r => r.json())
                .then(data => {
                    if (!data || !data.success) return;
                    const unread = parseInt(data.unread_count || 0, 10);
                    const badgeText = data.badge || (unread > 9 ? '9+' : unread);

                    // 1. Update all sidebar DigiChat badges (1 to 9+)
                    updateSidebarBadges(unread, badgeText);

                    // 2. Update notification dropdown
                    updateNotificationDropdown(unread, badgeText, data.latest);

                    // 3. Trigger toast & sound if new message arrived
                    if (data.latest && data.latest.id) {
                        const newMsgId = parseInt(data.latest.id, 10);
                        if (lastNotifiedMsgId !== null && newMsgId > lastNotifiedMsgId) {
                            playChatChime();
                            showChatToast(data.latest, badgeText);
                        }
                        lastNotifiedMsgId = newMsgId;
                    } else if (unread === 0) {
                        lastNotifiedMsgId = 0;
                    }

                    // 4. Trigger incoming call popup alert if active incoming call
                    if (data.incoming_call && data.incoming_call.id) {
                        showCallAlertToast(data.incoming_call);
                    } else if (activeCallAlertId) {
                        const alertToast = document.getElementById('digichatCallAlertToast');
                        if (alertToast) alertToast.classList.add('opacity-0', '-translate-y-8');
                        activeCallAlertId = null;
                    }
                })
                .catch(function(){});
        }
    }

    // Run immediately on page load
    checkPresenceAndMessages();

    // Periodic Heartbeat every 8 seconds while active on page
    setInterval(checkPresenceAndMessages, 8000);

    // Instant offline beacon on browser/tab close or navigate away
    function sendOfflineSignal() {
        try {
            if (navigator.sendBeacon) {
                const fd = new FormData();
                fd.append('action', 'set_offline');
                navigator.sendBeacon(PRESENCE_API, fd);
            } else {
                fetch(PRESENCE_API + '?action=set_offline', { method: 'POST', keepalive: true }).catch(function(){});
            }
        } catch(e) {}
    }

    window.addEventListener('beforeunload', sendOfflineSignal);
    window.addEventListener('pagehide', sendOfflineSignal);

    // Instant heartbeat when tab becomes visible again
    window.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'visible') {
            checkPresenceAndMessages();
        }
    });
})();
</script>

<!-- Firebase Cloud Messaging (Web Push) Real-time Desktop Notifications -->
<script src="<?= $baseAssetUrl ?>/assets/js/fcm-client.js"></script>
<?php endif; ?>
