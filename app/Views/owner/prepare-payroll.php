<?php
declare(strict_types=1);

$preview = is_array($payroll_preview ?? null) ? $payroll_preview : [];
$rows = is_array($preview['rows'] ?? null) ? $preview['rows'] : [];
$periodStart = (string) ($preview['period_start'] ?? '');
$periodEnd = (string) ($preview['period_end'] ?? '');
$periodLabel = (string) ($preview['period_label'] ?? '');
$payrollHeavyLoaded = !empty($payroll_heavy_loaded);
$disciplineRows = is_array($payroll_discipline_rows ?? null) ? $payroll_discipline_rows : [];
$disciplinePreset = (string) ($payroll_discipline_preset ?? 'today');
$disciplineDate = (string) ($payroll_discipline_date ?? '');
$disciplinePeriodLabel = (string) ($payroll_discipline_period_label ?? '');
$staffPage = max(1, (int) ($staff_page ?? 1));
$staffTotalPages = max(1, (int) ($staff_total_pages ?? 1));
$staffTotalCount = max(0, (int) ($staff_total_count ?? count($rows)));

$disciplineByUser = [];
foreach ($disciplineRows as $disciplineRow) {
    if (!is_array($disciplineRow)) {
        continue;
    }
    $disciplineByUser[(int) ($disciplineRow['user_id'] ?? 0)] = $disciplineRow;
}

$periodHref = static function (string $preset, string $monthQuery, string $disciplineDate, bool $heavy): string {
    $query = [
        'month' => $monthQuery,
        'preset' => $preset,
    ];
    if ($preset === 'date') {
        $query['date'] = $disciplineDate;
    }
    if ($heavy) {
        $query['heavy'] = '1';
    }

    return '/owner/paie/preparer?' . http_build_query($query);
};

$scoreLine = static function ($score): string {
    if ($score === null || !is_numeric($score)) {
        return 'Non evalue';
    }

    return rtrim(rtrim(number_format((float) $score, 1, '.', ''), '0'), '.') . ' / 100';
};

$signalSummary = static function (array $activePeriod, array $metrics): string {
    $signals = [];
    $kind = (string) (($activePeriod['score_breakdown']['evaluation_kind'] ?? $activePeriod['evaluation_kind'] ?? ''));
    if ($kind === 'absence_unjustified') {
        $signals[] = 'Absence / inactivite non justifiee';
    } elseif ($kind === 'absence_authorized') {
        $signals[] = 'Absence autorisee';
    } elseif ($kind === 'absence_illness') {
        $signals[] = 'Maladie';
    } elseif ($kind === 'late_justified') {
        $signals[] = 'Retard justifie';
    } elseif ($kind === 'neutral_rest') {
        $signals[] = 'Repos';
    }

    if ((int) ($metrics['activite_actions'] ?? 0) > 0) {
        $signals[] = (int) $metrics['activite_actions'] . ' action(s)';
    }
    if ((int) ($metrics['late_remittance_hits'] ?? 0) > 0) {
        $signals[] = (int) ($metrics['late_remittance_hits']) . ' remise(s) tardive(s)';
    }
    if ((int) ($metrics['manquants_caisse_hits'] ?? 0) > 0) {
        $signals[] = (int) ($metrics['manquants_caisse_hits']) . ' manquant(s)';
    }
    if ((int) ($metrics['jours_sans_activite_mesuree'] ?? 0) > 0) {
        $signals[] = (int) ($metrics['jours_sans_activite_mesuree']) . ' jour(s) sans activite';
    }

    return $signals === [] ? 'Aucun signal critique visible' : implode(' · ', array_unique($signals));
};

$sanctionSummary = static function (array $activePeriod, array $metrics): string {
    $score = $activePeriod['score'] ?? null;
    $kind = (string) (($activePeriod['score_breakdown']['evaluation_kind'] ?? $activePeriod['evaluation_kind'] ?? ''));
    $shortfalls = (int) ($metrics['manquants_caisse_hits'] ?? 0);
    $lateDelayDays = (int) ($metrics['late_remittance_max_delay_days'] ?? 0);
    $daysNoActivity = (int) ($metrics['jours_sans_activite_mesuree'] ?? 0);

    if ($shortfalls > 0 || $lateDelayDays >= 2) {
        return 'Retenue forte ou retrait poste sensible';
    }
    if ($kind === 'absence_unjustified') {
        return 'Avertissement ou retenue salaire';
    }
    if ($daysNoActivity > 0) {
        return 'Avertissement';
    }
    if ($score !== null && (float) $score < 50) {
        return 'Surveillance renforcee';
    }

    return 'RAS / suivi normal';
};

