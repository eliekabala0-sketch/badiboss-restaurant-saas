<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Container;
use App\Core\Request;

final class OperationsController
{
    public function stock(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('stock.view');
        $actor = current_user();
        $flashSuccess = flash('success');
        $flashError = flash('error');
        session_release_read_lock();
        $incidentCatalog = $this->incidentCatalog();
        $kitchenStockBlocks = Container::getInstance()->get('stockService')->listKitchenStockRequestBlocks($restaurantId);
        $items = Container::getInstance()->get('stockService')->listItems($restaurantId);
        $movements = Container::getInstance()->get('stockService')->listMovementHistoryRows($restaurantId);
        $stockCategoryFilter = trim((string) ($request->query['stock_cat'] ?? 'all'));
        $stockItemIdsForFilter = stock_item_ids_matching_category_filter($items, $stockCategoryFilter);
        $movementsDisplay = $stockItemIdsForFilter === null
            ? $movements
            : array_values(array_filter(
                $movements,
                static function (array $row) use ($stockItemIdsForFilter): bool {
                    return in_array((int) ($row['stock_item_id'] ?? 0), $stockItemIdsForFilter, true);
                }
            ));
        $stockCategoryLabels = [];
        foreach ($items as $stockItemRow) {
            $label = trim((string) ($stockItemRow['category_label'] ?? ''));
            if ($label !== '') {
                $stockCategoryLabels[$label] = true;
            }
        }
        ksort($stockCategoryLabels, SORT_NATURAL | SORT_FLAG_CASE);
        $stockCategoryLabels = array_keys($stockCategoryLabels);

        $dash = $this->operationalDashboardBundle($request, $restaurantId, null, true);
        $hold = Container::getInstance()->get('regularizationGate')->assessForUser($restaurantId, is_array($actor) ? $actor : []);
        $disc = $this->staffDisciplineOperationalExtras($dash, $restaurantId, is_array($actor) ? $actor : null, ['stock_manager']);

        $scDate = trim((string) ($request->query['sc_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $scDate)) {
            $scDate = Container::getInstance()->get('reportService')->todayForRestaurant($restaurantId);
        }
        $scPeriod = strtolower(trim((string) ($request->query['sc_period'] ?? 'daily')));
        if (!in_array($scPeriod, ['daily', 'weekly', 'monthly'], true)) {
            $scPeriod = 'daily';
        }
        $stockControlBundle = null;
        if (can_access('stock.control.report.view')) {
            $stockControlBundle = Container::getInstance()->get('stockControlReport')->buildBundle($restaurantId, $scDate, $scPeriod);
        }
        $stockControlStockQuery = http_build_query(array_filter([
            'sc_date' => $scDate,
            'sc_period' => $scPeriod,
            'stock_cat' => ($stockCategoryFilter !== 'all' && $stockCategoryFilter !== '') ? $stockCategoryFilter : null,
        ], static fn ($v): bool => $v !== null && $v !== ''));

        view('operations/stock', array_merge($dash, $disc, [
            'title' => 'Stock',
            'restaurant' => Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId),
            'items' => $items,
            'movements' => $movements,
            'movements_display' => $movementsDisplay,
            'stock_category_filter' => $stockCategoryFilter,
            'stock_item_ids_for_filter' => $stockItemIdsForFilter,
            'stock_category_labels' => $stockCategoryLabels,
            'kitchen_stock_requests' => $kitchenStockBlocks['requests'],
            'kitchen_stock_request_items_by_request' => $kitchenStockBlocks['items_by_request'],
            'correction_requests' => Container::getInstance()->get('correctionService')->listRecentForRestaurant($restaurantId, 12),
            'stock_audits' => Container::getInstance()->get('stockService')->recentAudits($restaurantId, 12),
            'productions' => Container::getInstance()->get('kitchenService')->listProductions($restaurantId),
            'cases' => Container::getInstance()->get('incidentService')->listCases($restaurantId, 'stock'),
            'incident_types' => $incidentCatalog['incident_types'],
            'final_qualifications' => $incidentCatalog['final_qualifications'],
            'responsibility_targets' => $incidentCatalog['responsibility_targets'],
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
            'day_start_hold' => $hold,
            'regularization_backlog' => Container::getInstance()->get('salesService')->regularizationBacklogCounts($restaurantId),
            'staff_gauges_panel_title' => 'Discipline · magasin',
            'stock_control_bundle' => $stockControlBundle,
            'stock_control_return_to' => 'stock',
            'stock_control_stock_query' => $stockControlStockQuery,
        ]));

        audit_access('stock', $restaurantId, 'screens', 'stock', 'Consultation module stock');
    }

    public function createStockItem(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('stock.create');

        try {
            $this->assertDayStartAllowsNewOperations($restaurantId);
        } catch (\RuntimeException $exception) {
            flash('error', ui_safe_message($exception->getMessage()));
            redirect($this->stockUrl($restaurantId));
        }

        Container::getInstance()->get('stockService')->createItem($restaurantId, [
            'name' => $request->input('name'),
            'unit_name' => $request->input('unit_name'),
            'quantity_in_stock' => $request->input('quantity_in_stock', 0),
            'alert_threshold' => $request->input('alert_threshold', 0),
            'estimated_unit_cost' => $request->input('estimated_unit_cost', 0),
            'category_label' => $request->input('category_label', ''),
            'item_note' => $request->input('item_note', ''),
        ], current_user());

        flash('success', 'Article de stock cree.');
        redirect($this->stockUrl($restaurantId));
    }

    public function updateStockItem(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('stock.item.edit');

        Container::getInstance()->get('stockService')->updateItem(
            $restaurantId,
            (int) $request->route('id'),
            [
                'name' => $request->input('name'),
                'unit_name' => $request->input('unit_name'),
                'alert_threshold' => $request->input('alert_threshold', 0),
                'estimated_unit_cost' => $request->input('estimated_unit_cost', 0),
                'category_label' => $request->input('category_label', ''),
                'item_note' => $request->input('item_note', ''),
            ],
            current_user()
        );

        flash('success', 'Article de stock modifie sans toucher aux mouvements historiques.');
        redirect($this->stockUrl($restaurantId));
    }

    public function archiveStockItem(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('stock.item.edit');

        try {
            Container::getInstance()->get('stockService')->archiveStockItem(
                $restaurantId,
                (int) $request->route('id'),
                (string) $request->input('archive_reason', ''),
                current_user()
            );
            flash('success', 'Article archive. Il reste visible dans l historique mais plus dans les formulaires actifs.');
        } catch (\RuntimeException $exception) {
            flash('error', ui_safe_message($exception->getMessage()));
        }

        redirect($this->stockUrl($restaurantId));
    }

    public function addStockEntry(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('stock.entry.create');

        try {
            $this->assertDayStartAllowsNewOperations($restaurantId);
        } catch (\RuntimeException $exception) {
            flash('error', ui_safe_message($exception->getMessage()));
            redirect($this->stockUrl($restaurantId));
        }

        Container::getInstance()->get('stockService')->addEntry($restaurantId, [
            'stock_item_id' => $request->input('stock_item_id'),
            'quantity' => $request->input('quantity'),
            'unit_cost' => $request->input('unit_cost', 0),
            'note' => $request->input('note'),
        ], current_user());

        flash('success', 'Entree de stock enregistree.');
        redirect($this->stockUrl($restaurantId));
    }

    public function sendToKitchen(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('stock.kitchen.issue');

        try {
            $this->assertDayStartAllowsNewOperations($restaurantId);
        } catch (\RuntimeException $exception) {
            flash('error', ui_safe_message($exception->getMessage()));
            redirect($this->stockUrl($restaurantId));
        }

        Container::getInstance()->get('stockService')->sendToKitchen($restaurantId, [
            'stock_item_id' => $request->input('stock_item_id'),
            'quantity' => $request->input('quantity'),
            'note' => $request->input('note'),
        ], current_user());

        flash('success', 'Sortie provisoire vers la cuisine enregistree.');
        redirect($this->stockUrl($restaurantId));
    }

    public function validateReturnStock(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('stock.return.validate');

        Container::getInstance()->get('stockService')->validateReturn($restaurantId, [
            'stock_item_id' => $request->input('stock_item_id'),
            'quantity' => $request->input('quantity'),
            'source_movement_id' => $request->input('source_movement_id'),
            'kitchen_production_id' => $request->input('kitchen_production_id'),
            'note' => $request->input('note'),
        ], current_user());

        flash('success', 'Retour stock valide.');
        redirect($this->stockUrl($restaurantId));
    }

    public function signalDamagedStockReturn(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('stock.damage.signal');

        Container::getInstance()->get('incidentService')->signalDamagedStockReturn($restaurantId, [
            'stock_item_id' => $request->input('stock_item_id'),
            'reported_category' => $request->input('reported_category', 'retour_stock_endommage'),
            'quantity_affected' => $request->input('quantity_affected'),
            'signal_notes' => $request->input('signal_notes'),
        ], current_user());

        flash('success', 'Retour stock endommage signale pour arbitrage manager.');
        redirect($this->stockUrl($restaurantId));
    }

    public function createLoss(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('stock.loss.declare');

        try {
            $this->assertDayStartAllowsNewOperations($restaurantId);
        } catch (\RuntimeException $exception) {
            flash('error', ui_safe_message($exception->getMessage()));
            redirect($this->stockUrl($restaurantId));
        }

        Container::getInstance()->get('stockService')->declareLoss($restaurantId, [
            'stock_item_id' => $request->input('stock_item_id'),
            'quantity' => $request->input('quantity'),
            'amount' => $request->input('amount'),
            'description' => $request->input('description'),
        ], current_user());

        flash('success', 'Perte matiere enregistree.');
        redirect($this->stockUrl($restaurantId));
    }

    public function recordFreeStockMovement(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        $kind = strtoupper(trim((string) $request->input('movement_kind', '')));
        if ($kind === 'PERTE') {
            authorize_access('stock.loss.declare');
        } else {
            authorize_access('stock.entry.create');
        }

        try {
            $this->assertDayStartAllowsNewOperations($restaurantId);
        } catch (\RuntimeException $exception) {
            flash('error', ui_safe_message($exception->getMessage()));
            redirect($this->stockUrl($restaurantId));
        }

        Container::getInstance()->get('stockService')->recordFreeStockMovement($restaurantId, [
            'movement_kind' => $request->input('movement_kind'),
            'stock_item_id' => $request->input('stock_item_id'),
            'free_item_name' => $request->input('free_item_name', ''),
            'free_unit_name' => $request->input('free_unit_name', 'unité'),
            'quantity' => $request->input('quantity', 0),
            'signed_adjustment' => $request->input('signed_adjustment', 0),
            'unit_cost' => $request->input('unit_cost', 0),
            'amount' => $request->input('amount', 0),
            'note' => $request->input('note', ''),
        ], current_user());

        flash('success', 'Mouvement de stock enregistre.');
        redirect($this->stockUrl($restaurantId));
    }

    public function respondKitchenStockRequest(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('stock.request.respond');

        Container::getInstance()->get('stockService')->respondKitchenStockRequest(
            $restaurantId,
            (int) $request->route('id'),
            [
                'workflow_stage' => $request->input('workflow_stage', 'FINALISER'),
                'quantity_supplied' => $request->input('quantity_supplied', 0),
                'status' => $request->input('status', 'FOURNI_TOTAL'),
                'planning_status' => $request->input('planning_status', ''),
                'note' => $request->input('note'),
                'items' => $request->input('items', []),
            ],
            current_user()
        );

        flash('success', 'Reponse du stock enregistree.');
        redirect($this->stockUrl($restaurantId));
    }

    public function signalKitchenStockIncident(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('stock.damage.signal');

        Container::getInstance()->get('incidentService')->signalKitchenStockIncident(
            $restaurantId,
            (int) $request->route('id'),
            [
                'reported_category' => $request->input('reported_category', 'litige_stock'),
                'quantity_affected' => $request->input('quantity_affected'),
                'signal_notes' => $request->input('signal_notes'),
            ],
            current_user()
        );

        flash('success', 'Cas complexe transmis au gerant avec sa trace.');
        redirect($this->stockUrl($restaurantId));
    }

    public function requestStockMovementCorrection(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('stock.correction.request');

        Container::getInstance()->get('correctionService')->requestStockMovementQuantityCorrection(
            $restaurantId,
            (int) $request->route('id'),
            [
                'new_quantity' => $request->input('new_quantity'),
                'justification' => $request->input('justification'),
            ],
            current_user()
        );

        flash('success', 'Demande de correction envoyee au gerant ou proprietaire.');
        redirect($this->stockUrl($restaurantId));
    }

    public function requestSensitiveCorrection(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('stock.correction.request');

        Container::getInstance()->get('correctionService')->requestSensitiveOperationCorrection(
            $restaurantId,
            [
                'module_name' => $request->input('module_name'),
                'entity_type' => $request->input('entity_type'),
                'entity_id' => $request->input('entity_id'),
                'request_type' => $request->input('request_type', 'sensitive_operation_correction'),
                'summary' => $request->input('summary', ''),
                'justification' => $request->input('justification'),
            ],
            current_user()
        );

        flash('success', 'Demande de correction sensible enregistree pour validation.');
        redirect($this->stockUrl($restaurantId));
    }

    public function kitchen(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('kitchen.view');
        $actor = current_user();
        $flashSuccess = flash('success');
        $flashError = flash('error');
        session_release_read_lock();
        $incidentCatalog = $this->incidentCatalog();
        $kitchenStockBlocks = Container::getInstance()->get('stockService')->listKitchenStockRequestBlocks($restaurantId);

        $allCases = Container::getInstance()->get('incidentService')->listCases($restaurantId);

        $dash = $this->operationalDashboardBundle($request, $restaurantId, null, true);
        $hold = Container::getInstance()->get('regularizationGate')->assessForUser($restaurantId, is_array($actor) ? $actor : []);
        $disc = $this->staffDisciplineOperationalExtras($dash, $restaurantId, is_array($actor) ? $actor : null, ['kitchen']);

        view('operations/kitchen', array_merge($dash, $disc, [
            'title' => 'Cuisine',
            'restaurant' => Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId),
            'productions' => Container::getInstance()->get('kitchenService')->listProductions($restaurantId),
            'server_request_items' => Container::getInstance()->get('kitchenService')->listPendingServerRequestItems($restaurantId),
            'server_request_history_items' => Container::getInstance()->get('salesService')->listServerRequestItems($restaurantId),
            'kitchen_stock_requests' => $kitchenStockBlocks['requests'],
            'kitchen_stock_request_items_by_request' => $kitchenStockBlocks['items_by_request'],
            'stock_items' => array_values(array_filter(
                Container::getInstance()->get('stockService')->listItems($restaurantId),
                static fn (array $row): bool => empty($row['archived_at'])
            )),
            'kitchen_inventory' => Container::getInstance()->get('stockService')->listKitchenInventoryDashboard($restaurantId),
            'kitchen_evolution' => Container::getInstance()->get('stockService')->listKitchenEvolution($restaurantId),
            'menu_categories' => Container::getInstance()->get('menuAdmin')->listCategories($restaurantId),
            'menu_items' => Container::getInstance()->get('menuAdmin')->listItems($restaurantId),
            'sale_items' => Container::getInstance()->get('salesService')->listSaleItemsForKitchen($restaurantId),
            'cases' => array_values(array_filter(
                $allCases,
                static fn (array $case): bool => in_array($case['source_module'], ['kitchen', 'sales'], true)
            )),
            'incident_types' => $incidentCatalog['incident_types'],
            'final_qualifications' => $incidentCatalog['final_qualifications'],
            'responsibility_targets' => $incidentCatalog['responsibility_targets'],
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
            'day_start_hold' => $hold,
            'regularization_backlog' => Container::getInstance()->get('salesService')->regularizationBacklogCounts($restaurantId),
            'staff_gauges_panel_title' => 'Discipline · cuisine',
        ]));

        audit_access('kitchen', $restaurantId, 'screens', 'kitchen', 'Consultation module cuisine');
    }

