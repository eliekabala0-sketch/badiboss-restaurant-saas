<?php
declare(strict_types=1);

$preview = is_array($payroll_preview ?? null) ? $payroll_preview : [];
$rows = is_array($preview['rows'] ?? null) ? $preview['rows'] : [];
$periodStart = (string) ($preview['period_start'] ?? '');
$periodEnd = (string) ($preview['period_end'] ?? '');
$periodLabel = (string) ($preview['period_label'] ?? '');
$payrollHeavyLoaded = !empty($payroll_heavy_loaded);
$disciplinePreset = (string) ($payroll_discipline_preset ?? 'today');
$disciplineDate = (string) ($payroll_discipline_date ?? '');
$disciplinePeriodLabel = (string) ($payroll_discipline_period_label ?? '');
$staffPage = max(1, (int) ($staff_page ?? 1));
$staffTotalPages = max(1, (int) ($staff_total_pages ?? 1));
$staffTotalCount = max(0, (int) ($staff_total_count ?? count($rows)));
$payrollPreviewWarning = (string) ($payroll_preview_warning ?? '');
$payrollRestaurantId = max(0, (int) ($payroll_restaurant_id ?? 0));

$scoreLine = static function ($score): string {
    if ($score === null || !is_numeric($score)) {
        return 'Non evalue';
    }

    return rtrim(rtrim(number_format((float) $score, 1, '.', ''), '0'), '.') . ' / 100';
};

$payrollHref = static function (
    int $restaurantId,
    string $monthQuery,
    string $preset,
    string $disciplineDate,
    bool $heavy,
    ?int $page = null
): string {
    $query = [
        'month' => $monthQuery,
        'preset' => $preset,
    ];
    if ($restaurantId > 0) {
        $query['restaurant_id'] = $restaurantId;
    }
    if ($preset === 'date') {
        $query['date'] = $disciplineDate;
    }
    if ($heavy) {
        $query['heavy'] = '1';
    }
    if ($page !== null && $page > 0) {
        $query['page'] = $page;
    }

    return '/owner/paie/preparer?' . http_build_query($query);
};

$disciplineHref = static function (int $restaurantId, string $preset, string $disciplineDate, bool $alerts = true): string {
    $query = [
        'preset' => $preset,
        'date' => $disciplineDate,
    ];
    if ($restaurantId > 0) {
        $query['restaurant_id'] = $restaurantId;
    }
    if ($alerts) {
        $query['alerts'] = '1';
    }

    return '/owner/discipline?' . http_build_query($query);
};

$impactSummary = static function (array $row): string {
    $parts = [];
    $retention = (float) ($row['retention_amount_est'] ?? 0);
    $shortfall = (float) ($row['cash_shortfall_amount_est'] ?? 0);
    $otherPenalties = (float) ($row['other_penalties_amount'] ?? 0);
    if ($retention > 0.0001) {
        $parts[] = 'Discipline ' . format_money($retention, (string) ($row['currency'] ?? 'USD'));
    }
    if ($shortfall > 0.0001) {
        $parts[] = 'Manquants ' . format_money($shortfall, (string) ($row['currency'] ?? 'USD'));
    }
    if ($otherPenalties > 0.0001) {
        $parts[] = 'Autres ' . format_money($otherPenalties, (string) ($row['currency'] ?? 'USD'));
    }

    return $parts === [] ? 'Aucun impact calcule' : implode(' · ', $parts);
};
?>

<section class="topbar">
    <div class="brand">
        <h1>Preparer la paie</h1>
        <p class="muted">Vue mensuelle ciblee : tous les agents restent visibles via pagination claire, sans recalculs secondaires inutiles.</p>
    </div>
</section>

<?php if ($staffTotalPages > 1): ?>
<section class="card no-print" style="padding:14px 18px; margin-bottom:18px;">
    <div class="topbar" style="margin:0;">
        <p class="muted" style="margin:0;">Agents affiches : page <?= e((string) $staffPage) ?> / <?= e((string) $staffTotalPages) ?> · total <?= e((string) $staffTotalCount) ?></p>
        <div class="toolbar-actions">
            <?php if ($staffPage > 1): ?>
                <a class="button-muted" href="<?= e($payrollHref($payrollRestaurantId, (string) $month_query, $disciplinePreset, $disciplineDate, $payrollHeavyLoaded, $staffPage - 1)) ?>">Page precedente</a>
            <?php endif; ?>
            <?php if ($staffPage < $staffTotalPages): ?>
                <a class="button-muted" href="<?= e($payrollHref($payrollRestaurantId, (string) $month_query, $disciplinePreset, $disciplineDate, $payrollHeavyLoaded, $staffPage + 1)) ?>">Page suivante</a>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($flash_success)): ?><div class="flash-ok"><?= e($flash_success) ?></div><?php endif; ?>
