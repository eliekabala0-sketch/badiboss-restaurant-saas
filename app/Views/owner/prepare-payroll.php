<?php
declare(strict_types=1);
/** @var array<string,mixed> $restaurant */
/** @var array<string,mixed> $payroll_preview */
/** @var string $month_query */
$preview = is_array($payroll_preview ?? null) ? $payroll_preview : [];
$rows = is_array($preview['rows'] ?? null) ? $preview['rows'] : [];
$periodStart = (string) ($preview['period_start'] ?? '');
$periodEnd = (string) ($preview['period_end'] ?? '');
$periodLabel = (string) ($preview['period_label'] ?? '');
?>
<section class="topbar">
    <div class="brand">
        <h1>Preparer la paie</h1>
        <p class="muted">Apercu indicatif : salaire de base, jauge mensuelle finale, absences, retards caisse, manquants, faible activite, penalites du mois, retenue proposee et net calcule. Ajustez les profils salaire dans la base si besoin.</p>
    </div>
</section>

<?php if (!empty($flash_success)): ?><div class="flash-ok"><?= e($flash_success) ?></div><?php endif; ?>
<?php if (!empty($flash_error)): ?><div class="flash-bad"><?= e($flash_error) ?></div><?php endif; ?>

<section class="card no-print" style="padding:18px 22px; margin-bottom:24px;">
    <form method="get" action="/owner/paie/preparer" class="split" style="align-items:flex-end; gap:16px; flex-wrap:wrap;">
        <label style="display:flex; flex-direction:column; gap:6px;">
            <span class="muted">Mois a preparer (AAAA-MM)</span>
            <input type="month" name="month" value="<?= e($month_query) ?>" required>
        </label>
        <button type="submit">Actualiser</button>
    </form>
    <p class="muted" style="margin:14px 0 0;">Periode calcul : <?= e($periodLabel) ?> · <?= e($periodStart) ?> -> <?= e($periodEnd) ?></p>
    <p class="muted" style="margin:8px 0 0;">Ordre : serveurs, cuisine, stock, caisse, autres ; meilleure jauge mensuelle finale en haut.</p>
</section>

<section class="card" style="padding:0; margin-bottom:24px; overflow:auto;">
    <table>
        <thead>
        <tr>
            <th>Agent</th>
            <th>Role</th>
            <th>Salaire base</th>
            <th>Jauge mois</th>
            <th>Profil</th>
            <th>Abs. non just.</th>
            <th>Abs. just./mal.</th>
            <th>Retards caisse</th>
            <th>Manquants</th>
            <th>Faible activite</th>
            <th>Repos</th>
            <th>Jours actifs</th>
            <th>Pts penalite</th>
            <th>Alerte score</th>
            <th>Retenue %</th>
            <th>Retenue (est.)</th>
            <th>Deduc. absence</th>
            <th>Prime</th>
            <th>Net propose</th>
        </tr>
        </thead>
        <tbody>
        <?php
        $payrollZoneLabel = static function (mixed $z): string {
            $s = (string) $z;

            return match ($s) {
                'non_evalue' => 'Non evalue',
                'vert' => 'Excellent',
                'jaune' => 'Bon',
                'orange' => 'Moyen',
                'rouge' => 'Problematique',
                'rouge_critique' => 'Tres problematique',
                default => $s === '' ? '-' : ucfirst($s),
            };
        };
        $payrollZonePill = static function (string $z): string {
            return match ($z) {
                'vert' => 'badge-closed',
                'jaune' => 'badge-ready',
                'orange' => 'badge-progress',
                'rouge', 'rouge_critique' => 'badge-bad',
                default => 'badge-neutral',
            };
        };
        ?>
        <?php foreach ($rows as $r): ?>
            <?php if (!is_array($r)) {
                continue;
            } ?>
            <?php
            $zRaw = (string) ($r['monthly_score_zone'] ?? 'non_evalue');
            $rawScore = $r['monthly_score_raw_avg'] ?? null;
            $finalScore = $r['monthly_score_avg'] ?? null;
            $scoreCap = $r['monthly_score_cap'] ?? null;
            $activityPct = $r['activity_pct_vs_role_avg'] ?? null;
            $capReasons = is_array($r['discipline_cap_reasons'] ?? null) ? $r['discipline_cap_reasons'] : [];
            ?>
            <tr>
                <td><?= e((string) ($r['full_name'] ?? '')) ?></td>
                <td><?= e(restaurant_role_label($r['role_code'] ?? null)) ?></td>
                <td><?= e(format_money((float) ($r['base_salary_monthly'] ?? 0), (string) ($r['currency'] ?? 'USD'))) ?></td>
                <td>
                    <?= $finalScore === null ? 'Non evalue' : e((string) $finalScore) . ' %' ?>
                    <?php if ($rawScore !== null && $finalScore !== null && (float) $rawScore > (float) $finalScore): ?>
                        <div class="muted" style="font-size:0.85rem;">brut <?= e((string) $rawScore) ?> % · cap <?= e((string) $scoreCap) ?> %</div>
                    <?php endif; ?>
                </td>
                <td><span class="pill <?= e($payrollZonePill($zRaw)) ?>"><?= e($payrollZoneLabel($zRaw)) ?></span></td>
                <td><?= e((string) (int) ($r['unjustified_absence_days'] ?? 0)) ?></td>
                <td><?= e((string) (int) ($r['justified_absence_days'] ?? 0)) ?></td>
                <td>
                    <?= e((string) (int) ($r['late_remittance_hits'] ?? 0)) ?>
                    <?php if ((int) ($r['late_remittance_max_delay_days'] ?? 0) > 0): ?>
                        <div class="muted" style="font-size:0.85rem;">max <?= e((string) (int) ($r['late_remittance_max_delay_days'] ?? 0)) ?> j</div>
                    <?php endif; ?>
                </td>
                <td><?= e((string) (int) ($r['cash_shortfall_hits'] ?? 0)) ?></td>
                <td><?= $activityPct === null ? '-' : e((string) $activityPct) . ' % role' ?></td>
                <td><?= e((string) (int) ($r['rest_days_recorded'] ?? 0)) ?></td>
                <td><?= e((string) (int) ($r['measured_activity_days'] ?? 0)) ?></td>
                <td><?= e((string) (int) ($r['ledger_penalty_points_month'] ?? 0)) ?></td>
                <td>
                    <?php if ($capReasons === []): ?>
                        -
                    <?php else: ?>
                        <?= e(implode(' / ', $capReasons)) ?>
                    <?php endif; ?>
                </td>
                <td><?= e((string) (float) ($r['retention_proposed_pct'] ?? 0)) ?> %</td>
                <td><?= e(format_money((float) ($r['retention_amount_est'] ?? 0), (string) ($r['currency'] ?? 'USD'))) ?></td>
                <td><?= e(format_money((float) ($r['deduction_absence_est'] ?? 0), (string) ($r['currency'] ?? 'USD'))) ?></td>
                <td><?= e(format_money((float) ($r['bonus_monthly'] ?? 0), (string) ($r['currency'] ?? 'USD'))) ?></td>
                <td><strong><?= e(format_money((float) ($r['net_pay_proposed'] ?? 0), (string) ($r['currency'] ?? 'USD'))) ?></strong></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="card no-print" style="padding:18px 22px; margin-bottom:24px;">
    <p class="muted" style="margin:0 0 12px;">Cet ecran est une aide a la preparation : controlez les montants avant tout paiement reel.</p>
    <button type="button" class="button-muted" onclick="window.print()">Previsualiser paie (impression / PDF)</button>
</section>