    public function createKitchenProduction(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('kitchen.production.create');

        try {
            $this->assertDayStartAllowsNewOperations($restaurantId);
        } catch (\RuntimeException $exception) {
            flash('error', ui_safe_message($exception->getMessage()));
            redirect($this->moduleUrl('/cuisine', $restaurantId));
        }

        Container::getInstance()->get('kitchenService')->createProduction($restaurantId, [
            'stock_item_id' => $request->input('stock_item_id'),
            'menu_item_id' => (string) $request->input('menu_item_id', ''),
            'quantity' => $request->input('quantity'),
            'quantity_produced' => $request->input('quantity_produced'),
            'dish_type' => $request->input('dish_type'),
            'publish_to_menu' => $request->input('publish_to_menu'),
            'menu_category_id' => $request->input('menu_category_id'),
            'menu_price' => $request->input('menu_price', 0),
            'menu_description' => $request->input('menu_description', ''),
            'note' => $request->input('note'),
            'materials' => $request->input('materials', []),
        ], current_user());

        flash('success', 'Production cuisine enregistree.');
        redirect($this->moduleUrl('/cuisine', $restaurantId));
    }

    public function validateKitchenReturn(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('kitchen.return.confirm');
        $incidentService = Container::getInstance()->get('incidentService');
        $caseId = (int) $request->input('case_id', 0);

        if ($caseId <= 0) {
            $caseId = $incidentService->signalSaleIncident($restaurantId, [
                'sale_item_id' => $request->input('sale_item_id'),
                'reported_category' => $request->input('reported_category', 'retour_simple'),
                'quantity_affected' => $request->input('quantity_affected'),
                'signal_notes' => $request->input('signal_notes', $request->input('return_reason', '')),
            ], current_user());
        }

        $technicalOutcome = (string) $request->input('technical_outcome', 'retour_simple');
        $incidentService->confirmKitchenCase($restaurantId, $caseId, [
            'technical_outcome' => $technicalOutcome,
            'technical_notes' => $request->input('technical_notes', $request->input('return_reason', '')),
        ], current_user());

        flash(
            'success',
            $technicalOutcome === 'retour_simple'
                ? 'Retour simple confirme par la cuisine et classe sans perte.'
                : 'Incident confirme par la cuisine et transmis au gerant.'
        );
        redirect($this->moduleUrl('/cuisine', $restaurantId));
    }

