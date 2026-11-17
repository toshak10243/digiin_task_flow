<?php
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../core/Session.php';
require_once __DIR__ . '/../config/database.php';

class AuthController {

    public function login($username, $password) {

        $userModel = new User();
        $user = $userModel->getUserByUsername($username);

        if ($user && password_verify($password, $user['password'])) {

            // Store session data
            $_SESSION['user_id']  = (int) $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role'];
            $_SESSION['name']     = $user['name'] ?? $user['username'];

            // Issue 30-Day Persistent Auth Token
            $this->issuePersistentToken((int) $user['id']);

            header("Location: views/splash/welcome.php");
            exit;

        } else {
            return "Invalid username or password";
        }
    }

    /**
     * Issue a secure 30-day persistent remember token
     */
    public function issuePersistentToken($userId) {
        try {
            $db = (new Database())->connect();

            // Auto-create table if not exists
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

            // Generate cryptographically secure tokens
            $selector = bin2hex(random_bytes(16));
            $validator = bin2hex(random_bytes(32));
            $hashedToken = hash('sha256', $validator);
            $expiresAt = date('Y-m-d H:i:s', time() + (86400 * 30));

            $stmt = $db->prepare("INSERT INTO auth_tokens (user_id, selector, hashed_token, expires_at) VALUES (:uid, :sel, :hash, :exp)");
            $stmt->execute([
                ':uid'  => $userId,
                ':sel'  => $selector,
                ':hash' => $hashedToken,
                ':exp'  => $expiresAt
            ]);

            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

            setcookie('digiink_remember', $selector . ':' . $validator, [
                'expires'  => time() + (86400 * 30),
                'path'     => '/',
                'domain'   => '',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        } catch (\Exception $e) {
            error_log("Failed to issue persistent token: " . $e->getMessage());
        }
    }

    /**
     * Complete Logout (destroys DB token, cookie and session)
     */
    public function logout() {
        if (!empty($_COOKIE['digiink_remember'])) {
            $parts = explode(':', $_COOKIE['digiink_remember'], 2);
            $selector = $parts[0] ?? '';
            if (!empty($selector)) {
                try {
                    $db = (new Database())->connect();
                    $stmt = $db->prepare("DELETE FROM auth_tokens WHERE selector = :selector");
                    $stmt->execute([':selector' => $selector]);
                } catch (\Exception $e) {}
            }
            setcookie('digiink_remember', '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }

        // Immediately mark user offline on logout
        if (!empty($_SESSION['user_id'])) {
            try {
                $db = (new Database())->connect();
                $pastIst = date('Y-m-d H:i:s', time() - 60);
                $db->prepare("UPDATE users SET last_seen = :past WHERE id = :id")
                   ->execute([':past' => $pastIst, ':id' => (int)$_SESSION['user_id']]);
            } catch (\Exception $e) {}
        }

        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();

        header("Location: index.php");
        exit;
    }
}
?>
