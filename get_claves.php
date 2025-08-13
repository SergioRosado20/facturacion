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
$con->set_charset("utf8mb4");
if($con->connect_error) {
    die("Coneccion fallida: " . $con->connect_error);
}

$json = file_get_contents('php://input');
$data = json_decode($json, true);

$termino = isset($data["termino"]) ? $data["termino"] : null;
$tabla = isset($data["tabla"]) ? $data["tabla"] : null;

$tablasPermitidas = ['claves_unidad', 'claves_ps']; // Agrega aquí otras tablas válidas
if (!in_array($tabla, $tablasPermitidas)) {
    die(json_encode(["error" => "Tabla no permitida.", "tabla" => $tabla, "data" => $data, "json" => $json]));
}

$termino = "%" . $con->real_escape_string($termino) . "%"; // Para LIKE

// Array de claves específicas que quieres buscar
$clavesArray = ['80141605', '80141500', '82121500', '82121908', 'H87', 'E48'];

// Crear placeholders dinámicamente para el IN clause
$placeholders = str_repeat('?,', count($clavesArray) - 1) . '?';

$sql = "SELECT id, clave, descripcion FROM $tabla WHERE clave IN ($placeholders) AND (clave LIKE ? OR descripcion LIKE ?) LIMIT 15";

$stmt = $con->prepare($sql);
if (!$stmt) {
    die(json_encode(["error" => "Error al preparar la consulta: " . $con->error]));
}

// Crear el string de tipos para bind_param
$types = str_repeat("s", count($clavesArray)) . "ss";

// Crear array de parámetros para bind_param (por referencia)
$bindParams = [$types];
foreach ($clavesArray as &$clave) {
    $bindParams[] = &$clave;
}
$bindParams[] = &$termino;
$bindParams[] = &$termino;

// Usar call_user_func_array para bind_param con parámetros dinámicos
call_user_func_array([$stmt, 'bind_param'], $bindParams);
$stmt->execute();
$resultado = $stmt->get_result();

if ($resultado === false) {
    die("Error en la consulta: " . $con->error);
}

$recomendaciones = [];

while ($row = $resultado->fetch_assoc()) {
    $recomendaciones[] = [
        'id' => $row['id'],
        'clave' => $row['clave'],
        'descripcion' => $row['descripcion']
    ];
}

echo json_encode([
    'rows' => $resultado->num_rows,
    'data' => $recomendaciones,
]);

$stmt->close();
$con->close();
?>