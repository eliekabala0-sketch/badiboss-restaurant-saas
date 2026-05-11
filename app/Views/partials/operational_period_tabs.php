<?php
declare(strict_types=1);
$preset = (string) ($dash_preset ?? 'today');
$d = (string) ($dash_date ?? ($today_ymd_restaurant ?? ''));
$todayY = (string) ($today_ymd_restaurant ?? $d);
$extra = (string) ($dash_tab_extra_qs ?? '');
$q = static function (string $p, string $date) use ($extra): string {
    return '?dash_preset=' . rawurlencode($p) . '&dash_date=' . rawurlencode($date) . $extra;
};
?>
<nav class="compact-card no-print" style="padding:12px 14px; margin-bottom:18px; display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
    <span class="muted" style="flex:100%; font-size:0.85rem; text-transform:uppercase; letter-spacing:0.12em;">Période affichée</span>
    <a class="button-muted <?= $preset === 'today' ? 'badge-gold' : '' ?>" href="<?= e($q('today', $todayY)) ?>">Aujourd’hui</a>
    <a class="button-muted <?= $preset === 'yesterday' ? 'badge-gold' : '' ?>" href="<?= e($q('yesterday', $todayY)) ?>">Hier</a>
    <a class="button-muted <?= $preset === 'week' ? 'badge-gold' : '' ?>" href="<?= e($q('week', $d !== '' ? $d : $todayY)) ?>">Semaine</a>
    <a class="button-muted <?= $preset === 'month' ? 'badge-gold' : '' ?>" href="<?= e($q('month', $d !== '' ? $d : $todayY)) ?>">Mois</a>
    <a class="button-muted <?= $preset === 'prev_month' ? 'badge-gold' : '' ?>" href="<?= e($q('prev_month', $todayY)) ?>">Mois précédent</a>
    <form method="get" action="" style="display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin:0;">
        <input type="hidden" name="dash_preset" value="date">
        <label class="muted" for="dash_date_pick" style="margin:0;">Date</label>
        <input id="dash_date_pick" type="date" name="dash_date" value="<?= e($d) ?>" style="max-width:160px;">
        <?= $extra !== '' ? '' : '' ?>
        <button type="submit" class="button-muted">Afficher</button>
    </form>
</nav>
