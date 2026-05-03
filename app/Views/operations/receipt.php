<?php
declare(strict_types=1);

$sale = $receipt['sale'] ?? [];
$items = $receipt['items'] ?? [];
$restaurantCurrency = restaurant_currency($restaurant);
$invoiceNumber = 'FAC-' . (string) ($restaurant['id'] ?? '0') . '-' . str_pad((string) ($sale['id'] ?? '0'), 6, '0', STR_PAD_LEFT);
?>
<section class="topbar">
    <div class="brand">
        <h1>Facture / recu</h1>
        <p>Facture imprimable par restaurant avec le snapshot historique des montants.</p>
    </div>
</section>

<section class="card" style="padding:24px;">
    <div class="topbar" style="margin-bottom:16px;">
        <div>
            <strong><?= e($invoiceNumber) ?></strong>
            <div class="muted"><?= e($restaurant['public_name'] ?? $restaurant['name'] ?? 'Restaurant') ?></div>
        </div>
        <div class="toolbar-actions no-print">
            <button type="button" onclick="window.print()">Imprimer</button>
            <button type="button" class="button-muted" onclick="window.print()">Export PDF navigateur</button>
        </div>
    </div>
    <p><strong>Serveur :</strong> <?= e(named_actor_label($sale['server_name'] ?? null, 'cashier_server')) ?></p>
    <p><strong>Date :</strong> <?= e(format_date_fr($sale['validated_at'] ?? $sale['created_at'] ?? null)) ?></p>
    <p><strong>Statut paiement :</strong> <?= e(validation_status_label($sale['status'] ?? null)) ?></p>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Article</th><th>Quantite</th><th>Prix</th><th>Total</th></tr></thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= e((string) ($item['menu_item_name'] ?? '-')) ?></td>
                    <td><?= e((string) ($item['quantity'] ?? 0)) ?></td>
                    <td><?= e(format_money($item['unit_price'] ?? 0, $restaurantCurrency)) ?></td>
                    <td><?= e(format_money(($item['quantity'] ?? 0) * ($item['unit_price'] ?? 0), $restaurantCurrency)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p style="margin-top:16px;"><strong>Total :</strong> <?= e(format_money($sale['total_amount'] ?? 0, $restaurantCurrency)) ?></p>
</section>
