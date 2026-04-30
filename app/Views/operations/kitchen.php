<?php
declare(strict_types=1);

$activeRequestStatuses = ['DEMANDE', 'EN_PREPARATION', 'PRET_A_SERVIR', 'FOURNI_PARTIEL', 'FOURNI_TOTAL'];
$historyTimezone = safe_timezone($restaurant['settings']['restaurant_reports_timezone'] ?? ($restaurant['timezone'] ?? null));
$todayDate = (new DateTimeImmutable('now', $historyTimezone))->format('Y-m-d');
$yesterdayDate = (new DateTimeImmutable('yesterday', $historyTimezone))->format('Y-m-d');
$historyPreviewLimit = 6;
$activePreviewLimit = 5;
$restaurantCurrency = restaurant_currency($restaurant);
$serverRequestHistoryItems = $server_request_history_items ?? [];

$normalizeServiceItemStatus = static function (array $item): string {
    $status = (string) ($item['status'] ?: $item['request_status']);

    return match ($status) {
        'FOURNI_TOTAL', 'FOURNI_PARTIEL', 'NON_FOURNI' => 'PRET_A_SERVIR',
        default => $status,
    };
};

$sortServiceItems = static function (array $left, array $right): int {
    $leftUrgency = (string) ($left['priority_level'] ?? ($left['planning_status'] ?? ''));
    $rightUrgency = (string) ($right['priority_level'] ?? ($right['planning_status'] ?? ''));

    if ($leftUrgency !== $rightUrgency) {
        if (in_array($leftUrgency, ['urgente', 'urgence'], true)) {
            return -1;
        }

        if (in_array($rightUrgency, ['urgente', 'urgence'], true)) {
            return 1;
        }
    }

    $leftDate = (string) ($left['request_created_at'] ?? $left['created_at'] ?? '');
    $rightDate = (string) ($right['request_created_at'] ?? $right['created_at'] ?? '');

    return strcmp($leftDate, $rightDate);
};

$sortStockRequests = static function (array $left, array $right): int {
    $leftPriority = (string) ($left['priority_level'] ?? 'normale');
    $rightPriority = (string) ($right['priority_level'] ?? 'normale');

    if ($leftPriority !== $rightPriority) {
        return $leftPriority === 'urgente' ? -1 : 1;
    }

    return strcmp((string) ($left['created_at'] ?? ''), (string) ($right['created_at'] ?? ''));
};

$groupItems = static function (array $items, callable $resolver): array {
    $grouped = [];

    foreach ($items as $item) {
        $label = trim((string) $resolver($item));
        $label = $label !== '' ? $label : 'Sans attribution';
        $grouped[$label] ??= [];
        $grouped[$label][] = $item;
    }

    ksort($grouped, SORT_NATURAL | SORT_FLAG_CASE);

    return $grouped;
};

