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

if($con->connect_error) {
    die("Coneccion fallida: " . $con->connect_error);
}

$json = file_get_contents('php://input');
$data = json_decode($json, true);

$id = isset($data["id"]) ? $data["id"] : null;
$cant = isset($data['cant']) ? intval($data['cant']) : 10;
$page = isset($data['page']) ? intval($data['page']) : 1;
$data_inner = isset($data['data']) ? $data['data'] : null;

$offset = ($page - 1) * $cant;
$whereClause = ' 1 ';

$sql_count = "SELECT COUNT(*) as total FROM notas_pagos WHERE $whereClause";
$stmt_count = $con->prepare($sql_count);
if (!$stmt_count) {
    die("Error en la preparación de la consulta (COUNT): " . $con->error);
}

$stmt_count->execute();
$resultado_count = $stmt_count->get_result();
$row = $resultado_count->fetch_assoc();

$total_registros = $row['total'];

$sql = "SELECT notas_pagos.*, notas_pagos.uuid as uuidNota, notas_pagos.fecha as fechaNota, facturas.*, facturas.uuid as uuidFactura, facturas.fecha as fechaFactura
        FROM notas_pagos
        INNER JOIN facturas ON notas_pagos.idFactura = facturas.id
        WHERE $whereClause";
if($id !== null) {
    $sql .= " AND notas_pagos.idNotaPago = '". $con->real_escape_string($id) ."'";
}
if ($data_inner !== null) {
    $sql .= ' AND (notas_pagos.idNotaPago LIKE "%' . $con->real_escape_string($data_inner) . '%" OR facturas.cliente LIKE "%' . $con->real_escape_string($data_inner) . '%" OR company.name LIKE "%' . $con->real_escape_string($data_inner) . '%")';
}

$sql .= " ORDER BY notas_pagos.idNotaPago ASC LIMIT ? OFFSET ?";

$stmt = $con->prepare($sql);
if (!$stmt) {
    die("Error en la preparación de la consulta: " . $con->error);
}

// Enlazar parámetros
$stmt->bind_param("ii", $cant, $offset);

// Ejecutar la consulta
$stmt->execute();
$resultado = $stmt->get_result();

$facturas = array();
$array = array('sql' => $sql);

if ($resultado === false) {
    die("Error en la consulta: " . $con->error);
}

// Iterar sobre las facturas y construir los subarrays de cliente y productos
while ($row = $resultado->fetch_assoc()) {
    // Separar los datos del cliente en un subarray
    $cliente = [
        'cliente' => $row['cliente'],
    ];

    // Retirar los datos del cliente de la factura principal
    unset($row['client_id'], $row['nombre'], $row['cliente']); // Actualizar según los campos del cliente

    $row['cliente'] = $cliente;

    $facturas[] = $row;
}


// Convertir el array de facturas en formato JSON y mostrarlo
$totalPages = ceil($total_registros / $cant);

echo json_encode([
    'total_registros' => $total_registros,
    'total_pages' => $totalPages,
    'rows' => $resultado->num_rows,
    'data' => $facturas,
    //'sql' => $sql,
]);

$con->close();
?>