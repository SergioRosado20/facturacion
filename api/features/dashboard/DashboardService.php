<?php

require_once 'DashboardRepository.php';
require_once dirname(__FILE__) . '/../../services/shared/currency/CurrencyService.php';

class DashboardService {
    private $repository;
    private $currencyService;
    private $exchangeRatesCache = [];

    public function __construct($con) {
        $this->repository = new DashboardRepository($con);
        $this->currencyService = new CurrencyService();
    }

    private function getRate($currency) {
        $currency = strtoupper(trim($currency));
        if (empty($currency)) {
            $currency = 'MXN';
        }
        if (!isset($this->exchangeRatesCache[$currency])) {
            $this->exchangeRatesCache[$currency] = $this->currencyService->getExchangeRate($currency, 'MXN');
        }
        return $this->exchangeRatesCache[$currency];
    }

    public function getDashboardData($filters) {
        $whereClause = $this->repository->buildWhereClause($filters);
        $whereClauseNotas = $this->repository->buildWhereClauseNotas($filters);

        $egresos = $this->getEgresos($whereClauseNotas);

        return [
            'totales'                   => $this->getTotals($whereClause, $filters),
            'analiticas_ingresos'       => $this->getIngresosReporting($whereClause, $filters, $egresos),
            'egresos'                   => $egresos,
            'analiticas_pagos_pieChart' => $this->getPaymentMethodsChart($whereClause),
            'kpis_accounts_barChart'    => $this->getKPIsAccountsChart($whereClause),
            'kpis_clients_barChart'     => $this->getKPIsClientsChart($whereClause)
        ];
    }

    private function getTotals($whereClause, $filters) {
        $whereClauseF = $this->repository->buildWhereClause($filters, 'f');
        $baseData = $this->repository->getTotalsByCurrency($whereClause);
        $ppdData = $this->repository->getPaidPPDByCurrency($whereClauseF);

        $result = [
            'total_facturas' => 0,
            'monto_total' => 0.0,
            'facturas_pagadas' => 0,
            'monto_pagado' => 0.0,
            'facturas_pendientes' => 0,
            'monto_pendiente' => 0.0
        ];

        foreach ($baseData as $moneda => $data) {
            $rate = $this->getRate($moneda);
            
            $result['total_facturas'] += (int)($data['total_facturas'] ?? 0);
            $result['monto_total'] += ((float)($data['monto_total'] ?? 0)) * $rate;
            $result['facturas_pagadas'] += (int)($data['facturas_pagadas'] ?? 0);
            $result['facturas_pendientes'] += (int)($data['facturas_pendientes'] ?? 0);
            $result['monto_pendiente'] += ((float)($data['monto_pendiente'] ?? 0)) * $rate;
            
            $pagadoPUE = ((float)($data['monto_pagado_pue'] ?? 0)) * $rate;
            $result['monto_pagado'] += $pagadoPUE;
        }

        foreach ($ppdData as $moneda => $data) {
            $rate = $this->getRate($moneda);
            $pagadoPPD = ((float)($data['sumatoria_pagos_ppd'] ?? 0)) * $rate;
            $result['monto_pagado'] += $pagadoPPD;
        }

        return $result;
    }

    private function getIngresosReporting($whereClause, $filters, array $egresos) {
        $whereClauseF = $this->repository->buildWhereClause($filters, 'f');
        $baseData = $this->repository->getTotalsByCurrency($whereClause);
        $ppdData  = $this->repository->getPaidPPDByCurrency($whereClauseF);

        $totalPUE     = 0.0;
        $totalPPD     = 0.0;
        $cantidadPagos = 0;

        foreach ($baseData as $moneda => $data) {
            $rate = $this->getRate($moneda);
            $totalPUE += ((float)($data['monto_pagado_pue'] ?? 0)) * $rate;
        }

        foreach ($ppdData as $moneda => $data) {
            $rate = $this->getRate($moneda);
            $totalPPD     += ((float)($data['sumatoria_pagos_ppd'] ?? 0)) * $rate;
            $cantidadPagos += (int)($data['cantidad_pagos'] ?? 0);
        }

        $totalIngresos = $totalPUE + $totalPPD;

        return [
            'sumatoria_pagos'  => $totalIngresos,
            'sumatoria_pue'    => $totalPUE,
            'sumatoria_ppd'    => $totalPPD,
            'cantidad_pagos'   => $cantidadPagos,
            'ingresos_netos'   => $totalIngresos - $egresos['monto_egresos_mxn']
        ];
    }

    /**
     * Calcula el total de egresos (notas de crédito) en MXN,
     * convirtiendo cada grupo de moneda antes de sumar.
     * Retorna un array con el monto total en MXN y la cantidad de notas.
     */
    private function getEgresos($whereClauseNotas) {
        $egresosData    = $this->repository->getEgresosByCurrency($whereClauseNotas);
        $totalEgresos   = 0.0;
        $cantidadNotas  = 0;

        foreach ($egresosData as $moneda => $data) {
            $rate          = $this->getRate($moneda);
            $totalEgresos  += ((float)($data['monto_egresos'] ?? 0)) * $rate;
            $cantidadNotas += (int)($data['cantidad_notas'] ?? 0);
        }

        return [
            'monto_egresos_mxn' => round($totalEgresos, 2),
            'cantidad_notas'    => $cantidadNotas
        ];
    }

