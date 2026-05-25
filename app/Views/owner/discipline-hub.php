<?php
declare(strict_types=1);

$restaurant = is_array($restaurant ?? null) ? $restaurant : [];
$todayYmd = (string) ($today_ymd ?? '');
$sched = is_array($discipline_schedule ?? null) ? $discipline_schedule : [];
$alerts = is_array($alerts ?? null) ? $alerts : [];
$gaugeRows = is_array($gauge_rows ?? null) ? $gauge_rows : [];
$staffUsers = is_array($staff_users ?? null) ? $staff_users : [];
$payrollProfiles = is_array($payroll_profiles ?? null) ? $payroll_profiles : [];
$disciplineAlertsLoaded = !empty($discipline_alerts_loaded);
$disciplinePreset = (string) ($discipline_preset ?? 'today');
$disciplineAnchorDate = (string) ($discipline_anchor_date ?? $todayYmd);
$disciplinePeriodLabel = (string) ($discipline_period_label ?? '');
$cur = $restaurant['currency'] ?? 'USD';
$staffPage = max(1, (int) ($staff_page ?? 1));
$staffTotalPages = max(1, (int) ($staff_total_pages ?? 1));
$staffTotalCount = max(0, (int) ($staff_total_count ?? count($gaugeRows)));
$staffDisplayedCount = count($gaugeRows);
$disciplineAlertsWarning = (string) ($discipline_alerts_warning ?? '');
$disciplineRestaurantId = max(0, (int) ($discipline_restaurant_id ?? 0));
$disciplineScoreMode = in_array($disciplinePreset, ['week', 'month', 'prev_month'], true)
    ? ($disciplinePreset === 'week' ? 'week' : 'month')
    : 'day';
$disciplineTableColspan = 8;

$periodHref = static function (string $preset, string $date, bool $alertsLoaded, int $restaurantId): string {
    $query = ['preset' => $preset];
    if ($restaurantId > 0) {
        $query['restaurant_id'] = $restaurantId;
    }
    if ($date !== '') {
        $query['date'] = $date;
    }
    if ($alertsLoaded) {
        $query['alerts'] = '1';
    }

    return '/owner/discipline?' . http_build_query($query);
};

$zoneClass = static function (?string $zone): string {
    return match ((string) $zone) {
        'vert' => 'badge-closed',
        'jaune' => 'badge-ready',
        'orange' => 'badge-progress',
        'rouge', 'rouge_critique' => 'badge-bad',
        default => 'badge-neutral',
    };
};

$zoneLabel = static function (?string $zone): string {
    return match ((string) $zone) {
        'vert' => 'Excellent',
        'jaune' => 'Bon',
        'orange' => 'Moyen',
        'rouge' => 'Problematique',
        'rouge_critique' => 'Critique',
        default => 'Non evalue',
    };
};

$statusLabel = static function (array $activePeriod): string {
    $kind = (string) (($activePeriod['score_breakdown']['evaluation_kind'] ?? $activePeriod['evaluation_kind'] ?? ''));

    return match ($kind) {
        'absence_unjustified' => 'Absence / inactivite',
        'absence_authorized' => 'Absence autorisee',
        'absence_illness' => 'Maladie',
        'late_justified' => 'Retard justifie',
        'neutral_rest' => 'Repos',
        'neutral_exempt' => 'Exonere',
        'manager_present_confirm' => 'Presence confirmee',
        'not_yet_active' => 'Pas encore actif',
        'never_active' => 'Jamais actif',
        'pre_hire' => 'Avant debut service',
        'account_inactive' => 'Compte inactif',
        default => (($activePeriod['score'] ?? null) === null ? 'Non evalue' : 'Observe'),
    };
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
        $signals[] = 'Absence non justifiee';
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
        $signals[] = (int) $metrics['late_remittance_hits'] . ' remise(s) tardive(s)';
    }
    if ((int) ($metrics['manquants_caisse_hits'] ?? 0) > 0) {
        $signals[] = (int) $metrics['manquants_caisse_hits'] . ' manquant(s)';
    }
    if ((int) ($metrics['jours_sans_activite_mesuree'] ?? 0) > 0) {
        $signals[] = (int) ($metrics['jours_sans_activite_mesuree']) . ' jour(s) sans activite';
    }
    $rolePct = $metrics['activite_pct_moyenne_periode'] ?? null;
    if ($rolePct !== null && is_numeric($rolePct) && (float) $rolePct < 60) {
        $signals[] = 'Faible activite vs collegues';
    }

    return $signals === [] ? 'Aucun signal critique visible' : implode(' · ', array_unique($signals));
};

