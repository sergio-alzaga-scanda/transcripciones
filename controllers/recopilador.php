<?php

// Esto sube dos niveles para llegar a la raíz y entrar a /config/
require_once __DIR__ . '/../../config/db.php';


$database = new Database();
$db = $database->getConnection();

if (!$db) {
    die("No se pudo establecer la conexión a la base de datos.");
}

try {
    
    $query = "SELECT project_id, api_key FROM projects_config";
    $stmt = $db->prepare($query);
    $stmt->execute();
    
    $proyectos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "--- Iniciando Sincronización Automática (" . date('Y-m-d H:i:s') . ") ---\n";
    echo "Proyectos encontrados: " . count($proyectos) . "\n\n";

    foreach ($proyectos as $proyecto) {
        $id = $proyecto['project_id'];
        $key = $proyecto['api_key'];
        $take = 100; // Valor por defecto igual al de tu app.js

        // URL del endpoint según el archivo app.js proporcionado
        $apiUrl = "http://158.23.137.150:8085/api/info_mensaje.php?take=$take&id_project=$id&api_key=$key";

        echo "Sincronizando: $id ... ";

        // 2. Ejecutar la petición usando cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 45); // Tiempo de espera razonable

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            echo "ERROR cURL: " . curl_error($ch) . "\n";
        } else {
            $data = json_decode($response, true);
            
            // Validamos éxito según la lógica de tu app.js (status 'success' o 'completed')
            if ($httpCode == 200 && isset($data['status']) && ($data['status'] === 'success' || $data['status'] === 'completed')) {
                
                // 3. Actualizar last_sync en la tabla projects_config
                $updateQuery = "UPDATE projects_config SET last_sync = NOW() WHERE project_id = :id";
                $updateStmt = $db->prepare($updateQuery);
                $updateStmt->bindParam(':id', $id);
                $updateStmt->execute();

                echo "OK (Mensaje: " . ($data['message'] ?? 'Completado') . ")\n";
            } else {
                echo "FALLÓ (Código: $httpCode | Error: " . ($data['error'] ?? $data['message'] ?? 'Respuesta inválida') . ")\n";
            }
        }
        curl_close($ch);
    }

    echo "\n--- Proceso terminado ---\n";

} catch (PDOException $e) {
    echo "Error en la consulta: " . $e->getMessage();
}