$groupDomId = static function (string $prefix, string $label): string {
    $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($label)) ?? 'bloc';
    $slug = trim($slug, '_');

    return $prefix . '_' . ($slug !== '' ? $slug : 'bloc');
};
?>
<style>
@media print {
    .no-print { display:none !important; }
    .card { box-shadow:none !important; border:1px solid #d6d6d6; }
}
</style>
<?php

$formatCaseType = static function (array $case): string {
    $category = (string) ($case['reported_category'] ?? $case['case_type'] ?? '');

    return match ($category) {
        'retour_simple' => 'Retour simple',
        'retour_avec_anomalie' => 'Retour avec anomalie',
        'produit_defectueux' => 'Produit defectueux',
        'produit_casse' => 'Produit casse',
        'produit_impropre' => 'Produit impropre',
        'incident' => 'Incident',
        default => $category !== '' ? str_replace('_', ' ', ucfirst($category)) : 'Cas cuisine',
    };
};

$formatCaseHistoryStatus = static function (array $case): string {
    $status = (string) ($case['status'] ?? '');

    return match ($status) {
        'EN_ATTENTE_VALIDATION_MANAGER' => 'Transmis au gerant',
        'VALIDE' => 'Traite',
        'REJETE' => 'Rejete',
        default => validation_status_label($status),
    };
};

$waitingServerItems = array_values(array_filter(
    $server_request_items,
    static fn (array $item): bool => $normalizeServiceItemStatus($item) === 'DEMANDE'
));
$preparingServerItems = array_values(array_filter(
    $server_request_items,
    static fn (array $item): bool => $normalizeServiceItemStatus($item) === 'EN_PREPARATION'
));
$readyServerItems = array_values(array_filter(
    $server_request_items,
    static fn (array $item): bool => $normalizeServiceItemStatus($item) === 'PRET_A_SERVIR'
));

usort($waitingServerItems, $sortServiceItems);
usort($preparingServerItems, $sortServiceItems);
usort($readyServerItems, $sortServiceItems);

$activeStockRequests = array_values(array_filter(
    $kitchen_stock_requests,
    static fn (array $request): bool => in_array((string) $request['status'], ['DEMANDE', 'EN_COURS_TRAITEMENT', 'FOURNI_TOTAL', 'FOURNI_PARTIEL', 'NON_FOURNI'], true)
));
$closedStockRequests = array_values(array_filter(
    $kitchen_stock_requests,
    static fn (array $request): bool => (string) $request['status'] === 'CLOTURE'
));
usort($activeStockRequests, $sortStockRequests);

$pendingKitchenCases = array_values(array_filter(
    $cases,
    static fn (array $case): bool => empty($case['technical_confirmed_by'])
        && !in_array((string) ($case['status'] ?? ''), ['VALIDE', 'REJETE', 'EN_ATTENTE_VALIDATION_MANAGER'], true)
));
$escalatedKitchenCases = array_values(array_filter(
    $cases,
    static fn (array $case): bool => (string) ($case['status'] ?? '') === 'EN_ATTENTE_VALIDATION_MANAGER'
));
$resolvedKitchenCases = array_values(array_filter(
    $cases,
    static fn (array $case): bool => in_array((string) ($case['status'] ?? ''), ['VALIDE', 'REJETE'], true)
        || !empty($case['submitted_to_manager_at'])
        || !empty($case['technical_confirmed_at'])
));

$historyEntries = [];
foreach ($serverRequestHistoryItems as $item) {
    $requestStatus = (string) ($item['request_status'] ?? '');
    if (in_array($requestStatus, $activeRequestStatuses, true)) {
        continue;
    }

    $eventDate = (string) ($item['request_received_at'] ?: $item['updated_at'] ?: $item['request_created_at'] ?: $item['created_at']);
    $historyEntries[] = [
        'type' => 'Demande serveur',
        'reference' => ($item['menu_item_name'] ?? 'Produit') . ' · ' . ($item['service_reference'] ?: 'Sans reference'),
        'status' => service_flow_status_label($requestStatus !== '' ? $requestStatus : (string) ($item['status'] ?? '')),
        'date' => $eventDate,
        'details' => 'Serveur ' . ($item['server_name'] ?? '-') . ' · demande ' . (string) ($item['requested_quantity'] ?? 0)
            . ' · prepare ' . (string) ($item['supplied_quantity'] ?? 0)
            . ' · indisponible ' . (string) ($item['unavailable_quantity'] ?? 0),
        'amount' => 0.0,
    ];
}

foreach ($resolvedKitchenCases as $case) {
    $eventDate = (string) ($case['resolved_at'] ?: $case['submitted_to_manager_at'] ?: $case['technical_confirmed_at'] ?: $case['created_at']);
    $historyEntries[] = [
        'type' => 'Retour / cas cuisine',
        'reference' => '#' . (string) $case['id'] . ' · ' . $formatCaseType($case),
        'status' => $formatCaseHistoryStatus($case),
        'date' => $eventDate,
        'details' => case_source_label($case['source_module'] ?? null) . ' · quantite ' . (string) ($case['quantity_affected'] ?? 0)
            . ' ' . (string) ($case['unit_name'] ?? '')
            . ' · ' . (($case['technical_notes'] ?? '') !== '' ? (string) $case['technical_notes'] : ((string) ($case['signal_notes'] ?? '-') ?: '-')),
        'amount' => 0.0,
    ];
}

foreach ($closedStockRequests as $request) {
    $eventDate = (string) ($request['received_at'] ?: $request['responded_at'] ?: $request['created_at']);
    $historyEntries[] = [
        'type' => 'Demande stock cloturee',
        'reference' => ($request['stock_item_name'] ?? 'Article') . ' · ' . priority_label($request['priority_level'] ?? null),
        'status' => stock_request_status_label($request['status'] ?? null),
        'date' => $eventDate,
        'details' => 'Demande ' . (string) ($request['quantity_requested'] ?? 0)
            . ' · fournie ' . (string) ($request['quantity_supplied'] ?? 0)
            . ' · reception ' . format_date_fr($request['received_at'] ?? null, $historyTimezone),
        'amount' => 0.0,
    ];
}

foreach ($productions as $production) {
    if ((string) ($production['status'] ?? '') !== 'TERMINE' && empty($production['closed_at'])) {
        continue;
    }

    $eventDate = (string) ($production['closed_at'] ?: $production['created_at']);
    $historyEntries[] = [
        'type' => 'Preparation terminee',
        'reference' => ($production['dish_type'] ?? 'Production') . ' · ' . ($production['menu_item_name'] ?? $production['stock_item_name'] ?? 'Cuisine'),
        'status' => validation_status_label($production['status'] ?? null),
        'date' => $eventDate,
        'details' => 'Produit ' . (string) ($production['quantity_produced'] ?? 0)
            . ' · reste ' . (string) ($production['quantity_remaining'] ?? 0)
            . ' · cree par ' . (string) ($production['created_by_name'] ?? '-'),
        'amount' => 0.0,
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
            'dom_id' => 'kitchen_history_' . str_replace('-', '_', $groupKey),
            'entries' => [],
        ];
    }

    $historyGroups[$groupKey]['entries'][] = $entry;
}