    public function signalKitchenIncident(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('kitchen.incident.signal');

        try {
            $this->assertDayStartAllowsNewOperations($restaurantId);
        } catch (\RuntimeException $exception) {
            flash('error', ui_safe_message($exception->getMessage()));
            redirect($this->moduleUrl('/cuisine', $restaurantId));
        }

        Container::getInstance()->get('incidentService')->signalKitchenIncident($restaurantId, [
            'production_id' => $request->input('production_id'),
            'reported_category' => $request->input('reported_category', 'produit_defectueux'),
            'quantity_affected' => $request->input('quantity_affected'),
            'signal_notes' => $request->input('signal_notes'),
        ], current_user());

        flash('success', 'Incident cuisine signale au gerant.');
        redirect($this->moduleUrl('/cuisine', $restaurantId));
    }

    public function requestKitchenStock(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('kitchen.stock.request');

        try {
            $this->assertDayStartAllowsNewOperations($restaurantId);
        } catch (\RuntimeException $exception) {
            flash('error', ui_safe_message($exception->getMessage()));
            redirect($this->moduleUrl('/cuisine', $restaurantId));
        }

        Container::getInstance()->get('stockService')->createKitchenStockRequest($restaurantId, [
            'stock_item_id' => $request->input('stock_item_id'),
            'quantity_requested' => $request->input('quantity_requested'),
            'priority_level' => $request->input('priority_level', 'normale'),
            'note' => $request->input('note'),
            'items' => $this->kitchenStockRequestItemsPayload($request),
        ], current_user());

        flash('success', 'Demande cuisine vers stock enregistree.');
        redirect($this->moduleUrl('/cuisine', $restaurantId));
    }

    public function fulfillServerRequestItem(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('kitchen.request.fulfill');

        try {
            Container::getInstance()->get('kitchenService')->fulfillServerRequestItem(
                $restaurantId,
                (int) $request->route('id'),
                [
                    'supplied_quantity' => $request->input('supplied_quantity'),
                    'workflow_stage' => $request->input('workflow_stage', 'PRET_A_SERVIR'),
                ],
                current_user()
            );
        } catch (\Throwable $e) {
            flash('error', ui_safe_message($e->getMessage()));
            redirect($this->moduleUrl('/cuisine', $restaurantId));
            return;
        }

        flash(
            'success',
            $request->input('workflow_stage', 'PRET_A_SERVIR') === 'EN_PREPARATION'
                ? 'La demande est maintenant en préparation côté cuisine.'
                : 'La demande est prête à servir et attend la confirmation du serveur.'
        );
        redirect($this->moduleUrl('/cuisine', $restaurantId));
    }

