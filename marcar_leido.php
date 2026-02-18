<?php
require_once 'config/db.php';
$db = (new Database())->getConnection();
if (isset($_POST['wa_id'])) {
    $db->prepare("UPDATE sesiones_chat SET no_leido = 0 WHERE wa_id = ?")->execute([$_POST['wa_id']]);
    echo json_encode(["status" => "ok"]);
}
?>