$serviceSections = [
    'En attente' => [
        'items' => $waitingServerItems,
        'empty' => 'Aucune nouvelle demande serveur en attente.',
        'dom_id' => 'kitchen_waiting',
        'counter' => count($waitingServerItems) . ' commande(s) en attente',
    ],
    'En preparation' => [
        'items' => $preparingServerItems,
        'empty' => 'Aucune demande actuellement en preparation.',
        'dom_id' => 'kitchen_preparing',
        'counter' => count($preparingServerItems) . ' commande(s) en preparation',
    ],
    'Pret a servir' => [
        'items' => $readyServerItems,
        'empty' => 'Aucune demande prete a remettre au serveur.',
        'dom_id' => 'kitchen_ready',
        'counter' => count($readyServerItems) . ' pret(e)(s) a servir',
    ],
];
$serviceBadgeClass = static function (?string $status): string {
    return match ((string) $status) {
        'DEMANDE' => 'badge-waiting',
        'EN_PREPARATION', 'FOURNI_PARTIEL' => 'badge-progress',
        'PRET_A_SERVIR', 'FOURNI_TOTAL', 'REMIS_SERVEUR' => 'badge-ready',
        'CLOTURE', 'VENDU_PARTIEL', 'VENDU_TOTAL', 'VALIDE' => 'badge-closed',
        'NON_FOURNI', 'REJETE' => 'badge-bad',
        default => 'badge-neutral',
    };
};
$stockBadgeClass = static function (?string $status): string {
    return match ((string) $status) {
        'DEMANDE' => 'badge-waiting',
        'EN_COURS_TRAITEMENT' => 'badge-progress',
        'FOURNI_TOTAL', 'FOURNI_PARTIEL' => 'badge-ready',
        'NON_FOURNI' => 'badge-bad',
        'CLOTURE' => 'badge-closed',
        default => 'badge-neutral',
    };
};
?>

<section class="topbar">
    <div class="brand">
        <h1>Cuisine</h1>
        <p>La file active reste separee par etape, les retours sont traites clairement et l historique reste compact par jour pour garder une lecture rapide meme en gros volume.</p>
    </div>
