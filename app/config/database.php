<?php
class Database {

    private $host = "localhost";
    private $db_name = "digiink_taskflow";
    private $username = "root";
    private $password = "";

    public function connect() {
        try {
            $pdo = new PDO(
                "mysql:host={$this->host};dbname={$this->db_name};charset=utf8mb4",
                $this->username,
                $this->password,
                [
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+05:30'",
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );

            // Double enforcement for all MySQL/MariaDB environments (including GoDaddy)
            $pdo->exec("SET time_zone = '+05:30'");

            return $pdo;

        } catch (PDOException $e) {
            die("DB Error: " . $e->getMessage());
        }
    }
}
?>