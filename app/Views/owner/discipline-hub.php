<?php
declare(strict_types=1);
$restaurant = is_array($restaurant ?? null) ? $restaurant : [];
$todayYmd = (string) ($today_ymd ?? '');
$sched = is_array($discipline_schedule ?? null) ? $discipline_schedule : [];
$alerts = is_array($alerts ?? null) ? $alerts : [];
$gaugeRows = is_array($gauge_rows ?? null) ? $gauge_rows : [];
$staffUsers = is_array($staff_users ?? null) ? $staff_users : [];
$payrollProfiles = is_array($payroll_profiles ?? null) ? $payroll_profiles : [];
$cur = $restaurant['currency'] ?? 'USD';
?>
<section class="topbar">
    <div class="brand">
        <h1>Discipline & présences</h1>
        <p class="muted">Jauges, présences, profils paie et alertes. Liens rapides : <a href="/owner#discipline-horaires">horaires discipline</a> · <a href="/owner/paie/preparer">Préparer la paie</a>.</p>
    </div>
</section>

<?php if (!empty($flash_success)): ?><div class="flash-ok"><?= e($flash_success) ?></div><?php endif; ?>
<?php if (!empty($flash_error)): ?><div class="flash-bad"><?= e($flash_error) ?></div><?php endif; ?>

<?php if (!empty($sched['notice_unset'])): ?>
    <section class="status-banner status-warning no-print" style="margin-bottom:18px;">
        <strong>Horaire par défaut</strong>
        <p class="muted" style="margin:8px 0 0;">Paramètres début de travail / tolérance / limite caisse non définis — valeurs par défaut appliquées (modifiables sur le tableau de bord).</p>
    </section>
<?php endif; ?>

<details class="card no-print" style="padding:14px 16px; margin-bottom:16px;" open data-autoclose-details>
    <summary style="font-weight:600; cursor:pointer;">Jauges agents (aujourd’hui)</summary>
    <div style="overflow:auto; margin-top:12px;">
        <table>
            <thead>
            <tr>
                <th>Agent</th>
                <th>Rôle</th>
                <th>Jour</th>
                <th>7 j.</th>
                <th>Mois</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($gaugeRows as $row): ?>
                <?php if (!is_array($row)) {
                    continue;
                } ?>
                <?php $g = is_array($row['gauges'] ?? null) ? $row['gauges'] : []; ?>
                <tr>
                    <td><?= e((string) ($row['full_name'] ?? '')) ?></td>
                    <td><?= e(restaurant_role_label($row['role_code'] ?? null)) ?></td>
                    <td><?= ($g['daily'] ?? null) === null ? '—' : e((string) $g['daily']) . ' %' ?></td>
                    <td><?= ($g['weekly_avg'] ?? null) === null ? '—' : e((string) $g['weekly_avg']) . ' %' ?></td>
                    <td><?= ($g['monthly_avg'] ?? null) === null ? '—' : e((string) $g['monthly_avg']) . ' %' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</details>

<details class="card no-print" style="padding:14px 16px; margin-bottom:16px;" data-autoclose-details>
    <summary style="font-weight:600; cursor:pointer;">Présence / jour (gérant)</summary>
    <form method="post" action="/owner/discipline/attendance" class="grid" style="gap:12px; margin-top:14px; max-width:720px;">
        <label>Agent
            <select name="target_user_id" required>
                <?php foreach ($staffUsers as $u): ?>
                    <?php if (!is_array($u) || (string) ($u['role_code'] ?? '') === 'owner') {
                        continue;
                    } ?>
                    <option value="<?= e((string) (int) ($u['id'] ?? 0)) ?>"><?= e((string) ($u['full_name'] ?? '')) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Date
            <input type="date" name="day_ymd" value="<?= e($todayYmd) ?>" required>
        </label>
        <label>Statut
            <select name="planned_status" required>
                <option value="TRAVAIL">Travail (défaut)</option>
                <option value="REPOS">Repos</option>
                <option value="EXONERE">Exonéré</option>
                <option value="PRESENT">Présence confirmée</option>
                <option value="RETARD_JUSTIFIE">Retard justifié</option>
                <option value="ABSENCE_AUTORISEE">Absence autorisée</option>
                <option value="MALADIE">Maladie</option>
            </select>
        </label>
        <label style="grid-column:1/-1;">Note (facultatif)
            <textarea name="manager_note" rows="2" maxlength="500" placeholder="Motif, contexte…"></textarea>
        </label>
        <button type="submit">Enregistrer</button>
    </form>
</details>

<details class="card no-print" style="padding:14px 16px; margin-bottom:16px;" data-autoclose-details>
    <summary style="font-weight:600; cursor:pointer;">Profil paie & date de début de service</summary>
    <p class="muted" style="margin:10px 0 12px;">Salaire mensuel, prime, date à laquelle la discipline commence à compter pour l’agent (sinon date de création du compte).</p>
    <?php foreach ($staffUsers as $u): ?>
        <?php if (!is_array($u) || (string) ($u['role_code'] ?? '') === 'owner') {
            continue;
        } ?>
        <?php
        $uid = (int) ($u['id'] ?? 0);
        $prof = is_array($payrollProfiles[$uid] ?? null) ? $payrollProfiles[$uid] : [];
        ?>
        <form method="post" action="/owner/discipline/payroll-profile" style="border:1px solid var(--line); border-radius:12px; padding:14px; margin-bottom:12px;">
            <input type="hidden" name="target_user_id" value="<?= e((string) $uid) ?>">
            <strong><?= e((string) ($u['full_name'] ?? '')) ?></strong>
            <div class="grid" style="gap:10px; margin-top:10px;">
                <label>Salaire base / mois
                    <input type="number" step="0.01" min="0" name="base_salary_monthly" value="<?= e((string) (float) ($prof['base_salary_monthly'] ?? 0)) ?>">
                </label>
                <label>Prime / mois
                    <input type="number" step="0.01" min="0" name="bonus_monthly" value="<?= e((string) (float) ($prof['bonus_monthly'] ?? 0)) ?>">
                </label>
                <label>Devise
                    <input type="text" name="currency" maxlength="8" value="<?= e((string) ($prof['currency'] ?? $cur)) ?>">
                </label>
                <label>Date début service
                    <input type="date" name="service_start_ymd" value="<?= e((string) ($prof['service_start_ymd'] ?? '')) ?>">
                </label>
                <label style="grid-column:1/-1;">Note interne
                    <input type="text" name="profile_note" maxlength="500" value="<?= e((string) ($prof['profile_note'] ?? '')) ?>">
                </label>
            </div>
            <button type="submit" style="margin-top:10px;">Enregistrer ce profil</button>
        </form>
    <?php endforeach; ?>
</details>

<details class="card no-print" style="padding:14px 16px; margin-bottom:16px;" <?= $alerts === [] ? '' : 'open' ?> data-autoclose-details>
    <summary style="font-weight:600; cursor:pointer;">Alertes disciplinaires</summary>
    <?php if ($alerts === []): ?>
        <p class="muted" style="margin-top:12px;">Aucune alerte active.</p>
    <?php else: ?>
        <?php
        $disciplinary_alerts = $alerts;
        $discipline_work_schedule = $sched;
        require base_path('app/Views/partials/disciplinary_alerts_foldable.php');
        ?>
    <?php endif; ?>
</details>

<p class="muted no-print"><a href="/owner">← Tableau de bord</a></p>
