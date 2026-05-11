<?php
declare(strict_types=1);
$hold = is_array($day_start_hold ?? null) ? $day_start_hold : [];
$items = is_array($hold['items'] ?? null) ? $hold['items'] : [];
$blocked = !empty($hold['blocked']);
$reasons = is_array($hold['reasons'] ?? null) ? $hold['reasons'] : [];
$user = $_SESSION['user'] ?? [];
$super = ($user['scope'] ?? '') === 'super_admin';
if (!$super && $items === [] && !$blocked && $reasons === []) {
    return;
}
$bannerTitle = 'Actions à régulariser avant de commencer aujourd’hui';
if (!$blocked && $items !== []) {
    $bannerTitle = 'Arriérés visibles (équipe) — pilotage';
}
?>
<section class="status-banner status-danger no-print" style="margin-bottom:20px;">
    <div style="flex:1;">
        <strong><?= e($bannerTitle) ?></strong>
        <?php if ($super): ?>
            <p class="muted" style="margin:8px 0 0;">Super administrateur : accès complet pour dépannage. Les autres profils doivent régulariser ci-dessous.</p>
        <?php endif; ?>
        <?php if ($blocked): ?>
            <p style="margin:10px 0 0;"><span class="pill badge-bad">Nouvelles actions bloquées jusqu’à régularisation</span></p>
        <?php endif; ?>
        <?php if ($reasons !== []): ?>
            <ul class="muted" style="margin:12px 0 0; padding-left:18px;">
                <?php foreach ($reasons as $rr): ?>
                    <li><?= e((string) $rr) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ($items !== []): ?>
            <div style="margin-top:14px; display:grid; gap:10px;">
                <?php foreach ($items as $it): ?>
                    <?php if (!is_array($it)) {
                        continue;
                    } ?>
                    <article class="card" style="padding:14px 16px; margin:0;">
                        <div style="display:flex; flex-wrap:wrap; justify-content:space-between; gap:10px; align-items:flex-start;">
                            <div>
                                <strong><?= e((string) ($it['type_label'] ?? 'Opération')) ?></strong>
                                <span class="muted"> · <?= e((string) ($it['reference'] ?? '')) ?></span>
                                <?php if (!empty($it['focus'])): ?>
                                    <span class="muted" style="display:block; margin-top:6px; font-size:0.85rem;">Cible : <?= e((string) $it['focus']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($it['manquant_a_charge'])): ?>
                                    <span class="pill badge-bad" style="margin-left:6px;">Manquant à votre charge</span>
                                <?php endif; ?>
                                <p class="muted" style="margin:8px 0 0; font-size:0.92rem;">
                                    <?= e((string) ($it['happened_at'] ?? '')) ?>
                                    <?php if (trim((string) ($it['agent_label'] ?? '')) !== '' && ($it['agent_label'] ?? '') !== '—'): ?>
                                        · <?= e((string) $it['agent_label']) ?>
                                    <?php endif; ?>
                                </p>
                                <p style="margin:6px 0 0;">
                                    <?= e((string) ($it['detail_label'] ?? '')) ?>
                                    <?php if (($it['amount_label'] ?? '') !== '' && ($it['amount_label'] ?? '') !== '—'): ?>
                                        · <strong><?= e((string) $it['amount_label']) ?></strong>
                                    <?php endif; ?>
                                </p>
                                <p class="muted" style="margin:6px 0 0;">Statut : <?= e((string) ($it['status_label'] ?? '—')) ?></p>
                                <p style="margin:8px 0 0;"><?= e((string) ($it['action_label'] ?? '')) ?></p>
                            </div>
                            <a class="button-muted" style="min-width:140px; text-align:center;" href="<?= e((string) ($it['href'] ?? '#')) ?>">Traiter maintenant</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if (!$super && $blocked && $items === []): ?>
            <p class="muted" style="margin-top:12px;">Si la situation persiste, contactez le super administrateur.</p>
        <?php endif; ?>
    </div>
</section>
