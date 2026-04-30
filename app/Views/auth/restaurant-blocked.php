<section class="card auth" style="max-width:720px; margin:40px auto; padding:32px;">
    <div class="brand">
        <span class="pill <?= ($status_severity ?? 'warning') === 'danger' ? 'badge-bad' : 'badge-progress' ?>">
            <?= e(status_label($restaurant['status'] ?? null)) ?>
        </span>
        <h1>Accès limité au restaurant</h1>
        <p><?= e($status_message ?? 'L’accès au restaurant est temporairement limité.') ?></p>
    </div>

    <div class="<?= ($status_severity ?? 'warning') === 'danger' ? 'flash-bad' : 'flash-ok' ?>" style="margin-top:18px;">
        <?= e($status_message ?? 'L’accès à ce restaurant est limité.') ?>
    </div>

    <p><strong>Restaurant :</strong> <?= e($restaurant['public_name'] ?? $restaurant['name'] ?? 'Restaurant') ?></p>
    <p><strong>Lien d’accès du restaurant :</strong> <a href="<?= e(restaurant_generated_access_path($restaurant)) ?>" target="_blank" rel="noopener noreferrer"><?= e(restaurant_generated_access_url($restaurant)) ?></a></p>
    <p class="muted">Les opérations de stock, cuisine, ventes et rapports restent bloquées tant que le statut n’est pas réactivé par la plateforme.</p>

    <div class="nav" style="margin-top:20px; margin-bottom:0;">
        <?php if (can_access('tenant.dashboard.view')): ?><a href="/owner">Voir le tableau de bord</a><?php endif; ?>
        <a href="/logout">Se déconnecter</a>
    </div>
</section>
