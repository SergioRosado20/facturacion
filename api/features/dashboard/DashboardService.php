<?php

require_once 'DashboardRepository.php';
require_once dirname(__FILE__) . '/../../services/shared/currency/CurrencyService.php';

/**
 * DashboardService
 *
 * FUENTE DE VERDAD ÚNICA: tabla `facturas` vía getTotalsByCurrency.
 *
 * El campo `pagado` NO se usa en ningún cálculo.
 * Toda la lógica se basa en:
 *   saldoInsoluto <= 0  → pagada
 *   saldoInsoluto  > 0  → pendiente
 *   status IN (3,4)     → cancelada (excluida en el repositorio)
 *
 * Identidad garantizada:
 *   monto_total = monto_pagado + monto_pendiente   (siempre cierra ✅)
 *
 * Estructura de `analiticas_ingresos`:
 *   sumatoria_pagos  → total cobrado (PUE + PPD convertido a MXN)
 *   sumatoria_pue    → cobrado en facturas de pago único
 *   sumatoria_ppd    → cobrado en facturas de parcialidades
 *   monto_pendiente  → total aún por cobrar
 *   ingresos_netos   → sumatoria_pagos − egresos (notas de crédito)
 */
class DashboardService {
    private $repository;
    private $currencyService;
    private $exchangeRatesCache = [];

    public function __construct($con) {
        $this->repository    = new DashboardRepository($con);
        $this->currencyService = new CurrencyService();
    }

    // ─── Tipo de cambio ───────────────────────────────────────────────────────

    private function getRate($currency) {
        $currency = strtoupper(trim($currency ?? ''));
        if (empty($currency)) $currency = 'MXN';
        if (!isset($this->exchangeRatesCache[$currency])) {
            $this->exchangeRatesCache[$currency] = $this->currencyService->getExchangeRate($currency, 'MXN');
        }
        return $this->exchangeRatesCache[$currency];
    }

    // ─── Entry point ─────────────────────────────────────────────────────────

    public function getDashboardData($filters) {
        $whereClause      = $this->repository->buildWhereClause($filters);
        $whereClauseNotas = $this->repository->buildWhereClauseNotas($filters);

        // Se obtiene una sola vez y se reutiliza en totales e ingresos
        $baseData = $this->repository->getTotalsByCurrency($whereClause);

        // Pre-carga de tipos de cambio en caché
        foreach (array_keys($baseData) as $moneda) {
            $this->getRate($moneda);
        }

        $egresos = $this->getEgresos($whereClauseNotas);

        return [
            'totales'                   => $this->getTotals($baseData),
            'analiticas_ingresos'       => $this->getIngresosReporting($baseData, $egresos),
            'egresos'                   => $egresos,
            'analiticas_pagos_pieChart' => $this->getPaymentMethodsChart($whereClause),
            'kpis_accounts_barChart'    => $this->getKPIsAccountsChart($whereClause),
            'kpis_clients_barChart'     => $this->getKPIsClientsChart($whereClause)
        ];
    }

    // ─── Totales principales ─────────────────────────────────────────────────

    /**
     * monto_total    = SUM(total)                              (emitido)
     * monto_pagado   = SUM(total - saldoInsoluto)              (cobrado, PUE+PPD)
     * monto_pendiente= SUM(saldoInsoluto)                      (por cobrar)
     * → Garantía: monto_total = monto_pagado + monto_pendiente ✅
     */
    private function getTotals(array $baseData) {
        $result = [
            'total_facturas'      => 0,
            'monto_total'         => 0.0,
            'facturas_pagadas'    => 0,
            'monto_pagado'        => 0.0,
            'facturas_pendientes' => 0,
            'monto_pendiente'     => 0.0,
        ];

        foreach ($baseData as $moneda => $data) {
            $rate = $this->getRate($moneda);
            $result['total_facturas']      += (int)($data['total_facturas']      ?? 0);
            $result['monto_total']         += ((float)($data['monto_total']      ?? 0)) * $rate;
            $result['facturas_pagadas']    += (int)($data['facturas_pagadas']    ?? 0);
            $result['facturas_pendientes'] += (int)($data['facturas_pendientes'] ?? 0);
            $result['monto_pagado']        += ((float)($data['monto_pagado']     ?? 0)) * $rate;
            $result['monto_pendiente']     += ((float)($data['monto_pendiente']  ?? 0)) * $rate;
        }

        $result['monto_total']     = round($result['monto_total'],     2);
        $result['monto_pagado']    = round($result['monto_pagado'],    2);
        $result['monto_pendiente'] = round($result['monto_pendiente'], 2);

        return $result;
    }

