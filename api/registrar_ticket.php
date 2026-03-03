<?php
/**
 * API para registrar tickets
 * Autenticación: Basic Auth  admin_select / sc4nd4_2026!
 * Parámetros GET: numero_ticket, proyecto, usuario, id_sesion
 */

header('Content-Type: application/json');

// --- Autenticación ---
$usuario_valido  = "admin_select";
$password_valido = "sc4nd4_2026!";

if (!isset($_SERVER['PHP_AUTH_USER']) ||
    $_SERVER['PHP_AUTH_USER'] !== $usuario_valido ||
    $_SERVER['PHP_AUTH_PW']   !== $password_valido) {

    header('WWW-Authenticate: Basic realm="Acceso Restringido API"');
    header('HTTP/1.0 401 Unauthorized');
    echo json_encode(["status" => "error", "message" => "Autenticación fallida"]);
    exit;
}

require_once __DIR__ . '/../config/db.php';

// --- Parámetros ---
$numero_ticket = trim($_GET['numero_ticket'] ?? '');
$proyecto      = trim($_GET['proyecto']      ?? '');
$usuario       = trim($_GET['usuario']       ?? '');
$id_sesion     = trim($_GET['id_sesion']     ?? '');

if (!$numero_ticket || !$id_sesion) {
    http_response_code(400);
    echo json_encode([
        "status"  => "error",
        "message" => "Faltan parámetros obligatorios (numero_ticket, id_sesion)"
    ]);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $db->prepare(
        "INSERT INTO tickets (numero_ticket, nombre_proyecto, usuario, id_sesion, created_at)
         VALUES (:numero_ticket, :proyecto, :usuario, :id_sesion, NOW())"
    );
    $stmt->execute([
        ':numero_ticket' => $numero_ticket,
        ':proyecto'      => $proyecto,
        ':usuario'       => $usuario,
        ':id_sesion'     => $id_sesion,
    ]);

    $newId = $db->lastInsertId();

    echo json_encode([
        "status"  => "success",
        "message" => "Ticket registrado correctamente",
        "data"    => [
            "id"            => $newId,
            "numero_ticket" => $numero_ticket,
            "proyecto"      => $proyecto,
            "usuario"       => $usuario,
            "id_sesion"     => $id_sesion
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error de base de datos: " . $e->getMessage()]);
}
