<?php
require 'pdf.php';
require 'log_helper.php';
require_once('vendor/autoload.php');
require_once "cors.php";
cors();

date_default_timezone_set('America/Mexico_City');
session_start();

require_once('vendor/autoload.php');


$client = new \GuzzleHttp\Client();

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$database_host = $_ENV['DATABASE_HOST'] ?? '';
$database_user = $_ENV['DATABASE_USER'] ?? '';
$database_password = $_ENV['DATABASE_PASSWORD'] ?? '';
$database_name = $_ENV['DATABASE_NAME'] ?? '';
$key = $_ENV['ENCRYPT_KEY'] ?? '';

$con = new mysqli($database_host, $database_user, $database_password, $database_name);

if ($con->connect_error) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Conexión a la base de datos fallida'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nombre = $_POST['nombre'];
    $rfc = $_POST['rfc'];
    $regimen = $_POST['regimen'];
    $cp = $_POST['cp'];
    $pais = $_POST['pais'];
    $estado = $_POST['estado'];
    $municipio = $_POST['municipio'];
    $ciudad = $_POST['ciudad'];
    $colonia = $_POST['colonia'];
    $calle = $_POST['calle'];
    $num_ext = $_POST['num_ext'];
    $num_int = $_POST['num_int'];
    $pass = $_POST['pass'];

    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $cipherText = openssl_encrypt($pass, 'aes-256-cbc', $key, 0, $iv);

    // Convertir el IV en formato legible para almacenarlo
    $iv_base64 = base64_encode($iv);


    // Manejar la carga de archivos
    $uploads_dir = 'sellos';
    if (!is_dir($uploads_dir)) {
        mkdir($uploads_dir, 0755, true);
    }

    $certificado = '';
    if (isset($_FILES['certificado']) && $_FILES['certificado']['error'] == UPLOAD_ERR_OK) {
        $certificado = $uploads_dir . '/' . basename($_FILES['certificado']['name']);
        move_uploaded_file($_FILES['certificado']['tmp_name'], $certificado);
    }

    $llave = '';
    if (isset($_FILES['llave']) && $_FILES['llave']['error'] == UPLOAD_ERR_OK) {
        $llave = $uploads_dir . '/' . basename($_FILES['llave']['name']);
        move_uploaded_file($_FILES['llave']['tmp_name'], $llave);
    }

    $stmt = $con->prepare("INSERT INTO cuenta_factura (nombre, rfc, regimen, cp, pais, estado, municipio, ciudad, colonia, calle, num_ext, num_int, certificado, llave, pass, iv) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('ssssssssssssssss', $nombre, $rfc, $regimen, $cp, $pais, $estado, $municipio, $ciudad, $colonia, $calle, $num_ext, $num_int, $certificado, $llave, $cipherText, $iv_base64);

    if ($stmt->execute()) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => $stmt->error]);
    }

    $stmt->close();
    $con->close();
}
?>
