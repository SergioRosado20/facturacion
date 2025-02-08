<?php
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

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$database_host = $_ENV['DATABASE_HOST'] ?? '';
$database_user = $_ENV['DATABASE_USER'] ?? '';
$database_password = $_ENV['DATABASE_PASSWORD'] ?? '';
$database_name = $_ENV['DATABASE_NAME'] ?? '';

$con = new mysqli($database_host, $database_user, $database_password, $database_name);

$data = json_decode(file_get_contents('php://input'), true);
$data_string = json_encode($data);

$factura_id = isset($data['factura_id']) ? $data['factura_id'] : null;
$motivo = isset($data['motivo']) ? $data['motivo'] : null;
$uuid_sustitucion = isset($data['uuid']) ? $data['uuid'] : null;
$token = 'eyJhbGciOiJodHRwOi8vd3d3LnczLm9yZy8yMDAxLzA0L3htbGRzaWctbW9yZSNobWFjLXNoYTI1NiIsInR5cCI6IkpXVCJ9.eyJodHRwOi8vc2NoZW1hcy54bWxzb2FwLm9yZy93cy8yMDA1LzA1L2lkZW50aXR5L2NsYWltcy9uYW1lIjoialYrdVVUYmtWNmUxRmNZb2cvNWtGQT09IiwibmJmIjoxNzM2NDQ2ODk4LCJleHAiOjE3MzkwMzg4OTgsImlzcyI6IlNjYWZhbmRyYVNlcnZpY2lvcyIsImF1ZCI6IlNjYWZhbmRyYSBTZXJ2aWNpb3MiLCJJZEVtcHJlc2EiOiJqVit1VVRia1Y2ZTFGY1lvZy81a0ZBPT0iLCJJZFVzdWFyaW8iOiJidXlaYzFMWUl5VURaSGhGR3NqaGdRPT0ifQ.-utuEHV6nmQZaI71YrdxeIz5OtTkFbjxMJB-6al8JwQ';
//print_r($factura_id);

