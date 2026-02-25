<?php
header("Content-Type: application/json");

class ServiceDeskAxo {
    // Configuración Base de Datos
    private $host = "localhost";
    private $port = "3307";
    private $db_name = "voiceFlow";
    private $username = "root";
    private $password = "";
    
    // Configuración API Axo
    private $BASE_URL = "https://servicedesk.grupoaxo.com/api/v3/";
    private $API_KEY = "423CEBBE-E849-4D17-9CA3-CD6AB3319401";

    public $conn;

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";port=" . $this->port . ";dbname=" . $this->db_name, $this->username, $this->password);
            $this->conn->exec("set names utf8");
        } catch(PDOException $exception) {
            echo "Error de conexión: " . $exception->getMessage();
        }
        return $this->conn;
    }

    public function procesarTicket($id_plantilla) {
        $db = $this->getConnection();
        
        // 1. Obtener datos de la tabla plantillas_incidentes
        $query = "SELECT * FROM plantillas_incidentes WHERE id = :id LIMIT 1";
        $stmt = $db->prepare($query);
        $stmt->bindParam(":id", $id_plantilla);
        $stmt->execute();
        $row = $stmt->fetch(PDO_FETCH_ASSOC);

        if (!$row) return ["error" => "Plantilla no encontrada"];

        // 2. CREAR TICKET (POST)
        $payload_crear = [
            "input_data" => [
                "request" => [
                    "subject" => $row['plantilla_incidente'],
                    "description" => $row['descripcion'] . "<br><b>Ticket creado de forma automática por autentificación.</b>",
                    "template" => ["name" => $row['plantilla_incidente']],
                    "category" => ["name" => $row['categoria']],
                    "subcategory" => ["name" => $row['subcategoria']],
                    "item" => ["name" => $row['articulo']],
                    "group" => ["name" => $row['grupo']],
                    "site" => ["name" => $row['sitio']],
                    "request_type" => ["name" => $row['tipo_solicitud']]
                ]
            ]
        ];

        $response_crear = $this->callAPI("POST", "requests", $payload_crear);
        $request_id = $response_crear['request']['id'] ?? null;

        if ($request_id) {
            // 3. CERRAR TICKET (PUT) - Basado en tu imagen
            $payload_cerrar = [
                "input_data" => [
                    "request" => [
                        "closure_info" => [
                            "requester_ack_resolution" => true,
                            "requester_ack_comments" => "Cierre automático por sistema.",
                            "closure_comments" => "Ticket abierto y cerrado automáticamente por autentificación.",
                            "closure_code" => ["name" => "success"]
                        ]
                    ]
                ]
            ];

            $this->callAPI("PUT", "requests/{$request_id}/close", $payload_cerrar);

            // 4. Registrar en tabla de logs
            $log_query = "INSERT INTO tickets_automatizados (id_plantilla_origen, request_id_servicedesk, status_final) VALUES (?, ?, ?)";
            $db->prepare($log_query)->execute([$id_plantilla, $request_id, "Cerrado Automáticamente"]);

            return ["status" => "success", "ticket_id" => $request_id];
        }

        return ["status" => "error", "detalle" => $response_crear];
    }

    private function callAPI($method, $endpoint, $data) {
        $curl = curl_init();
        $url = $this->BASE_URL . $endpoint;
        
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                "authtoken: " . $this->API_KEY,
                "Accept: application/v3.0+json" // Header requerido según tu documentación
            ],
        ]);

        $response = curl_exec($curl);
        curl_close($curl);
        return json_decode($response, true);
    }
}

// Ejecución de la API
if (isset($_GET['id_plantilla'])) {
    $api = new ServiceDeskAxo();
    echo json_encode($api->procesarTicket($_GET['id_plantilla']));
} else {
    echo json_encode(["error" => "Falta id_plantilla"]);
}