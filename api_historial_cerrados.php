<?php
header("Content-Type: application/json");
require_once 'config/db.php';
$db = (new Database())->getConnection();

$session_id = $_GET['is_sesion'] ?? '';

$query = "SELECT wa_id, nombre_usuario, estado, ultima_interaccion, ultimo_mensaje, is_sesion 
          FROM sesiones_chat 
          WHERE estado = 'cerrado'";

$params = [];
if (!empty($session_id)) {
    $query .= " AND is_sesion LIKE ?";
    $params[] = "%$session_id%";
}

$query .= " ORDER BY ultima_interaccion DESC LIMIT 50";

try {
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo json_encode([]);
}