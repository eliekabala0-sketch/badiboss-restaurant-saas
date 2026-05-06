<section class="topbar">
    <div class="brand">
        <h1>Super administration</h1>
        <p>Vue d ensemble courte, claire et orientee action pour gerer la plateforme sans ecran technique surcharge.</p>
    </div>
</section>

<?php if (!empty($flash_success ?? null)): ?><div class="flash-ok"><?= e($flash_success) ?></div><?php endif; ?>
<?php if (!empty($flash_error ?? null)): ?><div class="flash-bad"><?= e($flash_error) ?></div><?php endif; ?>

<?php
$stockFilters = $stock_reset_preview['filters'] ?? [];
$stockPeriodPreset = $stockFilters['stock_period_preset'] ?? ($_POST['stock_period_preset'] ?? 'today');
$stockOptionsSelected = isset($stockFilters['stock_options']) && is_array($stockFilters['stock_options'])
    ? $stockFilters['stock_options']
    : (array) ($_POST['stock_options'] ?? []);
$stockCheckboxDefs = [
    'qty_magasin_zero' => 'Remettre les quantités magasin à zéro (les articles restent)',
    'mouvements_magasin' => 'Effacer les mouvements stock magasin sur la période',
    'qty_cuisine_zero' => 'Remettre les quantités cuisine à zéro (les lignes restent)',
    'mouvements_cuisine' => 'Effacer les mouvements liés à la cuisine sur la période',
    'inventaire_cuisine_lignes' => 'Effacer les lignes d’inventaire cuisine sur la période',
    'pertes' => 'Effacer les pertes matière sur la période',
    'corrections_stock' => 'Effacer les demandes de correction stock sur la période',
    'demandes_cuisine_stock' => 'Effacer les demandes cuisine vers stock sur la période',
];
$stockPreviewCountLabels = [
    'articles_magasin_pour_zero' => 'Articles magasin concernés (quantité → 0)',
    'lignes_cuisine_pour_zero' => 'Lignes cuisine concernées (quantité → 0)',
    'mouvements_magasin' => 'Mouvements magasin à effacer',
    'mouvements_magasin_exclus_production' => 'Mouvements magasin non effaçables (production en cours)',
    'mouvements_cuisine' => 'Mouvements cuisine à effacer',
    'pertes' => 'Pertes à effacer',
    'corrections_stock' => 'Corrections stock à effacer',
    'demandes_cuisine_stock' => 'Demandes cuisine vers stock à effacer',
    'demandes_cuisine_stock_lignes' => 'Lignes de ces demandes',
    'inventaire_cuisine_lignes' => 'Lignes inventaire cuisine à effacer',
];
$stockDoneLabels = [
    'kitchen_stock_request_items' => 'lignes demandes',
    'kitchen_stock_requests' => 'demandes cuisine-stock',
    'correction_requests' => 'corrections',
    'losses' => 'pertes',
    'stock_movements_cuisine' => 'mouv. cuisine',
    'stock_movements_magasin' => 'mouv. magasin',
    'kitchen_inventory_period' => 'lignes inventaire cuisine',
    'stock_items_qty_zeroed' => 'qty magasin → 0',
    'kitchen_inventory_qty_zeroed' => 'qty cuisine → 0',
];
$stockDoneReportLabels = [
    'kitchen_stock_request_items' => 'Lignes de demandes cuisine → stock effacées',
    'kitchen_stock_requests' => 'Demandes cuisine → stock effacées',
    'correction_requests' => 'Corrections stock effacées',
    'losses' => 'Pertes effacées',
    'stock_movements_cuisine' => 'Mouvements cuisine effacés',
    'stock_movements_magasin' => 'Mouvements magasin effacés',
    'kitchen_inventory_period' => 'Lignes inventaire cuisine effacées',
    'stock_items_qty_zeroed' => 'Articles magasin remis à quantité 0',
    'kitchen_inventory_qty_zeroed' => 'Lignes cuisine remises à quantité 0',
];
?>

