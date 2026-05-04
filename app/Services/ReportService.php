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
    public function dailyReport(int $restaurantId, string $date, string $period = 'daily'): array { return $this->reportForPeriod($restaurantId, $date, $period); }
    public function todayForRestaurant(int $restaurantId): string { return (new DateTimeImmutable('now', $this->reportTimezone($restaurantId)))->format('Y-m-d'); }
    public function reportForPeriod(int $restaurantId, string $date, string $period): array
    {
        $timezone = $this->reportTimezone($restaurantId);
        $selectedDate = $this->normalizeDate($date, $timezone);
        [$startAt, $endAt, $label] = $this->periodBounds($selectedDate, $period, $timezone);
        $displayEndAt = $endAt->sub(new DateInterval('PT1S'));
        $currentStock = (float) $this->scalar('SELECT COALESCE(SUM(quantity_in_stock), 0) FROM stock_items WHERE restaurant_id = :restaurant_id', ['restaurant_id' => $restaurantId]);
        $summary = ['period' => $period, 'period_label' => $label, 'selected_date' => $selectedDate->format('Y-m-d'), 'timezone' => $timezone->getName(), 'range_start' => $startAt->format('Y-m-d H:i:s'), 'range_end' => $displayEndAt->format('Y-m-d H:i:s'), 'opening_stock_total' => $this->openingStock($restaurantId, $startAt, $currentStock), 'current_stock_total' => $currentStock, 'kitchen_outputs' => $this->sumMovement($restaurantId, $startAt, $endAt, 'SORTIE_CUISINE'), 'stock_returns' => $this->sumMovement($restaurantId, $startAt, $endAt, 'RETOUR_STOCK'), 'kitchen_production' => $this->sumProduction($restaurantId, $startAt, $endAt), 'stock_report' => $this->stockReport($restaurantId, $startAt, $endAt), 'kitchen_report' => $this->kitchenReport($restaurantId, $startAt, $endAt), 'server_report' => $this->serverReport($restaurantId, $startAt, $endAt), 'financial_report' => $this->financialReport($restaurantId, $startAt, $endAt), 'product_margins' => $this->productMargins($restaurantId, $startAt, $endAt), 'sales_by_server' => $this->salesByServer($restaurantId, $startAt, $endAt), 'sales_by_type' => $this->salesByType($restaurantId, $startAt, $endAt), 'material_losses' => $this->sumLosses($restaurantId, $startAt, $endAt, 'MATIERE_PREMIERE'), 'financial_losses' => $this->sumLosses($restaurantId, $startAt, $endAt, 'ARGENT'), 'dish_yields' => $this->dishYields($restaurantId, $startAt, $endAt), 'product_issues' => $this->productIssues($restaurantId, $startAt, $endAt), 'incident_statuses' => $this->incidentsByField($restaurantId, $startAt, $endAt, 'status'), 'incident_qualifications' => $this->incidentsByField($restaurantId, $startAt, $endAt, 'final_qualification'), 'incident_responsibilities' => $this->incidentsByField($restaurantId, $startAt, $endAt, 'responsibility_scope'), 'incident_cases' => $this->incidentCases($restaurantId, $startAt, $endAt), 'fraud_alerts' => $this->fraudAlerts($restaurantId, $startAt, $endAt)];
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
    private function financialReport(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt): array
    {
        $transfers = Container::getInstance()->get('cashService')->dashboard($restaurantId, [
            'date_from' => $startAt->format('Y-m-d'),
            'date_to' => $endAt->sub(new DateInterval('PT1S'))->format('Y-m-d'),
        ]);

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
    private function salesByServer(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt): array
    {
        $statement = $this->database->pdo()->prepare('SELECT COALESCE(u.full_name, "Vente automatique") AS server_name, COUNT(s.id) AS sales_count, COALESCE(SUM(s.total_amount), 0) AS total_amount FROM sales s LEFT JOIN users u ON u.id = s.server_id WHERE s.restaurant_id = :restaurant_id AND COALESCE(s.validated_at, s.created_at) >= :start_at AND COALESCE(s.validated_at, s.created_at) < :end_at GROUP BY COALESCE(u.full_name, "Vente automatique") ORDER BY total_amount DESC');
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
    private function alertRules(): array
    {
        $statement = $this->database->pdo()->prepare('SELECT setting_value FROM settings WHERE restaurant_id IS NULL AND setting_key = "global_alert_rules_json" LIMIT 1');
        $statement->execute(); $value = $statement->fetchColumn(); $decoded = is_string($value) ? json_decode($value, true) : null;
        return is_array($decoded) ? $decoded : ['server_incident_threshold' => 3, 'kitchen_loss_threshold' => 2, 'repeated_inconsistency_threshold' => 2, 'frequent_return_threshold' => 3];
    }
    private function scalar(string $sql, array $params): float|int|string { $statement = $this->database->pdo()->prepare($sql); $statement->execute($params); return $statement->fetchColumn() ?: 0; }
}
