<?php
require 'pdf.php';
require_once 'log_helper.php';
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
$con->set_charset("utf8mb4");

if ($con->connect_error) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Conexión a la base de datos fallida'
    ]);
    exit;
}

function limpiarUtf8($input) {
    if (is_array($input)) {
        foreach ($input as $key => $value) {
            $input[$key] = limpiarUtf8($value);
        }
    } elseif (is_string($input)) {
        if (!mb_check_encoding($input, 'UTF-8')) {
            $input = mb_convert_encoding($input, 'UTF-8', 'ISO-8859-1');
        }
    }
    return $input;
}
function limpiarNombreArchivo($nombre) {
    $nombre = limpiarUtf8($nombre);
    // Reemplaza espacios por guiones bajos y elimina caracteres peligrosos
    $nombre = preg_replace('/[^A-Za-z0-9_\.\-]/', '_', $nombre);
    return $nombre;
}

function verifyRFC($rfc){
    global $con;
    $sql = "SELECT * FROM cuenta_factura WHERE rfc = ?";
    $stmt = $con->prepare($sql);
    $stmt->bind_param('s', $rfc);
    $stmt->execute();
    $result = $stmt->get_result();
    if($result->num_rows > 0){
        return false;
    }
    return true;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $_POST = limpiarUtf8($_POST);
    $id = isset($_POST['id']) ? $_POST['id'] : null;
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
    $principal = $_POST['principal'];

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
        $certificado_nombre = limpiarNombreArchivo($_FILES['certificado']['name']);
        $certificado = $uploads_dir . '/' . $certificado_nombre;
        move_uploaded_file($_FILES['certificado']['tmp_name'], $certificado);
    }

    $llave = '';
    if (isset($_FILES['llave']) && $_FILES['llave']['error'] == UPLOAD_ERR_OK) {
        $llave_nombre = limpiarNombreArchivo($_FILES['llave']['name']);
        $llave = $uploads_dir . '/' . $llave_nombre;
        move_uploaded_file($_FILES['llave']['tmp_name'], $llave);
    }

    if($id){
        // Para actualización, construir la consulta dinámicamente
        $updateFields = [];
        $params = [];
        $types = '';
        
        // Campos que siempre se actualizan
        $updateFields[] = "nombre = ?";
        $updateFields[] = "rfc = ?";
        $updateFields[] = "regimen = ?";
        $updateFields[] = "cp = ?";
        $updateFields[] = "pais = ?";
        $updateFields[] = "estado = ?";
        $updateFields[] = "municipio = ?";
        $updateFields[] = "ciudad = ?";
        $updateFields[] = "colonia = ?";
        $updateFields[] = "calle = ?";
        $updateFields[] = "num_ext = ?";
        $updateFields[] = "num_int = ?";
        $updateFields[] = "principal = ?";

        $params[] = $nombre;
        $params[] = $rfc;
        $params[] = $regimen;
        $params[] = $cp;
        $params[] = $pais;
        $params[] = $estado;
        $params[] = $municipio;
        $params[] = $ciudad;
        $params[] = $colonia;
        $params[] = $calle;
        $params[] = $num_ext;
        $params[] = $num_int;
        $params[] = $principal;
        $types .= 'ssssssssssssi';
        
        // Solo actualizar certificado si se proporciona uno nuevo
        if (!empty($certificado)) {
            $updateFields[] = "certificado = ?";
            $params[] = $certificado;
            $types .= 's';
        }
        
        // Solo actualizar llave si se proporciona una nueva
        if (!empty($llave)) {
            $updateFields[] = "llave = ?";
            $params[] = $llave;
            $types .= 's';
        }
        
        // Solo actualizar contraseña si se proporciona una nueva
        if (!empty($pass)) {
            $updateFields[] = "pass = ?";
            $updateFields[] = "iv = ?";
            $params[] = $cipherText;
            $params[] = $iv_base64;
            $types .= 'ss';
        }
        
        // Agregar el ID al final
        $params[] = $id;
        $types .= 'i';
        
        $sql = "UPDATE cuenta_factura SET " . implode(', ', $updateFields) . " WHERE id = ?";
        $stmt = $con->prepare($sql);
        $stmt->bind_param($types, ...$params);
        
    } else {
        if(!verifyRFC($rfc)){
            echo json_encode(['status' => 'error', 'message' => 'Ya hay una cuenta existente con este RFC']);
            exit;
        }
        $stmt = $con->prepare("INSERT INTO cuenta_factura (nombre, rfc, regimen, cp, pais, estado, municipio, ciudad, colonia, calle, num_ext, num_int, certificado, llave, pass, iv, principal) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('ssssssssssssssssi', $nombre, $rfc, $regimen, $cp, $pais, $estado, $municipio, $ciudad, $colonia, $calle, $num_ext, $num_int, $certificado, $llave, $cipherText, $iv_base64, $principal);
    }

    if ($stmt->execute()) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => $stmt->error]);
    }

    $stmt->close();
    $con->close();
}
?>
