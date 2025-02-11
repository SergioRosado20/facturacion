<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

require_once 'vendor/autoload.php';
require_once "cors.php";
cors();

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$database_host = $_ENV['DATABASE_HOST'] ?? '';
$database_user = $_ENV['DATABASE_USER'] ?? '';
$database_password = $_ENV['DATABASE_PASSWORD'] ?? '';
$database_name = $_ENV['DATABASE_NAME'] ?? '';

$con = new mysqli($database_host, $database_user, $database_password, $database_name);

if($con->connect_error) {
    http_response_code(500);
    die(json_encode(["error" => "Conexión fallida: " . $con->connect_error]));
}

$json = file_get_contents('php://input');
$data = json_decode($json, true);

$id = isset($data["id"]) ? $data["id"] : null;
$pago = isset($data['pago']) ? intval($data['pago']) : 0;

if ($id === null) {
    http_response_code(400);
    die(json_encode(["error" => "ID inválido"]));
}

$sql = "UPDATE `facturas` SET `pagado`=? WHERE `id` = ?";
$stmt = $con->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    die(json_encode(["error" => "Error en la preparación de la consulta: " . $con->error]));
}

// Enlazar parámetros
$stmt->bind_param("ii", $pago, $id);

// Ejecutar la consulta
if (!$stmt->execute()) {
    http_response_code(500);
    die(json_encode(["error" => "Error en la ejecución de la consulta: " . $stmt->error]));
}

echo json_encode([
    'status' => 'success',
]);

$stmt->close();
$con->close();
?>