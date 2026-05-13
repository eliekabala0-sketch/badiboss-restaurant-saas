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

    return match ($s) {
        'non_evalue' => 'Non évalué',
        'vert' => 'Excellent',
        'jaune' => 'Bon',
        'orange' => 'Moyen',
        'rouge' => 'Problématique',
        'rouge_critique' => 'Très problématique',
        default => ucfirst($s),
    };
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
        <?php
        $zMo = (string) ($g['zone'] ?? 'non_evalue');
        $zMoLbl = $formatZone($zMo);
        $zMoPill = match ($zMo) {
            'vert' => 'badge-closed',
            'jaune' => 'badge-ready',
            'orange' => 'badge-progress',
            'rouge', 'rouge_critique' => 'badge-bad',
            default => 'badge-neutral',
        };
        ?>
        <article class="card stat"><span>Mention mois</span><strong><span class="pill <?= e($zMoPill) ?>"><?= e($zMoLbl) ?></span></strong></article>
        <article class="card stat"><span>Score jour (réf.)</span><strong><?= $g['daily'] === null ? 'Non évalué' : e((string) $g['daily']) . ' %' ?></strong></article>
        <article class="card stat"><span>Moy. 7 j. (réf.)</span><strong><?= $g['weekly_avg'] === null ? 'Non évalué' : e((string) $g['weekly_avg']) . ' %' ?></strong></article>
        <article class="card stat"><span>Moy. mois (réf. paie)</span><strong><?= $g['monthly_avg'] === null ? 'Non évalué' : e((string) $g['monthly_avg']) . ' %' ?></strong></article>
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
            $apZoneLabel = $formatZone($apZone);
            $sb = is_array($ap['score_breakdown'] ?? null) ? $ap['score_breakdown'] : [];
            $sbEvaluated = ($sb['evaluated'] ?? false) === true;
            $monthStats = is_array($sb['month_stats'] ?? null) ? $sb['month_stats'] : [];
            ?>
            <p style="margin:10px 0 0;"><strong>Score : <?= $apScore === null ? 'Non évalué' : e((string) $apScore) ?> <?= $apScore === null ? '' : '%' ?></strong> · zone <?= e($apZoneLabel) ?></p>
            <?php if ($sbEvaluated && array_key_exists('activite_pct_vs_role', $sb) && $sb['activite_pct_vs_role'] !== null): ?>
                <p class="muted" style="margin:8px 0 0;">
                    <strong>Vs moyenne du rôle (jour)</strong> : <?= e((string) (int) $sb['activite_pct_vs_role']) ?> %
                    <?php if (!empty($sb['role_activity_mean'])): ?>
                        · moyenne équipe <?= e((string) (float) $sb['role_activity_mean']) ?> actes
                    <?php endif; ?>
                </p>
            <?php endif; ?>
            <?php if ($monthStats !== []): ?>
                <p class="muted" style="margin:10px 0 0;">
                    <strong>Tableau de mois (provisoire)</strong> :
                    jours notés <?= (int) ($monthStats['days_scored'] ?? 0) ?>,
                    avec activité mesurée <?= (int) ($monthStats['days_with_activity'] ?? 0) ?>,
                    sans activité mesurée <?= (int) ($monthStats['days_without_measured_activity'] ?? 0) ?>,
                    repos neutre <?= (int) ($monthStats['days_rest_neutral'] ?? 0) ?>,
                    exon. <?= (int) ($monthStats['days_exempt_neutral'] ?? 0) ?>,
                    abs. just. / maladie <?= (int) ($monthStats['days_soft_absence'] ?? 0) ?>,
                    absence / inactivité <?= (int) ($monthStats['days_unjustified_absence'] ?? 0) ?>.
                    <strong>Écart à 100 % (Σ)</strong> : −<?= e((string) (int) ($monthStats['penalty_points_off_base'] ?? 0)) ?> pts
                </p>
            <?php endif; ?>
            <?php if ($sb !== []): ?>
                <?php if ($sbEvaluated): ?>
                    <p class="muted" style="margin:8px 0 0;">
                        <strong>Actions prises en compte :</strong> <?= e((string) (int) ($sb['action_count'] ?? 0)) ?>
                        · <strong>Base :</strong> <?= e((string) (int) ($sb['base_score'] ?? 100)) ?>
                        <?php if (array_key_exists('ledger_delta', $sb)): ?>
                            · <strong>Journal (Σ pts) :</strong> <?= e((string) (int) ($sb['ledger_delta'] ?? 0)) ?>
                        <?php endif; ?>
                        <?php
                        $extraSum = 0;
                        if (!empty($sb['extra_penalties']) && is_array($sb['extra_penalties'])) {
                            foreach ($sb['extra_penalties'] as $xp) {
                                if (is_array($xp)) {
                                    $extraSum += (int) ($xp['points'] ?? 0);
                                }
                            }
                        }
                        ?>
                        · <strong>Pénalités complémentaires (Σ) :</strong> <?= e((string) $extraSum) ?>
                    </p>
                    <?php if (!empty($sb['activity_breakdown']) && is_array($sb['activity_breakdown'])): ?>
                        <p class="muted" style="margin:8px 0 0;"><strong>Base de calcul (activité)</strong></p>
                        <ul class="muted" style="margin:4px 0 0; padding-left:18px;">
                            <?php foreach ($sb['activity_breakdown'] as $row): ?>
                                <?php if (!is_array($row)) {
                                    continue;
                                } ?>
                                <li><?= e((string) ($row['label'] ?? '')) ?> · <?= e((string) (int) ($row['count'] ?? 0)) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php elseif (isset($sb['evaluated_days'])): ?>
                    <p class="muted" style="margin:8px 0 0;">
                        <strong>Jours évalués :</strong> <?= e((string) (int) ($sb['evaluated_days'] ?? 0)) ?>
                        <?php if (array_key_exists('window_days', $sb)): ?>
                            · <strong>Jours applicables (fenêtre) :</strong> <?= e((string) (int) ($sb['window_days'] ?? 0)) ?>
                        <?php elseif (array_key_exists('calendar_days', $sb)): ?>
                            · <strong>Jours calendaires (mois) :</strong> <?= e((string) (int) ($sb['calendar_days'] ?? 0)) ?>
                        <?php endif; ?>
                        · <strong>Actions cumulées :</strong> <?= e((string) (int) ($sb['actions_total'] ?? 0)) ?>
                    </p>
                    <p class="muted" style="margin:6px 0 0;">Moyenne sur les jours pris en compte depuis l’entrée en service (activité, repos neutre, absences pénalisées).</p>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (!empty($ap['note'])): ?>
                <p class="muted" style="margin:8px 0 0;"><?= e((string) $ap['note']) ?></p>
            <?php endif; ?>
            <?php if (!empty($ap['jours_moyennes'])): ?>
                <p class="muted" style="margin:6px 0 0;">Jours pris en compte pour la moyenne : <?= e((string) (int) $ap['jours_moyennes']) ?></p>
            <?php endif; ?>
            <?php if (!empty($ap['points_detail']) && is_array($ap['points_detail'])): ?>
                <p class="muted" style="margin:10px 0 4px;"><strong>Pénalités (journal + métier)</strong></p>
                <ul class="muted" style="margin:0; padding-left:18px;">
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
