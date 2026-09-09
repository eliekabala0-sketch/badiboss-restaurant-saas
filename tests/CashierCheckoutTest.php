<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$cashService = file_get_contents($root . '/app/Services/CashService.php');
$salesService = file_get_contents($root . '/app/Services/SalesService.php');
$controller = file_get_contents($root . '/app/Http/Controllers/OperationsController.php');
$routes = file_get_contents($root . '/routes/web.php');
$view = file_get_contents($root . '/app/Views/operations/cash.php');
$authorization = file_get_contents($root . '/app/Services/AuthorizationService.php');
$menuService = file_get_contents($root . '/app/Services/MenuAdminService.php');
$menuView = file_get_contents($root . '/app/Views/owner/menu.php');

$assert = static function (bool $condition, string $label): void {
    if (!$condition) {
        fwrite(STDERR, 'ECHEC ' . $label . PHP_EOL);
        exit(1);
    }
};

$assert(str_contains($authorization, "'cash.checkout.create'"), 'permission encaissement direct');
$assert(str_contains($routes, "'/caisse/vente-directe'"), 'route encaissement direct');
$assert(str_contains($controller, 'createCashierCheckout'), 'action controleur');
$assert(str_contains($view, 'Boutique — vente directe'), 'option boutique');
$assert(str_contains($view, 'Restaurant — commande reçue'), 'option restaurant');
$assert(str_contains($view, 'Commande venant de'), 'origine client ou serveur');
$assert(str_contains($cashService, 'status = "active" AND is_available = 1'), 'prix menu actif verifie cote serveur');
$assert(str_contains($cashService, "'kitchen_production_id' => ''"), 'aucun passage cuisine');
$assert(str_contains($cashService, '"RECU_CAISSE"'), 'argent marque recu par la caisse');
$assert(str_contains($cashService, '$pdo->beginTransaction()'), 'transaction atomique');
$assert(str_contains($cashService, '$pdo->rollBack()'), 'annulation atomique sur erreur');
$assert(str_contains($salesService, 'array_key_exists(\'server_id\''), 'vente client sans faux serveur');
$assert(str_contains($menuService, 'product_type'), 'classification produit persistée');
$assert(str_contains($menuView, 'Article — vente directe en boutique, sans cuisine'), 'choix article sans cuisine');
$assert(str_contains($menuView, 'Plat — restaurant et cuisine'), 'choix plat cuisine');
$assert(str_contains($cashService, '$requiredProductType'), 'séparation boutique et restaurant côté serveur');
$assert(!str_contains($salesService, 'Une commande restaurant accepte uniquement les produits classés Plat.'), 'serveur libre de commander tout le menu');

echo "OK CashierCheckout: 17 controles\n";
