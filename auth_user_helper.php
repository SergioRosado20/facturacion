<?php

function getRequestHeadersSafe(): array {
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            return $headers;
        }
    }

    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $name = str_replace('_', '-', strtolower(substr($key, 5)));
            $name = implode('-', array_map('ucfirst', explode('-', $name)));
            $headers[$name] = $value;
        }
    }
    return $headers;
}

function decodeJwtPayloadWithoutValidation(string $jwt): ?array {
    $parts = explode('.', $jwt);
    if (count($parts) < 2) {
        return null;
    }

    $payload = strtr($parts[1], '-_', '+/');
    $padding = strlen($payload) % 4;
    if ($padding > 0) {
        $payload .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode($payload, true);
    if ($decoded === false) {
        return null;
    }

    $json = json_decode($decoded, true);
    return is_array($json) ? $json : null;
}

function getAuthenticatedUserData(): array {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $headers = getRequestHeadersSafe();
    $authorization = '';
    foreach ($headers as $key => $value) {
        if (strtolower($key) === 'authorization') {
            $authorization = trim((string)$value);
            break;
        }
    }

    $username = $_SESSION['username'] ?? 'Sistema';
    $userID = (string)($_SESSION['userID'] ?? '0');

    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        $payload = decodeJwtPayloadWithoutValidation($matches[1]);
        if (is_array($payload)) {
            $jwtUserId = $payload['id'] ?? $payload['sub'] ?? $payload['userId'] ?? null;
            $jwtUsername = $payload['email'] ?? $payload['name'] ?? $payload['username'] ?? null;

            if ($jwtUserId !== null && $jwtUserId !== '') {
                $userID = (string)$jwtUserId;
            }
            if ($jwtUsername !== null && $jwtUsername !== '') {
                $username = (string)$jwtUsername;
            }
        }
    }

    return [
        'username' => $username,
        'userID' => $userID,
    ];
}

?>