</section>

<?php if (!empty($flash_success)): ?><div class="flash-ok"><?= e($flash_success) ?></div><?php endif; ?>
<?php if (!empty($flash_error)): ?><div class="flash-bad"><?= e($flash_error) ?></div><?php endif; ?>
<section class="card no-print" style="padding:18px; margin-bottom:24px;">
    <div class="toolbar-actions">
        <button type="button" onclick="window.print()">Imprimer</button>
        <a href="/cuisine?print=1" class="button-muted" target="_blank" rel="noopener noreferrer">Export imprimable / PDF navigateur</a>
    </div>
</section>

<section class="grid stats">
    <article class="card stat">
        <span>Demandes en attente</span>
        <strong><?= e((string) count($waitingServerItems)) ?></strong>
    </article>
    <article class="card stat">
        <span>En preparation</span>
        <strong><?= e((string) count($preparingServerItems)) ?></strong>
    </article>
    <article class="card stat">
        <span>Pretes a servir</span>
        <strong><?= e((string) count($readyServerItems)) ?></strong>
    </article>
    <article class="card stat">
        <span>Retours a traiter</span>
        <strong><?= e((string) count($pendingKitchenCases)) ?></strong>
    </article>
    <article class="card stat">
        <span>Demandes stock actives</span>
        <strong><?= e((string) count($activeStockRequests)) ?></strong>
    </article>
</section>

<section class="card" style="padding:22px; margin-top:24px;">
    <div class="topbar" style="margin-bottom:10px;">
        <div>
            <h2 style="margin:0;">File cuisine active</h2>
            <p class="muted" style="margin:6px 0 0;">Fourni reel au serveur: les lignes quittent cette zone des que le serveur confirme la remise. Les urgences restent en tete et, ensuite, les lignes les plus anciennes passent avant les nouvelles pour fluidifier le terrain.</p>
        </div>
        <span class="pill badge-neutral"><?= e((string) count($server_request_items)) ?> ligne(s) actives</span>
    </div>
</section>

