<?php
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/models/User.php';

class AuthController {
    public function login() {
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $database = new Database();
            $userModel = new User($database->getConnection());
            
            $user = $userModel->login($_POST['username'], $_POST['password']);
            
            if ($user) {
                $_SESSION['user'] = $user;
                
                // --- NUEVO: Bandera para activar Sync Automático ---
                $_SESSION['trigger_sync'] = true; 
                
                header("Location: index.php?page=dashboard");
                exit;
            } else {
                $error = "Usuario o contraseña incorrectos";
                include dirname(__DIR__) . '/views/auth/login.php';
            }
        } else {
            include dirname(__DIR__) . '/views/auth/login.php';
        }
    }

    public function logout() {
        session_destroy();
        header("Location: index.php");
    }
}
?>