<?php
/*ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);*/
require 'pdf.php';
require 'log_helper.php';
require_once('vendor/autoload.php');
require_once "cors.php";
cors();

date_default_timezone_set('America/Mexico_City');
session_start();

$client = new \GuzzleHttp\Client();

$username = $_SESSION['username'];
$userID = $_SESSION['userID'];

$tokenData = [];
$token = "";



$data_string = json_encode($data);

logToFile($username, $userID, 'Datos recibidos para el pago de factura: '.$data_string, "success", $data_string);

///////////////////////////////////////////////////////////////////// INICIA ZONA DE CONSUTLAS Y CÁLCULOS

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

// Recibir los datos JSON en bruto
$json = file_get_contents('php://input');
$json_data = json_decode($json, true);

// Decodificar el JSON a un array asociativo
$data = json_decode($json, true);
$data = limpiarUtf8($data);

foreach ($data as $clave => $valor) {
    if (!mb_check_encoding($valor, 'UTF-8')) {
        echo "Campo '$clave' no está en UTF-8\n";
    }
}

$formaPago = $data['forma_pago'];
$fechaPago = $data['fecha_pago'];
$facturas = $data['facturas'];
$importePagado = $data['importe_pagado'];
$moneda = $data['moneda'];
$cambio = $data['cambio'];

if ($con->connect_error) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Conexión a la base de datos fallida'
    ]);
    exit;
}

if (isset($_GET['id'])) {
    $ids = explode(',', $_GET['id']);
    $ids = array_map('intval', $ids); // Asegurarnos de que sean enteros para evitar inyección SQL
    $firstId = $ids[0];
    // Verificar si es un solo ID o múltiples IDs
    $isSingleId = count($ids) === 1;
    $placeholders = implode(',', array_fill(0, count($ids), '?')); // Crear placeholders para la consulta

    // Consulta SQL para obtener los datos de las cotizaciones y encabezados
    $sql = "SELECT fac.*
            FROM facturas as fac
            WHERE fac.id IN ($placeholders)";

    $stmt = $con->prepare($sql);

    if ($stmt === false) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Error al preparar la consulta: ' . $con->error
        ]);
        exit;
    }

    // Vincular los parámetros dinámicamente
    $types = str_repeat('i', count($ids));
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result === false) {
        throw new Exception("Error al obtener el resultado: " . $stmt->error);
    }

    // Procesar los resultados
    $data = [];
    $nuevoTotal = 0;
    $todosServicios = [];

    while ($row = $result->fetch_assoc()) {
        $cotizacion_id = $row['id'];
        $nombreXml = $row['rutaXml'];
        $clienteID = $row['cliente'];

        if (is_numeric($clienteID)) {
            // Es un cliente registrado
            $data['clienteInfo'] = [
                'nombre' => $row['nombre'],
                'reg_fiscal' => $row['reg_fiscal'],
                'cfdi' => $row['cfdi'],
                'rfc' => $row['rfc'],
                'cp_factura' => $row['cp_factura'],
                'moneda' => $row['moneda'],
                'email' => $row['email'],
                'telefono' => $row['telefono'],
                'telefono2' => $row['telefono'],
                'calle' => $row['calle'],
                'colonia' => $row['colonia'],
                'ciudad' => $row['ciudad'],
                'estado' => $row['estado'],
                'municipio' => $row['municipio'],
                'pais' => $row['pais'],
                'num_ext' => $row['numExt'],
                'num_int' => $row['numInt'],
                'cp' => $row['cp'],
            ];
        } else { 
            // Leer los datos del cliente desde el XML de la factura
            // Supongamos que ya tienes una función para obtener los datos del cliente desde el XML
            $ruta = 'xml/'.$nombreXml;
            $xmlData = leerXml($ruta);
            $cliente = $xmlData['Receptor'];
            $data['clienteInfo'] = [
                'nombre' => $cliente['Nombre'],
                'reg_fiscal' => $cliente['RegimenFiscalReceptor'],
                'cfdi' => $cliente['UsoCFDI'],
                'rfc' => $cliente['Rfc'],
                'cp_factura' => $cliente['DomicilioFiscalReceptor']
            ];
        }

        // Recalcular el total con la cantidad de cada producto
        $servicios = json_decode($row['servicios'], true);
        foreach ($servicios as $servicio) {
            $nuevoTotal += floatval($servicio['precio']) * floatval($servicio['cantidad']);
            $todosServicios[] = $servicio;
        }
    }

    // Fusionar los servicios en un solo objeto
    $data['productos'] = $todosServicios;
    $data['total'] = $nuevoTotal;

    //print_r($data);
    $sqlFacturaCount = "SELECT COUNT(*) as total FROM facturas WHERE 1";

    $stmtCount = $con->prepare($sqlFacturaCount);

    if ($stmtCount === false) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Error al preparar la consulta: ' . $con->error
        ]);
        exit;
    }

    // Ejecutar la consulta preparada
    $stmtCount->execute();

    // Obtener el resultado
    $result = $stmtCount->get_result();
    if ($result === false) {
        throw new Exception("Error al obtener el resultado: " . $stmtCount->error);
    }

    // Extraer el conteo
    $facturaActual = $result->fetch_assoc();
    $facturaActual = strval($facturaActual['total'] + 1);

    $stmt->close();
}

