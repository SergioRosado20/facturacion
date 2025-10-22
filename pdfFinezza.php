<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('memory_limit', '512M');
set_time_limit(300);
error_reporting(E_ALL);
header('Content-Type: text/html; charset=utf-8');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
require('fpdf/fpdf.php');
require 'vendor/autoload.php';
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Color\Color;
require_once "cors.php";
require_once "log_helper.php";
require_once "constants.php";
cors();

$data = json_decode(file_get_contents('php://input'), true);
logToFile('0', '0', 'data recibida en pdfFinezza.php', 'info', json_encode($data, true));


if($_GET['preview'] && $data) {
    //print_r($data);
    $pdfBase64 = arrayPdf($data);
    if ($pdfBase64) {
        // Decodifica el Base64
        echo json_encode([
            'status' => 'success',
            'pdf' => $pdfBase64
        ]);
        exit;
    } else {
        echo 'Error: No se pudo generar el PDF.';
    }
}

function convert_to_utf8($data) {
    if (is_array($data)) {
        return array_map('convert_to_utf8', $data);
    } elseif (is_string($data)) {
        return mb_convert_encoding($data, 'UTF-8', 'UTF-8');
    } else {
        return $data;
    }
}

if($_GET['archivo'] && $_GET['pdf'] && $_GET['id']) {
    $archivo = $_GET['archivo'];
    //echo "Archivo recibido: $archivo\n";

    // Llama a la función con los valores correctos
    $pdfBase64 = leerXML($archivo, $_GET['pdf'], $_GET['id']);

    if ($pdfBase64) {
        // Decodifica el Base64
        $pdfContent = base64_decode($pdfBase64);

        // Configura los encabezados para mostrar el PDF en el navegador
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="pdf.pdf"');
        header('Content-Length: ' . strlen($pdfContent));

        // Envía el contenido del PDF
        echo $pdfContent;
        exit;
    } else {
        echo 'Error: No se pudo generar el PDF.';
    }
}

function sanitizeXML($xml) {
    // Reemplazar "@attributes" con un nombre de elemento válido
    $xml = str_replace('<@attributes>', '<attributes>', $xml);
    $xml = str_replace('</@attributes>', '</attributes>', $xml);
    
    // Reemplazar otros caracteres especiales no válidos en los nombres de los elementos si es necesario
    // Puedes agregar más reglas aquí si es necesario.
    
    return $xml;
}

function removeNamespacesFromXML($xml) {
    // Cargar el XML como un objeto SimpleXMLElement sin sanitización
    $xmlObject = simplexml_load_string($xml);

    // Si no se pudo cargar el XML, lanzar un error
    if ($xmlObject === false) {
        throw new Exception("Error al cargar el XML");
    }

    // Convertir el objeto en un JSON y luego de vuelta a un array para eliminar namespaces
    $json = json_encode($xmlObject);
    $array = json_decode($json, true);

    // Convertir el array nuevamente a un XML limpio, sin namespaces
    $xmlClean = new SimpleXMLElement('<root/>');
    arrayToXML($array, $xmlClean);

    return $xmlClean->asXML();
}

function arrayToXML($data, &$xmlData) {
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            if (is_numeric($key)) {
                $key = 'item' . $key; // Convertir las claves numéricas a etiquetas XML válidas
            }
            $subnode = $xmlData->addChild("$key");
            arrayToXML($value, $subnode);
        } else {
            $xmlData->addChild("$key", htmlspecialchars("$value"));
        }
    }
}

function getUUIDForPaymentCfdi($nombreArchivoXml): ?string
{
    if (!file_exists($nombreArchivoXml)) {
        echo 'Error: El archivo XML no existe.';
        return null;
    }

    try {
        $xmlContent = file_get_contents($nombreArchivoXml);
        // Proceso de sanitización y carga de SimpleXML
        //$cleanXML = removeNamespacesFromXML($xmlContent);
        libxml_use_internal_errors(true);
        // Convertir el string XML limpio en un objeto SimpleXMLElement
        $xml = simplexml_load_string($xmlContent);
        //print_r($xml);
        if ($xml === false) {
            echo "Error al cargar el XML: ";
            foreach (libxml_get_errors() as $error) {
                echo $error->message;
            }
        } else {
            $xml->registerXPathNamespace('tfd', 'http://www.sat.gob.mx/TimbreFiscalDigital');

            $uuidNode = $xml->xpath('//tfd:TimbreFiscalDigital');
            if (!empty($uuidNode)) {
                return (string) $uuidNode[0]['UUID'];
            } else {
                return null;
            }
        }
    } catch (Exception $e) {
        echo "Error al generar el PDF: " . $e->getMessage();
    }
    return null;
}

function getUUIDForNotaCredito(string $nombreArchivoXml): ?string
{
    // Leer el archivo XML directamente
    if (!file_exists($nombreArchivoXml)) {
        echo 'Error: El archivo XML no existe.';
        return null;
    }

    // Leer el archivo XML
    try {
        $xmlContent = file_get_contents($nombreArchivoXml);
        libxml_use_internal_errors(true);
        // Convertir el string XML limpio en un objeto SimpleXMLElement
        $xml = simplexml_load_string($xmlContent);
        //print_r($xml);
        if ($xml === false) {
            echo "Error al cargar el XML: ";
            foreach (libxml_get_errors() as $error) {
                echo $error->message;
            }
        } else {
            $xml->registerXPathNamespace('tfd', 'http://www.sat.gob.mx/TimbreFiscalDigital');

            // Complemento (Timbre Fiscal Digital)
            $complemento = $xml->xpath('//cfdi:Complemento/tfd:TimbreFiscalDigital');
            return $complemento[0]['UUID'];
        }
    } catch (Exception $e) {
        echo "Error al generar el PDF: " . $e->getMessage();
    }
    return null;
}

