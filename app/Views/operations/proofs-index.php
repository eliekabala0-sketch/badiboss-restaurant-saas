<?php
declare(strict_types=1);

$restaurantCurrency = restaurant_currency($restaurant);
$idx = $proof_index ?? ['sales' => [], 'cash_transfers' => [], 'servers' => [], 'date' => ''];
$servers = $idx['servers'] ?? [];
$selectedServerId = (int) ($idx['server_id'] ?? 0);
$date = (string) ($idx['date'] ?? '');
?>
<section class="topbar">
    <div class="brand">
        <h1>Factures / preuves</h1>
        <p>Documents generes seulement au clic : factures commandes, recus caisse, rapports serveur jour et mois.</p>
    </div>
</section>
<?php if (!empty($flash_success)): ?><div class="flash-ok"><?= e($flash_success) ?></div><?php endif; ?>
<?php if (!empty($flash_error)): ?><div class="flash-bad"><?= e($flash_error) ?></div><?php endif; ?>

<section class="card no-print" style="padding:18px; margin-bottom:22px;">
    <form method="get" action="/preuves" class="split">
        <div><label>Date</label><input type="date" name="date" value="<?= e($date) ?>"></div>
        <div><label>Type document</label><select name="type">
            <option value="all" <?= ($idx['type'] ?? 'all') === 'all' ? 'selected' : '' ?>>Tous</option>
            <option value="sale" <?= ($idx['type'] ?? '') === 'sale' ? 'selected' : '' ?>>Facture commande client</option>
            <option value="cash_transfer" <?= ($idx['type'] ?? '') === 'cash_transfer' ? 'selected' : '' ?>>Recu versement caisse</option>
        </select></div>
        <div><label>Serveur</label><select name="server_id">
            <option value="0">Tous les serveurs</option>
            <?php foreach ($servers as $server): ?>
                <option value="<?= e((string) $server['id']) ?>" <?= (int) $server['id'] === $selectedServerId ? 'selected' : '' ?>><?= e(named_actor_label($server['full_name'] ?? null, $server['role_code'] ?? null)) ?></option>
            <?php endforeach; ?>
        </select></div>
        <div style="align-self:end;"><button type="submit">Filtrer</button></div>
    </form>
    <div class="toolbar-actions" style="margin-top:12px;">
        <?php if ($selectedServerId > 0): ?>
            <a class="button-muted" target="_blank" rel="noopener noreferrer" href="/preuves/serveur/journalier?server_id=<?= e((string) $selectedServerId) ?>&date=<?= e(rawurlencode($date)) ?>">Voir rapport serveur jour</a>
            <a class="button-muted" target="_blank" rel="noopener noreferrer" href="/preuves/serveur/mensuel?server_id=<?= e((string) $selectedServerId) ?>&month=<?= e(rawurlencode(substr($date, 0, 7))) ?>">Voir rapport serveur mois</a>
        <?php else: ?>
            <span class="muted">Choisissez un serveur pour ouvrir les rapports journalier ou mensuel.</span>
        <?php endif; ?>
    </div>
</section>

<section class="card" style="padding:22px; margin-bottom:22px;">
    <h2 style="margin-top:0;">Factures commandes client</h2>
    <div class="table-wrap"><table><thead><tr><th>Vente</th><th>Serveur</th><th>Total</th><th>Date</th><th>Actions</th></tr></thead><tbody>
    <?php foreach (($idx['sales'] ?? []) as $sale): ?>
        <tr>
            <td>#<?= e((string) ($sale['id'] ?? '')) ?></td>
            <td><?= e(named_actor_label($sale['server_name'] ?? null, 'cashier_server')) ?></td>
            <td><?= e(format_money($sale['total_amount'] ?? 0, $restaurantCurrency)) ?></td>
            <td><?= e(format_date_fr($sale['sale_activity_at'] ?? $sale['created_at'] ?? null)) ?></td>
            <td><a class="button-muted" target="_blank" rel="noopener noreferrer" href="/preuves/commandes/<?= e((string) ($sale['id'] ?? 0)) ?>">Voir / imprimer</a></td>
        </tr>
    <?php endforeach; ?>
    <?php if (($idx['sales'] ?? []) === []): ?><tr><td colspan="5" class="muted">Aucune facture commande pour ce filtre.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>

<section class="card" style="padding:22px;">
    <h2 style="margin-top:0;">Recus versement caisse</h2>
    <div class="table-wrap"><table><thead><tr><th>Versement</th><th>Serveur</th><th>Caisse</th><th>Montant</th><th>Statut</th><th>Actions</th></tr></thead><tbody>
    <?php foreach (($idx['cash_transfers'] ?? []) as $transfer): ?>
        <tr>
            <td>#<?= e((string) ($transfer['id'] ?? '')) ?></td>
            <td><?= e(named_actor_label($transfer['server_name'] ?? null, 'cashier_server')) ?></td>
            <td><?= e(named_actor_label($transfer['cashier_name'] ?? null, 'cashier_accountant')) ?></td>
            <td><?= e(format_money($transfer['amount'] ?? 0, $restaurantCurrency)) ?></td>
            <td><?= e(cash_transfer_status_label($transfer['status'] ?? null)) ?></td>
            <td><a class="button-muted" target="_blank" rel="noopener noreferrer" href="/preuves/versements/<?= e((string) ($transfer['id'] ?? 0)) ?>">Voir / imprimer</a></td>
        </tr>
    <?php endforeach; ?>
    <?php if (($idx['cash_transfers'] ?? []) === []): ?><tr><td colspan="6" class="muted">Aucun recu caisse pour ce filtre.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>
