<?php
declare(strict_types=1);

$transfer = $proof['transfer'] ?? [];
$restaurantCurrency = restaurant_currency($restaurant);
$restaurantName = (string) ($restaurant['public_name'] ?? $restaurant['name'] ?? 'Restaurant');
$restaurantLogo = restaurant_media_url_or_default($restaurant['logo_url'] ?? null, 'logo');
$proofNumber = 'RC-' . (string) ($restaurant['id'] ?? '0') . '-' . str_pad((string) ($transfer['id'] ?? '0'), 6, '0', STR_PAD_LEFT);
$amount = (float) ($transfer['amount'] ?? 0);
$received = (float) ($transfer['amount_received'] ?? $amount);
$missing = max(0.0, $amount - $received);
$at = $transfer['received_at'] ?? $transfer['requested_at'] ?? $transfer['created_at'] ?? null;
?>
<style>
@media print { .no-print { display:none !important; } body { background:#fff !important; color:#111 !important; } .proof-sheet { box-shadow:none !important; border-color:#111 !important; } }
.proof-sheet { max-width:860px; margin:0 auto; padding:28px; background:#fff; color:#111; border:3px solid #111; border-radius:8px; }
.proof-logo { width:110px; height:86px; object-fit:contain; border:1px solid #ddd; border-radius:6px; }
.paid-stamp { font-size:3rem; font-weight:900; border:5px solid #111; display:inline-block; padding:10px 26px; transform:rotate(-2deg); margin:14px 0; }
.proof-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.proof-box { border:1px solid #111; border-radius:6px; padding:14px; }
</style>
<section class="topbar no-print">
    <div class="brand"><h1>Recu versement caisse</h1><p>Preuve caisse generee au clic.</p></div>
    <div class="toolbar-actions"><button onclick="window.print()">Imprimer</button><button class="button-muted" data-download-proof="<?= e($proofNumber) ?>.html">Telecharger</button></div>
</section>
<section class="proof-sheet" data-proof-sheet>
    <div style="display:flex; gap:18px; align-items:center;">
        <img src="<?= e($restaurantLogo) ?>" alt="Logo" class="proof-logo" width="110" height="86">
        <div><h1 style="margin:0;"><?= e($restaurantName) ?></h1><strong><?= e($proofNumber) ?></strong></div>
    </div>
    <div class="paid-stamp"><?= in_array((string) ($transfer['status'] ?? ''), ['RECU_CAISSE', 'ECART_SIGNALE'], true) ? 'PAYE' : 'VERSEMENT VALIDE' ?></div>
    <div class="proof-grid">
        <div class="proof-box">
            <p><strong>Serveur :</strong> <?= e(named_actor_label($transfer['server_name'] ?? null, 'cashier_server')) ?></p>
            <p><strong>Date :</strong> <?= e($at ? substr((string) $at, 0, 10) : '-') ?></p>
            <p><strong>Heure :</strong> <?= e($at ? substr((string) $at, 11, 5) : '-') ?></p>
            <p><strong>Vente :</strong> #<?= e((string) ($transfer['sale_id'] ?? '-')) ?></p>
        </div>
        <div class="proof-box">
            <p><strong>Montant vendu :</strong> <?= e(format_money($transfer['sale_total_amount'] ?? $amount, $restaurantCurrency)) ?></p>
            <p><strong>Montant verse :</strong> <?= e(format_money($amount, $restaurantCurrency)) ?></p>
            <p><strong>Montant valide caisse :</strong> <?= e(format_money($received, $restaurantCurrency)) ?></p>
            <p><strong>Montant non verse / manquant :</strong> <?= e(format_money($missing, $restaurantCurrency)) ?></p>
        </div>
    </div>
    <div class="proof-grid" style="margin-top:18px;">
        <div class="proof-box"><strong>Signature / nom serveur</strong><br><br><?= e(named_actor_label($transfer['server_name'] ?? null, 'cashier_server')) ?></div>
        <div class="proof-box"><strong>Signature / nom caissier</strong><br><br><?= e(named_actor_label($transfer['received_by_name'] ?? $transfer['cashier_name'] ?? null, 'cashier_accountant')) ?></div>
    </div>
</section>
<script>
document.querySelectorAll('[data-download-proof]').forEach(function (button) { button.addEventListener('click', function () { var sheet = document.querySelector('[data-proof-sheet]'); var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['<!doctype html><meta charset="utf-8">' + sheet.outerHTML], {type:'text/html;charset=utf-8'})); a.download = button.getAttribute('data-download-proof') || 'preuve.html'; a.click(); setTimeout(function(){ URL.revokeObjectURL(a.href); }, 1000); }); });
</script>