    // ─── Analíticas de ingresos ───────────────────────────────────────────────

    /**
     * sumatoria_pue  → cobrado en facturas PUE  (total - saldoInsoluto donde metodoPago='PUE')
     * sumatoria_ppd  → cobrado en facturas PPD  (total - saldoInsoluto donde metodoPago='PPD')
     * sumatoria_pagos→ sumatoria_pue + sumatoria_ppd  (= monto_pagado total)
     * monto_pendiente→ saldo aún por cobrar
     * ingresos_netos → sumatoria_pagos - egresos (notas de crédito = devoluciones)
     */
    private function getIngresosReporting(array $baseData, array $egresos) {
        $sumatoriaPUE   = 0.0;
        $sumatoriaPPD   = 0.0;
        $montoPendiente = 0.0;

        foreach ($baseData as $moneda => $data) {
            $rate = $this->getRate($moneda);
            $sumatoriaPUE   += ((float)($data['monto_pagado_pue'] ?? 0)) * $rate;
            $sumatoriaPPD   += ((float)($data['monto_pagado_ppd'] ?? 0)) * $rate;
            $montoPendiente += ((float)($data['monto_pendiente']  ?? 0)) * $rate;
        }

        $sumatoriaPagos = $sumatoriaPUE + $sumatoriaPPD;

        return [
            'sumatoria_pagos'  => round($sumatoriaPagos,                                2),
            'sumatoria_pue'    => round($sumatoriaPUE,                                  2),
            'sumatoria_ppd'    => round($sumatoriaPPD,                                  2),
            'monto_pendiente'  => round($montoPendiente,                                2),
            'ingresos_netos'   => round($sumatoriaPagos - $egresos['monto_egresos_mxn'], 2),
        ];
    }

    // ─── Egresos (notas de crédito) ───────────────────────────────────────────

    /**
     * notas_pagos.total = monto devuelto al cliente por cualquier motivo.
     * Se convierte a MXN según la moneda de la factura relacionada.
     */
    private function getEgresos($whereClauseNotas) {
        $egresosData   = $this->repository->getEgresosByCurrency($whereClauseNotas);
        $totalEgresos  = 0.0;
        $cantidadNotas = 0;

        foreach ($egresosData as $moneda => $data) {
            $rate          = $this->getRate($moneda);
            $totalEgresos  += ((float)($data['monto_egresos'] ?? 0)) * $rate;
            $cantidadNotas += (int)($data['cantidad_notas']   ?? 0);
        }

        return [
            'monto_egresos_mxn' => round($totalEgresos, 2),
            'cantidad_notas'    => $cantidadNotas,
        ];
    }

    // ─── Gráfica de métodos de pago ──────────────────────────────────────────

    private function getPaymentMethodsChart($whereClause) {
        $data       = $this->repository->getPaymentMethodsAnalyticsByCurrency($whereClause);
        $aggregated = [];

        foreach ($data as $row) {
            $name = empty($row['metodoPago']) ? 'Desconocido' : $row['metodoPago'];
            if (!isset($aggregated[$name])) {
                $aggregated[$name] = ['name' => $name, 'value' => 0, 'monto' => 0.0];
            }
            $rate = $this->getRate($row['moneda']);
            $aggregated[$name]['value'] += (int)$row['cantidad'];
            $aggregated[$name]['monto'] += ((float)$row['monto']) * $rate;
        }

        return array_values($aggregated);
    }

