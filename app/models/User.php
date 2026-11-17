<?php
require_once __DIR__ . '/../config/database.php';

class User {

    private $conn;

    public function __construct() {
        $this->conn = (new Database())->connect();
    }

    /* ================= LOGIN ================= */
    public function getUserByUsername($username) {
        $sql = "SELECT * FROM users WHERE username = :username AND status='active' LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(":username", $username);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /* ================= CREATE USER ================= */
    public function create($data) {

        // Check duplicate username
        $check = $this->conn->prepare("SELECT id FROM users WHERE username = :username");
        $check->bindParam(":username", $data['username']);
        $check->execute();

        if ($check->rowCount() > 0) {
            return "Username already exists";
        }

        $sql = "INSERT INTO users (name, username, password, role) 
                VALUES (:name, :username, :password, :role)";
        
        $stmt = $this->conn->prepare($sql);

        $stmt->execute([
            ':name'     => htmlspecialchars($data['name']),
            ':username' => htmlspecialchars($data['username']),
            ':password' => password_hash($data['password'], PASSWORD_DEFAULT),
            ':role'     => $data['role']
        ]);

        return true;
    }

    /* ================= GET USERS BY ROLE ================= */
    public function getByRole($role) {
        $sql = "SELECT id, name, username, role, created_at 
                FROM users 
                WHERE role = :role AND status='active' 
                ORDER BY id DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':role', $role);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ================= DELETE USER (SOFT DELETE) ================= */
    public function deactivate($id) {
        $sql = "UPDATE users SET status='inactive' WHERE id = :id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    /* ================= CHANGE PASSWORD ================= */
    public function updatePassword($id, $newPassword) {
        $sql = "UPDATE users SET password = :password WHERE id = :id";
        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            ':password' => password_hash($newPassword, PASSWORD_DEFAULT),
            ':id' => $id
        ]);
    }
}
?>
