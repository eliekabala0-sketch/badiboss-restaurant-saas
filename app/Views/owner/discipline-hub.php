<?php
declare(strict_types=1);

$restaurant = is_array($restaurant ?? null) ? $restaurant : [];
$todayYmd = (string) ($today_ymd ?? '');
$sched = is_array($discipline_schedule ?? null) ? $discipline_schedule : [];
$alerts = is_array($alerts ?? null) ? $alerts : [];
$gaugeRows = is_array($gauge_rows ?? null) ? $gauge_rows : [];
$staffUsers = is_array($staff_users ?? null) ? $staff_users : [];
$payrollProfiles = is_array($payroll_profiles ?? null) ? $payroll_profiles : [];
$disciplineHeavyLoaded = !empty($discipline_heavy_loaded);
$cur = $restaurant['currency'] ?? 'USD';

$disciplineZoneLabel = static function (?string $z): string {
    return match ((string) $z) {
        'vert' => 'Excellent',
        'jaune' => 'Bon',
        'orange' => 'Moyen',
        'rouge' => 'Problematique',
        'rouge_critique' => 'Critique',
        default => 'Non evalue',
    };
};
$disciplineZonePill = static function (?string $z): string {
    return match ((string) $z) {
        'vert' => 'badge-closed',
        'jaune' => 'badge-ready',
        'orange' => 'badge-progress',
        'rouge', 'rouge_critique' => 'badge-bad',
        default => 'badge-neutral',
    };
};
$dayStatusLabel = static function (array $activePeriod): string {
    $sb = is_array($activePeriod['score_breakdown'] ?? null) ? $activePeriod['score_breakdown'] : [];
    return match ((string) ($sb['evaluation_kind'] ?? '')) {
        'audit_activity' => 'Actif',
        'absence_unjustified' => 'Absence / inactivite',
        'absence_authorized' => 'Absence autorisee',
        'absence_illness' => 'Maladie',
        'neutral_rest' => 'Repos',
        'neutral_exempt' => 'Exonere',
        'late_justified' => 'Retard justifie',
        'manager_present_confirm' => 'Presence confirmee',
        'not_yet_active' => 'Pas encore actif',
        'never_active' => 'Jamais actif',
        'pre_hire' => 'Avant debut service',
        'account_inactive' => 'Compte inactif',
        default => (($activePeriod['score'] ?? null) === null ? 'Non evalue' : 'Observe'),
    };
};
$sanctionHint = static function (array $activePeriod, array $metrics): string {
    $score = $activePeriod['score'] ?? null;
    $absUnj = (int) ($metrics['absences_injustifiees'] ?? 0);
    $absSoft = (int) ($metrics['absences_justifiees_maladie'] ?? 0);
    $shortfalls = (int) ($metrics['manquants_caisse_hits'] ?? 0);
    $rolePct = $metrics['activite_pct_moyenne_periode'] ?? null;
    $penaltyPts = 0;
    foreach (($activePeriod['points_detail'] ?? []) as $row) {
        if (is_array($row)) {
            $penaltyPts += (int) ($row['delta_points'] ?? 0);
        }
    }
    if ($shortfalls > 0 || $absUnj >= 3 || ($score !== null && (float) $score < 40)) {
        return 'Retenue / sanction forte';
    }
    if ($absUnj >= 2 || ($rolePct !== null && is_numeric($rolePct) && (float) $rolePct < 60) || $penaltyPts < -20 || ($score !== null && (float) $score < 75)) {
        return 'Avertissement / surveillance';
    }
    if ($absSoft > 0) {
        return 'Justification / clemence possible';
    }

    return 'Rappel simple';
};
?>

<section class="topbar">
    <div class="brand">
        <h1>Discipline et presences</h1>
        <p class="muted">Jauges, presences, profils paie et alertes avec lecture quotidienne exploitable par le gerant.</p>
    </div>
</section>

<?php if (!empty($flash_success)): ?><div class="flash-ok"><?= e($flash_success) ?></div><?php endif; ?>
<?php if (!empty($flash_error)): ?><div class="flash-bad"><?= e($flash_error) ?></div><?php endif; ?>

