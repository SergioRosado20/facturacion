<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once 'log_helper.php';
require_once('vendor/autoload.php');
require_once "cors.php";
require_once 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
cors();

date_default_timezone_set('America/Mexico_City');
session_start();

$client = new \GuzzleHttp\Client();

//$username = $_SESSION['username'];

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$database_host = $_ENV['DATABASE_HOST'] ?? '';
$database_user = $_ENV['DATABASE_USER'] ?? '';
$database_password = $_ENV['DATABASE_PASSWORD'] ?? '';
$database_name = $_ENV['DATABASE_NAME'] ?? '';
$enc_key = $_ENV['ENCRYPT_KEY'] ?? '';

$con = new mysqli($database_host, $database_user, $database_password, $database_name);
$con->set_charset("utf8mb4");

$raw_json = file_get_contents('php://input');
$data = json_decode($raw_json, true);

$fecha_inicio = $data['fecha_inicio'];
$fecha_fin = $data['fecha_fin'];
$cuenta_facturacion = isset($data['cuenta_facturacion']) ? $data['cuenta_facturacion'] : null;
$cliente = isset($data['cliente']) ? $data['cliente'] : null;
$estado = isset($data['estado']) ? $data['estado'] : null;
$estado_pago = isset($data['estado_pago']) ? $data['estado_pago'] : null;
$tipo = isset($data['tipo']) ? $data['tipo'] : null;

logToFile('0', '0', 'Data recibida en generar_reporte.php', json_encode($data));