$sanctionHint = static function (array $activePeriod, array $metrics): string {
    $score = $activePeriod['score'] ?? null;
    $kind = (string) (($activePeriod['score_breakdown']['evaluation_kind'] ?? $activePeriod['evaluation_kind'] ?? ''));
    $shortfalls = (int) ($metrics['manquants_caisse_hits'] ?? 0);
    $lateRemittances = (int) ($metrics['late_remittance_hits'] ?? 0);
    $lateDelayDays = (int) ($metrics['late_remittance_max_delay_days'] ?? 0);
    $daysNoActivity = (int) ($metrics['jours_sans_activite_mesuree'] ?? 0);
    $unjustified = (int) ($metrics['absences_injustifiees'] ?? 0);
    $rolePct = $metrics['activite_pct_moyenne_periode'] ?? null;

    if ($shortfalls > 0 || $lateDelayDays >= 2) {
        return 'Retenue salaire ou retrait poste sensible';
    }
    if ($kind === 'absence_unjustified' && ($unjustified >= 2 || ($score !== null && (float) $score < 40))) {
        return 'Blame ou suspension temporaire';
    }
    if ($kind === 'absence_unjustified' || $daysNoActivity > 0) {
        return 'Avertissement ou retenue salaire';
    }
    if ($lateRemittances > 0) {
        return 'Rappel formel ou avertissement';
    }
    if ($score !== null && (float) $score < 50) {
        return 'Surveillance renforcee';
    }
    if ($rolePct !== null && is_numeric($rolePct) && (float) $rolePct < 60) {
        return 'Avertissement';
    }
    if (in_array($kind, ['absence_authorized', 'absence_illness', 'late_justified', 'neutral_rest'], true)) {
        return 'Clemence possible';
    }

    return 'RAS / suivi normal';
};

$salaryRisk = static function (array $metrics, array $activePeriod): string {
    if ((int) ($metrics['manquants_caisse_hits'] ?? 0) > 0) {
        return 'Risque salaire tres fort';
    }
    if ((int) ($metrics['late_remittance_max_delay_days'] ?? 0) >= 2) {
        return 'Risque salaire fort';
    }
    if ((string) (($activePeriod['score_breakdown']['evaluation_kind'] ?? $activePeriod['evaluation_kind'] ?? '')) === 'absence_unjustified') {
        return 'Retenue absence probable';
    }
    if ((int) ($metrics['jours_sans_activite_mesuree'] ?? 0) > 0) {
        return 'Retenue a confirmer';
    }

    return 'Impact limite ou nul';
};

$managerActionHint = static function (array $activePeriod, array $metrics): string {
    $kind = (string) (($activePeriod['score_breakdown']['evaluation_kind'] ?? $activePeriod['evaluation_kind'] ?? ''));
    if ($kind === 'absence_unjustified' || (int) ($metrics['manquants_caisse_hits'] ?? 0) > 0) {
        return 'Appliquer ou clemence partielle motivee';
    }
    if ((int) ($metrics['late_remittance_hits'] ?? 0) > 0 || (int) ($metrics['jours_sans_activite_mesuree'] ?? 0) > 0) {
        return 'Justifier, avertir ou reporter avec trace';
    }

    return 'Suivi simple ou clemence totale motivee';
};
?>

<section class="topbar">
    <div class="brand">
        <h1>Discipline et presences</h1>
        <p class="muted">Vue terrain par jour, hier, date precise, semaine, mois ou mois precedent, avec signaux visibles, sanction proposee et historique tracable.</p>
    </div>
</section>

