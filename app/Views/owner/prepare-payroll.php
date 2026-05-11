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
        <h1>Préparer la paie</h1>
        <p class="muted">Aperçu indicatif : salaires de base enregistrés, jauge mensuelle moyenne (jours évalués), présences saisies, points pénalité du mois, retenue proposée et net calculé. Ajustez les profils salaire dans la base si besoin.</p>
    </div>
</section>

<?php if (!empty($flash_success)): ?><div class="flash-ok"><?= e($flash_success) ?></div><?php endif; ?>
<?php if (!empty($flash_error)): ?><div class="flash-bad"><?= e($flash_error) ?></div><?php endif; ?>

<section class="card no-print" style="padding:18px 22px; margin-bottom:24px;">
    <form method="get" action="/owner/paie/preparer" class="split" style="align-items:flex-end; gap:16px; flex-wrap:wrap;">
        <label style="display:flex; flex-direction:column; gap:6px;">
            <span class="muted">Mois à préparer (AAAA-MM)</span>
            <input type="month" name="month" value="<?= e($month_query) ?>" required>
        </label>
        <button type="submit">Actualiser</button>
    </form>
    <p class="muted" style="margin:14px 0 0;">Période calcul : <?= e($periodLabel) ?> · <?= e($periodStart) ?> → <?= e($periodEnd) ?></p>
</section>

<section class="card" style="padding:0; margin-bottom:24px; overflow:auto;">
    <table>
        <thead>
        <tr>
            <th>Agent</th>
            <th>Rôle</th>
            <th>Salaire base</th>
            <th>Jauge mois (moy.)</th>
            <th>Présences (lignes)</th>
            <th>Pts pénalité (ledger)</th>
            <th>Retenue proposée</th>
            <th>Prime</th>
            <th>Net proposé</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <?php if (!is_array($r)) {
                continue;
            } ?>
            <tr>
                <td><?= e((string) ($r['full_name'] ?? '')) ?></td>
                <td><?= e(restaurant_role_label($r['role_code'] ?? null)) ?></td>
                <td><?= e(format_money((float) ($r['base_salary_monthly'] ?? 0), (string) ($r['currency'] ?? 'USD'))) ?></td>
                <td><?= ($r['monthly_score_avg'] ?? null) === null ? 'Non évalué' : e((string) $r['monthly_score_avg']) . ' %' ?></td>
                <td><?= e((string) (int) ($r['attendance_days_recorded'] ?? 0)) ?></td>
                <td><?= e((string) (int) ($r['ledger_penalty_points_month'] ?? 0)) ?></td>
                <td><?= e((string) (float) ($r['retention_proposed_pct'] ?? 0)) ?> %</td>
                <td><?= e(format_money((float) ($r['bonus_monthly'] ?? 0), (string) ($r['currency'] ?? 'USD'))) ?></td>
                <td><strong><?= e(format_money((float) ($r['net_pay_proposed'] ?? 0), (string) ($r['currency'] ?? 'USD'))) ?></strong></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="card no-print" style="padding:18px 22px; margin-bottom:24px;">
    <p class="muted" style="margin:0 0 12px;">Cet écran est une aide à la préparation : contrôlez les montants avant tout paiement réel.</p>
    <button type="button" class="button-muted" onclick="window.print()">Prévisualiser paie (impression / PDF)</button>
</section>