$client = new \GuzzleHttp\Client();


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
    $sql = "SELECT * FROM `cuenta_factura` ORDER BY `id` DESC LIMIT 1";

    // Ejecutar la consulta (puedes usar tu conexión y método habitual)
    $stmt = $con->prepare($sql);
    $stmt->execute();
    // Obtener el resultado
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

function calcularValorEsperado($conceptos): float {
    $valorEsperado = 0.0;

    foreach ($conceptos as $concepto) {
        if (isset($concepto['Impuestos'])) {
            foreach ($concepto['Impuestos'] as $impuesto) {
                $base = (float) $impuesto['Base'];
                $impuestoImporte = (float) $impuesto['ImpuestoImporte'];
                $valorEsperado += $base + $impuestoImporte;
            }
        }
    }

    return $valorEsperado;
}

function ajustarTotal($totalReportado, $valorEsperado) {
    $diferencia = abs($totalReportado - $valorEsperado);

    if ($diferencia <= 0.02) {
        return $valorEsperado;
    }

    return $totalReportado;
}

$conceptos = [];
// Iterar sobre cada producto y formatearlo para el array Conceptos
//print_r($data['productos']);
if (isset($data['productos']) && is_array($data['productos'])) {
    foreach ($data['productos'] as $producto) {
        // Verificar que las claves necesarias existan y no sean nulas
        if (isset($producto["cantidad"], $producto["precio"], $producto["claveUni"], $producto["unidad"], $producto["clavePS"])) {
            $importe = number_format($producto["cantidad"] * $producto["precio"], 2, '.', '');
            $impuestosNodos = [];

            if (isset($producto["impuestos"]) && is_array($producto["impuestos"])) {
                if (isset($producto["impuestos"]["trasladados"]) && is_array($producto["impuestos"]["trasladados"])) {
                    foreach ($producto["impuestos"]["trasladados"] as $impuestoDetalle) {
                        // Verificar que se cuente con los datos necesarios
                        if (isset($impuestoDetalle["Impuesto"], $impuestoDetalle["Factor"], $impuestoDetalle["Base"], $impuestoDetalle["Tasa"], $impuestoDetalle["Importe"])) {
                            $impuestosNodos[] = [
                                // Para impuestos trasladados asignamos "TipoImpuesto" = 1
                                "TipoImpuesto"      => 1,
                                // Se envía el código del impuesto (ej. 2 para IVA, 3 para IEPS, etc.)
                                "Impuesto"          => $impuestoDetalle["Impuesto"],
                                "Factor"            => $impuestoDetalle["Factor"],
                                "Base"              => $impuestoDetalle["Base"],
                                "Tasa"              => $impuestoDetalle["Tasa"],
                                // En el XML se suele usar “ImpuestoImporte” como etiqueta para el importe
                                "ImpuestoImporte"   => $impuestoDetalle["Importe"]
                            ];
                        }
                    }
                }

                // Procesar impuestos retenidos
                if (isset($producto["impuestos"]["retenidos"]) && is_array($producto["impuestos"]["retenidos"])) {
                    foreach ($producto["impuestos"]["retenidos"] as $impuestoDetalle) {
                        if (isset($impuestoDetalle["Impuesto"], $impuestoDetalle["Factor"], $impuestoDetalle["Base"], $impuestoDetalle["Tasa"], $impuestoDetalle["Importe"])) {
                            $impuestosNodos[] = [
                                // Para impuestos retenidos asignamos "TipoImpuesto" = 2
                                "TipoImpuesto"      => 2,
                                "Impuesto"          => $impuestoDetalle["Impuesto"],
                                "Factor"            => $impuestoDetalle["Factor"],
                                "Base"              => $impuestoDetalle["Base"],
                                "Tasa"              => $impuestoDetalle["Tasa"],
                                "ImpuestoImporte"   => $impuestoDetalle["Importe"]
                            ];
                        }
                    }
                }
            }
            
            $concepto = [
                "Cantidad" => $producto["cantidad"],
                "CodigoUnidad" => $producto["claveUni"],
                "Unidad" => $producto["unidad"],
                "CodigoProducto" => $producto["clavePS"],
                "Producto" => $producto["nombre"] ?? "Servicio",
                "PrecioUnitario" => number_format($producto["precio"], 2, '.', ''),
                "Importe" => $importe,
                "ObjetoDeImpuesto" => $producto["objetoImp"] ?? "01",
                "Impuestos" => $impuestosNodos
            ];
            $conceptos[] = $concepto;
        } else { // Manejo de errores si faltan claves necesarias
            echo "Error: Datos del producto incompletos.";
            exit;
        } 
    }
} else {
    echo "Error: No se encontró el array de productos.";
    exit;
}
//print_r($conceptos);

