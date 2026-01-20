<?php
class User {
    private $conn;
    private $table = "users";

    public function __construct($db) {
        $this->conn = $db;
    }

    public function login($username, $password) {
        $query = "SELECT * FROM " . $this->table . " WHERE username = :username LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":username", $username);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stored_password = $row['password'];

            // CASO 1: La contraseña en la BD ya es un Hash válido
            if (password_verify($password, $stored_password)) {
                // Verificar si el hash necesita actualización (ej. si cambiaste el algoritmo de PHP)
                if (password_needs_rehash($stored_password, PASSWORD_DEFAULT)) {
                    $this->upgradePassword($row['id'], $password);
                }
                return $row;
            }

            // CASO 2: La contraseña en la BD es Texto Plano (Legacy)
            // Si password_verify falló, comprobamos si es igual al texto plano
            if ($password === $stored_password) {
                // ¡Detectado texto plano! -> Hashear y Actualizar BD inmediatamente
                $this->upgradePassword($row['id'], $password);
                
                // Retornamos el usuario (el login es exitoso)
                return $row;
            }
        }
        
        // Si no coincide ni hash ni texto plano
        return false;
    }

    // Función auxiliar para actualizar la contraseña a Hash seguro
    private function upgradePassword($userId, $plainPassword) {
        $newHash = password_hash($plainPassword, PASSWORD_DEFAULT);
        
        $query = "UPDATE " . $this->table . " SET password = :hash WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':hash', $newHash);
        $stmt->bindParam(':id', $userId);
        
        try {
            $stmt->execute();
        } catch (Exception $e) {
            // Loguear error silenciosamente, no queremos detener el login
            error_log("Error actualizando hash de contraseña: " . $e->getMessage());
        }
    }
}
?>