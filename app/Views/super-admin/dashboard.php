<section class="topbar">
    <div class="brand">
        <h1>Super administration</h1>
        <p>Vue d’ensemble courte, claire et orientée action pour gérer la plateforme sans écran technique surchargé.</p>
    </div>
</section>

<?php if (!empty($flash_success ?? null)): ?><div class="flash-ok"><?= e($flash_success) ?></div><?php endif; ?>
<?php if (!empty($flash_error ?? null)): ?><div class="flash-bad"><?= e($flash_error) ?></div><?php endif; ?>

<section class="grid stats">
    <article class="card stat"><span>Restaurants</span><strong><?= e((string) $stats['restaurants_total']) ?></strong></article>
    <article class="card stat"><span>Restaurants actifs</span><strong><?= e((string) $stats['restaurants_active']) ?></strong></article>
    <article class="card stat"><span>Utilisateurs</span><strong><?= e((string) $stats['users_total']) ?></strong></article>
    <article class="card stat"><span>Entrées d’audit</span><strong><?= e((string) $stats['audit_entries']) ?></strong></article>
</section>

<div class="section-stack">
    <section class="card" style="padding:22px;">
        <h2 style="margin-top:0;">Accès rapides</h2>
        <p class="muted">Les actions les plus utiles restent visibles dès l’ouverture.</p>
        <div class="nav" style="margin-bottom:0;">
            <a href="/super-admin/restaurants">Restaurants</a>
            <a href="/super-admin/users">Utilisateurs</a>
            <a href="/super-admin/settings">Paramètres</a>
            <a href="/super-admin/menu">Menu</a>
            <a href="/super-admin/audit">Journal d’audit</a>
        </div>
    </section>

    <details class="card fold-card" open>
        <summary>
            <div>
                <strong>Restaurants clients</strong>
                <div class="muted">Liste condensée avec lien généré, statut réel et branding.</div>
            </div>
            <span class="pill badge-neutral"><?= e((string) count($restaurants)) ?> restaurant(s)</span>
        </summary>
        <div class="fold-body">
            <?php if ($restaurants === []): ?>
                <div class="compact-empty">Aucun restaurant à afficher.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Restaurant</th>
                            <th>Branding</th>
                            <th>Lien d’accès</th>
                            <th>Statut</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($restaurants as $restaurant): ?>
                            <tr>
                                <td><?= e((string) $restaurant['id']) ?></td>
                                <td>
                                    <strong><?= e($restaurant['name']) ?></strong><br>
                                    <span class="muted"><?= e($restaurant['slug']) ?></span>
                                </td>
                                <td>
                                    <span class="pill badge-neutral"><?= e($restaurant['public_name'] ?? '-') ?></span><br>
                                    <span class="muted"><?= e($restaurant['web_subdomain'] ?? '-') ?></span>
                                </td>
                                <td><a href="<?= e(restaurant_generated_access_path($restaurant)) ?>" target="_blank" rel="noopener noreferrer"><?= e(restaurant_generated_access_url($restaurant)) ?></a></td>
                                <td><span class="pill <?= ($restaurant['status'] ?? '') === 'active' ? 'badge-closed' : (($restaurant['status'] ?? '') === 'suspended' ? 'badge-progress' : 'badge-bad') ?>"><?= e(status_label($restaurant['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </details>
</div>
