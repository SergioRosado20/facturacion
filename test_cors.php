<?php
require_once "cors.php";
cors();

// Log para debug
error_log("Test CORS ejecutándose - " . date('Y-m-d H:i:s'));

header('Content-Type: application/json');

echo json_encode([
    'status' => 'success',
    'message' => 'CORS funcionando correctamente',
    'timestamp' => date('Y-m-d H:i:s'),
    'method' => $_SERVER['REQUEST_METHOD'],
    'origin' => $_SERVER['HTTP_ORIGIN'] ?? 'No origin'
]);
?> 