if($factura_id) {
    try {
        if (empty($token)) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Ha ocurrido un error inesperado.',
                'desc' => $e->getMessage(),
            ]);
            exit;
        }

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
        $sello = $data['Complemento']['SelloCFD'];

        // Leer y codificar los archivos
        /*try {
            $CSD = "credenciales/certificado.cer";
            $Key = "credenciales/key.key";

            // Verificar si los archivos existen
            if (!file_exists($CSD)) {
                die("Error: No se encontró el archivo CSD en la ruta especificada: $CSD");
            }
            if (!file_exists($Key)) {
                die("Error: No se encontró el archivo Key en la ruta especificada: $Key");
            }
            $CSDBinario = file_get_contents($CSD);
            $CSDBase64 = base64_encode($CSDBinario);

            $KeyBinario = file_get_contents($Key);
            $KeyBase64 = base64_encode($KeyBinario);
        } catch (Exception $e) {
            die("Error al leer los archivos: " . $e->getMessage());
        }*/

        $body = [
            "rfcEmisor" => $rfcEmisor,
            "rfcReceptor" => $rfcReceptor,
            "uuid" => $uuid,
            "total" => $total,
            "motivo" => $motivo,
            "folioFiscalSustitucion" => $uuid_sustitucion,
            "sello" => $sello,
            "certificado" => "MIIFsDCCA5igAwIBAgIUMzAwMDEwMDAwMDA1MDAwMDM0MTYwDQYJKoZIhvcNAQELBQAwggErMQ8wDQYDVQQDDAZBQyBVQVQxLjAsBgNVBAoMJVNFUlZJQ0lPIERFIEFETUlOSVNUUkFDSU9OIFRSSUJVVEFSSUExGjAYBgNVBAsMEVNBVC1JRVMgQXV0aG9yaXR5MSgwJgYJKoZIhvcNAQkBFhlvc2Nhci5tYXJ0aW5lekBzYXQuZ29iLm14MR0wGwYDVQQJDBQzcmEgY2VycmFkYSBkZSBjYWxpejEOMAwGA1UEEQwFMDYzNzAxCzAJBgNVBAYTAk1YMRkwFwYDVQQIDBBDSVVEQUQgREUgTUVYSUNPMREwDwYDVQQHDAhDT1lPQUNBTjERMA8GA1UELRMIMi41LjQuNDUxJTAjBgkqhkiG9w0BCQITFnJlc3BvbnNhYmxlOiBBQ0RNQS1TQVQwHhcNMjMwNTE4MTE0MzUxWhcNMjcwNTE4MTE0MzUxWjCB1zEnMCUGA1UEAxMeRVNDVUVMQSBLRU1QRVIgVVJHQVRFIFNBIERFIENWMScwJQYDVQQpEx5FU0NVRUxBIEtFTVBFUiBVUkdBVEUgU0EgREUgQ1YxJzAlBgNVBAoTHkVTQ1VFTEEgS0VNUEVSIFVSR0FURSBTQSBERSBDVjElMCMGA1UELRMcRUtVOTAwMzE3M0M5IC8gVkFEQTgwMDkyN0RKMzEeMBwGA1UEBRMVIC8gVkFEQTgwMDkyN0hTUlNSTDA1MRMwEQYDVQQLEwpTdWN1cnNhbCAxMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAtmecO6n2GS0zL025gbHGQVxznPDICoXzR2uUngz4DqxVUC/w9cE6FxSiXm2ap8Gcjg7wmcZfm85EBaxCx/0J2u5CqnhzIoGCdhBPuhWQnIh5TLgj/X6uNquwZkKChbNe9aeFirU/JbyN7Egia9oKH9KZUsodiM/pWAH00PCtoKJ9OBcSHMq8Rqa3KKoBcfkg1ZrgueffwRLws9yOcRWLb02sDOPzGIm/jEFicVYt2Hw1qdRE5xmTZ7AGG0UHs+unkGjpCVeJ+BEBn0JPLWVvDKHZAQMj6s5Bku35+d/MyATkpOPsGT/VTnsouxekDfikJD1f7A1ZpJbqDpkJnss3vQIDAQABox0wGzAMBgNVHRMBAf8EAjAAMAsGA1UdDwQEAwIGwDANBgkqhkiG9w0BAQsFAAOCAgEAFaUgj5PqgvJigNMgtrdXZnbPfVBbukAbW4OGnUhNrA7SRAAfv2BSGk16PI0nBOr7qF2mItmBnjgEwk+DTv8Zr7w5qp7vleC6dIsZFNJoa6ZndrE/f7KO1CYruLXr5gwEkIyGfJ9NwyIagvHHMszzyHiSZIA850fWtbqtythpAliJ2jF35M5pNS+YTkRB+T6L/c6m00ymN3q9lT1rB03YywxrLreRSFZOSrbwWfg34EJbHfbFXpCSVYdJRfiVdvHnewN0r5fUlPtR9stQHyuqewzdkyb5jTTw02D2cUfL57vlPStBj7SEi3uOWvLrsiDnnCIxRMYJ2UA2ktDKHk+zWnsDmaeleSzonv2CHW42yXYPCvWi88oE1DJNYLNkIjua7MxAnkNZbScNw01A6zbLsZ3y8G6eEYnxSTRfwjd8EP4kdiHNJftm7Z4iRU7HOVh79/lRWB+gd171s3d/mI9kte3MRy6V8MMEMCAnMboGpaooYwgAmwclI2XZCczNWXfhaWe0ZS5PmytD/GDpXzkX0oEgY9K/uYo5V77NdZbGAjmyi8cE2B2ogvyaN2XfIInrZPgEffJ4AB7kFA2mwesdLOCh0BLD9itmCve3A1FGR4+stO2ANUoiI3w3Tv2yQSg4bjeDlJ08lXaaFCLW2peEXMXjQUk7fmpb5MNuOUTW6BE=",
            "llavePrivada" => "MIIFDjBABgkqhkiG9w0BBQ0wMzAbBgkqhkiG9w0BBQwwDgQIAgEAAoIBAQACAggAMBQGCCqGSIb3DQMHBAgwggS/AgEAMASCBMh4EHl7aNSCaMDA1VlRoXCZ5UUmqErAbucoZQObOaLUEm+I+QZ7Y8Giupo+F1XWkLvAsdk/uZlJcTfKLJyJbJwsQYbSpLOCLataZ4O5MVnnmMbfG//NKJn9kSMvJQZhSwAwoGLYDm1ESGezrvZabgFJnoQv8Si1nAhVGTk9FkFBesxRzq07dmZYwFCnFSX4xt2fDHs1PMpQbeq83aL/PzLCce3kxbYSB5kQlzGtUYayiYXcu0cVRu228VwBLCD+2wTDDoCmRXtPesgrLKUR4WWWb5N2AqAU1mNDC+UEYsENAerOFXWnmwrcTAu5qyZ7GsBMTpipW4Dbou2yqQ0lpA/aB06n1kz1aL6mNqGPaJ+OqoFuc8Ugdhadd+MmjHfFzoI20SZ3b2geCsUMNCsAd6oXMsZdWm8lzjqCGWHFeol0ik/xHMQvuQkkeCsQ28PBxdnUgf7ZGer+TN+2ZLd2kvTBOk6pIVgy5yC6cZ+o1Tloql9hYGa6rT3xcMbXlW+9e5jM2MWXZliVW3ZhaPjptJFDbIfWxJPjz4QvKyJk0zok4muv13Iiwj2bCyefUTRz6psqI4cGaYm9JpscKO2RCJN8UluYGbbWmYQU+Int6LtZj/lv8p6xnVjWxYI+rBPdtkpfFYRp+MJiXjgPw5B6UGuoruv7+vHjOLHOotRo+RdjZt7NqL9dAJnl1Qb2jfW6+d7NYQSI/bAwxO0sk4taQIT6Gsu/8kfZOPC2xk9rphGqCSS/4q3Os0MMjA1bcJLyoWLp13pqhK6bmiiHw0BBXH4fbEp4xjSbpPx4tHXzbdn8oDsHKZkWh3pPC2J/nVl0k/yF1KDVowVtMDXE47k6TGVcBoqe8PDXCG9+vjRpzIidqNo5qebaUZu6riWMWzldz8x3Z/jLWXuDiM7/Yscn0Z2GIlfoeyz+GwP2eTdOw9EUedHjEQuJY32bq8LICimJ4Ht+zMJKUyhwVQyAER8byzQBwTYmYP5U0wdsyIFitphw+/IH8+v08Ia1iBLPQAeAvRfTTIFLCs8foyUrj5Zv2B/wTYIZy6ioUM+qADeXyo45uBLLqkN90Rf6kiTqDld78NxwsfyR5MxtJLVDFkmf2IMMJHTqSfhbi+7QJaC11OOUJTD0v9wo0X/oO5GvZhe0ZaGHnm9zqTopALuFEAxcaQlc4R81wjC4wrIrqWnbcl2dxiBtD73KW+wcC9ymsLf4I8BEmiN25lx/OUc1IHNyXZJYSFkEfaxCEZWKcnbiyf5sqFSSlEqZLc4lUPJFAoP6s1FHVcyO0odWqdadhRZLZC9RCzQgPlMRtji/OXy5phh7diOBZv5UYp5nb+MZ2NAB/eFXm2JLguxjvEstuvTDmZDUb6Uqv++RdhO5gvKf/AcwU38ifaHQ9uvRuDocYwVxZS2nr9rOwZ8nAh+P2o4e0tEXjxFKQGhxXYkn75H3hhfnFYjik/2qunHBBZfcdG148MaNP6DjX33M238T9Zw/GyGx00JMogr2pdP4JAErv9a5yt4YR41KGf8guSOUbOXVARw6+ybh7+meb7w4BeTlj3aZkv8tVGdfIt3lrwVnlbzhLjeQY6PplKp3/a5Kr5yM0T4wJoKQQ6v3vSNmrhpbuAtKxpMILe8CQoo=",
            "password" => "12345678a"
        ];

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
                        // Realizar un INSERT
                        $sqlInsert = "INSERT INTO `cancelaciones`(`folio`, `uuid`, `uuid_sustitucion`, `mensaje`, `acuse`, `codigoEstatus`, `esCancelable`, `estado`, `estatusCancelacion`, `fecha_creacion`, `fecha_actu`) 
                                        VALUES (?,?,?,?,?,?,?,?,?,?,?)";
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