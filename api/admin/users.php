<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../app/config/database.php';

try {
    $db = (new Database())->connect();
    $method = $_SERVER['REQUEST_METHOD'];

    // ─── GET: List users by role ─────────────────────────────
    if ($method === 'GET') {
        $role = $_GET['role'] ?? 'all';
        $search = trim($_GET['search'] ?? '');

        $sql = "SELECT id, name, username, email, role, status, created_at FROM users WHERE 1=1";
        $params = [];

        if ($role !== 'all' && in_array($role, ['admin', 'bdm', 'bde', 'operations_manager', 'operations_team', 'qc'])) {
            $sql .= " AND role = :role";
            $params[':role'] = $role;
        }

        if (!empty($search)) {
            $sql .= " AND (name LIKE :q OR username LIKE :q OR email LIKE :q)";
            $params[':q'] = "%$search%";
        }

        $sql .= " ORDER BY status ASC, id DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Enrich with task count per user
        foreach ($users as &$u) {
            $u['id'] = (int)$u['id'];
            if ($u['role'] === 'bde') {
                $stmtC = $db->prepare("SELECT COUNT(*) as total_tasks, COALESCE(SUM(word_count),0) as total_words FROM tasks WHERE created_by = :uid");
            } else {
                $stmtC = $db->prepare("SELECT COUNT(*) as total_tasks, COALESCE(SUM(word_count),0) as total_words FROM tasks WHERE assigned_to = :uid");
            }
            $stmtC->execute([':uid' => $u['id']]);
            $stats = $stmtC->fetch(PDO::FETCH_ASSOC);
            $u['total_tasks'] = (int)($stats['total_tasks'] ?? 0);
            $u['total_words'] = (int)($stats['total_words'] ?? 0);
        }

        echo json_encode([
            'success' => true,
            'count'   => count($users),
            'users'   => $users
        ]);
        exit;
    }

    // ─── POST: Create User ──────────────────────────────────
    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?? $_POST;

        $name     = trim($data['name'] ?? '');
        $username = trim($data['username'] ?? '');
        $email    = trim($data['email'] ?? '');
        $password = trim($data['password'] ?? '');
        $role     = trim($data['role'] ?? '');

        if (empty($name) || empty($username) || empty($password) || empty($role)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Name, username, password and role are required']);
            exit;
        }

        if (!in_array($role, ['admin', 'bdm', 'bde', 'operations_manager', 'operations_team', 'qc'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid role specified']);
            exit;
        }

        // Check duplicate username
        $check = $db->prepare("SELECT id FROM users WHERE username = :u");
        $check->execute([':u' => $username]);
        if ($check->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Username already exists']);
            exit;
        }

        $hashed = password_hash($password, PASSWORD_BCRYPT);
        $insert = $db->prepare("INSERT INTO users (name, username, email, password, role, status, created_at) VALUES (:name, :u, :e, :p, :r, 'active', NOW())");
        $insert->execute([
            ':name' => $name,
            ':u'    => $username,
            ':e'    => $email ?: null,
            ':p'    => $hashed,
            ':r'    => $role
        ]);

        $newId = (int)$db->lastInsertId();
        echo json_encode([
            'success' => true,
            'message' => 'User created successfully',
            'user'    => [
                'id'       => $newId,
                'name'     => $name,
                'username' => $username,
                'email'    => $email,
                'role'     => $role,
                'status'   => 'active'
            ]
        ]);
        exit;
    }

    // ─── PUT/PATCH: Update status or details ────────────────
    if ($method === 'PUT' || $method === 'PATCH') {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?? [];

        $userId = (int)($data['id'] ?? 0);
        if ($userId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'User ID is required']);
            exit;
        }

        if (isset($data['status'])) {
            $newStatus = $data['status'] === 'active' ? 'active' : 'inactive';
            $upd = $db->prepare("UPDATE users SET status = :st WHERE id = :id");
            $upd->execute([':st' => $newStatus, ':id' => $userId]);

            echo json_encode([
                'success' => true,
                'message' => "User status updated to $newStatus"
            ]);
            exit;
        }

        // General update
        $updates = [];
        $params = [':id' => $userId];
        if (!empty($data['name'])) { $updates[] = "name = :name"; $params[':name'] = trim($data['name']); }
        if (!empty($data['email'])) { $updates[] = "email = :email"; $params[':email'] = trim($data['email']); }
        if (!empty($data['password'])) { $updates[] = "password = :pass"; $params[':pass'] = password_hash(trim($data['password']), PASSWORD_BCRYPT); }
        if (!empty($data['role']) && in_array($data['role'], ['admin', 'bdm', 'bde', 'operations_manager', 'operations_team', 'qc'])) {
            $updates[] = "role = :role"; $params[':role'] = $data['role'];
        }

        if (empty($updates)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No valid fields to update']);
            exit;
        }

        $sql = "UPDATE users SET " . implode(", ", $updates) . " WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        echo json_encode(['success' => true, 'message' => 'User updated successfully']);
        exit;
    }

    // ─── DELETE: Deactivate User ────────────────────────────
    if ($method === 'DELETE') {
        $userId = (int)($_GET['id'] ?? 0);
        if ($userId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'User ID is required']);
            exit;
        }

        $upd = $db->prepare("UPDATE users SET status = 'inactive' WHERE id = :id");
        $upd->execute([':id' => $userId]);

        echo json_encode(['success' => true, 'message' => 'User deactivated successfully']);
        exit;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}

