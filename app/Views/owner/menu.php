<section class="topbar">
    <div class="brand">
        <h1>Menu du restaurant</h1>
        <p>Administrez les categories, publiez de nouveaux plats et gardez un menu vivant pour le service.</p>
    </div>
</section>

<?php if (!empty($flash_success)): ?><div class="flash-ok"><?= e($flash_success) ?></div><?php endif; ?>
<?php if (!empty($flash_error)): ?><div class="flash-bad"><?= e($flash_error) ?></div><?php endif; ?>

<section class="card" style="padding:22px; margin-bottom:24px;">
    <h2 style="margin-top:0;"><?= e($restaurant['public_name'] ?? $restaurant['name']) ?></h2>
    <p class="muted"><?= e($restaurant['portal_tagline'] ?? '') ?></p>
</section>

<section class="split" style="margin-bottom:24px;">
    <article class="card" style="padding:22px;">
        <h2 style="margin-top:0;">Nouvelle categorie</h2>
        <form method="post" action="/owner/menu/categories">
            <label>Nom</label>
            <input name="name" required placeholder="Plats signatures, Desserts, Boissons">
            <label>Slug</label>
            <input name="slug" placeholder="Optionnel, genere depuis le nom si vide">
            <label>Description</label>
            <textarea name="description" placeholder="Courte description de la categorie"></textarea>
            <label>Ordre</label>
            <input name="display_order" value="0">
            <label>Statut</label>
            <select name="status">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
            <button type="submit">Creer la categorie</button>
        </form>
    </article>

    <article class="card" style="padding:22px;">
        <h2 style="margin-top:0;">Nouveau plat</h2>
        <?php if ($categories === []): ?>
            <p class="muted">Creez d abord une categorie pour rattacher le nouveau plat au vrai menu du restaurant.</p>
        <?php else: ?>
            <form method="post" action="/owner/menu/items">
                <label>Categorie</label>
                <select name="category_id" required>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= e((string) $category['id']) ?>"><?= e($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label>Nom du plat</label>
                <input name="name" required placeholder="Poulet braise premium">
                <label>Slug</label>
                <input name="slug" placeholder="Optionnel, genere depuis le nom si vide">
                <label>Description</label>
                <textarea name="description" placeholder="Description visible dans le menu"></textarea>
                <label>Prix</label>
                <input name="price" value="0.00">
                <label>Ordre</label>
                <input name="display_order" value="0">
                <label>Statut</label>
                <select name="status">
                    <option value="active">Actif</option>
                    <option value="out_of_stock">Epuise</option>
                    <option value="hidden">Masque</option>
                </select>
                <label><input type="checkbox" name="is_available" value="1" checked style="width:auto;margin-right:8px;">Disponible</label>
                <label><input type="checkbox" name="available_dine_in" value="1" checked style="width:auto;margin-right:8px;">Sur place</label>
                <label><input type="checkbox" name="available_takeaway" value="1" checked style="width:auto;margin-right:8px;">A emporter</label>
                <label><input type="checkbox" name="available_delivery" value="1" checked style="width:auto;margin-right:8px;">Livraison</label>
                <button type="submit">Ajouter au menu</button>
            </form>
        <?php endif; ?>
    </article>
</section>

<section class="card">
    <div class="table-wrap">
        <table>
            <thead><tr><th>Article</th><th>Categorie</th><th>Prix</th><th>Disponibilite</th><th>Statut</th><th>Action menu</th></tr></thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><strong><?= e($item['name']) ?></strong><br><span class="muted"><?= e($item['description']) ?></span></td>
                    <td><?= e($item['category_name']) ?></td>
                    <td><?= e(format_money($item['price'], $restaurant['currency_code'] ?? 'USD')) ?></td>
                    <td><?= (int) $item['is_available'] === 1 ? 'Disponible' : 'Indisponible' ?></td>
                    <td><span class="pill <?= $item['status'] !== 'active' ? 'badge-off' : 'badge-closed' ?>"><?= e(status_label($item['status'])) ?></span></td>
                    <td>
                        <form method="post" action="/owner/menu/items/<?= e((string) $item['id']) ?>/status">
                            <select name="status">
                                <option value="active" <?= $item['status'] === 'active' ? 'selected' : '' ?>>Actif</option>
                                <option value="out_of_stock" <?= $item['status'] === 'out_of_stock' ? 'selected' : '' ?>>Epuise</option>
                                <option value="hidden" <?= $item['status'] === 'hidden' ? 'selected' : '' ?>>Masque</option>
                                <option value="archived" <?= $item['status'] === 'archived' ? 'selected' : '' ?>>Archive</option>
                            </select>
                            <button type="submit">Appliquer</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
