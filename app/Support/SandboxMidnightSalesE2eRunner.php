<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Container;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use ReflectionMethod;
use RuntimeException;

/**
 * E2E sandbox-only : boisson → REMIS_SERVEUR → backdate → runner minuit.
 * Utilisable depuis le CLI (après bootstrap Container) ou depuis l’app HTTP (Container déjà prêt).
 */
final class SandboxMidnightSalesE2eRunner
{
    public const CODE = 'test-ventes-minuit';

    /**
     * @return array{
     *   report_lines: list<string>,
     *   exit_code: int,
     *   metrics: array<string, mixed>,
     *   checks: array<string, bool>
     * }
     */
    public static function execute(string $runLabel = 'CLI'): array
    {
        $lines = [];
        $lines[] = '=== Sandbox midnight sales E2E (' . $runLabel . ') ===';
        $lines[] = 'commit_actif=' . self::deploymentCommitShort();

        try {
            self::assertSandboxEnv();
        } catch (RuntimeException $e) {
            $lines[] = 'garde_sandbox=NON';
            $lines[] = 'erreur=' . $e->getMessage();

            return self::failureResult($lines, $e->getMessage());
        }

        $lines[] = 'garde_sandbox=OK';

        try {
            return self::executeCore($lines, $runLabel);
        } catch (\Throwable $e) {
            $lines[] = '';
            $lines[] = '--- Erreur ---';
            $lines[] = $e->getMessage();

            return self::failureResult($lines, $e->getMessage());
        }
    }

