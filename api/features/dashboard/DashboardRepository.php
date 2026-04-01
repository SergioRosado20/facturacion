<?php
require_once dirname(__DIR__, 3) . '/log_helper.php';

/**
 * DashboardRepository
 *
 * FUENTE DE VERDAD: tabla `facturas`
 *
 * Campos usados para cálculos:
 *   facturas.total         → monto emitido original
 *   facturas.saldoInsoluto → saldo pendiente actualizado y sincronizado
 *   facturas.status        → FK a factura_status (3 y 4 = canceladas, se excluyen)
 *   facturas.metodoPago    → 'PUE' o 'PPD' (para desglose de ingresos)
 *
 * Reglas de negocio (campo `pagado` NO se usa en ningún cálculo):
 *   saldoInsoluto <= 0  → factura considerada PAGADA
 *   saldoInsoluto  > 0  → factura considerada PENDIENTE
 *
 * Identidad matemática garantizada:
 *   monto_total = monto_pagado + monto_pendiente
 *   SUM(total)  = SUM(total - saldoInsoluto) + SUM(saldoInsoluto)  → siempre cierra ✅
 */
class DashboardRepository {
    private $con;

    // IDs de factura_status considerados CANCELADOS (excluidos de todos los totales)
    const CANCELLED_STATUS = [3, 4];

    public function __construct($con) {
        $this->con = $con;
    }

    /**
     * Fragmento SQL reutilizable para excluir facturas canceladas.
     */
    private function notCancelledClause($alias = 'facturas') {
        $ids = implode(',', self::CANCELLED_STATUS);
        return "$alias.status NOT IN ($ids)";
    }

    /**
     * WHERE general para queries sobre `facturas`.
     * Siempre incluye exclusión de canceladas.
     */
    public function buildWhereClause($filters, $tableAlias = 'facturas') {
        $where = " 1=1 AND {$this->notCancelledClause($tableAlias)} ";

        if (!empty($filters['customer_rfc'])) {
            $v = $this->con->real_escape_string($filters['customer_rfc']);
            $where .= " AND $tableAlias.rfc_receptor = '$v' ";
        }
        if (!empty($filters['created_by'])) {
            $v = $this->con->real_escape_string($filters['created_by']);
            $where .= " AND $tableAlias.created_by = '$v' ";
        }
        if (!empty($filters['fechaInicio'])) {
            $v = $this->con->real_escape_string($filters['fechaInicio']);
            $where .= " AND DATE($tableAlias.fecha) >= '$v' ";
        }
        if (!empty($filters['fechaFin'])) {
            $v = $this->con->real_escape_string($filters['fechaFin']);
            $where .= " AND DATE($tableAlias.fecha) <= '$v' ";
        }

        return $where;
    }

    /**
     * WHERE para notas_pagos (alias 'np') con JOIN a facturas (alias 'f').
     * Filtros de fecha → sobre np.fecha
     * Filtros de cliente/cuenta → sobre f.*
     */
    public function buildWhereClauseNotas($filters) {
        $ids   = implode(',', self::CANCELLED_STATUS);
        $where = " 1=1 AND f.status NOT IN ($ids) ";

        if (!empty($filters['customer_rfc'])) {
            $v = $this->con->real_escape_string($filters['customer_rfc']);
            $where .= " AND f.rfc_receptor = '$v' ";
        }
        if (!empty($filters['created_by'])) {
            $v = $this->con->real_escape_string($filters['created_by']);
            $where .= " AND f.created_by = '$v' ";
        }
        if (!empty($filters['fechaInicio'])) {
            $v = $this->con->real_escape_string($filters['fechaInicio']);
            $where .= " AND DATE(np.fecha) >= '$v' ";
        }
        if (!empty($filters['fechaFin'])) {
            $v = $this->con->real_escape_string($filters['fechaFin']);
            $where .= " AND DATE(np.fecha) <= '$v' ";
        }

        return $where;
    }

