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
$con->set_charset("utf8");

if($con->connect_error) {
    die("Coneccion fallida: " . $con->connect_error);
}

$json = file_get_contents('php://input');
$data = json_decode($json, true);

$id = isset($data["id"]) ? $data["id"] : null;
$cant = isset($data['cant']) ? intval($data['cant']) : 10;
$page = isset($data['page']) ? intval($data['page']) : 1;
$data_inner = isset($data['data']) ? $data['data'] : null;
$vista = isset($data['vista']) ? $data['vista'] : 'pagos';

$offset = ($page - 1) * $cant;
$whereClause = ' 1 ';

if($vista == 'pagos'){
    $sql_count = "SELECT COUNT(*) as total FROM pagos WHERE $whereClause";
    $stmt_count = $con->prepare($sql_count);
    if (!$stmt_count) {
        die("Error en la preparación de la consulta (COUNT): " . $con->error);
    }

    $stmt_count->execute();
    $resultado_count = $stmt_count->get_result();
    $row = $resultado_count->fetch_assoc();

    $total_registros = $row['total'];


    $sql = "SELECT pagos.*, pagos.uuid as uuidPago, pagos.fecha as fechaPago, pagos.rutaXml as pagoXml, pagos.status as statusPago, facturas.*, facturas.uuid as uuidFactura, facturas.fecha as fechaFactura
            FROM pagos
            INNER JOIN facturas ON pagos.idFactura = facturas.id
            WHERE $whereClause";
    if($id !== null) {
        $sql .= " AND pagos.id = '". $con->real_escape_string($id) ."'";
    }
    if ($data_inner !== null) {
        $sql .= ' AND (pagos.id LIKE "%' . $con->real_escape_string($data_inner) . '%" OR pagos.pedidos LIKE "%' . $con->real_escape_string($data_inner) . '%" OR facturas.cliente LIKE "%' . $con->real_escape_string($data_inner) . '%" OR company.name LIKE "%' . $con->real_escape_string($data_inner) . '%")';
    }

    $sql .= " ORDER BY pagos.idPago ASC LIMIT ? OFFSET ?";
} else if($vista == 'facturas'){

    $sql = "SELECT * FROM pagos_facturas WHERE $whereClause";

    $sql_count = "SELECT COUNT(*) as total FROM pagos_facturas WHERE $whereClause";
    $stmt_count = $con->prepare($sql_count);
    if (!$stmt_count) {
        die("Error en la preparación de la consulta (COUNT): " . $con->error);
    }

    $stmt_count->execute();
    $resultado_count = $stmt_count->get_result();
    $row = $resultado_count->fetch_assoc();

    $total_registros = $row['total'];


    $sql = "SELECT pagos_facturas.*, pagos.fecha as fechaPago, facturas.cliente, facturas.total, pagos.rutaXml as pagoXml, pagos.status as statusPago
            FROM pagos_facturas
            INNER JOIN facturas ON pagos_facturas.fkFactura = facturas.id
            INNER JOIN pagos ON pagos_facturas.fkPago = pagos.idPago
            WHERE $whereClause";
    if($id !== null) {
        $sql .= " AND pagos_facturas.id = '". $con->real_escape_string($id) ."'";
    }
    if ($data_inner !== null) {
        $sql .= ' AND (pagos_facturas.id LIKE "%' . $con->real_escape_string($data_inner) . '%" OR pagos.idPago LIKE "%' . $con->real_escape_string($data_inner) . '%" OR pagos_facturas.fkFactura LIKE "%' . $con->real_escape_string($data_inner) . '%" )';
    }

    $sql .= " ORDER BY pagos_facturas.id ASC LIMIT ? OFFSET ?";
}

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