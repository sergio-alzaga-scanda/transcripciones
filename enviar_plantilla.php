<?php
// Este script simula el CURL pero guarda registro en tu BD
header("Content-Type: application/json");
require_once 'config/db.php';

$database = new Database();
$db = $database->getConnection();

// 1. Obtener Token
$stmt = $db->prepare("SELECT valor FROM config_api WHERE llave = 'meta_token'");
$stmt->execute();
// $token = $stmt->fetchColumn();
$token ='EAAUgv3zzT2wBQvDDuYSPJukjAJEF6iIAKZB4Pyl5pFSjpxhWXip8rDOBWk6NofqMlSqFyaTf633naoEwkmjh0GBbXrn7Ga8R0n10R3ESifReOqvKkEYFDzvJcROw6utZCtZAYhTEw5hfRdcvlElfvC38rMCTdMVrZBQKjxD2YbZCJ5gxYVvocJgpZBn6ZANZAWadAv4OLY9qEz7wg86MfNYHPI9uIZB8Y5TLeFHFsLAqBQH8IiBJ5qwf17oFeprUBNZAu67GZAp9wUFx7FIu7ZAqRZAiN';

// DATOS A ENVIAR (Puedes recibirlos por $_POST)
// Si lo usas manualmente, edita estas variables o pásalas por Postman
$wa_id_destino = $_POST['wa_id'] ?? '525650519317'; // Número destino
$nombre_plantilla = $_POST['plantilla'] ?? 'hello_world';
$idioma = $_POST['idioma'] ?? 'en_US';

// Limpieza de número
$wa_id_api = preg_replace('/^521/', '52', $wa_id_destino);

// Payload de Meta (Template)
$payload = [
    "messaging_product" => "whatsapp",
    "to" => $wa_id_api,
    "type" => "template",
    "template" => [
        "name" => $nombre_plantilla,
        "language" => [ "code" => $idioma ]
    ]
];

// Enviar a Meta
$ch = curl_init("https://graph.facebook.com/v22.0/1010725265451557/messages");
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "Authorization: Bearer " . $token]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code == 200) {
    // AQUÍ ESTÁ EL TRUCO: Guardamos manualmente lo que acabamos de enviar
    // Como es una plantilla, guardamos un texto descriptivo
    $texto_historial = "[Plantilla enviada: $nombre_plantilla]";
    
    // 1. Insertar en historial de chat
    $stmt_ins = $db->prepare("INSERT INTO chats (wa_id, nombre, mensaje, tipo, control) VALUES (?, 'Sistema (Meta)', ?, 'bot', 'bot')");
    $stmt_ins->execute([$wa_id_destino, $texto_historial]);

    // 2. Actualizar sesión (Vista previa en lista)
    // Buscamos si existe sesión, si no, la creamos
    $stmt_chk = $db->prepare("SELECT wa_id FROM sesiones_chat WHERE wa_id = ?");
    $stmt_chk->execute([$wa_id_destino]);
    if (!$stmt_chk->fetch()) {
         $db->prepare("INSERT INTO sesiones_chat (wa_id, nombre_usuario, estado, no_leido, ultimo_remitente, ultimo_mensaje) VALUES (?, 'Usuario', 'bot', 0, 'bot', ?)")
            ->execute([$wa_id_destino, $texto_historial]);
    } else {
         $db->prepare("UPDATE sesiones_chat SET ultimo_mensaje = ?, ultima_interaccion = NOW(), ultimo_remitente = 'bot' WHERE wa_id = ?")
            ->execute([$texto_historial, $wa_id_destino]);
    }

    echo json_encode(["status" => "ok", "message" => "Plantilla enviada y guardada"]);
} else {
    echo json_encode(["status" => "error", "details" => json_decode($response)]);
}
?>