<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Container;
use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class SalesService
{
    private ?bool $serverRequestResponsibleOutcomeColumn = null;
    /** @var array<string, bool> */
    private array $serverRequestColumnExists = [];

    public function __construct(private readonly Database $database)
    {
    }

    public function listSales(int $restaurantId, ?int $serverId = null): array
    {
        $sql = 'SELECT s.*, u.full_name AS server_name, ' . sql_sale_activity_datetime_expr('s', 'sr') . ' AS sale_activity_at
                FROM sales s
                ' . sql_sale_activity_left_join_server_request('s', 'sr') . '
                LEFT JOIN users u ON u.id = s.server_id
                WHERE s.restaurant_id = :restaurant_id';
        $params = ['restaurant_id' => $restaurantId];

        if ($serverId !== null) {
            $sql .= ' AND s.server_id = :server_id';
            $params['server_id'] = $serverId;
        }

        $sql .= ' ORDER BY s.id DESC';
        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listSaleItemsForRestaurant(int $restaurantId, ?int $serverId = null): array
    {
        $sql = 'SELECT si.*, s.sale_type, s.status AS sale_status, mi.name AS menu_item_name, mi.image_url AS menu_item_image_url, u.full_name AS server_name
                FROM sale_items si
                INNER JOIN sales s ON s.id = si.sale_id
                INNER JOIN menu_items mi ON mi.id = si.menu_item_id
                LEFT JOIN users u ON u.id = s.server_id
                WHERE s.restaurant_id = :restaurant_id';
        $params = ['restaurant_id' => $restaurantId];

        if ($serverId !== null) {
            $sql .= ' AND s.server_id = :server_id';
            $params['server_id'] = $serverId;
        }

        $sql .= ' ORDER BY si.id DESC';
        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listSaleItemsForKitchen(int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT si.*, s.sale_type, s.status AS sale_status, mi.name AS menu_item_name, mi.image_url AS menu_item_image_url, u.full_name AS server_name
             FROM sale_items si
             INNER JOIN sales s ON s.id = si.sale_id
             INNER JOIN menu_items mi ON mi.id = si.menu_item_id
             LEFT JOIN users u ON u.id = s.server_id
             WHERE s.restaurant_id = :restaurant_id
               AND si.status = "SERVI"
               AND si.kitchen_production_id IS NOT NULL
               AND si.return_validated_by_kitchen IS NULL
             ORDER BY si.id DESC'
        );
        $statement->execute(['restaurant_id' => $restaurantId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listServerRequests(int $restaurantId, ?int $serverId = null): array
    {
        $resolutionBySelect = 'NULL AS resolution_by_name';
        $resolutionByJoin = '';
        if ($this->serverRequestColumnExists('resolution_by')) {
            $resolutionBySelect = 'resolution_user.full_name AS resolution_by_name';
            $resolutionByJoin = ' LEFT JOIN users resolution_user ON resolution_user.id = sr.resolution_by';
        }
        $sql = 'SELECT sr.*,
                       u.full_name AS server_name,
                       ready_user.full_name AS ready_by_name,
                       received_user.full_name AS received_by_name,
                       ' . $resolutionBySelect . '
                FROM server_requests sr
                INNER JOIN users u ON u.id = sr.server_id
                LEFT JOIN users ready_user ON ready_user.id = sr.ready_by
                LEFT JOIN users received_user ON received_user.id = sr.received_by
                ' . $resolutionByJoin . '
                WHERE sr.restaurant_id = :restaurant_id';
        $params = ['restaurant_id' => $restaurantId];

        if ($serverId !== null) {
            $sql .= ' AND sr.server_id = :server_id';
            $params['server_id'] = $serverId;
        }

        $sql .= ' ORDER BY sr.id DESC';
        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listServerRequestItems(int $restaurantId, ?int $serverId = null): array
    {
        $resolutionNoteSelect = 'NULL AS request_resolution_note';
        $resolutionAtSelect = 'NULL AS request_resolution_at';
        $resolutionBySelect = 'NULL AS resolution_by_name';
        $resolutionByJoin = '';
        if ($this->serverRequestColumnExists('resolution_note')) {
            $resolutionNoteSelect = 'sr.resolution_note AS request_resolution_note';
        }
        if ($this->serverRequestColumnExists('resolution_at')) {
            $resolutionAtSelect = 'sr.resolution_at AS request_resolution_at';
        }
        if ($this->serverRequestColumnExists('resolution_by')) {
            $resolutionBySelect = 'resolution_actor.full_name AS resolution_by_name';
            $resolutionByJoin = ' LEFT JOIN users resolution_actor ON resolution_actor.id = sr.resolution_by';
        }
        $sql = 'SELECT sri.*,
                       sr.status AS request_status,
                       sr.server_id,
                       sr.note AS request_note,
                       sr.service_reference,
                       sr.created_at AS request_created_at,
                       sr.ready_at AS request_ready_at,
                       sr.received_at AS request_received_at,
                       ' . $resolutionNoteSelect . ',
                       ' . $resolutionAtSelect . ',
                       u.full_name AS server_name,
                       mi.name AS menu_item_name,
                       mi.image_url AS menu_item_image_url,
                       prepared_user.full_name AS prepared_by_name,
                       received_user.full_name AS received_by_name,
                       ' . $resolutionBySelect . '
                FROM server_request_items sri
                INNER JOIN server_requests sr ON sr.id = sri.request_id
                INNER JOIN menu_items mi ON mi.id = sri.menu_item_id
                INNER JOIN users u ON u.id = sr.server_id
                LEFT JOIN users prepared_user ON prepared_user.id = sri.technical_confirmed_by
                LEFT JOIN users received_user ON received_user.id = sri.received_by
                ' . $resolutionByJoin . '
                WHERE sr.restaurant_id = :restaurant_id';
        $params = ['restaurant_id' => $restaurantId];

        if ($serverId !== null) {
            $sql .= ' AND sr.server_id = :server_id';
            $params['server_id'] = $serverId;
        }

        $sql .= ' ORDER BY sri.id DESC';
        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function serverSalesOverview(int $restaurantId, ?int $serverId = null): array
    {
        $timezone = $this->restaurantTimezone($restaurantId);
        $todayStart = (new DateTimeImmutable('now', $timezone))->setTime(0, 0, 0);
        $todayEnd = $todayStart->modify('+1 day');
        $servedWithoutSale = $this->listServedRequestsWithoutSaleForPeriod($restaurantId, $todayStart, $todayEnd, $serverId);
        $servedWithoutSaleTotal = 0.0;
        foreach ($servedWithoutSale as $row) {
            $servedWithoutSaleTotal += (float) ($row['total_virtual_sold_amount'] ?? 0);
        }

        return [
            'today_total_sold' => $this->salesTotalForPeriod($restaurantId, $todayStart, $todayEnd, $serverId),
            'today_sales_count' => $this->salesCountForPeriod($restaurantId, $todayStart, $todayEnd, $serverId),
            'active_requests_count' => $this->serverRequestCountByStatuses($restaurantId, ['DEMANDE', 'EN_PREPARATION', 'PRET_A_SERVIR', 'FOURNI_PARTIEL', 'FOURNI_TOTAL'], $serverId),
            'remitted_requests_count' => $this->serverRequestCountByStatuses($restaurantId, ['REMIS_SERVEUR'], $serverId),
            'served_without_sale_count' => count($servedWithoutSale),
            'served_without_sale_total' => round($servedWithoutSaleTotal, 2),
            'today_label' => $todayStart->format('Y-m-d'),
        ];
    }

    /**
     * Lecture métier: demandes réellement servies / remises au serveur, sans vente liée.
     * Aucune écriture, aucun stock, aucune vente créée.
     *
     * @return list<array<string, mixed>>
     */
    public function listServedRequestsWithoutSaleForPeriod(
        int $restaurantId,
        DateTimeImmutable $startAt,
        DateTimeImmutable $endAt,
        ?int $serverId = null,
    ): array {
        $params = [
            'restaurant_id' => $restaurantId,
            'start_at' => $startAt->format('Y-m-d H:i:s'),
            'end_at' => $endAt->format('Y-m-d H:i:s'),
        ];
        $serverFilter = '';
        if ($serverId !== null) {
            $serverFilter = ' AND sr.server_id = :server_id';
            $params['server_id'] = $serverId;
        }
        $responsibleFilter = $this->serverRequestHasResponsibleOutcomeColumn()
            ? ' AND COALESCE(sr.responsible_outcome_code, "") = ""'
            : '';

        $statement = $this->database->pdo()->prepare(
            'SELECT sr.id AS request_id,
                    sr.restaurant_id,
                    sr.server_id,
                    sr.service_reference,
                    sr.status,
                    COALESCE(sr.received_at, sr.supplied_at, sr.ready_at, sr.created_at) AS activity_at,
                    u.full_name AS server_name,
                    COALESCE(r.code, "") AS server_role_code,
                    sri.id AS request_item_id,
                    sri.menu_item_id,
                    mi.name AS menu_item_name,
                    COALESCE(mc.id, 0) AS category_id,
                    COALESCE(mc.name, "Sans categorie") AS category_name,
                    COALESCE(sri.supplied_quantity, 0) AS supplied_quantity,
                    COALESCE(sri.returned_quantity_validated, sri.returned_quantity, 0) AS returned_quantity_validated,
                    COALESCE(sri.unit_price, 0) AS unit_price
             FROM server_requests sr
             INNER JOIN server_request_items sri ON sri.request_id = sr.id
             INNER JOIN menu_items mi ON mi.id = sri.menu_item_id
             LEFT JOIN menu_categories mc ON mc.id = mi.category_id
             INNER JOIN users u ON u.id = sr.server_id
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE sr.restaurant_id = :restaurant_id
               AND sr.status NOT IN ("ANNULE", "REFUSE_CUISINE")
               AND (sr.received_at IS NOT NULL OR sr.status = "REMIS_SERVEUR")
               AND COALESCE(sr.received_at, sr.supplied_at, sr.ready_at, sr.created_at) >= :start_at
               AND COALESCE(sr.received_at, sr.supplied_at, sr.ready_at, sr.created_at) < :end_at
               AND NOT EXISTS (
                    SELECT 1
                    FROM sales s
                    WHERE s.restaurant_id = sr.restaurant_id
                      AND s.origin_type = "server_request"
                      AND s.origin_id = sr.id
               )' . $responsibleFilter . $serverFilter . '
             ORDER BY COALESCE(sr.received_at, sr.supplied_at, sr.ready_at, sr.created_at) DESC, sr.id DESC, sri.id ASC'
        );
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        $grouped = [];
        foreach ($rows as $row) {
            $requestId = (int) ($row['request_id'] ?? 0);
            if ($requestId <= 0) {
                continue;
            }
            if (!isset($grouped[$requestId])) {
                $activityAt = (string) ($row['activity_at'] ?? '');
                $grouped[$requestId] = [
                    'request_id' => $requestId,
                    'service_reference' => (string) ($row['service_reference'] ?? ''),
                    'status' => (string) ($row['status'] ?? ''),
                    'server_user_id' => (int) ($row['server_id'] ?? 0),
                    'server_name' => (string) ($row['server_name'] ?? ''),
                    'server_role_code' => (string) ($row['server_role_code'] ?? ''),
                    'activity_at' => $activityAt,
                    'activity_day_ymd' => $activityAt !== '' ? substr($activityAt, 0, 10) : '',
                    'total_virtual_sold_amount' => 0.0,
                    'total_virtual_returned_amount' => 0.0,
                    'has_validated_return' => false,
                    'lines' => [],
                ];
            }

            $suppliedQuantity = max(0.0, (float) ($row['supplied_quantity'] ?? 0));
            $returnedQuantity = max(0.0, (float) ($row['returned_quantity_validated'] ?? 0));
            $soldQuantity = max(0.0, $suppliedQuantity - $returnedQuantity);
            $unitPrice = max(0.0, (float) ($row['unit_price'] ?? 0));
            $lineTotal = $soldQuantity * $unitPrice;
            $returnedTotal = $returnedQuantity * $unitPrice;

            if ($returnedQuantity > 0.0001) {
                $grouped[$requestId]['has_validated_return'] = true;
                $grouped[$requestId]['total_virtual_returned_amount'] += $returnedTotal;
            }
            if ($soldQuantity <= 0.0001 || $lineTotal <= 0.0001) {
                continue;
            }

            $grouped[$requestId]['total_virtual_sold_amount'] += $lineTotal;
            $grouped[$requestId]['lines'][] = [
                'request_item_id' => (int) ($row['request_item_id'] ?? 0),
                'menu_item_id' => (int) ($row['menu_item_id'] ?? 0),
                'menu_item_name' => (string) ($row['menu_item_name'] ?? ''),
                'category_id' => (int) ($row['category_id'] ?? 0),
                'category_name' => (string) ($row['category_name'] ?? 'Sans categorie'),
                'sold_quantity' => $soldQuantity,
                'returned_quantity' => $returnedQuantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ];
        }

        $result = [];
        foreach ($grouped as $requestId => $request) {
            if ((float) ($request['total_virtual_sold_amount'] ?? 0) <= 0.0001) {
                continue;
            }
            $request['total_virtual_sold_amount'] = round((float) $request['total_virtual_sold_amount'], 2);
            $request['total_virtual_returned_amount'] = round((float) $request['total_virtual_returned_amount'], 2);
            $result[] = $request;
        }

        usort($result, static function (array $left, array $right): int {
            $cmp = strcmp((string) ($right['activity_at'] ?? ''), (string) ($left['activity_at'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }

            return (int) ($right['request_id'] ?? 0) <=> (int) ($left['request_id'] ?? 0);
        });

        return $result;
    }

    public function salesTotalsByServerForPeriods(int $restaurantId): array
    {
        $timezone = $this->restaurantTimezone($restaurantId);
        $base = new DateTimeImmutable('now', $timezone);

        $periods = [
            'daily' => [
                'label' => 'Aujourd’hui',
                'start_at' => $base->setTime(0, 0, 0),
                'end_at' => $base->setTime(0, 0, 0)->modify('+1 day'),
            ],
            'weekly' => [
                'label' => 'Semaine en cours',
                'start_at' => $base->modify('monday this week')->setTime(0, 0, 0),
                'end_at' => $base->modify('monday next week')->setTime(0, 0, 0),
            ],
            'monthly' => [
                'label' => 'Mois en cours',
                'start_at' => $base->modify('first day of this month')->setTime(0, 0, 0),
                'end_at' => $base->modify('first day of next month')->setTime(0, 0, 0),
            ],
        ];

        foreach ($periods as $key => $period) {
            $periods[$key]['sales_by_server'] = $this->salesByServerForPeriod($restaurantId, $period['start_at'], $period['end_at']);
            $periods[$key]['total_general'] = $this->salesTotalForPeriod($restaurantId, $period['start_at'], $period['end_at']);
        }

        return $periods;
    }

    public function createServerRequest(int $restaurantId, array $payload, array $actor): int
    {
        $items = $payload['items'] ?? [];
        if ($items === []) {
            throw new \RuntimeException('Aucune demande serveur fournie.');
        }

        $pdo = $this->database->pdo();
        $pdo->beginTransaction();

        try {
            $totalRequested = 0.0;
            $normalizedItems = [];
            foreach ($items as $item) {
                $menuItem = $this->findMenuItemWithCategoryInRestaurant((int) $item['menu_item_id'], $restaurantId);
                $quantity = (float) $item['requested_quantity'];
                if ($quantity <= 0) {
                    throw new \RuntimeException('Quantite demandee invalide.');
                }

                // Always snapshot the authoritative menu price from the current restaurant.
                $unitPrice = (float) $menuItem['price'];
                $requestedTotal = $quantity * $unitPrice;
                $totalRequested += $requestedTotal;
                $normalizedItems[] = [
                    'menu_item_id' => (int) $item['menu_item_id'],
                    'requested_quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'requested_total' => $requestedTotal,
                    'note' => trim((string) ($item['note'] ?? '')),
                    'menu_category_name' => $menuItem['menu_category_name'] ?? null,
                    'menu_category_slug' => $menuItem['menu_category_slug'] ?? null,
                ];
            }

            $requestStatement = $pdo->prepare(
                'INSERT INTO server_requests
                (restaurant_id, server_id, service_reference, requested_by, technical_confirmed_by, ready_by, received_by, decided_by, status, total_requested_amount, total_supplied_amount, total_sold_amount, total_returned_amount, total_server_loss_amount, note, created_at, updated_at, supplied_at, ready_at, received_at, closed_at)
                 VALUES
                (:restaurant_id, :server_id, :service_reference, :requested_by, NULL, NULL, NULL, NULL, "DEMANDE", :total_requested_amount, 0, 0, 0, 0, :note, NOW(), NOW(), NULL, NULL, NULL, NULL)'
            );
            $requestStatement->execute([
                'restaurant_id' => $restaurantId,
                'server_id' => $actor['id'],
                'service_reference' => $payload['service_reference'] ?? null,
                'requested_by' => $actor['id'],
                'total_requested_amount' => $totalRequested,
                'note' => $payload['note'] ?? null,
            ]);
            $requestId = (int) $pdo->lastInsertId();

            $itemStatement = $pdo->prepare(
                'INSERT INTO server_request_items
                (request_id, server_request_id, restaurant_id, menu_item_id, stock_item_id, requested_quantity, supplied_quantity, unavailable_quantity, sold_quantity, returned_quantity, returned_quantity_validated, unit_price, requested_total, supplied_total, sold_total, returned_total, server_loss_total, total_requested_amount, total_supplied_amount, total_sold_amount, status, supply_status, note, technical_confirmed_by, prepared_at, received_by, received_at, decided_by, created_at, updated_at)
                 VALUES
                (:request_id, :server_request_id, :restaurant_id, :menu_item_id, NULL, :requested_quantity, 0, :requested_quantity, 0, 0, 0, :unit_price, :requested_total, 0, 0, 0, 0, :requested_total, 0, 0, "DEMANDE", "DEMANDE", :note, NULL, NULL, NULL, NULL, NULL, NOW(), NOW())'
            );
            foreach ($normalizedItems as $item) {
                $itemStatement->execute([
                    'request_id' => $requestId,
                    'server_request_id' => $requestId,
                    'restaurant_id' => $restaurantId,
                    'menu_item_id' => $item['menu_item_id'],
                    'requested_quantity' => $item['requested_quantity'],
                    'unit_price' => $item['unit_price'],
                    'requested_total' => $item['requested_total'],
                    'note' => $item['note'] !== '' ? $item['note'] : null,
                ]);
            }

            $pdo->commit();
            Container::getInstance()->get('audit')->log([
                'restaurant_id' => $restaurantId,
                'user_id' => $actor['id'],
                'actor_name' => $actor['full_name'],
                'actor_role_code' => $actor['role_code'],
                'module_name' => 'sales',
                'action_name' => 'server_request_created',
                'entity_type' => 'server_requests',
                'entity_id' => (string) $requestId,
                'new_values' => $payload,
                'justification' => 'Demande chiffree du serveur depuis le menu',
            ]);

            return $requestId;
        } catch (\Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $throwable;
        }
    }

    public function closeServerRequestAsSale(int $restaurantId, int $requestId, array $payload, array $actor): void
    {
        $this->closeServerRequest($restaurantId, $requestId, $payload, $actor, false);
    }

    public function reconcileOverdueServerClosures(int $restaurantId): int
    {
        $timezone = $this->restaurantTimezone($restaurantId);
        $now = new DateTimeImmutable('now', $timezone);
        $todayStart = $now->setTime(0, 0, 0)->format('Y-m-d H:i:s');

        $statement = $this->database->pdo()->prepare(
            'SELECT id
             FROM server_requests
             WHERE restaurant_id = :restaurant_id
               AND status = "REMIS_SERVEUR"
               AND total_supplied_amount > 0
               AND COALESCE(received_at, supplied_at, updated_at, created_at) < :today_start
             ORDER BY COALESCE(received_at, supplied_at, updated_at, created_at) ASC, id ASC'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'today_start' => $todayStart,
        ]);

        $systemActor = [
            'id' => null,
            'full_name' => 'Système',
            'role_code' => 'system',
        ];

        $count = 0;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $this->closeServerRequest($restaurantId, (int) $row['id'], [
                'sale_type' => 'SUR_PLACE',
                'note' => 'Clôture automatique au changement de jour (minuit, fuseau du restaurant).',
                'closure_mode' => 'auto',
            ], $systemActor, true);
            $count++;
        }

        return $count;
    }

    /**
     * Runner manuel sécurisé (sandbox only) pour la clôture minuit des demandes REMIS_SERVEUR.
     *
     * @return array<string, mixed>
     */
    public function runSandboxMidnightReconcile(int $restaurantId, array $actor, bool $readOnly = true): array
    {
        $restaurant = Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId);
        if ($restaurant === null) {
            throw new \RuntimeException('Restaurant introuvable.');
        }
        $restaurantCode = (string) ($restaurant['restaurant_code'] ?? '');
        if (!is_sandbox_restaurant_code($restaurantCode)) {
            throw new \RuntimeException('Runner refusé: restaurant hors allowlist sandbox.');
        }

        $timezone = $this->restaurantTimezone($restaurantId);
        $todayStart = (new DateTimeImmutable('now', $timezone))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $statement = $this->database->pdo()->prepare(
            'SELECT id, total_supplied_amount, total_sold_amount, total_returned_amount,
                    COALESCE(received_at, supplied_at, updated_at, created_at) AS activity_at
             FROM server_requests
             WHERE restaurant_id = :restaurant_id
               AND status = "REMIS_SERVEUR"
               AND total_supplied_amount > 0
               AND COALESCE(received_at, supplied_at, updated_at, created_at) < :today_start
             ORDER BY COALESCE(received_at, supplied_at, updated_at, created_at) ASC, id ASC'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'today_start' => $todayStart,
        ]);
        $candidates = $statement->fetchAll(PDO::FETCH_ASSOC);
        $requestIds = array_values(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $candidates));

        $createdSalesBefore = $this->countSalesLinkedToRequests($restaurantId, $requestIds);
        $createdCount = 0;
        if (!$readOnly) {
            $createdCount = $this->reconcileOverdueServerClosures($restaurantId);
        }
        $createdSalesAfter = $this->countSalesLinkedToRequests($restaurantId, $requestIds);

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'] ?? null,
            'actor_name' => $actor['full_name'] ?? 'system',
            'actor_role_code' => $actor['role_code'] ?? 'system',
            'module_name' => 'sales',
            'action_name' => $readOnly ? 'sandbox_midnight_reconcile_dry_run' : 'sandbox_midnight_reconcile_execute',
            'entity_type' => 'restaurants',
            'entity_id' => (string) $restaurantId,
            'new_values' => [
                'restaurant_code' => $restaurantCode,
                'read_only_diagnostic' => $readOnly ? 1 : 0,
                'candidate_request_ids' => $requestIds,
                'candidate_count' => count($requestIds),
                'sales_linked_before' => $createdSalesBefore,
                'sales_linked_after' => $createdSalesAfter,
                'created_count' => $createdCount,
            ],
            'justification' => 'Runner manuel minuit (sandbox only)',
        ]);

        return [
            'restaurant_id' => $restaurantId,
            'restaurant_code' => $restaurantCode,
            'read_only_diagnostic' => $readOnly,
            'candidate_count' => count($requestIds),
            'candidate_request_ids' => $requestIds,
            'sales_linked_before' => $createdSalesBefore,
            'sales_linked_after' => $createdSalesAfter,
            'created_count' => $createdCount,
        ];
    }

    /**
     * Sandbox uniquement : recule les horodatages d'activité d'une demande déjà REMIS_SERVEUR
     * (pour tester le runner minuit sans attendre le lendemain). Ne change pas le statut ni les montants.
     */
    public function backdateSandboxRemittedRequestActivityYesterday(int $restaurantId, int $serverRequestId, array $actor): void
    {
        $restaurant = Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId);
        if ($restaurant === null) {
            throw new \RuntimeException('Restaurant introuvable.');
        }
        $restaurantCode = (string) ($restaurant['restaurant_code'] ?? '');
        if (!is_sandbox_restaurant_code($restaurantCode)) {
            throw new \RuntimeException('Action réservée au restaurant sandbox.');
        }

        $request = $this->findServerRequest($serverRequestId, $restaurantId);
        if ($request === null) {
            throw new \RuntimeException('Demande serveur introuvable.');
        }
        if ((string) ($request['status'] ?? '') !== 'REMIS_SERVEUR') {
            throw new \RuntimeException('La demande doit être en statut REMIS_SERVEUR.');
        }

        $tz = $this->restaurantTimezone($restaurantId);
        $stamp = (new DateTimeImmutable('now', $tz))->modify('-1 day')->setTime(15, 0, 0)->format('Y-m-d H:i:s');

        $pdo = $this->database->pdo();
        $pdo->beginTransaction();
        try {
            $uItems = $pdo->prepare(
                'UPDATE server_request_items
                 SET prepared_at = :ts,
                     received_at = :ts,
                     updated_at = NOW()
                 WHERE request_id = :request_id'
            );
            $uItems->execute([
                'ts' => $stamp,
                'request_id' => $serverRequestId,
            ]);

            $uReq = $pdo->prepare(
                'UPDATE server_requests
                 SET supplied_at = COALESCE(supplied_at, :ts),
                     received_at = :ts,
                     updated_at = NOW()
                 WHERE id = :id AND restaurant_id = :restaurant_id'
            );
            $uReq->execute([
                'ts' => $stamp,
                'id' => $serverRequestId,
                'restaurant_id' => $restaurantId,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'] ?? null,
            'actor_name' => $actor['full_name'] ?? 'system',
            'actor_role_code' => $actor['role_code'] ?? 'system',
            'module_name' => 'sales',
            'action_name' => 'sandbox_backdate_remis_activity',
            'entity_type' => 'server_requests',
            'entity_id' => (string) $serverRequestId,
            'new_values' => [
                'restaurant_code' => $restaurantCode,
                'activity_at' => $stamp,
            ],
            'justification' => 'Recul date activité (sandbox, tests runner minuit)',
        ]);
    }

    public function cancelServerRequestByServer(int $restaurantId, int $requestId, string $reason, array $actor): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \RuntimeException('Motif d annulation obligatoire.');
        }

        $request = $this->findServerRequest($requestId, $restaurantId);
        if ($request === null) {
            throw new \RuntimeException('Demande serveur introuvable.');
        }

        if ((int) $request['server_id'] !== (int) ($actor['id'] ?? 0)) {
            throw new \RuntimeException('Seul le serveur demandeur peut annuler cette commande.');
        }

        if (!in_array((string) $request['status'], ['DEMANDE'], true)) {
            throw new \RuntimeException('Annulation impossible : la cuisine a deja avance sur cette demande.');
        }

        $items = $this->listServerRequestItemsByRequest($requestId, $restaurantId);
        foreach ($items as $item) {
            if ((string) ($item['status'] ?? '') !== 'DEMANDE') {
                throw new \RuntimeException('Annulation impossible : statut deja modifie sur au moins une ligne.');
            }
            if (!empty($item['technical_confirmed_by'])) {
                throw new \RuntimeException('Annulation impossible : la cuisine a pris en charge au moins une ligne.');
            }
        }

        $pdo = $this->database->pdo();
        $pdo->beginTransaction();

        try {
            $updItems = $pdo->prepare(
                'UPDATE server_request_items
                 SET status = "ANNULE",
                     supply_status = "NON_FOURNI",
                     supplied_quantity = 0,
                     supplied_total = 0,
                     total_supplied_amount = 0,
                     unavailable_quantity = requested_quantity,
                     updated_at = NOW()
                 WHERE request_id = :request_id'
            );
            $updItems->execute(['request_id' => $requestId]);

            $updReq = $pdo->prepare(
                'UPDATE server_requests
                 SET status = "ANNULE",
                     total_supplied_amount = 0,
                     resolution_note = :resolution_note,
                     resolution_by = :resolution_by,
                     resolution_at = NOW(),
                     updated_at = NOW()
                 WHERE id = :id AND restaurant_id = :restaurant_id'
            );
            $updReq->execute([
                'resolution_note' => $reason,
                'resolution_by' => $actor['id'],
                'id' => $requestId,
                'restaurant_id' => $restaurantId,
            ]);

            $pdo->commit();
        } catch (\Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $throwable;
        }

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'sales',
            'action_name' => 'request_cancelled',
            'entity_type' => 'server_requests',
            'entity_id' => (string) $requestId,
            'new_values' => [
                'status' => 'ANNULE',
                'resolution_note' => $reason,
                'cancelled_by' => [
                    'user_id' => $actor['id'] ?? null,
                    'full_name' => $actor['full_name'] ?? '',
                    'role_code' => $actor['role_code'] ?? '',
                ],
                'operation' => $this->buildServerRequestOperationSnapshot(
                    $request,
                    $this->serverRequestLineRowsForAudit($restaurantId, $requestId)
                ),
            ],
            'justification' => 'Annulation serveur avant prise en charge cuisine',
        ]);
    }

    public function declineServerRequestByKitchen(int $restaurantId, int $requestId, string $reason, array $actor): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \RuntimeException('Motif de declinaison obligatoire.');
        }

        if (!can_access('kitchen.request.fulfill', $actor)) {
            throw new \RuntimeException('Action reservee aux comptes habilites a traiter la file cuisine (declinaison).');
        }

        $request = $this->findServerRequest($requestId, $restaurantId);
        if ($request === null) {
            throw new \RuntimeException('Demande serveur introuvable.');
        }

        if (in_array((string) $request['status'], ['REMIS_SERVEUR', 'CLOTURE', 'ANNULE', 'REFUSE_CUISINE', 'VENDU_PARTIEL', 'VENDU_TOTAL'], true)) {
            throw new \RuntimeException('Declinaison impossible sur cette demande.');
        }

        $items = $this->listServerRequestItemsByRequest($requestId, $restaurantId);
        foreach ($items as $item) {
            $st = (string) ($item['status'] ?? '');
            if (in_array($st, ['PRET_A_SERVIR', 'REMIS_SERVEUR', 'CLOTURE', 'REFUSE_CUISINE', 'ANNULE'], true)) {
                throw new \RuntimeException('Declinaison impossible : au moins une ligne est deja prete ou terminee.');
            }
        }

        $pdo = $this->database->pdo();
        $pdo->beginTransaction();

        try {
            $updItems = $pdo->prepare(
                'UPDATE server_request_items
                 SET status = "REFUSE_CUISINE",
                     supply_status = "NON_FOURNI",
                     supplied_quantity = 0,
                     supplied_total = 0,
                     total_supplied_amount = 0,
                     unavailable_quantity = requested_quantity,
                     updated_at = NOW()
                 WHERE request_id = :request_id'
            );
            $updItems->execute(['request_id' => $requestId]);

            $updReq = $pdo->prepare(
                'UPDATE server_requests
                 SET status = "REFUSE_CUISINE",
                     total_supplied_amount = 0,
                     resolution_note = :resolution_note,
                     resolution_by = :resolution_by,
                     resolution_at = NOW(),
                     updated_at = NOW()
                 WHERE id = :id AND restaurant_id = :restaurant_id'
            );
            $updReq->execute([
                'resolution_note' => $reason,
                'resolution_by' => $actor['id'],
                'id' => $requestId,
                'restaurant_id' => $restaurantId,
            ]);

            $pdo->commit();
        } catch (\Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $throwable;
        }

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'sales',
            'action_name' => 'request_declined',
            'entity_type' => 'server_requests',
            'entity_id' => (string) $requestId,
            'new_values' => [
                'status' => 'REFUSE_CUISINE',
                'resolution_note' => $reason,
                'rejected_by' => [
                    'user_id' => $actor['id'] ?? null,
                    'full_name' => $actor['full_name'] ?? '',
                    'role_code' => $actor['role_code'] ?? '',
                ],
                'operation' => $this->buildServerRequestOperationSnapshot(
                    $request,
                    $this->serverRequestLineRowsForAudit($restaurantId, $requestId)
                ),
            ],
            'justification' => 'Commande declinee par la cuisine (non disponible)',
        ]);
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return array<string, mixed>
     */
    private function buildServerRequestOperationSnapshot(?array $request, array $lines): array
    {
        if ($request === null) {
            return [];
        }
        $serverName = '';
        $sid = (int) ($request['server_id'] ?? 0);
        if ($sid > 0) {
            $st = $this->database->pdo()->prepare('SELECT full_name FROM users WHERE id = :id LIMIT 1');
            $st->execute(['id' => $sid]);
            $serverName = (string) ($st->fetchColumn() ?: '');
        }
        $lineOut = [];
        foreach ($lines as $ln) {
            $lineOut[] = [
                'menu_item_id' => (int) ($ln['menu_item_id'] ?? 0),
                'menu_item_name' => (string) ($ln['menu_item_name'] ?? ''),
                'requested_quantity' => (float) ($ln['requested_quantity'] ?? 0),
                'unit_price' => (float) ($ln['unit_price'] ?? 0),
                'requested_total' => (float) ($ln['requested_total'] ?? 0),
                'supplied_quantity' => (float) ($ln['supplied_quantity'] ?? 0),
                'line_status' => (string) ($ln['status'] ?? ''),
            ];
        }

        return [
            'server_request_id' => (int) ($request['id'] ?? 0),
            'service_reference' => (string) ($request['service_reference'] ?? ''),
            'created_at' => $request['created_at'] ?? null,
            'request_status_at_event' => (string) ($request['status'] ?? ''),
            'requesting_server' => ['user_id' => $sid, 'full_name' => $serverName],
            'amounts' => [
                'total_requested' => (float) ($request['total_requested_amount'] ?? 0),
                'total_supplied' => (float) ($request['total_supplied_amount'] ?? 0),
            ],
            'lines' => $lineOut,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serverRequestLineRowsForAudit(int $restaurantId, int $requestId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT sri.*, mi.name AS menu_item_name
             FROM server_request_items sri
             INNER JOIN server_requests sr ON sr.id = sri.request_id
             INNER JOIN menu_items mi ON mi.id = sri.menu_item_id
             WHERE sri.request_id = :request_id AND sr.restaurant_id = :restaurant_id
             ORDER BY sri.id ASC'
        );
        $statement->execute(['request_id' => $requestId, 'restaurant_id' => $restaurantId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function closeServerRequest(int $restaurantId, int $requestId, array $payload, array $actor, bool $automatic): void
    {
        $request = $this->findServerRequest($requestId, $restaurantId);
        if ($request === null) {
            throw new \RuntimeException('Demande serveur introuvable.');
        }

        $isSystemActor = ($actor['role_code'] ?? null) === 'system';
        $isResponsible = $isSystemActor
            || (($actor['scope'] ?? null) === 'super_admin')
            || in_array(($actor['role_code'] ?? null), ['manager', 'owner'], true);
        if (!$isSystemActor && (int) $request['server_id'] !== (int) ($actor['id'] ?? 0) && !$isResponsible) {
            throw new \RuntimeException('Cette demande ne peut pas etre cloturee par cet utilisateur.');
        }

        $items = $this->listServerRequestItemsByRequest($requestId, $restaurantId);
        if ($items === []) {
            throw new \RuntimeException('Aucun article a conclure.');
        }
        $operationSnapshot = $this->buildServerRequestOperationSnapshot(
            $request,
            $this->serverRequestLineRowsForAudit($restaurantId, $requestId)
        );
        if (in_array((string) $request['status'], ['ANNULE', 'REFUSE_CUISINE'], true)) {
            throw new \RuntimeException('Cette demande a ete annulee ou refusee par la cuisine.');
        }
        if (in_array((string) $request['status'], ['CLOTURE', 'VENDU_TOTAL', 'VENDU_PARTIEL'], true)) {
            throw new \RuntimeException('Demande deja cloturee.');
        }

        $reqStatus = (string) $request['status'];
        $suppliedHeader = (float) ($request['total_supplied_amount'] ?? 0);
        $closureStatusOk = in_array($reqStatus, ['REMIS_SERVEUR', 'VENDU_PARTIEL', 'VENDU_TOTAL'], true)
            || ($isResponsible && $suppliedHeader > 0.0001);
        if (!$closureStatusOk) {
            throw new \RuntimeException('La demande doit d abord etre remise au serveur avant la cloture.');
        }
        if ($suppliedHeader <= 0) {
            throw new \RuntimeException('Aucune fourniture cuisine validee sur cette demande.');
        }

        $soldQuantities = $payload['sold_quantities'] ?? [];
        $returnedQuantities = $payload['returned_quantities'] ?? [];
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();

        try {
            $salePayloadItems = [];
            $totalSold = 0.0;
            $totalReturned = 0.0;
            $totalServerLoss = 0.0;

            $updateItem = $pdo->prepare(
                'UPDATE server_request_items
                 SET sold_quantity = :sold_quantity,
                     returned_quantity = :returned_quantity,
                     returned_quantity_validated = :returned_quantity_validated,
                     sold_total = :sold_total,
                     returned_total = :returned_total,
                     server_loss_total = :server_loss_total,
                     total_requested_amount = requested_total,
                     total_supplied_amount = supplied_total,
                     total_sold_amount = :sold_total_amount,
                     status = :status,
                     decided_by = :decided_by,
                     updated_at = NOW()
                 WHERE id = :id'
            );

            foreach ($items as $item) {
                $key = (string) $item['id'];
                $suppliedQuantity = (float) $item['supplied_quantity'];
                $soldQuantity = max(0.0, (float) ($soldQuantities[$key] ?? $suppliedQuantity));
                $returnedQuantity = max(0.0, (float) ($returnedQuantities[$key] ?? 0));

                if ($soldQuantity + $returnedQuantity > $suppliedQuantity) {
                    throw new \RuntimeException('Quantites vendues et retournees incoherentes avec le fourni.');
                }

                $unitPrice = (float) $item['unit_price'];
                $soldTotal = $soldQuantity * $unitPrice;
                $returnedTotal = $returnedQuantity * $unitPrice;
                $serverLossTotal = ($suppliedQuantity - $soldQuantity - $returnedQuantity) * $unitPrice;
                $itemStatus = 'CLOTURE';

                $updateItem->execute([
                    'sold_quantity' => $soldQuantity,
                    'returned_quantity' => $returnedQuantity,
                    'returned_quantity_validated' => $returnedQuantity,
                    'sold_total' => $soldTotal,
                    'returned_total' => $returnedTotal,
                    'server_loss_total' => $serverLossTotal,
                    'sold_total_amount' => $soldTotal,
                    'status' => $itemStatus,
                    'decided_by' => $actor['id'] ?? null,
                    'id' => (int) $item['id'],
                ]);

                $totalSold += $soldTotal;
                $totalReturned += $returnedTotal;
                $totalServerLoss += $serverLossTotal;

                if ($soldQuantity > 0) {
                    $salePayloadItems[] = [
                        'menu_item_id' => (int) $item['menu_item_id'],
                        'kitchen_production_id' => '',
                        'quantity' => $soldQuantity,
                        'unit_price' => $unitPrice,
                    ];
                }
            }

            if ($salePayloadItems !== []) {
                $dupSaleId = $this->findLatestSaleIdByServerRequestOrigin($restaurantId, $requestId);
                if ($dupSaleId !== null) {
                    $salePayloadItems = [];
                }
            }

            if ($salePayloadItems !== []) {
                $this->createSale($restaurantId, [
                    'sale_type' => $payload['sale_type'] ?? 'SUR_PLACE',
                    'status' => 'VALIDE',
                    'note' => $payload['note'] ?? 'Vente issue d une demande serveur #' . $requestId,
                    'origin_type' => 'server_request',
                    'origin_id' => $requestId,
                    'server_id' => (int) $request['server_id'],
                    'items' => $salePayloadItems,
                ], $actor);
            }

            $updateRequest = $pdo->prepare(
                'UPDATE server_requests
                 SET status = :status,
                     total_sold_amount = :total_sold_amount,
                     total_returned_amount = :total_returned_amount,
                     total_server_loss_amount = :total_server_loss_amount,
                     decided_by = :decided_by,
                     updated_at = NOW(),
                     closed_at = NOW()
                 WHERE id = :id'
            );
            $updateRequest->execute([
                'status' => 'CLOTURE',
                'total_sold_amount' => $totalSold,
                'total_returned_amount' => $totalReturned,
                'total_server_loss_amount' => $totalServerLoss,
                'decided_by' => $actor['id'] ?? null,
                'id' => $requestId,
            ]);

            $pdo->commit();
            $actionName = (string) ($payload['action_name_override'] ?? '');
            if ($actionName === '') {
                $actionName = $automatic ? 'server_request_auto_closed_as_sale' : 'server_request_closed_as_sale';
            }
            $justification = (string) ($payload['audit_justification'] ?? '');
            if ($justification === '') {
                $justification = $automatic
                    ? 'Cloture automatique d une remise serveur depassee'
                    : 'Cloture demande serveur en vente reelle';
            }
            Container::getInstance()->get('audit')->log([
                'restaurant_id' => $restaurantId,
                'user_id' => $actor['id'] ?? null,
                'actor_name' => $actor['full_name'],
                'actor_role_code' => $actor['role_code'],
                'module_name' => 'sales',
                'action_name' => $actionName,
                'entity_type' => 'server_requests',
                'entity_id' => (string) $requestId,
                'new_values' => array_merge($payload, [
                    'automatic' => $automatic,
                    'operation' => $operationSnapshot,
                ]),
                'justification' => $justification,
            ]);
        } catch (\Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $throwable;
        }
    }

    public function confirmServerRequestReceipt(int $restaurantId, int $requestId, array $actor): void
    {
        $request = $this->findServerRequest($requestId, $restaurantId);
        if ($request === null) {
            throw new \RuntimeException('Demande serveur introuvable.');
        }

        if (in_array((string) $request['status'], ['ANNULE', 'REFUSE_CUISINE'], true)) {
            throw new \RuntimeException('Cette demande ne peut pas etre receptionnee.');
        }

        if ((int) $request['server_id'] !== (int) $actor['id']
            && !in_array(($actor['role_code'] ?? null), ['manager', 'owner'], true)
            && (($actor['scope'] ?? null) !== 'super_admin')) {
            throw new \RuntimeException('Cette remise ne peut pas etre confirmee par cet utilisateur.');
        }

        if (!in_array((string) $request['status'], ['PRET_A_SERVIR', 'FOURNI_PARTIEL', 'FOURNI_TOTAL'], true)) {
            throw new \RuntimeException('Aucune remise cuisine prete a confirmer sur cette demande.');
        }

        $pdo = $this->database->pdo();
        $pdo->beginTransaction();

        try {
            $updateItems = $pdo->prepare(
                'UPDATE server_request_items
                 SET status = CASE
                        WHEN status = "PRET_A_SERVIR" THEN "REMIS_SERVEUR"
                        WHEN status = "FOURNI_TOTAL" THEN "REMIS_SERVEUR"
                        WHEN status = "FOURNI_PARTIEL" THEN "REMIS_SERVEUR"
                        ELSE status
                     END,
                     supply_status = CASE
                        WHEN supply_status = "PRET_A_SERVIR" THEN "REMIS_SERVEUR"
                        WHEN supply_status = "FOURNI_TOTAL" THEN "REMIS_SERVEUR"
                        WHEN supply_status = "FOURNI_PARTIEL" THEN "REMIS_SERVEUR"
                        ELSE supply_status
                     END,
                     received_by = :received_by,
                     received_at = NOW(),
                     updated_at = NOW()
                 WHERE request_id = :request_id'
            );
            $updateItems->execute([
                'received_by' => $actor['id'],
                'request_id' => $requestId,
            ]);

            $updateRequest = $pdo->prepare(
                'UPDATE server_requests
                 SET status = "REMIS_SERVEUR",
                     received_by = :received_by,
                     received_at = NOW(),
                     updated_at = NOW()
                 WHERE id = :id AND restaurant_id = :restaurant_id'
            );
            $updateRequest->execute([
                'received_by' => $actor['id'],
                'id' => $requestId,
                'restaurant_id' => $restaurantId,
            ]);

            $pdo->commit();
        } catch (\Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $throwable;
        }

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'sales',
            'action_name' => 'server_request_received',
            'entity_type' => 'server_requests',
            'entity_id' => (string) $requestId,
            'new_values' => ['status' => 'REMIS_SERVEUR'],
            'justification' => 'Confirmation explicite de remise au serveur',
        ]);
    }

    public function createSale(int $restaurantId, array $payload, array $actor): void
    {
        $pdo = $this->database->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $items = $payload['items'];
            $total = 0.0;

            foreach ($items as $item) {
                $this->assertMenuItemBelongsToRestaurant((int) $item['menu_item_id'], $restaurantId);

                if ($item['kitchen_production_id'] !== '') {
                    $this->findProductionInRestaurant((int) $item['kitchen_production_id'], $restaurantId);
                }

                $total += ((float) $item['quantity']) * ((float) $item['unit_price']);
            }

            $saleStatement = $pdo->prepare(
                'INSERT INTO sales
                (restaurant_id, server_id, sale_type, total_amount, status, origin_type, origin_id, note, created_at)
                 VALUES
                (:restaurant_id, :server_id, :sale_type, :total_amount, :status, :origin_type, :origin_id, :note, NOW())'
            );
            $saleStatement->execute([
                'restaurant_id' => $restaurantId,
                'server_id' => $payload['server_id'] ?? $actor['id'] ?? null,
                'sale_type' => $payload['sale_type'],
                'total_amount' => $total,
                'status' => $payload['status'],
                'origin_type' => $payload['origin_type'] ?? 'manuel',
                'origin_id' => $payload['origin_id'] ?? null,
                'note' => $payload['note'] ?? null,
            ]);

            $saleId = (int) $pdo->lastInsertId();
            $saleItemStatement = $pdo->prepare(
                'INSERT INTO sale_items
                (sale_id, menu_item_id, kitchen_production_id, quantity, unit_price, status, created_at)
                 VALUES
                (:sale_id, :menu_item_id, :kitchen_production_id, :quantity, :unit_price, "SERVI", NOW())'
            );

            foreach ($items as $item) {
                $saleItemStatement->execute([
                    'sale_id' => $saleId,
                    'menu_item_id' => (int) $item['menu_item_id'],
                    'kitchen_production_id' => $item['kitchen_production_id'] !== '' ? (int) $item['kitchen_production_id'] : null,
                    'quantity' => (float) $item['quantity'],
                    'unit_price' => (float) $item['unit_price'],
                ]);

                if ($item['kitchen_production_id'] !== '') {
                    $updateProduction = $pdo->prepare(
                        'UPDATE kitchen_production
                         SET quantity_remaining = GREATEST(quantity_remaining - :quantity, 0),
                             status = CASE WHEN GREATEST(quantity_remaining - :quantity, 0) = 0 THEN "TERMINE" ELSE status END,
                             closed_at = CASE WHEN GREATEST(quantity_remaining - :quantity, 0) = 0 THEN NOW() ELSE closed_at END
                         WHERE id = :id AND restaurant_id = :restaurant_id'
                    );
                    $updateProduction->execute([
                        'quantity' => (float) $item['quantity'],
                        'id' => (int) $item['kitchen_production_id'],
                        'restaurant_id' => $restaurantId,
                    ]);
                }
            }

            if ($payload['status'] === 'VALIDE') {
                $validate = $pdo->prepare('UPDATE sales SET validated_at = NOW() WHERE id = :id');
                $validate->execute(['id' => $saleId]);
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }
            Container::getInstance()->get('audit')->log([
                'restaurant_id' => $restaurantId,
                'user_id' => $actor['id'] ?? null,
                'actor_name' => $actor['full_name'],
                'actor_role_code' => $actor['role_code'],
                'module_name' => 'sales',
                'action_name' => 'sale_created',
                'entity_type' => 'sales',
                'entity_id' => (string) $saleId,
                'new_values' => $payload,
                'justification' => 'Vente enregistrée par serveur',
            ]);
        } catch (\Throwable $throwable) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $throwable;
        }
    }

    public function validateReturnByManager(int $restaurantId, array $payload, array $actor): void
    {
        throw new \RuntimeException('Le flux direct manager est desactive. Utilisez le workflow des cas metier.');
    }

    /**
     * Plus aucune régularisation silencieuse : pas de vente automatique, pas de clôture serveur
     * ni réception caisse implicites. Utiliser {@see regularizationBacklogCounts} pour l’affichage « à régulariser ».
     */
    public function reconcileOverdueReturnsToAutomaticSales(int $restaurantId): int
    {
        return 0;
    }

    /**
     * Comptages lecture seule pour signaler les arriérés (sans mutation).
     *
     * @return array<string, int>
     */
    public function regularizationBacklogCounts(int $restaurantId, ?int $restrictToServerUserId = null): array
    {
        $timezone = $this->restaurantTimezone($restaurantId);
        $todayStart = (new \DateTimeImmutable('now', $timezone))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $pdo = $this->database->pdo();

        if ($restrictToServerUserId !== null && $restrictToServerUserId > 0) {
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM server_requests
                 WHERE restaurant_id = :rid
                   AND server_id = :sid
                   AND status = "REMIS_SERVEUR"
                   AND total_supplied_amount > 0
                   AND COALESCE(responsible_outcome_code, "") = ""
                   AND COALESCE(received_at, supplied_at, updated_at, created_at) < :today_start'
            );
            $st->execute(['rid' => $restaurantId, 'sid' => $restrictToServerUserId, 'today_start' => $todayStart]);
            $overdueServerRemis = (int) $st->fetchColumn();

            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM cash_transfers
                 WHERE restaurant_id = :rid
                   AND source_type = "sale"
                   AND from_user_id = :fid
                   AND status = "REMIS_A_CAISSE"
                   AND COALESCE(requested_at, created_at) < :today_start'
            );
            $st->execute(['rid' => $restaurantId, 'fid' => $restrictToServerUserId, 'today_start' => $todayStart]);
            $overdueRemisCaisse = (int) $st->fetchColumn();

            return [
                'overdue_server_remis_serveur' => $overdueServerRemis,
                'overdue_remis_a_caisse' => $overdueRemisCaisse,
                'overdue_kitchen_production_returns' => 0,
            ];
        }

        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM server_requests
             WHERE restaurant_id = :rid
               AND status = "REMIS_SERVEUR"
               AND total_supplied_amount > 0
               AND COALESCE(responsible_outcome_code, "") = ""
               AND COALESCE(received_at, supplied_at, updated_at, created_at) < :today_start'
        );
        $st->execute(['rid' => $restaurantId, 'today_start' => $todayStart]);
        $overdueServerRemis = (int) $st->fetchColumn();

        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM cash_transfers
             WHERE restaurant_id = :rid
               AND source_type = "sale"
               AND status = "REMIS_A_CAISSE"
               AND COALESCE(requested_at, created_at) < :today_start'
        );
        $st->execute(['rid' => $restaurantId, 'today_start' => $todayStart]);
        $overdueRemisCaisse = (int) $st->fetchColumn();

        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM kitchen_production kp
             INNER JOIN stock_movements sm ON sm.id = kp.stock_movement_id
             WHERE kp.restaurant_id = :rid
               AND kp.quantity_remaining > 0
               AND sm.status = "PROVISOIRE"
               AND sm.created_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
        );
        $st->execute(['rid' => $restaurantId]);
        $overdueKitchenReturn = (int) $st->fetchColumn();

        return [
            'overdue_server_remis_serveur' => $overdueServerRemis,
            'overdue_remis_a_caisse' => $overdueRemisCaisse,
            'overdue_kitchen_production_returns' => $overdueKitchenReturn,
        ];
    }

    private function createAutomaticSale(int $restaurantId, array $payload): void
    {
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();

        try {
            $saleStatement = $pdo->prepare(
                'INSERT INTO sales
                (restaurant_id, server_id, sale_type, total_amount, status, origin_type, origin_id, note, created_at, validated_at)
                 VALUES
                (:restaurant_id, NULL, "SUR_PLACE", :total_amount, "VALIDE", "AUTO_24H", :origin_id,
                 "Non-retour après 24h converti en vente automatique", NOW(), NOW())'
            );
            $saleStatement->execute([
                'restaurant_id' => $restaurantId,
                'total_amount' => ((float) $payload['quantity']) * ((float) $payload['unit_price']),
                'origin_id' => (int) $payload['kitchen_production_id'],
            ]);

            $saleId = (int) $pdo->lastInsertId();
            $itemStatement = $pdo->prepare(
                'INSERT INTO sale_items
                (sale_id, menu_item_id, kitchen_production_id, quantity, unit_price, status, created_at)
                 VALUES
                (:sale_id, :menu_item_id, :kitchen_production_id, :quantity, :unit_price, "SERVI", NOW())'
            );
            $itemStatement->execute([
                'sale_id' => $saleId,
                'menu_item_id' => (int) $payload['menu_item_id'],
                'kitchen_production_id' => (int) $payload['kitchen_production_id'],
                'quantity' => (float) $payload['quantity'],
                'unit_price' => (float) $payload['unit_price'],
            ]);

            $movementStatement = $pdo->prepare(
                'UPDATE stock_movements
                 SET status = "VALIDE", validated_at = NOW()
                 WHERE id = (
                    SELECT stock_movement_id FROM kitchen_production WHERE id = :production_id
                 )'
            );
            $movementStatement->execute([
                'production_id' => (int) $payload['kitchen_production_id'],
            ]);

            $productionStatement = $pdo->prepare(
                'UPDATE kitchen_production
                 SET quantity_remaining = 0,
                     status = "TERMINE",
                     closed_at = NOW()
                 WHERE id = :id'
            );
            $productionStatement->execute([
                'id' => (int) $payload['kitchen_production_id'],
            ]);

            $pdo->commit();
            Container::getInstance()->get('audit')->log([
                'restaurant_id' => $restaurantId,
                'user_id' => null,
                'actor_name' => 'Système',
                'actor_role_code' => 'system',
                'module_name' => 'sales',
                'action_name' => 'automatic_sale_after_24h',
                'entity_type' => 'sales',
                'entity_id' => (string) $saleId,
                'new_values' => $payload,
                'justification' => 'Non-retour après 24h',
            ]);
        } catch (\Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $throwable;
        }
    }

    private function restaurantTimezone(int $restaurantId): DateTimeZone
    {
        $restaurant = Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId);
        $timezoneName = (string) ($restaurant['timezone'] ?? config('app.timezone', 'Africa/Lagos'));

        try {
            return new DateTimeZone($timezoneName);
        } catch (\Throwable) {
            return new DateTimeZone((string) config('app.timezone', 'Africa/Lagos'));
        }
    }

    private function salesTotalForPeriod(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt, ?int $serverId = null): float
    {
        $sql = 'SELECT COALESCE(SUM(s.total_amount), 0)
                FROM sales s
                ' . sql_sale_activity_left_join_server_request('s', 'sr') . '
                WHERE s.restaurant_id = :restaurant_id
                  AND ' . sql_sale_activity_datetime_expr('s', 'sr') . ' >= :start_at
                  AND ' . sql_sale_activity_datetime_expr('s', 'sr') . ' < :end_at';
        $params = [
            'restaurant_id' => $restaurantId,
            'start_at' => $startAt->format('Y-m-d H:i:s'),
            'end_at' => $endAt->format('Y-m-d H:i:s'),
        ];

        if ($serverId !== null) {
            $sql .= ' AND s.server_id = :server_id';
            $params['server_id'] = $serverId;
        }

        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($params);

        return (float) ($statement->fetchColumn() ?: 0);
    }

    private function salesCountForPeriod(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt, ?int $serverId = null): int
    {
        $sql = 'SELECT COUNT(*)
                FROM sales s
                ' . sql_sale_activity_left_join_server_request('s', 'sr') . '
                WHERE s.restaurant_id = :restaurant_id
                  AND ' . sql_sale_activity_datetime_expr('s', 'sr') . ' >= :start_at
                  AND ' . sql_sale_activity_datetime_expr('s', 'sr') . ' < :end_at';
        $params = [
            'restaurant_id' => $restaurantId,
            'start_at' => $startAt->format('Y-m-d H:i:s'),
            'end_at' => $endAt->format('Y-m-d H:i:s'),
        ];

        if ($serverId !== null) {
            $sql .= ' AND s.server_id = :server_id';
            $params['server_id'] = $serverId;
        }

        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($params);

        return (int) ($statement->fetchColumn() ?: 0);
    }

    private function serverRequestCountByStatuses(int $restaurantId, array $statuses, ?int $serverId = null): int
    {
        if ($statuses === []) {
            return 0;
        }

        $placeholders = [];
        $params = ['restaurant_id' => $restaurantId];
        foreach (array_values($statuses) as $index => $status) {
            $key = 'status_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $status;
        }

        $sql = 'SELECT COUNT(*)
                FROM server_requests
                WHERE restaurant_id = :restaurant_id
                  AND status IN (' . implode(', ', $placeholders) . ')';

        if ($serverId !== null) {
            $sql .= ' AND server_id = :server_id';
            $params['server_id'] = $serverId;
        }

        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($params);

        return (int) ($statement->fetchColumn() ?: 0);
    }

    private function salesByServerForPeriod(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT COALESCE(u.full_name, "Vente automatique") AS server_name,
                    COUNT(s.id) AS sales_count,
                    COALESCE(SUM(s.total_amount), 0) AS total_amount
             FROM sales s
             ' . sql_sale_activity_left_join_server_request('s', 'sr') . '
             LEFT JOIN users u ON u.id = s.server_id
             WHERE s.restaurant_id = :restaurant_id
               AND ' . sql_sale_activity_datetime_expr('s', 'sr') . ' >= :start_at
               AND ' . sql_sale_activity_datetime_expr('s', 'sr') . ' < :end_at
             GROUP BY COALESCE(u.full_name, "Vente automatique")
             ORDER BY total_amount DESC, server_name ASC'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'start_at' => $startAt->format('Y-m-d H:i:s'),
            'end_at' => $endAt->format('Y-m-d H:i:s'),
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function findMenuItem(int $menuItemId): ?array
    {
        $statement = $this->database->pdo()->prepare('SELECT * FROM menu_items WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $menuItemId]);
        $item = $statement->fetch(PDO::FETCH_ASSOC);

        return $item ?: null;
    }

    private function findMenuItemInRestaurant(int $menuItemId, int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT *
             FROM menu_items
             WHERE id = :id AND restaurant_id = :restaurant_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $menuItemId,
            'restaurant_id' => $restaurantId,
        ]);
        $item = $statement->fetch(PDO::FETCH_ASSOC);
        if ($item === false) {
            throw new \RuntimeException('Article menu hors perimetre restaurant.');
        }

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    private function findMenuItemWithCategoryInRestaurant(int $menuItemId, int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT mi.*, mc.name AS menu_category_name, mc.slug AS menu_category_slug
             FROM menu_items mi
             LEFT JOIN menu_categories mc ON mc.id = mi.category_id
             WHERE mi.id = :id AND mi.restaurant_id = :restaurant_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $menuItemId,
            'restaurant_id' => $restaurantId,
        ]);
        $item = $statement->fetch(PDO::FETCH_ASSOC);
        if ($item === false) {
            throw new \RuntimeException('Article menu hors perimetre restaurant.');
        }

        return $item;
    }

    private function assertMenuItemBelongsToRestaurant(int $menuItemId, int $restaurantId): void
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT id
             FROM menu_items
             WHERE id = :id AND restaurant_id = :restaurant_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $menuItemId,
            'restaurant_id' => $restaurantId,
        ]);

        if ($statement->fetch(PDO::FETCH_ASSOC) === false) {
            throw new \RuntimeException('Article menu hors perimetre restaurant.');
        }
    }

    /**
     * Clôture définitive sans fourniture cuisine : aucune vente créée, aucun mouvement de stock supplémentaire.
     *
     * @param 'close_no_sale'|'server_shortage' $terminalMode
     */
    private function managerResponsibleTerminalCloseZeroSupply(
        int $restaurantId,
        int $requestId,
        string $terminalMode,
        string $reason,
        array $actor,
    ): void {
        if (!in_array($terminalMode, ['close_no_sale', 'server_shortage'], true)) {
            throw new \RuntimeException('Mode terminal inconnu.');
        }
        $items = $this->listServerRequestItemsByRequest($requestId, $restaurantId);
        if ($items === []) {
            throw new \RuntimeException('Aucun article a conclure.');
        }
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();
        try {
            $updateItem = $pdo->prepare(
                'UPDATE server_request_items
                 SET sold_quantity = :sold_quantity,
                     returned_quantity = :returned_quantity,
                     returned_quantity_validated = :returned_quantity_validated,
                     sold_total = :sold_total,
                     returned_total = :returned_total,
                     server_loss_total = :server_loss_total,
                     total_requested_amount = requested_total,
                     total_supplied_amount = supplied_total,
                     total_sold_amount = :sold_total_amount,
                     status = :status,
                     decided_by = :decided_by,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $totalReturned = 0.0;
            $totalLoss = 0.0;
            foreach ($items as $item) {
                $soldQuantity = 0.0;
                $returnedQuantity = 0.0;
                $soldTotal = 0.0;
                $returnedTotal = 0.0;
                $serverLossTotal = 0.0;
                $updateItem->execute([
                    'sold_quantity' => $soldQuantity,
                    'returned_quantity' => $returnedQuantity,
                    'returned_quantity_validated' => $returnedQuantity,
                    'sold_total' => $soldTotal,
                    'returned_total' => $returnedTotal,
                    'server_loss_total' => $serverLossTotal,
                    'sold_total_amount' => $soldTotal,
                    'status' => 'CLOTURE',
                    'decided_by' => $actor['id'] ?? null,
                    'id' => (int) $item['id'],
                ]);
                $totalReturned += $returnedTotal;
                $totalLoss += $serverLossTotal;
            }

            $pdo->prepare(
                'UPDATE server_requests
                 SET status = "CLOTURE",
                     total_sold_amount = 0,
                     total_returned_amount = :total_returned_amount,
                     total_server_loss_amount = :total_server_loss_amount,
                     decided_by = :decided_by,
                     resolution_note = :resolution_note,
                     resolution_by = :resolution_by,
                     resolution_at = NOW(),
                     updated_at = NOW(),
                     closed_at = NOW()
                 WHERE id = :id AND restaurant_id = :restaurant_id'
            )->execute([
                'total_returned_amount' => $totalReturned,
                'total_server_loss_amount' => $totalLoss,
                'decided_by' => $actor['id'] ?? null,
                'resolution_note' => '[Responsable — sans fourniture] ' . $reason,
                'resolution_by' => $actor['id'] ?? null,
                'id' => $requestId,
                'restaurant_id' => $restaurantId,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $auditAction = $terminalMode === 'server_shortage'
            ? 'server_request_closed_manager_shortage'
            : 'server_request_closed_manager_no_sale';
        $auditJust = $terminalMode === 'server_shortage'
            ? 'Clôture responsable avec manquant (aucune fourniture cuisine)'
            : 'Clôture responsable sans vente (aucune fourniture cuisine)';
        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'] ?? null,
            'actor_name' => $actor['full_name'] ?? '',
            'actor_role_code' => $actor['role_code'] ?? '',
            'module_name' => 'sales',
            'action_name' => $auditAction,
            'entity_type' => 'server_requests',
            'entity_id' => (string) $requestId,
            'new_values' => [
                'terminal_zero_supply' => true,
                'operation' => $this->buildServerRequestOperationSnapshot(
                    $this->findServerRequest($requestId, $restaurantId) ?? [],
                    $this->serverRequestLineRowsForAudit($restaurantId, $requestId)
                ),
            ],
            'justification' => $auditJust . ($reason !== '' ? ' — ' . $reason : ''),
        ]);
    }

    /**
     * Aligne la demande serveur sur une vente déjà existante : pas de createSale, pas de nouvelle consommation stock.
     */
    private function syncServerRequestClosureFromExistingSale(int $restaurantId, int $requestId, int $saleId, array $actor): void
    {
        $pdo = $this->database->pdo();
        $saleSt = $pdo->prepare(
            'SELECT id, restaurant_id, origin_type, origin_id, total_amount
             FROM sales
             WHERE id = :id AND restaurant_id = :rid
             LIMIT 1'
        );
        $saleSt->execute(['id' => $saleId, 'rid' => $restaurantId]);
        $saleRow = $saleSt->fetch(PDO::FETCH_ASSOC);
        if ($saleRow === false) {
            throw new \RuntimeException('Vente liee introuvable.');
        }
        if ((string) ($saleRow['origin_type'] ?? '') !== 'server_request'
            || (int) ($saleRow['origin_id'] ?? 0) !== $requestId) {
            throw new \RuntimeException('La vente existante ne correspond pas a cette commande.');
        }

        $aggSt = $pdo->prepare(
            'SELECT menu_item_id, SUM(quantity) AS qty_sum
             FROM sale_items
             WHERE sale_id = :sid AND status = "SERVI"
             GROUP BY menu_item_id'
        );
        $aggSt->execute(['sid' => $saleId]);
        /** @var array<int, float> $remainingByMenu */
        $remainingByMenu = [];
        foreach ($aggSt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mid = (int) ($r['menu_item_id'] ?? 0);
            if ($mid <= 0) {
                continue;
            }
            $remainingByMenu[$mid] = (float) ($r['qty_sum'] ?? 0);
        }

        $items = $this->listServerRequestItemsByRequest($requestId, $restaurantId);
        if ($items === []) {
            throw new \RuntimeException('Aucun article a conclure.');
        }

        $pdo->beginTransaction();
        try {
            $updateItem = $pdo->prepare(
                'UPDATE server_request_items
                 SET sold_quantity = :sold_quantity,
                     returned_quantity = :returned_quantity,
                     returned_quantity_validated = :returned_quantity_validated,
                     sold_total = :sold_total,
                     returned_total = :returned_total,
                     server_loss_total = :server_loss_total,
                     total_requested_amount = requested_total,
                     total_supplied_amount = supplied_total,
                     total_sold_amount = :sold_total_amount,
                     status = :status,
                     decided_by = :decided_by,
                     updated_at = NOW()
                 WHERE id = :id'
            );

            $totalSold = 0.0;
            $totalReturned = 0.0;
            $totalServerLoss = 0.0;

            foreach ($items as $item) {
                $mid = (int) ($item['menu_item_id'] ?? 0);
                $suppliedQuantity = (float) ($item['supplied_quantity'] ?? 0);
                $unitPrice = (float) ($item['unit_price'] ?? 0);
                $avail = $remainingByMenu[$mid] ?? 0.0;
                $soldQuantity = min($suppliedQuantity, max(0.0, $avail));
                $remainingByMenu[$mid] = max(0.0, $avail - $soldQuantity);
                $unsoldSupplied = max(0.0, $suppliedQuantity - $soldQuantity);
                $returnedQuantity = 0.0;
                $serverLossTotal = $unsoldSupplied * $unitPrice;
                $soldTotal = $soldQuantity * $unitPrice;
                $returnedTotal = $returnedQuantity * $unitPrice;

                $updateItem->execute([
                    'sold_quantity' => $soldQuantity,
                    'returned_quantity' => $returnedQuantity,
                    'returned_quantity_validated' => $returnedQuantity,
                    'sold_total' => $soldTotal,
                    'returned_total' => $returnedTotal,
                    'server_loss_total' => $serverLossTotal,
                    'sold_total_amount' => $soldTotal,
                    'status' => 'CLOTURE',
                    'decided_by' => $actor['id'] ?? null,
                    'id' => (int) $item['id'],
                ]);

                $totalSold += $soldTotal;
                $totalReturned += $returnedTotal;
                $totalServerLoss += $serverLossTotal;
            }

            $pdo->prepare(
                'UPDATE server_requests
                 SET status = "CLOTURE",
                     total_sold_amount = :total_sold_amount,
                     total_returned_amount = :total_returned_amount,
                     total_server_loss_amount = :total_server_loss_amount,
                     decided_by = :decided_by,
                     updated_at = NOW(),
                     closed_at = NOW()
                 WHERE id = :id AND restaurant_id = :restaurant_id'
            )->execute([
                'total_sold_amount' => $totalSold,
                'total_returned_amount' => $totalReturned,
                'total_server_loss_amount' => $totalServerLoss,
                'decided_by' => $actor['id'] ?? null,
                'id' => $requestId,
                'restaurant_id' => $restaurantId,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'] ?? null,
            'actor_name' => $actor['full_name'] ?? '',
            'actor_role_code' => $actor['role_code'] ?? '',
            'module_name' => 'sales',
            'action_name' => 'server_request_synced_from_existing_sale',
            'entity_type' => 'server_requests',
            'entity_id' => (string) $requestId,
            'new_values' => [
                'sale_id' => $saleId,
                'operation' => $this->buildServerRequestOperationSnapshot(
                    $this->findServerRequest($requestId, $restaurantId) ?? [],
                    $this->serverRequestLineRowsForAudit($restaurantId, $requestId)
                ),
            ],
            'justification' => 'Alignement responsable sur vente existante (pas de nouvelle vente ni stock)',
        ]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function managerResolveServerRequest(int $restaurantId, int $requestId, string $mode, string $reason, array $actor, array $extra = []): void
    {
        $reason = trim($reason);
        if (!$this->isManagerResolutionActor($actor)) {
            throw new \RuntimeException('Action reservee au responsable (gerant, proprietaire ou super administrateur).');
        }
        if (!in_array($mode, ['served_sale', 'close_no_sale', 'server_shortage', 'reject_cancel'], true)) {
            throw new \RuntimeException('Decision responsable inconnue.');
        }
        if ($mode !== 'served_sale' && $reason === '') {
            throw new \RuntimeException('Motif obligatoire pour cette decision.');
        }
        $request = $this->findServerRequest($requestId, $restaurantId);
        if ($request === null) {
            throw new \RuntimeException('Demande serveur introuvable.');
        }
        $mr = Container::getInstance()->get('managerResolution');
        $mr->ensureResponsibleOutcomeColumns();
        if ($mr->serverRequestHasResponsibleOutcome($restaurantId, $requestId)) {
            throw new \RuntimeException('Cette demande a deja ete tranchee par un responsable.');
        }
        $serverUid = (int) ($request['server_id'] ?? 0);
        $st = (string) ($request['status'] ?? '');
        $beforeSnap = [
            'request_status_before' => $st,
            'total_supplied_before' => (float) ($request['total_supplied_amount'] ?? 0),
        ];
        if (in_array($st, ['ANNULE', 'REFUSE_CUISINE', 'CLOTURE', 'VENDU_TOTAL', 'VENDU_PARTIEL'], true)) {
            throw new \RuntimeException('Demande deja terminee ou annulee.');
        }
        if ($mode === 'reject_cancel') {
            $this->managerAnnulServerRequest($restaurantId, $requestId, $reason, $actor);
            $opSnap = $this->buildServerRequestOperationSnapshot($request, $this->serverRequestLineRowsForAudit($restaurantId, $requestId));
            $this->applyManagerRequestDisciplineExtras($restaurantId, $serverUid, $actor, $extra, $opSnap);

            return;
        }

        $supHeader = (float) ($request['total_supplied_amount'] ?? 0);
        $existingSaleId = $this->findLatestSaleIdByServerRequestOrigin($restaurantId, $requestId);

        if ($mode === 'served_sale' && $supHeader <= 0.0001 && $existingSaleId === null) {
            throw new \RuntimeException(
                'Sans fourniture cuisine validee ni vente existante : utilisez « Cloturer sans vente » ou « Mettre en manquant ».'
            );
        }

        if (($mode === 'close_no_sale' || $mode === 'server_shortage') && $supHeader <= 0.0001) {
            if ($existingSaleId !== null) {
                throw new \RuntimeException(
                    'Une vente est deja liee a cette commande : choisissez « Valider comme servie » pour aligner les statuts sans nouvelle vente.'
                );
            }
            $this->managerResponsibleTerminalCloseZeroSupply($restaurantId, $requestId, $mode, $reason, $actor);
            $afterReq = $this->findServerRequest($requestId, $restaurantId) ?? [];
            $operationSnapshot = $this->buildServerRequestOperationSnapshot(
                $afterReq,
                $this->serverRequestLineRowsForAudit($restaurantId, $requestId)
            );
            $outcome = $mode === 'server_shortage'
                ? ManagerResolutionService::OUTCOME_MANQUANT_GERANT
                : ManagerResolutionService::OUTCOME_CLOTURE_GERANT;
            $auditJust = $mode === 'server_shortage'
                ? 'Cloture responsable avec manquant (aucune fourniture cuisine)'
                : 'Cloture responsable sans vente (aucune fourniture cuisine)';
            $mr->markServerRequestResponsibleOutcome(
                $restaurantId,
                $requestId,
                $outcome,
                $actor,
                array_merge($beforeSnap, [
                    'request_status_after' => (string) ($afterReq['status'] ?? 'CLOTURE'),
                    'terminal_zero_supply' => true,
                    'shortage_mode' => $mode === 'server_shortage',
                ]),
            );
            Container::getInstance()->get('audit')->log([
                'restaurant_id' => $restaurantId,
                'user_id' => $actor['id'] ?? null,
                'actor_name' => $actor['full_name'] ?? '',
                'actor_role_code' => $actor['role_code'] ?? '',
                'module_name' => 'manager_resolution',
                'action_name' => 'server_request_responsible_terminal',
                'entity_type' => 'server_requests',
                'entity_id' => (string) $requestId,
                'old_values' => $beforeSnap,
                'new_values' => [
                    'outcome' => $outcome,
                    'terminal_zero_supply' => true,
                ],
                'justification' => $auditJust . ($reason !== '' ? ' — ' . $reason : ''),
            ]);
            if ($mode === 'server_shortage' && $serverUid > 0) {
                $items = $this->listServerRequestItemsByRequest($requestId, $restaurantId);
                $lossAmt = 0.0;
                $arts = [];
                foreach ($items as $item) {
                    $sup = (float) $item['supplied_quantity'];
                    $unit = (float) $item['unit_price'];
                    $lossAmt += $sup * $unit;
                    $arts[] = [
                        'menu_item_id' => (int) ($item['menu_item_id'] ?? 0),
                        'qty' => $sup,
                        'line_total' => $sup * $unit,
                    ];
                }
                if ($lossAmt > 0.0001) {
                    Container::getInstance()->get('managerResolution')->recordServerPayrollShortage(
                        $restaurantId,
                        $serverUid,
                        'server_request',
                        $requestId,
                        $lossAmt,
                        Container::getInstance()->get('reportService')->todayForRestaurant($restaurantId),
                        'server_shortage',
                        $reason,
                        isset($extra['imputation_basis']) && is_string($extra['imputation_basis']) ? $extra['imputation_basis'] : null,
                        $arts,
                        $actor,
                    );
                }
            }
            $this->applyManagerRequestDisciplineExtras($restaurantId, $serverUid, $actor, $extra, $operationSnapshot);

            return;
        }

        $needsReceiptConfirm = false;
        if ($mode === 'close_no_sale' || $mode === 'server_shortage') {
            $needsReceiptConfirm = true;
        } elseif ($mode === 'served_sale') {
            $needsReceiptConfirm = $existingSaleId === null || $supHeader > 0.0001;
        }
        if ($needsReceiptConfirm && in_array($st, ['PRET_A_SERVIR', 'FOURNI_PARTIEL', 'FOURNI_TOTAL'], true)) {
            $this->confirmServerRequestReceipt($restaurantId, $requestId, $actor);
        }

        $requestAfterReceipt = $this->findServerRequest($requestId, $restaurantId) ?? [];

        if ($mode === 'served_sale' && $existingSaleId !== null) {
            $this->syncServerRequestClosureFromExistingSale($restaurantId, $requestId, $existingSaleId, $actor);
            $requestFinal = $this->findServerRequest($requestId, $restaurantId) ?? [];
            $operationSnapshot = $this->buildServerRequestOperationSnapshot(
                $requestFinal,
                $this->serverRequestLineRowsForAudit($restaurantId, $requestId)
            );
            $mr->markServerRequestResponsibleOutcome(
                $restaurantId,
                $requestId,
                ManagerResolutionService::OUTCOME_VALIDE_GERANT,
                $actor,
                array_merge($beforeSnap, [
                    'request_status_after' => 'CLOTURE',
                    'sale_id' => $existingSaleId,
                    'sync_from_existing_sale' => true,
                    'clemency_requested' => ($extra['grant_clemency'] ?? '') === '1' || ($extra['grant_clemency'] ?? '') === 'on',
                ]),
            );
            Container::getInstance()->get('audit')->log([
                'restaurant_id' => $restaurantId,
                'user_id' => $actor['id'] ?? null,
                'actor_name' => $actor['full_name'] ?? '',
                'actor_role_code' => $actor['role_code'] ?? '',
                'module_name' => 'manager_resolution',
                'action_name' => 'server_request_responsible_terminal',
                'entity_type' => 'server_requests',
                'entity_id' => (string) $requestId,
                'old_values' => $beforeSnap,
                'new_values' => [
                    'outcome' => ManagerResolutionService::OUTCOME_VALIDE_GERANT,
                    'sale_id' => $existingSaleId,
                    'sync_from_existing_sale' => true,
                ],
                'justification' => $reason !== '' ? $reason : 'Validee comme servie (vente existante)',
            ]);
            $this->applyManagerRequestDisciplineExtras($restaurantId, $serverUid, $actor, $extra, $operationSnapshot);

            return;
        }

        $operationSnapshot = $this->buildServerRequestOperationSnapshot(
            $requestAfterReceipt,
            $this->serverRequestLineRowsForAudit($restaurantId, $requestId)
        );
        if ($mode === 'served_sale') {
            $this->closeServerRequestAsSale($restaurantId, $requestId, [
                'sale_type' => isset($extra['sale_type']) ? (string) $extra['sale_type'] : 'SUR_PLACE',
                'note' => $reason !== '' ? $reason : 'Regularisation responsable — validee comme servie',
                'sold_quantities' => is_array($extra['sold_quantities'] ?? null) ? $extra['sold_quantities'] : [],
                'returned_quantities' => is_array($extra['returned_quantities'] ?? null) ? $extra['returned_quantities'] : [],
            ], $actor);
            $saleIdFound = $this->findLatestSaleIdByServerRequestOrigin($restaurantId, $requestId);
            $mr->markServerRequestResponsibleOutcome(
                $restaurantId,
                $requestId,
                ManagerResolutionService::OUTCOME_VALIDE_GERANT,
                $actor,
                array_merge($beforeSnap, [
                    'request_status_after' => 'CLOTURE',
                    'sale_id' => $saleIdFound,
                    'clemency_requested' => ($extra['grant_clemency'] ?? '') === '1' || ($extra['grant_clemency'] ?? '') === 'on',
                ]),
            );
            Container::getInstance()->get('audit')->log([
                'restaurant_id' => $restaurantId,
                'user_id' => $actor['id'] ?? null,
                'actor_name' => $actor['full_name'] ?? '',
                'actor_role_code' => $actor['role_code'] ?? '',
                'module_name' => 'manager_resolution',
                'action_name' => 'server_request_responsible_terminal',
                'entity_type' => 'server_requests',
                'entity_id' => (string) $requestId,
                'old_values' => $beforeSnap,
                'new_values' => [
                    'outcome' => ManagerResolutionService::OUTCOME_VALIDE_GERANT,
                    'sale_id' => $saleIdFound,
                ],
                'justification' => $reason !== '' ? $reason : 'Validee comme servie',
            ]);
            $this->applyManagerRequestDisciplineExtras($restaurantId, $serverUid, $actor, $extra, $operationSnapshot);

            return;
        }
        $items = $this->listServerRequestItemsByRequest($requestId, $restaurantId);
        $soldQ = [];
        $retQ = [];
        foreach ($items as $item) {
            $id = (string) $item['id'];
            $sup = (float) $item['supplied_quantity'];
            if ($mode === 'close_no_sale') {
                $soldQ[$id] = 0;
                $retQ[$id] = $sup;
            } else {
                $soldQ[$id] = 0;
                $retQ[$id] = 0;
            }
        }
        $actionName = $mode === 'server_shortage' ? 'server_request_closed_manager_shortage' : 'server_request_closed_manager_no_sale';
        $auditJust = $mode === 'server_shortage'
            ? 'Cloture responsable avec manquant serveur (perte sur fourni)'
            : 'Cloture responsable sans vente (retour logique du fourni)';
        $this->closeServerRequest($restaurantId, $requestId, [
            'sale_type' => 'SUR_PLACE',
            'note' => $reason,
            'sold_quantities' => $soldQ,
            'returned_quantities' => $retQ,
            'action_name_override' => $actionName,
            'audit_justification' => $auditJust,
        ], $actor, false);
        $afterReq = $this->findServerRequest($requestId, $restaurantId) ?? [];
        $outcome = $mode === 'server_shortage' ? ManagerResolutionService::OUTCOME_MANQUANT_GERANT : ManagerResolutionService::OUTCOME_CLOTURE_GERANT;
        $mr->markServerRequestResponsibleOutcome(
            $restaurantId,
            $requestId,
            $outcome,
            $actor,
            array_merge($beforeSnap, [
                'request_status_after' => (string) ($afterReq['status'] ?? 'CLOTURE'),
                'shortage_mode' => $mode === 'server_shortage',
            ]),
        );
        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'] ?? null,
            'actor_name' => $actor['full_name'] ?? '',
            'actor_role_code' => $actor['role_code'] ?? '',
            'module_name' => 'manager_resolution',
            'action_name' => 'server_request_responsible_terminal',
            'entity_type' => 'server_requests',
            'entity_id' => (string) $requestId,
            'old_values' => $beforeSnap,
            'new_values' => ['outcome' => $outcome],
            'justification' => $auditJust,
        ]);
        if ($mode === 'server_shortage' && $serverUid > 0) {
            $lossAmt = 0.0;
            $arts = [];
            foreach ($items as $item) {
                $sup = (float) $item['supplied_quantity'];
                $unit = (float) $item['unit_price'];
                $lossAmt += $sup * $unit;
                $arts[] = [
                    'menu_item_id' => (int) ($item['menu_item_id'] ?? 0),
                    'qty' => $sup,
                    'line_total' => $sup * $unit,
                ];
            }
            if ($lossAmt > 0.0001) {
                Container::getInstance()->get('managerResolution')->recordServerPayrollShortage(
                    $restaurantId,
                    $serverUid,
                    'server_request',
                    $requestId,
                    $lossAmt,
                    Container::getInstance()->get('reportService')->todayForRestaurant($restaurantId),
                    'server_shortage',
                    $reason,
                    isset($extra['imputation_basis']) && is_string($extra['imputation_basis']) ? $extra['imputation_basis'] : null,
                    $arts,
                    $actor,
                );
            }
        }
        $this->applyManagerRequestDisciplineExtras($restaurantId, $serverUid, $actor, $extra, $operationSnapshot);
    }

    private function isManagerResolutionActor(array $actor): bool
    {
        return (($actor['scope'] ?? null) === 'super_admin')
            || in_array(($actor['role_code'] ?? null), ['manager', 'owner'], true);
    }

    /**
     * @param array<string, mixed> $extra
     * @param array<string, mixed> $operationSnapshot
     */
    private function applyManagerRequestDisciplineExtras(int $restaurantId, int $serverUserId, array $actor, array $extra, array $operationSnapshot): void
    {
        $grant = ($extra['grant_clemency'] ?? '') === '1' || ($extra['grant_clemency'] ?? '') === 'on';
        if ($grant) {
            $cr = trim((string) ($extra['clemency_reason'] ?? ''));
            Container::getInstance()->get('staffDiscipline')->grantDisciplinaryClemency(
                $restaurantId,
                $serverUserId,
                $cr,
                $actor,
                ['server_resolution' => $operationSnapshot],
            );

            return;
        }
        if ($serverUserId > 0) {
            Container::getInstance()->get('staffDiscipline')->recordManagerRegularizationPreservesPenalty(
                $restaurantId,
                $serverUserId,
                $operationSnapshot,
                'server_request_manager',
            );
        }
    }

    public function managerAnnulServerRequest(int $restaurantId, int $requestId, string $reason, array $actor): void
    {
        if (!$this->isManagerResolutionActor($actor)) {
            throw new \RuntimeException('Action reservee au responsable.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new \RuntimeException('Motif obligatoire.');
        }
        $request = $this->findServerRequest($requestId, $restaurantId);
        if ($request === null) {
            throw new \RuntimeException('Demande serveur introuvable.');
        }
        $mr = Container::getInstance()->get('managerResolution');
        $mr->ensureResponsibleOutcomeColumns();
        if ($mr->serverRequestHasResponsibleOutcome($restaurantId, $requestId)) {
            throw new \RuntimeException('Cette demande a deja ete tranchee par un responsable.');
        }
        $st = (string) ($request['status'] ?? '');
        if (in_array($st, ['ANNULE', 'CLOTURE', 'VENDU_TOTAL', 'VENDU_PARTIEL'], true)) {
            throw new \RuntimeException('Annulation responsable impossible sur ce statut.');
        }
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE server_request_items
                 SET status = "ANNULE",
                     supply_status = "NON_FOURNI",
                     supplied_quantity = 0,
                     supplied_total = 0,
                     total_supplied_amount = 0,
                     unavailable_quantity = requested_quantity,
                     updated_at = NOW()
                 WHERE request_id = :request_id'
            )->execute(['request_id' => $requestId]);
            $pdo->prepare(
                'UPDATE server_requests
                 SET status = "ANNULE",
                     total_supplied_amount = 0,
                     resolution_note = :resolution_note,
                     resolution_by = :resolution_by,
                     resolution_at = NOW(),
                     updated_at = NOW(),
                     closed_at = NOW()
                 WHERE id = :id AND restaurant_id = :restaurant_id'
            )->execute([
                'resolution_note' => '[Responsable] ' . $reason,
                'resolution_by' => $actor['id'],
                'id' => $requestId,
                'restaurant_id' => $restaurantId,
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'] ?? null,
            'actor_name' => $actor['full_name'] ?? '',
            'actor_role_code' => $actor['role_code'] ?? '',
            'module_name' => 'sales',
            'action_name' => 'server_request_manager_annulled',
            'entity_type' => 'server_requests',
            'entity_id' => (string) $requestId,
            'new_values' => [
                'status' => 'ANNULE',
                'resolution_note' => $reason,
            ],
            'justification' => 'Annulation / rejet commande par responsable',
        ]);
        $mr->markServerRequestResponsibleOutcome(
            $restaurantId,
            $requestId,
            ManagerResolutionService::OUTCOME_REJET_GERANT,
            $actor,
            [
                'request_status_before' => $st,
                'request_status_after' => 'ANNULE',
                'motif' => $reason,
            ],
        );
    }

    /**
     * Vente déjà créée pour cette commande serveur (garde-fou anti double vente / double stock).
     */
    public function latestSaleIdLinkedToServerRequest(int $restaurantId, int $requestId): ?int
    {
        return $this->findLatestSaleIdByServerRequestOrigin($restaurantId, $requestId);
    }

    private function findLatestSaleIdByServerRequestOrigin(int $restaurantId, int $requestId): ?int
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT id FROM sales
             WHERE restaurant_id = :rid AND origin_type = "server_request" AND origin_id = :oid
             ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(['rid' => $restaurantId, 'oid' => $requestId]);
        $v = $statement->fetchColumn();

        return is_numeric($v) ? (int) $v : null;
    }

    private function serverRequestHasResponsibleOutcomeColumn(): bool
    {
        if ($this->serverRequestResponsibleOutcomeColumn !== null) {
            return $this->serverRequestResponsibleOutcomeColumn;
        }
        $databaseName = (string) ($this->database->config()['database'] ?? '');
        if ($databaseName === '') {
            $this->serverRequestResponsibleOutcomeColumn = false;

            return false;
        }
        $statement = $this->database->pdo()->prepare(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = :database_name
               AND TABLE_NAME = "server_requests"
               AND COLUMN_NAME = "responsible_outcome_code"'
        );
        $statement->execute(['database_name' => $databaseName]);
        $this->serverRequestResponsibleOutcomeColumn = ((int) $statement->fetchColumn()) > 0;

        return $this->serverRequestResponsibleOutcomeColumn;
    }

    private function serverRequestColumnExists(string $columnName): bool
    {
        if (array_key_exists($columnName, $this->serverRequestColumnExists)) {
            return $this->serverRequestColumnExists[$columnName];
        }

        $databaseName = (string) ($this->database->config()['database'] ?? '');
        if ($databaseName === '') {
            $this->serverRequestColumnExists[$columnName] = false;

            return false;
        }

        $statement = $this->database->pdo()->prepare(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = :database_name
               AND TABLE_NAME = "server_requests"
               AND COLUMN_NAME = :column_name'
        );
        $statement->execute([
            'database_name' => $databaseName,
            'column_name' => $columnName,
        ]);
        $this->serverRequestColumnExists[$columnName] = ((int) $statement->fetchColumn()) > 0;

        return $this->serverRequestColumnExists[$columnName];
    }

    /**
     * @param list<int> $requestIds
     */
    private function countSalesLinkedToRequests(int $restaurantId, array $requestIds): int
    {
        $requestIds = array_values(array_filter(array_map('intval', $requestIds), static fn (int $v): bool => $v > 0));
        if ($requestIds === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($requestIds), '?'));
        $statement = $this->database->pdo()->prepare(
            'SELECT COUNT(*) FROM sales
             WHERE restaurant_id = ? AND origin_type = "server_request" AND origin_id IN (' . $in . ')'
        );
        $statement->execute(array_merge([$restaurantId], $requestIds));

        return (int) $statement->fetchColumn();
    }

    private function findServerRequest(int $requestId, int $restaurantId): ?array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT *
             FROM server_requests
             WHERE id = :id AND restaurant_id = :restaurant_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $requestId,
            'restaurant_id' => $restaurantId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function listServerRequestItemsByRequest(int $requestId, int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT sri.*
             FROM server_request_items sri
             INNER JOIN server_requests sr ON sr.id = sri.request_id
             WHERE sri.request_id = :request_id
               AND sr.restaurant_id = :restaurant_id
             ORDER BY sri.id ASC'
        );
        $statement->execute([
            'request_id' => $requestId,
            'restaurant_id' => $restaurantId,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function findProductionInRestaurant(int $productionId, int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT *
             FROM kitchen_production
             WHERE id = :id AND restaurant_id = :restaurant_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $productionId,
            'restaurant_id' => $restaurantId,
        ]);
        $production = $statement->fetch(PDO::FETCH_ASSOC);

        if ($production === false) {
            throw new \RuntimeException('Production cuisine hors perimetre restaurant.');
        }

        return $production;
    }

    private function findSaleItemInRestaurant(int $saleItemId, int $restaurantId): ?array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT si.*
             FROM sale_items si
             INNER JOIN sales s ON s.id = si.sale_id
             WHERE si.id = :id AND s.restaurant_id = :restaurant_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $saleItemId,
            'restaurant_id' => $restaurantId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}
