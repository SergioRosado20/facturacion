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

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$database_host = $_ENV['DATABASE_HOST'] ?? '';
$database_user = $_ENV['DATABASE_USER'] ?? '';
$database_password = $_ENV['DATABASE_PASSWORD'] ?? '';
$database_name = $_ENV['DATABASE_NAME'] ?? '';
$enc_key = $_ENV['ENCRYPT_KEY'] ?? '';

$con = new mysqli($database_host, $database_user, $database_password, $database_name);

// Decodificar el JSON a un array asociativo
$data = json_decode(file_get_contents('php://input'), true);
$data_string = json_encode($data);

if (isset($data['cuerpo'])) {
    $uuidRelacionado = isset($data['cuerpo']['uuidRelacionado']) ? $data['cuerpo']['uuidRelacionado'] : null;
    $anticipo = $data['cuerpo']['anticipo'];
    $anticipo = $anticipo ? 1 : 0;
    $pagado = $data['cuerpo']['pagado'];
    $pagado = $pagado ? 1 : 0;
    $moneda = $data['cuerpo']['Moneda'];
    $cambio = $data['cuerpo']['tipoCambio'];
    $productos = $data['cuerpo']['Conceptos'];
    $receptor = $data['cuerpo']['Receptor'];
    //$emisor = $data['cuerpo']['Emisor'];
    //$token = $data['cuerpo']['Token'];
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'No se recibieron datos',
        'recibido' => $data
    ]);
    logToFile($username, $userID, 'Error al recibir los datos en gen_factura.php', "error", $data_string);
    exit;
}

logToFile($username, $userID, 'Informacion recibida en gen_factura.php', "success", $data_string);

///////////////////////////////////////////////////////////////////// INICIA ZONA DE CONSUTLAS Y CÁLCULOS

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

if (empty($token)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Token de servicio invalido.',
        'desc' => $e->getMessage(),
    ]);
    logToFile($username, $userID, 'Error al recibir el token', "error", $e->getMessage());
    exit;
}