<?php if (!empty($flash_error)): ?><div class="flash-bad"><?= e($flash_error) ?></div><?php endif; ?>
<?php if ($payrollPreviewWarning !== ''): ?><div class="flash-bad"><?= e($payrollPreviewWarning) ?></div><?php endif; ?>

<section class="card no-print" style="padding:18px 22px; margin-bottom:20px;">
    <form method="get" action="/owner/paie/preparer" class="grid" style="gap:14px; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); align-items:end;">
        <?php if ($payrollRestaurantId > 0): ?>
            <input type="hidden" name="restaurant_id" value="<?= e((string) $payrollRestaurantId) ?>">
        <?php endif; ?>
        <label>
            <span class="muted">Mois paie</span>
            <input type="month" name="month" value="<?= e($month_query) ?>" required>
        </label>
        <label>
            <span class="muted">Periode discipline</span>
            <select name="preset">
                <option value="today" <?= $disciplinePreset === 'today' ? 'selected' : '' ?>>Aujourd hui</option>
                <option value="yesterday" <?= $disciplinePreset === 'yesterday' ? 'selected' : '' ?>>Hier</option>
                <option value="date" <?= $disciplinePreset === 'date' ? 'selected' : '' ?>>Date precise</option>
                <option value="week" <?= $disciplinePreset === 'week' ? 'selected' : '' ?>>Semaine</option>
                <option value="month" <?= $disciplinePreset === 'month' ? 'selected' : '' ?>>Mois</option>
                <option value="prev_month" <?= $disciplinePreset === 'prev_month' ? 'selected' : '' ?>>Mois precedent</option>
            </select>
        </label>
        <label>
            <span class="muted">Date d ancrage</span>
            <input type="date" name="date" value="<?= e($disciplineDate) ?>">
        </label>
        <label style="display:flex; align-items:center; gap:8px; margin:0;">
            <input type="checkbox" name="heavy" value="1" <?= $payrollHeavyLoaded ? 'checked' : '' ?> style="width:auto; margin:0;">
            <span>Details avances</span>
        </label>
        <button type="submit">Actualiser</button>
    </form>
    <p class="muted" style="margin:8px 0 0;">Le score mensuel est la moyenne des scores journaliers applicables du mois.</p>
    <p class="muted" style="margin:12px 0 0;">Paie calculee sur <?= e($periodLabel) ?> · <?= e($periodStart) ?> → <?= e($periodEnd) ?>. Discipline visible ici sur <?= e($disciplinePeriodLabel) ?>.</p>
</section>

<?php
$module_nav_title = 'Navigation paie';
$module_nav_intro = 'Paie mensuelle, pagination, retenues et net propose restent dans ce module.';
$module_nav_items = [
    ['label' => 'Aujourd hui', 'href' => $payrollHref($payrollRestaurantId, (string) $month_query, 'today', $disciplineDate, $payrollHeavyLoaded)],
    ['label' => 'Hier', 'href' => $payrollHref($payrollRestaurantId, (string) $month_query, 'yesterday', $disciplineDate, $payrollHeavyLoaded)],
    ['label' => 'Semaine', 'href' => $payrollHref($payrollRestaurantId, (string) $month_query, 'week', $disciplineDate, $payrollHeavyLoaded)],
    ['label' => 'Mois', 'href' => $payrollHref($payrollRestaurantId, (string) $month_query, 'month', $disciplineDate, $payrollHeavyLoaded)],
    ['label' => 'Mois precedent', 'href' => $payrollHref($payrollRestaurantId, (string) $month_query, 'prev_month', $disciplineDate, $payrollHeavyLoaded)],
    ['label' => 'Discipline', 'href' => $disciplineHref($payrollRestaurantId, $disciplinePreset, $disciplineDate, true)],
    ['label' => 'Imprimer', 'href' => '#payroll-print'],
];
require base_path('app/Views/partials/module_quick_nav.php');
?>

