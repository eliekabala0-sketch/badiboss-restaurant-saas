<?php
declare(strict_types=1);

$moduleNavTitle = (string) ($module_nav_title ?? 'Navigation du module');
$moduleNavIntro = trim((string) ($module_nav_intro ?? ''));
$moduleNavItems = is_array($module_nav_items ?? null) ? $module_nav_items : [];

if ($moduleNavItems === []) {
    return;
}
?>
<section class="card no-print" style="padding:16px 18px; margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start; flex-wrap:wrap;">
        <div>
            <h2 style="margin:0 0 6px; font-size:1.02rem;"><?= e($moduleNavTitle) ?></h2>
            <?php if ($moduleNavIntro !== ''): ?>
                <p class="muted" style="margin:0;"><?= e($moduleNavIntro) ?></p>
            <?php endif; ?>
        </div>
    </div>
    <div class="nav" style="margin-top:14px; margin-bottom:0;">
        <?php foreach ($moduleNavItems as $item): ?>
            <?php if (!is_array($item) || trim((string) ($item['href'] ?? '')) === '' || trim((string) ($item['label'] ?? '')) === '') { continue; } ?>
            <a href="<?= e((string) $item['href']) ?>" class="<?= !empty($item['muted']) ? 'button-muted' : '' ?>"><?= e((string) $item['label']) ?></a>
        <?php endforeach; ?>
    </div>
</section>
