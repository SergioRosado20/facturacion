<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

require_once '../../../vendor/autoload.php';
require_once "../../../cors.php";
require_once "DashboardService.php";
require_once dirname(__DIR__, 3) . '/log_helper.php';

cors();

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__, 3)); // /api/features/dashboard -> facturacion
$dotenv->load();

$database_host = $_ENV['DATABASE_HOST'] ?? '';
$database_user = $_ENV['DATABASE_USER'] ?? '';
$database_password = $_ENV['DATABASE_PASSWORD'] ?? '';
$database_name = $_ENV['DATABASE_NAME'] ?? '';

// Conexión
$con = new mysqli($database_host, $database_user, $database_password, $database_name);
$con->set_charset("utf8mb4");

if ($con->connect_error) {
    echo json_encode(['error' => true, 'message' => "Conexión fallida: " . $con->connect_error]);
    exit;
}

// Leer el JSON POST o GET
$json = file_get_contents('php://input');
$data = json_decode($json, true) ?? [];

// Unificar POST y GET parameters
$requestData = array_merge($_GET, $data);

$view = $requestData['view'] ?? null;

if ($view === 'dashboard/billing') {
    // Extraer filtros que mande el frontend
    $filters = [
        'customer_rfc' => $requestData['customer_rfc'] ?? null,  // FIX: was 'cliente'
        'created_by'   => $requestData['created_by']  ?? null,
        'fechaInicio'  => $requestData['fechaInicio']  ?? null,
        'fechaFin'     => $requestData['fechaFin']     ?? null
    ];

    // LOG: raw request + resolved filters
    logToFile(
        'dashboard_api',
        'n/a',
        '[dashboard/index.php] Raw request body',
        'Filters resolved',
        'RAW: ' . $json . ' | FILTERS: ' . json_encode($filters)
    );

    try {
        $service = new DashboardService($con);
        $dashboardData = $service->getDashboardData($filters);

        // LOG: confirm data returned without error
        logToFile(
            'dashboard_api',
            'n/a',
            '[dashboard/index.php] getDashboardData completed',
            'OK',
            'customer_rfc=' . ($filters['customer_rfc'] ?? 'NULL')
            . ' | totales.monto_pendiente=' . ($dashboardData['totales']['monto_pendiente'] ?? 'N/A')
        );

        echo json_encode([
            'error' => false,
            'message' => 'Success',
            'data' => $dashboardData
        ]);
        
    } catch (Exception $e) {
        logToFile('dashboard_api', 'n/a', '[dashboard/index.php] EXCEPTION', $e->getMessage(), '');
        echo json_encode([
            'error' => true,
            'message' => 'Error al procesar el dashboard: ' . $e->getMessage()
        ]);
    }

} else {
    echo json_encode([
        'error' => true,
        'message' => 'Vista no soportada: ' . $view
    ]);
}

$con->close();
?>
