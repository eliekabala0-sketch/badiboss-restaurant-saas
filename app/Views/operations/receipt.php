<?php
declare(strict_types=1);

$sale = $receipt['sale'] ?? [];
$items = $receipt['items'] ?? [];
$transfer = $receipt['cash_transfer'] ?? null;
$restaurantCurrency = restaurant_currency($restaurant);
$restaurantName = (string) ($restaurant['public_name'] ?? $restaurant['name'] ?? 'Restaurant');
$restaurantLogo = restaurant_media_url_or_default($restaurant['logo_url'] ?? null, 'logo');
$invoiceNumber = 'FC-' . (string) ($restaurant['id'] ?? '0') . '-' . str_pad((string) ($sale['id'] ?? '0'), 6, '0', STR_PAD_LEFT);
$activityAt = $sale['sale_activity_at'] ?? $sale['validated_at'] ?? $sale['created_at'] ?? null;
?>
<style>
@media print { .no-print { display:none !important; } body { background:#fff !important; color:#111 !important; } .proof-sheet { box-shadow:none !important; border-color:#111 !important; } }
.proof-sheet { max-width:920px; margin:0 auto; padding:28px; background:#fff; color:#111; border:3px solid #111; border-radius:8px; box-shadow:0 20px 60px rgba(0,0,0,.28); }
.proof-head { display:grid; grid-template-columns:120px 1fr; gap:18px; align-items:center; text-align:center; }
.proof-logo { width:120px; height:92px; object-fit:contain; border:1px solid #ddd; border-radius:6px; background:#fafafa; }
.proof-title { margin:8px 0 0; padding:12px; background:#050505; color:#fff; font-size:1.55rem; font-weight:900; letter-spacing:.04em; text-align:center; }
.proof-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin:18px 0; }
.proof-box { border:1px solid #111; border-radius:6px; padding:14px; min-height:92px; }
.proof-table { width:100%; border-collapse:collapse; margin-top:16px; }
.proof-table th { background:#050505; color:#fff; }
.proof-table th, .proof-table td { border:1px solid #111; padding:10px; }
.proof-total { display:flex; justify-content:flex-end; margin-top:14px; font-size:1.15rem; }
.proof-total strong { border:2px solid #111; padding:10px 16px; min-width:260px; text-align:right; }
.proof-sign { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-top:24px; }
.proof-sign div { border:1px solid #111; border-radius:6px; padding:16px; min-height:82px; }
</style>

<section class="topbar no-print">
    <div class="brand">
        <h1>Facture commande client</h1>
        <p>Preuve generee au clic, sans modifier la vente, la caisse ou le stock.</p>
    </div>
    <div class="toolbar-actions">
        <button type="button" onclick="window.print()">Imprimer facture</button>
        <button type="button" class="button-muted" data-download-proof="<?= e($invoiceNumber) ?>.html">Telecharger facture</button>
    </div>
</section>

<section class="proof-sheet" data-proof-sheet>
    <div class="proof-head">
        <img src="<?= e($restaurantLogo) ?>" alt="Logo <?= e($restaurantName) ?>" class="proof-logo" width="120" height="92" decoding="async">
        <div>
            <div style="font-size:1.1rem; letter-spacing:.28em;">RESTAURANT</div>
            <h1 style="font-size:3rem; margin:4px 0; letter-spacing:.08em;"><?= e($restaurantName) ?></h1>
            <div><?= e((string) ($restaurant['address_line'] ?? $restaurant['city'] ?? '')) ?></div>
        </div>
    </div>
    <div class="proof-title">FACTURE - SERVIR CLIENT</div>

    <div class="proof-grid">
        <div class="proof-box">
            <p><strong>No facture :</strong> <?= e($invoiceNumber) ?></p>
            <p><strong>Date :</strong> <?= e($activityAt ? substr((string) $activityAt, 0, 10) : '-') ?></p>
            <p><strong>Heure :</strong> <?= e($activityAt ? substr((string) $activityAt, 11, 5) : '-') ?></p>
        </div>
        <div class="proof-box">
            <p><strong>Nom du serveur :</strong> <?= e(named_actor_label($sale['server_name'] ?? null, 'cashier_server')) ?></p>
            <p><strong>No table :</strong> <?= e((string) ($sale['table_number'] ?? '-')) ?></p>
            <p><strong>Nombre de couverts :</strong> <?= e((string) ($sale['guest_count'] ?? '-')) ?></p>
        </div>
    </div>

    <table class="proof-table">
        <thead><tr><th>No</th><th>Plat / article</th><th>Quantite</th><th>Prix unit.</th><th>Total</th></tr></thead>
        <tbody>
        <?php foreach ($items as $index => $item): ?>
            <tr>
                <td><?= e((string) ($index + 1)) ?></td>
                <td><?= e((string) ($item['menu_item_name'] ?? '-')) ?></td>
                <td><?= e((string) ($item['quantity'] ?? 0)) ?></td>
                <td><?= e(format_money($item['unit_price'] ?? 0, $restaurantCurrency)) ?></td>
                <td><?= e(format_money(((float) ($item['quantity'] ?? 0)) * ((float) ($item['unit_price'] ?? 0)), $restaurantCurrency)) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php for ($i = count($items); $i < 10; $i++): ?>
            <tr><td><?= e((string) ($i + 1)) ?></td><td>&nbsp;</td><td></td><td></td><td></td></tr>
        <?php endfor; ?>
        </tbody>
    </table>

    <div class="proof-total"><strong>Total general : <?= e(format_money($sale['total_amount'] ?? 0, $restaurantCurrency)) ?></strong></div>
    <p style="border:1px solid #111; border-radius:6px; padding:12px; margin-top:16px;">
        <strong>Mode de paiement :</strong>
        <?= e((string) ($sale['payment_method'] ?? ($transfer !== null ? cash_transfer_status_label($transfer['status'] ?? null) : 'Non precise'))) ?>
    </p>
    <div class="proof-sign">
        <div><strong>Signature serveur :</strong><br><br><?= e(named_actor_label($sale['server_name'] ?? null, 'cashier_server')) ?></div>
        <div><strong>Signature caissier :</strong><br><br><?= e($transfer !== null ? named_actor_label($transfer['cashier_name'] ?? $transfer['received_by_name'] ?? null, 'cashier_accountant') : '-') ?></div>
    </div>
    <p style="text-align:center; margin:24px 0 0; font-size:1.2rem;">Merci pour votre confiance</p>
</section>

<script>
document.querySelectorAll('[data-download-proof]').forEach(function (button) {
    button.addEventListener('click', function () {
        var sheet = document.querySelector('[data-proof-sheet]');
        if (!sheet) { return; }
        var html = '<!doctype html><html><head><meta charset="utf-8"><title>' + document.title + '</title></head><body>' + sheet.outerHTML + '</body></html>';
        var a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([html], { type: 'text/html;charset=utf-8' }));
        a.download = button.getAttribute('data-download-proof') || 'preuve.html';
        a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
    });
});
</script>