<section class="card" id="payroll-print" style="padding:0; margin-bottom:24px; overflow:auto;">
    <table>
        <thead>
        <tr>
            <th>Agent</th>
            <th>Role</th>
            <th>Salaire base</th>
            <th>Jours app.</th>
            <th>Absences</th>
            <th>Inactivite</th>
            <th>Retards caisse</th>
            <th>Manquants</th>
            <th>Score mois</th>
            <th>Retenue discipline</th>
            <th>Net propose</th>
            <th>Raisons des retenues</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <tr>
                <td colspan="12" class="muted">Aucune ligne de paie disponible pour ce mois.</td>
            </tr>
        <?php endif; ?>
        <?php foreach ($rows as $row): ?>
            <?php if (!is_array($row)) { continue; } ?>
            <?php
            $monthZone = (string) ($row['monthly_score_zone'] ?? 'non_evalue');
            $zoneClass = match ($monthZone) {
                'vert' => 'badge-closed',
                'jaune' => 'badge-ready',
                'orange' => 'badge-progress',
                'rouge', 'rouge_critique' => 'badge-bad',
                default => 'badge-neutral',
            };
            ?>
            <tr>
                <td>
                    <strong><?= e((string) ($row['full_name'] ?? '')) ?></strong>
                    <?php if (!empty($row['period_effective_start'])): ?>
                        <div class="muted" style="font-size:0.84rem;">Actif depuis <?= e((string) $row['period_effective_start']) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= e(restaurant_role_label($row['role_code'] ?? null)) ?></td>
                <td><?= e(format_money((float) ($row['base_salary_monthly'] ?? 0), (string) ($row['currency'] ?? 'USD'))) ?></td>
                <td>
                    <strong><?= e((string) (int) ($row['applicable_days'] ?? 0)) ?></strong>
                    <div class="muted" style="font-size:0.84rem; margin-top:6px;">Travailles <?= e((string) (int) ($row['worked_days'] ?? 0)) ?> · repos <?= e((string) (int) ($row['rest_days_recorded'] ?? 0)) ?></div>
                </td>
                <td>
                    <strong><?= e((string) (int) ($row['unjustified_absence_days'] ?? 0)) ?> non just.</strong>
                    <div class="muted" style="font-size:0.84rem; margin-top:6px;"><?= e((string) (int) ($row['justified_absence_days'] ?? 0)) ?> just. · <?= e((string) (int) ($row['illness_days'] ?? 0)) ?> maladie</div>
                </td>
                <td><?= e((string) (int) ($row['inactive_days'] ?? 0)) ?></td>
                <td>
                    <strong><?= e((string) (int) ($row['late_remittance_hits'] ?? 0)) ?></strong>
                    <div class="muted" style="font-size:0.84rem; margin-top:6px;">max <?= e((string) (int) ($row['late_remittance_max_delay_days'] ?? 0)) ?> j</div>
                </td>
                <td>
                    <strong><?= e(format_money((float) ($row['cash_shortfall_amount_est'] ?? 0), (string) ($row['currency'] ?? 'USD'))) ?></strong>
                    <div class="muted" style="font-size:0.84rem; margin-top:6px;"><?= e((string) (int) ($row['cash_shortfall_hits'] ?? 0)) ?> cas</div>
                </td>
                <td>
                    <span class="pill <?= e($zoneClass) ?>"><?= e((string) ($row['monthly_mention'] ?? 'Non evalue')) ?></span>
                    <div class="muted" style="font-size:0.84rem; margin-top:6px;"><?= e($scoreLine($row['monthly_score_avg'] ?? null)) ?></div>
                </td>
                <td>
                    <strong><?= e(format_money((float) ($row['retention_amount_est'] ?? 0), (string) ($row['currency'] ?? 'USD'))) ?></strong>
                    <div class="muted" style="font-size:0.84rem; margin-top:6px;"><?= e((string) (float) ($row['retention_proposed_pct'] ?? 0)) ?> %</div>
                </td>
                <td><strong><?= e(format_money((float) ($row['net_pay_proposed'] ?? 0), (string) ($row['currency'] ?? 'USD'))) ?></strong></td>
                <td>
                    <strong><?= e((string) ($row['retention_reason_summary'] ?? '')) ?></strong>
                    <div class="muted" style="font-size:0.84rem; margin-top:6px;"><?= e($impactSummary($row)) ?></div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="card no-print" style="padding:18px 22px; margin-bottom:24px;">
    <p class="muted" style="margin:0;">Les montants restent indicatifs avant paiement reel. Pour une justification, une clemence ou un suivi, utilisez <a href="<?= e($disciplineHref($payrollRestaurantId, $disciplinePreset, $disciplineDate, true)) ?>">Discipline</a>.</p>
</section>
