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
    die("Coneccion fallida: " . $con->connect_error);
}

$sql = "SELECT * FROM timbres ORDER BY id DESC LIMIT 1";

$stmt = $con->prepare($sql);
if (!$stmt) {
    die("Error en la preparación de la consulta: " . $con->error);
}

// Ejecutar la consulta
$stmt->execute();
$resultado = $stmt->get_result();

$facturas = array();
$array = array('sql' => $sql);

if ($resultado === false) {
    die("Error en la consulta: " . $con->error);
}

$row = $resultado->fetch_assoc();

echo json_encode([
    'rows' => $resultado->num_rows,
    'data' => $row,
    //'sql' => $sql,
]);

$con->close();
?>