    /**
     * Totales y desglose de ingresos agrupados por moneda.
     *
     * Columnas retornadas:
     *   total_facturas     → cantidad de facturas activas
     *   monto_total        → SUM(total)
     *   facturas_pagadas   → COUNT donde saldoInsoluto <= 0
     *   facturas_pendientes→ COUNT donde saldoInsoluto  > 0
     *   monto_pagado       → SUM(total - saldoInsoluto)   — cobrado real (PUE + PPD)
     *   monto_pendiente    → SUM(saldoInsoluto)           — sin filtro: garantiza monto_total = pagado + pendiente
     *   monto_pagado_pue   → cobrado en facturas PUE
     *   monto_pagado_ppd   → cobrado en facturas PPD
     */
    public function getTotalsByCurrency($whereClause) {
        $sql = "SELECT
                    moneda,
                    COUNT(id)                                                                                       AS total_facturas,
                    SUM(total)                                                                                      AS monto_total,
                    COUNT(CASE WHEN saldoInsoluto <= 0 THEN 1 END)                                                 AS facturas_pagadas,
                    COUNT(CASE WHEN saldoInsoluto  > 0 THEN 1 END)                                                 AS facturas_pendientes,
                    SUM(total - COALESCE(saldoInsoluto, 0))                                                        AS monto_pagado,
                    SUM(COALESCE(saldoInsoluto, 0))                                                                AS monto_pendiente,
                    SUM(CASE WHEN metodoPago = 'PUE' THEN (total - COALESCE(saldoInsoluto, 0)) ELSE 0 END)         AS monto_pagado_pue,
                    SUM(CASE WHEN metodoPago = 'PPD' THEN (total - COALESCE(saldoInsoluto, 0)) ELSE 0 END)         AS monto_pagado_ppd
                FROM facturas
                WHERE $whereClause
                GROUP BY moneda";

        $result = $this->con->query($sql);
        $data   = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $moneda        = empty($row['moneda']) ? 'MXN' : $row['moneda'];
                $data[$moneda] = $row;
            }
        }
        return $data;
    }

    /**
     * Distribución por método de pago (pie chart).
     */
    public function getPaymentMethodsAnalyticsByCurrency($whereClause) {
        $sql = "SELECT
                    metodoPago,
                    moneda,
                    COUNT(id)  AS cantidad,
                    SUM(total) AS monto
                FROM facturas
                WHERE $whereClause AND metodoPago IS NOT NULL
                GROUP BY metodoPago, moneda";

        $result = $this->con->query($sql);
        $data   = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
        }
        return $data;
    }

    /**
     * KPIs por cuenta/emisor agrupados por moneda.
     *   pagadas      → saldoInsoluto <= 0
     *   pendientes   → saldoInsoluto  > 0
     *   monto_faltante → SUM(saldoInsoluto) de las pendientes
     */
    public function getKPIsByCreatedByWithCurrency($whereClause) {
        $sql = "SELECT
                    COALESCE(a.name, CAST(facturas.created_by AS CHAR), 'Desconocido') AS account_name,
                    facturas.moneda,
                    COUNT(facturas.id)                                                               AS total_facturas,
                    COUNT(CASE WHEN facturas.saldoInsoluto <= 0 THEN 1 END)                          AS pagadas,
                    COUNT(CASE WHEN facturas.saldoInsoluto  > 0 THEN 1 END)                          AS pendientes,
                    SUM(CASE WHEN facturas.saldoInsoluto  > 0 THEN facturas.saldoInsoluto ELSE 0 END) AS monto_faltante
                FROM facturas
                LEFT JOIN account a ON facturas.created_by = a.id
                WHERE $whereClause
                GROUP BY a.name, facturas.created_by, facturas.moneda";

        $result = $this->con->query($sql);
        $data   = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
        }
        return $data;
    }

    /**
     * KPIs por cliente (agrupado por RFC receptor) agrupados por moneda.
     *   nombre → facturas.cliente (campo de texto de la factura)
     *   monto_faltante → SUM(saldoInsoluto) de las pendientes
     */
    public function getKPIsByClientWithCurrency($whereClause) {
        $sql = "SELECT
                    facturas.rfc_receptor,
                    MAX(facturas.cliente)                                                              AS cliente,
                    facturas.moneda,
                    COUNT(facturas.id)                                                                 AS total_facturas,
                    SUM(facturas.total)                                                                AS monto_total,
                    SUM(CASE WHEN facturas.saldoInsoluto > 0 THEN facturas.saldoInsoluto ELSE 0 END)   AS monto_faltante
                FROM facturas
                WHERE $whereClause
                GROUP BY facturas.rfc_receptor, facturas.moneda";

        $result = $this->con->query($sql);
        $data   = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
        }
        return $data;
    }

    /**
     * Sumatoria de egresos (notas de crédito) agrupada por moneda.
     * notas_pagos.total representa el monto devuelto al cliente.
     */
    public function getEgresosByCurrency($whereClauseNotas) {
        $sql = "SELECT
                    f.moneda,
                    SUM(np.total)        AS monto_egresos,
                    COUNT(np.idNotaPago) AS cantidad_notas
                FROM notas_pagos np
                INNER JOIN facturas f ON np.idFactura = f.id
                WHERE $whereClauseNotas
                GROUP BY f.moneda";

        $result = $this->con->query($sql);
        $data   = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $moneda        = empty($row['moneda']) ? 'MXN' : $row['moneda'];
                $data[$moneda] = $row;
            }
        }
        return $data;
    }
}
?>
