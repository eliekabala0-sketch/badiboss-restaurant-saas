<?php
$portalLogo = restaurant_media_url_or_default($tenant['logo_url'] ?? null, 'logo');
$portalCover = restaurant_media_url_or_default($tenant['cover_image_url'] ?? null, 'photo');
?>
<section class="card brand-visual" style="margin-bottom:24px; background-image:url('<?= e($portalCover) ?>');">
    <div class="brand-visual-body">
        <img src="<?= e($portalLogo) ?>" alt="Logo restaurant" class="brand-visual-logo">
        <div class="brand-visual-copy">
            <span class="pill badge-gold">Portail public</span>
            <h1 style="margin:10px 0 8px; color:#fff8e7;"><?= e($tenant['public_name'] ?? $tenant['name']) ?></h1>
            <p class="muted" style="margin:0;"><?= e($tenant['portal_tagline'] ?? '') ?></p>
        </div>
    </div>
</section>

<section class="card" style="padding:28px; border-top:10px solid <?= e($tenant['primary_color'] ?? '#0f766e') ?>;">
    <h1 style="margin-top:0; color: <?= e($tenant['secondary_color'] ?? '#111827') ?>;"><?= e($tenant['public_name'] ?? $tenant['name']) ?></h1>
    <p class="muted"><?= e($tenant['portal_tagline'] ?? '') ?></p>
    <p><?= e($tenant['welcome_text'] ?? '') ?></p>
    <p><strong>Acces public:</strong> consultation libre des informations et du menu tant qu aucune action privee n est engagee.</p>
    <p><strong>Support:</strong> <?= e($tenant['support_email'] ?? '') ?> / <?= e($tenant['phone'] ?? '') ?></p>
    <p><strong>Regle commande:</strong> <?= !empty($public_rules['auth_required_for_order']) ? 'compte requis pour commander' : 'commande publique autorisee' ?></p>
    <p><strong>Regle reservation:</strong> <?= !empty($public_rules['auth_required_for_reservation']) ? 'compte requis pour reserver' : 'reservation publique autorisee' ?></p>
    <div class="toolbar-actions" style="margin-top:14px;">
        <a href="<?= e($registration_path) ?>">Inscription client</a>
        <a href="/login" class="button-muted">Connexion</a>
    </div>
</section>

<section class="card" style="padding:22px; margin-top:24px;">
    <h2 style="margin-top:0;">Parcours client</h2>
    <p>Sans compte: voir le restaurant, voir le menu, voir les prix, consulter les informations publiques.</p>
    <p>Avec compte: passer commande, reserver, suivre ses demandes et consulter son historique personnel quand ces modules sont actifs.</p>
</section>

<section class="card" style="padding:22px; margin-top:24px;">
    <h2 style="margin-top:0;">Menu public</h2>
    <?php if (!empty($public_rules['public_menu_enabled'])): ?>
        <?php if ($menu_items !== []): ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Article</th><th>Categorie</th><th>Prix</th><th>Statut</th></tr></thead>
                    <tbody>
                    <?php foreach ($menu_items as $item): ?>
                        <tr>
                            <td>
                                <div class="menu-thumb">
                                    <img src="<?= e(menu_item_media_url_or_default($item['image_url'] ?? null)) ?>" alt="<?= e($item['name']) ?>">
                                    <div>
                                        <strong><?= e($item['name']) ?></strong><br>
                                        <span class="muted"><?= e($item['description'] ?? '') ?></span>
                                    </div>
                                </div>
                            </td>
                            <td><?= e($item['category_name']) ?></td>
                            <td><?= e(format_money($item['price'], $tenant['currency_code'] ?? 'USD')) ?></td>
                            <td><?= e(status_label($item['status'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="muted">Aucun article public disponible pour le moment.</p>
        <?php endif; ?>
    <?php else: ?>
        <p class="muted">Le menu public est desactive pour ce restaurant.</p>
    <?php endif; ?>
</section>