    /**
     * @param list<string> $lines
     *
     * @return array{
     *   report_lines: list<string>,
     *   exit_code: int,
     *   metrics: array<string, mixed>,
     *   checks: array<string, bool>
     * }
     */
    private static function executeCore(array $lines, string $runLabel): array
    {
        $pdo = Container::getInstance()->get('db')->pdo();
        $systemActor = self::systemActor();
        $tenantProvisioning = Container::getInstance()->get('tenantProvisioning');
        $restaurantAdmin = Container::getInstance()->get('restaurantAdmin');
        $subscriptionService = Container::getInstance()->get('subscriptionService');

        $st = $pdo->prepare('SELECT * FROM restaurants WHERE restaurant_code = :code LIMIT 1');
        $st->execute(['code' => self::CODE]);
        $restaurant = $st->fetch(PDO::FETCH_ASSOC);

        if ($restaurant === false) {
            $newId = $tenantProvisioning->createRestaurant([
                'name' => strtoupper(self::CODE),
                'restaurant_code' => self::CODE,
                'slug' => self::CODE,
                'support_email' => 'sandbox+' . self::CODE . '@badiboss.test',
                'phone' => '+243000000000',
                'city' => 'Sandbox',
                'country' => 'CD',
                'address_line' => 'Sandbox only',
                'public_name' => strtoupper(self::CODE),
                'subscription_plan_id' => 1,
                'status' => 'active',
                'subscription_status' => 'ACTIVE',
                'subscription_payment_status' => 'PAID',
                'timezone' => 'Africa/Kinshasa',
                'currency_code' => 'USD',
            ], $systemActor);
            $restaurant = $restaurantAdmin->findRestaurant($newId);
            if ($restaurant === null) {
                throw new RuntimeException('Restaurant sandbox non résolu après création.');
            }
            $lines[] = 'Restaurant sandbox créé id=' . (string) $newId;
        } else {
            $lines[] = 'Restaurant sandbox existant id=' . (string) ($restaurant['id'] ?? '');
        }

        $restaurantId = self::assertRestaurantRow($restaurant);
        $lockedCode = strtolower(trim((string) ($restaurant['restaurant_code'] ?? '')));

        $subscription = $subscriptionService->summaryForRestaurant($restaurantId);
        if (!is_array($subscription) || !(bool) ($subscription['is_operational'] ?? false)) {
            $subscriptionService->activateRestaurant($restaurantId, [
                'subscription_started_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                'subscription_duration_days' => 3650,
                'payment_status' => 'WAIVED',
                'justification' => 'Activation abonnement sandbox (E2E minuit)',
            ], $systemActor);
            $lines[] = 'Abonnement sandbox activé.';
        }

        $accountsLog = self::ensureSandboxAccounts($restaurantId, self::CODE, $systemActor);
        if ($accountsLog !== []) {
            $lines[] = 'Comptes sandbox: ' . implode(', ', $accountsLog);
        }

        $ownerActor = self::loadUserActor($restaurantId, 'owner-' . self::CODE . '@badiboss.test');
        $serverActor = self::loadUserActor($restaurantId, 'server-' . self::CODE . '@badiboss.test');
        $kitchenActor = self::loadUserActor($restaurantId, 'kitchen-' . self::CODE . '@badiboss.test');

        $menuAdmin = Container::getInstance()->get('menuAdmin');
        $stockService = Container::getInstance()->get('stockService');
        $salesService = Container::getInstance()->get('salesService');
        $kitchenService = Container::getInstance()->get('kitchenService');
        $cashService = Container::getInstance()->get('cashService');

        $categoryId = 0;
        $catStmt = $pdo->prepare(
            'SELECT id FROM menu_categories WHERE restaurant_id = :rid AND slug = "boisson" LIMIT 1'
        );
        $catStmt->execute(['rid' => $restaurantId]);
        $categoryId = (int) ($catStmt->fetchColumn() ?: 0);
        if ($categoryId <= 0) {
            $menuAdmin->createCategory($restaurantId, [
                'name' => 'Boissons',
                'slug' => 'boisson',
                'description' => 'Sandbox boissons',
                'display_order' => 99,
                'status' => 'active',
            ], $ownerActor);
            $catStmt->execute(['rid' => $restaurantId]);
            $categoryId = (int) ($catStmt->fetchColumn() ?: 0);
            if ($categoryId <= 0) {
                throw new RuntimeException('Catégorie boisson non résolue après création.');
            }
            $lines[] = 'Catégorie boisson créée id=' . (string) $categoryId;
        }

        $label = 'SANDBOX-NKOYI-' . date('Ymd-His');
        $slug = self::slugify($label);

        $miStmt = $pdo->prepare(
            'SELECT id FROM menu_items WHERE restaurant_id = :rid AND name = :name LIMIT 1'
        );
        $miStmt->execute(['rid' => $restaurantId, 'name' => $label]);
        $menuItemId = (int) ($miStmt->fetchColumn() ?: 0);
        if ($menuItemId <= 0) {
            $menuAdmin->createItem($restaurantId, [
                'category_id' => $categoryId,
                'name' => $label,
                'slug' => $slug,
                'price' => 2.5,
                'status' => 'active',
                'is_available' => true,
                'display_order' => 0,
                'available_dine_in' => true,
                'description' => 'Article E2E sandbox minuit',
            ], $ownerActor);
            $miStmt->execute(['rid' => $restaurantId, 'name' => $label]);
            $menuItemId = (int) ($miStmt->fetchColumn() ?: 0);
            if ($menuItemId <= 0) {
                throw new RuntimeException('Article menu non résolu après création.');
            }
            $lines[] = 'Article menu créé id=' . (string) $menuItemId . ' name=' . $label;
        }

        $siStmt = $pdo->prepare(
            'SELECT id FROM stock_items WHERE restaurant_id = :rid AND name = :name LIMIT 1'
        );
        $siStmt->execute(['rid' => $restaurantId, 'name' => $label]);
        $stockItemId = (int) ($siStmt->fetchColumn() ?: 0);
        if ($stockItemId <= 0) {
            $stockService->createItem($restaurantId, [
                'name' => $label,
                'unit_name' => 'unite',
                'quantity_in_stock' => 500.0,
                'alert_threshold' => 0.0,
                'estimated_unit_cost' => 0.5,
                'category_label' => 'Boisson',
            ], $ownerActor);
            $siStmt->execute(['rid' => $restaurantId, 'name' => $label]);
            $stockItemId = (int) ($siStmt->fetchColumn() ?: 0);
            if ($stockItemId <= 0) {
                throw new RuntimeException('Article stock non résolu après création.');
            }
            $lines[] = 'Article stock créé id=' . (string) $stockItemId;
        } else {
            $stockService->createItem($restaurantId, [
                'name' => $label,
                'unit_name' => 'unite',
                'quantity_in_stock' => 100.0,
                'alert_threshold' => 0.0,
                'estimated_unit_cost' => 0.5,
                'category_label' => 'Boisson',
            ], $ownerActor);
            $siStmt->execute(['rid' => $restaurantId, 'name' => $label]);
            $stockItemId = (int) ($siStmt->fetchColumn() ?: 0);
            $lines[] = 'Article stock réutilisé / rechargé id=' . (string) $stockItemId;
        }

        $stockService->findKitchenInventoryMatchForMenuItem($restaurantId, $menuItemId, 0.0);
        if ($stockService->findKitchenInventoryMatchForMenuItem($restaurantId, $menuItemId, 1.0) === null) {
            self::bootstrapKitchenInventoryLocked($restaurantId, $stockItemId, 50.0, $lockedCode);
            $lines[] = 'Stock cuisine (kitchen_inventory) initialisé +50 pour correspondance nom menu.';
        }

        $kitchenQtyBefore = self::kitchenQty($restaurantId, $stockItemId);

        $serviceRef = 'SANDBOX-E2E-' . bin2hex(random_bytes(4));
        $serverRequestId = $salesService->createServerRequest($restaurantId, [
            'service_reference' => $serviceRef,
            'note' => $runLabel . ' sandbox_midnight_sales_e2e',
            'items' => [
                ['menu_item_id' => $menuItemId, 'requested_quantity' => 1.0, 'note' => ''],
            ],
        ], $serverActor);

        $itStmt = $pdo->prepare(
            'SELECT id FROM server_request_items WHERE request_id = :rid AND restaurant_id = :rest LIMIT 1'
        );
        $itStmt->execute(['rid' => $serverRequestId, 'rest' => $restaurantId]);
        $serverRequestItemId = (int) ($itStmt->fetchColumn() ?: 0);
        if ($serverRequestItemId <= 0) {
            throw new RuntimeException('Ligne demande serveur introuvable.');
        }

        $bevBefore = self::beverageMovementCount($restaurantId, $serverRequestItemId);

        $enumWarm = new ReflectionMethod(\App\Services\StockService::class, 'ensureStockMovementEnum');
        $enumWarm->setAccessible(true);
        $enumWarm->invoke($stockService);

        $kitchenService->fulfillServerRequestItem($restaurantId, $serverRequestItemId, [
            'workflow_stage' => 'PRET_A_SERVIR',
            'supplied_quantity' => 1.0,
        ], $kitchenActor);

        $salesService->confirmServerRequestReceipt($restaurantId, $serverRequestId, $serverActor);

        $reqStatusStmt = $pdo->prepare(
            'SELECT status FROM server_requests WHERE id = :id AND restaurant_id = :rid LIMIT 1'
        );
        $reqStatusStmt->execute(['id' => $serverRequestId, 'rid' => $restaurantId]);
        $statusAfterReceipt = (string) ($reqStatusStmt->fetchColumn() ?: '');
        if ($statusAfterReceipt !== 'REMIS_SERVEUR') {
            throw new RuntimeException('Statut REMIS_SERVEUR non atteint (obtenu: ' . $statusAfterReceipt . ').');
        }

        $kitchenQtyAfterFulfill = self::kitchenQty($restaurantId, $stockItemId);
        $bevAfterFulfill = self::beverageMovementCount($restaurantId, $serverRequestItemId);

        $salesService->backdateSandboxRemittedRequestActivityYesterday($restaurantId, $serverRequestId, $ownerActor);

        $tzName = (string) ($restaurant['timezone'] ?? 'Africa/Kinshasa');
        $tz = new DateTimeZone($tzName);
        $yesterdayYmd = (new DateTimeImmutable('now', $tz))->modify('-1 day')->format('Y-m-d');

        $chk = $pdo->prepare('SELECT received_at FROM server_requests WHERE id = :id AND restaurant_id = :rid LIMIT 1');
        $chk->execute(['id' => $serverRequestId, 'rid' => $restaurantId]);
        $recvAt = (string) ($chk->fetchColumn() ?: '');
        if (!str_starts_with($recvAt, $yesterdayYmd) || !str_contains($recvAt, '15:00:00')) {
            $lines[] = '[WARN] received_at attendu hier 15:00 (' . $tzName . '), obtenu: ' . $recvAt;
        }

        $dry = $salesService->runSandboxMidnightReconcile($restaurantId, $ownerActor, true);
        $candidateIds = $dry['candidate_request_ids'] ?? [];
        $ourRequestIsCandidate = in_array($serverRequestId, is_array($candidateIds) ? $candidateIds : [], true);

        $exec1 = $salesService->runSandboxMidnightReconcile($restaurantId, $ownerActor, false);
        $exec2 = $salesService->runSandboxMidnightReconcile($restaurantId, $ownerActor, false);

        $kitchenQtyAfterRunners = self::kitchenQty($restaurantId, $stockItemId);
        $bevAfterRunners = self::beverageMovementCount($restaurantId, $serverRequestItemId);

        $saleCountStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM sales
             WHERE restaurant_id = :rid AND origin_type = "server_request" AND origin_id = :oid'
        );
        $saleCountStmt->execute(['rid' => $restaurantId, 'oid' => $serverRequestId]);
        $salesLinkedCount = (int) $saleCountStmt->fetchColumn();

