<?php
/**
 * API AJAX para guardar comentario único por conversación.
 * Método: POST
 * Campos: session_id, comentario
 */
header('Content-Type: application/json');
require_once dirname(__DIR__) . '/config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "No autenticado"]);
    exit;
}

$session_id = trim($_POST['session_id'] ?? '');
$comentario_raw = trim($_POST['comentario'] ?? '');

if (!$session_id || !$comentario_raw) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Faltan datos (session_id, comentario)"]);
    exit;
}

$user_name = $_SESSION['user']['username'] ?? 'Operador';
$comentario = "[$user_name] " . $comentario_raw;

try {
    $database = new Database();
    $db = $database->getConnection();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Verificar si ya existe un comentario
    $check = $db->prepare("SELECT id FROM comentarios_conversacion WHERE session_table_id = :sid LIMIT 1");
    $check->execute([':sid' => $session_id]);
    if ($check->fetch()) {
        echo json_encode(["status" => "error", "message" => "Ya existe un comentario para esta conversación"]);
        exit;
    }

    $stmt = $db->prepare(
        "INSERT INTO comentarios_conversacion (session_table_id, comentario, created_at)
         VALUES (:sid, :comentario, NOW())"
    );
    $stmt->execute([':sid' => $session_id, ':comentario' => $comentario]);

    echo json_encode(["status" => "success", "message" => "Comentario guardado"]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error DB: " . $e->getMessage()]);
}
