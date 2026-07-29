<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Services/ExternalAuditEngine.php';

use App\Services\ExternalAuditEngine;

$engine = new ExternalAuditEngine();
$assert = static function (float $expected, float $actual, string $label): void {
    if (abs($expected - $actual) > 0.001) {
        fwrite(STDERR, sprintf("ECHEC %s: attendu %.3f, obtenu %.3f\n", $label, $expected, $actual));
        exit(1);
    }
};

$normal = $engine->product([
    'previous_stock' => 10, 'purchased_quantity' => 20, 'remaining_stock' => 8,
    'sale_price_snapshot' => 4000,
]);
$assert(22, $normal['sold_quantity'], 'vente normale quantite');
$assert(88000, $normal['sale_amount'], 'vente normale montant');
$assert(0, $normal['injection_quantity'], 'vente normale injection');

$injection = $engine->product([
    'previous_stock' => 10, 'purchased_quantity' => 0, 'remaining_stock' => 15,
    'sale_price_snapshot' => 4000,
]);
$assert(0, $injection['sold_quantity'], 'injection vente');
$assert(5, $injection['injection_quantity'], 'injection quantite');
$assert(20000, $injection['injection_amount'], 'injection montant');

$missing = $engine->report([
    ['previous_stock' => 386.5, 'remaining_stock' => 0, 'sale_price_snapshot' => 1000],
], 356500, 0);
$assert(30000, $missing['missing_amount'], 'manquant');
$assert(0, $missing['suspicious_amount'], 'manquant sans suspect');

$extra = $engine->report([
    ['previous_stock' => 386.5, 'remaining_stock' => 0, 'sale_price_snapshot' => 1000],
], 400000, 0);
$assert(0, $extra['missing_amount'], 'declare en plus sans manquant');
$assert(13500, $extra['suspicious_amount'], 'declare en plus suspect');

$period = $engine->period([
    ['missing_amount' => 30000, 'suspicious_amount' => 0],
    ['missing_amount' => 0, 'suspicious_amount' => 20000],
]);
$assert(30000, $period['missing_amount'], 'periode non-compensation manquant');
$assert(20000, $period['suspicious_amount'], 'periode non-compensation suspect');

$assert(4000, $engine->purchaseUnitPrice(20000, 5), 'prix unitaire');
$assert(0, $engine->purchaseUnitPrice(20000, 0), 'prix unitaire quantite zero');
$assert(38, $engine->caseUnits(2, 1, 2, 12, 12), 'casiers demi casiers unites');
$decimal = $engine->product([
    'previous_stock' => 2.5, 'purchased_quantity' => 1.25, 'remaining_stock' => 1.5,
    'sale_price_snapshot' => 100.5,
]);
$assert(2.25, $decimal['sold_quantity'], 'quantite decimale');
$assert(226.125, $decimal['sale_amount'], 'montant decimal');

$equal = $engine->confrontation(
    [['product_id' => 1, 'product' => 'A', 'category' => 'Boissons', 'quantity' => 2, 'amount' => 8000, 'person' => 'Resp']],
    [['product_id' => 1, 'product' => 'A', 'category' => 'Boissons', 'quantity' => 2, 'amount' => 8000, 'person' => 'Serveur']]
);
$assert(0, $equal['global_gap'], 'confrontation egale');
$responsibleMore = $engine->confrontation(
    [['product_id' => 1, 'product' => 'A', 'category' => 'Boissons', 'quantity' => 5, 'amount' => 20000, 'person' => 'Resp']],
    [['product_id' => 1, 'product' => 'A', 'category' => 'Boissons', 'quantity' => 3, 'amount' => 12000, 'person' => 'Serveur']]
);
$assert(8000, $responsibleMore['global_gap'], 'confrontation responsables superieurs');
$assert(2, $responsibleMore['rows'][0]['quantity_gap'], 'confrontation ecart produit');
$serverMore = $engine->confrontation(
    [['product_id' => 1, 'product' => 'A', 'category' => 'Boissons', 'quantity' => 1, 'amount' => 4000]],
    [['product_id' => 1, 'product' => 'A', 'category' => 'Boissons', 'quantity' => 3, 'amount' => 12000]]
);
$assert(-8000, $serverMore['global_gap'], 'confrontation serveurs superieurs');

echo "OK ExternalAuditEngine: 20 assertions critiques\n";
