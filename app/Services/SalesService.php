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
    public function __construct(private readonly Database $database)
    {
    }

    public function listSales(int $restaurantId, ?int $serverId = null): array
    {
        $sql = 'SELECT s.*, u.full_name AS server_name
                FROM sales s
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
        $sql = 'SELECT sr.*,
                       u.full_name AS server_name,
                       ready_user.full_name AS ready_by_name,
                       received_user.full_name AS received_by_name,
                       resolution_user.full_name AS resolution_by_name
                FROM server_requests sr
                INNER JOIN users u ON u.id = sr.server_id
                LEFT JOIN users ready_user ON ready_user.id = sr.ready_by
                LEFT JOIN users received_user ON received_user.id = sr.received_by
                LEFT JOIN users resolution_user ON resolution_user.id = sr.resolution_by
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
        $sql = 'SELECT sri.*,
                       sr.status AS request_status,
                       sr.server_id,
                       sr.note AS request_note,
                       sr.service_reference,
                       sr.created_at AS request_created_at,
                       sr.ready_at AS request_ready_at,
                       sr.received_at AS request_received_at,
                       sr.resolution_note AS request_resolution_note,
                       sr.resolution_at AS request_resolution_at,
                       u.full_name AS server_name,
                       mi.name AS menu_item_name,
                       mi.image_url AS menu_item_image_url,
                       prepared_user.full_name AS prepared_by_name,
                       received_user.full_name AS received_by_name,
                       resolution_actor.full_name AS resolution_by_name
                FROM server_request_items sri
                INNER JOIN server_requests sr ON sr.id = sri.request_id
                INNER JOIN menu_items mi ON mi.id = sri.menu_item_id
                INNER JOIN users u ON u.id = sr.server_id
                LEFT JOIN users prepared_user ON prepared_user.id = sri.technical_confirmed_by
                LEFT JOIN users received_user ON received_user.id = sri.received_by
                LEFT JOIN users resolution_actor ON resolution_actor.id = sr.resolution_by
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

        return [
            'today_total_sold' => $this->salesTotalForPeriod($restaurantId, $todayStart, $todayEnd, $serverId),
            'today_sales_count' => $this->salesCountForPeriod($restaurantId, $todayStart, $todayEnd, $serverId),
            'active_requests_count' => $this->serverRequestCountByStatuses($restaurantId, ['DEMANDE', 'EN_PREPARATION', 'PRET_A_SERVIR', 'FOURNI_PARTIEL', 'FOURNI_TOTAL'], $serverId),
            'remitted_requests_count' => $this->serverRequestCountByStatuses($restaurantId, ['REMIS_SERVEUR'], $serverId),
            'today_label' => $todayStart->format('Y-m-d'),
        ];
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

    public function createServerRequest(int $restaurantId, array $payload, array $actor): void
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

            $lineIdsStmt = $this->database->pdo()->prepare(
                'SELECT id FROM server_request_items WHERE request_id = :rid ORDER BY id ASC'
            );
            $lineIdsStmt->execute(['rid' => $requestId]);
            $lineIds = array_map(static fn ($v): int => (int) $v, $lineIdsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
            $kitchenService = Container::getInstance()->get('kitchenService');
            foreach ($normalizedItems as $idx => $norm) {
                if (!menu_line_is_beverage(
                    isset($norm['menu_category_name']) ? (string) $norm['menu_category_name'] : '',
                    isset($norm['menu_category_slug']) ? (string) $norm['menu_category_slug'] : '',
                )) {
                    continue;
                }
                $lid = $lineIds[$idx] ?? 0;
                if ($lid <= 0) {
                    continue;
                }
                try {
                    $kitchenService->autoFulfillBeverageServerLine($restaurantId, $lid, $actor);
                } catch (\Throwable $e) {
                    error_log('[badiboss] autoFulfillBeverage after create request_line=' . $lid . ' ' . $e->getMessage());
                }
            }
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

        $role = (string) ($actor['role_code'] ?? '');
        if (!in_array($role, ['kitchen', 'manager'], true)) {
            throw new \RuntimeException('Seule la cuisine (ou le gerant) peut decliner une demande serveur.');
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
        if (!$isSystemActor && (int) $request['server_id'] !== (int) ($actor['id'] ?? 0) && ($actor['role_code'] ?? null) !== 'manager') {
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

        if (!in_array((string) $request['status'], ['REMIS_SERVEUR', 'VENDU_PARTIEL', 'VENDU_TOTAL'], true)) {
            throw new \RuntimeException('La demande doit d abord etre remise au serveur avant la cloture.');
        }
        if ((float) $request['total_supplied_amount'] <= 0) {
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
            Container::getInstance()->get('audit')->log([
                'restaurant_id' => $restaurantId,
                'user_id' => $actor['id'] ?? null,
                'actor_name' => $actor['full_name'],
                'actor_role_code' => $actor['role_code'],
                'module_name' => 'sales',
                'action_name' => $automatic ? 'server_request_auto_closed_as_sale' : 'server_request_closed_as_sale',
                'entity_type' => 'server_requests',
                'entity_id' => (string) $requestId,
                'new_values' => array_merge($payload, [
                    'automatic' => $automatic,
                    'operation' => $operationSnapshot,
                ]),
                'justification' => $automatic
                    ? 'Cloture automatique d une remise serveur depassee'
                    : 'Cloture demande serveur en vente reelle',
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

        if ((int) $request['server_id'] !== (int) $actor['id'] && ($actor['role_code'] ?? null) !== 'manager') {
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

    public function reconcileOverdueReturnsToAutomaticSales(int $restaurantId): int
    {
        $count = $this->reconcileOverdueServerClosures($restaurantId);
        $count += Container::getInstance()->get('cashService')->reconcileOverdueCashierReceipts($restaurantId);
        $stockService = Container::getInstance()->get('stockService');
        $count += $stockService->reconcileExpiredKitchenStockRequests($restaurantId);
        $count += $stockService->reconcileAutoKitchenStockReceipts($restaurantId);
        $overdue = $stockService->reconcileOverdueKitchenIssues($restaurantId);

        foreach ($overdue as $row) {
            if ((float) $row['quantity_remaining'] <= 0 || (int) $row['menu_item_id'] <= 0) {
                continue;
            }

            $menuItem = $this->findMenuItem((int) $row['menu_item_id']);
            if ($menuItem === null) {
                continue;
            }

            $this->createAutomaticSale($restaurantId, [
                'menu_item_id' => (int) $row['menu_item_id'],
                'kitchen_production_id' => (int) $row['production_id'],
                'quantity' => (float) $row['quantity_remaining'],
                'unit_price' => (float) $menuItem['price'],
            ]);

            $count++;
        }

        return $count;
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
        $sql = 'SELECT COALESCE(SUM(total_amount), 0)
                FROM sales
                WHERE restaurant_id = :restaurant_id
                  AND COALESCE(validated_at, created_at) >= :start_at
                  AND COALESCE(validated_at, created_at) < :end_at';
        $params = [
            'restaurant_id' => $restaurantId,
            'start_at' => $startAt->format('Y-m-d H:i:s'),
            'end_at' => $endAt->format('Y-m-d H:i:s'),
        ];

        if ($serverId !== null) {
            $sql .= ' AND server_id = :server_id';
            $params['server_id'] = $serverId;
        }

        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($params);

        return (float) ($statement->fetchColumn() ?: 0);
    }

    private function salesCountForPeriod(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt, ?int $serverId = null): int
    {
        $sql = 'SELECT COUNT(*)
                FROM sales
                WHERE restaurant_id = :restaurant_id
                  AND COALESCE(validated_at, created_at) >= :start_at
                  AND COALESCE(validated_at, created_at) < :end_at';
        $params = [
            'restaurant_id' => $restaurantId,
            'start_at' => $startAt->format('Y-m-d H:i:s'),
            'end_at' => $endAt->format('Y-m-d H:i:s'),
        ];

        if ($serverId !== null) {
            $sql .= ' AND server_id = :server_id';
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
             LEFT JOIN users u ON u.id = s.server_id
             WHERE s.restaurant_id = :restaurant_id
               AND COALESCE(s.validated_at, s.created_at) >= :start_at
               AND COALESCE(s.validated_at, s.created_at) < :end_at
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
