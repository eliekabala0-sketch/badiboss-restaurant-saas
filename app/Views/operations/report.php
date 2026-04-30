<?php
$reportTimezone = safe_timezone($report['timezone'] ?? ($restaurant['settings']['restaurant_reports_timezone'] ?? $restaurant['timezone'] ?? null));
$restaurantCurrency = restaurant_currency($restaurant);
$printQuery = http_build_query(array_filter([
    'date' => $report['selected_date'] ?? $date,
    'period' => $period ?? 'daily',
    'restaurant_id' => (current_user()['scope'] ?? null) === 'super_admin' ? (string) $restaurant['id'] : null,
    'print' => '1',
], static fn ($value): bool => $value !== null && $value !== ''));
?>
<style>
@media print {
    .no-print { display:none !important; }
    .card { box-shadow:none !important; border:1px solid #d6d6d6; }
}
</style>
<section class="topbar">
    <div class="brand">
        <h1><?= e($title ?? 'Rapport') ?></h1>
        <p>Suivi détaillé du stock, de la cuisine, des ventes, des pertes et des incidents sur une vraie période calendrier.</p>
    </div>
</section>
<section class="card no-print" style="padding:18px; margin-bottom:24px;">
    <div class="toolbar-actions">
        <button type="button" onclick="window.print()">Imprimer</button>
        <a href="/rapport?<?= e($printQuery) ?>" class="button-muted" target="_blank" rel="noopener noreferrer">Export imprimable / PDF navigateur</a>
    </div>
</section>

<section class="card" style="padding:22px; margin-bottom:24px;">
    <form method="get" action="/rapport">
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
        </select>
        <button type="submit">Afficher</button>
    </form>
    <p class="muted" style="margin-bottom:0;"><?= e($report['period_label'] ?? '') ?> · du <?= e(format_date_fr($report['range_start'] ?? null, $reportTimezone)) ?> au <?= e(format_date_fr($report['range_end'] ?? null, $reportTimezone)) ?> · Fuseau <?= e($report['timezone'] ?? $reportTimezone->getName()) ?></p>
</section>

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

<?php if (($report['incident_cases'] ?? []) !== []): ?>
    <section class="card" style="padding:22px; margin-top:24px;">
        <h2 style="margin-top:0;">Incidents et decisions signes</h2>
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
    </section>
<?php endif; ?>

<section class="split" style="margin-top:24px;">
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
