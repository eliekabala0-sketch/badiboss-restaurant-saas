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
    public function buildBundle(int $restaurantId, string $dateYmd, string $period): array
    {
        $this->ensurePhysicalChecksSchema();
        $tz = $this->reportService->timezoneForRestaurantReports($restaurantId);
        $base = $this->reportService->normalizeDatePublic($dateYmd, $tz);
        [$startAt, $endAt, $periodLabel] = $this->reportService->periodBoundsPublic($base, $period, $tz);

        $items = $this->loadActiveStockItems($restaurantId);
        $aggByItem = $this->movementAggregatesForPeriod($restaurantId, $startAt, $endAt);
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
            $qtyStore = (float) ($si['quantity_in_stock'] ?? 0);
            $qtyKitchen = (float) ($si['kitchen_qty'] ?? 0);

            $deltaMagasin = (float) $agg['entrees'] + (float) $agg['retours'] + (float) $agg['corrections_magasin']
                - (float) $agg['sortie_cuisine'] - (float) $agg['sortie_autre'] - (float) $agg['pertes'];
            $openingStore = $qtyStore - $deltaMagasin;

            $deltaKitchen = (float) $agg['sortie_cuisine'] - (float) $agg['conso_cuisine'];
            $openingKitchen = $qtyKitchen - $deltaKitchen;

            $theoreticalStoreEnd = $openingStore + $deltaMagasin;
            $storeCoherent = abs($theoreticalStoreEnd - $qtyStore) < self::EPS;

            $macro = stock_control_macro_category((string) ($si['category_label'] ?? ''));

            $articles[] = [
                'id' => $sid,
                'name' => (string) ($si['name'] ?? ''),
                'category_label' => trim((string) ($si['category_label'] ?? '')),
                'macro_category' => $macro,
                'unit_name' => (string) ($si['unit_name'] ?? ''),
                'qty_store_now' => round($qtyStore, 4),
                'qty_kitchen_now' => round($qtyKitchen, 4),
                'qty_total_available' => round($qtyStore + $qtyKitchen, 4),
                'opening_store' => round($openingStore, 4),
                'opening_kitchen' => round($openingKitchen, 4),
                'period_entrees' => round((float) $agg['entrees'], 4),
                'period_sortie_cuisine' => round((float) $agg['sortie_cuisine'], 4),
                'period_sortie_autre' => round((float) $agg['sortie_autre'], 4),
                'period_pertes' => round((float) $agg['pertes'], 4),
                'period_retours' => round((float) $agg['retours'], 4),
                'period_corrections_magasin' => round((float) $agg['corrections_magasin'], 4),
                'period_conso_cuisine' => round((float) $agg['conso_cuisine'], 4),
                'store_coherent' => $storeCoherent,
            ];
        }

        usort($articles, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        $categories = $this->rollupCategories($articles);

        return [
            'period_label' => $periodLabel,
            'period_start_at' => $startAt->format('Y-m-d H:i:s'),
            'period_end_at' => $endAt->format('Y-m-d H:i:s'),
            'date_ymd' => $dateYmd,
            'period_key' => $period,
            'formula_note' => 'Magasin : départ + entrées − sorties magasin (dont cuisine validée) − autres sorties − pertes + retours + corrections inventaire (signées) = stock magasin actuel. Cuisine : départ + réceptions depuis magasin − consommation (servi / utilisé, hors double déduction plat déjà préparé) = stock cuisine actuel. Les sorties cuisine « PROVISOIRE » non réceptionnées ne sont pas comptées.',
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
            $eStore = (float) ($exp['qty_store_now'] ?? 0);
            $eKitchen = (float) ($exp['qty_kitchen_now'] ?? 0);

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
               AND sm.created_at >= :start_at AND sm.created_at < :end_at
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
        }
        foreach ($map as &$row) {
            foreach (['opening_store', 'period_entrees', 'period_sortie_cuisine', 'period_sortie_autre', 'period_conso_cuisine', 'period_pertes', 'qty_store_now', 'qty_kitchen_now', 'qty_total_available'] as $k) {
                $row[$k] = round((float) $row[$k], 4);
            }
        }
        unset($row);
        $list = array_values($map);
        usort($list, static fn (array $x, array $y): int => strcmp((string) $x['macro_category'], (string) $y['macro_category']));

        return $list;
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
