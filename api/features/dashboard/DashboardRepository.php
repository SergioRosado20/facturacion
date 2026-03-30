<?php
require_once dirname(__DIR__, 3) . '/log_helper.php';

class DashboardRepository {
    private $con;

    public function __construct($con) {
        $this->con = $con;
    }

    /**
     * Construye la cláusula WHERE basada en los filtros recibidos.
     * Soporta 'customer_rfc', 'created_by', 'fechaInicio' y 'fechaFin'.
     */
    public function buildWhereClause($filters, $tableAlias = 'facturas') {
        $where = " 1=1 ";
        
        if (!empty($filters['customer_rfc'])) {
            $customer_rfc = $this->con->real_escape_string($filters['customer_rfc']);
            $where .= " AND $tableAlias.rfc_receptor = '$customer_rfc' ";
        }
        
        if (!empty($filters['created_by'])) {
            $created_by = $this->con->real_escape_string($filters['created_by']);
            $where .= " AND $tableAlias.created_by = '$created_by' ";
        }

        if (!empty($filters['fechaInicio'])) {
            $fechaInicio = $this->con->real_escape_string($filters['fechaInicio']);
            $where .= " AND DATE($tableAlias.fecha) >= '$fechaInicio' ";
        }

        if (!empty($filters['fechaFin'])) {
            $fechaFin = $this->con->real_escape_string($filters['fechaFin']);
            $where .= " AND DATE($tableAlias.fecha) <= '$fechaFin' ";
        }

        return $where;
    }

    /**
     * Construye la cláusula WHERE para notas_pagos (notas de crédito).
     * - La fecha se filtra contra notas_pagos (alias 'np').
     * - El RFC del receptor y created_by se filtran contra facturas (alias 'f').
     */
    public function buildWhereClauseNotas($filters) {
        $where = " 1=1 ";

        if (!empty($filters['customer_rfc'])) {
            $customer_rfc = $this->con->real_escape_string($filters['customer_rfc']);
            $where .= " AND f.rfc_receptor = '$customer_rfc' ";
        }

        if (!empty($filters['created_by'])) {
            $created_by = $this->con->real_escape_string($filters['created_by']);
            $where .= " AND f.created_by = '$created_by' ";
        }

        if (!empty($filters['fechaInicio'])) {
            $fechaInicio = $this->con->real_escape_string($filters['fechaInicio']);
            $where .= " AND DATE(np.fecha) >= '$fechaInicio' ";
        }

        if (!empty($filters['fechaFin'])) {
            $fechaFin = $this->con->real_escape_string($filters['fechaFin']);
            $where .= " AND DATE(np.fecha) <= '$fechaFin' ";
        }

        return $where;
    }

