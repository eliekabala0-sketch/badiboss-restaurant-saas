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
        $incidentCatalog = $this->incidentCatalog();
        $kitchenStockBlocks = Container::getInstance()->get('stockService')->listKitchenStockRequestBlocks($restaurantId);

        Container::getInstance()->get('salesService')->reconcileOverdueReturnsToAutomaticSales($restaurantId);

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

        view('operations/stock', [
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
            'flash_success' => flash('success'),
            'flash_error' => flash('error'),
        ]);

        audit_access('stock', $restaurantId, 'screens', 'stock', 'Consultation module stock');
    }

    public function createStockItem(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('stock.create');

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

    public function addStockEntry(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('stock.entry.create');

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
        $incidentCatalog = $this->incidentCatalog();
        $kitchenStockBlocks = Container::getInstance()->get('stockService')->listKitchenStockRequestBlocks($restaurantId);

        Container::getInstance()->get('salesService')->reconcileOverdueReturnsToAutomaticSales($restaurantId);
        $allCases = Container::getInstance()->get('incidentService')->listCases($restaurantId);

        view('operations/kitchen', [
            'title' => 'Cuisine',
            'restaurant' => Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId),
            'productions' => Container::getInstance()->get('kitchenService')->listProductions($restaurantId),
            'server_request_items' => Container::getInstance()->get('kitchenService')->listPendingServerRequestItems($restaurantId),
            'server_request_history_items' => Container::getInstance()->get('salesService')->listServerRequestItems($restaurantId),
            'kitchen_stock_requests' => $kitchenStockBlocks['requests'],
            'kitchen_stock_request_items_by_request' => $kitchenStockBlocks['items_by_request'],
            'stock_items' => Container::getInstance()->get('stockService')->listItems($restaurantId),
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
            'flash_success' => flash('success'),
            'flash_error' => flash('error'),
        ]);

        audit_access('kitchen', $restaurantId, 'screens', 'kitchen', 'Consultation module cuisine');
    }

    public function createKitchenProduction(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('kitchen.production.create');

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

        Container::getInstance()->get('kitchenService')->fulfillServerRequestItem(
            $restaurantId,
            (int) $request->route('id'),
            [
                'supplied_quantity' => $request->input('supplied_quantity'),
                'workflow_stage' => $request->input('workflow_stage', 'PRET_A_SERVIR'),
            ],
            current_user()
        );

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
        $incidentCatalog = $this->incidentCatalog();

        $autoSales = Container::getInstance()->get('salesService')->reconcileOverdueReturnsToAutomaticSales($restaurantId);
        if ($autoSales > 0) {
            flash('success', $autoSales . ' ligne(s) serveur ou production en attente ont ete regularisee(s) automatiquement.');
        }

        view('operations/sales', [
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
            'incident_types' => $incidentCatalog['incident_types'],
            'flash_success' => flash('success'),
            'flash_error' => flash('error'),
        ]);

        audit_access('sales', $restaurantId, 'screens', 'sales', 'Consultation module ventes');
    }

    public function cash(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('cash.view');

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

        view('operations/cash', [
            'title' => 'Caisse',
            'restaurant' => Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId),
            'cash' => Container::getInstance()->get('cashService')->dashboard($restaurantId, $filters),
            'sales' => Container::getInstance()->get('salesService')->listSales($restaurantId),
            'users' => Container::getInstance()->get('roleAdmin')->listUsersForRestaurant($restaurantId),
            'filters' => $filters,
            'cash_clarity_period' => $cashClarity,
            'flash_success' => flash('success'),
            'flash_error' => flash('error'),
        ]);

        audit_access('cash', $restaurantId, 'screens', 'cash', 'Consultation module caisse');
    }

    public function createSale(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('sales.create');

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

        $items = $this->serverRequestItemsPayload($request);

        Container::getInstance()->get('salesService')->createServerRequest($restaurantId, [
            'service_reference' => $request->input('service_reference'),
            'note' => $request->input('note'),
            'items' => $items,
        ], current_user());

        flash('success', 'Demande serveur enregistree.');
        redirect($this->moduleUrl('/ventes', $restaurantId));
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

    public function createCashMovement(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('cash.expense.manage');

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

    public function transferCashToManager(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('cash.transfer.manager');

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

        Container::getInstance()->get('salesService')->reconcileOverdueReturnsToAutomaticSales($restaurantId);
        $date = (string) ($request->query['date'] ?? Container::getInstance()->get('reportService')->todayForRestaurant($restaurantId));
        $period = (string) ($request->query['period'] ?? 'daily');
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
        ];

        $reportUsers = Container::getInstance()->get('roleAdmin')->listUsersForRestaurant($restaurantId);
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

        view('operations/report', [
            'title' => $title,
            'restaurant' => Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId),
            'date' => $date,
            'period' => $period,
            'view_filters' => $viewFilters,
            'report_users' => $reportUsers,
            'report_role_codes' => array_keys($reportRoleCodes),
            'report_menu_items' => Container::getInstance()->get('menuAdmin')->listPublicItems($restaurantId),
            'report_stock_items' => Container::getInstance()->get('stockService')->listItems($restaurantId),
            'report' => Container::getInstance()->get('reportService')->dailyReport($restaurantId, $date, $period, $viewFilters),
        ]);

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

    private function resolveRestaurantId(Request $request): int
    {
        return current_restaurant_id();
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