    public function declineServerRequest(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('kitchen.request.fulfill');

        try {
            Container::getInstance()->get('salesService')->declineServerRequestByKitchen(
                $restaurantId,
                (int) $request->route('id'),
                (string) $request->input('reason', ''),
                current_user()
            );
            flash('success', 'Commande declinee. Le service voit le motif dans son historique.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }

        redirect($this->moduleUrl('/cuisine', $restaurantId));
    }

    public function cancelKitchenStockRequest(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('kitchen.stock.request');

        try {
            Container::getInstance()->get('stockService')->cancelKitchenStockRequestByKitchen(
                $restaurantId,
                (int) $request->route('id'),
                (string) $request->input('reason', ''),
                current_user()
            );
            flash('success', 'Demande stock annulee avant traitement.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }

        redirect($this->moduleUrl('/cuisine', $restaurantId));
    }

    public function declineKitchenStockRequest(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('stock.request.respond');

        try {
            Container::getInstance()->get('stockService')->declineKitchenStockRequestByStock(
                $restaurantId,
                (int) $request->route('id'),
                (string) $request->input('reason', ''),
                current_user()
            );
            flash('success', 'Demande cuisine declinee. La cuisine voit le motif dans son historique.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }

        redirect($this->stockUrl($restaurantId));
    }

    public function sales(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('sales.view');
        $flashSuccess = flash('success');
        $flashError = flash('error');
        $incidentCatalog = $this->incidentCatalog();

        $actor = current_user();
        session_release_read_lock();
        $serverScopeId = null;
        if (is_array($actor) && ($actor['role_code'] ?? null) === 'cashier_server' && (int) ($actor['id'] ?? 0) > 0) {
            $serverScopeId = (int) $actor['id'];
        }
        $dash = $this->operationalDashboardBundle($request, $restaurantId, $serverScopeId, false);
        $selfDisc = $this->staffDisciplineOperationalExtras($dash, $restaurantId, is_array($actor) ? $actor : null, ['cashier_server']);
        $agentCash = $serverScopeId !== null
            ? Container::getInstance()->get('reportService')->agentServerCashAccountReadModel($restaurantId, $serverScopeId)
            : null;
        $salesPeriodWindow = Container::getInstance()->get('reportService')->operationalPeriodWindow(
            $restaurantId,
            (string) ($dash['dash_preset'] ?? 'today'),
            (string) ($dash['dash_date'] ?? Container::getInstance()->get('reportService')->todayForRestaurant($restaurantId)),
        );
        $servedWithoutSalePeriod = Container::getInstance()->get('salesService')->listServedRequestsWithoutSaleForPeriod(
            $restaurantId,
            $salesPeriodWindow['start'],
            $salesPeriodWindow['end'],
            $serverScopeId,
        );

        view('operations/sales', array_merge($dash, $selfDisc, [
            'title' => 'Ventes',
            'restaurant' => Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId),
            'sales' => Container::getInstance()->get('salesService')->listSales($restaurantId, $this->salesActorIdFilter()),
            'sale_items' => Container::getInstance()->get('salesService')->listSaleItemsForRestaurant($restaurantId, $this->salesActorIdFilter()),
            'server_requests' => Container::getInstance()->get('salesService')->listServerRequests($restaurantId, $this->salesActorIdFilter()),
            'server_request_items' => Container::getInstance()->get('salesService')->listServerRequestItems($restaurantId, $this->salesActorIdFilter()),
            'server_cashiers' => Container::getInstance()->get('cashService')->dashboard($restaurantId)['cashiers'] ?? [],
            'sale_remittance_tracking' => Container::getInstance()->get('cashService')->listSaleRemittanceTracking($restaurantId, $this->salesActorIdFilter()),
            'pending_cash_remittances' => Container::getInstance()->get('cashService')->listServerRemittanceCandidates($restaurantId, $this->salesActorIdFilter()),
            'menu_items' => Container::getInstance()->get('menuAdmin')->listPublicItems($restaurantId),
            'productions' => Container::getInstance()->get('kitchenService')->listProductions($restaurantId),
            'sales_overview' => Container::getInstance()->get('salesService')->serverSalesOverview($restaurantId, $this->salesActorIdFilter()),
            'served_requests_without_sale_period' => $servedWithoutSalePeriod,
            'agent_server_cash' => $agentCash,
            'incident_types' => $incidentCatalog['incident_types'],
            'day_start_hold' => Container::getInstance()->get('regularizationGate')->assessForUser($restaurantId, is_array($actor) ? $actor : []),
            'regularization_backlog' => Container::getInstance()->get('salesService')->regularizationBacklogCounts($restaurantId, $serverScopeId),
            'cash_today_snapshot' => $serverScopeId !== null
                ? null
                : Container::getInstance()->get('reportService')->cashTodayOperationalSnapshot($restaurantId),
            'sales_view_scope' => $serverScopeId !== null ? 'self' : 'full',
            'staff_gauges_panel_title' => 'Discipline · service et ventes',
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
            'manager_resolution_panel' => $this->buildManagerResolutionPanelFromRequest($request, $restaurantId),
            'manager_recent_decisions' => \App\Services\ManagerResolutionService::actorCanResolve(is_array($actor) ? $actor : null)
                ? Container::getInstance()->get('managerResolution')->listRecentResponsibleDecisions($restaurantId, 14)
                : [],
        ]));

        audit_access('sales', $restaurantId, 'screens', 'sales', 'Consultation module ventes');
    }

    public function cash(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('cash.view');
        $actor = current_user();
        $flashSuccess = flash('success');
        $flashError = flash('error');
        session_release_read_lock();

        $filters = [
            'date_from' => (string) ($request->query['date_from'] ?? ''),
            'date_to' => (string) ($request->query['date_to'] ?? ''),
            'status' => (string) ($request->query['status'] ?? ''),
            'movement_type' => (string) ($request->query['movement_type'] ?? ''),
            'user_id' => (int) ($request->query['user_id'] ?? 0),
        ];

        $today = Container::getInstance()->get('reportService')->todayForRestaurant($restaurantId);
        $clarityFrom = $filters['date_from'] !== '' ? $filters['date_from'] : $today;
        $clarityTo = $filters['date_to'] !== '' ? $filters['date_to'] : $today;
        $cashClarity = Container::getInstance()->get('cashService')->periodCashClarity($restaurantId, $clarityFrom, $clarityTo);

        $dash = $this->operationalDashboardBundle($request, $restaurantId);
        $hold = Container::getInstance()->get('regularizationGate')->assessForUser($restaurantId, is_array($actor) ? $actor : []);
        $disc = $this->staffDisciplineOperationalExtras(
            $dash,
            $restaurantId,
            is_array($actor) ? $actor : null,
            ['cashier_accountant', 'manager', 'owner'],
        );

        view('operations/cash', array_merge($dash, $disc, [
            'title' => 'Caisse',
            'restaurant' => Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId),
            'cash' => Container::getInstance()->get('cashService')->dashboard($restaurantId, $filters),
            'sales' => Container::getInstance()->get('salesService')->listSales($restaurantId),
            'users' => Container::getInstance()->get('roleAdmin')->listUsersForRestaurant($restaurantId),
            'filters' => $filters,
            'cash_clarity_period' => $cashClarity,
            'cash_today_snapshot' => Container::getInstance()->get('reportService')->cashTodayOperationalSnapshot($restaurantId),
            'day_start_hold' => $hold,
            'regularization_backlog' => Container::getInstance()->get('salesService')->regularizationBacklogCounts($restaurantId),
            'staff_gauges_panel_title' => 'Discipline · caisse',
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
            'manager_resolution_panel' => $this->buildManagerResolutionPanelFromRequest($request, $restaurantId),
            'manager_recent_decisions' => \App\Services\ManagerResolutionService::actorCanResolve(is_array($actor) ? $actor : [])
                ? Container::getInstance()->get('managerResolution')->listRecentResponsibleDecisions($restaurantId, 14)
                : [],
        ]));

        audit_access('cash', $restaurantId, 'screens', 'cash', 'Consultation module caisse');
    }

    public function createSale(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('sales.create');

        try {
            $this->assertDayStartAllowsNewOperations($restaurantId);
        } catch (\RuntimeException $exception) {
            flash('error', ui_safe_message($exception->getMessage()));
            redirect($this->moduleUrl('/ventes', $restaurantId));
        }
        $items = [[
            'menu_item_id' => $request->input('menu_item_id'),
            'kitchen_production_id' => (string) $request->input('kitchen_production_id', ''),
            'quantity' => $request->input('quantity'),
            'unit_price' => $request->input('unit_price'),
        ]];

        $requestedStatus = (string) $request->input('status', 'VALIDE');
        if (!can_access('sales.cancel') && $requestedStatus === 'ANNULE') {
            $requestedStatus = 'EN_COURS';
        }

        Container::getInstance()->get('salesService')->createSale($restaurantId, [
            'sale_type' => $request->input('sale_type', 'SUR_PLACE'),
            'status' => $requestedStatus,
            'note' => $request->input('note'),
            'items' => $items,
        ], current_user());

        flash('success', 'Vente enregistree.');
        redirect($this->moduleUrl('/ventes', $restaurantId));
    }

    public function printSaleReceipt(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('sales.view');

        view('operations/receipt', [
            'title' => 'Facture',
            'restaurant' => Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId),
            'receipt' => Container::getInstance()->get('cashService')->printableReceipt($restaurantId, (int) $request->route('id')),
        ]);
    }

    public function createServerRequest(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('sales.request.create');

        try {
            $this->assertDayStartAllowsNewOperations($restaurantId);
        } catch (\RuntimeException $exception) {
            flash('error', ui_safe_message($exception->getMessage()));
            redirect($this->moduleUrl('/ventes', $restaurantId));
        }

        $items = $this->serverRequestItemsPayload($request);

        $newRequestId = Container::getInstance()->get('salesService')->createServerRequest($restaurantId, [
            'service_reference' => $request->input('service_reference'),
            'note' => $request->input('note'),
            'items' => $items,
        ], current_user());

        flash('success', 'Demande serveur enregistree #' . (string) $newRequestId . '.');
        redirect($this->moduleUrl('/ventes', $restaurantId) . '#op-focus-server_request-' . (string) $newRequestId);
    }

    public function cancelServerRequest(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('sales.request.create');

        try {
            Container::getInstance()->get('salesService')->cancelServerRequestByServer(
                $restaurantId,
                (int) $request->route('id'),
                (string) $request->input('reason', ''),
                current_user()
            );
            flash('success', 'Demande annulee avant prise en charge cuisine.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }

        redirect($this->moduleUrl('/ventes', $restaurantId));
    }

    public function closeServerRequest(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('sales.request.close');

        $requestId = (int) $request->route('id');
        Container::getInstance()->get('salesService')->closeServerRequestAsSale($restaurantId, $requestId, [
            'sale_type' => $request->input('sale_type', 'SUR_PLACE'),
            'sold_quantities' => (array) $request->input('sold_quantities', []),
            'returned_quantities' => (array) $request->input('returned_quantities', []),
        ], current_user());

        flash('success', 'Demande serveur cloturee avec vente reelle.');
        redirect($this->moduleUrl('/ventes', $restaurantId));
    }

    public function confirmServerRequestReceipt(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('sales.request.close');

        Container::getInstance()->get('salesService')->confirmServerRequestReceipt(
            $restaurantId,
            (int) $request->route('id'),
            current_user()
        );

        flash('success', 'Remise cuisine confirmée par le serveur.');
        redirect($this->moduleUrl('/ventes', $restaurantId));
    }

    public function postManagerResolutionSales(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('sales.view');
        $actor = current_user();
        if (!\App\Services\ManagerResolutionService::actorCanResolve(is_array($actor) ? $actor : null)) {
            flash('error', 'Action reservee au responsable.');
            redirect($this->moduleUrl('/ventes', $restaurantId));
        }
        $redirectExtra = trim((string) $request->input('return_focus', ''));
        try {
            $kind = (string) $request->input('entity_kind', '');
            if ($kind !== 'server_request') {
                throw new \RuntimeException('Type d entite non gere.');
            }
            Container::getInstance()->get('salesService')->managerResolveServerRequest(
                $restaurantId,
                (int) $request->input('entity_id', 0),
                (string) $request->input('decision', ''),
                (string) $request->input('reason', ''),
                is_array($actor) ? $actor : [],
                [
                    'grant_clemency' => $request->input('grant_clemency', ''),
                    'clemency_reason' => $request->input('clemency_reason', ''),
                    'imputation_basis' => $request->input('imputation_basis', ''),
                ],
            );
            flash('success', 'Decision responsable enregistree sur la commande.');
        } catch (\Throwable $e) {
            $msg = ui_safe_message($e->getMessage());
            if ((str_contains(strtolower($msg), 'deja') && str_contains(strtolower($msg), 'tranche'))
                || str_contains(strtolower($msg), 'deja ete envoyee')) {
                flash('success', 'Déjà traité par responsable — aucune double action.');
            } else {
                flash('error', $msg);
            }
        }
        $this->redirectWithQuerySuffix($this->moduleUrl('/ventes', $restaurantId), $redirectExtra);
    }

    public function postManagerResolutionCash(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('cash.view');
        $actor = current_user();
        if (!\App\Services\ManagerResolutionService::actorCanResolve(is_array($actor) ? $actor : null)) {
            flash('error', 'Action reservee au responsable.');
            redirect($this->moduleUrl('/caisse', $restaurantId));
        }
        $redirectExtra = trim((string) $request->input('return_focus', ''));
        try {
            Container::getInstance()->get('cashService')->managerResolveSaleRemittance(
                $restaurantId,
                (int) $request->input('transfer_id', 0),
                [
                    'decision' => $request->input('decision', ''),
                    'reason' => $request->input('reason', ''),
                    'amount_accepted' => $request->input('amount_accepted', 0),
                    'imputation_basis' => $request->input('imputation_basis', ''),
                    'grant_clemency' => $request->input('grant_clemency', ''),
                    'clemency_reason' => $request->input('clemency_reason', ''),
                ],
                is_array($actor) ? $actor : [],
            );
            flash('success', 'Decision responsable enregistree sur la remise caisse.');
        } catch (\Throwable $e) {
            $msg = ui_safe_message($e->getMessage());
            if ((str_contains(strtolower($msg), 'deja') && str_contains(strtolower($msg), 'tranche'))
                || str_contains(strtolower($msg), 'deja ete envoyee')) {
                flash('success', 'Déjà traité par responsable — aucune double action.');
            } else {
                flash('error', $msg);
            }
        }
        $this->redirectWithQuerySuffix($this->moduleUrl('/caisse', $restaurantId), $redirectExtra);
    }

    public function postStockPhysicalRecord(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('stock.control.perform');
        $actor = current_user();
        $returnTo = trim((string) $request->input('return_to', 'report'));
        $scDate = trim((string) $request->input('sc_date', ''));
        $scPeriod = trim((string) $request->input('sc_period', 'daily'));
        try {
            Container::getInstance()->get('stockControlReport')->recordPhysicalChecksFromRequest(
                $restaurantId,
                $_POST,
                is_array($actor) ? $actor : null,
            );
            flash('success', 'Contrôle physique enregistré — aucune correction automatique du stock.');
        } catch (\Throwable $e) {
            flash('error', ui_safe_message($e->getMessage()));
        }
        if ($returnTo === 'stock') {
            $q = http_build_query(array_filter([
                'sc_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $scDate) ? $scDate : null,
                'sc_period' => $scPeriod !== '' ? $scPeriod : null,
            ], static fn ($v): bool => $v !== null && $v !== ''));

            redirect($this->stockUrl($restaurantId) . ($q !== '' ? '?' . $q : ''));
        }
        $q = http_build_query(array_filter([
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $scDate) ? $scDate : null,
            'period' => $scPeriod !== '' ? $scPeriod : null,
        ], static fn ($v): bool => $v !== null && $v !== ''));

        redirect($this->moduleUrl('/rapport', $restaurantId) . ($q !== '' ? '?' . $q : ''));
    }

    private function buildManagerResolutionPanelFromRequest(Request $request, int $restaurantId): ?array
    {
        $focusRaw = trim((string) ($request->query['focus'] ?? ''));
        if ($focusRaw === '' || !str_contains($focusRaw, ':')) {
            return null;
        }
        [$fk, $fid] = explode(':', $focusRaw, 2);
        $fk = trim($fk);
        $fid = (int) trim($fid);
        if ($fk === '' || $fid <= 0) {
            return null;
        }

        return Container::getInstance()->get('managerResolution')->buildPanelContext(
            $restaurantId,
            $fk,
            $fid,
            current_user()
        );
    }

    private function redirectWithQuerySuffix(string $base, string $extraQuery): void
    {
        if ($extraQuery !== '') {
            $q = ltrim($extraQuery, '?&');
            $base .= (str_contains($base, '?') ? '&' : '?') . $q;
        }
        redirect($base);
    }

    public function confirmKitchenStockReceipt(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('kitchen.stock.request');

        Container::getInstance()->get('stockService')->confirmKitchenStockReceipt(
            $restaurantId,
            (int) $request->route('id'),
            current_user()
        );

        flash('success', 'Réception du stock confirmée par la cuisine.');
        redirect($this->moduleUrl('/cuisine', $restaurantId));
    }

    public function signalSaleIncident(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('sales.incident.signal');

        try {
            $this->assertDayStartAllowsNewOperations($restaurantId);
        } catch (\RuntimeException $exception) {
            flash('error', ui_safe_message($exception->getMessage()));
            redirect($this->moduleUrl('/ventes', $restaurantId));
        }

        Container::getInstance()->get('incidentService')->signalSaleIncident($restaurantId, [
            'sale_item_id' => $request->input('sale_item_id'),
            'reported_category' => $request->input('reported_category', 'retour_avec_anomalie'),
            'quantity_affected' => $request->input('quantity_affected'),
            'signal_notes' => $request->input('signal_notes'),
        ], current_user());

        flash('success', 'Incident vente ou service signale au gerant.');
        redirect($this->moduleUrl('/ventes', $restaurantId));
    }

    public function decideCase(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        enforce_restaurant_access(false);
        authorize_access('incident.decide');

        Container::getInstance()->get('incidentService')->decideCase($restaurantId, (int) $request->route('id'), [
            'decision_status' => $request->input('decision_status', 'VALIDE'),
            'final_qualification' => $request->input('final_qualification'),
            'responsibility_scope' => $request->input('responsibility_scope', 'restaurant'),
            'responsible_user_id' => $request->input('responsible_user_id', 0),
            'material_loss_amount' => $request->input('material_loss_amount', 0),
            'cash_loss_amount' => $request->input('cash_loss_amount', 0),
            'manager_justification' => $request->input('manager_justification'),
        ], current_user());

        flash('success', 'Decision manager enregistree avec justification.');
        $redirectTo = (string) $request->input('redirect_to', '/ventes');
        if (!in_array($redirectTo, ['/owner', '/ventes', '/cuisine', '/stock'], true)) {
            $redirectTo = '/ventes';
        }

        redirect($this->moduleUrl($redirectTo, $restaurantId));
    }

    public function createCashLoss(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('cash_loss.declare');

        try {
            $this->assertDayStartAllowsNewOperations($restaurantId);
        } catch (\RuntimeException $exception) {
            flash('error', ui_safe_message($exception->getMessage()));
            redirect($this->moduleUrl('/caisse', $restaurantId));
        }

        Container::getInstance()->get('stockService')->declareCashLoss($restaurantId, [
            'reference_id' => (string) $request->input('reference_id', ''),
            'description' => $request->input('description'),
            'amount' => $request->input('amount'),
        ], current_user());

        flash('success', 'Perte d argent enregistree.');
        redirect($this->moduleUrl('/ventes', $restaurantId));
    }

    public function remitServerCash(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('cash.remit.server');

        Container::getInstance()->get('cashService')->remitServerCash($restaurantId, [
            'sale_id' => $request->input('sale_id'),
            'to_user_id' => $request->input('to_user_id'),
            'note' => $request->input('note'),
        ], current_user());

        flash('success', 'Remise serveur enregistree.');
        $redirectTo = (string) ($request->server['HTTP_REFERER'] ?? '');
        if (str_contains($redirectTo, '/caisse')) {
            redirect($this->moduleUrl('/caisse', $restaurantId));
        }

        redirect($this->moduleUrl('/ventes', $restaurantId));
    }

    public function receiveCashAtCashier(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('cash.receive.cashier');

        Container::getInstance()->get('cashService')->receiveByCashier(
            $restaurantId,
            (int) $request->route('id'),
            [
                'amount_received' => $request->input('amount_received'),
                'discrepancy_note' => $request->input('discrepancy_note'),
            ],
            current_user()
        );

        flash('success', 'Reception caisse confirmee.');
        redirect($this->moduleUrl('/caisse', $restaurantId));
    }

    public function rejectSaleRemittanceAtCashier(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('cash.receive.cashier');

        try {
            Container::getInstance()->get('cashService')->rejectSaleRemittanceByCashier(
                $restaurantId,
                (int) $request->route('id'),
                (string) $request->input('reason', ''),
                current_user()
            );
            flash('success', 'Remise rejetee par la caisse (tracee avec motif).');
        } catch (\Throwable $e) {
            flash('error', ui_safe_message($e->getMessage()));
        }

        redirect($this->moduleUrl('/caisse', $restaurantId));
    }

    public function submitSaleRemittanceToManagerFromCashier(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('cash.receive.cashier');

        try {
            Container::getInstance()->get('cashService')->submitSaleRemittanceToManager(
                $restaurantId,
                (int) $request->route('id'),
                (string) $request->input('reason', ''),
                current_user()
            );
            flash('success', 'Remise soumise au gerant pour decision.');
        } catch (\Throwable $e) {
            flash('error', ui_safe_message($e->getMessage()));
        }

        redirect($this->moduleUrl('/caisse', $restaurantId));
    }

    public function decideManagerSaleRemittance(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        $actor = current_user();
        if (($actor['role_code'] ?? null) !== 'manager') {
            flash('error', 'Seul le gerant peut trancher cette file.');
            redirect($this->moduleUrl('/owner', $restaurantId));
        }
        authorize_access('cash.receive.manager');

        try {
            Container::getInstance()->get('cashService')->managerDecideSaleRemittance(
                $restaurantId,
                (int) $request->route('id'),
                (string) $request->input('decision', ''),
                (string) $request->input('reason', ''),
                $actor
            );
            flash('success', 'Decision gerant enregistree sur la remise.');
        } catch (\Throwable $e) {
            flash('error', ui_safe_message($e->getMessage()));
        }

        redirect($this->moduleUrl('/owner', $restaurantId));
    }

    public function decideLateSaleRemittanceAttribution(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        $actor = current_user();
        if (!in_array($actor['role_code'] ?? null, ['manager', 'owner'], true)) {
            flash('error', 'Action reservee au gerant ou au proprietaire.');
            redirect($this->moduleUrl('/owner', $restaurantId));
        }
        if (($actor['role_code'] ?? null) === 'manager') {
            authorize_access('cash.receive.manager');
        } else {
            authorize_access('cash.receive.owner');
        }

        try {
            Container::getInstance()->get('cashService')->decideLateRemittanceAttribution(
                $restaurantId,
                (int) $request->route('id'),
                (string) $request->input('basis', ''),
                $actor
            );
            flash('success', 'Rattachement de la remise tardive enregistre.');
        } catch (\Throwable $e) {
            flash('error', ui_safe_message($e->getMessage()));
        }

        redirect($this->moduleUrl('/owner', $restaurantId));
    }

    public function createCashMovement(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('cash.expense.manage');

        try {
            $this->assertDayStartAllowsNewOperations($restaurantId);
        } catch (\RuntimeException $exception) {
            flash('error', ui_safe_message($exception->getMessage()));
            redirect($this->moduleUrl('/caisse', $restaurantId));
        }

        Container::getInstance()->get('cashService')->createMovement($restaurantId, [
            'movement_type' => $request->input('movement_type', 'ENTREE'),
            'amount' => $request->input('amount'),
            'note' => $request->input('note'),
            'source_type' => $request->input('source_type', 'manual'),
            'source_id' => $request->input('source_id', 0),
        ], current_user());

        flash('success', 'Mouvement de caisse enregistre.');
        redirect($this->moduleUrl('/caisse', $restaurantId));
    }

    public function reclassifyCashMovementManager(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        $actor = current_user() ?? [];
        $rc = (string) ($actor['role_code'] ?? '');
        if (!in_array($rc, ['owner', 'manager'], true)) {
            flash('error', 'Réserve aux comptes gérant ou propriétaire.');
            redirect($this->moduleUrl('/caisse', $restaurantId));
        }
        authorize_access('cash.view');

        try {
            Container::getInstance()->get('cashService')->managerReclassifyMovement(
                $restaurantId,
                (int) $request->route('id'),
                [
                    'movement_type' => (string) $request->input('movement_type', ''),
                    'reason' => (string) $request->input('reason', ''),
                ],
                $actor
            );
            flash('success', 'Mouvement reclasse (trace conservee, aucune suppression).');
        } catch (\Throwable $e) {
            flash('error', ui_safe_message($e->getMessage()));
        }

        redirect($this->moduleUrl('/caisse', $restaurantId));
    }

    public function transferCashToManager(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('cash.transfer.manager');

        try {
            $this->assertDayStartAllowsNewOperations($restaurantId);
        } catch (\RuntimeException $exception) {
            flash('error', ui_safe_message($exception->getMessage()));
            redirect($this->moduleUrl('/caisse', $restaurantId));
        }

        Container::getInstance()->get('cashService')->transferToManager($restaurantId, [
            'to_user_id' => $request->input('to_user_id'),
            'amount' => $request->input('amount'),
            'note' => $request->input('note'),
        ], current_user());

        flash('success', 'Fonds remis au gerant.');
        redirect($this->moduleUrl('/caisse', $restaurantId));
    }

    public function receiveCashAtManager(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('cash.receive.manager');

        Container::getInstance()->get('cashService')->receiveByManager(
            $restaurantId,
            (int) $request->route('id'),
            ['amount_received' => $request->input('amount_received')],
            current_user()
        );

        flash('success', 'Reception gerant confirmee.');
        redirect($this->moduleUrl('/caisse', $restaurantId));
    }

    public function transferCashToOwner(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('cash.transfer.owner');

        try {
            $this->assertDayStartAllowsNewOperations($restaurantId);
        } catch (\RuntimeException $exception) {
            flash('error', ui_safe_message($exception->getMessage()));
            redirect($this->moduleUrl('/caisse', $restaurantId));
        }

        Container::getInstance()->get('cashService')->transferToOwner($restaurantId, [
            'to_user_id' => $request->input('to_user_id'),
            'amount' => $request->input('amount'),
            'note' => $request->input('note'),
        ], current_user());

        flash('success', 'Fonds remis au proprietaire.');
        redirect($this->moduleUrl('/caisse', $restaurantId));
    }

    public function receiveCashAtOwner(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('cash.receive.owner');

        Container::getInstance()->get('cashService')->receiveByOwner(
            $restaurantId,
            (int) $request->route('id'),
            ['amount_received' => $request->input('amount_received')],
            current_user()
        );

        flash('success', 'Reception proprietaire confirmee.');
        redirect($this->moduleUrl('/caisse', $restaurantId));
    }

    public function report(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('reports.view');
        $actor = current_user() ?? [];
        session_release_read_lock();

        $reportPreset = trim((string) ($request->query['report_preset'] ?? ''));
        $date = (string) ($request->query['date'] ?? Container::getInstance()->get('reportService')->todayForRestaurant($restaurantId));
        $period = (string) ($request->query['period'] ?? 'daily');
        $todayYmd = Container::getInstance()->get('reportService')->todayForRestaurant($restaurantId);
        if (in_array($reportPreset, ['today', 'yesterday', 'week', 'month'], true)) {
            $restaurantRow = Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId);
            $tzName = (string) ($restaurantRow['timezone'] ?? config('app.timezone', 'Africa/Lagos'));
            try {
                $tz = new \DateTimeZone($tzName);
            } catch (\Throwable) {
                $tz = new \DateTimeZone((string) config('app.timezone', 'Africa/Lagos'));
            }

            if ($reportPreset === 'today') {
                $date = $todayYmd;
                $period = 'daily';
            } elseif ($reportPreset === 'yesterday') {
                $date = (new \DateTimeImmutable($todayYmd . ' 00:00:00', $tz))->modify('-1 day')->format('Y-m-d');
                $period = 'daily';
            } elseif ($reportPreset === 'week') {
                $date = $todayYmd;
                $period = 'weekly';
            } elseif ($reportPreset === 'month') {
                $date = $todayYmd;
                $period = 'monthly';
            }
        }
        if (!in_array($period, ['daily', 'weekly', 'monthly'], true)) {
            $period = 'daily';
        }

        $actionScope = (string) ($request->query['action_scope'] ?? 'all');
        if (!in_array($actionScope, ['all', 'sales', 'cash', 'stock', 'kitchen'], true)) {
            $actionScope = 'all';
        }

        $viewFilters = [
            'user_id' => (int) ($request->query['user_id'] ?? 0),
            'role_code' => trim((string) ($request->query['role_code'] ?? '')),
            'action_scope' => $actionScope,
            'action_name' => trim((string) ($request->query['action_name'] ?? '')),
            'closed_sales_only' => isset($request->query['closed_sales_only']) && (string) $request->query['closed_sales_only'] === '1',
            'menu_item_id' => (int) ($request->query['menu_item_id'] ?? 0),
            'stock_item_id' => (int) ($request->query['stock_item_id'] ?? 0),
            'stock_movement_type' => trim((string) ($request->query['stock_movement_type'] ?? '')),
            'article_search' => trim((string) ($request->query['article_search'] ?? '')),
            'activity_agent_search' => trim((string) ($request->query['activity_agent_search'] ?? '')),
            'timeline_actor_search' => trim((string) ($request->query['timeline_actor_search'] ?? '')),
            'timeline_limit' => (int) ($request->query['timeline_limit'] ?? 350),
        ];

        $isServerReporter = ($actor['role_code'] ?? '') === 'cashier_server' && (int) ($actor['id'] ?? 0) > 0;
        if ($isServerReporter) {
            $viewFilters['user_id'] = (int) $actor['id'];
            $viewFilters['__financial_scope_server_id'] = (int) $actor['id'];
        }

        $reportUsers = Container::getInstance()->get('roleAdmin')->listUsersForRestaurant($restaurantId);
        if ($isServerReporter) {
            $selfId = (int) $actor['id'];
            $reportUsers = array_values(array_filter(
                $reportUsers,
                static fn (array $u): bool => (int) ($u['id'] ?? 0) === $selfId
            ));
        }
        $reportRoleCodes = [];
        foreach ($reportUsers as $ru) {
            $rc = (string) ($ru['role_code'] ?? '');
            if ($rc !== '') {
                $reportRoleCodes[$rc] = true;
            }
        }
        ksort($reportRoleCodes);

        $title = match ($period) {
            'weekly' => 'Rapport hebdomadaire',
            'monthly' => 'Rapport mensuel',
            default => 'Rapport journalier',
        };

        $dash = $this->operationalDashboardBundle(
            $request,
            $restaurantId,
            $isServerReporter ? (int) $actor['id'] : null,
            false,
        );

        $stockControlBundle = null;
        if (can_access('stock.control.report.view')) {
            $stockControlBundle = Container::getInstance()->get('stockControlReport')->buildBundle($restaurantId, $date, $period);
        }

        $staffDisc = Container::getInstance()->get('staffDiscipline');
        $staffDisc->ensureSchema();
        $disciplineSchedule = $staffDisc->disciplineWorkScheduleForRestaurant($restaurantId);
        $disciplinaryAlerts = (!$isServerReporter && can_access('staff.team_gauges.view'))
            ? $staffDisc->listDisciplinaryAlerts($restaurantId)
            : [];

        view('operations/report', array_merge($dash, [
            'title' => $title,
            'restaurant' => Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId),
            'date' => $date,
            'period' => $period,
            'view_filters' => $viewFilters,
            'report_users' => $reportUsers,
            'report_role_codes' => array_keys($reportRoleCodes),
            'report_menu_items' => Container::getInstance()->get('menuAdmin')->listPublicItems($restaurantId),
            'report_stock_items' => array_values(array_filter(
                Container::getInstance()->get('stockService')->listItems($restaurantId),
                static fn (array $row): bool => empty($row['archived_at'])
            )),
            'report' => Container::getInstance()->get('reportService')->dailyReport($restaurantId, $date, $period, $viewFilters),
            'cash_today_snapshot' => $isServerReporter
                ? null
                : Container::getInstance()->get('reportService')->cashTodayOperationalSnapshot($restaurantId),
            'pending_late_remittance_attributions' => Container::getInstance()->get('cashService')->listPendingLateRemittanceAttributions($restaurantId),
            'today_ymd_restaurant' => $todayYmd,
            'regularization_backlog' => Container::getInstance()->get('salesService')->regularizationBacklogCounts(
                $restaurantId,
                $isServerReporter ? (int) $actor['id'] : null,
            ),
            'report_agent_filter_locked' => $isServerReporter,
            'day_start_hold' => Container::getInstance()->get('regularizationGate')->assessForUser($restaurantId, $actor),
            'stock_control_bundle' => $stockControlBundle,
            'stock_control_return_to' => 'report',
            'stock_control_stock_query' => '',
            'discipline_work_schedule' => $disciplineSchedule,
            'disciplinary_alerts' => $disciplinaryAlerts,
        ]));

        audit_access('reports', $restaurantId, 'screens', 'daily-report', 'Consultation rapport journalier');
    }

    public function decideCorrectionRequest(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('correction.approve');

        Container::getInstance()->get('correctionService')->decide(
            $restaurantId,
            (int) $request->route('id'),
            [
                'decision' => $request->input('decision'),
                'review_notes' => $request->input('review_notes'),
            ],
            current_user()
        );

        flash('success', 'Decision sur la demande de correction enregistree.');
        redirect($this->moduleUrl('/owner', $restaurantId));
    }

    public function myDiscipline(Request $request): void
    {
        $actor = current_user();
        if (!is_array($actor) || ($actor['scope'] ?? null) === 'super_admin') {
            http_response_code(403);
            echo '403 Forbidden';

            return;
        }
        $restaurantId = $this->resolveRestaurantId($request);
        if ($restaurantId <= 0) {
            redirect('/login');
        }
        if (can_access('staff.team_gauges.view')) {
            redirect('/owner/discipline');
        }
        $staffDisc = Container::getInstance()->get('staffDiscipline');
        $staffDisc->ensureSchema();
        $todayY = Container::getInstance()->get('reportService')->todayForRestaurant($restaurantId);
        $dash = $this->operationalDashboardBundle($request, $restaurantId, (int) ($actor['id'] ?? 0), false);
        $role = (string) ($actor['role_code'] ?? '');
        $highlights = [];
        if (in_array($role, ['cashier_server', 'kitchen', 'stock_manager', 'cashier_accountant'], true)) {
            $highlights = Container::getInstance()->get('reportService')->userAuditHighlightsForOperationalPreset(
                $restaurantId,
                (int) ($actor['id'] ?? 0),
                (string) ($dash['dash_preset'] ?? 'today'),
                (string) ($dash['dash_date'] ?? $todayY),
                $role,
            );
        }
        view('operations/my-discipline', array_merge($dash, [
            'title' => 'Ma discipline',
            'user' => $actor,
            'restaurant' => Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId),
            'my_gauges' => $staffDisc->gaugesForUserOperationalPanel($restaurantId, (int) ($actor['id'] ?? 0), 'today', $todayY),
            'discipline_schedule' => $staffDisc->disciplineWorkScheduleForRestaurant($restaurantId),
            'staff_audit_highlights' => $highlights,
        ]));

        audit_access('discipline', $restaurantId, 'screens', 'my-discipline', 'Consultation discipline personnelle');
    }

    private function resolveRestaurantId(Request $request): int
    {
        return current_restaurant_id();
    }

    /**
     * @return array{dash_preset: string, dash_date: string, today_ymd_restaurant: string, module_today_pulse: array<string, mixed>}
     */
    private function operationalDashboardBundle(
        Request $request,
        int $restaurantId,
        ?int $pulseRestrictServerUserId = null,
        bool $pulseHideRestaurantSalesClosureKpis = false,
    ): array {
        $rs = Container::getInstance()->get('reportService');
        $todayY = $rs->todayForRestaurant($restaurantId);
        $preset = strtolower(trim((string) ($request->query['dash_preset'] ?? 'today')));
        $allowed = ['today', 'yesterday', 'date', 'week', 'month', 'prev_month'];
        if (!in_array($preset, $allowed, true)) {
            $preset = 'today';
        }
        $dRaw = trim((string) ($request->query['dash_date'] ?? ''));
        $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dRaw) ? $dRaw : $todayY;
        $pulse = $rs->moduleOperationalPulse(
            $restaurantId,
            $preset,
            $d,
            $pulseRestrictServerUserId,
            $pulseHideRestaurantSalesClosureKpis,
        );

        return [
            'dash_preset' => $preset,
            'dash_date' => $d,
            'today_ymd_restaurant' => $todayY,
            'module_today_pulse' => $pulse,
        ];
    }

