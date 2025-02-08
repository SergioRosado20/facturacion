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

/*$usuario = 'ROBS031020T71';
$password = '@VMnmko74700';*/

$usuario = 'PruebasTimbrado';
$password = '@Notiene1';

$username = $_SESSION['username'];
$userID = $_SESSION['userID'];

$tokenData = [];
$token = "";

// Recibir los datos JSON en bruto
$json = file_get_contents('php://input');
$json_data = json_decode($json, true);

// Decodificar el JSON a un array asociativo
$data = json_decode($json, true);
$formaPago = $data['forma_pago'];
$fechaPago = $data['fecha_pago'];
$facturas = $data['facturas'];
$importePagado = $data['importe_pagado'];
$moneda = $data['moneda'];
$cambio = $data['cambio'];

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
if (isset($data['productos']) && is_array($data['productos'])) {
    foreach ($data['productos'] as $producto) {
        // Verificar que las claves necesarias existan y no sean nulas
        if (isset($producto["cantidad"], $producto["precio"], $producto["claveUni"], $producto["unidad"], $producto["clavePS"])) {
            $importe = number_format($producto["cantidad"] * $producto["precio"], 2, '.', '');
            $impuestoImporte = number_format($importe * 0.16, 2, '.', '');
            $concepto = [
                "Cantidad" => $producto["cantidad"],
                "CodigoUnidad" => $producto["claveUni"],
                "Unidad" => $producto["unidad"],
                "CodigoProducto" => $producto["clavePS"],
                "Producto" => $producto["nombre"] ?? "Servicio",
                "PrecioUnitario" => number_format($producto["precio"], 2, '.', ''),
                "Importe" => $importe,
                "ObjetoDeImpuesto" => "02",
                "Impuestos" => [
                    [
                        "TipoImpuesto" => 1,
                        "Impuesto" => 2,
                        "Factor" => 1,
                        "Base" => $importe,
                        "Tasa" => "0.160000",
                        "ImpuestoImporte" => $impuestoImporte
                    ]
                ]
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
            'iva' => $factura['iva'],
            'importe' => $factura['importe'],
            'moneda' => $moneda,
            'cambio' => $cambio,
        ];
    }

    $ids = array_keys($idFacturas);
    $idFacturasString = implode(',', $ids);
    
    $insolutos = [];
    //print_r($idFacturas);

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
        $importePagadoFloat = number_format((float)$detalle['importe'], 2, '.', '');
        $saldoAnteriorFloat = floatval(number_format((float)$saldoAnterior, 2, '.', ''));

        $objetoDeImpuesto = $detalle['iva'] ? '02' : '01';
        $importePagadoSinIva = $detalle['iva'] ? (bcdiv($detalle['importe'], "1.16", 2)) : $detalle['importe'];
        $impuestoPago = $detalle['iva'] ? bcsub($detalle['importe'], $importePagadoSinIva, 2) : 0;
        $saldoInsoluto = bcsub($saldoAnterior, $importePagadoSinIva, 2);

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

        if($detalle['iva']) {
            $documento["Impuestos"] = [
                "Trasladados" => [
                    [
                        "Impuesto" => 2,
                        "Factor" => 1,
                        "Base" => $importePagadoSinIva,
                        "Tasa" => "0.160000",
                        "Importe" => $impuestoPago
                    ]
                ]
            ];
        }

        $documentosRelacionados[] = $documento;
        $insolutos[$idFactura] = $saldoInsoluto;
        $totalImpuestoPagado = bcadd($totalImpuestoPagado, $impuestoPago, 2);
        $totalTrasladosBase = bcadd($totalTrasladosBase, $importePagadoSinIva, 2);
    }

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
            "Moneda" => "XXX",
            "LugarExpedicion" => "42501",
            "SubTotal" => 0,
            "Total" => 0,
            "Folio" => $facturaActual,
            "adicionales" => [[
                "Folio" => "6475"
            ]],
        ],
        "Conceptos" => [
            [
                "cantidad" => "1",
                "codigoUnidad" => "ACT",
                "descripcion" => "Pago",
                "codigoProducto" => "84111506",
                "precioUnitario" => 0,
                "importe" => 0,
                "objetoDeImpuesto" => "01",
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
                    ]
                ],
                "Totales" => [
                    "MontoTotalPagos" => $importePagado
                ]
            ],
        ],
    ];

    if ($totalImpuestoPagado > 0) {
        $data["Complemento"]["PagosV20"]["Pagos"][0]["Impuestos"] = [
            "Trasladados" => [
                [
                    "Impuesto" => 2,
                    "Factor" => 1,
                    "Base" => $totalTrasladosBase,
                    "Tasa" => "0.160000",
                    "Importe" => $totalImpuestoPagado
                ]
            ]
        ];
    
        $data["Complemento"]["PagosV20"]["Totales"]["TotalTrasladosBaseIVA16"] = $totalTrasladosBase;
        $data["Complemento"]["PagosV20"]["Totales"]["TotalTrasladosImpuestoIVA16"] = $totalImpuestoPagado;
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

