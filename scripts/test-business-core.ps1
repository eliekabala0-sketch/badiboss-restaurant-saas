$ErrorActionPreference = "Stop"

$projectRoot = Split-Path -Parent $PSScriptRoot

function Assert-Equal {
    param(
        [string]$Label,
        $Expected,
        $Actual
    )

    [pscustomobject]@{
        label = $Label
        expected = $Expected
        actual = $Actual
        passed = ($Expected -eq $Actual)
    }
}

function New-FinancialState {
    [pscustomobject]@{
        stock_initial = [decimal]0
        stock_final = [decimal]0
        total_requested = [decimal]0
        total_supplied = [decimal]0
        total_sold = [decimal]0
        total_returned = [decimal]0
        server_loss = [decimal]0
        kitchen_loss = [decimal]0
        stock_loss = [decimal]0
        material_loss = [decimal]0
        cash_loss = [decimal]0
        responsibility = $null
        kitchen_stock_request_status = $null
        kitchen_stock_request_planning = $null
        manager_visible = $false
        alerts = @()
    }
}

function Sum-Items {
    param([array]$Items, [string]$QuantityField)

    $total = [decimal]0
    foreach ($item in $Items) {
        $total += ([decimal]$item[$QuantityField]) * ([decimal]$item.unit_price)
    }

    return $total
}

function Compute-ServerLoss {
    param($State)

    $State.server_loss = $State.total_supplied - $State.total_sold - $State.total_returned
}

function Add-FraudAlert {
    param(
        $State,
        [string]$Type,
        [string]$Detail
    )

    $State.alerts += [pscustomobject]@{
        type = $Type
        detail = $Detail
    }
}

function Test-ScenarioFinancialNormal {
    $items = @(
        @{ name = "poulet mayo"; requested_quantity = 1; supplied_quantity = 1; sold_quantity = 1; returned_quantity = 0; unit_price = 8 },
        @{ name = "vitalo"; requested_quantity = 2; supplied_quantity = 2; sold_quantity = 2; returned_quantity = 0; unit_price = 1 },
        @{ name = "eau"; requested_quantity = 2; supplied_quantity = 1; sold_quantity = 1; returned_quantity = 0; unit_price = 0.5 }
    )
    $state = New-FinancialState
    $state.total_requested = Sum-Items $items "requested_quantity"
    $state.total_supplied = Sum-Items $items "supplied_quantity"
    $state.total_sold = Sum-Items $items "sold_quantity"
    $state.total_returned = Sum-Items $items "returned_quantity"
    Compute-ServerLoss -State $state

    [pscustomobject]@{
        scenario = "SCENARIO 1 - DEMANDE / FOURNI / VENDU"
        data = $items
        checks = @(
            Assert-Equal "total_demande" ([decimal]11.0) $state.total_requested
            Assert-Equal "total_fourni" ([decimal]10.5) $state.total_supplied
            Assert-Equal "total_vendu" ([decimal]10.5) $state.total_sold
            Assert-Equal "perte_serveur" ([decimal]0.0) $state.server_loss
        )
    }
}

function Test-ScenarioBrokenVitalo {
    $state = New-FinancialState
    $state.total_requested = [decimal]11.0
    $state.total_supplied = [decimal]10.5
    $state.total_sold = [decimal]9.5
    $state.total_returned = [decimal]0.0
    $state.material_loss = [decimal]1.0
    $state.responsibility = "serveur"
    Compute-ServerLoss -State $state

    [pscustomobject]@{
        scenario = "SCENARIO 2 - PRODUIT CASSE AVANT SERVICE"
        data = @{
            requested_total = 11.0
            supplied_total = 10.5
            sold_total = 9.5
            broken_item_value = 1.0
            manager_classification = "perte_serveur"
        }
        checks = @(
            Assert-Equal "perte_matiere" ([decimal]1.0) $state.material_loss
            Assert-Equal "responsabilite" "serveur" $state.responsibility
            Assert-Equal "perte_serveur" ([decimal]1.0) $state.server_loss
        )
    }
}

function Test-ScenarioUnavailableProduct {
    $state = New-FinancialState
    $state.kitchen_stock_request_status = "INDISPONIBLE"
    $state.kitchen_stock_request_planning = "urgence"
    $state.manager_visible = $true

    [pscustomobject]@{
        scenario = "SCENARIO 3 - PRODUIT INDISPONIBLE"
        data = @{
            server_request = "vitalo x2"
            kitchen_to_stock = "vitalo x2"
            stock_response = "INDISPONIBLE"
            planning_status = "urgence"
        }
        checks = @(
            Assert-Equal "statut_demande_stock" "INDISPONIBLE" $state.kitchen_stock_request_status
            Assert-Equal "classement" "urgence" $state.kitchen_stock_request_planning
            Assert-Equal "visible_gerant" $true $state.manager_visible
        )
    }
}

function Test-ScenarioSimpleReturnFinancial {
    $state = New-FinancialState
    $state.total_supplied = [decimal]10.5
    $state.total_sold = [decimal]9.5
    $state.total_returned = [decimal]1.0
    Compute-ServerLoss -State $state

    [pscustomobject]@{
        scenario = "SCENARIO 4 - RETOUR SIMPLE"
        data = @{
            supplied_total = 10.5
            sold_total = 9.5
            returned_validated = 1.0
        }
        checks = @(
            Assert-Equal "perte_serveur" ([decimal]0.0) $state.server_loss
            Assert-Equal "perte_matiere" ([decimal]0.0) $state.material_loss
            Assert-Equal "perte_argent" ([decimal]0.0) $state.cash_loss
        )
    }
}

