<?php

require_once dirname(__FILE__) . '/CurrencyConfig.php';
require_once dirname(__FILE__) . '/CurrencyClient.php';

class CurrencyService {
    private $client;

    public function __construct() {
        $this->client = new CurrencyClient();
    }

    /**
     * Convierte un monto de una moneda a otra.
     * 
     * @param float $amount El monto que se desea convertir.
     * @param string $from El código de la moneda origen (ej. 'USD').
     * @param string|null $to El código de la moneda destino. Si es null, usa el default configurado (MXN).
     * @return float El monto convertido.
     * @throws Exception Si ocurre un problema al obtener el tipo de cambio.
     */
    public function convertAmount($amount, $from, $to = null) {
        if ($to === null) {
            $to = CurrencyConfig::$defaultTargetCurrency;
        }

        if (strtoupper($from) === strtoupper($to)) {
            return (float)$amount;
        }

        $rate = $this->client->fetchLatestRate($from, $to);
        return (float)$amount * $rate;
    }

    /**
     * Obtiene únicamente el tipo de cambio actual.
     *
     * @param string $from Moneda de origen.
     * @param string|null $to Moneda destino. Si es null, usa el default configurado.
     * @return float Tipo de cambio.
     */
    public function getExchangeRate($from, $to = null) {
        if ($to === null) {
            $to = CurrencyConfig::$defaultTargetCurrency;
        }

        if (strtoupper($from) === strtoupper($to)) {
            return 1.0;
        }

        return $this->client->fetchLatestRate($from, $to);
    }
}