    // ─── KPIs por cuenta (empleado) ───────────────────────────────────────────

    private function getKPIsAccountsChart($whereClause) {
        $data = $this->repository->getKPIsByCreatedByWithCurrency($whereClause);

        foreach (array_unique(array_column($data, 'moneda')) as $moneda) {
            $this->getRate($moneda ?: 'MXN');
        }

        $aggregated = [];
        foreach ($data as $row) {
            $name = empty($row['account_name']) ? 'Desconocido' : $row['account_name'];
            if (!isset($aggregated[$name])) {
                $aggregated[$name] = ['pagadas' => 0, 'pendientes' => 0, 'monto_faltante' => 0.0];
            }
            $rate = $this->getRate($row['moneda']);
            $aggregated[$name]['pagadas']        += (int)$row['pagadas'];
            $aggregated[$name]['pendientes']     += (int)$row['pendientes'];
            $aggregated[$name]['monto_faltante'] += ((float)($row['monto_faltante'] ?? 0)) * $rate;
        }

        $xAxis           = [];
        $seriesPagadas   = [];
        $seriesPendientes= [];
        $seriesFaltante  = [];

        foreach ($aggregated as $name => $vals) {
            $xAxis[]            = $name;
            $seriesPagadas[]    = $vals['pagadas'];
            $seriesPendientes[] = $vals['pendientes'];
            $seriesFaltante[]   = round($vals['monto_faltante'], 2);
        }

        return [
            'xAxis'  => $xAxis,
            'series' => [
                ['name' => 'Pagadas',    'type' => 'bar', 'data' => $seriesPagadas],
                ['name' => 'Pendientes', 'type' => 'bar', 'data' => $seriesPendientes],
            ],
            'faltante_series' => [
                'name' => 'Monto Faltante (MXN)',
                'type' => 'line',
                'data' => $seriesFaltante,
            ],
        ];
    }

    // ─── KPIs por cliente ─────────────────────────────────────────────────────

    private function getKPIsClientsChart($whereClause) {
        $data = $this->repository->getKPIsByClientWithCurrency($whereClause);

        foreach (array_unique(array_column($data, 'moneda')) as $moneda) {
            $this->getRate($moneda ?: 'MXN');
        }

        $aggregated = [];
        foreach ($data as $row) {
            $name = !empty($row['cliente'])
                ? $row['cliente']
                : ($row['rfc_receptor'] ?? 'Desconocido');

            if (!isset($aggregated[$name])) {
                $aggregated[$name] = ['total_facturas' => 0, 'monto_total' => 0.0, 'monto_faltante' => 0.0];
            }
            $rate = $this->getRate($row['moneda']);
            $aggregated[$name]['total_facturas'] += (int)$row['total_facturas'];
            $aggregated[$name]['monto_total']    += ((float)($row['monto_total']    ?? 0)) * $rate;
            $aggregated[$name]['monto_faltante'] += ((float)($row['monto_faltante'] ?? 0)) * $rate;
        }

        $xAxis           = [];
        $seriesTotal     = [];
        $seriesMontoTotal= [];
        $seriesFaltante  = [];

        foreach ($aggregated as $name => $vals) {
            $xAxis[]            = $name;
            $seriesTotal[]      = $vals['total_facturas'];
            $seriesMontoTotal[] = round($vals['monto_total'],    2);
            $seriesFaltante[]   = round($vals['monto_faltante'], 2);
        }

        return [
            'xAxis'  => $xAxis,
            'series' => [
                ['name' => 'Total Facturas',    'type' => 'bar',                  'data' => $seriesTotal],
                ['name' => 'Monto Total (MXN)', 'type' => 'bar', 'yAxisIndex' => 1, 'data' => $seriesMontoTotal],
            ],
            'faltante_series' => [
                'name' => 'Monto Faltante (MXN)',
                'type' => 'bar',
                'data' => $seriesFaltante,
            ],
        ];
    }
}
?>