<?php $resetFilters = $reset_preview['filters'] ?? []; ?>
<?php $selectedTypes = $resetFilters['data_types'] ?? []; ?>
<?php $resetOptions = [
    'commandes_serveur' => 'Commandes serveur',
    'demandes_cuisine' => 'Cuisine / plats prepares',
    'demandes_stock' => 'Demandes stock cuisine',
    'stock_magasin' => 'Stock magasin (mouvements)',
    'stock_cuisine' => 'Stock cuisine (inventaire)',
    'ventes' => 'Ventes',
    'caisse_finance' => 'Caisse / finance',
    'pertes' => 'Pertes',
    'incidents' => 'Incidents / cas',
    'retours' => 'Retours ventes',
    'rapports_operationnels' => 'Corrections / incidents rapports',
    'audit_operationnel' => 'Audit operationnel lie',
    'stock_articles_fiches' => 'Articles stock (fiches orphelines, periode)',
    'images_test' => 'Images de test',
]; ?>

<section class="grid stats">
    <article class="card stat"><span>Restaurants</span><strong><?= e((string) $stats['restaurants_total']) ?></strong></article>
    <article class="card stat"><span>Restaurants actifs</span><strong><?= e((string) $stats['restaurants_active']) ?></strong></article>
    <article class="card stat"><span>Utilisateurs</span><strong><?= e((string) $stats['users_total']) ?></strong></article>
    <article class="card stat"><span>Entrees d audit</span><strong><?= e((string) $stats['audit_entries']) ?></strong></article>
</section>

