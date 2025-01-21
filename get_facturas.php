<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Content-Type: application/json');
//print_r('Hola');
require '../.env';

$con = new mysqli(DATABASE_HOST, DATABASE_USER, DATABASE_PASSWORD, DATABASE_NAME);

if($con->connect_error) {
    die("Coneccion fallida: " . $con->connect_error);
}

$id = isset($_POST["id"]) ? $_POST["id"] : null;
$data = isset($_POST['data']) ? $_POST['data'] : null;
$cant = isset($_POST['cant']) ? intval($_POST['cant']) : 10;
$page = isset($_POST['page']) ? intval($_POST['page']) : 1;
$canceladas = isset($_POST['canceladas']) ? $_POST['canceladas'] : null;

$offset = ($page - 1) * $cant;

$whereClause = $canceladas ? "status != 1" : "1";

$sql_count = "SELECT COUNT(*) as total FROM facturas WHERE $whereClause";
$stmt_count = $con->prepare($sql_count);
if (!$stmt_count) {
    die("Error en la preparación de la consulta (COUNT): " . $con->error);
}

$stmt_count->execute();
$resultado_count = $stmt_count->get_result();
$row = $resultado_count->fetch_assoc();

$total_registros = $row['total'];

$sql = "SELECT facturas.*, facturas.pedidos, facturas.id as fac_id, facturas.cliente as fac_cliente,
            COALESCE(company.name, 'Factura de mantenimiento') AS nombre,
            company.id as client_id
        FROM facturas 
        LEFT JOIN company ON facturas.cliente = company.id
        WHERE $whereClause";
if($id !== null) {
    $sql .= " AND facturas.id = '". $con->real_escape_string($id) ."'";
}
if ($data !== null) {
    $sql .= ' AND (facturas.id LIKE "%' . $con->real_escape_string($data) . '%" OR facturas.pedidos LIKE "%' . $con->real_escape_string($data) . '%" OR company.name LIKE "%' . $con->real_escape_string($data) . '%")';
}
$sql .= " ORDER BY facturas.id ASC
        LIMIT ? OFFSET ?";

// Preparar la consulta para evitar inyecciones SQL
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
        'client_id' => $row['client_id'],
        'nombre' => $row['nombre'],
        'cliente' => $row['fac_cliente'],
    ];

    // Retirar los datos del cliente de la factura principal
    unset($row['client_id'], $row['nombre'], $row['fac_cliente']); // Actualizar según los campos del cliente

    $row['cliente'] = $cliente;
    $row['productos'] = [];

    // Consulta para obtener productos de cada factura
    $sqlProductos = "SELECT * FROM encabezado_venta WHERE folio = ?";
    $stmtProductos = $con->prepare($sqlProductos);
    $stmtProductos->bind_param("i", $row['fac_id']);
    $stmtProductos->execute();
    $resultadoProductos = $stmtProductos->get_result();

    while ($producto = $resultadoProductos->fetch_assoc()) {
        $row['productos'][] = $producto;
    }

    $facturas[] = $row;
}


// Convertir el array de facturas en formato JSON y mostrarlo
$totalPages = ceil($total_registros / $cant);

echo json_encode([
    'total_registros' => $total_registros,
    'total_pages' => $totalPages,
    'rows' => $resultado->num_rows,
    'data' => $facturas
]);

$con->close();

?>