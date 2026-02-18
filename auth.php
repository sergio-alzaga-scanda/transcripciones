<?php
session_start();

// Verificamos que exista la estructura completa del usuario
if (!isset($_SESSION['user']) || !isset($_SESSION['user']['project_id'])) {
    
    // Respuesta JSON para llamadas AJAX/API
    if (strpos($_SERVER['REQUEST_URI'], 'api_') !== false || 
        strpos($_SERVER['REQUEST_URI'], 'enviar_') !== false) {
        
        header("Content-Type: application/json");
        echo json_encode(["status" => "error", "message" => "No autorizado o sesión caducada"]);
        exit;
    }

    // Redirección para acceso directo por navegador
    header("Location: index.php"); // Cambié index.html a index.php que es donde está tu login
    exit;
}
?>