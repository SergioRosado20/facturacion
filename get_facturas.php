<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

require_once 'vendor/autoload.php';
require_once "cors.php";
require_once "log_helper.php";
cors();

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$database_host = $_ENV['DATABASE_HOST'] ?? '';
$database_user = $_ENV['DATABASE_USER'] ?? '';
$database_password = $_ENV['DATABASE_PASSWORD'] ?? '';
$database_name = $_ENV['DATABASE_NAME'] ?? '';

$con = new mysqli($database_host, $database_user, $database_password, $database_name);
$con->set_charset("utf8mb4");
if($con->connect_error) {
    die("Coneccion fallida: " . $con->connect_error);
}

$json = file_get_contents('php://input');
$data = json_decode($json, true);

logToFile('', '', 'Datos recibidos para obtener facturas: '.$json, "success", $json);

$id = isset($data["id"]) ? $data["id"] : null;
$data_inner = isset($data['data']) ? $data['data'] : null; // Cambié la variable a $data_inner para evitar la sobrescritura
$cant = isset($data['cant']) ? intval($data['cant']) : null;
$page = isset($data['page']) ? intval($data['page']) : 1;
$canceladas = isset($data['canceladas']) ? filter_var($data['canceladas'], FILTER_VALIDATE_BOOLEAN) : null; // Usa filter_var para asegurar que sea booleano
$vigentes = isset($data['vigentes']) ? filter_var($data['vigentes'], FILTER_VALIDATE_BOOLEAN) : null; 
$ppd = isset($data['ppd']) ? filter_var($data['ppd'], FILTER_VALIDATE_BOOLEAN) : null; // Usa filter_var para asegurar que sea booleano
//print_r('Canceladas: '.$canceladas);
//print_r('cant: '.$cant);

if($cant !== null) {
    $offset = ($page - 1) * $cant;
    $offsetSql = 'LIMIT '.$cant.' OFFSET '.$offset;
} else {
    $offsetSql = '';
}

$whereClause = ' 1 ';
$whereClause .= $canceladas ? " AND status != 1" : null;
$whereClause .= $ppd ? " AND metodoPago = 'PPD'" : null;
$whereClause .= $vigentes ? " AND status = 1" : null;

$sql_count = "SELECT COUNT(*) as total FROM facturas WHERE $whereClause";
$stmt_count = $con->prepare($sql_count);
if (!$stmt_count) {
    die("Error en la preparación de la consulta (COUNT): " . $con->error);
}

$stmt_count->execute();
$resultado_count = $stmt_count->get_result();
$row = $resultado_count->fetch_assoc();

$total_registros = $row['total'];

/*SELECT facturas.*, facturas.pedidos, facturas.id as fac_id, facturas.cliente as fac_cliente, cancelaciones.id as cancel_id, cancelaciones.esCancelable, cancelaciones.estado, cancelaciones.estatusCancelacion, cancelaciones.fecha_creacion as fecha_cancelacion,
            COALESCE(company.name, 'Factura de mantenimiento') AS nombre,
            company.id as client_id
        FROM facturas 
        LEFT JOIN company ON facturas.cliente = company.id
        LEFT JOIN cancelaciones ON facturas.id = cancelaciones.folio
        WHERE $whereClause*/
$sql = "SELECT facturas.*, facturas.pedidos, facturas.id as fac_id, facturas.cliente as fac_cliente, cancelaciones.id as cancel_id, cancelaciones.esCancelable, cancelaciones.estado, cancelaciones.estatusCancelacion, cancelaciones.fecha_creacion as fecha_cancelacion,
            COALESCE(facturas.cliente, 'Factura de mantenimiento') AS nombre
        FROM facturas 
        LEFT JOIN cancelaciones ON facturas.id = cancelaciones.folio
        WHERE $whereClause";
if($id !== null) {
    $sql .= " AND facturas.id = '". $con->real_escape_string($id) ."'";
}
if ($data_inner !== null) {
    $sql .= ' AND (facturas.id LIKE "%' . $con->real_escape_string($data_inner) . '%" OR facturas.cliente LIKE "%' . $con->real_escape_string($data_inner) . '%" OR facturas.uuid LIKE "%' . $con->real_escape_string($data_inner) . '%")';
}

$order = $canceladas ? " ORDER BY cancelaciones.id ASC ". $offsetSql : " ORDER BY facturas.id ASC ". $offsetSql;
$sql .= $order;

// Preparar la consulta para evitar inyecciones SQL
$stmt = $con->prepare($sql);
if (!$stmt) {
    die("Error en la preparación de la consulta: " . $con->error);
}

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
        //'client_id' => $row['client_id'],
        'nombre' => $row['nombre'],
        'cliente' => $row['fac_cliente'],
    ];

    // Retirar los datos del cliente de la factura principal
    unset($row['client_id'], $row['nombre'], $row['fac_cliente']); // Actualizar según los campos del cliente

    $row['cliente'] = $cliente;
    $row['productos'] = [];

    // Consulta para obtener productos de cada factura
    /*$sqlProductos = "SELECT * FROM encabezado_venta WHERE folio = ?";
    $stmtProductos = $con->prepare($sqlProductos);
    $stmtProductos->bind_param("i", $row['fac_id']);
    $stmtProductos->execute();
    $resultadoProductos = $stmtProductos->get_result();

    while ($producto = $resultadoProductos->fetch_assoc()) {
        $row['productos'][] = $producto;
    }*/

    $facturas[] = $row;
}

// Convertir el array de facturas en formato JSON y mostrarlo
if($cant !== null) {
    $totalPages = ceil($total_registros / $cant);
} else {
    $totalPages = 0;
}

echo json_encode([
    'total_registros' => $total_registros,
    'total_pages' => $totalPages,
    'rows' => $resultado->num_rows,
    'data' => $facturas,
    //'sql' => $sql,
    'canceladas' => $canceladas,
    //'sql' => $sql
]);

$con->close();

?>