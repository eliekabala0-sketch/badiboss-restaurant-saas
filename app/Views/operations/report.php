<?php
$reportTimezone = safe_timezone($report['timezone'] ?? ($restaurant['settings']['restaurant_reports_timezone'] ?? $restaurant['timezone'] ?? null));
$restaurantCurrency = restaurant_currency($restaurant);
$restaurantLogo = restaurant_media_url_or_default($restaurant['logo_url'] ?? null, 'logo');
$viewFilters = $report['view_filters'] ?? ($view_filters ?? []);
$printQuery = http_build_query(array_filter([
    'date' => $report['selected_date'] ?? $date,
    'period' => $period ?? 'daily',
    'restaurant_id' => (current_user()['scope'] ?? null) === 'super_admin' ? (string) $restaurant['id'] : null,
    'user_id' => (int) ($viewFilters['user_id'] ?? 0) > 0 ? (string) (int) $viewFilters['user_id'] : null,
    'role_code' => trim((string) ($viewFilters['role_code'] ?? '')) !== '' ? (string) $viewFilters['role_code'] : null,
    'action_scope' => ($viewFilters['action_scope'] ?? 'all') !== 'all' ? (string) $viewFilters['action_scope'] : null,
    'action_name' => trim((string) ($viewFilters['action_name'] ?? '')) !== '' ? (string) $viewFilters['action_name'] : null,
    'closed_sales_only' => !empty($viewFilters['closed_sales_only']) ? '1' : null,
    'menu_item_id' => (int) ($viewFilters['menu_item_id'] ?? 0) > 0 ? (string) (int) $viewFilters['menu_item_id'] : null,
    'stock_item_id' => (int) ($viewFilters['stock_item_id'] ?? 0) > 0 ? (string) (int) $viewFilters['stock_item_id'] : null,
    'stock_movement_type' => trim((string) ($viewFilters['stock_movement_type'] ?? '')) !== '' ? (string) $viewFilters['stock_movement_type'] : null,
    'article_search' => trim((string) ($viewFilters['article_search'] ?? '')) !== '' ? (string) $viewFilters['article_search'] : null,
    'activity_agent_search' => trim((string) ($viewFilters['activity_agent_search'] ?? '')) !== '' ? (string) $viewFilters['activity_agent_search'] : null,
    'timeline_actor_search' => trim((string) ($viewFilters['timeline_actor_search'] ?? '')) !== '' ? (string) $viewFilters['timeline_actor_search'] : null,
    'timeline_limit' => (int) ($viewFilters['timeline_limit'] ?? 350) > 350 ? (string) (int) ($viewFilters['timeline_limit'] ?? 350) : null,
    'print' => '1',
], static fn ($value): bool => $value !== null && $value !== ''));
$cashClarity = $report['financial_report']['cash_clarity'] ?? [];
$people = $report['people_overview'] ?? [];
$gt = $people['grand_totals'] ?? [];
$salesDetail = $report['sales_detail_by_server'] ?? ['servers' => [], 'grand_total' => 0];
$kitchenDetail = $report['kitchen_detail_by_cook'] ?? ['cooks' => [], 'grand_total_qty' => 0, 'grand_total_value' => 0];
$stockDetail = $report['stock_detail_by_person'] ?? ['people' => [], 'grand_total_movements' => 0];
$timeline = $report['nominative_timeline'] ?? [];
$execSummary = $report['executive_summary'] ?? ['by_server' => [], 'by_article' => [], 'totals' => [], 'period_label' => ''];
$leaderboards = $report['leaderboards'] ?? [];
$agentsActivity = $report['agents_activity'] ?? [
    'pool_total_actions' => 0,
    'servers' => [],
    'kitchen' => [],
    'stock' => [],
    'cashiers' => [],
    'other_roles' => [],
];
$reportUiChunk = 18;
$autoClosed = $report['auto_closed_operations'] ?? [];
$cashTodaySnap = $cash_today_snapshot ?? null;
$pendingLateR = $pending_late_remittance_attributions ?? [];
$todayYmdRestaurant = $today_ymd_restaurant ?? ($report['selected_date'] ?? $date ?? '');
$salesByCategory = $report['sales_by_category'] ?? ['categories' => [], 'grand_total' => 0];
$serverShortfallRep = $report['server_remittance_shortfall'] ?? ['agents' => [], 'grand_shortfall' => 0];
$rptUid = (int) ($viewFilters['user_id'] ?? 0);
$rptUserQ = $rptUid > 0 ? '&user_id=' . rawurlencode((string) $rptUid) : '';
$ridQsa = ((current_user()['scope'] ?? null) === 'super_admin' && !empty($restaurant['id']))
    ? '&restaurant_id=' . rawurlencode((string) (int) $restaurant['id'])
    : '';
$dash_tab_extra_qs = $rptUserQ . $ridQsa;
$dash_tab_extra_qs .= '&date=' . rawurlencode((string) ($date ?? ''));
$dash_tab_extra_qs .= '&period=' . rawurlencode((string) ($period ?? 'daily'));
$regularizationBacklog = $regularization_backlog ?? [];
$module_today_pulse = $module_today_pulse ?? [];
$reportAgentFilterLocked = !empty($report_agent_filter_locked);
$reportHeavyLoaded = !empty($report['heavy_loaded'] ?? null) || !empty($report_heavy_loaded ?? null);
$activityDaySalesSnap = $report['activity_day_sales_snapshot'] ?? [
    'recorded_sales_count' => 0,
    'recorded_sales_total' => 0,
    'served_without_sale_count' => 0,
    'served_without_sale_total' => 0,
    'combined_activity_total' => 0,
];
$servedRequestsWithoutSale = $report['served_requests_without_sale'] ?? [];
?>
<style>
@media print {
    .no-print { display:none !important; }
    .card { box-shadow:none !important; border:1px solid #d6d6d6; }
    .report-section-details { border: none !important; }
    .report-section-details > summary { list-style: none; }
    .report-section-details > summary::-webkit-details-marker { display: none; }
    /* Impression : ouvrir le détail pour inclure le corps */
    .report-section-details { display: block !important; }
    .report-section-details > *:not(summary) { display: block !important; }
}
.report-detail-nested > details { margin-top:12px; }
.report-detail-nested summary { cursor:pointer; }
.report-section-details { padding: 18px 22px 22px; margin-bottom: 24px; border-radius: var(--radius-xl, 24px); border: 1px solid var(--line, rgba(212,175,55,0.14)); background: var(--panel, #171717); box-shadow: var(--shadow, 0 26px 60px rgba(0,0,0,0.42)); }
.report-section-details > summary {
    cursor: pointer;
    font-size: 1.05rem;
    font-family: Georgia, "Times New Roman", serif;
    color: #fff8e7;
    padding: 4px 0 12px;
    border-bottom: 1px solid rgba(255,255,255,0.06);
    margin-bottom: 14px;
    list-style: none;
}
.report-section-details > summary::-webkit-details-marker { display: none; }
.report-section-body { padding-top: 4px; }
.report-mobile-cards { display: none; }
@media (max-width: 720px) {
    .report-table-desktop { display: none !important; }
    .report-mobile-cards { display: grid !important; gap: 12px; }
    .report-agent-card {
        border: 1px solid rgba(255,255,255,0.08);
        border-radius: 14px;
        padding: 14px 16px;
        background: rgba(255,255,255,0.03);
        font-size: 0.95rem;
        line-height: 1.55;
    }
    .report-agent-card strong { color: var(--brand, #d4af37); }
}
.report-leader-grid {
    display: grid;
    gap: 14px;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    margin-top: 14px;
}
.report-leader-card {
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 14px;
    padding: 14px 16px;
    background: rgba(255,255,255,0.025);
}
#report-today > .grid.stats:first-of-type {
    display: none;
}
.voir-plus-btn {
    margin-top: 12px;
    padding: 10px 16px;
    border-radius: 999px;
    border: 1px solid rgba(212,175,55,0.35);
    background: transparent;
    color: var(--brand, #d4af37);
    cursor: pointer;
    font-weight: 600;
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-report-expand]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-report-expand');
            var target = id ? document.getElementById(id) : null;
            if (target) {
                target.hidden = false;
                btn.style.display = 'none';
            }
        });
    });
});
window.addEventListener('beforeprint', function () {
    document.querySelectorAll('.report-section-details').forEach(function (d) { d.open = true; });
});
</script>
<section class="topbar">
    <div class="brand">
        <h1><?= e($title ?? 'Rapport') ?></h1>
        <p>Suivi détaillé du stock, de la cuisine, des ventes, des pertes et des incidents sur une vraie période calendrier.</p>
    </div>
</section>
<?php
$module_nav_title = 'Navigation rapport';
$module_nav_intro = 'Journalier, hebdomadaire, mensuel, ventes, caisse, stock et agents restent dans le rapport.';
$module_nav_items = [
    ['label' => 'Aujourd hui', 'href' => '#report-today'],
    ['label' => 'Hebdo', 'href' => '#report-finance'],
    ['label' => 'Mensuel', 'href' => '#report-finance'],
    ['label' => 'Vente serveur', 'href' => '#report-sales'],
    ['label' => 'Vente article', 'href' => '#report-sales'],
    ['label' => 'Vente categorie', 'href' => '#report-sales'],
    ['label' => 'Caisse', 'href' => '#report-finance'],
    ['label' => 'Stock', 'href' => '#report-stock'],
    ['label' => 'Agents', 'href' => '#report-agents'],
];
require base_path('app/Views/partials/module_quick_nav.php');
?>

<?php if (!$reportHeavyLoaded): ?>
<section class="card no-print" style="padding:14px 16px; margin-bottom:16px;">
    <p class="muted" style="margin:0;">Vue rapide chargee pour eviter un blocage a l ouverture. Les blocs lourds equipe, timeline, detail cuisine-stock et controle stock se chargent a la demande : <a href="/rapport?heavy=1<?= e($dash_tab_extra_qs) ?>">ouvrir la version detaillee</a>.</p>
</section>
<?php endif; ?>

<?php require base_path('app/Views/partials/regularization_hold_banner.php'); ?>
<?php require base_path('app/Views/partials/operational_period_tabs.php'); ?>

<section class="card no-print" style="padding:16px 18px; margin-top:16px;">
    <strong>Changer la periode</strong>
    <div class="toolbar-actions" style="margin-top:12px; flex-wrap:wrap;">
        <a class="button-muted" href="/rapport?report_preset=today<?= e($rptUserQ . $ridQsa) ?>">Jour</a>
        <a class="button-muted" href="/rapport?report_preset=yesterday<?= e($rptUserQ . $ridQsa) ?>">Hier</a>
        <form method="get" action="/rapport" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <?php if ((current_user()['scope'] ?? null) === 'super_admin'): ?>
                <input type="hidden" name="restaurant_id" value="<?= e((string) $restaurant['id']) ?>">
            <?php endif; ?>
            <input type="hidden" name="period" value="daily">
            <input type="date" name="date" value="<?= e((string) ($report['selected_date'] ?? $date)) ?>">
            <button type="submit" class="button-muted">Date</button>
        </form>
        <a class="button-muted" href="/rapport?report_preset=week<?= e($rptUserQ . $ridQsa) ?>">Semaine</a>
        <a class="button-muted" href="/rapport?report_preset=month<?= e($rptUserQ . $ridQsa) ?>">Mois</a>
        <a class="button-muted" href="/rapport?period=annual&date=<?= e(rawurlencode((string) ($report['selected_date'] ?? $date))) ?><?= e($rptUserQ . $ridQsa) ?>">Tout</a>
    </div>
</section>

<?php
$simpleCash = is_array($cashClarity ?? null) ? $cashClarity : [];
$simpleSold = (float) ($simpleCash['sales_activity_total'] ?? ($report['general_report']['total_sold_value'] ?? 0));
$simplePaid = (float) ($simpleCash['server_remittance_total'] ?? ($report['financial_report']['summary']['total_remitted_to_cash'] ?? 0));
$simpleReceived = (float) ($simpleCash['cashier_received_sales'] ?? ($report['financial_report']['summary']['total_received_by_cash'] ?? 0));
$simpleExpected = $simpleSold;
$simpleNotPaid = max(0.0, (float) ($simpleCash['activity_gap_sales_vs_attributed_remittance'] ?? ($simpleSold - $simplePaid)));
$simpleLoss = (float) ($report['general_report']['total_losses_value'] ?? 0);
$simpleCashBalance = (float) ($simpleCash['cash_balance'] ?? ($report['financial_report']['summary']['cash_balance'] ?? 0));
?>
<section class="card" style="padding:22px; margin-top:16px; border-left:4px solid #0f766e;">
    <h2 style="margin:0 0 12px;">Resume simple</h2>
    <p class="muted" style="margin:0 0 12px;"><?= e((string) ($report['period_label'] ?? '')) ?></p>
    <div class="grid stats">
        <article class="card stat"><span>Montant vendu</span><strong><?= e(format_money($simpleSold, $restaurantCurrency)) ?></strong></article>
        <article class="card stat"><span>Montant verse</span><strong><?= e(format_money($simplePaid, $restaurantCurrency)) ?></strong></article>
        <article class="card stat"><span>Montant attendu</span><strong><?= e(format_money($simpleExpected, $restaurantCurrency)) ?></strong></article>
        <article class="card stat"><span>Montant non verse</span><strong><?= e(format_money($simpleNotPaid, $restaurantCurrency)) ?></strong></article>
        <article class="card stat"><span>Perte estimee</span><strong><?= e(format_money($simpleLoss, $restaurantCurrency)) ?></strong></article>
        <article class="card stat"><span>Solde caisse reel</span><strong><?= e(format_money($simpleCashBalance, $restaurantCurrency)) ?></strong></article>
    </div>
    <p style="margin:14px 0 0;"><a class="button-muted" href="#report-detail">Voir detail</a></p>
