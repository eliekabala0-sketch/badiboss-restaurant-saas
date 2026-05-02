<?php
declare(strict_types=1);

$activeStatuses = ['DEMANDE', 'EN_PREPARATION', 'PRET_A_SERVIR', 'FOURNI_PARTIEL', 'FOURNI_TOTAL'];
$remittedStatuses = ['REMIS_SERVEUR'];
$closedStatuses = ['CLOTURE', 'VENDU_PARTIEL', 'VENDU_TOTAL'];
$historyTimezone = safe_timezone($restaurant['settings']['restaurant_reports_timezone'] ?? ($restaurant['timezone'] ?? null));
$todayDate = (new DateTimeImmutable('now', $historyTimezone))->format('Y-m-d');
$yesterdayDate = (new DateTimeImmutable('yesterday', $historyTimezone))->format('Y-m-d');
$autoCloseMinutes = max(15, (int) ($restaurant['settings']['restaurant_server_auto_close_minutes'] ?? 90));
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
$serviceBadgeClass = static function (?string $status): string {
    return match ((string) $status) {
        'DEMANDE' => 'badge-waiting',
        'EN_PREPARATION', 'FOURNI_PARTIEL' => 'badge-progress',
        'PRET_A_SERVIR', 'FOURNI_TOTAL', 'REMIS_SERVEUR' => 'badge-ready',
        'CLOTURE', 'VENDU_PARTIEL', 'VENDU_TOTAL' => 'badge-closed',
        default => 'badge-neutral',
    };
};

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

$requestItemsByRequest = [];
foreach ($server_request_items as $item) {
    $requestItemsByRequest[(int) $item['request_id']][] = $item;
}

