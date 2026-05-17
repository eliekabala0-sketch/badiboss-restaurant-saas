<?php
declare(strict_types=1);

$subscriptionTimezone = safe_timezone($subscription['timezone'] ?? ($restaurant['timezone'] ?? null));
$restaurantCurrency = restaurant_currency($restaurant);
$restaurantLogo = restaurant_media_url_or_default($restaurant['logo_url'] ?? null, 'logo');
$restaurantCover = restaurant_media_url_or_default($restaurant['cover_image_url'] ?? null, 'photo');
$restaurantLogoFallback = restaurant_media_fallback_url('logo');
$cashTodaySnapshot = $cash_today_snapshot ?? null;
$cashClarityToday = is_array($cashTodaySnapshot['cash_clarity_today'] ?? null) ? $cashTodaySnapshot['cash_clarity_today'] : [];
$cashSalesActivityToday = (float) ($cashClarityToday['sales_activity_total'] ?? ($cashTodaySnapshot['activity_day_sales_total_today'] ?? 0));
$cashReceivedForTodaySales = (float) ($cashClarityToday['cashier_received_sales_attributed'] ?? 0);
$cashMissingForTodaySales = round($cashSalesActivityToday - $cashReceivedForTodaySales, 2);
$cashPreviousSalesToday = (float) ($cashClarityToday['remittance_from_previous_sales'] ?? 0);
$cashBalanceToday = (float) ($cashClarityToday['cash_balance'] ?? ($cashTodaySnapshot['cash_balance_current'] ?? 0));
$pendingLateRemittance = $pending_late_remittance_attributions ?? [];
$printQuery = http_build_query(['print' => '1']);
$currentUserName = trim((string) ($user['full_name'] ?? 'Equipe'));
$currentUserRole = restaurant_role_label($user['role_code'] ?? null);
$currentUserIdentity = trim($currentUserRole . ' ' . $currentUserName);
$dashboardPrimaryNav = [];
if (!restaurant_status_blocks_operations($restaurant['status'] ?? null) && $can_access_reports) {
    $dashboardPrimaryNav[] = ['label' => 'Rapport', 'href' => '/rapport'];
}
if (!restaurant_status_blocks_operations($restaurant['status'] ?? null) && $can_access_sales) {
    $dashboardPrimaryNav[] = ['label' => 'Ventes', 'href' => '/ventes'];
}
if (!restaurant_status_blocks_operations($restaurant['status'] ?? null) && $can_access_cash) {
    $dashboardPrimaryNav[] = ['label' => 'Caisse', 'href' => '/caisse'];
}
if (!restaurant_status_blocks_operations($restaurant['status'] ?? null) && $can_access_stock) {
    $dashboardPrimaryNav[] = ['label' => 'Stock', 'href' => '/stock'];
}
if (!restaurant_status_blocks_operations($restaurant['status'] ?? null) && $can_access_kitchen) {
    $dashboardPrimaryNav[] = ['label' => 'Cuisine', 'href' => '/cuisine'];
}
if (can_access('staff.team_gauges.view')) {
    $dashboardPrimaryNav[] = ['label' => 'Discipline', 'href' => '/owner/discipline'];
}
if (can_access('payroll.prepare.view')) {
    $dashboardPrimaryNav[] = ['label' => 'Paie', 'href' => '/owner/paie/preparer'];
}
$decisionBadgeClass = static function (?string $status): string {
    return match ((string) $status) {
        'EN_ATTENTE_VALIDATION_MANAGER' => 'badge-progress',
        'VALIDE' => 'badge-closed',
        'REJETE' => 'badge-bad',
        default => 'badge-neutral',
    };
};
?>
<style>
@media print {
    .no-print { display:none !important; }
    .card { box-shadow:none !important; border:1px solid #d6d6d6; }
}
</style>
<section class="topbar">
    <div class="brand">
        <h1><?= e(($user['role_code'] ?? '') === 'manager' ? 'Pilotage opérationnel' : 'Pilotage du restaurant') ?></h1>
        <p>Suivez votre restaurant en temps réel, avec un abonnement fiable, des accès cohérents et une traçabilité exploitable jusqu’aux décisions du gérant.</p>
    </div>
    <?php if (restaurant_status_blocks_operations($restaurant['status'] ?? null)): ?>
        <div class="compact-empty">Le tableau de bord reste visible pour information, mais les actions métier sont bloquées tant que le restaurant n’est pas réactivé.</div>
    <?php endif; ?>
</section>

<?php if (!empty($flash_success)): ?><div class="flash-ok"><?= e($flash_success) ?></div><?php endif; ?>
<?php if (!empty($flash_error)): ?><div class="flash-bad"><?= e($flash_error) ?></div><?php endif; ?>
<?php
$dash_tab_extra_qs = $dash_tab_extra_qs ?? '';
$day_start_hold = $day_start_hold ?? ['blocked' => false, 'reasons' => [], 'items' => []];
?>
<?php require base_path('app/Views/partials/regularization_hold_banner.php'); ?>
<?php require base_path('app/Views/partials/operational_period_tabs.php'); ?>
<?php if (can_access('staff.team_gauges.view')): ?>
<section class="card no-print" id="discipline-horaires" style="padding:16px 18px; margin-bottom:18px;">
    <h3 style="margin:0 0 8px; font-size:1.05rem;">Paramètres horaires discipline</h3>
    <?php $dws = is_array($discipline_work_schedule ?? null) ? $discipline_work_schedule : []; ?>
    <?php if (!empty($dws['notice_unset'])): ?>
        <p class="muted" style="margin:0 0 10px; font-size:0.88rem;">Paramètres non définis en base — valeurs par défaut affichées (08:00, 15 min, versement 22:00).</p>
    <?php endif; ?>
    <form method="post" action="/owner/settings/discipline-schedule" class="grid" style="gap:12px; align-items:end;">
        <label>Début travail (HH:MM)
            <input type="text" name="work_start" value="<?= e((string) ($dws['work_start'] ?? '08:00')) ?>" pattern="\d{1,2}:\d{2}" required>
        </label>
        <label>Tolérance arrivée (minutes)
            <input type="number" name="arrival_grace_minutes" min="0" max="120" value="<?= e((string) ($dws['arrival_grace_minutes'] ?? '15')) ?>">
        </label>
        <label>Limite versement caisse (HH:MM)
            <input type="text" name="cash_deadline" value="<?= e((string) ($dws['cash_deadline'] ?? '22:00')) ?>" pattern="\d{1,2}:\d{2}" required>
        </label>
        <button type="submit">Enregistrer</button>
    </form>
    <p class="muted" style="margin:10px 0 0; font-size:0.82rem;">Utilisé pour la pénalité « retard léger » (1re action audit) et affichage cohérent avec les rapports.</p>
</section>
<?php endif; ?>
<?php
$disciplinary_alerts = $disciplinary_alerts ?? [];
$discipline_work_schedule = $discipline_work_schedule ?? null;
$disciplineDashboardLoaded = !empty($discipline_dashboard_loaded);
?>
<?php if (can_access('staff.team_gauges.view') && $disciplinary_alerts !== []): ?>
<details class="card no-print" style="padding:12px 16px; margin-bottom:18px;" data-autoclose-details>
    <summary style="font-weight:600; cursor:pointer;">Alertes disciplinaires</summary>
    <?php require base_path('app/Views/partials/disciplinary_alerts_foldable.php'); ?>
</details>
<?php endif; ?>
<?php if (can_access('staff.team_gauges.view') && !$disciplineDashboardLoaded): ?>
<section class="card no-print" style="padding:16px 18px; margin-bottom:18px;">
    <p class="muted" style="margin:0;">Les jauges et alertes d’équipe sont disponibles sur <a href="/owner/discipline">Discipline</a>. Pour les charger ici ponctuellement : <a href="/owner?discipline_dashboard=1">ouvrir la version complète</a>.</p>
</section>
<?php endif; ?>
<section class="card brand-visual" style="margin-bottom:24px; background-image:url('<?= e($restaurantCover) ?>');">
    <div class="brand-visual-body">
        <img
            src="<?= e($restaurantLogo) ?>"
            alt="Logo restaurant"
            class="brand-visual-logo"
            data-fallback-src="<?= e($restaurantLogoFallback) ?>"
        >
        <div class="brand-visual-copy">
            <span class="pill badge-gold"><?= e(subscription_status_label($subscription['status'] ?? null)) ?></span>
            <h2 style="margin:10px 0 8px;"><?= e($restaurant['public_name'] ?? $restaurant['name'] ?? 'Restaurant') ?></h2>
            <p class="muted" style="margin:0;"><?= e($currentUserIdentity !== '' ? $currentUserIdentity : 'Utilisateur connecte') ?></p>
            <p class="muted" style="margin:8px 0 0;"><?= e($restaurant['portal_tagline'] ?? ($restaurant['welcome_text'] ?? 'Vue terrain compacte pour piloter le restaurant sans duplication.')) ?></p>
            <div class="context-meta" style="margin-top:12px;">
                <span class="pill badge-neutral">Abonnement <?= e(subscription_status_label($subscription['status'] ?? null)) ?></span>
                <span class="pill badge-neutral">Paiement <?= e(subscription_payment_label($subscription['payment_status'] ?? null)) ?></span>
            </div>
        </div>
    </div>
</section>
<?php
$module_nav_title = 'Navigation principale';
$module_nav_intro = 'Une seule zone d actions : chaque bouton ouvre son module sans doublons.';
$module_nav_items = $dashboardPrimaryNav;
require base_path('app/Views/partials/module_quick_nav.php');
?>
<section class="card no-print" style="padding:18px; margin-bottom:24px;">
    <div class="toolbar-actions">
        <button type="button" onclick="window.print()">Imprimer</button>
        <a href="/owner?<?= e($printQuery) ?>" class="button-muted" target="_blank" rel="noopener noreferrer">Export imprimable / PDF navigateur</a>
    </div>
</section>
<?php if (restaurant_status_blocks_operations($restaurant['status'] ?? null)): ?>
    <section class="status-banner status-<?= e(restaurant_status_severity($restaurant['status'] ?? null)) ?>">
        <div>
            <strong><?= e(status_label($restaurant['status'] ?? null)) ?></strong>
            <div><?= e(restaurant_status_message($restaurant['status'] ?? null) ?? 'Accès limité au restaurant.') ?></div>
        </div>
        <span class="pill <?= restaurant_status_severity($restaurant['status'] ?? null) === 'danger' ? 'badge-bad' : 'badge-progress' ?>">Actions métier bloquées</span>
    </section>
<?php endif; ?>

<?php if (!empty($cashTodaySnapshot)): ?>
<section class="card" style="padding:22px; margin-bottom:24px;">
    <div class="topbar" style="margin-bottom:14px;">
        <div>
            <h2 style="margin:0;">Tableau essentiel du jour</h2>
            <p class="muted" style="margin:6px 0 0;"><?= e((string) ($cashTodaySnapshot['period_label'] ?? '')) ?> · <?= e((string) ($cashTodaySnapshot['date_ymd'] ?? '')) ?></p>
        </div>
        <?php if (!empty($can_access_reports)): ?><a href="/rapport?report_preset=today" class="button-muted no-print">Ouvrir le rapport du jour</a><?php endif; ?>
    </div>
    <div class="grid stats">
        <article class="card stat"><span>Ventes du jour</span><strong><?= e(format_money($cashSalesActivityToday, $restaurantCurrency)) ?></strong></article>
        <article class="card stat"><span>Recu caisse pour ventes du jour</span><strong><?= e(format_money($cashReceivedForTodaySales, $restaurantCurrency)) ?></strong></article>
        <article class="card stat"><span>Manquant ventes du jour</span><strong><?= e(format_money($cashMissingForTodaySales, $restaurantCurrency)) ?></strong></article>
        <article class="card stat"><span>Solde caisse cumule</span><strong><?= e(format_money($cashBalanceToday, $restaurantCurrency)) ?></strong></article>
    </div>
    <?php if ($cashClarityToday !== []): ?>
    <div class="compact-empty" style="margin-top:16px;">
        <strong>Lecture de coherence</strong>
        <div style="margin-top:8px;">Vente = jour d activite, remise = jour physique de versement, reception caisse = jour de validation caisse.</div>
        <?php if ($cashPreviousSalesToday > 0.0001): ?>
            <div style="margin-top:8px;"><strong>Encaissements recus aujourd hui pour anciennes ventes</strong> : <?= e(format_money($cashPreviousSalesToday, $restaurantCurrency)) ?></div>
        <?php endif; ?>
        <?php if (!empty($cashClarityToday['clarity_notes']) && is_array($cashClarityToday['clarity_notes'])): ?>
            <ul style="margin:8px 0 0; padding-left:18px; line-height:1.6;">
                <?php foreach ($cashClarityToday['clarity_notes'] as $note): ?>
                    <li><?= e((string) $note) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</section>
<section class="card" style="display:none;">
    <div class="topbar" style="margin-bottom:14px;">
        <div>
            <h2 style="margin:0;">Situation actuelle / Aujourd’hui</h2>
            <p class="muted" style="margin:6px 0 0;"><?= e((string) ($cashTodaySnapshot['period_label'] ?? '')) ?> · <?= e((string) ($cashTodaySnapshot['date_ymd'] ?? '')) ?></p>
        </div>
        <?php if (!empty($can_access_reports)): ?><a href="/rapport?report_preset=today" class="button-muted no-print">Ouvrir le rapport du jour</a><?php endif; ?>
    </div>
    <div class="grid stats">
        <article class="card stat"><span>Total vendu (clôturé)</span><strong><?= e(format_money((float) ($cashTodaySnapshot['total_sold_closed'] ?? 0), $restaurantCurrency)) ?></strong></article>
        <article class="card stat"><span>Remis à caisse</span><strong><?= e(format_money((float) ($cashTodaySnapshot['remitted_to_cash_physical'] ?? 0), $restaurantCurrency)) ?></strong></article>
        <article class="card stat"><span>Reçu caisse</span><strong><?= e(format_money((float) ($cashTodaySnapshot['cashier_received_today'] ?? 0), $restaurantCurrency)) ?></strong></article>
        <article class="card stat"><span>Non versé / manquant</span><strong><?= e(format_money((float) ($cashTodaySnapshot['shortfall_today_total'] ?? 0), $restaurantCurrency)) ?></strong></article>
        <article class="card stat"><span>Remises rejetées (jour)</span><strong><?= e(format_money((float) ($cashTodaySnapshot['rejected_remittances_today'] ?? 0), $restaurantCurrency)) ?></strong></article>
        <article class="card stat"><span>Écart vendu − reçu caisse</span><strong><?= e(format_money((float) ($cashTodaySnapshot['real_gap_sold_closed_minus_received'] ?? 0), $restaurantCurrency)) ?></strong></article>
        <article class="card stat"><span>Dépenses</span><strong><?= e(format_money((float) ($cashTodaySnapshot['expenses_today'] ?? 0), $restaurantCurrency)) ?></strong></article>
        <article class="card stat"><span>Solde caisse (cumul)</span><strong><?= e(format_money((float) ($cashTodaySnapshot['cash_balance_current'] ?? 0), $restaurantCurrency)) ?></strong></article>
        <article class="card stat"><span>Écarts (jour)</span><strong><?= e(format_money((float) ($cashTodaySnapshot['discrepancies_today'] ?? 0), $restaurantCurrency)) ?></strong></article>
    </div>
    <?php if ($cashClarityToday !== []): ?>
    <div class="compact-empty" style="margin-top:16px;">
        <strong>Lecture de cohÃ©rence</strong>
        <div style="margin-top:8px;">Vente = jour dâ€™activitÃ©, remise = jour physique de versement, rÃ©ception caisse = jour de validation caisse.</div>
        <?php if (!empty($cashClarityToday['clarity_notes']) && is_array($cashClarityToday['clarity_notes'])): ?>
            <ul style="margin:8px 0 0; padding-left:18px; line-height:1.6;">
                <?php foreach ($cashClarityToday['clarity_notes'] as $note): ?>
                    <li><?= e((string) $note) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if (!empty($cashClarityToday['explanations']) && is_array($cashClarityToday['explanations'])): ?>
            <ul style="margin:8px 0 0; padding-left:18px; line-height:1.6;">
                <?php foreach ($cashClarityToday['explanations'] as $explanation): ?>
                    <?php if (!is_array($explanation)) { continue; } ?>
                    <li><?= e((string) ($explanation['label'] ?? 'Explication')) ?> : <?= e(format_money((float) ($explanation['amount'] ?? 0), $restaurantCurrency)) ?><?php if (!empty($explanation['note'])): ?> Â· <?= e((string) $explanation['note']) ?><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php $sfAgents = $cashTodaySnapshot['server_shortfall']['agents'] ?? []; ?>
    <?php if (array_filter($sfAgents, static fn (array $a): bool => ((float) ($a['shortfall'] ?? 0)) > 0.0001)): ?>
    <details class="compact-card no-print" data-autoclose-details style="margin-top:16px;">
        <summary><strong>Manquants par serveur</strong> · détail articles</summary>
        <?php foreach ($sfAgents as $ag): ?>
            <?php if ((float) ($ag['shortfall'] ?? 0) <= 0.0001) { continue; } ?>
            <article class="card" style="padding:14px; margin-top:12px; border-radius:14px;">
                <strong><?= e(named_actor_label($ag['server_name'] ?? null, 'cashier_server')) ?></strong>
                <p class="muted" style="margin:6px 0 0;">Vendu clôturé : <?= e(format_money((float) ($ag['sold_closed'] ?? 0),$restaurantCurrency)) ?> · Remis (effectif) : <?= e(format_money((float) ($ag['remitted_effective'] ?? 0),$restaurantCurrency)) ?> · Non versé : <strong><?= e(format_money((float) ($ag['shortfall'] ?? 0),$restaurantCurrency)) ?></strong></p>
                <?php foreach (($ag['missing_sales'] ?? []) as $ms): ?>
                    <div class="muted" style="margin-top:10px;">Vente #<?= e((string) ($ms['sale_id'] ?? '')) ?> — <?= e(format_money((float) ($ms['total_amount'] ?? 0),$restaurantCurrency)) ?></div>
                    <ul style="margin:6px 0 0; padding-left:18px; line-height:1.65;">
                        <?php foreach (($ms['lines'] ?? []) as $ln): ?>
                            <li><?= e((string) ($ln['menu_item_name'] ?? '')) ?> × <?= e((string) ($ln['quantity'] ?? '')) ?> : <?= e(format_money((float) ($ln['line_total'] ?? 0),$restaurantCurrency)) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endforeach; ?>
            </article>
        <?php endforeach; ?>
    </details>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php
$opPulse = $module_today_pulse ?? [];
$staffGauges = $staff_gauges_overview ?? [];
?>
<?php if ($opPulse !== []): ?>
<section class="card" style="padding:20px; margin-bottom:24px;">
    <h2 style="margin:0 0 8px;">Pulse opérationnel · <?= e((string) ($opPulse['period_label'] ?? 'Aujourd’hui')) ?></h2>
    <?php if (!empty($opPulse['range_start_ymd']) && !empty($opPulse['range_end_ymd'])): ?>
        <p class="muted" style="margin:0 0 12px;"><?= e((string) $opPulse['range_start_ymd']) ?> → <?= e((string) $opPulse['range_end_ymd']) ?></p>
    <?php endif; ?>
    <div class="grid stats">
        <article class="card stat"><span>Ventes clôturées</span><strong><?= e((string) (int) ($opPulse['sales_closed_count_today'] ?? 0)) ?></strong></article>
        <article class="card stat"><span>Montant ventes clôturées</span><strong><?= e(format_money((float) ($opPulse['sales_closed_total_today'] ?? 0), $restaurantCurrency)) ?></strong></article>
        <article class="card stat"><span>Mouvements stock</span><strong><?= e((string) (int) ($opPulse['stock_movements_count_today'] ?? 0)) ?></strong></article>
        <article class="card stat"><span>Productions cuisine</span><strong><?= e((string) (int) ($opPulse['kitchen_production_count_today'] ?? 0)) ?></strong></article>
        <article class="card stat"><span>Demandes service (file)</span><strong><?= e((string) (int) ($opPulse['open_service_requests'] ?? 0)) ?></strong></article>
        <article class="card stat"><span>Demandes magasin cuisine</span><strong><?= e((string) (int) ($opPulse['open_kitchen_stock_requests'] ?? 0)) ?></strong></article>
        <article class="card stat"><span>Traçabilité (actions)</span><strong><?= e((string) (int) ($opPulse['audit_actions_today'] ?? 0)) ?></strong></article>
    </div>
    <?php if (empty($opPulse['include_live_queues'])): ?>
        <p class="muted" style="margin:14px 0 0;">La file service et les demandes magasin « en direct » ne s’affichent que lorsque la période inclut aujourd’hui.</p>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($staffGauges !== []): ?>
<?php
$disciplineZoneLabelFr = static function (string $z): string {
    return match ($z) {
        'non_evalue' => 'Non évalué',
        'vert' => 'Excellent',
        'jaune' => 'Bon',
        'orange' => 'Moyen',
        'rouge' => 'Problématique',
        'rouge_critique' => 'Très problématique',
        default => $z === '' ? '—' : ucfirst($z),
    };
};
$disciplineZonePillClass = static function (string $z): string {
    return match ($z) {
        'vert' => 'badge-closed',
        'jaune' => 'badge-ready',
        'orange' => 'badge-progress',
        'rouge', 'rouge_critique' => 'badge-bad',
        default => 'badge-neutral',
    };
};
?>
<details class="card no-print" style="padding:18px 22px; margin-bottom:24px;">
    <summary style="cursor:pointer;"><strong>Discipline et jauges</strong> · selon l’onglet période ci-dessus</summary>
    <p class="muted" style="margin:10px 0 14px;">Score affiché = période choisie (jour, semaine, mois, etc.). Retenue indicative : selon la moyenne du mois en cours (zone mois). Classement : serveurs, cuisine, stock, caisse, puis autres rôles ; dans chaque groupe, meilleur score en haut. Le compte propriétaire n’est pas coté.</p>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Agent</th><th>Rôle</th><th>Score (période)</th><th>Statut</th><th>Activité (actions)</th><th>% / moy. rôle<sup>†</sup></th><th>Abs. injust. (mois<sup>*</sup>)</th><th>Sans activité (mois<sup>*</sup>)</th><th>Manquants caisse (période)</th><th>Prép. moy. (min)</th><th>Moy. 7 j.</th><th>Mois</th><th>Retenue</th></tr></thead>
            <tbody>
            <?php foreach ($staffGauges as $sg): ?>
                <?php
                $g = $sg['gauges'] ?? [];
                $ap = is_array($g['active_period'] ?? null) ? $g['active_period'] : [];
                $periodScore = $ap['score'] ?? ($g['daily'] ?? null);
                $scoreDisplay = $periodScore === null ? 'Non évalué' : (string) $periodScore . ' %';
                $zoneRaw = (string) ($ap['zone'] ?? ($g['zone'] ?? ''));
                $zoneP = $disciplineZoneLabelFr($zoneRaw);
                $zoneClass = $disciplineZonePillClass($zoneRaw);
                $rm = is_array($g['row_metrics'] ?? null) ? $g['row_metrics'] : [];
                $actDisp = array_key_exists('activite_actions', $rm) && $rm['activite_actions'] !== null ? (string) (int) $rm['activite_actions'] : '—';
                $pctRoleDisp = array_key_exists('activite_pct_moyenne_periode', $rm) && $rm['activite_pct_moyenne_periode'] !== null ? (string) $rm['activite_pct_moyenne_periode'] . ' %' : '—';
                $abuDisp = array_key_exists('absences_injustifiees', $rm) && $rm['absences_injustifiees'] !== null ? (string) (int) $rm['absences_injustifiees'] : '—';
                $zeroActDisp = array_key_exists('jours_sans_activite_mesuree', $rm) && $rm['jours_sans_activite_mesuree'] !== null ? (string) (int) $rm['jours_sans_activite_mesuree'] : '—';
                $manqDisp = array_key_exists('manquants_caisse_hits', $rm) ? (string) (int) $rm['manquants_caisse_hits'] : '—';
                $prepDisp = array_key_exists('preparation_moy_min', $rm) && $rm['preparation_moy_min'] !== null ? (string) $rm['preparation_moy_min'] : '—';
                $wAvg = $g['weekly_avg'] ?? null;
                $mAvg = $g['monthly_avg'] ?? null;
                $wDisplay = $wAvg === null ? 'Non évalué' : (string) $wAvg . ' %';
                $mDisplay = $mAvg === null ? 'Non évalué' : (string) $mAvg . ' %';
                $mavgFloat = $mAvg === null ? null : (float) $mAvg;
                $ret = $mavgFloat === null ? '—' : (string) ($mavgFloat >= 90 ? 0.0 : ($mavgFloat >= 70 ? 5.0 : ($mavgFloat >= 50 ? 15.0 : 25.0)));
                ?>
                <tr>
                    <td><?= e((string) ($sg['full_name'] ?? '')) ?></td>
                    <td><?= e(restaurant_role_label($sg['role_code'] ?? null)) ?></td>
                    <td><?= e($scoreDisplay) ?></td>
                    <td><span class="pill <?= e($zoneClass) ?>"><?= e($zoneP) ?></span></td>
                    <td><?= e($actDisp) ?></td>
                    <td><?= e($pctRoleDisp) ?></td>
                    <td><?= e($abuDisp) ?></td>
                    <td><?= e($zeroActDisp) ?></td>
                    <td><?= e($manqDisp) ?></td>
                    <td><?= e($prepDisp) ?></td>
                    <td><?= e($wDisplay) ?></td>
                    <td><?= e($mDisplay) ?></td>
                    <td><?= e($ret) ?><?= $ret === '—' ? '' : ' %' ?></td>
                </tr>
                <?php if (!empty($ap['points_detail']) && is_array($ap['points_detail'])): ?>
                <tr>
                    <td colspan="13" class="muted" style="font-size:0.92rem;">
                        <strong><?= e((string) ($ap['titre'] ?? '')) ?></strong><?php if (!empty($ap['jour'])): ?> · <?= e((string) $ap['jour']) ?><?php endif; ?>
                        <?php if (!empty($ap['note'])): ?><span> — <?= e((string) $ap['note']) ?></span><?php endif; ?>
                        <?php if (!empty($ap['jours_moyennes'])): ?><span> — <?= e((string) (int) $ap['jours_moyennes']) ?> jour(s) pris en compte</span><?php endif; ?>
                        <ul style="margin:8px 0 0; padding-left:18px;">
                            <?php foreach ($ap['points_detail'] as $row): ?>
                                <?php if (!is_array($row)) {
                                    continue;
                                } ?>
                                <li><?= e((string) ($row['delta_points'] ?? '')) ?> pts — <?= e((string) ($row['label'] ?? '')) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted" style="margin:10px 0 0; font-size:0.9rem;"><sup>*</sup> Colonnes « mois » : données du tableau de mois lorsque la période active est un mois (mois en cours ou mois précédent) ; sinon « — ».</p>
    <p class="muted" style="margin:6px 0 0; font-size:0.9rem;"><sup>†</sup> Moyenne sur la période du % d’activité par rapport à la moyenne du même rôle (jours travaillés avec volume mesuré ; seul agent du rôle ou journée très calme : « — »).</p>
</details>
<?php endif; ?>

<details class="card" style="padding:18px 22px; margin-bottom:24px;" id="owner-global-history">
    <summary style="cursor:pointer; font-size:1.05rem;"><strong>Historique / Vue globale</strong> — rapports, périodes, totaux par serveur</summary>
    <div style="padding-top:16px;">

<?php if (!empty($report_detail_summary) && !empty($can_access_reports)): ?>
<section class="card" style="padding:20px; margin-bottom:24px;">
    <details class="compact-card" data-autoclose-details>
        <summary><strong>Résumé rapports détaillés (jour courant)</strong></summary>
        <p class="muted" style="margin-top:12px;"><?= e((string) ($report_detail_summary['period_label'] ?? '')) ?> — <?= e((string) ($report_detail_summary['date'] ?? '')) ?>. Les détails par produit et par personne sont dans le rapport, repliables pour rester lisibles sur téléphone.</p>
        <ul style="line-height:1.75; margin:12px 0 0; padding-left:20px;">
            <li><strong>Ventes</strong> : <?= e((string) ($report_detail_summary['sales_server_count'] ?? 0)) ?> serveur(s) avec lignes · <?= e(format_money((float) ($report_detail_summary['sales_grand_total'] ?? 0), $restaurantCurrency)) ?></li>
            <li><strong>Cuisine</strong> : <?= e((string) ($report_detail_summary['kitchen_cook_count'] ?? 0)) ?> cuisinier(s) · <?= e((string) ($report_detail_summary['kitchen_grand_qty'] ?? 0)) ?> unités · <?= e(format_money((float) ($report_detail_summary['kitchen_grand_value'] ?? 0), $restaurantCurrency)) ?></li>
            <li><strong>Stock</strong> : <?= e((string) ($report_detail_summary['stock_people_count'] ?? 0)) ?> responsable(s) · <?= e((string) ($report_detail_summary['stock_grand_movements'] ?? 0)) ?> mouvements validés</li>
        </ul>
        <p style="margin:14px 0 0;"><a href="/rapport" class="button-muted">Ouvrir le rapport complet</a></p>
    </details>
    <details class="compact-card" data-autoclose-details style="margin-top:14px;">
        <summary><strong>Opérations auto-clôturées aujourd’hui</strong> · <?= e((string) (int) ($report_detail_summary['auto_closed_count_today'] ?? 0)) ?></summary>
        <p class="muted" style="margin-top:12px;">Événements système (minuit, conversions) sur la journée calendaire du restaurant. Détail et filtres dans le rapport.</p>
        <p style="margin:12px 0 0;"><a href="/rapport" class="button-muted">Voir le journal dans Rapport</a></p>
    </details>
    <details class="compact-card" data-autoclose-details style="margin-top:14px;">
        <summary><strong>Résumé ventes aujourd’hui</strong> · <?= e(format_money((float) (($report_detail_summary['vente_exec_summary_today']['totals']['grand_amount'] ?? 0)), $restaurantCurrency)) ?></summary>
        <?php $vt = $report_detail_summary['vente_exec_summary_today']['totals'] ?? []; ?>
        <ul style="line-height:1.75; margin:12px 0 0; padding-left:20px;">
            <li><strong>Articles (unités)</strong> : <?= e((string) ($vt['articles_units'] ?? 0)) ?></li>
            <li><strong>Serveurs (lignes rapport)</strong> : <?= e((string) count($report_detail_summary['vente_exec_summary_today']['by_server'] ?? [])) ?></li>
        </ul>
        <p style="margin:14px 0 0;"><a href="/rapport" class="button-muted">Détail ventes / serveurs / articles</a></p>
    </details>
    <details class="compact-card" data-autoclose-details style="margin-top:14px;">
        <summary><strong>Résumé ventes semaine en cours</strong> · <?= e((string) ($report_detail_summary['week_period_label'] ?? '')) ?> · <?= e(format_money((float) ($report_detail_summary['sales_grand_total_week'] ?? 0), $restaurantCurrency)) ?> · auto <?= e((string) (int) ($report_detail_summary['auto_closed_count_week'] ?? 0)) ?></summary>
        <?php $vw = $report_detail_summary['vente_exec_summary_week']['totals'] ?? []; ?>
        <ul style="line-height:1.75; margin:12px 0 0; padding-left:20px;">
            <li><strong>Articles (unités)</strong> : <?= e((string) ($vw['articles_units'] ?? 0)) ?></li>
            <li><strong>Opérations auto-clôturées (semaine)</strong> : <?= e((string) (int) ($report_detail_summary['auto_closed_count_week'] ?? 0)) ?></li>
        </ul>
        <p style="margin:14px 0 0;"><a href="/rapport?period=weekly" class="button-muted">Ouvrir le rapport en mode semaine</a></p>
    </details>
</section>
<?php if (!empty($report_detail_summary['activity_index']['agents'] ?? null)): ?>
<section class="card" style="padding:20px; margin-bottom:24px;">
    <details class="compact-card" data-autoclose-details>
        <summary><strong>Activité (jour courant)</strong> · <?= e((string) (int) round((float) ($report_detail_summary['activity_index']['grand_total_actions'] ?? $report_detail_summary['activity_index']['total_raw_score'] ?? 0))) ?> actions</summary>
        <ul style="line-height:1.75; margin:12px 0 0; padding-left:20px;">
            <?php foreach (($report_detail_summary['activity_index']['agents'] ?? []) as $ag): ?>
                <li><?= e(named_actor_label($ag['full_name'] ?? null, $ag['role_code'] ?? null)) ?> : <strong>Activité</strong> <?= e((string) ($ag['activity_share_percent'] ?? $ag['activity_percent'] ?? 0)) ?> %
                    <span class="muted">(<?= e((string) (int) round((float) ($ag['total_actions'] ?? $ag['raw_score'] ?? 0))) ?> actions · serveur <?= e((string) (int) ($ag['server_actions'] ?? 0)) ?>, cuisine <?= e((string) (int) ($ag['kitchen_actions'] ?? 0)) ?>, stock <?= e((string) (int) ($ag['stock_actions'] ?? 0)) ?>, caisse <?= e((string) (int) ($ag['cash_actions'] ?? 0)) ?>)</span>
                </li>
            <?php endforeach; ?>
        </ul>
        <p style="margin:14px 0 0;"><a href="/rapport" class="button-muted">Détail dans Rapport</a></p>
    </details>
</section>
<?php endif; ?>

<?php endif; ?>

    <?php if (!empty($sales_period_totals)): ?>
        <section class="card" style="padding:24px; margin-top:12px;">
            <h3 style="margin-top:0;">Total vendu par serveur (périodes)</h3>
            <p class="muted">Jour, semaine et mois — détail par serveur.</p>
            <div class="grid">
                <?php foreach ($sales_period_totals as $period): ?>
                    <article class="card" style="padding:18px; border-radius:16px;">
                        <div class="topbar" style="margin-bottom:12px;">
                            <strong><?= e($period['label']) ?></strong>
                            <span class="pill badge-gold"><?= e(format_money($period['total_general'] ?? 0, $restaurant)) ?></span>
                        </div>
                        <?php if (($period['sales_by_server'] ?? []) === []): ?>
                            <p class="muted">Aucune vente validée sur cette période.</p>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table>
                                    <thead><tr><th>Serveur</th><th>Ventes</th><th>Montant</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($period['sales_by_server'] as $row): ?>
                                        <tr>
                                            <td><?= e(named_actor_label($row['server_name'] ?? null, 'cashier_server')) ?></td>
                                            <td><?= e((string) $row['sales_count']) ?></td>
                                            <td><?= e(format_money($row['total_amount'], $restaurant)) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    </div>
</details>

<?php
$restaurantAccessUrl = restaurant_generated_access_url($restaurant);
$restaurantRegisterUrl = restaurant_generated_registration_url($restaurant);
?>

<section class="card" style="padding:24px; margin-bottom:24px;">
    <div class="topbar" style="margin-bottom:18px;">
        <div>
            <h2 style="margin:0;">Liens du restaurant</h2>
            <p class="muted" style="margin:6px 0 0;">Lien public, lien d'inscription client et code restaurant a partager sans manipulation technique.</p>
        </div>
        <span class="pill badge-gold">Portail public</span>
    </div>

    <div class="grid" style="grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));">
        <div class="link-box">
            <strong>Lien d'acces restaurant</strong>
            <a href="<?= e(restaurant_generated_access_path($restaurant)) ?>" target="_blank" rel="noopener noreferrer" id="owner-restaurant-link" data-copy-value="<?= e($restaurantAccessUrl) ?>"><?= e($restaurantAccessUrl) ?></a>
            <div class="toolbar-actions">
                <button type="button" class="button-muted" data-copy-target="#owner-restaurant-link">Copier le lien</button>
                <a href="<?= e(restaurant_generated_access_path($restaurant)) ?>" target="_blank" rel="noopener noreferrer" class="button-muted">Ouvrir</a>
            </div>
        </div>

        <div class="link-box">
            <strong>Lien d'inscription client</strong>
            <a href="<?= e(restaurant_generated_registration_path($restaurant)) ?>" target="_blank" rel="noopener noreferrer" id="owner-register-link" data-copy-value="<?= e($restaurantRegisterUrl) ?>"><?= e($restaurantRegisterUrl) ?></a>
            <div class="toolbar-actions">
                <button type="button" class="button-muted" data-copy-target="#owner-register-link">Copier le lien</button>
                <a href="<?= e(restaurant_generated_registration_path($restaurant)) ?>" target="_blank" rel="noopener noreferrer" class="button-muted">Ouvrir</a>
            </div>
        </div>

        <div class="link-box">
            <strong>Code restaurant</strong>
            <span id="owner-restaurant-code" data-copy-value="<?= e($restaurant['restaurant_code'] ?? '-') ?>"><?= e($restaurant['restaurant_code'] ?? '-') ?></span>
            <div class="toolbar-actions">
                <button type="button" class="button-muted" data-copy-target="#owner-restaurant-code">Copier le code</button>
            </div>
            <span class="muted">Le code permet aussi l'inscription client sans passer par le lien direct.</span>
        </div>
    </div>
</section>

<section class="split">
    <article class="card" style="padding:24px;">
        <h2 style="margin-top:0;">Statut du restaurant</h2>
        <p><strong>Nom :</strong> <?= e($restaurant['name'] ?? '-') ?></p>
        <p><strong>Rôle courant :</strong> <?= e(restaurant_role_label($user['role_code'] ?? null)) ?></p>
        <p><strong>Aujourd’hui :</strong> <?= e(((new DateTimeImmutable(($subscription['today'] ?? 'now') . ' 00:00:00', $subscriptionTimezone))->format('d/m/Y'))) ?></p>
        <p><strong>Début abonnement :</strong> <?= e(format_date_fr($subscription['started_at'] ?? null, $subscriptionTimezone)) ?></p>
        <p><strong>Fin abonnement :</strong> <?= e(format_date_fr($subscription['ends_at'] ?? null, $subscriptionTimezone)) ?></p>
        <p><strong>Fin de grâce :</strong> <?= e(format_date_fr($subscription['grace_ends_at'] ?? null, $subscriptionTimezone)) ?></p>
        <p><strong>Jours restants :</strong> <?= e((string) ($subscription['days_remaining'] ?? '-')) ?></p>
        <p><strong>Jours expirés :</strong> <?= e((string) ($subscription['days_expired'] ?? '-')) ?></p>
        <p><strong>Message :</strong> <?= e($subscription['message'] ?? 'Aucun message') ?></p>
        <?php if (($subscription['status'] ?? null) !== 'ACTIVE' && ($subscription['status'] ?? null) !== 'GRACE_PERIOD'): ?>
            <form method="post" action="/owner/subscription/pay">
                <button type="submit">Payer l’abonnement</button>
            </form>
        <?php endif; ?>
    </article>

    <article class="card" style="padding:24px;">
        <h2 style="margin-top:0;">Accès disponibles</h2>
        <p><strong>Stock :</strong> <?= $can_access_stock ? 'Oui' : 'Non' ?></p>
        <p><strong>Cuisine :</strong> <?= $can_access_kitchen ? 'Oui' : 'Non' ?></p>
        <p><strong>Ventes :</strong> <?= $can_access_sales ? 'Oui' : 'Non' ?></p>
        <p><strong>Caisse :</strong> <?= $can_access_cash ? 'Oui' : 'Non' ?></p>
        <p><strong>Rapports :</strong> <?= $can_access_reports ? 'Oui' : 'Non' ?></p>
        <p class="muted">Les écritures et les rapports avancés restent verrouillés tant que l’abonnement n’est pas opérationnel.</p>
    </article>
</section>

<?php if (false): ?>
<?php if (false): ?>
<?php if (false): ?>
<?php if (false): ?>
<?php if (false): ?>
<section class="card" style="padding:24px; margin-top:24px;">
    <h2 style="margin-top:0;">Parametres du restaurant</h2>
    <p class="muted">La devise change uniquement l affichage du restaurant courant. Aucun montant historique n est converti.</p>
    <form method="post" action="/owner/settings/currency" class="split no-print">
        <div>
            <label>Devise du restaurant</label>
            <select name="currency">
                <option value="USD" <?= $restaurantCurrency === 'USD' ? 'selected' : '' ?>>USD</option>
                <option value="CDF" <?= $restaurantCurrency === 'CDF' ? 'selected' : '' ?>>CDF</option>
            </select>
        </div>
        <div style="align-self:end;">
            <button type="submit">Enregistrer</button>
        </div>
    </form>
    <p><strong>Devise active :</strong> <?= e($restaurantCurrency) ?></p>
</section>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>

<section class="card" style="padding:24px; margin-top:24px;">
    <h2 style="margin-top:0;">Demandes de correction</h2>
    <p class="muted">Les corrections sensibles restent tracees, motivees et limitees au restaurant courant. Les anciennes ecritures ne sont jamais reecrites silencieusement.</p>
    <?php if ($correction_requests_pending === []): ?>
        <p class="muted">Aucune demande en attente pour le moment.</p>
    <?php else: ?>
        <?php foreach ($correction_requests_pending as $request): ?>
            <article class="card" style="padding:18px; border-radius:16px; margin-bottom:14px;">
                <div class="topbar" style="margin-bottom:10px;">
                    <strong><?= e(correction_request_type_label((string) $request['request_type'])) ?></strong>
                    <span class="pill badge-bad"><?= e(correction_request_status_label((string) $request['status'])) ?></span>
                </div>
                <p><strong>Demandeur :</strong> <?= e(named_actor_label($request['requested_by_name'] ?? null, $request['requested_role_code'] ?? null)) ?></p>
                <p><strong>Justification :</strong> <?= e((string) ($request['justification'] ?? '')) ?></p>
                <?php if (($request['old_values']['quantity'] ?? null) !== null && ($request['proposed_values']['new_quantity'] ?? null) !== null): ?>
                    <p><strong>Quantite :</strong> <?= e((string) $request['old_values']['quantity']) ?> -> <?= e((string) $request['proposed_values']['new_quantity']) ?></p>
                <?php endif; ?>
                <?php if (can_access('correction.approve')): ?>
                    <form method="post" action="/owner/correction-requests/<?= e((string) $request['id']) ?>/decision" class="split no-print" style="margin-top:14px;">
                        <div>
                            <label>Decision</label>
                            <select name="decision">
                                <option value="APPROVED">Approuver</option>
                                <option value="REJECTED">Rejeter</option>
                            </select>
                        </div>
                        <div style="grid-column:1 / -1;">
                            <label>Justification du gérant / propriétaire</label>
                            <textarea name="review_notes" required>Decision motivee et tracee.</textarea>
                        </div>
                        <div style="grid-column:1 / -1;">
                            <button type="submit">Enregistrer la decision</button>
                        </div>
                    </form>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($correction_requests_recent !== []): ?>
        <div class="table-wrap" style="margin-top:16px;">
            <table>
                <thead><tr><th>Date</th><th>Demande</th><th>Acteur</th><th>Statut</th></tr></thead>
                <tbody>
                <?php foreach ($correction_requests_recent as $request): ?>
                    <tr>
                        <td><?= e(format_date_fr($request['created_at'])) ?></td>
                        <td><?= e(correction_request_type_label((string) $request['request_type'])) ?></td>
                        <td><?= e(named_actor_label($request['requested_by_name'] ?? null, $request['requested_role_code'] ?? null)) ?></td>
                        <td><?= e(correction_request_status_label((string) $request['status'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php
$saleRemittancePending = $pending_manager_sale_remittances ?? [];
$saleRemittanceHist = $sale_remittance_history ?? [];
?>
<?php if ($saleRemittancePending !== [] || $saleRemittanceHist !== []): ?>
<section class="card" style="padding:24px; margin-top:24px;">
    <h2 style="margin-top:0;">Remises caisse (ventes)</h2>
    <p class="muted">File <strong>à décider par le gérant</strong> lorsque la caisse soumet un doute. L’historique est visible par le gérant et le propriétaire, sans double réception.</p>

    <?php if ($saleRemittancePending !== []): ?>
        <details class="compact-card no-print" data-autoclose-details style="margin-top:14px;">
            <summary><strong>File gérant</strong> · <?= e((string) count($saleRemittancePending)) ?> en attente</summary>
            <?php foreach ($saleRemittancePending as $pr): ?>
                <article class="card" style="padding:16px; margin-top:14px; border-radius:14px;">
                    <div class="topbar" style="margin-bottom:8px;">
                        <strong>Transfert #<?= e((string) ($pr['id'] ?? '')) ?> · <?= e(format_money((float) ($pr['amount'] ?? 0), $restaurantCurrency)) ?></strong>
                        <span class="pill badge-progress"><?= e(cash_transfer_status_label($pr['status'] ?? null)) ?></span>
                    </div>
                    <p class="muted" style="margin:0;">Vente #<?= e((string) ($pr['sale_id'] ?? '')) ?>
                        <?php if (!empty($pr['service_reference'])): ?> · <?= e((string) $pr['service_reference']) ?><?php endif; ?>
                        · serveur <?= e(named_actor_label($pr['sale_server_name'] ?? null, 'cashier_server')) ?>
                        · de <?= e(named_actor_label($pr['from_user_name'] ?? null)) ?> vers <?= e(named_actor_label($pr['to_user_name'] ?? null)) ?>
                    </p>
                    <?php if (($user['role_code'] ?? '') === 'manager'): ?>
                        <form method="post" action="/owner/caisse/remises-vente/<?= e((string) ($pr['id'] ?? '0')) ?>/decision" class="split no-print" style="margin-top:12px;" onsubmit="return confirm('Confirmer la décision gérant sur cette remise ?');">
                            <div>
                                <label>Décision</label>
                                <select name="decision">
                                    <option value="VALIDER">Valider réception (montant attendu intégralement reçu)</option>
                                    <option value="REJETER">Rejeter la remise</option>
                                </select>
                            </div>
                            <div style="grid-column:1 / -1;">
                                <label>Motif (obligatoire)</label>
                                <textarea name="reason" required></textarea>
                            </div>
                            <div style="grid-column:1 / -1;">
                                <button type="submit">Enregistrer la décision</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <p class="muted no-print" style="margin:12px 0 0;">Seul le gérant peut valider ou rejeter depuis cette file.</p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </details>
    <?php endif; ?>

    <?php if ($saleRemittanceHist !== []): ?>
        <details class="compact-card" data-autoclose-details style="margin-top:14px;">
            <summary><strong>Historique remises vente</strong> · <?= e((string) count($saleRemittanceHist)) ?> derniers mouvements</summary>
            <div class="table-wrap" style="margin-top:12px;">
                <table>
                    <thead><tr><th>#</th><th>Vente</th><th>Montant</th><th>Statut</th><th>Date</th></tr></thead>
                    <tbody>
                    <?php foreach ($saleRemittanceHist as $h): ?>
                        <tr>
                            <td><?= e((string) ($h['id'] ?? '')) ?></td>
                            <td>#<?= e((string) ($h['sale_id'] ?? '-')) ?><?php if (!empty($h['service_reference'])): ?><br><span class="muted"><?= e((string) $h['service_reference']) ?></span><?php endif; ?></td>
                            <td><?= e(format_money((float) ($h['amount'] ?? 0), $restaurantCurrency)) ?></td>
                            <td><?= e(cash_transfer_status_label($h['status'] ?? null)) ?></td>
                            <td><?= e(format_date_fr($h['sale_activity_at'] ?? $h['validated_at'] ?? $h['received_at'] ?? $h['requested_at'] ?? $h['created_at'] ?? null, $subscriptionTimezone)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if (false): ?>
<section class="card" style="padding:24px; margin-top:24px;">
    <h2 style="margin-top:0;">Orientation rapide</h2>
    <div class="nav" style="margin-bottom:0;">
        <?php if (!restaurant_status_blocks_operations($restaurant['status'] ?? null) && $can_access_stock): ?><a href="/stock">Ouvrir Stock</a><?php endif; ?>
        <?php if (!restaurant_status_blocks_operations($restaurant['status'] ?? null) && $can_access_kitchen): ?><a href="/cuisine">Ouvrir Cuisine</a><?php endif; ?>
        <?php if (!restaurant_status_blocks_operations($restaurant['status'] ?? null) && $can_access_sales): ?><a href="/ventes">Ouvrir Ventes</a><?php endif; ?>
        <?php if (!restaurant_status_blocks_operations($restaurant['status'] ?? null) && $can_access_reports): ?><a href="/rapport">Voir les rapports</a><?php endif; ?>
        <?php if (!restaurant_status_blocks_operations($restaurant['status'] ?? null) && $can_access_cash): ?><a href="/caisse">Ouvrir Caisse</a><?php endif; ?>
        <?php if (!restaurant_status_blocks_operations($restaurant['status'] ?? null)): ?><a href="/owner/menu">Voir le menu</a><?php endif; ?>
        <?php if (can_access('payroll.prepare.view')): ?><a href="/owner/paie/preparer">Préparer la paie</a><?php endif; ?>
        <?php if (can_access('tenant.access.manage')): ?><a href="/owner/access">Rôles et accès</a><?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($pendingLateRemittance !== [] && in_array((string) ($user['role_code'] ?? ''), ['owner', 'manager'], true)): ?>
<section class="card no-print" style="padding:24px; margin-top:24px;">
    <h2 style="margin-top:0;">Remise tardive à décider</h2>
    <p class="muted">La date de vente et la date de remise diffèrent : choisissez le jour de rattachement pour les totaux.</p>
    <?php foreach ($pendingLateRemittance as $lat): ?>
        <article class="card" style="padding:16px; margin-top:14px; border-radius:14px;">
            <strong>Transfert #<?= e((string) ($lat['id'] ?? '')) ?></strong> · <?= e(format_money((float) ($lat['amount'] ?? 0), $restaurantCurrency)) ?>
            <p class="muted" style="margin:8px 0 0;">Vente #<?= e((string) ($lat['sale_id'] ?? '')) ?> · <?= e(named_actor_label($lat['sale_server_name'] ?? $lat['from_user_name'] ?? null, 'cashier_server')) ?></p>
            <p class="muted" style="margin:4px 0 0;">Date vente : <?= e((string) ($lat['sale_day_ymd'] ?? '—')) ?> · Date remise : <?= e((string) ($lat['remittance_day_ymd'] ?? '—')) ?></p>
            <form method="post" action="/owner/caisse/remises-tardives/<?= e((string) ($lat['id'] ?? '0')) ?>/rattachement" style="margin-top:12px;" onsubmit="return confirm('Rattacher au jour de vente ?');">
                <input type="hidden" name="basis" value="SALE_DAY">
                <button type="submit">Rattacher au jour de vente</button>
            </form>
            <form method="post" action="/owner/caisse/remises-tardives/<?= e((string) ($lat['id'] ?? '0')) ?>/rattachement" style="margin-top:8px;" onsubmit="return confirm('Rattacher au jour de remise ?');">
                <input type="hidden" name="basis" value="REMITTANCE_DAY">
                <button type="submit" class="button-muted">Rattacher au jour de remise</button>
            </form>
            <form method="post" action="/owner/caisse/remises-tardives/<?= e((string) ($lat['id'] ?? '0')) ?>/rattachement" style="margin-top:8px;" onsubmit="return confirm('Rattacher au jour de résolution ?');">
                <input type="hidden" name="basis" value="RESOLUTION_DAY">
                <button type="submit" class="button-muted">Rattacher au jour de résolution</button>
            </form>
        </article>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($manager_queue_cases !== []): ?>
<section class="card" style="padding:24px; margin-top:24px;">
        <div class="topbar" style="margin-bottom:18px;">
            <div>
                <h2 style="margin:0;">À décider par le gérant</h2>
                <p class="muted" style="margin:6px 0 0;">Chaque arbitrage repose uniquement sur les acteurs réellement présents dans la trace applicative.</p>
            </div>
            <span class="pill badge-bad"><?= e((string) count($manager_queue_cases)) ?> cas</span>
        </div>

        <div class="grid">
            <?php foreach ($manager_queue_cases as $case): ?>
                <article class="card" style="padding:20px; border-radius:16px;">
                    <div class="topbar" style="margin-bottom:12px;">
                        <div>
                            <strong>Cas #<?= e((string) $case['id']) ?> · <?= e(case_source_label($case['source_module'] ?? null)) ?></strong>
                            <div class="muted">
                                <?= e($case['trace']['origin_label'] ?? ($case['reported_category'] ?? $case['case_type'])) ?>
                                · <?= e(format_date_fr($case['submitted_to_manager_at'] ?? $case['technical_confirmed_at'] ?? $case['created_at'])) ?>
                                · Produit <?= e($case['trace']['product_name'] ?? ($case['stock_item_name'] ?? 'Produit')) ?>
                            </div>
                        </div>
                        <span class="pill <?= e($decisionBadgeClass($case['status'])) ?>"><?= e(validation_status_label($case['status'])) ?></span>
                    </div>

                    <p><strong>Origine :</strong> <?= e($case['trace']['source_summary'] ?? ($case['reported_category'] ?? $case['case_type'])) ?></p>
                    <p><strong>Quantité concernée :</strong> <?= e((string) ($case['trace']['quantity_affected'] ?? $case['quantity_affected'])) ?> <?= e((string) ($case['trace']['unit_name'] ?? $case['unit_name'])) ?></p>

                    <?php if (($case['trace']['metrics'] ?? []) !== []): ?>
                        <div class="table-wrap" style="margin-top:12px;">
                            <table>
                                <thead>
                                <tr>
                                    <th>Repère</th>
                                    <th>Valeur</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($case['trace']['metrics'] as $metric): ?>
                                    <tr>
                                        <td><?= e($metric['label'] ?? '-') ?></td>
                                        <td><?= e($metric['value'] ?? '-') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <div class="split" style="margin-top:16px;">
                        <div>
                            <h3 style="margin:0 0 10px;">Chaîne de responsabilité</h3>
                            <?php if (($case['trace']['steps'] ?? []) === []): ?>
                                <p class="muted">Aucune étape détaillée disponible.</p>
                            <?php else: ?>
                                <?php foreach ($case['trace']['steps'] as $step): ?>
                                    <div style="padding:12px 14px; border:1px solid var(--line); border-radius:14px; margin-bottom:10px;">
                                        <strong><?= e($step['label'] ?? '-') ?></strong>
                                        <div class="muted">
                                            <?= e($step['actor_name'] ?? 'Agent') ?>
                                            <?php if (!empty($step['role_code'])): ?> · <?= e(restaurant_role_label($step['role_code'])) ?><?php endif; ?>
                                            <?php if (!empty($step['time'])): ?> · <?= e(format_date_fr($step['time'])) ?><?php endif; ?>
                                        </div>
                                        <?php if (!empty($step['details'])): ?><div><?= e($step['details']) ?></div><?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <div>
                            <h3 style="margin:0 0 10px;">Acteurs liés seulement</h3>
                            <?php if (($case['linked_actors'] ?? []) === []): ?>
                                <p class="muted">Aucun acteur exploitable n’a été trouvé dans la trace.</p>
                            <?php else: ?>
                                <?php foreach ($case['linked_actors'] as $actor): ?>
                                    <div style="padding:12px 14px; border:1px solid var(--line); border-radius:14px; margin-bottom:10px;">
                                        <strong><?= e($actor['name']) ?></strong>
                                        <div class="muted">
                                            <?= e(restaurant_role_label($actor['role_code'] ?? null)) ?>
                                            · <?= e($actor['reason'] ?? 'Trace applicative') ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <?php if (($case['trace']['notes'] ?? []) !== []): ?>
                                <h3 style="margin:16px 0 10px;">Observations déjà enregistrées</h3>
                                <?php foreach ($case['trace']['notes'] as $note): ?>
                                    <div style="padding:12px 14px; border:1px solid var(--line); border-radius:14px; margin-bottom:10px;"><?= e($note) ?></div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (can_access('incident.decide')): ?>
                        <form method="post" action="/operations/cases/<?= e((string) $case['id']) ?>/decision" class="split" style="margin-top:18px;">
                            <input type="hidden" name="redirect_to" value="/owner">
                            <div>
                                <label>Décision finale</label>
                                <select name="decision_status">
                                    <option value="VALIDE">Valider</option>
                                    <option value="REJETE">Rejeter</option>
                                </select>
                            </div>
                            <div>
                                <label>Qualification finale</label>
                                <select name="final_qualification">
                                    <?php foreach ($final_qualifications as $qualification): ?>
                                        <option value="<?= e($qualification) ?>"><?= e($qualification) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label>Mode d’imputation</label>
                                <select name="responsibility_scope">
                                    <option value="restaurant">Restaurant</option>
                                    <option value="sans_faute_individuelle">Sans faute individuelle</option>
                                    <option value="agent_lie">Agent lié à la trace</option>
                                </select>
                            </div>
                            <div>
                                <label>Agent lié concerné</label>
                                <select name="responsible_user_id">
                                    <option value="0">Aucun agent individuel</option>
                                    <?php foreach (($case['linked_actors'] ?? []) as $actor): ?>
                                        <option value="<?= e((string) $actor['id']) ?>"><?= e($actor['name']) ?> · <?= e(restaurant_role_label($actor['role_code'] ?? null)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label>Perte matière</label>
                                <input name="material_loss_amount" value="0">
                            </div>
                            <div>
                                <label>Perte argent</label>
                                <input name="cash_loss_amount" value="0">
                            </div>
                            <div style="grid-column:1 / -1;">
                                <label>Justification du gérant</label>
                                <textarea name="manager_justification" required>Décision motivée à partir de la traçabilité disponible.</textarea>
                            </div>
                            <div style="grid-column:1 / -1;">
                                <button type="submit">Enregistrer la décision motivée</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <p class="muted" style="margin-top:16px;">Ce cas attend l’arbitrage du gérant. Le propriétaire peut suivre la trace et la décision finale sans intervenir dans l’imputation.</p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($case_decision_history !== []): ?>
    <section class="card" style="padding:24px; margin-top:24px;">
        <h2 style="margin-top:0;">Décisions enregistrées</h2>
        <p class="muted">Le propriétaire garde une visibilité claire sur la justification, les acteurs liés et l’impact financier de chaque arbitrage.</p>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Cas</th>
                    <th>Décision</th>
                    <th>Imputation</th>
                    <th>Justification</th>
                    <th>Date</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($case_decision_history as $case): ?>
                    <tr>
                        <td>
                            #<?= e((string) $case['id']) ?> · <?= e(case_source_label($case['source_module'] ?? null)) ?><br>
                            <span class="muted"><?= e($case['trace']['product_name'] ?? ($case['stock_item_name'] ?? 'Produit')) ?></span>
                        </td>
                        <td>
                            <?= e($case['final_qualification'] ?? '-') ?><br>
                            <span class="muted"><?= e(validation_status_label($case['status'])) ?></span>
                        </td>
                        <td>
                            <?= e(case_responsibility_label($case['responsibility_scope'] ?? null, $case['responsible_user_name'] ?? null)) ?><br>
                            <span class="muted">
                                Matière <?= e((string) ($case['material_loss_amount'] ?? 0)) ?>
                                · Argent <?= e((string) ($case['cash_loss_amount'] ?? 0)) ?>
                            </span>
                        </td>
                        <td><?= e($case['manager_justification'] ?? '-') ?></td>
                        <td><?= e(signed_actor_line('Decide', $case['decided_by_name'] ?? null, 'manager', $case['decided_at'] ?? $case['resolved_at'] ?? $case['created_at'], $restaurant, $subscriptionTimezone)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