function leerXML($nombreArchivoXml, $pdf = false, $id = null, $emisor = null, $pais = null, $estado = null, $municipio = null, $ciudad = null, $colonia = null, $numExt = null, $numInt = null, $calle = null, $cp = null, $manzana = null) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
    
    $database_host = $_ENV['DATABASE_HOST'] ?? '';
    $database_user = $_ENV['DATABASE_USER'] ?? '';
    $database_password = $_ENV['DATABASE_PASSWORD'] ?? '';
    $database_name = $_ENV['DATABASE_NAME'] ?? '';
    $enc_key = $_ENV['ENCRYPT_KEY'] ?? '';
    
    $con = new mysqli($database_host, $database_user, $database_password, $database_name);
    $con->set_charset("utf8mb4");

    // Leer el archivo XML directamente
    if (!file_exists($nombreArchivoXml)) {
        echo 'Error: El archivo XML no existe. Ruta: ' . realpath($nombreArchivoXml);
        return;
    }

    if($emisor) {
        try {
            $sql = "SELECT * FROM `cuenta_factura` WHERE id = $emisor";
        
            // Ejecutar la consulta (puedes usar tu conexión y método habitual)
            $stmt = $con->prepare($sql);
            $stmt->execute();
            // Obtener el resultado
            $result = $stmt->get_result();
        
            if ($result && $row = $result->fetch_assoc()) {
                $emisor = $row;
                logToFile('0', '0', 'emisor encontrado en pdfFinezza.php', 'info', json_encode($emisor, true));
                //print_r($emisor);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'No se encontró ningún registro en la tabla token 444.',
                ]);
            }
        } catch (\Exception $e) {
            echo json_encode([
            'status' => 'error',
            'message' => 'Ha ocurrido un error inesperado.',
            'desc' => $e->getMessage(),
            'sql' => $sql,
            ]);
            exit;
        }
        
        $arrEmisor = [
            "Rfc" => $emisor["rfc"],
            "Nombre" => $emisor["nombre"],
            "RegimenFiscal" => $emisor["regimen"],
            "domicilioEmisor" => $emisor["cp"] . ', ' . $emisor["estado"] . ', ' . $emisor["municipio"] . ', ' . $emisor["colonia"] . ', ' . $emisor["manzana"] . ', ' . $emisor["calle"] . ', ' . $emisor["num_ext"] . ' ' . $emisor["num_int"],
            "LugarExpedicion" => $emisor["cp"],
        ];
    } else if($id) {

        try {
            $sql = "SELECT f.emisor, c.nombre, c.rfc, c.regimen, c.cp, c.estado, c.municipio, c.colonia, c.calle, c.num_ext, c.num_int
                    FROM `facturas` as f
                    INNER JOIN `cuenta_factura` as c ON f.emisor = c.id
                    WHERE f.id = $id";

            // Ejecutar la consulta (puedes usar tu conexión y método habitual)
            $stmt = $con->prepare($sql);
            $stmt->execute();
            // Obtener el resultado
            $result = $stmt->get_result();

            if ($result && $row = $result->fetch_assoc()) {
                $emisor = $row;
                logToFile('0', '0', 'emisor encontrado en pdfFinezza.php', 'info', json_encode($emisor, true));
                //print_r($emisor);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'No se encontró ningún registro en la tabla token 555.',
                ]);
            }
        } catch (\Exception $e) {
            echo json_encode([
            'status' => 'error',
            'message' => 'Ha ocurrido un error inesperado.',
            'desc' => $e->getMessage(),
            'sql' => $sql,
            ]);
            exit;
        }

        $arrEmisor = [
            "Rfc" => $emisor["rfc"],
            "Nombre" => $emisor["nombre"],
            "RegimenFiscal" => $emisor["regimen"],
            "domicilioEmisor" => $emisor["cp"] . ', ' . $emisor["estado"] . ', ' . $emisor["municipio"] . ', ' . $emisor["colonia"] . ', ' . $emisor["calle"] . ', ' . $emisor["num_ext"] . ' ' . $emisor["num_int"],
            "LugarExpedicion" => $emisor["cp"],
        ];
    }
    //$factura['Emisor'] = $arrEmisor;
    
    // Leer el archivo XML
    try {
        $xmlContent = file_get_contents($nombreArchivoXml);

        // Imprimir el contenido del XML para ver si está bien estructurado
        //echo "<pre>";
        //var_dump($xmlContent); // <-- Aquí verificamos el contenido exacto del archivo XML
        //echo "</pre>";

        // Proceso de sanitización y carga de SimpleXML
        //$cleanXML = removeNamespacesFromXML($xmlContent);
        libxml_use_internal_errors(true);
        // Convertir el string XML limpio en un objeto SimpleXMLElement
        $xml = simplexml_load_string($xmlContent);
        //print_r($xml);
        if ($xml === false) {
            echo "Error al cargar el XML: ";
            foreach (libxml_get_errors() as $error) {
                echo $error->message;
            }
        } else {
            // Registro del comprobante (atributos principales)
            $impuesto = $xml->xpath('//cfdi:Impuestos[@TotalImpuestosTrasladados]');
            $totalImpuestosTrasladados = (string) $impuesto[0]['TotalImpuestosTrasladados'];
            $totalImpuestosRetenidos = (string) $impuesto[0]['TotalImpuestosRetenidos'];

            //print_r($totalImpuestos);
            $factura = [
                'Version' => (string) $xml['Version'],
                'Serie' => (string) $xml['Serie'],
                'Folio' => (string) $xml['Folio'],
                'Fecha' => (string) $xml['Fecha'],
                'FormaPago' => (string) $xml['FormaPago'],
                'SubTotal' => (string) $xml['SubTotal'],
                'TotalImpuestosTrasladados' => (string) $totalImpuestosTrasladados,
                'TotalImpuestosRetenidos' => (string) $totalImpuestosRetenidos,
                'Total' => (string) $xml['Total'],
                'MetodoPago' => (string) isset($xml['MetodoPago']) ? $xml['MetodoPago'] : 'PUE',
                'LugarExpedicion' => (string) $xml['LugarExpedicion'],
                'TipoDeComprobante' => (string) $xml['TipoDeComprobante'],
                'Moneda' => (string) $xml['Moneda'],
                'TipoCambio' => (string) $xml['TipoCambio'],
                'Sello' => (string) $xml['Sello'],
                ''
            ];

            // Espacio de nombres, asumiendo que están definidos
            $namespaces = $xml->getNamespaces(true);
            // Registrar el espacio de nombres (ajusta la URL según sea necesario)
            $xml->registerXPathNamespace('tfd', 'http://www.sat.gob.mx/TimbreFiscalDigital');
            
            // Datos del emisor
            $emisor = $xml->xpath('//cfdi:Emisor');
            //print_r($emisor);
            $factura['Emisor'] = $arrEmisor;

            // Datos del receptor
            $receptor = $xml->xpath('//cfdi:Receptor');
            $factura['Receptor'] = [
                'Rfc' => (string)$receptor[0]['Rfc'],
                'Nombre' => (string)$receptor[0]['Nombre'],
                'RegimenFiscalReceptor' => (string)$receptor[0]['RegimenFiscalReceptor'],
                'UsoCFDI' => (string)$receptor[0]['UsoCFDI'],
                'DomicilioFiscalReceptor' => (string)$receptor[0]['DomicilioFiscalReceptor'],
                'Pais' => (string)$pais,
                'Estado' => (string)$estado,
                'Municipio' => (string)$municipio,
                'Ciudad' => (string)$ciudad,
                'Colonia' => (string)$colonia,
                'Manzana' => (string)$manzana,
                'NumExt' => (string)$numExt,
                'NumInt' => (string)$numInt,
                'Calle' => (string)$calle,
                'Cp' => (string)$cp,
            ];

            // Conceptos (productos o servicios)
            $factura['Conceptos'] = [];
            $conceptos = $xml->xpath('//cfdi:Conceptos/cfdi:Concepto');            
            foreach ($conceptos as $concepto) {
                $impuestosTrasladados = $concepto->xpath('./cfdi:Impuestos/cfdi:Traslados/cfdi:Traslado');

                $prodImpuestoTrasladado = 0.00;
                foreach($impuestosTrasladados as $impuestoTrasladado) {
                    $prodImpuestoTrasladado += $impuestoTrasladado['Importe'];
                };

                $impuestosRetenidos = $concepto->xpath('./cfdi:Impuestos/cfdi:Retenciones/cfdi:Retencion');

                $prodImpuestoRetenido = 0.00;
                foreach($impuestosRetenidos as $impuestoRetenido) {
                    $prodImpuestoRetenido += $impuestoRetenido['Importe'];
                };

                $factura['Conceptos'][] = [
                    'ClaveProdServ' => (string) $concepto['ClaveProdServ'],
                    'Cantidad' => (string) $concepto['Cantidad'],
                    'ClaveUnidad' => (string) $concepto['ClaveUnidad'],
                    'Descripcion' => (string) $concepto['Descripcion'],
                    'ValorUnitario' => (string) $concepto['ValorUnitario'],
                    'Descuento' => (string) $concepto['Descuento'],
                    'Importe' => (string) $concepto['Importe'],
                    'Base' => (string) $factura['SubTotal'],
                    'ImporteImpuestoTrasladado' => (string) $prodImpuestoTrasladado,
                    'ImporteImpuestoRetenido' => (string) $prodImpuestoRetenido,
                ];
            }
            //print_r($factura);

            $totales = $xml->xpath('//cfdi:Complemento/pago20:Pagos/pago20:Totales');
            if (!empty($totales)) {
                // Impuestos retenidos
                $totalRetencionesIVA = (string) $totales[0]['TotalRetencionesIVA'];
                $totalRetencionesISR = (string) $totales[0]['TotalRetencionesISR'];
                $totalRetencionesIEPS = (string) $totales[0]['TotalRetencionesIEPS'];
                
                // Impuestos trasladados
                $totalTrasladosBaseIVA16 = (string) $totales[0]['TotalTrasladosBaseIVA16'];
                $totalTrasladosImpuestoIVA16 = (string) $totales[0]['TotalTrasladosImpuestoIVA16'];
                $montoTotalPagos = (string) $totales[0]['MontoTotalPagos'];
                
                $pagos = $xml->xpath('//cfdi:Complemento/pago20:Pagos/pago20:Pago');
                if (!empty($pagos)) {
                    $formaPago = (string) $pagos[0]['FormaDePagoP'];
                    $factura['FormaPago'] = $formaPago;
                    $factura['Moneda'] = (string) $pagos[0]['MonedaP'];;
                }
                // Reemplazar los valores en el concepto si existen valores en totales
                foreach ($factura['Conceptos'] as &$concepto) {
                    if ($concepto['ValorUnitario'] == "0") {
                        $concepto['ValorUnitario'] = $totalTrasladosBaseIVA16;
                        $concepto['Base'] = $totalTrasladosBaseIVA16;
                        $concepto['ImporteImpuesto'] = $totalTrasladosImpuestoIVA16;
                        $concepto['Impuesto'] = $totalTrasladosImpuestoIVA16;
                        $concepto['Importe'] = floatval($montoTotalPagos);
                        $concepto['ImporteImpuestoTrasladado'] = floatval($totalTrasladosImpuestoIVA16);
                        $concepto['ImporteImpuestoRetenido'] = floatval($totalRetencionesIVA) + floatval($totalRetencionesISR) + floatval($totalRetencionesIEPS);
                    }
                }
                if($factura['SubTotal'] == '0'){
                    $factura['SubTotal'] = $totalTrasladosBaseIVA16;
                    $factura['TotalImpuestos'] = $totalTrasladosImpuestoIVA16;
                    $factura['Total'] = $montoTotalPagos;
                }
            } unset($concepto);
            //print_r($factura);
            

            // Complemento (Timbre Fiscal Digital)
            $pagos = $xml->xpath('//cfdi:Complemento/pago20:Pagos');
            if (!empty($pagos)) {
                $totales = $pagos[0]->xpath('//pago20:Totales');
                $pagosArray = $pagos[0]->xpath('//pago20:Pago');
                
                $factura['Pagos'] = [
                    'MontoTotalPagos' => (string) $totales[0]['MontoTotalPagos'],
                    'FechaPago' => (string) $pagosArray[0]['FechaPago'],
                    'FormaDePagoP' => (string) $pagosArray[0]['FormaDePagoP'],
                    'MonedaP' => (string) $pagosArray[0]['MonedaP'],
                    'TipoCambioP' => (string) $pagosArray[0]['TipoCambioP'],
                    'Monto' => (string) $pagosArray[0]['Monto']
                ];
                
                // Bucle para obtener cada pago
                foreach ($pagosArray as $pago) {
                    $documentosRelacionados = $pago->xpath('./pago20:DoctoRelacionado');
                    $documentos = [];
                    
                    // Bucle para obtener cada documento relacionado del pago
                    foreach ($documentosRelacionados as $docRelacionado) {
                        $documentos[] = [
                            'IdDocumento' => (string) $docRelacionado['IdDocumento'],
                            'MonedaDR' => (string) $docRelacionado['MonedaDR'],
                            'EquivalenciaDR' => (string) $docRelacionado['EquivalenciaDR'],
                            'NumParcialidad' => (string) $docRelacionado['NumParcialidad'],
                            'ImpSaldoAnt' => (string) $docRelacionado['ImpSaldoAnt'],
                            'ImpPagado' => (string) $docRelacionado['ImpPagado'],
                            'ImpSaldoInsoluto' => (string) $docRelacionado['ImpSaldoInsoluto'],
                            'ObjetoImpDR' => (string) $docRelacionado['ObjetoImpDR']
                        ];
                    }
                    
                    $factura['Pagos']['DocumentosRelacionados'] = $documentos;
                }
            }

            $complemento = $xml->xpath('//cfdi:Complemento/tfd:TimbreFiscalDigital');
            $factura['Complemento'] = [
                'UUID' => (string) $complemento[0]['UUID'],
                'FechaTimbrado' => (string) $complemento[0]['FechaTimbrado'],
                'RfcProvCertif' => (string) $complemento[0]['RfcProvCertif'],
                'SelloCFD' => (string) $complemento[0]['SelloCFD'],
                'NoCertificadoSAT' => (string) $complemento[0]['NoCertificadoSAT'],
                'SelloSAT' => (string) $complemento[0]['SelloSAT']
            ];

            $docsRelacionados = $xml->xpath('//cfdi:CfdiRelacionados');
            if (!empty($docsRelacionados)) {
                $factura['DocsRelacionados']['TipoRelacion'] = (string) $docsRelacionados[0]['TipoRelacion'];
                // Impuestos retenidos
                $relacionados = $xml->xpath('//cfdi:CfdiRelacionados/cfdi:CfdiRelacionado');
                foreach($relacionados as $relacionado) {
                    $factura['DocsRelacionados']['Doc'][] = [
                        'UUID' => (string) $relacionado['UUID']
                    ];
                }
            }

            // Retornar la factura organizada en un array
            //unlink($nombreArchivoXml); // Asegúrate de que esto es lo que deseas hacer
            
            //print_r($factura);

            if($pdf) {
                if ($id === null) {
                    throw new InvalidArgumentException('El parámetro $id es obligatorio cuando $pdf es true.');
                }
                $pdfBase64 = generarPDF($factura, $id); // Llama a generarPDF

                return $pdfBase64;
            } else {
                return $factura;
            }
            
        }
    } catch (Exception $e) {
        echo "Error al generar el PDF: " . $e->getMessage();
    }
}

