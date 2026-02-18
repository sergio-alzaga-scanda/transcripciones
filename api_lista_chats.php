<?php
header("Content-Type: application/json");
require_once 'config/db.php';
$db = (new Database())->getConnection();

$wa_id = $_GET['wa_id'] ?? '';

// REGLA: Incluir is_sesion en el SELECT
$query = "SELECT wa_id, nombre_usuario, estado, no_leido, ultimo_remitente, ultima_interaccion, ultimo_mensaje, is_sesion 
          FROM sesiones_chat 
          WHERE estado = 'agente'";

$params = [];
if (!empty($wa_id)) {
    $query .= " AND wa_id LIKE ?";
    $params[] = "%$wa_id%";
}

$query .= " ORDER BY no_leido DESC, ultima_interaccion DESC";

try {
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (PDOException $e) {
    echo json_encode([]);
}