<section class="card no-print" style="padding:14px 16px; margin-bottom:16px;">
    <?php if ($disciplineHeavyLoaded): ?>
        <p class="muted" style="margin:0;">Mode actif : <strong>version detaillee</strong>. Les jauges du jour, profils et alertes sont charges. <a href="/owner/discipline">Revenir a la vue rapide</a>.</p>
    <?php else: ?>
        <p class="muted" style="margin:0;">Mode actif : <strong>vue rapide</strong>. Pour charger les jauges du jour et les alertes detaillees : <a href="/owner/discipline?heavy=1">ouvrir la version detaillee</a>.</p>
    <?php endif; ?>
</section>

<?php if (!empty($sched['notice_unset'])): ?>
    <section class="status-banner status-warning no-print" style="margin-bottom:18px;">
        <strong>Horaire par defaut</strong>
        <p class="muted" style="margin:8px 0 0;">Parametres debut travail / tolerance / limite caisse non definis. Les valeurs par defaut restent appliquees.</p>
    </section>
<?php endif; ?>

<details class="card no-print" style="padding:14px 16px; margin-bottom:16px;" <?= $disciplineHeavyLoaded ? 'open' : '' ?> data-autoclose-details>
    <summary style="font-weight:600; cursor:pointer;">Jauges agents (aujourd hui)</summary>
    <p class="muted" style="margin:10px 0 0; font-size:0.9rem;">Tous les agents actifs du jour doivent apparaitre ici avec leur etat, leur activite, les signaux visibles et une proposition de suivi.</p>
    <div style="overflow:auto; margin-top:12px;">
        <table>
            <thead>
            <tr>
                <th>Agent</th>
                <th>Role</th>
                <th>Statut du jour</th>
                <th>Activite / score</th>
                <th>Signaux visibles</th>
                <th>Sanction proposee</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($gaugeRows === []): ?>
                <tr>
                    <td colspan="6" class="muted">Aucune jauge agent chargee pour aujourd hui.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($gaugeRows as $row): ?>
                <?php if (!is_array($row)) { continue; } ?>
                <?php
                $g = is_array($row['gauges'] ?? null) ? $row['gauges'] : [];
                $active = is_array($g['active_period'] ?? null) ? $g['active_period'] : [];
                $metrics = is_array($g['row_metrics'] ?? null) ? $g['row_metrics'] : [];
                $scoreBreakdown = is_array($active['score_breakdown'] ?? null) ? $active['score_breakdown'] : [];
                $dayZone = (string) ($active['zone'] ?? 'non_evalue');
                $monthZone = (string) ($g['zone'] ?? 'non_evalue');
                $actions = (int) ($metrics['activite_actions'] ?? ($scoreBreakdown['action_count'] ?? 0));
                $rolePct = $metrics['activite_pct_moyenne_periode'] ?? null;
                $signals = [];
                $absUnj = (int) ($metrics['absences_injustifiees'] ?? 0);
                $absSoft = (int) ($metrics['absences_justifiees_maladie'] ?? 0);
                $daysNoAct = (int) ($metrics['jours_sans_activite_mesuree'] ?? 0);
                $shortfalls = (int) ($metrics['manquants_caisse_hits'] ?? 0);
                if ($absUnj > 0) { $signals[] = $absUnj . ' abs. non just.'; }
                if ($absSoft > 0) { $signals[] = $absSoft . ' abs. just./mal.'; }
                if ($daysNoAct > 0) { $signals[] = $daysNoAct . ' j sans activite'; }
                if ($shortfalls > 0) { $signals[] = $shortfalls . ' manquant caisse'; }
                if ($rolePct !== null && is_numeric($rolePct) && (float) $rolePct < 60) { $signals[] = 'faible activite vs role'; }
                foreach (($active['points_detail'] ?? []) as $pointRow) {
                    if (!is_array($pointRow)) { continue; }
                    $lbl = strtolower((string) ($pointRow['label'] ?? ''));
                    if (str_contains($lbl, 'remise') || str_contains($lbl, 'caisse')) {
                        $signals[] = 'retard / penalite caisse';
                        break;
                    }
                }
                if ($signals === []) {
                    $signals[] = 'aucun signal critique visible';
                }
                $activityLine = $actions . ' action(s)';
                if (($active['score'] ?? null) !== null) {
                    $activityLine .= ' · score jour ' . (string) $active['score'] . ' %';
                }
                if (($g['monthly_avg'] ?? null) !== null) {
                    $activityLine .= ' · mois ' . (string) $g['monthly_avg'] . ' %';
                }
                if ($rolePct !== null && is_numeric($rolePct)) {
                    $activityLine .= ' · ' . (string) $rolePct . ' % role';
                }
                ?>
                <tr>
                    <td><?= e((string) ($row['full_name'] ?? '')) ?></td>
                    <td><?= e(restaurant_role_label($row['role_code'] ?? null)) ?></td>
                    <td>
                        <span class="pill <?= e($disciplineZonePill($dayZone)) ?>"><?= e($dayStatusLabel($active)) ?></span>
                        <div class="muted" style="font-size:0.85rem; margin-top:6px;">Jour <?= e($disciplineZoneLabel($dayZone)) ?> · mois <?= e($disciplineZoneLabel($monthZone)) ?></div>
                    </td>
                    <td class="muted" style="font-size:0.9rem;"><?= e($activityLine) ?></td>
                    <td class="muted" style="font-size:0.9rem;"><?= e(implode(' · ', array_values(array_unique($signals)))) ?></td>
                    <td>
                        <strong><?= e($sanctionHint($active, $metrics)) ?></strong>
                        <div class="muted" style="font-size:0.85rem; margin-top:6px;">La clemence reste possible via les alertes ou la justification du gerant, sans effacer l historique.</div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</details>