        $sale = self::findSaleForRequest($restaurantId, $serverRequestId);
        $saleId = $sale !== null ? (int) ($sale['id'] ?? 0) : 0;
        $cashTablePresent = self::tableExists($pdo, 'cash_transfers');
        $cashCount = ($cashTablePresent && $saleId > 0) ? self::cashTransfersForSale($restaurantId, $saleId) : ($cashTablePresent ? 0 : -1);

        $manquantVisible = false;
        if ($cashTablePresent && $saleId > 0) {
            $remittanceCandidates = $cashService->listServerRemittanceCandidates($restaurantId, (int) $serverActor['id']);
            foreach ($remittanceCandidates as $c) {
                if ((int) ($c['sale_id'] ?? 0) === $saleId) {
                    $tid = (int) ($c['transfer_id'] ?? 0);
                    if ($tid <= 0) {
                        $manquantVisible = true;
                        break;
                    }
                }
            }
        }

        $reqFinal = $pdo->prepare('SELECT status FROM server_requests WHERE id = :id LIMIT 1');
        $reqFinal->execute(['id' => $serverRequestId]);
        $statusFinal = (string) ($reqFinal->fetchColumn() ?: '');

        $saleActivity = $sale['sale_activity_at'] ?? null;
        $saleActivityYmd = is_string($saleActivity) && $saleActivity !== '' ? substr($saleActivity, 0, 10) : '';

