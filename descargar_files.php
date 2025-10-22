<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');
// Incluir el archivo de la función CORS
include 'cors.php';
include 'pdfFinezza.php';
require_once 'constants.php';
require_once 'log_helper.php';
cors();

$json = file_get_contents('php://input');
$data = json_decode($json, true);

$ruta = $data['ruta'];
$id = $data['id'];
$file = $data['file'];
$tipo = $data['tipo'];

$datos = [
    'ruta' => $ruta,
    'id' => $id,
    'file' => $file,
    'tipo' => $tipo,
];
logToFile('0', '0', 'Informacion recibida descargar_files.php', json_encode($datos));
// Aquí va el resto del código para manejar la descarga del archivo XML
$file_path = 'xml/' . $ruta;
if (file_exists($file_path)) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();

    $database_host = $_ENV['DATABASE_HOST'] ?? '';
    $database_user = $_ENV['DATABASE_USER'] ?? '';
    $database_password = $_ENV['DATABASE_PASSWORD'] ?? '';
    $database_name = $_ENV['DATABASE_NAME'] ?? '';
    $enc_key = $_ENV['ENCRYPT_KEY'] ?? '';

    $con = new mysqli($database_host, $database_user, $database_password, $database_name);
    $con->set_charset("utf8mb4");

    if($tipo == 'factura') {
        $sql = "SELECT emisor, pais_receptor, estado_receptor, municipio_receptor, ciudad_receptor, colonia_receptor, manzana_receptor, num_ext_receptor, num_int_receptor, calle_receptor, cp_receptor
                FROM `facturas` WHERE id = ?";
    } else if($tipo == 'nota') {
        $sql = "SELECT f.emisor, f.pais_receptor, f.estado_receptor, f.municipio_receptor, f.ciudad_receptor, f.colonia_receptor, f.manzana_receptor, f.num_ext_receptor, f.num_int_receptor, f.calle_receptor, f.cp_receptor
                FROM `notas_pagos`
                INNER JOIN `facturas` f ON f.id = `notas_pagos`.idFactura
                WHERE `notas_pagos`.idNotaPago = ?";
    } else if($tipo == 'pago') {
        $sql = "SELECT f.emisor, f.pais_receptor, f.estado_receptor, f.municipio_receptor, f.ciudad_receptor, f.colonia_receptor, f.manzana_receptor, f.num_ext_receptor, f.num_int_receptor, f.calle_receptor, f.cp_receptor
                FROM `pagos`
                INNER JOIN `facturas` f ON f.id IN (SELECT idFactura FROM `pagos` WHERE idPago = ?)";
    }
    $stmt = $con->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $receptorBD = $row;
    
    // Determinar el prefijo según el tipo
    $prefijo = '';
    if($tipo == 'factura') {
        $prefijo = 'F';
    } else if($tipo == 'pago') {
        $prefijo = 'P';
    } else if($tipo == 'nota') {
        $prefijo = 'N';
    }
    
    // Formatear el ID con 10 dígitos
    $idFormateado = str_pad($id, 10, '0', STR_PAD_LEFT);
    $nuevoNombre = $prefijo . $idFormateado;
    
    // Forzar la descarga del archivo
    if($file == 'xml') {
        header('Content-Type: application/xml');
        header('Content-Disposition: attachment; filename="' . $nuevoNombre . '.xml"');
        readfile($file_path);
        exit;
    } else if($file == 'pdf') {
        $pdfBase64 = leerXml($file_path, true, $id, $receptorBD['emisor'], $receptorBD['pais_receptor'], $receptorBD['estado_receptor'], $receptorBD['municipio_receptor'], $receptorBD['ciudad_receptor'], $receptorBD['colonia_receptor'], $receptorBD['num_ext_receptor'], $receptorBD['num_int_receptor'], $receptorBD['calle_receptor'], $receptorBD['cp_receptor'], $receptorBD['manzana_receptor']);
        echo json_encode([
            'status' => 'success',
            'pdf' => $pdfBase64,
            'idFactura' => $id,
            'filename' => $nuevoNombre . '.pdf'
        ]);
    }
} else {
    http_response_code(404);
    echo "Archivo no encontrado.";
}
?>