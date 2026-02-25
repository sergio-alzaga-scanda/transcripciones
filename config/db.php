<?php
class Database {
    // private $host = "localhost";
    // private $port = "3307";
    // private $db_name = "voiceFlow";
    // private $username = "root";
    // private $password = "";
    // public $conn;
    private $host = "localhost";
    private $port = "3306";
    private $db_name = "voiceflow";
    private $username = "root";
    private $password = "Melco154.,";
    public $conn;

    public function getConnection() {
        $this->conn = null;
        try {
            $dsn = "mysql:host=" . $this->host . ";port=" . $this->port . ";dbname=" . $this->db_name . ";charset=utf8mb4";
            $this->conn = new PDO($dsn, $this->username, $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $exception) {
            echo "Error de conexión: " . $exception->getMessage();
        }
        return $this->conn;
    }
}
?>