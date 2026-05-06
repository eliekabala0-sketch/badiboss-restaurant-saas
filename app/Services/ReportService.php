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

    /**
     * Aperçu compact pour le tableau de bord /owner (jour courant uniquement, sans charger tout le rapport).
     *
     * @return array<string, mixed>
     */
    public function reportDetailSummaryForDashboard(int $restaurantId): array
    {
        $timezone = $this->reportTimezone($restaurantId);
        $selectedDate = $this->normalizeDate($this->todayForRestaurant($restaurantId), $timezone);
        [$startAt, $endAt, $label] = $this->periodBounds($selectedDate, 'daily', $timezone);
        $empty = [];
        $sales = $this->salesDetailByServerProduct($restaurantId, $startAt, $endAt, false, 0, $empty);
        $kitchen = $this->kitchenDetailByCook($restaurantId, $startAt, $endAt, 0, $empty);
        $stock = $this->stockDetailByPerson($restaurantId, $startAt, $endAt, 0, $empty);

        $activity = $this->activityIndex($restaurantId, $startAt, $endAt, []);

        $autoClosedToday = $this->autoClosedServerRequestAudits($restaurantId, $startAt, $endAt);
        $execToday = $this->executiveSummaryRollup($restaurantId, $startAt, $endAt, $label, $empty, $sales, $activity);

        [$weekStart, $weekEnd, $weekLabel] = $this->periodBounds($selectedDate, 'weekly', $timezone);
        $salesWeek = $this->salesDetailByServerProduct($restaurantId, $weekStart, $weekEnd, false, 0, $empty);
        $activityWeek = $this->activityIndex($restaurantId, $weekStart, $weekEnd, []);
        $autoClosedWeek = $this->autoClosedServerRequestAudits($restaurantId, $weekStart, $weekEnd);
        $execWeek = $this->executiveSummaryRollup($restaurantId, $weekStart, $weekEnd, $weekLabel, $empty, $salesWeek, $activityWeek);

        return [
            'date' => $selectedDate->format('Y-m-d'),
            'period_label' => $label,
            'week_period_label' => $weekLabel,
            'sales_grand_total' => (float) ($sales['grand_total'] ?? 0),
            'sales_server_count' => count($sales['servers'] ?? []),
            'kitchen_grand_qty' => (float) ($kitchen['grand_total_qty'] ?? 0),
            'kitchen_grand_value' => (float) ($kitchen['grand_total_value'] ?? 0),
            'kitchen_cook_count' => count($kitchen['cooks'] ?? []),
            'stock_grand_movements' => (int) ($stock['grand_total_movements'] ?? 0),
            'stock_people_count' => count($stock['people'] ?? []),
            'activity_index' => $activity,
            'auto_closed_count_today' => count($autoClosedToday),
            'auto_closed_count_week' => count($autoClosedWeek),
            'vente_exec_summary_today' => $execToday,
            'vente_exec_summary_week' => $execWeek,
            'sales_grand_total_week' => (float) ($salesWeek['grand_total'] ?? 0),
        ];
    }

    public function reportForPeriod(int $restaurantId, string $date, string $period, array $viewFilters = []): array
    {
        $timezone = $this->reportTimezone($restaurantId);
        $selectedDate = $this->normalizeDate($date, $timezone);
        [$startAt, $endAt, $label] = $this->periodBounds($selectedDate, $period, $timezone);
        $displayEndAt = $endAt->sub(new DateInterval('PT1S'));
        $currentStock = Container::getInstance()->get('stockService')->sumActiveStockQuantity($restaurantId);
        $closedOnly = (bool) ($viewFilters['closed_sales_only'] ?? false);
        $userId = (int) ($viewFilters['user_id'] ?? 0);
        $salesByServer = $this->salesByServer($restaurantId, $startAt, $endAt, $closedOnly, $userId);
        $reportActivityIndex = $this->activityIndex($restaurantId, $startAt, $endAt, $viewFilters);
        $reportSalesDetailByServer = $this->salesDetailByServerProduct($restaurantId, $startAt, $endAt, $closedOnly, $userId, $viewFilters);
        $reportExecutiveSummary = $this->executiveSummaryRollup($restaurantId, $startAt, $endAt, $label, $viewFilters, $reportSalesDetailByServer, $reportActivityIndex);
        $summary = ['period' => $period, 'period_label' => $label, 'selected_date' => $selectedDate->format('Y-m-d'), 'timezone' => $timezone->getName(), 'range_start' => $startAt->format('Y-m-d H:i:s'), 'range_end' => $displayEndAt->format('Y-m-d H:i:s'), 'opening_stock_total' => $this->openingStock($restaurantId, $startAt, $currentStock), 'current_stock_total' => $currentStock, 'kitchen_outputs' => $this->sumMovement($restaurantId, $startAt, $endAt, 'SORTIE_CUISINE'), 'stock_returns' => $this->sumMovement($restaurantId, $startAt, $endAt, 'RETOUR_STOCK'), 'kitchen_production' => $this->sumProduction($restaurantId, $startAt, $endAt), 'stock_report' => $this->stockReport($restaurantId, $startAt, $endAt), 'kitchen_report' => $this->kitchenReport($restaurantId, $startAt, $endAt), 'server_report' => $this->serverReport($restaurantId, $startAt, $endAt), 'financial_report' => $this->financialReport($restaurantId, $startAt, $endAt, $displayEndAt), 'product_margins' => $this->productMargins($restaurantId, $startAt, $endAt), 'sales_by_server' => $salesByServer, 'sales_by_type' => $this->salesByType($restaurantId, $startAt, $endAt), 'material_losses' => $this->sumLosses($restaurantId, $startAt, $endAt, 'MATIERE_PREMIERE'), 'financial_losses' => $this->sumLosses($restaurantId, $startAt, $endAt, 'ARGENT'), 'dish_yields' => $this->dishYields($restaurantId, $startAt, $endAt), 'product_issues' => $this->productIssues($restaurantId, $startAt, $endAt), 'incident_statuses' => $this->incidentsByField($restaurantId, $startAt, $endAt, 'status'), 'incident_qualifications' => $this->incidentsByField($restaurantId, $startAt, $endAt, 'final_qualification'), 'incident_responsibilities' => $this->incidentsByField($restaurantId, $startAt, $endAt, 'responsibility_scope'), 'incident_cases' => $this->incidentCases($restaurantId, $startAt, $endAt), 'fraud_alerts' => $this->fraudAlerts($restaurantId, $startAt, $endAt), 'view_filters' => $viewFilters, 'people_overview' => $this->peopleOverview($restaurantId, $startAt, $endAt, $userId, $salesByServer, $viewFilters), 'activity_index' => $reportActivityIndex, 'nominative_timeline' => $this->nominativeTimeline($restaurantId, $startAt, $endAt, $viewFilters), 'sales_detail_by_server' => $reportSalesDetailByServer, 'executive_summary' => $reportExecutiveSummary, 'kitchen_detail_by_cook' => $this->kitchenDetailByCook($restaurantId, $startAt, $endAt, $userId, $viewFilters), 'stock_detail_by_person' => $this->stockDetailByPerson($restaurantId, $startAt, $endAt, $userId, $viewFilters)];
        $salesTotal = 0.0; foreach ($summary['sales_by_type'] as $row) { $salesTotal += (float) $row['total_amount']; }
        $summary['general_report'] = ['total_product_value' => (float) $summary['kitchen_report']['value_produced'], 'total_sold_value' => $salesTotal, 'real_material_cost_value' => (float) $summary['kitchen_report']['real_material_cost_of_sales'], 'total_losses_value' => (float) $summary['stock_report']['stock_losses_value'] + (float) $summary['kitchen_report']['kitchen_losses_value'] + (float) $summary['server_report']['server_loss_value'] + (float) $summary['financial_losses'], 'stock_loss_value' => (float) $summary['stock_report']['stock_losses_value'], 'kitchen_loss_value' => (float) $summary['kitchen_report']['kitchen_losses_value'], 'server_loss_value' => (float) $summary['server_report']['server_loss_value']];
        $summary['estimated_profit'] = $salesTotal - (float) $summary['kitchen_report']['real_material_cost_of_sales'] - (float) $summary['stock_report']['stock_losses_value'] - (float) $summary['kitchen_report']['kitchen_losses_value'] - (float) $summary['server_report']['server_loss_value'] - (float) $summary['financial_losses'];
        $summary['general_report']['estimated_gross_profit'] = (float) $summary['estimated_profit'];
        $summary['auto_closed_operations'] = $this->autoClosedServerRequestAudits($restaurantId, $startAt, $endAt);
        return $summary;
    }

    /**
     * Journal : clôtures automatiques (passage minuit, conversions, etc.) sur la même plage que le rapport.
     *
     * @return list<array<string, mixed>>
     */
    public function autoClosedServerRequestAudits(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt): array
    {
        $actions = [
            'server_request_auto_closed_as_sale',
            'automatic_sale_after_24h',
            'cash_cashier_auto_received',
            'kitchen_stock_request_expired_midnight',
            'kitchen_stock_request_auto_received',
        ];
        $inList = implode(',', array_fill(0, count($actions), '?'));
        $statement = $this->database->pdo()->prepare(
            "SELECT id, created_at, actor_name, actor_role_code, entity_id, action_name, new_values_json, justification
             FROM audit_logs
             WHERE restaurant_id = ?
               AND action_name IN ($inList)
               AND created_at >= ? AND created_at < ?
             ORDER BY id DESC
             LIMIT 120"
        );
        $params = array_merge([$restaurantId], $actions, [
            $startAt->format('Y-m-d H:i:s'),
            $endAt->format('Y-m-d H:i:s'),
        ]);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
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
    private function currentStockValue(int $restaurantId): float { return Container::getInstance()->get('stockService')->sumActiveStockValue($restaurantId); }
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

        $totMov = 0;
        foreach ($stock as $row) {
            $totMov += (int) ($row['movements_total'] ?? 0);
        }

        foreach ($salesByServer as &$sRow) {
            $sRow['pct_of_sales_amount'] = $totSalesAmount <= 0.0 ? 0.0 : round(100.0 * (float) ($sRow['total_amount'] ?? 0) / $totSalesAmount, 2);
            $sRow['pct_of_sales_count'] = $totSalesCount <= 0 ? 0.0 : round(100.0 * (int) ($sRow['sales_count'] ?? 0) / $totSalesCount, 2);
        }
        unset($sRow);

        foreach ($kitchen as &$kRow) {
            $kRow['pct_of_plates'] = $totPlates <= 0.0 ? 0.0 : round(100.0 * (float) ($kRow['plates_prepared'] ?? 0) / $totPlates, 2);
        }
        unset($kRow);

        foreach ($stock as &$stRow) {
            $mt = (int) ($stRow['movements_total'] ?? 0);
            $stRow['pct_of_movements'] = $totMov <= 0 ? 0.0 : round(100.0 * $mt / $totMov, 2);
        }
        unset($stRow);

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
                'stock_movements_lines' => $totMov,
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
     * Activité nominative : actions comptées par rôle (serveur, cuisine, stock, caisse) puis % sur le total général.
     *
     * @return array{
     *   global_percent: int,
     *   grand_total_actions: float,
     *   agents: list<array<string, mixed>>,
     *   total_raw_score: float
     * }
     */
    private function activityIndex(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt, array $viewFilters): array
    {
        $s = $startAt->format('Y-m-d H:i:s');
        $e = $endAt->format('Y-m-d H:i:s');
        $roleFilter = trim((string) ($viewFilters['role_code'] ?? ''));
        $userFilter = (int) ($viewFilters['user_id'] ?? 0);
        $closedStatuses = ['VALIDE', 'CLOTURE', 'VENDU_TOTAL', 'VENDU_PARTIEL'];
        $inStatus = implode(',', array_map(static fn (string $st): string => '"' . $st . '"', $closedStatuses));

        /** @var array<int, array<string, mixed>> $bucket */
        $bucket = [];
        $ensure = static function (int $uid, string $fn, string $rc) use (&$bucket): void {
            if ($uid <= 0) {
                return;
            }
            if (!isset($bucket[$uid])) {
                $bucket[$uid] = [
                    'user_id' => $uid,
                    'full_name' => $fn,
                    'role_code' => $rc,
                    'server_actions' => 0.0,
                    'kitchen_actions' => 0.0,
                    'stock_actions' => 0.0,
                    'cash_actions' => 0.0,
                ];
            }
        };
        $addLane = static function (string $lane, int $uid, string $fn, string $rc, float $c) use (&$bucket, $ensure): void {
            if ($uid <= 0 || $c <= 0) {
                return;
            }
            $ensure($uid, $fn, $rc);
            $bucket[$uid][$lane] += $c;
        };

        $run = function (string $sql, array $params, string $lane) use ($addLane): void {
            $st = $this->database->pdo()->prepare($sql);
            $st->execute($params);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $addLane($lane, (int) ($row['uid'] ?? 0), (string) ($row['fn'] ?? ''), (string) ($row['rc'] ?? ''), (float) ($row['c'] ?? 0));
            }
        };

        $base = ['rid' => $restaurantId, 's' => $s, 'e' => $e];
        if ($userFilter > 0) {
            $base['uidfil'] = $userFilter;
        }
        if ($roleFilter !== '') {
            $base['rolec'] = $roleFilter;
        }

        $run(
            'SELECT sr.server_id AS uid, u.full_name AS fn, COALESCE(r.code, "") AS rc, COUNT(*) AS c
             FROM server_requests sr
             INNER JOIN users u ON u.id = sr.server_id
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE sr.restaurant_id = :rid AND sr.created_at >= :s AND sr.created_at < :e
               AND sr.status NOT IN ("ANNULE", "REFUSE_CUISINE")'
            . ($userFilter > 0 ? ' AND sr.server_id = :uidfil' : '')
            . ($roleFilter !== '' ? ' AND r.code = :rolec' : '')
            . ' GROUP BY sr.server_id, u.full_name, r.code',
            $base,
            'server_actions',
        );

        $run(
            'SELECT s.server_id AS uid, u.full_name AS fn, COALESCE(r.code, "") AS rc, COUNT(*) AS c
             FROM sales s
             INNER JOIN users u ON u.id = s.server_id
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE s.restaurant_id = :rid AND s.status IN (' . $inStatus . ')
               AND COALESCE(s.validated_at, s.created_at) >= :s AND COALESCE(s.validated_at, s.created_at) < :e'
            . ($userFilter > 0 ? ' AND s.server_id = :uidfil' : '')
            . ($roleFilter !== '' ? ' AND r.code = :rolec' : '')
            . ' GROUP BY s.server_id, u.full_name, r.code',
            $base,
            'server_actions',
        );

        $run(
            'SELECT ct.from_user_id AS uid, u.full_name AS fn, COALESCE(r.code, "") AS rc, COUNT(*) AS c
             FROM cash_transfers ct
             INNER JOIN users u ON u.id = ct.from_user_id
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE ct.restaurant_id = :rid AND ct.source_type = "sale"
               AND COALESCE(ct.received_at, ct.requested_at, ct.created_at) >= :s
               AND COALESCE(ct.received_at, ct.requested_at, ct.created_at) < :e'
            . ($userFilter > 0 ? ' AND ct.from_user_id = :uidfil' : '')
            . ($roleFilter !== '' ? ' AND r.code = :rolec' : '')
            . ' GROUP BY ct.from_user_id, u.full_name, r.code',
            $base,
            'server_actions',
        );

        $run(
            'SELECT al.user_id AS uid, u.full_name AS fn, COALESCE(r.code, "") AS rc, COUNT(*) AS c
             FROM audit_logs al
             INNER JOIN users u ON u.id = al.user_id
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE al.restaurant_id = :rid AND al.module_name = "kitchen" AND al.action_name = "server_request_item_fulfilled"
               AND al.created_at >= :s AND al.created_at < :e'
            . ($userFilter > 0 ? ' AND al.user_id = :uidfil' : '')
            . ($roleFilter !== '' ? ' AND r.code = :rolec' : '')
            . ' GROUP BY al.user_id, u.full_name, r.code',
            $base,
            'kitchen_actions',
        );

        $run(
            'SELECT kp.created_by AS uid, u.full_name AS fn, COALESCE(r.code, "") AS rc, COUNT(*) AS c
             FROM kitchen_production kp
             INNER JOIN users u ON u.id = kp.created_by
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE kp.restaurant_id = :rid AND kp.created_at >= :s AND kp.created_at < :e'
            . ($userFilter > 0 ? ' AND kp.created_by = :uidfil' : '')
            . ($roleFilter !== '' ? ' AND r.code = :rolec' : '')
            . ' GROUP BY kp.created_by, u.full_name, r.code',
            $base,
            'kitchen_actions',
        );

        $run(
            'SELECT al.user_id AS uid, u.full_name AS fn, COALESCE(r.code, "") AS rc, COUNT(*) AS c
             FROM audit_logs al
             INNER JOIN users u ON u.id = al.user_id
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE al.restaurant_id = :rid AND al.module_name = "kitchen"
               AND al.action_name NOT IN ("server_request_item_fulfilled", "kitchen_production_created")
               AND al.created_at >= :s AND al.created_at < :e'
            . ($userFilter > 0 ? ' AND al.user_id = :uidfil' : '')
            . ($roleFilter !== '' ? ' AND r.code = :rolec' : '')
            . ' GROUP BY al.user_id, u.full_name, r.code',
            $base,
            'kitchen_actions',
        );

        $run(
            'SELECT sm.user_id AS uid, u.full_name AS fn, COALESCE(r.code, "") AS rc, COUNT(*) AS c
             FROM stock_movements sm
             INNER JOIN users u ON u.id = sm.user_id
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE sm.restaurant_id = :rid AND sm.status = "VALIDE"
               AND sm.created_at >= :s AND sm.created_at < :e'
            . ($userFilter > 0 ? ' AND sm.user_id = :uidfil' : '')
            . ($roleFilter !== '' ? ' AND r.code = :rolec' : '')
            . ' GROUP BY sm.user_id, u.full_name, r.code',
            $base,
            'stock_actions',
        );

        $run(
            'SELECT ksr.responded_by AS uid, u.full_name AS fn, COALESCE(r.code, "") AS rc, COUNT(*) AS c
             FROM kitchen_stock_requests ksr
             INNER JOIN users u ON u.id = ksr.responded_by
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE ksr.restaurant_id = :rid AND ksr.responded_at IS NOT NULL
               AND ksr.responded_at >= :s AND ksr.responded_at < :e'
            . ($userFilter > 0 ? ' AND ksr.responded_by = :uidfil' : '')
            . ($roleFilter !== '' ? ' AND r.code = :rolec' : '')
            . ' GROUP BY ksr.responded_by, u.full_name, r.code',
            $base,
            'stock_actions',
        );

        $run(
            'SELECT ct.received_by AS uid, u.full_name AS fn, COALESCE(r.code, "") AS rc, COUNT(*) AS c
             FROM cash_transfers ct
             INNER JOIN users u ON u.id = ct.received_by
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE ct.restaurant_id = :rid AND ct.received_at IS NOT NULL
               AND ct.received_at >= :s AND ct.received_at < :e
               AND ct.status IN ("RECU_CAISSE","RECU_GERANT","RECU_PROPRIETAIRE","ECART_SIGNALE")'
            . ($userFilter > 0 ? ' AND ct.received_by = :uidfil' : '')
            . ($roleFilter !== '' ? ' AND r.code = :rolec' : '')
            . ' GROUP BY ct.received_by, u.full_name, r.code',
            $base,
            'cash_actions',
        );

        $run(
            'SELECT ct.from_user_id AS uid, u.full_name AS fn, COALESCE(r.code, "") AS rc, COUNT(*) AS c
             FROM cash_transfers ct
             INNER JOIN users u ON u.id = ct.from_user_id
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE ct.restaurant_id = :rid AND ct.source_type IN ("REMISE_GERANT","REMISE_PROPRIETAIRE")
               AND COALESCE(ct.received_at, ct.requested_at, ct.created_at) >= :s
               AND COALESCE(ct.received_at, ct.requested_at, ct.created_at) < :e'
            . ($userFilter > 0 ? ' AND ct.from_user_id = :uidfil' : '')
            . ($roleFilter !== '' ? ' AND r.code = :rolec' : '')
            . ' GROUP BY ct.from_user_id, u.full_name, r.code',
            $base,
            'cash_actions',
        );

        $run(
            'SELECT cm.created_by AS uid, u.full_name AS fn, COALESCE(r.code, "") AS rc, COUNT(*) AS c
             FROM cash_movements cm
             INNER JOIN users u ON u.id = cm.created_by
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE cm.restaurant_id = :rid AND cm.movement_type = "DEPENSE"
               AND cm.created_at >= :s AND cm.created_at < :e'
            . ($userFilter > 0 ? ' AND cm.created_by = :uidfil' : '')
            . ($roleFilter !== '' ? ' AND r.code = :rolec' : '')
            . ' GROUP BY cm.created_by, u.full_name, r.code',
            $base,
            'cash_actions',
        );

        $agents = [];
        $grandTotal = 0.0;
        foreach ($bucket as $row) {
            $total = (float) $row['server_actions'] + (float) $row['kitchen_actions'] + (float) $row['stock_actions'] + (float) $row['cash_actions'];
            $grandTotal += $total;
            $agents[] = array_merge($row, ['total_actions' => $total]);
        }

        foreach ($agents as &$ag) {
            $t = (float) ($ag['total_actions'] ?? 0);
            $pct = $grandTotal <= 0.0 ? 0.0 : round(100.0 * $t / $grandTotal, 2);
            $ag['activity_percent'] = $pct;
            $ag['activity_share_percent'] = $pct;
            $ag['raw_score'] = $t;
        }
        unset($ag);

        usort($agents, static fn (array $a, array $b): int => ((float) ($b['total_actions'] ?? 0)) <=> ((float) ($a['total_actions'] ?? 0)));

        $globalPercent = $grandTotal > 0.0 ? 100 : 0;

        return [
            'global_percent' => $globalPercent,
            'grand_total_actions' => round($grandTotal, 2),
            'agents' => $agents,
            'total_raw_score' => round($grandTotal, 2),
        ];
    }

    /**
     * @return array<int, int> server_user_id => commandes créées
     */
    private function serverRequestCountsByServer(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt, array $viewFilters): array
    {
        $s = $startAt->format('Y-m-d H:i:s');
        $e = $endAt->format('Y-m-d H:i:s');
        $params = ['rid' => $restaurantId, 's' => $s, 'e' => $e];
        $extra = '';
        if ((int) ($viewFilters['user_id'] ?? 0) > 0) {
            $extra .= ' AND sr.server_id = :uid';
            $params['uid'] = (int) $viewFilters['user_id'];
        }
        if (trim((string) ($viewFilters['role_code'] ?? '')) !== '') {
            $extra .= ' AND r.code = :rolec';
            $params['rolec'] = (string) $viewFilters['role_code'];
        }
        $statement = $this->database->pdo()->prepare(
            'SELECT sr.server_id AS uid, COUNT(*) AS c
             FROM server_requests sr
             INNER JOIN users u ON u.id = sr.server_id
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE sr.restaurant_id = :rid AND sr.created_at >= :s AND sr.created_at < :e
               AND sr.status NOT IN ("ANNULE", "REFUSE_CUISINE")' . $extra . '
             GROUP BY sr.server_id'
        );
        $statement->execute($params);
        $out = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) ($row['uid'] ?? 0)] = (int) ($row['c'] ?? 0);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $salesDetail
     * @param array<string, mixed> $activityIndex
     *
     * @return array<string, mixed>
     */
    private function executiveSummaryRollup(
        int $restaurantId,
        DateTimeImmutable $startAt,
        DateTimeImmutable $endAt,
        string $periodLabel,
        array $viewFilters,
        array $salesDetail,
        array $activityIndex,
    ): array {
        $orderCounts = $this->serverRequestCountsByServer($restaurantId, $startAt, $endAt, $viewFilters);
        $agentsByUid = [];
        foreach (($activityIndex['agents'] ?? []) as $ag) {
            $agentsByUid[(int) ($ag['user_id'] ?? 0)] = $ag;
        }
        $byServer = [];
        foreach (($salesDetail['servers'] ?? []) as $srv) {
            $uid = (int) ($srv['server_user_id'] ?? 0);
            $articles = 0.0;
            foreach ($srv['lines'] ?? [] as $ln) {
                $articles += (float) ($ln['qty_sold'] ?? 0);
            }
            $ag = $agentsByUid[$uid] ?? null;
            $byServer[] = [
                'server_name' => (string) ($srv['server_name'] ?? ''),
                'server_user_id' => $uid,
                'orders_count' => (int) ($orderCounts[$uid] ?? 0),
                'articles_sold' => round($articles, 3),
                'total_sold' => round((float) ($srv['server_total'] ?? 0), 2),
                'activity_actions' => (int) round((float) ($ag['total_actions'] ?? $ag['raw_score'] ?? 0)),
                'activity_percent' => (float) ($ag['activity_percent'] ?? 0),
            ];
        }
        $byArticle = [];
        foreach (($salesDetail['servers'] ?? []) as $srv) {
            foreach ($srv['lines'] ?? [] as $ln) {
                $name = (string) ($ln['menu_item_name'] ?? '');
                if ($name === '') {
                    continue;
                }
                if (!isset($byArticle[$name])) {
                    $byArticle[$name] = ['article' => $name, 'qty_sold' => 0.0, 'total_sold' => 0.0];
                }
                $byArticle[$name]['qty_sold'] += (float) ($ln['qty_sold'] ?? 0);
                $byArticle[$name]['total_sold'] += (float) ($ln['line_total'] ?? 0);
            }
        }
        $articleList = array_values($byArticle);
        usort($articleList, static fn (array $a, array $b): int => ((float) ($b['total_sold'] ?? 0)) <=> ((float) ($a['total_sold'] ?? 0)));

        $itemsQty = 0.0;
        foreach ($articleList as $ar) {
            $itemsQty += (float) ($ar['qty_sold'] ?? 0);
        }

        return [
            'period_label' => $periodLabel,
            'by_server' => $byServer,
            'by_article' => $articleList,
            'totals' => [
                'grand_amount' => round((float) ($salesDetail['grand_total'] ?? 0), 2),
                'articles_units' => round($itemsQty, 3),
                'activity_pool_total' => (float) ($activityIndex['grand_total_actions'] ?? $activityIndex['total_raw_score'] ?? 0),
            ],
        ];
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

    /**
     * Ventes détaillées par serveur et par produit (carte/menu).
     *
     * @return array{servers: list<array<string, mixed>>, grand_total: float}
     */
    private function salesDetailByServerProduct(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt, bool $closedOnly, int $filterUserId, array $viewFilters): array
    {
        $menuItemId = (int) ($viewFilters['menu_item_id'] ?? 0);
        $roleCode = trim((string) ($viewFilters['role_code'] ?? ''));
        $extra = '';
        if ($closedOnly) {
            $extra .= ' AND s.status IN ("VALIDE","CLOTURE","VENDU_TOTAL","VENDU_PARTIEL")';
        }
        if ($filterUserId > 0) {
            $extra .= ' AND s.server_id = ' . $filterUserId;
        }
        if ($roleCode !== '') {
            $extra .= ' AND r.code = ' . $this->database->pdo()->quote($roleCode);
        }
        if ($menuItemId > 0) {
            $extra .= ' AND mi.id = ' . $menuItemId;
        }
        $statement = $this->database->pdo()->prepare(
            'SELECT COALESCE(u.full_name, "Vente automatique") AS server_name,
                    COALESCE(u.id, 0) AS server_user_id,
                    COALESCE(r.code, "") AS server_role_code,
                    mi.id AS menu_item_id,
                    mi.name AS menu_item_name,
                    COALESCE(SUM(si.quantity), 0) AS qty_sold,
                    COALESCE(SUM(si.quantity * si.unit_price), 0) AS line_total
             FROM sale_items si
             INNER JOIN sales s ON s.id = si.sale_id
             INNER JOIN menu_items mi ON mi.id = si.menu_item_id
             LEFT JOIN users u ON u.id = s.server_id
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE s.restaurant_id = :restaurant_id
               AND COALESCE(s.validated_at, s.created_at) >= :start_at
               AND COALESCE(s.validated_at, s.created_at) < :end_at' . $extra . '
             GROUP BY server_name, server_user_id, server_role_code, mi.id, mi.name
             ORDER BY server_name ASC, line_total DESC'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'start_at' => $startAt->format('Y-m-d H:i:s'),
            'end_at' => $endAt->format('Y-m-d H:i:s'),
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $byKey = [];
        $grand = 0.0;
        foreach ($rows as $row) {
            $sname = (string) ($row['server_name'] ?? '');
            if (!isset($byKey[$sname])) {
                $byKey[$sname] = [
                    'server_name' => $sname,
                    'server_user_id' => (int) ($row['server_user_id'] ?? 0),
                    'server_role_code' => (string) ($row['server_role_code'] ?? ''),
                    'lines' => [],
                    'server_total' => 0.0,
                ];
            }
            $lineTotal = (float) ($row['line_total'] ?? 0);
            $byKey[$sname]['lines'][] = [
                'menu_item_id' => (int) ($row['menu_item_id'] ?? 0),
                'menu_item_name' => (string) ($row['menu_item_name'] ?? ''),
                'qty_sold' => (float) ($row['qty_sold'] ?? 0),
                'line_total' => $lineTotal,
                'pct_of_server_sales' => 0.0,
            ];
            $byKey[$sname]['server_total'] += $lineTotal;
            $grand += $lineTotal;
        }
        $servers = array_values($byKey);
        foreach ($servers as &$srv) {
            $srvTotal = (float) ($srv['server_total'] ?? 0);
            $srv['pct_of_grand_total'] = $grand <= 0.0 ? 0.0 : round(100.0 * $srvTotal / $grand, 2);
            foreach ($srv['lines'] as &$ln) {
                $lt = (float) ($ln['line_total'] ?? 0);
                $ln['pct_of_server_sales'] = $srvTotal <= 0.0 ? 0.0 : round(100.0 * $lt / $srvTotal, 2);
            }
            unset($ln);
        }
        unset($srv);

        return ['servers' => $servers, 'grand_total' => round($grand, 2)];
    }

    /**
     * Production cuisine par cuisinier, avec matières liées au mouvement de stock si présent.
     *
     * @return array{cooks: list<array<string, mixed>>, grand_total_qty: float, grand_total_value: float}
     */
    private function kitchenDetailByCook(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt, int $filterUserId, array $viewFilters): array
    {
        $menuItemId = (int) ($viewFilters['menu_item_id'] ?? 0);
        $roleCode = trim((string) ($viewFilters['role_code'] ?? ''));
        $extra = '';
        if ($filterUserId > 0) {
            $extra .= ' AND kp.created_by = ' . $filterUserId;
        }
        if ($roleCode !== '') {
            $extra .= ' AND r.code = ' . $this->database->pdo()->quote($roleCode);
        }
        if ($menuItemId > 0) {
            $extra .= ' AND kp.menu_item_id = ' . $menuItemId;
        }
        $statement = $this->database->pdo()->prepare(
            'SELECT kp.created_by AS cook_id,
                    u.full_name AS cook_name,
                    COALESCE(r.code, "") AS role_code,
                    COALESCE(mi.name, kp.dish_type) AS dish_label,
                    COALESCE(SUM(kp.quantity_produced), 0) AS qty_produced,
                    COALESCE(SUM(kp.total_sale_value_snapshot), 0) AS value_produced,
                    COUNT(kp.id) AS batches
             FROM kitchen_production kp
             INNER JOIN users u ON u.id = kp.created_by
             LEFT JOIN roles r ON r.id = u.role_id
             LEFT JOIN menu_items mi ON mi.id = kp.menu_item_id
             WHERE kp.restaurant_id = :restaurant_id
               AND kp.created_at >= :start_at AND kp.created_at < :end_at' . $extra . '
             GROUP BY kp.created_by, u.full_name, r.code, dish_label
             ORDER BY u.full_name ASC, value_produced DESC'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'start_at' => $startAt->format('Y-m-d H:i:s'),
            'end_at' => $endAt->format('Y-m-d H:i:s'),
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        $matStatement = $this->database->pdo()->prepare(
            'SELECT kp.created_by AS cook_id, si.name AS material_name, COALESCE(SUM(sm.quantity), 0) AS material_qty
             FROM kitchen_production kp
             INNER JOIN users u ON u.id = kp.created_by
             LEFT JOIN roles r ON r.id = u.role_id
             INNER JOIN stock_movements sm ON sm.id = kp.stock_movement_id
             INNER JOIN stock_items si ON si.id = sm.stock_item_id
             WHERE kp.restaurant_id = :restaurant_id
               AND kp.created_at >= :start_at AND kp.created_at < :end_at' . $extra . '
             GROUP BY kp.created_by, si.id, si.name
             ORDER BY cook_id ASC, material_name ASC'
        );
        $matStatement->execute([
            'restaurant_id' => $restaurantId,
            'start_at' => $startAt->format('Y-m-d H:i:s'),
            'end_at' => $endAt->format('Y-m-d H:i:s'),
        ]);
        $matRows = $matStatement->fetchAll(PDO::FETCH_ASSOC);
        $materialsByCook = [];
        foreach ($matRows as $mr) {
            $cid = (int) $mr['cook_id'];
            if (!isset($materialsByCook[$cid])) {
                $materialsByCook[$cid] = [];
            }
            $materialsByCook[$cid][] = [
                'name' => (string) ($mr['material_name'] ?? ''),
                'quantity' => (float) ($mr['material_qty'] ?? 0),
                'pct_of_cook_material_qty' => 0.0,
            ];
        }

        $byCook = [];
        $grandQty = 0.0;
        $grandVal = 0.0;
        foreach ($rows as $row) {
            $cid = (int) ($row['cook_id'] ?? 0);
            if (!isset($byCook[$cid])) {
                $byCook[$cid] = [
                    'cook_id' => $cid,
                    'cook_name' => (string) ($row['cook_name'] ?? ''),
                    'role_code' => (string) ($row['role_code'] ?? ''),
                    'dishes' => [],
                    'materials' => $materialsByCook[$cid] ?? [],
                    'cook_total_qty' => 0.0,
                    'cook_total_value' => 0.0,
                    'batches' => 0,
                ];
            }
            $q = (float) ($row['qty_produced'] ?? 0);
            $v = (float) ($row['value_produced'] ?? 0);
            $b = (int) ($row['batches'] ?? 0);
            $byCook[$cid]['dishes'][] = [
                'dish_label' => (string) ($row['dish_label'] ?? ''),
                'qty_produced' => $q,
                'value_produced' => $v,
                'batches' => $b,
                'pct_of_cook_qty' => 0.0,
            ];
            $byCook[$cid]['cook_total_qty'] += $q;
            $byCook[$cid]['cook_total_value'] += $v;
            $byCook[$cid]['batches'] += $b;
            $grandQty += $q;
            $grandVal += $v;
        }

        foreach ($byCook as &$c) {
            $c['cook_total_qty'] = round((float) $c['cook_total_qty'], 2);
            $c['cook_total_value'] = round((float) $c['cook_total_value'], 2);
            $c['pct_of_kitchen_qty'] = $grandQty <= 0.0 ? 0.0 : round(100.0 * (float) $c['cook_total_qty'] / $grandQty, 2);
            $cookQty = (float) $c['cook_total_qty'];
            foreach ($c['dishes'] as &$dish) {
                $dq = (float) ($dish['qty_produced'] ?? 0);
                $dish['pct_of_cook_qty'] = $cookQty <= 0.0 ? 0.0 : round(100.0 * $dq / $cookQty, 2);
            }
            unset($dish);
            $matSum = 0.0;
            foreach ($c['materials'] as $mat) {
                $matSum += (float) ($mat['quantity'] ?? 0);
            }
            foreach ($c['materials'] as &$mat) {
                $mq = (float) ($mat['quantity'] ?? 0);
                $mat['pct_of_cook_material_qty'] = $matSum <= 0.0 ? 0.0 : round(100.0 * $mq / $matSum, 2);
            }
            unset($mat);
        }
        unset($c);

        return [
            'cooks' => array_values($byCook),
            'grand_total_qty' => round($grandQty, 2),
            'grand_total_value' => round($grandVal, 2),
        ];
    }

    /**
     * Stock : mouvements validés par responsable (entrées, sorties, pertes, retours) + lignes par produit.
     *
     * @return array{people: list<array<string, mixed>>, grand_total_movements: int}
     */
    private function stockDetailByPerson(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt, int $filterUserId, array $viewFilters): array
    {
        $stockItemId = (int) ($viewFilters['stock_item_id'] ?? 0);
        $roleCode = trim((string) ($viewFilters['role_code'] ?? ''));
        $movType = trim((string) ($viewFilters['stock_movement_type'] ?? ''));
        $allowedTypes = ['ENTREE', 'SORTIE_CUISINE', 'SORTIE', 'PERTE', 'RETOUR_STOCK', 'CONSOMMATION_CUISINE', 'CORRECTION_INVENTAIRE'];
        if ($movType !== '' && !in_array($movType, $allowedTypes, true)) {
            $movType = '';
        }
        $extra = '';
        if ($filterUserId > 0) {
            $extra .= ' AND sm.user_id = ' . $filterUserId;
        }
        if ($roleCode !== '') {
            $extra .= ' AND r.code = ' . $this->database->pdo()->quote($roleCode);
        }
        if ($stockItemId > 0) {
            $extra .= ' AND sm.stock_item_id = ' . $stockItemId;
        }
        if ($movType !== '') {
            $extra .= ' AND sm.movement_type = ' . $this->database->pdo()->quote($movType);
        }

        $statement = $this->database->pdo()->prepare(
            'SELECT sm.user_id, u.full_name, COALESCE(r.code, "") AS role_code, sm.movement_type,
                    COUNT(*) AS line_count,
                    COALESCE(SUM(sm.quantity), 0) AS qty_sum
             FROM stock_movements sm
             INNER JOIN users u ON u.id = sm.user_id
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE sm.restaurant_id = :restaurant_id
               AND sm.status = "VALIDE"
               AND sm.created_at >= :start_at AND sm.created_at < :end_at' . $extra . '
             GROUP BY sm.user_id, u.full_name, r.code, sm.movement_type
             ORDER BY u.full_name ASC, sm.movement_type ASC'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'start_at' => $startAt->format('Y-m-d H:i:s'),
            'end_at' => $endAt->format('Y-m-d H:i:s'),
        ]);
        $aggRows = $statement->fetchAll(PDO::FETCH_ASSOC);

        $lineStatement = $this->database->pdo()->prepare(
            'SELECT sm.user_id, si.name AS product_name, sm.movement_type,
                    COUNT(*) AS line_count,
                    COALESCE(SUM(sm.quantity), 0) AS qty_sum
             FROM stock_movements sm
             INNER JOIN stock_items si ON si.id = sm.stock_item_id
             INNER JOIN users u ON u.id = sm.user_id
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE sm.restaurant_id = :restaurant_id
               AND sm.status = "VALIDE"
               AND sm.created_at >= :start_at AND sm.created_at < :end_at' . $extra . '
             GROUP BY sm.user_id, si.id, si.name, sm.movement_type
             ORDER BY u.full_name ASC, product_name ASC'
        );
        $lineStatement->execute([
            'restaurant_id' => $restaurantId,
            'start_at' => $startAt->format('Y-m-d H:i:s'),
            'end_at' => $endAt->format('Y-m-d H:i:s'),
        ]);
        $lineRows = $lineStatement->fetchAll(PDO::FETCH_ASSOC);
        $linesByUser = [];
        foreach ($lineRows as $lr) {
            $uid = (int) ($lr['user_id'] ?? 0);
            if (!isset($linesByUser[$uid])) {
                $linesByUser[$uid] = [];
            }
            $linesByUser[$uid][] = [
                'product_name' => (string) ($lr['product_name'] ?? ''),
                'movement_type' => (string) ($lr['movement_type'] ?? ''),
                'line_count' => (int) ($lr['line_count'] ?? 0),
                'qty_sum' => (float) ($lr['qty_sum'] ?? 0),
                'pct_of_person_movements' => 0.0,
            ];
        }

        $byUser = [];
        $grandMov = 0;
        foreach ($aggRows as $row) {
            $uid = (int) ($row['user_id'] ?? 0);
            if (!isset($byUser[$uid])) {
                $byUser[$uid] = [
                    'user_id' => $uid,
                    'full_name' => (string) ($row['full_name'] ?? ''),
                    'role_code' => (string) ($row['role_code'] ?? ''),
                    'entrees_lines' => 0,
                    'sorties_lines' => 0,
                    'pertes_lines' => 0,
                    'retours_lines' => 0,
                    'autres_lines' => 0,
                    'entrees_qty' => 0.0,
                    'sorties_qty' => 0.0,
                    'pertes_qty' => 0.0,
                    'retours_qty' => 0.0,
                    'autres_qty' => 0.0,
                    'total_movements' => 0,
                    'product_lines' => $linesByUser[$uid] ?? [],
                ];
            }
            $type = (string) ($row['movement_type'] ?? '');
            $lc = (int) ($row['line_count'] ?? 0);
            $qs = (float) ($row['qty_sum'] ?? 0);
            $byUser[$uid]['total_movements'] += $lc;
            $grandMov += $lc;
            if ($type === 'ENTREE') {
                $byUser[$uid]['entrees_lines'] += $lc;
                $byUser[$uid]['entrees_qty'] += $qs;
            } elseif ($type === 'PERTE') {
                $byUser[$uid]['pertes_lines'] += $lc;
                $byUser[$uid]['pertes_qty'] += $qs;
            } elseif ($type === 'RETOUR_STOCK') {
                $byUser[$uid]['retours_lines'] += $lc;
                $byUser[$uid]['retours_qty'] += $qs;
            } elseif ($type === 'SORTIE_CUISINE' || $type === 'SORTIE' || $type === 'CONSOMMATION_CUISINE') {
                $byUser[$uid]['sorties_lines'] += $lc;
                $byUser[$uid]['sorties_qty'] += $qs;
            } else {
                $byUser[$uid]['autres_lines'] += $lc;
                $byUser[$uid]['autres_qty'] += $qs;
            }
        }

        foreach ($byUser as &$p) {
            foreach (['entrees_qty', 'sorties_qty', 'pertes_qty', 'retours_qty', 'autres_qty'] as $k) {
                $p[$k] = round((float) $p[$k], 2);
            }
            $tm = (int) ($p['total_movements'] ?? 0);
            $p['pct_of_global_movements'] = $grandMov <= 0 ? 0.0 : round(100.0 * $tm / $grandMov, 2);
            $p['pct_entrees'] = $tm <= 0 ? 0.0 : round(100.0 * (int) ($p['entrees_lines'] ?? 0) / $tm, 2);
            $p['pct_sorties'] = $tm <= 0 ? 0.0 : round(100.0 * (int) ($p['sorties_lines'] ?? 0) / $tm, 2);
            $p['pct_pertes'] = $tm <= 0 ? 0.0 : round(100.0 * (int) ($p['pertes_lines'] ?? 0) / $tm, 2);
            $p['pct_retours'] = $tm <= 0 ? 0.0 : round(100.0 * (int) ($p['retours_lines'] ?? 0) / $tm, 2);
            $p['pct_autres'] = $tm <= 0 ? 0.0 : round(100.0 * (int) ($p['autres_lines'] ?? 0) / $tm, 2);
            foreach ($p['product_lines'] as &$pl) {
                $lc = (int) ($pl['line_count'] ?? 0);
                $pl['pct_of_person_movements'] = $tm <= 0 ? 0.0 : round(100.0 * $lc / $tm, 2);
            }
            unset($pl);
        }
        unset($p);

        return ['people' => array_values($byUser), 'grand_total_movements' => $grandMov];
    }

    private function alertRules(): array
    {
        $statement = $this->database->pdo()->prepare('SELECT setting_value FROM settings WHERE restaurant_id IS NULL AND setting_key = "global_alert_rules_json" LIMIT 1');
        $statement->execute(); $value = $statement->fetchColumn(); $decoded = is_string($value) ? json_decode($value, true) : null;
        return is_array($decoded) ? $decoded : ['server_incident_threshold' => 3, 'kitchen_loss_threshold' => 2, 'repeated_inconsistency_threshold' => 2, 'frequent_return_threshold' => 3];
    }
    private function scalar(string $sql, array $params): float|int|string { $statement = $this->database->pdo()->prepare($sql); $statement->execute($params); return $statement->fetchColumn() ?: 0; }
}