$impactSummary = static function (array $row, array $activePeriod): string {
    $parts = [];
    $retention = (float) ($row['retention_amount_est'] ?? 0);
    $absence = (float) ($row['deduction_absence_est'] ?? 0);
    if ($retention > 0.0001) {
        $parts[] = 'Retenue ' . format_money($retention, (string) ($row['currency'] ?? 'USD'));
    }
    if ($absence > 0.0001) {
        $parts[] = 'Absence ' . format_money($absence, (string) ($row['currency'] ?? 'USD'));
    }
    if (($activePeriod['score'] ?? null) === null && $parts === []) {
        return 'Aucun impact calcule';
    }

    return $parts === [] ? 'Impact faible / a confirmer' : implode(' · ', $parts);
};
?>

<section class="topbar">
    <div class="brand">
        <h1>Preparer la paie</h1>
        <p class="muted">Module direct et exploitable sans vue detaillee supplementaire, avec discipline visible sur la meme page.</p>
    </div>
</section>

<?php if ($staffTotalPages > 1): ?>
<section class="card no-print" style="padding:14px 18px; margin-bottom:18px;">
    <div class="topbar" style="margin:0;">
        <p class="muted" style="margin:0;">Agents affiches : page <?= e((string) $staffPage) ?> / <?= e((string) $staffTotalPages) ?> · total <?= e((string) $staffTotalCount) ?></p>
        <div class="toolbar-actions">
            <?php if ($staffPage > 1): ?>
                <a class="button-muted" href="/owner/paie/preparer?<?= e(http_build_query(['month' => $month_query, 'preset' => $disciplinePreset, 'date' => $disciplineDate, 'page' => $staffPage - 1])) ?>">Page precedente</a>
            <?php endif; ?>
            <?php if ($staffPage < $staffTotalPages): ?>
                <a class="button-muted" href="/owner/paie/preparer?<?= e(http_build_query(['month' => $month_query, 'preset' => $disciplinePreset, 'date' => $disciplineDate, 'page' => $staffPage + 1])) ?>">Page suivante</a>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($flash_success)): ?><div class="flash-ok"><?= e($flash_success) ?></div><?php endif; ?>
<?php if (!empty($flash_error)): ?><div class="flash-bad"><?= e($flash_error) ?></div><?php endif; ?>

<section class="card no-print" style="padding:18px 22px; margin-bottom:20px;">
    <form method="get" action="/owner/paie/preparer" class="grid" style="gap:14px; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); align-items:end;">
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
            <span>Details jour + semaine</span>
        </label>
        <button type="submit">Actualiser</button>
    </form>
    <p class="muted" style="margin:12px 0 0;">Paie calculee sur <?= e($periodLabel) ?> · <?= e($periodStart) ?> → <?= e($periodEnd) ?>. Discipline visible ici sur <?= e($disciplinePeriodLabel) ?>.</p>
</section>

