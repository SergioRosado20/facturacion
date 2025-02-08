<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');
// Incluir el archivo de la función CORS
include 'cors.php';
include 'pdf.php';
cors();

$json = file_get_contents('php://input');
$data = json_decode($json, true);

$ruta = $data['ruta'];
$id = $data['id'];
$file = $data['file'];

// Aquí va el resto del código para manejar la descarga del archivo XML
$file_path = 'xml/' . $ruta;
if (file_exists($file_path)) {
    // Forzar la descarga del archivo
    if($file == 'xml') {
        header('Content-Type: application/xml');
        header('Content-Disposition: attachment; filename="' . basename($file_path) . '"');
        readfile($file_path);
        exit;
    } else if($file == 'pdf') {
        $pdfBase64 = leerXml($file_path, true, $id);
        echo json_encode([
            'status' => 'success',
            'pdf' => $pdfBase64,
            'idFactura' => $id,
        ]);
    }
} else {
    http_response_code(404);
    echo "Archivo no encontrado.";
}
?>