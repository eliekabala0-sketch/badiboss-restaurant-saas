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
                            <td><strong><?= e($item['name']) ?></strong><br><span class="muted"><?= e($item['description'] ?? '') ?></span></td>
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
