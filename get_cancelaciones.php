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

function convert_to_utf8($dataRaw) {
    if (is_array($dataRaw)) {
        return array_map('convert_to_utf8', $dataRaw);
    } elseif (is_string($dataRaw)) {
        return mb_convert_encoding($dataRaw, 'UTF-8', 'UTF-8');
    } else {
        return $dataRaw;
    }
}

$json = file_get_contents('php://input');
$data = json_decode($json, true);
//print_r('Data: '.$json);

$id = isset($data["id"]) ? $data["id"] : null;
$dataInner = isset($data['data']) ? $data['data'] : null;
$cant = isset($data['cant']) ? intval($data['cant']) : null;
$page = isset($data['page']) ? intval($data['page']) : null;

//print_r('Cant: '.$cant);
//print_r('Page: '.$page);
if($cant !== null) {
    $offset = ($page - 1) * $cant;
    $offsetSql = 'LIMIT '.$cant.' OFFSET '.$offset;
} else {
    $offsetSql = '';
}

$sql_count = "SELECT COUNT(*) as total FROM cancelaciones WHERE 1";
$stmt_count = $con->prepare($sql_count);
if (!$stmt_count) {
    die("Error en la preparación de la consulta (COUNT): " . $con->error);
}

$stmt_count->execute();
$resultado_count = $stmt_count->get_result();
$row = $resultado_count->fetch_assoc();

$total_registros = $row['total'];

$sql = "SELECT cancelaciones.*, cancelaciones.folio as folioFac, cancelaciones.pago as folioPago, cancelaciones.id as cancel_id, 
            CASE 
                WHEN cancelaciones.folio IS NOT NULL AND cancelaciones.folio != '' THEN facturas.cliente
                WHEN cancelaciones.pago IS NOT NULL AND cancelaciones.pago != '' THEN factura_del_pago.cliente
                ELSE 'Documento sin cliente'
            END AS nombre,
            CASE 
                WHEN cancelaciones.folio IS NOT NULL AND cancelaciones.folio != '' THEN facturas.total
                WHEN cancelaciones.pago IS NOT NULL AND cancelaciones.pago != '' THEN pagos.importePagado
                ELSE NULL
            END AS total,
            CASE 
                WHEN cancelaciones.folio IS NOT NULL AND cancelaciones.folio != '' THEN 'factura'
                WHEN cancelaciones.pago IS NOT NULL AND cancelaciones.pago != '' THEN 'pago'
                ELSE 'desconocido'
            END AS tipo_documento
        FROM cancelaciones 
        LEFT JOIN 
            facturas ON cancelaciones.folio = facturas.id
        LEFT JOIN 
            pagos ON cancelaciones.pago = pagos.idPago
        LEFT JOIN 
            facturas AS factura_del_pago ON pagos.idFactura = factura_del_pago.id
        WHERE 1";
if($id !== null) {
    $sql .= " AND cancelaciones.id = '". $con->real_escape_string($id) ."'";
}
if ($dataInner !== null && $dataInner !== '') {
    $sql .= ' AND (cancelaciones.id LIKE "%' . $con->real_escape_string($dataInner) . '%" OR clientes_frec.nombre LIKE "%' . $con->real_escape_string($dataInner) . '%")';
}
$sql .= " ORDER BY cancelaciones.id ASC ". $offsetSql;

//print_r($sql);
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
        'nombre' => $row['nombre'],
    ];

    // Retirar los datos del cliente de la factura principal
    unset($row['nombre']); // Actualizar según los campos del cliente

    $row['cliente'] = $cliente;

    $facturas[] = convert_to_utf8($row);
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