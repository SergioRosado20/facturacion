<?php
/*ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);*/
require 'pdfFinezza.php';
require_once 'log_helper.php';
require_once('vendor/autoload.php');
require_once "cors.php";
cors();

date_default_timezone_set('America/Mexico_City');
session_start();

$client = new \GuzzleHttp\Client();

$usuario = 'ROBS031020T71';
$password = '@VMnmko74700';

//$usuario = 'PruebasTimbrado';
//$password = '@Notiene1';

$username = $_SESSION['username'];

$tokenData = [];
$token = "";

// Recibir los datos JSON en bruto
$json = file_get_contents('php://input');
$json_data = json_decode($json, true);

// Decodificar el JSON a un array asociativo
$data = json_decode($json, true);

$prods = $data['prods']; // Acceder a 'prods'
$descuento = isset($data['descuento']) ? $data['descuento'] : null; // Acceder a 'descuento'
$descuento = (float)$descuento;
// Obtener los IDs de la cadena del GET, y convertirlos en un array
$idFactura = $data['id_factura'];
$cuenta = $data['cuenta'];
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

if ($con->connect_error) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Conexión a la base de datos fallida'
    ]);
    exit;
}

if (isset($idFactura)) {

    // Consulta SQL para obtener los datos de las cotizaciones y encabezados
    $sql = "SELECT * FROM facturas WHERE id = ?";

    $stmt = $con->prepare($sql);

    if ($stmt === false) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Error al preparar la consulta: ' . $con->error
        ]);
        exit;
    }

    // Vincular los parámetros dinámicamente
    $stmt->bind_param("i", $idFactura);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result === false) {
        throw new Exception("Error al obtener el resultado: " . $stmt->error);
    }

    // Procesar los resultados
    $data = [];
    $nuevoTotal = 0;

    while ($row = $result->fetch_assoc()) {
        $fac_id = $row['id'];
        $cliente = $row['cliente'];

        if (!isset($data[$fac_id])) {
            $data[$fac_id] = [
                'id' => $row['id'],
                'cliente' => $row['cliente'],
                'fecha' => $row['fecha'],
                'subtotal' => $row['subtotal'],
                'total' => $row['total'],
                'status' => $row['status'],
                'metodo_pago' => $row['metodoPago'],
                'forma_pago' => $row['formaPago'],
                'moneda' => $row['moneda'],
                'cambio' => $row['cambio'],
                'xml' => $row['rutaXml'],
                'productos' => []
            ];
        }

        //print_r($row['rutaXml']);
        $xmlData = leerXml('xml/' . $row['rutaXml']);
        //print_r($xmlData);
        if(isset($xmlData)) {
            $data[$fac_id]["clienteInfo"] = [
                'rfc' => $xmlData['Receptor']['Rfc'],
                'nombre' => $xmlData['Receptor']['Nombre'],
                'reg_fiscal' => $xmlData['Receptor']['RegimenFiscalReceptor'],
                'cfdi' => $xmlData['Receptor']['UsoCFDI'],
                'cp_factura' => $xmlData['Receptor']['DomicilioFiscalReceptor'],
            ];
        }

        if ($prods) {
            $data[$fac_id]['productos'] = $prods;

            // Calcular el nuevo total usando solo el array recibido de productos
            $nuevoTotal = array_reduce($prods, function ($carry, $item) {
                // Si la cantidad es proporcionada, multiplicar por el precio individual (indv)
                $cantidad = isset($item['cantidad']) ? $item['cantidad'] : 1; // Si no hay cantidad, usar 1 por defecto
                return $carry + (floatval($item['precio']) * $cantidad); // Multiplicar precio por cantidad
            }, 0);

            // Asignar el total calculado
            $data[$fac_id]['total'] = $nuevoTotal;
        } /*else {
            // Si no se recibió el array de productos, puedes hacer un cálculo con los productos de la base de datos
            $productoData = [
                'id_serv' => $row['id_serv'],
                'servicio' => $row['servicio'],
                'cantidad' => $row['cantidad'],
                'indv' => $row['indv'],
                'total' => $row['total']
            ];

            // Asignar el producto y recalcular el total
            $data[$fac_id]['productos'][] = $productoData;

            // Recalcular el total con la cantidad de cada producto
            $nuevoTotal = floatval($row['indv']) * floatval($row['cantidad']);
            $data[$fac_id]['total'] += $nuevoTotal;
        }*/
    }

    $sqlFacturaCount = "SELECT MAX(idNotaPago) AS max_id FROM notas_pagos WHERE 1";

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
    $facturaActual = intval($facturaActual['max_id']) + 1;

    $stmt->close();

    // Si hay múltiples IDs, reemplazar el total con la suma calculada
    if (!$isSingleId) {
        foreach ($data as &$cotizacion) {
            $cotizacion['total'] = $nuevoTotal;
        }
    } else {
        $cotizacion['total'] = $nuevoTotal;
    }

    // Convertir el resultado a JSON
    $rawJson = json_encode(array_values($data));
    //echo $rawJson;
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
    $sql = "SELECT * FROM `cuenta_factura` WHERE id = ?";

    // Ejecutar la consulta (puedes usar tu conexión y método habitual)
    $stmt = $con->prepare($sql);
    $stmt->bind_param("i", $cuenta);
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
$productos = json_decode($rawJson, true);
foreach($productos[0]['productos'] as $producto) {
    $importe = number_format($producto["cantidad"] * $producto["precio"], 2, '.', '');
    $impuestoImporte = number_format($importe * 0.16, 2, '.', '');

    $concepto = [
        "Cantidad" => $producto["cantidad"],
        "CodigoUnidad" => $producto["claveUni"],
        "Unidad" => $producto["unidad"],
        "Descripcion" => "",
        "CodigoProducto" => $producto["clavePS"],
        "Producto" => $producto["nombre"] ?? "Producto",
        "PrecioUnitario" => number_format($producto["precio"], 2, '.', ''),
        "Importe" => $importe,
    ];

    if(isset($producto["Impuestos"])) {
        $concepto["ObjetoDeImpuesto"] = "02";

        $concepto["Impuestos"] = [
            [
                "TipoImpuesto" => 1,
                "Impuesto" => 2,
                "Factor" => 1,
                "Base" => $importe,
                "Tasa" => "0.160000",
                "ImpuestoImporte" => $impuestoImporte
            ]
        ];
    } else {
        $concepto["ObjetoDeImpuesto"] = "01";
    }

    $conceptos[] = $concepto;
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

    $json = json_decode($rawJson, true);

    $subTotal = $json[0]['total'];

    // Redondea el total a 2 decimales para asegurar la precisión
    $totalRedondeado = round((float)$subTotal, 2);
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

    // Obtener UUID de factura
    $sql = "SELECT uuid FROM facturas WHERE id = ?";
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
    $uuidRelacionado = $row['uuid'];

    $data = [
        "DatosGenerales" => [
            "Version" => "4.0",
            "CSD" => $CSDBase64,
            "LlavePrivada" => $KeyBase64,
            "CSDPassword" => $decryptedPassword,
            "GeneraPDF" => false,
            "CFDI" => "NotaCredito",
            "OpcionDecimales" => 2,
            "NumeroDecimales" => 2,
            "TipoCFDI" => "Egreso",
            "EnviaEmail" => false,
            "ReceptorEmail" => "sergioedurdo21@gmail.com",
            "EmailMensaje" => "Factura de ISI Import",
            "noUsarPlantillaHtml" => "true",
        ],
        "Encabezado" => [
            "CFDIsRelacionados" => $uuidRelacionado,
            "TipoRelacion" => "01",
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
                "RFC" => $json[0]['clienteInfo']['rfc'],
                "NombreRazonSocial" => $json[0]['clienteInfo']['nombre'],
                "UsoCFDI" => $json[0]['clienteInfo']['cfdi'],
                "RegimenFiscal" => $json[0]['clienteInfo']['reg_fiscal'],
                "Direccion" => [
                    "Calle" => $json[0]['clienteInfo']['calle'],
                    "NumeroExterior" => $json[0]['clienteInfo']['num_ext'],
                    "NumeroInterior" => $json[0]['clienteInfo']['num_int'],
                    "Colonia" => $json[0]['clienteInfo']['colonia'],
                    "Localidad" => $json[0]['clienteInfo']['ciudad'],
                    "Municipio" => $json[0]['clienteInfo']['municipio'],
                    "Estado" => $json[0]['clienteInfo']['estado'],
                    "Pais" => $json[0]['clienteInfo']['pais'],
                    "CodigoPostal" => $json[0]['clienteInfo']['cp_factura']
                ]
            ],
            "Fecha" => $fechaExpedicion,
            "Serie" => strval($facturaActual),
            "MetodoPago" => $json[0]['metodo_pago'],
            "FormaPago" => $json[0]['forma_pago'],
            "Moneda" => "MXN",
            "LugarExpedicion" => "42501",
            "SubTotal" => $subTotal,
            "Total" => $totalAjustado,
            "Descuento" => $descuento,
            "Folio" => strval($facturaActual),
            "adicionales" => [[
                "Folio" => "6475"
            ]],
        ],
        "Conceptos" => $conceptos,
    ];
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
    //print_r($content);

    if ($codigo == '000') {
        $xml = $content['cfdiTimbrado']['respuesta']['cfdixml'];
        $nombre = 'cfdi_' . date('Y-m-d_H-i-s') . '.xml';
        $ruta = 'xml/'.$nombre;
        file_put_contents($ruta, $xml);
        $uuid = getUUIDForNotaCredito($ruta);

        // Generar el XML y PDF base64
        try {
            $sql = "INSERT INTO notas_pagos(idFactura, uuid, fecha, rutaXml, total, emisor) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $con->prepare($sql);
            if ($stmt === false) {
                throw new Exception("Error en la preparación de la consulta: " . $con->error);
            }
            $stmt->bind_param("issssi", $idFactura, $uuid, $fechaExpedicion, $nombre, $totalAjustado, $cuenta);
            if (!$stmt->execute()) {
                throw new Exception("Error en la ejecución de la consulta: " . $stmt->error);
            }
            $idNota = $con->insert_id;
            $stmt->close();
        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
            exit;
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

        try {
            $sql = "SELECT f.emisor, f.pais_receptor, f.estado_receptor, f.municipio_receptor, f.ciudad_receptor, f.colonia_receptor, f.num_ext_receptor, f.num_int_receptor, f.calle_receptor, f.cp_receptor
                FROM `notas_pagos`
                INNER JOIN `facturas` f ON f.id = `notas_pagos`.idFactura
                WHERE `notas_pagos`.idNotaPago = ?";
            $stmt = $con->prepare($sql);
            $stmt->bind_param("i", $idNota);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $receptorBD = $row;

            $pdfBase64 = leerXML($ruta, true, $idNota, $cuenta, $receptorBD['pais_receptor'], $receptorBD['estado_receptor'], $receptorBD['municipio_receptor'], $receptorBD['ciudad_receptor'], $receptorBD['colonia_receptor'], $receptorBD['num_ext_receptor'], $receptorBD['num_int_receptor'], $receptorBD['calle_receptor'], $receptorBD['cp_receptor']);
            if (!$pdfBase64) {
                throw new Exception('Error al leer el XML y generar el PDF en base64.');
            }
        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
            exit;
        }

        echo json_encode([
            'status' => 'success',
            'pdf' => $pdfBase64,
            'idFactura' => $idFactura,
            'z' => true
        ]);
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'Parece que los datos de facturación son incorrectos. Corrígelos e intentalo más tarde.',
            'content' => $content,
            'cuerpo' => $data,
        ]);
        exit;
    }
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Error en la solicitud de la factura: ' . $e->getMessage()
    ]);
    exit;
}

?>