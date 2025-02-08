<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

//require_once('vendor/autoload.php');
require_once 'vendor/autoload.php';
require_once "cors.php";
cors();

date_default_timezone_set('America/Mexico_City');
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$database_host = $_ENV['DATABASE_HOST'] ?? '';
$database_user = $_ENV['DATABASE_USER'] ?? '';
$database_password = $_ENV['DATABASE_PASSWORD'] ?? '';
$database_name = $_ENV['DATABASE_NAME'] ?? '';

$con = new mysqli($database_host, $database_user, $database_password, $database_name);

$id = isset($_POST["id"]) ? $_POST["id"] : null;
$data = isset($_POST['data']) ? $_POST['data'] : null;
$cant = isset($_POST['cant']) ? intval($_POST['cant']) : 10;
$page = isset($_POST['page']) ? intval($_POST['page']) : 1;
$canceladas = isset($_POST['canceladas']) ? $_POST['canceladas'] : null;

/*$usuario = 'ROBS031020T71';
$password = '@VMnmko74700';*/

$client = new \GuzzleHttp\Client();

/*try {
    $sql = "SELECT `id`, `token`, `creacion` FROM `token` ORDER BY `id` DESC LIMIT 1";

    // Ejecutar la consulta (puedes usar tu conexión y método habitual)
    $stmt = $con->prepare($sql);
    $stmt->execute();
    // Obtener el resultado
    $result = $stmt->get_result();

    if ($result && $row = $result->fetch_assoc()) {
        $id = $row['id'];
        $token = $row['token'];
        $creacion = $row['creacion'];
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
exit;
}*/

try {

    $sql = "SELECT * FROM `cancelaciones` ORDER BY `id` DESC";

    // Ejecutar la consulta (puedes usar tu conexión y método habitual)
    $stmt = $con->prepare($sql);
    $stmt->execute();
    // Obtener el resultado
    $result = $stmt->get_result();

    if ($result && $row = $result->fetch_assoc()) {
        $id = $row['id'];
        $token = $row['token'];
        $creacion = $row['creacion'];
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'No se encontró ningún registro en la tabla token.',
        ]);
    }

    /*$response = $client->request('GET', 'https://testapi.facturoporti.com.mx/servicios/consultar/solicitudespendientescancelacion?rfc=rfc', [
        'headers' => [
            'accept' => 'application/json',
            'authorization' => 'Bearer '.$token,
        ],
    ]);

    //echo $responseToken->getBody()->getContents();
    $data = json_decode($response->getBody()->getContents(), true);
    $codigo = $data['codigo'] ?? '';
    $mensaje = $data['mensaje'] ?? '';
    $array = $data['uuid'] ?? '';

    echo json_encode([
        'codigo' => $codigo,
        'mensaje' => $mensaje,
        'data' => $array,
    ]);*/
    
    //echo $token;
} catch (\Exception $e) {
    echo json_encode([
    'status' => 'error',
    'message' => 'Ha ocurrido un error inesperado.',
    'desc' => $e->getMessage(),
    ]);
exit;
}
?>