    private function staffDisciplineOperationalExtras(
        array $dash,
        int $restaurantId,
        ?array $actor,
        array $rolesAllowed,
    ): array {
        if (!is_array($actor) || (int) ($actor['id'] ?? 0) <= 0) {
            return ['self_staff_gauges' => null, 'staff_audit_highlights' => []];
        }
        $role = (string) ($actor['role_code'] ?? '');
        if (!in_array($role, $rolesAllowed, true)) {
            return ['self_staff_gauges' => null, 'staff_audit_highlights' => []];
        }
        $g = Container::getInstance()->get('staffDiscipline')->gaugesForUserOperationalPanel(
            $restaurantId,
            (int) $actor['id'],
            (string) $dash['dash_preset'],
            (string) $dash['dash_date'],
        );
        $highlights = Container::getInstance()->get('reportService')->userAuditHighlightsForOperationalPreset(
            $restaurantId,
            (int) $actor['id'],
            (string) $dash['dash_preset'],
            (string) $dash['dash_date'],
            $role,
        );

        return ['self_staff_gauges' => $g, 'staff_audit_highlights' => $highlights];
    }

    private function assertDayStartAllowsNewOperations(int $restaurantId): void
    {
        $gate = Container::getInstance()->get('regularizationGate')->assessForUser($restaurantId, current_user() ?? []);
        if (!($gate['blocked'] ?? false)) {
            return;
        }
        $reasons = $gate['reasons'] ?? [];
        $text = $reasons !== [] ? implode(' ', $reasons) : 'Situation à régulariser avant toute nouvelle action.';

        throw new \RuntimeException($text);
    }

