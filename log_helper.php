<?php
    function logToFile($userName, $userId, $query, $queryResult, $data = '') {
        $logFile = 'log.txt'; // Nombre del archivo de log
        // Configurar la zona horaria a Ciudad de México
        date_default_timezone_set('America/Mexico_City');
        $timestamp = date("Y-m-d H:i:s"); // Fecha y hora actual

        // Crear el mensaje de log
        $logMessage = "Fecha: $timestamp" . PHP_EOL;
        $logMessage .= "User name: $userName" . PHP_EOL;
        $logMessage .= "User Id: $userId" . PHP_EOL;
        $logMessage .= "Query: $query" . PHP_EOL;
        $logMessage .= "Query result: $queryResult" . PHP_EOL;
        $logMessage .= "Datos recibidos: $data" . PHP_EOL;
        $logMessage .= "-------------------------" . PHP_EOL;

        // Abrir el archivo en modo de añadir (a)
        $file = fopen($logFile, 'a');

        // Escribir el mensaje en el archivo
        fwrite($file, $logMessage);

        // Cerrar el archivo
        fclose($file);
    }
?>