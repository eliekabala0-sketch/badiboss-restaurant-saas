<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Container;
use App\Core\Database;
use PDO;

final class IncidentService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function listCases(int $restaurantId, ?string $sourceModule = null): array
    {
        $sql = 'SELECT oc.*,
                       s.full_name AS signaled_by_name,
                       t.full_name AS technical_confirmed_by_name,
                       d.full_name AS decided_by_name,
                       ru.full_name AS responsible_user_name,
                       sm.full_name AS submitted_to_manager_by_name,
                       si.name AS stock_item_name
                FROM operation_cases oc
                LEFT JOIN users s ON s.id = oc.signaled_by
                LEFT JOIN users t ON t.id = oc.technical_confirmed_by
                LEFT JOIN users d ON d.id = oc.decided_by
                LEFT JOIN users ru ON ru.id = oc.responsible_user_id
                LEFT JOIN users sm ON sm.id = oc.submitted_to_manager_by
                LEFT JOIN stock_items si ON si.id = oc.stock_item_id
                WHERE oc.restaurant_id = :restaurant_id';
        $params = ['restaurant_id' => $restaurantId];

        if ($sourceModule !== null) {
            $sql .= ' AND oc.source_module = :source_module';
            $params['source_module'] = $sourceModule;
        }

        $sql .= ' ORDER BY oc.id DESC';
        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listManagerDecisionQueue(int $restaurantId): array
    {
        $cases = array_values(array_filter(
            $this->listCases($restaurantId),
            static fn (array $case): bool => (string) ($case['status'] ?? '') === 'EN_ATTENTE_VALIDATION_MANAGER'
                && empty($case['decided_by'])
        ));

        return array_map(
            fn (array $case): array => $this->enrichCaseWithDecisionContext($restaurantId, $case),
            $cases
        );
    }

    public function listRecentDecisions(int $restaurantId, int $limit = 10): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT oc.*,
                    s.full_name AS signaled_by_name,
                    t.full_name AS technical_confirmed_by_name,
                    d.full_name AS decided_by_name,
                    ru.full_name AS responsible_user_name,
                    sm.full_name AS submitted_to_manager_by_name,
                    si.name AS stock_item_name
             FROM operation_cases oc
             LEFT JOIN users s ON s.id = oc.signaled_by
             LEFT JOIN users t ON t.id = oc.technical_confirmed_by
             LEFT JOIN users d ON d.id = oc.decided_by
             LEFT JOIN users ru ON ru.id = oc.responsible_user_id
             LEFT JOIN users sm ON sm.id = oc.submitted_to_manager_by
             LEFT JOIN stock_items si ON si.id = oc.stock_item_id
             WHERE oc.restaurant_id = :restaurant_id
               AND oc.decided_by IS NOT NULL
             ORDER BY oc.decided_at DESC, oc.id DESC
             LIMIT ' . max(1, (int) $limit)
        );
        $statement->execute(['restaurant_id' => $restaurantId]);
        $cases = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            fn (array $case): array => $this->enrichCaseWithDecisionContext($restaurantId, $case),
            $cases
        );
    }

    public function signalSaleIncident(int $restaurantId, array $payload, array $actor): int
    {
        $saleItem = $this->findSaleItemInRestaurant((int) $payload['sale_item_id'], $restaurantId);
        if ($saleItem === null) {
            throw new \RuntimeException('Article de vente introuvable.');
        }

        $quantity = $this->assertPositiveQuantity($payload['quantity_affected'] ?? 0);
        if ($quantity > (float) $saleItem['quantity']) {
            throw new \RuntimeException('La quantite signalee depasse la quantite vendue.');
        }

        $stockLink = $this->resolveSaleItemStockLink($saleItem);
        $caseId = $this->insertCase($restaurantId, [
            'case_type' => 'SALE_RETURN',
            'reported_category' => trim((string) ($payload['reported_category'] ?? 'retour_simple')) ?: 'retour_simple',
            'source_module' => 'sales',
            'source_entity_type' => 'sale_items',
            'source_entity_id' => (int) $payload['sale_item_id'],
            'stock_item_id' => $stockLink['stock_item_id'],
            'quantity_affected' => $quantity,
            'unit_name' => $stockLink['unit_name'] ?? 'portion',
            'status' => 'PROPOSE',
            'signaled_by' => $actor['id'],
            'signal_notes' => trim((string) $payload['signal_notes']),
        ], $actor, 'sale_incident_signaled');

        $this->refreshCaseTraceSnapshot($restaurantId, $caseId);

        return $caseId;
    }

    public function signalKitchenIncident(int $restaurantId, array $payload, array $actor): int
    {
        $production = $this->findProductionInRestaurant((int) $payload['production_id'], $restaurantId);
        if ($production === null) {
            throw new \RuntimeException('Production cuisine introuvable.');
        }

        $quantity = $this->assertPositiveQuantity($payload['quantity_affected'] ?? 0);
        if ($quantity > (float) $production['quantity_remaining']) {
            throw new \RuntimeException('La quantite signalee depasse le reste disponible en cuisine.');
        }

        $caseId = $this->insertCase($restaurantId, [
            'case_type' => 'KITCHEN_INCIDENT',
            'reported_category' => trim((string) ($payload['reported_category'] ?? 'produit_defectueux')) ?: 'produit_defectueux',
            'source_module' => 'kitchen',
            'source_entity_type' => 'kitchen_production',
            'source_entity_id' => (int) $payload['production_id'],
            'stock_item_id' => (int) $production['stock_item_id'],
            'quantity_affected' => $quantity,
            'unit_name' => (string) $production['unit_name'],
            'status' => 'PROPOSE',
            'signaled_by' => $actor['id'],
            'signal_notes' => trim((string) $payload['signal_notes']),
        ], $actor, 'kitchen_incident_signaled');

        $this->refreshCaseTraceSnapshot($restaurantId, $caseId);

        return $caseId;
    }

    public function signalDamagedStockReturn(int $restaurantId, array $payload, array $actor): int
    {
        $stockItem = $this->findStockItemInRestaurant((int) $payload['stock_item_id'], $restaurantId);
        if ($stockItem === null) {
            throw new \RuntimeException('Article de stock introuvable.');
        }

        $quantity = $this->assertPositiveQuantity($payload['quantity_affected'] ?? 0);
        if ($quantity > (float) $stockItem['quantity_in_stock']) {
            throw new \RuntimeException('La quantite signalee depasse le stock disponible.');
        }

        $caseId = $this->insertCase($restaurantId, [
            'case_type' => 'STOCK_DAMAGE',
            'reported_category' => trim((string) ($payload['reported_category'] ?? 'retour_stock_endommage')) ?: 'retour_stock_endommage',
            'source_module' => 'stock',
            'source_entity_type' => 'stock_items',
            'source_entity_id' => (int) $payload['stock_item_id'],
            'stock_item_id' => (int) $payload['stock_item_id'],
            'quantity_affected' => $quantity,
            'unit_name' => (string) $stockItem['unit_name'],
            'status' => 'PROPOSE',
            'signaled_by' => $actor['id'],
            'signal_notes' => trim((string) $payload['signal_notes']),
        ], $actor, 'stock_damaged_return_signaled');

        $this->refreshCaseTraceSnapshot($restaurantId, $caseId);

        return $caseId;
    }

    public function signalKitchenStockIncident(int $restaurantId, int $requestId, array $payload, array $actor): int
    {
        $request = $this->findKitchenStockRequestWithContext($requestId, $restaurantId);
        $quantity = $this->assertPositiveQuantity($payload['quantity_affected'] ?? 0);
        if ($quantity > (float) $request['quantity_requested']) {
            throw new \RuntimeException('La quantite soumise au gerant depasse la demande cuisine.');
        }

        $caseId = $this->insertCase($restaurantId, [
            'case_type' => 'STOCK_REQUEST_DISPUTE',
            'reported_category' => trim((string) ($payload['reported_category'] ?? 'litige_stock')) ?: 'litige_stock',
            'source_module' => 'stock',
            'source_entity_type' => 'kitchen_stock_requests',
            'source_entity_id' => $requestId,
            'stock_item_id' => (int) $request['stock_item_id'],
            'quantity_affected' => $quantity,
            'unit_name' => (string) $request['unit_name'],
            'status' => 'EN_ATTENTE_VALIDATION_MANAGER',
            'signaled_by' => $actor['id'],
            'signal_notes' => trim((string) ($payload['signal_notes'] ?? $request['response_note'] ?? $request['note'] ?? '')),
        ], $actor, 'kitchen_stock_incident_submitted');

        $traceJson = $this->buildTraceSnapshotJson($restaurantId, $this->findCase($caseId, $restaurantId) ?? []);
        $statement = $this->database->pdo()->prepare(
            'UPDATE operation_cases
             SET submitted_to_manager_by = :submitted_to_manager_by,
                 submitted_to_manager_at = NOW(),
                 trace_snapshot_json = :trace_snapshot_json
             WHERE id = :id AND restaurant_id = :restaurant_id'
        );
        $statement->execute([
            'submitted_to_manager_by' => $actor['id'],
            'trace_snapshot_json' => $traceJson,
            'id' => $caseId,
            'restaurant_id' => $restaurantId,
        ]);

        return $caseId;
    }

    public function confirmKitchenCase(int $restaurantId, int $caseId, array $payload, array $actor): void
    {
        $case = $this->findCase($caseId, $restaurantId);
        if ($case === null) {
            throw new \RuntimeException('Cas introuvable.');
        }
        if (($case['technical_confirmed_by'] ?? null) !== null) {
            throw new \RuntimeException('Ce cas a deja ete confirme techniquement.');
        }
        if ((float) ($case['quantity_affected'] ?? 0) <= 0) {
            throw new \RuntimeException('Aucune confirmation sans quantite impactee.');
        }

        $technicalOutcome = (string) $payload['technical_outcome'];
        $technicalNotes = trim((string) ($payload['technical_notes'] ?? ''));
        if ($technicalNotes === '') {
            throw new \RuntimeException('Le constat technique est obligatoire.');
        }

        $status = $technicalOutcome === 'retour_simple' ? 'VALIDE' : 'EN_ATTENTE_VALIDATION_MANAGER';
        $traceJson = $this->buildTraceSnapshotJson($restaurantId, $case);

        $statement = $this->database->pdo()->prepare(
            'UPDATE operation_cases
             SET status = :status,
                 final_qualification = CASE WHEN :status = "VALIDE" THEN "retour_simple" ELSE final_qualification END,
                 decision = CASE WHEN :status = "VALIDE" THEN "retour_simple" ELSE decision END,
                 technical_confirmed_by = :technical_confirmed_by,
                 technical_notes = :technical_notes,
                 technical_confirmed_at = NOW(),
                 submitted_to_manager_by = CASE WHEN :status = "EN_ATTENTE_VALIDATION_MANAGER" THEN :technical_confirmed_by ELSE submitted_to_manager_by END,
                 submitted_to_manager_at = CASE WHEN :status = "EN_ATTENTE_VALIDATION_MANAGER" THEN NOW() ELSE submitted_to_manager_at END,
                 trace_snapshot_json = :trace_snapshot_json,
                 validated_by = CASE WHEN :status = "VALIDE" THEN :technical_confirmed_by ELSE validated_by END,
                 resolved_by = CASE WHEN :status = "VALIDE" THEN :technical_confirmed_by ELSE resolved_by END,
                 validated_at = CASE WHEN :status = "VALIDE" THEN NOW() ELSE validated_at END,
                 resolved_at = CASE WHEN :status = "VALIDE" THEN NOW() ELSE resolved_at END
             WHERE id = :id AND restaurant_id = :restaurant_id'
        );
        $statement->execute([
            'status' => $status,
            'technical_confirmed_by' => $actor['id'],
            'technical_notes' => $technicalNotes,
            'trace_snapshot_json' => $traceJson,
            'id' => $caseId,
            'restaurant_id' => $restaurantId,
        ]);

        if ($technicalOutcome === 'retour_simple' && $case['source_entity_type'] === 'sale_items') {
            $this->applySaleReturn(
                $restaurantId,
                (int) $case['source_entity_id'],
                $actor['id'],
                (float) $case['quantity_affected'],
                'retour_simple',
                true
            );
        }

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'incidents',
            'action_name' => 'incident_technical_confirmed',
            'entity_type' => 'operation_cases',
            'entity_id' => (string) $caseId,
            'new_values' => ['technical_outcome' => $technicalOutcome, 'technical_notes' => $technicalNotes],
            'justification' => 'Confirmation technique cuisine',
        ]);
    }

    public function decideCase(int $restaurantId, int $caseId, array $payload, array $actor): void
    {
        $case = $this->findCase($caseId, $restaurantId);
        if ($case === null) {
            throw new \RuntimeException('Cas introuvable.');
        }
        if (($case['decided_by'] ?? null) !== null) {
            throw new \RuntimeException('Ce cas a deja ete tranche.');
        }

        $quantity = (float) ($case['quantity_affected'] ?? 0);
        if ($quantity <= 0) {
            throw new \RuntimeException('Aucune validation sans quantite impactee.');
        }

        $qualification = trim((string) $payload['final_qualification']);
        $justification = trim((string) $payload['manager_justification']);
        $decisionStatus = strtoupper(trim((string) ($payload['decision_status'] ?? 'VALIDE')));
        if ($qualification === '' || $justification === '') {
            throw new \RuntimeException('Decision manager et justification obligatoires.');
        }
        if (!in_array($decisionStatus, ['VALIDE', 'REJETE'], true)) {
            throw new \RuntimeException('Statut final invalide.');
        }

        if ($case['source_module'] === 'sales'
            && ($case['stock_item_id'] ?? null) !== null
            && ($case['technical_confirmed_by'] ?? null) === null
        ) {
            throw new \RuntimeException('Confirmation technique cuisine obligatoire avant arbitrage manager.');
        }
        if ((string) ($case['status'] ?? '') !== 'EN_ATTENTE_VALIDATION_MANAGER') {
            throw new \RuntimeException('Seuls les cas transmis au gerant peuvent etre tranches ici.');
        }

        $decisionContext = $this->enrichCaseWithDecisionContext($restaurantId, $case);
        $responsibility = $this->resolveResponsibilityAssignment($payload, $decisionContext['linked_actors']);
        $materialLoss = max(0.0, (float) ($payload['material_loss_amount'] ?? 0));
        $cashLoss = max(0.0, (float) ($payload['cash_loss_amount'] ?? 0));

        $pdo = $this->database->pdo();
        $pdo->beginTransaction();

        try {
            if ($decisionStatus === 'VALIDE') {
                $this->assertDecisionConsistency($restaurantId, $case, $qualification, $materialLoss, $cashLoss);
                $this->applyOperationalImpact($restaurantId, $case, $qualification, $materialLoss, $cashLoss, $actor);
            }

            $traceJson = $this->buildTraceSnapshotJson($restaurantId, $case);
            $statement = $pdo->prepare(
                'UPDATE operation_cases
                 SET status = :status,
                     final_qualification = :final_qualification,
                     decision = :decision,
                     responsibility_scope = :responsibility_scope,
                     responsible_user_id = :responsible_user_id,
                     trace_snapshot_json = :trace_snapshot_json,
                     material_loss_amount = :material_loss_amount,
                     cash_loss_amount = :cash_loss_amount,
                     validated_by = CASE WHEN :status = "VALIDE" THEN :decided_by ELSE validated_by END,
                     decided_by = :decided_by,
                     resolved_by = :resolved_by,
                     manager_justification = :manager_justification,
                     decided_at = NOW(),
                     validated_at = CASE WHEN :status = "VALIDE" THEN NOW() ELSE validated_at END,
                     resolved_at = NOW()
                 WHERE id = :id AND restaurant_id = :restaurant_id'
            );
            $statement->execute([
                'status' => $decisionStatus,
                'final_qualification' => $qualification,
                'decision' => $qualification,
                'responsibility_scope' => $responsibility['responsibility_scope'],
                'responsible_user_id' => $responsibility['responsible_user_id'],
                'trace_snapshot_json' => $traceJson,
                'material_loss_amount' => $materialLoss,
                'cash_loss_amount' => $cashLoss,
                'resolved_by' => $actor['id'],
                'decided_by' => $actor['id'],
                'manager_justification' => $justification,
                'id' => $caseId,
                'restaurant_id' => $restaurantId,
            ]);

            if ($materialLoss > 0) {
                $this->createLoss($restaurantId, 'MATIERE_PREMIERE', $caseId, $justification, $materialLoss, $actor['id']);
            }

            if ($cashLoss > 0) {
                $this->createLoss($restaurantId, 'ARGENT', $caseId, $justification, $cashLoss, $actor['id']);
            }

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
            'module_name' => 'incidents',
            'action_name' => 'incident_manager_decided',
            'entity_type' => 'operation_cases',
            'entity_id' => (string) $caseId,
            'new_values' => [
                'decision_status' => $decisionStatus,
                'final_qualification' => $qualification,
                'responsibility_scope' => $responsibility['responsibility_scope'],
                'responsible_user_id' => $responsibility['responsible_user_id'],
                'material_loss_amount' => $materialLoss,
                'cash_loss_amount' => $cashLoss,
            ],
            'justification' => $justification,
        ]);
    }

    private function enrichCaseWithDecisionContext(int $restaurantId, array $case): array
    {
        $snapshot = $this->decodeSnapshot($case['trace_snapshot_json'] ?? null);
        $trace = $snapshot !== [] ? $snapshot : $this->buildCaseTrace($restaurantId, $case);
        $linkedActors = $this->extractLinkedActors($trace, $case);

        $case['trace'] = $trace;
        $case['linked_actors'] = $linkedActors;

        return $case;
    }

    private function buildTraceSnapshotJson(int $restaurantId, array $case): string
    {
        $trace = $this->buildCaseTrace($restaurantId, $case);

        return json_encode($trace, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function buildCaseTrace(int $restaurantId, array $case): array
    {
        return match ((string) ($case['source_entity_type'] ?? '')) {
            'sale_items' => $this->buildSaleCaseTrace($restaurantId, $case),
            'kitchen_stock_requests' => $this->buildKitchenStockCaseTrace($restaurantId, $case),
            'kitchen_production' => $this->buildKitchenProductionCaseTrace($restaurantId, $case),
            'stock_items' => $this->buildStockItemCaseTrace($restaurantId, $case),
            default => $this->buildFallbackCaseTrace($case),
        };
    }

    private function buildSaleCaseTrace(int $restaurantId, array $case): array
    {
        $saleItem = $this->findSaleTraceSource((int) $case['source_entity_id'], $restaurantId);
        if ($saleItem === null) {
            return $this->buildFallbackCaseTrace($case);
        }

        $requestContext = $this->findLatestServerRequestContextForSale(
            $restaurantId,
            (int) ($saleItem['server_id'] ?? 0),
            (int) $saleItem['menu_item_id']
        );

        $trace = [
            'origin_label' => 'Retour service -> cuisine',
            'source_summary' => 'Article ' . ($saleItem['menu_item_name'] ?? 'du menu'),
            'product_name' => $saleItem['menu_item_name'] ?? ($case['stock_item_name'] ?? 'Produit'),
            'quantity_affected' => (float) $case['quantity_affected'],
            'unit_name' => $case['unit_name'] ?? 'portion',
            'metrics' => [],
            'notes' => array_values(array_filter([
                $case['signal_notes'] ?? null,
                $case['technical_notes'] ?? null,
                $saleItem['sale_note'] ?? null,
                $requestContext['request_note'] ?? null,
            ])),
            'steps' => [],
            'actors' => [],
        ];

        $this->appendMetric($trace, 'Quantité demandée', (float) ($requestContext['requested_quantity'] ?? 0), $case['unit_name'] ?? 'portion');
        $this->appendMetric($trace, 'Quantité préparée', (float) ($requestContext['supplied_quantity'] ?? 0), $case['unit_name'] ?? 'portion');
        $this->appendMetric($trace, 'Quantité non disponible', (float) ($requestContext['unavailable_quantity'] ?? 0), $case['unit_name'] ?? 'portion');
        $this->appendMetric($trace, 'Quantité vendue', (float) ($saleItem['quantity'] ?? 0), $case['unit_name'] ?? 'portion');
        $this->appendMetric($trace, 'Quantité retournée', (float) ($saleItem['returned_quantity'] ?? 0), $case['unit_name'] ?? 'portion');

        if ((int) ($requestContext['server_id'] ?? 0) > 0) {
            $this->appendActor($trace, (int) $requestContext['server_id'], (string) ($requestContext['server_name'] ?? 'Serveur'), 'cashier_server', 'Serveur demandeur');
            $this->appendStep($trace, 'Demande initiale', (int) $requestContext['server_id'], (string) ($requestContext['server_name'] ?? 'Serveur'), 'cashier_server', $requestContext['request_created_at'] ?? null, 'Référence ' . ((string) ($requestContext['service_reference'] ?? '-')));
        }

        if ((int) ($requestContext['prepared_by'] ?? 0) > 0) {
            $this->appendActor($trace, (int) $requestContext['prepared_by'], (string) ($requestContext['prepared_by_name'] ?? 'Cuisine'), 'kitchen', 'Préparation technique');
            $this->appendStep($trace, 'Préparation cuisine', (int) $requestContext['prepared_by'], (string) ($requestContext['prepared_by_name'] ?? 'Cuisine'), 'kitchen', $requestContext['prepared_at'] ?? $requestContext['request_ready_at'] ?? null, 'Quantité acceptée : ' . (string) ($requestContext['supplied_quantity'] ?? 0));
        }

        if ((int) ($requestContext['ready_by'] ?? 0) > 0) {
            $this->appendActor($trace, (int) $requestContext['ready_by'], (string) ($requestContext['ready_by_name'] ?? 'Cuisine'), 'kitchen', 'Validation prêt à servir');
            $this->appendStep($trace, 'Prêt à servir', (int) $requestContext['ready_by'], (string) ($requestContext['ready_by_name'] ?? 'Cuisine'), 'kitchen', $requestContext['request_ready_at'] ?? null, 'Remise prête pour le service');
        }

        if ((int) ($requestContext['received_by'] ?? 0) > 0) {
            $this->appendActor($trace, (int) $requestContext['received_by'], (string) ($requestContext['received_by_name'] ?? 'Serveur'), 'cashier_server', 'Réception du service');
            $this->appendStep($trace, 'Réception par le service', (int) $requestContext['received_by'], (string) ($requestContext['received_by_name'] ?? 'Serveur'), 'cashier_server', $requestContext['request_received_at'] ?? null, 'Réception explicite confirmée');
        }

        if ((int) ($case['technical_confirmed_by'] ?? 0) > 0) {
            $this->appendActor($trace, (int) $case['technical_confirmed_by'], (string) ($case['technical_confirmed_by_name'] ?? 'Cuisine'), 'kitchen', 'Confirmation technique du retour');
            $this->appendStep($trace, 'Constat technique', (int) $case['technical_confirmed_by'], (string) ($case['technical_confirmed_by_name'] ?? 'Cuisine'), 'kitchen', $case['technical_confirmed_at'] ?? null, (string) ($case['technical_notes'] ?? ''));
        }

        if ((int) ($case['signaled_by'] ?? 0) > 0) {
            $this->appendActor($trace, (int) $case['signaled_by'], (string) ($case['signaled_by_name'] ?? 'Agent'), (string) ($saleItem['server_role_code'] ?? 'cashier_server'), 'Signalement du retour');
            $this->appendStep($trace, 'Retour signalé', (int) $case['signaled_by'], (string) ($case['signaled_by_name'] ?? 'Agent'), (string) ($saleItem['server_role_code'] ?? 'cashier_server'), $case['created_at'] ?? null, (string) ($case['signal_notes'] ?? ''));
        }

        return $trace;
    }

    private function buildKitchenStockCaseTrace(int $restaurantId, array $case): array
    {
        $request = $this->findKitchenStockRequestWithContext((int) $case['source_entity_id'], $restaurantId);
        $trace = [
            'origin_label' => 'Cuisine -> stock',
            'source_summary' => 'Demande stock #' . (int) $request['id'],
            'product_name' => $request['stock_item_name'] ?? ($case['stock_item_name'] ?? 'Article'),
            'quantity_affected' => (float) $case['quantity_affected'],
            'unit_name' => $request['unit_name'] ?? ($case['unit_name'] ?? 'unité'),
            'metrics' => [],
            'notes' => array_values(array_filter([
                $request['note'] ?? null,
                $request['response_note'] ?? null,
                $case['signal_notes'] ?? null,
                $case['technical_notes'] ?? null,
            ])),
            'steps' => [],
            'actors' => [],
        ];

        $this->appendMetric($trace, 'Quantité demandée', (float) $request['quantity_requested'], $trace['unit_name']);
        $this->appendMetric($trace, 'Quantité fournie', (float) $request['quantity_supplied'], $trace['unit_name']);
        $this->appendMetric($trace, 'Quantité non fournie', (float) $request['unavailable_quantity'], $trace['unit_name']);

        $this->appendActor($trace, (int) $request['requested_by'], (string) ($request['requested_by_name'] ?? 'Cuisine'), 'kitchen', 'Demande cuisine');
        $this->appendStep($trace, 'Demande cuisine', (int) $request['requested_by'], (string) ($request['requested_by_name'] ?? 'Cuisine'), 'kitchen', $request['created_at'] ?? null, 'Priorité ' . priority_label((string) ($request['priority_level'] ?? 'normale')));

        if ((int) ($request['responded_by'] ?? 0) > 0) {
            $this->appendActor($trace, (int) $request['responded_by'], (string) ($request['responded_by_name'] ?? 'Stock'), 'stock_manager', 'Traitement stock');
            $this->appendStep($trace, 'Réponse stock', (int) $request['responded_by'], (string) ($request['responded_by_name'] ?? 'Stock'), 'stock_manager', $request['responded_at'] ?? null, stock_request_status_label((string) ($request['status'] ?? '')));
        }

        if ((int) ($request['received_by'] ?? 0) > 0) {
            $this->appendActor($trace, (int) $request['received_by'], (string) ($request['received_by_name'] ?? 'Cuisine'), 'kitchen', 'Réception cuisine');
            $this->appendStep($trace, 'Réception cuisine', (int) $request['received_by'], (string) ($request['received_by_name'] ?? 'Cuisine'), 'kitchen', $request['received_at'] ?? null, 'Réception confirmée côté cuisine');
        }

        if ((int) ($case['signaled_by'] ?? 0) > 0) {
            $this->appendActor($trace, (int) $case['signaled_by'], (string) ($case['signaled_by_name'] ?? 'Agent'), $this->resolveActorRoleFromIds($request, (int) $case['signaled_by']), 'Soumission au gérant');
            $this->appendStep($trace, 'Soumis au gérant', (int) $case['signaled_by'], (string) ($case['signaled_by_name'] ?? 'Agent'), $this->resolveActorRoleFromIds($request, (int) $case['signaled_by']), $case['created_at'] ?? null, (string) ($case['signal_notes'] ?? ''));
        }

        return $trace;
    }

    private function buildKitchenProductionCaseTrace(int $restaurantId, array $case): array
    {
        $production = $this->findKitchenProductionTraceSource((int) $case['source_entity_id'], $restaurantId);
        if ($production === null) {
            return $this->buildFallbackCaseTrace($case);
        }

        $trace = [
            'origin_label' => 'Incident cuisine',
            'source_summary' => 'Production ' . ($production['dish_type'] ?? 'cuisine'),
            'product_name' => $production['dish_type'] ?? ($case['stock_item_name'] ?? 'Préparation cuisine'),
            'quantity_affected' => (float) $case['quantity_affected'],
            'unit_name' => $case['unit_name'] ?? ($production['unit_name'] ?? 'portion'),
            'metrics' => [],
            'notes' => array_values(array_filter([
                $case['signal_notes'] ?? null,
                $case['technical_notes'] ?? null,
                $production['movement_note'] ?? null,
            ])),
            'steps' => [],
            'actors' => [],
        ];

        $this->appendMetric($trace, 'Quantité produite', (float) ($production['quantity_produced'] ?? 0), $trace['unit_name']);
        $this->appendMetric($trace, 'Quantité restante', (float) ($production['quantity_remaining'] ?? 0), $trace['unit_name']);

        $this->appendActor($trace, (int) $production['created_by'], (string) ($production['created_by_name'] ?? 'Cuisine'), 'kitchen', 'Production initiale');
        $this->appendStep($trace, 'Production créée', (int) $production['created_by'], (string) ($production['created_by_name'] ?? 'Cuisine'), 'kitchen', $production['created_at'] ?? null, 'Production ' . ($production['dish_type'] ?? 'cuisine'));

        if ((int) ($production['stock_user_id'] ?? 0) > 0) {
            $this->appendActor($trace, (int) $production['stock_user_id'], (string) ($production['stock_user_name'] ?? 'Stock'), 'stock_manager', 'Sortie stock liée');
            $this->appendStep($trace, 'Sortie stock vers cuisine', (int) $production['stock_user_id'], (string) ($production['stock_user_name'] ?? 'Stock'), 'stock_manager', $production['movement_created_at'] ?? null, (string) ($production['movement_note'] ?? ''));
        }

        if ((int) ($case['technical_confirmed_by'] ?? 0) > 0) {
            $this->appendActor($trace, (int) $case['technical_confirmed_by'], (string) ($case['technical_confirmed_by_name'] ?? 'Cuisine'), 'kitchen', 'Confirmation technique');
            $this->appendStep($trace, 'Constat technique', (int) $case['technical_confirmed_by'], (string) ($case['technical_confirmed_by_name'] ?? 'Cuisine'), 'kitchen', $case['technical_confirmed_at'] ?? null, (string) ($case['technical_notes'] ?? ''));
        }

        if ((int) ($case['signaled_by'] ?? 0) > 0) {
            $this->appendActor($trace, (int) $case['signaled_by'], (string) ($case['signaled_by_name'] ?? 'Agent'), 'kitchen', 'Signalement incident');
            $this->appendStep($trace, 'Incident signalé', (int) $case['signaled_by'], (string) ($case['signaled_by_name'] ?? 'Agent'), 'kitchen', $case['created_at'] ?? null, (string) ($case['signal_notes'] ?? ''));
        }

        return $trace;
    }

    private function buildStockItemCaseTrace(int $restaurantId, array $case): array
    {
        $stockItem = $this->findStockItemInRestaurant((int) $case['source_entity_id'], $restaurantId);
        $trace = [
            'origin_label' => 'Incident stock',
            'source_summary' => 'Article de stock ' . ($stockItem['name'] ?? ($case['stock_item_name'] ?? 'stock')),
            'product_name' => $stockItem['name'] ?? ($case['stock_item_name'] ?? 'Article'),
            'quantity_affected' => (float) $case['quantity_affected'],
            'unit_name' => $case['unit_name'] ?? ($stockItem['unit_name'] ?? 'unité'),
            'metrics' => [],
            'notes' => array_values(array_filter([
                $case['signal_notes'] ?? null,
                $case['technical_notes'] ?? null,
            ])),
            'steps' => [],
            'actors' => [],
        ];

        if ($stockItem !== null) {
            $this->appendMetric($trace, 'Stock disponible', (float) ($stockItem['quantity_in_stock'] ?? 0), $trace['unit_name']);
        }

        if ((int) ($case['signaled_by'] ?? 0) > 0) {
            $this->appendActor($trace, (int) $case['signaled_by'], (string) ($case['signaled_by_name'] ?? 'Stock'), 'stock_manager', 'Signalement initial');
            $this->appendStep($trace, 'Retour signalé', (int) $case['signaled_by'], (string) ($case['signaled_by_name'] ?? 'Stock'), 'stock_manager', $case['created_at'] ?? null, (string) ($case['signal_notes'] ?? ''));
        }

        return $trace;
    }

    private function buildFallbackCaseTrace(array $case): array
    {
        $trace = [
            'origin_label' => 'Cas opérationnel',
            'source_summary' => 'Cas #' . (int) ($case['id'] ?? 0),
            'product_name' => $case['stock_item_name'] ?? 'Produit concerné',
            'quantity_affected' => (float) ($case['quantity_affected'] ?? 0),
            'unit_name' => $case['unit_name'] ?? 'unité',
            'metrics' => [],
            'notes' => array_values(array_filter([
                $case['signal_notes'] ?? null,
                $case['technical_notes'] ?? null,
            ])),
            'steps' => [],
            'actors' => [],
        ];

        if ((int) ($case['signaled_by'] ?? 0) > 0) {
            $this->appendActor($trace, (int) $case['signaled_by'], (string) ($case['signaled_by_name'] ?? 'Agent'), null, 'Signalement');
            $this->appendStep($trace, 'Signalement', (int) $case['signaled_by'], (string) ($case['signaled_by_name'] ?? 'Agent'), null, $case['created_at'] ?? null, (string) ($case['signal_notes'] ?? ''));
        }

        return $trace;
    }

    private function extractLinkedActors(array $trace, array $case): array
    {
        $actors = [];
        foreach (($trace['actors'] ?? []) as $actor) {
            $id = (int) ($actor['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $actors[$id] = [
                'id' => $id,
                'name' => (string) ($actor['name'] ?? 'Agent'),
                'role_code' => (string) ($actor['role_code'] ?? ''),
                'reason' => (string) ($actor['reason'] ?? 'Trace applicative'),
            ];
        }

        if ((int) ($case['signaled_by'] ?? 0) > 0 && !isset($actors[(int) $case['signaled_by']])) {
            $actors[(int) $case['signaled_by']] = [
                'id' => (int) $case['signaled_by'],
                'name' => (string) ($case['signaled_by_name'] ?? 'Agent'),
                'role_code' => '',
                'reason' => 'Signalement initial',
            ];
        }

        return array_values($actors);
    }

    private function resolveResponsibilityAssignment(array $payload, array $linkedActors): array
    {
        $scope = trim((string) ($payload['responsibility_scope'] ?? 'restaurant'));
        $responsibleUserId = (int) ($payload['responsible_user_id'] ?? 0);
        $linkedActorIds = [];
        foreach ($linkedActors as $linkedActor) {
            $linkedActorIds[(int) $linkedActor['id']] = true;
        }

        if ($responsibleUserId > 0 && !isset($linkedActorIds[$responsibleUserId])) {
            throw new \RuntimeException('Impossible d imputer ce cas a un agent absent de la trace applicative.');
        }

        return match ($scope) {
            'restaurant', 'sans_faute_individuelle' => [
                'responsibility_scope' => $scope,
                'responsible_user_id' => null,
            ],
            'agent_lie' => $responsibleUserId > 0
                ? [
                    'responsibility_scope' => 'agent_lie',
                    'responsible_user_id' => $responsibleUserId,
                ]
                : throw new \RuntimeException('Choisissez un agent reellement implique dans le cas.'),
            default => throw new \RuntimeException('Mode d imputation invalide.'),
        };
    }

    private function refreshCaseTraceSnapshot(int $restaurantId, int $caseId): void
    {
        $case = $this->findCase($caseId, $restaurantId);
        if ($case === null) {
            return;
        }

        $statement = $this->database->pdo()->prepare(
            'UPDATE operation_cases
             SET trace_snapshot_json = :trace_snapshot_json
             WHERE id = :id AND restaurant_id = :restaurant_id'
        );
        $statement->execute([
            'trace_snapshot_json' => $this->buildTraceSnapshotJson($restaurantId, $case),
            'id' => $caseId,
            'restaurant_id' => $restaurantId,
        ]);
    }

    private function appendMetric(array &$trace, string $label, float $value, string $unitName): void
    {
        if ($value <= 0) {
            return;
        }

        $trace['metrics'][] = [
            'label' => $label,
            'value' => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . ' ' . $unitName,
        ];
    }

    private function appendActor(array &$trace, int $userId, string $name, ?string $roleCode, string $reason): void
    {
        if ($userId <= 0) {
            return;
        }

        foreach ($trace['actors'] as $index => $actor) {
            if ((int) $actor['id'] === $userId) {
                $trace['actors'][$index]['reason'] = $actor['reason'] . ' · ' . $reason;

                return;
            }
        }

        $trace['actors'][] = [
            'id' => $userId,
            'name' => $name,
            'role_code' => $roleCode,
            'reason' => $reason,
        ];
    }

    private function appendStep(array &$trace, string $label, int $userId, string $name, ?string $roleCode, ?string $timestamp, string $details): void
    {
        $trace['steps'][] = [
            'label' => $label,
            'actor_id' => $userId,
            'actor_name' => $name,
            'role_code' => $roleCode,
            'time' => $timestamp,
            'details' => $details,
        ];
    }

    private function decodeSnapshot(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function resolveActorRoleFromIds(array $request, int $userId): string
    {
        if ((int) ($request['requested_by'] ?? 0) === $userId || (int) ($request['received_by'] ?? 0) === $userId) {
            return 'kitchen';
        }

        if ((int) ($request['responded_by'] ?? 0) === $userId) {
            return 'stock_manager';
        }

        return '';
    }

    private function insertCase(int $restaurantId, array $payload, array $actor, string $actionName): int
    {
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO operation_cases
            (restaurant_id, type, case_type, reported_category, source, source_module, source_entity_type, reference_id, source_entity_id, stock_item_id, description, quantity_affected, unit_name, status, created_by, signaled_by, signal_notes, created_at)
             VALUES
            (:restaurant_id, :type, :case_type, :reported_category, :source, :source_module, :source_entity_type, :reference_id, :source_entity_id, :stock_item_id, :description, :quantity_affected, :unit_name, :status, :created_by, :signaled_by, :signal_notes, NOW())'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'type' => $payload['case_type'],
            'case_type' => $payload['case_type'],
            'reported_category' => $payload['reported_category'] ?? null,
            'source' => $payload['source_module'],
            'source_module' => $payload['source_module'],
            'source_entity_type' => $payload['source_entity_type'],
            'reference_id' => $payload['source_entity_id'],
            'source_entity_id' => $payload['source_entity_id'],
            'stock_item_id' => $payload['stock_item_id'] ?? null,
            'description' => $payload['signal_notes'],
            'quantity_affected' => $payload['quantity_affected'],
            'unit_name' => $payload['unit_name'],
            'status' => $payload['status'],
            'created_by' => $payload['signaled_by'],
            'signaled_by' => $payload['signaled_by'],
            'signal_notes' => $payload['signal_notes'],
        ]);
        $caseId = (int) $this->database->pdo()->lastInsertId();

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'incidents',
            'action_name' => $actionName,
            'entity_type' => 'operation_cases',
            'entity_id' => (string) $caseId,
            'new_values' => $payload,
            'justification' => 'Signalement incident ou retour',
        ]);

        return $caseId;
    }

    private function applyOperationalImpact(
        int $restaurantId,
        array $case,
        string $qualification,
        float $materialLoss,
        float $cashLoss,
        array $actor
    ): void {
        if ($qualification === 'retour_simple' && $case['source_entity_type'] === 'sale_items') {
            $this->applySaleReturn($restaurantId, (int) $case['source_entity_id'], $actor['id'], (float) $case['quantity_affected'], $qualification, true);
            return;
        }

        if ($case['source_module'] === 'stock' && $case['stock_item_id'] !== null) {
            $this->applyStockLossMovement($restaurantId, (int) $case['stock_item_id'], (float) $case['quantity_affected'], $actor['id'], $qualification);
            return;
        }

        if ($case['source_module'] === 'kitchen') {
            $this->applyKitchenLoss($restaurantId, (int) $case['source_entity_id'], (float) $case['quantity_affected']);
            return;
        }

        if ($case['source_module'] === 'sales' && $qualification !== 'retour_simple') {
            $this->applySaleReturn($restaurantId, (int) $case['source_entity_id'], $actor['id'], (float) $case['quantity_affected'], $qualification, false);
            return;
        }

        if ($materialLoss > 0 || $cashLoss > 0) {
            return;
        }
    }

    private function assertDecisionConsistency(int $restaurantId, array $case, string $qualification, float $materialLoss, float $cashLoss): void
    {
        if ($qualification !== 'retour_simple' && $materialLoss <= 0 && $cashLoss <= 0) {
            throw new \RuntimeException('Une perte validee doit comporter un impact matiere ou argent.');
        }

        if ($case['source_module'] === 'stock' && $case['stock_item_id'] !== null) {
            $stockItem = $this->findStockItemInRestaurant((int) $case['stock_item_id'], $restaurantId);
            if ($stockItem === null || (float) $stockItem['quantity_in_stock'] < (float) $case['quantity_affected']) {
                throw new \RuntimeException('Stock insuffisant pour enregistrer cette perte.');
            }
        }

        if ($case['source_module'] === 'kitchen') {
            $production = $this->findProductionInRestaurant((int) $case['source_entity_id'], $restaurantId);
            if ($production === null || (float) $production['quantity_remaining'] < (float) $case['quantity_affected']) {
                throw new \RuntimeException('Quantite cuisine insuffisante pour valider ce cas.');
            }
        }
    }

    private function applyStockLossMovement(int $restaurantId, int $stockItemId, float $quantity, int $userId, string $qualification): void
    {
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO stock_movements
            (restaurant_id, stock_item_id, movement_type, quantity, status, user_id, validated_by, reference_type, reference_id, note, created_at, validated_at)
             VALUES
            (:restaurant_id, :stock_item_id, "PERTE", :quantity, "VALIDE", :user_id, :validated_by, "operation_case", NULL, :note, NOW(), NOW())'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'stock_item_id' => $stockItemId,
            'quantity' => $quantity,
            'user_id' => $userId,
            'validated_by' => $userId,
            'note' => 'Perte issue du cas: ' . $qualification,
        ]);

        $update = $this->database->pdo()->prepare(
            'UPDATE stock_items
             SET quantity_in_stock = quantity_in_stock - :quantity
             WHERE id = :id AND restaurant_id = :restaurant_id'
        );
        $update->execute([
            'quantity' => $quantity,
            'id' => $stockItemId,
            'restaurant_id' => $restaurantId,
        ]);
    }

    private function applyKitchenLoss(int $restaurantId, int $productionId, float $quantity): void
    {
        $statement = $this->database->pdo()->prepare(
            'UPDATE kitchen_production
             SET quantity_remaining = GREATEST(quantity_remaining - :quantity, 0),
                 status = CASE WHEN GREATEST(quantity_remaining - :quantity, 0) = 0 THEN "TERMINE" ELSE status END,
                 closed_at = CASE WHEN GREATEST(quantity_remaining - :quantity, 0) = 0 THEN NOW() ELSE closed_at END
             WHERE id = :id AND restaurant_id = :restaurant_id'
        );
        $statement->execute([
            'quantity' => $quantity,
            'id' => $productionId,
            'restaurant_id' => $restaurantId,
        ]);
    }

    private function createLoss(int $restaurantId, string $lossType, int $referenceId, string $description, float $amount, int $userId): void
    {
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO losses (restaurant_id, loss_type, reference_id, description, amount, validated_by, created_by, created_at, validated_at)
             VALUES (:restaurant_id, :loss_type, :reference_id, :description, :amount, :validated_by, :created_by, NOW(), NOW())'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'loss_type' => $lossType,
            'reference_id' => $referenceId,
            'description' => $description,
            'amount' => $amount,
            'validated_by' => $userId,
            'created_by' => $userId,
        ]);
    }

    private function applySaleReturn(
        int $restaurantId,
        int $saleItemId,
        int $userId,
        float $quantity,
        string $reason,
        bool $restoreProduction
    ): void {
        $saleItem = $this->findSaleItemInRestaurant($saleItemId, $restaurantId);
        if ($saleItem === null) {
            return;
        }
        if ($quantity > (float) $saleItem['quantity']) {
            throw new \RuntimeException('Retour impossible: quantite superieure a la vente.');
        }

        $pdo = $this->database->pdo();
        $startedTransaction = !$pdo->inTransaction();
        if ($startedTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $statement = $pdo->prepare(
                'UPDATE sale_items
                 SET status = "RETOUR",
                     return_reason = :return_reason,
                     return_validated_by_kitchen = COALESCE(return_validated_by_kitchen, :validated_by_kitchen),
                     return_validated_by_manager = :validated_by_manager,
                     returned_at = COALESCE(returned_at, NOW())
                 WHERE id = :id'
            );
            $statement->execute([
                'return_reason' => $reason,
                'validated_by_kitchen' => $userId,
                'validated_by_manager' => $restoreProduction ? null : $userId,
                'id' => $saleItemId,
            ]);

            if ($restoreProduction && $saleItem['kitchen_production_id'] !== null) {
                $restore = $pdo->prepare(
                    'UPDATE kitchen_production
                     SET quantity_remaining = quantity_remaining + :quantity,
                         status = "EN_COURS",
                         closed_at = NULL
                     WHERE id = :id AND restaurant_id = :restaurant_id'
                );
                $restore->execute([
                    'quantity' => $quantity,
                    'id' => (int) $saleItem['kitchen_production_id'],
                    'restaurant_id' => $restaurantId,
                ]);
            }

            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }
        } catch (\Throwable $throwable) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $throwable;
        }
    }

    private function resolveSaleItemStockLink(array $saleItem): array
    {
        if ($saleItem['kitchen_production_id'] === null) {
            return ['stock_item_id' => null, 'unit_name' => 'portion'];
        }

        $statement = $this->database->pdo()->prepare(
            'SELECT si.id AS stock_item_id, si.unit_name
             FROM kitchen_production kp
             INNER JOIN stock_movements sm ON sm.id = kp.stock_movement_id
             INNER JOIN stock_items si ON si.id = sm.stock_item_id
             WHERE kp.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => (int) $saleItem['kitchen_production_id']]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: ['stock_item_id' => null, 'unit_name' => 'portion'];
    }

    private function assertPositiveQuantity(mixed $quantity): float
    {
        $normalized = (float) $quantity;
        if ($normalized <= 0) {
            throw new \RuntimeException('Quantite impactee obligatoire et strictement positive.');
        }

        return $normalized;
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

    private function findSaleTraceSource(int $saleItemId, int $restaurantId): ?array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT si.*,
                    s.server_id,
                    s.note AS sale_note,
                    s.created_at AS sale_created_at,
                    s.validated_at AS sale_validated_at,
                    sale_server.full_name AS server_name,
                    mi.name AS menu_item_name,
                    kp.created_by AS production_created_by,
                    kp.created_at AS production_created_at,
                    kp.dish_type,
                    r.code AS server_role_code
             FROM sale_items si
             INNER JOIN sales s ON s.id = si.sale_id
             INNER JOIN menu_items mi ON mi.id = si.menu_item_id
             LEFT JOIN users sale_server ON sale_server.id = s.server_id
             LEFT JOIN roles r ON r.id = sale_server.role_id
             LEFT JOIN kitchen_production kp ON kp.id = si.kitchen_production_id
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

    private function findLatestServerRequestContextForSale(int $restaurantId, int $serverId, int $menuItemId): ?array
    {
        if ($serverId <= 0 || $menuItemId <= 0) {
            return null;
        }

        $statement = $this->database->pdo()->prepare(
            'SELECT sri.*,
                    sr.server_id,
                    sr.service_reference,
                    sr.note AS request_note,
                    sr.created_at AS request_created_at,
                    sr.ready_at AS request_ready_at,
                    sr.received_at AS request_received_at,
                    sr.ready_by,
                    sr.received_by,
                    server_user.full_name AS server_name,
                    prepared_user.full_name AS prepared_by_name,
                    ready_user.full_name AS ready_by_name,
                    received_user.full_name AS received_by_name
             FROM server_request_items sri
             INNER JOIN server_requests sr ON sr.id = sri.request_id
             INNER JOIN users server_user ON server_user.id = sr.server_id
             LEFT JOIN users prepared_user ON prepared_user.id = sri.technical_confirmed_by
             LEFT JOIN users ready_user ON ready_user.id = sr.ready_by
             LEFT JOIN users received_user ON received_user.id = sr.received_by
             WHERE sr.restaurant_id = :restaurant_id
               AND sr.server_id = :server_id
               AND sri.menu_item_id = :menu_item_id
             ORDER BY sr.id DESC, sri.id DESC
             LIMIT 1'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'server_id' => $serverId,
            'menu_item_id' => $menuItemId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function findStockItemInRestaurant(int $stockItemId, int $restaurantId): ?array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT *
             FROM stock_items
             WHERE id = :id AND restaurant_id = :restaurant_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $stockItemId,
            'restaurant_id' => $restaurantId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function findProductionInRestaurant(int $productionId, int $restaurantId): ?array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT kp.*, si.id AS stock_item_id, si.unit_name
             FROM kitchen_production kp
             INNER JOIN stock_movements sm ON sm.id = kp.stock_movement_id
             INNER JOIN stock_items si ON si.id = sm.stock_item_id
             WHERE kp.id = :id AND kp.restaurant_id = :restaurant_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $productionId,
            'restaurant_id' => $restaurantId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function findKitchenProductionTraceSource(int $productionId, int $restaurantId): ?array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT kp.*,
                    sm.user_id AS stock_user_id,
                    sm.created_at AS movement_created_at,
                    sm.note AS movement_note,
                    stock_user.full_name AS stock_user_name,
                    kitchen_user.full_name AS created_by_name,
                    si.unit_name
             FROM kitchen_production kp
             INNER JOIN stock_movements sm ON sm.id = kp.stock_movement_id
             INNER JOIN stock_items si ON si.id = sm.stock_item_id
             LEFT JOIN users stock_user ON stock_user.id = sm.user_id
             LEFT JOIN users kitchen_user ON kitchen_user.id = kp.created_by
             WHERE kp.id = :id AND kp.restaurant_id = :restaurant_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $productionId,
            'restaurant_id' => $restaurantId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function findKitchenStockRequestWithContext(int $requestId, int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT ksr.*,
                    si.name AS stock_item_name,
                    si.unit_name,
                    rq.full_name AS requested_by_name,
                    rp.full_name AS responded_by_name,
                    ru.full_name AS received_by_name
             FROM kitchen_stock_requests ksr
             INNER JOIN stock_items si ON si.id = ksr.stock_item_id
             INNER JOIN users rq ON rq.id = ksr.requested_by
             LEFT JOIN users rp ON rp.id = ksr.responded_by
             LEFT JOIN users ru ON ru.id = ksr.received_by
             WHERE ksr.id = :id AND ksr.restaurant_id = :restaurant_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $requestId,
            'restaurant_id' => $restaurantId,
        ]);
        $request = $statement->fetch(PDO::FETCH_ASSOC);

        if ($request === false) {
            throw new \RuntimeException('Demande cuisine vers stock introuvable.');
        }

        return $request;
    }

    private function findCase(int $caseId, int $restaurantId): ?array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT *
             FROM operation_cases
             WHERE id = :id AND restaurant_id = :restaurant_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $caseId,
            'restaurant_id' => $restaurantId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}
