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
cors();

$data = json_decode(file_get_contents('php://input'), true);

if($_GET['preview'] && $data) {
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

function leerXML($nombreArchivoXml, $pdf = false, $id = null) {

    // Leer el archivo XML directamente
    if (!file_exists($nombreArchivoXml)) {
        echo 'Error: El archivo XML no existe. Ruta: ' . realpath($nombreArchivoXml);
        return;
    }

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
                'MetodoPago' => (string) $xml['MetodoPago'],
                'LugarExpedicion' => (string) $xml['LugarExpedicion'],
                'TipoDeComprobante' => (string) $xml['TipoDeComprobante'],
                'Moneda' => (string) $xml['Moneda'],
                'TipoCambio' => (string) $xml['TipoCambio'],
                'Sello' => (string) $xml['Sello']
            ];

            // Espacio de nombres, asumiendo que están definidos
            $namespaces = $xml->getNamespaces(true);
            // Registrar el espacio de nombres (ajusta la URL según sea necesario)
            $xml->registerXPathNamespace('tfd', 'http://www.sat.gob.mx/TimbreFiscalDigital');
            
            // Datos del emisor
            $emisor = $xml->xpath('//cfdi:Emisor');
            //print_r($emisor);
            $factura['Emisor'] = [
                'Rfc' => (string)$emisor[0]['Rfc'],
                'Nombre' => (string)$emisor[0]['Nombre'],
                'RegimenFiscal' => (string)$emisor[0]['RegimenFiscal']
            ];

            // Datos del receptor
            $receptor = $xml->xpath('//cfdi:Receptor');
            $factura['Receptor'] = [
                'Rfc' => (string)$receptor[0]['Rfc'],
                'Nombre' => (string)$receptor[0]['Nombre'],
                'RegimenFiscalReceptor' => (string)$receptor[0]['RegimenFiscalReceptor'],
                'UsoCFDI' => (string)$receptor[0]['UsoCFDI'],
                'DomicilioFiscalReceptor' => (string)$receptor[0]['DomicilioFiscalReceptor'],
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
                $totalTrasladosBaseIVA16 = (string) $totales[0]['TotalTrasladosBaseIVA16'];
                $totalTrasladosImpuestoIVA16 = (string) $totales[0]['TotalTrasladosImpuestoIVA16'];
                $montoTotalPagos = (string) $totales[0]['MontoTotalPagos'];
                // Reemplazar los valores en el concepto si existen valores en totales
                foreach ($factura['Conceptos'] as &$concepto) {
                    if ($concepto['ValorUnitario'] == "0") {
                        $concepto['ValorUnitario'] = $totalTrasladosBaseIVA16;
                        $concepto['Base'] = $totalTrasladosBaseIVA16;
                        $concepto['ImporteImpuesto'] = $totalTrasladosImpuestoIVA16;
                        $concepto['Impuesto'] = $totalTrasladosImpuestoIVA16;
                        $concepto['Importe'] = $montoTotalPagos;
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
            $complemento = $xml->xpath('//cfdi:Complemento/tfd:TimbreFiscalDigital');
            $factura['Complemento'] = [
                'UUID' => (string) $complemento[0]['UUID'],
                'FechaTimbrado' => (string) $complemento[0]['FechaTimbrado'],
                'RfcProvCertif' => (string) $complemento[0]['RfcProvCertif'],
                'SelloCFD' => (string) $complemento[0]['SelloCFD'],
                'NoCertificadoSAT' => (string) $complemento[0]['NoCertificadoSAT'],
                'SelloSAT' => (string) $complemento[0]['SelloSAT']
            ];

            // Retornar la factura organizada en un array
            //print_r($factura);
            //unlink($nombreArchivoXml); // Asegúrate de que esto es lo que deseas hacer

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

    $res_emisor = null;
    try {
        $sql = "SELECT * FROM `cuenta_factura` ORDER BY `id` DESC LIMIT 1";
    
        // Ejecutar la consulta (puedes usar tu conexión y método habitual)
        $stmt = $con->prepare($sql);
        $stmt->execute();
        // Obtener el resultado
        $result = $stmt->get_result();
    
        $stmt->close();
        $con->close();
        if ($result && $row = $result->fetch_assoc()) {
            $res_emisor = $row;
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
        "Rfc" => $res_emisor["rfc"],
        "Nombre" => $res_emisor["nombre"],
        "RegimenFiscal" => $res_emisor["regimen"]
    ];

    $data['Emisor'] = $arrEmisor;

    //Info General
    $data['Fecha'] = date('Y-m-d\TH:i:s');
    $data['LugarExpedicion'] = $res_emisor['cp'];
    $data['TipoCambio'] = isset($data['TipoCambio']) ? $data['TipoCambio'] : 1;
    $data['TipoDeComprobante'] = 'I';
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
                /*$imgInfo = getimagesize('../../assets/img/icon.png');
                if ($imgInfo) {
                    list($width, $height) = $imgInfo;
                    echo "Ancho: $width px, Alto: $height px";
                } else {
                    die("Error: No se pudo obtener información de la imagen.");
                }*/
                // Logo
                if (file_exists('assets/logo.png')) {
                    $this->Image('assets/logo.png', 10, 1, 80, 50);
                }
                // Arial bold 15
                $this->SetFont('Arial', 'B', 12);
                // Movernos a la derecha
                $this->Cell(80);
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
            // Definir los anchos de las columnas
            $widths = array(15, 18, 25, 35, 16, 15, 22, 22, 22); // Ajusta los valores de ancho según las necesidades
            
            // Cabecera
            $this->SetFont('Arial', 'B', 7);
            $this->SetFillColor(200, 200, 200);
            
            foreach ($header as $i => $col) {
                $this->Cell($widths[$i], 10, safe_sutf8_decode($col), 1, 0, 'C', true);
            }
            $this->Ln();
        
            // Datos
            $this->SetFont('Arial', '', 8);
        
            foreach ($data as $row) {
                // Variable para determinar la altura más grande de la celda
                $maxHeight = 0;
        
                // Iterar sobre cada columna de la fila para obtener la altura máxima
                foreach ($row as $i => $col) {
                    // Guardar la posición de la celda actual
                    $this->SetX(0);
                    $x = $this->GetX();
                    $y = $this->GetY();
        
                    // Calcular la altura de la celda actual usando la función GetMultiCellHeight
                    $textHeight = $this->GetMultiCellHeight($widths[$i], 6, safe_sutf8_decode($col));
                    
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
                    
                    // Dibujar MultiCell y luego un borde alrededor de la celda con la altura máxima
                    $this->MultiCell($widths[$i], 6, safe_sutf8_decode($col), 0, 'C');
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
    }

    function safe_sutf8_decode($string) {
        return utf8_decode($string ?? '');
    }

    $Emisor = $array['Emisor'];
    $Receptor = $array['Receptor'];
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
    $header = array('Cantidad', 'Clave Uni SAT', 'Clave Prod/Servicio', 'Descripcion', 'Valor uni', 'Descuento', 'Imp Trasladados', 'Imp Retenidos', 'Importe');
    
    $data = [];

    $descuentos = 0;
    // Bucle para llenar el array data con los conceptos
    foreach ($array['Conceptos'] as $concepto) {
        $cantidad = $concepto['Cantidad'];
        $claveUnidad = $concepto['ClaveUnidad'];
        $claveProdServ = $concepto['ClaveProdServ'];
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
        $impuestosTrasladados = '$' . number_format($concepto['ImporteImpuestoTrasladado'], 2); // Impuesto formateado
        $impuestosRetenidos = '$' . number_format($concepto['ImporteImpuestoRetenido'], 2); // Impuesto formateado
        $importe = '$' . number_format($concepto['Importe'], 2); // Importe formateado

        // Agregar los datos del concepto al array data
        $data[] = [$cantidad, $claveUnidad, $claveProdServ, $descripcion, $valorUnitario, $descuento, $impuestosTrasladados, $impuestosRetenidos, $importe];
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
    $pdf->AddPage();
    
    $pdf->SetXY(100,20);
    $pdf->SetFont('Arial','',10);
    $pdf->Cell(100,10,'Tipo de Comprobante: I - Ingreso',0,1, 'C');
    $pdf->SetXY(100,25);
    $pdf->Cell(100,10,'Serie: CFDIC',0,1, 'C');
    $pdf->SetXY(100,30);
    $pdf->Cell(100,10,'Folio: '.$id,0,1, 'C');
    $pdf->SetXY(100,35);
    $pdf->Cell(100,10,'Fecha: '.$fechaFinal,0,1, 'C');
    $pdf->SetXY(100,40);
    $pdf->Cell(100,10,'Lugar de Expedicion (CP): '.$array['LugarExpedicion'],0,1, 'C');
    $pdf->SetXY(100,45);
    $pdf->Cell(100,10,'Metodo de Pago: '.safe_sutf8_decode($metodoPago),0,1, 'C');
    $pdf->SetXY(100,50);
    $pdf->Cell(100,10,'Forma de Pago: '.safe_sutf8_decode($formaPago),0,1, 'C');
    $pdf->SetXY(100,55);
    $pdf->Cell(100,10,'Moneda: '.safe_sutf8_decode($array['Moneda']),0,1, 'C');
    $pdf->Ln(10);

    // Información Emisor
    $pdf->SetFont('Arial','B',10);
    $pdf->SetXY(10,40);
    $pdf->Cell(0,10,'Emisor:',0,1);
    $pdf->SetFont('Arial','',10);
    $pdf->SetXY(10,45);
    $pdf->Cell(0,10, $Emisor['Nombre'],0,1);
    $pdf->SetXY(10,50);
    $pdf->Cell(0,10,'RFC: '.$Emisor['Rfc'],0,1);
    $pdf->SetXY(10,55);
    $pdf->Cell(0,10,'Regimen Fiscal: '.safe_sutf8_decode(regFiscal($Emisor['RegimenFiscal'])),0,1);
    $pdf->Ln(10);

    // Información Receptor
    $pdf->SetFont('Arial','B',10);
    $pdf->SetXY(10,65);
    $pdf->Cell(0,10,'Receptor:',0,1);
    $pdf->SetFont('Arial','',10);
    $pdf->SetXY(10,70);
    $pdf->Cell(0,10,'Cliente: '.safe_sutf8_decode($Receptor['Nombre']),0,1);
    $pdf->SetXY(10,75);
    $pdf->Cell(0,10,'RFC: '.$Receptor['Rfc'],0,1);
    $pdf->SetXY(10,80);
    $pdf->Cell(0,10,'Regimen Fiscal: '.safe_sutf8_decode(regFiscal($Receptor['RegimenFiscalReceptor'])),0,1);
    $pdf->SetXY(10,85);
    $pdf->Cell(0,10,'Uso CFDI: '.safe_sutf8_decode(cfdi($Receptor['UsoCFDI'])),0,1);
    $pdf->SetXY(10,90);
    $pdf->Cell(0,10,'Domicilio Fiscal: '.safe_sutf8_decode(cfdi($Receptor['DomicilioFiscalReceptor'])),0,1);
    $pdf->Ln(10);
    
    // Tabla
    $pdf->FacturaTable($header, $data);

    $y = $pdf->GetY();
    $y = $y +5;
    $pdf->SetXY(10, $y); // Ajusta la posición en el PDF
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 10, 'Total con letra:', 0, 1, 'L');
    $pdf->SetFont('Arial', '', 8);
    $pdf->MultiCell(90, 5, safe_sutf8_decode(numeroALetras($array['Total'])), 0, 'L');

    //print_r($array['TotalImpuestos']);
    $totalImpuestosTrasladados = $array['TotalImpuestosTrasladados'];
    $totalImpuestosRetenidos = $array['TotalImpuestosRetenidos'];
    
    $subtotal = isset($array['SubTotal']) && is_numeric($array['SubTotal']) ? (float)$array['SubTotal'] : $array['Total'];

    $pdf->SetXY(90, $y);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(100, 10, 'Subtotal: $' . number_format($subtotal, 2), 0, 1, 'R');

    $pdf->SetXY(90, $y + 5);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(100, 10, 'Descuentos: $' . number_format($descuentos, 2), 0, 1, 'R');

    $pdf->SetXY(90, $y + 10);
    $pdf->SetFont('Arial', 'B', 10);

    // Verifica si $totalImpuestos es válido
    $totalImpuestosTrasFormatted = is_numeric($totalImpuestosTrasladados) 
        ? number_format($totalImpuestosTrasladados, 2) 
        : number_format((isset($array['TotalImpuestos']) ? $array['TotalImpuestos'] : 0), 2);
    $totalImpuestosReteFormatted = is_numeric($totalImpuestosRetenidos) 
        ? number_format($totalImpuestosRetenidos, 2) 
        : '0.00';
    $pdf->Cell(100, 10, 'Impuestos Trasladados: $' . $totalImpuestosTrasFormatted, 0, 1, 'R');
    $pdf->SetXY(90, $y + 15);
    $pdf->Cell(100, 10, 'Impuestos Retenidos: $' . $totalImpuestosReteFormatted, 0, 1, 'R');

    $pdf->SetXY(90, $y + 20);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(100, 10, 'Total: $' . number_format($array['Total'], 2), 0, 1, 'R');

    $pdf->Ln(10);

    $y = $pdf->GetY();
    $y = $y+10;

    if(isset($array['Complemento'])) {
        // Añadir QR
        $tamanoQR = 40; // Tamaño del QR en mm
        $espacioDisponible = $pdf->GetPageHeight() - $pdf->GetY(); // Espacio restante en la página

        // Verificar si el QR cabe en la página actual
        if ($espacioDisponible < $tamanoQR) {
            // Si no hay suficiente espacio, crear una nueva página
            $pdf->AddPage();
            $y = $pdf->GetY();
            $y = $y+10;
        }

        // Generar el código QR en la posición deseada
        if (file_exists($qrFilePath)) {
            $pdf->Image($qrFilePath, 10, $y, 40, 40);
        } else {
            echo "El archivo no se encuentra en la ruta: ".$qrFilePath;
        }

        $pdf->SetXY(50, $y); // Ajusta la posición en el PDF
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(50, 5, 'Serie del Certificado del emisor:', 0, 1, 'R');
        $y = $pdf->GetY();
        $pdf->SetXY(100, $y-5); // Ajusta la posición en el PDF
        $pdf->SetFont('Arial', '', 8);
        $pdf->MultiCell(90, 5, $array['Complemento']['NoCertificadoSAT'], 0, 'L');

        $y = $pdf->GetY();
        $y = $y+5;
        $pdf->SetXY(50, $y); // Ajusta la posición en el PDF
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(50, 5, 'Folio Fiscal:', 0, 1, 'R');
        $y = $pdf->GetY();
        $pdf->SetXY(100, $y-5); // Ajusta la posición en el PDF
        $pdf->SetFont('Arial', '', 8);
        $pdf->MultiCell(90, 5, $array['Complemento']['UUID'], 0, 'L');

        $y = $pdf->GetY();
        $y = $y+5;
        $pdf->SetXY(50, $y); // Ajusta la posición en el PDF
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(50, 5, 'No. de serie del Certificado del SAT:', 0, 1, 'R');
        $y = $pdf->GetY();
        $pdf->SetXY(100, $y-5); // Ajusta la posición en el PDF
        $pdf->SetFont('Arial', '', 8);
        $pdf->MultiCell(90, 5, $array['Complemento']['NoCertificadoSAT'], 0, 'L');

        $y = $pdf->GetY();
        $y = $y+5;
        $pdf->SetXY(50, $y); // Ajusta la posición en el PDF
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(50, 10, safe_sutf8_decode('Fecha y hora de certificación:'), 0, 1, 'R');
        $y = $pdf->GetY();
        $pdf->SetXY(100, $y-10); // Ajusta la posición en el PDF
        $pdf->SetFont('Arial', '', 8);
        $pdf->MultiCell(90, 10, $array['Complemento']['FechaTimbrado'], 0, 'L');

        // Añadir Sello digital del CFDI
        $y = $pdf->GetY();
        $y = $y +10;
        $pdf->SetXY(10, $y); // Ajusta la posición en el PDF
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(0, 5, 'Sello digital del CFDI:', 0, 1, 'C', true);
        $pdf->SetFont('Arial', '', 8);
        $pdf->MultiCell(0, 5, $array['Complemento']['SelloCFD'], 0, 'L');

        // Añadir Sello del SAT
        $y = $pdf->GetY();
        $y = $y +5;
        $pdf->SetXY(10, $y); // Ajusta la posición en el PDF
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(0, 5, 'Sello del SAT:', 0, 1, 'C', true);
        $pdf->SetFont('Arial', '', 8);
        $pdf->MultiCell(0, 5, $array['Complemento']['SelloSAT'], 0, 'L');

    } else {
        $pdf->MultiCell(0, 5, safe_sutf8_decode('Este documento es una previsualización de la factura y no tiene validez legal ni fiscal.
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