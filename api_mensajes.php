<?php
header("Content-Type: application/json");
require_once 'config/db.php';
$db = (new Database())->getConnection();

$wa_id = $_GET['wa_id'] ?? '';
$is_sesion = $_GET['is_sesion'] ?? '';

// Si no hay is_sesion (chat activo), buscar la sesión actual en sesiones_chat
if (empty($is_sesion) && !empty($wa_id)) {
    $stmt = $db->prepare("SELECT is_sesion FROM sesiones_chat WHERE wa_id = ? AND estado != 'cerrado' LIMIT 1");
    $stmt->execute([$wa_id]);
    $is_sesion = $stmt->fetchColumn();
}

if (!empty($is_sesion)) {
    $query = "SELECT * FROM chats WHERE is_sesion = ? ORDER BY fecha ASC";
    $stmt_msg = $db->prepare($query);
    $stmt_msg->execute([$is_sesion]);
    echo json_encode($stmt_msg->fetchAll(PDO::FETCH_ASSOC));
} else {
    echo json_encode([]);
}
?>