        $lines[] = '';
        $lines[] = '--- Rapport ---';
        $lines[] = 'restaurant_sandbox_id=' . (string) $restaurantId;
        $lines[] = 'user_server_id=' . (string) $serverActor['id'];
        $lines[] = 'user_kitchen_id=' . (string) $kitchenActor['id'];
        $lines[] = 'menu_item_id=' . (string) $menuItemId;
        $lines[] = 'stock_item_id=' . (string) $stockItemId;
        $lines[] = 'server_request_id=' . (string) $serverRequestId;
        $lines[] = 'status_apres_runner=' . $statusFinal;
        $lines[] = 'dry_run_candidate_count=' . (string) ($dry['candidate_count'] ?? -1);
        $lines[] = 'dry_run_created_count=' . (string) ($dry['created_count'] ?? -1);
        $lines[] = 'ventes_liees_avant_dry=' . (string) ($dry['sales_linked_before'] ?? -1);
        $lines[] = 'exec1_created_count=' . (string) ($exec1['created_count'] ?? -1);
        $lines[] = 'exec1_sales_linked_apres=' . (string) ($exec1['sales_linked_after'] ?? -1);
        $lines[] = 'exec2_created_count=' . (string) ($exec2['created_count'] ?? -1);
        $lines[] = 'ventes_liees_server_request_count=' . (string) $salesLinkedCount;
        $lines[] = 'notre_demande_dans_candidats_dry_run=' . ($ourRequestIsCandidate ? 'oui' : 'non');
        $lines[] = 'sale_id=' . (string) $saleId;
        $lines[] = 'sale_activity_ymd=' . $saleActivityYmd . ' (attendu jour activité: ' . $yesterdayYmd . ')';
        $lines[] = 'stock_cuisine_avant_fulfill=' . (string) $kitchenQtyBefore;
        $lines[] = 'stock_cuisine_apres_fulfill=' . (string) $kitchenQtyAfterFulfill;
        $lines[] = 'stock_cuisine_apres_runners=' . (string) $kitchenQtyAfterRunners;
        $lines[] = 'mouvements_boisson_item_avant=' . (string) $bevBefore . ' apres_fulfill=' . (string) $bevAfterFulfill . ' apres_runners=' . (string) $bevAfterRunners;
        $lines[] = 'cash_transfers_pour_vente_count=' . ($cashCount < 0 ? 'n/a (table absente)' : (string) $cashCount);
        $lines[] = 'manquant_serveur_detectable=' . ($manquantVisible ? 'oui' : 'non')
            . ($cashTablePresent ? ' (listServerRemittanceCandidates)' : ' (table cash_transfers absente — non vérifiable)');
        $lines[] = 'garde_sandbox=oui (code ' . self::CODE . ')';
        $lines[] = 'aucune_donnee_reelle_hors_allowlist=oui';

