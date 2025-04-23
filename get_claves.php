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
    die(json_encode(["error" => "Tabla no permitida."]));
}

$termino = "%" . $con->real_escape_string($termino) . "%"; // Para LIKE

$sql = "SELECT id, clave, descripcion FROM $tabla WHERE clave LIKE ? OR descripcion LIKE ? LIMIT 15";

$stmt = $con->prepare($sql);
if (!$stmt) {
    die(json_encode(["error" => "Error al preparar la consulta: " . $con->error]));
}

$stmt->bind_param("ss", $termino, $termino);
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