<section class="card no-print" style="padding:14px 18px; margin-bottom:18px;">
    <div class="topbar" style="margin:0;">
        <p class="muted" style="margin:0;">Agents affiches : page <?= e((string) $staffPage) ?> / <?= e((string) $staffTotalPages) ?> · total <?= e((string) $staffTotalCount) ?></p>
        <div class="toolbar-actions">
            <?php if ($staffPage > 1): ?>
                <a class="button-muted" href="/owner/discipline?<?= e(http_build_query(['restaurant_id' => $disciplineRestaurantId > 0 ? $disciplineRestaurantId : null, 'preset' => $disciplinePreset, 'date' => $disciplineAnchorDate, 'alerts' => $disciplineAlertsLoaded ? '1' : null, 'page' => $staffPage - 1])) ?>">Page precedente</a>
            <?php endif; ?>
            <?php if ($staffPage < $staffTotalPages): ?>
                <a class="button-muted" href="/owner/discipline?<?= e(http_build_query(['restaurant_id' => $disciplineRestaurantId > 0 ? $disciplineRestaurantId : null, 'preset' => $disciplinePreset, 'date' => $disciplineAnchorDate, 'alerts' => $disciplineAlertsLoaded ? '1' : null, 'page' => $staffPage + 1])) ?>">Page suivante</a>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if (!empty($flash_success)): ?><div class="flash-ok"><?= e($flash_success) ?></div><?php endif; ?>
<?php if (!empty($flash_error)): ?><div class="flash-bad"><?= e($flash_error) ?></div><?php endif; ?>
<?php if ($disciplineAlertsWarning !== ''): ?><div class="flash-bad"><?= e($disciplineAlertsWarning) ?></div><?php endif; ?>

<section class="card no-print" style="padding:18px 22px; margin-bottom:18px;">
    <form method="get" action="/owner/discipline" class="grid" style="gap:14px; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); align-items:end;">
        <?php if ($disciplineRestaurantId > 0): ?>
            <input type="hidden" name="restaurant_id" value="<?= e((string) $disciplineRestaurantId) ?>">
        <?php endif; ?>
        <label>
            <span class="muted">Periode</span>
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
            <input type="date" name="date" value="<?= e($disciplineAnchorDate) ?>">
        </label>
        <label style="display:flex; align-items:center; gap:8px; margin:0;">
            <input type="checkbox" name="alerts" value="1" <?= $disciplineAlertsLoaded ? 'checked' : '' ?> style="width:auto; margin:0;">
            <span>Charger alertes actives</span>
        </label>
        <button type="submit">Actualiser</button>
    </form>
    <p class="muted" style="margin:12px 0 0;">Periode lue: <?= e($disciplinePeriodLabel) ?>.</p>
</section>

<?php
$module_nav_title = 'Navigation discipline';
$module_nav_intro = 'Jour, hier, date, semaine, mois, alertes, sanctions et clemence restent dans ce module.';
$module_nav_items = [
    ['label' => 'Jour', 'href' => $periodHref('today', $disciplineAnchorDate, $disciplineAlertsLoaded, $disciplineRestaurantId)],
    ['label' => 'Hier', 'href' => $periodHref('yesterday', $disciplineAnchorDate, $disciplineAlertsLoaded, $disciplineRestaurantId)],
    ['label' => 'Date', 'href' => '/owner/discipline?' . http_build_query(['restaurant_id' => $disciplineRestaurantId > 0 ? $disciplineRestaurantId : null, 'preset' => 'date', 'date' => $disciplineAnchorDate, 'alerts' => $disciplineAlertsLoaded ? '1' : null])],
    ['label' => 'Semaine', 'href' => $periodHref('week', $disciplineAnchorDate, $disciplineAlertsLoaded, $disciplineRestaurantId)],
    ['label' => 'Mois', 'href' => $periodHref('month', $disciplineAnchorDate, $disciplineAlertsLoaded, $disciplineRestaurantId)],
    ['label' => 'Alertes', 'href' => '/owner/discipline?' . http_build_query(['restaurant_id' => $disciplineRestaurantId > 0 ? $disciplineRestaurantId : null, 'preset' => $disciplinePreset, 'date' => $disciplineAnchorDate, 'alerts' => 1])],
    ['label' => 'Sanctions', 'href' => '#discipline-tableau'],
    ['label' => 'Clemence', 'href' => '#discipline-actions'],
    ['label' => 'Ouvrir paie', 'href' => '/owner/paie/preparer?' . http_build_query(['restaurant_id' => $disciplineRestaurantId > 0 ? $disciplineRestaurantId : null, 'preset' => $disciplinePreset, 'date' => $disciplineAnchorDate])],
];
require base_path('app/Views/partials/module_quick_nav.php');
?>

