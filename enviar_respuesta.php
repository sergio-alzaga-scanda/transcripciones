<?php
header("Content-Type: application/json");
require_once 'config/db.php';

$database = new Database();
$db = $database->getConnection();

if ($_POST) {
    $wa_id_cliente = $_POST['wa_id']; // Recibe el ID del tercero
    $mensaje = $_POST['mensaje'];
    
    // --- AQUÍ INSERTARÍAS TU CURL A META PARA ENVIAR EL WHATSAPP REAL ---

    try {
        $query = "INSERT INTO chats (wa_id, nombre, mensaje, tipo, control) 
                  VALUES (:wa_id, 'Agente Sergio', :mensaje, 'bot', 'agente')";
        $stmt = $db->prepare($query);
        $stmt->execute([':wa_id' => $wa_id_cliente, ':mensaje' => $mensaje]);
        
        echo json_encode(["status" => "sent"]);
    } catch (PDOException $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
}
?>