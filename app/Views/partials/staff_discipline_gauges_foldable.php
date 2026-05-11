<?php
declare(strict_types=1);
$g = $self_staff_gauges ?? null;
$auditLines = is_array($staff_audit_highlights ?? null) ? $staff_audit_highlights : [];
if (!is_array($g) || $g === []) {
    return;
}
$panelTitle = trim((string) ($staff_gauges_panel_title ?? ''));
if ($panelTitle === '') {
    $panelTitle = 'Vos jauges discipline';
}
$formatGaugeVal = static function (mixed $v): string {
    if ($v === null) {
        return 'Non évalué';
    }
    if (is_float($v)) {
        return (string) $v;
    }
    if (is_int($v)) {
        return (string) $v;
    }

    return (string) $v;
};
$formatZone = static function (mixed $z): string {
    $s = (string) $z;

    return $s === 'non_evalue' ? 'Non évalué' : ucfirst($s);
};
$ap = is_array($g['active_period'] ?? null) ? $g['active_period'] : [];
$periodHint = (string) ($ap['titre'] ?? '');
if ($periodHint === '') {
    $periodHint = 'selon l’onglet période ci-dessus';
}
?>
<details class="compact-card no-print" style="margin-bottom:20px;" data-autoclose-details>
    <summary><strong><?= e($panelTitle) ?></strong> · <?= e($periodHint) ?></summary>
    <div class="grid stats" style="margin-top:14px;">
        <article class="card stat"><span>Jour (réf.)</span><strong><?= e($formatGaugeVal($g['daily'] ?? null)) ?></strong></article>
        <article class="card stat"><span>Moy. 7 j.</span><strong><?= e($formatGaugeVal($g['weekly_avg'] ?? null)) ?></strong></article>
        <article class="card stat"><span>Mois (cumul)</span><strong><?= e($formatGaugeVal($g['monthly_avg'] ?? null)) ?></strong></article>
        <article class="card stat"><span>Zone mois</span><strong><?= e($formatZone($g['zone'] ?? '')) ?></strong></article>
    </div>
    <?php if ($ap !== []): ?>
        <article class="card" style="padding:14px 16px; margin-top:14px;">
            <h3 style="margin:0 0 6px;"><?= e((string) ($ap['titre'] ?? 'Période')) ?></h3>
            <?php if (!empty($ap['jour'])): ?>
                <p class="muted" style="margin:0;">Jour : <?= e((string) $ap['jour']) ?></p>
            <?php endif; ?>
            <?php
            $apScore = $ap['score'] ?? null;
            $apZone = (string) ($ap['zone'] ?? '');
            $apZoneLabel = $apZone === 'non_evalue' ? 'Non évalué' : ucfirst($apZone);
            ?>
            <p style="margin:10px 0 0;"><strong>Score : <?= $apScore === null ? 'Non évalué' : e((string) $apScore) ?></strong> · zone <?= e($apZoneLabel) ?></p>
            <?php if (!empty($ap['note'])): ?>
                <p class="muted" style="margin:8px 0 0;"><?= e((string) $ap['note']) ?></p>
            <?php endif; ?>
            <?php if (!empty($ap['jours_moyennes'])): ?>
                <p class="muted" style="margin:6px 0 0;">Jours pris en compte : <?= e((string) (int) $ap['jours_moyennes']) ?></p>
            <?php endif; ?>
            <?php if (!empty($ap['points_detail']) && is_array($ap['points_detail'])): ?>
                <ul class="muted" style="margin:12px 0 0; padding-left:18px;">
                    <?php foreach ($ap['points_detail'] as $row): ?>
                        <?php if (!is_array($row)) {
                            continue;
                        } ?>
                        <li><?= e((string) ($row['delta_points'] ?? '')) ?> pts — <?= e((string) ($row['label'] ?? '')) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>
    <?php endif; ?>
    <?php if (!empty($g['ledger_preview']) && is_array($g['ledger_preview'])): ?>
    <ul class="muted" style="margin:14px 0 0; padding-left:18px;">
        <?php foreach ($g['ledger_preview'] as $le): ?>
            <li><?= e((string) ($le['day_ymd'] ?? '')) ?> · <?= e((string) ($le['delta_points'] ?? '')) ?> pts — <?= e((string) ($le['label'] ?? '')) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <div style="margin-top:16px;">
        <p class="muted" style="margin:0 0 8px;"><strong>Activité tracée (même période)</strong></p>
        <?php if ($auditLines !== []): ?>
            <ul class="muted" style="margin:0; padding-left:18px; line-height:1.65;">
                <?php foreach ($auditLines as $al): ?>
                    <?php if (!is_array($al)) {
                        continue;
                    } ?>
                    <?php $ac = (int) ($al['count'] ?? 0); ?>
                    <li><?= e((string) ($al['label'] ?? '')) ?> · <?= e((string) $ac) ?> <?= $ac > 1 ? 'actions' : 'action' ?></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="muted" style="margin:0;">Aucune ligne de traçabilité identifiée pour votre rôle sur cette période (actions hors tableau ou période vide).</p>
        <?php endif; ?>
    </div>
</details>
