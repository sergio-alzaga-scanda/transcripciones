<?php
/**
 * Script para extraer tickets históricos usando Azure OpenAI GPT-4o
 * USO: http://localhost/transcripciones/api/extract_historical_tickets_ia.php?project_id=XYZ&limit=50
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

$project_id = $_GET['project_id'] ?? null;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 1500;

if (!$project_id) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Falta el parámetro project_id"]);
    exit;
}

// Configuración de OpenAI Azure
$azure_endpoint = "https://yuscanopenai.openai.azure.com/openai/deployments/gpt-4o/chat/completions?api-version=2025-01-01-preview";
$azure_api_key = "b5cf6623705b45e1befed28fda1350f7";

$database = new Database();
$db = $database->getConnection();

try {
    // 1. Obtener sesiones del proyecto
    $sqlSessions = "SELECT id, session_id FROM sessions WHERE project_id = :pid";
    $stmtSessions = $db->prepare($sqlSessions);
    $stmtSessions->execute([':pid' => $project_id]);
    $sessions = $stmtSessions->fetchAll(PDO::FETCH_ASSOC);

    if (empty($sessions)) {
        echo json_encode(["status" => "success", "message" => "No se encontraron sesiones para este proyecto.", "procesados" => 0]);
        exit;
    }

    $sessionIds = array_column($sessions, 'id');
    $sessionMap = [];
    foreach ($sessions as $s) {
        $sessionMap[$s['id']] = $s['session_id'];
    }

    // 2. Buscar mensajes candidatos (role = assistant y contiene la palabra 'ticket')
    $inQuery = implode(',', array_fill(0, count($sessionIds), '?'));
    $sqlMessages = "SELECT id, session_table_id, content 
                    FROM messages 
                    WHERE session_table_id IN ($inQuery) 
                    AND role = 'assistant' 
                    AND content LIKE '%ticket%'
                    ORDER BY timestamp DESC
                    LIMIT ?";
    
    $params = $sessionIds;
    $params[] = $limit; // Agregar el límite al final

    $stmtMessages = $db->prepare($sqlMessages);
    $stmtMessages->execute($params);
    $mensajesCandidatos = $stmtMessages->fetchAll(PDO::FETCH_ASSOC);

    if (empty($mensajesCandidatos)) {
        echo json_encode(["status" => "success", "message" => "No se encontraron mensajes candidatos con tickets.", "procesados" => 0]);
        exit;
    }

    $resultados = [
        "mensajes_evaluados" => count($mensajesCandidatos),
        "tickets_encontrados" => 0,
        "tickets_insertados" => 0,
        "tickets_duplicados" => 0,
        "errores" => []
    ];

    // 3. Procesar con IA
    $prompt_base = "Eres un extractor de datos. Tu tarea es encontrar el 'Número de ticket' en el siguiente texto de un chat. 
Es probable que el número tenga espacios entre los números (ej. '6 2 3 1'). 
Debes juntar los números eliminando los espacios para formar el ticket original (ej. '6231').
Vamós a responder estrictamente en formato JSON válido con una propiedad 'ticket'. 
Si no encuentras ningún número de ticket claro, devuelve null.
Ejemplo: {\"ticket\": \"623111\"}";

    foreach ($mensajesCandidatos as $msg) {
        $data = [
            "messages" => [
                ["role" => "system", "content" => $prompt_base],
                ["role" => "user", "content" => $msg['content']]
            ],
            "temperature" => 0.0, // Para respuestas determinísticas
            "response_format" => ["type" => "json_object"]
        ];

        $ch = curl_init($azure_endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "api-key: $azure_api_key"
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code == 200 && $response) {
            $json = json_decode($response, true);
            $bot_reply = $json['choices'][0]['message']['content'] ?? '{}';
            $parsed = json_decode($bot_reply, true);

            if (isset($parsed['ticket']) && !empty($parsed['ticket'])) {
                $numero_ticket = trim((string)$parsed['ticket']);
                $resultados['tickets_encontrados']++;

                // 4. Verificar si ya existe e insertar
                $sqlCheck = "SELECT id FROM tickets WHERE numero_ticket = :ticket LIMIT 1";
                $stmtCheck = $db->prepare($sqlCheck);
                $stmtCheck->execute([':ticket' => $numero_ticket]);
                
                if (!$stmtCheck->fetch()) {
                    // No existe, insertar
                    $real_session_id = $sessionMap[$msg['session_table_id']] ?? '';
                    
                    $sqlInsert = "INSERT INTO tickets (numero_ticket, proyecto, usuario, id_sesion, created_at)
                                  VALUES (:numero_ticket, :proyecto, :usuario, :id_sesion, NOW())";
                    $stmtInsert = $db->prepare($sqlInsert);
                    $stmtInsert->execute([
                        ':numero_ticket' => $numero_ticket,
                        ':proyecto' => $project_id,
                        ':usuario' => 'Generado_IA_Historico',
                        ':id_sesion' => $real_session_id
                    ]);
                    $resultados['tickets_insertados']++;
                } else {
                    $resultados['tickets_duplicados']++;
                }
            }
        } else {
            $resultados['errores'][] = "Error IA en msg ID {$msg['id']} (HTTP $http_code)";
        }
    }

    echo json_encode(["status" => "success", "data" => $resultados]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
