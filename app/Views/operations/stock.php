<?php
declare(strict_types=1);

$historyTimezone = safe_timezone($restaurant['settings']['restaurant_reports_timezone'] ?? ($restaurant['timezone'] ?? null));
$todayDate = (new DateTimeImmutable('now', $historyTimezone))->format('Y-m-d');
$yesterdayDate = (new DateTimeImmutable('yesterday', $historyTimezone))->format('Y-m-d');
$historyPreviewLimit = 6;
$activePreviewLimit = 5;

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
    $eventDate = (string) ($request['received_at'] ?: $request['responded_at'] ?: $request['created_at']);
    $historyEntries[] = [
        'type' => 'Demande stock cloturee',
        'reference' => ($request['stock_item_name'] ?? 'Article') . ' · ' . priority_label($request['priority_level'] ?? null),
        'status' => stock_request_status_label($request['status'] ?? null),
        'date' => $eventDate,
        'details' => 'Cuisine ' . ($request['requested_by_name'] ?? '-') . ' · demande ' . (string) ($request['quantity_requested'] ?? 0)
            . ' · fournie ' . (string) ($request['quantity_supplied'] ?? 0)
            . ' · non fournie ' . (string) ($request['unavailable_quantity'] ?? 0),
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

<section class="topbar">
    <div class="brand">
        <h1>Stock</h1>
        <p>Le stock garde une file simple par statut, sort les cas complexes vers le gerant et conserve un historique compact par jour pour rester lisible meme en gros volume.</p>
    </div>
</section>

<?php if (!empty($flash_success)): ?><div class="flash-ok"><?= e($flash_success) ?></div><?php endif; ?>
<?php if (!empty($flash_error)): ?><div class="flash-bad"><?= e($flash_error) ?></div><?php endif; ?>

<?php if (can_access('stock.create')): ?>
    <section class="card" style="padding:22px; margin-bottom:24px;">
        <h2 style="margin-top:0;">Creer un article de stock</h2>
        <form method="post" action="/stock/items" class="split">
            <div><label>Nom</label><input name="name" required></div>
            <div><label>Unite</label><input name="unit_name" placeholder="kg, piece, casier" required></div>
            <div><label>Quantite en stock</label><input name="quantity_in_stock" value="0"></div>
            <div><label>Seuil d alerte</label><input name="alert_threshold" value="0"></div>
            <div><label>Cout unitaire estime</label><input name="estimated_unit_cost" value="0"></div>
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
    <?php $groupedRequests = $groupRequests($section['requests']); ?>
    <section class="card" style="margin-top:24px;">
        <div style="padding:22px 22px 12px;">
            <div class="topbar">
                <div>
                    <h2 style="margin:0;"><?= e($sectionTitle) ?></h2>
                    <p class="muted" style="margin:6px 0 0;"><?= e($section['empty']) ?> Regroupement visuel par produit pour accelerer la lecture.</p>
                </div>
                <span class="pill badge-neutral"><?= e($section['counter']) ?></span>
            </div>
        </div>

        <?php if ($section['requests'] === []): ?>
            <div style="padding:0 22px 22px;">
                <p class="muted">Aucune demande dans cette etape.</p>
            </div>
        <?php else: ?>
            <div style="padding:0 22px 22px;">
                <?php foreach ($groupedRequests as $productName => $productRequests): ?>
                    <?php $productGroupId = $groupDomId($section['dom_id'], $productName); ?>
                    <div style="border-top:1px solid var(--line); padding-top:16px; margin-top:16px;">
                        <div class="topbar" style="margin-bottom:10px;">
                            <div>
                                <h3 style="margin:0;"><?= e($productName) ?></h3>
                                <p class="muted" style="margin:6px 0 0;"><?= e((string) count($productRequests)) ?> ligne(s) pour ce produit.</p>
                            </div>
                            <span class="pill badge-neutral"><?= e((string) count($productRequests)) ?></span>
                        </div>

                        <div class="table-wrap">
                            <table>
                                <thead>
                                <tr>
                                    <th>Heure</th>
                                    <th>Demandeur cuisine</th>
                                    <th>Demande</th>
                                    <th>Fourni</th>
                                    <th>Non fourni</th>
                                    <th>Priorite</th>
                                    <th>Statut</th>
                                    <th>Trace des acteurs</th>
                                    <th>Notes</th>
                                    <th>Action stock</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($productRequests as $index => $request): ?>
                                    <tr class="<?= $index >= $activePreviewLimit ? 'history-extra' : '' ?>" data-history-group="<?= e($productGroupId) ?>" <?= $index >= $activePreviewLimit ? 'style="display:none;"' : '' ?>>
                                        <td><?= e(format_date_fr($request['created_at'] ?? null, $historyTimezone)) ?></td>
                                        <td><?= e($request['requested_by_name'] ?? '-') ?></td>
                                        <td><?= e((string) $request['quantity_requested']) ?> <?= e((string) ($request['unit_name'] ?? '')) ?></td>
                                        <td><?= e((string) $request['quantity_supplied']) ?> <?= e((string) ($request['unit_name'] ?? '')) ?></td>
                                        <td><?= e((string) $request['unavailable_quantity']) ?> <?= e((string) ($request['unit_name'] ?? '')) ?></td>
                                        <td><span class="pill <?= e($priorityBadgeClass($request['priority_level'] ?? null)) ?>"><?= e(priority_label($request['priority_level'] ?? null)) ?></span></td>
                                        <td><span class="pill <?= e($stockBadgeClass($request['status'] ?? null)) ?>"><?= e(stock_request_status_label($request['status'] ?? null)) ?></span></td>
                                        <td style="min-width:220px;">
                                            <div><strong>Demande :</strong> <?= e((string) ($request['requested_by_name'] ?: '-')) ?></div>
                                            <div><strong>Prise en charge :</strong> <?= e((string) ($request['responded_by_name'] ?: '-')) ?></div>
                                            <div><strong>Reception cuisine :</strong> <?= e((string) ($request['received_by_name'] ?: '-')) ?></div>
                                            <div><strong>Heure reponse :</strong> <?= e(format_date_fr($request['responded_at'] ?? null, $historyTimezone)) ?></div>
                                        </td>
                                        <td style="min-width:220px;">
                                            <div><strong>Note cuisine :</strong> <?= e((string) ($request['note'] ?: '-')) ?></div>
                                            <div><strong>Note stock :</strong> <?= e((string) ($request['response_note'] ?: '-')) ?></div>
                                        </td>
                                        <td style="min-width:260px;">
                                            <?php if (can_access('stock.request.respond')): ?>
                                                <form method="post" action="/stock/demandes-cuisine/<?= e((string) $request['id']) ?>/reponse">
                                                    <label>Quantite reellement fournie</label>
                                                    <input name="quantity_supplied" value="<?= e((string) ((float) $request['quantity_supplied'] > 0 ? (float) $request['quantity_supplied'] : (float) $request['quantity_requested'])) ?>">
                                                    <label>Classement</label>
                                                    <select name="planning_status">
                                                        <option value="">Aucun</option>
                                                        <option value="urgence" <?= ($request['planning_status'] ?? '') === 'urgence' ? 'selected' : '' ?>>Urgence</option>
                                                        <option value="a_prevoir" <?= ($request['planning_status'] ?? '') === 'a_prevoir' ? 'selected' : '' ?>>A prevoir</option>
                                                    </select>
                                                    <label>Reponse stock</label>
                                                    <textarea name="note"><?= e((string) ($request['response_note'] ?: $request['note'])) ?></textarea>
                                                    <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:10px;">
                                                        <button type="submit" name="workflow_stage" value="EN_COURS_TRAITEMENT">Prendre en charge</button>
                                                        <button type="submit" name="status" value="FOURNI_TOTAL">Valider fourni totalement</button>
                                                        <button type="submit" name="status" value="FOURNI_PARTIEL">Valider fourni partiellement</button>
                                                        <button type="submit" name="status" value="NON_FOURNI">Valider non fourni</button>
                                                    </div>
                                                </form>
                                            <?php else: ?>
                                                <span class="muted">Lecture seule.</span>
                                            <?php endif; ?>

                                            <?php if (can_access('stock.damage.signal')): ?>
                                                <form method="post" action="/stock/demandes-cuisine/<?= e((string) $request['id']) ?>/incident" style="margin-top:14px;">
                                                    <label>Soumettre un cas complexe au gerant</label>
                                                    <input type="hidden" name="reported_category" value="litige_stock">
                                                    <input name="quantity_affected" value="<?= e((string) ((float) $request['quantity_supplied'] > 0 ? (float) $request['quantity_supplied'] : (float) $request['quantity_requested'])) ?>">
                                                    <textarea name="signal_notes">Cas complexe stock a arbitrer a partir de la trace reelle de la demande cuisine.</textarea>
                                                    <button type="submit">Soumettre au gerant</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if (count($productRequests) > $activePreviewLimit): ?>
                            <div style="padding-top:12px;">
                                <button type="button" data-history-toggle="<?= e($productGroupId) ?>">Voir plus</button>
                            </div>
                        <?php endif; ?>
                    </div>
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
            </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><strong><?= e($item['name']) ?></strong><br><span class="muted"><?= e($item['unit_name']) ?></span></td>
                    <td><?= e((string) $item['quantity_in_stock']) ?></td>
                    <td><?= e((string) $item['quantity_out_provisional']) ?></td>
                    <td><?= e((string) $item['alert_threshold']) ?></td>
                    <td><?= e(format_money($item['estimated_unit_cost'], $restaurant['currency_code'] ?? 'USD')) ?></td>
                    <td><?= e(format_money(((float) $item['quantity_in_stock']) * ((float) $item['estimated_unit_cost']), $restaurant['currency_code'] ?? 'USD')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
