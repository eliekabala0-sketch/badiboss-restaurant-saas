<?php
declare(strict_types=1);
/** @var array<string, mixed>|null $stock_control_bundle */
/** @var array<string, mixed>|null $restaurant */
/** @var string $stock_control_return_to report|stock */
$scb = $stock_control_bundle ?? null;
$retTo = ($stock_control_return_to ?? 'report') === 'stock' ? 'stock' : 'report';
if (!is_array($scb) || $scb === []) {
    return;
}
$ridHidden = ((current_user()['scope'] ?? null) === 'super_admin' && is_array($restaurant) && !empty($restaurant['id']))
    ? '<input type="hidden" name="restaurant_id" value="' . e((string) (int) $restaurant['id']) . '">' . "\n"
    : '';
$articles = $scb['articles'] ?? [];
$cats = $scb['categories'] ?? [];
$plates = $scb['prepared_plates'] ?? [];
$checks = $scb['checks_recent'] ?? [];
$summary = is_array($scb['summary'] ?? null) ? $scb['summary'] : [];
$controlGroups = is_array($scb['control_groups'] ?? null) ? $scb['control_groups'] : [];
$periodLabel = (string) ($scb['period_label'] ?? '');
$dateYmd = (string) ($scb['date_ymd'] ?? '');
$periodKey = (string) ($scb['period_key'] ?? 'daily');
$stockQs = $stock_control_stock_query ?? '';
$selectedStockControlItemId = (int) ($stock_control_item_id ?? 0);
$stockControlCurrency = restaurant_currency(is_array($restaurant ?? null) ? $restaurant : []);
$stockControlItems = is_array($stock_control_items ?? null)
    ? $stock_control_items
    : (is_array($items ?? null) ? $items : []);
