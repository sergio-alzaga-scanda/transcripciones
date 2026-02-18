<?php
// sync-voiceflow.php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");

require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/models/User.php';

// 1. Inicializar conexión
$database = new Database();
$db = $database->getConnection();

if (!$db) {
    echo json_encode(["error" => "No se pudo establecer la conexión con la base de datos"]);
    exit;
}

// 2. Obtener parámetros
$take = $_GET['take'] ?? 25;
$project_id = $_GET['id_project'] ?? null;
$api_key = $_GET['api_key'] ?? null;
$server_url = "https://proy020.kenos-atom.com/reimpresion/analyze-project";

if (!$project_id || !$api_key) {
    http_response_code(400);
    echo json_encode(["error" => "Faltan parámetros (id_project o api_key)"]);
    exit;
}

/**
 * Función para restar 6 horas a la fecha ISO de VoiceFlow
 */
function format_date_with_offset($iso_str) {
    if (!$iso_str) return null;
    try {
        $date = new DateTime($iso_str);
        $date->modify('-6 hours');
        return $date->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

try {
    // 3. Gestionar Configuración del Proyecto
    $stmt = $db->prepare("SELECT project_id FROM projects_config WHERE project_id = ?");
    $stmt->execute([$project_id]);
    
    if (!$stmt->fetch()) {
        $stmt = $db->prepare("INSERT INTO projects_config (project_id, api_key) VALUES (?, ?)");
        $stmt->execute([$project_id, $api_key]);
    }

    // 4. Consumir el servidor de análisis (Equivalente a requests.get)
    $query_params = http_build_query([
        'take' => $take,
        'id_project' => $project_id,
        'api_key' => $api_key
    ]);
    
    $response = file_get_contents($server_url . "?" . $query_params);
    
    if ($response === FALSE) {
        throw new Exception("Error al conectar con el servidor externo.");
    }

    $data_json = json_decode($response, true);

    if (($data_json['status'] ?? '') !== 'success') {
        throw new Exception("El servidor de análisis devolvió un error.");
    }

    // 5. Procesar Sesiones y Mensajes
    $sessions_list = $data_json['data'] ?? [];
    $c_ins = 0;
    $c_upd = 0;

    $db->beginTransaction(); // Iniciamos transacción para proteger la integridad

    foreach ($sessions_list as $s) {
        $meta = $s['meta'] ?? [];
        $metrics = $s['metrics'] ?? [];
        $history = $s['history'] ?? [];

        // Verificar si la sesión ya existe
        $check = $db->prepare("SELECT id FROM sessions WHERE session_id = ?");
        $check->execute([$meta['sessionID']]);
        $exists = $check->fetch();

        if (!$exists) {
            // INSERTAR NUEVA SESIÓN
            $sql_session = "INSERT INTO sessions 
                (id, session_id, project_id, created_at, platform, duration_sec, cost_credits, model_used, tokens_total, is_read, new_messages_count) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)";
            
            $stmt_session = $db->prepare($sql_session);
            $stmt_session->execute([
                $meta['id'],
                $meta['sessionID'],
                $project_id,
                format_date_with_offset($meta['createdAt']),
                $meta['platform'] ?? 'unknown',
                $meta['duration_seconds'] ?? 0,
                $meta['total_cost_credits'] ?? 0,
                $metrics['model_used'] ?? 'n/a',
                $metrics['total_tokens'] ?? 0,
                count($history)
            ]);
            $c_ins++;
        } else {
            // Aquí podrías añadir lógica de UPDATE si fuera necesario
            $c_upd++;
        }

        // INSERTAR MENSAJES (Relacionados por el ID de la sesión)
        // Primero limpiamos mensajes anteriores para evitar duplicados si la sesión se está re-procesando
        $del_msg = $db->prepare("DELETE FROM messages WHERE session_table_id = ?");
        $del_msg->execute([$meta['id']]);

        $sql_msg = "INSERT INTO messages (session_table_id, role, content, timestamp) VALUES (?, ?, ?, ?)";
        $stmt_msg = $db->prepare($sql_msg);

        foreach ($history as $m) {
            $stmt_msg->execute([
                $meta['id'],
                $m['role'],
                $m['content'],
                format_date_with_offset($m['time'])
            ]);
        }
    }

    // Actualizar última sincronización
    $upd_sync = $db->prepare("UPDATE projects_config SET last_sync = NOW() WHERE project_id = ?");
    $upd_sync->execute([$project_id]);

    $db->commit();

    echo json_encode([
        "status" => "success",
        "message" => "Sincronización finalizada",
        "metrics" => [
            "nuevas_sesiones" => $c_ins,
            "sesiones_existentes" => $c_upd,
            "total_procesadas" => $c_ins + $c_upd
        ]
    ]);

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}