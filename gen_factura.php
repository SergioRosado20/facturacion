<?php
/*ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);*/
require 'constants.php';
require 'pdf.php';
require 'log_helper.php';
require_once('vendor/autoload.php');
require_once "cors.php";
cors();

date_default_timezone_set('America/Mexico_City');
session_start();

$client = new \GuzzleHttp\Client();

$username = $_SESSION['username'];

$con = new mysqli(DB_SERVER, DB_USER, DB_PASSWORD, DB_NAME);
// Decodificar el JSON a un array asociativo
$data = json_decode(file_get_contents('php://input'), true);
$data_string = json_encode($data);

if (isset($data['cuerpo'])) {
    $productos = $data['cuerpo']['Conceptos'];  // Acceder a 'Conceptos' dentro de 'cuerpo'
    $cliente = $data['cuerpo']['Cliente'];      // Acceder a 'Cliente' dentro de 'cuerpo'
    $emisor = $data['cuerpo']['Emisor'];
    $token = $data['cuerpo']['Token'];
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
$totalImp = 0;
// Iterar sobre cada producto y formatearlo para el array Conceptos
foreach($productos as $producto) {
    $impuestos = $producto["impuestos"];
    $importe = number_format($producto["cantidad"] * $producto["precio"], 2, '.', '');

    $nodoImp = [];
    foreach($impuestos as $impuesto) {
        $impuestoImporte = number_format($importe * floatval($impuesto["tasaImp"]), 2, '.', '');
        $prodImp = [
            "TipoImpuesto" => $impuesto["tipoImp"], //int
            "Impuesto" => $impuesto["impuesto"],    //int
            "Factor" => $impuesto["factorImp"],     //int
            "Base" => $importe,                     //double
            "Tasa" => $impuesto["tasaImp"],         //string:  "0.160000", "0.08000"
            "ImpuestoImporte" => $impuestoImporte   //double
        ];

        $nodoImp[] = $prodImp;
        $totalImp += $impuestoImporte;
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
    ];

    $conceptos[] = $concepto;
    $subTotal += $importe;
}

$total = bcadd($subTotal, $totalImp, 2); // La multiplicación se realiza con 2 decimales de precisión
// Redondea el total a 2 decimales para asegurar la precisión
$totalRedondeado = round((float)$total, 2);
// Luego, si es necesario, conviértelo a float para el JSON
$totalFloat = (float)$totalRedondeado;
$totalReportado = $totalFloat;
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
    $numExtEmisor = $emisor["numExt"];
    $numIntEmisor = $emisor["numInt"];
    $coloniaEmisor = $emisor["colonia"];
    $localidadEmisor = $emisor["localidad"];
    $municipioEmisor = $emisor["municpio"];
    $estadoEmisor = $emisor["estado"];
    $cpEmisor = $emisor["cp"];
    $paisEmisor = $emisor["pais"];

    // Otros Datos
    $fechaExpedicion = date('Y-m-d\TH:i:s');

    /*$CSD = "credenciales/certificado.cer";
    $Key = "credenciales/key.key";

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
    }*/
    
    /*
    "CSD" => "MIIFsDCCA5igAwIBAgIUMzAwMDEwMDAwMDA1MDAwMDM0MTYwDQYJKoZIhvcNAQELBQAwggErMQ8wDQYDVQQDDAZBQyBVQVQxLjAsBgNVBAoMJVNFUlZJQ0lPIERFIEFETUlOSVNUUkFDSU9OIFRSSUJVVEFSSUExGjAYBgNVBAsMEVNBVC1JRVMgQXV0aG9yaXR5MSgwJgYJKoZIhvcNAQkBFhlvc2Nhci5tYXJ0aW5lekBzYXQuZ29iLm14MR0wGwYDVQQJDBQzcmEgY2VycmFkYSBkZSBjYWxpejEOMAwGA1UEEQwFMDYzNzAxCzAJBgNVBAYTAk1YMRkwFwYDVQQIDBBDSVVEQUQgREUgTUVYSUNPMREwDwYDVQQHDAhDT1lPQUNBTjERMA8GA1UELRMIMi41LjQuNDUxJTAjBgkqhkiG9w0BCQITFnJlc3BvbnNhYmxlOiBBQ0RNQS1TQVQwHhcNMjMwNTE4MTE0MzUxWhcNMjcwNTE4MTE0MzUxWjCB1zEnMCUGA1UEAxMeRVNDVUVMQSBLRU1QRVIgVVJHQVRFIFNBIERFIENWMScwJQYDVQQpEx5FU0NVRUxBIEtFTVBFUiBVUkdBVEUgU0EgREUgQ1YxJzAlBgNVBAoTHkVTQ1VFTEEgS0VNUEVSIFVSR0FURSBTQSBERSBDVjElMCMGA1UELRMcRUtVOTAwMzE3M0M5IC8gVkFEQTgwMDkyN0RKMzEeMBwGA1UEBRMVIC8gVkFEQTgwMDkyN0hTUlNSTDA1MRMwEQYDVQQLEwpTdWN1cnNhbCAxMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAtmecO6n2GS0zL025gbHGQVxznPDICoXzR2uUngz4DqxVUC/w9cE6FxSiXm2ap8Gcjg7wmcZfm85EBaxCx/0J2u5CqnhzIoGCdhBPuhWQnIh5TLgj/X6uNquwZkKChbNe9aeFirU/JbyN7Egia9oKH9KZUsodiM/pWAH00PCtoKJ9OBcSHMq8Rqa3KKoBcfkg1ZrgueffwRLws9yOcRWLb02sDOPzGIm/jEFicVYt2Hw1qdRE5xmTZ7AGG0UHs+unkGjpCVeJ+BEBn0JPLWVvDKHZAQMj6s5Bku35+d/MyATkpOPsGT/VTnsouxekDfikJD1f7A1ZpJbqDpkJnss3vQIDAQABox0wGzAMBgNVHRMBAf8EAjAAMAsGA1UdDwQEAwIGwDANBgkqhkiG9w0BAQsFAAOCAgEAFaUgj5PqgvJigNMgtrdXZnbPfVBbukAbW4OGnUhNrA7SRAAfv2BSGk16PI0nBOr7qF2mItmBnjgEwk+DTv8Zr7w5qp7vleC6dIsZFNJoa6ZndrE/f7KO1CYruLXr5gwEkIyGfJ9NwyIagvHHMszzyHiSZIA850fWtbqtythpAliJ2jF35M5pNS+YTkRB+T6L/c6m00ymN3q9lT1rB03YywxrLreRSFZOSrbwWfg34EJbHfbFXpCSVYdJRfiVdvHnewN0r5fUlPtR9stQHyuqewzdkyb5jTTw02D2cUfL57vlPStBj7SEi3uOWvLrsiDnnCIxRMYJ2UA2ktDKHk+zWnsDmaeleSzonv2CHW42yXYPCvWi88oE1DJNYLNkIjua7MxAnkNZbScNw01A6zbLsZ3y8G6eEYnxSTRfwjd8EP4kdiHNJftm7Z4iRU7HOVh79/lRWB+gd171s3d/mI9kte3MRy6V8MMEMCAnMboGpaooYwgAmwclI2XZCczNWXfhaWe0ZS5PmytD/GDpXzkX0oEgY9K/uYo5V77NdZbGAjmyi8cE2B2ogvyaN2XfIInrZPgEffJ4AB7kFA2mwesdLOCh0BLD9itmCve3A1FGR4+stO2ANUoiI3w3Tv2yQSg4bjeDlJ08lXaaFCLW2peEXMXjQUk7fmpb5MNuOUTW6BE=",
    "LlavePrivada" => "MIIFDjBABgkqhkiG9w0BBQ0wMzAbBgkqhkiG9w0BBQwwDgQIAgEAAoIBAQACAggAMBQGCCqGSIb3DQMHBAgwggS/AgEAMASCBMh4EHl7aNSCaMDA1VlRoXCZ5UUmqErAbucoZQObOaLUEm+I+QZ7Y8Giupo+F1XWkLvAsdk/uZlJcTfKLJyJbJwsQYbSpLOCLataZ4O5MVnnmMbfG//NKJn9kSMvJQZhSwAwoGLYDm1ESGezrvZabgFJnoQv8Si1nAhVGTk9FkFBesxRzq07dmZYwFCnFSX4xt2fDHs1PMpQbeq83aL/PzLCce3kxbYSB5kQlzGtUYayiYXcu0cVRu228VwBLCD+2wTDDoCmRXtPesgrLKUR4WWWb5N2AqAU1mNDC+UEYsENAerOFXWnmwrcTAu5qyZ7GsBMTpipW4Dbou2yqQ0lpA/aB06n1kz1aL6mNqGPaJ+OqoFuc8Ugdhadd+MmjHfFzoI20SZ3b2geCsUMNCsAd6oXMsZdWm8lzjqCGWHFeol0ik/xHMQvuQkkeCsQ28PBxdnUgf7ZGer+TN+2ZLd2kvTBOk6pIVgy5yC6cZ+o1Tloql9hYGa6rT3xcMbXlW+9e5jM2MWXZliVW3ZhaPjptJFDbIfWxJPjz4QvKyJk0zok4muv13Iiwj2bCyefUTRz6psqI4cGaYm9JpscKO2RCJN8UluYGbbWmYQU+Int6LtZj/lv8p6xnVjWxYI+rBPdtkpfFYRp+MJiXjgPw5B6UGuoruv7+vHjOLHOotRo+RdjZt7NqL9dAJnl1Qb2jfW6+d7NYQSI/bAwxO0sk4taQIT6Gsu/8kfZOPC2xk9rphGqCSS/4q3Os0MMjA1bcJLyoWLp13pqhK6bmiiHw0BBXH4fbEp4xjSbpPx4tHXzbdn8oDsHKZkWh3pPC2J/nVl0k/yF1KDVowVtMDXE47k6TGVcBoqe8PDXCG9+vjRpzIidqNo5qebaUZu6riWMWzldz8x3Z/jLWXuDiM7/Yscn0Z2GIlfoeyz+GwP2eTdOw9EUedHjEQuJY32bq8LICimJ4Ht+zMJKUyhwVQyAER8byzQBwTYmYP5U0wdsyIFitphw+/IH8+v08Ia1iBLPQAeAvRfTTIFLCs8foyUrj5Zv2B/wTYIZy6ioUM+qADeXyo45uBLLqkN90Rf6kiTqDld78NxwsfyR5MxtJLVDFkmf2IMMJHTqSfhbi+7QJaC11OOUJTD0v9wo0X/oO5GvZhe0ZaGHnm9zqTopALuFEAxcaQlc4R81wjC4wrIrqWnbcl2dxiBtD73KW+wcC9ymsLf4I8BEmiN25lx/OUc1IHNyXZJYSFkEfaxCEZWKcnbiyf5sqFSSlEqZLc4lUPJFAoP6s1FHVcyO0odWqdadhRZLZC9RCzQgPlMRtji/OXy5phh7diOBZv5UYp5nb+MZ2NAB/eFXm2JLguxjvEstuvTDmZDUb6Uqv++RdhO5gvKf/AcwU38ifaHQ9uvRuDocYwVxZS2nr9rOwZ8nAh+P2o4e0tEXjxFKQGhxXYkn75H3hhfnFYjik/2qunHBBZfcdG148MaNP6DjX33M238T9Zw/GyGx00JMogr2pdP4JAErv9a5yt4YR41KGf8guSOUbOXVARw6+ybh7+meb7w4BeTlj3aZkv8tVGdfIt3lrwVnlbzhLjeQY6PplKp3/a5Kr5yM0T4wJoKQQ6v3vSNmrhpbuAtKxpMILe8CQoo=",
    "CSDPassword" => "12345678a",
    * 
    "CSD" => $CSDBase64,
    "LlavePrivada" => $KeyBase64,
    "CSDPassword" => "",*/
    $data = [
        "DatosGenerales" => [
            "Version" => "4.0",
            "CSD" => "MIIFsDCCA5igAwIBAgIUMzAwMDEwMDAwMDA1MDAwMDM0MTYwDQYJKoZIhvcNAQELBQAwggErMQ8wDQYDVQQDDAZBQyBVQVQxLjAsBgNVBAoMJVNFUlZJQ0lPIERFIEFETUlOSVNUUkFDSU9OIFRSSUJVVEFSSUExGjAYBgNVBAsMEVNBVC1JRVMgQXV0aG9yaXR5MSgwJgYJKoZIhvcNAQkBFhlvc2Nhci5tYXJ0aW5lekBzYXQuZ29iLm14MR0wGwYDVQQJDBQzcmEgY2VycmFkYSBkZSBjYWxpejEOMAwGA1UEEQwFMDYzNzAxCzAJBgNVBAYTAk1YMRkwFwYDVQQIDBBDSVVEQUQgREUgTUVYSUNPMREwDwYDVQQHDAhDT1lPQUNBTjERMA8GA1UELRMIMi41LjQuNDUxJTAjBgkqhkiG9w0BCQITFnJlc3BvbnNhYmxlOiBBQ0RNQS1TQVQwHhcNMjMwNTE4MTE0MzUxWhcNMjcwNTE4MTE0MzUxWjCB1zEnMCUGA1UEAxMeRVNDVUVMQSBLRU1QRVIgVVJHQVRFIFNBIERFIENWMScwJQYDVQQpEx5FU0NVRUxBIEtFTVBFUiBVUkdBVEUgU0EgREUgQ1YxJzAlBgNVBAoTHkVTQ1VFTEEgS0VNUEVSIFVSR0FURSBTQSBERSBDVjElMCMGA1UELRMcRUtVOTAwMzE3M0M5IC8gVkFEQTgwMDkyN0RKMzEeMBwGA1UEBRMVIC8gVkFEQTgwMDkyN0hTUlNSTDA1MRMwEQYDVQQLEwpTdWN1cnNhbCAxMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAtmecO6n2GS0zL025gbHGQVxznPDICoXzR2uUngz4DqxVUC/w9cE6FxSiXm2ap8Gcjg7wmcZfm85EBaxCx/0J2u5CqnhzIoGCdhBPuhWQnIh5TLgj/X6uNquwZkKChbNe9aeFirU/JbyN7Egia9oKH9KZUsodiM/pWAH00PCtoKJ9OBcSHMq8Rqa3KKoBcfkg1ZrgueffwRLws9yOcRWLb02sDOPzGIm/jEFicVYt2Hw1qdRE5xmTZ7AGG0UHs+unkGjpCVeJ+BEBn0JPLWVvDKHZAQMj6s5Bku35+d/MyATkpOPsGT/VTnsouxekDfikJD1f7A1ZpJbqDpkJnss3vQIDAQABox0wGzAMBgNVHRMBAf8EAjAAMAsGA1UdDwQEAwIGwDANBgkqhkiG9w0BAQsFAAOCAgEAFaUgj5PqgvJigNMgtrdXZnbPfVBbukAbW4OGnUhNrA7SRAAfv2BSGk16PI0nBOr7qF2mItmBnjgEwk+DTv8Zr7w5qp7vleC6dIsZFNJoa6ZndrE/f7KO1CYruLXr5gwEkIyGfJ9NwyIagvHHMszzyHiSZIA850fWtbqtythpAliJ2jF35M5pNS+YTkRB+T6L/c6m00ymN3q9lT1rB03YywxrLreRSFZOSrbwWfg34EJbHfbFXpCSVYdJRfiVdvHnewN0r5fUlPtR9stQHyuqewzdkyb5jTTw02D2cUfL57vlPStBj7SEi3uOWvLrsiDnnCIxRMYJ2UA2ktDKHk+zWnsDmaeleSzonv2CHW42yXYPCvWi88oE1DJNYLNkIjua7MxAnkNZbScNw01A6zbLsZ3y8G6eEYnxSTRfwjd8EP4kdiHNJftm7Z4iRU7HOVh79/lRWB+gd171s3d/mI9kte3MRy6V8MMEMCAnMboGpaooYwgAmwclI2XZCczNWXfhaWe0ZS5PmytD/GDpXzkX0oEgY9K/uYo5V77NdZbGAjmyi8cE2B2ogvyaN2XfIInrZPgEffJ4AB7kFA2mwesdLOCh0BLD9itmCve3A1FGR4+stO2ANUoiI3w3Tv2yQSg4bjeDlJ08lXaaFCLW2peEXMXjQUk7fmpb5MNuOUTW6BE=",
            "LlavePrivada" => "MIIFDjBABgkqhkiG9w0BBQ0wMzAbBgkqhkiG9w0BBQwwDgQIAgEAAoIBAQACAggAMBQGCCqGSIb3DQMHBAgwggS/AgEAMASCBMh4EHl7aNSCaMDA1VlRoXCZ5UUmqErAbucoZQObOaLUEm+I+QZ7Y8Giupo+F1XWkLvAsdk/uZlJcTfKLJyJbJwsQYbSpLOCLataZ4O5MVnnmMbfG//NKJn9kSMvJQZhSwAwoGLYDm1ESGezrvZabgFJnoQv8Si1nAhVGTk9FkFBesxRzq07dmZYwFCnFSX4xt2fDHs1PMpQbeq83aL/PzLCce3kxbYSB5kQlzGtUYayiYXcu0cVRu228VwBLCD+2wTDDoCmRXtPesgrLKUR4WWWb5N2AqAU1mNDC+UEYsENAerOFXWnmwrcTAu5qyZ7GsBMTpipW4Dbou2yqQ0lpA/aB06n1kz1aL6mNqGPaJ+OqoFuc8Ugdhadd+MmjHfFzoI20SZ3b2geCsUMNCsAd6oXMsZdWm8lzjqCGWHFeol0ik/xHMQvuQkkeCsQ28PBxdnUgf7ZGer+TN+2ZLd2kvTBOk6pIVgy5yC6cZ+o1Tloql9hYGa6rT3xcMbXlW+9e5jM2MWXZliVW3ZhaPjptJFDbIfWxJPjz4QvKyJk0zok4muv13Iiwj2bCyefUTRz6psqI4cGaYm9JpscKO2RCJN8UluYGbbWmYQU+Int6LtZj/lv8p6xnVjWxYI+rBPdtkpfFYRp+MJiXjgPw5B6UGuoruv7+vHjOLHOotRo+RdjZt7NqL9dAJnl1Qb2jfW6+d7NYQSI/bAwxO0sk4taQIT6Gsu/8kfZOPC2xk9rphGqCSS/4q3Os0MMjA1bcJLyoWLp13pqhK6bmiiHw0BBXH4fbEp4xjSbpPx4tHXzbdn8oDsHKZkWh3pPC2J/nVl0k/yF1KDVowVtMDXE47k6TGVcBoqe8PDXCG9+vjRpzIidqNo5qebaUZu6riWMWzldz8x3Z/jLWXuDiM7/Yscn0Z2GIlfoeyz+GwP2eTdOw9EUedHjEQuJY32bq8LICimJ4Ht+zMJKUyhwVQyAER8byzQBwTYmYP5U0wdsyIFitphw+/IH8+v08Ia1iBLPQAeAvRfTTIFLCs8foyUrj5Zv2B/wTYIZy6ioUM+qADeXyo45uBLLqkN90Rf6kiTqDld78NxwsfyR5MxtJLVDFkmf2IMMJHTqSfhbi+7QJaC11OOUJTD0v9wo0X/oO5GvZhe0ZaGHnm9zqTopALuFEAxcaQlc4R81wjC4wrIrqWnbcl2dxiBtD73KW+wcC9ymsLf4I8BEmiN25lx/OUc1IHNyXZJYSFkEfaxCEZWKcnbiyf5sqFSSlEqZLc4lUPJFAoP6s1FHVcyO0odWqdadhRZLZC9RCzQgPlMRtji/OXy5phh7diOBZv5UYp5nb+MZ2NAB/eFXm2JLguxjvEstuvTDmZDUb6Uqv++RdhO5gvKf/AcwU38ifaHQ9uvRuDocYwVxZS2nr9rOwZ8nAh+P2o4e0tEXjxFKQGhxXYkn75H3hhfnFYjik/2qunHBBZfcdG148MaNP6DjX33M238T9Zw/GyGx00JMogr2pdP4JAErv9a5yt4YR41KGf8guSOUbOXVARw6+ybh7+meb7w4BeTlj3aZkv8tVGdfIt3lrwVnlbzhLjeQY6PplKp3/a5Kr5yM0T4wJoKQQ6v3vSNmrhpbuAtKxpMILe8CQoo=",
            "CSDPassword" => "12345678a",
            "GeneraPDF" => false,
            "CFDI" => "Factura",
            "OpcionDecimales" => 2,
            "NumeroDecimales" => 2,
            "TipoCFDI" => "Ingreso",
            "EnviaEmail" => false,
            "ReceptorEmail" => "sergioedurdo21@gmail.com",
            "EmailMensaje" => "Factura de Smart Building Solutions",
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
            "RFC" => $cliente['rfc'],
            "NombreRazonSocial" => $cliente['nombre'],
            "UsoCFDI" => $cliente['cfdi'],
            "RegimenFiscal" => $cliente['regimen'],
            "Direccion" => [            
                "Calle" => $cliente['calle'],
                "NumeroExterior" => $cliente['numExt'],
                "NumeroInterior" => $cliente['numInt'],
                "Colonia" => $cliente['colonia'],
                "Localidad" => $cliente['ciudad'],
                "Municipio" => $cliente['municipio'],
                "Estado" => $cliente['estado'],
                "Pais" => $cliente['pais'],
                "CodigoPostal" => $cliente['cp']
            ]
            ],
            "Fecha" => $fechaExpedicion,
            "Serie" => "AB",
            "MetodoPago" => $cliente['metodoP'],
            "FormaPago" => $cliente['formaP'],
            "Moneda" => "MXN",
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
    //var_dump($data);
    //print_r($data);
    $responseFactura = $client->request('POST', 'https://testapi.facturoporti.com.mx/servicios/timbrar/json', [
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

            if($cliente['metodoP'] == 'PPD') {
                $saldoInsoluto = $totalAjustado;
            } else {
                $saldoInsoluto = '0.00';
            }

            $status = '1'; // Status Activa
            $sqlBase64 = "INSERT INTO `facturas`(`status`, `total`, `cliente`, `base64`, `rutaXml`, `servicios`, `fecha`, `uuid`, `moneda`, `tipoCfdi`, `metodoPago`, `formaPago`, `lugarExpedicion`, `subTotal`, `serie`, `folio`, `cfdi`, `saldoInsoluto`) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            $stmt = $con->prepare($sqlBase64);
            if ($stmt === false) {
                throw new Exception("Error en la preparación de la consulta: " . $con->error);
            }

            $prodsJson = json_encode($productos);
            $fechaActual = date('Y-m-d H:i:s');

            // Vincular parámetros y ejecutar
            $stmt->bind_param("isssssssssssssssss", $status, $totalAjustado, $cliente['nombre'], $pdfBase64, $nombre, $prodsJson, $fechaActual, $uuid, $moneda, $tipoCFDI, $cliente['metodoP'], $cliente['formaP'], $cpEmisor, $subTotal, $serie, $folio, $sello, $saldoInsoluto);
            if (!$stmt->execute()) {
                throw new Exception("Error en la ejecución de la consulta: " . $stmt->error);
            }

            // Obtener el ID de la última inserción
            $idFactura = $con->insert_id;

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
        'message' => 'Error en la solicitud de la factura: ' . $e->getMessage()
    ]);
    logToFile($username, $userID, 'Error en la solicitud de la factura: '.$e->getMessage(), "error", $e->getMessage());
    exit;
}

?>