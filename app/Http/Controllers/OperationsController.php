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

        Container::getInstance()->get('salesService')->reconcileOverdueReturnsToAutomaticSales($restaurantId);

        view('operations/stock', [
            'title' => 'Stock',
            'restaurant' => Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId),
            'items' => Container::getInstance()->get('stockService')->listItems($restaurantId),
            'movements' => Container::getInstance()->get('stockService')->listMovements($restaurantId),
            'kitchen_stock_requests' => Container::getInstance()->get('stockService')->listKitchenStockRequests($restaurantId),
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
        ], current_user());

        flash('success', 'Article de stock cree.');
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

    public function kitchen(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('kitchen.view');
        $incidentCatalog = $this->incidentCatalog();

        Container::getInstance()->get('salesService')->reconcileOverdueReturnsToAutomaticSales($restaurantId);
        $allCases = Container::getInstance()->get('incidentService')->listCases($restaurantId);

        view('operations/kitchen', [
            'title' => 'Cuisine',
            'restaurant' => Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId),
            'productions' => Container::getInstance()->get('kitchenService')->listProductions($restaurantId),
            'server_request_items' => Container::getInstance()->get('kitchenService')->listPendingServerRequestItems($restaurantId),
            'server_request_history_items' => Container::getInstance()->get('salesService')->listServerRequestItems($restaurantId),
            'kitchen_stock_requests' => Container::getInstance()->get('stockService')->listKitchenStockRequests($restaurantId),
            'stock_items' => Container::getInstance()->get('stockService')->listItems($restaurantId),
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
            'menu_items' => Container::getInstance()->get('menuAdmin')->listPublicItems($restaurantId),
            'productions' => Container::getInstance()->get('kitchenService')->listProductions($restaurantId),
            'sales_overview' => Container::getInstance()->get('salesService')->serverSalesOverview($restaurantId, $this->salesActorIdFilter()),
            'incident_types' => $incidentCatalog['incident_types'],
            'flash_success' => flash('success'),
            'flash_error' => flash('error'),
        ]);

        audit_access('sales', $restaurantId, 'screens', 'sales', 'Consultation module ventes');
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

    public function createServerRequest(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('sales.request.create');

        $items = [[
            'menu_item_id' => $request->input('menu_item_id'),
            'requested_quantity' => $request->input('requested_quantity', 1),
            'unit_price' => $request->input('unit_price', 0),
        ]];

        Container::getInstance()->get('salesService')->createServerRequest($restaurantId, [
            'service_reference' => $request->input('service_reference'),
            'note' => $request->input('note'),
            'items' => $items,
        ], current_user());

        flash('success', 'Demande serveur enregistree.');
        redirect($this->moduleUrl('/ventes', $restaurantId));
    }

    public function closeServerRequest(Request $request): void
    {
        $restaurantId = $this->resolveRestaurantId($request);
        authorize_access('sales.request.close');

        $requestId = (int) $request->route('id');
        Container::getInstance()->get('salesService')->closeServerRequestAsSale($restaurantId, $requestId, [
            'sale_type' => $request->input('sale_type', 'SUR_PLACE'),
            'sold_quantities' => [
                (string) $request->input('request_item_id') => $request->input('sold_quantity', 0),
            ],
            'returned_quantities' => [
                (string) $request->input('request_item_id') => $request->input('returned_quantity', 0),
            ],
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
            'report' => Container::getInstance()->get('reportService')->dailyReport($restaurantId, $date, $period),
        ]);

        audit_access('reports', $restaurantId, 'screens', 'daily-report', 'Consultation rapport journalier');
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
}