<section class="card" style="padding:18px 22px; margin-bottom:18px;">
    <h2 style="margin:0 0 8px;">Bareme terrain visible</h2>
    <p class="muted" style="margin:0 0 12px;">La jauge part de 100 chaque jour. Toute faute retire des points. Non evalue reste reserve aux agents hors service reel, inactifs ou exoneres.</p>
    <div class="grid stats">
        <article class="card stat"><span>Absence non justifiee</span><strong>-35</strong></article>
        <article class="card stat"><span>Absence repetee</span><strong>-45</strong></article>
        <article class="card stat"><span>Absence successive grave</span><strong>-55</strong></article>
        <article class="card stat"><span>Retard justifie</span><strong>-8</strong></article>
        <article class="card stat"><span>Absence autorisee</span><strong>-15</strong></article>
        <article class="card stat"><span>Maladie</span><strong>-18</strong></article>
    </div>
    <p class="muted" style="margin:12px 0 0;">Couleurs: 90-100 excellent, 75-89 bon, 60-74 moyen, 40-59 problematique, 0-39 critique.</p>
</section>

<?php if (!empty($sched['notice_unset'])): ?>
    <section class="status-banner status-warning no-print" style="margin-bottom:18px;">
        <strong>Horaires discipline par defaut</strong>
        <p class="muted" style="margin:8px 0 0;">Debut <?= e((string) ($sched['work_start'] ?? '08:00')) ?> · tolerance <?= e((string) ($sched['arrival_grace_minutes'] ?? '15')) ?> min · remise caisse <?= e((string) ($sched['cash_deadline'] ?? '22:00')) ?>.</p>
    </section>
<?php endif; ?>

<section class="card" id="discipline-tableau" style="padding:0; margin-bottom:24px; overflow:auto;">
    <table>
        <thead>
        <tr>
            <th>Agent</th>
            <th>Role</th>
            <?php if ($disciplineScoreMode === 'day'): ?><th>Score jour</th><?php endif; ?>
            <?php if ($disciplineScoreMode === 'week'): ?><th>Score semaine</th><?php endif; ?>
            <?php if ($disciplineScoreMode === 'month'): ?><th>Score mois</th><?php endif; ?>
            <th>Statut / periode</th>
            <th>Retraits / signaux</th>
            <th>Sanction proposee</th>
            <th>Risque salaire</th>
            <th>Action manager</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($gaugeRows === []): ?>
            <tr>
                <td colspan="<?= e((string) $disciplineTableColspan) ?>" class="muted">Aucune jauge disponible pour cette periode.</td>
            </tr>
        <?php endif; ?>
        <?php foreach ($gaugeRows as $row): ?>
            <?php if (!is_array($row)) { continue; } ?>
            <?php
            $gauges = is_array($row['gauges'] ?? null) ? $row['gauges'] : [];
            $active = is_array($gauges['active_period'] ?? null) ? $gauges['active_period'] : [];
            $metrics = is_array($gauges['row_metrics'] ?? null) ? $gauges['row_metrics'] : [];
            $score = $active['score'] ?? null;
            $detailRows = is_array($active['points_detail'] ?? null) ? $active['points_detail'] : [];
            $dailyScore = $gauges['daily'] ?? null;
            $weeklyScore = $gauges['weekly_avg'] ?? null;
            $monthlyScore = $gauges['monthly_avg'] ?? null;
            ?>
            <tr>
                <td><strong><?= e((string) ($row['full_name'] ?? '')) ?></strong></td>
                <td><?= e(restaurant_role_label($row['role_code'] ?? null)) ?></td>
                <?php if ($disciplineScoreMode === 'day'): ?><td><strong><?= e($scoreLine($dailyScore)) ?></strong></td><?php endif; ?>
                <?php if ($disciplineScoreMode === 'week'): ?><td><strong><?= e($scoreLine($weeklyScore)) ?></strong></td><?php endif; ?>
                <?php if ($disciplineScoreMode === 'month'): ?><td><strong><?= e($scoreLine($monthlyScore)) ?></strong></td><?php endif; ?>
                <td>
                    <span class="pill <?= e($zoneClass($active['zone'] ?? 'non_evalue')) ?>"><?= e($statusLabel($active)) ?></span>
                    <div class="muted" style="font-size:0.84rem; margin-top:6px;"><?= e($disciplinePeriodLabel) ?></div>
                    <div class="muted" style="font-size:0.84rem; margin-top:6px;"><?= e($scoreLine($score)) ?> · zone <?= e($zoneLabel($active['zone'] ?? 'non_evalue')) ?></div>
                    <?php if (!empty($active['note'])): ?>
                        <div class="muted" style="font-size:0.84rem; margin-top:6px;"><?= e((string) $active['note']) ?></div>
                    <?php endif; ?>
                </td>
                <td class="muted" style="font-size:0.9rem;"><?= e($signalSummary($active, $metrics)) ?></td>
                <td>
                    <strong><?= e($sanctionHint($active, $metrics)) ?></strong>
                    <div class="muted" style="font-size:0.84rem; margin-top:6px;">Clemence totale ou partielle possible si justification.</div>
                </td>
                <td class="muted" style="font-size:0.9rem;"><?= e($salaryRisk($metrics, $active)) ?></td>
                <td class="muted" style="font-size:0.9rem;"><?= e($managerActionHint($active, $metrics)) ?></td>
            </tr>
            <?php if ($detailRows !== []): ?>
                <tr>
                    <td colspan="<?= e((string) $disciplineTableColspan) ?>" class="muted" style="font-size:0.9rem;">
                        <strong>Trace detaillee</strong>
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

