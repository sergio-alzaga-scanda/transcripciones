<?php
/**
 * API de Transferencia con Autenticación Básica
 * Credenciales: admin_select / sc4nd4_2026!
 */

// 1. Configuración de Autenticación
$usuario_valido = "admin_select";
$password_valido = "sc4nd4_2026!";

if (!isset($_SERVER['PHP_AUTH_USER']) || 
    $_SERVER['PHP_AUTH_USER'] !== $usuario_valido || 
    $_SERVER['PHP_AUTH_PW'] !== $password_valido) {
    
    header('WWW-Authenticate: Basic realm="Acceso Restringido API"');
    header('HTTP/1.0 401 Unauthorized');
    echo json_encode(["status" => "error", "message" => "Autenticación fallida"]);
    exit;
}

// 2. Incluir la conexión (Ruta: /home/Crisisyco/transcripciones-new/config/db.php)
require_once __DIR__ . '/../config/db.php';

// Cabeceras para respuesta JSON
header('Content-Type: application/json');

// 3. Obtener parámetros de la URL (GET)
$session_id = $_GET['session_id'] ?? null;
$resumen    = $_GET['resumen'] ?? null;
$project_id = $_GET['project_id'] ?? null;

// Validar parámetros obligatorios
if (!$session_id || !$project_id) {
    echo json_encode([
        "status" => "error", 
        "message" => "Faltan parámetros obligatorios (session_id o project_id)"
    ]);
    exit;
}

$database = new Database();
$db = $database->getConnection();

try {
    // 4. Obtener la API Key del proyecto
    $stmtProj = $db->prepare("SELECT api_key FROM projects_config WHERE project_id = :pid LIMIT 1");
    $stmtProj->execute([':pid' => $project_id]);
    $proyecto = $stmtProj->fetch(PDO::FETCH_ASSOC);

    if (!$proyecto) {
        throw new Exception("Proyecto no encontrado en la base de datos.");
    }

    $apiKey = $proyecto['api_key'];

    // 5. Sincronización externa vía cURL
    $apiUrl = "http://158.23.137.150:8085/api/info_mensaje.php?take=100&id_project=$project_id&api_key=$apiKey";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $apiResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 6. Registro en tabla 'messages'
    $insertQuery = "INSERT INTO messages (session_table_id, role, content, timestamp, transferencia) 
                    VALUES (:session, 'transferencia', :content, NOW(), 1)";
    
    $stmtIns = $db->prepare($insertQuery);
    $stmtIns->execute([
        ':session' => $session_id,
        ':content' => "Se hizo la transferencia a un agente. Resumen: " . ($resumen ?? "Sin resumen")
    ]);

    echo json_encode([
        "status" => "success",
        "message" => "Transferencia exitosa y sincronización enviada.",
        "auth" => "authorized",
        "details" => [
            "session" => $session_id,
            "external_api_status" => $httpCode
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}