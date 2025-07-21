<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

//require_once('vendor/autoload.php');
require_once ('vendor/autoload.php');
require 'constants.php';
require_once "cors.php";
require_once 'log_helper.php';
cors();

date_default_timezone_set('America/Mexico_City');
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$database_host = $_ENV['DATABASE_HOST'] ?? '';
$database_user = $_ENV['DATABASE_USER'] ?? '';
$database_password = $_ENV['DATABASE_PASSWORD'] ?? '';
$database_name = $_ENV['DATABASE_NAME'] ?? '';

$con = new mysqli($database_host, $database_user, $database_password, $database_name);
date_default_timezone_set('America/Mexico_City');
$con->set_charset("utf8");

$rfc = isset($_POST['rfc']) ? $_POST['rfc'] : null;

try {
    $where = " WHERE 1";
    if($rfc){
        $where = " WHERE rfc = '$rfc'";
    }
    $sql = "SELECT id, nombre, rfc, regimen, cp, pais, estado, municipio, ciudad, colonia, calle, num_ext, num_int, principal FROM `cuenta_factura` $where";

    // Ejecutar la consulta (puedes usar tu conexión y método habitual)
    $stmt = $con->prepare($sql);
    $stmt->execute();
    // Obtener el resultado
    $result = $stmt->get_result();
    $stmt->close();

    if ($result) {
        $emisor = [];
        while($row = $result->fetch_assoc()){
            $emisor[] = $row;
        }
        //print_r($emisor);
        echo json_encode([
            'status' => 'success',
            'message' => 'Cuenta encontrada',
            'data' => $emisor,
            'rfc' => $rfc,
            'sql' => $sql,
        ]);
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'No se encontró ningún registro en la tabla token.',
        ]);
    }
} catch (\Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Ha ocurrido un error inesperado.',
        'desc' => $e->getMessage(),
    ]);
}
?>