<?php foreach ($serviceSections as $sectionTitle => $section): ?>
    <?php $groupedSectionItems = $groupItems($section['items'], static fn (array $item): string => (string) ($item['server_name'] ?? 'Sans serveur')); ?>
    <section class="card" style="margin-top:24px;">
        <div style="padding:22px 22px 12px;">
            <div class="topbar">
                <div>
                    <h2 style="margin:0;"><?= e($sectionTitle) ?></h2>
                    <p class="muted" style="margin:6px 0 0;"><?= e($section['empty']) ?> Regroupement visuel par serveur pour accelerer la lecture.</p>
                </div>
                <span class="pill badge-neutral"><?= e($section['counter']) ?></span>
            </div>
        </div>

        <?php if ($section['items'] === []): ?>
            <div style="padding:0 22px 22px;">
                <p class="muted">Aucune ligne dans ce bloc.</p>
            </div>
        <?php else: ?>
            <div style="padding:0 22px 22px;">
                <?php foreach ($groupedSectionItems as $serverName => $serverItems): ?>
                    <?php $serverGroupId = $groupDomId($section['dom_id'], $serverName); ?>
                    <div style="border-top:1px solid var(--line); padding-top:16px; margin-top:16px;">
                        <div class="topbar" style="margin-bottom:10px;">
                            <div>
                                <h3 style="margin:0;"><?= e($serverName) ?></h3>
                                <p class="muted" style="margin:6px 0 0;"><?= e((string) count($serverItems)) ?> ligne(s) dans ce bloc serveur.</p>
                            </div>
                            <span class="pill badge-neutral"><?= e((string) count($serverItems)) ?></span>
                        </div>

                        <div class="table-wrap">
                            <table>
                                <thead>
                                <tr>
                                    <th>Heure</th>
                                    <th>Service / table</th>
                                    <th>Produit</th>
                                    <th>Demande</th>
                                    <th>Prepare</th>
                                    <th>Indisponible</th>
                                    <th>Statut</th>
                                    <th>Trace</th>
                                    <th>Note</th>
                                    <th>Action cuisine</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($serverItems as $index => $item): ?>
                                    <tr class="<?= $index >= $activePreviewLimit ? 'history-extra' : '' ?>" data-history-group="<?= e($serverGroupId) ?>" <?= $index >= $activePreviewLimit ? 'style="display:none;"' : '' ?>>
                                        <td><?= e(format_date_fr($item['request_created_at'] ?? $item['created_at'], $historyTimezone)) ?></td>
                                        <td><?= e((string) ($item['service_reference'] ?: '-')) ?></td>
                                        <td><strong><?= e($item['menu_item_name'] ?? '-') ?></strong></td>
                                        <td><?= e((string) $item['requested_quantity']) ?></td>
                                        <td><?= e((string) $item['supplied_quantity']) ?></td>
                                        <td><?= e((string) $item['unavailable_quantity']) ?></td>
                                        <td><span class="pill <?= e($serviceBadgeClass($item['status'] ?: $item['request_status'])) ?>"><?= e(service_flow_status_label($item['status'] ?: $item['request_status'])) ?></span></td>
                                        <td style="min-width:210px;">
                                            <div><strong>Demande :</strong> <?= e(signed_actor_line('Demande', ($item['requested_by_name'] ?? '') !== '' ? $item['requested_by_name'] : ($item['server_name'] ?? '-'), 'cashier_server', $item['request_created_at'] ?? $item['created_at'] ?? null, $restaurant, $historyTimezone)) ?></div>
                                            <div><strong>Pris :</strong> <?= e(signed_actor_line('Pris en charge', $item['prepared_by_name'] ?: null, 'kitchen', $item['prepared_at'] ?? null, $restaurant, $historyTimezone)) ?></div>
                                            <div><strong>Pret :</strong> <?= e(signed_actor_line('Pret', $item['ready_by_name'] ?: null, 'kitchen', $item['request_ready_at'] ?? $item['ready_at'] ?? null, $restaurant, $historyTimezone)) ?></div>
                                            <div><strong>Recu :</strong> <?= e(signed_actor_line('Recu', $item['received_by_name'] ?: null, 'cashier_server', $item['request_received_at'] ?? $item['received_at'] ?? null, $restaurant, $historyTimezone)) ?></div>
                                        </td>
                                        <td style="min-width:180px;"><?= e((string) ($item['request_note'] ?: '-')) ?></td>
                                        <td style="min-width:220px;">
                                            <?php if (can_access('kitchen.request.fulfill')): ?>
                                                <form method="post" action="/cuisine/demandes-serveur/<?= e((string) $item['id']) ?>/fourni">
                                                    <label>Quantite preparee</label>
                                                    <input name="supplied_quantity" value="<?= e((string) ((float) $item['supplied_quantity'] > 0 ? (float) $item['supplied_quantity'] : (float) $item['requested_quantity'])) ?>">
                                                    <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:10px;">
                                                        <button type="submit" name="workflow_stage" value="EN_PREPARATION">Prendre en charge</button>
                                                        <button type="submit" name="workflow_stage" value="PRET_A_SERVIR">Marquer pret</button>
                                                    </div>
                                                </form>
                                            <?php else: ?>
                                                <span class="muted">Lecture seule.</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if (count($serverItems) > $activePreviewLimit): ?>
                            <div style="padding-top:12px;">
                                <button type="button" data-history-toggle="<?= e($serverGroupId) ?>">Voir plus</button>
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
                <h2 style="margin:0;">Retours et confirmations techniques</h2>
                <p class="muted" style="margin:6px 0 0;">Le retour simple se valide dans le flux cuisine. Le cas complexe est confirme techniquement puis sort vers la file gerant.</p>
            </div>
            <span class="pill badge-progress"><?= e((string) count($pendingKitchenCases)) ?> a traiter</span>
        </div>
    </div>

    <?php if ($pendingKitchenCases === []): ?>
        <div style="padding:0 22px 22px;">
            <p class="muted">Aucun retour ou cas en attente de confirmation technique.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Heure</th>
                    <th>Origine</th>
                    <th>Categorie</th>
                    <th>Quantite</th>
                    <th>Signalement</th>
                    <th>Action cuisine</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($pendingKitchenCases as $index => $case): ?>
                    <tr class="<?= $index >= $activePreviewLimit ? 'history-extra' : '' ?>" data-history-group="kitchen_pending_cases" <?= $index >= $activePreviewLimit ? 'style="display:none;"' : '' ?>>
                        <td><?= e(format_date_fr($case['created_at'] ?? null, $historyTimezone)) ?></td>
                        <td><?= e(case_source_label($case['source_module'] ?? null)) ?></td>
                        <td>
                            <strong><?= e($formatCaseType($case)) ?></strong><br>
                            <span class="muted">Cas #<?= e((string) $case['id']) ?></span>
                        </td>
                        <td><?= e((string) ($case['quantity_affected'] ?? 0)) ?> <?= e((string) ($case['unit_name'] ?? '')) ?></td>
                        <td style="min-width:220px;"><?= e((string) ($case['signal_notes'] ?: '-')) ?></td>
                        <td style="min-width:260px;">
                            <?php if (can_access('incident.confirm.technical')): ?>
                                <form method="post" action="/cuisine/retours">
                                    <input type="hidden" name="case_id" value="<?= e((string) $case['id']) ?>">
                                    <label>Traitement</label>
                                    <select name="technical_outcome">
                                        <option value="retour_simple">Retour simple a valider</option>
                                        <option value="incident">Cas complexe a soumettre au gerant</option>
                                    </select>
                                    <label>Constat technique</label>
                                    <textarea name="technical_notes">Confirmation technique cuisine sur le cas signale.</textarea>
                                    <button type="submit">Confirmer techniquement</button>
                                </form>
                            <?php else: ?>
                                <span class="muted">Aucune action autorisee.</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($pendingKitchenCases) > $activePreviewLimit): ?>
            <div style="padding:12px 22px 22px;">
                <button type="button" data-history-toggle="kitchen_pending_cases">Voir plus</button>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="card" style="margin-top:24px;">
    <div style="padding:22px 22px 12px;">
        <div class="topbar">
            <div>
                <h2 style="margin:0;">Cas deja transmis au gerant</h2>
                <p class="muted" style="margin:6px 0 0;">Ces cas ne restent plus dans le flux simple cuisine, mais la trace reste visible.</p>
            </div>
            <span class="pill badge-bad"><?= e((string) count($escalatedKitchenCases)) ?> en suivi</span>
        </div>
    </div>

    <?php if ($escalatedKitchenCases === []): ?>
        <div style="padding:0 22px 22px;">
            <p class="muted">Aucun cas complexe actuellement dans la file gerant.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Cas</th>
                    <th>Origine</th>
                    <th>Quantite</th>
                    <th>Confirme par</th>
                    <th>Transmis</th>
                    <th>Constat</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($escalatedKitchenCases as $case): ?>
                    <tr>
                        <td><strong>#<?= e((string) $case['id']) ?></strong><br><span class="muted"><?= e($formatCaseType($case)) ?></span></td>
                        <td><?= e(case_source_label($case['source_module'] ?? null)) ?></td>
                        <td><?= e((string) ($case['quantity_affected'] ?? 0)) ?> <?= e((string) ($case['unit_name'] ?? '')) ?></td>
                        <td><?= e((string) ($case['technical_confirmed_by_name'] ?: '-')) ?></td>
                        <td><?= e(format_date_fr($case['submitted_to_manager_at'] ?? $case['technical_confirmed_at'] ?? null, $historyTimezone)) ?></td>
                        <td style="min-width:220px;"><?= e((string) (($case['technical_notes'] ?? '') !== '' ? $case['technical_notes'] : ($case['signal_notes'] ?? '-'))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card" style="margin-top:24px;">
    <div style="padding:22px 22px 12px;">
        <div class="topbar">
            <div>
                <h2 style="margin:0;">Demandes stock actives</h2>
                <p class="muted" style="margin:6px 0 0;">Cette file reste totalement separee des preparations client pour eviter les melanges en plein service.</p>
            </div>
            <span class="pill badge-neutral"><?= e((string) count($activeStockRequests)) ?> actives</span>
        </div>
    </div>

    <?php if ($activeStockRequests === []): ?>
        <div style="padding:0 22px 22px;">
            <p class="muted">Aucune demande cuisine vers stock en cours.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Heure</th>
                    <th>Article</th>
                    <th>Priorite</th>
                    <th>Demande</th>
                    <th>Fourni</th>
                    <th>Indisponible</th>
                    <th>Statut</th>
                    <th>Trace</th>
                    <th>Notes</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($activeStockRequests as $index => $request): ?>
                    <tr class="<?= $index >= $activePreviewLimit ? 'history-extra' : '' ?>" data-history-group="kitchen_active_stock" <?= $index >= $activePreviewLimit ? 'style="display:none;"' : '' ?>>
                        <td><?= e(format_date_fr($request['created_at'] ?? null, $historyTimezone)) ?></td>
                        <td><strong><?= e($request['stock_item_name'] ?? '-') ?></strong></td>
                        <td><?= e(priority_label($request['priority_level'] ?? null)) ?></td>
                        <td><?= e((string) $request['quantity_requested']) ?> <?= e((string) ($request['unit_name'] ?? '')) ?></td>
                        <td><?= e((string) $request['quantity_supplied']) ?> <?= e((string) ($request['unit_name'] ?? '')) ?></td>
                        <td><?= e((string) $request['unavailable_quantity']) ?> <?= e((string) ($request['unit_name'] ?? '')) ?></td>
                        <td><span class="pill <?= e($stockBadgeClass($request['status'] ?? null)) ?>"><?= e(stock_request_status_label($request['status'] ?? null)) ?></span></td>
                        <td style="min-width:200px;">
                            <div><strong>Demande :</strong> <?= e(signed_actor_line('Demande', $request['requested_by_name'] ?: null, 'kitchen', $request['created_at'] ?? null, $restaurant, $historyTimezone)) ?></div>
                            <div><strong>Reponse stock :</strong> <?= e(signed_actor_line('Repondu', $request['responded_by_name'] ?: null, 'stock_manager', $request['responded_at'] ?? null, $restaurant, $historyTimezone)) ?></div>
                            <div><strong>Reception :</strong> <?= e(signed_actor_line('Recu', $request['received_by_name'] ?: null, 'kitchen', $request['received_at'] ?? null, $restaurant, $historyTimezone)) ?></div>
                        </td>
                        <td style="min-width:220px;">
                            <div><strong>Cuisine :</strong> <?= e((string) ($request['note'] ?: '-')) ?></div>
                            <div><strong>Stock :</strong> <?= e((string) ($request['response_note'] ?: '-')) ?></div>
                        </td>
                        <td style="min-width:180px;">
                            <?php if (in_array((string) $request['status'], ['FOURNI_TOTAL', 'FOURNI_PARTIEL', 'NON_FOURNI'], true) && can_access('kitchen.stock.request')): ?>
                                <form method="post" action="/cuisine/demandes-stock/<?= e((string) $request['id']) ?>/reception">
                                    <button type="submit">Confirmer reception</button>
                                </form>
                            <?php else: ?>
                                <span class="muted">En attente de reponse stock.</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($activeStockRequests) > $activePreviewLimit): ?>
            <div style="padding:12px 22px 22px;">
                <button type="button" data-history-toggle="kitchen_active_stock">Voir plus</button>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="split" style="margin-top:24px;">
    <article class="card" style="padding:22px;">
        <h2 style="margin-top:0;">Nouvelle production</h2>
        <?php if (can_access('kitchen.production.create')): ?>
            <form method="post" action="/cuisine/productions">
                <label>Matiere utilisee</label>
                <select name="stock_item_id">
                    <?php foreach ($stock_items as $item): ?>
                        <option value="<?= e((string) $item['id']) ?>"><?= e($item['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label>Article du menu lie</label>
                <select name="menu_item_id">
                    <option value="">Aucun lien</option>
                    <?php foreach ($menu_items as $item): ?>
                        <option value="<?= e((string) $item['id']) ?>"><?= e($item['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label>Type de plat</label>
                <input name="dish_type" placeholder="Poulet braise, dessert, jus maison" required>
                <label><input type="checkbox" name="publish_to_menu" value="1" style="width:auto;margin-right:8px;">Publier ce plat dans le menu</label>
                <label>Categorie de menu</label>
                <select name="menu_category_id">
                    <option value="">Choisir une categorie</option>
                    <?php foreach (($menu_categories ?? []) as $category): ?>
                        <?php if (($category['status'] ?? '') === 'active'): ?>
                            <option value="<?= e((string) $category['id']) ?>"><?= e($category['name']) ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <label>Prix menu</label>
                <input name="menu_price" value="0.00">
                <label>Description menu</label>
                <textarea name="menu_description">Plat propose par la cuisine et publie dans le menu.</textarea>
                <label>Quantite sortie du stock</label>
                <input name="quantity" value="1">
                <label>Quantite produite</label>
                <input name="quantity_produced" value="1">
                <label>Note</label>
                <textarea name="note">Production cuisine du service.</textarea>
                <button type="submit">Enregistrer la production</button>
            </form>
        <?php else: ?>
            <p class="muted">Lecture seule.</p>
        <?php endif; ?>
    </article>

    <article class="card" style="padding:22px;">
        <h2 style="margin-top:0;">Demande cuisine vers stock</h2>
        <?php if (can_access('kitchen.stock.request')): ?>
            <form method="post" action="/cuisine/demandes-stock">
                <label>Article de stock</label>
                <select name="stock_item_id">
                    <?php foreach ($stock_items as $item): ?>
                        <option value="<?= e((string) $item['id']) ?>"><?= e($item['name']) ?> (<?= e((string) $item['quantity_in_stock']) ?> <?= e($item['unit_name']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <label>Quantite demandee</label>
                <input name="quantity_requested" value="1">
                <label>Priorite</label>
                <select name="priority_level">
                    <option value="normale">Normale</option>
                    <option value="urgente">Urgente</option>
                </select>
                <label>Note</label>
                <textarea name="note">Besoin cuisine a traiter par le stock.</textarea>
                <button type="submit">Envoyer au stock</button>
            </form>
        <?php else: ?>
            <p class="muted">Action reservee a la cuisine.</p>
        <?php endif; ?>
    </article>
</section>

<?php if (can_access('kitchen.incident.signal')): ?>
    <section class="card" style="padding:22px; margin-top:24px;">
        <h2 style="margin-top:0;">Signaler un incident cuisine</h2>
        <p class="muted">Ce formulaire reste reserve aux incidents de production cuisine. Les retours serveur deja signales se traitent dans le bloc de confirmations techniques ci-dessus.</p>
        <form method="post" action="/cuisine/incidents">
            <label>Production</label>
            <select name="production_id">
                <?php foreach ($productions as $production): ?>
                    <option value="<?= e((string) $production['id']) ?>"><?= e(($production['dish_type'] ?? 'Production') . ' - reste ' . (string) ($production['quantity_remaining'] ?? 0)) ?></option>
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
            <textarea name="signal_notes">Produit defectueux, tombe ou impropre detecte en cuisine.</textarea>
            <button type="submit">Signaler au gerant</button>
        </form>
    </section>
<?php endif; ?>

<section class="card" style="margin-top:24px;">
    <div style="padding:22px 22px 10px;">
        <h2 style="margin:0;">Historique cuisine</h2>
        <p class="muted" style="margin:6px 0 0;">Aujourd hui reste ouvert par defaut. Les jours plus anciens sont replies pour eviter l encombrement, avec un bouton Voir plus si la journee est chargee.</p>
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
                    <span class="muted">Historique cuisine</span>
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
