<?php

require_once dirname(__FILE__) . '/CurrencyConfig.php';

class CurrencyClient {
    /**
     * Recupera el factor de conversión más reciente usando la API de Frankfurter.
     * 
     * @param string $from Código de moneda origen (ej. 'USD', 'EUR')
     * @param string $to Código de moneda destino (ej. 'MXN')
     * @return float|null La tasa de cambio o lanza excepción si falla.
     * @throws Exception
     */
    public function fetchLatestRate($from, $to) {
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));

        $url = CurrencyConfig::$baseUrl . "/latest?from={$from}&to={$to}";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // Timeout para evitar que se bloquee el request si la API no responde
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        
        curl_close($ch);

        if ($response === false) {
            throw new Exception("Error al conectar con la API de Frankfurter: $curlError");
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            $data = json_decode($response, true);
            if (isset($data['rates'][$to])) {
                return (float)$data['rates'][$to];
            } else {
                 throw new Exception("La API no devolvió una tasa para la moneda destino: $to");
            }
        }
        
        throw new Exception("Falló la petición a la API de Frankfurter. HTTP Code: $httpCode, Respuesta: $response");
    }
}