$respuesta = [
    'status' => 'success',
    'data' => [],
    'message' => 'Datos obtenidos correctamente',
    'query' => '',
];
try {
    $whereFacturas = " WHERE 1";
    $wherePagos = " WHERE 1";
    $paramsFacturas = [];
    $paramsPagos = [];
    if($fecha_inicio){
        $whereFacturas .= " AND f.fecha >= ?";
        $paramsFacturas[] = $fecha_inicio . ' 00:00:00';

        $wherePagos .= " AND pf.fecha >= ?";
        $paramsPagos[] = $fecha_inicio . ' 00:00:00';
    }
    if($fecha_fin){
        $whereFacturas .= " AND f.fecha <= ?";
        $paramsFacturas[] = $fecha_fin . ' 23:59:59';

        $wherePagos .= " AND pf.fecha <= ?";
        $paramsPagos[] = $fecha_fin . ' 23:59:59';
    }
    if($cuenta_facturacion){
        $whereFacturas .= " AND f.emisor = ?";
        $paramsFacturas[] = $cuenta_facturacion;

        $wherePagos .= " AND p.emisor = ?";
        $paramsPagos[] = $cuenta_facturacion;
    }
    if($cliente){
        $whereFacturas .= " AND f.cliente = ?";
        $paramsFacturas[] = $cliente;

        $wherePagos .= " AND f.cliente = ?";
        $paramsPagos[] = $cliente;
    }
    if($estado){
        $whereFacturas .= " AND f.status = ?";
        $paramsFacturas[] = $estado;

        $wherePagos .= " AND p.status = ?";
        switch ($estado) {
            case 1:
                $status = 'Vigente';
                break;
            case 2:
                $status = 'En proceso de cancelación';
                break;
            case 3:
                $status = 'Cancelada sin aceptación';
                break;
            case 4:
                $status = 'Cancelada con aceptación';
                break;
            default:
                $status = $status;
                break;
        }
        $paramsPagos[] = $status;
    }
    if($estado_pago){
        $whereFacturas .= " AND pagado = ?";
        $paramsFacturas[] = $estado_pago;
    }

    $whereFacturas .= " ORDER BY f.id ASC";
    $wherePagos .= " ORDER BY pf.id ASC";

    $sql = "SELECT f.id, f.status, f.total, f.cliente, f.fecha, f.cfdi, f.tipoCfdi, f.uuid, f.metodoPago, f.formaPago, f.moneda, f.lugarExpedicion, f.subTotal, f.serie, f.folio, f.saldoInsoluto, f.pagado, f.anticipo, f.id_relacion, f.emisor, f.saldoInsoluto, cf.rfc as rfc_emisor
            FROM facturas as f
            INNER JOIN cuenta_factura as cf ON f.emisor = cf.id
            $whereFacturas";
    logToFile('0', '0', 'SQL en generar_reporte.php', $sql, json_encode($paramsFacturas));
    $result = $con->prepare($sql);
    $result->bind_param(str_repeat('s', count($paramsFacturas)), ...$paramsFacturas);
    $result->execute();
    $result = $result->get_result();
    $facturas = [];
    while($row = $result->fetch_assoc()){
        $facturas[] = $row;
    }

    $sql = "SELECT pf.id, pf.fkPago, pf.fkFactura, pf.importePagado, pf.saldoAnterior, pf.saldoInsoluto, pf.parcialidad, pf.fecha, p.emisor, p.status, p.uuid, p.formaPago, cf.rfc as rfc_emisor, f.cliente, f.total
            FROM pagos_facturas as pf
            INNER JOIN pagos as p ON pf.fkPago = p.idPago
            INNER JOIN cuenta_factura as cf ON p.emisor = cf.id
            INNER JOIN facturas as f ON pf.fkFactura = f.id
            $wherePagos";
    logToFile('0', '0', 'SQL en generar_reporte.php', $sql, json_encode($paramsPagos));
    $result = $con->prepare($sql);
    $result->bind_param(str_repeat('s', count($paramsPagos)), ...$paramsPagos);
    $result->execute();
    $result = $result->get_result();
    while($row = $result->fetch_assoc()){
        $row['tipoCfdi'] = 'P';
        $row['metodoPago'] = 'PUE';
        $facturas[] = $row;
    }

    $respuesta['data'] = $facturas;
    $respuesta['query'] = $sql;
    logToFile('0', '0', 'Respuesta en generar_reporte.php', json_encode($respuesta));

    if($tipo === 'datos'){
        echo json_encode($respuesta);
    } else {

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'Folio Pago');
        $sheet->setCellValue('B1', 'Folio Factura');
        $sheet->setCellValue('C1', 'Cliente');
        $sheet->setCellValue('D1', 'Fecha');
        $sheet->setCellValue('E1', 'Total');
        $sheet->setCellValue('F1', 'Importe Pagado');
        $sheet->setCellValue('G1', 'Saldo Anterior');
        $sheet->setCellValue('H1', 'Saldo Insoluto');
        $sheet->setCellValue('I1', 'Parcialidad');
        $sheet->setCellValue('J1', 'Estado');
        $sheet->setCellValue('K1', 'Pago');
        $sheet->setCellValue('L1', 'Tipo CFDI');
        $sheet->setCellValue('M1', 'RFC Emisor');
        $sheet->setCellValue('N1', 'UUID');
        $sheet->setCellValue('O1', 'Metodo Pago');
        $sheet->setCellValue('P1', 'Forma Pago');

        $row = 2;
        foreach($facturas as $factura){
            $status = '';
            $tipoCfdi = '';
            $formaPago = '';
            // Verificar si es un registro de pago o factura
            if (isset($factura['importePagado'])) {
                // Es un registro de pago
                $status = $factura['status'] ?? '';
            } else {
                // Es un registro de factura
                switch ($factura['status']) {
                    case 1:
                        $status = 'Vigente';
                        break;
                    case 2:
                        $status = 'En proceso de cancelación';
                        break;
                    case 3:
                        $status = 'Cancelada sin aceptación';
                        break;
                    case 4:
                        $status = 'Cancelada con aceptación';
                        break;
                    default:
                        $status = 'Desconocido';
                        break;
                }
            }

            // Manejar tipoCfdi
            if (isset($factura['tipoCfdi'])) {
                switch ($factura['tipoCfdi']) {
                    case 'I':
                        $tipoCfdi = 'Ingreso';
                        break;
                    case 'E':
                        $tipoCfdi = 'Egreso';
                        break;
                    case 'P':
                        $tipoCfdi = 'Pago';
                        break;
                    default:
                        $tipoCfdi = 'Desconocido';
                        break;
                }
            } else {
                $tipoCfdi = '';
            }

            // Manejar formaPago
            if (isset($factura['formaPago'])) {
                switch($factura['formaPago']) {
                case '01':
                    $formaPago = '01 - Efectivo';
                    break;
                case '02':
                    $formaPago = '02 - Cheque nominativo';
                    break;
                case '03':
                    $formaPago = '03 - Transferencia electrónica de fondos';
                    break;
                case '04':
                    $formaPago = '04 - Tarjeta de crédito';
                    break;
                case '05':
                    $formaPago = '05 - Monedero electrónico';
                    break;
                case '06':
                    $formaPago = '06 - Dinero electrónico';
                    break;
                case '08':
                    $formaPago = '08 - Vales de despensa';
                    break;
                case '12':
                    $formaPago = '12 - Dación en pago';
                    break;
                case '13':
                    $formaPago = '13 - Pago por subrogación';
                    break;
                case '14':
                    $formaPago = '14 - Pago por consignación';
                    break;
                case '15':
                    $formaPago = '15 - Condonación';
                    break;
                case '17':
                    $formaPago = '17 - Compensación';
                    break;
                case '23':
                    $formaPago = '23 - Novación';
                    break;
                case '24':
                    $formaPago = '24 - Confusión';
                    break;
                case '25':
                    $formaPago = '25 - Remisión de deuda';
                    break;
                case '26':
                    $formaPago = '26 - Prescripción o caducidad';
                    break;
                case '27':
                    $formaPago = '27 - A satisfacción del acreedor';
                    break;
                case '28':
                    $formaPago = '28 - Tarjeta de débito';
                    break;
                case '29':
                    $formaPago = '29 - Tarjeta de servicios';
                    break;
                case '30':
                    $formaPago = '30 - Aplicación de anticipos';
                    break;
                case '31':
                    $formaPago = '31 - Intermediario pagos';
                    break;
                case '99':
                    $formaPago = '99 - Por definir';
                    break;
                default:
                    $formaPago = '';
                    break;
                }
            } else {
                $formaPago = '';
            }

            // Determinar si es factura o pago para mostrar los folios correctamente
            if (isset($factura['importePagado'])) {
                // Es un registro de pago: mostrar Folio Factura (fkFactura) y Folio Pago (id)
                $sheet->setCellValue('A' . $row, $factura['id'] ?? '');
                $sheet->setCellValue('B' . $row, $factura['fkFactura'] ?? '');
            } else {
                // Es un registro de factura: mostrar solo Folio Factura (id)
                $sheet->setCellValue('A' . $row, ''); // Sin Folio Pago para facturas
                $sheet->setCellValue('B' . $row, $factura['id'] ?? '');
            }
            $sheet->setCellValue('C' . $row, $factura['cliente'] ?? '');
            $sheet->setCellValue('D' . $row, $factura['fecha'] ?? '');
            $sheet->setCellValue('E' . $row, $factura['total'] ?? '');
            $sheet->setCellValue('F' . $row, $factura['importePagado'] ?? '');
            $sheet->setCellValue('G' . $row, $factura['saldoAnterior'] ?? '');
            $sheet->setCellValue('H' . $row, $factura['saldoInsoluto'] ?? '');
            $sheet->setCellValue('I' . $row, $factura['parcialidad'] ?? '');
            $sheet->setCellValue('J' . $row, $status ?? '');
            $sheet->setCellValue('K' . $row, isset($factura['pagado']) ? ($factura['pagado'] === 1 ? 'Pagada' : 'Pendiente') : '');
            $sheet->setCellValue('L' . $row, $tipoCfdi ?? '');
            $sheet->setCellValue('M' . $row, $factura['rfc_emisor'] ?? '');
            $sheet->setCellValue('N' . $row, $factura['uuid'] ?? '');
            $sheet->setCellValue('O' . $row, $factura['metodoPago'] ?? '');
            $sheet->setCellValue('P' . $row, $formaPago ?? '');
            $row++;
        }
        
        // Para Excel, generar el archivo y enviarlo
        $outputFile = "reporte_facturacion.xlsx";
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header("Content-Disposition: attachment; filename=\"{$outputFile}\"");
        header('Cache-Control: max-age=0');
        
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save('php://output');
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        exit;
    }
} catch (Exception $e) {
    $respuesta['status'] = 'error';
    $respuesta['message'] = $e->getMessage();
    logToFile('0', '0', 'Error en generar_reporte.php', $e->getMessage());
    echo json_encode($respuesta);
}
?>