function calcularValorEsperado($conceptos) {
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

function ajustarTotalSiEsNecesario($totalReportado, $valorEsperado) {
    $diferencia = abs($totalReportado - $valorEsperado);
    
    if ($diferencia <= 0.02) {
        return $valorEsperado;
    }
    
    return $totalReportado;
}

$conceptos = [];
$subTotal = 0;
$totalImpuestoTrasladado = 0;
$totalImpuestoRetenido = 0;
$descuentos = 0;
$anticipos = 0;
// Iterar sobre cada producto y formatearlo para el array Conceptos
foreach($productos as $producto) {
    $descuento = isset($producto['descuento']) ? $producto['descuento'] : 0;
    $importe = bcmul($producto["cantidad"], $producto["precio"], 2);
    $importeSinDesc = bcsub($importe, $descuento, 2);
    $descuentos += $descuento;

    $impuestos = $producto["impuestos"];

    $nodoImp = [];
    if ($producto["clavePS"] !== "84111506") {
        foreach($impuestos as $impuesto) {
            $impuestoImporte = number_format($importeSinDesc * floatval($impuesto["tasaImp"]), 2, '.', '');
            $prodImp = [
                "TipoImpuesto" => $impuesto["tipoImp"], //int
                "Impuesto" => $impuesto["impuesto"],    //int
                "Factor" => $impuesto["factorImp"],     //int
                "Base" => $importeSinDesc,              //double
                "Tasa" => $impuesto["tasaImp"],         //string:  "0.160000", "0.08000"
                "ImpuestoImporte" => $impuestoImporte   //double
            ];
    
            $nodoImp[] = $prodImp;
            if($impuesto['tipoImp'] == 1) {
                $totalImpuestoTrasladado += $impuestoImporte;
            } else if($impuesto['tipoImp'] == 2) {
                $totalImpuestoRetenido += $impuestoImporte;
            }
        }
    }

    $informacionAduanera = [];
    if(isset($producto['informacionAduanera']) && !is_null($producto['informacionAduanera'])) {
        $numeroPedimento = $producto['informacionAduanera'][0]['numeroPedimento'];

        $informacionAduanera[]['NumeroPedimento'] = $numeroPedimento;
    }

    $concepto = [
        "Cantidad" => $producto["cantidad"],
        "CodigoUnidad" => $producto["claveUni"],
        "Unidad" => $producto["unidad"],
        "CodigoProducto" => $producto["clavePS"],
        "Producto" => $producto["nombre"],
        "PrecioUnitario" => number_format($producto["precio"], 2, '.', ''),
        "Importe" => $importe,
        "ObjetoDeImpuesto" => $producto["objetoImp"],   //string
        "Impuestos" => $nodoImp,
        "informacionAduanera" => !empty($informacionAduanera) ? $informacionAduanera : null,
    ];

    if ($descuento != 0) {
        $concepto["Descuento"] = number_format($descuento, 2, '.', '');
    }

    $conceptos[] = $concepto;
    if ($producto["clavePS"] === "84111506") { // Verifica si es un anticipo
        $anticipos = bcadd($anticipos, abs($importe), 2);
        if($anticipo === 1) {
            $subTotal += $producto["subTotal"];
        }
    } else {
        $subTotal += $producto["subTotal"];
    }
}

$totalImp = bcsub($totalImpuestoTrasladado, $totalImpuestoRetenido, 2);
$subT = bcsub($subTotal, $descuentos, 2);
$total = bcadd($subT, $totalImp, 2);
$totalRedondeado = round((float)$total, 2);
$totalReportado = (float)$totalRedondeado;
$valorEsperado = calcularValorEsperado($conceptos);
$totalAjustado = ajustarTotalSiEsNecesario($totalReportado, $valorEsperado);
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
    
    /*
    "CSD" => $CSDBase64,
    "LlavePrivada" => $KeyBase64,
    "CSDPassword" => "",*/

    $data = [
        "DatosGenerales" => [
            "Version" => "4.0",
            "CSD" => $CSDBase64,
            "LlavePrivada" => $KeyBase64,
            "CSDPassword" => $decryptedPassword,
            "GeneraPDF" => false,
            "CFDI" => "Factura",
            "OpcionDecimales" => 2,
            "NumeroDecimales" => 2,
            "TipoCFDI" => "Ingreso",
            "EnviaEmail" => false,
            "EmailMensaje" => "Factura de ISI Import",
            "noUsarPlantillaHtml" => "true",
        ],
        "Encabezado" => [
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
                "RFC" => $receptor['rfc'],
                "NombreRazonSocial" => $receptor['nombre'],
                "UsoCFDI" => $receptor['cfdi'],
                "RegimenFiscal" => $receptor['regimen'],
                "Direccion" => [            
                    "Calle" => $receptor['calle'],
                    "NumeroExterior" => $receptor['numext'],
                    "NumeroInterior" => $receptor['numint'],
                    "Colonia" => $receptor['colonia'],
                    "Localidad" => $receptor['ciudad'],
                    "Municipio" => $receptor['municipio'],
                    "Estado" => $receptor['estado'],
                    "Pais" => $receptor['pais'],
                    "CodigoPostal" => $receptor['cp']
                ]
            ],
            "Fecha" => $fechaExpedicion,
            "Serie" => "AB",
            "MetodoPago" => $receptor['metodoP'],
            "FormaPago" => $receptor['formaP'],
            "Moneda" => $moneda,
            "LugarExpedicion" => $cpEmisor,
            "SubTotal" => $subTotal,
            "Total" => $totalAjustado,
            "Folio" => $facturaActual,
            "adicionales" => [[
                "Folio" => "6475"
            ]],
        ],
        "Conceptos" => $conceptos,
    ];

    if($moneda !== 'MXN') {
        $data['Encabezado']['TipoCambio'] = $cambio;
    }
    if(!empty($uuidRelacionado)) {
            $data['Encabezado']['TipoRelacion'] = "07";
            $data['Encabezado']['cfdIsRelacionados'] = $uuidRelacionado;
    }
    //var_dump($data);
    //print_r($data);
    $responseFactura = $client->request('POST', 'https://api.facturoporti.com.mx/servicios/timbrar/json', [
        'json' => $data,
        'headers' => [
            'accept' => 'application/json',
            'authorization' => 'Bearer '.$token,
            'content-type' => 'application/json',
        ],
    ]);
    
    
    //print_r($responseFactura);
    $content = $responseFactura->getBody()->getContents();
    $content = json_decode($content, true);
    $codigo = $content['estatus']['codigo'];
    //echo $content;

    if ($codigo == '000') {
        logToFile($username, $userID, 'Se generó correctamente la factura', "success");
        // Generar el XML y PDF base64
        try {
            $xml = $content['cfdiTimbrado']['respuesta']['cfdixml'];
            $nombre = 'cfdi_' . date('Y-m-d_H-i-s') . '.xml';
            $ruta = 'xml/'.$nombre;
            file_put_contents($ruta, $xml);

            $sql = "SELECT COUNT(*) AS total FROM facturas WHERE 1";

            $stmt = $con->prepare($sql);

            if ($stmt === false) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Error al preparar la consulta: ' . $con->error
                ]);
                exit;
            }

            $stmt->execute();
            $result = $stmt->get_result();

            if ($result) {
                $row = $result->fetch_assoc();
                $id = intval($row['total']) + 1; // Aquí accedes al alias 'total'
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Error al obtener el resultado'
                ]);
            }


            $pdfBase64 = leerXML($ruta, true, $id);
            if (!$pdfBase64) {
                throw new Exception('Error al leer el XML y generar el PDF en base64.');
            }

        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
            logToFile($username, $userID, 'Error al actualizar el historico', "error", $e->getMessage());
            exit;
        }

        // Inserción en la tabla `facturas`
        try {
            $data = leerXML($ruta);
            $tipoCFDI = $data['TipoDeComprobante'];
            $uuid = $data['Complemento']['UUID'];
            $moneda = $data['Moneda'];
            $serie = $data['Serie'];
            $folio = $data['Folio'];
            $sello = $data['Sello'];

            if($receptor['metodoP'] == 'PPD') {
                $saldoInsoluto = $totalAjustado;
            } else {
                $saldoInsoluto = '0.00';
            }

            $status = '1'; // Status Activa
            $sqlBase64 = "INSERT INTO `facturas`(`status`, `total`, `cliente`, `base64`, `rutaXml`, `servicios`, `fecha`, `uuid`, `moneda`, `tipoCfdi`, `metodoPago`, `formaPago`, `lugarExpedicion`, `subTotal`, `serie`, `folio`, `cfdi`, `saldoInsoluto`, `pagado`, `anticipo`) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            $stmt = $con->prepare($sqlBase64);
            if ($stmt === false) {
                throw new Exception("Error en la preparación de la consulta: " . $con->error);
            }

            $prodsJson = json_encode($productos);
            $fechaActual = date('Y-m-d H:i:s');

            // Vincular parámetros y ejecutar
            $stmt->bind_param("isssssssssssssssssii", $status, $totalAjustado, $receptor['nombre'], $pdfBase64, $nombre, $prodsJson, $fechaActual, $uuid, $moneda, $tipoCFDI, $receptor['metodoP'], $receptor['formaP'], $cpEmisor, $subTotal, $serie, $folio, $sello, $saldoInsoluto, $pagado, $anticipo);
            if (!$stmt->execute()) {
                throw new Exception("Error en la ejecución de la consulta: " . $stmt->error);
            }

            // Obtener el ID de la última inserción
            $idFactura = $con->insert_id;

            $stmt->close();

            if (!empty($uuidRelacionado)) {
                // Descomponer el string en un array usando el punto y coma como separador
                $uuids = explode(';', $uuidRelacionado);
            
                // Preparar la consulta de actualización
                $sqlUpdate = "UPDATE facturas SET id_relacion = ? WHERE uuid = ?";
                $stmtUpdate = $con->prepare($sqlUpdate);
                
                if ($stmtUpdate === false) {
                    throw new Exception("Error en la preparación de la consulta de actualización: " . $con->error);
                }
            
                // Recorrer cada UUID y actualizar en la BD
                foreach ($uuids as $uuid) {
                    $uuid = trim($uuid); // Eliminar espacios en blanco extra
                    if (!empty($uuid)) { // Evitar ejecutar la consulta con un UUID vacío
                        $stmtUpdate->bind_param("is", $idFactura, $uuid);
                        if (!$stmtUpdate->execute()) {
                            throw new Exception("Error en la ejecución de la consulta de actualización: " . $stmtUpdate->error);
                        }
                    }
                }
                $stmtUpdate->close();
            }

            logToFile($username, $userID, 'Inserción de la factura a la bd', "success");

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

            logToFile($username, $userID, 'Inserción de la factura a la bd', "success");

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
            logToFile($username, $userID, 'Error al leer el XML', "error", $e->getMessage());
            exit;
        }

    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'Parece que los datos de facturación son incorrectos. Corrígelos e intentalo más tarde.',
            'content' => $content,
            'cuerpo' => $data,
        ]);
        logToFile($username, $userID, 'Error con los datos de facturación: '.json_encode($content), "error", json_encode($data));
        exit;
    }
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Error en la solicitud de la factura: ' . $e->getMessage(),
        'data' => $data,
    ]);
    logToFile($username, $userID, 'Error en la solicitud de la factura: '.$e->getMessage(), "error", $e->getMessage());
    exit;
}

?>