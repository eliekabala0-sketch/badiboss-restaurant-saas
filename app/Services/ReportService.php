<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Container;
use App\Core\Database;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class ReportService
{
    public function __construct(private readonly Database $database) {}
    public function dailyReport(int $restaurantId, string $date, string $period = 'daily', array $viewFilters = []): array
    {
        return $this->reportForPeriod($restaurantId, $date, $period, $viewFilters);
    }
    public function todayForRestaurant(int $restaurantId): string { return (new DateTimeImmutable('now', $this->reportTimezone($restaurantId)))->format('Y-m-d'); }
    public function reportForPeriod(int $restaurantId, string $date, string $period, array $viewFilters = []): array
    {
        $timezone = $this->reportTimezone($restaurantId);
        $selectedDate = $this->normalizeDate($date, $timezone);
        [$startAt, $endAt, $label] = $this->periodBounds($selectedDate, $period, $timezone);
        $displayEndAt = $endAt->sub(new DateInterval('PT1S'));
        $currentStock = (float) $this->scalar('SELECT COALESCE(SUM(quantity_in_stock), 0) FROM stock_items WHERE restaurant_id = :restaurant_id', ['restaurant_id' => $restaurantId]);
        $closedOnly = (bool) ($viewFilters['closed_sales_only'] ?? false);
        $userId = (int) ($viewFilters['user_id'] ?? 0);
        $salesByServer = $this->salesByServer($restaurantId, $startAt, $endAt, $closedOnly, $userId);
        $summary = ['period' => $period, 'period_label' => $label, 'selected_date' => $selectedDate->format('Y-m-d'), 'timezone' => $timezone->getName(), 'range_start' => $startAt->format('Y-m-d H:i:s'), 'range_end' => $displayEndAt->format('Y-m-d H:i:s'), 'opening_stock_total' => $this->openingStock($restaurantId, $startAt, $currentStock), 'current_stock_total' => $currentStock, 'kitchen_outputs' => $this->sumMovement($restaurantId, $startAt, $endAt, 'SORTIE_CUISINE'), 'stock_returns' => $this->sumMovement($restaurantId, $startAt, $endAt, 'RETOUR_STOCK'), 'kitchen_production' => $this->sumProduction($restaurantId, $startAt, $endAt), 'stock_report' => $this->stockReport($restaurantId, $startAt, $endAt), 'kitchen_report' => $this->kitchenReport($restaurantId, $startAt, $endAt), 'server_report' => $this->serverReport($restaurantId, $startAt, $endAt), 'financial_report' => $this->financialReport($restaurantId, $startAt, $endAt, $displayEndAt), 'product_margins' => $this->productMargins($restaurantId, $startAt, $endAt), 'sales_by_server' => $salesByServer, 'sales_by_type' => $this->salesByType($restaurantId, $startAt, $endAt), 'material_losses' => $this->sumLosses($restaurantId, $startAt, $endAt, 'MATIERE_PREMIERE'), 'financial_losses' => $this->sumLosses($restaurantId, $startAt, $endAt, 'ARGENT'), 'dish_yields' => $this->dishYields($restaurantId, $startAt, $endAt), 'product_issues' => $this->productIssues($restaurantId, $startAt, $endAt), 'incident_statuses' => $this->incidentsByField($restaurantId, $startAt, $endAt, 'status'), 'incident_qualifications' => $this->incidentsByField($restaurantId, $startAt, $endAt, 'final_qualification'), 'incident_responsibilities' => $this->incidentsByField($restaurantId, $startAt, $endAt, 'responsibility_scope'), 'incident_cases' => $this->incidentCases($restaurantId, $startAt, $endAt), 'fraud_alerts' => $this->fraudAlerts($restaurantId, $startAt, $endAt), 'view_filters' => $viewFilters, 'people_overview' => $this->peopleOverview($restaurantId, $startAt, $endAt, $userId, $salesByServer, $viewFilters), 'activity_index' => $this->activityIndex($restaurantId, $startAt, $endAt, $viewFilters), 'nominative_timeline' => $this->nominativeTimeline($restaurantId, $startAt, $endAt, $viewFilters)];
        $salesTotal = 0.0; foreach ($summary['sales_by_type'] as $row) { $salesTotal += (float) $row['total_amount']; }
        $summary['general_report'] = ['total_product_value' => (float) $summary['kitchen_report']['value_produced'], 'total_sold_value' => $salesTotal, 'real_material_cost_value' => (float) $summary['kitchen_report']['real_material_cost_of_sales'], 'total_losses_value' => (float) $summary['stock_report']['stock_losses_value'] + (float) $summary['kitchen_report']['kitchen_losses_value'] + (float) $summary['server_report']['server_loss_value'] + (float) $summary['financial_losses'], 'stock_loss_value' => (float) $summary['stock_report']['stock_losses_value'], 'kitchen_loss_value' => (float) $summary['kitchen_report']['kitchen_losses_value'], 'server_loss_value' => (float) $summary['server_report']['server_loss_value']];
        $summary['estimated_profit'] = $salesTotal - (float) $summary['kitchen_report']['real_material_cost_of_sales'] - (float) $summary['stock_report']['stock_losses_value'] - (float) $summary['kitchen_report']['kitchen_losses_value'] - (float) $summary['server_report']['server_loss_value'] - (float) $summary['financial_losses'];
        $summary['general_report']['estimated_gross_profit'] = (float) $summary['estimated_profit'];
        return $summary;
    }
    private function reportTimezone(int $restaurantId): DateTimeZone
    {
        $restaurant = Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId);
        $timezoneName = (string) ($restaurant['settings']['restaurant_reports_timezone'] ?? $restaurant['timezone'] ?? config('app.timezone', 'Africa/Lagos'));
        try { return new DateTimeZone($timezoneName); } catch (\Throwable) { return new DateTimeZone((string) config('app.timezone', 'Africa/Lagos')); }
    }
    private function normalizeDate(string $date, DateTimeZone $timezone): DateTimeImmutable
    {
        $trimmed = trim($date); if ($trimmed === '') { return new DateTimeImmutable('now', $timezone); }
        $base = DateTimeImmutable::createFromFormat('Y-m-d', $trimmed, $timezone); if ($base instanceof DateTimeImmutable) { return $base->setTime(0, 0, 0); }
        try { return new DateTimeImmutable($trimmed, $timezone); } catch (\Throwable) { return new DateTimeImmutable('now', $timezone); }
    }
    private function periodBounds(DateTimeImmutable $base, string $period, DateTimeZone $timezone): array
    {
        $base = $base->setTimezone($timezone);
        return match ($period) {
            'weekly' => [$base->modify('monday this week')->setTime(0, 0, 0), $base->modify('monday next week')->setTime(0, 0, 0), 'Semaine du ' . $base->modify('monday this week')->format('d/m/Y')],
            'monthly' => [$base->modify('first day of this month')->setTime(0, 0, 0), $base->modify('first day of next month')->setTime(0, 0, 0), 'Mois de ' . $base->format('m/Y')],
            default => [$base->setTime(0, 0, 0), $base->setTime(0, 0, 0)->add(new DateInterval('P1D')), 'Journée du ' . $base->format('d/m/Y')],
        };
    }
    private function openingStock(int $restaurantId, DateTimeImmutable $startAt, float $currentStock): float
    {
        $entries = (float) $this->scalar('SELECT COALESCE(SUM(quantity), 0) FROM stock_movements WHERE restaurant_id = :restaurant_id AND movement_type = "ENTREE" AND COALESCE(validated_at, created_at) >= :start_at', ['restaurant_id' => $restaurantId, 'start_at' => $startAt->format('Y-m-d H:i:s')]);
        $returns = (float) $this->scalar('SELECT COALESCE(SUM(quantity), 0) FROM stock_movements WHERE restaurant_id = :restaurant_id AND movement_type = "RETOUR_STOCK" AND COALESCE(validated_at, created_at) >= :start_at', ['restaurant_id' => $restaurantId, 'start_at' => $startAt->format('Y-m-d H:i:s')]);
        $losses = (float) $this->scalar('SELECT COALESCE(SUM(quantity), 0) FROM stock_movements WHERE restaurant_id = :restaurant_id AND movement_type = "PERTE" AND COALESCE(validated_at, created_at) >= :start_at', ['restaurant_id' => $restaurantId, 'start_at' => $startAt->format('Y-m-d H:i:s')]);
        return $currentStock - $entries - $returns + $losses;
    }
    private function stockReport(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt): array
    {
        $entry = $this->movementTotals($restaurantId, $startAt, $endAt, 'ENTREE'); $output = $this->movementTotals($restaurantId, $startAt, $endAt, 'SORTIE_CUISINE'); $loss = $this->movementTotals($restaurantId, $startAt, $endAt, 'PERTE'); $requests = $this->kitchenStockRequestSummary($restaurantId, $startAt, $endAt);
        return ['total_entered_quantity' => (float) $entry['quantity'], 'total_entered_value' => (float) $entry['value'], 'total_output_quantity' => (float) $output['quantity'], 'total_output_value' => (float) $output['value'], 'stock_value' => $this->currentStockValue($restaurantId), 'stock_losses_value' => (float) $loss['value'], 'urgent_requests' => (int) $requests['urgent_requests'], 'planned_requests' => (int) $requests['planned_requests'], 'ruptures' => (int) $requests['ruptures']];
    }
    private function kitchenReport(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt): array
    {
        $statement = $this->database->pdo()->prepare('SELECT COALESCE(SUM(quantity_produced), 0) AS total_produced, COALESCE(SUM(total_real_cost_snapshot), 0) AS total_real_cost, COALESCE(SUM(total_sale_value_snapshot), 0) AS value_produced, COALESCE(SUM(quantity_produced - quantity_remaining), 0) AS total_supplied_to_servers FROM kitchen_production WHERE restaurant_id = :restaurant_id AND created_at >= :start_at AND created_at < :end_at');
        $statement->execute(['restaurant_id' => $restaurantId, 'start_at' => $startAt->format('Y-m-d H:i:s'), 'end_at' => $endAt->format('Y-m-d H:i:s')]); $totals = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        $valueSupplied = $this->scalar('SELECT COALESCE(SUM(total_supplied_amount), 0) FROM server_requests WHERE restaurant_id = :restaurant_id AND created_at >= :start_at AND created_at < :end_at', ['restaurant_id' => $restaurantId, 'start_at' => $startAt->format('Y-m-d H:i:s'), 'end_at' => $endAt->format('Y-m-d H:i:s')]);
        $realMaterialCostOfSales = $this->scalar('SELECT COALESCE(SUM(si.quantity * COALESCE(kp.unit_real_cost_snapshot, avg_cost.unit_real_cost_snapshot, 0)), 0) FROM sale_items si INNER JOIN sales s ON s.id = si.sale_id LEFT JOIN kitchen_production kp ON kp.id = si.kitchen_production_id LEFT JOIN (SELECT restaurant_id, menu_item_id, AVG(unit_real_cost_snapshot) AS unit_real_cost_snapshot FROM kitchen_production WHERE menu_item_id IS NOT NULL GROUP BY restaurant_id, menu_item_id) avg_cost ON avg_cost.restaurant_id = s.restaurant_id AND avg_cost.menu_item_id = si.menu_item_id WHERE s.restaurant_id = :restaurant_id AND COALESCE(s.validated_at, s.created_at) >= :start_at AND COALESCE(s.validated_at, s.created_at) < :end_at', ['restaurant_id' => $restaurantId, 'start_at' => $startAt->format('Y-m-d H:i:s'), 'end_at' => $endAt->format('Y-m-d H:i:s')]);
        $kitchenLosses = $this->scalar('SELECT COALESCE(SUM(material_loss_amount), 0) FROM operation_cases WHERE restaurant_id = :restaurant_id AND COALESCE(decided_at, technical_confirmed_at, validated_at, created_at) >= :start_at AND COALESCE(decided_at, technical_confirmed_at, validated_at, created_at) < :end_at AND final_qualification = "perte_cuisine"', ['restaurant_id' => $restaurantId, 'start_at' => $startAt->format('Y-m-d H:i:s'), 'end_at' => $endAt->format('Y-m-d H:i:s')]);
        $incidentCount = $this->scalar('SELECT COUNT(*) FROM operation_cases WHERE restaurant_id = :restaurant_id AND created_at >= :start_at AND created_at < :end_at AND source_module = "kitchen"', ['restaurant_id' => $restaurantId, 'start_at' => $startAt->format('Y-m-d H:i:s'), 'end_at' => $endAt->format('Y-m-d H:i:s')]);
        return ['total_produced' => (float) ($totals['total_produced'] ?? 0), 'real_material_cost_produced' => (float) ($totals['total_real_cost'] ?? 0), 'value_produced' => (float) ($totals['value_produced'] ?? 0), 'total_supplied_to_servers' => (float) ($totals['total_supplied_to_servers'] ?? 0), 'value_supplied' => (float) $valueSupplied, 'real_material_cost_of_sales' => (float) $realMaterialCostOfSales, 'kitchen_losses_value' => (float) $kitchenLosses, 'kitchen_incidents' => (int) $incidentCount];
    }
    private function serverReport(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt): array
    {
        $statement = $this->database->pdo()->prepare('SELECT COALESCE(SUM(total_requested_amount), 0) AS total_requested, COALESCE(SUM(total_supplied_amount), 0) AS total_supplied, COALESCE(SUM(total_sold_amount), 0) AS total_sold, COALESCE(SUM(total_returned_amount), 0) AS total_returned, COALESCE(SUM(total_server_loss_amount), 0) AS total_server_loss FROM server_requests WHERE restaurant_id = :restaurant_id AND created_at >= :start_at AND created_at < :end_at');
        $statement->execute(['restaurant_id' => $restaurantId, 'start_at' => $startAt->format('Y-m-d H:i:s'), 'end_at' => $endAt->format('Y-m-d H:i:s')]); $totals = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        $incidents = $this->database->pdo()->prepare('SELECT COALESCE(u.full_name, "Serveur inconnu") AS server_name, COUNT(*) AS incidents FROM operation_cases oc LEFT JOIN users u ON u.id = oc.signaled_by LEFT JOIN roles r ON r.id = u.role_id WHERE oc.restaurant_id = :restaurant_id AND oc.created_at >= :start_at AND oc.created_at < :end_at AND r.code = "cashier_server" GROUP BY COALESCE(u.full_name, "Serveur inconnu") ORDER BY incidents DESC');
        $incidents->execute(['restaurant_id' => $restaurantId, 'start_at' => $startAt->format('Y-m-d H:i:s'), 'end_at' => $endAt->format('Y-m-d H:i:s')]);
        return ['total_requested' => (float) ($totals['total_requested'] ?? 0), 'total_supplied' => (float) ($totals['total_supplied'] ?? 0), 'total_sold' => (float) ($totals['total_sold'] ?? 0), 'total_returned' => (float) ($totals['total_returned'] ?? 0), 'server_loss_value' => (float) ($totals['total_server_loss'] ?? 0), 'incidents_by_server' => $incidents->fetchAll(PDO::FETCH_ASSOC)];
    }
    private function financialReport(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt, DateTimeImmutable $displayEndAt): array
    {
        $dateTo = $displayEndAt->format('Y-m-d');
        $transfers = Container::getInstance()->get('cashService')->dashboard($restaurantId, [
            'date_from' => $startAt->format('Y-m-d'),
            'date_to' => $dateTo,
        ]);
        $cashClarity = Container::getInstance()->get('cashService')->periodCashClarity(
            $restaurantId,
            $startAt->format('Y-m-d'),
            $dateTo
        );

        $byServer = $this->database->pdo()->prepare(
            'SELECT COALESCE(u.full_name, "Utilisateur non identifie") AS server_name,
                    COUNT(ct.id) AS transfer_count,
                    COALESCE(SUM(ct.amount), 0) AS total_amount
             FROM cash_transfers ct
             LEFT JOIN users u ON u.id = ct.from_user_id
             WHERE ct.restaurant_id = :restaurant_id
               AND ct.source_type = "sale"
               AND COALESCE(ct.received_at, ct.requested_at, ct.created_at) >= :start_at
               AND COALESCE(ct.received_at, ct.requested_at, ct.created_at) < :end_at
             GROUP BY COALESCE(u.full_name, "Utilisateur non identifie")
             ORDER BY total_amount DESC'
        );
        $byServer->execute([
            'restaurant_id' => $restaurantId,
            'start_at' => $startAt->format('Y-m-d H:i:s'),
            'end_at' => $endAt->format('Y-m-d H:i:s'),
        ]);

        return [
            'summary' => $transfers['summary'] ?? [],
            'cash_clarity' => $cashClarity,
            'remittances_by_server' => $byServer->fetchAll(PDO::FETCH_ASSOC),
            'sale_remittance_details' => array_values(array_filter(
                $transfers['transfers'] ?? [],
                static fn (array $transfer): bool => (string) ($transfer['source_type'] ?? '') === 'sale'
            )),
        ];
    }
    private function productMargins(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt): array
    {
        $statement = $this->database->pdo()->prepare('SELECT mi.name AS menu_item_name, COALESCE(SUM(si.quantity), 0) AS quantity_sold, COALESCE(AVG(si.unit_price), 0) AS average_sale_price, COALESCE(SUM(si.quantity * si.unit_price), 0) AS total_sales_value, COALESCE(AVG(COALESCE(kp.unit_real_cost_snapshot, avg_cost.unit_real_cost_snapshot, 0)), 0) AS unit_real_cost, COALESCE(SUM(si.quantity * COALESCE(kp.unit_real_cost_snapshot, avg_cost.unit_real_cost_snapshot, 0)), 0) AS total_real_cost FROM sale_items si INNER JOIN sales s ON s.id = si.sale_id INNER JOIN menu_items mi ON mi.id = si.menu_item_id LEFT JOIN kitchen_production kp ON kp.id = si.kitchen_production_id LEFT JOIN (SELECT restaurant_id, menu_item_id, AVG(unit_real_cost_snapshot) AS unit_real_cost_snapshot FROM kitchen_production WHERE menu_item_id IS NOT NULL GROUP BY restaurant_id, menu_item_id) avg_cost ON avg_cost.restaurant_id = s.restaurant_id AND avg_cost.menu_item_id = si.menu_item_id WHERE s.restaurant_id = :restaurant_id AND COALESCE(s.validated_at, s.created_at) >= :start_at AND COALESCE(s.validated_at, s.created_at) < :end_at GROUP BY mi.id, mi.name ORDER BY total_sales_value DESC, mi.name ASC');
        $statement->execute(['restaurant_id' => $restaurantId, 'start_at' => $startAt->format('Y-m-d H:i:s'), 'end_at' => $endAt->format('Y-m-d H:i:s')]); $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) { $row['unit_margin'] = (float) $row['average_sale_price'] - (float) $row['unit_real_cost']; $row['total_margin'] = (float) $row['total_sales_value'] - (float) $row['total_real_cost']; } unset($row);
        return $rows;
    }
    private function salesByServer(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt, bool $closedOnly = false, int $userId = 0): array
    {
        $extra = '';
        if ($closedOnly) {
            $extra .= ' AND s.status IN ("VALIDE","CLOTURE","VENDU_TOTAL","VENDU_PARTIEL")';
        }
        if ($userId > 0) {
            $extra .= ' AND s.server_id = ' . $userId;
        }
        $statement = $this->database->pdo()->prepare('SELECT COALESCE(u.full_name, "Vente automatique") AS server_name, COUNT(s.id) AS sales_count, COALESCE(SUM(s.total_amount), 0) AS total_amount FROM sales s LEFT JOIN users u ON u.id = s.server_id WHERE s.restaurant_id = :restaurant_id AND COALESCE(s.validated_at, s.created_at) >= :start_at AND COALESCE(s.validated_at, s.created_at) < :end_at' . $extra . ' GROUP BY COALESCE(u.full_name, "Vente automatique") ORDER BY total_amount DESC');
        $statement->execute(['restaurant_id' => $restaurantId, 'start_at' => $startAt->format('Y-m-d H:i:s'), 'end_at' => $endAt->format('Y-m-d H:i:s')]); return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
    private function salesByType(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt): array
    {
        $statement = $this->database->pdo()->prepare('SELECT sale_type, COUNT(*) AS sales_count, COALESCE(SUM(total_amount), 0) AS total_amount FROM sales WHERE restaurant_id = :restaurant_id AND COALESCE(validated_at, created_at) >= :start_at AND COALESCE(validated_at, created_at) < :end_at GROUP BY sale_type ORDER BY sale_type ASC');
        $statement->execute(['restaurant_id' => $restaurantId, 'start_at' => $startAt->format('Y-m-d H:i:s'), 'end_at' => $endAt->format('Y-m-d H:i:s')]); return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
    private function sumLosses(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt, string $type): float
    {
        return (float) $this->scalar('SELECT COALESCE(SUM(amount), 0) FROM losses WHERE restaurant_id = :restaurant_id AND loss_type = :loss_type AND COALESCE(validated_at, created_at) >= :start_at AND COALESCE(validated_at, created_at) < :end_at', ['restaurant_id' => $restaurantId, 'loss_type' => $type, 'start_at' => $startAt->format('Y-m-d H:i:s'), 'end_at' => $endAt->format('Y-m-d H:i:s')]);
    }
    private function dishYields(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt): array
    {
        $statement = $this->database->pdo()->prepare('SELECT si.name AS stock_item_name, kp.dish_type, COALESCE(SUM(sm.quantity), 0) AS source_quantity, COALESCE(SUM(kp.quantity_produced), 0) AS dishes_produced FROM kitchen_production kp INNER JOIN stock_movements sm ON sm.id = kp.stock_movement_id INNER JOIN stock_items si ON si.id = sm.stock_item_id WHERE kp.restaurant_id = :restaurant_id AND kp.created_at >= :start_at AND kp.created_at < :end_at GROUP BY si.name, kp.dish_type ORDER BY kp.dish_type ASC');
        $statement->execute(['restaurant_id' => $restaurantId, 'start_at' => $startAt->format('Y-m-d H:i:s'), 'end_at' => $endAt->format('Y-m-d H:i:s')]); return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
    private function productIssues(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt): array
    {
        $statement = $this->database->pdo()->prepare('SELECT mi.name AS menu_item_name, COUNT(si.id) AS return_count FROM sale_items si INNER JOIN sales s ON s.id = si.sale_id INNER JOIN menu_items mi ON mi.id = si.menu_item_id WHERE s.restaurant_id = :restaurant_id AND si.status = "RETOUR" AND COALESCE(si.returned_at, si.created_at) >= :start_at AND COALESCE(si.returned_at, si.created_at) < :end_at GROUP BY mi.name ORDER BY return_count DESC');
        $statement->execute(['restaurant_id' => $restaurantId, 'start_at' => $startAt->format('Y-m-d H:i:s'), 'end_at' => $endAt->format('Y-m-d H:i:s')]); return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
    private function incidentsByField(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt, string $field): array
    {
        if (!in_array($field, ['status', 'final_qualification', 'responsibility_scope'], true)) { return []; }
        $statement = $this->database->pdo()->prepare('SELECT ' . $field . ' AS label, COUNT(*) AS total FROM operation_cases WHERE restaurant_id = :restaurant_id AND created_at >= :start_at AND created_at < :end_at AND ' . $field . ' IS NOT NULL AND ' . $field . ' != "" GROUP BY ' . $field . ' ORDER BY total DESC, ' . $field . ' ASC');
        $statement->execute(['restaurant_id' => $restaurantId, 'start_at' => $startAt->format('Y-m-d H:i:s'), 'end_at' => $endAt->format('Y-m-d H:i:s')]); return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
    private function incidentCases(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt): array
    {
        $statement = $this->database->pdo()->prepare('SELECT oc.*, s.full_name AS signaled_by_name, t.full_name AS technical_confirmed_by_name, d.full_name AS decided_by_name, si.name AS stock_item_name FROM operation_cases oc LEFT JOIN users s ON s.id = oc.signaled_by LEFT JOIN users t ON t.id = oc.technical_confirmed_by LEFT JOIN users d ON d.id = oc.decided_by LEFT JOIN stock_items si ON si.id = oc.stock_item_id WHERE oc.restaurant_id = :restaurant_id AND oc.created_at >= :start_at AND oc.created_at < :end_at ORDER BY oc.id DESC');
        $statement->execute(['restaurant_id' => $restaurantId, 'start_at' => $startAt->format('Y-m-d H:i:s'), 'end_at' => $endAt->format('Y-m-d H:i:s')]); return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
    private function fraudAlerts(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt): array
    {
        $rules = $this->alertRules(); $alerts = [];
        $serverStatement = $this->database->pdo()->prepare('SELECT u.full_name, COUNT(*) AS total FROM operation_cases oc INNER JOIN users u ON u.id = oc.signaled_by WHERE oc.restaurant_id = :restaurant_id AND oc.created_at >= :start_at AND oc.created_at < :end_at GROUP BY u.id, u.full_name HAVING COUNT(*) >= :threshold');
        $serverStatement->execute(['restaurant_id' => $restaurantId, 'start_at' => $startAt->format('Y-m-d H:i:s'), 'end_at' => $endAt->format('Y-m-d H:i:s'), 'threshold' => (int) ($rules['server_incident_threshold'] ?? 3)]);
        foreach ($serverStatement->fetchAll(PDO::FETCH_ASSOC) as $row) { $alerts[] = ['type' => 'server_incidents', 'label' => 'Incidents serveur fréquents', 'detail' => $row['full_name'] . ' a signalé ' . $row['total'] . ' incident(s).']; }
        $kitchenLosses = (int) $this->scalar('SELECT COUNT(*) FROM operation_cases WHERE restaurant_id = :restaurant_id AND COALESCE(decided_at, technical_confirmed_at, validated_at, created_at) >= :start_at AND COALESCE(decided_at, technical_confirmed_at, validated_at, created_at) < :end_at AND final_qualification = "perte_cuisine"', ['restaurant_id' => $restaurantId, 'start_at' => $startAt->format('Y-m-d H:i:s'), 'end_at' => $endAt->format('Y-m-d H:i:s')]);
        if ($kitchenLosses >= (int) ($rules['kitchen_loss_threshold'] ?? 2)) { $alerts[] = ['type' => 'kitchen_losses', 'label' => 'Pertes cuisine élevées', 'detail' => $kitchenLosses . ' cas classés en perte cuisine sur la période.']; }
        $rejectedCases = (int) $this->scalar('SELECT COUNT(*) FROM operation_cases WHERE restaurant_id = :restaurant_id AND COALESCE(decided_at, validated_at, created_at) >= :start_at AND COALESCE(decided_at, validated_at, created_at) < :end_at AND status = "REJETE"', ['restaurant_id' => $restaurantId, 'start_at' => $startAt->format('Y-m-d H:i:s'), 'end_at' => $endAt->format('Y-m-d H:i:s')]);
        if ($rejectedCases >= (int) ($rules['repeated_inconsistency_threshold'] ?? 2)) { $alerts[] = ['type' => 'repeated_inconsistencies', 'label' => 'Incohérences répétées', 'detail' => $rejectedCases . ' cas rejetés pour incohérence ou invalidité.']; }
        $returns = (int) $this->scalar('SELECT COUNT(*) FROM operation_cases WHERE restaurant_id = :restaurant_id AND created_at >= :start_at AND created_at < :end_at AND source_module = "sales"', ['restaurant_id' => $restaurantId, 'start_at' => $startAt->format('Y-m-d H:i:s'), 'end_at' => $endAt->format('Y-m-d H:i:s')]);
        if ($returns >= (int) ($rules['frequent_return_threshold'] ?? 3)) { $alerts[] = ['type' => 'frequent_returns', 'label' => 'Retours anormaux fréquents', 'detail' => $returns . ' cas de retour ou incident vente sur la période.']; }
        return $alerts;
    }
    private function sumMovement(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt, string $type): float { return (float) $this->scalar('SELECT COALESCE(SUM(quantity), 0) FROM stock_movements WHERE restaurant_id = :restaurant_id AND movement_type = :movement_type AND COALESCE(validated_at, created_at) >= :start_at AND COALESCE(validated_at, created_at) < :end_at', ['restaurant_id' => $restaurantId, 'movement_type' => $type, 'start_at' => $startAt->format('Y-m-d H:i:s'), 'end_at' => $endAt->format('Y-m-d H:i:s')]); }
    private function movementTotals(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt, string $type): array
    {
        $statement = $this->database->pdo()->prepare('SELECT COALESCE(SUM(quantity), 0) AS total_quantity, COALESCE(SUM(total_cost_snapshot), 0) AS total_value FROM stock_movements WHERE restaurant_id = :restaurant_id AND movement_type = :movement_type AND COALESCE(validated_at, created_at) >= :start_at AND COALESCE(validated_at, created_at) < :end_at');
        $statement->execute(['restaurant_id' => $restaurantId, 'movement_type' => $type, 'start_at' => $startAt->format('Y-m-d H:i:s'), 'end_at' => $endAt->format('Y-m-d H:i:s')]); $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['quantity' => (float) ($row['total_quantity'] ?? 0), 'value' => (float) ($row['total_value'] ?? 0)];
    }
    private function sumProduction(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt): float { return (float) $this->scalar('SELECT COALESCE(SUM(quantity_produced), 0) FROM kitchen_production WHERE restaurant_id = :restaurant_id AND created_at >= :start_at AND created_at < :end_at', ['restaurant_id' => $restaurantId, 'start_at' => $startAt->format('Y-m-d H:i:s'), 'end_at' => $endAt->format('Y-m-d H:i:s')]); }
    private function currentStockValue(int $restaurantId): float { return (float) $this->scalar('SELECT COALESCE(SUM(quantity_in_stock * estimated_unit_cost), 0) FROM stock_items WHERE restaurant_id = :restaurant_id', ['restaurant_id' => $restaurantId]); }
    private function kitchenStockRequestSummary(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt): array
    {
        $statement = $this->database->pdo()->prepare('SELECT SUM(CASE WHEN planning_status = "urgence" THEN 1 ELSE 0 END) AS urgent_requests, SUM(CASE WHEN planning_status = "a_prevoir" THEN 1 ELSE 0 END) AS planned_requests, SUM(CASE WHEN status = "INDISPONIBLE" THEN 1 ELSE 0 END) AS ruptures FROM kitchen_stock_requests WHERE restaurant_id = :restaurant_id AND created_at >= :start_at AND created_at < :end_at');
        $statement->execute(['restaurant_id' => $restaurantId, 'start_at' => $startAt->format('Y-m-d H:i:s'), 'end_at' => $endAt->format('Y-m-d H:i:s')]); return $statement->fetch(PDO::FETCH_ASSOC) ?: ['urgent_requests' => 0, 'planned_requests' => 0, 'ruptures' => 0];
    }

    /**
     * @param list<array<string, mixed>> $salesByServer
     * @return array<string, mixed>
     */
    private function peopleOverview(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt, int $userId, array $salesByServer, array $viewFilters): array
    {
        $roleCode = trim((string) ($viewFilters['role_code'] ?? ''));
        $kitchen = $this->kitchenByCook($restaurantId, $startAt, $endAt, $userId, $roleCode);
        $stock = $this->stockMovementsByUser($restaurantId, $startAt, $endAt, $userId, $roleCode);
        $cashPersons = $this->cashActivityByPerson($restaurantId, $startAt, $endAt, $userId, $roleCode);
        $totSalesCount = 0;
        $totSalesAmount = 0.0;
        foreach ($salesByServer as $row) {
            $totSalesCount += (int) ($row['sales_count'] ?? 0);
            $totSalesAmount += (float) ($row['total_amount'] ?? 0);
        }
        $totPlates = 0.0;
        foreach ($kitchen as $row) {
            $totPlates += (float) ($row['plates_prepared'] ?? 0);
        }
        $totStockOut = 0;
        $totLosses = 0;
        foreach ($stock as $row) {
            $totStockOut += (int) ($row['sorties_count'] ?? 0);
            $totLosses += (int) ($row['pertes_count'] ?? 0);
        }

        return [
            'sales_by_server_rows' => $salesByServer,
            'kitchen_by_cook' => $kitchen,
            'stock_by_staff' => $stock,
            'cash_touchpoints' => $cashPersons,
            'grand_totals' => [
                'sales_count' => $totSalesCount,
                'sales_amount' => $totSalesAmount,
                'plates_prepared' => $totPlates,
                'kitchen_productions' => array_sum(array_map(static fn (array $r): int => (int) ($r['productions_count'] ?? 0), $kitchen)),
                'stock_sorties' => $totStockOut,
                'stock_pertes' => $totLosses,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function kitchenByCook(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt, int $userId, string $roleCode): array
    {
        $extra = '';
        if ($userId > 0) {
            $extra .= ' AND kp.created_by = ' . $userId;
        }
        if ($roleCode !== '') {
            $extra .= ' AND r.code = ' . $this->database->pdo()->quote($roleCode);
        }
        $statement = $this->database->pdo()->prepare(
            'SELECT u.id AS user_id, u.full_name AS full_name, r.code AS role_code,
                    COUNT(kp.id) AS productions_count,
                    COALESCE(SUM(kp.quantity_produced), 0) AS plates_prepared
             FROM kitchen_production kp
             INNER JOIN users u ON u.id = kp.created_by
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE kp.restaurant_id = :restaurant_id AND kp.created_at >= :start_at AND kp.created_at < :end_at' . $extra . '
             GROUP BY u.id, u.full_name, r.code
             ORDER BY plates_prepared DESC'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'start_at' => $startAt->format('Y-m-d H:i:s'),
            'end_at' => $endAt->format('Y-m-d H:i:s'),
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stockMovementsByUser(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt, int $userId, string $roleCode): array
    {
        $extra = '';
        if ($userId > 0) {
            $extra .= ' AND sm.user_id = ' . $userId;
        }
        if ($roleCode !== '') {
            $extra .= ' AND r.code = ' . $this->database->pdo()->quote($roleCode);
        }
        $statement = $this->database->pdo()->prepare(
            'SELECT u.id AS user_id, u.full_name AS full_name, r.code AS role_code,
                    SUM(CASE WHEN sm.movement_type IN ("SORTIE_CUISINE","SORTIE") THEN 1 ELSE 0 END) AS sorties_count,
                    SUM(CASE WHEN sm.movement_type = "PERTE" THEN 1 ELSE 0 END) AS pertes_count,
                    COUNT(sm.id) AS movements_total
             FROM stock_movements sm
             INNER JOIN users u ON u.id = sm.user_id
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE sm.restaurant_id = :restaurant_id
               AND sm.created_at >= :start_at AND sm.created_at < :end_at
               AND sm.status = "VALIDE"' . $extra . '
             GROUP BY u.id, u.full_name, r.code
             ORDER BY movements_total DESC'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'start_at' => $startAt->format('Y-m-d H:i:s'),
            'end_at' => $endAt->format('Y-m-d H:i:s'),
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cashActivityByPerson(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt, int $userId, string $roleCode): array
    {
        $s = $startAt->format('Y-m-d H:i:s');
        $e = $endAt->format('Y-m-d H:i:s');
        $extraUser = $userId > 0 ? ' AND u.id = ' . $userId : '';
        $extraRole = $roleCode !== '' ? ' AND r.code = ' . $this->database->pdo()->quote($roleCode) : '';
        $statement = $this->database->pdo()->prepare(
            'SELECT u.id AS user_id,
                    u.full_name AS full_name,
                    r.code AS role_code,
                    COALESCE(rs.remis, 0) AS remis_ventes,
                    COALESCE(rc.recu, 0) AS recu_caisse_ventes,
                    COALESCE(rg.rem, 0) AS remis_comme_caisse_gerant
             FROM users u
             LEFT JOIN roles r ON r.id = u.role_id
             LEFT JOIN (
                 SELECT from_user_id, SUM(amount) AS remis FROM cash_transfers
                 WHERE restaurant_id = :rid1 AND source_type = "sale"
                   AND COALESCE(received_at, requested_at, created_at) >= :s1
                   AND COALESCE(received_at, requested_at, created_at) < :e1
                 GROUP BY from_user_id
             ) rs ON rs.from_user_id = u.id
             LEFT JOIN (
                 SELECT received_by, SUM(COALESCE(amount_received, amount)) AS recu FROM cash_transfers
                 WHERE restaurant_id = :rid2 AND source_type = "sale"
                   AND status IN ("RECU_CAISSE","ECART_SIGNALE")
                   AND COALESCE(received_at, requested_at, created_at) >= :s2
                   AND COALESCE(received_at, requested_at, created_at) < :e2
                 GROUP BY received_by
             ) rc ON rc.received_by = u.id
             LEFT JOIN (
                 SELECT from_user_id, SUM(amount) AS rem FROM cash_transfers
                 WHERE restaurant_id = :rid3 AND source_type = "REMISE_GERANT"
                   AND COALESCE(received_at, requested_at, created_at) >= :s3
                   AND COALESCE(received_at, requested_at, created_at) < :e3
                 GROUP BY from_user_id
             ) rg ON rg.from_user_id = u.id
             WHERE u.restaurant_id = :ridu
               AND (COALESCE(rs.remis, 0) + COALESCE(rc.recu, 0) + COALESCE(rg.rem, 0)) > 0.0001'
                . $extraUser . $extraRole
        );
        $statement->execute([
            'rid1' => $restaurantId, 's1' => $s, 'e1' => $e,
            'rid2' => $restaurantId, 's2' => $s, 'e2' => $e,
            'rid3' => $restaurantId, 's3' => $s, 'e3' => $e,
            'ridu' => $restaurantId,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array{global_percent: int, agents: list<array<string, mixed>>}
     */
    private function activityIndex(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt, array $viewFilters): array
    {
        $s = $startAt->format('Y-m-d H:i:s');
        $e = $endAt->format('Y-m-d H:i:s');
        $closedStatuses = ['VALIDE', 'CLOTURE', 'VENDU_TOTAL', 'VENDU_PARTIEL'];
        $roleFilter = trim((string) ($viewFilters['role_code'] ?? ''));
        $userFilter = (int) ($viewFilters['user_id'] ?? 0);
        $scores = [];
        $merge = static function (array &$bucket, int $uid, string $name, string $role, float $add): void {
            if ($uid <= 0 || $add <= 0) {
                return;
            }
            if (!isset($bucket[$uid])) {
                $bucket[$uid] = ['user_id' => $uid, 'full_name' => $name, 'role_code' => $role, 'score' => 0.0];
            }
            $bucket[$uid]['score'] += $add;
        };
        $inStatus = implode(',', array_map(static fn (string $st): string => '"' . $st . '"', $closedStatuses));

        $sql = 'SELECT s.server_id AS uid, u.full_name AS fn, COALESCE(r.code, "") AS rc, COUNT(*) AS c
                FROM sales s
                INNER JOIN users u ON u.id = s.server_id
                LEFT JOIN roles r ON r.id = u.role_id
                WHERE s.restaurant_id = :rid AND s.status IN (' . $inStatus . ')
                  AND COALESCE(s.validated_at, s.created_at) >= :st AND COALESCE(s.validated_at, s.created_at) < :en';
        $params = ['rid' => $restaurantId, 'st' => $s, 'en' => $e];
        if ($userFilter > 0) {
            $sql .= ' AND s.server_id = :uidfil';
            $params['uidfil'] = $userFilter;
        }
        if ($roleFilter !== '') {
            $sql .= ' AND r.code = :rolec';
            $params['rolec'] = $roleFilter;
        }
        $sql .= ' GROUP BY s.server_id, u.full_name, r.code';
        $st = $this->database->pdo()->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $merge($scores, (int) $row['uid'], (string) $row['fn'], (string) $row['rc'], (float) $row['c']);
        }

        $sql = 'SELECT kp.created_by AS uid, u.full_name AS fn, COALESCE(r.code, "") AS rc, COUNT(*) * 2 AS c
                FROM kitchen_production kp
                INNER JOIN users u ON u.id = kp.created_by
                LEFT JOIN roles r ON r.id = u.role_id
                WHERE kp.restaurant_id = :rid AND kp.created_at >= :st AND kp.created_at < :en';
        $params = ['rid' => $restaurantId, 'st' => $s, 'en' => $e];
        if ($userFilter > 0) {
            $sql .= ' AND kp.created_by = :uidfil';
            $params['uidfil'] = $userFilter;
        }
        if ($roleFilter !== '') {
            $sql .= ' AND r.code = :rolec';
            $params['rolec'] = $roleFilter;
        }
        $sql .= ' GROUP BY kp.created_by, u.full_name, r.code';
        $st = $this->database->pdo()->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $merge($scores, (int) $row['uid'], (string) $row['fn'], (string) $row['rc'], (float) $row['c']);
        }

        $sql = 'SELECT sm.user_id AS uid, u.full_name AS fn, COALESCE(r.code, "") AS rc, COUNT(*) AS c
                FROM stock_movements sm
                INNER JOIN users u ON u.id = sm.user_id
                LEFT JOIN roles r ON r.id = u.role_id
                WHERE sm.restaurant_id = :rid AND sm.status = "VALIDE"
                  AND sm.created_at >= :st AND sm.created_at < :en';
        $params = ['rid' => $restaurantId, 'st' => $s, 'en' => $e];
        if ($userFilter > 0) {
            $sql .= ' AND sm.user_id = :uidfil';
            $params['uidfil'] = $userFilter;
        }
        if ($roleFilter !== '') {
            $sql .= ' AND r.code = :rolec';
            $params['rolec'] = $roleFilter;
        }
        $sql .= ' GROUP BY sm.user_id, u.full_name, r.code';
        $st = $this->database->pdo()->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $merge($scores, (int) $row['uid'], (string) $row['fn'], (string) $row['rc'], (float) $row['c']);
        }

        $sql = 'SELECT ct.from_user_id AS uid, u.full_name AS fn, COALESCE(r.code, "") AS rc, COUNT(*) AS c
                FROM cash_transfers ct
                INNER JOIN users u ON u.id = ct.from_user_id
                LEFT JOIN roles r ON r.id = u.role_id
                WHERE ct.restaurant_id = :rid AND ct.source_type = "sale"
                  AND COALESCE(ct.received_at, ct.requested_at, ct.created_at) >= :st
                  AND COALESCE(ct.received_at, ct.requested_at, ct.created_at) < :en';
        $params = ['rid' => $restaurantId, 'st' => $s, 'en' => $e];
        if ($userFilter > 0) {
            $sql .= ' AND ct.from_user_id = :uidfil';
            $params['uidfil'] = $userFilter;
        }
        if ($roleFilter !== '') {
            $sql .= ' AND r.code = :rolec';
            $params['rolec'] = $roleFilter;
        }
        $sql .= ' GROUP BY ct.from_user_id, u.full_name, r.code';
        $st = $this->database->pdo()->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $merge($scores, (int) $row['uid'], (string) $row['fn'], (string) $row['rc'], (float) $row['c']);
        }

        $sql = 'SELECT ksr.responded_by AS uid, u.full_name AS fn, COALESCE(r.code, "") AS rc, COUNT(*) AS c
                FROM kitchen_stock_requests ksr
                INNER JOIN users u ON u.id = ksr.responded_by
                LEFT JOIN roles r ON r.id = u.role_id
                WHERE ksr.restaurant_id = :rid AND ksr.responded_at IS NOT NULL
                  AND ksr.responded_at >= :st AND ksr.responded_at < :en';
        $params = ['rid' => $restaurantId, 'st' => $s, 'en' => $e];
        if ($userFilter > 0) {
            $sql .= ' AND ksr.responded_by = :uidfil';
            $params['uidfil'] = $userFilter;
        }
        if ($roleFilter !== '') {
            $sql .= ' AND r.code = :rolec';
            $params['rolec'] = $roleFilter;
        }
        $sql .= ' GROUP BY ksr.responded_by, u.full_name, r.code';
        $st = $this->database->pdo()->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $merge($scores, (int) $row['uid'], (string) $row['fn'], (string) $row['rc'], (float) $row['c']);
        }

        $rows = array_values($scores);
        $maxScore = 0.0;
        foreach ($rows as $row) {
            $maxScore = max($maxScore, (float) ($row['score'] ?? 0));
        }
        $maxScore = $maxScore > 0 ? $maxScore : 1.0;
        $agents = [];
        $sumPct = 0;
        foreach ($rows as $row) {
            $pct = (int) round(100.0 * (float) ($row['score'] ?? 0) / $maxScore);
            $sumPct += $pct;
            $agents[] = [
                'user_id' => (int) ($row['user_id'] ?? 0),
                'full_name' => (string) ($row['full_name'] ?? ''),
                'role_code' => (string) ($row['role_code'] ?? ''),
                'raw_score' => (float) ($row['score'] ?? 0),
                'activity_percent' => min(100, $pct),
            ];
        }
        usort($agents, static fn (array $a, array $b): int => ($b['activity_percent'] ?? 0) <=> ($a['activity_percent'] ?? 0));
        $globalPercent = count($agents) > 0 ? (int) round($sumPct / count($agents)) : 0;

        return ['global_percent' => min(100, $globalPercent), 'agents' => $agents];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function nominativeTimeline(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt, array $viewFilters): array
    {
        $scope = (string) ($viewFilters['action_scope'] ?? 'all');
        $moduleClause = match ($scope) {
            'sales' => ' AND al.module_name = "sales"',
            'cash' => ' AND al.module_name = "cash"',
            'stock' => ' AND al.module_name = "stock"',
            'kitchen' => ' AND al.module_name = "kitchen"',
            default => '',
        };
        $actionFilter = trim((string) ($viewFilters['action_name'] ?? ''));
        $params = [
            'restaurant_id' => $restaurantId,
            'start_at' => $startAt->format('Y-m-d H:i:s'),
            'end_at' => $endAt->format('Y-m-d H:i:s'),
        ];
        $sql = 'SELECT al.id, al.created_at, al.actor_name, al.actor_role_code, al.module_name, al.action_name,
                       al.entity_type, al.entity_id, al.new_values_json, al.justification, al.user_id
                FROM audit_logs al
                WHERE al.restaurant_id = :restaurant_id
                  AND al.created_at >= :start_at AND al.created_at < :end_at';
        $sql .= $moduleClause;
        if ((int) ($viewFilters['user_id'] ?? 0) > 0) {
            $sql .= ' AND al.user_id = :uid';
            $params['uid'] = (int) $viewFilters['user_id'];
        }
        if (trim((string) ($viewFilters['role_code'] ?? '')) !== '') {
            $sql .= ' AND al.actor_role_code = :rc';
            $params['rc'] = (string) $viewFilters['role_code'];
        }
        if ($actionFilter !== '') {
            $sql .= ' AND al.action_name = :an';
            $params['an'] = $actionFilter;
        }
        if (!empty($viewFilters['closed_sales_only'])) {
            $sql .= ' AND (al.module_name IS NULL OR al.module_name != "sales")';
        }
        $sql .= ' ORDER BY al.id DESC LIMIT 320';
        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($params);
        $auditRows = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($auditRows as &$r) {
            $r['_source'] = 'audit';
        }
        unset($r);

        $saleRows = $this->saleClosureTimelineRows($restaurantId, $startAt, $endAt, $viewFilters);
        $merged = array_merge($saleRows, $auditRows);
        usort($merged, static function (array $a, array $b): int {
            return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
        });

        return array_slice($merged, 0, 350);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function saleClosureTimelineRows(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt, array $viewFilters): array
    {
        if (in_array($viewFilters['action_scope'] ?? 'all', ['cash', 'stock', 'kitchen'], true)) {
            return [];
        }
        if (trim((string) ($viewFilters['action_name'] ?? '')) !== '') {
            $want = (string) $viewFilters['action_name'];
            if ($want !== 'sale_closed') {
                return [];
            }
        }
        $extra = '';
        if ((int) ($viewFilters['user_id'] ?? 0) > 0) {
            $extra .= ' AND s.server_id = ' . (int) $viewFilters['user_id'];
        }
        if (trim((string) ($viewFilters['role_code'] ?? '')) !== '') {
            $extra .= ' AND r.code = ' . $this->database->pdo()->quote((string) $viewFilters['role_code']);
        }
        $statement = $this->database->pdo()->prepare(
            'SELECT s.id AS sale_id, s.validated_at AS created_at, s.total_amount,
                    u.full_name AS actor_name, COALESCE(r.code, "cashier_server") AS actor_role_code, u.id AS user_id,
                    mi.name AS menu_item_name, si.quantity AS item_quantity
             FROM sales s
             INNER JOIN users u ON u.id = s.server_id
             LEFT JOIN roles r ON r.id = u.role_id
             LEFT JOIN sale_items si ON si.sale_id = s.id
             LEFT JOIN menu_items mi ON mi.id = si.menu_item_id
             WHERE s.restaurant_id = :restaurant_id
               AND s.validated_at IS NOT NULL
               AND s.validated_at >= :start_at AND s.validated_at < :end_at
               AND s.status IN ("VALIDE","CLOTURE","VENDU_TOTAL","VENDU_PARTIEL")' . $extra . '
             ORDER BY s.validated_at DESC, s.id ASC, si.id ASC'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'start_at' => $startAt->format('Y-m-d H:i:s'),
            'end_at' => $endAt->format('Y-m-d H:i:s'),
        ]);
        $raw = $statement->fetchAll(PDO::FETCH_ASSOC);
        $bySale = [];
        foreach ($raw as $row) {
            $sid = (int) $row['sale_id'];
            if (!isset($bySale[$sid])) {
                $bySale[$sid] = [
                    'created_at' => $row['created_at'],
                    'actor_name' => $row['actor_name'],
                    'actor_role_code' => $row['actor_role_code'],
                    'user_id' => $row['user_id'],
                    'total_amount' => (float) ($row['total_amount'] ?? 0),
                    'items' => [],
                ];
            }
            $name = trim((string) ($row['menu_item_name'] ?? ''));
            if ($name !== '') {
                $bySale[$sid]['items'][] = [
                    'name' => $name,
                    'quantity' => (float) ($row['item_quantity'] ?? 0),
                ];
            }
        }
        $out = [];
        foreach ($bySale as $sid => $bundle) {
            $parts = [];
            foreach ($bundle['items'] as $it) {
                $q = $it['quantity'];
                $qStr = abs($q - round($q)) < 0.001 ? (string) (int) round($q) : (string) $q;
                $parts[] = $it['name'] . ' x' . $qStr;
            }
            $detail = $parts !== [] ? implode(', ', $parts) : ('Total ' . (string) $bundle['total_amount']);
            $out[] = [
                'id' => 'sale-' . (string) $sid,
                'created_at' => $bundle['created_at'],
                'actor_name' => $bundle['actor_name'],
                'actor_role_code' => $bundle['actor_role_code'],
                'module_name' => 'sales',
                'action_name' => 'sale_closed',
                'entity_type' => 'sale',
                'entity_id' => (string) $sid,
                'new_values_json' => null,
                'justification' => null,
                'user_id' => $bundle['user_id'],
                'timeline_detail' => $detail,
                'line_amount' => $bundle['total_amount'],
                '_source' => 'sale_closure',
            ];
        }

        return $out;
    }

    private function alertRules(): array
    {
        $statement = $this->database->pdo()->prepare('SELECT setting_value FROM settings WHERE restaurant_id IS NULL AND setting_key = "global_alert_rules_json" LIMIT 1');
        $statement->execute(); $value = $statement->fetchColumn(); $decoded = is_string($value) ? json_decode($value, true) : null;
        return is_array($decoded) ? $decoded : ['server_incident_threshold' => 3, 'kitchen_loss_threshold' => 2, 'repeated_inconsistency_threshold' => 2, 'frequent_return_threshold' => 3];
    }
    private function scalar(string $sql, array $params): float|int|string { $statement = $this->database->pdo()->prepare($sql); $statement->execute($params); return $statement->fetchColumn() ?: 0; }
}
