<?php
declare(strict_types=1);

$historyTimezone = safe_timezone($restaurant['settings']['restaurant_reports_timezone'] ?? ($restaurant['timezone'] ?? null));
$todayDate = (new DateTimeImmutable('now', $historyTimezone))->format('Y-m-d');
$yesterdayDate = (new DateTimeImmutable('yesterday', $historyTimezone))->format('Y-m-d');
$historyPreviewLimit = 6;
$activePreviewLimit = 5;
$restaurantCurrency = restaurant_currency($restaurant);
$restaurantLogo = restaurant_media_url_or_default($restaurant['logo_url'] ?? null, 'logo');
$kitchenStockRequestItemsByRequest = $kitchen_stock_request_items_by_request ?? [];

$stockRequestCases = array_values(array_filter(
    $cases,
    static fn (array $case): bool => (string) ($case['source_entity_type'] ?? '') === 'kitchen_stock_requests'
));
$managerCases = array_values(array_filter(
    $stockRequestCases,
    static fn (array $case): bool => (string) ($case['status'] ?? '') === 'EN_ATTENTE_VALIDATION_MANAGER'
));
$managerCaseRequestIds = array_map(
    static fn (array $case): int => (int) ($case['source_entity_id'] ?? 0),
    $managerCases
);
$managerCaseRequestIds = array_values(array_unique(array_filter($managerCaseRequestIds)));

$sortRequests = static function (array $left, array $right): int {
    $leftPriority = (string) ($left['priority_level'] ?? 'normale');
    $rightPriority = (string) ($right['priority_level'] ?? 'normale');

    if ($leftPriority !== $rightPriority) {
        return $leftPriority === 'urgente' ? -1 : 1;
    }

    return strcmp((string) ($left['created_at'] ?? ''), (string) ($right['created_at'] ?? ''));
};

$groupRequests = static function (array $requests): array {
    $grouped = [];

    foreach ($requests as $request) {
        $label = trim((string) ($request['stock_item_name'] ?? ''));
        $label = $label !== '' ? $label : 'Article non defini';
        $grouped[$label] ??= [];
        $grouped[$label][] = $request;
    }

    ksort($grouped, SORT_NATURAL | SORT_FLAG_CASE);

    return $grouped;
};

$groupDomId = static function (string $prefix, string $label): string {
    $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($label)) ?? 'bloc';
    $slug = trim($slug, '_');

    return $prefix . '_' . ($slug !== '' ? $slug : 'bloc');
};

$activeSimpleRequests = array_values(array_filter(
    $kitchen_stock_requests,
    static fn (array $request): bool => !in_array((int) $request['id'], $managerCaseRequestIds, true)
));

$waitingRequests = array_values(array_filter(
    $activeSimpleRequests,
    static fn (array $request): bool => (string) $request['status'] === 'DEMANDE'
));
$processingRequests = array_values(array_filter(
    $activeSimpleRequests,
    static fn (array $request): bool => (string) $request['status'] === 'EN_COURS_TRAITEMENT'
));
$readyRequests = array_values(array_filter(
    $activeSimpleRequests,
    static fn (array $request): bool => in_array((string) $request['status'], ['FOURNI_TOTAL', 'FOURNI_PARTIEL', 'NON_FOURNI'], true)
));
$closedRequests = array_values(array_filter(
    $kitchen_stock_requests,
    static fn (array $request): bool => (string) $request['status'] === 'CLOTURE'
));
$nonSuppliedReadyRequests = array_values(array_filter(
    $readyRequests,
    static fn (array $request): bool => (string) $request['status'] === 'NON_FOURNI'
));

usort($waitingRequests, $sortRequests);
usort($processingRequests, $sortRequests);
usort($readyRequests, $sortRequests);
usort($managerCases, static fn (array $left, array $right): int => strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? '')));

