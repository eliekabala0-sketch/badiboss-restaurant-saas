<section class="topbar">
    <div class="brand">
        <h1>Socle du menu par restaurant</h1>
        <p>Catégories, articles, prix, image, description, ordre et disponibilités par canal.</p>
    </div>
</section>

<?php if (!empty($flash_success)): ?><div class="flash-ok"><?= e($flash_success) ?></div><?php endif; ?>

<section class="card" style="padding:22px; margin-bottom:24px;">
    <form method="get" action="/super-admin/menu">
        <label>Choisir un restaurant</label>
        <select name="restaurant_id" onchange="this.form.submit()">
            <option value="">Sélectionner</option>
            <?php foreach ($restaurants as $row): ?>
                <option value="<?= e((string) $row['id']) ?>" <?= $restaurant && (int) $restaurant['id'] === (int) $row['id'] ? 'selected' : '' ?>><?= e($row['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</section>

<?php if ($restaurant): ?>
    <section class="split">
        <article class="card" style="padding:22px;">
            <h2 style="margin-top:0;">Nouvelle catégorie</h2>
            <form method="post" action="/super-admin/menu/categories">
                <input type="hidden" name="restaurant_id" value="<?= e((string) $restaurant['id']) ?>">
                <label>Nom</label><input name="name" required>
                <label>Slug</label><input name="slug" required>
                <label>Description</label><textarea name="description"></textarea>
                <label>Ordre</label><input name="display_order" value="0">
                <label>Statut</label><select name="status"><option value="active">Actif</option><option value="inactive">Inactif</option></select>
                <button type="submit">Créer la catégorie</button>
            </form>
        </article>

        <article class="card" style="padding:22px;">
            <h2 style="margin-top:0;">Nouvel article</h2>
            <form method="post" action="/super-admin/menu/items">
                <input type="hidden" name="restaurant_id" value="<?= e((string) $restaurant['id']) ?>">
                <label>Catégorie</label><select name="category_id"><?php foreach ($categories as $category): ?><option value="<?= e((string) $category['id']) ?>"><?= e($category['name']) ?></option><?php endforeach; ?></select>
                <label>Nom</label><input name="name" required>
                <label>Slug</label><input name="slug" required>
                <label>Description</label><textarea name="description"></textarea>
                <label>Image URL</label><input name="image_url">
                <label>Prix (USD)</label><input name="price" value="0.00">
                <label>Ordre</label><input name="display_order" value="0">
                <label>Statut</label><select name="status"><option value="active">Actif</option><option value="out_of_stock">Épuisé</option><option value="hidden">Masqué</option></select>
                <label><input type="checkbox" name="is_available" value="1" checked style="width:auto;margin-right:8px;">Disponible</label>
                <label><input type="checkbox" name="available_dine_in" value="1" checked style="width:auto;margin-right:8px;">Sur place</label>
                <label><input type="checkbox" name="available_takeaway" value="1" checked style="width:auto;margin-right:8px;">À emporter</label>
                <label><input type="checkbox" name="available_delivery" value="1" checked style="width:auto;margin-right:8px;">Livraison</label>
                <button type="submit">Créer l’article</button>
            </form>
        </article>
    </section>

    <section class="card" style="margin-top:24px; padding:22px;">
        <h2 style="margin-top:0;">Éditer les catégories</h2>
        <?php foreach ($categories as $category): ?>
            <form method="post" action="/super-admin/menu/categories/<?= e((string) $category['id']) ?>/update" class="split" style="padding:14px 0; border-bottom:1px solid var(--line);">
                <input type="hidden" name="restaurant_id" value="<?= e((string) $restaurant['id']) ?>">
                <div><label>Nom</label><input name="name" value="<?= e($category['name']) ?>"></div>
                <div><label>Slug</label><input name="slug" value="<?= e($category['slug']) ?>"></div>
                <div><label>Description</label><input name="description" value="<?= e($category['description']) ?>"></div>
                <div><label>Ordre</label><input name="display_order" value="<?= e((string) $category['display_order']) ?>"></div>
                <div><label>Statut</label><select name="status"><option value="active" <?= $category['status'] === 'active' ? 'selected' : '' ?>>Actif</option><option value="inactive" <?= $category['status'] === 'inactive' ? 'selected' : '' ?>>Inactif</option><option value="archived" <?= $category['status'] === 'archived' ? 'selected' : '' ?>>Archivé</option></select></div>
                <div style="align-self:end;"><button type="submit">Mettre a jour</button></div>
            </form>
        <?php endforeach; ?>
    </section>

    <section class="card" style="margin-top:24px;">
        <div class="table-wrap">
            <table>
                <thead><tr><th>Article</th><th>Catégorie</th><th>Prix</th><th>Canaux</th><th>Statut</th><th>Édition</th><th>Action rapide</th></tr></thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><strong><?= e($item['name']) ?></strong><br><span class="muted"><?= e($item['slug']) ?></span></td>
                        <td><?= e($item['category_name']) ?></td>
                        <td><?= e(format_money($item['price'], $restaurant['currency_code'] ?? 'USD')) ?></td>
                        <td><?= $item['available_dine_in'] ? 'Sur place ' : '' ?><?= $item['available_takeaway'] ? 'À emporter ' : '' ?><?= $item['available_delivery'] ? 'Livraison' : '' ?></td>
                        <td><span class="pill <?= $item['status'] !== 'active' ? 'badge-off' : '' ?>"><?= e(status_label($item['status'])) ?></span></td>
                        <td style="min-width:280px;">
                            <form method="post" action="/super-admin/menu/items/<?= e((string) $item['id']) ?>/update">
                                <input type="hidden" name="restaurant_id" value="<?= e((string) $restaurant['id']) ?>">
                                <label>Nom</label><input name="name" value="<?= e($item['name']) ?>">
                                <label>Slug</label><input name="slug" value="<?= e($item['slug']) ?>">
                                <label>Catégorie</label><select name="category_id"><?php foreach ($categories as $category): ?><option value="<?= e((string) $category['id']) ?>" <?= (int) $category['id'] === (int) $item['category_id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select>
                                <label>Description</label><input name="description" value="<?= e($item['description']) ?>">
                                <label>Image URL</label><input name="image_url" value="<?= e($item['image_url']) ?>">
                                <label>Prix (USD)</label><input name="price" value="<?= e((string) $item['price']) ?>">
                                <label>Ordre</label><input name="display_order" value="<?= e((string) $item['display_order']) ?>">
                                <label>Statut</label><select name="status"><option value="active" <?= $item['status'] === 'active' ? 'selected' : '' ?>>Actif</option><option value="out_of_stock" <?= $item['status'] === 'out_of_stock' ? 'selected' : '' ?>>Épuisé</option><option value="hidden" <?= $item['status'] === 'hidden' ? 'selected' : '' ?>>Masqué</option><option value="archived" <?= $item['status'] === 'archived' ? 'selected' : '' ?>>Archivé</option></select>
                                <label><input type="checkbox" name="is_available" value="1" <?= (int) $item['is_available'] === 1 ? 'checked' : '' ?> style="width:auto;margin-right:8px;">Disponible</label>
                                <label><input type="checkbox" name="available_dine_in" value="1" <?= (int) $item['available_dine_in'] === 1 ? 'checked' : '' ?> style="width:auto;margin-right:8px;">Sur place</label>
                                <label><input type="checkbox" name="available_takeaway" value="1" <?= (int) $item['available_takeaway'] === 1 ? 'checked' : '' ?> style="width:auto;margin-right:8px;">À emporter</label>
                                <label><input type="checkbox" name="available_delivery" value="1" <?= (int) $item['available_delivery'] === 1 ? 'checked' : '' ?> style="width:auto;margin-right:8px;">Livraison</label>
                                <button type="submit">Mettre à jour</button>
                            </form>
                        </td>
                        <td>
                            <form method="post" action="/super-admin/menu/items/<?= e((string) $item['id']) ?>/status">
                                <input type="hidden" name="restaurant_id" value="<?= e((string) $restaurant['id']) ?>">
                                <select name="status">
                                    <option value="active">Actif</option>
                                    <option value="out_of_stock">Épuisé</option>
                                    <option value="hidden">Masqué</option>
                                    <option value="archived">Archivé</option>
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
<?php endif; ?>