<details class="card no-print" id="discipline-actions" style="padding:14px 16px; margin-bottom:18px;" data-autoclose-details>
    <summary style="font-weight:600; cursor:pointer;">Exceptions presence (gerant)</summary>
    <p class="muted" style="margin:10px 0 14px;">Utilisez cette section pour absence justifiee, maladie, repos, retard justifie, exemption ou travail attendu sans activite.</p>
    <form method="post" action="/owner/discipline/attendance" class="grid" style="gap:12px; max-width:720px;">
        <label>Agent
            <select name="target_user_id" required>
                <?php foreach ($staffUsers as $u): ?>
                    <?php if (!is_array($u) || (string) ($u['role_code'] ?? '') === 'owner') { continue; } ?>
                    <option value="<?= e((string) (int) ($u['id'] ?? 0)) ?>"><?= e((string) ($u['full_name'] ?? '')) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Date
            <input type="date" name="day_ymd" value="<?= e($disciplineAnchorDate !== '' ? $disciplineAnchorDate : $todayYmd) ?>" required>
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

<details class="card no-print" style="padding:14px 16px; margin-bottom:18px;" data-autoclose-details>
    <summary style="font-weight:600; cursor:pointer;">Profil paie et debut de service</summary>
    <p class="muted" style="margin:10px 0 12px;">Base mensuelle, prime et date de debut reelle pour eviter les non evalues injustifies.</p>
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

<details class="card no-print" style="padding:14px 16px; margin-bottom:18px;" <?= ($disciplineAlertsLoaded && $alerts !== []) ? 'open' : '' ?> data-autoclose-details>
    <summary style="font-weight:600; cursor:pointer;">Alertes disciplinaires</summary>
    <?php if (!$disciplineAlertsLoaded): ?>
        <p class="muted" style="margin-top:12px;">Les alertes detaillees restent a la demande pour garder l ouverture fluide. <a href="/owner/discipline?<?= e(http_build_query(['restaurant_id' => $disciplineRestaurantId > 0 ? $disciplineRestaurantId : null, 'preset' => $disciplinePreset, 'date' => $disciplineAnchorDate, 'alerts' => 1])) ?>">Charger aussi les alertes actives</a>.</p>
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