$historyEntries = [];
foreach ($closedRequests as $request) {
    $requestItems = $kitchenStockRequestItemsByRequest[(int) $request['id']] ?? [];
    $details = [];
    foreach ($requestItems as $detailItem) {
        $details[] = (string) ($detailItem['stock_item_name'] ?? 'Article') . ' ' . (string) ($detailItem['quantity_requested'] ?? 0);
    }
    $eventDate = (string) ($request['received_at'] ?: $request['responded_at'] ?: $request['created_at']);
    $historyEntries[] = [
        'type' => 'Demande stock cloturee',
        'reference' => 'Demande #' . (string) $request['id'] . ' · ' . ((int) ($request['item_count'] ?? count($requestItems) ?: 1)) . ' produit(s)',
        'status' => stock_request_status_label($request['status'] ?? null),
        'date' => $eventDate,
        'details' => 'Cuisine ' . ($request['requested_by_name'] ?? '-') . ' · '
            . (($details !== []) ? implode(', ', $details) . ' · ' : '')
            . 'fournie ' . (string) ($request['quantity_supplied_total'] ?? $request['quantity_supplied'] ?? 0)
            . ' · non fournie ' . (string) ($request['unavailable_quantity_total'] ?? $request['unavailable_quantity'] ?? 0),
    ];
}

foreach ($managerCases as $case) {
    $eventDate = (string) ($case['submitted_to_manager_at'] ?: $case['created_at']);
    $historyEntries[] = [
        'type' => 'Cas complexe stock',
        'reference' => '#' . (string) $case['id'] . ' · ' . (string) (($case['reported_category'] ?? '') !== '' ? str_replace('_', ' ', $case['reported_category']) : 'Litige stock'),
        'status' => validation_status_label($case['status'] ?? null),
        'date' => $eventDate,
        'details' => 'Quantite ' . (string) ($case['quantity_affected'] ?? 0) . ' ' . (string) ($case['unit_name'] ?? '')
            . ' · ' . (($case['signal_notes'] ?? '') !== '' ? (string) $case['signal_notes'] : 'Soumis au gerant'),
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
            'dom_id' => 'stock_history_' . str_replace('-', '_', $groupKey),
            'entries' => [],
        ];
    }

    $historyGroups[$groupKey]['entries'][] = $entry;
}

