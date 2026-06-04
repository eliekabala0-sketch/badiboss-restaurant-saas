<?php
declare(strict_types=1);

$report = $proof['report'] ?? [];
$cash = $proof['cash'] ?? [];
$user = $proof['user'] ?? [];
$restaurantCurrency = restaurant_currency($restaurant);
$restaurantName = (string) ($restaurant['public_name'] ?? $restaurant['name'] ?? 'Restaurant');
$restaurantLogo = restaurant_media_url_or_default($restaurant['logo_url'] ?? null, 'logo');
$isMonthly = ($proof['kind'] ?? '') === 'server_monthly';
$docNumber = ($isMonthly ? 'RM-' : 'RJ-') . (string) ($restaurant['id'] ?? '0') . '-' . (string) ($user['id'] ?? '0') . '-' . str_replace('-', '', (string) ($proof['anchor'] ?? ''));
$salesByCategory = $report['sales_by_category']['categories'] ?? [];
$salesByServer = $report['sales_detail_by_server']['servers'] ?? [];
$serverSales = $salesByServer[0] ?? [];
$itemsByProduct = $serverSales['products'] ?? [];
?>
<style>
@media print { .no-print { display:none !important; } body { background:#fff !important; color:#111 !important; } .proof-sheet { box-shadow:none !important; border-color:#111 !important; } }
.proof-sheet { max-width:980px; margin:0 auto; padding:28px; background:#fff; color:#111; border:3px solid #111; border-radius:8px; }
.proof-logo { width:110px; height:86px; object-fit:contain; border:1px solid #ddd; border-radius:6px; }
.proof-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin:18px 0; }
.proof-stat { border:1px solid #111; border-radius:6px; padding:12px; }
.proof-table { width:100%; border-collapse:collapse; margin-top:16px; }
.proof-table th { background:#050505; color:#fff; }
.proof-table th, .proof-table td { border:1px solid #111; padding:9px; }
</style>
<section class="topbar no-print">
    <div class="brand"><h1><?= e($isMonthly ? 'Rapport mensuel serveur' : 'Rapport journalier serveur') ?></h1><p>Preuve globale serveur en lecture seule.</p></div>
    <div class="toolbar-actions"><button onclick="window.print()">Imprimer</button><button class="button-muted" data-download-proof="<?= e($docNumber) ?>.html">Telecharger</button></div>
</section>
<section class="proof-sheet" data-proof-sheet>
    <div style="display:flex; gap:18px; align-items:center;">
        <img src="<?= e($restaurantLogo) ?>" alt="Logo" class="proof-logo" width="110" height="86">
        <div>
            <h1 style="margin:0;"><?= e($restaurantName) ?></h1>
            <strong><?= e($isMonthly ? 'Rapport mensuel serveur' : 'Rapport journalier serveur') ?> - <?= e($docNumber) ?></strong>
            <div><?= e((string) ($report['period_label'] ?? '')) ?></div>
        </div>
    </div>
    <p><strong>Serveur :</strong> <?= e(named_actor_label($user['full_name'] ?? null, 'cashier_server')) ?></p>
    <div class="proof-grid">
        <div class="proof-stat"><span>Total ventes</span><br><strong><?= e(format_money($cash['sales_activity_total'] ?? 0, $restaurantCurrency)) ?></strong></div>
        <div class="proof-stat"><span>Total verse</span><br><strong><?= e(format_money($cash['server_remittance_total'] ?? 0, $restaurantCurrency)) ?></strong></div>
        <div class="proof-stat"><span>Total valide caisse</span><br><strong><?= e(format_money($cash['cashier_received_sales'] ?? 0, $restaurantCurrency)) ?></strong></div>
        <div class="proof-stat"><span>Manquant / non verse</span><br><strong><?= e(format_money($cash['activity_gap_sales_vs_attributed_remittance'] ?? 0, $restaurantCurrency)) ?></strong></div>
    </div>
    <h2>Ventes par article</h2>
    <table class="proof-table"><thead><tr><th>Article</th><th>Quantite</th><th>Total</th></tr></thead><tbody>
    <?php foreach ($itemsByProduct as $row): ?>
        <tr><td><?= e((string) ($row['menu_item_name'] ?? $row['name'] ?? '-')) ?></td><td><?= e((string) ($row['quantity'] ?? $row['total_quantity'] ?? 0)) ?></td><td><?= e(format_money($row['total_amount'] ?? $row['total'] ?? 0, $restaurantCurrency)) ?></td></tr>
    <?php endforeach; ?>
    <?php if ($itemsByProduct === []): ?><tr><td colspan="3">Aucune ligne article sur cette periode.</td></tr><?php endif; ?>
    </tbody></table>
    <h2>Ventes par categorie</h2>
    <table class="proof-table"><thead><tr><th>Categorie</th><th>Total</th></tr></thead><tbody>
    <?php foreach ($salesByCategory as $row): ?>
        <tr><td><?= e((string) ($row['category_name'] ?? $row['name'] ?? 'Sans categorie')) ?></td><td><?= e(format_money($row['total_amount'] ?? $row['total'] ?? 0, $restaurantCurrency)) ?></td></tr>
    <?php endforeach; ?>
    <?php if ($salesByCategory === []): ?><tr><td colspan="2">Aucune categorie vendue sur cette periode.</td></tr><?php endif; ?>
    </tbody></table>
    <h2>Observations</h2>
    <p>Remises rejetees, incidents, discipline et paie restent lus depuis les modules existants. Ce document ne modifie aucune vente, aucune caisse et aucun stock.</p>
</section>
<script>
document.querySelectorAll('[data-download-proof]').forEach(function (button) { button.addEventListener('click', function () { var sheet = document.querySelector('[data-proof-sheet]'); var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['<!doctype html><meta charset="utf-8">' + sheet.outerHTML], {type:'text/html;charset=utf-8'})); a.download = button.getAttribute('data-download-proof') || 'preuve.html'; a.click(); setTimeout(function(){ URL.revokeObjectURL(a.href); }, 1000); }); });
</script>
