<?php
/**
 * API de Transferencia con Autenticación Básica
 * Credenciales: admin_select / sc4nd4_2026!
 */

// --- NUEVO: Función para escribir en el log ---
function escribir_log($mensaje) {
    // El archivo se creará en la misma carpeta que este script
    $archivo_log = __DIR__ . '/api_debug.log';
    $fecha = date("Y-m-d H:i:s");
    // Se usa error_log con el tipo 3 para escribir en un archivo específico
    error_log("[$fecha] $mensaje\n", 3, $archivo_log);
}

escribir_log("=== NUEVA PETICIÓN RECIBIDA ===");
escribir_log("Parámetros GET recibidos: " . json_encode($_GET));

// 1. Configuración de Autenticación
$usuario_valido = "admin_select";
$password_valido = "sc4nd4_2026!";

if (!isset($_SERVER['PHP_AUTH_USER']) || 
    $_SERVER['PHP_AUTH_USER'] !== $usuario_valido || 
    $_SERVER['PHP_AUTH_PW'] !== $password_valido) {
    
    escribir_log("ERROR: Autenticación fallida.");
    header('WWW-Authenticate: Basic realm="Acceso Restringido API"');
    header('HTTP/1.0 401 Unauthorized');
    echo json_encode(["status" => "error", "message" => "Autenticación fallida"]);
    exit;
}

// 2. Incluir la conexión
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

// 3. Obtener parámetros de la URL (GET)
$session_id = $_GET['session_id'] ?? null;
$resumen    = $_GET['resumen'] ?? null;
$project_id = $_GET['project_id'] ?? null;
$canal      = $_GET['canal'] ?? null;

// Validar parámetros obligatorios
if (!$session_id || !$project_id || !$canal) { 
    escribir_log("ERROR: Faltan parámetros obligatorios. session_id: $session_id, project_id: $project_id, canal: $canal");
    echo json_encode([
        "status" => "error", 
        "message" => "Faltan parámetros obligatorios (session_id, project_id o canal)"
    ]);
    exit;
}

$database = new Database();
$db = $database->getConnection();

// Forzamos a que PDO arroje excepciones para capturar cualquier error SQL
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    // 4. Obtener la API Key del proyecto
    $stmtProj = $db->prepare("SELECT api_key FROM projects_config WHERE project_id = :pid LIMIT 1");
    $stmtProj->execute([':pid' => $project_id]);
    $proyecto = $stmtProj->fetch(PDO::FETCH_ASSOC);

    if (!$proyecto) {
        throw new Exception("Proyecto no encontrado en la base de datos (project_id: $project_id).");
    }

    $apiKey = $proyecto['api_key'];
    escribir_log("API Key obtenida correctamente para el proyecto $project_id.");

    // 5. Sincronización externa vía cURL
    $apiUrl = "http://158.23.137.150:8085/api/info_mensaje.php?take=100&id_project=$project_id&api_key=$apiKey";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $apiResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    escribir_log("Llamada cURL finalizada. Código HTTP: $httpCode. Respuesta: $apiResponse");

    // 6. Registro en tabla 'messages'
    $contenido =  ($resumen ?? "Sin resumen");
    
    $insertQuery = "INSERT INTO messages (session_table_id, role, content, timestamp, transferencia, canal) 
                    VALUES (:session, 'assistant', :content, NOW(), 1, :canal)";
    
    escribir_log("Intentando insertar en base de datos. Query: $insertQuery");
    
    $stmtIns = $db->prepare($insertQuery);
    $stmtIns->execute([
        ':session' => $session_id,
        ':content' => $contenido,
        ':canal'   => $canal
    ]);

    escribir_log("Inserción exitosa en la base de datos.");

    echo json_encode([
        "status" => "success",
        "message" => "Transferencia exitosa y sincronización enviada.",
        "auth" => "authorized",
        "details" => [
            "session" => $session_id,
            "canal" => $canal,
            "external_api_status" => $httpCode
        ]
    ]);

} catch (PDOException $e) {
    // Captura específicamente errores de la base de datos
    escribir_log("ERROR CRÍTICO DE BASE DE DATOS (PDO): " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error de base de datos. Revisa el log."]);
} catch (Exception $e) {
    // Captura otros errores generales
    escribir_log("ERROR GENERAL: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}