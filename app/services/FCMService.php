<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../../vendor/autoload.php';

class FCMService
{
    private static ?PDO $db = null;
    private static ?string $cachedAccessToken = null;
    private static int $cachedTokenExpiry = 0;
    private static bool $schemaChecked = false;

    private static function getDb(): PDO
    {
        if (self::$db === null) {
            self::$db = (new Database())->connect();
        }
        return self::$db;
    }

    /**
     * Ensure user_fcm_tokens table exists
     */
    public static function ensureSchema(): void
    {
        if (self::$schemaChecked) {
            return;
        }

        try {
            $db = self::getDb();
            $db->exec("
                CREATE TABLE IF NOT EXISTS `user_fcm_tokens` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `user_id` INT NOT NULL,
                    `token` TEXT NOT NULL,
                    `user_agent` VARCHAR(255) DEFAULT NULL,
                    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    KEY `idx_fcm_user` (`user_id`),
                    KEY `idx_fcm_updated` (`updated_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
            ");
        } catch (Exception $e) {
            error_log("FCMService::ensureSchema error: " . $e->getMessage());
        }

        self::$schemaChecked = true;
    }

    /**
     * Get Google OAuth2 access token for FCM HTTP v1
     */
    private static function getAccessToken(): ?string
    {
        $now = time();
        if (self::$cachedAccessToken !== null && self::$cachedTokenExpiry > ($now + 60)) {
            return self::$cachedAccessToken;
        }

        $tokenCacheFile = __DIR__ . '/../../scratch/fcm_access_token.json';
        if (file_exists($tokenCacheFile)) {
            $cached = @json_decode(file_get_contents($tokenCacheFile), true);
            if (!empty($cached['access_token']) && !empty($cached['expires_at']) && $cached['expires_at'] > ($now + 60)) {
                self::$cachedAccessToken = $cached['access_token'];
                self::$cachedTokenExpiry = $cached['expires_at'];
                return self::$cachedAccessToken;
            }
        }

        $credentialsPath = __DIR__ . '/../../config/credentials/firebase_service_account.json';
        if (!file_exists($credentialsPath)) {
            error_log("FCMService: service account file not found at " . $credentialsPath);
            return null;
        }

        try {
            $client = new Google\Client();
            $client->setAuthConfig($credentialsPath);
            $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
            $tokenInfo = $client->fetchAccessTokenWithAssertion();

            if (!empty($tokenInfo['access_token'])) {
                self::$cachedAccessToken = $tokenInfo['access_token'];
                $expiresIn = (int)($tokenInfo['expires_in'] ?? 3600);
                self::$cachedTokenExpiry = $now + $expiresIn;

                // Cache token locally to avoid repeated Google Auth round-trips
                $scratchDir = dirname($tokenCacheFile);
                if (!is_dir($scratchDir)) {
                    @mkdir($scratchDir, 0777, true);
                }
                @file_put_contents($tokenCacheFile, json_encode([
                    'access_token' => self::$cachedAccessToken,
                    'expires_at'   => self::$cachedTokenExpiry
                ]));

                return self::$cachedAccessToken;
            } else {
                error_log("FCMService: failed to fetch access token: " . json_encode($tokenInfo));
                return null;
            }
        } catch (Exception $e) {
            error_log("FCMService::getAccessToken exception: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Save/refresh browser registration token for user
     */
    public static function saveToken(int $userId, string $token, ?string $userAgent = null): bool
    {
        if ($userId <= 0 || empty(trim($token))) {
            return false;
        }

        self::ensureSchema();

        try {
            $db = self::getDb();
            // Check if token already exists for this user
            $stmt = $db->prepare("SELECT id FROM user_fcm_tokens WHERE user_id = :uid AND token = :tok LIMIT 1");
            $stmt->execute([':uid' => $userId, ':tok' => $token]);
            $existingId = $stmt->fetchColumn();

            if ($existingId) {
                $up = $db->prepare("UPDATE user_fcm_tokens SET user_agent = :ua, updated_at = NOW() WHERE id = :id");
                return $up->execute([':ua' => $userAgent, ':id' => $existingId]);
            } else {
                $ins = $db->prepare("INSERT INTO user_fcm_tokens (user_id, token, user_agent, created_at, updated_at) VALUES (:uid, :tok, :ua, NOW(), NOW())");
                return $ins->execute([':uid' => $userId, ':tok' => $token, ':ua' => $userAgent]);
            }
        } catch (Exception $e) {
            error_log("FCMService::saveToken error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send push notification to a specific user across all their registered browser devices
     */
    public static function sendToUser(int $userId, string $title, string $body, ?int $taskId = null, ?string $url = null): int
    {
        if ($userId <= 0) {
            return 0;
        }

        self::ensureSchema();
        $db = self::getDb();

        $stmt = $db->prepare("SELECT id, token FROM user_fcm_tokens WHERE user_id = :uid ORDER BY updated_at DESC");
        $stmt->execute([':uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return 0;
        }

        $successCount = 0;
        foreach ($rows as $row) {
            $sent = self::sendRawMessage($row['token'], $title, $body, $taskId, $url);
            if ($sent['success']) {
                $successCount++;
            } elseif ($sent['should_delete']) {
                // Delete invalid or unregistered token
                $db->prepare("DELETE FROM user_fcm_tokens WHERE id = :id")->execute([':id' => $row['id']]);
            }
        }

        return $successCount;
    }

    /**
     * Send push notification to all users of specific roles
     */
    public static function sendToRole($roles, string $title, string $body, ?int $taskId = null, ?string $url = null): int
    {
        if (!is_array($roles)) {
            $roles = [$roles];
        }

        self::ensureSchema();
        $db = self::getDb();

        $inClause = implode(',', array_fill(0, count($roles), '?'));
        $stmt = $db->prepare("SELECT id FROM users WHERE role IN ($inClause) AND status = 'active'");
        $stmt->execute($roles);
        $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $totalSent = 0;
        foreach ($userIds as $uid) {
            $totalSent += self::sendToUser((int)$uid, $title, $body, $taskId, $url);
        }

        return $totalSent;
    }

    /**
     * Dispatch raw message to FCM HTTP v1 endpoint
     */
    private static function sendRawMessage(string $fcmToken, string $title, string $body, ?int $taskId = null, ?string $url = null): array
    {
        $accessToken = self::getAccessToken();
        if (!$accessToken) {
            return ['success' => false, 'should_delete' => false];
        }

        $projectId = 'digiink-taskflow';
        $endpoint = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        $defaultUrl = $url ?: "/";

        $payload = [
            'message' => [
                'token' => $fcmToken,
                'notification' => [
                    'title' => $title,
                    'body'  => $body
                ],
                'data' => [
                    'title'   => $title,
                    'body'    => $body,
                    'task_id' => $taskId ? (string)$taskId : '',
                    'url'     => $defaultUrl
                ],
                'webpush' => [
                    'headers' => [
                        'Urgency' => 'high'
                    ],
                    'notification' => [
                        'icon'               => '/favicon.png',
                        'badge'              => '/favicon.png',
                        'requireInteraction' => true
                    ],
                    'fcm_options' => [
                        'link' => $defaultUrl
                    ]
                ]
            ]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 200) {
            return ['success' => true, 'should_delete' => false];
        }

        // Check if token is invalid or uninstalled
        $shouldDelete = false;
        if ($httpCode === 404 || strpos($response, 'UNREGISTERED') !== false || strpos($response, 'NOT_FOUND') !== false) {
            $shouldDelete = true;
        }

        error_log("FCM send failed (HTTP {$httpCode}): " . $response . " Error: " . $error);
        return ['success' => false, 'should_delete' => $shouldDelete];
    }
}

