<?php
declare(strict_types=1);
/** @var list<array<string, mixed>>|null $manager_recent_decisions */
$rows = $manager_recent_decisions ?? [];
$user = current_user();
if ($rows === [] || !\App\Services\ManagerResolutionService::actorCanResolve(is_array($user) ? $user : null)) {
    return;
}
$restaurantCurrency = restaurant_currency((isset($restaurant) && is_array($restaurant)) ? $restaurant : null);
?>
<details class="card no-print" style="padding:14px 18px; margin-bottom:20px;">
    <summary style="cursor:pointer; font-weight:600;">Décisions responsables récentes (<?= count($rows) ?>)</summary>
    <p class="muted" style="margin:10px 0; font-size:0.9rem;">Synthèse des derniers arbitrages enregistrés sur ce restaurant.</p>
    <div class="table-wrap" style="overflow-x:auto;">
        <table style="width:100%; font-size:0.9rem; border-collapse:collapse;">
            <thead>
                <tr style="text-align:left; border-bottom:1px solid var(--line);">
                    <th style="padding:6px 8px;">Date</th>
                    <th style="padding:6px 8px;">Type</th>
                    <th style="padding:6px 8px;">Agent</th>
                    <th style="padding:6px 8px;">Décision</th>
                    <th style="padding:6px 8px;">Montant</th>
                    <th style="padding:6px 8px;">Réf.</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <?php
                $kind = (string) ($r['row_kind'] ?? '');
                $at = (string) ($r['decided_at'] ?? '');
                $ref = (int) ($r['ref_id'] ?? 0);
                $saleId = isset($r['sale_id']) ? (int) $r['sale_id'] : 0;
                $refLabel = $kind === 'commande' ? ('SR-' . $ref) : ('TRF-' . $ref . ($saleId > 0 ? (' · VTE ' . $saleId) : ''));
                ?>
                <tr style="border-bottom:1px solid rgba(212,175,55,0.12);">
                    <td style="padding:6px 8px;"><?= e(format_date_fr($at !== '' ? $at : null)) ?></td>
                    <td style="padding:6px 8px;"><?= e($kind === 'commande' ? 'Commande' : 'Caisse') ?></td>
                    <td style="padding:6px 8px;"><?= e((string) ($r['agent_label'] ?? '—')) ?></td>
                    <td style="padding:6px 8px;"><?= e(responsible_outcome_label((string) ($r['outcome_code'] ?? ''))) ?></td>
                    <td style="padding:6px 8px;"><?= e(format_money((float) ($r['amount_hint'] ?? 0), $restaurantCurrency)) ?></td>
                    <td style="padding:6px 8px;"><?= e($refLabel) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</details>