function arrayPdf($data) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
    
    $database_host = $_ENV['DATABASE_HOST'] ?? '';
    $database_user = $_ENV['DATABASE_USER'] ?? '';
    $database_password = $_ENV['DATABASE_PASSWORD'] ?? '';
    $database_name = $_ENV['DATABASE_NAME'] ?? '';
    $enc_key = $_ENV['ENCRYPT_KEY'] ?? '';
    
    $con = new mysqli($database_host, $database_user, $database_password, $database_name);
    $con->set_charset("utf8");

    $res_emisor = null;

    $cuenta = $data['cuenta'];

    try {
        $sql = "SELECT * FROM `cuenta_factura` WHERE `id` = $cuenta";
    
        // Ejecutar la consulta (puedes usar tu conexión y método habitual)
        $stmt = $con->prepare($sql);
        $stmt->execute();
        // Obtener el resultado
        $result = $stmt->get_result();
    
        if ($result && $row = $result->fetch_assoc()) {
            $emisor = $row;
            logToFile('0', '0', 'emisor encontrado en pdfFinezza.php', 'info', json_encode($emisor, true));
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

    $arrEmisor = [
        "Rfc" => $emisor["rfc"],
        "Nombre" => $emisor["nombre"],
        "RegimenFiscal" => $emisor["regimen"],
        "domicilioEmisor" => $emisor["cp"] . ', ' . $emisor["estado"] . ', ' . $emisor["municipio"] . ', ' . $emisor["colonia"] . ', ' . $emisor["manzana"] . ', ' . $emisor["calle"] . ', ' . $emisor["num_ext"] . ' ' . $emisor["num_int"],
        "LugarExpedicion" => $emisor["cp"],
    ];
    $data['Emisor'] = $arrEmisor;

    //Info General
    $data['Fecha'] = date('Y-m-d\TH:i:s');
    $data['LugarExpedicion'] = $res_emisor['cp'];
    $data['TipoCambio'] = isset($data['TipoCambio']) ? $data['TipoCambio'] : 1;
    $data['Serie'] = 'AB';

    $pdf = generarPDF($data, '1');
    if($pdf) {
        return $pdf;
    } else {
        echo 'Error al generar el pdf 2.';
    }
}

function generarPDF($array, $id) {
    ob_start();
    //print_r($id);
    class PDF extends FPDF {
        // Encabezado
        function Header() {
            if ($this->PageNo() == 1) {
                //logToFile('0', '0', 'Tipo de comprobante', 'info', $this->TipoDeComprobante);
                switch($this->TipoDeComprobante) {
                    case 'I':
                        $tipoComprobante = 'I - Ingreso';
                        break;
                    case 'E':
                        $tipoComprobante = 'E - Egreso';
                        break;
                    case 'P':
                        $tipoComprobante = 'P - Pago';
                        break;
                    case 'Ingreso':
                        $tipoComprobante = 'I - Ingreso';
                        break;
                    case 'Egreso':
                        $tipoComprobante = 'E - Egreso';
                        break;
                    case 'Pago':
                        $tipoComprobante = 'P - Pago';
                        break;
                    default:
                        $tipoComprobante = 'No Especificado';
                        break;
                }
                // Logo en la esquina superior izquierda
                if($this->emisorRFC == 'HUCJ750513DW7'){
                    if (file_exists('assets/FinezzaPublicidad.jpg')) {
                        $this->Image('assets/FinezzaPublicidad.jpg', 10, 10, 80, 25);
                    }
                } else if($this->emisorRFC == 'IITV760127956'){
                    if (file_exists('assets/GrupoFinezza2.png')) {
                        $this->Image('assets/GrupoFinezza2.png', 10, 10, 80, 25);
                    }
                } else {
                    if (file_exists('assets/logoZN.png')) {
                        $this->Image('assets/logoZN.png', 10, 10, 80, 25);
                    }
                }
                
                // Caja gris para información del emisor (lado derecho)
                $this->SetFillColor(220, 220, 220);
                $this->Rect(95, 10, 110, 35, 'DF');
                
                // Información del emisor en la caja gris
                $this->SetFont('Arial', 'B', 8);
                $this->SetXY(105, 10);
                $this->Cell(90, 5, safe_sutf8_decode($this->emisorNombre), 0, 1, 'C');
                
                $this->SetFont('Arial', 'B', 7);
                $this->SetXY(95, 15);
                $this->SetFillColor(255, 255, 255);
                $this->Cell(110, 4, 'RFC: ' . safe_sutf8_decode($this->emisorRFC), 0, 1, 'C', 1);
                
                $this->SetXY(95, 20);
                $this->Cell(110, 4, safe_sutf8_decode('Tipo de Comprobante: ' . $tipoComprobante), 0, 1, 'L');
                
                $this->SetXY(95, 25);
                $this->Cell(110, 4, safe_sutf8_decode('Lugar de Expedición: ') . safe_sutf8_decode($this->lugarExpedicion), 0, 1, 'L');
                
                $this->SetXY(95, 30);
                $this->SetFillColor(220, 220, 220);
                $this->MultiCell(110, 4, safe_sutf8_decode('Régimen Fiscal: ') . safe_sutf8_decode($this->regimenFiscal), 0, 1, 'L', 1);
                
                // Obtener la posición Y después del MultiCell para continuar desde ahí
                $yDespuesRegimen = $this->GetY();
                
                $this->SetXY(95, $yDespuesRegimen);
                $this->Cell(110, 4, safe_sutf8_decode('Domicilio: ') . safe_sutf8_decode($this->domicilioEmisor), 0, 1, 'L');
                
                $this->SetXY(95, $yDespuesRegimen + 5);
                $this->Cell(110, 4, 'Tel: / Correo:', 0, 1, 'L');
            }
        }
    
        // Pie de página
        function Footer() {
            // Posición a 1.5 cm del final
            $this->SetY(-15);
            // Arial italic 8
            $this->SetFont('Arial','I',8);
            // Número de página
            $this->Cell(0,10,safe_sutf8_decode('Página ').$this->PageNo().'/{nb}',0,0,'C');
        }
    
        // Tabla
        function FacturaTable($header, $data) {
            // Definir los anchos de las columnas (más compactos)
            $widths = array(13, 20, 25, 60, 20, 15, 22, 20); // Ajustados para 8 columnas
            
            // Cabecera
            $this->SetFont('Arial', 'B', 7);
            $this->SetFillColor(220, 220, 220);
            
            foreach ($header as $i => $col) {
                $this->Cell($widths[$i], 8, safe_sutf8_decode($col), 1, 0, 'C', true);
            }
            $this->Ln();
        
            // Datos
            $this->SetFont('Arial', '', 7);
        
            foreach ($data as $row) {
                // Variable para determinar la altura más grande de la celda
                $maxHeight = 0;
        
                // Iterar sobre cada columna de la fila para obtener la altura máxima
                foreach ($row as $i => $col) {
                    $espacioDisponible = $this->GetPageHeight() - $this->GetY();
                    if ($espacioDisponible < 50) {
                        $this->AddPage();
                        $y = 10;
                    }
                    // Guardar la posición de la celda actual
                    $this->SetX(10);
                    $x = $this->GetX();
                    $y = $this->GetY();
        
                    // Calcular la altura de la celda actual usando la función GetMultiCellHeight
                    $textHeight = $this->GetMultiCellHeight($widths[$i], 5, safe_sutf8_decode($col));
                    
                    // Obtener la altura máxima entre las celdas
                    $maxHeight = max($maxHeight, $textHeight);
        
                    // Volver a la posición anterior (sin mover Y) para dibujar la próxima celda en la misma línea
                    $this->SetXY($x + $widths[$i], $y);
                }
        
                // Iterar sobre cada columna nuevamente para dibujar las celdas con la altura máxima calculada
                $this->SetX(10);
                foreach ($row as $i => $col) {
                    $x = $this->GetX();
                    $y = $this->GetY();
                    
                    // Alineación específica para cada columna
                    $align = 'C';
                    //if ($i == 3) $align = 'L'; // Descripción alineada a la izquierda
                    //if (in_array($i, [4, 5, 6, 7])) $align = 'R'; // Valores numéricos a la derecha
                    
                    // Dibujar MultiCell y luego un borde alrededor de la celda con la altura máxima
                    $this->MultiCell($widths[$i], 5, safe_sutf8_decode($col), 0, $align);
                    $this->Rect($x, $y, $widths[$i], $maxHeight); // Dibuja el borde ajustado a la altura máxima
                    $this->SetXY($x + $widths[$i], $y); // Ajustar la posición para la siguiente celda
                }
        
                // Después de procesar la fila, mover el cursor a la siguiente línea
                $this->Ln($maxHeight);
            }
        }

        function GetMultiCellHeight($w, $h, $txt) {
            // Copiar la configuración actual
            $cw = &$this->CurrentFont['cw'];
            if ($w == 0) {
                $w = $this->w - $this->rMargin - $this->x;
            }
            $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
            $s = str_replace("\r", '', $txt);
            $nb = strlen($s);
            if ($nb > 0 and $s[$nb - 1] == "\n") {
                $nb--;
            }
            $sep = -1;
            $i = 0;
            $j = 0;
            $l = 0;
            $ns = 0;
            $height = 0;
            while ($i < $nb) {
                $c = $s[$i];
                if ($c == "\n") {
                    if ($this->ws > 0) {
                        $this->ws = 0;
                        $this->_out('0 Tw');
                    }
                    $height += $h;
                    $i++;
                    $sep = -1;
                    $j = $i;
                    $l = 0;
                    $ns = 0;
                    continue;
                }
                if ($c == ' ') {
                    $sep = $i;
                    $ls = $l;
                    $ns++;
                }
                $l += $cw[$c];
                if ($l > $wmax) {
                    if ($sep == -1) {
                        if ($i == $j) {
                            $i++;
                        }
                    } else {
                        $i = $sep + 1;
                    }
                    if ($this->ws > 0) {
                        $this->ws = 0;
                        $this->_out('0 Tw');
                    }
                    $height += $h;
                    $sep = -1;
                    $j = $i;
                    $l = 0;
                    $ns = 0;
                } else {
                    $i++;
                }
            }
            $height += $h;
            return $height;
        }

        function RelacionadosTable($data) {
            // Definir los anchos de las columnas
            $widths = array(100); // Ajusta los valores de ancho según las necesidades
            $y = $this->GetY();
            $y = $y + 8;
            $this->SetXY(10, $y);
            // Cabecera
            $this->SetFont('Arial', 'B', 7);
            $this->SetFillColor(220, 220, 220);
            
            $this->Cell(120, 10, safe_sutf8_decode('Documentos Relacionados'), 1, 0, 'C', true);
            $this->Cell(75, 10, safe_sutf8_decode('Tipo de Relación'), 1, 0, 'C', true);
            
            $this->Ln();
        
            // Datos
            $this->SetFont('Arial', '', 8);
        
            foreach ($data['Doc'] as $row) {
        
                $maxHeight = 0;

                // Iterar sobre cada columna nuevamente para dibujar las celdas con la altura máxima calculada
                $this->SetX(10);
                foreach ($row as $doc) {
                    switch($data['TipoRelacion']) {
                        case '01':
                            $tipoRelacion = '01 - Nota de crédito de los documentos relacionados';
                            break;
                        case '02':
                            $tipoRelacion = '02 - Nota de débito de los documentos relacionados';
                            break;
                        case '03':
                            $tipoRelacion = '03 - Devolución de mercancía sobre facturas o traslados previos';
                            break;
                        case '04':
                            $tipoRelacion = '04 - Sustitución de los CFDI previos';
                            break;
                        case '05':
                            $tipoRelacion = '05 - Traslados de mercancías facturados previamente';
                            break;
                        case '06':
                            $tipoRelacion = '06 - Factura generada por los traslados previos';
                            break;
                        case '07':
                            $tipoRelacion = '07 - CFDI por aplicación de anticipo';
                            break;
                            
                    }

                    $textHeight = $this->GetMultiCellHeight(70, 6, safe_sutf8_decode($tipoRelacion));
                    // Obtener la altura máxima entre las celdas
                    $maxHeight = max($maxHeight, $textHeight);

                    $x = $this->GetX();
                    $y = $this->GetY();
                    
                    // Dibujar MultiCell y luego un borde alrededor de la celda con la altura máxima
                    $this->MultiCell(120, 6, safe_sutf8_decode($doc), 0, 'C');
                    $this->Rect($x, $y, 120, $maxHeight); // Dibuja el borde ajustado a la altura máxima
                    $this->SetXY($x + 120, $y); // Ajustar la posición para la siguiente celda

                    $x = $this->GetX();
                    $y = $this->GetY();
                    $this->MultiCell(75, 6, safe_sutf8_decode($tipoRelacion), 0, 'C');
                    $this->Rect($x, $y, 75, $maxHeight); // Dibuja el borde ajustado a la altura máxima

                    
                    $this->SetXY($x + 75, $y); // Ajustar la posición para la siguiente celda
                }
        
                // Después de procesar la fila, mover el cursor a la siguiente línea
                $this->Ln($maxHeight);
            }
        }

        function PagosTable($data) {
            logToFile($username, $userID, 'Se Recibe info de pagos', "success", json_encode($data));
            // Definir los anchos de las columnas
            $widths = array(100); // Ajusta los valores de ancho según las necesidades
            $y = $this->GetY();
            $y = $y + 8;
            $this->SetXY(10, $y);
            // Cabecera
            $this->SetFont('Arial', 'B', 7);
            $this->SetFillColor(220, 220, 220);
            
            $this->Cell(120, 10, safe_sutf8_decode('Documentos Relacionados'), 1, 0, 'C', true);
            $this->Cell(75, 10, safe_sutf8_decode('Monto'), 1, 0, 'C', true);
            
            $this->Ln();
        
            // Datos
            $this->SetFont('Arial', '', 8);
        
            //print_r($data);
            foreach ($data['DocumentosRelacionados'] as $row) {
        
                logToFile($username, $userID, 'Cada fila de pagos', "success", json_encode($row));
                $maxHeight = 0;

                // Iterar sobre cada columna nuevamente para dibujar las celdas con la altura máxima calculada
                $this->SetX(10);
                

                $textHeight = $this->GetMultiCellHeight(120, 6, safe_sutf8_decode($row['IdDocumento']));
                // Obtener la altura máxima entre las celdas
                $maxHeight = max($maxHeight, $textHeight);

                $x = $this->GetX();
                $y = $this->GetY();
                
                // Dibujar MultiCell y luego un borde alrededor de la celda con la altura máxima
                $this->MultiCell(120, 6, safe_sutf8_decode($row['IdDocumento']), 0, 'C');
                $this->Rect($x, $y, 120, $maxHeight); // Dibuja el borde ajustado a la altura máxima
                $this->SetXY($x + 120, $y); // Ajustar la posición para la siguiente celda

                $x = $this->GetX();
                $y = $this->GetY();
                $this->MultiCell(75, 6, safe_sutf8_decode($row['ImpPagado']), 0, 'C');
                $this->Rect($x, $y, 75, $maxHeight); // Dibuja el borde ajustado a la altura máxima

                
                $this->SetXY($x + 70, $y); // Ajustar la posición para la siguiente celda
                
        
                // Después de procesar la fila, mover el cursor a la siguiente línea
                $this->Ln($maxHeight);
            }
        }
    }

    function safe_sutf8_decode($string) {
        return utf8_decode($string ?? '');
    }

    $Emisor = $array['Emisor'];
    //$Receptor = $array['Receptor'];
    $Receptor = array_filter($array['Receptor'], function($value) {
        return !is_null($value) && $value !== 'null';
    });
    $DocsRelacionados = $array['DocsRelacionados'];
    // Fecha original en formato ISO 8601
    $fechaOriginal = $array['Fecha'];
    $date = new DateTime($fechaOriginal);
    // Formatear la fecha al formato deseado: d/M/Y H:i:s
    $formatoDeseado = $date->format('d/M/Y H:i:s');
    // Reemplazar el mes numérico por su versión en texto (español)
    $meses = array(
        'Jan' => 'Ene', 'Feb' => 'Feb', 'Mar' => 'Mar', 'Apr' => 'Abr', 'May' => 'May', 'Jun' => 'Jun',
        'Jul' => 'Jul', 'Aug' => 'Ago', 'Sep' => 'Sep', 'Oct' => 'Oct', 'Nov' => 'Nov', 'Dec' => 'Dic'
    );
    // Reemplazar el mes abreviado en inglés por español
    $fechaFinal = strtr($formatoDeseado, $meses);

    $metodoPago = $array['MetodoPago'];
    if($metodoPago == 'PUE') {
        $metodoPago = 'PUE - Pago en una sola exhibición';
    } else if($metodoPago == 'PPD') {
        $metodoPago = 'PPD - Pago en parcialidades o diferido';
    }
    
    $formaPago = $array['FormaPago'];
    switch($formaPago) {
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
    }

    function regFiscal($reg) {
        switch($reg) {
            case '601':
                $reg = '601 - General de Ley Personas Morales';
                break;
            case '603':
                $reg = '603 - Personas Morales con Fines no Lucrativos';
                break;
            case '605':
                $reg = '605 - Sueldos y Salarios e Ingresos Asimilados a Salarios';
                break;
            case '606':
                $reg = '606 - Arrendamiento';
                break;
            case '607':
                $reg = '607 - Régimen de Enajenación o Adquisición de Bienes';
                break;
            case '608':
                $reg = '608 - Demás ingresos';
                break;
            case '610':
                $reg = '610 - Residentes en el Extranjero sin Establecimiento Permanente en México';
                break;
            case '611':
                $reg = '611 - Ingresos por Dividendos (socios y accionistas)';
                break;
            case '612':
                $reg = '612 - Personas Físicas con Actividades Empresariales y Profesionales';
                break;
            case '614':
                $reg = '614 - Ingresos por intereses';
                break;
            case '615':
                $reg = '615 - Régimen de los ingresos por obtención de premios';
                break;
            case '616':
                $reg = '616 - Sin obligaciones fiscales';
                break;
            case '620':
                $reg = '620 - Sociedades Cooperativas de Producción que optan por diferir sus ingresos';
                break;
            case '621':
                $reg = '621 - Incorporación Fiscal';
                break;
            case '622':
                $reg = '622 - Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras';
                break;
            case '623':
                $reg = '623 - Opcional para Grupos de Sociedades';
                break;
            case '624':
                $reg = '624 - Coordinados';
                break;
            case '625':
                $reg = '625 - Régimen de las Actividades Empresariales con ingresos a través de Plataformas Tecnológicas';
                break;
            case '626':
                $reg = '626 - Régimen Simplificado de Confianza';
                break;
            case '601':
                $reg = '601 - General de Ley Personas Morales';
                break;
            case '603':
                $reg = '603 - Personas Morales con Fines no Lucrativos';
                break;
            case '605':
                $reg = '605 - Sueldos y Salarios e Ingresos Asimilados a Salarios';
                break;
            case '606':
                $reg = '606 - Arrendamiento';
                break;
            case '607':
                $reg = '607 - Régimen de Enajenación o Adquisición de Bienes';
                break;
            case '608':
                $reg = '608 - Demás ingresos';
                break;
            case '610':
                $reg = '610 - Residentes en el Extranjero sin Establecimiento Permanente en México';
                break;
            case '611':
                $reg = '611 - Ingresos por Dividendos (socios y accionistas)';
                break;
            case '612':
                $reg = '612 - Personas Físicas con Actividades Empresariales y Profesionales';
                break;
            case '614':
                $reg = '614 - Ingresos por intereses';
                break;
            case '615':
                $reg = '615 - Régimen de los ingresos por obtención de premios';
                break;
            case '616':
                $reg = '616 - Sin obligaciones fiscales';
                break;
            case '620':
                $reg = '620 - Sociedades Cooperativas de Producción que optan por diferir sus ingresos';
                break;
            case '621':
                $reg = '621 - Incorporación Fiscal';
                break;
            case '622':
                $reg = '622 - Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras';
                break;
            case '623':
                $reg = '623 - Opcional para Grupos de Sociedades';
                break;
            case '624':
                $reg = '624 - Coordinados';
                break;
            case '625':
                $reg = '625 - Régimen de las Actividades Empresariales con ingresos a través de Plataformas Tecnológicas';
                break;
            case '626':
                $reg = '626 - Régimen Simplificado de Confianza';
                break;
        }
        return $reg;
    }

    function cfdi($cfdi) {
        switch($cfdi) {
            case 'G01':
                $cfdi = 'G01 - Adquisición de mercancías.';
                break;
            case 'G02':
                $cfdi = 'G02 - Devoluciones, descuentos o bonificaciones.';
                break;
            case 'G03':
                $cfdi = 'G03 - Gastos en general.';
                break;
            case 'I01':
                $cfdi = 'I01 - Construcciones.';
                break;
            case 'I02':
                $cfdi = 'I02 - Mobiliario y equipo de oficina por inversiones.';
                break;
            case 'I03':
                $cfdi = 'I03 - Equipo de transporte.';
                break;
            case 'I04':
                $cfdi = 'I04 - Equipo de computo y accesorios.';
                break;
            case 'I05':
                $cfdi = 'I05 - Dados, troqueles, moldes, matrices y herramental.';
                break;
            case 'I06':
                $cfdi = 'I06 - Comunicaciones telefónicas.';
                break;
            case 'I07':
                $cfdi = 'I07 - Comunicaciones satelitales.';
                break;
            case 'I08':
                $cfdi = 'I08 - Otra maquinaria y equipo.';
                break;
            case 'D01':
                $cfdi = 'D01 - Honorarios médicos, dentales y gastos hospitalarios.';
                break;
            case 'D02':
                $cfdi = 'D02 - Gastos médicos por incapacidad o discapacidad.';
                break;
            case 'D03':
                $cfdi = 'D03 - Gastos funerales.';
                break;
            case 'D04':
                $cfdi = 'D04 - Donativos.';
                break;
            case 'D05':
                $cfdi = 'D05 - Intereses reales efectivamente pagados por créditos hipotecarios (casa habitación).';
                break;
            case 'D06':
                $cfdi = 'D06 - Aportaciones voluntarias al SAR.';
                break;
            case 'D07':
                $cfdi = 'D07 - Primas por seguros de gastos médicos.';
                break;
            case 'D08':
                $cfdi = 'D08 - Gastos de transportación escolar obligatoria.';
                break;
            case 'D09':
                $cfdi = 'D09 - Depósitos en cuentas para el ahorro, primas que tengan como base planes de pensiones.';
                break;
            case 'D10':
                $cfdi = 'D10 - Pagos por servicios educativos (colegiaturas).';
                break;
            case 'S01':
                $cfdi = 'S01 - Sin efectos fiscales.  ';
                break;
            case 'CP01':
                $cfdi = 'CP01 - Pagos.';
                break;
            case 'CN01':
                $cfdi = 'CN01 - Nómina.';
                break;
        }
        return $cfdi;
    }
    // Datos de ejemplo
    $header = array('Cantidad', 'Clave Uni SAT', 'Clave P/S', 'Concepto / Descripción', 'Valor Unitario', 'Descuentos', 'Impuestos', 'Importe');
    
    $data = [];

    $descuentos = 0;
    $totalImpuestosTrasladados = 0;
    $totalImpuestosRetenidos = 0;
    // Bucle para llenar el array data con los conceptos
    foreach ($array['Conceptos'] as $concepto) {
        $cantidad = $concepto['Cantidad'];
        $claveUnidad = $concepto['ClaveUnidad'];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://zamnadev.com/finezza/api/facturacion/get_claves.php');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['termino' => $concepto['ClaveUnidad'], 'tabla' => 'claves_unidad']));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $res = json_decode(curl_exec($ch), true);
        $claveUnidadText = $res['data'][0]['descripcion'];
        curl_close($ch);

        $claveProdServ = $concepto['ClaveProdServ'];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://zamnadev.com/finezza/api/facturacion/get_claves.php');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['termino' => $concepto['ClaveProdServ'], 'tabla' => 'claves_ps']));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $res = json_decode(curl_exec($ch), true);
        $claveProdServText = $res['data'][0]['descripcion'];
        curl_close($ch);

        $descripcion = $concepto['Descripcion'];
        $valorUnitario = '$' . number_format((float)($concepto['ValorUnitario'] ?? 0), 2); // Asigna 0 si no está definido
        if(isset($concepto['Descuento'])) {
            $descuento = (float)$concepto['Descuento'];
            $descuento = '$' . number_format($descuento, 2); // No hay descuento en los conceptos proporcionados
        } else {
            $descuento = 0.00;
        }
        $descuentos = $descuentos + floatval($concepto['Descuento']);
        //$impuestoValor = isset($concepto['Impuesto']) && $concepto['Impuesto'] !== '0' ? $concepto['Impuesto'] : (isset($concepto['ImporteImpuestoTrasladado']) ? $concepto['ImporteImpuestoTrasladado'] : 0);
        //print_r($impuestoValor);
        // Combinar impuestos en una sola columna
        $impuestos = '';
        if (floatval($concepto['ImporteImpuestoTrasladado']) > 0) {
            $impuestos .= '002 - IVA $' . number_format(floatval($concepto['ImporteImpuestoTrasladado']), 2);
        }
        if (floatval($concepto['ImporteImpuestoRetenido']) > 0) {
            if ($impuestos != '') $impuestos .= ' / ';
            $impuestos .= '001 - ISR $' . number_format(floatval($concepto['ImporteImpuestoRetenido']), 2);
        }
        if ($impuestos == '') $impuestos = '$0.00';
        
        $importe = '$' . number_format(floatval($concepto['Importe']), 2); // Importe formateado

        // Agregar los datos del concepto al array data (8 columnas)
        $data[] = [$cantidad, $claveUnidad . ' ' . mb_strtoupper($claveUnidadText, 'UTF-8'), $claveProdServ . ' ' . mb_strtoupper($claveProdServText, 'UTF-8'), mb_strtoupper($claveProdServText, 'UTF-8') . ' ' . $descripcion, $valorUnitario, $descuento, $impuestos, $importe];

        $totalImpuestosTrasladados = floatval($totalImpuestosTrasladados) + floatval($concepto['ImporteImpuestoTrasladado']);
        $totalImpuestosRetenidos = floatval($totalImpuestosRetenidos) + floatval($concepto['ImporteImpuestoRetenido']);
    }

    function numeroALetras($numero) {
        // Arreglos para las distintas categorías
        $unidades = [
            '', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve',
            'diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete',
            'dieciocho', 'diecinueve', 'veinte'
        ];
    
        $decenas = [
            '', '', 'veinte', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 
            'ochenta', 'noventa'
        ];
    
        $centenas = [
            '', 'cien', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos', 
            'seiscientos', 'setecientos', 'ochocientos', 'novecientos'
        ];
    
        // Manejo de valores
        if ($numero == 0) {
            return 'cero';
        }
    
        if ($numero < 0) {
            return 'menos ' . numeroALetras(-$numero);
        }
    
        // Separar la parte entera y decimal
        $partes = explode('.', number_format($numero, 2, '.', ''));
        $entero = intval($partes[0]);
        $decimal = intval($partes[1]);
    
        $resultado = '';
    
        // Manejo de millones
        if ($entero >= 1000000) {
            $millones = intval($entero / 1000000);
            $resultado .= numeroALetras($millones) . ' millón' . ($millones > 1 ? 'es' : '') . ' ';
            $entero %= 1000000;
        }
    
        // Manejo de miles
        if ($entero >= 1000) {
            $miles = intval($entero / 1000);
            $resultado .= numeroALetras($miles) . ' mil ';
            $entero %= 1000;
        }
    
        // Manejo de centenas
        if ($entero >= 100) {
            $resultado .= $centenas[intval($entero / 100)] . ' ';
            $entero %= 100;
        }
    
        // Manejo de decenas
        if ($entero >= 20) {
            $resultado .= $decenas[intval($entero / 10)] . ' ';
            $entero %= 10;
        }
    
        // Manejo de unidades
        if ($entero > 0) {
            if ($resultado != '') {
                $resultado .= 'y '; // Agrega "y" si ya hay decenas o centenas
            }
            $resultado .= $unidades[$entero] . ' ';
        }
    
        // Agregar la parte decimal
        if ($decimal > 0) {
            $resultado .= 'con ';
            $resultado .= numeroALetras($decimal) . ' centavos'; // Puedes cambiar "centavos" según tus necesidades
        }
    
        return trim($resultado);
    }

    // Variables del QR
    $uuid = $array['Complemento']['UUID'];
    $rfcEmisor = $Emisor['Rfc'];
    $rfcReceptor = $Receptor['Rfc'];
    $total = $array['Total'];
    $selloCFD = $array['Complemento']['SelloCFD']; // Este es el selloCFD completo
    $fe = substr($selloCFD, -8); // Últimos 8 caracteres del sello CFD

    // Contenido del QR
    $urlQR = "https://verificacfdi.facturaelectronica.sat.gob.mx/default.aspx?id=$uuid&re=$rfcEmisor&rr=$rfcReceptor&tt=$total&fe=$fe";

    // Generar el QR
    $qrCode = new QrCode($urlQR);
    $qrCode->setSize(300); // Tamaño del QR
    $qrCode->setMargin(10); // Margen del QR
    $qrCode->setForegroundColor(new Color(0, 0, 0)); // Color del QR (negro)
    $qrCode->setBackgroundColor(new Color(255, 255, 255)); // Color de fondo (blanco)

    // Escribir la imagen en un archivo
    $writer = new PngWriter();
    $qrFilePath = 'qr_cfdi_'.$id.'.png'; // Nombre del archivo
    $result = $writer->write($qrCode);
    $result->saveToFile($qrFilePath); // Guarda el archivo QR

    //echo "Código QR generado y guardado en: $qrFilePath";
    
    // Crear PDF
    $pdf = new PDF();
    $pdf->AliasNbPages();
    
    // Establecer propiedades para el header
    $pdf->emisorNombre = $Emisor['Nombre'];
    $pdf->emisorRFC = $Emisor['Rfc'];
    $pdf->lugarExpedicion = $Emisor['LugarExpedicion'];
    $pdf->regimenFiscal = regFiscal($Emisor['RegimenFiscal']);
    $pdf->domicilioEmisor = $Emisor['domicilioEmisor'];
    $pdf->paisReceptor = $Receptor['Pais'];
    $pdf->estadoReceptor = $Receptor['Estado'];
    $pdf->municipioReceptor = $Receptor['Municipio'];
    $pdf->ciudadReceptor = $Receptor['Ciudad'];
    $pdf->coloniaReceptor = $Receptor['Colonia'];
    $pdf->manzanaReceptor = $Receptor['Manzana'];
    $pdf->numExtReceptor = $Receptor['NumExt'];
    $pdf->numIntReceptor = $Receptor['NumInt'];
    $pdf->calleReceptor = $Receptor['Calle'];
    $pdf->cpReceptor = $Receptor['Cp'];
    //logToFile('0', '0', 'Tipo de comprobante 2', 'info', $array['TipoDeComprobante'] . ' ' . $array['Comprobante']);
    $pdf->TipoDeComprobante = isset($array['TipoDeComprobante']) ? $array['TipoDeComprobante'] : $array['Comprobante'];
    $pdf->AddPage();
    
    $pdf->SetFillColor(220, 220, 220);
    $pdf->Rect(10, 50, 195, 20, 'DF');

    // Información de la factura (esquina superior derecha, fuera de la caja gris)
    $pdf->SetFont('Arial','B',7);
    $pdf->SetXY(110, 51);
    $pdf->Cell(80, 4, 'Folio: ', 0, 1, 'R');
    $pdf->SetFont('Arial','',7);
    $pdf->SetXY(115, 51);
    $pdf->Cell(80, 4, $id, 0, 1, 'R');

    $pdf->SetFont('Arial','B',7);
    $pdf->SetXY(93, 55);
    $pdf->Cell(80, 4, 'Fecha: ', 0, 1, 'R');
    $pdf->SetFont('Arial','',7);
    $pdf->SetXY(120, 55);
    $pdf->Cell(80, 4, $fechaFinal, 0, 1, 'R');

    $pdf->SetFillColor(255, 255, 255);
    // Información del Cliente (lado izquierdo, debajo del header)
    $pdf->SetFont('Arial','B',7);
    $pdf->SetXY(10, 59);
    $pdf->Cell(0, 4, 'Cliente:', 0, 1, 'L', 1);
    $pdf->SetFont('Arial','',7);
    $pdf->SetXY(23, 59);
    $pdf->Cell(0, 4, safe_sutf8_decode($Receptor['Nombre']), 0, 1, 'L', 1);

    $pdf->SetFont('Arial','B',7);
    $pdf->SetXY(80, 59);
    $pdf->Cell(0, 4, 'R.F.C.:', 0, 1, 'L', 1);
    $pdf->SetFont('Arial','',7);
    $pdf->SetXY(90, 59);
    $pdf->Cell(0, 4, safe_sutf8_decode($Receptor['Rfc']), 0, 1, 'L', 1);

    $pdf->SetFont('Arial','B',7);
    $pdf->SetXY(150, 59);
    $pdf->MultiCell(55, 4, safe_sutf8_decode('Régimen Fiscal: '), 0, 1, 'L', 1);
    $pdf->SetFont('Arial','',7);
    $pdf->SetXY(145, 63);
    $pdf->MultiCell(0, 4, safe_sutf8_decode(regFiscal($Receptor['RegimenFiscalReceptor'])), 0, 1);

    $direccion = implode(', ', array_filter([
        $Receptor['Pais'] ?? '',
        $Receptor['Estado'] ?? '',
        $Receptor['Ciudad'] ?? '',
        $Receptor['Municipio'] ?? '',
        $Receptor['Cp'] ?? '',
        $Receptor['Colonia'] ?? '',
        $Receptor['Manzana'] ?? '',
        $Receptor['Calle'] ?? '',
        $Receptor['NumExt'] ?? '',
        $Receptor['NumInt'] ?? ''
    ]));
    $pdf->SetFont('Arial','B',7);
    $pdf->SetXY(10, 63);
    $pdf->Cell(0, 4, 'Domicilio:', 0, 1);
    $pdf->SetFont('Arial','',7);
    $pdf->SetXY(25, 63);
    $pdf->MultiCell(120, 4, safe_sutf8_decode($direccion), 0, 1);
    

    $pdf->Ln(8);
    // Tabla
    $pdf->FacturaTable($header, $data);

    $y = $pdf->GetY();
    $y = $y + 5;

    $espacioDisponible = $pdf->GetPageHeight() - $pdf->GetY();
    if ($espacioDisponible < 50) {
        $pdf->AddPage();
        $y = 10;
    }
    // Total con letra (lado izquierdo)
    $pdf->SetXY(10, $y);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(0, 4, 'Importe con letra:', 0, 1, 'L');
    $pdf->SetFont('Arial', '', 7);
    $pdf->MultiCell(80, 3, safe_sutf8_decode(mb_strtoupper(numeroALetras($array['Total']), 'UTF-8') . ' PESOS 00/100 M.N.'), 0, 'L');

    // Tabla de totales (lado derecho)
    $subtotal = isset($array['SubTotal']) && is_numeric($array['SubTotal']) ? (float)$array['SubTotal'] : $array['Total'];
    
    $pdf->SetFillColor(220, 220, 220);
    $pdf->SetFont('Arial', 'B', 7);
    
    // Subtotal
    $pdf->SetXY(135, $y);
    $pdf->Cell(40, 6, 'Subtotal:', 1, 0, 'R', true);
    $pdf->SetFont('Arial', '', 7);
    $pdf->Cell(30, 6, '$' . number_format($subtotal, 2), 1, 1, 'R');
    
    // Impuestos IVA
    $totalImpuestosTrasFormatted = is_numeric($totalImpuestosTrasladados) 
        ? number_format((float) $totalImpuestosTrasladados, 2) 
        : number_format((isset($array['TotalImpuestos']) ? (float) $array['TotalImpuestos'] : 0), 2);
    
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetXY(135, $y + 6);
    $pdf->Cell(40, 6, 'Impuestos I.V.A.:', 1, 0, 'R', true);
    $pdf->SetFont('Arial', '', 7);
    $pdf->Cell(30, 6, '$' . $totalImpuestosTrasFormatted, 1, 1, 'R');
    
    // Total
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetXY(135, $y + 12);
    $pdf->Cell(40, 6, 'Total:', 1, 0, 'R', true);
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->Cell(30, 6, '$' . number_format($array['Total'], 2), 1, 1, 'R');

    $pdf->Ln(5);

    $y = $pdf->GetY();
    $y = $y;
    
    $espacioDisponible = $pdf->GetPageHeight() - $pdf->GetY();
    if ($espacioDisponible < 50) {
        $pdf->AddPage();
        $y = 10;
    }
    
    $pdf->SetFillColor(220, 220, 220);
    $pdf->Rect(10, $y-2, 195, 18, 'DF');

    // Sección de forma de pago y método de pago
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetXY(10, $y);
    $pdf->Cell(0, 4, 'Forma de pago:', 0, 1, 'L');
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetXY(35, $y);
    $pdf->MultiCell(40, 4, safe_sutf8_decode($formaPago), 0, 1, 'C');

    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetXY(80, $y);
    $pdf->Cell(0, 4, 'Cuenta:', 0, 1, 'L');
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetXY(95, $y);
    $pdf->Cell(0, 4, '', 0, 1, 'L');

    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetXY(140, $y);
    $pdf->Cell(0, 4, 'Moneda:', 0, 1, 'L');
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetXY(155, $y);
    $pdf->Cell(0, 4, safe_sutf8_decode($array['Moneda']) . ' - Peso Mexicano', 0, 1, 'L');
    
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetXY(10, $y + 8);
    $pdf->Cell(0, 4, safe_sutf8_decode('Método de pago:'), 0, 1, 'L');
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetXY(35, $y + 8);
    $pdf->MultiCell(50, 4, safe_sutf8_decode($metodoPago), 0, 1, 'C');
    
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetXY(80, $y + 8);
    $pdf->Cell(0, 4, 'Uso CFDI:', 0, 1, 'L');
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetXY(95, $y + 8);
    $pdf->Cell(0, 4, safe_sutf8_decode(cfdi($Receptor['UsoCFDI'])), 0, 1, 'L');
    
    
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetXY(140, $y + 8);
    $pdf->Cell(0, 4, 'Tipo de Cambio:', 0, 1, 'L');
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetXY(155, $y + 8);
    $pdf->Cell(0, 4, '', 0, 1, 'L');

    $y = $pdf->GetY();
    $y = $y + 5;

    if(isset($DocsRelacionados) && !empty($DocsRelacionados)) {
        $pdf->RelacionadosTable($DocsRelacionados);
    }

    $y = $pdf->GetY();
    $y = $y + 5;

    if(isset($array['Pagos']) && !empty($array['Pagos'])) {
        logToFile($username, $userID, 'Se envia a la tabla de pagos', "success", json_encode($array['Pagos']));
        $pdf->PagosTable($array['Pagos']);
        $pdf->Ln(5);
        $y = $pdf->GetY();
        $y = $y;
    }

    if(isset($array['Complemento'])) {
        // Verificar si el QR cabe en la página actual
        $espacioDisponible = $pdf->GetPageHeight() - $pdf->GetY();
        if ($espacioDisponible < 50) {
            $pdf->AddPage();
            $y = 10;
        }

        // QR code más grande en el lado izquierdo
        if (file_exists($qrFilePath)) {
            $pdf->Image($qrFilePath, 15, $y, 40, 40);
        }

        $pdf->Rect(70, $y, 110, 30, 'D');
        // Información de certificación en el lado derecho
        $pdf->SetFont('Arial', 'B', 7);
        $pdf->SetXY(70, $y);
        $pdf->Cell(50, 4, 'Serie del Certificado del emisor:', 0, 1, 'L');
        $pdf->SetFont('Arial', '', 7);
        $pdf->SetXY(120, $y);
        $pdf->Cell(70, 4, $array['Complemento']['NoCertificadoSAT'], 0, 1, 'L');

        $pdf->SetFont('Arial', 'B', 7);
        $pdf->SetXY(70, $y + 5);
        $pdf->Cell(50, 4, 'Folio fiscal:', 0, 1, 'L');
        $pdf->SetFont('Arial', '', 7);
        $pdf->SetXY(120, $y + 5);
        $pdf->Cell(70, 4, $array['Complemento']['UUID'], 0, 1, 'L');

        $pdf->SetFont('Arial', 'B', 7);
        $pdf->SetXY(70, $y + 10);
        $pdf->Cell(50, 4, 'No. de Serie del Certificado del SAT:', 0, 1, 'L');
        $pdf->SetFont('Arial', '', 7);
        $pdf->SetXY(120, $y + 10);
        $pdf->Cell(70, 4, $array['Complemento']['NoCertificadoSAT'], 0, 1, 'L');

        $pdf->SetFont('Arial', 'B', 7);
        $pdf->SetXY(70, $y + 15);
        $pdf->Cell(50, 4, safe_sutf8_decode('Fecha y hora de certificación:'), 0, 1, 'L');
        $pdf->SetFont('Arial', '', 7);
        $pdf->SetXY(120, $y + 15);
        $pdf->Cell(70, 4, $array['Complemento']['FechaTimbrado'], 0, 1, 'L');

        // Mensaje de representación impresa
        $y = $pdf->GetY();
        $y = $y;
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetXY(45, $y);
        $pdf->Cell(0, 4, safe_sutf8_decode('Este documento es una representación impresa de un CFDI'), 0, 1, 'C');

        // Sellos digitales
        $y = $pdf->GetY();
        $y = $y + 20;
        $pdf->SetY($y);

        $espacioDisponible = $pdf->GetPageHeight() - $pdf->GetY();
        if ($espacioDisponible < 50) {
            $pdf->AddPage();
            $y = 10;
        }

        $pdf->Rect(10, $y, 195, 20, 'D');
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetXY(10, $y);
        $pdf->Cell(195, 4, 'Sello Digital del CFDi:', 0, 1, 'L', 1);
        $pdf->SetFont('Arial', '', 7);
        $pdf->SetXY(10, $y + 4);
        $pdf->MultiCell(195, 4, $array['Complemento']['SelloCFD'], 0, 'L', 0);

        $y = $pdf->GetY();
        $y = $y + 4;
        $pdf->SetY($y);

        $espacioDisponible = $pdf->GetPageHeight() - $pdf->GetY();
        if ($espacioDisponible < 50) {
            $pdf->AddPage();
            $y = 10;
        }

        $pdf->Rect(10, $y, 195, 20, 'D');
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetXY(10, $y);
        $pdf->Cell(195, 4, 'Sello del SAT:', 0, 1, 'L', 1);
        $pdf->SetFont('Arial', '', 7);
        $pdf->SetXY(10, $y + 4);
        $pdf->MultiCell(195, 3, $array['Complemento']['SelloSAT'], 0, 'L');

    } else {
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetXY(10, $y);
        $pdf->MultiCell(0, 4, safe_sutf8_decode('Este documento es una previsualización de la factura y no tiene validez legal ni fiscal.
    Esta previsualización se genera con fines informativos y no constituye una factura electrónica timbrada por el SAT.
    Para obtener una factura oficial con validez fiscal, es necesario completar el proceso de timbrado.'), 0, 'C');
    }
    /*// Añadir la Cadena original del complemento
    $pdf->SetXY(60, 300); // Ajusta la posición en el PDF
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 10, 'Cadena original del complemento del certificacion digital del SAT:', 0, 1);
    $pdf->SetFont('Arial', '', 8);
    $pdf->MultiCell(0, 5, $array['Complemento']['CadenaOriginal'], 0, 'L');*/

    // Generar el PDF y capturarlo en el buffer
    $pdfContent = $pdf->Output('S'); // 'S' para retornar el PDF como string
    //$pdf->Output();
    
    // Limpiar el buffer de salida
    ob_end_clean();

    // Convertir el contenido del PDF a base64
    $pdfBase64 = base64_encode($pdfContent);
    unlink($qrFilePath);
    // Retornar el PDF en formato base64
    return $pdfBase64;
}

?>