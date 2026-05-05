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
    'print' => '1',
], static fn ($value): bool => $value !== null && $value !== ''));
$cashClarity = $report['financial_report']['cash_clarity'] ?? [];
$people = $report['people_overview'] ?? [];
$activity = $report['activity_index'] ?? ['global_percent' => 0, 'agents' => []];
$timeline = $report['nominative_timeline'] ?? [];
$salesDetail = $report['sales_detail_by_server'] ?? ['servers' => [], 'grand_total' => 0];
$kitchenDetail = $report['kitchen_detail_by_cook'] ?? ['cooks' => [], 'grand_total_qty' => 0, 'grand_total_value' => 0];
$stockDetail = $report['stock_detail_by_person'] ?? ['people' => [], 'grand_total_movements' => 0];
?>
<style>
@media print {
    .no-print { display:none !important; }
    .card { box-shadow:none !important; border:1px solid #d6d6d6; }
}
.report-detail-nested > details { margin-top:12px; }
.report-detail-nested summary { cursor:pointer; }
</style>
<section class="topbar">
    <div class="brand">
        <h1><?= e($title ?? 'Rapport') ?></h1>
        <p>Suivi détaillé du stock, de la cuisine, des ventes, des pertes et des incidents sur une vraie période calendrier.</p>
    </div>
</section>

<?php if (!empty($report['financial_report']['summary'] ?? [])): ?>
    <section class="card" style="padding:22px; margin-top:24px;">
        <h2 style="margin-top:0;">Synthèse caisse (filtre période du rapport)</h2>
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
        </select>
        <label>Utilisateur</label>
        <select name="user_id">
            <option value="0">Tous</option>
            <?php foreach (($report_users ?? []) as $ru): ?>
                <option value="<?= e((string) $ru['id']) ?>" <?= (int) ($viewFilters['user_id'] ?? 0) === (int) $ru['id'] ? 'selected' : '' ?>><?= e(named_actor_label($ru['full_name'] ?? null, $ru['role_code'] ?? null)) ?></option>
            <?php endforeach; ?>
        </select>
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
        <label>Type d’action (code audit)</label>
        <input type="text" name="action_name" value="<?= e((string) ($viewFilters['action_name'] ?? '')) ?>" placeholder="sale_closed, cash_server_remitted…">
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
        <div style="margin-top:14px;"><button type="submit">Afficher</button></div>
    </form>
    </details>
    <p class="muted" style="margin-bottom:0;"><?= e($report['period_label'] ?? '') ?> · du <?= e(format_date_fr($report['range_start'] ?? null, $reportTimezone)) ?> au <?= e(format_date_fr($report['range_end'] ?? null, $reportTimezone)) ?> · Fuseau <?= e($report['timezone'] ?? $reportTimezone->getName()) ?></p>
</section>

<section class="card" style="padding:22px; margin-bottom:24px;">
    <h2 style="margin-top:0;">Par personne</h2>
    <p class="muted">Répartition sur la période sélectionnée (les filtres utilisateur / rôle s’appliquent aux blocs cuisine, stock et caisse ; les ventes par serveur suivent aussi le filtre « ventes clôturées »).</p>
    <div class="split" style="margin-top:12px;">
        <article style="flex:1; min-width:220px;">
            <h3 style="margin-top:0;">Ventes par serveur</h3>
            <?php if (($people['sales_by_server_rows'] ?? []) === []): ?><p class="muted">Aucune vente dans le filtre.</p><?php else: ?>
                <ul style="margin:0; padding-left:18px;">
                    <?php foreach ($people['sales_by_server_rows'] as $row): ?>
                        <li><?= e(named_actor_label($row['server_name'] ?? null, 'cashier_server')) ?> : <?= e((string) ($row['sales_count'] ?? 0)) ?> ventes — <?= e(format_money((float) ($row['total_amount'] ?? 0), $restaurantCurrency)) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>
        <article style="flex:1; min-width:220px;">
            <h3 style="margin-top:0;">Cuisine par cuisinier</h3>
            <?php if (($people['kitchen_by_cook'] ?? []) === []): ?><p class="muted">Aucune production.</p><?php else: ?>
                <ul style="margin:0; padding-left:18px;">
                    <?php foreach ($people['kitchen_by_cook'] as $row): ?>
                        <li><?= e(named_actor_label($row['full_name'] ?? null, $row['role_code'] ?? 'kitchen')) ?> : <?= e((string) (int) round((float) ($row['plates_prepared'] ?? 0))) ?> plats préparés<?php if ((int) ($row['productions_count'] ?? 0) > 0): ?> (<?= e((string) $row['productions_count']) ?> productions)<?php endif; ?></li>
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
                        <li><?= e(named_actor_label($row['full_name'] ?? null, $row['role_code'] ?? 'stock_manager')) ?> : <?= e((string) ($row['sorties_count'] ?? 0)) ?> sorties stock, <?= e((string) ($row['pertes_count'] ?? 0)) ?> pertes</li>
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
    <?php
    $gt = $people['grand_totals'] ?? [];
    ?>
    <div style="margin-top:18px; padding-top:14px; border-top:1px solid #e8e8e8;">
        <h3 style="margin-top:0;">Total général</h3>
        <p style="margin:0;"><strong>Ventes</strong> : <?= e((string) ($gt['sales_count'] ?? 0)) ?> · <?= e(format_money((float) ($gt['sales_amount'] ?? 0), $restaurantCurrency)) ?> &nbsp;|&nbsp;
            <strong>Plats préparés</strong> : <?= e((string) (int) round((float) ($gt['plates_prepared'] ?? 0))) ?> &nbsp;|&nbsp;
            <strong>Sorties stock</strong> : <?= e((string) ($gt['stock_sorties'] ?? 0)) ?> &nbsp;|&nbsp;
            <strong>Pertes stock</strong> : <?= e((string) ($gt['stock_pertes'] ?? 0)) ?></p>
    </div>
</section>

<section class="card" style="padding:22px; margin-bottom:24px;">
    <h2 style="margin-top:0;">Activité (indicateur, pas salaire ni prime)</h2>
    <p class="muted" style="margin-top:0;">Indice basé sur des actions réelles (ventes clôturées, production cuisine, mouvements stock validés, remises caisse, validations demandes stock). Le pourcentage compare chaque agent au plus actif sur la période (= 100&nbsp;%).</p>
    <p><strong>Activité globale du jour / période</strong> (moyenne des agents actifs) : <strong><?= e((string) ($activity['global_percent'] ?? 0)) ?> %</strong></p>
    <?php if (($activity['agents'] ?? []) === []): ?>
        <p class="muted">Pas assez d’actions pour calculer un indice.</p>
    <?php else: ?>
        <ul style="margin:0; padding-left:18px;">
            <?php foreach ($activity['agents'] as $ag): ?>
                <li><?= e(named_actor_label($ag['full_name'] ?? null, $ag['role_code'] ?? null)) ?> : <?= e((string) ($ag['activity_percent'] ?? 0)) ?> %</li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="card" style="padding:22px; margin-bottom:24px;">
    <h2 style="margin-top:0;">Historique nominatif</h2>
    <p class="muted" style="margin-top:0;">Lignes synthétiques (ventes clôturées) et entrées d’audit ; filtres périmètre et code d’action ci-dessus.</p>
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
</section>

<section class="card no-print" style="padding:22px; margin-bottom:24px;">
    <details class="compact-card" data-autoclose-details>
        <summary><strong>Rapports détaillés — par personne et par produit</strong></summary>
        <p class="muted" style="margin-top:12px; margin-bottom:0;">Sections repliables pour éviter de surcharger l’écran ; adapté mobile. Les filtres ci-dessus (période, personne, rôle, produit carte, article stock, type de mouvement) s’appliquent.</p>
        <div class="report-detail-nested" style="margin-top:16px;">
            <details>
                <summary><strong>1. Ventes par serveur et par produit</strong> · Total général <?= e(format_money((float) ($salesDetail['grand_total'] ?? 0), $restaurantCurrency)) ?></summary>
                <?php if (($salesDetail['servers'] ?? []) === []): ?>
                    <p class="muted" style="margin-top:12px;">Aucune ligne dans le filtre.</p>
                <?php else: ?>
                    <?php foreach ($salesDetail['servers'] as $srv): ?>
                        <details style="margin-top:12px; padding:12px; border:1px solid var(--line, #e0e0e0); border-radius:12px;">
                            <summary>Serveur <?= e(named_actor_label($srv['server_name'] ?? null, $srv['server_role_code'] ?? 'cashier_server')) ?> · Total <?= e(format_money((float) ($srv['server_total'] ?? 0), $restaurantCurrency)) ?></summary>
                            <ul style="margin:12px 0 0; padding-left:18px;">
                                <?php foreach (($srv['lines'] ?? []) as $ln): ?>
                                    <li><?= e((string) ($ln['menu_item_name'] ?? '')) ?> x<?php
                                    $qs = (float) ($ln['qty_sold'] ?? 0);
                                    echo e(abs($qs - round($qs)) < 0.001 ? (string) (int) round($qs) : (string) $qs);
                                    ?> = <?= e(format_money((float) ($ln['line_total'] ?? 0), $restaurantCurrency)) ?></li>
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
                            <summary><?= e(named_actor_label($ck['cook_name'] ?? null, $ck['role_code'] ?? 'kitchen')) ?> · <?= e(format_money((float) ($ck['cook_total_value'] ?? 0), $restaurantCurrency)) ?> · <?= e((string) ($ck['cook_total_qty'] ?? 0)) ?> unités</summary>
                            <p class="muted" style="margin:10px 0 6px;"><strong>Plats</strong></p>
                            <ul style="margin:0; padding-left:18px;">
                                <?php foreach (($ck['dishes'] ?? []) as $d): ?>
                                    <li><?= e((string) ($d['dish_label'] ?? '')) ?> · qté <?= e((string) ($d['qty_produced'] ?? 0)) ?> · <?= e(format_money((float) ($d['value_produced'] ?? 0), $restaurantCurrency)) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if (($ck['materials'] ?? []) !== []): ?>
                                <p class="muted" style="margin:12px 0 6px;"><strong>Matières (mouvements liés)</strong></p>
                                <ul style="margin:0; padding-left:18px;">
                                    <?php foreach ($ck['materials'] as $mat): ?>
                                        <li><?= e((string) ($mat['name'] ?? '')) ?> · <?= e((string) ($mat['quantity'] ?? 0)) ?></li>
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
                            <summary><?= e(named_actor_label($sp['full_name'] ?? null, $sp['role_code'] ?? 'stock_manager')) ?> · <?= e((string) ($sp['total_movements'] ?? 0)) ?> mouvements</summary>
                            <p style="margin:10px 0 6px;" class="muted">Entrées <?= e((string) ($sp['entrees_lines'] ?? 0)) ?> (qté <?= e((string) ($sp['entrees_qty'] ?? 0)) ?>) · Sorties <?= e((string) ($sp['sorties_lines'] ?? 0)) ?> (<?= e((string) ($sp['sorties_qty'] ?? 0)) ?>) · Pertes <?= e((string) ($sp['pertes_lines'] ?? 0)) ?> · Retours <?= e((string) ($sp['retours_lines'] ?? 0)) ?></p>
                            <?php if (($sp['product_lines'] ?? []) !== []): ?>
                                <div class="table-wrap" style="margin-top:8px;">
                                    <table>
                                        <thead><tr><th>Produit</th><th>Type</th><th>Lignes</th><th>Qté</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($sp['product_lines'] as $pl): ?>
                                            <tr>
                                                <td><?= e((string) ($pl['product_name'] ?? '')) ?></td>
                                                <td><?= e((string) ($pl['movement_type'] ?? '')) ?></td>
                                                <td><?= e((string) ($pl['line_count'] ?? 0)) ?></td>
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
    </details>
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
