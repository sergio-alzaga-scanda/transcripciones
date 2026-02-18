<?php
require_once 'auth.php';
require_once 'config/db.php';
$db = (new Database())->getConnection();

// Filtros recibidos por GET
$filtro_wa_id = $_GET['wa_id'] ?? ''; // Ahora filtramos por ID de Conversación
$filtro_sesion = $_GET['is_sesion'] ?? ''; 
$filtro_fecha = $_GET['fecha'] ?? '';

$sql = "SELECT s.*, p.nombre as proyecto 
        FROM sesiones_chat s
        JOIN projects p ON s.project_id = p.id
        WHERE 1=1";

$params = [];

if (!empty($filtro_wa_id)) {
    $sql .= " AND s.wa_id = ?";
    $params[] = $filtro_wa_id;
}
if (!empty($filtro_sesion)) {
    $sql .= " AND s.is_sesion = ?";
    $params[] = $filtro_sesion;
}
if (!empty($filtro_fecha)) {
    $sql .= " AND DATE(s.ultima_interaccion) = ?";
    $params[] = $filtro_fecha;
}

$sql .= " ORDER BY s.ultima_interaccion DESC LIMIT 50";

$stmt = $db->prepare($sql);
$stmt->execute($params);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
?>