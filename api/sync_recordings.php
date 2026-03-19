<?php
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/config/twilio.php';

$account_sid = TWILIO_ACCOUNT_SID;
$auth_token = TWILIO_AUTH_TOKEN;

$recordings_dir = dirname(__DIR__) . '/public/recordings';
if (!is_dir($recordings_dir)) {
    mkdir($recordings_dir, 0777, true);
}

// 1. Obtener la lista de grabaciones
$recordings_url = "https://api.twilio.com/2010-04-01/Accounts/$account_sid/Recordings.json?PageSize=50";

$ch = curl_init($recordings_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERPWD, "$account_sid:$auth_token");
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$res = curl_exec($ch);
$http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_status !== 200 || !$res) {
    die("Error al obtener grabaciones de Twilio: Status $http_status\n");
}

$data = json_decode($res, true);
$recordings = $data['recordings'] ?? [];

$db = (new Database())->getConnection();

$c_downloaded = 0;
$c_matched = 0;

foreach ($recordings as $rec) {
    $recording_sid = $rec['sid'];
    $call_sid = $rec['call_sid'];
    $duration = $rec['duration'];
    $date_created = $rec['date_created']; // Formato rfc2822 "Wed, 18 Mar 2026 12:34:56 +0000"
    
    // Ver si ya existe el archivo
    $file_path = "$recordings_dir/$recording_sid.mp3";
    $relative_path = "public/recordings/$recording_sid.mp3";
    
    // Y verificar en DB
    $stmt = $db->prepare("SELECT id FROM sessions WHERE recording_url = ?");
    $stmt->execute([$relative_path]);
    if ($stmt->fetch()) {
        continue; // Ya sincronizado
    }
    
    // 2. Obtener detalles de la llamada para saber el número telefónico
    $call_url = "https://api.twilio.com/2010-04-01/Accounts/$account_sid/Calls/$call_sid.json";
    $ch_call = curl_init($call_url);
    curl_setopt($ch_call, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_call, CURLOPT_USERPWD, "$account_sid:$auth_token");
    curl_setopt($ch_call, CURLOPT_SSL_VERIFYPEER, false);
    $res_call = curl_exec($ch_call);
    curl_close($ch_call);
    
    if (!$res_call) continue;
    
    $call_data = json_decode($res_call, true);
    if (!isset($call_data['from'])) continue;
    
    $from = $call_data['from'];
    $to = $call_data['to'];
    
    // 3. Buscar la sesión en la base de datos
    // Podría ser el 'From' o el 'To'. Asumimos que la sesión tiene phone format completo (+52...)
    // Y la fecha debe ser del mismo día.
    $rec_time = strtotime($date_created);
    // Convertir el tiempo de UTC a la zona horaria local (-6 horas para Mexico City, como está en DB)
    $rec_time_local = $rec_time - (6 * 3600);
    $rec_date_str = date('Y-m-d', $rec_time_local);
    
    $sql = "SELECT id, session_id, created_at FROM sessions 
            WHERE (session_id = ? OR session_id = ?) 
            AND DATE(created_at) = ? 
            AND recording_url IS NULL 
            LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->execute([$from, $to, $rec_date_str]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($session) {
        // Encontramos la sesión, procedemos a descargar
        if (!file_exists($file_path)) {
            $mp3_url = "https://api.twilio.com/2010-04-01/Accounts/$account_sid/Recordings/$recording_sid.mp3";
            $ch_mp3 = curl_init($mp3_url);
            curl_setopt($ch_mp3, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch_mp3, CURLOPT_USERPWD, "$account_sid:$auth_token");
            curl_setopt($ch_mp3, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch_mp3, CURLOPT_SSL_VERIFYPEER, false);
            $mp3_data = curl_exec($ch_mp3);
            $http_mp3 = curl_getinfo($ch_mp3, CURLINFO_HTTP_CODE);
            curl_close($ch_mp3);
            
            if ($http_mp3 === 200 && $mp3_data) {
                file_put_contents($file_path, $mp3_data);
                $c_downloaded++;
            } else {
                continue; // Error bajando mp3
            }
        }
        
        // Actualizar la sesión
        $update = $db->prepare("UPDATE sessions SET recording_url = ? WHERE id = ?");
        $update->execute([$relative_path, $session['id']]);
        $c_matched++;
    }
}

header('Content-Type: application/json');
echo json_encode([
    "status" => "success", 
    "message" => "Proceso completado", 
    "downloaded" => $c_downloaded, 
    "matched" => $c_matched
]);
