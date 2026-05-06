<?php
declare(strict_types=1);

$activeStatuses = ['DEMANDE', 'EN_PREPARATION', 'PRET_A_SERVIR', 'FOURNI_PARTIEL', 'FOURNI_TOTAL'];
$remittedStatuses = ['REMIS_SERVEUR'];
$closedStatuses = ['CLOTURE', 'VENDU_PARTIEL', 'VENDU_TOTAL'];
$historyTimezone = safe_timezone($restaurant['settings']['restaurant_reports_timezone'] ?? ($restaurant['timezone'] ?? null));
$todayDate = (new DateTimeImmutable('now', $historyTimezone))->format('Y-m-d');
$yesterdayDate = (new DateTimeImmutable('yesterday', $historyTimezone))->format('Y-m-d');
$historyPreviewLimit = 6;
$activePreviewLimit = 6;
$restaurantCurrency = restaurant_currency($restaurant);
$restaurantLogo = restaurant_media_url_or_default($restaurant['logo_url'] ?? null, 'logo');
$salesOverview = $sales_overview ?? [
    'today_total_sold' => 0,
    'today_sales_count' => 0,
    'active_requests_count' => 0,
    'remitted_requests_count' => 0,
];
$serverCashiers = $server_cashiers ?? [];
$pendingCashRemittances = $pending_cash_remittances ?? [];
$saleRemittanceTracking = $sale_remittance_tracking ?? [];

$serviceBadgeClass = static function (?string $status): string {
    return match ((string) $status) {
        'DEMANDE' => 'badge-waiting',
        'EN_PREPARATION', 'FOURNI_PARTIEL' => 'badge-progress',
        'PRET_A_SERVIR', 'FOURNI_TOTAL', 'REMIS_SERVEUR' => 'badge-ready',
        'CLOTURE', 'VENDU_PARTIEL', 'VENDU_TOTAL' => 'badge-closed',
        'ANNULE', 'REFUSE_CUISINE' => 'badge-bad',
        default => 'badge-neutral',
    };
};

$saleTrackingBySaleId = [];
foreach ($saleRemittanceTracking as $trackingRow) {
    $saleTrackingBySaleId[(int) ($trackingRow['sale_id'] ?? 0)] = $trackingRow;
}

$requestItemsByRequest = [];
foreach ($server_request_items as $item) {
    $requestItemsByRequest[(int) $item['request_id']][] = $item;
}

$activeRequests = array_values(array_filter(
    $server_requests,
    static fn (array $request): bool => in_array((string) $request['status'], $activeStatuses, true)
));
$remittedRequests = array_values(array_filter(
    $server_requests,
    static fn (array $request): bool => in_array((string) $request['status'], $remittedStatuses, true)
));
$closedRequests = array_values(array_filter(
    $server_requests,
    static fn (array $request): bool => in_array((string) $request['status'], $closedStatuses, true)
));
$cancelledOrDeclinedRequests = array_values(array_filter(
    $server_requests,
    static fn (array $request): bool => in_array((string) $request['status'], ['ANNULE', 'REFUSE_CUISINE'], true)
));

$historyEntries = [];
foreach ($cancelledOrDeclinedRequests as $request) {
    $eventDate = (string) ($request['resolution_at'] ?: $request['updated_at'] ?: $request['created_at']);
    $isDeclined = (string) $request['status'] === 'REFUSE_CUISINE';
    $note = trim((string) ($request['resolution_note'] ?? ''));
    $rItems = $requestItemsByRequest[(int) ($request['id'] ?? 0)] ?? [];
    $lineBits = [];
    $sumLines = 0.0;
    foreach ($rItems as $ri) {
        $lineBits[] = (string) ($ri['menu_item_name'] ?? 'Article')
            . ' × ' . (string) ($ri['requested_quantity'] ?? 0)
            . ' @ ' . format_money((float) ($ri['unit_price'] ?? 0), $restaurantCurrency)
            . ' → ' . format_money((float) ($ri['requested_total'] ?? 0), $restaurantCurrency);
        $sumLines += (float) ($ri['requested_total'] ?? 0);
    }
    $linesSummary = $lineBits === [] ? '—' : implode(' · ', $lineBits);
    $moneyHint = $sumLines > 0.0001 ? (' · total commande ' . format_money($sumLines, $restaurantCurrency) . ' (non vendu)') : '';
    $historyEntries[] = [
        'type' => 'Demande service',
        'reference' => '#' . (string) $request['id'] . ' - ' . (string) ($request['service_reference'] ?: '-') . ' · serveur ' . ($request['server_name'] ?? '—'),
        'status' => service_flow_status_label($request['status']),
        'date' => $eventDate,
        'details' => 'Lignes : ' . $linesSummary . $moneyHint . ' · '
            . ($isDeclined
                ? ('Refus cuisine : ' . ($note !== '' ? $note : '—') . ' · ' . request_terminal_resolution_line(
                    'declinee',
                    $request['resolution_by_name'] ?? null,
                    'kitchen',
                    $note,
                    (string) ($request['resolution_at'] ?? ''),
                    $historyTimezone
                ))
                : ('Annulation serveur · ' . request_terminal_resolution_line(
                    'annulee',
                    $request['resolution_by_name'] ?? null,
                    'cashier_server',
                    $note,
                    (string) ($request['resolution_at'] ?? ''),
                    $historyTimezone
                ))),
        'amount' => 0.0,
    ];
}
foreach ($closedRequests as $request) {
    $eventDate = (string) ($request['closed_at'] ?: $request['updated_at'] ?: $request['created_at']);
    $historyEntries[] = [
        'type' => 'Demande service',
        'reference' => '#' . (string) $request['id'] . ' - ' . (string) ($request['service_reference'] ?: '-'),
        'status' => service_flow_status_label($request['status']),
        'date' => $eventDate,
        'details' => format_money($request['total_sold_amount'] ?? 0, $restaurantCurrency) . ' vendu',
        'amount' => (float) ($request['total_sold_amount'] ?? 0),
    ];
}
foreach ($sales as $sale) {
    $eventDate = (string) ($sale['validated_at'] ?: $sale['created_at']);
    $historyEntries[] = [
        'type' => 'Vente',
        'reference' => '#' . (string) $sale['id'] . ' - ' . (string) ($sale['server_name'] ?? 'Vente automatique'),
        'status' => validation_status_label($sale['status'] ?? null),
        'date' => $eventDate,
        'details' => format_money($sale['total_amount'] ?? 0, $restaurantCurrency),
        'amount' => (float) ($sale['total_amount'] ?? 0),
    ];
}