</section>

<?php if (can_access('staff.team_gauges.view')): ?>
<?php
$dSchedRep = is_array($discipline_work_schedule ?? null) ? $discipline_work_schedule : [];
$disciplinary_alerts = $disciplinary_alerts ?? [];
?>
<details class="card no-print" style="padding:12px 16px; margin-top:16px;" data-autoclose-details>
    <summary style="font-weight:600;">Alertes disciplinaires & horaires</summary>
    <p class="muted" style="margin:8px 0 10px; font-size:0.88rem;">
        Début <?= e((string) ($dSchedRep['work_start'] ?? '08:00')) ?> · tolérance <?= e((string) ($dSchedRep['arrival_grace_minutes'] ?? '15')) ?> min · versement max <?= e((string) ($dSchedRep['cash_deadline'] ?? '22:00')) ?>
        <?php if (!empty($dSchedRep['notice_unset'])): ?> <em>(défauts)</em><?php endif; ?>
    </p>
    <?php if (!$reportHeavyLoaded): ?>
        <p class="muted" style="margin:0; font-size:0.88rem;">Vue rapide : alertes equipe non chargees ici. Utilisez la version detaillee si besoin.</p>
    <?php elseif ($disciplinary_alerts !== []): ?>
        <?php require base_path('app/Views/partials/disciplinary_alerts_foldable.php'); ?>
    <?php else: ?>
        <p class="muted" style="margin:0; font-size:0.88rem;">Aucune alerte disciplinaire active pour le moment.</p>
    <?php endif; ?>
</details>
<?php endif; ?>

<?php if (!empty($cashTodaySnap)): ?>
<section class="card" id="report-today" style="padding:22px; margin-top:24px;">
    <div class="topbar" style="margin-bottom:14px;">
        <div>
            <h2 style="margin:0;">Situation actuelle / Aujourd’hui</h2>
            <p class="muted" style="margin:6px 0 0;"><?= e((string) ($cashTodaySnap['period_label'] ?? '')) ?> · calendrier restaurant</p>
        </div>
    </div>
    <div class="grid stats">
        <article class="card stat"><span>Vendu clôturé</span><strong><?= e(format_money((float) ($cashTodaySnap['total_sold_closed'] ?? 0), $restaurantCurrency)) ?></strong></article>
        <article class="card stat"><span>Servi non clôturé</span><strong><?= e(format_money((float) ($cashTodaySnap['served_without_sale_total_today'] ?? 0), $restaurantCurrency)) ?></strong></article>
        <article class="card stat"><span>Lecture activité</span><strong><?= e(format_money((float) ($cashTodaySnap['activity_day_sales_total_today'] ?? 0), $restaurantCurrency)) ?></strong></article>
        <article class="card stat"><span>Remis à caisse</span><strong><?= e(format_money((float) ($cashTodaySnap['remitted_to_cash_physical'] ?? 0), $restaurantCurrency)) ?></strong></article>
        <article class="card stat"><span>Reçu caisse</span><strong><?= e(format_money((float) ($cashTodaySnap['cashier_received_today'] ?? 0), $restaurantCurrency)) ?></strong></article>
        <article class="card stat"><span>Manquant</span><strong><?= e(format_money((float) ($cashTodaySnap['shortfall_today_total'] ?? 0), $restaurantCurrency)) ?></strong></article>
        <article class="card stat"><span>Remises rejetées</span><strong><?= e(format_money((float) ($cashTodaySnap['rejected_remittances_today'] ?? 0), $restaurantCurrency)) ?></strong></article>
        <article class="card stat"><span>Écart vendu − reçu</span><strong><?= e(format_money((float) ($cashTodaySnap['real_gap_sold_closed_minus_received'] ?? 0), $restaurantCurrency)) ?></strong></article>
        <article class="card stat"><span>Dépenses</span><strong><?= e(format_money((float) ($cashTodaySnap['expenses_today'] ?? 0), $restaurantCurrency)) ?></strong></article>
        <article class="card stat"><span>Solde caisse</span><strong><?= e(format_money((float) ($cashTodaySnap['cash_balance_current'] ?? 0), $restaurantCurrency)) ?></strong></article>
        <article class="card stat"><span>Écarts</span><strong><?= e(format_money((float) ($cashTodaySnap['discrepancies_today'] ?? 0), $restaurantCurrency)) ?></strong></article>
    </div>
    <?php $todayClarity = is_array($cashTodaySnap['cash_clarity_today'] ?? null) ? $cashTodaySnap['cash_clarity_today'] : []; ?>
    <?php
    $todaySalesActivity = (float) ($todayClarity['sales_activity_total'] ?? ($cashTodaySnap['activity_day_sales_total_today'] ?? 0));
    $todayReceivedForSales = (float) ($todayClarity['cashier_received_sales_attributed'] ?? 0);
    $todayMissingForSales = round($todaySalesActivity - $todayReceivedForSales, 2);
    $todayPreviousSalesReceipts = (float) ($todayClarity['remittance_from_previous_sales'] ?? 0);
    $todayCashBalance = (float) ($todayClarity['cash_balance'] ?? ($cashTodaySnap['cash_balance_current'] ?? 0));
    ?>
    <div class="grid stats">
        <article class="card stat"><span>Ventes du jour</span><strong><?= e(format_money($todaySalesActivity, $restaurantCurrency)) ?></strong></article>
        <article class="card stat"><span>Recu caisse pour ventes du jour</span><strong><?= e(format_money($todayReceivedForSales, $restaurantCurrency)) ?></strong></article>
        <article class="card stat"><span>Manquant ventes du jour</span><strong><?= e(format_money($todayMissingForSales, $restaurantCurrency)) ?></strong></article>
        <article class="card stat"><span>Encaissements recus aujourd hui pour anciennes ventes</span><strong><?= e(format_money($todayPreviousSalesReceipts, $restaurantCurrency)) ?></strong></article>
        <article class="card stat"><span>Solde caisse cumule</span><strong><?= e(format_money($todayCashBalance, $restaurantCurrency)) ?></strong></article>
    </div>
    <?php if ($todayClarity !== []): ?>
    <div class="compact-empty" style="margin-top:14px;">
        <strong>Lecture de cohÃ©rence</strong>
        <div style="margin-top:8px;">Les ventes gardent le jour dâ€™activitÃ©. Les remises et rÃ©ceptions caisse gardent leur date opÃ©rationnelle propre.</div>
        <?php if (!empty($todayClarity['explanations']) && is_array($todayClarity['explanations'])): ?>
            <ul style="margin:8px 0 0; padding-left:18px; line-height:1.6;">
                <?php foreach ($todayClarity['explanations'] as $explanation): ?>
                    <?php if (!is_array($explanation)) { continue; } ?>
                    <li><?= e((string) ($explanation['label'] ?? 'Explication')) ?> : <?= e(format_money((float) ($explanation['amount'] ?? 0), $restaurantCurrency)) ?><?php if (!empty($explanation['note'])): ?> Â· <?= e((string) $explanation['note']) ?><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php $sfd = $cashTodaySnap['server_shortfall']['agents'] ?? []; ?>
    <?php if (array_filter($sfd, static fn (array $a): bool => ((float) ($a['shortfall'] ?? 0)) > 0.0001)): ?>
    <details class="compact-card no-print" style="margin-top:14px;" data-autoclose-details>
        <summary><strong>Manquants serveurs</strong> (aujourd’hui)</summary>
        <?php foreach ($sfd as $ag): ?>
            <?php if ((float) ($ag['shortfall'] ?? 0) <= 0.0001) { continue; } ?>
            <div class="report-agent-card" style="margin-top:12px;">
                <strong><?= e(named_actor_label($ag['server_name'] ?? null, 'cashier_server')) ?></strong>
                <p class="muted" style="margin:6px 0 0;">Non versé : <?= e(format_money((float) ($ag['shortfall'] ?? 0), $restaurantCurrency)) ?></p>
                <?php foreach (($ag['missing_sales'] ?? []) as $ms): ?>
                    <p style="margin:8px 0 4px;">Vente #<?= e((string) ($ms['sale_id'] ?? '')) ?></p>
                    <ul style="margin:0; padding-left:18px;">
                        <?php foreach (($ms['lines'] ?? []) as $ln): ?>
                            <li><?= e((string) ($ln['menu_item_name'] ?? '')) ?> × <?= e((string) ($ln['quantity'] ?? '')) ?> : <?= e(format_money((float) ($ln['line_total'] ?? 0), $restaurantCurrency)) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </details>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if (!empty($activityDaySalesSnap['served_without_sale_count'])): ?>
<section class="card" id="report-sales" style="padding:18px; margin-top:16px;">
    <h3 style="margin:0 0 8px;">Lecture métier · ventes du jour d’activité</h3>
    <p class="muted" style="margin:0 0 14px;">Option A retenue: on montre les demandes réellement servies sans vente liée à côté des ventes enregistrées, sans créer de vente comptable automatiquement et sans redéduire le stock.</p>
    <div class="grid stats">
        <article class="card stat"><span>Ventes enregistrées</span><strong><?= e(format_money((float) ($activityDaySalesSnap['recorded_sales_total'] ?? 0), $restaurantCurrency)) ?></strong></article>
        <article class="card stat"><span>Servi sans vente liée</span><strong><?= e(format_money((float) ($activityDaySalesSnap['served_without_sale_total'] ?? 0), $restaurantCurrency)) ?></strong></article>
        <article class="card stat"><span>Total lecture activité</span><strong><?= e(format_money((float) ($activityDaySalesSnap['combined_activity_total'] ?? 0), $restaurantCurrency)) ?></strong></article>
    </div>
    <details class="compact-card" style="margin-top:14px;" data-autoclose-details>
        <summary><strong>Dossiers servis sans vente liée</strong> · <?= e((string) (int) ($activityDaySalesSnap['served_without_sale_count'] ?? 0)) ?></summary>
        <?php foreach ($servedRequestsWithoutSale as $row): ?>
            <article class="card" style="padding:12px; margin-top:10px;" data-operation-focus="server_request:<?= e((string) ($row['request_id'] ?? '0')) ?>">
                <strong>Demande #<?= e((string) ($row['request_id'] ?? '')) ?></strong>
                <?php if (!empty($row['service_reference'])): ?> · <?= e((string) $row['service_reference']) ?><?php endif; ?>
                <span class="muted"> · <?= e(named_actor_label($row['server_name'] ?? null, 'cashier_server')) ?></span>
                <p class="muted" style="margin:6px 0 0;">Activité <?= e(format_date_fr($row['activity_at'] ?? null, $reportTimezone)) ?> · Statut <?= e(service_flow_status_label((string) ($row['status'] ?? ''))) ?> · Montant lecture <?= e(format_money((float) ($row['total_virtual_sold_amount'] ?? 0), $restaurantCurrency)) ?></p>
                <ul style="margin:8px 0 0; padding-left:18px;">
                    <?php foreach (($row['lines'] ?? []) as $ln): ?>
                        <li><?= e((string) ($ln['menu_item_name'] ?? '')) ?> × <?= e((string) ($ln['sold_quantity'] ?? '0')) ?> : <?= e(format_money((float) ($ln['line_total'] ?? 0), $restaurantCurrency)) ?></li>
                    <?php endforeach; ?>
                </ul>
            </article>
        <?php endforeach; ?>
    </details>
</section>
<?php endif; ?>

<?php
$rbSum = (int) (($regularizationBacklog['overdue_server_remis_serveur'] ?? 0) + ($regularizationBacklog['overdue_remis_a_caisse'] ?? 0) + ($regularizationBacklog['overdue_kitchen_production_returns'] ?? 0));
$mPulse = $module_today_pulse ?? [];
?>
<?php if ($rbSum > 0): ?>
<section class="card no-print" style="padding:18px; margin-top:16px; border-left:4px solid #f59e0b;">
    <h3 style="margin:0 0 8px;">File « à régulariser »</h3>
    <p class="muted" style="margin:0 0 10px;">Pas de conversion automatique en vente ou réception caisse. Traiter manuellement les cas listés dans les modules concernés.</p>
    <ul style="margin:0; padding-left:20px;">
        <li>Clôtures service en retard : <?= e((string) (int) ($regularizationBacklog['overdue_server_remis_serveur'] ?? 0)) ?></li>
        <li>Remises en attente caisse (veille+) : <?= e((string) (int) ($regularizationBacklog['overdue_remis_a_caisse'] ?? 0)) ?></li>
        <li>Retours cuisine provisoires &gt; 24h : <?= e((string) (int) ($regularizationBacklog['overdue_kitchen_production_returns'] ?? 0)) ?></li>
    </ul>