    private function getPaymentMethodsChart($whereClause) {
        $data = $this->repository->getPaymentMethodsAnalyticsByCurrency($whereClause);
        $aggregated = [];
        
        foreach ($data as $row) {
            $name = empty($row['metodoPago']) ? 'Desconocido' : $row['metodoPago'];
            if (!isset($aggregated[$name])) {
                $aggregated[$name] = [
                    'name' => $name,
                    'value' => 0,
                    'monto' => 0.0
                ];
            }
            $rate = $this->getRate($row['moneda']);
            $aggregated[$name]['value'] += (int)$row['cantidad'];
            $aggregated[$name]['monto'] += ((float)$row['monto']) * $rate;
        }
        
        return array_values($aggregated);
    }

    private function getKPIsAccountsChart($whereClause) {
        $data = $this->repository->getKPIsByCreatedByWithCurrency($whereClause);

        // First pass: collect all distinct currencies to pre-fetch rates into cache
        $currencies = array_unique(array_map(fn($r) => empty($r['moneda']) ? 'MXN' : strtoupper(trim($r['moneda'])), $data));
        foreach ($currencies as $currency) {
            $this->getRate($currency); // populates the cache
        }

        $aggregated = [];
        foreach ($data as $row) {
            $accountName = empty($row['account_name']) ? 'Desconocido' : $row['account_name'];
            if (!isset($aggregated[$accountName])) {
                $aggregated[$accountName] = ['pagadas'=>0, 'pendientes'=>0, 'monto_faltante'=>0.0];
            }
            $rate = $this->getRate($row['moneda']);
            $aggregated[$accountName]['pagadas']        += (int)$row['pagadas'];
            $aggregated[$accountName]['pendientes']     += (int)$row['pendientes'];
            $aggregated[$accountName]['monto_faltante'] += ((float)($row['monto_faltante'] ?? 0)) * $rate;
        }

        $xAxisData = [];
        $seriesPagadas = [];
        $seriesPendientes = [];
        $seriesMontoFaltante = [];

        foreach ($aggregated as $name => $vals) {
            $xAxisData[]           = $name;
            $seriesPagadas[]       = $vals['pagadas'];
            $seriesPendientes[]    = $vals['pendientes'];
            $seriesMontoFaltante[] = round($vals['monto_faltante'], 2);
        }

        return [
            'xAxis' => $xAxisData,
            'series' => [
                ['name' => 'Pagadas',    'type' => 'bar', 'data' => $seriesPagadas],
                ['name' => 'Pendientes', 'type' => 'bar', 'data' => $seriesPendientes]
            ],
            'faltante_series' => [
                'name' => 'Monto Faltante (MXN)',
                'type' => 'line',
                'data' => $seriesMontoFaltante
            ]
        ];
    }


    private function getKPIsClientsChart($whereClause) {
        $data = $this->repository->getKPIsByClientWithCurrency($whereClause);

        // First pass: collect all distinct currencies to pre-fetch rates
        $currencies = array_unique(array_map(fn($r) => empty($r['moneda']) ? 'MXN' : strtoupper(trim($r['moneda'])), $data));
        foreach ($currencies as $currency) {
            $this->getRate($currency); // populates the cache
        }

        $aggregated = [];
        foreach ($data as $row) {
            // Use the resolved customer name directly (already joined via customer table)
            $clientName = empty($row['cliente']) ? 'Desconocido' : $row['cliente'];
            if (!isset($aggregated[$clientName])) {
                $aggregated[$clientName] = ['total_facturas'=>0, 'monto_total'=>0.0, 'monto_faltante'=>0.0];
            }
            $rate = $this->getRate($row['moneda']);
            $aggregated[$clientName]['total_facturas'] += (int)$row['total_facturas'];
            $aggregated[$clientName]['monto_total']    += ((float)($row['monto_total']    ?? 0)) * $rate;
            $aggregated[$clientName]['monto_faltante'] += ((float)($row['monto_faltante'] ?? 0)) * $rate;
        }

        $xAxisData = [];
        $seriesTotal = [];
        $seriesMontoTotal = [];
        $seriesMontoFaltante = [];

        foreach ($aggregated as $name => $vals) {
            $xAxisData[]          = $name;
            $seriesTotal[]        = $vals['total_facturas'];
            $seriesMontoTotal[]   = round($vals['monto_total'], 2);
            $seriesMontoFaltante[] = round($vals['monto_faltante'], 2);
        }

        return [
            'xAxis' => $xAxisData,
            'series' => [
                ['name' => 'Total Facturas',   'type' => 'bar', 'data' => $seriesTotal],
                ['name' => 'Monto Total (MXN)','type' => 'bar', 'yAxisIndex' => 1, 'data' => $seriesMontoTotal]
            ],
            'faltante_series' => [
                'name' => 'Monto Faltante (MXN)',
                'type' => 'bar',
                'data' => $seriesMontoFaltante
            ]
        ];
    }
}
?>