usort(
    $historyEntries,
    static fn (array $left, array $right): int => strcmp((string) $right['date'], (string) $left['date'])
);

$historyGroups = [];
foreach ($historyEntries as $entry) {
    $entryDate = new DateTimeImmutable((string) $entry['date'], $historyTimezone);
    $groupKey = $entryDate->format('Y-m-d');

    if (!isset($historyGroups[$groupKey])) {
        $historyGroups[$groupKey] = [
            'label' => $groupKey === $todayDate ? 'Aujourd hui' : ($groupKey === $yesterdayDate ? 'Hier' : $entryDate->format('d/m/Y')),
            'is_current' => $groupKey === $todayDate,
            'dom_id' => 'history_' . str_replace('-', '_', $groupKey),
            'entries' => [],
            'total_amount' => 0.0,
        ];
    }

    $historyGroups[$groupKey]['entries'][] = $entry;
    $historyGroups[$groupKey]['total_amount'] += (float) $entry['amount'];
}
?>
<style>
@media print {
    .no-print { display:none !important; }
    .card { box-shadow:none !important; border:1px solid #d6d6d6; }
}
.server-order-lines {
    display:grid;
    gap:10px;
    margin-top:14px;
}
.server-order-line {
    border:1px solid var(--line);
    border-radius:14px;
    padding:12px;
    display:grid;
    gap:10px;
    background:rgba(255,255,255,0.03);
}
.server-order-line-grid {
    display:grid;
    gap:10px;
    grid-template-columns:minmax(180px, 2fr) minmax(110px, 130px) minmax(140px, 1fr) minmax(140px, 1fr) auto;
    align-items:end;
}
.server-order-line-note {
    display:grid;
    gap:8px;
}
.server-order-summary {
    display:flex;
    justify-content:space-between;
    gap:12px;
    align-items:center;
    margin-top:16px;
    padding-top:16px;
    border-top:1px solid var(--line);
}
.remittance-grid {
    display:grid;
    gap:16px;
}
.remittance-card {
    border:1px solid var(--line);
    border-radius:16px;
    padding:16px;
    background:rgba(255,255,255,0.03);
}
.remittance-actions {
    display:grid;
    gap:12px;
    grid-template-columns:minmax(200px, 240px) auto;
    align-items:end;
    margin-top:14px;
}
.compact-qty-grid {
    display:grid;
    gap:10px;
    grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));
    margin-top:14px;
}
.compact-qty-card {
    padding:12px;
    border-radius:14px;
    border:1px solid var(--line);
    background:rgba(255,255,255,0.03);
}
.compact-qty-card .quantity-stepper {
    width:100%;
}
@media (max-width: 900px) {
    .server-order-line-grid,
    .remittance-actions {
        grid-template-columns:1fr;
    }
}
</style>

<section class="topbar">
    <div class="brand">
        <h1>Service et ventes</h1>
        <p>Suivez les demandes envoyees a la cuisine, cloturez les ventes servies, puis remettez seulement les montants reels a la caisse.</p>
    </div>
</section>

<?php if (!empty($flash_success)): ?><div class="flash-ok"><?= e($flash_success) ?></div><?php endif; ?>
<?php if (!empty($flash_error)): ?><div class="flash-bad"><?= e($flash_error) ?></div><?php endif; ?>

<section class="card" style="padding:18px; margin-bottom:24px;">
    <div class="menu-thumb">
        <img src="<?= e($restaurantLogo) ?>" alt="Logo restaurant">
        <div>
            <strong><?= e($restaurant['public_name'] ?? $restaurant['name'] ?? 'Restaurant') ?></strong><br>
            <span class="muted">Vue compacte pour le service, sur telephone comme sur ordinateur.</span>
        </div>
    </div>
