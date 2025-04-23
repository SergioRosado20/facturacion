<?php
require 'pdf.php';
require 'log_helper.php';
require_once('vendor/autoload.php');
require_once "cors.php";
cors();

date_default_timezone_set('America/Mexico_City');
session_start();

$client = new \GuzzleHttp\Client();
use PhpOffice\PhpSpreadsheet\IOFactory;

$username = $_SESSION['username'];

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$database_host = $_ENV['DATABASE_HOST'] ?? '';
$database_user = $_ENV['DATABASE_USER'] ?? '';
$database_password = $_ENV['DATABASE_PASSWORD'] ?? '';
$database_name = $_ENV['DATABASE_NAME'] ?? '';
$enc_key = $_ENV['ENCRYPT_KEY'] ?? '';

$con = new mysqli($database_host, $database_user, $database_password, $database_name);
$con->set_charset("utf8mb4");

function limpiarUtf8($input) {
    if (is_array($input)) {
        foreach ($input as $key => $value) {
            $input[$key] = limpiarUtf8($value); // llamada recursiva
        }
    } elseif (is_string($input)) {
        // Solo si no está codificado en UTF-8
        if (!mb_check_encoding($input, 'UTF-8')) {
            $input = mb_convert_encoding($input, 'UTF-8', 'ISO-8859-1');
        }
    } // Si no es string ni array (es int, float, bool, null, etc), lo deja tal cual

    //print_r('Campo: '.$key.' - Valor: '.$input);
    //echo nl2br('Campo: '.$key.' - Valor: '.$input.'\n');
    return $input;
}

// Ruta del archivo Excel
$archivoExcel = 'claves_unidad.xlsx';

try {
    $spreadsheet = IOFactory::load($archivoExcel);
    $hoja = $spreadsheet->getActiveSheet();

    $fila = 1; // Comenzamos desde la fila 1
    while (true) {
        $clave = trim($hoja->getCell("A{$fila}")->getValue());
        $descripcion = trim($hoja->getCell("B{$fila}")->getValue());

        // Rompemos si la clave está vacía
        if ($clave === null || $clave === '') break;

        $clave = limpiarUtf8(strtoupper($clave));
        $descripcion = limpiarUtf8($descripcion);

        $stmt = $con->prepare("INSERT INTO claves_unidad (clave, descripcion) VALUES (?, ?)");
        if ($stmt) {
            $stmt->bind_param("ss", $clave, $descripcion);
            $stmt->execute();
            $stmt->close();
            $registros++;
        } else {
            echo "Error al preparar la consulta: " . $con->error . "<br>";
        }

        $fila++;
    }

    echo "Importación completa. Se insertaron " . ($fila - 1) . " registros.";
} catch (Exception $e) {
    die("Error al procesar el archivo: " . $e->getMessage());
}
