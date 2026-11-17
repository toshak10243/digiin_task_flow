<?php
date_default_timezone_set('Asia/Kolkata');

// 30 Days Session Persistence
$lifetime = 60 * 60 * 24 * 30; // 30 Days

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', $lifetime);
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

/* ==========================================================================
   30-DAY PERSISTENT AUTH TOKEN CHECK (REMEMBER ME)
   If session is expired / browser was closed, but persistent cookie exists,
   automatically authenticate the user from the database.
   ========================================================================== */
if (empty($_SESSION['user_id']) && !empty($_COOKIE['digiink_remember'])) {
    $cookieVal = $_COOKIE['digiink_remember'];
    $parts = explode(':', $cookieVal, 2);

    if (count($parts) === 2) {
        $selector  = $parts[0];
        $validator = $parts[1];

        if (ctype_xdigit($selector) && ctype_xdigit($validator)) {
            try {
                require_once __DIR__ . '/../config/database.php';
                $db = (new Database())->connect();

                // Auto-create table if not yet executed via phpMyAdmin
                $db->exec("CREATE TABLE IF NOT EXISTS `auth_tokens` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `user_id` int(11) NOT NULL,
                  `selector` varchar(32) NOT NULL,
                  `hashed_token` varchar(64) NOT NULL,
                  `expires_at` datetime NOT NULL,
                  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `idx_selector` (`selector`),
                  KEY `fk_auth_tokens_user` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

                $stmt = $db->prepare("
                    SELECT t.*, u.id AS user_id, u.username, u.name, u.role, u.status
                    FROM auth_tokens t
                    JOIN users u ON t.user_id = u.id
                    WHERE t.selector = :selector
                      AND t.expires_at > NOW()
                      AND u.status = 'active'
                    LIMIT 1
                ");
                $stmt->execute([':selector' => $selector]);
                $tokenRow = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($tokenRow && hash_equals($tokenRow['hashed_token'], hash('sha256', $validator))) {
                    // Valid persistent token! Restore session automatically
                    $_SESSION['user_id']  = (int) $tokenRow['user_id'];
                    $_SESSION['username'] = $tokenRow['username'];
                    $_SESSION['role']     = $tokenRow['role'];
                    $_SESSION['name']     = $tokenRow['name'] ?? $tokenRow['username'];
                } else {
                    // Invalid/Expired token -> clear cookie
                    setcookie('digiink_remember', '', [
                        'expires'  => time() - 3600,
                        'path'     => '/',
                        'httponly' => true,
                        'samesite' => 'Lax'
                    ]);
                }
            } catch (\Exception $e) {
                error_log("Persistent token verification error: " . $e->getMessage());
            }
        }
    }
}

/* ==========================================================================
   USER ACTIVITY HEARTBEAT / LAST SEEN TRACKER
   Updates last_seen in database whenever user navigates anywhere on site.
   Throttled to once every 30 seconds to stay super lightweight.
   ========================================================================== */
if (!empty($_SESSION['user_id'])) {
    $now = time();
    if (empty($_SESSION['last_activity_ping']) || ($now - $_SESSION['last_activity_ping']) > 30) {
        $_SESSION['last_activity_ping'] = $now;
        try {
            require_once __DIR__ . '/../config/database.php';
            $dbPing = (new Database())->connect();
            $nowIst = date('Y-m-d H:i:s');
            $dbPing->prepare("UPDATE users SET last_seen = :now WHERE id = :id")->execute([
                ':now' => $nowIst,
                ':id'  => (int)$_SESSION['user_id']
            ]);
        } catch (\Exception $e) {
            // Silently fail if DB is temporarily busy or column not yet added
        }
    }
}
?>