$requestSections = [
    'En attente' => [
        'requests' => $waitingRequests,
        'empty' => 'Aucune nouvelle demande cuisine en attente.',
        'dom_id' => 'stock_waiting',
        'counter' => count($waitingRequests) . ' commande(s) en attente',
    ],
    'En cours de traitement' => [
        'requests' => $processingRequests,
        'empty' => 'Aucune demande actuellement prise en charge par le stock.',
        'dom_id' => 'stock_processing',
        'counter' => count($processingRequests) . ' demande(s) en traitement',
    ],
    'Pretes a remettre' => [
        'requests' => $readyRequests,
        'empty' => 'Aucune demande prete a remettre a la cuisine.',
        'dom_id' => 'stock_ready',
        'counter' => count($readyRequests) . ' pret(e)(s) a remettre',
    ],
];
$stockBadgeClass = static function (?string $status): string {
    return match ((string) $status) {
        'DEMANDE' => 'badge-waiting',
        'EN_COURS_TRAITEMENT' => 'badge-progress',
        'FOURNI_TOTAL', 'FOURNI_PARTIEL' => 'badge-ready',
        'NON_FOURNI', 'REJETE' => 'badge-bad',
        'CLOTURE', 'VALIDE' => 'badge-closed',
        default => 'badge-neutral',
    };
};
$priorityBadgeClass = static function (?string $priority): string {
    return (string) $priority === 'urgente' ? 'badge-urgent' : 'badge-neutral';
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
        <h1>Stock</h1>
        <p>Le stock garde une file simple par statut, sort les cas complexes vers le gerant et conserve un historique compact par jour pour rester lisible meme en gros volume.</p>
    </div>
</section>
<section class="card no-print" style="padding:18px; margin-bottom:24px;">
    <div class="toolbar-actions">
        <button type="button" onclick="window.print()">Imprimer</button>
        <a href="/stock?print=1" class="button-muted" target="_blank" rel="noopener noreferrer">Export imprimable / PDF navigateur</a>
    </div>
</section>

<?php if (!empty($flash_success)): ?><div class="flash-ok"><?= e($flash_success) ?></div><?php endif; ?>
<?php if (!empty($flash_error)): ?><div class="flash-bad"><?= e($flash_error) ?></div><?php endif; ?>
<section class="card" style="padding:18px; margin-bottom:24px;">
    <div class="menu-thumb">
        <img src="<?= e($restaurantLogo) ?>" alt="Logo restaurant">
        <div>
            <strong><?= e($restaurant['public_name'] ?? $restaurant['name'] ?? 'Restaurant') ?></strong><br>
            <span class="muted">Logo visible dans le module stock avec fallback propre si aucun visuel n est defini.</span>
        </div>
    </div>
</section>

<?php if (can_access('stock.create')): ?>
    <section class="card" style="padding:22px; margin-bottom:24px;">
        <h2 style="margin-top:0;">Creer un article de stock</h2>
        <form method="post" action="/stock/items" class="split">
            <div><label>Nom</label><input name="name" required></div>
            <div><label>Unite</label><input name="unit_name" placeholder="kg, piece, casier" required></div>
            <div><label>Categorie</label><input name="category_label" placeholder="Boissons, Viandes, Emballages"></div>
            <div><label>Quantite en stock</label><input name="quantity_in_stock" value="0"></div>
            <div><label>Seuil d alerte</label><input name="alert_threshold" value="0"></div>
            <div><label>Cout unitaire estime</label><input name="estimated_unit_cost" value="0"></div>
            <div style="grid-column:1 / -1;"><label>Note</label><textarea name="item_note" placeholder="Informations internes sur le produit"></textarea></div>
            <div style="align-self:end;"><button type="submit">Creer l article</button></div>
        </form>
    </section>
<?php endif; ?>

<section class="grid stats">
    <article class="card stat">
        <span>Demandes en attente</span>
        <strong><?= e((string) count($waitingRequests)) ?></strong>
    </article>
    <article class="card stat">
        <span>En cours de traitement</span>
        <strong><?= e((string) count($processingRequests)) ?></strong>
    </article>
    <article class="card stat">
        <span>Pretes a remettre</span>
        <strong><?= e((string) count($readyRequests)) ?></strong>
    </article>
    <article class="card stat">
        <span>Non fournies</span>
        <strong><?= e((string) count($nonSuppliedReadyRequests)) ?></strong>
    </article>
    <article class="card stat">
        <span>Cas complexes</span>
        <strong><?= e((string) count($managerCases)) ?></strong>
    </article>
</section>

<section class="split">
    <?php if (can_access('stock.entry.create')): ?>
        <article class="card" style="padding:22px;">
            <h2 style="margin-top:0;">Entree de stock</h2>
            <form method="post" action="/stock/entries">
                <label>Article</label>
                <select name="stock_item_id">
                    <?php foreach ($items as $item): ?>
                        <option value="<?= e((string) $item['id']) ?>"><?= e($item['name']) ?> (<?= e($item['unit_name']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <label>Quantite</label>
                <input name="quantity" value="1">
                <label>Cout unitaire</label>
                <input name="unit_cost" value="0">
                <label>Note</label>
                <textarea name="note"></textarea>
                <button type="submit">Enregistrer l entree</button>
            </form>
        </article>
    <?php endif; ?>

    <?php if (can_access('stock.loss.declare')): ?>
        <article class="card" style="padding:22px;">
            <h2 style="margin-top:0;">Perte matiere</h2>
            <form method="post" action="/stock/pertes">
                <label>Article</label>
                <select name="stock_item_id">
                    <?php foreach ($items as $item): ?>
                        <option value="<?= e((string) $item['id']) ?>"><?= e($item['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label>Quantite perdue</label>
                <input name="quantity" value="1">
                <label>Montant estime</label>
                <input name="amount" value="1.00">
                <label>Description</label>
                <textarea name="description">Produit abime ou inutilisable.</textarea>
                <button type="submit">Declarer la perte</button>
            </form>
        </article>
    <?php endif; ?>
</section>

<section class="card" style="padding:22px; margin-top:24px;">
    <h2 style="margin-top:0;">Demandes cuisine actives</h2>
    <p class="muted" style="margin-bottom:0;">Demande cuisine: les urgences restent en tete, les demandes deja remontees au gerant sortent du flux simple et les lignes quittent l actif des que la cuisine confirme la reception.</p>
</section>

<?php foreach ($requestSections as $sectionTitle => $section): ?>
    <section class="card" style="margin-top:24px;">
        <div style="padding:22px 22px 12px;">
            <div class="topbar">
                <div>
                    <h2 style="margin:0;"><?= e($sectionTitle) ?></h2>
                    <p class="muted" style="margin:6px 0 0;"><?= e($section['empty']) ?> Chaque demande reste group&eacute;e en un seul bloc pour &eacute;viter de m&eacute;langer les produits.</p>
                </div>
                <span class="pill badge-neutral"><?= e($section['counter']) ?></span>
            </div>
        </div>

        <?php if ($section['requests'] === []): ?>
            <div style="padding:0 22px 22px;">
                <p class="muted">Aucune demande dans cette etape.</p>
            </div>
        <?php else: ?>
            <div style="padding:0 22px 22px;" class="section-stack">
                <?php foreach ($section['requests'] as $index => $request): ?>
                    <?php $requestItems = $kitchenStockRequestItemsByRequest[(int) $request['id']] ?? []; ?>
                    <details class="<?= $index >= $activePreviewLimit ? 'history-extra' : '' ?>" data-history-group="<?= e($section['dom_id']) ?>" <?= $index >= $activePreviewLimit ? 'style="display:none;"' : '' ?> open style="border-top:1px solid var(--line); padding-top:16px;">
                        <summary style="cursor:pointer; list-style:none; display:flex; justify-content:space-between; gap:12px; align-items:flex-start;">
                            <div>
                                <strong>Demande #<?= e((string) $request['id']) ?></strong>
                                <div class="muted"><?= e($request['requested_by_name'] ?? '-') ?> · <?= e(format_date_fr($request['created_at'] ?? null, $historyTimezone)) ?></div>
                                <div class="muted"><?= e((string) ($request['item_count'] ?? count($requestItems) ?: 1)) ?> produit(s) demand&eacute;(s)</div>
                            </div>
                            <div style="text-align:right;">
                                <span class="pill <?= e($stockBadgeClass($request['status'] ?? null)) ?>"><?= e(stock_request_status_label($request['status'] ?? null)) ?></span>
                                <div class="muted" style="margin-top:6px;"><?= e((string) ($request['quantity_requested_total'] ?? $request['quantity_requested'] ?? 0)) ?> demand&eacute;(s)</div>
                            </div>
                        </summary>

                        <div class="table-wrap" style="margin-top:14px;">
                            <table>
                                <thead>
                                <tr>
                                    <th>Produit</th>
                                    <th>Demand&eacute;</th>
                                    <th>Fourni</th>
                                    <th>Non fourni</th>
                                    <th>Priorit&eacute;</th>
                                    <th>Statut</th>
                                    <th>Note cuisine</th>
                                    <th>Note stock</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($requestItems as $item): ?>
                                    <tr>
                                        <td><strong><?= e($item['stock_item_name'] ?? '-') ?></strong></td>
                                        <td><?= e((string) ($item['quantity_requested'] ?? 0)) ?> <?= e((string) ($item['unit_name'] ?? '')) ?></td>
                                        <td><?= e((string) ($item['quantity_supplied'] ?? 0)) ?> <?= e((string) ($item['unit_name'] ?? '')) ?></td>
                                        <td><?= e((string) ($item['unavailable_quantity'] ?? 0)) ?> <?= e((string) ($item['unit_name'] ?? '')) ?></td>
                                        <td><span class="pill <?= e($priorityBadgeClass($item['priority_level'] ?? null)) ?>"><?= e(priority_label($item['priority_level'] ?? null)) ?></span></td>
                                        <td><span class="pill <?= e($stockBadgeClass($item['status'] ?? null)) ?>"><?= e(stock_request_status_label($item['status'] ?? null)) ?></span></td>
                                        <td><?= e((string) (($item['note'] ?? '') !== '' ? $item['note'] : '-')) ?></td>
                                        <td><?= e((string) (($item['response_note'] ?? '') !== '' ? $item['response_note'] : '-')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div style="margin-top:14px;">
                            <div><strong>Demande :</strong> <?= e(signed_actor_line('Demande', $request['requested_by_name'] ?: null, 'kitchen', $request['created_at'] ?? null, $restaurant, $historyTimezone)) ?></div>
                            <div><strong>Prise en charge :</strong> <?= e(signed_actor_line('Pris en charge', $request['responded_by_name'] ?: null, 'stock_manager', $request['responded_at'] ?? null, $restaurant, $historyTimezone)) ?></div>
                            <div><strong>Reception cuisine :</strong> <?= e(signed_actor_line('Recu', $request['received_by_name'] ?: null, 'kitchen', $request['received_at'] ?? null, $restaurant, $historyTimezone)) ?></div>
                            <div><strong>Note generale :</strong> <?= e((string) ($request['note'] ?: '-')) ?></div>
                        </div>

                        <div style="margin-top:14px;">
                            <?php if (can_access('stock.request.respond')): ?>
                                <form method="post" action="/stock/demandes-cuisine/<?= e((string) $request['id']) ?>/reponse">
                                    <div class="table-wrap">
                                        <table>
                                            <thead>
                                            <tr>
                                                <th>Produit</th>
                                                <th>Quantit&eacute; fournie</th>
                                                <th>Quantit&eacute; non fournie</th>
                                                <th>Classement</th>
                                                <th>Note stock</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($requestItems as $item): ?>
                                                <?php
                                                $requestedQuantity = (float) ($item['quantity_requested'] ?? 0);
                                                $currentSupplied = (float) (($item['quantity_supplied'] ?? 0) > 0 ? $item['quantity_supplied'] : $requestedQuantity);
                                                if ((string) ($item['status'] ?? '') === 'NON_FOURNI') {
                                                    $currentSupplied = 0;
                                                }
                                                $currentUnavailable = max($requestedQuantity - $currentSupplied, 0);
                                                ?>
                                                <tr>
                                                    <td><?= e($item['stock_item_name'] ?? '-') ?></td>
                                                    <td><input name="items[<?= e((string) $item['id']) ?>][quantity_supplied]" value="<?= e((string) $currentSupplied) ?>"></td>
                                                    <td><input value="<?= e((string) $currentUnavailable) ?>" readonly></td>
                                                    <td>
                                                        <select name="items[<?= e((string) $item['id']) ?>][planning_status]">
                                                            <option value="">Aucun</option>
                                                            <option value="urgence" <?= ($item['planning_status'] ?? '') === 'urgence' ? 'selected' : '' ?>>Urgence</option>
                                                            <option value="a_prevoir" <?= ($item['planning_status'] ?? '') === 'a_prevoir' ? 'selected' : '' ?>>A prevoir</option>
                                                        </select>
                                                    </td>
                                                    <td><textarea name="items[<?= e((string) $item['id']) ?>][response_note]"><?= e((string) (($item['response_note'] ?? '') !== '' ? $item['response_note'] : ($item['note'] ?? ''))) ?></textarea></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <label>Note globale stock</label>
                                    <textarea name="note"><?= e((string) ($request['response_note'] ?: $request['note'])) ?></textarea>
                                    <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:10px;">
                                        <button type="submit" name="workflow_stage" value="EN_COURS_TRAITEMENT">Prendre en charge</button>
                                        <button type="submit">Valider la remise globale</button>
                                    </div>
                                </form>
                            <?php else: ?>
                                <span class="muted">Lecture seule.</span>
                            <?php endif; ?>

                            <?php if (can_access('stock.damage.signal')): ?>
                                <form method="post" action="/stock/demandes-cuisine/<?= e((string) $request['id']) ?>/incident" style="margin-top:14px;">
                                    <label>Soumettre un cas complexe au gerant</label>
                                    <input type="hidden" name="reported_category" value="litige_stock">
                                    <input name="quantity_affected" value="<?= e((string) ($request['quantity_requested_total'] ?? $request['quantity_requested'] ?? 0)) ?>">
                                    <textarea name="signal_notes">Cas complexe stock a arbitrer a partir de la trace reelle de la demande cuisine.</textarea>
                                    <button type="submit">Soumettre au gerant</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endforeach; ?>

<section class="card" style="margin-top:24px;">
    <div style="padding:22px 22px 12px;">
        <div class="topbar">
            <div>
                <h2 style="margin:0;">Cas complexes soumis au gerant</h2>
                <p class="muted" style="margin:6px 0 0;">Ces demandes sont sorties du flux simple stock. Elles restent traceables sans encombrer la file normale.</p>
            </div>
            <span class="pill badge-bad"><?= e((string) count($managerCases)) ?> cas</span>
        </div>
    </div>

    <?php if ($managerCases === []): ?>
        <div style="padding:0 22px 22px;">
            <p class="muted">Aucun cas complexe actuellement soumis au gerant.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Cas</th>
                    <th>Produit</th>
                    <th>Quantite</th>
                    <th>Soumis par</th>
                    <th>Date</th>
                    <th>Motif</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($managerCases as $index => $case): ?>
                    <tr class="<?= $index >= $activePreviewLimit ? 'history-extra' : '' ?>" data-history-group="stock_manager_cases" <?= $index >= $activePreviewLimit ? 'style="display:none;"' : '' ?>>
                        <td><strong>#<?= e((string) $case['id']) ?></strong><br><span class="muted"><?= e(validation_status_label($case['status'] ?? null)) ?></span></td>
                        <td><?= e((string) ($case['stock_item_name'] ?? 'Article')) ?></td>
                        <td><?= e((string) ($case['quantity_affected'] ?? 0)) ?> <?= e((string) ($case['unit_name'] ?? '')) ?></td>
                        <td><?= e((string) ($case['signaled_by_name'] ?: '-')) ?></td>
                        <td><?= e(format_date_fr($case['submitted_to_manager_at'] ?? $case['created_at'] ?? null, $historyTimezone)) ?></td>
                        <td style="min-width:220px;"><?= e((string) ($case['signal_notes'] ?: '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($managerCases) > $activePreviewLimit): ?>
            <div style="padding:12px 22px 22px;">
                <button type="button" data-history-toggle="stock_manager_cases">Voir plus</button>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="card" style="margin-top:24px;">
    <div style="padding:22px 22px 10px;">
        <h2 style="margin:0;">Historique cloture</h2>
        <p class="muted" style="margin:6px 0 0;">Aujourd hui reste visible par defaut. Les jours precedents sont replies et la journee peut etre etendue avec Voir plus.</p>
    </div>

    <?php if ($historyGroups === []): ?>
        <div style="padding:0 22px 22px;">
            <p class="muted">Aucun historique a afficher pour le moment.</p>
        </div>
    <?php else: ?>
        <?php foreach ($historyGroups as $group): ?>
            <?php $entries = $group['entries']; ?>
            <details <?= $group['is_current'] ? 'open' : '' ?> style="padding:0 22px 18px;">
                <summary style="cursor:pointer; list-style:none; padding:14px 0; border-top:1px solid var(--line); display:flex; justify-content:space-between; gap:12px; align-items:center;">
                    <span>
                        <strong><?= e($group['label']) ?></strong>
                        <span class="muted"> · <?= e((string) count($entries)) ?> element(s)</span>
                    </span>
                    <span class="muted">Historique stock</span>
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
</section>

<section class="card" style="margin-top:24px;">
    <div style="padding:22px 22px 10px;">
        <h2 style="margin:0;">Niveaux de stock</h2>
        <p class="muted" style="margin:6px 0 0;">Lecture rapide des quantites disponibles et des sorties encore provisoires.</p>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Article</th>
                <th>Stock actuel</th>
                <th>Sortie provisoire</th>
                <th>Seuil</th>
                <th>Cout unitaire</th>
                <th>Valeur du stock</th>
                <th>Action</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td>
                        <strong><?= e($item['name']) ?></strong><br>
                        <span class="muted"><?= e($item['unit_name']) ?></span>
                        <?php if (!empty($item['category_label'])): ?><br><span class="muted">Categorie : <?= e((string) $item['category_label']) ?></span><?php endif; ?>
                    </td>
                    <td><?= e((string) $item['quantity_in_stock']) ?></td>
                    <td><?= e((string) $item['quantity_out_provisional']) ?></td>
                    <td><?= e((string) $item['alert_threshold']) ?></td>
                    <td><?= e(format_money($item['estimated_unit_cost'], $restaurantCurrency)) ?></td>
                    <td><?= e(format_money(((float) $item['quantity_in_stock']) * ((float) $item['estimated_unit_cost']), $restaurantCurrency)) ?></td>
                    <td>
                        <?php if (can_access('stock.item.edit')): ?>
                            <details class="no-print">
                                <summary style="cursor:pointer;">Modifier</summary>
                                <form method="post" action="/stock/items/<?= e((string) $item['id']) ?>/update" class="split" style="margin-top:12px;">
                                    <div><label>Nom</label><input name="name" value="<?= e((string) $item['name']) ?>" required></div>
                                    <div><label>Unite</label><input name="unit_name" value="<?= e((string) $item['unit_name']) ?>" required></div>
                                    <div><label>Categorie</label><input name="category_label" value="<?= e((string) ($item['category_label'] ?? '')) ?>"></div>
                                    <div><label>Seuil d alerte</label><input name="alert_threshold" value="<?= e((string) $item['alert_threshold']) ?>"></div>
                                    <div><label>Cout unitaire estime</label><input name="estimated_unit_cost" value="<?= e((string) $item['estimated_unit_cost']) ?>"></div>
                                    <div style="grid-column:1 / -1;"><label>Note</label><textarea name="item_note"><?= e((string) ($item['item_note'] ?? '')) ?></textarea></div>
                                    <div style="grid-column:1 / -1; display:flex; gap:10px; flex-wrap:wrap;">
                                        <button type="submit">Enregistrer</button>
                                        <a href="#stock_modifications" class="button-muted">Historique des modifications</a>
                                    </div>
                                </form>
                            </details>
                        <?php else: ?>
                            <span class="muted">Lecture seule</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card" style="padding:24px; margin-top:24px;">
    <h2 style="margin-top:0;">Corrections sensibles</h2>
    <p class="muted">Apres validation, aucune quantite n est modifiee directement. Toute correction passe par une demande motivee puis une validation du gerant ou proprietaire.</p>
    <?php if ($movements === []): ?>
        <p class="muted">Aucun mouvement disponible pour demander une correction.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Mouvement</th><th>Article</th><th>Quantite</th><th>Statut</th><th>Date</th><th>Correction</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($movements, 0, 12) as $movement): ?>
                    <tr>
                        <td><?= e((string) $movement['movement_type']) ?></td>
                        <td><?= e((string) $movement['stock_item_name']) ?></td>
                        <td><?= e((string) $movement['quantity']) ?></td>
                        <td><?= e(validation_status_label($movement['status'] ?? null)) ?></td>
                        <td><?= e(format_date_fr($movement['created_at'], $historyTimezone)) ?></td>
                        <td>
                            <?php if ((string) ($movement['status'] ?? '') === 'VALIDE' && can_access('stock.correction.request')): ?>
                                <details class="no-print">
                                    <summary style="cursor:pointer;">Demander correction</summary>
                                    <form method="post" action="/stock/movements/<?= e((string) $movement['id']) ?>/correction-request" style="margin-top:12px;">
                                        <label>Nouvelle quantite souhaitee</label>
                                        <input name="new_quantity" value="<?= e((string) $movement['quantity']) ?>" required>
                                        <label>Justification</label>
                                        <textarea name="justification" required>Erreur de saisie detectee apres validation.</textarea>
                                        <button type="submit">Envoyer au gerant</button>
                                    </form>
                                </details>
                            <?php elseif (can_access('stock.correction.request')): ?>
                                <span class="muted">A corriger avant validation initiale</span>
                            <?php else: ?>
                                <span class="muted">Non autorise</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if (can_access('stock.correction.request')): ?>
        <div class="no-print" style="margin-top:18px;">
            <h3 style="margin-bottom:10px;">Action deja verrouillee</h3>
            <p class="muted">Les sorties vers cuisine, receptions, pertes ou autres actions deja validees ne se modifient pas librement. Une demande motivee est enregistree et transmise au gerant.</p>
            <form method="post" action="/stock/corrections/sensitive" class="split">
                <input type="hidden" name="module_name" value="stock">
                <input type="hidden" name="entity_type" value="sensitive_operation">
                <input type="hidden" name="entity_id" value="<?= e((string) $restaurant['id']) ?>">
                <input type="hidden" name="request_type" value="sensitive_operation_correction">
                <div><label>Resume</label><input name="summary" value="Correction d une action stock deja validee"></div>
                <div style="grid-column:1 / -1;"><label>Justification obligatoire</label><textarea name="justification" required>Demande de correction sensible a arbitrer par le gerant.</textarea></div>
                <div style="grid-column:1 / -1;"><button type="submit">Demander correction</button></div>
            </form>
        </div>
    <?php endif; ?>
</section>

<section class="card" id="stock_modifications" style="padding:24px; margin-top:24px;">
    <h2 style="margin-top:0;">Historique des modifications</h2>
    <?php if ($stock_audits === []): ?>
        <p class="muted">Aucune trace de modification stock pour le moment.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Date</th><th>Acteur</th><th>Action</th><th>Details</th></tr></thead>
                <tbody>
                <?php foreach ($stock_audits as $audit): ?>
                    <?php
                    $oldCost = $audit['old_values']['estimated_unit_cost'] ?? null;
                    $newCost = $audit['new_values']['estimated_unit_cost'] ?? null;
                    $detail = (string) ($audit['justification'] ?? '');
                    if ($oldCost !== null && $newCost !== null && (float) $oldCost !== (float) $newCost) {
                        $detail = 'Cout ' . format_money($oldCost, $restaurantCurrency) . ' -> ' . format_money($newCost, $restaurantCurrency);
                    }
                    ?>
                    <tr>
                        <td><?= e(format_date_fr($audit['created_at'], $historyTimezone)) ?></td>
                        <td><?= e(named_actor_label($audit['actor_name'] ?? null, $audit['actor_role_code'] ?? null)) ?></td>
                        <td><?= e(audit_action_label((string) $audit['action_name'])) ?></td>
                        <td><?= e($detail !== '' ? $detail : 'Modification tracee') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($correction_requests !== []): ?>
        <div class="table-wrap" style="margin-top:18px;">
            <table>
                <thead><tr><th>Demande</th><th>Acteur</th><th>Statut</th><th>Justification</th></tr></thead>
                <tbody>
                <?php foreach ($correction_requests as $request): ?>
                    <tr>
                        <td><?= e(correction_request_type_label((string) $request['request_type'])) ?></td>
                        <td><?= e(named_actor_label($request['requested_by_name'] ?? null, $request['requested_role_code'] ?? null)) ?></td>
                        <td><?= e(correction_request_status_label((string) $request['status'])) ?></td>
                        <td><?= e((string) ($request['justification'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
