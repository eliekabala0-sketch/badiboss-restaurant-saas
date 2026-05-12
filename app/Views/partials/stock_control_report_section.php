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
$periodLabel = (string) ($scb['period_label'] ?? '');
$dateYmd = (string) ($scb['date_ymd'] ?? '');
$periodKey = (string) ($scb['period_key'] ?? 'daily');
$stockQs = $stock_control_stock_query ?? '';
?>
<style>
.stock-control-wrap details { margin-top: 14px; }
.stock-control-wrap summary { cursor: pointer; font-weight: 600; }
.stock-cc-gap { color: #fecaca; font-weight: 700; }
.stock-cc-ok { color: #86efac; }
@media (max-width: 720px) {
    .stock-cc-desktop-table { display: none !important; }
    .stock-cc-cards { display: grid !important; gap: 12px; }
}
@media (min-width: 721px) {
    .stock-cc-cards { display: none !important; }
}
.stock-cc-bigbtn { min-height: 48px; font-size: 1.05rem; margin-top: 10px; }
</style>
<section class="card stock-control-wrap no-print" style="padding:18px 22px; margin-top:18px; border-left:4px solid #0f766e;">
    <details class="report-section-details" <?= ($stock_control_open ?? false) ? 'open' : '' ?> data-autoclose-details>
        <summary>Contrôle stock · <?= e($periodLabel) ?></summary>
        <div class="report-section-body">
            <p class="muted" style="margin-top:0;"><?= e((string) ($scb['formula_note'] ?? '')) ?></p>
            <p style="margin:10px 0;"><strong>Période affichée</strong> : <?= e($dateYmd) ?> · <?= e($periodKey) ?></p>

            <details open style="margin-top:14px;">
                <summary>Résumé (période sélectionnée)</summary>
                <div class="grid stats" style="margin-top:12px;">
                    <article class="card stat"><span>Articles suivis</span><strong><?= e((string) count($articles)) ?></strong></article>
                    <article class="card stat"><span>Plats préparés restants</span><strong><?= e((string) count($plates)) ?></strong></article>
                    <article class="card stat"><span>Derniers contrôles physiques</span><strong><?= e((string) count($checks)) ?></strong></article>
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
                                <th>Attendu mag.</th>
                                <th>Trouvé mag.</th>
                                <th>Attendu cuis.</th>
                                <th>Trouvé cuis.</th>
                                <th>Motif si écart</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($articles as $idx => $ar): ?>
                                <tr>
                                    <td><?= e((string) ($ar['name'] ?? '')) ?>
                                        <input type="hidden" name="pc_stock_item_id[<?= (string) $idx ?>]" value="<?= e((string) (int) ($ar['id'] ?? 0)) ?>">
                                    </td>
                                    <td><?= e((string) ($ar['qty_store_now'] ?? '')) ?></td>
                                    <td><input name="pc_found_store[<?= (string) $idx ?>]" type="text" inputmode="decimal" style="width:88px;" placeholder="—"></td>
                                    <td><?= e((string) ($ar['qty_kitchen_now'] ?? '')) ?></td>
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