</section>
<?php endif; ?>

<?php if ($mPulse !== []): ?>
<section class="card" style="padding:18px; margin-top:16px;">
    <h3 style="margin:0 0 8px;">Activité opérationnelle (aperçu)</h3>
    <p class="muted" style="margin:0 0 12px;"><?= e((string) ($mPulse['period_label'] ?? '')) ?><?php if (!empty($mPulse['range_start_ymd']) && !empty($mPulse['range_end_ymd'])): ?> · <?= e((string) $mPulse['range_start_ymd']) ?> → <?= e((string) $mPulse['range_end_ymd']) ?><?php endif; ?></p>
    <div class="grid stats">
        <?php if (empty($mPulse['hide_sales_closure_kpis'])): ?>
        <article class="card stat"><span>Ventes clôturées</span><strong><?= e((string) (int) ($mPulse['sales_closed_count_today'] ?? 0)) ?></strong></article>
        <?php endif; ?>
        <article class="card stat"><span>Servi non clôturé</span><strong><?= e(format_money((float) ($mPulse['served_without_sale_total_today'] ?? 0), $restaurantCurrency)) ?></strong></article>
        <article class="card stat"><span>Stock (mouv.)</span><strong><?= e((string) (int) ($mPulse['stock_movements_count_today'] ?? 0)) ?></strong></article>
        <article class="card stat"><span>Cuisine (prod.)</span><strong><?= e((string) (int) ($mPulse['kitchen_production_count_today'] ?? 0)) ?></strong></article>
        <article class="card stat"><span>Service (file)</span><strong><?= e((string) (int) ($mPulse['open_service_requests'] ?? 0)) ?></strong></article>
    </div>
    <?php if (empty($mPulse['include_live_queues'])): ?>
        <p class="muted" style="margin:10px 0 0;">File service « en direct » : uniquement si la période sélectionnée inclut aujourd’hui.</p>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php require base_path('app/Views/partials/stock_control_report_section.php'); ?>

<?php if ($rptUid > 0): ?>
<section class="card no-print" style="padding:18px; margin-top:16px;">
    <details class="compact-card" open data-autoclose-details>
        <summary><strong>Filtre agent</strong> : aperçu rapide des périodes</summary>
        <p class="muted" style="margin-top:10px;">Le tableau du bas reflète la date et la période choisies dans les filtres. Raccourcis ci-dessous conservent l’agent #<?= e((string) $rptUid) ?>.</p>
        <div class="nav" style="flex-wrap:wrap; margin-top:10px;">
            <a href="/rapport?report_preset=today<?= e($rptUserQ . $ridQsa) ?>">Aujourd’hui</a>
            <a href="/rapport?report_preset=yesterday<?= e($rptUserQ . $ridQsa) ?>">Hier</a>
            <a href="/rapport?report_preset=week<?= e($rptUserQ . $ridQsa) ?>">Semaine</a>
            <a href="/rapport?report_preset=month<?= e($rptUserQ . $ridQsa) ?>">Mois</a>
        </div>
    </details>
</section>
<?php endif; ?>

<?php
$reportCanDecideLateRemittance = in_array((string) (current_user()['role_code'] ?? ''), ['owner', 'manager'], true);
?>
<?php if ($pendingLateR !== [] && $reportCanDecideLateRemittance): ?>
<section class="card no-print" style="padding:18px; margin-top:16px;">
    <h3 style="margin-top:0;">Remise tardive à décider</h3>
    <?php foreach ($pendingLateR as $lat): ?>
        <article class="card" style="padding:12px; margin-top:10px;">
            <strong>#<?= e((string) ($lat['id'] ?? '')) ?></strong> · <?= e(format_money((float) ($lat['amount'] ?? 0), $restaurantCurrency)) ?> · Vente #<?= e((string) ($lat['sale_id'] ?? '')) ?>
            <p class="muted" style="margin:6px 0 0;">Serveur : <?= e(named_actor_label($lat['sale_server_name'] ?? $lat['from_user_name'] ?? null, 'cashier_server')) ?></p>
            <p class="muted" style="margin:6px 0 0;">Vente : <?= e((string) ($lat['sale_day_ymd'] ?? '—')) ?> · Remise : <?= e((string) ($lat['remittance_day_ymd'] ?? '—')) ?></p>
            <form method="post" action="/owner/caisse/remises-tardives/<?= e((string) ($lat['id'] ?? '0')) ?>/rattachement" style="display:inline;" onsubmit="return confirm('Confirmer ?');"><?php /* owner route — session gère le restaurant */ ?>
                <input type="hidden" name="basis" value="SALE_DAY"><button type="submit" class="button-muted">Jour de vente</button>
            </form>
            <form method="post" action="/owner/caisse/remises-tardives/<?= e((string) ($lat['id'] ?? '0')) ?>/rattachement" style="display:inline; margin-left:8px;" onsubmit="return confirm('Confirmer ?');">
                <input type="hidden" name="basis" value="REMITTANCE_DAY"><button type="submit" class="button-muted">Jour de remise</button>
            </form>
            <form method="post" action="/owner/caisse/remises-tardives/<?= e((string) ($lat['id'] ?? '0')) ?>/rattachement" style="display:inline; margin-left:8px;" onsubmit="return confirm('Confirmer ?');">
                <input type="hidden" name="basis" value="RESOLUTION_DAY"><button type="submit" class="button-muted">Jour de résolution</button>
            </form>
        </article>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<section class="card no-print" style="padding:0; margin-top:20px;">
    <details class="report-section-details" open>
        <summary><strong>Statistiques</strong> · ventes / caisse / stock / pertes / manquants / personnel</summary>
        <div class="report-section-body">
            <div class="toolbar-actions" style="flex-wrap:wrap; margin-bottom:14px;">
                <a class="button-muted" href="#stats-ventes">Ventes</a>
                <a class="button-muted" href="#stats-caisse">Caisse</a>
                <a class="button-muted" href="#stats-stock">Stock</a>
                <a class="button-muted" href="#stats-pertes">Pertes</a>
                <a class="button-muted" href="#stats-manquants">Manquants</a>
                <a class="button-muted" href="#stats-personnel">Personnel</a>
            </div>
            <div class="grid stats">
                <article class="card stat" id="stats-ventes"><span>Evolution ventes</span><strong><?= e(format_money($simpleSold, $restaurantCurrency)) ?></strong></article>
                <article class="card stat" id="stats-caisse"><span>Evolution versements</span><strong><?= e(format_money($simplePaid, $restaurantCurrency)) ?></strong></article>
                <article class="card stat" id="stats-stock"><span>Valeur stock</span><strong><?= e(format_money((float) ($report['stock_report']['stock_value'] ?? 0), $restaurantCurrency)) ?></strong></article>
                <article class="card stat" id="stats-pertes"><span>Evolution pertes</span><strong><?= e(format_money($simpleLoss, $restaurantCurrency)) ?></strong></article>
                <article class="card stat" id="stats-manquants"><span>Manquants</span><strong><?= e(format_money($simpleNotPaid, $restaurantCurrency)) ?></strong></article>
                <article class="card stat" id="stats-personnel"><span>Top serveur</span><strong><?= e((string) (($leaderboards['day']['best_server']['server_name'] ?? '') ?: 'Aucun')) ?></strong></article>
            </div>
            <?php $topCategory = $salesByCategory['categories'][0] ?? []; ?>
            <div class="grid stats" style="margin-top:12px;">
                <article class="card stat"><span>Categorie rentable</span><strong><?= e((string) (($topCategory['category_name'] ?? '') ?: 'A verifier')) ?></strong></article>
                <article class="card stat"><span>Categorie a risque</span><strong><?= e(((float) ($report['stock_report']['stock_losses_value'] ?? 0) > 0) ? 'Stock avec pertes' : 'Aucune perte stock') ?></strong></article>
                <article class="card stat"><span>Jour / semaine / mois</span><strong><?= e((string) ($report['period_label'] ?? '')) ?></strong></article>
            </div>
        </div>
    </details>
</section>

<details id="report-detail" class="card report-section-details no-print" style="padding:0; margin-top:20px;">
<summary><strong>Historique / Vue globale (détail période)</strong> · <?= e((string) ($report['period_label'] ?? '')) ?></summary>
<div class="report-section-body" style="padding:16px;">

