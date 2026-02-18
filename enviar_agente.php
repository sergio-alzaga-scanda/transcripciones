<?php
session_start();
header("Content-Type: application/json");
require_once 'config/db.php';

if (!isset($_SESSION['user'])) {
    echo json_encode(["status" => "error", "message" => "No autorizado"]);
    exit;
}

$db = (new Database())->getConnection();
$project_id = $_SESSION['user']['assigned_project_id'] ?? 1;

if ($_POST) {
    $wa_id_raw = $_POST['wa_id'];
    $mensaje = $_POST['mensaje'];
    
    // Validar por wa_id y project_id
    $stmt_check = $db->prepare("SELECT wa_id FROM sesiones_chat WHERE wa_id = ? AND project_id = ?");
    $stmt_check->execute([$wa_id_raw, $project_id]);
    if (!$stmt_check->fetch()) {
        echo json_encode(["status" => "error", "message" => "Conversación no encontrada"]);
        exit;
    }

      $TOKEN = 'EAAUgv3zzT2wBQvj0yuWkv1rM4ZCQrmh71JnfEEao4k3UFRnavjZAwocKp2wkj1w8iCp6lfTZAvimcoJ8JZA8cBYFoTHDF90cLHzVGw07v8P8gs1igIRGyjUKKXm4Rt26jdh1riSiqfQXKd2ZBaHjoZA1gS4hrum4Y1rNvG2zRrmWNjwxt3ZAqDGuYGOzsTZCCjhOUZCDyiR2Lp7UQTLoWuv1AZBZB2NAZAXt2nkrb7ExexnUQ8C5wDVSbj5CwqZAoY2mzWbI7Srs0SDHWzJHIiP6egO23';
    $PHONE_ID = '1010725265451557';
    $wa_id_api = preg_replace('/^521/', '52', $wa_id_raw);

    $payload = [
        "messaging_product" => "whatsapp",
        "to" => $wa_id_api,
        "type" => "text",
        "text" => ["body" => $mensaje]
    ];

    $ch = curl_init("https://graph.facebook.com/v22.0/{$PHONE_ID}/messages");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "Authorization: Bearer " . $TOKEN]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code == 200) {
        // Registrar en historial con control 'agente' para la alineación derecha
        $stmt_sess = $db->prepare("SELECT is_sesion FROM sesiones_chat WHERE wa_id = ?");
        $stmt_sess->execute([$wa_id_raw]);
        $current_session = $stmt_sess->fetchColumn();

        $db->prepare("INSERT INTO chats (wa_id, nombre, mensaje, tipo, control, is_sesion, project_id) VALUES (?, ?, ?, 'agente', 'agente', ?, ?)")
   ->execute([$wa_id_raw, $_SESSION['user']['username'], $mensaje, $current_session, $project_id]);

        $db->prepare("INSERT INTO chats (wa_id, nombre, mensaje, tipo, control, project_id) VALUES (?, ?, ?, 'agente', 'agente', ?)")
           ->execute([$wa_id_raw, $_SESSION['user']['username'], $mensaje, $project_id]);
        
        $db->prepare("UPDATE sesiones_chat SET ultima_interaccion = NOW(), ultimo_remitente = 'agente', ultimo_mensaje = ? WHERE wa_id = ?")
           ->execute([$mensaje, $wa_id_raw]);
        
        echo json_encode(["status" => "ok"]);
    } else {
        echo json_encode(["status" => "error", "details" => json_decode($res)]);
    }
}