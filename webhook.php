<?php
require_once 'config/db.php';
date_default_timezone_set('America/Mexico_City');

$database = new Database();
$db = $database->getConnection();

// --- REGLA 1: CIERRE POR ABANDONO (10 MINUTOS) ---
// Liberar agentes y cerrar sesiones automáticamente si no hay actividad
$db->query("UPDATE agentes SET estado = 'disponible' WHERE estado = 'ocupado' AND wa_id IN (SELECT agente_asignado FROM sesiones_chat WHERE estado = 'agente' AND ultima_interaccion < DATE_SUB(NOW(), INTERVAL 10 MINUTE))");
$db->query("UPDATE sesiones_chat SET estado = 'cerrado', agente_asignado = NULL WHERE estado = 'agente' AND ultima_interaccion < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");

// --- PROCESAMIENTO DE MENSAJES ---
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (isset($data['entry'][0]['changes'][0]['value']['messages'][0])) {
    $value = $data['entry'][0]['changes'][0]['value'];
    $msg = $value['messages'][0];
    $wa_id = $msg['from'];
    $nombre = $value['contacts'][0]['profile']['name'] ?? 'Usuario';
    $mensaje_texto = $msg['text']['body'] ?? "[Multimedia]";

    // 1. BUSCAR SESIÓN ACTUAL
    $stmt_s = $db->prepare("SELECT estado, is_sesion FROM sesiones_chat WHERE wa_id = ?");
    $stmt_s->execute([$wa_id]);
    $sesion = $stmt_s->fetch(PDO::FETCH_ASSOC);

    /**
     * REGLA DE ORO: Si no hay sesión o la que existe está cerrada, 
     * forzamos un nuevo registro de is_sesion.
     */
    if (!$sesion || $sesion['estado'] == 'cerrado') {
        // Generar un ID de sesión único (Ej: SESS-20260210-A1B2)
        $current_session_id = "SESS-" . date('YmdHis') . "-" . strtoupper(bin2hex(random_bytes(2)));
        
        if (!$sesion) {
            $db->prepare("INSERT INTO sesiones_chat (wa_id, nombre_usuario, estado, is_sesion, ultimo_mensaje, project_id) VALUES (?, ?, 'bot', ?, ?, 1)")
               ->execute([$wa_id, $nombre, $current_session_id, $mensaje_texto]);
        } else {
            // Se actualiza el registro principal para que el panel detecte la nueva sesión activa
            $db->prepare("UPDATE sesiones_chat SET estado = 'bot', is_sesion = ?, ultimo_mensaje = ?, ultima_interaccion = NOW(), no_leido = 1 WHERE wa_id = ?")
               ->execute([$current_session_id, $mensaje_texto, $wa_id]);
        }
        $control_actual = 'bot';
    } else {
        // Si hay una sesión abierta (bot o agente), continuamos sobre ella
        $current_session_id = $sesion['is_sesion'];
        $control_actual = $sesion['estado'];
        
        $db->prepare("UPDATE sesiones_chat SET ultima_interaccion = NOW(), ultimo_mensaje = ?, no_leido = 1, ultimo_remitente = 'usuario' WHERE wa_id = ?")
           ->execute([$mensaje_texto, $wa_id]);
    }

    // 2. GUARDAR MENSAJE EN CHATS (Siempre vinculado al is_sesion actual)
    insertarMensaje($db, $wa_id, $nombre, $mensaje_texto, 'usuario', 'bot', $current_session_id);

    // 3. Lógica de transferencia
    $input_clean = strtolower(trim($mensaje_texto));
    if ($control_actual == 'bot' && (strpos($input_clean, 'agente') !== false || strpos($input_clean, 'transferir') !== false)) {
        
        $stmt_ag = $db->prepare("SELECT wa_id, nombre FROM agentes WHERE estado = 'disponible' LIMIT 1");
        $stmt_ag->execute();
        $agente = $stmt_ag->fetch(PDO::FETCH_ASSOC);

        if ($agente) {
            $db->prepare("UPDATE agentes SET estado = 'ocupado' WHERE wa_id = ?")->execute([$agente['wa_id']]);
            $db->prepare("UPDATE sesiones_chat SET estado = 'agente', agente_asignado = ? WHERE wa_id = ?")
               ->execute([$agente['wa_id'], $wa_id]);
            
            insertarMensaje($db, $wa_id, 'Sistema', "Control cedido a: " . $agente['nombre'], 'sistema', 'agente', $current_session_id);
            exit;
        }
    }

    // Respuesta del Bot por defecto
    if ($control_actual == 'bot') {
        $resp_bot = "Hola " . $nombre . ". Recibí tu mensaje. Escribe 'agente' si necesitas hablar con un técnico.";
        insertarMensaje($db, $wa_id, 'Bot', $resp_bot, 'bot', 'bot', $current_session_id);
    }
}

function insertarMensaje($db, $wa_id, $nom, $msg, $tipo, $ctrl, $sess_id) {
    if (empty($msg)) return;
    $db->prepare("INSERT INTO chats (wa_id, nombre, mensaje, tipo, control, is_sesion, project_id) VALUES (?, ?, ?, ?, ?, ?, 1)")
       ->execute([$wa_id, $nom, $msg, $tipo, $ctrl, $sess_id]);
}
?>