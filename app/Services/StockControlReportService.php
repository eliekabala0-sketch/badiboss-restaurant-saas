<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Container;
use App\Core\Database;
use DateTimeImmutable;
use PDO;

/**
 * Rapport contrôle stock : magasin + cuisine, flux période, plats préparés restants,
 * saisie contrôle physique (audit uniquement, sans correction automatique).
 */
final class StockControlReportService
{
    private const EPS = 0.0001;

    public function __construct(
        private readonly Database $database,
        private readonly ReportService $reportService,
    ) {
    }

    public function ensurePhysicalChecksSchema(): void
    {
        $this->database->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS stock_physical_checks (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                restaurant_id BIGINT UNSIGNED NOT NULL,
                stock_item_id BIGINT UNSIGNED NOT NULL,
                period_start_at DATETIME NULL,
                period_end_at DATETIME NULL,
                expected_store DECIMAL(16,4) NOT NULL DEFAULT 0,
                expected_kitchen DECIMAL(16,4) NOT NULL DEFAULT 0,
                found_store DECIMAL(16,4) NOT NULL DEFAULT 0,
                found_kitchen DECIMAL(16,4) NOT NULL DEFAULT 0,
                gap_store DECIMAL(16,4) NOT NULL DEFAULT 0,
                gap_kitchen DECIMAL(16,4) NOT NULL DEFAULT 0,
                gap_total DECIMAL(16,4) NOT NULL DEFAULT 0,
                gap_motif TEXT NULL,
                note TEXT NULL,
                created_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_spc_restaurant_created (restaurant_id, created_at),
                KEY idx_spc_item (restaurant_id, stock_item_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function buildBundle(int $restaurantId, string $dateYmd, string $period, ?array $onlyStockItemIds = null): array
    {
        $this->ensurePhysicalChecksSchema();
        $tz = $this->reportService->timezoneForRestaurantReports($restaurantId);
        $base = $this->reportService->normalizeDatePublic($dateYmd, $tz);
        [$startAt, $endAt, $periodLabel] = $this->reportService->periodBoundsPublic($base, $period, $tz);

        $allowedItemIds = $this->normalizeOnlyStockItemIds($onlyStockItemIds);
        $items = $this->loadActiveStockItems($restaurantId);
        if ($allowedItemIds !== null) {
            $items = array_values(array_filter(
                $items,
                static fn (array $row): bool => isset($allowedItemIds[(int) ($row['id'] ?? 0)])
            ));
        }
        $aggByItem = $this->movementAggregatesForPeriod($restaurantId, $startAt, $endAt);
        $futureAggByItem = $this->movementAggregatesAfterPeriod($restaurantId, $endAt, $tz);
        $salesSignalsByItem = $this->salesStockSignalsForPeriod($restaurantId, $startAt, $endAt);
        $physicalByItem = $this->latestPhysicalChecksForPeriod($restaurantId, $startAt, $endAt);
        $articles = [];
        foreach ($items as $si) {
            $sid = (int) $si['id'];
            $agg = $aggByItem[$sid] ?? [
                'entrees' => 0.0,
                'sortie_cuisine' => 0.0,
                'sortie_autre' => 0.0,
                'pertes' => 0.0,
                'retours' => 0.0,
                'corrections_magasin' => 0.0,
                'conso_cuisine' => 0.0,
            ];
            $futureAgg = $futureAggByItem[$sid] ?? [
                'entrees' => 0.0,
                'sortie_cuisine' => 0.0,
                'sortie_autre' => 0.0,
                'pertes' => 0.0,
                'retours' => 0.0,
                'corrections_magasin' => 0.0,
                'conso_cuisine' => 0.0,
            ];
            $salesSignal = $salesSignalsByItem[$sid] ?? [
                'sales_linked_consumption_qty' => 0.0,
                'sales_linked_value' => 0.0,
                'sales_linked_lines' => 0,
            ];
            $physical = $physicalByItem[$sid] ?? null;
            $qtyStore = (float) ($si['quantity_in_stock'] ?? 0);
            $qtyKitchen = (float) ($si['kitchen_qty'] ?? 0);
            $unitCost = (float) ($si['estimated_unit_cost'] ?? 0);

            $deltaMagasin = (float) $agg['entrees'] + (float) $agg['retours'] + (float) $agg['corrections_magasin']
                - (float) $agg['sortie_cuisine'] - (float) $agg['sortie_autre'] - (float) $agg['pertes'];
            $futureDeltaMagasin = (float) $futureAgg['entrees'] + (float) $futureAgg['retours'] + (float) $futureAgg['corrections_magasin']
                - (float) $futureAgg['sortie_cuisine'] - (float) $futureAgg['sortie_autre'] - (float) $futureAgg['pertes'];
            $expectedStore = round($qtyStore - $futureDeltaMagasin, 4);
            $openingStore = $expectedStore - $deltaMagasin;

            $deltaKitchen = (float) $agg['sortie_cuisine'] - (float) $agg['conso_cuisine'];
            $futureDeltaKitchen = (float) $futureAgg['sortie_cuisine'] - (float) $futureAgg['conso_cuisine'];
            $expectedKitchen = round($qtyKitchen - $futureDeltaKitchen, 4);
            $openingKitchen = $expectedKitchen - $deltaKitchen;

            $theoreticalStoreEnd = $openingStore + $deltaMagasin;
            $storeCoherent = abs($theoreticalStoreEnd - $expectedStore) < self::EPS;
            $expectedTotal = round($expectedStore + $expectedKitchen, 4);
            $hasPhysical = is_array($physical);
            $foundStore = $hasPhysical ? (float) ($physical['found_store'] ?? 0) : $qtyStore;
            $foundKitchen = $hasPhysical ? (float) ($physical['found_kitchen'] ?? 0) : $qtyKitchen;
            $foundTotal = round($foundStore + $foundKitchen, 4);
            $gapQty = round($foundTotal - $expectedTotal, 4);
            $gapValue = round($gapQty * $unitCost, 2);
            $salesLinkedQty = (float) ($salesSignal['sales_linked_consumption_qty'] ?? 0);
            $stockStatus = $this->stockControlStatus($gapQty, $expectedTotal, $hasPhysical, $salesLinkedQty, (float) $agg['conso_cuisine']);

            $macro = stock_control_macro_category((string) ($si['category_label'] ?? ''));

            $articles[] = [
                'id' => $sid,
                'name' => (string) ($si['name'] ?? ''),
                'category_label' => trim((string) ($si['category_label'] ?? '')),
                'macro_category' => $macro,
                'unit_name' => (string) ($si['unit_name'] ?? ''),
                'estimated_unit_cost' => round($unitCost, 4),
                'qty_store_now' => round($qtyStore, 4),
                'qty_kitchen_now' => round($qtyKitchen, 4),
                'qty_total_available' => round($qtyStore + $qtyKitchen, 4),
                'opening_store' => round($openingStore, 4),
                'opening_kitchen' => round($openingKitchen, 4),
                'opening_total' => round($openingStore + $openingKitchen, 4),
                'period_entrees' => round((float) $agg['entrees'], 4),
                'period_sortie_cuisine' => round((float) $agg['sortie_cuisine'], 4),
                'period_sortie_autre' => round((float) $agg['sortie_autre'], 4),
                'period_pertes' => round((float) $agg['pertes'], 4),
                'period_retours' => round((float) $agg['retours'], 4),
                'period_corrections_magasin' => round((float) $agg['corrections_magasin'], 4),
                'period_conso_cuisine' => round((float) $agg['conso_cuisine'], 4),
                'sales_linked_consumption_qty' => round($salesLinkedQty, 4),
                'sales_linked_value' => round((float) ($salesSignal['sales_linked_value'] ?? 0), 2),
                'sales_linked_lines' => (int) ($salesSignal['sales_linked_lines'] ?? 0),
                'expected_store_end' => $expectedStore,
                'expected_kitchen_end' => $expectedKitchen,
                'expected_total_end' => $expectedTotal,
                'actual_store_found' => round($foundStore, 4),
                'actual_kitchen_found' => round($foundKitchen, 4),
                'actual_total_found' => $foundTotal,
                'physical_check_found' => $hasPhysical,
                'physical_check_at' => $hasPhysical ? (string) ($physical['created_at'] ?? '') : null,
                'gap_qty_total' => $gapQty,
                'gap_value_total' => $gapValue,
                'stock_status_key' => $stockStatus['key'],
                'stock_status_label' => $stockStatus['label'],
                'stock_status_class' => $stockStatus['class'],
                'probable_breakpoint' => $this->probableBreakpoint($gapQty, $hasPhysical, $salesLinkedQty, (float) $agg['sortie_cuisine'], (float) $agg['pertes'], (float) $agg['retours'], (float) $agg['corrections_magasin']),
                'store_coherent' => $storeCoherent,
            ];
        }

        usort($articles, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        $categories = $this->rollupCategories($articles);
        $summary = $this->expectedStockSummary($articles);

        return [
            'period_label' => $periodLabel,
            'period_start_at' => $startAt->format('Y-m-d H:i:s'),
            'period_end_at' => $endAt->format('Y-m-d H:i:s'),
            'date_ymd' => $dateYmd,
            'period_key' => $period,
            'formula_note' => 'Stock attendu = stock initial periode + entrees validees + retours valides + ajustements valides - pertes validees - sorties validees - consommations validees liees cuisine/ventes. Les ventes validees et les produits servis au serveur alimentent le signal ventes liees; le stock reel vient du dernier comptage physique de la periode quand il existe, sinon du stock systeme courant.',
            'summary' => $summary,
            'articles' => $articles,
            'categories' => $categories,
            'prepared_plates' => $this->preparedPlatesKitchenRemaining($restaurantId),
            'checks_recent' => $this->listRecentPhysicalChecks($restaurantId, 40),
        ];
    }

    /**
     * @param array<string, mixed>|null $actor
     */
    public function recordPhysicalChecksFromRequest(int $restaurantId, array $post, ?array $actor): void
    {
        $this->ensurePhysicalChecksSchema();
        $pdo = $this->database->pdo();

        $ids = $post['pc_stock_item_id'] ?? [];
        $foundStoreRaw = $post['pc_found_store'] ?? [];
        $foundKitchenRaw = $post['pc_found_kitchen'] ?? [];
        $motifs = $post['pc_gap_motif'] ?? [];
        $globalNote = trim((string) ($post['pc_note'] ?? ''));

        if (!is_array($ids)) {
            throw new \RuntimeException('Saisie controle invalide.');
        }

        $periodStart = trim((string) ($post['pc_period_start'] ?? ''));
        $periodEnd = trim((string) ($post['pc_period_end'] ?? ''));

        $bundle = $this->buildBundle(
            $restaurantId,
            trim((string) ($post['sc_date'] ?? $this->reportService->todayForRestaurant($restaurantId))),
            trim((string) ($post['sc_period'] ?? 'daily')),
        );
        $expectedById = [];
        foreach (($bundle['articles'] ?? []) as $row) {
            $expectedById[(int) ($row['id'] ?? 0)] = $row;
        }

        $linesToInsert = [];
        foreach ($ids as $i => $rawId) {
            $stockItemId = (int) $rawId;
            if ($stockItemId <= 0) {
                continue;
            }
            $fs = isset($foundStoreRaw[$i]) ? trim((string) $foundStoreRaw[$i]) : '';
            $fk = isset($foundKitchenRaw[$i]) ? trim((string) $foundKitchenRaw[$i]) : '';
            if ($fs === '' && $fk === '') {
                continue;
            }

            $foundS = $fs === '' ? null : $this->parseDecimal($fs);
            $foundK = $fk === '' ? null : $this->parseDecimal($fk);
            if ($foundS === null && $foundK === null) {
                continue;
            }
            if (($foundS !== null && $foundS < 0) || ($foundK !== null && $foundK < 0)) {
                throw new \RuntimeException('Les quantités trouvées ne peuvent pas être négatives.');
            }

            $exp = $expectedById[$stockItemId] ?? null;
            if ($exp === null) {
                throw new \RuntimeException('Article hors périmètre restaurant.');
            }
            $eStore = (float) ($exp['expected_store_end'] ?? ($exp['qty_store_now'] ?? 0));
            $eKitchen = (float) ($exp['expected_kitchen_end'] ?? ($exp['qty_kitchen_now'] ?? 0));

            $useStore = $foundS ?? $eStore;
            $useKitchen = $foundK ?? $eKitchen;

            $gapS = round($useStore - $eStore, 4);
            $gapK = round($useKitchen - $eKitchen, 4);
            $gapT = round($gapS + $gapK, 4);

            $motif = isset($motifs[$i]) ? trim((string) $motifs[$i]) : '';
            if ((abs($gapS) > self::EPS || abs($gapK) > self::EPS) && $motif === '') {
                throw new \RuntimeException(
                    'Motif obligatoire pour tout écart sur « ' . (string) ($exp['name'] ?? ('#' . $stockItemId)) . ' ».',
                );
            }

            $linesToInsert[] = [
                'stock_item_id' => $stockItemId,
                'expected_store' => $eStore,
                'expected_kitchen' => $eKitchen,
                'found_store' => $useStore,
                'found_kitchen' => $useKitchen,
                'gap_store' => $gapS,
                'gap_kitchen' => $gapK,
                'gap_total' => $gapT,
                'gap_motif' => $motif !== '' ? $motif : null,
                'note' => $globalNote !== '' ? $globalNote : null,
                'name' => (string) ($exp['name'] ?? ''),
            ];
        }

        if ($linesToInsert === []) {
            throw new \RuntimeException('Renseignez au moins une quantité trouvée (magasin ou cuisine).');
        }

        $uid = is_array($actor) ? (int) ($actor['id'] ?? 0) : 0;
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO stock_physical_checks
                (restaurant_id, stock_item_id, period_start_at, period_end_at,
                 expected_store, expected_kitchen, found_store, found_kitchen,
                 gap_store, gap_kitchen, gap_total, gap_motif, note, created_by, created_at)
                 VALUES
                (:rid, :sid, :ps, :pe, :es, :ek, :fs, :fk, :gs, :gk, :gt, :gm, :nt, :cb, NOW())'
            );
            foreach ($linesToInsert as $ln) {
                $stmt->execute([
                    'rid' => $restaurantId,
                    'sid' => (int) $ln['stock_item_id'],
                    'ps' => $periodStart !== '' ? $periodStart : ($bundle['period_start_at'] ?? null),
                    'pe' => $periodEnd !== '' ? $periodEnd : ($bundle['period_end_at'] ?? null),
                    'es' => $ln['expected_store'],
                    'ek' => $ln['expected_kitchen'],
                    'fs' => $ln['found_store'],
                    'fk' => $ln['found_kitchen'],
                    'gs' => $ln['gap_store'],
                    'gk' => $ln['gap_kitchen'],
                    'gt' => $ln['gap_total'],
                    'gm' => $ln['gap_motif'],
                    'nt' => $ln['note'],
                    'cb' => $uid > 0 ? $uid : null,
                ]);

                Container::getInstance()->get('audit')->log([
                    'restaurant_id' => $restaurantId,
                    'user_id' => $uid > 0 ? $uid : null,
                    'actor_name' => is_array($actor) ? ($actor['full_name'] ?? null) : null,
                    'actor_role_code' => is_array($actor) ? ($actor['role_code'] ?? null) : null,
                    'module_name' => 'stock_control',
                    'action_name' => 'physical_check_recorded',
                    'entity_type' => 'stock_items',
                    'entity_id' => (string) (int) $ln['stock_item_id'],
                    'new_values' => [
                        'expected_store' => $ln['expected_store'],
                        'expected_kitchen' => $ln['expected_kitchen'],
                        'found_store' => $ln['found_store'],
                        'found_kitchen' => $ln['found_kitchen'],
                        'gap_total' => $ln['gap_total'],
                    ],
                    'justification' => ($ln['gap_motif'] ?? '') !== '' ? (string) $ln['gap_motif'] : ($globalNote !== '' ? $globalNote : 'Contrôle physique stock'),
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadActiveStockItems(int $restaurantId): array
    {
        $joinKi = $this->tableExists('kitchen_inventory')
            ? 'LEFT JOIN kitchen_inventory ki ON ki.restaurant_id = si.restaurant_id AND ki.stock_item_id = si.id'
            : '';
        $selectKitchen = $this->tableExists('kitchen_inventory')
            ? 'COALESCE(ki.quantity_available, 0) AS kitchen_qty'
            : '0 AS kitchen_qty';
        $sql = 'SELECT si.*, ' . $selectKitchen . '
                FROM stock_items si
                ' . $joinKi . '
                WHERE si.restaurant_id = :rid';
        if ($this->tableColumnExists('stock_items', 'archived_at')) {
            $sql .= ' AND si.archived_at IS NULL';
        }
        $sql .= ' ORDER BY si.name ASC';
        $st = $this->database->pdo()->prepare($sql);
        $st->execute(['rid' => $restaurantId]);

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<int, array<string, float>>
     */
    private function movementAggregatesForPeriod(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt): array
    {
        $st = $this->database->pdo()->prepare(
            'SELECT sm.stock_item_id AS sid,
                    SUM(CASE WHEN sm.movement_type = "ENTREE" AND sm.status = "VALIDE" THEN sm.quantity ELSE 0 END) AS entrees,
                    SUM(CASE WHEN sm.movement_type = "SORTIE_CUISINE" AND sm.status = "VALIDE" THEN sm.quantity ELSE 0 END) AS sortie_cuisine,
                    SUM(CASE WHEN sm.movement_type = "SORTIE" AND sm.status = "VALIDE" THEN sm.quantity ELSE 0 END) AS sortie_autre,
                    SUM(CASE WHEN sm.movement_type = "PERTE" AND sm.status = "VALIDE" THEN sm.quantity ELSE 0 END) AS pertes,
                    SUM(CASE WHEN sm.movement_type = "RETOUR_STOCK" AND sm.status = "VALIDE" THEN sm.quantity ELSE 0 END) AS retours,
                    SUM(CASE WHEN sm.movement_type = "CORRECTION_INVENTAIRE" AND sm.status = "VALIDE" THEN sm.quantity ELSE 0 END) AS corrections_magasin,
                    SUM(CASE WHEN sm.movement_type = "CONSOMMATION_CUISINE" AND sm.status = "VALIDE" THEN sm.quantity ELSE 0 END) AS conso_cuisine
             FROM stock_movements sm
             WHERE sm.restaurant_id = :rid
               AND COALESCE(sm.validated_at, sm.created_at) >= :start_at
               AND COALESCE(sm.validated_at, sm.created_at) < :end_at
             GROUP BY sm.stock_item_id'
        );
        $st->execute([
            'rid' => $restaurantId,
            'start_at' => $startAt->format('Y-m-d H:i:s'),
            'end_at' => $endAt->format('Y-m-d H:i:s'),
        ]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sid = (int) ($row['sid'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $out[$sid] = [
                'entrees' => (float) ($row['entrees'] ?? 0),
                'sortie_cuisine' => (float) ($row['sortie_cuisine'] ?? 0),
                'sortie_autre' => (float) ($row['sortie_autre'] ?? 0),
                'pertes' => (float) ($row['pertes'] ?? 0),
                'retours' => (float) ($row['retours'] ?? 0),
                'corrections_magasin' => (float) ($row['corrections_magasin'] ?? 0),
                'conso_cuisine' => (float) ($row['conso_cuisine'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array<string, float>>
     */
    private function movementAggregatesAfterPeriod(int $restaurantId, DateTimeImmutable $periodEndAt, \DateTimeZone $tz): array
    {
        $now = new DateTimeImmutable('now', $tz);
        if ($periodEndAt >= $now) {
            return [];
        }

        return $this->movementAggregatesForPeriod($restaurantId, $periodEndAt, $now);
    }

    /**
     * Plats préparés encore disponibles en cuisine (non vendus — quantity_remaining).
     *
     * @return list<array<string, mixed>>
     */
    private function preparedPlatesKitchenRemaining(int $restaurantId): array
    {
        if (!$this->tableExists('kitchen_production')) {
            return [];
        }
        $st = $this->database->pdo()->prepare(
            'SELECT kp.id,
                    COALESCE(mi.name, kp.dish_type, CONCAT("Production #", kp.id)) AS dish_label,
                    kp.quantity_produced,
                    kp.quantity_remaining,
                    kp.created_at
             FROM kitchen_production kp
             LEFT JOIN menu_items mi ON mi.id = kp.menu_item_id
             WHERE kp.restaurant_id = :rid AND kp.quantity_remaining > 0.0001
             ORDER BY kp.created_at DESC
             LIMIT 80'
        );
        $st->execute(['rid' => $restaurantId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['quantity_remaining'] = round((float) ($r['quantity_remaining'] ?? 0), 4);
            $r['quantity_produced'] = round((float) ($r['quantity_produced'] ?? 0), 4);
        }
        unset($r);

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listRecentPhysicalChecks(int $restaurantId, int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $st = $this->database->pdo()->prepare(
            'SELECT spc.*, si.name AS stock_item_name
             FROM stock_physical_checks spc
             INNER JOIN stock_items si ON si.id = spc.stock_item_id AND si.restaurant_id = spc.restaurant_id
             WHERE spc.restaurant_id = :rid
             ORDER BY spc.id DESC
             LIMIT ' . $limit
        );
        $st->execute(['rid' => $restaurantId]);

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param list<array<string, mixed>> $articles
     *
     * @return list<array<string, mixed>>
     */
    private function rollupCategories(array $articles): array
    {
        $map = [];
        foreach ($articles as $a) {
            $cat = (string) ($a['macro_category'] ?? 'Autres');
            $map[$cat] ??= [
                'macro_category' => $cat,
                'opening_store' => 0.0,
                'period_entrees' => 0.0,
                'period_sortie_cuisine' => 0.0,
                'period_sortie_autre' => 0.0,
                'period_conso_cuisine' => 0.0,
                'period_pertes' => 0.0,
                'qty_store_now' => 0.0,
                'qty_kitchen_now' => 0.0,
                'qty_total_available' => 0.0,
                'expected_total_end' => 0.0,
                'actual_total_found' => 0.0,
                'gap_qty_total' => 0.0,
                'gap_value_total' => 0.0,
                'sales_linked_consumption_qty' => 0.0,
            ];
            $map[$cat]['opening_store'] += (float) ($a['opening_store'] ?? 0);
            $map[$cat]['period_entrees'] += (float) ($a['period_entrees'] ?? 0);
            $map[$cat]['period_sortie_cuisine'] += (float) ($a['period_sortie_cuisine'] ?? 0);
            $map[$cat]['period_sortie_autre'] += (float) ($a['period_sortie_autre'] ?? 0);
            $map[$cat]['period_conso_cuisine'] += (float) ($a['period_conso_cuisine'] ?? 0);
            $map[$cat]['period_pertes'] += (float) ($a['period_pertes'] ?? 0);
            $map[$cat]['qty_store_now'] += (float) ($a['qty_store_now'] ?? 0);
            $map[$cat]['qty_kitchen_now'] += (float) ($a['qty_kitchen_now'] ?? 0);
            $map[$cat]['qty_total_available'] += (float) ($a['qty_total_available'] ?? 0);
            $map[$cat]['expected_total_end'] += (float) ($a['expected_total_end'] ?? 0);
            $map[$cat]['actual_total_found'] += (float) ($a['actual_total_found'] ?? 0);
            $map[$cat]['gap_qty_total'] += (float) ($a['gap_qty_total'] ?? 0);
            $map[$cat]['gap_value_total'] += (float) ($a['gap_value_total'] ?? 0);
            $map[$cat]['sales_linked_consumption_qty'] += (float) ($a['sales_linked_consumption_qty'] ?? 0);
        }
        foreach ($map as &$row) {
            foreach (['opening_store', 'period_entrees', 'period_sortie_cuisine', 'period_sortie_autre', 'period_conso_cuisine', 'period_pertes', 'qty_store_now', 'qty_kitchen_now', 'qty_total_available', 'expected_total_end', 'actual_total_found', 'gap_qty_total', 'sales_linked_consumption_qty'] as $k) {
                $row[$k] = round((float) $row[$k], 4);
            }
            $row['gap_value_total'] = round((float) $row['gap_value_total'], 2);
        }
        unset($row);
        $list = array_values($map);
        usort($list, static fn (array $x, array $y): int => strcmp((string) $x['macro_category'], (string) $y['macro_category']));

        return $list;
    }

    /**
     * @param array<int, mixed>|null $onlyStockItemIds
     * @return array<int, true>|null
     */
    private function normalizeOnlyStockItemIds(?array $onlyStockItemIds): ?array
    {
        if (!is_array($onlyStockItemIds)) {
            return null;
        }
        $out = [];
        foreach ($onlyStockItemIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $out[$id] = true;
            }
        }

        return $out === [] ? [] : $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function latestPhysicalChecksForPeriod(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt): array
    {
        $st = $this->database->pdo()->prepare(
            'SELECT spc.*
             FROM stock_physical_checks spc
             INNER JOIN (
                SELECT stock_item_id, MAX(id) AS max_id
                FROM stock_physical_checks
                WHERE restaurant_id = :rid
                  AND created_at >= :start_at AND created_at < :end_at
                GROUP BY stock_item_id
             ) latest ON latest.max_id = spc.id
             WHERE spc.restaurant_id = :rid2'
        );
        $st->execute([
            'rid' => $restaurantId,
            'rid2' => $restaurantId,
            'start_at' => $startAt->format('Y-m-d H:i:s'),
            'end_at' => $endAt->format('Y-m-d H:i:s'),
        ]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sid = (int) ($row['stock_item_id'] ?? 0);
            if ($sid > 0) {
                $out[$sid] = $row;
            }
        }

        return $out;
    }

    /**
     * Signal lecture seule : quantite vendue qui a un lien traçable avec un article stock.
     *
     * @return array<int, array{sales_linked_consumption_qty: float, sales_linked_value: float, sales_linked_lines: int}>
     */
    private function salesStockSignalsForPeriod(int $restaurantId, DateTimeImmutable $startAt, DateTimeImmutable $endAt): array
    {
        $out = [];
        if (!$this->tableExists('sales') || !$this->tableExists('sale_items')) {
            return $out;
        }

        if ($this->tableExists('server_request_items') && $this->tableExists('server_requests')) {
            $sql = 'SELECT sm.stock_item_id AS sid,
                           COALESCE(SUM(LEAST(GREATEST(COALESCE(sri.sold_quantity, 0), 0), sm.quantity)), 0) AS qty,
                           COALESCE(SUM(LEAST(GREATEST(COALESCE(sri.sold_quantity, 0), 0), sm.quantity) * COALESCE(sri.unit_price, 0)), 0) AS val,
                           COUNT(DISTINCT sri.id) AS lines_count
                    FROM server_request_items sri
                    INNER JOIN server_requests sr ON sr.id = COALESCE(sri.request_id, sri.server_request_id)
                    INNER JOIN stock_movements sm ON sm.restaurant_id = sr.restaurant_id
                        AND sm.reference_type = "server_request_beverage"
                        AND sm.reference_id = sri.id
                        AND sm.status = "VALIDE"
                    WHERE sr.restaurant_id = :rid
                      AND COALESCE(sr.received_at, sr.supplied_at, sr.closed_at, sr.updated_at, sr.created_at) >= :start_at
                      AND COALESCE(sr.received_at, sr.supplied_at, sr.closed_at, sr.updated_at, sr.created_at) < :end_at
                      AND COALESCE(sri.sold_quantity, 0) > 0
                    GROUP BY sm.stock_item_id';
            try {
                $st = $this->database->pdo()->prepare($sql);
                $st->execute([
                    'rid' => $restaurantId,
                    'start_at' => $startAt->format('Y-m-d H:i:s'),
                    'end_at' => $endAt->format('Y-m-d H:i:s'),
                ]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $this->mergeSalesSignal($out, (int) ($row['sid'] ?? 0), (float) ($row['qty'] ?? 0), (float) ($row['val'] ?? 0), (int) ($row['lines_count'] ?? 0));
                }
            } catch (\Throwable) {
            }
        }

        if ($this->tableExists('kitchen_production') && $this->tableExists('kitchen_production_materials')) {
            $sql = 'SELECT kpm.stock_item_id AS sid,
                           COALESCE(SUM(CASE WHEN kp.quantity_produced > 0 THEN (kpm.quantity_used / kp.quantity_produced) * si.quantity ELSE 0 END), 0) AS qty,
                           COALESCE(SUM(si.quantity * si.unit_price), 0) AS val,
                           COUNT(DISTINCT si.id) AS lines_count
                    FROM sale_items si
                    INNER JOIN sales s ON s.id = si.sale_id
                    ' . sql_sale_activity_left_join_server_request('s', 'sr') . '
                    INNER JOIN kitchen_production kp ON kp.id = si.kitchen_production_id AND kp.restaurant_id = s.restaurant_id
                    INNER JOIN kitchen_production_materials kpm ON kpm.kitchen_production_id = kp.id AND kpm.restaurant_id = s.restaurant_id
                    WHERE s.restaurant_id = :rid
                      AND s.status = "VALIDE"
                      AND si.status = "SERVI"
                      AND ' . sql_sale_activity_datetime_expr('s', 'sr') . ' >= :start_at
                      AND ' . sql_sale_activity_datetime_expr('s', 'sr') . ' < :end_at
                    GROUP BY kpm.stock_item_id';
            try {
                $st = $this->database->pdo()->prepare($sql);
                $st->execute([
                    'rid' => $restaurantId,
                    'start_at' => $startAt->format('Y-m-d H:i:s'),
                    'end_at' => $endAt->format('Y-m-d H:i:s'),
                ]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $this->mergeSalesSignal($out, (int) ($row['sid'] ?? 0), (float) ($row['qty'] ?? 0), (float) ($row['val'] ?? 0), (int) ($row['lines_count'] ?? 0));
                }
            } catch (\Throwable) {
            }
        }

        return $out;
    }

    /**
     * @param array<int, array{sales_linked_consumption_qty: float, sales_linked_value: float, sales_linked_lines: int}> $out
     */
    private function mergeSalesSignal(array &$out, int $stockItemId, float $quantity, float $value, int $lines): void
    {
        if ($stockItemId <= 0) {
            return;
        }
        $out[$stockItemId] ??= [
            'sales_linked_consumption_qty' => 0.0,
            'sales_linked_value' => 0.0,
            'sales_linked_lines' => 0,
        ];
        $out[$stockItemId]['sales_linked_consumption_qty'] += $quantity;
        $out[$stockItemId]['sales_linked_value'] += $value;
        $out[$stockItemId]['sales_linked_lines'] += $lines;
    }

    /**
     * @return array{key:string,label:string,class:string}
     */
    private function stockControlStatus(float $gapQty, float $expectedQty, bool $hasPhysicalCheck, float $salesLinkedQty, float $consumedQty): array
    {
        if (!$hasPhysicalCheck) {
            return ['key' => 'a_verifier', 'label' => 'A verifier', 'class' => 'badge-neutral'];
        }
        $lightThreshold = max(0.5, abs($expectedQty) * 0.02);
        if (abs($gapQty) <= self::EPS) {
            return ['key' => 'conforme', 'label' => 'Conforme', 'class' => 'badge-good'];
        }
        if (abs($gapQty) <= $lightThreshold) {
            return ['key' => 'ecart_leger', 'label' => 'Ecart leger', 'class' => 'badge-progress'];
        }
        if ($gapQty < 0) {
            return ['key' => 'manquant', 'label' => 'Manquant', 'class' => 'badge-bad'];
        }

        return ['key' => 'surplus', 'label' => 'Surplus', 'class' => 'badge-neutral'];
    }

    private function probableBreakpoint(float $gapQty, bool $hasPhysicalCheck, float $salesLinkedQty, float $sortieCuisine, float $pertes, float $retours, float $adjustments): string
    {
        if (!$hasPhysicalCheck) {
            return 'Comptage physique attendu';
        }
        if (abs($gapQty) <= self::EPS) {
            return 'Aucun ecart';
        }
        if ($gapQty < 0) {
            if ($salesLinkedQty > self::EPS) {
                return 'Vente / retour serveur a verifier';
            }
            if ($sortieCuisine > self::EPS) {
                return 'Transfert cuisine ou reception';
            }
            if ($pertes > self::EPS) {
                return 'Pertes / casse';
            }

            return 'Manquant potentiel';
        }
        if ($retours > self::EPS || $adjustments > self::EPS) {
            return 'Retour ou ajustement a rapprocher';
        }

        return 'Entree ou comptage en surplus';
    }

    /**
     * @param list<array<string, mixed>> $articles
     * @return array<string, mixed>
     */
    private function expectedStockSummary(array $articles): array
    {
        $summary = [
            'expected_total' => 0.0,
            'actual_total' => 0.0,
            'gap_qty_total' => 0.0,
            'gap_value_total' => 0.0,
            'sales_linked_consumption_qty' => 0.0,
            'with_physical_check' => 0,
            'conforme' => 0,
            'ecart_leger' => 0,
            'manquant' => 0,
            'surplus' => 0,
            'a_verifier' => 0,
        ];
        foreach ($articles as $row) {
            $summary['expected_total'] += (float) ($row['expected_total_end'] ?? 0);
            $summary['actual_total'] += (float) ($row['actual_total_found'] ?? 0);
            $summary['gap_qty_total'] += (float) ($row['gap_qty_total'] ?? 0);
            $summary['gap_value_total'] += (float) ($row['gap_value_total'] ?? 0);
            $summary['sales_linked_consumption_qty'] += (float) ($row['sales_linked_consumption_qty'] ?? 0);
            if (!empty($row['physical_check_found'])) {
                $summary['with_physical_check']++;
            }
            $key = (string) ($row['stock_status_key'] ?? 'a_verifier');
            if (array_key_exists($key, $summary)) {
                $summary[$key]++;
            }
        }
        foreach (['expected_total', 'actual_total', 'gap_qty_total', 'sales_linked_consumption_qty'] as $key) {
            $summary[$key] = round((float) $summary[$key], 4);
        }
        $summary['gap_value_total'] = round((float) $summary['gap_value_total'], 2);

        return $summary;
    }

    private function parseDecimal(string $raw): float
    {
        $raw = str_replace(',', '.', trim($raw));
        if ($raw === '' || !is_numeric($raw)) {
            throw new \RuntimeException('Quantité invalide (nombre attendu).');
        }

        return round((float) $raw, 6);
    }

    private function tableExists(string $table): bool
    {
        $st = $this->database->pdo()->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t'
        );
        $st->execute(['t' => $table]);

        return (int) $st->fetchColumn() > 0;
    }

    private function tableColumnExists(string $table, string $column): bool
    {
        $st = $this->database->pdo()->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c'
        );
        $st->execute(['t' => $table, 'c' => $column]);

        return (int) $st->fetchColumn() > 0;
    }
}
