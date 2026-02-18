<?php
require_once 'config/db.php';
$db = (new Database())->getConnection();

if (isset($_POST['wa_id'])) {
    $wa_id = $_POST['wa_id'];

    // 1. Obtener agente asignado
    $stmt = $db->prepare("SELECT agente_asignado FROM sesiones_chat WHERE wa_id = ?");
    $stmt->execute([$wa_id]);
    $agente = $stmt->fetchColumn();

    if ($agente) {
        $db->prepare("UPDATE agentes SET estado = 'disponible' WHERE wa_id = ?")->execute([$agente]);
    }

    // 2. Cerrar sesión
    $db->prepare("UPDATE sesiones_chat SET estado = 'cerrado', agente_asignado = NULL WHERE wa_id = ?")
       ->execute([$wa_id]);
       
    echo json_encode(["status" => "ok"]);
}