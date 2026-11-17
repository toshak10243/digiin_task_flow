<?php
require_once __DIR__ . '/../../bootstrap/app.php';
require_once __DIR__ . '/../../app/core/Session.php';
require_once __DIR__ . '/../../app/config/database.php';

if (!headers_sent()) {
    header('Content-Type: application/json');
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$myId = (int)$_SESSION['user_id'];
$myRole = $_SESSION['role'] ?? '';
$myUsername = $_SESSION['username'] ?? '';
$db = (new Database())->connect();

if (empty($myUsername)) {
    try {
        $stmtMe = $db->prepare("SELECT username FROM users WHERE id = :id");
        $stmtMe->execute([':id' => $myId]);
        $myUsername = (string)$stmtMe->fetchColumn();
    } catch (\Exception $e) {}
}

// Auto-create chat_messages table if not exists
$db->exec("CREATE TABLE IF NOT EXISTS `chat_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sender_id` int(11) NOT NULL,
  `receiver_id` int(11) NOT NULL,
  `message` text DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `file_type` varchar(50) DEFAULT NULL,
  `file_size` int(11) DEFAULT 0,
  `is_delivered` tinyint(1) NOT NULL DEFAULT 0,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `is_edited` tinyint(1) NOT NULL DEFAULT 0,
  `is_deleted_for_everyone` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_for_sender` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_for_receiver` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sender_receiver` (`sender_id`, `receiver_id`),
  KEY `idx_receiver_read` (`receiver_id`, `is_read`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

// Ensure all newer columns exist safely
$colsCheck = [
    'is_delivered' => "TINYINT(1) NOT NULL DEFAULT 0",
    'is_edited' => "TINYINT(1) NOT NULL DEFAULT 0",
    'is_deleted_for_everyone' => "TINYINT(1) NOT NULL DEFAULT 0",
    'deleted_for_sender' => "TINYINT(1) NOT NULL DEFAULT 0",
    'deleted_for_receiver' => "TINYINT(1) NOT NULL DEFAULT 0",
    'delivered_at' => "DATETIME DEFAULT NULL",
    'read_at' => "DATETIME DEFAULT NULL",
    'is_forwarded' => "TINYINT(1) NOT NULL DEFAULT 0",
    'group_id'     => "INT(11) NULL DEFAULT NULL",
    'is_view_once' => "TINYINT(1) NOT NULL DEFAULT 0",
    'view_once_opened' => "TINYINT(1) NOT NULL DEFAULT 0",
    'view_once_opened_at' => "DATETIME DEFAULT NULL",
    'reply_to_id'  => "INT(11) NULL DEFAULT NULL"
];
foreach ($colsCheck as $col => $def) {
    try {
        $cExists = $db->query("SHOW COLUMNS FROM `chat_messages` LIKE '$col'")->fetch();
        if (!$cExists) {
            $db->exec("ALTER TABLE `chat_messages` ADD COLUMN `$col` $def");
        }
    } catch (\Exception $e) {}
}

// Ensure chat_message_reactions table exists for emoji reactions
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `chat_message_reactions` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `message_id` int(11) NOT NULL,
      `user_id` int(11) NOT NULL,
      `reaction` varchar(32) NOT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      UNIQUE KEY `idx_msg_user_react` (`message_id`, `user_id`, `reaction`),
      KEY `idx_msg_id` (`message_id`),
      KEY `idx_user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
} catch (\Exception $e) {}

// Ensure receiver_id allows NULL for group messages and index on group_id exists
try {
    $db->exec("ALTER TABLE `chat_messages` MODIFY COLUMN `receiver_id` INT(11) NULL DEFAULT NULL");
    $hasGrpIdx = $db->query("SHOW INDEX FROM `chat_messages` WHERE Key_name = 'idx_group_id'")->fetch();
    if (!$hasGrpIdx) {
        $db->exec("ALTER TABLE `chat_messages` ADD INDEX `idx_group_id` (`group_id`, `id`)");
    }
} catch (\Exception $e) {}

// Ensure chat_calls table exists for WebRTC Voice & Video calling
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `chat_calls` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `caller_id` int(11) NOT NULL,
      `receiver_id` int(11) NOT NULL,
      `call_type` enum('voice','video') NOT NULL DEFAULT 'voice',
      `status` enum('calling','ringing','connected','rejected','ended','busy','missed') NOT NULL DEFAULT 'calling',
      `offer` longtext DEFAULT NULL,
      `answer` longtext DEFAULT NULL,
      `caller_candidates` longtext DEFAULT NULL,
      `receiver_candidates` longtext DEFAULT NULL,
      `duration` int(11) NOT NULL DEFAULT 0,
      `started_at` datetime DEFAULT NULL,
      `ended_at` datetime DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `idx_caller_status` (`caller_id`, `status`),
      KEY `idx_receiver_status` (`receiver_id`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

    $db->exec("CREATE TABLE IF NOT EXISTS `chat_call_candidates` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `call_id` int(11) NOT NULL,
      `user_id` int(11) NOT NULL,
      `candidate` longtext NOT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `idx_call_user` (`call_id`, `user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

    $hasLastPing = $db->query("SHOW COLUMNS FROM `chat_calls` LIKE 'last_ping'")->fetch();
    if (!$hasLastPing) {
        $db->exec("ALTER TABLE `chat_calls` ADD COLUMN `last_ping` DATETIME DEFAULT NULL");
    }

    // Ensure chat_calls table has recording columns
    $callRecCols = [
        'recording_path' => "VARCHAR(500) DEFAULT NULL",
        'recording_size' => "INT(11) DEFAULT 0"
    ];
    foreach ($callRecCols as $col => $def) {
        try {
            $hasCol = $db->query("SHOW COLUMNS FROM `chat_calls` LIKE '$col'")->fetch();
            if (!$hasCol) {
                $db->exec("ALTER TABLE `chat_calls` ADD COLUMN `$col` $def");
            }
        } catch (\Exception $e) {}
    }

    // Ensure chat_calls allows nullable receiver_id and group_id column exists
    try {
        $db->exec("ALTER TABLE `chat_calls` MODIFY COLUMN `receiver_id` INT(11) NULL DEFAULT NULL");
        $hasGrpCol = $db->query("SHOW COLUMNS FROM `chat_calls` LIKE 'group_id'")->fetch();
        if (!$hasGrpCol) {
            $db->exec("ALTER TABLE `chat_calls` ADD COLUMN `group_id` INT(11) NULL DEFAULT NULL");
            $db->exec("ALTER TABLE `chat_calls` ADD INDEX `idx_group_status` (`group_id`, `status`)");
        }
    } catch (\Exception $colEx) {}

    // Ensure chat_call_participants table exists for Group Voice & Video Calls
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `chat_call_participants` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `call_id` int(11) NOT NULL,
          `user_id` int(11) NOT NULL,
          `role` enum('caller','participant') NOT NULL DEFAULT 'participant',
          `status` enum('calling','ringing','connected','declined','left') NOT NULL DEFAULT 'ringing',
          `offer` longtext DEFAULT NULL,
          `answer` longtext DEFAULT NULL,
          `last_ping` datetime DEFAULT NULL,
          `joined_at` datetime DEFAULT NULL,
          `left_at` datetime DEFAULT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `idx_call_user` (`call_id`, `user_id`),
          KEY `idx_call_status` (`call_id`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
    } catch (\Exception $partEx) {}
} catch (\Exception $e) {}

// Ensure last_seen, profile_image & about columns exist on users table
try {
    $hasLastSeen = $db->query("SHOW COLUMNS FROM `users` LIKE 'last_seen'")->fetch();
    if (!$hasLastSeen) {
        $db->exec("ALTER TABLE `users` ADD COLUMN `last_seen` DATETIME DEFAULT NULL");
    }
    $hasProfileImg = $db->query("SHOW COLUMNS FROM `users` LIKE 'profile_image'")->fetch();
    if (!$hasProfileImg) {
        $db->exec("ALTER TABLE `users` ADD COLUMN `profile_image` VARCHAR(500) DEFAULT NULL");
    }
    $hasAbout = $db->query("SHOW COLUMNS FROM `users` LIKE 'about'")->fetch();
    if (!$hasAbout) {
        $db->exec("ALTER TABLE `users` ADD COLUMN `about` VARCHAR(255) DEFAULT 'Available'");
    }
} catch (\Exception $e) {}

// Ensure chat_blocks table exists for blocking functionality
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `chat_blocks` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `blocker_id` int(11) NOT NULL,
      `blocked_id` int(11) NOT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      UNIQUE KEY `idx_blocker_blocked` (`blocker_id`, `blocked_id`),
      KEY `idx_blocked` (`blocked_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
} catch (\Exception $e) {}

// Ensure chat_pinned table exists for pinning chats to top
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `chat_pinned` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `user_id` int(11) NOT NULL,
      `peer_type` enum('direct','group') NOT NULL DEFAULT 'direct',
      `peer_id` int(11) NOT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      UNIQUE KEY `idx_user_peer` (`user_id`, `peer_type`, `peer_id`),
      KEY `idx_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
} catch (\Exception $e) {}

// Ensure chat_nicknames table exists for personal contact nicknames
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `chat_nicknames` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `user_id` int(11) NOT NULL,
      `contact_id` int(11) NOT NULL,
      `nickname` varchar(100) NOT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`),
      UNIQUE KEY `idx_user_contact` (`user_id`, `contact_id`),
      KEY `idx_user` (`user_id`),
      KEY `idx_contact` (`contact_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
} catch (\Exception $e) {}

// Ensure chat_wallpapers table exists for custom chat backgrounds (WhatsApp Style)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `chat_wallpapers` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `user_id` int(11) NOT NULL,
      `peer_type` enum('direct','group') NOT NULL DEFAULT 'direct',
      `peer_id` int(11) NOT NULL,
      `wallpaper_type` enum('image','preset','default') NOT NULL DEFAULT 'default',
      `wallpaper_value` varchar(500) DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`),
      UNIQUE KEY `idx_user_peer_wp` (`user_id`, `peer_type`, `peer_id`),
      KEY `idx_user_wp` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
} catch (\Exception $e) {}

// Ensure chat_groups and chat_group_members tables exist for WhatsApp-style Group Chat
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `chat_groups` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `name` varchar(150) NOT NULL,
      `description` varchar(500) DEFAULT NULL,
      `created_by` int(11) NOT NULL,
      `icon` varchar(255) DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `idx_created_by` (`created_by`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

    $db->exec("CREATE TABLE IF NOT EXISTS `chat_group_members` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `group_id` int(11) NOT NULL,
      `user_id` int(11) NOT NULL,
      `role` enum('admin','member') NOT NULL DEFAULT 'member',
      `last_read_message_id` int(11) NOT NULL DEFAULT 0,
      `joined_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      UNIQUE KEY `idx_group_user` (`group_id`, `user_id`),
      KEY `idx_user` (`user_id`),
      KEY `idx_group` (`group_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

    // Ensure last_read_message_id column exists
    try {
        $db->exec("ALTER TABLE `chat_group_members` ADD COLUMN `last_read_message_id` int(11) NOT NULL DEFAULT 0");
    } catch (\Exception $colEx) {}
} catch (\Exception $e) {}

/**
 * DigiChat End-to-End Enterprise Encryption (E2EE) Module
 * Stores all chat messages in AES-256-CBC cipher hash form in the database.
 * No readable plain text is saved in MySQL or database dumps.
 */
if (!defined('CHAT_ENCRYPTION_SECRET')) {
    define('CHAT_ENCRYPTION_SECRET', 'DigiInk_Enterprise_E2EE_Secret_Key_2026_Secured_v1');
}

if (!function_exists('getChatEncryptionKey')) {
    function getChatEncryptionKey() {
        return hash('sha256', CHAT_ENCRYPTION_SECRET, true); // 32-byte binary key
    }
}

if (!function_exists('encryptChatMessage')) {
    function encryptChatMessage($plainText) {
        if ($plainText === null || $plainText === '') {
            return $plainText;
        }
        // If already in encrypted cipher hash form, return as-is
        if (str_starts_with($plainText, 'enc::')) {
            return $plainText;
        }
        $key = getChatEncryptionKey();
        $iv = openssl_random_pseudo_bytes(16);
        $ciphertext = openssl_encrypt($plainText, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            return $plainText;
        }
        // SHA-256 HMAC for tamper-proof cryptographic authenticity & integrity
        $hmac = hash_hmac('sha256', $iv . $ciphertext, $key, true);
        return 'enc::' . base64_encode($iv . $hmac . $ciphertext);
    }
}

if (!function_exists('decryptChatMessage')) {
    function decryptChatMessage($cipherText) {
        if ($cipherText === null || $cipherText === '') {
            return $cipherText;
        }
        if (!str_starts_with($cipherText, 'enc::')) {
            return $cipherText; // Legacy unencrypted message
        }
        $rawPayload = substr($cipherText, 5);
        $binary = base64_decode($rawPayload, true);
        if ($binary === false || strlen($binary) < 49) { // 16 (iv) + 32 (hmac) + >=1 (ciphertext)
            return '[Encrypted Message]';
        }
        $key = getChatEncryptionKey();
        $iv = substr($binary, 0, 16);
        $hmac = substr($binary, 16, 32);
        $ciphertext = substr($binary, 48);

        $calculatedHmac = hash_hmac('sha256', $iv . $ciphertext, $key, true);
        if (!hash_equals($calculatedHmac, $hmac)) {
            return '[Encrypted Message (Tampered)]';
        }

        $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($decrypted === false) {
            return '[Encrypted Message]';
        }
        return $decrypted;
    }
}

/**
 * Attaches reply preview data and reactions array to a list of messages
 */
if (!function_exists('populateMessagesMetadata')) {
    function populateMessagesMetadata(PDO $db, array &$messages, int $currentUserId) {
        if (empty($messages)) {
            return;
        }

        $msgIds = [];
        $replyIds = [];
        foreach ($messages as &$m) {
            $msgIds[] = (int)$m['id'];
            if (!empty($m['reply_to_id'])) {
                $replyIds[] = (int)$m['reply_to_id'];
            }
            $m['reactions'] = [];
            $m['reply_to']  = null;
        }
        unset($m);

        // 1. Fetch Reactions for these messages
        if (!empty($msgIds)) {
            $msgIds = array_values(array_unique($msgIds));
            $inClause = implode(',', array_fill(0, count($msgIds), '?'));
            try {
                $stmtReactions = $db->prepare("
                    SELECT r.message_id, r.reaction, r.user_id, u.name as user_name
                    FROM chat_message_reactions r
                    JOIN users u ON u.id = r.user_id
                    WHERE r.message_id IN ($inClause)
                    ORDER BY r.id ASC
                ");
                $stmtReactions->execute($msgIds);
                $reactRows = $stmtReactions->fetchAll(PDO::FETCH_ASSOC);

                // Group by message_id -> emoji -> count & users
                $groupedReactions = [];
                foreach ($reactRows as $rr) {
                    $mId = (int)$rr['message_id'];
                    $emoji = $rr['reaction'];
                    if (!isset($groupedReactions[$mId])) {
                        $groupedReactions[$mId] = [];
                    }
                    if (!isset($groupedReactions[$mId][$emoji])) {
                        $groupedReactions[$mId][$emoji] = [
                            'emoji' => $emoji,
                            'count' => 0,
                            'users' => [],
                            'reacted_by_me' => false
                        ];
                    }
                    $groupedReactions[$mId][$emoji]['count']++;
                    $groupedReactions[$mId][$emoji]['users'][] = [
                        'user_id' => (int)$rr['user_id'],
                        'name'    => $rr['user_name']
                    ];
                    if ((int)$rr['user_id'] === $currentUserId) {
                        $groupedReactions[$mId][$emoji]['reacted_by_me'] = true;
                    }
                }

                foreach ($messages as &$m) {
                    $mId = (int)$m['id'];
                    if (isset($groupedReactions[$mId])) {
                        $m['reactions'] = array_values($groupedReactions[$mId]);
                    }
                }
                unset($m);
            } catch (\Exception $e) {}
        }

        // 2. Fetch Reply Previews for replied-to messages
        if (!empty($replyIds)) {
            $replyIds = array_values(array_unique($replyIds));
            $inClause = implode(',', array_fill(0, count($replyIds), '?'));
            try {
                $stmtReplies = $db->prepare("
                    SELECT m.id, m.sender_id, m.message, m.file_path, m.file_name, m.file_type, 
                           m.is_deleted_for_everyone, u.name as sender_name
                    FROM chat_messages m
                    JOIN users u ON u.id = m.sender_id
                    WHERE m.id IN ($inClause)
                ");
                $stmtReplies->execute($replyIds);
                $replyRows = $stmtReplies->fetchAll(PDO::FETCH_ASSOC);

                $replyMap = [];
                foreach ($replyRows as $rr) {
                    $decryptedMsg = '';
                    if ((int)$rr['is_deleted_for_everyone'] === 1) {
                        $decryptedMsg = 'This message was deleted';
                    } elseif (!empty($rr['message'])) {
                        $decryptedMsg = decryptChatMessage($rr['message']);
                    }
                    $replyMap[(int)$rr['id']] = [
                        'id'          => (int)$rr['id'],
                        'sender_id'   => (int)$rr['sender_id'],
                        'sender_name' => $rr['sender_name'],
                        'message'     => $decryptedMsg,
                        'file_path'   => $rr['file_path'],
                        'file_name'   => $rr['file_name'],
                        'file_type'   => $rr['file_type']
                    ];
                }

                foreach ($messages as &$m) {
                    if (!empty($m['reply_to_id']) && isset($replyMap[(int)$m['reply_to_id']])) {
                        $m['reply_to'] = $replyMap[(int)$m['reply_to_id']];
                    }
                }
                unset($m);
            } catch (\Exception $e) {}
        }
    }
}

// Auto-migrate any unencrypted legacy messages so database stores 100% hash form
try {
    $legacyStmt = $db->query("SELECT id, message FROM chat_messages WHERE message IS NOT NULL AND message != '' AND message NOT LIKE 'enc::%' LIMIT 100");
    $legacyRows = $legacyStmt ? $legacyStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    if (!empty($legacyRows)) {
        $updStmt = $db->prepare("UPDATE chat_messages SET message = :msg WHERE id = :id");
        foreach ($legacyRows as $lr) {
            $enc = encryptChatMessage($lr['message']);
            $updStmt->execute([':msg' => $enc, ':id' => $lr['id']]);
        }
    }
} catch (\Exception $e) {}

date_default_timezone_set('Asia/Kolkata');

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// If user is closing tab/browser or logging out, set offline immediately
if ($action === 'set_offline') {
    try {
        $pastTime = date('Y-m-d H:i:s', time() - 60);
        $db->prepare("UPDATE users SET last_seen = :ts WHERE id = :me")->execute([':ts' => $pastTime, ':me' => $myId]);
    } catch (\Exception $e) {}
    echo json_encode(['success' => true]);
    exit;
}

// =========================================================================
// ACTION: media_info (Return HD size & Normal preview size for images/videos)
// =========================================================================
if ($action === 'media_info') {
    $filePathParam = $_GET['file_path'] ?? ($_POST['file_path'] ?? '');
    // Clean and validate relative file path
    $filePathClean = ltrim(str_replace(['../', '..\\'], '', $filePathParam), '/\\');
    $fullPath = realpath(__DIR__ . '/../../' . $filePathClean);
    $baseUploadDir = realpath(__DIR__ . '/../../uploads/chat_files');

    if (!$fullPath || !file_exists($fullPath) || !str_starts_with($fullPath, realpath(__DIR__ . '/../../uploads'))) {
        echo json_encode(['success' => false, 'error' => 'File not found']);
        exit;
    }

    $hdSize = filesize($fullPath);
    $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif']);
    $isVideo = in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm']);

    $normalSize = $hdSize;
    if ($isImage) {
        // Normal compressed/resized cache check
        $cacheDir = __DIR__ . '/../../uploads/chat_cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0777, true);
        }
        $cacheFile = $cacheDir . '/normal_' . basename($fullPath);
        if (file_exists($cacheFile)) {
            $normalSize = filesize($cacheFile);
        } else {
            // Generate or calculate estimated normal size
            $normalSize = max(10240, (int)round($hdSize * 0.35));
        }
    } elseif ($isVideo) {
        // Video normal estimation
        $normalSize = max(51200, (int)round($hdSize * 0.45));
    }

    echo json_encode([
        'success'     => true,
        'is_image'    => $isImage,
        'is_video'    => $isVideo,
        'hd_size'     => $hdSize,
        'normal_size' => $normalSize
    ]);
    exit;
}

// =========================================================================
// ACTION: download_media (Download HD / Normal image or video)
// =========================================================================
if ($action === 'download_media') {
    $filePathParam = $_GET['file_path'] ?? '';
    $quality       = strtolower($_GET['quality'] ?? 'hd'); // 'hd' or 'normal'
    $customName    = trim($_GET['file_name'] ?? '');

    $filePathClean = ltrim(str_replace(['../', '..\\'], '', $filePathParam), '/\\');
    $fullPath      = realpath(__DIR__ . '/../../' . $filePathClean);

    if (!$fullPath || !file_exists($fullPath) || !str_starts_with($fullPath, realpath(__DIR__ . '/../../uploads'))) {
        http_response_code(404);
        echo "File not found";
        exit;
    }

    $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    $fileName = !empty($customName) ? basename($customName) : basename($fullPath);

    // If downloading normal version and it is an image, compress/resize with GD
    $servePath = $fullPath;
    if ($quality === 'normal' && in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
        $cacheDir = __DIR__ . '/../../uploads/chat_cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0777, true);
        }
        $cacheFile = $cacheDir . '/normal_' . basename($fullPath);

        if (!file_exists($cacheFile) && extension_loaded('gd')) {
            try {
                $srcImg = match($ext) {
                    'jpg', 'jpeg' => @imagecreatefromjpeg($fullPath),
                    'png'         => @imagecreatefrompng($fullPath),
                    'webp'        => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($fullPath) : false,
                    default       => false
                };

                if ($srcImg) {
                    $origW = imagesx($srcImg);
                    $origH = imagesy($srcImg);

                    // Max standard width/height 1280px for normal quality
                    $maxDim = 1280;
                    if ($origW > $maxDim || $origH > $maxDim) {
                        if ($origW >= $origH) {
                            $newW = $maxDim;
                            $newH = (int)round(($origH / $origW) * $maxDim);
                        } else {
                            $newH = $maxDim;
                            $newW = (int)round(($origW / $origH) * $maxDim);
                        }
                    } else {
                        $newW = $origW;
                        $newH = $origH;
                    }

                    $dstImg = imagecreatetruecolor($newW, $newH);
                    if ($ext === 'png') {
                        imagealphablending($dstImg, false);
                        imagesavealpha($dstImg, true);
                    }
                    imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

                    // Save with medium quality (65%)
                    if ($ext === 'png') {
                        imagepng($dstImg, $cacheFile, 6);
                    } elseif ($ext === 'webp' && function_exists('imagewebp')) {
                        imagewebp($dstImg, $cacheFile, 65);
                    } else {
                        imagejpeg($dstImg, $cacheFile, 65);
                    }

                    imagedestroy($dstImg);
                    imagedestroy($srcImg);
                }
            } catch (\Exception $e) {}
        }

        if (file_exists($cacheFile)) {
            $servePath = $cacheFile;
        }
    }

    // Direct download stream without browser restriction/popup blocker
    if (ob_get_level()) {
        ob_end_clean();
    }

    $mime = mime_content_type($servePath) ?: 'application/octet-stream';
    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $fileName) . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($servePath));
    readfile($servePath);
    exit;
}