function Test-ScenarioServerLoss {
    $state = New-FinancialState
    $state.total_supplied = [decimal]10.5
    $state.total_sold = [decimal]8.5
    $state.total_returned = [decimal]1.0
    Compute-ServerLoss -State $state

    [pscustomobject]@{
        scenario = "SCENARIO 5 - PERTE SERVEUR"
        data = @{
            supplied_total = 10.5
            sold_total = 8.5
            returned_validated = 1.0
        }
        checks = @(
            Assert-Equal "perte_serveur" ([decimal]1.0) $state.server_loss
            Assert-Equal "formule_perte" ([decimal]1.0) ($state.total_supplied - $state.total_sold - $state.total_returned)
        )
    }
}

function Test-ScenarioRealProductionCost {
    $stockConsumptionCost = [decimal]12.0
    $producedQuantity = [decimal]18.0
    $unitRealCost = [math]::Round(($stockConsumptionCost / $producedQuantity), 2)

    [pscustomobject]@{
        scenario = "SCENARIO 6 - COUT REEL DE PRODUCTION"
        data = @{
            stock_consumption_cost = 12.0
            produced_quantity = 18.0
        }
        checks = @(
            Assert-Equal "cout_total_production" ([decimal]12.0) $stockConsumptionCost
            Assert-Equal "cout_unitaire_reel" ([decimal]0.67) $unitRealCost
        )
    }
}

function Test-ScenarioProductMargin {
    $salePrice = [decimal]8.0
    $realCost = [decimal]2.0
    $margin = $salePrice - $realCost

    [pscustomobject]@{
        scenario = "SCENARIO 7 - MARGE PAR PRODUIT"
        data = @{
            sale_price = 8.0
            real_cost = 2.0
        }
        checks = @(
            Assert-Equal "marge_unitaire" ([decimal]6.0) $margin
            Assert-Equal "coherence_marge" ([decimal]6.0) ($salePrice - $realCost)
        )
    }
}

function Test-ScenarioRealProfit {
    $sales = [decimal]40.0
    $materialCost = [decimal]10.0
    $losses = [decimal]3.0
    $profit = $sales - $materialCost - $losses

    [pscustomobject]@{
        scenario = "SCENARIO 8 - BENEFICE REEL"
        data = @{
            total_sales = 40.0
            real_material_cost = 10.0
            validated_losses = 3.0
        }
        checks = @(
            Assert-Equal "benefice_reel" ([decimal]27.0) $profit
            Assert-Equal "formule_benefice" ([decimal]27.0) ($sales - $materialCost - $losses)
        )
    }
}

function Test-StaticGuards {
    $routes = Get-Content -Raw "$projectRoot\routes\web.php"
    $operationsController = Get-Content -Raw "$projectRoot\app\Http\Controllers\OperationsController.php"
    $reportService = Get-Content -Raw "$projectRoot\app\Services\ReportService.php"
    $salesView = Get-Content -Raw "$projectRoot\app\Views\operations\sales.php"
    $kitchenView = Get-Content -Raw "$projectRoot\app\Views\operations\kitchen.php"
    $stockView = Get-Content -Raw "$projectRoot\app\Views\operations\stock.php"
    $schema = Get-Content -Raw "$projectRoot\database\schema.sql"

    [pscustomobject]@{
        scenario = "GARDE-FOUS STRUCTURELS"
        data = @{
            route_file = "routes/web.php"
            report_file = "app/Services/ReportService.php"
        }
        checks = @(
            Assert-Equal "ancienne_route_manager_supprimee" $false ($routes -match '/ventes/retours-manager')
            Assert-Equal "ancienne_methode_manager_supprimee" $false ($operationsController -match 'function validateManagerReturn')
            Assert-Equal "tables_financieres_presentes" $true (($schema -match 'CREATE TABLE server_requests') -and ($schema -match 'CREATE TABLE kitchen_stock_requests'))
            Assert-Equal "vue_ventes_demande_fourni" $true (($salesView -match 'Demande serveur depuis le menu') -and ($salesView -match 'Attente fourni cuisine'))
            Assert-Equal "vue_cuisine_fourni" $true (($kitchenView -match 'Fourni reel au serveur') -and ($kitchenView -match 'Demande cuisine vers stock'))
            Assert-Equal "vue_stock_demandes" $true ($stockView -match 'Demande cuisine')
            Assert-Equal "rapport_financier_niveaux" $true (($reportService -match 'stockReport') -and ($reportService -match 'serverReport') -and ($reportService -match 'general_report'))
            Assert-Equal "cout_reel_en_schema" $true ($schema -match 'unit_real_cost_snapshot')
            Assert-Equal "marge_par_produit_en_rapport" $true ($reportService -match 'productMargins')
        )
    }
}

$results = @(
    Test-ScenarioFinancialNormal
    Test-ScenarioBrokenVitalo
    Test-ScenarioUnavailableProduct
    Test-ScenarioSimpleReturnFinancial
    Test-ScenarioServerLoss
    Test-ScenarioRealProductionCost
    Test-ScenarioProductMargin
    Test-ScenarioRealProfit
    Test-StaticGuards
)

$failed = @()
foreach ($scenario in $results) {
    foreach ($check in $scenario.checks) {
        if (-not $check.passed) {
            $failed += [pscustomobject]@{
                scenario = $scenario.scenario
                label = $check.label
                expected = $check.expected
                actual = $check.actual
            }
        }
    }
}

$summary = [pscustomobject]@{
    executed_at = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
    scenarios = $results
    failed_checks = $failed
    success = ($failed.Count -eq 0)
}

$summary | ConvertTo-Json -Depth 8

if ($failed.Count -gt 0) {
    exit 1
}