<?php
$module_nav_title = 'Navigation paie';
$module_nav_intro = 'Preparation directe, liste agents, retenues, primes, net et impression restent dans ce module.';
$module_nav_items = [
    ['label' => 'Aujourd hui', 'href' => $periodHref('today', (string) $month_query, $disciplineDate, $payrollHeavyLoaded)],
    ['label' => 'Hier', 'href' => $periodHref('yesterday', (string) $month_query, $disciplineDate, $payrollHeavyLoaded)],
    ['label' => 'Semaine', 'href' => $periodHref('week', (string) $month_query, $disciplineDate, $payrollHeavyLoaded)],
    ['label' => 'Mois', 'href' => $periodHref('month', (string) $month_query, $disciplineDate, $payrollHeavyLoaded)],
    ['label' => 'Mois precedent', 'href' => $periodHref('prev_month', (string) $month_query, $disciplineDate, $payrollHeavyLoaded)],
    ['label' => 'Discipline', 'href' => '/owner/discipline?' . http_build_query(['preset' => $disciplinePreset, 'date' => $disciplineDate, 'alerts' => 1])],
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
            <th>Jour</th>
            <th>Semaine</th>
            <th>Mois</th>
            <th>Signaux / sanction</th>
            <th>Impact salaire</th>
            <th>Salaire base</th>
            <th>Retenue %</th>
            <th>Abs. non just.</th>
            <th>Retards caisse</th>
            <th>Manquants</th>
            <th>Prime</th>
            <th>Net propose</th>
            <th>Action</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <tr>
                <td colspan="15" class="muted">Aucune ligne de paie disponible pour ce mois.</td>
            </tr>
        <?php endif; ?>
        <?php foreach ($rows as $row): ?>
            <?php if (!is_array($row)) { continue; } ?>
            <?php
            $uid = (int) ($row['user_id'] ?? 0);
            $disciplineRow = is_array($disciplineByUser[$uid] ?? null) ? $disciplineByUser[$uid] : [];
            $gauges = is_array($disciplineRow['gauges'] ?? null) ? $disciplineRow['gauges'] : [];
            $activePeriod = is_array($gauges['active_period'] ?? null) ? $gauges['active_period'] : [];
            $metrics = is_array($gauges['row_metrics'] ?? null) ? $gauges['row_metrics'] : [];
            $score = $activePeriod['score'] ?? null;
            $zone = (string) ($activePeriod['zone'] ?? 'non_evalue');
            $zoneClass = match ($zone) {
                'vert' => 'badge-closed',
                'jaune' => 'badge-ready',
                'orange' => 'badge-progress',
                'rouge', 'rouge_critique' => 'badge-bad',
                default => 'badge-neutral',
            };
            $zoneLabel = match ($zone) {
                'vert' => 'Excellent',
                'jaune' => 'Bon',
                'orange' => 'Moyen',
                'rouge' => 'Problematique',
                'rouge_critique' => 'Critique',
                default => 'Non evalue',
            };
            $signals = $signalSummary($activePeriod, $metrics);
            $sanction = $sanctionSummary($activePeriod, $metrics);
            $impact = $impactSummary($row, $activePeriod);
            $detailRows = is_array($activePeriod['points_detail'] ?? null) ? $activePeriod['points_detail'] : [];
            ?>
            <tr>
                <td>
                    <strong><?= e((string) ($row['full_name'] ?? '')) ?></strong>
                    <?php if (!empty($row['period_effective_start'])): ?>
                        <div class="muted" style="font-size:0.84rem;">Actif depuis <?= e((string) $row['period_effective_start']) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= e(restaurant_role_label($row['role_code'] ?? null)) ?></td>
                <td><strong><?= e($scoreLine($gauges['daily'] ?? null)) ?></strong></td>
                <td><strong><?= e($scoreLine($gauges['weekly_avg'] ?? null)) ?></strong></td>
                <td>
                    <span class="pill <?= e($zoneClass) ?>"><?= e($zoneLabel) ?></span>
                    <div class="muted" style="font-size:0.84rem; margin-top:6px;"><?= e($scoreLine($gauges['monthly_avg'] ?? $score)) ?></div>
                </td>
                <td>
                    <strong><?= e($sanction) ?></strong>
                    <div class="muted" style="font-size:0.84rem; margin-top:6px;"><?= e($signals) ?></div>
                    <?php if (!empty($activePeriod['note'])): ?>
                        <div class="muted" style="font-size:0.84rem; margin-top:6px;"><?= e((string) $activePeriod['note']) ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <strong><?= e($impact) ?></strong>
                    <div class="muted" style="font-size:0.84rem; margin-top:6px;">Trace disponible dans Discipline et l audit.</div>
                </td>
                <td><?= e(format_money((float) ($row['base_salary_monthly'] ?? 0), (string) ($row['currency'] ?? 'USD'))) ?></td>
                <td><?= e((string) (float) ($row['retention_proposed_pct'] ?? 0)) ?> %</td>
                <td><?= e((string) (int) ($row['unjustified_absence_days'] ?? 0)) ?></td>
                <td><?= e((string) (int) ($row['late_remittance_hits'] ?? 0)) ?></td>
                <td><?= e((string) (int) ($row['cash_shortfall_hits'] ?? 0)) ?></td>
                <td><?= e(format_money((float) ($row['bonus_monthly'] ?? 0), (string) ($row['currency'] ?? 'USD'))) ?></td>
                <td><strong><?= e(format_money((float) ($row['net_pay_proposed'] ?? 0), (string) ($row['currency'] ?? 'USD'))) ?></strong></td>
                <td>
                    <a href="/owner/discipline?<?= e(http_build_query(['preset' => $disciplinePreset, 'date' => $disciplineDate, 'alerts' => 1])) ?>">Discipline</a>
                    <div class="muted" style="font-size:0.84rem; margin-top:6px;"><a href="#" onclick="window.print(); return false;">Imprimer</a></div>
                </td>
            </tr>
            <?php if ($detailRows !== []): ?>
                <tr>
                    <td colspan="15" class="muted" style="font-size:0.9rem;">
                        <strong>Detail trace</strong>
                        <ul style="margin:8px 0 0; padding-left:18px;">
                            <?php foreach ($detailRows as $detailRow): ?>
                                <?php if (!is_array($detailRow)) { continue; } ?>
                                <li><?= e((string) ($detailRow['label'] ?? 'Point')) ?> · <?= e((string) ($detailRow['delta_points'] ?? 0)) ?> pts</li>
                            <?php endforeach; ?>
                        </ul>
                    </td>
                </tr>
            <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="card no-print" style="padding:18px 22px; margin-bottom:24px;">
    <p class="muted" style="margin:0;">Les montants restent indicatifs avant paiement reel. Pour appliquer une sanction, une clemence, une justification ou un report avec trace, utilisez <a href="/owner/discipline?<?= e(http_build_query(['preset' => $disciplinePreset, 'date' => $disciplineDate, 'alerts' => 1])) ?>">Discipline</a>.</p>
</section>
