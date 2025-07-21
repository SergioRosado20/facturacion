<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

//require_once('vendor/autoload.php');
require_once ('vendor/autoload.php');
require 'constants.php';
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
date_default_timezone_set('America/Mexico_City');
$con->set_charset("utf8");
$usuario = 'PruebasTimbrado';
$password = '@Notiene1';

//$usuario = 'HUCJ750513DW7';
//$password = 'AnubisRosadoLopez201306?*';

$client = new \GuzzleHttp\Client();

try {
  $response = $client->request('DELETE', 'https://testapi.facturoporti.com.mx/token/borrar', [
      'body' => '{"usuario":"PruebasTimbrado","password":"@Notiene1"}',
      'headers' => [
          'accept' => 'application/json',
          'content-type' => 'application/*+json',
      ],
  ]);
} catch (\Exception $e) {
  echo json_encode([
  'status' => 'error',
  'message' => 'Ha ocurrido un error inesperado.',
  'desc' => $e->getMessage(),
  ]);
  exit;
}

try {
  $responseToken = $client->request('GET', 'https://testapi.facturoporti.com.mx/token/crear?Usuario='.$usuario.'&Password='.$password.'', [
      'headers' => [
          'accept' => 'application/json',
      ],
  ]);

  //echo $responseToken->getBody()->getContents();
  $tokenData = json_decode($responseToken->getBody()->getContents(), true);
  $token = $tokenData['token'] ?? '';

  $sql = "INSERT INTO `token`(`token`, `creacion`) VALUES (?,NOW())";
  $stmt = $con->prepare($sql);
  if ($stmt === false) {
      throw new Exception("Error en la preparación de la consulta: " . $con->error);
  }

  $fechaActual = date('Y-m-d H:i:s');

  // Vincular parámetros y ejecutar
  $stmt->bind_param("s", $token);
  if (!$stmt->execute()) {
      throw new Exception("Error en la ejecución de la consulta: " . $stmt->error);
  }

  $stmt->close();
  //echo $token;
} catch (\Exception $e) {
  echo json_encode([
  'status' => 'error',
  'message' => 'Ha ocurrido un error inesperado.',
  'desc' => $e->getMessage(),
  ]);
  exit;
}

echo $responseToken->getBody();
?>