        $okDry = ((int) ($dry['candidate_count'] ?? 0) >= 1)
            && ((int) ($dry['created_count'] ?? -1) === 0)
            && $ourRequestIsCandidate;
        $okExec1 = ($saleId > 0) && ($salesLinkedCount === 1) && ((int) ($exec1['created_count'] ?? 0) >= 1);
        $okExec2 = ($salesLinkedCount === 1) && ((int) ($exec2['created_count'] ?? 0) === 0);
        $okStockOnce = ($bevAfterFulfill === 1 && $bevAfterRunners === 1);
        $okKitchenStable = abs($kitchenQtyAfterFulfill - $kitchenQtyAfterRunners) < 0.0001;
        $okCashNone = !$cashTablePresent || $cashCount === 0;
        $okManquant = !$cashTablePresent || $manquantVisible;
        $okActivity = ($saleActivityYmd === $yesterdayYmd);
        $okStatus = ($statusFinal === 'CLOTURE');

        $checks = [
            'demande_boisson' => $serverRequestId > 0,
            'remis_serveur' => true,
            'backdate' => str_starts_with($recvAt, $yesterdayYmd),
            'dry_run' => $okDry,
            'vente_creee' => $okExec1,
            'relance_sans_doublon' => $okExec2,
            'stock_non_reduit' => $okStockOnce && $okKitchenStable,
            'caisse_non_auto' => $okCashNone,
            'manquant_detecte' => $okManquant,
            'donnees_reelles' => true,
        ];

        $lines[] = '';
        $lines[] = '--- Contrôles (résumé) ---';
        $lines[] = 'A_demande_boisson=' . ($checks['demande_boisson'] ? 'OK' : 'NON');
        $lines[] = 'B_cuisine_servie=OK';
        $lines[] = 'C_remis_serveur=OK';
        $lines[] = 'D_backdate_hier_15h=' . ($checks['backdate'] ? 'OK' : 'VERIFIER');
        $lines[] = 'E_dry_run=' . ($okDry ? 'OK' : 'NON');
        $lines[] = 'F_exec_vente=' . ($okExec1 ? 'OK' : 'NON');
        $lines[] = 'G_relance_sans_cloture_supplementaire=' . ($okExec2 ? 'OK' : 'NON');
        $lines[] = 'H_stock_boisson_une_fois=' . ($okStockOnce ? 'OK' : 'NON');
        $lines[] = 'H2_stock_cuisine_stable_apres_runner=' . ($okKitchenStable ? 'OK' : 'NON');
        $lines[] = 'I_caisse_auto=' . ($okCashNone ? 'OK (aucun transfert ou module caisse absent)' : 'NON');
        $lines[] = 'J_manquant_detectable=' . ($okManquant ? 'OK' : 'NON');
        $lines[] = 'K_date_activite_vente=' . ($okActivity ? 'OK' : 'NON');
        $lines[] = 'L_statut_demande_coture=' . ($okStatus ? 'OK' : 'NON');

        $allCore = $okDry && $okExec1 && $okExec2 && $okStockOnce && $okKitchenStable && $okCashNone && $okActivity && $okStatus && $okManquant;

        $metrics = [
            'restaurant_id' => $restaurantId,
            'server_request_id' => $serverRequestId,
            'sale_id' => $saleId,
            'commit_short' => self::deploymentCommitShort(),
            'all_core_ok' => $allCore,
        ];

