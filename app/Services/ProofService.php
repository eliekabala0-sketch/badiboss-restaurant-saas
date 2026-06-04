<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Container;
use App\Core\Database;
use PDO;

final class ProofService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function saleProof(int $restaurantId, int $saleId): array
    {
        $sale = $this->saleRow($restaurantId, $saleId);
        $itemsStatement = $this->database->pdo()->prepare(
            'SELECT si.*, mi.name AS menu_item_name, mc.name AS category_name
             FROM sale_items si
             INNER JOIN menu_items mi ON mi.id = si.menu_item_id
             LEFT JOIN menu_categories mc ON mc.id = mi.category_id
             WHERE si.sale_id = :sale_id
             ORDER BY si.id ASC'
        );
        $itemsStatement->execute(['sale_id' => $saleId]);

        $transfer = null;
        $transferStatement = $this->database->pdo()->prepare(
            'SELECT ct.*, ru.full_name AS received_by_name, tu.full_name AS cashier_name
             FROM cash_transfers ct
             LEFT JOIN users ru ON ru.id = ct.received_by
             LEFT JOIN users tu ON tu.id = ct.to_user_id
             WHERE ct.restaurant_id = :restaurant_id
               AND ct.source_type = "sale"
               AND ct.source_id = :sale_id
             ORDER BY ct.id DESC
             LIMIT 1'
        );
        $transferStatement->execute(['restaurant_id' => $restaurantId, 'sale_id' => $saleId]);
        $row = $transferStatement->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            $transfer = $row;
        }

        return [
            'kind' => 'sale',
            'sale' => $sale,
            'items' => $itemsStatement->fetchAll(PDO::FETCH_ASSOC),
            'cash_transfer' => $transfer,
        ];
    }

    public function cashTransferProof(int $restaurantId, int $transferId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT ct.*,
                    fu.full_name AS server_name,
                    tu.full_name AS cashier_name,
                    ru.full_name AS received_by_name,
                    s.id AS sale_id,
                    s.total_amount AS sale_total_amount,
                    s.status AS sale_status,
                    sr.service_reference
             FROM cash_transfers ct
             LEFT JOIN users fu ON fu.id = ct.from_user_id
             LEFT JOIN users tu ON tu.id = ct.to_user_id
             LEFT JOIN users ru ON ru.id = ct.received_by
             LEFT JOIN sales s ON ct.source_type = "sale" AND s.id = ct.source_id
             LEFT JOIN server_requests sr ON s.origin_type = "server_request" AND sr.id = s.origin_id AND sr.restaurant_id = s.restaurant_id
             WHERE ct.id = :id AND ct.restaurant_id = :restaurant_id
             LIMIT 1'
        );
        $statement->execute(['id' => $transferId, 'restaurant_id' => $restaurantId]);
        $transfer = $statement->fetch(PDO::FETCH_ASSOC);
        if ($transfer === false) {
            throw new \RuntimeException('Versement caisse introuvable pour ce restaurant.');
        }

        $saleProof = null;
        if ((string) ($transfer['source_type'] ?? '') === 'sale' && (int) ($transfer['source_id'] ?? 0) > 0) {
            $saleProof = $this->saleProof($restaurantId, (int) $transfer['source_id']);
        }

        return [
            'kind' => 'cash_transfer',
            'transfer' => $transfer,
            'sale_proof' => $saleProof,
        ];
    }

    public function serverPeriodProof(int $restaurantId, int $serverUserId, string $period, string $anchor): array
    {
        $period = $period === 'monthly' ? 'monthly' : 'daily';
        if ($period === 'monthly') {
            $anchor = preg_match('/^\d{4}-\d{2}$/', $anchor) === 1 ? $anchor . '-01' : substr($anchor, 0, 7) . '-01';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor) !== 1) {
            $anchor = Container::getInstance()->get('reportService')->todayForRestaurant($restaurantId);
        }

        $user = $this->restaurantUser($restaurantId, $serverUserId);
        $report = Container::getInstance()->get('reportService')->dailyReport($restaurantId, $anchor, $period, [
            'user_id' => $serverUserId,
            '__financial_scope_server_id' => $serverUserId,
            '__report_heavy' => false,
        ]);

        $rangeStart = (string) ($report['range_start'] ?? ($anchor . ' 00:00:00'));
        $rangeEnd = (string) ($report['range_end'] ?? ($anchor . ' 23:59:59'));
        $from = substr($rangeStart, 0, 10);
        $to = substr($rangeEnd, 0, 10);
        $cash = Container::getInstance()->get('cashService')->periodCashClarity($restaurantId, $from, $to, $serverUserId);

        $discipline = null;
        try {
            $staff = Container::getInstance()->get('staffDiscipline');
            $staff->ensureSchema();
            $discipline = $period === 'monthly'
                ? $staff->payrollMonthPreviewLight($restaurantId, substr($anchor, 0, 7), [], [$serverUserId])
                : $staff->gaugesForUserOperationalPanelLight($restaurantId, $serverUserId, $anchor);
        } catch (\Throwable) {
            $discipline = null;
        }

        return [
            'kind' => $period === 'monthly' ? 'server_monthly' : 'server_daily',
            'period' => $period,
            'anchor' => $anchor,
            'user' => $user,
            'report' => $report,
            'cash' => $cash,
            'discipline' => $discipline,
        ];
    }

    public function documentIndex(int $restaurantId, array $filters): array
    {
        $today = Container::getInstance()->get('reportService')->todayForRestaurant($restaurantId);
        $date = (string) ($filters['date'] ?? $today);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            $date = $today;
        }
        $serverId = max(0, (int) ($filters['server_id'] ?? 0));
        $type = (string) ($filters['type'] ?? 'all');

        $sales = [];
        if ($type === 'all' || $type === 'sale') {
            $sql = 'SELECT s.*, u.full_name AS server_name, ' . sql_sale_activity_datetime_expr('s', 'sr') . ' AS sale_activity_at
                    FROM sales s
                    ' . sql_sale_activity_left_join_server_request('s', 'sr') . '
                    LEFT JOIN users u ON u.id = s.server_id
                    WHERE s.restaurant_id = :restaurant_id
                      AND ' . sql_sale_activity_datetime_expr('s', 'sr') . ' >= :start_at
                      AND ' . sql_sale_activity_datetime_expr('s', 'sr') . ' <= :end_at';
            $params = ['restaurant_id' => $restaurantId, 'start_at' => $date . ' 00:00:00', 'end_at' => $date . ' 23:59:59'];
            if ($serverId > 0) {
                $sql .= ' AND s.server_id = :server_id';
                $params['server_id'] = $serverId;
            }
            $sql .= ' ORDER BY s.id DESC LIMIT 80';
            $statement = $this->database->pdo()->prepare($sql);
            $statement->execute($params);
            $sales = $statement->fetchAll(PDO::FETCH_ASSOC);
        }

        $transfers = [];
        if ($type === 'all' || $type === 'cash_transfer') {
            $sql = 'SELECT ct.*, fu.full_name AS server_name, tu.full_name AS cashier_name
                    FROM cash_transfers ct
                    LEFT JOIN users fu ON fu.id = ct.from_user_id
                    LEFT JOIN users tu ON tu.id = ct.to_user_id
                    WHERE ct.restaurant_id = :restaurant_id
                      AND COALESCE(ct.received_at, ct.requested_at, ct.created_at) >= :start_at
                      AND COALESCE(ct.received_at, ct.requested_at, ct.created_at) <= :end_at';
            $params = ['restaurant_id' => $restaurantId, 'start_at' => $date . ' 00:00:00', 'end_at' => $date . ' 23:59:59'];
            if ($serverId > 0) {
                $sql .= ' AND ct.from_user_id = :server_id';
                $params['server_id'] = $serverId;
            }
            $sql .= ' ORDER BY ct.id DESC LIMIT 80';
            $statement = $this->database->pdo()->prepare($sql);
            $statement->execute($params);
            $transfers = $statement->fetchAll(PDO::FETCH_ASSOC);
        }

        return [
            'date' => $date,
            'type' => $type,
            'server_id' => $serverId,
            'sales' => $sales,
            'cash_transfers' => $transfers,
            'servers' => $this->servers($restaurantId),
        ];
    }

    private function saleRow(int $restaurantId, int $saleId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT s.*, u.full_name AS server_name, sr.service_reference, sr.created_at AS request_created_at,
                    ' . sql_sale_activity_datetime_expr('s', 'sr') . ' AS sale_activity_at
             FROM sales s
             ' . sql_sale_activity_left_join_server_request('s', 'sr') . '
             LEFT JOIN users u ON u.id = s.server_id
             WHERE s.id = :id AND s.restaurant_id = :restaurant_id
             LIMIT 1'
        );
        $statement->execute(['id' => $saleId, 'restaurant_id' => $restaurantId]);
        $sale = $statement->fetch(PDO::FETCH_ASSOC);
        if ($sale === false) {
            throw new \RuntimeException('Facture introuvable pour ce restaurant.');
        }

        return $sale;
    }

    private function restaurantUser(int $restaurantId, int $userId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT u.*, r.code AS role_code
             FROM users u
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id AND u.restaurant_id = :restaurant_id
             LIMIT 1'
        );
        $statement->execute(['id' => $userId, 'restaurant_id' => $restaurantId]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if ($user === false) {
            throw new \RuntimeException('Serveur introuvable pour ce restaurant.');
        }

        return $user;
    }

    private function servers(int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT u.id, u.full_name, r.code AS role_code
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.restaurant_id = :restaurant_id
               AND r.code = "cashier_server"
             ORDER BY u.full_name ASC'
        );
        $statement->execute(['restaurant_id' => $restaurantId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