</section>

<section class="card no-print" style="padding:18px; margin-bottom:24px;">
    <div class="toolbar-actions">
        <button type="button" onclick="window.print()">Imprimer</button>
        <a href="/ventes?print=1" class="button-muted" target="_blank" rel="noopener noreferrer">Export imprimable / PDF navigateur</a>
    </div>
</section>

<section class="grid stats">
    <article class="card stat">
        <span>Total vendu aujourd hui</span>
        <strong><?= e(format_money($salesOverview['today_total_sold'] ?? 0, $restaurantCurrency)) ?></strong>
    </article>
    <article class="card stat">
        <span>Ventes validees du jour</span>
        <strong><?= e((string) ($salesOverview['today_sales_count'] ?? 0)) ?></strong>
    </article>
    <article class="card stat">
        <span>Demandes actives</span>
        <strong><?= e((string) ($salesOverview['active_requests_count'] ?? 0)) ?></strong>
    </article>
    <article class="card stat">
        <span>A cloturer vite</span>
        <strong><?= e((string) ($salesOverview['remitted_requests_count'] ?? 0)) ?></strong>
    </article>
</section>

<section class="split">
    <article class="card" style="padding:22px;">
        <details class="compact-card" data-autoclose-details>
            <summary><strong>Passer commande / Demander a la cuisine</strong></summary>
            <h2 style="margin-top:14px;">Nouvelle demande de service</h2>
            <p class="muted" style="margin-top:0;">Une commande reste un seul formulaire, meme avec plusieurs articles.</p>
            <?php if (can_access('sales.request.create')): ?>
                <form method="post" action="/ventes/demandes">
                    <label>Table ou reference de service</label>
                    <input name="service_reference" placeholder="Table 12, Terrasse B, Ticket 48">
                    <label>Note de service</label>
                    <textarea name="note">Demande transmise a la cuisine pour le service en cours.</textarea>

                    <div class="server-order-lines" data-server-order-lines data-currency="<?= e($restaurantCurrency) ?>">
                        <div class="server-order-line" data-server-order-line>
                            <div class="server-order-line-grid">
                                <div>
                                    <label>Article</label>
                                    <select data-line-menu-item>
                                        <?php foreach ($menu_items as $item): ?>
                                            <option value="<?= e((string) $item['id']) ?>" data-price="<?= e(number_format((float) ($item['price'] ?? 0), 2, '.', '')) ?>"><?= e($item['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="hidden" data-line-menu-item-input name="items[0][menu_item_id]" value="">
                                    <input type="hidden" data-line-unit-price-input name="items[0][unit_price]" value="">
                                </div>
                                <div>
                                    <label>Quantite</label>
                                    <div class="quantity-stepper" data-quantity-stepper>
                                        <button type="button" data-stepper-minus>-</button>
                                        <input type="number" min="1" step="1" value="1" data-line-quantity name="items[0][requested_quantity]" required>
                                        <button type="button" data-stepper-plus>+</button>
                                    </div>
                                </div>
                                <div>
                                    <label>Prix unitaire</label>
                                    <input type="text" value="" data-line-unit-price-display readonly>
                                </div>
                                <div>
                                    <label>Total ligne</label>
                                    <input type="text" value="" data-line-total-display readonly>
                                </div>
                                <div style="align-self:end;">
                                    <button type="button" class="button-muted" data-line-remove>Retirer</button>
                                </div>
                            </div>
                            <div class="server-order-line-note">
                                <div>
                                    <label>Note eventuelle</label>
                                    <textarea data-line-note name="items[0][note]" placeholder="Cuisson, accompagnement, remarque de table..."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="toolbar-actions" style="margin-top:14px;">
                        <button type="button" class="button-muted" data-server-order-add>Ajouter un article</button>
                    </div>
                    <div class="server-order-summary">
                        <strong>Total commande</strong>
                        <strong data-server-order-grand-total><?= e(format_money(0, $restaurantCurrency)) ?></strong>
                    </div>
                    <button type="submit">Envoyer a la cuisine</button>
                </form>
            <?php else: ?>
                <p class="muted">Creation reservee au service.</p>
            <?php endif; ?>
        </details>
    </article>

    <article class="card" style="padding:22px;">
        <details class="compact-card" data-autoclose-details>
            <summary><strong>Perte d argent / Cas sensible</strong></summary>
            <h2 style="margin-top:14px;">Perte d argent</h2>
            <?php if (can_access('cash_loss.declare')): ?>
                <form method="post" action="/ventes/pertes-argent">
                    <label>Reference de vente</label>
                    <select name="reference_id">
                        <option value="">Aucune</option>
                        <?php foreach ($sales as $sale): ?>
                            <option value="<?= e((string) $sale['id']) ?>">#<?= e((string) $sale['id']) ?> - <?= e($sale['server_name'] ?? 'Vente automatique') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label>Montant</label>
                    <input name="amount" value="1.00">
                    <label>Description</label>
                    <textarea name="description">Ecart de caisse ou remise non justifiee.</textarea>
                    <button type="submit">Declarer la perte</button>
                </form>
            <?php else: ?>
                <p class="muted">Declaration reservee a la supervision.</p>
            <?php endif; ?>
        </details>
    </article>
</section>

<section class="card" style="padding:22px; margin-top:24px;">
    <details class="compact-card">
        <summary><strong>Remise caisse pratique</strong></summary>
    <h2 style="margin-top:14px;">Remise caisse pratique</h2>
    <p class="muted">Le service ne saisit jamais un montant libre. Chaque remise vient d une vente deja cloturee et chaque vente ne peut etre remise qu une seule fois.</p>

    <?php if ($pendingCashRemittances === [] && $remittedRequests === []): ?>
        <p class="muted">Aucune vente a remettre pour le moment.</p>
    <?php else: ?>
        <div class="remittance-grid">
            <?php foreach ($remittedRequests as $request): ?>
                <?php $items = $requestItemsByRequest[(int) $request['id']] ?? []; ?>
                <article class="remittance-card">
                    <div class="topbar" style="margin-bottom:12px;">
                        <div>
                            <strong>Demande #<?= e((string) $request['id']) ?></strong>
                            <div class="muted">Reference <?= e((string) ($request['service_reference'] ?: '-')) ?> - Recu le <?= e(format_date_fr($request['received_at'] ?? null, $historyTimezone)) ?></div>
                        </div>
                        <span class="pill badge-progress">Cloture requise</span>
                    </div>
                    <p style="margin:0 0 10px;">Cloturez d abord cette vente avant remise caisse.</p>
                    <?php if (can_access('sales.request.close')): ?>
                        <form method="post" action="/ventes/demandes/<?= e((string) $request['id']) ?>/cloture">
                            <div>
                                <label>Type</label>
                                <select name="sale_type">
                                    <option value="SUR_PLACE">Sur place</option>
                                    <option value="LIVRAISON">Livraison</option>
                                </select>
                            </div>
                            <div class="compact-qty-grid">
                                <?php foreach ($items as $item): ?>
                                    <div class="compact-qty-card">
                                        <strong><?= e($item['menu_item_name'] ?? 'Article') ?></strong>
                                        <div class="muted" style="margin:6px 0 10px;">Prepare <?= e((string) ($item['supplied_quantity'] ?? 0)) ?> - Prix <?= e(format_money($item['unit_price'] ?? 0, $restaurantCurrency)) ?></div>
                                        <label>Vendu</label>
                                        <div class="quantity-stepper" data-quantity-stepper>
                                            <button type="button" data-stepper-minus>-</button>
                                            <input type="number" min="0" step="1" name="sold_quantities[<?= e((string) $item['id']) ?>]" value="<?= e((string) (int) ($item['supplied_quantity'] ?? 0)) ?>">
                                            <button type="button" data-stepper-plus>+</button>
                                        </div>
                                        <label style="margin-top:10px;">Retourne</label>
                                        <div class="quantity-stepper" data-quantity-stepper>
                                            <button type="button" data-stepper-minus>-</button>
                                            <input type="number" min="0" step="1" name="returned_quantities[<?= e((string) $item['id']) ?>]" value="0">
                                            <button type="button" data-stepper-plus>+</button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div style="margin-top:14px;">
                                <button type="submit">Cloturer maintenant</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <p class="muted">Cloture reservee au profil autorise.</p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>

            <?php foreach ($pendingCashRemittances as $sale): ?>
                <article class="remittance-card">
                    <div class="topbar" style="margin-bottom:12px;">
                        <div>
                            <strong>Vente #<?= e((string) $sale['sale_id']) ?></strong>
                            <div class="muted">
                                <?= e(named_actor_label($sale['server_name'] ?? null, 'cashier_server')) ?> - <?= e(format_money($sale['sale_total_amount'] ?? 0, $restaurantCurrency)) ?> - Validee le <?= e(format_date_fr($sale['validated_at'] ?? $sale['sale_created_at'] ?? null, $historyTimezone)) ?>
                            </div>
                            <?php if (!empty($sale['server_request_id'])): ?>
                                <div class="muted">Demande serveur liee #<?= e((string) $sale['server_request_id']) ?> - Reference <?= e((string) ($sale['service_reference'] ?? '-')) ?></div>
                            <?php endif; ?>
                        </div>
                        <span class="pill badge-ready">Pret pour caisse</span>
                    </div>

                    <?php if (can_access('cash.remit.server')): ?>
                        <form method="post" action="/caisse/remises-serveur">
                            <input type="hidden" name="sale_id" value="<?= e((string) $sale['sale_id']) ?>">
                            <div class="remittance-actions">
                                <div>
                                    <label>Caisse destinataire</label>
                                    <select name="to_user_id" <?= $serverCashiers === [] ? 'disabled' : '' ?>>
                                        <?php if ($serverCashiers === []): ?>
                                            <option value="">Aucune caisse disponible</option>
                                        <?php else: ?>
                                            <?php foreach ($serverCashiers as $cashier): ?>
                                                <option value="<?= e((string) $cashier['id']) ?>"><?= e(named_actor_label($cashier['full_name'] ?? null, $cashier['role_code'] ?? null)) ?></option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div>
                                    <div class="muted" style="margin-bottom:8px;">Montant automatique: <strong><?= e(format_money($sale['sale_total_amount'] ?? 0, $restaurantCurrency)) ?></strong></div>
                                    <button type="submit" <?= $serverCashiers === [] ? 'disabled' : '' ?>>Remettre a la caisse</button>
                                </div>
                            </div>
                        </form>
                    <?php else: ?>
                        <p class="muted">Remise reservee au profil autorise.</p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    </details>
</section>

<section class="card" style="padding:22px; margin-top:24px;">
    <details class="compact-card">
        <summary><strong>Remis au serveur (à clôturer)</strong></summary>
    <h2 style="margin-top:14px;">Remis au serveur</h2>
    <p class="muted">Ces demandes doivent etre cloturees avant toute remise caisse. Les demandes non cloturees le soir passent en cloture automatique au passage de minuit (fuseau du restaurant), avec enregistrement de la vente comme pour une cloture manuelle.</p>
    <?php if ($remittedRequests === []): ?>
        <p class="muted">Aucune demande en attente de cloture.</p>
    <?php else: ?>
        <div class="grid">
            <?php foreach ($remittedRequests as $index => $request): ?>
                <?php $items = $requestItemsByRequest[(int) $request['id']] ?? []; ?>
                <article class="card <?= $index >= $activePreviewLimit ? 'history-extra' : '' ?>" data-history-group="sales_remitted_requests" <?= $index >= $activePreviewLimit ? 'style="padding:18px; border-radius:16px; display:none;"' : 'style="padding:18px; border-radius:16px;"' ?>>
                    <div class="topbar" style="margin-bottom:12px;">
                        <div>
                            <strong>Demande #<?= e((string) $request['id']) ?></strong>
                            <div class="muted"><?= e($request['server_name'] ?? '-') ?> - Recu le <?= e(format_date_fr($request['received_at'] ?? null, $historyTimezone)) ?> - Reference <?= e((string) ($request['service_reference'] ?: '-')) ?></div>
                        </div>
                        <span class="pill <?= e($serviceBadgeClass($request['status'] ?? null)) ?>"><?= e(service_flow_status_label($request['status'] ?? null)) ?></span>
                    </div>

                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>Produit</th>
                                <th>Prepare</th>
                                <th>Prix</th>
                                <th>Total demande</th>
                                <th>Non disponible</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td><div class="menu-thumb"><img src="<?= e(menu_item_media_url_or_default($item['menu_item_image_url'] ?? null)) ?>" alt="<?= e($item['menu_item_name'] ?? 'Article') ?>"><div><strong><?= e($item['menu_item_name'] ?? 'Article') ?></strong></div></div></td>
                                    <td><?= e((string) ($item['supplied_quantity'] ?? 0)) ?></td>
                                    <td><?= e(format_money($item['unit_price'] ?? 0, $restaurantCurrency)) ?></td>
                                    <td><?= e(format_money($item['requested_total'] ?? 0, $restaurantCurrency)) ?></td>
                                    <td><?= e((string) ($item['unavailable_quantity'] ?? 0)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (can_access('sales.request.close')): ?>
                        <p class="muted" style="margin:14px 0 10px;">Cloturez d abord cette vente avant remise caisse.</p>
                        <form method="post" action="/ventes/demandes/<?= e((string) $request['id']) ?>/cloture">
                            <div>
                                <label>Type</label>
                                <select name="sale_type">
                                    <option value="SUR_PLACE">Sur place</option>
                                    <option value="LIVRAISON">Livraison</option>
                                </select>
                            </div>
                            <div class="compact-qty-grid">
                                <?php foreach ($items as $item): ?>
                                    <div class="compact-qty-card">
                                        <strong><?= e($item['menu_item_name'] ?? 'Article') ?></strong>
                                        <div class="muted" style="margin:6px 0 10px;">Prepare <?= e((string) ($item['supplied_quantity'] ?? 0)) ?></div>
                                        <label>Vendu</label>
                                        <div class="quantity-stepper" data-quantity-stepper>
                                            <button type="button" data-stepper-minus>-</button>
                                            <input type="number" min="0" step="1" name="sold_quantities[<?= e((string) $item['id']) ?>]" value="<?= e((string) (int) ($item['supplied_quantity'] ?? 0)) ?>">
                                            <button type="button" data-stepper-plus>+</button>
                                        </div>
                                        <label style="margin-top:10px;">Retourne</label>
                                        <div class="quantity-stepper" data-quantity-stepper>
                                            <button type="button" data-stepper-minus>-</button>
                                            <input type="number" min="0" step="1" name="returned_quantities[<?= e((string) $item['id']) ?>]" value="0">
                                            <button type="button" data-stepper-plus>+</button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div style="margin-top:14px;">
                                <button type="submit">Cloturer maintenant</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
        <?php if (count($remittedRequests) > $activePreviewLimit): ?>
            <div style="padding-top:12px;">
                <button type="button" data-history-toggle="sales_remitted_requests">Voir plus</button>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    </details>
</section>

<template id="server-order-line-template">
    <div class="server-order-line" data-server-order-line>
        <div class="server-order-line-grid">
            <div>
                <label>Article</label>
                <select data-line-menu-item>
                    <?php foreach ($menu_items as $item): ?>
                        <option value="<?= e((string) $item['id']) ?>" data-price="<?= e(number_format((float) ($item['price'] ?? 0), 2, '.', '')) ?>"><?= e($item['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" data-line-menu-item-input value="">
                <input type="hidden" data-line-unit-price-input value="">
            </div>
            <div>
                <label>Quantite</label>
                <div class="quantity-stepper" data-quantity-stepper>
                    <button type="button" data-stepper-minus>-</button>
                    <input type="number" min="1" step="1" value="1" data-line-quantity required>
                    <button type="button" data-stepper-plus>+</button>
                </div>
            </div>
            <div>
                <label>Prix unitaire</label>
                <input type="text" value="" data-line-unit-price-display readonly>
            </div>
            <div>
                <label>Total ligne</label>
                <input type="text" value="" data-line-total-display readonly>
            </div>
            <div style="align-self:end;">
                <button type="button" class="button-muted" data-line-remove>Retirer</button>
            </div>
        </div>
        <div class="server-order-line-note">
            <div>
                <label>Note eventuelle</label>
                <textarea data-line-note placeholder="Cuisson, accompagnement, remarque de table..."></textarea>
            </div>
        </div>
    </div>
</template>

<script>
(function () {
    const formRoot = document.querySelector('[data-server-order-lines]');
    if (!formRoot) {
        return;
    }

    const addButton = document.querySelector('[data-server-order-add]');
    const template = document.getElementById('server-order-line-template');
    const grandTotalNode = document.querySelector('[data-server-order-grand-total]');
    const currency = formRoot.getAttribute('data-currency') || 'USD';
    const currencySymbol = currency === 'CDF' ? 'FC' : '$';
    const formatMoney = (value) => currencySymbol + Number(value || 0).toFixed(2);

    const renumberLines = () => {
        formRoot.querySelectorAll('[data-server-order-line]').forEach((line, index) => {
            const menuItemInput = line.querySelector('[data-line-menu-item-input]');
            const quantityInput = line.querySelector('[data-line-quantity]');
            const unitPriceInput = line.querySelector('[data-line-unit-price-input]');
            const noteInput = line.querySelector('[data-line-note]');

            if (menuItemInput) {
                menuItemInput.name = 'items[' + index + '][menu_item_id]';
            }
            if (quantityInput) {
                quantityInput.name = 'items[' + index + '][requested_quantity]';
            }
            if (unitPriceInput) {
                unitPriceInput.name = 'items[' + index + '][unit_price]';
            }
            if (noteInput) {
                noteInput.name = 'items[' + index + '][note]';
            }
        });
    };

    const syncLine = (line) => {
        const select = line.querySelector('[data-line-menu-item]');
        const menuItemInput = line.querySelector('[data-line-menu-item-input]');
        const quantityInput = line.querySelector('[data-line-quantity]');
        const unitPriceInput = line.querySelector('[data-line-unit-price-input]');
        const unitPriceDisplay = line.querySelector('[data-line-unit-price-display]');
        const totalDisplay = line.querySelector('[data-line-total-display]');
        const selectedOption = select.options[select.selectedIndex];
        const unitPrice = Number(selectedOption ? selectedOption.getAttribute('data-price') : 0);
        const quantity = Math.max(1, Number(quantityInput.value || 1));
        const lineTotal = unitPrice * quantity;

        quantityInput.value = String(quantity);
        menuItemInput.value = select.value;
        unitPriceInput.value = unitPrice.toFixed(2);
        unitPriceDisplay.value = formatMoney(unitPrice);
        totalDisplay.value = formatMoney(lineTotal);

        return lineTotal;
    };

    const syncAllLines = () => {
        let grandTotal = 0;
        formRoot.querySelectorAll('[data-server-order-line]').forEach((line) => {
            grandTotal += syncLine(line);
        });

        if (grandTotalNode) {
            grandTotalNode.textContent = formatMoney(grandTotal);
        }

        const lines = formRoot.querySelectorAll('[data-server-order-line]');
        formRoot.querySelectorAll('[data-line-remove]').forEach((button) => {
            button.disabled = lines.length === 1;
        });
    };

    formRoot.addEventListener('input', (event) => {
        if (event.target.matches('[data-line-quantity], [data-line-menu-item]')) {
            syncAllLines();
        }
    });

    formRoot.addEventListener('change', (event) => {
        if (event.target.matches('[data-line-menu-item]')) {
            syncAllLines();
        }
    });

    formRoot.addEventListener('click', (event) => {
        const removeButton = event.target.closest('[data-line-remove]');
        if (!removeButton) {
            return;
        }

        const lines = formRoot.querySelectorAll('[data-server-order-line]');
        if (lines.length === 1) {
            return;
        }

        removeButton.closest('[data-server-order-line]').remove();
        renumberLines();
        syncAllLines();
    });

    if (addButton && template) {
        addButton.addEventListener('click', () => {
            formRoot.appendChild(template.content.cloneNode(true));
            renumberLines();
            syncAllLines();
        });
    }

    renumberLines();
    syncAllLines();
})();
</script>

<section class="card" style="padding:22px; margin-top:24px;">
    <details class="compact-card">
        <summary><strong>Signaler retour / casse / incident</strong></summary>
    <h2 style="margin-top:14px;">Signaler un retour ou une casse</h2>
    <p class="muted">Le service declare le cas ici. Toute decision manager reste centralisee dans le tableau de bord /owner.</p>
    <?php if (can_access('sales.incident.signal')): ?>
        <form method="post" action="/ventes/incidents">
            <label>Article vendu</label>
            <select name="sale_item_id">
                <?php foreach ($sale_items as $item): ?>
                    <option value="<?= e((string) $item['id']) ?>"><?= e($item['menu_item_name'] ?? 'Article') ?> - <?= e($item['server_name'] ?? 'Vente automatique') ?></option>
                <?php endforeach; ?>
            </select>
            <label>Quantite impactee</label>
            <input name="quantity_affected" value="1" required>
            <label>Categorie</label>
            <select name="reported_category">
                <?php foreach ($incident_types as $incidentType): ?>
                    <option value="<?= e($incidentType) ?>"><?= e($incidentType) ?></option>
                <?php endforeach; ?>
            </select>
            <label>Signalement</label>
            <textarea name="signal_notes">Retour, casse ou incident constate pendant le service.</textarea>
            <button type="submit">Signaler au gerant</button>
        </form>
    <?php else: ?>
        <p class="muted">Signalement reserve au service terrain.</p>
    <?php endif; ?>
    </details>
</section>

<section class="card" style="margin-top:24px;">
    <details class="compact-card" style="padding:0;">
        <summary style="padding:22px 22px 10px; cursor:pointer; list-style:none;"><strong>Historique du service</strong></summary>
    <div style="padding:0 22px 10px;">
        <h2 style="margin:0;">Historique du service</h2>
        <p class="muted" style="margin:6px 0 0;">Groupé par jour (Aujourd’hui, Hier, date). Ouvrez un bloc pour le détail. Utilisez « Voir plus » pour les longues listes.</p>
    </div>

    <?php if ($historyGroups === []): ?>
        <div style="padding:0 22px 22px;">
            <p class="muted">Aucun historique a afficher pour le moment.</p>
        </div>
    <?php else: ?>
        <?php foreach ($historyGroups as $group): ?>
            <?php $entries = $group['entries']; ?>
            <details style="padding:0 22px 18px;">
                <summary style="cursor:pointer; list-style:none; padding:14px 0; border-top:1px solid var(--line); display:flex; justify-content:space-between; gap:12px; align-items:center;">
                    <span>
                        <strong><?= e($group['label']) ?></strong>
                        <span class="muted"> - <?= e((string) count($entries)) ?> element(s)</span>
                    </span>
                    <span class="muted"><?= e(format_money($group['total_amount'], $restaurantCurrency)) ?></span>
                </summary>

                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Type</th>
                            <th>Reference</th>
                            <th>Statut</th>
                            <th>Date</th>
                            <th>Details</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($entries as $index => $entry): ?>
                            <tr class="<?= $index >= $historyPreviewLimit ? 'history-extra' : '' ?>" data-history-group="<?= e($group['dom_id']) ?>" <?= $index >= $historyPreviewLimit ? 'style="display:none;"' : '' ?>>
                                <td><?= e($entry['type']) ?></td>
                                <td><?= e($entry['reference']) ?></td>
                                <td><?= e($entry['status']) ?></td>
                                <td><?= e(format_date_fr($entry['date'], $historyTimezone)) ?></td>
                                <td><?= e($entry['details']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (count($entries) > $historyPreviewLimit): ?>
                    <div style="padding-top:12px;">
                        <button type="button" data-history-toggle="<?= e($group['dom_id']) ?>">Voir plus</button>
                    </div>
                <?php endif; ?>
            </details>
        <?php endforeach; ?>
    <?php endif; ?>
    </details>
</section>

<?php if ($sales !== []): ?>
    <section class="card" style="padding:22px; margin-top:24px;">
        <h2 style="margin-top:0;">Factures recentes</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Vente</th>
                    <th>Serveur</th>
                    <th>Total</th>
                    <th>Statut vente</th>
                    <th>Remise caisse</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach (array_slice($sales, 0, 12) as $sale): ?>
                    <?php $tracking = $saleTrackingBySaleId[(int) $sale['id']] ?? null; ?>
                    <tr>
                        <td>#<?= e((string) $sale['id']) ?></td>
                        <td><?= e(named_actor_label($sale['server_name'] ?? null, 'cashier_server')) ?></td>
                        <td><?= e(format_money($sale['total_amount'] ?? 0, $restaurantCurrency)) ?></td>
                        <td><?= e(validation_status_label($sale['status'] ?? null)) ?></td>
                        <td><?= e($tracking !== null && !empty($tracking['transfer_id']) ? cash_transfer_status_label($tracking['transfer_status'] ?? null) : 'En attente') ?></td>
                        <td><a href="/ventes/factures/<?= e((string) $sale['id']) ?>" class="button-muted" target="_blank" rel="noopener noreferrer">Imprimer</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<section class="card" style="padding:22px; margin-top:24px;">
    <h2 style="margin-top:0;">Actif maintenant</h2>
    <p class="muted">Les demandes actives restent visibles jusqu a la remise au serveur. Une fois la cuisine remise, elles passent dans la file de cloture.</p>
    <?php if ($activeRequests === []): ?>
        <p class="muted">Aucune demande active pour le moment.</p>
    <?php else: ?>
        <div class="grid">
            <?php foreach ($activeRequests as $index => $request): ?>
                <?php $items = $requestItemsByRequest[(int) $request['id']] ?? []; ?>
                <article class="card <?= $index >= $activePreviewLimit ? 'history-extra' : '' ?>" data-history-group="sales_active_requests" <?= $index >= $activePreviewLimit ? 'style="padding:18px; border-radius:16px; display:none;"' : 'style="padding:18px; border-radius:16px;"' ?>>
                    <div class="topbar" style="margin-bottom:12px;">
                        <div>
                            <strong>Demande #<?= e((string) $request['id']) ?></strong>
                            <div class="muted"><?= e($request['server_name'] ?? '-') ?> - <?= e(format_date_fr($request['created_at'] ?? null, $historyTimezone)) ?> - Reference <?= e((string) ($request['service_reference'] ?: '-')) ?></div>
                        </div>
                        <span class="pill <?= e($serviceBadgeClass($request['status'] ?? null)) ?>"><?= e(service_flow_status_label($request['status'] ?? null)) ?></span>
                    </div>

                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>Produit</th>
                                <th>Demande</th>
                                <th>Prix</th>
                                <th>Total</th>
                                <th>Prepare</th>
                                <th>Non disponible</th>
                                <th>Etape</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td><div class="menu-thumb"><img src="<?= e(menu_item_media_url_or_default($item['menu_item_image_url'] ?? null)) ?>" alt="<?= e($item['menu_item_name'] ?? 'Article') ?>"><div><strong><?= e($item['menu_item_name'] ?? 'Article') ?></strong></div></div></td>
                                    <td><?= e((string) ($item['requested_quantity'] ?? 0)) ?></td>
                                    <td><?= e(format_money($item['unit_price'] ?? 0, $restaurantCurrency)) ?></td>
                                    <td><?= e(format_money($item['requested_total'] ?? 0, $restaurantCurrency)) ?></td>
                                    <td><?= e((string) ($item['supplied_quantity'] ?? 0)) ?></td>
                                    <td><?= e((string) ($item['unavailable_quantity'] ?? 0)) ?></td>
                                    <td><?= e(service_flow_status_label(($item['status'] ?? '') !== '' ? $item['status'] : ($item['supply_status'] ?? null))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (in_array((string) $request['status'], ['PRET_A_SERVIR', 'FOURNI_PARTIEL', 'FOURNI_TOTAL'], true) && can_access('sales.request.close')): ?>
                        <form method="post" action="/ventes/demandes/<?= e((string) $request['id']) ?>/reception" style="margin-top:14px;">
                            <button type="submit">Confirmer la reception cote service</button>
                        </form>
                    <?php endif; ?>

                    <?php
                    $uid = (int) (current_user()['id'] ?? 0);
                    $canCancelRequest = can_access('sales.request.create')
                        && $uid > 0
                        && (int) ($request['server_id'] ?? 0) === $uid
                        && (string) ($request['status'] ?? '') === 'DEMANDE';
                    if ($canCancelRequest) {
                        foreach ($items as $cit) {
                            if ((string) ($cit['status'] ?? '') !== 'DEMANDE' || !empty($cit['technical_confirmed_by'])) {
                                $canCancelRequest = false;
                                break;
                            }
                        }
                    }
                    ?>
                    <?php if ($canCancelRequest): ?>
                        <form method="post" action="/ventes/demandes/<?= e((string) $request['id']) ?>/annuler" style="margin-top:14px; padding-top:14px; border-top:1px solid var(--line);" onsubmit="return confirm('Annuler la commande #<?= e((string) $request['id']) ?> avant prise en charge cuisine ?');">
                            <label>Annuler cette commande (motif obligatoire)</label>
                            <textarea name="reason" required placeholder="Ex. table change d avis, erreur de saisie..."></textarea>
                            <button type="submit" class="button-muted">Annuler commande #<?= e((string) $request['id']) ?></button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
        <?php if (count($activeRequests) > $activePreviewLimit): ?>
            <div style="padding-top:12px;">
                <button type="button" data-history-toggle="sales_active_requests">Voir plus</button>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
