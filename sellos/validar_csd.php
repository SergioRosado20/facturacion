<?php
$rutaRoberto = "Roberto Finezza/v2/";
$cerFileR = $rutaRoberto . "00001000000716779229.cer"; // ruta al .cer
$keyFileR = $rutaRoberto . "CSD_UNIDADA_HUCJ750513DW7_20250624_184149.key"; // ruta al .key
$passR = "Robe7505";

$rutaVeronica = "Veronica Finezza/";
$cerFileV = $rutaVeronica . "00001000000513208688.cer"; // ruta al .cer
$keyFileV = $rutaVeronica . "CSD_Unidad_IITV760127956_20220530_130359.key"; // ruta al .key
$passV = "Promociones27";

$cerFile = $cerFileR; // ruta al .cer
$keyFile = $keyFileR; // ruta al .key
$password = $passR; // contraseña del .key

if(!file_exists($cerFile) || !file_exists($keyFile)) {
    echo "❌ No se encontró el certificado o el .key\n";
    echo "Cer: " . $cerFile . "\n";
    echo "Key: " . $keyFile . "\n";
    exit;
}

// Leer certificado
$cerContent = file_get_contents($cerFile);
//print_r($cerContent);
$certData = openssl_x509_parse($cerContent);
//print_r($certData);

if ($cerContent === false) {
    echo "❌ No se pudo leer el certificado\n";
    exit;
}

// Convertir DER → PEM
$cerContent = file_get_contents($cerFile);
$pemCer = "-----BEGIN CERTIFICATE-----\n"
        . chunk_split(base64_encode($cerContent), 64, "\n")
        . "-----END CERTIFICATE-----\n";

$certData = openssl_x509_parse($pemCer);
if ($certData === false) {
    die("❌ No se pudo parsear el certificado\n");
}
echo "✅ Certificado leído\n";
echo "Serie: " . $certData['serialNumberHex'] . "\n";
echo "Válido desde: " . date('Y-m-d H:i:s', $certData['validFrom_time_t']) . "\n";
echo "Válido hasta: " . date('Y-m-d H:i:s', $certData['validTo_time_t']) . "\n";

// === LEER KEY ===
$keyDer = file_get_contents($keyFile);
$pemKey = "-----BEGIN ENCRYPTED PRIVATE KEY-----\n"
        . chunk_split(base64_encode($keyDer), 64, "\n")
        . "-----END ENCRYPTED PRIVATE KEY-----\n";

$pkeyid = openssl_pkey_get_private($pemKey, $password);
if ($pkeyid === false) {
    die("❌ Contraseña incorrecta o .key corrupto\n");
}
echo "✅ .key descifrado correctamente\n";

// === VALIDAR QUE CORRESPONDAN ===
$pubKey = openssl_pkey_get_public($pemCer);
$keyDetails = openssl_pkey_get_details($pkeyid);
$pubDetails = openssl_pkey_get_details($pubKey);

if ($keyDetails && $pubDetails && $keyDetails['rsa']['n'] === $pubDetails['rsa']['n']) {
    echo "✅ El .cer y el .key corresponden\n";
} else {
    echo "❌ El .cer y el .key NO corresponden\n";
}
?>