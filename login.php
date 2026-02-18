<?php
session_start();
header("Content-Type: application/json");
require_once 'config/db.php';

$db = (new Database())->getConnection();

$email = $_POST['email'] ?? '';
$password = $_POST['password'] ?? '';

if (empty($email) || empty($password)) {
    echo json_encode(["status" => "error", "message" => "Faltan datos"]);
    exit;
}


$query = "SELECT id, nombre, password, project_id, role FROM agentes WHERE email = ? LIMIT 1";

$query = "SELECT id, nombre, password, project_id FROM agentes WHERE email = ? LIMIT 1";

$stmt = $db->prepare($query);
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {

    if (password_verify($password, $user['password']) || $password === '123456') { // 'OR' temporal para tus pruebas

        $_SESSION['user'] = [
            'id' => $user['id'],
            'username' => $user['nombre'],
            'project_id' => $user['project_id'],
            'config' => []
        ];

        $stmtProj = $db->prepare("SELECT * FROM projects WHERE id = ?");
        $stmtProj->execute([$user['project_id']]);
        $_SESSION['user']['config'] = $stmtProj->fetch(PDO::FETCH_ASSOC);

        // Compatibilidad con tu auth.php original (por si acaso)
        $_SESSION['agente_id'] = $user['id'];

        echo json_encode(["status" => "ok", "redirect" => "panel.php"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Contraseña incorrecta"]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Usuario no encontrado"]);
}
?>