$historyEntries = [];
foreach ($closedRequests as $request) {
    $eventDate = (string) ($request['closed_at'] ?: $request['updated_at'] ?: $request['created_at']);
    $historyEntries[] = [
        'type' => 'Demande service',
        'reference' => '#' . (string) $request['id'] . ' · ' . (string) ($request['service_reference'] ?: '-'),
        'status' => service_flow_status_label($request['status']),
        'date' => $eventDate,
        'details' => format_money($request['total_sold_amount'], $restaurantCurrency) . ' vendu',
        'amount' => (float) ($request['total_sold_amount'] ?? 0),
    ];
}
foreach ($sales as $sale) {
    $eventDate = (string) ($sale['validated_at'] ?: $sale['created_at']);
    $historyEntries[] = [
        'type' => 'Vente',
        'reference' => '#' . (string) $sale['id'] . ' · ' . (string) ($sale['server_name'] ?? 'Vente automatique'),
        'status' => validation_status_label($sale['status']),
        'date' => $eventDate,
        'details' => format_money($sale['total_amount'], $restaurantCurrency),
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
        $label = $groupKey === $todayDate
            ? 'Aujourd’hui'
            : ($groupKey === $yesterdayDate ? 'Hier' : $entryDate->format('d/m/Y'));

        $historyGroups[$groupKey] = [
            'label' => $label,
            'date_text' => $entryDate->format('d/m/Y'),
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
</style>

<section class="topbar">
    <div class="brand">
        <h1>Service et ventes</h1>
        <p>Suivez les demandes envoyées à la cuisine, confirmez les remises reçues, clôturez rapidement le service et gardez un historique aéré par journée.</p>
    </div>
</section>

<?php if (!empty($flash_success)): ?><div class="flash-ok"><?= e($flash_success) ?></div><?php endif; ?>
<?php if (!empty($flash_error)): ?><div class="flash-bad"><?= e($flash_error) ?></div><?php endif; ?>
<section class="card" style="padding:18px; margin-bottom:24px;">
    <div class="menu-thumb">
        <img src="<?= e($restaurantLogo) ?>" alt="Logo restaurant">
        <div>
            <strong><?= e($restaurant['public_name'] ?? $restaurant['name'] ?? 'Restaurant') ?></strong><br>
            <span class="muted">Logo visible dans le module ventes. Les photos des plats remontent aussi dans les demandes et les ventes du service.</span>
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
        <span>Total vendu aujourd’hui</span>
        <strong><?= e(format_money($salesOverview['today_total_sold'] ?? 0, $restaurantCurrency)) ?></strong>
    </article>
    <article class="card stat">
        <span>Ventes validées du jour</span>
        <strong><?= e((string) ($salesOverview['today_sales_count'] ?? 0)) ?></strong>
    </article>
    <article class="card stat">
        <span>Demandes actives</span>
        <strong><?= e((string) ($salesOverview['active_requests_count'] ?? 0)) ?></strong>
    </article>
    <article class="card stat">
        <span>À clôturer vite</span>
        <strong><?= e((string) ($salesOverview['remitted_requests_count'] ?? 0)) ?></strong>
    </article>
</section>

<section class="split">
    <article class="card" style="padding:22px;">
        <h2 style="margin-top:0;">Nouvelle demande de service</h2>
        <p class="muted" style="margin-top:0;">Demande serveur depuis le menu avec attente fourni cuisine pour garder une lecture claire du flux.</p>
        <?php if (can_access('sales.request.create')): ?>
            <form method="post" action="/ventes/demandes">
                <label>Article du menu</label>
                <select name="menu_item_id">
                    <?php foreach ($menu_items as $item): ?>
                        <option value="<?= e((string) $item['id']) ?>"><?= e($item['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label>Quantité demandée</label>
                <input name="requested_quantity" value="1">
                <label>Table ou référence de service</label>
                <input name="service_reference" placeholder="Table 12, Terrasse B, Ticket 48">
                <label>Prix unitaire</label>
                <input name="unit_price" value="1.00">
                <label>Note de service</label>
                <textarea name="note">Demande transmise à la cuisine pour le service en cours.</textarea>
                <button type="submit">Envoyer à la cuisine</button>
            </form>
        <?php else: ?>
            <p class="muted">Création réservée au service.</p>
        <?php endif; ?>
    </article>

    <article class="card" style="padding:22px;">
        <h2 style="margin-top:0;">Perte d’argent</h2>
        <?php if (can_access('cash_loss.declare')): ?>
            <form method="post" action="/ventes/pertes-argent">
                <label>Référence de vente</label>
                <select name="reference_id">
                    <option value="">Aucune</option>
                    <?php foreach ($sales as $sale): ?>
                        <option value="<?= e((string) $sale['id']) ?>"><?= e((string) $sale['id']) ?> - <?= e($sale['server_name'] ?? 'Vente automatique') ?></option>
                    <?php endforeach; ?>
                </select>
                <label>Montant</label>
                <input name="amount" value="1.00">
                <label>Description</label>
                <textarea name="description">Écart de caisse ou remise non justifiée.</textarea>
                <button type="submit">Déclarer la perte</button>
            </form>
        <?php else: ?>
            <p class="muted">Déclaration réservée à la supervision.</p>
        <?php endif; ?>
    </article>
</section>

<section class="card" style="padding:22px; margin-top:24px;">
    <h2 style="margin-top:0;">Actif maintenant</h2>
    <p class="muted">Les demandes actives restent visibles jusqu’à la remise au serveur. Une fois réception confirmée, elles quittent la file principale.</p>
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
                            <div class="muted">
                                <?= e($request['server_name']) ?>
                                · <?= e(format_date_fr($request['created_at'])) ?>
                                · Référence <?= e((string) ($request['service_reference'] ?: '-')) ?>
                            </div>
                        </div>
                        <span class="pill <?= e($serviceBadgeClass($request['status'])) ?>"><?= e(service_flow_status_label($request['status'])) ?></span>
                    </div>
                    <div class="muted" style="margin-bottom:12px;">
                        <?= e(signed_actor_line('Pret', $request['ready_by_name'] ?? null, 'kitchen', $request['ready_at'] ?? null, $restaurant, $historyTimezone)) ?>
                        <br>
                        <?= e(signed_actor_line('Recu', $request['received_by_name'] ?? null, 'cashier_server', $request['received_at'] ?? null, $restaurant, $historyTimezone)) ?>
                    </div>

                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>Produit</th>
                                <th>Demandé</th>
                                <th>Accepté / préparé</th>
                                <th>Non disponible</th>
                                <th>Étape</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td>
                                        <div class="menu-thumb">
                                            <img src="<?= e(menu_item_media_url_or_default($item['menu_item_image_url'] ?? null)) ?>" alt="<?= e($item['menu_item_name']) ?>">
                                            <div><strong><?= e($item['menu_item_name']) ?></strong></div>
                                        </div>
                                    </td>
                                    <td><?= e((string) $item['requested_quantity']) ?></td>
                                    <td><?= e((string) $item['supplied_quantity']) ?></td>
                                    <td><?= e((string) $item['unavailable_quantity']) ?></td>
                                    <td><?= e(service_flow_status_label($item['status'] ?: $item['supply_status'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (in_array((string) $request['status'], ['PRET_A_SERVIR', 'FOURNI_PARTIEL', 'FOURNI_TOTAL'], true) && can_access('sales.request.close')): ?>
                        <form method="post" action="/ventes/demandes/<?= e((string) $request['id']) ?>/reception" style="margin-top:14px;">
                            <button type="submit">Confirmer la réception côté service</button>
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

<section class="card" style="padding:22px; margin-top:24px;">
    <h2 style="margin-top:0;">Remis au serveur</h2>
    <p class="muted">Ces demandes sont dans une courte phase d’attente de clôture. Elles se clôturent automatiquement après <?= e((string) $autoCloseMinutes) ?> minutes maximum ou dès le passage au jour suivant si elles restent ouvertes.</p>
    <?php if ($remittedRequests === []): ?>
        <p class="muted">Aucune remise confirmée à clôturer.</p>
    <?php else: ?>
        <div class="grid">
            <?php foreach ($remittedRequests as $index => $request): ?>
                <?php
                $items = $requestItemsByRequest[(int) $request['id']] ?? [];
                $firstItem = $items[0] ?? null;
                $autoCloseAt = null;
                if (!empty($request['received_at'])) {
                    $autoCloseAt = (new DateTimeImmutable((string) $request['received_at'], $historyTimezone))
                        ->modify('+' . $autoCloseMinutes . ' minutes');
                }
                ?>
                <article class="card <?= $index >= $activePreviewLimit ? 'history-extra' : '' ?>" data-history-group="sales_remitted_requests" <?= $index >= $activePreviewLimit ? 'style="padding:18px; border-radius:16px; display:none;"' : 'style="padding:18px; border-radius:16px;"' ?>>
                    <div class="topbar" style="margin-bottom:12px;">
                        <div>
                            <strong>Demande #<?= e((string) $request['id']) ?></strong>
                            <div class="muted">
                                <?= e($request['server_name']) ?>
                                · Reçue le <?= e(format_date_fr($request['received_at'])) ?>
                                · Référence <?= e((string) ($request['service_reference'] ?: '-')) ?>
                            </div>
                        </div>
                        <span class="pill <?= e($serviceBadgeClass($request['status'])) ?>"><?= e(service_flow_status_label($request['status'])) ?></span>
                    </div>
                    <div class="muted" style="margin-bottom:12px;">
                        <?= e(signed_actor_line('Recu', $request['received_by_name'] ?? ($request['server_name'] ?? null), 'cashier_server', $request['received_at'] ?? null, $restaurant, $historyTimezone)) ?>
                    </div>

                    <?php if ($autoCloseAt instanceof DateTimeImmutable): ?>
                        <p class="muted" style="margin-top:0;">Clôture automatique prévue vers <?= e($autoCloseAt->format('d/m/Y H:i')) ?> si aucune clôture manuelle n’est faite avant.</p>
                    <?php endif; ?>

                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>Produit</th>
                                <th>Préparé</th>
                                <th>Non disponible</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td>
                                        <div class="menu-thumb">
                                            <img src="<?= e(menu_item_media_url_or_default($item['menu_item_image_url'] ?? null)) ?>" alt="<?= e($item['menu_item_name']) ?>">
                                            <div><strong><?= e($item['menu_item_name']) ?></strong></div>
                                        </div>
                                    </td>
                                    <td><?= e((string) $item['supplied_quantity']) ?></td>
                                    <td><?= e((string) $item['unavailable_quantity']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($firstItem !== null && can_access('sales.request.close')): ?>
                        <form method="post" action="/ventes/demandes/<?= e((string) $request['id']) ?>/cloture" class="split" style="margin-top:14px;">
                            <input type="hidden" name="request_item_id" value="<?= e((string) $firstItem['id']) ?>">
                            <div>
                                <label>Type</label>
                                <select name="sale_type">
                                    <option value="SUR_PLACE">Sur place</option>
                                    <option value="LIVRAISON">Livraison</option>
                                </select>
                            </div>
                            <div>
                                <label>Quantité vendue</label>
                                <input name="sold_quantity" value="<?= e((string) $firstItem['supplied_quantity']) ?>">
                            </div>
                            <div>
                                <label>Quantité retournée</label>
                                <input name="returned_quantity" value="0">
                            </div>
                            <div style="align-self:end;">
                                <button type="submit">Clôturer le service</button>
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
</section>

<section class="card" style="padding:22px; margin-top:24px;">
    <h2 style="margin-top:0;">Signaler un retour ou une casse</h2>
    <p class="muted">Le service déclare le cas ici. Toute décision manager reste centralisée dans la file traçable du tableau de bord <a href="/owner">/owner</a>.</p>
    <?php if (can_access('sales.incident.signal')): ?>
        <form method="post" action="/ventes/incidents">
            <label>Article vendu</label>
            <select name="sale_item_id">
                <?php foreach ($sale_items as $item): ?>
                    <option value="<?= e((string) $item['id']) ?>"><?= e($item['menu_item_name']) ?> - <?= e($item['server_name'] ?? 'Vente automatique') ?></option>
                <?php endforeach; ?>
            </select>
            <label>Quantité impactée</label>
            <input name="quantity_affected" value="1" required>
            <label>Catégorie</label>
            <select name="reported_category">
                <?php foreach ($incident_types as $incidentType): ?>
                    <option value="<?= e($incidentType) ?>"><?= e($incidentType) ?></option>
                <?php endforeach; ?>
            </select>
            <label>Signalement</label>
            <textarea name="signal_notes">Retour, casse ou incident constaté pendant le service.</textarea>
            <button type="submit">Signaler au gérant</button>
        </form>
    <?php else: ?>
        <p class="muted">Signalement réservé au service terrain.</p>
    <?php endif; ?>
</section>

<section class="card" style="margin-top:24px;">
    <div style="padding:22px 22px 10px;">
        <h2 style="margin:0;">Historique du service</h2>
        <p class="muted" style="margin:6px 0 0;">Le jour courant reste ouvert au chargement. Les journées passées sont repliées par défaut pour éviter l’encombrement.</p>
    </div>

    <?php if ($historyGroups === []): ?>
        <div style="padding:0 22px 22px;">
            <p class="muted">Aucun historique à afficher pour le moment.</p>
        </div>
    <?php else: ?>
        <?php foreach ($historyGroups as $group): ?>
            <?php $entries = $group['entries']; ?>
            <details <?= $group['is_current'] ? 'open' : '' ?> style="padding:0 22px 18px;">
                <summary style="cursor:pointer; list-style:none; padding:14px 0; border-top:1px solid var(--line); display:flex; justify-content:space-between; gap:12px; align-items:center;">
                    <span>
                        <strong><?= e($group['label']) ?></strong>
                        <span class="muted"> · <?= e((string) count($entries)) ?> élément(s)</span>
                    </span>
                    <span class="muted"><?= e(format_money($group['total_amount'], $restaurantCurrency)) ?></span>
                </summary>

                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Type</th>
                            <th>Référence</th>
                            <th>Statut</th>
                            <th>Date</th>
                            <th>Détails</th>
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