<details class="card no-print" style="padding:14px 16px; margin-bottom:16px;" data-autoclose-details>
    <summary style="font-weight:600; cursor:pointer;">Exceptions presence (gerant)</summary>
    <p class="muted" style="margin:10px 0 14px;">La presence normale suit l activite. Saisissez ici seulement repos, maladie, absence autorisee, exonere, retard justifie, ou travail attendu sans activite.</p>
    <form method="post" action="/owner/discipline/attendance" class="grid" style="gap:12px; margin-top:14px; max-width:720px;">
        <label>Agent
            <select name="target_user_id" required>
                <?php foreach ($staffUsers as $u): ?>
                    <?php if (!is_array($u) || (string) ($u['role_code'] ?? '') === 'owner') { continue; } ?>
                    <option value="<?= e((string) (int) ($u['id'] ?? 0)) ?>"><?= e((string) ($u['full_name'] ?? '')) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Date
            <input type="date" name="day_ymd" value="<?= e($todayYmd) ?>" required>
        </label>
        <label>Exception
            <select name="planned_status" required>
                <option value="AUTO">Effacer la saisie (activite automatique)</option>
                <option value="REPOS">Repos</option>
                <option value="EXONERE">Exonere</option>
                <option value="RETARD_JUSTIFIE">Retard justifie</option>
                <option value="ABSENCE_AUTORISEE">Absence autorisee</option>
                <option value="MALADIE">Maladie</option>
                <option value="TRAVAIL">Forcer travail attendu sans activite</option>
            </select>
        </label>
        <label style="grid-column:1/-1;">Note (facultatif)
            <textarea name="manager_note" rows="2" maxlength="500" placeholder="Motif, contexte..."></textarea>
        </label>
        <button type="submit">Enregistrer</button>
    </form>
</details>

<details class="card no-print" style="padding:14px 16px; margin-bottom:16px;" data-autoclose-details>
    <summary style="font-weight:600; cursor:pointer;">Profil paie et date de debut de service</summary>
    <p class="muted" style="margin:10px 0 12px;">Salaire mensuel, prime, et date a partir de laquelle la discipline doit compter pour l agent.</p>
    <?php foreach ($staffUsers as $u): ?>
        <?php if (!is_array($u) || (string) ($u['role_code'] ?? '') === 'owner') { continue; } ?>
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
                <label>Date debut service
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

<details class="card no-print" style="padding:14px 16px; margin-bottom:16px;" <?= ($disciplineHeavyLoaded && $alerts !== []) ? 'open' : '' ?> data-autoclose-details>
    <summary style="font-weight:600; cursor:pointer;">Alertes disciplinaires</summary>
    <?php if (!$disciplineHeavyLoaded): ?>
        <p class="muted" style="margin-top:12px;">Vue rapide : les alertes detaillees ne sont pas chargees ici. <a href="/owner/discipline?heavy=1">Afficher les alertes actives</a>.</p>
    <?php elseif ($alerts === []): ?>
        <p class="muted" style="margin-top:12px;">Aucune alerte active.</p>
    <?php else: ?>
        <?php
        $disciplinary_alerts = $alerts;
        $discipline_work_schedule = $sched;
        require base_path('app/Views/partials/disciplinary_alerts_foldable.php');
        ?>
    <?php endif; ?>
</details>

<p class="muted no-print"><a href="/owner">Retour tableau de bord</a></p>