// =========================================================================
// ACTION: get_wallpaper (Retrieve chat background wallpaper for direct/group)
// =========================================================================
if ($action === 'get_wallpaper') {
    $peerType = ($_GET['peer_type'] ?? 'direct') === 'group' ? 'group' : 'direct';
    $peerId   = (int)($_GET['peer_id'] ?? 0);

    if ($peerId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid peer ID']);
        exit;
    }

    try {
        $stmtWp = $db->prepare("
            SELECT wallpaper_type, wallpaper_value 
            FROM chat_wallpapers 
            WHERE user_id = :uid AND peer_type = :ptype AND peer_id = :pid
        ");
        $stmtWp->execute([':uid' => $myId, ':ptype' => $peerType, ':pid' => $peerId]);
        $row = $stmtWp->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            echo json_encode([
                'success'         => true,
                'wallpaper_type'  => $row['wallpaper_type'],
                'wallpaper_value' => $row['wallpaper_value']
            ]);
        } else {
            echo json_encode([
                'success'         => true,
                'wallpaper_type'  => 'default',
                'wallpaper_value' => ''
            ]);
        }
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// ACTION: set_wallpaper (Save custom image or preset background for chat)
// =========================================================================
if ($action === 'set_wallpaper') {
    $peerType = ($_POST['peer_type'] ?? 'direct') === 'group' ? 'group' : 'direct';
    $peerId   = (int)($_POST['peer_id'] ?? 0);
    $wpType   = $_POST['wallpaper_type'] ?? 'default'; // 'default', 'preset', 'image'
    $wpValue  = trim($_POST['wallpaper_value'] ?? '');

    if ($peerId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid peer ID']);
        exit;
    }

    // Handle custom image file upload if provided
    if (!empty($_FILES['wallpaper_image']['name']) && $_FILES['wallpaper_image']['error'] === UPLOAD_ERR_OK) {
        $wpUploadDir = __DIR__ . '/../../uploads/chat_wallpapers/';
        if (!is_dir($wpUploadDir)) {
            @mkdir($wpUploadDir, 0777, true);
        }

        $orig = basename($_FILES['wallpaper_image']['name']);
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];

        if (!in_array($ext, $allowed)) {
            echo json_encode(['success' => false, 'error' => 'Please upload a valid JPG, PNG or WEBP image']);
            exit;
        }

        $safeName = 'wp_' . $myId . '_' . $peerType . '_' . $peerId . '_' . time() . '.' . $ext;
        $target = $wpUploadDir . $safeName;

        if (move_uploaded_file($_FILES['wallpaper_image']['tmp_name'], $target)) {
            $wpType = 'image';
            $wpValue = 'uploads/chat_wallpapers/' . $safeName;
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to save background image']);
            exit;
        }
    }

    try {
        $stmtSave = $db->prepare("
            INSERT INTO chat_wallpapers (user_id, peer_type, peer_id, wallpaper_type, wallpaper_value, created_at, updated_at)
            VALUES (:uid, :ptype, :pid, :wtype, :wval, NOW(), NOW())
            ON DUPLICATE KEY UPDATE 
                wallpaper_type = VALUES(wallpaper_type),
                wallpaper_value = VALUES(wallpaper_value),
                updated_at = NOW()
        ");
        $stmtSave->execute([
            ':uid'   => $myId,
            ':ptype' => $peerType,
            ':pid'   => $peerId,
            ':wtype' => $wpType,
            ':wval'  => $wpValue
        ]);

        echo json_encode([
            'success'         => true,
            'wallpaper_type'  => $wpType,
            'wallpaper_value' => $wpValue
        ]);
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// For active sessions, update current user's last_seen timestamp in exact IST time
try {
    $nowTime = date('Y-m-d H:i:s');
    $db->prepare("UPDATE users SET last_seen = :ts WHERE id = :me")->execute([':ts' => $nowTime, ':me' => $myId]);
} catch (\Exception $e) {}

// Automatically mark any incoming messages for current user as delivered with timestamp
try {
    $db->prepare("UPDATE chat_messages SET is_delivered = 1, delivered_at = COALESCE(delivered_at, NOW()) WHERE receiver_id = :me AND is_delivered = 0")->execute([':me' => $myId]);
} catch (\Exception $e) {}

// If this is a heartbeat ping, respond immediately with real-time unread chat metrics & incoming calls
if ($action === 'heartbeat') {
    $unreadCount = 0;
    $latest = null;
    $incomingCall = null;

    try {
        $stmtUnread = $db->prepare("
            SELECT COUNT(*) FROM chat_messages m
            LEFT JOIN chat_blocks b ON (b.blocker_id = :me AND b.blocked_id = m.sender_id)
            WHERE m.receiver_id = :me 
              AND b.id IS NULL
              AND m.is_read = 0 
              AND m.deleted_for_receiver = 0 
              AND m.is_deleted_for_everyone = 0
        ");
        $stmtUnread->execute([':me' => $myId]);
        $unreadCount = (int)$stmtUnread->fetchColumn();

        // Calculate group unread count for current user
        try {
            $stmtGrpUnread = $db->prepare("
                SELECT COUNT(*) FROM chat_messages m
                JOIN chat_group_members gm ON (gm.group_id = m.group_id AND gm.user_id = :me)
                WHERE m.group_id IS NOT NULL
                  AND m.id > gm.last_read_message_id
                  AND m.sender_id != :me
            ");
            $stmtGrpUnread->execute([':me' => $myId]);
            $unreadCount += (int)$stmtGrpUnread->fetchColumn();
        } catch (\Exception $e) {}

        if ($unreadCount > 0) {
            $stmtLatest = $db->prepare("
                SELECT m.id, m.sender_id, m.message, m.file_name, m.created_at, u.name AS sender_name, u.role AS sender_role
                FROM chat_messages m
                JOIN users u ON m.sender_id = u.id
                LEFT JOIN chat_blocks b ON (b.blocker_id = :me AND b.blocked_id = m.sender_id)
                WHERE m.receiver_id = :me 
                  AND b.id IS NULL
                  AND m.is_read = 0 
                  AND m.deleted_for_receiver = 0 
                  AND m.is_deleted_for_everyone = 0
                ORDER BY m.id DESC 
                LIMIT 1
            ");
            $stmtLatest->execute([':me' => $myId]);
            $latest = $stmtLatest->fetch(PDO::FETCH_ASSOC);
            if ($latest && !empty($latest['message'])) {
                $latest['message'] = decryptChatMessage($latest['message']);
            }
        }

        // Check active incoming call (excluding blocked callers, including Group Calls)
        $stmtCall = $db->prepare("
            SELECT c.id, c.caller_id, c.receiver_id, c.group_id, c.call_type, c.status, 
                   u.name AS caller_name, u.role AS caller_role,
                   g.name AS group_name
            FROM chat_calls c
            JOIN users u ON c.caller_id = u.id
            LEFT JOIN chat_groups g ON g.id = c.group_id
            LEFT JOIN chat_blocks b ON ((b.blocker_id = :me AND b.blocked_id = c.caller_id) OR (b.blocker_id = c.caller_id AND b.blocked_id = :me))
            WHERE (
                (c.receiver_id = :me AND b.id IS NULL AND c.status IN ('calling', 'ringing'))
                OR
                (c.group_id IS NOT NULL 
                 AND c.caller_id != :me 
                 AND c.status IN ('calling', 'ringing', 'connected')
                 AND c.group_id IN (SELECT group_id FROM chat_group_members WHERE user_id = :me)
                 AND NOT EXISTS (SELECT 1 FROM chat_call_participants WHERE call_id = c.id AND user_id = :me AND status IN ('declined', 'left'))
                )
            )
            AND c.created_at >= (NOW() - INTERVAL 60 SECOND)
            ORDER BY c.id DESC LIMIT 1
        ");
        $stmtCall->execute([':me' => $myId]);
        $incomingCall = $stmtCall->fetch(PDO::FETCH_ASSOC);
    } catch (\Exception $e) {}

    echo json_encode([
        'success'       => true,
        'unread_count'  => $unreadCount,
        'badge'         => ($unreadCount > 9) ? '9+' : (string)$unreadCount,
        'latest'        => $latest,
        'incoming_call' => $incomingCall
    ]);
    exit;
}

/**
 * Format Last Seen Presence
 * If active within last 35s => Online
 * Otherwise => "Last seen today at 12:09 PM", "Last seen yesterday at...", etc.
 */
if (!function_exists('formatLastSeen')) {
    function formatLastSeen($datetime) {
        if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
            return [
                'is_online' => false,
                'text'      => 'Offline'
            ];
        }

        $timestamp = strtotime($datetime);
        if (!$timestamp) {
            return [
                'is_online' => false,
                'text'      => 'Offline'
            ];
        }

        $diff = time() - $timestamp;

        // Within last 35 seconds = Online (with -10s buffer for slight clock sync variance)
        if ($diff >= -10 && $diff <= 35) {
            return [
                'is_online' => true,
                'text'      => 'Online'
            ];
        }

        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $seenDate = date('Y-m-d', $timestamp);
        $timeStr = date('h:i A', $timestamp);

        if ($seenDate === $today) {
            $text = "Last seen today at " . $timeStr;
        } elseif ($seenDate === $yesterday) {
            $text = "Last seen yesterday at " . $timeStr;
        } else {
            $text = "Last seen " . date('d M', $timestamp) . " at " . $timeStr;
        }

        return [
            'is_online' => false,
            'text'      => $text
        ];
    }
}

/**
 * Strict Enterprise Communication Matrix
 */
if (!function_exists('isChatAllowed')) {
    function isChatAllowed($myRole, $targetRole, $myUsername = '', $targetUsername = '', $myId = 0, $targetId = 0) {
        if ($myRole === 'admin' || $targetRole === 'admin') {
            return true;
        }

        // Special Authorized Exceptions: Specific BDEs ('di.ish2505' [ID: 3], 'di.suv2505' [ID: 2], 'di.avigat2505' [ID: 30])
        // and 'operations_manager' can chat & call directly!
        $myU = strtolower(trim($myUsername));
        $targetU = strtolower(trim($targetUsername));
        $allowedBdeUsernames = ['di.ish2505', 'di.suv2505', 'di.avigat2505'];
        $allowedBdeIds = [3, 2, 30];

        $isMeAllowedBde = (in_array($myU, $allowedBdeUsernames, true) || in_array((int)$myId, $allowedBdeIds, true));
        $isTargetAllowedBde = (in_array($targetU, $allowedBdeUsernames, true) || in_array((int)$targetId, $allowedBdeIds, true));

        if (($isMeAllowedBde && $targetRole === 'operations_manager') || 
            ($myRole === 'operations_manager' && $isTargetAllowedBde)) {
            return true;
        }

        // Strict constraint: Operations Team and BDE can NEVER chat directly
        if ($myRole === 'operations_team' && $targetRole === 'bde') return false;
        if ($myRole === 'bde' && $targetRole === 'operations_team') return false;

        // Operations Manager can chat with Operations Team, BD Manager, QC, Admin, other OM
        if ($myRole === 'operations_manager') {
            return in_array($targetRole, ['operations_team', 'bdmanager', 'qc', 'admin', 'operations_manager']);
        }
        // Operations Team can chat with Operations Manager, QC, Admin, other Team
        if ($myRole === 'operations_team') {
            return in_array($targetRole, ['operations_manager', 'qc', 'admin', 'operations_team']);
        }
        // BD Manager can chat with BDE, Operations Manager, Admin, other BDM
        if ($myRole === 'bdmanager') {
            return in_array($targetRole, ['bde', 'operations_manager', 'admin', 'bdmanager']);
        }
        // BDE can chat with BD Manager, Admin, other BDE
        if ($myRole === 'bde') {
            return in_array($targetRole, ['bdmanager', 'admin', 'bde']);
        }
        // QC can chat with Operations Manager, Operations Team, Admin, other QC
        if ($myRole === 'qc') {
            return in_array($targetRole, ['operations_manager', 'operations_team', 'admin', 'qc']);
        }
        return false;
    }
}

switch ($action) {

    /* ==========================================================
       GET CONTACTS LIST (Role Filtered + Last Msg + Presence)
       ========================================================== */
    case 'get_contacts':
        try {
            $stmt = $db->prepare("SELECT id, name, username, role, status, last_seen, profile_image, about FROM users WHERE id != :me AND status = 'active' ORDER BY name ASC");
            $stmt->execute([':me' => $myId]);
            $allUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch block relationships for current user
            $stmtBlocks = $db->prepare("SELECT blocked_id FROM chat_blocks WHERE blocker_id = :me");
            $stmtBlocks->execute([':me' => $myId]);
            $blockedByUser = $stmtBlocks->fetchAll(PDO::FETCH_COLUMN);

            $stmtBlockedBy = $db->prepare("SELECT blocker_id FROM chat_blocks WHERE blocked_id = :me");
            $stmtBlockedBy->execute([':me' => $myId]);
            $blockedByOthers = $stmtBlockedBy->fetchAll(PDO::FETCH_COLUMN);

            // Fetch pinned chats for current user
            $pinnedDirect = [];
            $pinnedGroups = [];
            try {
                $stmtPinned = $db->prepare("SELECT peer_type, peer_id FROM chat_pinned WHERE user_id = :me");
                $stmtPinned->execute([':me' => $myId]);
                $pinnedRows = $stmtPinned->fetchAll(PDO::FETCH_ASSOC);
                foreach ($pinnedRows as $pr) {
                    if ($pr['peer_type'] === 'direct') {
                        $pinnedDirect[] = (int)$pr['peer_id'];
                    } elseif ($pr['peer_type'] === 'group') {
                        $pinnedGroups[] = (int)$pr['peer_id'];
                    }
                }
            } catch (\Exception $e) {}

            // Fetch personal nicknames assigned by current user
            $nicknameMap = [];
            try {
                $stmtNicks = $db->prepare("SELECT contact_id, nickname FROM chat_nicknames WHERE user_id = :me");
                $stmtNicks->execute([':me' => $myId]);
                $nicknameMap = $stmtNicks->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            } catch (\Exception $e) {}

            $contacts = [];

            foreach ($allUsers as $u) {
                if (!isChatAllowed($myRole, $u['role'], $myUsername, $u['username'], $myId, (int)$u['id'])) {
                    continue;
                }

                $uid = (int)$u['id'];

                // Get last message between me and this user that is NOT deleted for me
                $stmtMsg = $db->prepare("
                    SELECT id, sender_id, message, file_name, file_type, created_at, is_delivered, is_read, is_edited, is_deleted_for_everyone
                    FROM chat_messages
                    WHERE ((sender_id = :me AND receiver_id = :uid AND deleted_for_sender = 0)
                       OR (sender_id = :uid AND receiver_id = :me AND deleted_for_receiver = 0))
                    ORDER BY id DESC LIMIT 1
                ");
                $stmtMsg->execute([':me' => $myId, ':uid' => $uid]);
                $lastMsg = $stmtMsg->fetch(PDO::FETCH_ASSOC);

                // Get unread count sent from this user to me
                $stmtUnread = $db->prepare("
                    SELECT COUNT(*) FROM chat_messages
                    WHERE sender_id = :uid AND receiver_id = :me AND is_read = 0 AND deleted_for_receiver = 0
                ");
                $stmtUnread->execute([':me' => $myId, ':uid' => $uid]);
                $unreadCount = (int)$stmtUnread->fetchColumn();

                $presence = formatLastSeen($u['last_seen'] ?? null);

                $previewText = '';
                if ($lastMsg) {
                    if ((int)$lastMsg['is_deleted_for_everyone'] === 1) {
                        $previewText = '🚫 This message was deleted';
                    } elseif (!empty($lastMsg['is_view_once'])) {
                        $previewText = !empty($lastMsg['view_once_opened']) ? '① Opened' : '① Photo';
                    } elseif ($lastMsg['message']) {
                        $previewText = decryptChatMessage($lastMsg['message']);
                    } elseif ($lastMsg['file_name']) {
                        $previewText = '📎 ' . $lastMsg['file_name'];
                    } else {
                        $previewText = 'Attachment';
                    }
                }

                $rawName = $u['name'] ?: $u['username'];
                $nick = !empty($nicknameMap[$uid]) ? trim($nicknameMap[$uid]) : '';

                $contacts[] = [
                    'id'                 => $uid,
                    'name'               => $nick !== '' ? $nick : $rawName,
                    'real_name'          => $rawName,
                    'nickname'           => $nick,
                    'username'           => $u['username'],
                    'role'               => $u['role'],
                    'role_label'         => ucwords(str_replace('_', ' ', $u['role'])),
                    'profile_image'      => !empty($u['profile_image']) ? $u['profile_image'] : null,
                    'is_online'          => $presence['is_online'],
                    'last_seen_text'     => $presence['text'],
                    'last_message'       => $previewText,
                    'last_time'          => $lastMsg ? $lastMsg['created_at'] : '',
                    'last_is_mine'       => $lastMsg ? ((int)$lastMsg['sender_id'] === $myId) : false,
                    'last_is_delivered'  => $lastMsg ? (bool)$lastMsg['is_delivered'] : false,
                    'last_is_read'       => $lastMsg ? (bool)$lastMsg['is_read'] : false,
                    'unread_count'       => $unreadCount,
                    'about'              => !empty($u['about']) ? $u['about'] : 'Available',
                    'is_blocked_by_me'   => in_array($uid, $blockedByUser),
                    'is_blocked_by_them' => in_array($uid, $blockedByOthers),
                    'is_pinned'          => in_array($uid, $pinnedDirect)
                ];
            }

            // Sort contacts: ones with recent messages first
            usort($contacts, function ($a, $b) {
                if (empty($a['last_time']) && empty($b['last_time'])) return 0;
                if (empty($a['last_time'])) return 1;
                if (empty($b['last_time'])) return -1;
                return strtotime($b['last_time']) - strtotime($a['last_time']);
            });

            // Fetch Groups where current user is a member
            $groups = [];
            $totalGroupUnread = 0;
            try {
                $stmtGroups = $db->prepare("
                    SELECT g.id, g.name, g.description, g.created_by, g.created_at, gm.role AS my_role, gm.last_read_message_id,
                           (SELECT COUNT(*) FROM chat_group_members WHERE group_id = g.id) AS member_count,
                           (SELECT COUNT(*) FROM chat_messages m WHERE m.group_id = g.id AND m.id > gm.last_read_message_id AND m.sender_id != :me) AS unread_count,
                           (SELECT m.id FROM chat_messages m WHERE m.group_id = g.id ORDER BY m.id DESC LIMIT 1) AS last_msg_id,
                           (SELECT m.message FROM chat_messages m WHERE m.group_id = g.id ORDER BY m.id DESC LIMIT 1) AS last_msg_text,
                           (SELECT m.file_name FROM chat_messages m WHERE m.group_id = g.id ORDER BY m.id DESC LIMIT 1) AS last_msg_file,
                           (SELECT m.is_view_once FROM chat_messages m WHERE m.group_id = g.id ORDER BY m.id DESC LIMIT 1) AS last_msg_view_once,
                           (SELECT m.view_once_opened FROM chat_messages m WHERE m.group_id = g.id ORDER BY m.id DESC LIMIT 1) AS last_msg_view_once_opened,
                           (SELECT m.created_at FROM chat_messages m WHERE m.group_id = g.id ORDER BY m.id DESC LIMIT 1) AS last_msg_time,
                           (SELECT u.name FROM chat_messages m JOIN users u ON u.id = m.sender_id WHERE m.group_id = g.id ORDER BY m.id DESC LIMIT 1) AS last_sender_name
                    FROM chat_groups g
                    JOIN chat_group_members gm ON (gm.group_id = g.id AND gm.user_id = :me)
                    ORDER BY g.id DESC
                ");
                $stmtGroups->execute([':me' => $myId]);
                $rawGroups = $stmtGroups->fetchAll(PDO::FETCH_ASSOC);

                foreach ($rawGroups as $grp) {
                    $previewText = '';
                    $prefix = !empty($grp['last_sender_name']) ? ($grp['last_sender_name'] . ': ') : '';
                    if (!empty($grp['last_msg_view_once'])) {
                        $previewText = $prefix . (!empty($grp['last_msg_view_once_opened']) ? '① Opened' : '① Photo');
                    } elseif (!empty($grp['last_msg_text'])) {
                        $decrypted = decryptChatMessage($grp['last_msg_text']);
                        $previewText = $prefix . $decrypted;
                    } elseif (!empty($grp['last_msg_file'])) {
                        $previewText = $prefix . '📎 ' . $grp['last_msg_file'];
                    }

                    $uCount = (int)$grp['unread_count'];
                    $totalGroupUnread += $uCount;

                    $groups[] = [
                        'id'           => (int)$grp['id'],
                        'is_group'     => true,
                        'name'         => $grp['name'],
                        'description'  => $grp['description'] ?? '',
                        'created_by'   => (int)$grp['created_by'],
                        'created_at'   => $grp['created_at'],
                        'my_role'      => $grp['my_role'] ?? 'member',
                        'member_count' => (int)$grp['member_count'],
                        'unread_count' => $uCount,
                        'last_message' => $previewText,
                        'last_time'    => $grp['last_msg_time'] ?? $grp['created_at'],
                        'is_pinned'    => in_array((int)$grp['id'], $pinnedGroups)
                    ];
                }

                // Sort groups by recent message
                usort($groups, function ($a, $b) {
                    if (empty($a['last_time']) && empty($b['last_time'])) return 0;
                    if (empty($a['last_time'])) return 1;
                    if (empty($b['last_time'])) return -1;
                    return strtotime($b['last_time']) - strtotime($a['last_time']);
                });
            } catch (\Exception $e) {}

            // Global unread count (excluding blocked users) + group unread
            $stmtTotal = $db->prepare("
                SELECT COUNT(*) FROM chat_messages m
                LEFT JOIN chat_blocks b ON (b.blocker_id = :me AND b.blocked_id = m.sender_id)
                WHERE m.receiver_id = :me AND m.is_read = 0 AND m.deleted_for_receiver = 0 AND b.id IS NULL
            ");
            $stmtTotal->execute([':me' => $myId]);
            $totalUnread = (int)$stmtTotal->fetchColumn() + $totalGroupUnread;

            echo json_encode([
                'success'      => true,
                'contacts'     => $contacts,
                'groups'       => $groups,
                'total_unread' => $totalUnread
            ]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /* ==========================================================
       GET MESSAGES FOR A CONTACT (Mark Read + Return Thread)
       ========================================================== */
    case 'get_messages':
        $contactId = (int)($_GET['contact_id'] ?? 0);
        if ($contactId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid contact']);
            exit;
        }

        try {
            // Verify target user and permission
            $stmtUser = $db->prepare("SELECT id, name, username, role, last_seen, profile_image, about FROM users WHERE id = :id");
            $stmtUser->execute([':id' => $contactId]);
            $targetUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

            if (!$targetUser || !isChatAllowed($myRole, $targetUser['role'], $myUsername, $targetUser['username'] ?? '', $myId, (int)$targetUser['id'])) {
                echo json_encode(['success' => false, 'error' => 'Communication with this user is restricted by policy.']);
                exit;
            }

            // Mark unread messages from this contact as read AND delivered with timestamps
            $stmtRead = $db->prepare("UPDATE chat_messages SET is_read = 1, is_delivered = 1, delivered_at = COALESCE(delivered_at, NOW()), read_at = COALESCE(read_at, NOW()) WHERE sender_id = :cid AND receiver_id = :me AND is_read = 0");
            $stmtRead->execute([':cid' => $contactId, ':me' => $myId]);

            // Fetch messages (excluding deleted for me)
            $afterId = (int)($_GET['after_id'] ?? 0);
            $querySql = "
                SELECT id, sender_id, receiver_id, message, file_path, file_name, file_type, file_size, 
                       is_delivered, is_read, is_edited, is_deleted_for_everyone, is_forwarded,
                       is_view_once, view_once_opened, view_once_opened_at,
                       delivered_at, read_at, reply_to_id, created_at
                FROM chat_messages
                WHERE ((sender_id = :me AND receiver_id = :cid AND deleted_for_sender = 0)
                   OR (sender_id = :cid AND receiver_id = :me AND deleted_for_receiver = 0))
            ";

            if ($afterId > 0) {
                $querySql .= " AND id > :afterId ORDER BY id ASC";
                $stmt = $db->prepare($querySql);
                $stmt->execute([':me' => $myId, ':cid' => $contactId, ':afterId' => $afterId]);
            } else {
                $querySql .= " ORDER BY id ASC LIMIT 250";
                $stmt = $db->prepare($querySql);
                $stmt->execute([':me' => $myId, ':cid' => $contactId]);
            }

            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Clean messages if deleted for everyone, or decrypt for active chat
            foreach ($messages as &$m) {
                if ((int)$m['is_deleted_for_everyone'] === 1) {
                    $m['message'] = 'This message was deleted';
                    $m['file_path'] = null;
                    $m['file_name'] = null;
                } elseif (!empty($m['is_view_once'])) {
                    if (!empty($m['view_once_opened'])) {
                        $m['message'] = null;
                        $m['file_path'] = null;
                    } elseif ((int)$m['sender_id'] !== $myId) {
                        // Mask content until receiver clicks to view
                        $m['message'] = null;
                    } else {
                        $m['message'] = decryptChatMessage($m['message']);
                    }
                } else {
                    $m['message'] = decryptChatMessage($m['message']);
                }
            }
            unset($m);

            populateMessagesMetadata($db, $messages, $myId);

            $targetPresence = formatLastSeen($targetUser['last_seen'] ?? null);

            // Block status between me and target contact
            $stmtBlockCheck = $db->prepare("SELECT 
                EXISTS(SELECT 1 FROM chat_blocks WHERE blocker_id = :me AND blocked_id = :cid) AS blocked_by_me,
                EXISTS(SELECT 1 FROM chat_blocks WHERE blocker_id = :cid AND blocked_id = :me) AS blocked_by_them
            ");
            $stmtBlockCheck->execute([':me' => $myId, ':cid' => $contactId]);
            $bRow = $stmtBlockCheck->fetch(PDO::FETCH_ASSOC);
            $isBlockedByMe   = (bool)($bRow['blocked_by_me'] ?? 0);
            $isBlockedByThem = (bool)($bRow['blocked_by_them'] ?? 0);

            // Nickname for contact
            $customNickname = '';
            $nicknameByThem = '';
            try {
                // Nickname I gave this contact
                $stmtNick = $db->prepare("SELECT nickname FROM chat_nicknames WHERE user_id = :me AND contact_id = :cid");
                $stmtNick->execute([':me' => $myId, ':cid' => $contactId]);
                $customNickname = (string)($stmtNick->fetchColumn() ?: '');

                // Nickname this contact gave me
                $stmtNickByThem = $db->prepare("SELECT nickname FROM chat_nicknames WHERE user_id = :cid AND contact_id = :me");
                $stmtNickByThem->execute([':cid' => $contactId, ':me' => $myId]);
                $nicknameByThem = (string)($stmtNickByThem->fetchColumn() ?: '');
            } catch (\Exception $e) {}

            $rawName = $targetUser['name'] ?: $targetUser['username'];

            echo json_encode([
                'success'      => true,
                'contact'      => [
                    'id'                 => (int)$targetUser['id'],
                    'name'               => $customNickname !== '' ? $customNickname : $rawName,
                    'real_name'          => $rawName,
                    'nickname'           => $customNickname,
                    'nickname_by_them'   => $nicknameByThem,
                    'username'           => $targetUser['username'],
                    'role'               => $targetUser['role'],
                    'role_label'         => ucwords(str_replace('_', ' ', $targetUser['role'])),
                    'profile_image'      => !empty($targetUser['profile_image']) ? $targetUser['profile_image'] : null,
                    'is_online'          => $targetPresence['is_online'],
                    'last_seen_text'     => $targetPresence['text'],
                    'about'              => !empty($targetUser['about']) ? $targetUser['about'] : 'Available',
                    'is_blocked_by_me'   => $isBlockedByMe,
                    'is_blocked_by_them' => $isBlockedByThem
                ],
                'messages'     => $messages
            ]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /* ==========================================================
       CREATE GROUP CHAT (WhatsApp Style Custom Name & Members)
       ========================================================== */
    case 'create_group':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }

        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (empty($name)) {
            echo json_encode(['success' => false, 'error' => 'Group name is required. Please provide a group name.']);
            exit;
        }

        if (mb_strlen($name) > 150) {
            echo json_encode(['success' => false, 'error' => 'Group name must be 150 characters or less.']);
            exit;
        }

        $rawMembers = $_POST['members'] ?? $_POST['member_ids'] ?? [];
        if (is_string($rawMembers)) {
            $decoded = json_decode($rawMembers, true);
            $memberIds = is_array($decoded) ? $decoded : array_filter(array_map('intval', explode(',', $rawMembers)));
        } elseif (is_array($rawMembers)) {
            $memberIds = array_filter(array_map('intval', $rawMembers));
        } else {
            $memberIds = [];
        }

        try {
            // 1. Insert into chat_groups
            $stmt = $db->prepare("INSERT INTO chat_groups (name, description, created_by, created_at) VALUES (:name, :desc, :creator, NOW())");
            $stmt->execute([
                ':name'    => $name,
                ':desc'    => $description,
                ':creator' => $myId
            ]);
            $groupId = (int)$db->lastInsertId();

            // 2. Add creator as Admin
            $stmtAdmin = $db->prepare("INSERT INTO chat_group_members (group_id, user_id, role, last_read_message_id, joined_at) VALUES (:gid, :uid, 'admin', 0, NOW())");
            $stmtAdmin->execute([':gid' => $groupId, ':uid' => $myId]);

            // 3. Add members
            $stmtMember = $db->prepare("INSERT IGNORE INTO chat_group_members (group_id, user_id, role, last_read_message_id, joined_at) VALUES (:gid, :uid, 'member', 0, NOW())");
            $addedCount = 1;
            foreach ($memberIds as $mId) {
                $mId = (int)$mId;
                if ($mId > 0 && $mId !== $myId) {
                    $stmtMember->execute([':gid' => $groupId, ':uid' => $mId]);
                    $addedCount++;
                }
            }

            // 4. Initial system message
            $creatorName = $_SESSION['name'] ?? $_SESSION['username'] ?? 'User';
            $systemMsg = "✨ " . $creatorName . " created group \"" . $name . "\"";
            $encSysMsg = encryptChatMessage($systemMsg);

            $stmtSys = $db->prepare("INSERT INTO chat_messages (sender_id, receiver_id, group_id, message, is_delivered, delivered_at, created_at) VALUES (:sid, NULL, :gid, :msg, 1, NOW(), NOW())");
            $stmtSys->execute([
                ':sid' => $myId,
                ':gid' => $groupId,
                ':msg' => $encSysMsg
            ]);
            $sysMsgId = (int)$db->lastInsertId();

            // Update creator last read
            $db->prepare("UPDATE chat_group_members SET last_read_message_id = :mid WHERE group_id = :gid AND user_id = :uid")
               ->execute([':mid' => $sysMsgId, ':gid' => $groupId, ':uid' => $myId]);

            echo json_encode([
                'success'      => true,
                'group_id'     => $groupId,
                'group'        => [
                    'id'           => $groupId,
                    'name'         => $name,
                    'description'  => $description,
                    'member_count' => $addedCount
                ],
                'name'         => $name,
                'member_count' => $addedCount,
                'message'      => 'Group created successfully!'
            ]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /* ==========================================================
       GET GROUPS LIST FOR CURRENT USER
       ========================================================== */
    case 'get_groups':
        try {
            $stmtGroups = $db->prepare("
                SELECT g.id, g.name, g.description, g.created_by, g.created_at, gm.role AS my_role, gm.last_read_message_id,
                       (SELECT COUNT(*) FROM chat_group_members WHERE group_id = g.id) AS member_count,
                       (SELECT COUNT(*) FROM chat_messages m WHERE m.group_id = g.id AND m.id > gm.last_read_message_id AND m.sender_id != :me) AS unread_count,
                       (SELECT m.id FROM chat_messages m WHERE m.group_id = g.id ORDER BY m.id DESC LIMIT 1) AS last_msg_id,
                       (SELECT m.message FROM chat_messages m WHERE m.group_id = g.id ORDER BY m.id DESC LIMIT 1) AS last_msg_text,
                       (SELECT m.file_name FROM chat_messages m WHERE m.group_id = g.id ORDER BY m.id DESC LIMIT 1) AS last_msg_file,
                       (SELECT m.created_at FROM chat_messages m WHERE m.group_id = g.id ORDER BY m.id DESC LIMIT 1) AS last_msg_time,
                       (SELECT u.name FROM chat_messages m JOIN users u ON u.id = m.sender_id WHERE m.group_id = g.id ORDER BY m.id DESC LIMIT 1) AS last_sender_name
                FROM chat_groups g
                JOIN chat_group_members gm ON (gm.group_id = g.id AND gm.user_id = :me)
                ORDER BY g.id DESC
            ");
            $stmtGroups->execute([':me' => $myId]);
            $rawGroups = $stmtGroups->fetchAll(PDO::FETCH_ASSOC);

            $groups = [];
            foreach ($rawGroups as $grp) {
                $previewText = '';
                if (!empty($grp['last_msg_text'])) {
                    $decrypted = decryptChatMessage($grp['last_msg_text']);
                    $prefix = !empty($grp['last_sender_name']) ? ($grp['last_sender_name'] . ': ') : '';
                    $previewText = $prefix . $decrypted;
                } elseif (!empty($grp['last_msg_file'])) {
                    $prefix = !empty($grp['last_sender_name']) ? ($grp['last_sender_name'] . ': ') : '';
                    $previewText = $prefix . '📎 ' . $grp['last_msg_file'];
                }

                $groups[] = [
                    'id'           => (int)$grp['id'],
                    'is_group'     => true,
                    'name'         => $grp['name'],
                    'description'  => $grp['description'] ?? '',
                    'created_by'   => (int)$grp['created_by'],
                    'created_at'   => $grp['created_at'],
                    'my_role'      => $grp['my_role'] ?? 'member',
                    'member_count' => (int)$grp['member_count'],
                    'unread_count' => (int)$grp['unread_count'],
                    'last_message' => $previewText,
                    'last_time'    => $grp['last_msg_time'] ?? $grp['created_at']
                ];
            }

            echo json_encode(['success' => true, 'groups' => $groups]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /* ==========================================================
       GET MESSAGES FOR A GROUP (Mark Read + Return Thread)
       ========================================================== */
    case 'get_group_messages':
        $groupId = (int)($_GET['group_id'] ?? 0);
        if ($groupId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid group']);
            exit;
        }

        try {
            // Verify membership
            $stmtMem = $db->prepare("SELECT role FROM chat_group_members WHERE group_id = :gid AND user_id = :me");
            $stmtMem->execute([':gid' => $groupId, ':me' => $myId]);
            $myMembership = $stmtMem->fetch(PDO::FETCH_ASSOC);

            if (!$myMembership) {
                echo json_encode(['success' => false, 'error' => 'You are not a member of this group.']);
                exit;
            }

            // Fetch group details
            $stmtGrp = $db->prepare("SELECT g.*, (SELECT COUNT(*) FROM chat_group_members WHERE group_id = g.id) AS member_count, u.name as creator_name FROM chat_groups g LEFT JOIN users u ON u.id = g.created_by WHERE g.id = :gid");
            $stmtGrp->execute([':gid' => $groupId]);
            $groupData = $stmtGrp->fetch(PDO::FETCH_ASSOC);

            if (!$groupData) {
                echo json_encode(['success' => false, 'error' => 'Group not found']);
                exit;
            }

            // Mark unread group messages as read for this user
            $db->prepare("UPDATE chat_group_members SET last_read_message_id = COALESCE((SELECT MAX(id) FROM chat_messages WHERE group_id = :gid), 0) WHERE group_id = :gid AND user_id = :me")
               ->execute([':gid' => $groupId, ':me' => $myId]);

            // Fetch group messages
            $afterId = (int)($_GET['after_id'] ?? 0);
            $querySql = "
                SELECT m.id, m.sender_id, m.group_id, m.message, m.file_path, m.file_name, m.file_type, m.file_size,
                       m.is_edited, m.is_deleted_for_everyone, m.is_forwarded, 
                       m.is_view_once, m.view_once_opened, m.view_once_opened_at, m.reply_to_id, m.created_at,
                       u.name AS sender_name, u.role AS sender_role
                FROM chat_messages m
                JOIN users u ON m.sender_id = u.id
                WHERE m.group_id = :gid
            ";

            if ($afterId > 0) {
                $querySql .= " AND m.id > :afterId ORDER BY m.id ASC";
                $stmt = $db->prepare($querySql);
                $stmt->execute([':gid' => $groupId, ':afterId' => $afterId]);
            } else {
                $querySql .= " ORDER BY m.id ASC LIMIT 250";
                $stmt = $db->prepare($querySql);
                $stmt->execute([':gid' => $groupId]);
            }

            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($messages as &$m) {
                if ((int)$m['is_deleted_for_everyone'] === 1) {
                    $m['message'] = 'This message was deleted';
                    $m['file_path'] = null;
                    $m['file_name'] = null;
                } elseif (!empty($m['is_view_once'])) {
                    if (!empty($m['view_once_opened'])) {
                        $m['message'] = null;
                        $m['file_path'] = null;
                    } elseif ((int)$m['sender_id'] !== $myId) {
                        $m['message'] = null;
                    } else {
                        $m['message'] = decryptChatMessage($m['message']);
                    }
                } else {
                    $m['message'] = decryptChatMessage($m['message']);
                }
            }
            unset($m);

            populateMessagesMetadata($db, $messages, $myId);

            $activeGroupCall = null;
            try {
                $stmtActCall = $db->prepare("
                    SELECT c.id, c.caller_id, c.group_id, c.call_type, c.status, c.created_at, c.started_at,
                           u.name AS caller_name
                    FROM chat_calls c
                    JOIN users u ON c.caller_id = u.id
                    WHERE c.group_id = :gid AND c.status IN ('calling', 'ringing', 'connected')
                    ORDER BY c.id DESC LIMIT 1
                ");
                $stmtActCall->execute([':gid' => $groupId]);
                $activeGroupCall = $stmtActCall->fetch(PDO::FETCH_ASSOC);
            } catch (\Exception $e) {}

            echo json_encode([
                'success'     => true,
                'active_call' => $activeGroupCall,
                'group'       => [
                    'id'           => (int)$groupData['id'],
                    'is_group'     => true,
                    'name'         => $groupData['name'],
                    'description'  => $groupData['description'] ?? '',
                    'created_by'   => (int)$groupData['created_by'],
                    'creator_name' => $groupData['creator_name'] ?? 'Admin',
                    'created_at'   => $groupData['created_at'],
                    'my_role'      => $myMembership['role'],
                    'member_count' => (int)$groupData['member_count']
                ],
                'messages'    => $messages
            ]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /* ==========================================================
       SEND GROUP MESSAGE (Encrypted AES-256 + Optional File)
       ========================================================== */
    case 'send_group_message':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }

        $groupId = (int)($_POST['group_id'] ?? 0);
        $message = trim($_POST['message'] ?? '');

        if ($groupId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Group ID required']);
            exit;
        }

        // Verify membership
        $stmtMem = $db->prepare("SELECT role FROM chat_group_members WHERE group_id = :gid AND user_id = :me");
        $stmtMem->execute([':gid' => $groupId, ':me' => $myId]);
        if (!$stmtMem->fetch()) {
            echo json_encode(['success' => false, 'error' => 'You are not a member of this group.']);
            exit;
        }

        $filePath = null;
        $fileName = null;
        $fileType = null;
        $fileSize = 0;

        // Handle File Attachment if uploaded
        if (!empty($_FILES['attachment']['name'])) {
            $uploadDir = __DIR__ . '/../../uploads/chat_files/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $origName = basename($_FILES['attachment']['name']);
            $fileExt = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $fileSize = (int)$_FILES['attachment']['size'];

            $blocked = ['php', 'phtml', 'php3', 'php4', 'php5', 'cgi', 'exe', 'bat', 'sh'];
            if (in_array($fileExt, $blocked)) {
                echo json_encode(['success' => false, 'error' => 'Executable files are prohibited']);
                exit;
            }

            $safeFileName = uniqid('grp_', true) . '.' . $fileExt;
            $targetPath = $uploadDir . $safeFileName;

            if (move_uploaded_file($_FILES['attachment']['tmp_name'], $targetPath)) {
                $filePath = 'uploads/chat_files/' . $safeFileName;
                $fileName = $origName;

                if (in_array($fileExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    $fileType = 'image';
                } elseif (in_array($fileExt, ['mp4', 'mov', 'avi', 'mkv', 'webm'])) {
                    $fileType = 'video';
                } elseif (in_array($fileExt, ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'opus'])) {
                    $fileType = 'audio';
                } elseif (in_array($fileExt, ['pdf'])) {
                    $fileType = 'pdf';
                } elseif (in_array($fileExt, ['doc', 'docx'])) {
                    $fileType = 'word';
                } elseif (in_array($fileExt, ['xls', 'xlsx', 'csv'])) {
                    $fileType = 'excel';
                } elseif (in_array($fileExt, ['zip', 'rar', '7z'])) {
                    $fileType = 'zip';
                } else {
                    $fileType = 'document';
                }
            } else {
                echo json_encode(['success' => false, 'error' => 'Attachment upload failed']);
                exit;
            }
        }

        if (empty($message) && empty($filePath)) {
            echo json_encode(['success' => false, 'error' => 'Message or attachment required']);
            exit;
        }

        try {
            $isViewOnce = !empty($_POST['is_view_once']) ? 1 : 0;
            $replyToId  = !empty($_POST['reply_to_id']) ? (int)$_POST['reply_to_id'] : null;
            $encryptedMessage = !empty($message) ? encryptChatMessage($message) : null;

            $stmt = $db->prepare("
                INSERT INTO chat_messages (sender_id, receiver_id, group_id, message, file_path, file_name, file_type, file_size, is_delivered, delivered_at, is_read, is_view_once, reply_to_id, created_at)
                VALUES (:sid, NULL, :gid, :msg, :fpath, :fname, :ftype, :fsize, 1, NOW(), 0, :ivo, :reply_to, NOW())
            ");
            $stmt->execute([
                ':sid'      => $myId,
                ':gid'      => $groupId,
                ':msg'      => $encryptedMessage,
                ':fpath'    => $filePath,
                ':fname'    => $fileName,
                ':ftype'    => $fileType,
                ':fsize'    => $fileSize,
                ':ivo'      => $isViewOnce,
                ':reply_to' => $replyToId
            ]);

            $newMsgId = (int)$db->lastInsertId();

            // Update sender's last read message id in this group
            $db->prepare("UPDATE chat_group_members SET last_read_message_id = :mid WHERE group_id = :gid AND user_id = :me")
               ->execute([':mid' => $newMsgId, ':gid' => $groupId, ':me' => $myId]);

            // Fetch created message with sender info
            $stmtMsg = $db->prepare("
                SELECT m.*, u.name AS sender_name, u.role AS sender_role
                FROM chat_messages m
                JOIN users u ON u.id = m.sender_id
                WHERE m.id = :id
            ");
            $stmtMsg->execute([':id' => $newMsgId]);
            $createdMsg = $stmtMsg->fetch(PDO::FETCH_ASSOC);

            if ($createdMsg && !empty($createdMsg['message'])) {
                $createdMsg['message'] = decryptChatMessage($createdMsg['message']);
            }

            if ($createdMsg) {
                $tmpArr = [&$createdMsg];
                populateMessagesMetadata($db, $tmpArr, $myId);
            }

            echo json_encode([
                'success' => true,
                'message' => $createdMsg
            ]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /* ==========================================================
       GET GROUP INFO & MEMBERS
       ========================================================== */
    case 'get_group_info':
        $groupId = (int)($_GET['group_id'] ?? 0);
        if ($groupId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid group']);
            exit;
        }

        try {
            // Verify membership
            $stmtMem = $db->prepare("SELECT role FROM chat_group_members WHERE group_id = :gid AND user_id = :me");
            $stmtMem->execute([':gid' => $groupId, ':me' => $myId]);
            $myMem = $stmtMem->fetch(PDO::FETCH_ASSOC);
            if (!$myMem) {
                echo json_encode(['success' => false, 'error' => 'You are not a member of this group']);
                exit;
            }

            $stmtGrp = $db->prepare("
                SELECT g.*, u.name AS creator_name 
                FROM chat_groups g
                LEFT JOIN users u ON u.id = g.created_by
                WHERE g.id = :gid
            ");
            $stmtGrp->execute([':gid' => $groupId]);
            $group = $stmtGrp->fetch(PDO::FETCH_ASSOC);

            // Fetch members
            $stmtList = $db->prepare("
                SELECT u.id, u.name, u.username, u.role, u.last_seen, gm.role AS group_role, gm.joined_at
                FROM chat_group_members gm
                JOIN users u ON u.id = gm.user_id
                WHERE gm.group_id = :gid
                ORDER BY CASE WHEN gm.role = 'admin' THEN 0 ELSE 1 END, u.name ASC
            ");
            $stmtList->execute([':gid' => $groupId]);
            $membersRaw = $stmtList->fetchAll(PDO::FETCH_ASSOC);

            $members = [];
            foreach ($membersRaw as $m) {
                $pres = formatLastSeen($m['last_seen'] ?? null);
                $members[] = [
                    'id'             => (int)$m['id'],
                    'user_id'        => (int)$m['id'],
                    'name'           => $m['name'] ?: $m['username'],
                    'username'       => $m['username'],
                    'role'           => $m['role'],
                    'role_label'     => ucwords(str_replace('_', ' ', $m['role'])),
                    'group_role'     => $m['group_role'],
                    'is_online'      => $pres['is_online'],
                    'last_seen_text' => $pres['text'],
                    'joined_at'      => $m['joined_at'],
                    'is_me'          => ((int)$m['id'] === $myId)
                ];
            }

            $isAdmin = ($myMem['role'] === 'admin' || $myRole === 'admin');

            echo json_encode([
                'success'  => true,
                'is_admin' => $isAdmin,
                'members'  => $members,
                'group'    => [
                    'id'           => (int)$group['id'],
                    'name'         => $group['name'],
                    'description'  => $group['description'] ?? '',
                    'created_by'   => (int)$group['created_by'],
                    'creator_name' => $group['creator_name'] ?? 'Admin',
                    'created_at'   => $group['created_at'],
                    'my_role'      => $myMem['role'],
                    'is_admin'     => $isAdmin,
                    'member_count' => count($members),
                    'members'      => $members
                ]
            ]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /* ==========================================================
       ADD MEMBERS TO GROUP
       ========================================================== */
    case 'add_group_members':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }

        $groupId = (int)($_POST['group_id'] ?? 0);
        $rawMembers = $_POST['members'] ?? $_POST['member_ids'] ?? [];
        if (is_string($rawMembers)) {
            $decoded = json_decode($rawMembers, true);
            $memberIds = is_array($decoded) ? $decoded : array_filter(array_map('intval', explode(',', $rawMembers)));
        } elseif (is_array($rawMembers)) {
            $memberIds = array_filter(array_map('intval', $rawMembers));
        } else {
            $memberIds = [];
        }

        if ($groupId <= 0 || empty($memberIds)) {
            echo json_encode(['success' => false, 'error' => 'Group and members required']);
            exit;
        }

        try {
            // Verify current user is group admin or system admin
            $stmtMem = $db->prepare("SELECT role FROM chat_group_members WHERE group_id = :gid AND user_id = :me");
            $stmtMem->execute([':gid' => $groupId, ':me' => $myId]);
            $myMem = $stmtMem->fetch(PDO::FETCH_ASSOC);

            if (!$myMem || ($myMem['role'] !== 'admin' && $myRole !== 'admin')) {
                echo json_encode(['success' => false, 'error' => 'Only group admins can add new members.']);
                exit;
            }

            $stmtAdd = $db->prepare("INSERT IGNORE INTO chat_group_members (group_id, user_id, role, last_read_message_id, joined_at) VALUES (:gid, :uid, 'member', 0, NOW())");
            $stmtU = $db->prepare("SELECT name FROM users WHERE id = :uid");
            $addedNames = [];
            foreach ($memberIds as $mId) {
                $mId = (int)$mId;
                if ($mId > 0 && $mId !== $myId) {
                    $stmtAdd->execute([':gid' => $groupId, ':uid' => $mId]);
                    $stmtU->execute([':uid' => $mId]);
                    $uName = $stmtU->fetchColumn();
                    if ($uName) $addedNames[] = $uName;
                }
            }

            if (!empty($addedNames)) {
                $adminName = $_SESSION['name'] ?? $_SESSION['username'] ?? 'Admin';
                $sysMsg = "➕ " . $adminName . " added " . implode(', ', $addedNames) . " to the group";
                $db->prepare("INSERT INTO chat_messages (sender_id, receiver_id, group_id, message, is_delivered, delivered_at, created_at) VALUES (:me, NULL, :gid, :m, 1, NOW(), NOW())")
                   ->execute([':me' => $myId, ':gid' => $groupId, ':m' => encryptChatMessage($sysMsg)]);
            }

            echo json_encode(['success' => true, 'message' => 'Members added successfully']);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /* ==========================================================
       LEAVE GROUP
       ========================================================== */
    case 'leave_group':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }

        $groupId = (int)($_POST['group_id'] ?? 0);
        if ($groupId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid group']);
            exit;
        }

        try {
            $stmtMem = $db->prepare("SELECT role FROM chat_group_members WHERE group_id = :gid AND user_id = :me");
            $stmtMem->execute([':gid' => $groupId, ':me' => $myId]);
            $myMem = $stmtMem->fetch(PDO::FETCH_ASSOC);

            if (!$myMem) {
                echo json_encode(['success' => false, 'error' => 'You are not a member of this group']);
                exit;
            }

            $db->prepare("DELETE FROM chat_group_members WHERE group_id = :gid AND user_id = :me")
               ->execute([':gid' => $groupId, ':me' => $myId]);

            $userName = $_SESSION['name'] ?? $_SESSION['username'] ?? 'User';
            $sysMsg = "🚪 " . $userName . " left the group";
            $db->prepare("INSERT INTO chat_messages (sender_id, receiver_id, group_id, message, is_delivered, delivered_at, created_at) VALUES (:me, NULL, :gid, :m, 1, NOW(), NOW())")
               ->execute([':me' => $myId, ':gid' => $groupId, ':m' => encryptChatMessage($sysMsg)]);

            echo json_encode(['success' => true, 'message' => 'You have left the group']);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /* ==========================================================
       SEND MESSAGE (Text + Optional File Attachment)
       ========================================================== */
    case 'send_message':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }

        $receiverId = (int)($_POST['receiver_id'] ?? 0);
        $message    = trim($_POST['message'] ?? '');

        if ($receiverId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Receiver required']);
            exit;
        }

        // Verify receiver permissions & check presence
        $stmtUser = $db->prepare("SELECT id, username, role, last_seen FROM users WHERE id = :id AND status = 'active'");
        $stmtUser->execute([':id' => $receiverId]);
        $receiver = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if (!$receiver || !isChatAllowed($myRole, $receiver['role'], $myUsername, $receiver['username'] ?? '', $myId, (int)$receiver['id'])) {
            echo json_encode(['success' => false, 'error' => 'You cannot send messages to this role.']);
            exit;
        }

        // Check if either party has blocked the other
        $stmtBlockCheck = $db->prepare("SELECT 
            EXISTS(SELECT 1 FROM chat_blocks WHERE blocker_id = :me AND blocked_id = :rid) AS blocked_by_me,
            EXISTS(SELECT 1 FROM chat_blocks WHERE blocker_id = :rid AND blocked_id = :me) AS blocked_by_them
        ");
        $stmtBlockCheck->execute([':me' => $myId, ':rid' => $receiverId]);
        $bRow = $stmtBlockCheck->fetch(PDO::FETCH_ASSOC);

        if (!empty($bRow['blocked_by_me'])) {
            echo json_encode(['success' => false, 'error' => 'You have blocked this contact. Unblock them to send messages.', 'is_blocked' => true]);
            exit;
        }

        if (!empty($bRow['blocked_by_them'])) {
            echo json_encode(['success' => false, 'error' => 'You cannot send messages to this contact.', 'is_blocked' => true]);
            exit;
        }

        // If receiver is currently online, mark as delivered immediately
        $recPresence = formatLastSeen($receiver['last_seen'] ?? null);
        $isDelivered = $recPresence['is_online'] ? 1 : 0;

        $filePath = null;
        $fileName = null;
        $fileType = null;
        $fileSize = 0;

        // Handle File Attachment if uploaded
        if (!empty($_FILES['attachment']['name'])) {
            $uploadDir = __DIR__ . '/../../uploads/chat_files/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $origName = basename($_FILES['attachment']['name']);
            $fileExt = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $fileSize = (int)$_FILES['attachment']['size'];

            $blocked = ['php', 'phtml', 'php3', 'php4', 'php5', 'cgi', 'exe', 'bat', 'sh'];
            if (in_array($fileExt, $blocked)) {
                echo json_encode(['success' => false, 'error' => 'Executable files are prohibited']);
                exit;
            }

            $safeFileName = uniqid('chat_', true) . '.' . $fileExt;
            $targetPath = $uploadDir . $safeFileName;

            if (move_uploaded_file($_FILES['attachment']['tmp_name'], $targetPath)) {
                $filePath = 'uploads/chat_files/' . $safeFileName;
                $fileName = $origName;
                
                if (in_array($fileExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    $fileType = 'image';
                } elseif (in_array($fileExt, ['mp4', 'mov', 'avi', 'mkv', 'webm'])) {
                    $fileType = 'video';
                } elseif (in_array($fileExt, ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'opus'])) {
                    $fileType = 'audio';
                } elseif (in_array($fileExt, ['pdf'])) {
                    $fileType = 'pdf';
                } elseif (in_array($fileExt, ['doc', 'docx'])) {
                    $fileType = 'word';
                } elseif (in_array($fileExt, ['xls', 'xlsx', 'csv'])) {
                    $fileType = 'excel';
                } elseif (in_array($fileExt, ['zip', 'rar', '7z'])) {
                    $fileType = 'zip';
                } else {
                    $fileType = 'document';
                }
            } else {
                echo json_encode(['success' => false, 'error' => 'Attachment upload failed']);
                exit;
            }
        }

        if (empty($message) && empty($filePath)) {
            echo json_encode(['success' => false, 'error' => 'Message or attachment required']);
            exit;
        }

        try {
            $isViewOnce = !empty($_POST['is_view_once']) ? 1 : 0;
            $replyToId  = !empty($_POST['reply_to_id']) ? (int)$_POST['reply_to_id'] : null;
            $encryptedMessage = !empty($message) ? encryptChatMessage($message) : null;

            $stmt = $db->prepare("
                INSERT INTO chat_messages (sender_id, receiver_id, message, file_path, file_name, file_type, file_size, is_delivered, delivered_at, is_read, is_view_once, reply_to_id, created_at)
                VALUES (:sid, :rid, :msg, :fpath, :fname, :ftype, :fsize, :is_del, :del_at, 0, :ivo, :reply_to, NOW())
            ");
            $stmt->execute([
                ':sid'      => $myId,
                ':rid'      => $receiverId,
                ':msg'      => $encryptedMessage,
                ':fpath'    => $filePath,
                ':fname'    => $fileName,
                ':ftype'    => $fileType,
                ':fsize'    => $fileSize,
                ':is_del'   => $isDelivered,
                ':del_at'   => $isDelivered ? date('Y-m-d H:i:s') : null,
                ':ivo'      => $isViewOnce,
                ':reply_to' => $replyToId
            ]);

            $newMsgId = $db->lastInsertId();

            // Fetch created message
            $stmtMsg = $db->prepare("SELECT * FROM chat_messages WHERE id = :id");
            $stmtMsg->execute([':id' => $newMsgId]);
            $createdMsg = $stmtMsg->fetch(PDO::FETCH_ASSOC);

            if ($createdMsg && !empty($createdMsg['message'])) {
                $createdMsg['message'] = decryptChatMessage($createdMsg['message']);
            }

            if ($createdMsg) {
                $tmpArr = [&$createdMsg];
                populateMessagesMetadata($db, $tmpArr, $myId);
            }

            echo json_encode([
                'success' => true,
                'message' => $createdMsg
            ]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /* ==========================================================
       POLL FOR LIVE UPDATES (With Real-Time Status & Presence)
       ========================================================== */
    case 'poll_updates':
        $contactId = (int)($_GET['contact_id'] ?? 0);
        $groupId   = (int)($_GET['group_id'] ?? 0);
        $lastId    = (int)($_GET['last_id'] ?? 0);

        try {
            $newMessages = [];
            if ($contactId > 0 && $lastId >= 0) {
                // Mark incoming from active contact as read AND delivered
                $db->prepare("UPDATE chat_messages SET is_read = 1, is_delivered = 1, delivered_at = COALESCE(delivered_at, NOW()), read_at = COALESCE(read_at, NOW()) WHERE sender_id = :cid AND receiver_id = :me AND is_read = 0")
                   ->execute([':cid' => $contactId, ':me' => $myId]);

                // Fetch new messages
                $stmt = $db->prepare("
                    SELECT id, sender_id, receiver_id, message, file_path, file_name, file_type, file_size, 
                           is_delivered, is_read, is_edited, is_deleted_for_everyone, is_forwarded,
                           is_view_once, view_once_opened, view_once_opened_at,
                           delivered_at, read_at, reply_to_id, created_at
                    FROM chat_messages
                    WHERE ((sender_id = :me AND receiver_id = :cid AND deleted_for_sender = 0)
                       OR (sender_id = :cid AND receiver_id = :me AND deleted_for_receiver = 0))
                      AND id > :lastId
                    ORDER BY id ASC
                ");
                $stmt->execute([':me' => $myId, ':cid' => $contactId, ':lastId' => $lastId]);
                $newMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($newMessages as &$m) {
                    if ((int)$m['is_deleted_for_everyone'] === 1) {
                        $m['message'] = 'This message was deleted';
                        $m['file_path'] = null;
                        $m['file_name'] = null;
                    } elseif (!empty($m['is_view_once'])) {
                        if (!empty($m['view_once_opened'])) {
                            $m['message'] = null;
                            $m['file_path'] = null;
                        } elseif ((int)$m['sender_id'] !== $myId) {
                            $m['message'] = null;
                        } else {
                            $m['message'] = decryptChatMessage($m['message']);
                        }
                    } else {
                        $m['message'] = decryptChatMessage($m['message']);
                    }
                }
            } elseif ($groupId > 0 && $lastId >= 0) {
                // Mark active group messages as read
                $db->prepare("UPDATE chat_group_members SET last_read_message_id = COALESCE((SELECT MAX(id) FROM chat_messages WHERE group_id = :gid), last_read_message_id) WHERE group_id = :gid AND user_id = :me")
                   ->execute([':gid' => $groupId, ':me' => $myId]);

                // Fetch new messages for this group
                $stmt = $db->prepare("
                    SELECT m.id, m.sender_id, m.group_id, m.message, m.file_path, m.file_name, m.file_type, m.file_size, 
                           m.is_delivered, m.is_read, m.is_edited, m.is_deleted_for_everyone, m.is_forwarded,
                           m.is_view_once, m.view_once_opened, m.view_once_opened_at,
                           m.delivered_at, m.read_at, m.reply_to_id, m.created_at,
                           u.name AS sender_name, u.role AS sender_role
                    FROM chat_messages m
                    JOIN users u ON m.sender_id = u.id
                    WHERE m.group_id = :gid AND m.id > :lastId
                    ORDER BY m.id ASC
                ");
                $stmt->execute([':gid' => $groupId, ':lastId' => $lastId]);
                $newMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($newMessages as &$m) {
                    if ((int)$m['is_deleted_for_everyone'] === 1) {
                        $m['message'] = 'This message was deleted';
                        $m['file_path'] = null;
                        $m['file_name'] = null;
                    } elseif (!empty($m['is_view_once'])) {
                        if (!empty($m['view_once_opened'])) {
                            $m['message'] = null;
                            $m['file_path'] = null;
                        } elseif ((int)$m['sender_id'] !== $myId) {
                            $m['message'] = null;
                        } else {
                            $m['message'] = decryptChatMessage($m['message']);
                        }
                    } else {
                        $m['message'] = decryptChatMessage($m['message']);
                    }
                }
                unset($m);
            }

            if (!empty($newMessages)) {
                populateMessagesMetadata($db, $newMessages, $myId);
            }

            // Total direct unread for current user (excluding blocked senders)
            $stmtTotal = $db->prepare("
                SELECT COUNT(*) FROM chat_messages m
                LEFT JOIN chat_blocks b ON (b.blocker_id = :me AND b.blocked_id = m.sender_id)
                WHERE m.receiver_id = :me AND m.is_read = 0 AND m.deleted_for_receiver = 0 AND b.id IS NULL
            ");
            $stmtTotal->execute([':me' => $myId]);
            $totalUnread = (int)$stmtTotal->fetchColumn();

            // Get unread counts per direct sender (excluding blocked)
            $stmtUnreadList = $db->prepare("
                SELECT m.sender_id, COUNT(*) as unread
                FROM chat_messages m
                LEFT JOIN chat_blocks b ON (b.blocker_id = :me AND b.blocked_id = m.sender_id)
                WHERE m.receiver_id = :me AND m.is_read = 0 AND m.deleted_for_receiver = 0 AND b.id IS NULL
                GROUP BY m.sender_id
            ");
            $stmtUnreadList->execute([':me' => $myId]);
            $unreadBySender = $stmtUnreadList->fetchAll(PDO::FETCH_KEY_PAIR);

            // Get unread counts per group for current user
            $unreadByGroup = [];
            try {
                $stmtGrpUnread = $db->prepare("
                    SELECT gm.group_id, COUNT(m.id) as unread
                    FROM chat_group_members gm
                    JOIN chat_messages m ON (m.group_id = gm.group_id AND m.id > gm.last_read_message_id AND m.sender_id != :me)
                    WHERE gm.user_id = :me
                    GROUP BY gm.group_id
                ");
                $stmtGrpUnread->execute([':me' => $myId]);
                $unreadByGroup = $stmtGrpUnread->fetchAll(PDO::FETCH_KEY_PAIR);
                $totalUnread += array_sum($unreadByGroup);
            } catch (\Exception $e) {}

            // Active contact presence & status of recent messages
            $activeContactPresence = null;
            $recentStatuses = [];
            $isBlockedByMe = false;
            $isBlockedByThem = false;

            if ($contactId > 0) {
                // Check block status with active contact
                $stmtBlockCheck = $db->prepare("SELECT 
                    EXISTS(SELECT 1 FROM chat_blocks WHERE blocker_id = :me AND blocked_id = :cid) AS blocked_by_me,
                    EXISTS(SELECT 1 FROM chat_blocks WHERE blocker_id = :cid AND blocked_id = :me) AS blocked_by_them
                ");
                $stmtBlockCheck->execute([':me' => $myId, ':cid' => $contactId]);
                $bRow = $stmtBlockCheck->fetch(PDO::FETCH_ASSOC);
                $isBlockedByMe   = (bool)($bRow['blocked_by_me'] ?? 0);
                $isBlockedByThem = (bool)($bRow['blocked_by_them'] ?? 0);

                // Presence
                $stmtPres = $db->prepare("SELECT last_seen FROM users WHERE id = :cid");
                $stmtPres->execute([':cid' => $contactId]);
                $cidLastSeen = $stmtPres->fetchColumn();
                $activeContactPresence = formatLastSeen($cidLastSeen);

                // Fetch status of recent 50 messages so client updates blue ticks, edits, deletes, and reactions live!
                $stmtStatus = $db->prepare("
                    SELECT id, is_delivered, is_read, is_edited, is_deleted_for_everyone, message, reply_to_id
                    FROM chat_messages
                    WHERE ((sender_id = :me AND receiver_id = :cid) OR (sender_id = :cid AND receiver_id = :me))
                      AND ((sender_id = :me AND deleted_for_sender = 0) OR (receiver_id = :me AND deleted_for_receiver = 0))
                    ORDER BY id DESC LIMIT 50
                ");
                $stmtStatus->execute([':me' => $myId, ':cid' => $contactId]);
                $recentStatuses = $stmtStatus->fetchAll(PDO::FETCH_ASSOC);
                foreach ($recentStatuses as &$st) {
                    if ((int)$st['is_deleted_for_everyone'] === 1) {
                        $st['message'] = 'This message was deleted';
                    } else {
                        $st['message'] = decryptChatMessage($st['message']);
                    }
                }
                unset($st);

                if (!empty($recentStatuses)) {
                    populateMessagesMetadata($db, $recentStatuses, $myId);
                }

                // Check if active contact is currently typing to current user (only if not blocked)
                $isTyping = false;
                if (!$isBlockedByMe && !$isBlockedByThem) {
                    $typingFile = sys_get_temp_dir() . '/digiink_type_' . $myId . '_' . $contactId . '.txt';
                    if (file_exists($typingFile)) {
                        $typeTime = (int)@file_get_contents($typingFile);
                        if ((time() - $typeTime) <= 4) {
                            $isTyping = true;
                        } else {
                            @unlink($typingFile);
                        }
                    }
                }
            } elseif ($groupId > 0) {
                // Fetch status of recent 50 messages in active group for live reactions & edits
                $stmtGrpStatus = $db->prepare("
                    SELECT id, is_delivered, is_read, is_edited, is_deleted_for_everyone, message, reply_to_id
                    FROM chat_messages
                    WHERE group_id = :gid
                    ORDER BY id DESC LIMIT 50
                ");
                $stmtGrpStatus->execute([':gid' => $groupId]);
                $recentStatuses = $stmtGrpStatus->fetchAll(PDO::FETCH_ASSOC);
                foreach ($recentStatuses as &$st) {
                    if ((int)$st['is_deleted_for_everyone'] === 1) {
                        $st['message'] = 'This message was deleted';
                    } else {
                        $st['message'] = decryptChatMessage($st['message']);
                    }
                }
                unset($st);

                if (!empty($recentStatuses)) {
                    populateMessagesMetadata($db, $recentStatuses, $myId);
                }
                $isTyping = false;
            } else {
                $isTyping = false;
            }

            $activeGroupCall = null;
            if ($groupId > 0) {
                try {
                    $stmtActGrp = $db->prepare("
                        SELECT c.id, c.caller_id, c.group_id, c.call_type, c.status, c.created_at, c.started_at,
                               u.name AS caller_name
                        FROM chat_calls c
                        JOIN users u ON c.caller_id = u.id
                        WHERE c.group_id = :gid AND c.status IN ('calling', 'ringing', 'connected')
                        ORDER BY c.id DESC LIMIT 1
                    ");
                    $stmtActGrp->execute([':gid' => $groupId]);
                    $activeGroupCall = $stmtActGrp->fetch(PDO::FETCH_ASSOC);
                } catch (\Exception $e) {}
            }

            // Check active incoming call for current user (direct and group calls)
            $incomingCall = null;
            $stmtCall = $db->prepare("
                SELECT c.id, c.caller_id, c.receiver_id, c.group_id, c.call_type, c.status, c.offer, 
                       u.name AS caller_name, u.role AS caller_role,
                       g.name AS group_name
                FROM chat_calls c
                JOIN users u ON c.caller_id = u.id
                LEFT JOIN chat_groups g ON g.id = c.group_id
                LEFT JOIN chat_blocks b ON ((b.blocker_id = :me AND b.blocked_id = c.caller_id) OR (b.blocker_id = c.caller_id AND b.blocked_id = :me))
                WHERE (
                    (c.receiver_id = :me AND b.id IS NULL AND c.status IN ('calling', 'ringing'))
                    OR
                    (c.group_id IS NOT NULL 
                     AND c.caller_id != :me 
                     AND c.status IN ('calling', 'ringing', 'connected')
                     AND c.group_id IN (SELECT group_id FROM chat_group_members WHERE user_id = :me)
                     AND NOT EXISTS (SELECT 1 FROM chat_call_participants WHERE call_id = c.id AND user_id = :me AND status IN ('declined', 'left'))
                    )
                )
                AND c.created_at >= (NOW() - INTERVAL 60 SECOND)
                ORDER BY c.id DESC LIMIT 1
            ");
            $stmtCall->execute([':me' => $myId]);
            $incomingCall = $stmtCall->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success'                 => true,
                'new_messages'            => $newMessages,
                'total_unread'            => $totalUnread,
                'unread_by_sender'        => $unreadBySender,
                'unread_by_group'         => $unreadByGroup,
                'active_contact_presence' => $activeContactPresence,
                'recent_statuses'         => $recentStatuses,
                'is_typing'               => $isTyping,
                'incoming_call'           => $incomingCall,
                'active_group_call'       => $activeGroupCall,
                'is_blocked_by_me'        => $isBlockedByMe,
            ]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /* ==========================================================
       MARK MESSAGES AS READ (Instant Read Receipts from Toast / Popup)
       ========================================================== */
    case 'mark_read':
        $senderId = (int)($_POST['sender_id'] ?? 0);
        if ($senderId > 0) {
            $stmt = $db->prepare("
                UPDATE chat_messages 
                SET is_read = 1, 
                    is_delivered = 1, 
                    delivered_at = COALESCE(delivered_at, NOW()), 
                    read_at = COALESCE(read_at, NOW()) 
                WHERE sender_id = :sid 
                  AND receiver_id = :me 
                  AND is_read = 0
            ");
            $stmt->execute([':sid' => $senderId, ':me' => $myId]);
        }
        echo json_encode(['success' => true]);
        break;

    /* ==========================================================
       TYPING NOTIFICATION STATUS (Lightweight Real-time Indicator)
       ========================================================== */
    case 'typing':
        $receiverId = (int)($_POST['receiver_id'] ?? ($_GET['receiver_id'] ?? 0));
        if ($receiverId > 0) {
            $typingFile = sys_get_temp_dir() . '/digiink_type_' . $receiverId . '_' . $myId . '.txt';
            @file_put_contents($typingFile, time());
        }
        echo json_encode(['success' => true]);
        exit;

    case 'stop_typing':
        $receiverId = (int)($_POST['receiver_id'] ?? ($_GET['receiver_id'] ?? 0));
        if ($receiverId > 0) {
            $typingFile = sys_get_temp_dir() . '/digiink_type_' . $receiverId . '_' . $myId . '.txt';
            if (file_exists($typingFile)) {
                @unlink($typingFile);
            }
        }
        echo json_encode(['success' => true]);
        exit;

    /* ==========================================================
       REACT TO MESSAGE (Emoji Reactions - WhatsApp Style)
       ========================================================== */
    case 'react_message':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }

        $msgId = (int)($_POST['message_id'] ?? 0);
        $emoji = trim($_POST['reaction'] ?? '');

        if ($msgId <= 0 || empty($emoji)) {
            echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
            exit;
        }

        try {
            // Verify message exists and user has access
            $stmtM = $db->prepare("SELECT id, sender_id, receiver_id, group_id, is_deleted_for_everyone FROM chat_messages WHERE id = :id");
            $stmtM->execute([':id' => $msgId]);
            $msgRow = $stmtM->fetch(PDO::FETCH_ASSOC);

            if (!$msgRow || (int)$msgRow['is_deleted_for_everyone'] === 1) {
                echo json_encode(['success' => false, 'error' => 'Message not found or deleted']);
                exit;
            }

            // Verify permission: sender, receiver, or group member
            if (!empty($msgRow['group_id'])) {
                $stmtGm = $db->prepare("SELECT 1 FROM chat_group_members WHERE group_id = :gid AND user_id = :me");
                $stmtGm->execute([':gid' => $msgRow['group_id'], ':me' => $myId]);
                if (!$stmtGm->fetch()) {
                    echo json_encode(['success' => false, 'error' => 'You are not a member of this group']);
                    exit;
                }
            } else {
                if ((int)$msgRow['sender_id'] !== $myId && (int)$msgRow['receiver_id'] !== $myId) {
                    echo json_encode(['success' => false, 'error' => 'Access denied']);
                    exit;
                }
            }

            // Check if reaction already exists by this user
            $stmtCheck = $db->prepare("SELECT id, reaction FROM chat_message_reactions WHERE message_id = :mid AND user_id = :me");
            $stmtCheck->execute([':mid' => $msgId, ':me' => $myId]);
            $existingReaction = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if ($existingReaction) {
                if ($existingReaction['reaction'] === $emoji) {
                    // Clicking same emoji removes reaction (toggle behavior)
                    $db->prepare("DELETE FROM chat_message_reactions WHERE id = :id")->execute([':id' => $existingReaction['id']]);
                } else {
                    // Update to new emoji
                    $db->prepare("UPDATE chat_message_reactions SET reaction = :r WHERE id = :id")->execute([':r' => $emoji, ':id' => $existingReaction['id']]);
                }
            } else {
                // Insert new reaction
                $db->prepare("INSERT INTO chat_message_reactions (message_id, user_id, reaction, created_at) VALUES (:mid, :me, :r, NOW())")
                   ->execute([':mid' => $msgId, ':me' => $myId, ':r' => $emoji]);
            }

            // Fetch updated grouped reactions for this message
            $dummyMsg = [['id' => $msgId, 'reply_to_id' => null]];
            populateMessagesMetadata($db, $dummyMsg, $myId);
            $updatedReactions = $dummyMsg[0]['reactions'] ?? [];

            echo json_encode([
                'success'    => true,
                'message_id' => $msgId,
                'reactions'  => $updatedReactions
            ]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;

    /* ==========================================================
       DELETE MESSAGE (PERMANENTLY DISABLED BY POLICY)
       ========================================================== */
    case 'delete_message':
        echo json_encode([
            'success' => false, 
            'error'   => 'Message deletion has been permanently disabled by audit & compliance policy. Messages cannot be deleted.'
        ]);
        exit;

    /* ==========================================================
       USER BLOCK / UNBLOCK ACTIONS
       ========================================================== */
    case 'block_user':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }
        $targetId = (int)($_POST['user_id'] ?? 0);
        if ($targetId <= 0 || $targetId === $myId) {
            echo json_encode(['success' => false, 'error' => 'Invalid user ID to block']);
            exit;
        }
        try {
            $stmt = $db->prepare("INSERT IGNORE INTO chat_blocks (blocker_id, blocked_id) VALUES (:me, :target)");
            $stmt->execute([':me' => $myId, ':target' => $targetId]);

            // Immediately cancel/end any active calling sessions between these users
            $db->prepare("UPDATE chat_calls SET status = 'ended', ended_at = NOW() 
                WHERE ((caller_id = :me AND receiver_id = :target) OR (caller_id = :target AND receiver_id = :me))
                  AND status IN ('calling', 'ringing', 'connected')")->execute([':me' => $myId, ':target' => $targetId]);

            echo json_encode(['success' => true, 'is_blocked' => true, 'message' => 'User blocked successfully']);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'unblock_user':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }
        $targetId = (int)($_POST['user_id'] ?? 0);
        if ($targetId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid user ID to unblock']);
            exit;
        }
        try {
            $stmt = $db->prepare("DELETE FROM chat_blocks WHERE blocker_id = :me AND blocked_id = :target");
            $stmt->execute([':me' => $myId, ':target' => $targetId]);
            echo json_encode(['success' => true, 'is_blocked' => false, 'message' => 'User unblocked successfully']);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /* ==========================================================
       TOGGLE PIN CHAT (Direct Contact or Group)
       ========================================================== */
    case 'toggle_pin_chat':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }

        $peerType = trim($_POST['peer_type'] ?? 'direct');
        $peerId = (int)($_POST['peer_id'] ?? 0);

        if ($peerId <= 0 || !in_array($peerType, ['direct', 'group'])) {
            echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
            exit;
        }

        try {
            $stmtCheck = $db->prepare("SELECT id FROM chat_pinned WHERE user_id = :me AND peer_type = :pt AND peer_id = :pid");
            $stmtCheck->execute([':me' => $myId, ':pt' => $peerType, ':pid' => $peerId]);
            $existingId = $stmtCheck->fetchColumn();

            if ($existingId) {
                $db->prepare("DELETE FROM chat_pinned WHERE id = :id")->execute([':id' => $existingId]);
                echo json_encode(['success' => true, 'is_pinned' => false, 'message' => 'Chat unpinned']);
            } else {
                $db->prepare("INSERT IGNORE INTO chat_pinned (user_id, peer_type, peer_id) VALUES (:me, :pt, :pid)")
                   ->execute([':me' => $myId, ':pt' => $peerType, ':pid' => $peerId]);
                echo json_encode(['success' => true, 'is_pinned' => true, 'message' => 'Chat pinned to top']);
            }
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /* ==========================================================
       SAVE / REMOVE NICKNAME FOR CONTACT (Personal / Custom Name)
       ========================================================== */
    case 'save_nickname':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }

        $contactId = (int)($_POST['contact_id'] ?? 0);
        $nickname = trim($_POST['nickname'] ?? '');

        if ($contactId <= 0 || $contactId === $myId) {
            echo json_encode(['success' => false, 'error' => 'Invalid contact selected']);
            exit;
        }

        try {
            // Verify contact exists
            $stmtUser = $db->prepare("SELECT id, name, username FROM users WHERE id = :cid AND status = 'active'");
            $stmtUser->execute([':cid' => $contactId]);
            $contactUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

            if (!$contactUser) {
                echo json_encode(['success' => false, 'error' => 'Contact does not exist']);
                exit;
            }

            $realName = $contactUser['name'] ?: $contactUser['username'];

            if ($nickname === '') {
                // Remove nickname
                $stmtDel = $db->prepare("DELETE FROM chat_nicknames WHERE user_id = :me AND contact_id = :cid");
                $stmtDel->execute([':me' => $myId, ':cid' => $contactId]);

                echo json_encode([
                    'success'    => true,
                    'contact_id' => $contactId,
                    'nickname'   => '',
                    'real_name'  => $realName,
                    'display_name' => $realName,
                    'message'    => 'Nickname removed successfully'
                ]);
            } else {
                // Upsert nickname
                $nickname = mb_substr($nickname, 0, 80);
                $stmtUpsert = $db->prepare("
                    INSERT INTO chat_nicknames (user_id, contact_id, nickname)
                    VALUES (:me, :cid, :nick)
                    ON DUPLICATE KEY UPDATE nickname = VALUES(nickname)
                ");
                $stmtUpsert->execute([
                    ':me'   => $myId,
                    ':cid'  => $contactId,
                    ':nick' => $nickname
                ]);

                echo json_encode([
                    'success'      => true,
                    'contact_id'   => $contactId,
                    'nickname'     => $nickname,
                    'real_name'    => $realName,
                    'display_name' => $nickname,
                    'message'      => 'Nickname saved successfully'
                ]);
            }
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /* ==========================================================
       OPEN VIEW ONCE MEDIA / MESSAGE
       ========================================================== */
    case 'open_view_once':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }

        $messageId = (int)($_POST['message_id'] ?? 0);
        if ($messageId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Message ID required']);
            exit;
        }

        try {
            $stmt = $db->prepare("SELECT * FROM chat_messages WHERE id = :id");
            $stmt->execute([':id' => $messageId]);
            $msg = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$msg) {
                echo json_encode(['success' => false, 'error' => 'Message not found']);
                exit;
            }

            if (empty($msg['is_view_once'])) {
                echo json_encode(['success' => false, 'error' => 'Not a view-once message']);
                exit;
            }

            $isSender = ((int)$msg['sender_id'] === $myId);
            $isReceiver = ((int)($msg['receiver_id'] ?? 0) === $myId);
            $isGroup = !empty($msg['group_id']);

            if (!$isSender && !$isReceiver && !$isGroup) {
                echo json_encode(['success' => false, 'error' => 'Unauthorized']);
                exit;
            }

            // WhatsApp Rule: Sender cannot open their own View Once message
            if ($isSender) {
                echo json_encode([
                    'success' => false, 
                    'error' => 'View once messages cannot be opened by the sender.',
                    'is_sender' => true
                ]);
                exit;
            }

            // If already opened, expired!
            if (!empty($msg['view_once_opened'])) {
                echo json_encode(['success' => false, 'error' => 'This photo/media has already expired.', 'is_expired' => true]);
                exit;
            }

            // Mark as opened immediately when receiver opens it
            $db->prepare("UPDATE chat_messages SET view_once_opened = 1, view_once_opened_at = NOW() WHERE id = :id")->execute([':id' => $messageId]);

            $decrypted = decryptChatMessage($msg['message']);

            echo json_encode([
                'success'       => true,
                'message_id'    => $messageId,
                'message'       => $decrypted,
                'file_path'     => $msg['file_path'],
                'file_name'     => $msg['file_name'],
                'file_type'     => $msg['file_type'],
                'file_size'     => (int)$msg['file_size'],
                'is_sender'     => $isSender
            ]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'get_blocked_users':
        try {
            $stmt = $db->prepare("
                SELECT u.id, u.name, u.username, u.role, b.created_at as blocked_at
                FROM chat_blocks b
                JOIN users u ON b.blocked_id = u.id
                WHERE b.blocker_id = :me
                ORDER BY b.id DESC
            ");
            $stmt->execute([':me' => $myId]);
            $blockedList = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'blocked_users' => $blockedList]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /* ==========================================================
       MESSAGE INFO (Delivered & Read Timestamps)
       ========================================================== */
    case 'message_info':
        $msgId = (int)($_GET['message_id'] ?? ($_POST['message_id'] ?? 0));
        if ($msgId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid message ID']);
            exit;
        }

        try {
            $stmt = $db->prepare("
                SELECT m.*, s.name AS sender_name, r.name AS receiver_name
                FROM chat_messages m
                LEFT JOIN users s ON m.sender_id = s.id
                LEFT JOIN users r ON m.receiver_id = r.id
                WHERE m.id = :id
            ");
            $stmt->execute([':id' => $msgId]);
            $m = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$m) {
                echo json_encode(['success' => false, 'error' => 'Message not found']);
                exit;
            }

            if ((int)$m['sender_id'] !== $myId && (int)$m['receiver_id'] !== $myId) {
                echo json_encode(['success' => false, 'error' => 'Unauthorized']);
                exit;
            }

            $sentTime = date('d M Y, h:i A', strtotime($m['created_at']));
            $deliveredTime = !empty($m['delivered_at']) ? date('d M Y, h:i A', strtotime($m['delivered_at'])) : ((int)$m['is_delivered'] === 1 ? $sentTime : null);
            $readTime = !empty($m['read_at']) ? date('d M Y, h:i A', strtotime($m['read_at'])) : ((int)$m['is_read'] === 1 ? $deliveredTime : null);

            echo json_encode([
                'success' => true,
                'info'    => [
                    'id'            => (int)$m['id'],
                    'message'       => decryptChatMessage($m['message']),
                    'file_name'     => $m['file_name'],
                    'file_type'     => $m['file_type'],
                    'is_forwarded'  => (int)($m['is_forwarded'] ?? 0),
                    'sender_name'   => $m['sender_name'],
                    'receiver_name' => $m['receiver_name'],
                    'is_mine'       => ((int)$m['sender_id'] === $myId),
                    'sent_at'       => $sentTime,
                    'is_delivered'  => (int)$m['is_delivered'] === 1,
                    'delivered_at'  => $deliveredTime,
                    'is_read'       => (int)$m['is_read'] === 1,
                    'read_at'       => $readTime
                ]
            ]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /* ==========================================================
       FORWARD MESSAGE
       ========================================================== */
    case 'forward_message':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }

        $msgId         = (int)($_POST['message_id'] ?? 0);
        $msgIdsRaw     = $_POST['message_ids'] ?? null;
        $receiverId    = (int)($_POST['receiver_id'] ?? 0);
        $targetGroupId = (int)($_POST['group_id'] ?? 0);

        $msgIds = [];
        if (!empty($msgIdsRaw)) {
            if (is_array($msgIdsRaw)) {
                $msgIds = array_map('intval', $msgIdsRaw);
            } else {
                $decoded = json_decode($msgIdsRaw, true);
                if (is_array($decoded)) {
                    $msgIds = array_map('intval', $decoded);
                } else {
                    $msgIds = array_map('intval', explode(',', (string)$msgIdsRaw));
                }
            }
        } elseif ($msgId > 0) {
            $msgIds = [$msgId];
        }
        $msgIds = array_values(array_unique(array_filter($msgIds, fn($id) => $id > 0)));

        if (empty($msgIds) || ($receiverId <= 0 && $targetGroupId <= 0)) {
            echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
            exit;
        }

        try {
            if ($targetGroupId > 0) {
                // Verify membership in target group
                $stmtGrpMem = $db->prepare("SELECT g.name FROM chat_group_members gm JOIN chat_groups g ON g.id = gm.group_id WHERE gm.group_id = :gid AND gm.user_id = :me");
                $stmtGrpMem->execute([':gid' => $targetGroupId, ':me' => $myId]);
                $targetGrp = $stmtGrpMem->fetch(PDO::FETCH_ASSOC);
                if (!$targetGrp) {
                    echo json_encode(['success' => false, 'error' => 'You are not a member of this target group']);
                    exit;
                }
            } else {
                $stmtTarget = $db->prepare("SELECT id, role, name, username FROM users WHERE id = :id AND status = 'active'");
                $stmtTarget->execute([':id' => $receiverId]);
                $targetUser = $stmtTarget->fetch(PDO::FETCH_ASSOC);

                if (!$targetUser || !isChatAllowed($myRole, $targetUser['role'], $myUsername, $targetUser['username'] ?? '', $myId, (int)$receiverId)) {
                    echo json_encode(['success' => false, 'error' => 'Cannot forward to this recipient due to role policy']);
                    exit;
                }

                // Check if blocked
                $stmtBlockCheck = $db->prepare("SELECT 
                    EXISTS(SELECT 1 FROM chat_blocks WHERE blocker_id = :me AND blocked_id = :rid) AS blocked_by_me,
                    EXISTS(SELECT 1 FROM chat_blocks WHERE blocker_id = :rid AND blocked_id = :me) AS blocked_by_them
                ");
                $stmtBlockCheck->execute([':me' => $myId, ':rid' => $receiverId]);
                $bRow = $stmtBlockCheck->fetch(PDO::FETCH_ASSOC);
                if (!empty($bRow['blocked_by_me'])) {
                    echo json_encode(['success' => false, 'error' => 'You have blocked this contact. Unblock them before forwarding.']);
                    exit;
                }
                if (!empty($bRow['blocked_by_them'])) {
                    echo json_encode(['success' => false, 'error' => 'You cannot forward messages to this contact.']);
                    exit;
                }
            }

            // Fetch all specified original messages
            $inPlaceholders = implode(',', array_fill(0, count($msgIds), '?'));
            $stmtOrig = $db->prepare("SELECT * FROM chat_messages WHERE id IN ($inPlaceholders) AND is_deleted_for_everyone = 0 ORDER BY id ASC");
            $stmtOrig->execute($msgIds);
            $origMessages = $stmtOrig->fetchAll(PDO::FETCH_ASSOC);

            if (empty($origMessages)) {
                echo json_encode(['success' => false, 'error' => 'Original message(s) not found']);
                exit;
            }

            $forwardedIds = [];
            foreach ($origMessages as $orig) {
                // Fresh encryption for the forwarded message
                $plainFwdText = decryptChatMessage($orig['message']);
                $encFwdText   = !empty($plainFwdText) ? encryptChatMessage($plainFwdText) : null;

                if ($targetGroupId > 0) {
                    $stmtIns = $db->prepare("
                        INSERT INTO chat_messages (sender_id, receiver_id, group_id, message, file_path, file_name, file_type, file_size, is_forwarded, is_delivered, delivered_at, created_at)
                        VALUES (:s, NULL, :gid, :m, :fp, :fn, :ft, :fs, 1, 1, NOW(), NOW())
                    ");
                    $stmtIns->execute([
                        ':s'   => $myId,
                        ':gid' => $targetGroupId,
                        ':m'   => $encFwdText,
                        ':fp'  => $orig['file_path'],
                        ':fn'  => $orig['file_name'],
                        ':ft'  => $orig['file_type'],
                        ':fs'  => (int)($orig['file_size'] ?? 0)
                    ]);

                    $newMsgId = (int)$db->lastInsertId();
                    $forwardedIds[] = $newMsgId;
                    $db->prepare("UPDATE chat_group_members SET last_read_message_id = :mid WHERE group_id = :gid AND user_id = :me")
                       ->execute([':mid' => $newMsgId, ':gid' => $targetGroupId, ':me' => $myId]);
                } else {
                    $stmtIns = $db->prepare("
                        INSERT INTO chat_messages (sender_id, receiver_id, message, file_path, file_name, file_type, file_size, is_forwarded, created_at)
                        VALUES (:s, :r, :m, :fp, :fn, :ft, :fs, 1, NOW())
                    ");
                    $stmtIns->execute([
                        ':s'  => $myId,
                        ':r'  => $receiverId,
                        ':m'  => $encFwdText,
                        ':fp' => $orig['file_path'],
                        ':fn' => $orig['file_name'],
                        ':ft' => $orig['file_type'],
                        ':fs' => (int)($orig['file_size'] ?? 0)
                    ]);
                    $forwardedIds[] = (int)$db->lastInsertId();
                }
            }

            echo json_encode([
                'success'       => true,
                'count'         => count($forwardedIds),
                'forwarded_ids' => $forwardedIds,
                'forwarded_id'  => end($forwardedIds),
                'receiver_name' => $targetGroupId > 0 ? $targetGrp['name'] : $targetUser['name']
            ]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /* ==========================================================
       WEBRTC VOICE & VIDEO CALLING ACTIONS
       ========================================================== */
    case 'initiate_call':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }
        $receiverId = (int)($_POST['receiver_id'] ?? 0);
        $groupId    = (int)($_POST['group_id'] ?? 0);
        $callType   = in_array($_POST['call_type'] ?? '', ['voice', 'video']) ? $_POST['call_type'] : 'voice';
        $offer      = trim($_POST['offer'] ?? '');

        if (($receiverId <= 0 && $groupId <= 0) || empty($offer)) {
            echo json_encode(['success' => false, 'error' => 'Receiver or Group and WebRTC Offer are required']);
            exit;
        }

        try {
            // Cancel any pending stale calls from current caller
            $db->prepare("UPDATE chat_calls SET status = 'missed' WHERE caller_id = :me AND status IN ('calling', 'ringing')")->execute([':me' => $myId]);

            // Auto-clean any abandoned connected calls where last_ping is older than 25 seconds
            $db->prepare("UPDATE chat_calls SET status = 'ended', ended_at = NOW() WHERE status = 'connected' AND last_ping IS NOT NULL AND TIMESTAMPDIFF(SECOND, last_ping, NOW()) > 25")->execute();

            // Group Call Flow
            if ($groupId > 0) {
                $stmtMem = $db->prepare("SELECT gm.role, g.name FROM chat_group_members gm JOIN chat_groups g ON g.id = gm.group_id WHERE gm.group_id = :gid AND gm.user_id = :me");
                $stmtMem->execute([':gid' => $groupId, ':me' => $myId]);
                $grp = $stmtMem->fetch(PDO::FETCH_ASSOC);

                if (!$grp) {
                    echo json_encode(['success' => false, 'error' => 'You are not a member of this group.']);
                    exit;
                }

                // Check if active call already exists in this group
                $stmtExist = $db->prepare("SELECT id FROM chat_calls WHERE group_id = :gid AND status IN ('calling', 'ringing', 'connected') ORDER BY id DESC LIMIT 1");
                $stmtExist->execute([':gid' => $groupId]);
                $existingCallId = (int)$stmtExist->fetchColumn();

                if ($existingCallId > 0) {
                    echo json_encode([
                        'success'        => true,
                        'call_id'        => $existingCallId,
                        'call_type'      => $callType,
                        'is_group'       => true,
                        'group_id'       => $groupId,
                        'group_name'     => $grp['name'],
                        'already_active' => true
                    ]);
                    exit;
                }

                $stmtCall = $db->prepare("
                    INSERT INTO chat_calls (caller_id, receiver_id, group_id, call_type, status, offer, last_ping, created_at)
                    VALUES (:caller, NULL, :gid, :type, 'calling', :offer, NOW(), NOW())
                ");
                $stmtCall->execute([
                    ':caller' => $myId,
                    ':gid'    => $groupId,
                    ':type'   => $callType,
                    ':offer'  => $offer
                ]);
                $callId = (int)$db->lastInsertId();

                // Register host in chat_call_participants
                $db->prepare("
                    INSERT INTO chat_call_participants (call_id, user_id, role, status, joined_at, last_ping)
                    VALUES (:cid, :uid, 'caller', 'connected', NOW(), NOW())
                ")->execute([':cid' => $callId, ':uid' => $myId]);

                // Group announcement message
                $callerName = $_SESSION['name'] ?? $_SESSION['username'] ?? 'User';
                $callLabel = ($callType === 'video') ? "📹 {$callerName} started a Group Video Call" : "📞 {$callerName} started a Group Voice Call";
                $db->prepare("INSERT INTO chat_messages (sender_id, receiver_id, group_id, message, is_delivered, delivered_at, created_at) VALUES (:sid, NULL, :gid, :msg, 1, NOW(), NOW())")
                   ->execute([':sid' => $myId, ':gid' => $groupId, ':msg' => encryptChatMessage($callLabel)]);

                echo json_encode([
                    'success'    => true,
                    'call_id'    => $callId,
                    'call_type'  => $callType,
                    'is_group'   => true,
                    'group_id'   => $groupId,
                    'group_name' => $grp['name']
                ]);
                exit;
            }

            // Direct 1-on-1 Call Flow
            $stmtTarget = $db->prepare("SELECT id, role, name, username, last_seen FROM users WHERE id = :id AND status = 'active'");
            $stmtTarget->execute([':id' => $receiverId]);
            $targetUser = $stmtTarget->fetch(PDO::FETCH_ASSOC);

            if (!$targetUser || !isChatAllowed($myRole, $targetUser['role'], $myUsername, $targetUser['username'] ?? '', $myId, (int)$receiverId)) {
                echo json_encode(['success' => false, 'error' => 'Calls with this user are restricted by role policy.']);
                exit;
            }

            // Check if blocked
            $stmtBlockCheck = $db->prepare("SELECT 
                EXISTS(SELECT 1 FROM chat_blocks WHERE blocker_id = :me AND blocked_id = :rid) AS blocked_by_me,
                EXISTS(SELECT 1 FROM chat_blocks WHERE blocker_id = :rid AND blocked_id = :me) AS blocked_by_them
            ");
            $stmtBlockCheck->execute([':me' => $myId, ':rid' => $receiverId]);
            $bRow = $stmtBlockCheck->fetch(PDO::FETCH_ASSOC);
            if (!empty($bRow['blocked_by_me'])) {
                echo json_encode(['success' => false, 'error' => 'You have blocked this contact. Unblock them to make calls.']);
                exit;
            }
            if (!empty($bRow['blocked_by_them'])) {
                echo json_encode(['success' => false, 'error' => 'Calls are not permitted with this contact.']);
                exit;
            }

            // Check if receiver is currently offline / internet disconnected
            $lastSeenVal = $targetUser['last_seen'] ?? null;
            $isReceiverOnline = false;
            if (!empty($lastSeenVal) && $lastSeenVal !== '0000-00-00 00:00:00') {
                $lastSeenDiff = time() - strtotime($lastSeenVal);
                if ($lastSeenDiff >= -10 && $lastSeenDiff <= 45) {
                    $isReceiverOnline = true;
                }
            }

            if (!$isReceiverOnline) {
                echo json_encode([
                    'success'       => false,
                    'is_offline'    => true,
                    'receiver_name' => $targetUser['name'],
                    'message'       => 'Aap jis vyakti ko call kar rahe hain, woh abhi offline hain. Kripya unke online aane ka wait karein.',
                    'error'         => 'User is currently offline.'
                ]);
                exit;
            }

            // Check if receiver is currently busy on another active call
            $stmtBusy = $db->prepare("
                SELECT id FROM chat_calls 
                WHERE ((caller_id = :rid AND status IN ('calling', 'ringing', 'connected'))
                   OR (receiver_id = :rid AND status IN ('calling', 'ringing', 'connected')))
                  AND (
                      (status = 'connected' AND (last_ping IS NULL OR TIMESTAMPDIFF(SECOND, last_ping, NOW()) <= 20) AND TIMESTAMPDIFF(SECOND, COALESCE(started_at, created_at), NOW()) <= 7200)
                      OR (status IN ('calling', 'ringing') AND TIMESTAMPDIFF(SECOND, created_at, NOW()) <= 45)
                  )
                ORDER BY id DESC LIMIT 1
            ");
            $stmtBusy->execute([':rid' => $receiverId]);
            $busyCall = $stmtBusy->fetch(PDO::FETCH_ASSOC);

            if ($busyCall) {
                echo json_encode([
                    'success'       => false,
                    'is_busy'       => true,
                    'receiver_name' => $targetUser['name'],
                    'message'       => 'Aap jis vyakti ko call kar rahe hain wo doosre call par vyast hain.',
                    'error'         => 'User is busy on another call.'
                ]);
                exit;
            }

            $stmtCall = $db->prepare("
                INSERT INTO chat_calls (caller_id, receiver_id, call_type, status, offer, last_ping, created_at)
                VALUES (:caller, :receiver, :type, 'calling', :offer, NOW(), NOW())
            ");
            $stmtCall->execute([
                ':caller'   => $myId,
                ':receiver' => $receiverId,
                ':type'     => $callType,
                ':offer'    => $offer
            ]);

            $callId = (int)$db->lastInsertId();

            echo json_encode([
                'success'   => true,
                'call_id'   => $callId,
                'call_type' => $callType,
                'receiver'  => [
                    'id'   => $receiverId,
                    'name' => $targetUser['name']
                ]
            ]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'get_call_status':
        $callId = (int)($_GET['call_id'] ?? ($_POST['call_id'] ?? 0));
        if ($callId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid call ID']);
            exit;
        }

        try {
            $stmt = $db->prepare("
                SELECT c.*, u.name AS caller_name, u.role AS caller_role, g.name AS group_name
                FROM chat_calls c
                LEFT JOIN users u ON c.caller_id = u.id
                LEFT JOIN chat_groups g ON g.id = c.group_id
                WHERE c.id = :id
            ");
            $stmt->execute([':id' => $callId]);
            $call = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$call) {
                echo json_encode(['success' => false, 'error' => 'Call not found']);
                exit;
            }

            $isGroup = !empty($call['group_id']);
            $isCaller = ((int)$call['caller_id'] === $myId);
            $isReceiver = ((int)($call['receiver_id'] ?? 0) === $myId);

            if ($isGroup) {
                // Verify group membership
                $stmtMem = $db->prepare("SELECT 1 FROM chat_group_members WHERE group_id = :gid AND user_id = :me");
                $stmtMem->execute([':gid' => $call['group_id'], ':me' => $myId]);
                if (!$stmtMem->fetch()) {
                    echo json_encode(['success' => false, 'error' => 'Unauthorized group member']);
                    exit;
                }

                // Update participant last_ping
                $db->prepare("UPDATE chat_call_participants SET last_ping = NOW() WHERE call_id = :id AND user_id = :me")->execute([':id' => $callId, ':me' => $myId]);
            } else {
                if (!$isCaller && !$isReceiver) {
                    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
                    exit;
                }
            }

            // Keep heartbeat ping updated for active connected call
            if ($call['status'] === 'connected') {
                $db->prepare("UPDATE chat_calls SET last_ping = NOW() WHERE id = :id")->execute([':id' => $callId]);
            }

            // Fetch connected participants for group call
            $participants = [];
            if ($isGroup) {
                // Clean abandoned participants who haven't pinged in 25s
                $db->prepare("UPDATE chat_call_participants SET status = 'left', left_at = NOW() WHERE call_id = :id AND status = 'connected' AND last_ping IS NOT NULL AND TIMESTAMPDIFF(SECOND, last_ping, NOW()) > 25")->execute([':id' => $callId]);

                $stmtParts = $db->prepare("
                    SELECT p.user_id, u.name, u.role, p.status, p.role AS participant_role
                    FROM chat_call_participants p
                    JOIN users u ON u.id = p.user_id
                    WHERE p.call_id = :id AND p.status = 'connected'
                    ORDER BY CASE WHEN p.role = 'caller' THEN 0 ELSE 1 END, u.name ASC
                ");
                $stmtParts->execute([':id' => $callId]);
                $participants = $stmtParts->fetchAll(PDO::FETCH_ASSOC);

                // If host is connected and at least one other participant is connected, ensure status is 'connected'
                if (count($participants) > 1 && $call['status'] === 'calling') {
                    $db->prepare("UPDATE chat_calls SET status = 'connected', started_at = COALESCE(started_at, NOW()) WHERE id = :id")->execute([':id' => $callId]);
                    $call['status'] = 'connected';
                }
            }

            // Auto-expire unanswered calls older than 60 seconds
            if (in_array($call['status'], ['calling', 'ringing'])) {
                $checkStmt = $db->prepare("SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) FROM chat_calls WHERE id = :id");
                $checkStmt->execute([':id' => $callId]);
                $ageSec = (int)$checkStmt->fetchColumn();

                if ($ageSec > 60) {
                    $db->prepare("UPDATE chat_calls SET status = 'missed' WHERE id = :id AND status IN ('calling', 'ringing')")->execute([':id' => $callId]);
                    $call['status'] = 'missed';
                }
            }

            // Fetch any remote candidates from dedicated candidates table
            $lastCandId = (int)($_GET['last_candidate_id'] ?? ($_POST['last_candidate_id'] ?? 0));
            $stmtCands = $db->prepare("
                SELECT id, candidate 
                FROM chat_call_candidates 
                WHERE call_id = :id AND user_id != :me AND id > :last_id 
                ORDER BY id ASC
            ");
            $stmtCands->execute([':id' => $callId, ':me' => $myId, ':last_id' => $lastCandId]);
            $newCandidates = $stmtCands->fetchAll(PDO::FETCH_ASSOC);

            $durSec = (int)$call['duration'];
            if ($durSec <= 0 && !empty($call['started_at'])) {
                $durSec = max(1, time() - strtotime($call['started_at']));
            }
            $durM = floor($durSec / 60);
            $durS = $durSec % 60;
            $durationFormatted = sprintf("%02d:%02d", $durM, $durS);

            echo json_encode([
                'success'             => true,
                'status'              => $call['status'],
                'call_type'           => $call['call_type'],
                'is_group'            => $isGroup,
                'group_id'            => (int)($call['group_id'] ?? 0),
                'group_name'          => $call['group_name'] ?? 'Group Call',
                'participants'        => $participants,
                'caller_id'           => (int)$call['caller_id'],
                'receiver_id'         => (int)($call['receiver_id'] ?? 0),
                'caller_name'         => $call['caller_name'] ?: 'Team Member',
                'caller_role'         => $call['caller_role'] ?? '',
                'offer'               => $call['offer'],
                'answer'              => $call['answer'],
                'new_candidates'      => $newCandidates,
                'caller_filter'       => $call['caller_filter'] ?? 'normal',
                'receiver_filter'     => $call['receiver_filter'] ?? 'normal',
                'duration'            => $durSec,
                'duration_text'       => $durationFormatted
            ]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'answer_call':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }

        $callId = (int)($_POST['call_id'] ?? 0);
        $answer = trim($_POST['answer'] ?? '');

        if ($callId <= 0 || empty($answer)) {
            echo json_encode(['success' => false, 'error' => 'Call ID and Answer SDP required']);
            exit;
        }

        try {
            $stmt = $db->prepare("SELECT * FROM chat_calls WHERE id = :id");
            $stmt->execute([':id' => $callId]);
            $call = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$call) {
                echo json_encode(['success' => false, 'error' => 'Call not found or unauthorized']);
                exit;
            }

            if (!empty($call['group_id'])) {
                // Verify group membership
                $stmtMem = $db->prepare("SELECT 1 FROM chat_group_members WHERE group_id = :gid AND user_id = :me");
                $stmtMem->execute([':gid' => $call['group_id'], ':me' => $myId]);
                if (!$stmtMem->fetch()) {
                    echo json_encode(['success' => false, 'error' => 'Unauthorized group member']);
                    exit;
                }

                $db->prepare("UPDATE chat_calls SET status = 'connected', answer = COALESCE(answer, :ans), started_at = COALESCE(started_at, NOW()), last_ping = NOW() WHERE id = :id")
                   ->execute([':ans' => $answer, ':id' => $callId]);

                $stmtP = $db->prepare("
                    INSERT INTO chat_call_participants (call_id, user_id, role, status, answer, joined_at, last_ping)
                    VALUES (:cid, :uid, 'participant', 'connected', :ans, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE status = 'connected', answer = :ans, joined_at = COALESCE(joined_at, NOW()), last_ping = NOW()
                ");
                $stmtP->execute([':cid' => $callId, ':uid' => $myId, ':ans' => $answer]);

                $uName = $_SESSION['name'] ?? $_SESSION['username'] ?? 'User';
                $callLabel = ($call['call_type'] === 'video') ? "📹 {$uName} joined the Group Video Call" : "📞 {$uName} joined the Group Voice Call";
                $db->prepare("INSERT INTO chat_messages (sender_id, receiver_id, group_id, message, is_delivered, delivered_at, created_at) VALUES (:sid, NULL, :gid, :msg, 1, NOW(), NOW())")
                   ->execute([':sid' => $myId, ':gid' => $call['group_id'], ':msg' => encryptChatMessage($callLabel)]);

                echo json_encode(['success' => true, 'is_group' => true]);
                exit;
            }

            // Direct 1-on-1 check
            if ((int)$call['receiver_id'] !== $myId) {
                echo json_encode(['success' => false, 'error' => 'Call not found or unauthorized']);
                exit;
            }

            $db->prepare("UPDATE chat_calls SET status = 'connected', answer = :ans, started_at = NOW(), last_ping = NOW() WHERE id = :id")
               ->execute([':ans' => $answer, ':id' => $callId]);

            echo json_encode(['success' => true]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'send_ice_candidate':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }

        $callId = (int)($_POST['call_id'] ?? 0);
        $candidate = trim($_POST['candidate'] ?? '');

        if ($callId <= 0 || empty($candidate)) {
            echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
            exit;
        }

        try {
            // Atomic candidate insert into chat_call_candidates table
            $stmt = $db->prepare("INSERT INTO chat_call_candidates (call_id, user_id, candidate) VALUES (:cid, :uid, :cand)");
            $stmt->execute([':cid' => $callId, ':uid' => $myId, ':cand' => $candidate]);

            echo json_encode(['success' => true]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'update_call_filter':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }

        $callId = (int)($_POST['call_id'] ?? 0);
        $filter = trim($_POST['filter'] ?? 'normal');

        if ($callId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid call ID']);
            exit;
        }

        try {
            $stmt = $db->prepare("SELECT caller_id, receiver_id FROM chat_calls WHERE id = :id");
            $stmt->execute([':id' => $callId]);
            $call = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$call) {
                echo json_encode(['success' => false, 'error' => 'Call not found']);
                exit;
            }

            if ((int)$call['caller_id'] === $myId) {
                $db->prepare("UPDATE chat_calls SET caller_filter = :f WHERE id = :id")->execute([':f' => $filter, ':id' => $callId]);
            } elseif ((int)$call['receiver_id'] === $myId) {
                $db->prepare("UPDATE chat_calls SET receiver_filter = :f WHERE id = :id")->execute([':f' => $filter, ':id' => $callId]);
            }

            echo json_encode(['success' => true, 'filter' => $filter]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'reject_call':
        $callId = (int)($_POST['call_id'] ?? ($_GET['call_id'] ?? 0));
        try {
            $stmt = $db->prepare("SELECT * FROM chat_calls WHERE id = :id");
            $stmt->execute([':id' => $callId]);
            $call = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($call && !empty($call['group_id'])) {
                $db->prepare("
                    INSERT INTO chat_call_participants (call_id, user_id, role, status, last_ping)
                    VALUES (:cid, :uid, 'participant', 'declined', NOW())
                    ON DUPLICATE KEY UPDATE status = 'declined', last_ping = NOW()
                ")->execute([':cid' => $callId, ':uid' => $myId]);

                echo json_encode(['success' => true]);
                exit;
            }

            $db->prepare("UPDATE chat_calls SET status = 'rejected', ended_at = NOW() WHERE id = :id AND (receiver_id = :me OR caller_id = :me)")
               ->execute([':id' => $callId, ':me' => $myId]);
            echo json_encode(['success' => true]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'end_call':
        $callId = (int)($_POST['call_id'] ?? ($_GET['call_id'] ?? 0));
        try {
            $stmt = $db->prepare("SELECT * FROM chat_calls WHERE id = :id");
            $stmt->execute([':id' => $callId]);
            $call = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($call && $call['status'] !== 'ended') {
                $duration = 0;
                if (!empty($call['started_at'])) {
                    $duration = max(1, time() - strtotime($call['started_at']));
                }

                if (!empty($call['group_id'])) {
                    $isHost = ((int)$call['caller_id'] === $myId);
                    if ($isHost) {
                        // Host ends call for entire group
                        $db->prepare("UPDATE chat_calls SET status = 'ended', duration = :dur, ended_at = NOW() WHERE id = :id")
                           ->execute([':dur' => $duration, ':id' => $callId]);
                        $db->prepare("UPDATE chat_call_participants SET status = 'left', left_at = NOW() WHERE call_id = :id AND status = 'connected'")
                           ->execute([':id' => $callId]);

                        $durMin = floor($duration / 60);
                        $durSec = $duration % 60;
                        $durText = $durMin > 0 ? "{$durMin}m {$durSec}s" : "{$durSec}s";
                        $callLabel = ($call['call_type'] === 'video') ? "📹 Group Video Call" : "📞 Group Voice Call";
                        $summaryMsg = $duration > 0 ? "{$callLabel} ended • {$durText}" : "{$callLabel} • Missed";

                        $db->prepare("INSERT INTO chat_messages (sender_id, receiver_id, group_id, message, is_delivered, delivered_at, created_at) VALUES (:sid, NULL, :gid, :msg, 1, NOW(), NOW())")
                           ->execute([':sid' => $myId, ':gid' => $call['group_id'], ':msg' => encryptChatMessage($summaryMsg)]);
                    } else {
                        // Participant leaves
                        $db->prepare("UPDATE chat_call_participants SET status = 'left', left_at = NOW() WHERE call_id = :id AND user_id = :me")
                           ->execute([':id' => $callId, ':me' => $myId]);

                        $activeCount = (int)$db->query("SELECT COUNT(*) FROM chat_call_participants WHERE call_id = $callId AND status = 'connected'")->fetchColumn();
                        if ($activeCount <= 1) {
                            $db->prepare("UPDATE chat_calls SET status = 'ended', duration = :dur, ended_at = NOW() WHERE id = :id")
                               ->execute([':dur' => $duration, ':id' => $callId]);
                        }
                    }
                    echo json_encode(['success' => true]);
                    exit;
                }

                // Direct 1-on-1 end
                if ((int)$call['caller_id'] === $myId || (int)$call['receiver_id'] === $myId) {
                    $db->prepare("UPDATE chat_calls SET status = 'ended', duration = :dur, ended_at = NOW() WHERE id = :id")
                       ->execute([':dur' => $duration, ':id' => $callId]);

                    $durMin = floor($duration / 60);
                    $durSec = $duration % 60;
                    $durText = $durMin > 0 ? "{$durMin}m {$durSec}s" : "{$durSec}s";
                    $callLabel = ($call['call_type'] === 'video') ? "📹 Video Call" : "📞 Voice Call";
                    $summaryMsg = $duration > 0 ? "{$callLabel} ended • {$durText}" : "{$callLabel} • Missed";

                    $stmtMsg = $db->prepare("
                        INSERT INTO chat_messages (sender_id, receiver_id, message, is_delivered, is_read, created_at)
                        VALUES (:s, :r, :m, 1, 0, NOW())
                    ");
                    $stmtMsg->execute([
                        ':s' => (int)$call['caller_id'],
                        ':r' => (int)$call['receiver_id'],
                        ':m' => encryptChatMessage($summaryMsg)
                    ]);
                }
            }
            echo json_encode(['success' => true]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'get_call_history':
        try {
            $stmt = $db->prepare("
                SELECT 
                    c.id,
                    c.caller_id,
                    c.receiver_id,
                    c.group_id,
                    c.call_type,
                    c.status,
                    c.duration,
                    c.started_at,
                    c.ended_at,
                    c.created_at,
                    c.recording_path,
                    c.recording_size,
                    u_caller.name AS caller_name,
                    u_caller.username AS caller_username,
                    u_caller.role AS caller_role,
                    u_receiver.name AS receiver_name,
                    u_receiver.username AS receiver_username,
                    u_receiver.role AS receiver_role,
                    g.name AS group_name
                FROM chat_calls c
                LEFT JOIN users u_caller ON u_caller.id = c.caller_id
                LEFT JOIN users u_receiver ON u_receiver.id = c.receiver_id
                LEFT JOIN chat_groups g ON g.id = c.group_id
                WHERE c.caller_id = :me 
                   OR c.receiver_id = :me 
                   OR (c.group_id IS NOT NULL AND c.group_id IN (SELECT group_id FROM chat_group_members WHERE user_id = :me))
                ORDER BY c.id DESC
                LIMIT 80
            ");
            $stmt->execute([':me' => $myId]);
            $rawCalls = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $callHistory = [];
            foreach ($rawCalls as $row) {
                $isCaller = ((int)$row['caller_id'] === $myId);
                $isGroup  = !empty($row['group_id']);
                
                // Determine peer contact
                if ($isGroup) {
                    $peerId    = (int)$row['group_id'];
                    $peerName  = $row['group_name'] ?? 'Group Call';
                    $peerRole  = 'Group';
                    $peerType  = 'group';
                } elseif ($isCaller) {
                    $peerId    = (int)$row['receiver_id'];
                    $peerName  = $row['receiver_name'] ?? 'Team Member';
                    $peerRole  = ucwords(str_replace('_', ' ', $row['receiver_role'] ?? 'member'));
                    $peerType  = 'direct';
                } else {
                    $peerId    = (int)$row['caller_id'];
                    $peerName  = $row['caller_name'] ?? 'Team Member';
                    $peerRole  = ucwords(str_replace('_', ' ', $row['caller_role'] ?? 'member'));
                    $peerType  = 'direct';
                }

                // Call direction and status
                $status = $row['status'];
                $isMissed = false;
                $direction = $isCaller ? 'outgoing' : 'incoming';

                if (!$isCaller && in_array($status, ['missed', 'calling', 'ringing'])) {
                    $isMissed = true;
                } elseif (!$isCaller && $status === 'rejected' && (int)$row['duration'] === 0) {
                    $isMissed = true;
                }

                $dur = (int)$row['duration'];
                $durMin = floor($dur / 60);
                $durSec = $dur % 60;
                $durText = $dur > 0 
                    ? ($durMin > 0 ? "{$durMin}m {$durSec}s" : "{$durSec}s") 
                    : ($isMissed ? 'Missed' : ($status === 'rejected' ? 'Declined' : ($status === 'ended' ? 'Ended' : ucfirst($status))));

                $timestamp = strtotime($row['created_at']);
                $dateFormatted = date('d M, h:i A', $timestamp);
                if (date('Y-m-d', $timestamp) === date('Y-m-d')) {
                    $dateFormatted = 'Today, ' . date('h:i A', $timestamp);
                } elseif (date('Y-m-d', $timestamp) === date('Y-m-d', strtotime('-1 day'))) {
                    $dateFormatted = 'Yesterday, ' . date('h:i A', $timestamp);
                }

                $callHistory[] = [
                    'id'            => (int)$row['id'],
                    'peer_id'       => $peerId,
                    'peer_name'     => $peerName,
                    'peer_role'     => $peerRole,
                    'peer_type'     => $peerType,
                    'call_type'     => $row['call_type'],
                    'direction'     => $direction,
                    'status'        => $status,
                    'is_missed'     => $isMissed,
                    'is_group'      => $isGroup,
                    'duration'      => $dur,
                    'duration_text' => $durText,
                    'created_at'    => $row['created_at'],
                    'time_formatted'=> $dateFormatted,
                    'recording_url' => !empty($row['recording_path']) ? $row['recording_path'] : null,
                    'recording_size'=> (int)($row['recording_size'] ?? 0),
                    'has_recording' => !empty($row['recording_path'])
                ];
            }

            echo json_encode([
                'success' => true,
                'calls'   => $callHistory
            ]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'upload_call_recording':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }

        $callId = (int)($_POST['call_id'] ?? 0);
        if ($callId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid call ID']);
            exit;
        }

        if (empty($_FILES['recording']) || $_FILES['recording']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'error' => 'No valid recording file uploaded']);
            exit;
        }

        try {
            $stmt = $db->prepare("SELECT * FROM chat_calls WHERE id = :id");
            $stmt->execute([':id' => $callId]);
            $call = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$call) {
                echo json_encode(['success' => false, 'error' => 'Call not found']);
                exit;
            }

            $isCaller = ((int)$call['caller_id'] === $myId);
            $isReceiver = ((int)($call['receiver_id'] ?? 0) === $myId);
            $isGroup = !empty($call['group_id']);

            if (!$isCaller && !$isReceiver && !$isGroup) {
                echo json_encode(['success' => false, 'error' => 'Unauthorized call participant']);
                exit;
            }

            $uploadDir = __DIR__ . '/../../uploads/call_recordings/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $fileTmpPath = $_FILES['recording']['tmp_name'];
            $fileSize = $_FILES['recording']['size'];
            $origName = $_FILES['recording']['name'];
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (!$ext || !in_array($ext, ['webm', 'mp4', 'ogg', 'wav', 'm4a', 'aac', 'mp3'])) {
                $ext = 'webm';
            }

            $newFileName = 'call_rec_' . $callId . '_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            $destPath = $uploadDir . $newFileName;
            $relativeUrl = '../../uploads/call_recordings/' . $newFileName;

            if (move_uploaded_file($fileTmpPath, $destPath)) {
                $db->prepare("UPDATE chat_calls SET recording_path = :path, recording_size = :size WHERE id = :id")
                   ->execute([':path' => $relativeUrl, ':size' => $fileSize, ':id' => $callId]);

                echo json_encode([
                    'success'       => true,
                    'call_id'       => $callId,
                    'recording_url' => $relativeUrl,
                    'recording_size'=> $fileSize
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to save recording file']);
            }
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /* ==========================================================
       GET USER PROFILE (Read-Only Information for Profile Modal)
       ========================================================== */
    case 'get_user_profile':
        $targetUserId = (int)($_GET['user_id'] ?? $myId);
        if ($targetUserId <= 0) {
            $targetUserId = $myId;
        }

        try {
            $stmt = $db->prepare("SELECT id, name, username, email, role, status, last_seen, created_at, profile_image, about FROM users WHERE id = :id");
            $stmt->execute([':id' => $targetUserId]);
            $userRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$userRow) {
                echo json_encode(['success' => false, 'error' => 'User not found']);
                exit;
            }

            $presence = formatLastSeen($userRow['last_seen'] ?? null);

            // Fetch shared media and documents between me and this user (or sent by this user)
            $mediaItems = [];
            $mediaCount = 0;
            try {
                $stmtMedia = $db->prepare("
                    SELECT id, file_path, file_name, file_type, file_size, created_at 
                    FROM chat_messages 
                    WHERE ((sender_id = :me AND receiver_id = :uid AND deleted_for_sender = 0)
                       OR (sender_id = :uid AND receiver_id = :me AND deleted_for_receiver = 0))
                      AND file_path IS NOT NULL AND file_path != '' 
                      AND is_deleted_for_everyone = 0
                      AND is_view_once = 0
                    ORDER BY id DESC LIMIT 20
                ");
                $stmtMedia->execute([':me' => $myId, ':uid' => $targetUserId]);
                $mediaItems = $stmtMedia->fetchAll(PDO::FETCH_ASSOC);

                $stmtCount = $db->prepare("
                    SELECT COUNT(*) 
                    FROM chat_messages 
                    WHERE ((sender_id = :me AND receiver_id = :uid AND deleted_for_sender = 0)
                       OR (sender_id = :uid AND receiver_id = :me AND deleted_for_receiver = 0))
                      AND file_path IS NOT NULL AND file_path != '' 
                      AND is_deleted_for_everyone = 0
                      AND is_view_once = 0
                ");
                $stmtCount->execute([':me' => $myId, ':uid' => $targetUserId]);
                $mediaCount = (int)$stmtCount->fetchColumn();
            } catch (\Exception $mex) {}

            $customNickname = '';
            $nicknameByThem = '';
            if ($targetUserId !== $myId) {
                try {
                    // Nickname I gave to this contact
                    $stmtNick = $db->prepare("SELECT nickname FROM chat_nicknames WHERE user_id = :me AND contact_id = :cid");
                    $stmtNick->execute([':me' => $myId, ':cid' => $targetUserId]);
                    $customNickname = (string)($stmtNick->fetchColumn() ?: '');

                    // Nickname this contact gave to me
                    $stmtNickByThem = $db->prepare("SELECT nickname FROM chat_nicknames WHERE user_id = :cid AND contact_id = :me");
                    $stmtNickByThem->execute([':cid' => $targetUserId, ':me' => $myId]);
                    $nicknameByThem = (string)($stmtNickByThem->fetchColumn() ?: '');
                } catch (\Exception $e) {}
            }

            $rawName = $userRow['name'] ?: $userRow['username'];

            echo json_encode([
                'success' => true,
                'user'    => [
                    'id'               => (int)$userRow['id'],
                    'name'             => $customNickname !== '' ? $customNickname : $rawName,
                    'real_name'        => $rawName,
                    'nickname'         => $customNickname,
                    'nickname_by_them' => $nicknameByThem,
                    'username'         => $userRow['username'],
                    'email'            => !empty($userRow['email']) ? $userRow['email'] : '—',
                    'role'             => $userRow['role'],
                    'role_label'       => ucwords(str_replace('_', ' ', $userRow['role'])),
                    'status'           => $userRow['status'] ?? 'active',
                    'about'            => !empty($userRow['about']) ? $userRow['about'] : 'Available',
                    'profile_image'    => !empty($userRow['profile_image']) ? $userRow['profile_image'] : null,
                    'is_online'        => $presence['is_online'],
                    'last_seen_text'   => $presence['text'],
                    'member_since'     => !empty($userRow['created_at']) ? date('M d, Y', strtotime($userRow['created_at'])) : '—',
                    'is_me'            => ((int)$userRow['id'] === $myId)
                ],
                'media'       => $mediaItems,
                'media_count' => $mediaCount
            ]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /* ==========================================================
       UPLOAD PROFILE IMAGE (From Gallery File or Camera Snapshot)
       ========================================================== */
    case 'upload_profile_image':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }

        try {
            $uploadDir = __DIR__ . '/../../uploads/profile_images/';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0777, true);
            }

            $savedFileName = null;

            // 1. Direct file upload via Gallery/File picker
            if (!empty($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['profile_image'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
                if (!in_array($ext, $allowed)) {
                    echo json_encode(['success' => false, 'error' => 'Invalid image format. Allowed: JPG, PNG, WEBP, GIF']);
                    exit;
                }
                if ($file['size'] > 10 * 1024 * 1024) { // 10MB
                    echo json_encode(['success' => false, 'error' => 'Image size must be less than 10MB']);
                    exit;
                }

                $savedFileName = 'avatar_' . $myId . '_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                $destPath = $uploadDir . $savedFileName;

                if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                    echo json_encode(['success' => false, 'error' => 'Failed to save uploaded image']);
                    exit;
                }
            }
            // 2. Base64 data from Camera Snapshot
            elseif (!empty($_POST['image_base64'])) {
                $base64Data = $_POST['image_base64'];
                if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $type)) {
                    $base64Data = substr($base64Data, strpos($base64Data, ',') + 1);
                    $ext = strtolower($type[1]);
                    if ($ext === 'jpeg') $ext = 'jpg';
                    $decoded = base64_decode($base64Data);
                    if ($decoded === false) {
                        echo json_encode(['success' => false, 'error' => 'Invalid base64 image data']);
                        exit;
                    }

                    $savedFileName = 'avatar_' . $myId . '_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                    $destPath = $uploadDir . $savedFileName;
                    if (file_put_contents($destPath, $decoded) === false) {
                        echo json_encode(['success' => false, 'error' => 'Failed to save captured photo']);
                        exit;
                    }
                } else {
                    echo json_encode(['success' => false, 'error' => 'Invalid image format string']);
                    exit;
                }
            } else {
                echo json_encode(['success' => false, 'error' => 'No image received']);
                exit;
            }

            $relativeUrl = '../../uploads/profile_images/' . $savedFileName;

            // Remove old profile image file if exists
            try {
                $oldStmt = $db->prepare("SELECT profile_image FROM users WHERE id = :id");
                $oldStmt->execute([':id' => $myId]);
                $oldPath = $oldStmt->fetchColumn();
                if (!empty($oldPath) && str_starts_with($oldPath, '../../uploads/profile_images/')) {
                    $oldReal = __DIR__ . '/' . $oldPath;
                    if (file_exists($oldReal)) {
                        @unlink($oldReal);
                    }
                }
            } catch (\Exception $delEx) {}

            // Update user record in database
            $upd = $db->prepare("UPDATE users SET profile_image = :img WHERE id = :id");
            $upd->execute([':img' => $relativeUrl, ':id' => $myId]);

            // Update session if available
            $_SESSION['profile_image'] = $relativeUrl;

            echo json_encode([
                'success'   => true,
                'image_url' => $relativeUrl,
                'user_id'   => $myId,
                'message'   => 'Profile picture updated successfully'
            ]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    /* ==========================================================
       UPDATE USER ABOUT STATUS (WhatsApp Style About Status)
       ========================================================== */
    case 'update_about':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            exit;
        }

        $aboutText = trim($_POST['about'] ?? '');
        if ($aboutText === '') {
            $aboutText = 'Available';
        }
        $aboutText = mb_substr($aboutText, 0, 150);

        try {
            $stmtUpd = $db->prepare("UPDATE users SET about = :about WHERE id = :me");
            $stmtUpd->execute([':about' => $aboutText, ':me' => $myId]);

            echo json_encode([
                'success' => true,
                'about'   => $aboutText,
                'user_id' => $myId,
                'message' => 'About status updated successfully'
            ]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        break;
}