    private function salesActorIdFilter(): ?int
    {
        $user = current_user();

        return ($user['role_code'] ?? null) === 'cashier_server' ? (int) $user['id'] : null;
    }

    private function stockUrl(int $restaurantId): string
    {
        return $this->moduleUrl('/stock', $restaurantId);
    }

    private function moduleUrl(string $path, int $restaurantId): string
    {
        if ((current_user()['scope'] ?? null) === 'super_admin') {
            return $path . '?restaurant_id=' . $restaurantId;
        }

        return $path;
    }

    private function incidentCatalog(): array
    {
        $settings = Container::getInstance()->get('platformSettings')->listSystemSettings();

        return [
            'incident_types' => $settings['global_incident_types_json'] ?? [],
            'final_qualifications' => $settings['global_final_qualifications_json'] ?? [],
            'responsibility_targets' => $settings['global_responsibility_targets_json'] ?? [],
        ];
    }

    private function serverRequestItemsPayload(Request $request): array
    {
        $rawLines = $request->input('items', []);
        if (!is_array($rawLines) || $rawLines === []) {
            return [[
                'menu_item_id' => $request->input('menu_item_id'),
                'requested_quantity' => $request->input('requested_quantity', 1),
                'unit_price' => $request->input('unit_price', 0),
                'note' => $request->input('line_note', ''),
            ]];
        }

        $items = [];
        foreach ($rawLines as $line) {
            if (!is_array($line)) {
                continue;
            }

            $menuItemId = (int) ($line['menu_item_id'] ?? 0);
            $quantity = (float) ($line['requested_quantity'] ?? 0);
            $note = trim((string) ($line['note'] ?? ''));

            if ($menuItemId <= 0 || $quantity <= 0) {
                continue;
            }

            $items[] = [
                'menu_item_id' => $menuItemId,
                'requested_quantity' => $quantity,
                'unit_price' => $line['unit_price'] ?? 0,
                'note' => $note,
            ];
        }

        return $items;
    }

    private function kitchenStockRequestItemsPayload(Request $request): array
    {
        $rawLines = $request->input('items', []);
        if (!is_array($rawLines) || $rawLines === []) {
            return [[
                'stock_item_id' => $request->input('stock_item_id'),
                'quantity_requested' => $request->input('quantity_requested', 1),
                'priority_level' => $request->input('priority_level', 'normale'),
                'note' => $request->input('line_note', ''),
            ]];
        }

        $items = [];
        foreach ($rawLines as $line) {
            if (!is_array($line)) {
                continue;
            }

            $stockItemId = (int) ($line['stock_item_id'] ?? 0);
            $quantity = (float) ($line['quantity_requested'] ?? 0);
            if ($stockItemId <= 0 || $quantity <= 0) {
                continue;
            }

            $items[] = [
                'stock_item_id' => $stockItemId,
                'quantity_requested' => $quantity,
                'priority_level' => $line['priority_level'] ?? $request->input('priority_level', 'normale'),
                'note' => trim((string) ($line['note'] ?? '')),
            ];
        }

        return $items;
    }
}