<?php if (!empty($report['financial_report']['summary'] ?? [])): ?>
<section class="card" id="report-finance" style="padding:0; margin-top:24px;">
<details class="report-section-details" id="report-agents">
        <summary><strong>Caisse</strong> — synthèse financière et remises</summary>
        <div class="report-section-body">
        <?php if (!empty($cashClarity)): ?>
            <p class="muted" style="margin-top:0;">Convention affichée : entrées +, sorties − (montants tels qu’enregistrés sur la période <?= e(($cashClarity['period_from'] ?? '') . ' → ' . ($cashClarity['period_to'] ?? '')) ?>).</p>
            <ul style="margin:0; padding-left:20px; line-height:1.7;">
                <li><strong>Versé par les serveurs</strong> (remises vente) : + <?= e(format_money($cashClarity['server_remittance_total'] ?? 0, $restaurantCurrency)) ?></li>
                <li><strong>Total reçu par la caisse</strong> (ventes confirmées / écarts signalés) : + <?= e(format_money($cashClarity['cashier_received_sales'] ?? 0, $restaurantCurrency)) ?></li>
                <li><strong>Total remis au gérant</strong> (remise caisse) : − <?= e(format_money($cashClarity['declared_to_manager'] ?? 0, $restaurantCurrency)) ?></li>
                <li><strong>Total reçu par le gérant</strong> : + <?= e(format_money($cashClarity['manager_received'] ?? 0, $restaurantCurrency)) ?></li>
                <li><strong>Total remis au propriétaire</strong> : − <?= e(format_money($cashClarity['declared_to_owner'] ?? 0, $restaurantCurrency)) ?></li>
                <li><strong>Total reçu par le propriétaire</strong> : + <?= e(format_money($cashClarity['owner_received'] ?? 0, $restaurantCurrency)) ?></li>
                <li><strong>Écarts signalés</strong> (somme des écarts) : <?= e(format_money($cashClarity['discrepancy_total'] ?? 0, $restaurantCurrency)) ?></li>
                <li><strong>Solde caisse courant</strong> (après entrées / sorties enregistrées sur la plage filtre caisse du module résumé) : <?= e(format_money($cashClarity['cash_balance'] ?? 0, $restaurantCurrency)) ?></li>
                <li><strong>Solde gérant sur la période</strong> (reçu − déclaré vers propriétaire) : <?= e(format_money($cashClarity['manager_net_period'] ?? 0, $restaurantCurrency)) ?></li>
            </ul>
        <?php endif; ?>
        <p style="margin-top:16px; margin-bottom:0;"><strong>Rapport financier</strong> (résumé module)</p>
        <p><strong>Total remis a caisse</strong> : <?= e(format_money($report['financial_report']['summary']['total_remitted_to_cash'] ?? 0, $restaurantCurrency)) ?></p>
        <p><strong>Total recu caisse</strong> : <?= e(format_money($report['financial_report']['summary']['total_received_by_cash'] ?? 0, $restaurantCurrency)) ?></p>
        <p><strong>Depenses caisse</strong> : <?= e(format_money($report['financial_report']['summary']['cash_expenses'] ?? 0, $restaurantCurrency)) ?></p>
        <p><strong>Solde caisse</strong> : <?= e(format_money($report['financial_report']['summary']['cash_balance'] ?? 0, $restaurantCurrency)) ?></p>
        <p><strong>Remises caisse vers gerant</strong> : <?= e(format_money($report['financial_report']['summary']['transferred_to_manager'] ?? 0, $restaurantCurrency)) ?></p>
        <p><strong>Remises gerant vers proprietaire</strong> : <?= e(format_money($report['financial_report']['summary']['transferred_to_owner'] ?? 0, $restaurantCurrency)) ?></p>
        <p><strong>Ecarts signales</strong> : <?= e(format_money($report['financial_report']['summary']['discrepancies'] ?? 0, $restaurantCurrency)) ?></p>
        <?php if (($report['financial_report']['remittances_by_server'] ?? []) !== []): ?>
            <div class="table-wrap" style="margin-top:14px;">
                <table>
                    <thead><tr><th>Serveur</th><th>Remises</th><th>Total</th></tr></thead>
                    <tbody>
                    <?php foreach ($report['financial_report']['remittances_by_server'] as $row): ?>
                        <tr>
                            <td><?= e(named_actor_label($row['server_name'] ?? null, 'cashier_server')) ?></td>
                            <td><?= e((string) ($row['transfer_count'] ?? 0)) ?></td>
                            <td><?= e(format_money($row['total_amount'] ?? 0, $restaurantCurrency)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php if (($report['financial_report']['sale_remittance_details'] ?? []) !== []): ?>
            <div class="table-wrap" style="margin-top:14px;">
                <table>
                    <thead><tr><th>Vente cloturee</th><th>Serveur</th><th>Remise serveur</th><th>Reception caisse</th><th>Ecart</th></tr></thead>
                    <tbody>
                    <?php foreach ($report['financial_report']['sale_remittance_details'] as $row): ?>
                        <tr>
                            <td>
                                <?php if (!empty($row['sale_id'])): ?>
                                    <strong>#<?= e((string) $row['sale_id']) ?></strong>
                                    <br><span class="muted"><?= e(format_money($row['sale_total_amount'] ?? 0, $restaurantCurrency)) ?></span>
                                    <?php if (!empty($row['server_request_id'])): ?>
                                        <br><span class="muted">Demande #<?= e((string) $row['server_request_id']) ?> - <?= e((string) ($row['service_reference'] ?? '-')) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="muted">Aucune</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e(named_actor_label($row['sale_server_name'] ?? $row['from_user_name'] ?? null, 'cashier_server')) ?></td>
                            <td><?= e(format_date_fr($row['requested_at'] ?? $row['created_at'] ?? null, $reportTimezone)) ?><br><span class="muted"><?= e(format_money($row['amount'] ?? 0, $restaurantCurrency)) ?></span></td>
                            <td><?= e(($row['status'] ?? '') === 'REMIS_A_CAISSE' ? 'En attente de caisse' : cash_transfer_status_label($row['status'] ?? null)) ?><?php if (!empty($row['received_at'])): ?><br><span class="muted"><?= e(format_date_fr($row['received_at'], $reportTimezone)) ?></span><?php endif; ?></td>
                            <td><?= e(format_money($row['discrepancy_amount'] ?? 0, $restaurantCurrency)) ?><?php if (!empty($row['discrepancy_note'])): ?><br><span class="muted"><?= e((string) $row['discrepancy_note']) ?></span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        </div>
    </details>
</section>
<?php endif; ?>
<section class="card" style="padding:18px; margin-bottom:24px;">
    <div class="menu-thumb">
        <img src="<?= e($restaurantLogo) ?>" alt="Logo restaurant">
        <div>
            <strong><?= e($restaurant['public_name'] ?? $restaurant['name'] ?? 'Restaurant') ?></strong><br>
            <span class="muted">Logo visible dans les rapports avec fallback propre si aucun visuel n est defini.</span>
        </div>
    </div>
</section>
<section class="card no-print" style="padding:18px; margin-bottom:24px;">
    <div class="toolbar-actions">
        <button type="button" onclick="window.print()">Imprimer</button>
        <a href="/rapport?<?= e($printQuery) ?>" class="button-muted" target="_blank" rel="noopener noreferrer">Export imprimable / PDF navigateur</a>
    </div>
</section>

<section class="card" style="padding:22px; margin-bottom:24px;">
    <details class="compact-card" data-autoclose-details>
    <summary><strong>Afficher les filtres</strong></summary>
    <details class="compact-card" style="margin-top:12px;" data-autoclose-details>
        <summary><strong>Raccourcis période (ventes / rapport global)</strong></summary>
        <p class="muted" style="margin-top:10px;">Aujourd’hui, hier, semaine ou mois calendaire (fuseau restaurant) sans ressaisir la date.</p>
        <div class="nav" style="flex-wrap:wrap; margin-top:10px;">
            <a href="/rapport?report_preset=today<?= e($rptUserQ . $ridQsa) ?>">Aujourd’hui</a>
            <a href="/rapport?report_preset=yesterday<?= e($rptUserQ . $ridQsa) ?>">Hier</a>
            <a href="/rapport?report_preset=week<?= e($rptUserQ . $ridQsa) ?>">Semaine en cours</a>
            <a href="/rapport?report_preset=month<?= e($rptUserQ . $ridQsa) ?>">Mois en cours</a>
            <a href="/rapport?report_preset=year<?= e($rptUserQ . $ridQsa) ?>">AnnÃ©e en cours</a>
        </div>
    </details>
    <form method="get" action="/rapport" style="margin-top:14px;">
        <?php if ((current_user()['scope'] ?? null) === 'super_admin'): ?>
            <input type="hidden" name="restaurant_id" value="<?= e((string) $restaurant['id']) ?>">
        <?php endif; ?>
        <label>Date du rapport</label>
        <input type="date" name="date" value="<?= e($report['selected_date'] ?? $date) ?>">
        <label>Période</label>
        <select name="period">
            <option value="daily" <?= ($period ?? 'daily') === 'daily' ? 'selected' : '' ?>>Journalier</option>
            <option value="weekly" <?= ($period ?? 'daily') === 'weekly' ? 'selected' : '' ?>>Hebdomadaire</option>
            <option value="monthly" <?= ($period ?? 'daily') === 'monthly' ? 'selected' : '' ?>>Mensuel</option>
            <option value="annual" <?= ($period ?? 'daily') === 'annual' ? 'selected' : '' ?>>Annuel</option>
        </select>
        <label>Agent</label>
        <?php if ($reportAgentFilterLocked): ?>
            <input type="hidden" name="user_id" value="<?= e((string) (int) ($viewFilters['user_id'] ?? 0)) ?>">
            <select aria-label="Agent (fixé)"><?php /* option unique — compte connecté */ ?>
                <?php foreach (($report_users ?? []) as $ru): ?>
                    <option selected><?= e(named_actor_label($ru['full_name'] ?? null, $ru['role_code'] ?? null)) ?></option>
                <?php endforeach; ?>
            </select>
        <?php else: ?>
        <select name="user_id">
            <option value="0">Tous</option>
            <?php foreach (($report_users ?? []) as $ru): ?>
                <option value="<?= e((string) $ru['id']) ?>" <?= (int) ($viewFilters['user_id'] ?? 0) === (int) $ru['id'] ? 'selected' : '' ?>><?= e(named_actor_label($ru['full_name'] ?? null, $ru['role_code'] ?? null)) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <label>Rôle</label>
        <select name="role_code">
            <option value="">Tous</option>
            <?php foreach (($report_role_codes ?? []) as $rc): ?>
                <option value="<?= e($rc) ?>" <?= (($viewFilters['role_code'] ?? '') === $rc) ? 'selected' : '' ?>><?= e(restaurant_role_label($rc)) ?></option>
            <?php endforeach; ?>
        </select>
        <label>Périmètre</label>
        <select name="action_scope">
            <option value="all" <?= (($viewFilters['action_scope'] ?? 'all') === 'all') ? 'selected' : '' ?>>Tout</option>
            <option value="sales" <?= (($viewFilters['action_scope'] ?? '') === 'sales') ? 'selected' : '' ?>>Ventes</option>
            <option value="cash" <?= (($viewFilters['action_scope'] ?? '') === 'cash') ? 'selected' : '' ?>>Caisse</option>
            <option value="stock" <?= (($viewFilters['action_scope'] ?? '') === 'stock') ? 'selected' : '' ?>>Stock</option>
            <option value="kitchen" <?= (($viewFilters['action_scope'] ?? '') === 'kitchen') ? 'selected' : '' ?>>Cuisine</option>
        </select>
        <label>Type d’action (journal)</label>
        <input type="text" name="action_name" value="<?= e((string) ($viewFilters['action_name'] ?? '')) ?>" placeholder="ex. vente enregistrée, remise caisse">
        <label style="display:flex; align-items:center; gap:8px; margin-top:10px;">
            <input type="checkbox" name="closed_sales_only" value="1" <?= !empty($viewFilters['closed_sales_only']) ? 'checked' : '' ?>>
            Ventes clôturées uniquement (totaux ventes par serveur)
        </label>
        <label>Produit (carte / ventes)</label>
        <select name="menu_item_id">
            <option value="0">Tous les produits</option>
            <?php foreach (($report_menu_items ?? []) as $mi): ?>
                <option value="<?= e((string) $mi['id']) ?>" <?= (int) ($viewFilters['menu_item_id'] ?? 0) === (int) $mi['id'] ? 'selected' : '' ?>><?= e((string) ($mi['name'] ?? '')) ?></option>
            <?php endforeach; ?>
        </select>
        <label>Article stock (mouvements)</label>
        <select name="stock_item_id">
            <option value="0">Tous les articles</option>
            <?php foreach (($report_stock_items ?? []) as $sti): ?>
                <option value="<?= e((string) $sti['id']) ?>" <?= (int) ($viewFilters['stock_item_id'] ?? 0) === (int) $sti['id'] ? 'selected' : '' ?>><?= e((string) ($sti['name'] ?? '')) ?></option>
            <?php endforeach; ?>
        </select>
        <label>Type de mouvement stock</label>
        <select name="stock_movement_type">
            <option value="" <?= (($viewFilters['stock_movement_type'] ?? '') === '') ? 'selected' : '' ?>>Tous</option>
            <option value="ENTREE" <?= (($viewFilters['stock_movement_type'] ?? '') === 'ENTREE') ? 'selected' : '' ?>>Entrée</option>
            <option value="SORTIE_CUISINE" <?= (($viewFilters['stock_movement_type'] ?? '') === 'SORTIE_CUISINE') ? 'selected' : '' ?>>Sortie cuisine</option>
            <option value="SORTIE" <?= (($viewFilters['stock_movement_type'] ?? '') === 'SORTIE') ? 'selected' : '' ?>>Sortie</option>
            <option value="PERTE" <?= (($viewFilters['stock_movement_type'] ?? '') === 'PERTE') ? 'selected' : '' ?>>Perte</option>
            <option value="RETOUR_STOCK" <?= (($viewFilters['stock_movement_type'] ?? '') === 'RETOUR_STOCK') ? 'selected' : '' ?>>Retour stock</option>
        </select>
        <label>Recherche article (résumé / détail ventes)</label>
        <input type="text" name="article_search" value="<?= e((string) ($viewFilters['article_search'] ?? '')) ?>" placeholder="Ex. poulet, jus…">
        <label>Recherche agent (activité agents)</label>
        <input type="text" name="activity_agent_search" value="<?= e((string) ($viewFilters['activity_agent_search'] ?? '')) ?>" placeholder="Prénom ou rôle">
        <label>Recherche historique nominatif</label>
        <input type="text" name="timeline_actor_search" value="<?= e((string) ($viewFilters['timeline_actor_search'] ?? '')) ?>" placeholder="Nom ou mot dans le détail">
        <?php $tlLim = (int) ($viewFilters['timeline_limit'] ?? 350); ?>
        <label>Lignes max historique</label>
        <select name="timeline_limit">
            <option value="350" <?= $tlLim <= 350 ? 'selected' : '' ?>>350</option>
            <option value="600" <?= $tlLim > 350 && $tlLim <= 600 ? 'selected' : '' ?>>600</option>
            <option value="900" <?= $tlLim > 600 ? 'selected' : '' ?>>900</option>
        </select>
        <div style="margin-top:14px;"><button type="submit">Afficher</button></div>
    </form>
    </details>
    <p class="muted" style="margin-bottom:0;"><?= e($report['period_label'] ?? '') ?> · du <?= e(format_date_fr($report['range_start'] ?? null, $reportTimezone)) ?> au <?= e(format_date_fr($report['range_end'] ?? null, $reportTimezone)) ?> · Fuseau <?= e($report['timezone'] ?? $reportTimezone->getName()) ?></p>
</section>

<?php if (!empty($salesByCategory['categories'] ?? [])): ?>
<section class="card" style="padding:0; margin-bottom:24px;">
<details class="report-section-details" id="report-stock">
        <summary><strong>Ventes par catégorie</strong> · <?= e(format_money((float) ($salesByCategory['grand_total'] ?? 0), $restaurantCurrency)) ?> sur la période filtrée</summary>
        <div class="report-section-body">
            <div class="table-wrap" style="margin-top:8px;">
                <table>
                    <thead><tr><th>Catégorie</th><th>Quantité</th><th>Total</th><th>%</th><th>Top article</th></tr></thead>
                    <tbody>
                    <?php foreach (($salesByCategory['categories'] ?? []) as $cr): ?>
                        <tr>
                            <td><?= e((string) ($cr['category_name'] ?? '')) ?></td>
                            <td><?= e((string) ($cr['quantity_total'] ?? 0)) ?></td>
                            <td><?= e(format_money((float) ($cr['total_amount'] ?? 0), $restaurantCurrency)) ?></td>
                            <td><?= e((string) ($cr['pct_of_grand'] ?? 0)) ?> %</td>
                            <td><?= e((string) ($cr['top_item_name'] ?? '—')) ?> <?php if ((float) ($cr['top_item_qty'] ?? 0) > 0): ?>(× <?= e((string) ($cr['top_item_qty'] ?? '')) ?>)<?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </details>
</section>
<?php endif; ?>

<?php if ((float) ($serverShortfallRep['grand_shortfall'] ?? 0) > 0.0001): ?>
<section class="card" style="padding:0; margin-bottom:24px;">
    <details class="report-section-details">
        <summary><strong>Non versé / manquant (période filtrée)</strong> · <?= e(format_money((float) ($serverShortfallRep['grand_shortfall'] ?? 0), $restaurantCurrency)) ?></summary>
        <div class="report-section-body">
            <?php foreach (($serverShortfallRep['agents'] ?? []) as $ag): ?>
                <?php if ((float) ($ag['shortfall'] ?? 0) <= 0.0001) { continue; } ?>
                <div class="report-agent-card" style="margin-top:12px;">
                    <strong><?= e(named_actor_label($ag['server_name'] ?? null, 'cashier_server')) ?></strong>
                    <p class="muted" style="margin:6px 0 0;">Vendu clôturé <?= e(format_money((float) ($ag['sold_closed'] ?? 0), $restaurantCurrency)) ?> · Remis effectif <?= e(format_money((float) ($ag['remitted_effective'] ?? 0), $restaurantCurrency)) ?> · Manquant <?= e(format_money((float) ($ag['shortfall'] ?? 0), $restaurantCurrency)) ?></p>
                    <?php foreach (($ag['missing_sales'] ?? []) as $ms): ?>
                        <p style="margin:8px 0 4px;">Vente #<?= e((string) ($ms['sale_id'] ?? '')) ?></p>
                        <ul style="margin:0; padding-left:18px;">
                            <?php foreach (($ms['lines'] ?? []) as $ln): ?>
                                <li><?= e((string) ($ln['menu_item_name'] ?? '')) ?> × <?= e((string) ($ln['quantity'] ?? '')) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </details>
</section>
<?php endif; ?>

<section class="card no-print" style="padding:0;">
    <details class="report-section-details">
        <summary><strong>Meilleurs indicateurs</strong> · jour / semaine / mois (calendaires autour de la date du rapport)</summary>
        <div class="report-section-body">
            <p class="muted" style="margin-top:0;">Critères : serveur classé par montant total vendu ; produit par montant sur les ventes prises en compte dans le rapport.</p>
            <div class="report-leader-grid">
                <?php foreach (['day' => 'Jour', 'week' => 'Semaine', 'month' => 'Mois'] as $lk => $lab): ?>
                    <?php $slice = $leaderboards[$lk] ?? []; $bs = $slice['best_server'] ?? []; $bp = $slice['best_product'] ?? []; ?>
                    <div class="report-leader-card">
                        <strong><?= e($lab) ?></strong>
                        <p class="muted" style="margin:8px 0 6px;"><?= e((string) ($slice['period_label'] ?? '')) ?></p>
                        <p style="margin:0;">
                            <?php if (($bs['server_name'] ?? '') !== ''): ?>
                                Meilleur serveur : <strong><?= e((string) $bs['server_name']) ?></strong>
                                — <?= e((string) (int) ($bs['sales_count'] ?? 0)) ?> ventes
                                — <?= e(format_money((float) ($bs['total_amount'] ?? 0), $restaurantCurrency)) ?>
                            <?php else: ?>
                                <span class="muted">Aucun serveur sur cette plage.</span>
                            <?php endif; ?>
                        </p>
                        <p style="margin:12px 0 0;">
                            <?php if (($bp['product_name'] ?? '') !== ''): ?>
                                Produit le plus vendu : <strong><?= e((string) $bp['product_name']) ?></strong>
                                — <?= e((string) $bp['qty_sold']) ?> pièces — <?= e(format_money((float) ($bp['total_sold'] ?? 0), $restaurantCurrency)) ?>
                                <?php if (($bp['category_name'] ?? '') !== ''): ?>
                                    <span class="muted"> · <?= e((string) $bp['category_name']) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="muted">Aucun produit sur cette plage.</span>
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </details>
</section>

<section class="card" style="padding:0; margin-bottom:24px;">
    <details class="report-section-details">
        <summary><strong>Synthèse équipe</strong> · ventes / cuisine / stock / caisse</summary>
        <div class="report-section-body">
    <p class="muted">Répartition sur la période sélectionnée (les filtres utilisateur / rôle s’appliquent aux blocs cuisine, stock et caisse ; les ventes par serveur suivent aussi le filtre « ventes clôturées »).</p>
    <div class="split" style="margin-top:12px;">
        <article style="flex:1; min-width:220px;">
            <h3 style="margin-top:0;">Ventes par serveur</h3>
            <?php if (($people['sales_by_server_rows'] ?? []) === []): ?><p class="muted">Aucune vente dans le filtre.</p><?php else: ?>
                <ul style="margin:0; padding-left:18px;">
                    <?php foreach ($people['sales_by_server_rows'] as $row): ?>
                        <li><?= e(named_actor_label($row['server_name'] ?? null, 'cashier_server')) ?> : <?= e((string) ($row['sales_count'] ?? 0)) ?> ventes — <?= e(format_money((float) ($row['total_amount'] ?? 0), $restaurantCurrency)) ?><?php if ((float) ($row['pct_of_sales_amount'] ?? 0) > 0 || ($gt['sales_amount'] ?? 0) > 0): ?> — <?= e((string) ($row['pct_of_sales_amount'] ?? 0)) ?> % du montant total<?php endif; ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>
        <article style="flex:1; min-width:220px;">
            <h3 style="margin-top:0;">Cuisine par cuisinier</h3>
            <?php if (($people['kitchen_by_cook'] ?? []) === []): ?><p class="muted">Aucune production.</p><?php else: ?>
                <ul style="margin:0; padding-left:18px;">
                    <?php foreach ($people['kitchen_by_cook'] as $row): ?>
                        <li><?= e(named_actor_label($row['full_name'] ?? null, $row['role_code'] ?? 'kitchen')) ?> : <?= e((string) (int) round((float) ($row['plates_prepared'] ?? 0))) ?> plats préparés<?php if ((int) ($row['productions_count'] ?? 0) > 0): ?> (<?= e((string) $row['productions_count']) ?> productions)<?php endif; ?><?php if ((float) ($row['pct_of_plates'] ?? 0) > 0 || ($gt['plates_prepared'] ?? 0) > 0): ?> — <?= e((string) ($row['pct_of_plates'] ?? 0)) ?> % des unités cuisine<?php endif; ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>
    </div>
    <div class="split" style="margin-top:18px;">
        <article style="flex:1; min-width:220px;">
            <h3 style="margin-top:0;">Stock par responsable</h3>
            <?php if (($people['stock_by_staff'] ?? []) === []): ?><p class="muted">Aucun mouvement validé.</p><?php else: ?>
                <ul style="margin:0; padding-left:18px;">
                    <?php foreach ($people['stock_by_staff'] as $row): ?>
                        <li><?= e(named_actor_label($row['full_name'] ?? null, $row['role_code'] ?? 'stock_manager')) ?> : <?= e((string) ($row['sorties_count'] ?? 0)) ?> sorties stock, <?= e((string) ($row['pertes_count'] ?? 0)) ?> pertes<?php if (((int) ($gt['stock_movements_lines'] ?? 0)) > 0): ?> — <?= e((string) ($row['pct_of_movements'] ?? 0)) ?> % des mouvements (lignes)<?php endif; ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>
        <article style="flex:1; min-width:220px;">
            <h3 style="margin-top:0;">Caisse par personne</h3>
            <?php if (($people['cash_touchpoints'] ?? []) === []): ?><p class="muted">Aucune remise ou réception sur la période.</p><?php else: ?>
                <ul style="margin:0; padding-left:18px;">
                    <?php foreach ($people['cash_touchpoints'] as $row): ?>
                        <li><?= e(named_actor_label($row['full_name'] ?? null, $row['role_code'] ?? null)) ?> :
                            <?php if ((float) ($row['remis_ventes'] ?? 0) > 0): ?> + <?= e(format_money((float) $row['remis_ventes'], $restaurantCurrency)) ?> versés (ventes)<?php endif; ?>
                            <?php if ((float) ($row['recu_caisse_ventes'] ?? 0) > 0): ?><?= ((float) ($row['remis_ventes'] ?? 0) > 0) ? ' · ' : '' ?> + <?= e(format_money((float) $row['recu_caisse_ventes'], $restaurantCurrency)) ?> reçus caisse<?php endif; ?>
                            <?php if ((float) ($row['remis_comme_caisse_gerant'] ?? 0) > 0): ?><?= ((float) ($row['remis_ventes'] ?? 0) + (float) ($row['recu_caisse_ventes'] ?? 0) > 0) ? ' · ' : '' ?> − <?= e(format_money((float) $row['remis_comme_caisse_gerant'], $restaurantCurrency)) ?> remis gérant<?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>
    </div>
    <div style="margin-top:18px; padding-top:14px; border-top:1px solid #e8e8e8;">
        <h3 style="margin-top:0;">Total général</h3>
        <p style="margin:0;"><strong>Ventes</strong> : <?= e((string) ($gt['sales_count'] ?? 0)) ?> · <?= e(format_money((float) ($gt['sales_amount'] ?? 0), $restaurantCurrency)) ?> &nbsp;|&nbsp;
            <strong>Plats préparés</strong> : <?= e((string) (int) round((float) ($gt['plates_prepared'] ?? 0))) ?> &nbsp;|&nbsp;
            <strong>Sorties stock</strong> : <?= e((string) ($gt['stock_sorties'] ?? 0)) ?> &nbsp;|&nbsp;
            <strong>Pertes stock</strong> : <?= e((string) ($gt['stock_pertes'] ?? 0)) ?></p>
    </div>
        </div>
    </details>
</section>

<section class="card" style="padding:0; margin-bottom:24px;">
    <details class="report-section-details">
        <summary><strong>Ventes globales</strong> · <?= e((string) ($execSummary['period_label'] ?? ($report['period_label'] ?? ''))) ?></summary>
        <div class="report-section-body">
        <p class="muted" style="margin-top:0;">Totaux alignés sur les filtres du rapport (jour / semaine / mois / date précise). Les pourcentages articles sont calculés sur les lignes affichées ; les pourcentages serveurs sur le total vendu de la période.</p>
        <?php $exTotals = $execSummary['totals'] ?? []; ?>
        <p style="margin:12px 0 0;"><strong>Total vendu (période filtre)</strong> : <?= e(format_money((float) ($exTotals['grand_amount'] ?? 0), $restaurantCurrency)) ?> · <strong>Articles (unités)</strong> : <?= e((string) ($exTotals['articles_units'] ?? 0)) ?> · <strong>Commandes (serveur)</strong> : <?= e((string) (int) ($exTotals['orders_total'] ?? 0)) ?> · <strong>Pool activité (actions)</strong> : <?= e((string) (int) round((float) ($exTotals['activity_pool_total'] ?? 0))) ?></p>
        <?php if (($execSummary['by_server'] ?? []) !== []): ?>
            <p style="margin:14px 0 8px;"><strong>Répartition serveurs</strong></p>
            <p class="muted" style="margin-top:0;">
                <?php foreach ($execSummary['by_server'] as $ix => $row): ?>
                    <?= $ix > 0 ? ' · ' : '' ?>
                    <?= e((string) ($row['server_name'] ?? '-')) ?> : <?= e(format_money((float) ($row['total_sold'] ?? 0), $restaurantCurrency)) ?>
                    (<?= e((string) ($row['pct_of_grand_amount'] ?? 0)) ?> %)
                <?php endforeach; ?>
            </p>
            <?php
            $srvRows = $execSummary['by_server'];
            $srvChunk = $reportUiChunk;
            $srvMain = array_slice($srvRows, 0, $srvChunk);
            $srvMore = array_slice($srvRows, $srvChunk);
            ?>
            <div class="table-wrap report-table-desktop">
                <table>
                    <thead><tr><th>Serveur</th><th>Commandes</th><th>% cmd</th><th>Articles</th><th>Total vendu</th><th>% montant</th><th>Activité</th><th>% pool</th></tr></thead>
                    <tbody>
                    <?php foreach ($srvMain as $row): ?>
                        <tr>
                            <td><?= e((string) ($row['server_name'] ?? '-')) ?></td>
                            <td><?= e((string) ($row['orders_count'] ?? 0)) ?></td>
                            <td><?= e((string) ($row['pct_of_orders'] ?? 0)) ?> %</td>
                            <td><?= e((string) ($row['articles_sold'] ?? 0)) ?></td>
                            <td><?= e(format_money((float) ($row['total_sold'] ?? 0), $restaurantCurrency)) ?></td>
                            <td><?= e((string) ($row['pct_of_grand_amount'] ?? 0)) ?> %</td>
                            <td><?= e((string) ($row['activity_actions'] ?? 0)) ?></td>
                            <td><?= e((string) ($row['activity_percent'] ?? 0)) ?> %</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if ($srvMore !== []): ?>
                    <tbody id="exec-srv-more" hidden>
                    <?php foreach ($srvMore as $row): ?>
                        <tr>
                            <td><?= e((string) ($row['server_name'] ?? '-')) ?></td>
                            <td><?= e((string) ($row['orders_count'] ?? 0)) ?></td>
                            <td><?= e((string) ($row['pct_of_orders'] ?? 0)) ?> %</td>
                            <td><?= e((string) ($row['articles_sold'] ?? 0)) ?></td>
                            <td><?= e(format_money((float) ($row['total_sold'] ?? 0), $restaurantCurrency)) ?></td>
                            <td><?= e((string) ($row['pct_of_grand_amount'] ?? 0)) ?> %</td>
                            <td><?= e((string) ($row['activity_actions'] ?? 0)) ?></td>
                            <td><?= e((string) ($row['activity_percent'] ?? 0)) ?> %</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php endif; ?>
                </table>
            </div>
            <div class="report-mobile-cards">
                <?php foreach ($srvRows as $row): ?>
                    <div class="report-agent-card">
                        <strong><?= e((string) ($row['server_name'] ?? '-')) ?></strong><br>
                        <?= e((string) ($row['orders_count'] ?? 0)) ?> cmd (<?= e((string) ($row['pct_of_orders'] ?? 0)) ?> %) ·
                        <?= e((string) ($row['articles_sold'] ?? 0)) ?> art.<br>
                        Total <?= e(format_money((float) ($row['total_sold'] ?? 0), $restaurantCurrency)) ?> (<?= e((string) ($row['pct_of_grand_amount'] ?? 0)) ?> %)<br>
                        <span class="muted">Activité <?= e((string) ($row['activity_actions'] ?? 0)) ?> · <?= e((string) ($row['activity_percent'] ?? 0)) ?> %</span>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($srvMore !== []): ?>
                <button type="button" class="voir-plus-btn no-print report-table-desktop" data-report-expand="exec-srv-more">Voir plus (<?= e((string) count($srvMore)) ?> serveur<?= count($srvMore) > 1 ? 's' : '' ?>)</button>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (($execSummary['by_article'] ?? []) !== []): ?>
            <?php
            $artRows = $execSummary['by_article'];
            $artChunk = $reportUiChunk;
            $artMain = array_slice($artRows, 0, $artChunk);
            $artMore = array_slice($artRows, $artChunk);
            ?>
            <p style="margin:22px 0 8px;"><strong>Ventes par article</strong></p>
            <p class="muted" style="margin-top:0;">
                <?php foreach ($execSummary['by_article'] as $ix => $row): ?>
                    <?= $ix > 0 ? ' · ' : '' ?>
                    <?= e((string) ($row['article'] ?? '-')) ?> : <?= e(format_money((float) ($row['total_sold'] ?? 0), $restaurantCurrency)) ?>
                    (<?= e((string) ($row['pct_amount_of_grand'] ?? 0)) ?> %)
                <?php endforeach; ?>
            </p>
            <div class="table-wrap report-table-desktop">
                <table>
                    <thead><tr><th>Article</th><th>Quantité</th><th>% qté</th><th>Total vendu</th><th>% montant</th></tr></thead>
                    <tbody>
                    <?php foreach ($artMain as $row): ?>
                        <tr>
                            <td><?= e((string) ($row['article'] ?? '-')) ?></td>
                            <td><?= e((string) ($row['qty_sold'] ?? 0)) ?></td>
                            <td><?= e((string) ($row['pct_qty_of_total'] ?? 0)) ?> %</td>
                            <td><?= e(format_money((float) ($row['total_sold'] ?? 0), $restaurantCurrency)) ?></td>
                            <td><?= e((string) ($row['pct_amount_of_grand'] ?? 0)) ?> %</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if ($artMore !== []): ?>
                    <tbody id="exec-art-more" hidden>
                    <?php foreach ($artMore as $row): ?>
                        <tr>
                            <td><?= e((string) ($row['article'] ?? '-')) ?></td>
                            <td><?= e((string) ($row['qty_sold'] ?? 0)) ?></td>
                            <td><?= e((string) ($row['pct_qty_of_total'] ?? 0)) ?> %</td>
                            <td><?= e(format_money((float) ($row['total_sold'] ?? 0), $restaurantCurrency)) ?></td>
                            <td><?= e((string) ($row['pct_amount_of_grand'] ?? 0)) ?> %</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php endif; ?>
                </table>
            </div>
            <div class="report-mobile-cards">
                <?php foreach ($artRows as $row): ?>
                    <div class="report-agent-card">
                        <strong><?= e((string) ($row['article'] ?? '-')) ?></strong><br>
                        <?= e((string) ($row['qty_sold'] ?? 0)) ?> pièces (<?= e((string) ($row['pct_qty_of_total'] ?? 0)) ?> %)<br>
                        <?= e(format_money((float) ($row['total_sold'] ?? 0), $restaurantCurrency)) ?> (<?= e((string) ($row['pct_amount_of_grand'] ?? 0)) ?> %)
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($artMore !== []): ?>
                <button type="button" class="voir-plus-btn no-print report-table-desktop" data-report-expand="exec-art-more">Voir plus (<?= e((string) count($artMore)) ?> article<?= count($artMore) > 1 ? 's' : '' ?>)</button>
            <?php endif; ?>
        <?php endif; ?>
        </div>
    </details>
</section>

<section class="card" style="padding:0; margin-bottom:24px;">
    <details class="report-section-details">
        <summary><strong>Activité des agents</strong> · pool <?= e((string) (int) round((float) ($agentsActivity['pool_total_actions'] ?? 0))) ?> actions (période rapport)</summary>
        <div class="report-section-body">
            <p class="muted" style="margin-top:0;">Pourcentage affiché : nombre d’actions / total des actions de la période (0&nbsp;% si total nul). Les métriques dépendent du rôle. Rapidité : Rapide &lt; 10&nbsp;min, Moyen 10 à 30&nbsp;min, Lent &gt; 30&nbsp;min ; sinon « Non calculé ».</p>
            <?php if (($agentsActivity['servers'] ?? []) !== []): ?>
                <h4 style="margin:18px 0 8px;">Serveurs</h4>
                <div class="table-wrap report-table-desktop">
                    <table>
                        <thead>
                        <tr>
                            <th>Agent</th><th>Actions</th><th>%</th><th>Début</th><th>Cmd.</th><th>Ventes clôt.</th><th>Remises</th>
                            <th>Moy. clôture</th><th>Moy. remise caisse</th><th>Score</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($agentsActivity['servers'] as $row): ?>
                            <tr>
                                <td><?= e(named_actor_label($row['full_name'] ?? null, $row['role_code'] ?? null)) ?></td>
                                <td><?= e((string) (int) ($row['actions_count'] ?? 0)) ?></td>
                                <td><?= e((string) ($row['activity_percent'] ?? 0)) ?> %</td>
                                <td><?= !empty($row['first_order_at']) ? e(format_date_fr($row['first_order_at'], $reportTimezone)) : '—' ?></td>
                                <td><?= e((string) (int) ($row['orders_count'] ?? 0)) ?></td>
                                <td><?= e((string) (int) ($row['closed_sales_count'] ?? 0)) ?></td>
                                <td><?= e((string) (int) ($row['cash_remittances_count'] ?? 0)) ?></td>
                                <td><?= isset($row['avg_minutes_order_to_close']) ? e((string) $row['avg_minutes_order_to_close']) . ' min · ' . e((string) ($row['speed_close_tier'] ?? '')) : 'Non calculé' ?></td>
                                <td><?= isset($row['avg_minutes_close_to_remittance']) ? e((string) $row['avg_minutes_close_to_remittance']) . ' min · ' . e((string) ($row['speed_remittance_tier'] ?? '')) : 'Non calculé' ?></td>
                                <td><?= isset($row['simple_score']) ? e((string) (int) $row['simple_score']) . ' / 100' : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="report-mobile-cards">
                    <?php foreach ($agentsActivity['servers'] as $row): ?>
                        <div class="report-agent-card">
                            <strong><?= e(named_actor_label($row['full_name'] ?? null, $row['role_code'] ?? null)) ?></strong>
                            <div><?= e((string) ($row['activity_line'] ?? '')) ?></div>
                            <div>Début service : <?= !empty($row['first_order_at']) ? e(format_date_fr($row['first_order_at'], $reportTimezone)) : '—' ?></div>
                            <div>Cmd. <?= e((string) (int) ($row['orders_count'] ?? 0)) ?> · Ventes clôturées <?= e((string) (int) ($row['closed_sales_count'] ?? 0)) ?> · Remises <?= e((string) (int) ($row['cash_remittances_count'] ?? 0)) ?></div>
                            <div>Clôture : <?= isset($row['avg_minutes_order_to_close']) ? e((string) $row['avg_minutes_order_to_close']) . ' min (' . e((string) ($row['speed_close_tier'] ?? '')) . ')' : 'Non calculé' ?></div>
                            <div>Remise caisse : <?= isset($row['avg_minutes_close_to_remittance']) ? e((string) $row['avg_minutes_close_to_remittance']) . ' min (' . e((string) ($row['speed_remittance_tier'] ?? '')) . ')' : 'Non calculé' ?></div>
                            <div>Score rapidité : <?= isset($row['simple_score']) ? e((string) (int) $row['simple_score']) . ' / 100' : '—' ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (($agentsActivity['kitchen'] ?? []) !== []): ?>
                <h4 style="margin:22px 0 8px;">Cuisine</h4>
                <div class="table-wrap report-table-desktop">
                    <table>
                        <thead><tr><th>Cuisinier</th><th>Actions</th><th>%</th><th>1ʳᵉ action</th><th>Lignes</th><th>Validées</th><th>Rejetées</th><th>Moy. traitement</th><th>Score</th></tr></thead>
                        <tbody>
                        <?php foreach ($agentsActivity['kitchen'] as $row): ?>
                            <tr>
                                <td><?= e(named_actor_label($row['full_name'] ?? null, $row['role_code'] ?? null)) ?></td>
                                <td><?= e((string) (int) ($row['actions_count'] ?? 0)) ?></td>
                                <td><?= e((string) ($row['activity_percent'] ?? 0)) ?> %</td>
                                <td><?= !empty($row['first_kitchen_action_at']) ? e(format_date_fr($row['first_kitchen_action_at'], $reportTimezone)) : '—' ?></td>
                                <td><?= e((string) (int) ($row['commands_received'] ?? 0)) ?></td>
                                <td><?= e((string) (int) ($row['commands_validated'] ?? 0)) ?></td>
                                <td><?= e((string) (int) ($row['commands_rejected'] ?? 0)) ?></td>
                                <td><?= isset($row['avg_minutes_processing']) ? e((string) $row['avg_minutes_processing']) . ' min · ' . e((string) ($row['speed_tier'] ?? '')) : 'Non calculé' ?></td>
                                <td><?= isset($row['simple_score']) ? e((string) (int) $row['simple_score']) . ' / 100' : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="report-mobile-cards">
                    <?php foreach ($agentsActivity['kitchen'] as $row): ?>
                        <div class="report-agent-card">
                            <strong><?= e(named_actor_label($row['full_name'] ?? null, $row['role_code'] ?? null)) ?></strong>
                            <div><?= e((string) ($row['activity_line'] ?? '')) ?></div>
                            <div>1ʳᵉ action : <?= !empty($row['first_kitchen_action_at']) ? e(format_date_fr($row['first_kitchen_action_at'], $reportTimezone)) : '—' ?></div>
                            <div>Lignes <?= e((string) (int) ($row['commands_received'] ?? 0)) ?> · Validées <?= e((string) (int) ($row['commands_validated'] ?? 0)) ?> · Rejetées <?= e((string) (int) ($row['commands_rejected'] ?? 0)) ?></div>
                            <div>Trait. moy. <?= isset($row['avg_minutes_processing']) ? e((string) $row['avg_minutes_processing']) . ' min (' . e((string) ($row['speed_tier'] ?? '')) . ')' : 'Non calculé' ?></div>
                            <div>Score <?= isset($row['simple_score']) ? e((string) (int) $row['simple_score']) . ' / 100' : '—' ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (($agentsActivity['stock'] ?? []) !== []): ?>
                <h4 style="margin:22px 0 8px;">Stock</h4>
                <div class="table-wrap report-table-desktop">
                    <table>
                        <thead><tr><th>Agent</th><th>Actions</th><th>%</th><th>1ʳᵉ action</th><th>Demandes reçues</th><th>Traitées</th><th>Sorties</th><th>Moy. traitement</th><th>Score</th></tr></thead>
                        <tbody>
                        <?php foreach ($agentsActivity['stock'] as $row): ?>
                            <tr>
                                <td><?= e(named_actor_label($row['full_name'] ?? null, $row['role_code'] ?? null)) ?></td>
                                <td><?= e((string) (int) ($row['actions_count'] ?? 0)) ?></td>
                                <td><?= e((string) ($row['activity_percent'] ?? 0)) ?> %</td>
                                <td><?= !empty($row['first_stock_action_at']) ? e(format_date_fr($row['first_stock_action_at'], $reportTimezone)) : '—' ?></td>
                                <td><?= e((string) (int) ($row['requests_received'] ?? 0)) ?></td>
                                <td><?= e((string) (int) ($row['requests_handled'] ?? 0)) ?></td>
                                <td><?= e((string) (int) ($row['stock_out_movements'] ?? 0)) ?></td>
                                <td><?= isset($row['avg_minutes_request_processing']) ? e((string) $row['avg_minutes_request_processing']) . ' min · ' . e((string) ($row['speed_tier'] ?? '')) : 'Non calculé' ?></td>
                                <td><?= isset($row['simple_score']) ? e((string) (int) $row['simple_score']) . ' / 100' : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="report-mobile-cards">
                    <?php foreach ($agentsActivity['stock'] as $row): ?>
                        <div class="report-agent-card">
                            <strong><?= e(named_actor_label($row['full_name'] ?? null, $row['role_code'] ?? null)) ?></strong>
                            <div><?= e((string) ($row['activity_line'] ?? '')) ?></div>
                            <div>Demandes reçues <?= e((string) (int) ($row['requests_received'] ?? 0)) ?> · Traitées <?= e((string) (int) ($row['requests_handled'] ?? 0)) ?> · Sorties <?= e((string) (int) ($row['stock_out_movements'] ?? 0)) ?></div>
                            <div>Moy. <?= isset($row['avg_minutes_request_processing']) ? e((string) $row['avg_minutes_request_processing']) . ' min (' . e((string) ($row['speed_tier'] ?? '')) . ')' : 'Non calculé' ?></div>
                            <div>Score <?= isset($row['simple_score']) ? e((string) (int) $row['simple_score']) . ' / 100' : '—' ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (($agentsActivity['cashiers'] ?? []) !== []): ?>
                <h4 style="margin:22px 0 8px;">Caisse</h4>
                <div class="table-wrap report-table-desktop">
                    <table>
                        <thead><tr><th>Agent</th><th>Actions</th><th>%</th><th>1ʳᵉ réception</th><th>Remises reçues</th><th>Validées</th><th>Rejetées</th><th>Moy. décision</th><th>Score</th></tr></thead>
                        <tbody>
                        <?php foreach ($agentsActivity['cashiers'] as $row): ?>
                            <tr>
                                <td><?= e(named_actor_label($row['full_name'] ?? null, $row['role_code'] ?? null)) ?></td>
                                <td><?= e((string) (int) ($row['actions_count'] ?? 0)) ?></td>
                                <td><?= e((string) ($row['activity_percent'] ?? 0)) ?> %</td>
                                <td><?= !empty($row['first_cash_action_at']) ? e(format_date_fr($row['first_cash_action_at'], $reportTimezone)) : '—' ?></td>
                                <td><?= e((string) (int) ($row['remittances_received'] ?? 0)) ?></td>
                                <td><?= e((string) (int) ($row['remittances_validated'] ?? 0)) ?></td>
                                <td><?= e((string) (int) ($row['remittances_rejected'] ?? 0)) ?></td>
                                <td><?= isset($row['avg_minutes_reception_decision']) ? e((string) $row['avg_minutes_reception_decision']) . ' min · ' . e((string) ($row['speed_tier'] ?? '')) : 'Non calculé' ?></td>
                                <td><?= isset($row['simple_score']) ? e((string) (int) $row['simple_score']) . ' / 100' : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="report-mobile-cards">
                    <?php foreach ($agentsActivity['cashiers'] as $row): ?>
                        <div class="report-agent-card">
                            <strong><?= e(named_actor_label($row['full_name'] ?? null, $row['role_code'] ?? null)) ?></strong>
                            <div><?= e((string) ($row['activity_line'] ?? '')) ?></div>
                            <div>Remises <?= e((string) (int) ($row['remittances_received'] ?? 0)) ?> · Validées <?= e((string) (int) ($row['remittances_validated'] ?? 0)) ?> · Rejetées <?= e((string) (int) ($row['remittances_rejected'] ?? 0)) ?></div>
                            <div>Délai moyen <?= isset($row['avg_minutes_reception_decision']) ? e((string) $row['avg_minutes_reception_decision']) . ' min (' . e((string) ($row['speed_tier'] ?? '')) . ')' : 'Non calculé' ?></div>
                            <div>Score <?= isset($row['simple_score']) ? e((string) (int) $row['simple_score']) . ' / 100' : '—' ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (($agentsActivity['other_roles'] ?? []) !== []): ?>
                <h4 style="margin:22px 0 8px;">Autres rôles (résumé)</h4>
                <ul style="margin:0; padding-left:18px;">
                    <?php foreach ($agentsActivity['other_roles'] as $row): ?>
                        <li><?= e(named_actor_label($row['full_name'] ?? null, $row['role_code'] ?? null)) ?> · <?= e((string) ($row['activity_line'] ?? '')) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if (
                ($agentsActivity['servers'] ?? []) === [] && ($agentsActivity['kitchen'] ?? []) === []
                && ($agentsActivity['stock'] ?? []) === [] && ($agentsActivity['cashiers'] ?? []) === []
                && ($agentsActivity['other_roles'] ?? []) === []
            ): ?>
                <p class="muted" style="margin-bottom:0;">Aucun agent avec actions sur cette période dans les filtres — élargissez la date ou les filtres.</p>
            <?php endif; ?>
        </div>
    </details>
</section>

<section class="card" style="padding:0; margin-bottom:24px;">
    <details class="report-section-details">
        <summary><strong>Historique nominatif</strong><?php if ($timeline !== []): ?> · <?= e((string) count($timeline)) ?> ligne(s) affichée(s)<?php endif; ?></summary>
        <div class="report-section-body">
    <p class="muted" style="margin-top:0;">Ventes clôturées synthétiques et entrées d’audit ; filtres périmètre et code d’action ci-dessus. Limite : <?= e((string) (int) ($viewFilters['timeline_limit'] ?? 350)) ?> lignes.</p>
    <?php if ($timeline === []): ?>
        <p class="muted">Aucun événement dans le filtre.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Utilisateur</th>
                    <th>Rôle</th>
                    <th>Action</th>
                    <th>Détail / montant</th>
                    <th>Date et heure</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($timeline as $trow): ?>
                    <tr>
                        <td><?= e((string) ($trow['actor_name'] ?? '')) ?></td>
                        <td><?= e(restaurant_role_label((string) ($trow['actor_role_code'] ?? ''))) ?></td>
                        <td><?= e(report_audit_action_label((string) ($trow['action_name'] ?? ''))) ?></td>
                        <td>
                            <?php if (!empty($trow['timeline_detail'])): ?><?= e((string) $trow['timeline_detail']) ?><?php endif; ?>
                            <?php if (isset($trow['line_amount']) && (float) $trow['line_amount'] !== 0.0): ?>
                                <?php if (!empty($trow['timeline_detail'])): ?><br><?php endif; ?>
                                <span class="muted"><?= e(format_money((float) $trow['line_amount'], $restaurantCurrency)) ?></span>
                            <?php endif; ?>
                            <?php if (empty($trow['timeline_detail']) && (empty($trow['line_amount']) || (float) $trow['line_amount'] === 0.0) && !empty($trow['new_values_json'])): ?>
                                <span class="muted"><?= e(mb_substr((string) $trow['new_values_json'], 0, 160, 'UTF-8')) ?><?= mb_strlen((string) $trow['new_values_json'], 'UTF-8') > 160 ? '…' : '' ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(format_date_fr($trow['created_at'] ?? null, $reportTimezone)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="muted" style="margin-bottom:0;">Format phrase (exemple) : <?= e(nominative_timeline_sentence($timeline[0] ?? [], $restaurantCurrency, $reportTimezone)) ?></p>
    <?php endif; ?>
        </div>
    </details>
</section>

<section class="card no-print" style="padding:0; margin-bottom:24px;">
    <details class="report-section-details">
        <summary><strong>Rapports détaillés</strong> · ventes par ligne, cuisine, stock</summary>
        <div class="report-section-body">
        <p class="muted" style="margin-top:0; margin-bottom:0;">Sections repliables pour éviter de surcharger l’écran ; adapté mobile. Les filtres ci-dessus (période, personne, rôle, produit carte, article stock, type de mouvement) s’appliquent.</p>
        <div class="report-detail-nested" style="margin-top:16px;">
            <details>
                <summary><strong>1. Ventes par serveur et par produit</strong> · Total général <?= e(format_money((float) ($salesDetail['grand_total'] ?? 0), $restaurantCurrency)) ?></summary>
                <?php if (($salesDetail['servers'] ?? []) === []): ?>
                    <p class="muted" style="margin-top:12px;">Aucune ligne dans le filtre.</p>
                <?php else: ?>
                    <?php foreach ($salesDetail['servers'] as $srv): ?>
                        <details style="margin-top:12px; padding:12px; border:1px solid var(--line, #e0e0e0); border-radius:12px;">
                            <summary>Serveur <?= e(named_actor_label($srv['server_name'] ?? null, $srv['server_role_code'] ?? 'cashier_server')) ?> · Total <?= e(format_money((float) ($srv['server_total'] ?? 0), $restaurantCurrency)) ?> — <?= e((string) ($srv['pct_of_grand_total'] ?? 0)) ?> % du total ventes</summary>
                            <ul style="margin:12px 0 0; padding-left:18px;">
                                <?php foreach (($srv['lines'] ?? []) as $ln): ?>
                                    <li><?= e((string) ($ln['menu_item_name'] ?? '')) ?> x<?php
                                    $qs = (float) ($ln['qty_sold'] ?? 0);
                                    echo e(abs($qs - round($qs)) < 0.001 ? (string) (int) round($qs) : (string) $qs);
                                    ?> = <?= e(format_money((float) ($ln['line_total'] ?? 0), $restaurantCurrency)) ?> — <?= e((string) ($ln['pct_of_server_sales'] ?? 0)) ?> % des ventes de ce serveur</li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endforeach; ?>
                <?php endif; ?>
            </details>
            <details>
                <summary><strong>2. Production cuisine par personne</strong> · <?= e((string) ($kitchenDetail['grand_total_qty'] ?? 0)) ?> unités · <?= e(format_money((float) ($kitchenDetail['grand_total_value'] ?? 0), $restaurantCurrency)) ?></summary>
                <?php if (($kitchenDetail['cooks'] ?? []) === []): ?>
                    <p class="muted" style="margin-top:12px;">Aucune production dans le filtre.</p>
                <?php else: ?>
                    <?php foreach ($kitchenDetail['cooks'] as $ck): ?>
                        <details style="margin-top:12px; padding:12px; border:1px solid var(--line, #e0e0e0); border-radius:12px;">
                            <summary><?= e(named_actor_label($ck['cook_name'] ?? null, $ck['role_code'] ?? 'kitchen')) ?> · <?= e(format_money((float) ($ck['cook_total_value'] ?? 0), $restaurantCurrency)) ?> · <?= e((string) ($ck['cook_total_qty'] ?? 0)) ?> unités — <?= e((string) ($ck['pct_of_kitchen_qty'] ?? 0)) ?> % de la cuisine (unités)</summary>
                            <p class="muted" style="margin:10px 0 6px;"><strong>Plats</strong></p>
                            <ul style="margin:0; padding-left:18px;">
                                <?php foreach (($ck['dishes'] ?? []) as $d): ?>
                                    <li><?= e((string) ($d['dish_label'] ?? '')) ?> · qté <?= e((string) ($d['qty_produced'] ?? 0)) ?> · <?= e(format_money((float) ($d['value_produced'] ?? 0), $restaurantCurrency)) ?> — <?= e((string) ($d['pct_of_cook_qty'] ?? 0)) ?> % des unités du cuisinier</li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if (($ck['materials'] ?? []) !== []): ?>
                                <p class="muted" style="margin:12px 0 6px;"><strong>Matières (mouvements liés)</strong></p>
                                <ul style="margin:0; padding-left:18px;">
                                    <?php foreach ($ck['materials'] as $mat): ?>
                                        <li><?= e((string) ($mat['name'] ?? '')) ?> · <?= e((string) ($mat['quantity'] ?? 0)) ?> — <?= e((string) ($mat['pct_of_cook_material_qty'] ?? 0)) ?> % des matières (qté) du cuisinier</li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </details>
                    <?php endforeach; ?>
                <?php endif; ?>
            </details>
            <details>
                <summary><strong>3. Stock par responsable</strong> · <?= e((string) ($stockDetail['grand_total_movements'] ?? 0)) ?> mouvements (lignes)</summary>
                <?php if (($stockDetail['people'] ?? []) === []): ?>
                    <p class="muted" style="margin-top:12px;">Aucun mouvement validé dans le filtre.</p>
                <?php else: ?>
                    <?php foreach ($stockDetail['people'] as $sp): ?>
                        <details style="margin-top:12px; padding:12px; border:1px solid var(--line, #e0e0e0); border-radius:12px;">
                            <summary><?= e(named_actor_label($sp['full_name'] ?? null, $sp['role_code'] ?? 'stock_manager')) ?> · <?= e((string) ($sp['total_movements'] ?? 0)) ?> mouvements — <?= e((string) ($sp['pct_of_global_movements'] ?? 0)) ?> % du total</summary>
                            <p style="margin:10px 0 6px;" class="muted">Entrées <?= e((string) ($sp['entrees_lines'] ?? 0)) ?> (<?= e((string) ($sp['pct_entrees'] ?? 0)) ?> % des lignes) · Sorties <?= e((string) ($sp['sorties_lines'] ?? 0)) ?> (<?= e((string) ($sp['pct_sorties'] ?? 0)) ?> %) · Pertes <?= e((string) ($sp['pertes_lines'] ?? 0)) ?> (<?= e((string) ($sp['pct_pertes'] ?? 0)) ?> %) · Retours <?= e((string) ($sp['retours_lines'] ?? 0)) ?> (<?= e((string) ($sp['pct_retours'] ?? 0)) ?> %)<?php if ((int) ($sp['autres_lines'] ?? 0) > 0): ?> · Autres <?= e((string) ($sp['autres_lines'] ?? 0)) ?> (<?= e((string) ($sp['pct_autres'] ?? 0)) ?> %)<?php endif; ?></p>
                            <?php if (($sp['product_lines'] ?? []) !== []): ?>
                                <div class="table-wrap" style="margin-top:8px;">
                                    <table>
                                        <thead><tr><th>Produit</th><th>Type</th><th>Lignes (% pers.)</th><th>Qté</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($sp['product_lines'] as $pl): ?>
                                            <tr>
                                                <td><?= e((string) ($pl['product_name'] ?? '')) ?></td>
                                                <td><?= e((string) ($pl['movement_type'] ?? '')) ?></td>
                                                <td><?= e((string) ($pl['line_count'] ?? 0)) ?> (<?= e((string) ($pl['pct_of_person_movements'] ?? 0)) ?> %)</td>
                                                <td><?= e((string) ($pl['qty_sum'] ?? 0)) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </details>
                    <?php endforeach; ?>
                <?php endif; ?>
            </details>
        </div>
        </div>
    </details>
</section>

<section class="card" style="padding:0; margin-bottom:24px;">
    <details class="report-section-details">
        <summary><strong>Stock & Cuisine</strong> · agrégés et indicateurs</summary>
        <div class="report-section-body">
<section class="grid stats">
    <article class="card stat"><span>Stock début</span><strong><?= e((string) $report['opening_stock_total']) ?></strong></article>
    <article class="card stat"><span>Stock actuel</span><strong><?= e((string) $report['current_stock_total']) ?></strong></article>
    <article class="card stat"><span>Sorties cuisine</span><strong><?= e((string) $report['kitchen_outputs']) ?></strong></article>
    <article class="card stat"><span>Retours stock</span><strong><?= e((string) $report['stock_returns']) ?></strong></article>
    <article class="card stat"><span>Production cuisine</span><strong><?= e((string) $report['kitchen_production']) ?></strong></article>
    <article class="card stat"><span>Pertes matière</span><strong><?= e(format_money($report['material_losses'], $restaurantCurrency)) ?></strong></article>
    <article class="card stat"><span>Pertes argent</span><strong><?= e(format_money($report['financial_losses'], $restaurantCurrency)) ?></strong></article>
    <article class="card stat"><span>Bénéfice estimé</span><strong><?= e(format_money($report['estimated_profit'], $restaurantCurrency)) ?></strong></article>
</section>

<section class="split" style="margin-top:24px;">
    <article class="card" style="padding:22px;">
        <h2 style="margin-top:0;">Rapport stock</h2>
        <p><strong>Total entré</strong> : <?= e((string) $report['stock_report']['total_entered_quantity']) ?> - <?= e(format_money($report['stock_report']['total_entered_value'], $restaurantCurrency)) ?></p>
        <p><strong>Total sorti</strong> : <?= e((string) $report['stock_report']['total_output_quantity']) ?> - <?= e(format_money($report['stock_report']['total_output_value'], $restaurantCurrency)) ?></p>
        <p><strong>Valeur stock</strong> : <?= e(format_money($report['stock_report']['stock_value'], $restaurantCurrency)) ?></p>
        <p><strong>Pertes stock</strong> : <?= e(format_money($report['stock_report']['stock_losses_value'], $restaurantCurrency)) ?></p>
        <p><strong>Demandes urgentes</strong> : <?= e((string) $report['stock_report']['urgent_requests']) ?></p>
        <p><strong>Demandes à prévoir</strong> : <?= e((string) $report['stock_report']['planned_requests']) ?></p>
        <p><strong>Ruptures</strong> : <?= e((string) $report['stock_report']['ruptures']) ?></p>
    </article>

    <article class="card" style="padding:22px;">
        <h2 style="margin-top:0;">Rapport cuisine</h2>
        <p><strong>Total produit</strong> : <?= e((string) $report['kitchen_report']['total_produced']) ?></p>
        <p><strong>Coût réel produit</strong> : <?= e(format_money($report['kitchen_report']['real_material_cost_produced'], $restaurantCurrency)) ?></p>
        <p><strong>Valeur produite</strong> : <?= e(format_money($report['kitchen_report']['value_produced'], $restaurantCurrency)) ?></p>
        <p><strong>Total remis aux serveurs</strong> : <?= e((string) $report['kitchen_report']['total_supplied_to_servers']) ?></p>
        <p><strong>Valeur remise</strong> : <?= e(format_money($report['kitchen_report']['value_supplied'], $restaurantCurrency)) ?></p>
        <p><strong>Coût réel des ventes</strong> : <?= e(format_money($report['kitchen_report']['real_material_cost_of_sales'], $restaurantCurrency)) ?></p>
        <p><strong>Pertes cuisine</strong> : <?= e(format_money($report['kitchen_report']['kitchen_losses_value'], $restaurantCurrency)) ?></p>
        <p><strong>Incidents cuisine</strong> : <?= e((string) $report['kitchen_report']['kitchen_incidents']) ?></p>
    </article>
</section>
        </div>
    </details>
</section>

<?php if (($report['incident_cases'] ?? []) !== []): ?>
<section class="card" style="padding:0; margin-top:24px; margin-bottom:24px;">
    <details class="report-section-details">
        <summary><strong>Incidents et décisions</strong> · <?= e((string) count($report['incident_cases'] ?? [])) ?> cas</summary>
        <div class="report-section-body">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Cas</th>
                    <th>Signalement</th>
                    <th>Confirmation cuisine</th>
                    <th>Decision gerant</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($report['incident_cases'] as $case): ?>
                    <tr>
                        <td><strong>#<?= e((string) $case['id']) ?></strong><br><span class="muted"><?= e($case['stock_item_name'] ?? 'Produit') ?></span></td>
                        <td><?= e(signed_actor_line('Signale', $case['signaled_by_name'] ?? null, 'cashier_server', $case['created_at'] ?? null, $restaurant, $reportTimezone)) ?></td>
                        <td><?= e(signed_actor_line('Confirme', $case['technical_confirmed_by_name'] ?? null, 'kitchen', $case['technical_confirmed_at'] ?? null, $restaurant, $reportTimezone)) ?></td>
                        <td><?= e(signed_actor_line('Decide', $case['decided_by_name'] ?? null, 'manager', $case['decided_at'] ?? null, $restaurant, $reportTimezone)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        </div>
    </details>
</section>
<?php endif; ?>

<section class="card" style="padding:0; margin-top:24px; margin-bottom:24px;">
    <details class="report-section-details">
        <summary><strong>Ventes serveur & bilan général</strong></summary>
        <div class="report-section-body">
<section class="split" style="margin-top:0;">
    <article class="card" style="padding:22px;">
        <h2 style="margin-top:0;">Rapport serveur</h2>
        <p><strong>Total demandé</strong> : <?= e(format_money($report['server_report']['total_requested'], $restaurantCurrency)) ?></p>
        <p><strong>Total fourni</strong> : <?= e(format_money($report['server_report']['total_supplied'], $restaurantCurrency)) ?></p>
        <p><strong>Total vendu</strong> : <?= e(format_money($report['server_report']['total_sold'], $restaurantCurrency)) ?></p>
        <p><strong>Total retourné</strong> : <?= e(format_money($report['server_report']['total_returned'], $restaurantCurrency)) ?></p>
        <p><strong>Perte serveur</strong> : <?= e(format_money($report['server_report']['server_loss_value'], $restaurantCurrency)) ?></p>
        <?php if (($report['sales_by_server'] ?? []) !== []): ?>
            <div class="table-wrap" style="margin-top:14px;">
                <table>
                    <thead>
                    <tr>
                        <th>Serveur</th>
                        <th>Ventes</th>
                        <th>Montant</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($report['sales_by_server'] as $row): ?>
                        <tr>
                            <td><?= e(named_actor_label($row['server_name'] ?? null, 'cashier_server')) ?></td>
                            <td><?= e((string) $row['sales_count']) ?></td>
                            <td><?= e(format_money($row['total_amount'], $restaurantCurrency)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php foreach ($report['server_report']['incidents_by_server'] as $row): ?>
            <p><span class="muted"><?= e(named_actor_label($row['server_name'] ?? null, 'cashier_server')) ?> : <?= e((string) $row['incidents']) ?> incident(s)</span></p>
        <?php endforeach; ?>
    </article>

    <article class="card" style="padding:22px;">
        <h2 style="margin-top:0;">Rapport général</h2>
        <p><strong>Total produit</strong> : <?= e(format_money($report['general_report']['total_product_value'], $restaurantCurrency)) ?></p>
        <p><strong>Total vendu</strong> : <?= e(format_money($report['general_report']['total_sold_value'], $restaurantCurrency)) ?></p>
        <p><strong>Coût matières réel</strong> : <?= e(format_money($report['general_report']['real_material_cost_value'], $restaurantCurrency)) ?></p>
        <p><strong>Total pertes</strong> : <?= e(format_money($report['general_report']['total_losses_value'], $restaurantCurrency)) ?></p>
        <p><strong>Perte stock</strong> : <?= e(format_money($report['general_report']['stock_loss_value'], $restaurantCurrency)) ?></p>
        <p><strong>Perte cuisine</strong> : <?= e(format_money($report['general_report']['kitchen_loss_value'], $restaurantCurrency)) ?></p>
        <p><strong>Perte serveur</strong> : <?= e(format_money($report['general_report']['server_loss_value'], $restaurantCurrency)) ?></p>
        <p><strong>Bénéfice brut estimé</strong> : <?= e(format_money($report['general_report']['estimated_gross_profit'], $restaurantCurrency)) ?></p>
    </article>
</section>
        </div>
    </details>
</section>

<section class="card" style="padding:0; margin-bottom:24px;">
    <details class="report-section-details">
        <summary><strong>Opérations clôturées automatiquement</strong><?php if ($autoClosed !== []): ?> · <?= e((string) count($autoClosed)) ?> événement(s)<?php endif; ?></summary>
        <div class="report-section-body">
        <p class="muted" style="margin-top:0;">Clôtures vente/remise au changement de jour, réceptions ou expirations pilotées par le système (fuseau restaurant).</p>
        <?php if ($autoClosed === []): ?>
            <p class="muted" style="margin-bottom:0;">Aucune opération auto-clôturée sur cette période.</p>
        <?php else: ?>
            <div class="table-wrap" style="margin-top:14px;">
                <table>
                    <thead>
                    <tr><th>Date</th><th>Acteur</th><th>Type</th><th>Réf.</th><th>Motif / détail</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($autoClosed as $acr): ?>
                        <?php
                        $nv = [];
                        if (!empty($acr['new_values_json'])) {
                            $tmp = json_decode((string) $acr['new_values_json'], true);
                            $nv = is_array($tmp) ? $tmp : [];
                        }
                        $amtHint = '';
                        if (isset($nv['operation']['amounts']['total_supplied'])) {
                            $amtHint = format_money((float) $nv['operation']['amounts']['total_supplied'], $restaurantCurrency);
                        } elseif (isset($nv['amount_received'])) {
                            $amtHint = format_money((float) $nv['amount_received'], $restaurantCurrency);
                        } elseif (isset($nv['total_amount'])) {
                            $amtHint = format_money((float) $nv['total_amount'], $restaurantCurrency);
                        }
                        ?>
                        <tr>
                            <td><?= e(format_date_fr($acr['created_at'] ?? null, $reportTimezone)) ?></td>
                            <td><?= e((string) ($acr['actor_name'] ?? '')) ?><br><span class="muted"><?= e((string) ($acr['actor_role_code'] ?? '')) ?></span></td>
                            <td><?= e(report_audit_action_label((string) ($acr['action_name'] ?? ''))) ?></td>
                            <td><span class="muted"><?= e((string) ($acr['entity_id'] ?? '-')) ?></span><?php if ($amtHint !== ''): ?><br><?= e($amtHint) ?><?php endif; ?></td>
                            <td style="max-width:320px;"><?= e((string) ($acr['justification'] ?? '')) ?><?php if ($nv !== []): ?><details style="margin-top:8px;"><summary class="muted" style="cursor:pointer;">JSON technique</summary><pre style="white-space:pre-wrap;font-size:11px;margin:8px 0 0;"><?= e(json_encode($nv, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre></details><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        </div>
    </details>
</section>
</div>
</details>