    public function getTotalsByCurrency($whereClause) {
        $sql = "SELECT 
                    moneda,
                    COUNT(id) as total_facturas,
                    SUM(total) as monto_total,
                    SUM(CASE WHEN saldoInsoluto <= 0 OR pagado = 1 THEN 1 ELSE 0 END) as facturas_pagadas,
                    SUM(CASE WHEN metodoPago = 'PUE' THEN total ELSE 0 END) as monto_pagado_pue,
                    SUM(CASE WHEN saldoInsoluto > 0 AND pagado = 0 THEN 1 ELSE 0 END) as facturas_pendientes,
                    SUM(CASE WHEN saldoInsoluto > 0 AND pagado = 0 THEN saldoInsoluto ELSE 0 END) as monto_pendiente
                FROM facturas 
                WHERE $whereClause
                GROUP BY moneda";

        // LOG: dump the WHERE clause to verify filters are injected
        logToFile('dashboard_repo', 'n/a', '[getTotalsByCurrency] SQL WHERE', 'executing', $whereClause);
        
        $result = $this->con->query($sql);
        $data = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $moneda = empty($row['moneda']) ? 'MXN' : $row['moneda'];
                $data[$moneda] = $row;
            }
        }
        return $data;
    }

    public function getPaidPPDByCurrency($whereClauseAliasF) {
        $sql = "SELECT 
                    f.moneda,
                    SUM(pf.importePagado) as sumatoria_pagos_ppd,
                    COUNT(pf.id) as cantidad_pagos
                FROM pagos_facturas pf
                INNER JOIN facturas f ON pf.fkFactura = f.id
                INNER JOIN pagos p ON pf.fkPago = p.idPago
                WHERE $whereClauseAliasF 
                  AND f.metodoPago = 'PPD'
                  AND (p.status IS NULL OR p.status != 'Cancelado') 
                  AND (f.status != 0)
                GROUP BY f.moneda";
                
        $result = $this->con->query($sql);
        $data = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $moneda = empty($row['moneda']) ? 'MXN' : $row['moneda'];
                $data[$moneda] = $row;
            }
        }
        return $data;
    }

    public function getPaymentMethodsAnalyticsByCurrency($whereClause) {
        $sql = "SELECT 
                    metodoPago, 
                    moneda,
                    COUNT(id) as cantidad, 
                    SUM(total) as monto 
                FROM facturas 
                WHERE $whereClause AND metodoPago IS NOT NULL
                GROUP BY metodoPago, moneda";
                
        $result = $this->con->query($sql);
        $data = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
        }
        return $data;
    }

    public function getKPIsByCreatedByWithCurrency($whereClause) {
        $sql = "SELECT 
                    COALESCE(a.name, facturas.created_by, 'Desconocido') as account_name,
                    facturas.moneda,
                    COUNT(facturas.id) as total_facturas,
                    SUM(CASE WHEN facturas.saldoInsoluto <= 0 OR facturas.pagado = 1 THEN 1 ELSE 0 END) as pagadas,
                    SUM(CASE WHEN facturas.saldoInsoluto > 0 AND facturas.pagado = 0 THEN 1 ELSE 0 END) as pendientes,
                    SUM(CASE WHEN facturas.saldoInsoluto > 0 AND facturas.pagado = 0 THEN facturas.saldoInsoluto ELSE 0 END) as monto_faltante
                FROM facturas 
                LEFT JOIN account a ON facturas.created_by = a.id
                WHERE $whereClause 
                GROUP BY a.name, facturas.created_by, facturas.moneda";
                
        $result = $this->con->query($sql);
        $data = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
        }
        return $data;
    }

    public function getKPIsByClientWithCurrency($whereClause) {
        $sql = "SELECT 
                    COALESCE(c.name, facturas.rfc_receptor, 'Desconocido') as cliente,
                    facturas.moneda,
                    COUNT(facturas.id) as total_facturas,
                    SUM(facturas.total) as monto_total,
                    SUM(CASE WHEN facturas.saldoInsoluto > 0 AND facturas.pagado = 0 THEN facturas.saldoInsoluto ELSE 0 END) as monto_faltante
                FROM facturas 
                LEFT JOIN customer c ON facturas.rfc_receptor = c.rfc
                WHERE $whereClause 
                GROUP BY c.name, facturas.rfc_receptor, facturas.moneda";
                
        $result = $this->con->query($sql);
        $data = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
        }
        return $data;
    }

    /**
     * Obtiene la sumatoria de egresos (notas de crédito / notas_pagos)
     * agrupada por la moneda de la factura relacionada.
     * Se une notas_pagos con facturas para poder aplicar filtros de fecha,
     * RFC receptor y usuario creador.
     */
    public function getEgresosByCurrency($whereClauseNotas) {
        $sql = "SELECT 
                    f.moneda,
                    SUM(np.total) as monto_egresos,
                    COUNT(np.idNotaPago) as cantidad_notas
                FROM notas_pagos np
                INNER JOIN facturas f ON np.idFactura = f.id
                WHERE $whereClauseNotas
                GROUP BY f.moneda";

        $result = $this->con->query($sql);
        $data = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $moneda = empty($row['moneda']) ? 'MXN' : $row['moneda'];
                $data[$moneda] = $row;
            }
        }
        return $data;
    }
}
?>