<div class="section-stack">
    <section class="card" style="padding:22px;">
        <h2 style="margin-top:0;">Acces rapides</h2>
        <p class="muted">Les actions les plus utiles restent visibles des l ouverture.</p>
        <div class="nav" style="margin-bottom:0;">
            <a href="/super-admin/restaurants">Restaurants</a>
            <a href="/super-admin/users">Utilisateurs</a>
            <a href="/super-admin/settings">Parametres</a>
            <a href="/super-admin/menu">Menu</a>
            <a href="/super-admin/audit">Journal d audit</a>
        </div>
    </section>

    <details class="card fold-card" <?= !empty($super_admin_ops_lookup) ? 'open' : '' ?>>
        <summary>
            <div>
                <strong>Dépannage opérations</strong>
                <div class="muted">Recherche par restaurant et changement de statut encadré — confirmation <code>FORCER</code>, motif et audit <code>super_admin_force_*</code>.</div>
            </div>
        </summary>
        <div class="fold-body">
            <?php
            $opsLookup = $super_admin_ops_lookup ?? null;
            $opsStatuses = $super_admin_ops_statuses ?? [];
            ?>
            <form method="post" action="/super-admin/operations/lookup" class="split" style="margin-bottom:18px;">
                <div>
                    <label>Restaurant</label>
                    <select name="restaurant_id" required>
                        <option value="">—</option>
                        <?php foreach ($restaurants as $r): ?>
                            <option value="<?= e((string) $r['id']) ?>"><?= e($r['name']) ?> (#<?= e((string) $r['id']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Type</label>
                    <select name="kind" required>
                        <option value="sale">Vente</option>
                        <option value="cash_remittance">Remise caisse (transfert vente)</option>
                        <option value="server_request">Demande serveur → cuisine</option>
                        <option value="kitchen_stock_request">Demande cuisine → stock</option>
                    </select>
                </div>
                <div>
                    <label>ID entité</label>
                    <input type="number" name="entity_id" min="1" required>
                </div>
                <div style="align-self:end;"><button type="submit">Charger la fiche</button></div>
            </form>

            <?php if ($opsLookup !== null): ?>
                <article class="card" style="padding:16px; margin-bottom:16px;">
                    <p class="muted" style="margin-top:0;">Statut actuel : <strong><?= e((string) ($opsLookup['status'] ?? '')) ?></strong> · <?= e((string) ($opsLookup['kind'] ?? '')) ?></p>
                    <?php if (($opsLookup['lines'] ?? []) !== []): ?>
                        <details style="margin-top:10px;">
                            <summary>Lignes (<?= e((string) count($opsLookup['lines'])) ?>)</summary>
                            <pre style="white-space:pre-wrap; font-size:12px; max-height:240px; overflow:auto;"><?= e(json_encode($opsLookup['lines'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                        </details>
                    <?php endif; ?>
                </article>

                <form method="post" action="/super-admin/operations/force" class="split" onsubmit="return confirm('Confirmer le changement de statut super administrateur ?');">
                    <input type="hidden" name="restaurant_id" value="<?= e((string) ($opsLookup['row']['restaurant_id'] ?? '')) ?>">
                    <input type="hidden" name="kind" value="<?= e((string) ($opsLookup['kind'] ?? '')) ?>">
                    <input type="hidden" name="entity_id" value="<?= e((string) ($opsLookup['row']['id'] ?? '')) ?>">
                    <div>
                        <label>Nouveau statut (liste cadrée)</label>
                        <select name="target_status" required>
                            <?php
                            $k = (string) ($opsLookup['kind'] ?? '');
                            foreach (($opsStatuses[$k] ?? []) as $stOpt):
                            ?>
                                <option value="<?= e($stOpt) ?>"><?= e($stOpt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="grid-column:1 / -1;">
                        <label>Motif obligatoire</label>
                        <textarea name="reason" required></textarea>
                    </div>
                    <div style="grid-column:1 / -1;">
                        <label>Confirmation exacte</label>
                        <input name="confirmation_phrase" placeholder="FORCER" required autocomplete="off">
                    </div>
                    <div style="grid-column:1 / -1;">
                        <button type="submit">Appliquer le statut</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </details>

    <details class="card fold-card" <?= (!empty($stock_reset_preview) || !empty($stock_reset_report)) ? 'open' : '' ?>>
        <summary>
            <div>
                <strong>Réinitialisation stock</strong>
                <div class="muted">Magasin, cuisine, mouvements et demandes — un restaurant à la fois. Aperçu obligatoire.</div>
            </div>
        </summary>
        <div class="fold-body">
            <form method="post" action="/super-admin/stock-reset/preview">
                <div class="split">
                    <div>
                        <label>Restaurant</label>
                        <select name="restaurant_id" required data-stock-reset-restaurant>
                            <option value="">Choisir un restaurant</option>
                            <?php $stockRidSel = (string) ($stockFilters['restaurant_id'] ?? ''); ?>
                            <?php foreach ($restaurants as $restaurant): ?>
                                <option value="<?= e((string) $restaurant['id']) ?>" <?= $stockRidSel !== '' && $stockRidSel === (string) $restaurant['id'] ? 'selected' : '' ?>><?= e($restaurant['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Période</label>
                        <select name="stock_period_preset" data-stock-period>
                            <option value="today" <?= $stockPeriodPreset === 'today' ? 'selected' : '' ?>>Aujourd’hui</option>
                            <option value="yesterday" <?= $stockPeriodPreset === 'yesterday' ? 'selected' : '' ?>>Hier</option>
                            <option value="week" <?= $stockPeriodPreset === 'week' ? 'selected' : '' ?>>Semaine</option>
                            <option value="month" <?= $stockPeriodPreset === 'month' ? 'selected' : '' ?>>Mois</option>
                            <option value="custom" <?= $stockPeriodPreset === 'custom' ? 'selected' : '' ?>>Plage personnalisée</option>
                        </select>
                    </div>
                    <div data-stock-period-field="week">
                        <label>Semaine (n’importe quel jour)</label>
                        <input type="date" name="stock_week_value" value="<?= e((string) ($stockFilters['stock_week_value'] ?? ($_POST['stock_week_value'] ?? ''))) ?>">
                    </div>
                    <div data-stock-period-field="month">
                        <label>Mois</label>
                        <input type="month" name="stock_month_value" value="<?= e((string) ($stockFilters['stock_month_value'] ?? ($_POST['stock_month_value'] ?? ''))) ?>">
                    </div>
                    <div data-stock-period-field="custom">
                        <label>Date début</label>
                        <input type="date" name="stock_date_from" value="<?= e((string) ($stockFilters['stock_date_from'] ?? ($_POST['stock_date_from'] ?? ''))) ?>">
                    </div>
                    <div data-stock-period-field="custom">
                        <label>Date fin</label>
                        <input type="date" name="stock_date_to" value="<?= e((string) ($stockFilters['stock_date_to'] ?? ($_POST['stock_date_to'] ?? ''))) ?>">
                    </div>
                </div>

                <label>Ce que vous voulez faire</label>
                <div class="inline-list">
                    <?php foreach ($stockCheckboxDefs as $optKey => $optLabel): ?>
                        <label><input type="checkbox" name="stock_options[]" value="<?= e($optKey) ?>" style="width:auto;margin-right:8px;" <?= in_array($optKey, $stockOptionsSelected, true) ? 'checked' : '' ?>><?= e($optLabel) ?></label>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top:16px;">
                    <button type="submit" class="button-primary" style="min-height:48px;font-size:1.05em;">Voir l’estimation</button>
                </div>
            </form>

            <?php if (!empty($stock_reset_preview)): ?>
                <?php $spCounts = $stock_reset_preview['counts'] ?? []; ?>
                <div class="card" style="padding:18px; margin-top:18px;">
                    <h3 style="margin-top:0;">Estimation avant validation</h3>
                    <p class="muted">Restaurant : <strong><?= e($stock_reset_preview['restaurant']['name'] ?? '-') ?></strong> · <?= e($stock_reset_preview['period']['label'] ?? '-') ?></p>
                    <?php if ((int) ($spCounts['mouvements_magasin_exclus_production'] ?? 0) > 0): ?>
                        <p class="muted"><strong>Attention :</strong> <?= e((string) (int) $spCounts['mouvements_magasin_exclus_production']) ?> mouvement(s) magasin ne peuvent pas être effacés tant qu’ils sont liés à une production cuisine.</p>
                    <?php endif; ?>
                    <ul style="margin:12px 0 0; padding-left:1.2em;">
                        <?php foreach ($spCounts as $ckey => $cval): ?>
                            <?php if ((int) $cval <= 0) { continue; } ?>
                            <?php if (!isset($stockPreviewCountLabels[$ckey])) { continue; } ?>
                            <li><strong><?= e((string) $cval) ?></strong> — <?= e($stockPreviewCountLabels[$ckey]) ?></li>
                        <?php endforeach; ?>
                    </ul>

                    <form method="post" action="/super-admin/stock-reset/execute" style="margin-top:18px;">
                        <input type="hidden" name="restaurant_id" value="<?= e((string) ($stock_reset_preview['filters']['restaurant_id'] ?? '')) ?>">
                        <input type="hidden" name="stock_period_preset" value="<?= e((string) ($stock_reset_preview['filters']['stock_period_preset'] ?? 'today')) ?>">
                        <input type="hidden" name="stock_week_value" value="<?= e((string) ($stock_reset_preview['filters']['stock_week_value'] ?? '')) ?>">
                        <input type="hidden" name="stock_month_value" value="<?= e((string) ($stock_reset_preview['filters']['stock_month_value'] ?? '')) ?>">
                        <input type="hidden" name="stock_date_from" value="<?= e((string) ($stock_reset_preview['filters']['stock_date_from'] ?? '')) ?>">
                        <input type="hidden" name="stock_date_to" value="<?= e((string) ($stock_reset_preview['filters']['stock_date_to'] ?? '')) ?>">
                        <?php foreach (($stock_reset_preview['filters']['stock_options'] ?? []) as $so): ?>
                            <input type="hidden" name="stock_options[]" value="<?= e((string) $so) ?>">
                        <?php endforeach; ?>
                        <label>Motif (obligatoire)</label>
                        <textarea name="reset_reason" required rows="3"></textarea>
                        <label>Confirmation forte</label>
                        <input name="confirmation_text" required placeholder="REINITIALISER STOCK <?= e((string) ($stock_reset_preview['filters']['restaurant_id'] ?? '')) ?>" autocomplete="off">
                        <p class="muted" style="margin:8px 0 16px;">Saisir exactement : <code style="word-break:break-all;">REINITIALISER STOCK <?= e((string) ($stock_reset_preview['filters']['restaurant_id'] ?? '')) ?></code></p>
                        <button type="submit" style="min-height:52px;font-size:1.08em;">Valider la réinitialisation stock</button>
                    </form>
                </div>
            <?php endif; ?>

            <?php if (!empty($stock_reset_report)): ?>
                <div class="card" style="padding:18px; margin-top:18px;">
                    <h3 style="margin-top:0;">Réinitialisation stock terminée</h3>
                    <p class="muted">Restaurant : <?= e($stock_reset_report['preview']['restaurant']['name'] ?? '-') ?> · <?= e($stock_reset_report['preview']['period']['label'] ?? '-') ?></p>
                    <p class="muted">Motif : <?= e((string) ($stock_reset_report['reason'] ?? '-')) ?></p>
                    <ul style="margin:12px 0 0; padding-left:1.2em;">
                        <?php foreach (($stock_reset_report['done'] ?? []) as $dk => $dv): ?>
                            <?php if ((int) $dv <= 0) { continue; } ?>
                            <li><strong><?= e((string) $dv) ?></strong> — <?= e((string) ($stockDoneReportLabels[$dk] ?? $dk)) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="muted" style="margin-bottom:0;">Trace conservée : <strong>super_admin_stock_reset</strong> (journal d’audit).</p>
                </div>
            <?php endif; ?>

            <?php $hist = $stock_reset_history ?? []; ?>
            <?php if ($hist !== []): ?>
                <details class="card" style="margin-top:18px;padding:0;overflow:hidden;">
                    <summary style="cursor:pointer;padding:14px 16px;list-style:none;">Historique des réinitialisations stock</summary>
                    <div style="padding:0 16px 16px;">
                        <div class="table-wrap">
                            <table>
                                <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Restaurant</th>
                                    <th>Par</th>
                                    <th>Période</th>
                                    <th>Motif</th>
                                    <th>Quantités</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($hist as $hrow): ?>
                                    <?php
                                    $nv = $hrow['new_values_json'] ?? null;
                                    if (is_string($nv)) {
                                        $nv = json_decode($nv, true) ?: [];
                                    }
                                    if (!is_array($nv)) {
                                        $nv = [];
                                    }
                                    $pdone = $nv['done'] ?? [];
                                    $sumParts = [];
                                    foreach ($pdone as $pk => $pv) {
                                        $pv = (int) $pv;
                                        if ($pv > 0) {
                                            $sumParts[] = $pv . ' ' . (string) ($stockDoneLabels[$pk] ?? $pk);
                                        }
                                    }
                                    $sumLine = $sumParts !== [] ? implode(' · ', $sumParts) : '—';
                                    $motif = trim((string) ($hrow['justification'] ?? ''));
                                    if (mb_strlen($motif) > 80) {
                                        $motif = mb_substr($motif, 0, 77) . '…';
                                    }
                                    ?>
                                    <tr>
                                        <td style="white-space:nowrap;"><?= e((string) ($hrow['created_at'] ?? '')) ?></td>
                                        <td><?= e((string) ($hrow['restaurant_name'] ?? ('#' . (string) ($hrow['restaurant_id'] ?? '')))) ?></td>
                                        <td><?= e((string) ($hrow['actor_name'] ?? '-')) ?></td>
                                        <td><?= e((string) (($nv['period']['label'] ?? null) ?: '-')) ?></td>
                                        <td><?= e($motif !== '' ? $motif : '—') ?></td>
                                        <td><?= e(mb_strlen($sumLine) > 120 ? mb_substr($sumLine, 0, 117) . '…' : $sumLine) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </details>
            <?php endif; ?>
        </div>
    </details>

    <details class="card fold-card" <?= (!empty($reset_preview) || !empty($reset_report)) ? 'open' : '' ?>>
        <summary>
            <div>
                <strong>Réinitialisation élargie (autres modules)</strong>
                <div class="muted">Ventes, caisse, commandes… — utiliser seulement si nécessaire.</div>
            </div>
        </summary>
        <div class="fold-body">
        <p class="muted">Toujours prévisualiser puis confirmer avec le texte exact demandé.</p>
        <form method="post" action="/super-admin/reset/preview">
            <div class="split">
                <div>
                    <label>Restaurant concerne</label>
                    <select name="restaurant_id" required data-reset-restaurant>
                        <option value="">Choisir un restaurant</option>
                        <?php foreach ($restaurants as $restaurant): ?>
                            <option value="<?= e((string) $restaurant['id']) ?>" <?= (string) ($resetFilters['restaurant_id'] ?? '') === (string) $restaurant['id'] ? 'selected' : '' ?>><?= e($restaurant['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Perimetre</label>
                    <select name="scope" data-reset-scope>
                        <option value="restaurant" <?= ($resetFilters['scope'] ?? 'restaurant') === 'restaurant' ? 'selected' : '' ?>>Tout le restaurant</option>
                        <option value="user" <?= ($resetFilters['scope'] ?? '') === 'user' ? 'selected' : '' ?>>Un utilisateur precis</option>
                    </select>
                </div>
                <div data-reset-user-wrap>
                    <label>Utilisateur cible</label>
                    <select name="user_id" data-reset-user>
                        <option value="">Choisir un utilisateur</option>
                        <?php foreach (($restaurant_users ?? []) as $resetUser): ?>
                            <option value="<?= e((string) $resetUser['id']) ?>" data-restaurant-id="<?= e((string) $resetUser['restaurant_id']) ?>" <?= (string) ($resetFilters['user_id'] ?? '') === (string) $resetUser['id'] ? 'selected' : '' ?>><?= e($resetUser['full_name'] . ' - ' . $resetUser['email']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Periode</label>
                    <select name="period_type" data-reset-period>
                        <option value="day" <?= ($resetFilters['period_type'] ?? 'day') === 'day' ? 'selected' : '' ?>>Un jour precis</option>
                        <option value="week" <?= ($resetFilters['period_type'] ?? '') === 'week' ? 'selected' : '' ?>>Une semaine</option>
                        <option value="month" <?= ($resetFilters['period_type'] ?? '') === 'month' ? 'selected' : '' ?>>Un mois</option>
                        <option value="custom" <?= ($resetFilters['period_type'] ?? '') === 'custom' ? 'selected' : '' ?>>Plage personnalisee</option>
                    </select>
                </div>
                <div data-period-field="day">
                    <label>Jour</label>
                    <input type="date" name="day_value" value="<?= e((string) ($_POST['day_value'] ?? '')) ?>">
                </div>
                <div data-period-field="week">
                    <label>Semaine</label>
                    <input type="date" name="week_value" value="<?= e((string) ($_POST['week_value'] ?? '')) ?>">
                </div>
                <div data-period-field="month">
                    <label>Mois</label>
                    <input type="month" name="month_value" value="<?= e((string) ($_POST['month_value'] ?? '')) ?>">
                </div>
                <div data-period-field="custom">
                    <label>Date debut</label>
                    <input type="date" name="date_from" value="<?= e((string) ($_POST['date_from'] ?? '')) ?>">
                </div>
                <div data-period-field="custom">
                    <label>Date fin</label>
                    <input type="date" name="date_to" value="<?= e((string) ($_POST['date_to'] ?? '')) ?>">
                </div>
            </div>

            <label>Donnees a reinitialiser</label>
            <div class="inline-list">
                <?php foreach ($resetOptions as $value => $label): ?>
                    <label><input type="checkbox" name="data_types[]" value="<?= e($value) ?>" style="width:auto;margin-right:8px;" <?= in_array($value, $selectedTypes, true) ? 'checked' : '' ?>><?= e($label) ?></label>
                <?php endforeach; ?>
            </div>

            <div style="margin-top:16px;">
                <button type="submit">Previsualiser les donnees concernees</button>
            </div>
        </form>

        <?php if (!empty($reset_preview)): ?>
            <div class="card" style="padding:18px; margin-top:18px;">
                <h3 style="margin-top:0;">Previsualisation</h3>
                <p class="muted">Restaurant : <?= e($reset_preview['restaurant']['name'] ?? '-') ?> · Periode : <?= e($reset_preview['period']['label'] ?? '-') ?></p>
                <p class="muted">Montant total concerne : <?= e(number_format((float) ($reset_preview['amount_total'] ?? 0), 2, '.', ' ')) ?></p>
                <?php if ((int) ($reset_preview['counts']['stock_magasin_mouvements_exclus'] ?? 0) > 0): ?>
                    <p class="muted"><strong>Attention :</strong> <?= e((string) (int) $reset_preview['counts']['stock_magasin_mouvements_exclus']) ?> mouvement(s) stock sur la periode sont lies a une production cuisine et ne seront pas effaces tant que les productions restent (choisir aussi « Cuisine / plats prepares » ou effacer les productions d abord).</p>
                <?php endif; ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Bloc</th>
                            <th>Nombre</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (($reset_preview['counts'] ?? []) as $key => $count): ?>
                            <tr>
                                <td><?= e((string) (($reset_preview['dataset_labels'][$key] ?? null) ?: $key)) ?></td>
                                <td><?= e((string) $count) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (($reset_preview['users_concerned'] ?? []) !== []): ?>
                    <div class="table-wrap" style="margin-top:14px;">
                        <table>
                            <thead><tr><th>Utilisateurs concernes</th><th>Email</th></tr></thead>
                            <tbody>
                            <?php foreach ($reset_preview['users_concerned'] as $affectedUser): ?>
                                <tr>
                                    <td><?= e((string) ($affectedUser['full_name'] ?? '-')) ?></td>
                                    <td><?= e((string) ($affectedUser['email'] ?? '-')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <form method="post" action="/super-admin/reset/execute" style="margin-top:16px;">
                    <input type="hidden" name="restaurant_id" value="<?= e((string) ($reset_preview['filters']['restaurant_id'] ?? '')) ?>">
                    <input type="hidden" name="scope" value="<?= e((string) ($reset_preview['filters']['scope'] ?? 'restaurant')) ?>">
                    <input type="hidden" name="user_id" value="<?= e((string) ($reset_preview['filters']['user_id'] ?? '0')) ?>">
                    <input type="hidden" name="period_type" value="<?= e((string) ($reset_preview['filters']['period_type'] ?? 'day')) ?>">
                    <input type="hidden" name="day_value" value="<?= e((string) ($_POST['day_value'] ?? '')) ?>">
                    <input type="hidden" name="week_value" value="<?= e((string) ($_POST['week_value'] ?? '')) ?>">
                    <input type="hidden" name="month_value" value="<?= e((string) ($_POST['month_value'] ?? '')) ?>">
                    <input type="hidden" name="date_from" value="<?= e((string) ($_POST['date_from'] ?? '')) ?>">
                    <input type="hidden" name="date_to" value="<?= e((string) ($_POST['date_to'] ?? '')) ?>">
                    <?php foreach (($reset_preview['filters']['data_types'] ?? []) as $selectedType): ?>
                        <input type="hidden" name="data_types[]" value="<?= e((string) $selectedType) ?>">
                    <?php endforeach; ?>
                    <label>Motif de reinitialisation</label>
                    <textarea name="reset_reason" required></textarea>
                    <label>Confirmation forte</label>
                    <input name="confirmation_text" placeholder="REINITIALISER ou REINITIALISER RESTAURANT <?= e((string) ($reset_preview['filters']['restaurant_id'] ?? '')) ?>">
                    <button type="submit">Executer la reinitialisation ciblee</button>
                </form>
            </div>
        <?php endif; ?>

        <?php if (!empty($reset_report)): ?>
            <div class="card" style="padding:18px; margin-top:18px;">
                <h3 style="margin-top:0;">Rapport apres reinitialisation</h3>
                <p class="muted">Restaurant : <?= e($reset_report['preview']['restaurant']['name'] ?? '-') ?> · Periode : <?= e($reset_report['preview']['period']['label'] ?? '-') ?></p>
                <p class="muted">Motif : <?= e((string) ($reset_report['reason'] ?? '-')) ?></p>
                <p class="muted">Confirmation : <?= e((string) ($reset_report['confirmation_text'] ?? '-')) ?></p>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Table</th><th>Lignes touchees</th></tr></thead>
                        <tbody>
                        <?php foreach (($reset_report['deleted'] ?? []) as $table => $count): ?>
                            <tr>
                                <td><?= e((string) $table) ?></td>
                                <td><?= e((string) $count) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="muted" style="margin-bottom:0;">Audit cree : <strong>super_admin_data_reset</strong></p>
            </div>
        <?php endif; ?>
        </div>
    </details>

    <details class="card fold-card" open>
        <summary>
            <div>
                <strong>Restaurants clients</strong>
                <div class="muted">Liste condensee avec lien genere, statut reel et branding.</div>
            </div>
            <span class="pill badge-neutral"><?= e((string) count($restaurants)) ?> restaurant(s)</span>
        </summary>
        <div class="fold-body">
            <?php if ($restaurants === []): ?>
                <div class="compact-empty">Aucun restaurant a afficher.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Restaurant</th>
                            <th>Branding</th>
                            <th>Lien d acces</th>
                            <th>Statut</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($restaurants as $restaurant): ?>
                            <tr>
                                <td><?= e((string) $restaurant['id']) ?></td>
                                <td>
                                    <strong><?= e($restaurant['name']) ?></strong><br>
                                    <span class="muted"><?= e($restaurant['slug']) ?></span>
                                </td>
                                <td>
                                    <span class="pill badge-neutral"><?= e($restaurant['public_name'] ?? '-') ?></span><br>
                                    <span class="muted"><?= e($restaurant['web_subdomain'] ?? '-') ?></span>
                                </td>
                                <td><a href="<?= e(restaurant_generated_access_path($restaurant)) ?>" target="_blank" rel="noopener noreferrer"><?= e(restaurant_generated_access_url($restaurant)) ?></a></td>
                                <td><span class="pill <?= ($restaurant['status'] ?? '') === 'active' ? 'badge-closed' : (($restaurant['status'] ?? '') === 'suspended' ? 'badge-progress' : 'badge-bad') ?>"><?= e(status_label($restaurant['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </details>
</div>

<script>
(function () {
    const restaurantSelect = document.querySelector('[data-reset-restaurant]');
    const scopeSelect = document.querySelector('[data-reset-scope]');
    const userWrap = document.querySelector('[data-reset-user-wrap]');
    const userSelect = document.querySelector('[data-reset-user]');
    const periodSelect = document.querySelector('[data-reset-period]');

    const syncUsers = () => {
        if (!restaurantSelect || !userSelect) {
            return;
        }

        const restaurantId = restaurantSelect.value;
        Array.from(userSelect.options).forEach((option, index) => {
            if (index === 0) {
                option.hidden = false;
                return;
            }

            option.hidden = restaurantId !== '' && option.getAttribute('data-restaurant-id') !== restaurantId;
            if (option.hidden && option.selected) {
                userSelect.value = '';
            }
        });
    };

    const syncScope = () => {
        if (!scopeSelect || !userWrap) {
            return;
        }
        userWrap.style.display = scopeSelect.value === 'user' ? '' : 'none';
    };

    const syncPeriod = () => {
        const mode = periodSelect ? periodSelect.value : 'day';
        document.querySelectorAll('[data-period-field]').forEach((node) => {
            node.style.display = node.getAttribute('data-period-field') === mode ? '' : 'none';
        });
    };

    restaurantSelect && restaurantSelect.addEventListener('change', syncUsers);
    scopeSelect && scopeSelect.addEventListener('change', syncScope);
    periodSelect && periodSelect.addEventListener('change', syncPeriod);

    syncUsers();
    syncScope();
    syncPeriod();

    const stockPeriodSelect = document.querySelector('[data-stock-period]');
    const syncStockPeriod = () => {
        const mode = stockPeriodSelect ? stockPeriodSelect.value : 'today';
        document.querySelectorAll('[data-stock-period-field]').forEach((node) => {
            const fields = node.getAttribute('data-stock-period-field') || '';
            const show = fields.split(',').map((s) => s.trim()).includes(mode);
            node.style.display = show ? '' : 'none';
        });
    };
    stockPeriodSelect && stockPeriodSelect.addEventListener('change', syncStockPeriod);
    syncStockPeriod();
})();
</script>