        return [
            'report_lines' => $lines,
            'exit_code' => $allCore ? 0 : 1,
            'metrics' => $metrics,
            'checks' => $checks,
        ];
    }

    /**
     * @return array{report_lines: list<string>, exit_code: int, metrics: array<string, mixed>, checks: array<string, bool>}
     */
    private static function failureResult(array $lines, string $errorMessage): array
    {
        return [
            'report_lines' => $lines,
            'exit_code' => 2,
            'metrics' => ['error' => $errorMessage, 'commit_short' => self::deploymentCommitShort()],
            'checks' => [
                'demande_boisson' => false,
                'remis_serveur' => false,
                'backdate' => false,
                'dry_run' => false,
                'vente_creee' => false,
                'relance_sans_doublon' => false,
                'stock_non_reduit' => false,
                'caisse_non_auto' => false,
                'manquant_detecte' => false,
                'donnees_reelles' => true,
            ],
        ];
    }

    public static function deploymentCommitShort(): string
    {
        $commit = (string) (
            getenv('RAILWAY_GIT_COMMIT_SHA')
            ?: getenv('RAILWAY_GIT_COMMIT')
            ?: getenv('RENDER_GIT_COMMIT')
            ?: getenv('VERCEL_GIT_COMMIT_SHA')
            ?: getenv('GIT_COMMIT')
            ?: ''
        );

        return $commit !== '' ? substr($commit, 0, 7) : 'unknown';
    }

    private static function assertSandboxEnv(): void
    {
        $allowed = sandbox_allowed_restaurant_codes();
        if (!in_array(self::CODE, $allowed, true)) {
            throw new RuntimeException(
                'SANDBOX_RESTAURANT_CODES ne contient pas "' . self::CODE . '". Valeur: ' . json_encode($allowed, JSON_UNESCAPED_UNICODE)
            );
        }
    }

    /**
     * @param array<string, mixed> $restaurant
     */
    private static function assertRestaurantRow(array $restaurant): int
    {
        $code = strtolower(trim((string) ($restaurant['restaurant_code'] ?? '')));
        if ($code !== self::CODE) {
            throw new RuntimeException('restaurant_code attendu "' . self::CODE . '", obtenu "' . $code . '".');
        }
        if (!is_sandbox_restaurant_code($code)) {
            throw new RuntimeException('Code restaurant refusé par is_sandbox_restaurant_code().');
        }

        return (int) ($restaurant['id'] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadUserActor(int $restaurantId, string $email): array
    {
        $pdo = Container::getInstance()->get('db')->pdo();
        $st = $pdo->prepare(
            'SELECT u.*, r.code AS role_code
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.restaurant_id = :rid AND u.email = :email AND u.status = "active"
             LIMIT 1'
        );
        $st->execute(['rid' => $restaurantId, 'email' => $email]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('Utilisateur introuvable: ' . $email);
        }

        return [
            'id' => (int) $row['id'],
            'full_name' => (string) $row['full_name'],
            'role_code' => (string) $row['role_code'],
            'restaurant_id' => (int) $row['restaurant_id'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function systemActor(): array
    {
        return [
            'id' => null,
            'full_name' => 'CLI sandbox E2E',
            'role_code' => 'system',
            'restaurant_id' => null,
        ];
    }

    private static function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');

        return $value !== '' ? $value : 'sandbox-item';
    }

    private static function bootstrapKitchenInventoryLocked(
        int $restaurantId,
        int $stockItemId,
        float $quantity,
        string $lockedCode,
    ): void {
        if ($lockedCode !== self::CODE || !is_sandbox_restaurant_code($lockedCode)) {
            throw new RuntimeException('Verrou sandbox cuisine invalide.');
        }
        if ($quantity <= 0) {
            throw new RuntimeException('Quantité cuisine positive requise.');
        }
        $pdo = Container::getInstance()->get('db')->pdo();
        $st = $pdo->prepare(
            'INSERT INTO kitchen_inventory (restaurant_id, stock_item_id, quantity_available, created_at, updated_at)
             VALUES (:restaurant_id, :stock_item_id, :quantity_available, NOW(), NOW())
             ON DUPLICATE KEY UPDATE quantity_available = quantity_available + VALUES(quantity_available), updated_at = NOW()'
        );
        $st->execute([
            'restaurant_id' => $restaurantId,
            'stock_item_id' => $stockItemId,
            'quantity_available' => $quantity,
        ]);
    }

    private static function kitchenQty(int $restaurantId, int $stockItemId): float
    {
        $pdo = Container::getInstance()->get('db')->pdo();
        $st = $pdo->prepare(
            'SELECT quantity_available FROM kitchen_inventory
             WHERE restaurant_id = :rid AND stock_item_id = :sid LIMIT 1'
        );
        $st->execute(['rid' => $restaurantId, 'sid' => $stockItemId]);
        $v = $st->fetchColumn();

        return $v === false ? 0.0 : (float) $v;
    }

    private static function beverageMovementCount(int $restaurantId, int $serverRequestItemId): int
    {
        $pdo = Container::getInstance()->get('db')->pdo();
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM stock_movements
             WHERE restaurant_id = :rid
               AND reference_type = "server_request_beverage"
               AND reference_id = :ref'
        );
        $st->execute(['rid' => $restaurantId, 'ref' => $serverRequestItemId]);

        return (int) $st->fetchColumn();
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $table = trim($table);
        if ($table === '' || !preg_match('/^[a-z0-9_]+$/', strtolower($table))) {
            return false;
        }
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :t'
        );
        $st->execute(['t' => $table]);

        return (int) $st->fetchColumn() > 0;
    }

    private static function cashTransfersForSale(int $restaurantId, int $saleId): int
    {
        $pdo = Container::getInstance()->get('db')->pdo();
        if (!self::tableExists($pdo, 'cash_transfers')) {
            return -1;
        }
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM cash_transfers
             WHERE restaurant_id = :rid AND source_type = "sale" AND source_id = :sid'
        );
        $st->execute(['rid' => $restaurantId, 'sid' => $saleId]);

        return (int) $st->fetchColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function findSaleForRequest(int $restaurantId, int $serverRequestId): ?array
    {
        $pdo = Container::getInstance()->get('db')->pdo();
        $st = $pdo->prepare(
            'SELECT s.*,
                    COALESCE(sr.received_at, sr.supplied_at, sr.ready_at, s.validated_at, s.created_at) AS sale_activity_at
             FROM sales s
             LEFT JOIN server_requests sr
               ON s.origin_type = "server_request" AND sr.id = s.origin_id AND sr.restaurant_id = s.restaurant_id
             WHERE s.restaurant_id = :rid AND s.origin_type = "server_request" AND s.origin_id = :oid
             ORDER BY s.id DESC LIMIT 1'
        );
        $st->execute(['rid' => $restaurantId, 'oid' => $serverRequestId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @return list<string>
     */
    private static function ensureSandboxAccounts(int $restaurantId, string $code, array $actor): array
    {
        $templates = [
            ['full_name' => 'Owner Sandbox', 'role_code' => 'owner', 'email' => 'owner-' . $code . '@badiboss.test'],
            ['full_name' => 'Server Sandbox', 'role_code' => 'cashier_server', 'email' => 'server-' . $code . '@badiboss.test'],
            ['full_name' => 'Kitchen Sandbox', 'role_code' => 'kitchen', 'email' => 'kitchen-' . $code . '@badiboss.test'],
        ];
        $pdo = Container::getInstance()->get('db')->pdo();
        $userAdmin = Container::getInstance()->get('userAdmin');
        $out = [];
        foreach ($templates as $tpl) {
            $exists = $pdo->prepare('SELECT id FROM users WHERE restaurant_id = :rid AND email = :email LIMIT 1');
            $exists->execute(['rid' => $restaurantId, 'email' => $tpl['email']]);
            if ((int) ($exists->fetchColumn() ?: 0) > 0) {
                $out[] = $tpl['email'] . ':exists';
                continue;
            }
            $roleStatement = $pdo->prepare(
                'SELECT id FROM roles
                 WHERE code = :code
                   AND status = "active"
                   AND (scope = "system" OR (scope = "tenant" AND restaurant_id = :rid))
                 ORDER BY scope ASC
                 LIMIT 1'
            );
            $roleStatement->execute(['code' => $tpl['role_code'], 'rid' => $restaurantId]);
            $roleId = (int) ($roleStatement->fetchColumn() ?: 0);
            if ($roleId <= 0) {
                throw new RuntimeException('Role introuvable pour sandbox: ' . $tpl['role_code']);
            }
            $userAdmin->createUser([
                'restaurant_id' => $restaurantId,
                'role_id' => $roleId,
                'full_name' => $tpl['full_name'],
                'email' => $tpl['email'],
                'phone' => '+243000000000',
                'password' => 'password',
                'status' => 'active',
            ], $actor);
            $out[] = $tpl['email'] . ':created';
        }

        return $out;
    }
}