?>
<style>
.stock-control-wrap details { margin-top: 14px; }
.stock-control-wrap summary { cursor: pointer; font-weight: 600; }
.stock-cc-gap { color: #fecaca; font-weight: 700; }
.stock-cc-ok { color: #86efac; }
.stock-cc-status { white-space: nowrap; }
.stock-cc-filter { display:grid; gap:12px; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); align-items:end; margin:12px 0; }
.stock-cc-loss { color:#fecaca; font-weight:700; }
.stock-cc-total-row { border-top:1px solid rgba(255,255,255,0.16); font-weight:700; }
@media (max-width: 720px) {
    .stock-cc-desktop-table { display: none !important; }
    .stock-cc-cards { display: grid !important; gap: 12px; }
}
@media (min-width: 721px) {
    .stock-cc-cards { display: none !important; }
}
.stock-cc-bigbtn { min-height: 48px; font-size: 1.05rem; margin-top: 10px; }
</style>
<script>
document.addEventListener('input', function (event) {
    var input = event.target.closest('[data-physical-found]');
    if (!input) {
        return;
    }
    var row = input.closest('[data-physical-row]');
    if (!row) {
        return;
    }
    var expected = Number(row.getAttribute('data-expected-total') || '0');
    var unitCost = Number(row.getAttribute('data-unit-cost') || '0');
    var found = Number(String(input.value || '').replace(',', '.'));
    var gapCell = row.querySelector('[data-physical-gap]');
    var valueCell = row.querySelector('[data-physical-value]');
    if (!Number.isFinite(found)) {
        if (gapCell) { gapCell.textContent = '-'; }
        if (valueCell) { valueCell.textContent = '-'; }
        return;
    }
    var gap = found - expected;
    var loss = Math.max(0, -gap) * unitCost;
    if (gapCell) { gapCell.textContent = gap.toFixed(2); }
    if (valueCell) { valueCell.textContent = loss.toFixed(2); }
});
</script>
<section class="card stock-control-wrap no-print" style="padding:18px 22px; margin-top:18px; border-left:4px solid #0f766e;">
    <details class="report-section-details" <?= ($stock_control_open ?? false) ? 'open' : '' ?> data-autoclose-details>
        <summary>Contrôle stock · <?= e($periodLabel) ?></summary>
        <div class="report-section-body">
            <p class="muted" style="margin-top:0;"><?= e((string) ($scb['formula_note'] ?? '')) ?></p>
            <p style="margin:10px 0;"><strong>Période affichée</strong> : <?= e($dateYmd) ?> · <?= e($periodKey) ?></p>

            <?php if ($retTo === 'stock'): ?>
            <form method="get" action="/stock" class="stock-cc-filter">
                <?= $ridHidden ?>
                <label>Date
                    <input type="date" name="sc_date" value="<?= e($dateYmd) ?>">
                </label>
                <label>Periode
                    <select name="sc_period">
                        <option value="daily" <?= $periodKey === 'daily' ? 'selected' : '' ?>>Jour</option>
                        <option value="weekly" <?= $periodKey === 'weekly' ? 'selected' : '' ?>>Semaine</option>
                        <option value="monthly" <?= $periodKey === 'monthly' ? 'selected' : '' ?>>Mois</option>
                    </select>
                </label>
                <label>Produit
                    <select name="sc_item_id">
                        <option value="0">Tous les produits</option>
                        <?php foreach ($stockControlItems as $it): ?>
                            <?php if (!empty($it['archived_at'])) { continue; } ?>
                            <option value="<?= e((string) (int) ($it['id'] ?? 0)) ?>" <?= $selectedStockControlItemId === (int) ($it['id'] ?? 0) ? 'selected' : '' ?>><?= e((string) ($it['name'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php if (($stock_category_filter ?? 'all') !== 'all' && ($stock_category_filter ?? '') !== ''): ?>
                    <input type="hidden" name="stock_cat" value="<?= e((string) $stock_category_filter) ?>">
                <?php endif; ?>
                <button type="submit">Actualiser</button>
            </form>
            <?php endif; ?>

            <details open style="margin-top:14px;">
                <summary>Résumé (période sélectionnée)</summary>
                <div class="grid stats" style="margin-top:12px;">
                    <article class="card stat"><span>Articles suivis</span><strong><?= e((string) count($articles)) ?></strong></article>
                    <article class="card stat"><span>Plats préparés restants</span><strong><?= e((string) count($plates)) ?></strong></article>
                    <article class="card stat"><span>Derniers contrôles physiques</span><strong><?= e((string) count($checks)) ?></strong></article>
                    <article class="card stat"><span>Avec comptage periode</span><strong><?= e((string) (int) ($summary['with_physical_check'] ?? 0)) ?></strong></article>
                    <article class="card stat"><span>Manquants</span><strong><?= e((string) (int) ($summary['manquant'] ?? 0)) ?></strong></article>
                    <article class="card stat"><span>Valeur ecarts</span><strong><?= e(format_money((float) ($summary['gap_value_total'] ?? 0), $stockControlCurrency)) ?></strong></article>
                    <article class="card stat"><span>Stock total</span><strong><?= e((string) ($summary['stock_total_qty'] ?? 0)) ?></strong></article>
                    <article class="card stat"><span>Valeur stock total</span><strong><?= e(format_money((float) ($summary['stock_total_value'] ?? 0), $stockControlCurrency)) ?></strong></article>
                    <article class="card stat"><span>Perte estimee</span><strong><?= e(format_money((float) ($summary['missing_value_total'] ?? 0), $stockControlCurrency)) ?></strong></article>
                </div>
                <p class="muted" style="margin:10px 0 0;">Stock total = stock principal + stock cuisine. La valeur utilise le prix article ou, si absent, le prix calcule depuis les achats de la periode.</p>
            </details>

            <details open style="margin-top:14px;">
                <summary>Manquants par zone</summary>
                <div class="report-table-desktop stock-cc-desktop-table" style="overflow-x:auto; margin-top:12px;">
                    <table class="table-clean" style="width:100%; border-collapse:collapse;">
                        <thead><tr>
                            <th>Zone</th>
                            <th>Stock attendu</th>
                            <th>Stock trouve</th>
                            <th>Manquant quantite</th>
                            <th>Valeur perte</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach (['boisson' => 'TOTAL BOISSON', 'general' => 'TOTAL STOCK', 'global' => 'TOTAL GLOBAL MANQUANT'] as $groupKey => $groupTitle): ?>
                            <?php $gr = is_array($controlGroups[$groupKey] ?? null) ? $controlGroups[$groupKey] : []; ?>
                            <tr class="<?= $groupKey === 'global' ? 'stock-cc-total-row' : '' ?>">
                                <td><?= e($groupTitle) ?><br><span class="muted"><?= e((string) (int) ($gr['articles_count'] ?? 0)) ?> article(s)</span></td>
                                <td><?= e((string) ($gr['expected_total'] ?? 0)) ?></td>
                                <td><?= e((string) ($gr['actual_total'] ?? 0)) ?></td>
                                <td class="stock-cc-loss"><?= e((string) ($gr['missing_qty_total'] ?? 0)) ?></td>
                                <td class="stock-cc-loss"><?= e(format_money((float) ($gr['missing_value_total'] ?? 0), $stockControlCurrency)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="stock-cc-cards">
                    <?php foreach (['boisson' => 'TOTAL BOISSON', 'general' => 'TOTAL STOCK', 'global' => 'TOTAL GLOBAL MANQUANT'] as $groupKey => $groupTitle): ?>
                        <?php $gr = is_array($controlGroups[$groupKey] ?? null) ? $controlGroups[$groupKey] : []; ?>
                        <article class="report-agent-card">
                            <strong><?= e($groupTitle) ?></strong>
                            <p>Attendu <?= e((string) ($gr['expected_total'] ?? 0)) ?> · Trouve <?= e((string) ($gr['actual_total'] ?? 0)) ?></p>
                            <p>Manquant <strong><?= e((string) ($gr['missing_qty_total'] ?? 0)) ?></strong> · <strong><?= e(format_money((float) ($gr['missing_value_total'] ?? 0), $stockControlCurrency)) ?></strong></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </details>

            <details open style="margin-top:14px;">
                <summary>Stock attendu vs reel</summary>
                <div class="report-table-desktop stock-cc-desktop-table" style="overflow-x:auto; margin-top:12px;">
                    <table class="table-clean" style="width:100%; border-collapse:collapse; font-size:0.92rem;">
                        <thead><tr>
                            <th>Produit</th>
                            <th>Stock attendu</th>
                            <th>Stock trouve</th>
                            <th>Stock total</th>
                            <th>Ecart</th>
                            <th>Prix unitaire</th>
                            <th>Valeur ecart</th>
                            <th>Perte estimee</th>
                            <th>Ventes liees</th>
                            <th>Statut</th>
                            <th>A verifier</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($articles as $ar): ?>
                            <?php $gapQty = (float) ($ar['gap_qty_total'] ?? 0); ?>
                            <tr>
                                <td><strong><?= e((string) ($ar['name'] ?? '')) ?></strong><br><span class="muted"><?= e((string) ($ar['unit_name'] ?? '')) ?></span></td>
                                <td><?= e((string) ($ar['expected_total_end'] ?? 0)) ?></td>
                                <td><?= e((string) ($ar['actual_total_found'] ?? 0)) ?><?php if (empty($ar['physical_check_found'])): ?><br><span class="muted">systeme</span><?php endif; ?></td>
                                <td><?= e((string) ($ar['stock_total_qty'] ?? 0)) ?><br><span class="muted"><?= e(format_money((float) ($ar['stock_total_value'] ?? 0), $stockControlCurrency)) ?></span></td>
                                <td class="<?= $gapQty < -0.0001 ? 'stock-cc-gap' : ($gapQty > 0.0001 ? 'stock-cc-ok' : '') ?>"><?= e((string) ($ar['gap_qty_total'] ?? 0)) ?></td>
                                <td><?= e(format_money((float) ($ar['estimated_unit_cost'] ?? 0), $stockControlCurrency)) ?><br><span class="muted"><?= e((string) ($ar['unit_cost_label'] ?? '')) ?></span></td>
                                <td><?= e(format_money((float) ($ar['gap_value_total'] ?? 0), $stockControlCurrency)) ?></td>
                                <td class="stock-cc-loss"><?= e(format_money((float) ($ar['missing_value_total'] ?? 0), $stockControlCurrency)) ?></td>
                                <td><?= e((string) ($ar['sales_linked_consumption_qty'] ?? 0)) ?><br><span class="muted"><?= e((string) (int) ($ar['sales_linked_lines'] ?? 0)) ?> ligne(s)</span></td>
                                <td><span class="pill stock-cc-status <?= e((string) ($ar['stock_status_class'] ?? 'badge-neutral')) ?>"><?= e((string) ($ar['stock_status_label'] ?? 'A verifier')) ?></span></td>
                                <td class="muted"><?= e((string) ($ar['probable_breakpoint'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="stock-cc-cards">
                    <?php foreach ($articles as $ar): ?>
                        <article class="report-agent-card">
                            <strong><?= e((string) ($ar['name'] ?? '')) ?></strong>
                            <p>Total <strong><?= e((string) ($ar['stock_total_qty'] ?? 0)) ?></strong> · <?= e(format_money((float) ($ar['stock_total_value'] ?? 0), $stockControlCurrency)) ?></p>
                            <p class="stock-cc-loss">Perte estimee <?= e(format_money((float) ($ar['missing_value_total'] ?? 0), $stockControlCurrency)) ?></p>
                            <p>Attendu <strong><?= e((string) ($ar['expected_total_end'] ?? 0)) ?></strong> · Reel <strong><?= e((string) ($ar['actual_total_found'] ?? 0)) ?></strong></p>
                            <p>Ecart <strong><?= e((string) ($ar['gap_qty_total'] ?? 0)) ?></strong> · <?= e(format_money((float) ($ar['gap_value_total'] ?? 0), $stockControlCurrency)) ?></p>
                            <p><span class="pill <?= e((string) ($ar['stock_status_class'] ?? 'badge-neutral')) ?>"><?= e((string) ($ar['stock_status_label'] ?? 'A verifier')) ?></span></p>
                            <p class="muted"><?= e((string) ($ar['probable_breakpoint'] ?? '')) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </details>

            <details style="margin-top:14px;">
                <summary>Par catégorie</summary>
                <div class="report-table-desktop stock-cc-desktop-table" style="overflow-x:auto; margin-top:12px;">
                    <table class="table-clean" style="width:100%; border-collapse:collapse;">
                        <thead><tr>
                            <th>Catégorie</th>
                            <th>Dépôt début (mag.)</th>
                            <th>Entrées</th>
                            <th>Sorties cuisine</th>
                            <th>Sorties autres</th>
                            <th>Vendu / utilisé (conso.)</th>
                            <th>Pertes</th>
                            <th>Reste magasin</th>
                            <th>Reste cuisine</th>
                            <th>Total disponible</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($cats as $cr): ?>
                            <tr>
                                <td><?= e((string) ($cr['macro_category'] ?? '')) ?></td>
                                <td><?= e((string) ($cr['opening_store'] ?? '')) ?></td>
                                <td><?= e((string) ($cr['period_entrees'] ?? '')) ?></td>
                                <td><?= e((string) ($cr['period_sortie_cuisine'] ?? '')) ?></td>
                                <td><?= e((string) ($cr['period_sortie_autre'] ?? '')) ?></td>
                                <td><?= e((string) ($cr['period_conso_cuisine'] ?? '')) ?></td>
                                <td><?= e((string) ($cr['period_pertes'] ?? '')) ?></td>
                                <td><?= e((string) ($cr['qty_store_now'] ?? '')) ?></td>
                                <td><?= e((string) ($cr['qty_kitchen_now'] ?? '')) ?></td>
                                <td><strong><?= e((string) ($cr['qty_total_available'] ?? '')) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="stock-cc-cards">
                    <?php foreach ($cats as $cr): ?>
                        <article class="report-agent-card">
                            <strong><?= e((string) ($cr['macro_category'] ?? '')) ?></strong>
                            <p class="muted">Dispo total <strong><?= e((string) ($cr['qty_total_available'] ?? '')) ?></strong></p>
                            <p>Mag <?= e((string) ($cr['qty_store_now'] ?? '')) ?> · Cuis <?= e((string) ($cr['qty_kitchen_now'] ?? '')) ?></p>
                            <p class="muted">Entrées <?= e((string) ($cr['period_entrees'] ?? '')) ?> · SC <?= e((string) ($cr['period_sortie_cuisine'] ?? '')) ?> · Util <?= e((string) ($cr['period_conso_cuisine'] ?? '')) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </details>

            <details style="margin-top:14px;">
                <summary>Par article</summary>
                <div class="report-table-desktop stock-cc-desktop-table" style="overflow-x:auto; margin-top:12px;">
                    <table class="table-clean" style="width:100%; border-collapse:collapse; font-size:0.92rem;">
                        <thead><tr>
                            <th>Article</th>
                            <th>Cat.</th>
                            <th>Unité</th>
                            <th>Dépôt mag.</th>
                            <th>Entr.</th>
                            <th>Sor. cuis.</th>
                            <th>Sor. aut.</th>
                            <th>Util./vendu</th>
                            <th>Pertes</th>
                            <th>Retours</th>
                            <th>Corr. mag.</th>
                            <th>Reste mag.</th>
                            <th>Reste cuis.</th>
                            <th>Total</th>
                            <th>Coh.</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($articles as $ar): ?>
                            <?php $coh = !empty($ar['store_coherent']); ?>
                            <tr>
                                <td><?= e((string) ($ar['name'] ?? '')) ?></td>
                                <td><?= e((string) ($ar['macro_category'] ?? '')) ?></td>
                                <td><?= e((string) ($ar['unit_name'] ?? '')) ?></td>
                                <td><?= e((string) ($ar['opening_store'] ?? '')) ?></td>
                                <td><?= e((string) ($ar['period_entrees'] ?? '')) ?></td>
                                <td><?= e((string) ($ar['period_sortie_cuisine'] ?? '')) ?></td>
                                <td><?= e((string) ($ar['period_sortie_autre'] ?? '')) ?></td>
                                <td><?= e((string) ($ar['period_conso_cuisine'] ?? '')) ?></td>
                                <td><?= e((string) ($ar['period_pertes'] ?? '')) ?></td>
                                <td><?= e((string) ($ar['period_retours'] ?? '')) ?></td>
                                <td><?= e((string) ($ar['period_corrections_magasin'] ?? '')) ?></td>
                                <td><?= e((string) ($ar['qty_store_now'] ?? '')) ?></td>
                                <td><?= e((string) ($ar['qty_kitchen_now'] ?? '')) ?></td>
                                <td><strong><?= e((string) ($ar['qty_total_available'] ?? '')) ?></strong></td>
                                <td class="<?= $coh ? 'stock-cc-ok' : 'stock-cc-gap' ?>"><?= $coh ? 'OK' : '!' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="stock-cc-cards">
                    <?php foreach ($articles as $ar): ?>
                        <?php $coh = !empty($ar['store_coherent']); ?>
                        <article class="report-agent-card">
                            <strong><?= e((string) ($ar['name'] ?? '')) ?></strong>
                            <p class="muted"><?= e((string) ($ar['macro_category'] ?? '')) ?> · <?= e((string) ($ar['unit_name'] ?? '')) ?></p>
                            <p>Total <strong><?= e((string) ($ar['qty_total_available'] ?? '')) ?></strong> (mag <?= e((string) ($ar['qty_store_now'] ?? '')) ?> / cuis <?= e((string) ($ar['qty_kitchen_now'] ?? '')) ?>)</p>
                            <p class="muted">Mag cohérence : <span class="<?= $coh ? 'stock-cc-ok' : 'stock-cc-gap' ?>"><?= $coh ? 'OK' : 'À vérifier' ?></span></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </details>

            <?php if ($plates !== []): ?>
            <details style="margin-top:14px;">
                <summary>Plats préparés encore disponibles cuisine (non vendus)</summary>
                <ul style="margin:10px 0 0 18px;">
                    <?php foreach ($plates as $pp): ?>
                        <li><?= e((string) ($pp['dish_label'] ?? '')) ?> — reste <?= e((string) ($pp['quantity_remaining'] ?? '')) ?> / fait <?= e((string) ($pp['quantity_produced'] ?? '')) ?></li>
                    <?php endforeach; ?>
                </ul>
            </details>
            <?php endif; ?>

            <?php if (can_access('stock.control.perform')): ?>
            <details style="margin-top:14px;">
                <summary>Contrôle physique stock</summary>
                <p class="muted">Saisissez les quantités <strong>réellement trouvées</strong>. En cas d’écart, un <strong>motif obligatoire</strong> est demandé. Aucune correction automatique du stock : audit uniquement.</p>
                <form method="post" action="/stock/controle-physique" style="margin-top:12px;">
                    <?= $ridHidden ?>
                    <input type="hidden" name="return_to" value="<?= e($retTo) ?>">
                    <input type="hidden" name="sc_date" value="<?= e($dateYmd) ?>">
                    <input type="hidden" name="sc_period" value="<?= e($periodKey) ?>">
                    <input type="hidden" name="pc_period_start" value="<?= e((string) ($scb['period_start_at'] ?? '')) ?>">
                    <input type="hidden" name="pc_period_end" value="<?= e((string) ($scb['period_end_at'] ?? '')) ?>">
                    <label>Note générale (facultatif)</label>
                    <textarea name="pc_note" rows="2" style="width:100%; margin-bottom:12px;" placeholder="Commentaire contrôle inventaire"></textarea>
                    <div style="overflow-x:auto;">
                        <table class="table-clean" style="width:100%; border-collapse:collapse; font-size:0.9rem;">
                            <thead><tr>
                                <th>Article</th>
                                <th>Categorie</th>
                                <th>Stock attendu</th>
                                <th>Stock physique trouve</th>
                                <th>Ecart quantite</th>
                                <th>Prix unitaire</th>
                                <th>Valeur perte</th>
                                <th>Statut</th>
                                <th>Attendu mag.</th>
                                <th>Trouvé mag.</th>
                                <th>Attendu cuis.</th>
                                <th>Trouvé cuis.</th>
                                <th>Motif si écart</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($articles as $idx => $ar): ?>
                                <tr data-physical-row data-expected-total="<?= e((string) (float) ($ar['expected_total_end'] ?? 0)) ?>" data-unit-cost="<?= e((string) (float) ($ar['estimated_unit_cost'] ?? 0)) ?>">
                                    <td><?= e((string) ($ar['name'] ?? '')) ?>
                                        <input type="hidden" name="pc_stock_item_id[<?= (string) $idx ?>]" value="<?= e((string) (int) ($ar['id'] ?? 0)) ?>">
                                    </td>
                                    <td><?= e((string) ($ar['macro_category'] ?? '')) ?></td>
                                    <td><?= e((string) ($ar['expected_total_end'] ?? 0)) ?></td>
                                    <td><input name="pc_found_total[<?= (string) $idx ?>]" type="text" inputmode="decimal" data-physical-found style="width:96px;" placeholder="Trouve"></td>
                                    <td data-physical-gap>-</td>
                                    <td><?= e(format_money((float) ($ar['estimated_unit_cost'] ?? 0), $stockControlCurrency)) ?><br><span class="muted"><?= e((string) ($ar['unit_cost_label'] ?? '')) ?></span></td>
                                    <td class="stock-cc-loss" data-physical-value>-</td>
                                    <td><span class="pill <?= e((string) ($ar['stock_status_class'] ?? 'badge-neutral')) ?>"><?= e((string) ($ar['stock_status_label'] ?? 'A verifier')) ?></span></td>
                                    <td><?= e((string) ($ar['expected_store_end'] ?? ($ar['qty_store_now'] ?? ''))) ?></td>
                                    <td><input name="pc_found_store[<?= (string) $idx ?>]" type="text" inputmode="decimal" style="width:88px;" placeholder="—"></td>
                                    <td><?= e((string) ($ar['expected_kitchen_end'] ?? ($ar['qty_kitchen_now'] ?? ''))) ?></td>
                                    <td><input name="pc_found_kitchen[<?= (string) $idx ?>]" type="text" inputmode="decimal" style="width:88px;" placeholder="—"></td>
                                    <td><input name="pc_gap_motif[<?= (string) $idx ?>]" type="text" style="min-width:160px;" placeholder="Si écart"></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button type="submit" class="stock-cc-bigbtn button-primary">Enregistrer le contrôle physique</button>
                </form>
            </details>
            <?php endif; ?>

            <?php if ($checks !== []): ?>
            <details style="margin-top:14px;">
                <summary>Historique contrôles</summary>
                <div class="report-table-desktop stock-cc-desktop-table" style="overflow-x:auto; margin-top:12px;">
                    <table class="table-clean" style="width:100%; font-size:0.88rem;">
                        <thead><tr>
                            <th>Date</th><th>Article</th><th>Écart mag.</th><th>Écart cuis.</th><th>Total</th><th>Motif</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($checks as $ck): ?>
                            <?php
                            $gtot = (float) ($ck['gap_total'] ?? 0);
                            $rowGap = abs($gtot) > 0.0001;
                            ?>
                            <tr class="<?= $rowGap ? 'stock-cc-gap' : '' ?>">
                                <td><?= e(format_date_fr($ck['created_at'] ?? null)) ?></td>
                                <td><?= e((string) ($ck['stock_item_name'] ?? '')) ?></td>
                                <td><?= e((string) ($ck['gap_store'] ?? '')) ?></td>
                                <td><?= e((string) ($ck['gap_kitchen'] ?? '')) ?></td>
                                <td><?= e((string) ($ck['gap_total'] ?? '')) ?></td>
                                <td><?= e((string) ($ck['gap_motif'] ?? '—')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
            <?php endif; ?>

            <?php if ($retTo === 'stock' && $stockQs !== ''): ?>
                <p class="muted" style="margin-top:14px;"><a href="/stock?<?= $stockQs ?>">Ajuster la période du rapport (date / granularité)</a></p>
            <?php endif; ?>
        </div>
    </details>
</section>
