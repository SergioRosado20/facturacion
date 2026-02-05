<?php
require 'pdf.php';
require_once 'log_helper.php';
require_once('vendor/autoload.php');
require_once "cors.php";
cors();

date_default_timezone_set('America/Mexico_City');
session_start();

$client = new \GuzzleHttp\Client();

$username = $_SESSION['username'];
$userID = $_SESSION['userID'];

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$database_host = $_ENV['DATABASE_HOST'] ?? '';
$database_user = $_ENV['DATABASE_USER'] ?? '';
$database_password = $_ENV['DATABASE_PASSWORD'] ?? '';
$database_name = $_ENV['DATABASE_NAME'] ?? '';
$enc_key = $_ENV['ENCRYPT_KEY'] ?? '';

$con = new mysqli($database_host, $database_user, $database_password, $database_name);

$data = json_decode(file_get_contents('php://input'), true);
$data_string = json_encode($data);

$factura_id = isset($data['factura_id']) ? $data['factura_id'] : null;
$motivo = isset($data['motivo']) ? $data['motivo'] : null;
$uuid_sustitucion = isset($data['uuid']) ? $data['uuid'] : null;
$pago = isset($data['pago']) ? $data['pago'] : null;
//print_r($factura_id);
logToFile($username, $userID, 'Informacion recibida en cancelar.php', "success", $data_string);
if($factura_id) {
    try {
        try {
            $sql = "SELECT `id`, `token`, `creacion` FROM `token` ORDER BY `id` DESC LIMIT 1";
        
            // Ejecutar la consulta (puedes usar tu conexión y método habitual)
            $stmt = $con->prepare($sql);
            $stmt->execute();
            // Obtener el resultado
            $result = $stmt->get_result();
        
            if ($result && $row = $result->fetch_assoc()) {
                $id = $row['id'];
                $token = $row['token'];
                $creacion = $row['creacion'];
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'No se encontró ningún registro en la tabla token.',
                ]);
            }
        } catch (\Exception $e) {
            echo json_encode([
            'status' => 'error',
            'message' => 'Ha ocurrido un error inesperado.',
            'desc' => $e->getMessage(),
            ]);
            exit;
        }
        
        if (empty($token)) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Ha ocurrido un error inesperado.',
                'desc' => $e->getMessage(),
            ]);
            exit;
        }

        try {
            if($pago == '1') {
                $tabla = "pagos";
                $id = "idPago";
            } else {
                $tabla = "facturas";
                $id = "id";
            }
            $sql = "SELECT `emisor` FROM `".$tabla."` WHERE ".$id." = ?";
            $stmt = $con->prepare($sql);
            $stmt->bind_param("i", $factura_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $id_emisor = $row['emisor'];
            //print_r($id_emisor);
            $sql = "SELECT * FROM `cuenta_factura` WHERE id = ?";
            $stmt = $con->prepare($sql);
            $stmt->bind_param("i", $id_emisor);
            $stmt->execute();
            $result = $stmt->get_result();
        
            if ($result && $row = $result->fetch_assoc()) {
                $emisor = $row;
                //print_r($emisor);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'No se encontró ningún registro en la tabla token.',
                ]);
            }
        } catch (\Exception $e) {
            echo json_encode([
            'status' => 'error',
            'message' => 'Ha ocurrido un error inesperado.',
            'desc' => $e->getMessage(),
            ]);
            exit;
        }

        if($pago == '1') {
            $sql = "SELECT * FROM pagos WHERE idPago = ?";
        } else {
            $sql = "SELECT * FROM facturas WHERE id = ?";
        }

        $stmt = $con->prepare($sql);

        if ($stmt === false) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Error al preparar la consulta: ' . $con->error
            ]);
            exit;
        }

        // Vincular los parámetros dinámicamente
        $stmt->bind_param("i", $factura_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result === false) {
            throw new Exception("Error al obtener el resultado: " . $stmt->error);
        }
        $row = $result->fetch_assoc();
        $rutaXml = 'xml/'.$row['rutaXml'];

        $data = leerXML($rutaXml);

        $total = $data['Total'];
        $rfcEmisor = $data['Emisor']['Rfc'];
        $rfcReceptor = $data['Receptor']['Rfc'];
        $uuid = $data['Complemento']['UUID'];
        $sello = substr($data['Complemento']['SelloCFD'], -8);

        // Leer y codificar los archivos
        try {
            $pass = $emisor["pass"];
            $iv_base64 = $emisor["iv"];
            $iv = base64_decode($iv_base64);
            // Verificar si IV y pass no están vacíos
            if ($iv === false || $pass === '') {
                die("Error: IV o pass no válidos.");
                logToFile($username, $userID, 'IV o pass no validos', "error");
            }
            // Descifrar la contraseña
            $decryptedPassword = openssl_decrypt($pass, 'aes-256-cbc', $enc_key, 0, $iv);
            if ($decryptedPassword === false) {
                die("Error al descifrar la contraseña.");
                logToFile($username, $userID, 'Error al descifrar contraseña', "error");
            }

            $CSD = $emisor["certificado"];
            $Key = $emisor["llave"];

            // Verificar si los archivos existen
            if (!file_exists($CSD)) {
                die("Error: No se encontró el archivo CSD en la ruta especificada: $CSD");
                logToFile($username, $userID, 'No se encontró el CSD en la ruta:'.$CSD, "error", $CSD);
            }
            if (!file_exists($Key)) {
                die("Error: No se encontró el archivo Key en la ruta especificada: $Key");
                logToFile($username, $userID, 'No se encontró el Key en la ruta:'.$Key, "error", $Key);
            }

            try {
                $CSDBinario = file_get_contents($CSD);
                $CSDBase64 = base64_encode($CSDBinario);
        
                $KeyBinario = file_get_contents($Key);
                $KeyBase64 = base64_encode($KeyBinario);
            } catch (Exception $e) {
                die("Error al leer los archivos: " . $e->getMessage());
                logToFile($username, $userID, 'Error al leer los archivos', "error");
            }
        } catch (Exception $e) {
            die("Error al leer los archivos: " . $e->getMessage());
        }

        $body = [
            "rfcEmisor" => $rfcEmisor,
            "rfcReceptor" => $rfcReceptor,
            "uuid" => $uuid,
            "total" => $total,
            "motivo" => $motivo,
            "sello" => $sello,
            "certificado" => $CSDBase64,
            "llavePrivada" => $KeyBase64,
            "password" => $decryptedPassword
        ];

        if($pago == '1') {

        } else {
            $body["total"] = floatval($total);
        }
        if($motivo == '01') {
            $body["folioFiscalSustitucion"] = $uuid_sustitucion;
        }

        logToFile('', 'Cancelar', json_encode($body, true), "success");

        $resCancelacion = $client->request('POST', 'https://testapi.facturoporti.com.mx/servicios/cancelar/csd', [
            'body' => json_encode($body),
            'headers' => [
                'accept' => 'application/json',
                'authorization' => 'Bearer '.$token,
                'content-type' => 'application/*+json',
            ],
        ]);

        $content = $resCancelacion->getBody()->getContents();
        $content = json_decode($content, true);
        $codigo = $content['codigo'];

        if($codigo == '000') {
            //print_r($content);
            logToFile($username, $userID, json_encode($content, true), "success");
            try {
                $mensaje = $content['mensaje'];
                $acuse = $content['acuse'];
                $codigoEstatus = $content['codigoEstatus'];
                $esCancelable = $content['esCancelable'];
                $estado = $content['estado'];
                $estatusCancelacion = $content['estatusCancelacion'];
                if(!$estatusCancelacion) {
                    if($estado == 'Cancelado') {
                        switch($esCancelable) {
                            case 'Cancelable sin aceptacion':
                                $estatusCancelacion = 'Cancelado sin aceptacion';
                                break;
                            case 'Cancelable con aceptacion':
                                $estatusCancelacion = 'Cancelado con aceptacion';
                                break;
                            default:
                                $estatusCancelacion = null;
                        }
                    }
                }
                
                try {
                    // Verificar si el UUID ya existe
                    $sqlCheck = "SELECT id FROM cancelaciones WHERE uuid = ?";
                    $stmtCheck = $con->prepare($sqlCheck);
                    if ($stmtCheck === false) {
                        throw new Exception("Error en la preparación de la consulta: " . $con->error);
                    }
                
                    $stmtCheck->bind_param("s", $uuid);
                    if (!$stmtCheck->execute()) {
                        throw new Exception("Error en la ejecución de la consulta: " . $stmtCheck->error);
                    }
                
                    $stmtCheck->store_result(); // Almacenar el resultado
                    $uuidExists = $stmtCheck->num_rows > 0; // Verificar si existe al menos un resultado
                
                    $stmtCheck->close();
                
                    $fechaActual = date('Y-m-d H:i:s');
                
                    if ($uuidExists) {
                        // Realizar un UPDATE
                        $sqlUpdate = "UPDATE `cancelaciones` SET  `mensaje` = ?, `codigoEstatus` = ?, `esCancelable` = ?, `estado` = ?, `estatusCancelacion` = ?, `fecha_actu` = ? WHERE `uuid` = ?";
                        $stmtUpdate = $con->prepare($sqlUpdate);
                        if ($stmtUpdate === false) {
                            throw new Exception("Error en la preparación del UPDATE: " . $con->error);
                        }
                
                        $stmtUpdate->bind_param("sssssss", $mensaje, $codigoEstatus, $esCancelable, $estado, $estatusCancelacion, $fechaActual, $uuid);
                        if (!$stmtUpdate->execute()) {
                            throw new Exception("Error en la ejecución del UPDATE: " . $stmtUpdate->error);
                        }
                
                        $stmtUpdate->close();
                    } else {
                        if($pago == '1') {
                            $sqlInsert = "INSERT INTO `cancelaciones`(`pago`, `uuid`, `uuid_sustitucion`, `mensaje`, `acuse`, `codigoEstatus`, `esCancelable`, `estado`, `estatusCancelacion`, `fecha_creacion`, `fecha_actu`) 
                                        VALUES (?,?,?,?,?,?,?,?,?,?,?)";
                        } else {
                            $sqlInsert = "INSERT INTO `cancelaciones`(`folio`, `uuid`, `uuid_sustitucion`, `mensaje`, `acuse`, `codigoEstatus`, `esCancelable`, `estado`, `estatusCancelacion`, `fecha_creacion`, `fecha_actu`) 
                                        VALUES (?,?,?,?,?,?,?,?,?,?,?)";
                        }

                        $stmtInsert = $con->prepare($sqlInsert);
                        if ($stmtInsert === false) {
                            throw new Exception("Error en la preparación del INSERT: " . $con->error);
                        }
                
                        $stmtInsert->bind_param("sssssssssss", $factura_id, $uuid, $uuid_sustitucion, $mensaje, $acuse, $codigoEstatus, $esCancelable, $estado, $estatusCancelacion, $fechaActual, $fechaActual);
                        if (!$stmtInsert->execute()) {
                            throw new Exception("Error en la ejecución del INSERT: " . $stmtInsert->error);
                        }
                
                        $stmtInsert->close();
                    }
                
                } catch (Exception $e) {
                    echo "Error: " . $e->getMessage();
                }

                // Buscar el ID del nuevo status en factura_status
                $sqlStatus = "SELECT id FROM factura_status WHERE status = ?";
                $stmtStatus = $con->prepare($sqlStatus);
                if ($stmtStatus === false) {
                    throw new Exception("Error en la preparación de la consulta: " . $con->error);
                }
                $stmtStatus->bind_param("s", $estatusCancelacion);
                $stmtStatus->execute();
                $stmtStatus->bind_result($nuevoStatusId);
                $stmtStatus->fetch();
                $stmtStatus->close();

                if($pago == '1') {
                    // Obtener las facturas asociadas al pago
                    $sqlPagoFacturas = "SELECT pf.fkFactura, pf.importePagado
                                    FROM pagos_facturas pf 
                                    WHERE pf.fkPago = ?";
                    $stmtPagoFacturas = $con->prepare($sqlPagoFacturas);
                    if ($stmtPagoFacturas === false) {
                        throw new Exception("Error en la preparación de la consulta de pagos_facturas: " . $con->error);
                    }

                    $stmtPagoFacturas->bind_param("i", $factura_id);
                    if (!$stmtPagoFacturas->execute()) {
                        throw new Exception("Error en la ejecución de la consulta de pagos_facturas: " . $stmtPagoFacturas->error);
                    }

                    $resultPagoFacturas = $stmtPagoFacturas->get_result();

                    // Actualizar el saldo insoluto de cada factura
                    while ($rowPagoFactura = $resultPagoFacturas->fetch_assoc()) {
                        $idFactura = $rowPagoFactura['fkFactura'];
                        $importePagado = $rowPagoFactura['importePagado'];
                        
                        // Actualizar el saldo insoluto sumando el importe pagado
                        $sqlUpdateSaldo = "UPDATE facturas 
                                        SET saldoInsoluto = saldoInsoluto + ? 
                                        WHERE id = ?";
                        $stmtUpdateSaldo = $con->prepare($sqlUpdateSaldo);
                        if ($stmtUpdateSaldo === false) {
                            throw new Exception("Error en la preparación de la actualización de saldo: " . $con->error);
                        }
                        
                        $stmtUpdateSaldo->bind_param("di", $importePagado, $idFactura);
                        if (!$stmtUpdateSaldo->execute()) {
                            throw new Exception("Error en la ejecución de la actualización de saldo: " . $stmtUpdateSaldo->error);
                        }
                        
                        $stmtUpdateSaldo->close();
                    }

                    $stmtPagoFacturas->close();

                    // Actualizar el estado del pago
                    $sqlUpdatePago = "UPDATE pagos SET status = 'Cancelado' WHERE idPago = ?";
                    $stmtUpdatePago = $con->prepare($sqlUpdatePago);
                    if ($stmtUpdatePago === false) {
                        throw new Exception("Error en la preparación de la actualización del pago: " . $con->error);
                    }

                    $stmtUpdatePago->bind_param("i", $factura_id);
                    if (!$stmtUpdatePago->execute()) {
                        throw new Exception("Error en la ejecución de la actualización del pago: " . $stmtUpdatePago->error);
                    }

                    $stmtUpdatePago->close();
                } else {
                    if ($nuevoStatusId) {
                        // Actualizar el campo status en facturas
                        $sqlUpdateFactura = "UPDATE facturas SET status = ? WHERE id = ?";
                        $stmtUpdate = $con->prepare($sqlUpdateFactura);
                        if ($stmtUpdate === false) {
                            throw new Exception("Error en la preparación de la consulta: " . $con->error);
                        }
                        $stmtUpdate->bind_param("ii", $nuevoStatusId, $factura_id);
                        if (!$stmtUpdate->execute()) {
                            throw new Exception("Error en la ejecución de la consulta: " . $stmtUpdate->error);
                        }
                        $stmtUpdate->close();
                    }
                }

                $sqlSello = "UPDATE `timbres` SET `restantes`=`restantes` - 1,`fecha_update`= ? WHERE 1 ORDER BY `id` DESC LIMIT 1";
                $stmt = $con->prepare($sqlSello);
                if ($stmt === false) {
                    throw new Exception("Error en la preparación de la consulta: " . $con->error);
                }
                $fechaActual = date('Y-m-d H:i:s');

                // Vincular parámetros y ejecutar
                $stmt->bind_param("s", $fechaActual);
                if (!$stmt->execute()) {
                    throw new Exception("Error en la ejecución de la consulta: " . $stmt->error);
                }

                $stmt->close();

                echo json_encode([
                    'status' => 'success',
                    'mensaje' => $content['mensaje'],
                    'acuse' => $content['acuse'],
                    'codigoEstatus' => $content['codigoEstatus'],
                    'esCancelable' => $content['esCancelable'],
                    'estado' => $content['estado'],
                    'estatusCancelacion' => $content['estatusCancelacion'],
                    'uuid' => $content['uuid']
                ]);

            } catch (Exception $e) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Error al registrar la cancelación: ' . $e->getMessage()
                ]);
                exit;
            }
        } else {
            echo json_encode([
                'status' => 'error',
                'message' => 'Parece que los datos de facturación son incorrectos. Corrígelos e intentalo más tarde.',
                'content' => $content,
                'cuerpo' => $body,
            ]);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage(),
            'content' => $content,
            'cuerpo' => $body,
        ]);
        die("Error al hacer la cancelación: " . $e->getMessage());
    }
}

?>