///////////////////////////////////////////////////////////////////// TERMINA ZONA DE CONSUTLAS Y CÁLCULOS

try {

    $imagen = 'logo.png';
    $imageData = file_get_contents($imagen);
    $imageBase64 = base64_encode($imageData);

    // Datos del Emisor
    $rfcEmisor = $emisor["rfc"];
    $razonSocialEmisor = $emisor["nombre"];
    $regimenFiscEmisor = $emisor["regimen"];
    $calleEmisor = $emisor["calle"];
    $numExtEmisor = $emisor["num_ext"];
    $numIntEmisor = $emisor["num_int"];
    $coloniaEmisor = $emisor["colonia"];
    $localidadEmisor = $emisor["ciudad"];
    $municipioEmisor = $emisor["municipio"];
    $estadoEmisor = $emisor["estado"];
    $cpEmisor = $emisor["cp"];
    $paisEmisor = $emisor["pais"];

    // Otros Datos
    $fechaExpedicion = date('Y-m-d\TH:i:s');

    $subTotal = $data['total'];

    $total = bcmul($subTotal, '1.16', 2); // La multiplicación se realiza con 2 decimales de precisión
    // Redondea el total a 2 decimales para asegurar la precisión
    $totalRedondeado = round((float)$total, 2);
    // Luego, si es necesario, conviértelo a float para el JSON
    $totalFloat = (float)$totalRedondeado;

    $totalReportado = $totalFloat;
    $valorEsperado = calcularValorEsperado($conceptos);
    $totalAjustado = ajustarTotal($totalReportado, $valorEsperado);

    //error_log("Subtotal: " . $subTotal);
    //error_log("Total calculado: " . $total);
    //error_log("Total redondeado: " . $totalRedondeado);

    $pass = $emisor["pass"];
    $iv_base64 = $emisor["iv"];
    $iv = base64_decode($iv_base64);
    // Verificar si IV y pass no están vacíos
    if ($iv === false || $pass === '') {
        die("Error: IV o pass no válidos.");
    }
    // Descifrar la contraseña
    $decryptedPassword = openssl_decrypt($pass, 'aes-256-cbc', $enc_key, 0, $iv);
    if ($decryptedPassword === false) {
        die("Error al descifrar la contraseña.");
    }

    // Otros Datos
    $fechaExpedicion = date('Y-m-d\TH:i:s');

    $CSD = $emisor["certificado"];
    $Key = $emisor["llave"];

    // Verificar si los archivos existen
    if (!file_exists($CSD)) {
        die("Error: No se encontró el archivo CSD en la ruta especificada: $CSD");
    }
    if (!file_exists($Key)) {
        die("Error: No se encontró el archivo Key en la ruta especificada: $Key");
    }

    // Leer y codificar los archivos
    try {
        $CSDBinario = file_get_contents($CSD);
        $CSDBase64 = base64_encode($CSDBinario);

        $KeyBinario = file_get_contents($Key);
        $KeyBase64 = base64_encode($KeyBinario);
    } catch (Exception $e) {
        die("Error al leer los archivos: " . $e->getMessage());
    }

    $cuentaOrdenante = $json_data['cuenta_ordenante'];
    $rfcCtaOrdenante = $json_data['rfc_cuenta_ordenante'];
    $cuentaBeneficiario = $json_data['cuenta_beneficiario'];
    $rfcCtaBeneficiario = $json_data['rfc_cuenta_beneficiario'];
    $totalSaldoInsoluto = 0;

    $totalImpuestoPagado = 0;
    $totalTrasladosBase = 0;

    $idFacturas = [];
    foreach($facturas as $factura) {
        $idFacturas[$factura['id_factura']] = [
            // Usamos el campo objetoDeImpuesto para saber si la factura está sujeta a impuestos.
            // Por defecto, si no se especifica, se asigna '01' (no sujeto a impuestos)
            'objetoDeImpuesto' => $factura['objetoDeImpuesto'] ?? '01',
            'importe' => $factura['importe'],
            // Se agrega el array de impuestos para poder recorrerlos y calcularlos luego
            'impuestos' => $factura['impuestos'] ?? [],
            'moneda' => $moneda,
            'cambio' => $cambio,
        ];
    }

    $ids = array_keys($idFacturas);
    $idFacturasString = implode(',', $ids);
    
    $insolutos = [];
    //print_r($idFacturas);
    $objetoDeImpuesto = "01";

    foreach ($idFacturas as $idFactura => $detalle) {
        // Obtener UUID de factura
        $sql = "SELECT uuid, total, subTotal FROM facturas WHERE id = ?";
        $stmt = $con->prepare($sql);
        if ($stmt === false) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Error al preparar la consulta: ' . $con->error
            ]);
            exit;
        }
        $stmt->bind_param("i", $idFactura);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        if ($row === null) {
            echo json_encode([
                'status' => 'error',
                'message' => 'No se encontró la factura con el ID: ' . $idFactura
            ]);
            exit;
        }

        $uuidRelacionado = $row['uuid'];
        $totalFactura = $row['total'];
        $subTotalFactura = $row['subTotal'];
    
        $saldoAnterior = 0.00;
        $numeroParcialidad = 1;
        $saldoInsoluto = 0;
    
        // Obtener el último pago de la factura
        $sql = "SELECT * FROM pagos WHERE idFactura = ? ORDER BY fecha DESC LIMIT 1";
        $stmt = $con->prepare($sql);
        if ($stmt === false) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Error al preparar la consulta: ' . $con->error
            ]);
            exit;
        }
        $stmt->bind_param("i", $idFactura);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result->fetch_assoc();
        $stmt->close();
    
        if (empty($rows)) {
            $saldoAnterior = $totalFactura;
        } else {
            if ($rows['saldoInsoluto'] === "0.00") {
                http_response_code(400);
                throw new Exception('Esta factura ya ha sido pagada completamente');
            }
            $saldoAnterior = $rows['saldoInsoluto'];
            $numeroParcialidad = $rows['parcialidad'] + 1;
        }
    
        // Calcular saldos
        $importePagadoFloat = number_format((float)$detalle['importe'], 4, '.', '');
        $saldoAnteriorFloat = floatval(number_format((float)$saldoAnterior, 4, '.', ''));

        $saldoInsoluto = bcsub($saldoAnteriorFloat, $importePagadoFloat, 4);

        $objetoDeImpuesto = $detalle['objetoDeImpuesto'] ?? '01'; // Por defecto, "01" si no está definido
        $importePagadoSinImpuestos = $importePagadoFloat;

        if ($objetoDeImpuesto === '02') {
            if (isset($detalle['impuestos']['trasladados']) && is_array($detalle['impuestos']['trasladados'])) {
                $totalPorcentajeTrasladados = 0;
                foreach ($detalle['impuestos']['trasladados'] as $impDetalle) {
                    // Se asume que "Tasa" ya viene en formato decimal (e.g., "0.160000")
                    $totalPorcentajeTrasladados += floatval($impDetalle['Tasa']);
                }
                if ($totalPorcentajeTrasladados > 0) {
                    $importePagadoSinImpuestos = bcdiv($importePagadoFloat, (1 + $totalPorcentajeTrasladados), 4);
                }
            }
        }

        if ($saldoInsoluto < 0) {
            http_response_code(400);
            // Crear un array con la información adicional
            $errorData = [
                'message' => 'El importe pagado es mayor al total de la factura.',
                'totalFactura' => $totalFactura,
                'saldoAnterior' => $saldoAnterior,
                'importePagado' => $detalle['importe'] // Usamos $detalle['importe'] aquí
            ];

            // Lanzar la excepción con el array convertido a JSON
            throw new Exception(json_encode($errorData));
        }

        $totalTrasladosBaseIVA16      = 0;
        $totalTrasladosImpuestoIVA16  = 0;

        $impuestosTrasladados = [];
        if (isset($detalle['impuestos']['trasladados']) && is_array($detalle['impuestos']['trasladados'])) {
            foreach ($detalle['impuestos']['trasladados'] as $impDetalle) {
                // Se espera que cada $impDetalle tenga las claves: Impuesto, Factor, Base, Tasa y Importe
                if ($impDetalle["Impuesto"] == 2 && floatval($impDetalle["Tasa"]) == 0.16) {
                    $impuestosTrasladados[] = [
                        "Impuesto" => $impDetalle["Impuesto"],
                        "Factor"    => $impDetalle["Factor"],
                        "Base"      => $impDetalle["Base"],
                        "Tasa"      => $impDetalle["Tasa"],
                        "Importe"   => $impDetalle["Importe"]
                    ];
                    // Acumulamos para los totales IVA 16%
                    $totalTrasladosBaseIVA16     += floatval($impDetalle["Base"]);
                    $totalTrasladosImpuestoIVA16 += floatval($impDetalle["Importe"]);
                } else {
                    // Opcional: si decides guardar los demás traslados en el documento relacionado, pero no incluirlos en totales de IVA, hazlo aquí.
                    $impuestosTrasladados[] = [
                        "Impuesto" => $impDetalle["Impuesto"],
                        "Factor"    => $impDetalle["Factor"],
                        "Base"      => $impDetalle["Base"],
                        "Tasa"      => $impDetalle["Tasa"],
                        "Importe"   => $impDetalle["Importe"]
                    ];
                }

                $totalImpuestoPagado += $impDetalle["Importe"];
            }
        }

        $impuestosRetenidos = [];
        if (isset($detalle['impuestos']['retenidos']) && is_array($detalle['impuestos']['retenidos'])) {
            foreach ($detalle['impuestos']['retenidos'] as $impDetalle) {
                $impuestosRetenidos[] = [
                    "Impuesto" => $impDetalle["Impuesto"],
                    "Factor"    => $impDetalle["Factor"],
                    "Base"      => $impDetalle["Base"],
                    "Tasa"      => $impDetalle["Tasa"],
                    "Importe"   => $impDetalle["Importe"]
                ];

                $totalImpuestoPagado += $impDetalle["Importe"];
            }
        }

        $documento = [
            "IdDocumento" => $uuidRelacionado,
            "Moneda" => $moneda,
            "Equivalencia" => $cambio,
            "NumeroParcialidad" => $numeroParcialidad,
            "ImporteSaldoAnterior" => $saldoAnterior,
            "ImportePagado" => $importePagadoFloat,
            "ImporteSaldoInsoluto" => $saldoInsoluto,
            "ObjetoDeImpuesto" => $objetoDeImpuesto,
        ];

        if (!empty($impuestosTrasladados)) {
            $documento["Impuestos"]["Trasladados"] = $impuestosTrasladados;
        }
        if (!empty($impuestosRetenidos)) {
            $documento["Impuestos"]["Retenidos"] = $impuestosRetenidos;
        }

        $documentosRelacionados[] = $documento;
        $insolutos[$idFactura] = $saldoInsoluto;
    }

    $totalBaseTrasladados   = 0;
    $totalImporteTrasladados = 0;
    $totalTrasladosBaseIVA16     = 0.0;
    $totalTrasladosImpuestoIVA16 = 0.0;
    $totalRetencionesIVA         = 0.0;
    $totalRetencionesISR         = 0.0;
    $totalRetencionesIEPS        = 0.0;
    $globalTrasladados = [];
    $globalRetenidos = [];

    foreach ($documentosRelacionados as $doc) {
        if (isset($doc["Impuestos"]["Trasladados"]) && is_array($doc["Impuestos"]["Trasladados"])) {
            foreach ($doc["Impuestos"]["Trasladados"] as $traslado) {
                if (floatval($traslado["Tasa"]) == 0.16 && $traslado["Impuesto"] == 2) {
                    $totalTrasladosBaseIVA16     += floatval($traslado["Base"]);
                    $totalTrasladosImpuestoIVA16 += floatval($traslado["Importe"]);
                }

                $key = $traslado["Impuesto"] . "-" . $traslado["Factor"] . "-" . $traslado["Tasa"];
                if (isset($globalTrasladados[$key])) {
                    // Sumamos las bases e importes
                    $globalTrasladados[$key]["Base"] += floatval($traslado["Base"]);
                    $globalTrasladados[$key]["Importe"] += floatval($traslado["Importe"]);
                } else {
                    $globalTrasladados[$key] = [
                        "Impuesto" => $traslado["Impuesto"],
                        "Factor" => $traslado["Factor"],
                        "Base" => floatval($traslado["Base"]),
                        "Tasa" => $traslado["Tasa"],
                        "Importe" => floatval($traslado["Importe"])
                    ];
                }
            }
        }
        if (isset($doc["Impuestos"]["Retenidos"]) && is_array($doc["Impuestos"]["Retenidos"])) {
            foreach ($doc["Impuestos"]["Retenidos"] as $retenido) {
                switch ($retenido["Impuesto"]) {
                    case 2:
                        $totalRetencionesIVA += round(floatval($retenido["Importe"]), 4);
                        break;
                    case 1:
                        $totalRetencionesISR += round(floatval($retenido["Importe"]), 4);
                        break;
                    case 3:
                        $totalRetencionesIEPS += round(floatval($retenido["Importe"]), 4);
                        break;
                }

                $key = $retenido["Impuesto"];
                if (isset($globalRetenidos[$key])) {
                    $globalRetenidos[$key]["Importe"] += floatval($retenido["Importe"]);
                } else {
                    $globalRetenidos[$key] = [
                        "Impuesto" => $retenido["Impuesto"],
                        "Importe" => floatval($retenido["Importe"])
                    ];
                }
            }
        }
    }

    $impuestosGlobales = [
        "Trasladados" => array_values($globalTrasladados),
        "Retenidos" => array_values($globalRetenidos)
    ];

    if ($totalSaldoInsoluto > $importePagadoFloat) {
        http_response_code(400);
        throw new Exception("El saldo total insoluto es mayor que el importe pagado.");
    }

    $data = [
        "DatosGenerales" => [
            "Version" => "4.0",
            "CSD" => $CSDBase64,
            "LlavePrivada" => $KeyBase64,
            "CSDPassword" => $decryptedPassword,
            "GeneraPDF" => false,
            "CFDI" => "Pago",
            "OpcionDecimales" => 2,
            "NumeroDecimales" => 2,
            "TipoCFDI" => "Pago",
            "EnviaEmail" => false,
            "ReceptorEmail" => "sergioedurdo21@gmail.com",
            "EmailMensaje" => "Factura de ISI Import",
            "noUsarPlantillaHtml" => "true",
        ],
        "Encabezado" => [
            "CFDIsRelacionados" => null,
            "TipoRelacion" => null,
            "FormaPago" => $formaPago,
            "MetodoPago" => 'PUE',
            "Emisor" => [
                "RFC" => $rfcEmisor,
                "NombreRazonSocial" => $razonSocialEmisor,
                "RegimenFiscal" => $regimenFiscEmisor,
                "Direccion" => [
                    [
                        "Calle" => $calleEmisor,
                        "NumeroExterior" => $numExtEmisor,
                        "NumeroInterior" => $numIntEmisor,
                        "Colonia" => $coloniaEmisor,
                        "Localidad" => $localidadEmisor,
                        "Municipio" => $municipioEmisor,
                        "Estado" => $estadoEmisor,
                        "Pais" => $paisEmisor,
                        "CodigoPostal" => $cpEmisor
                    ]
                ]
            ],
            "Receptor" => [
                "RFC" => $data['clienteInfo']['rfc'],
                "NombreRazonSocial" => $data['clienteInfo']['nombre'],
                "UsoCFDI" => $data['clienteInfo']['cfdi'],
                "RegimenFiscal" => $data['clienteInfo']['reg_fiscal'],
                "Direccion" => [
                    "Calle" => $data['clienteInfo']['calle'],
                    "NumeroExterior" => $data['clienteInfo']['num_ext'],
                    "NumeroInterior" => $data['clienteInfo']['num_int'],
                    "Colonia" => $data['clienteInfo']['colonia'],
                    "Localidad" => $data['clienteInfo']['ciudad'],
                    "Municipio" => $data['clienteInfo']['municipio'],
                    "Estado" => $data['clienteInfo']['estado'],
                    "Pais" => $data['clienteInfo']['pais'],
                    "CodigoPostal" => $data['clienteInfo']['cp_factura']
                ]
            ],
            "Fecha" => $fechaExpedicion,
            "Serie" => "AB",
            "Moneda" => $moneda,
            "LugarExpedicion" => $cpEmisor,
            "SubTotal" => 0,
            "Total" => 0,
            "Folio" => $facturaActual,
        ],
        "Conceptos" => [
            [
                "cantidad" => "1",
                "codigoUnidad" => "ACT",
                "descripcion" => "Pago",
                "codigoProducto" => "84111506",
                "precioUnitario" => 0,
                "importe" => 0,
                "objetoDeImpuesto" => $objetoDeImpuesto,
            ]
        ],
        "Complemento" => [
            "TipoComplemento" => 28,
            "PagosV20" => [
                "Pagos" => [
                    [
                        "FechaPago" => $fechaPago,
                        "FormaPago" => $formaPago,
                        "cuentaOrdenante" => $cuentaOrdenante,
                        "rfcCtaOrdenante" => $rfcCtaOrdenante,
                        "cuentaBeneficiario" => $cuentaBeneficiario,
                        "rfcCtaBeneficiario" => $rfcCtaBeneficiario,
                        "Moneda" => "MXN",
                        "TipoCambio" => 1,
                        "DocumentosRelacionados" => $documentosRelacionados,
                        "Impuestos" => $impuestosGlobales
                    ]
                ],
                "Totales" => [
                    "MontoTotalPagos"       => round($importePagado, 4),
                    "TotalTrasladosBase"    => round($totalBaseTrasladados, 4),
                    "TotalTrasladosImpuestos" => round($totalImporteTrasladados, 4)
                ]
            ],
        ],
    ];

    //$data["Complemento"]["PagosV20"]["Impuestos"] = $impuestosGlobales;

    // Calcular totales para los impuestos trasladados (esto ya lo tienes)
    $totalBaseTrasladados   = array_sum(array_column($impuestosTrasladados, 'Base'));
    $totalImporteTrasladados = array_sum(array_column($impuestosTrasladados, 'Importe'));

    if ($totalImpuestoPagado > 0) {
        $montoTotalPagos = round($importePagado, 4);

        // Creamos el arreglo de Totales obligatoriamente con "montoTotalPagos" y solo agregamos los demás 
        // campos si su valor es mayor que 0.
        $totales = [
            "montoTotalPagos" => $montoTotalPagos
        ];
        
        if (round($totalTrasladosBaseIVA16, 2) > 0) {
            $totales["totalTrasladosBaseIVA16"] = round($totalTrasladosBaseIVA16, 4);
        }
        if (round($totalTrasladosImpuestoIVA16, 2) > 0) {
            $totales["totalTrasladosImpuestoIVA16"] = round($totalTrasladosImpuestoIVA16, 4);
        }
        if (round($totalRetencionesIVA, 2) > 0) {
            $totales["totalRetencionesIVA"] = round($totalRetencionesIVA, 4);
        }
        if (round($totalRetencionesISR, 2) > 0) {
            $totales["totalRetencionesISR"] = round($totalRetencionesISR, 4);
        }
        if (round($totalRetencionesIEPS, 2) > 0) {
            $totales["totalRetencionesIEPS"] = round($totalRetencionesIEPS, 4);
        }
        
        // Asignamos el nodo Totales a la estructura final del complemento
        $data["Complemento"]["PagosV20"]["Totales"] = $totales;
    }
    //var_dump($data);
    $responseFactura = $client->request('POST', 'https://testapi.facturoporti.com.mx/servicios/timbrar/json', [
        'json' => $data,
        'headers' => [
            'accept' => 'application/json',
            'authorization' => 'Bearer '.$token,
            'content-type' => 'application/json',
        ],
    ]);

    //print_r($responseFactura);
    $status = $responseFactura->getStatusCode();
    $content = $responseFactura->getBody()->getContents();
    $content = json_decode($content, true);
    $codigo = $content['estatus']['codigo'];
    //echo $content;

    if ($codigo == '000') {
        logToFile($username, $userID, 'Realizó un pago por: '.$importePagado.'; A la(s) factura(s) con id: '.$ids, "success");
        $uuid = '';
        $xml = $content['cfdiTimbrado']['respuesta']['cfdixml'];
        $nombre = 'cfdi_' . date('Y-m-d_H-i-s') . '.xml';
        $ruta = 'xml/'.$nombre;
        file_put_contents($ruta, $xml);
        $uuid = getUUIDForPaymentCfdi($ruta);

        try {
            foreach ($insolutos as $idFactura => $saldoInsoluto) {
                $sql = "UPDATE `facturas` SET `saldoInsoluto`=? WHERE `id`=?";
                $stmt = $con->prepare($sql);
                if ($stmt === false) {
                    throw new Exception("Error en la preparación de la consulta: " . $con->error);
                }
                
                $stmt->bind_param("si", $saldoInsoluto, $idFactura);
                if (!$stmt->execute()) {
                    throw new Exception("Error en la ejecución de la consulta: " . $stmt->error);
                    logToFile($username, $userID, 'Error al ejecutar el INSERT del pago: '.$stmt->error, "error");
                }
                $id = $con->insert_id;
                $stmt->close();
            }
            logToFile($username, $userID, 'Se realizó el UPDATE de las facturas.', "success");
        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
            logToFile($username, $userID, 'Error al ejecutar el INSERT del pago: '.$e->getMessage(), "error");
            exit;
        }

        // Inserción en la tabla pagos y pagos_facturas
        try {
            $sql = "INSERT INTO pagos(importepagado, saldoanterior, saldoinsoluto, parcialidad, uuid, idFactura, fecha, rutaXml) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $con->prepare($sql);
            if ($stmt === false) {
                throw new Exception("Error en la preparación de la consulta: " . $con->error);
            }
            
            $stmt->bind_param("ssssssss", $importePagado, $saldoAnterior, $saldoInsoluto, $numeroParcialidad, $uuid, $idFacturasString, $fechaExpedicion, $nombre);
            if (!$stmt->execute()) {
                throw new Exception("Error en la ejecución de la consulta: " . $stmt->error);
                logToFile($username, $userID, 'Error al ejecutar el INSERT del pago: '.$stmt->error, "error");
            }
            $id = $con->insert_id;
            $stmt->close();
            logToFile($username, $userID, 'Se realizó el INSERT del pago.', "success");

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
            
        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
            logToFile($username, $userID, 'Error al ejecutar el INSERT del pago: '.$e->getMessage(), "error");
            exit;
        }

        // Generar el XML y PDF base64
        try {
            $pdfBase64 = leerXML($ruta, true, $id);
            if (!$pdfBase64) {
                throw new Exception('Error al leer el XML y generar el PDF en base64.');
                logToFile($username, $userID, 'Error al leer el xml del pago realizado', "error", $data_string);
            }
            logToFile($username, $userID, 'Se creó correctamente el pdf del pago realizado', "success", $data_string);
            echo json_encode([
                'status' => 'success',
                'pdf' => $pdfBase64,
                'idFactura' => $idFactura,
                'z' => true
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
            logToFile($username, $userID, 'Error al leer el xml del pago realizado: '.$e->getMessage(), "error", $data_string);
            exit;
        }
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'Parece que los datos de facturación son incorrectos. Corrígelos e intentalo más tarde.',
            'content' => $content,
            'cuerpo' => $data,
        ]);
        logToFile($username, $userID, 'Intento de pago, datos de facturacion incorrectos.', "error", $data_string);
        exit;
    }
} catch (Exception $e) {
    $errorMessage = 'Error en la solicitud de la factura: ' . $e->getMessage();
    echo json_encode([ 'status' => 'error', 'message' => $errorMessage, 'data' => $data, ]);
    logToFile($username, $userID, $errorMessage . ' -- ' . json_encode($data), "error", $data_string);
    exit;
}

