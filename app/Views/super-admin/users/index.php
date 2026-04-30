<section class="topbar">
    <div class="brand">
        <h1>Utilisateurs</h1>
        <p>Affectez chaque utilisateur au bon restaurant et au bon role, sans exposition de codes techniques ni fuite inter-restaurant.</p>
    </div>
</section>

<?php if (!empty($flash_success)): ?><div class="flash-ok"><?= e($flash_success) ?></div><?php endif; ?>
<?php if (!empty($flash_error)): ?><div class="flash-bad"><?= e($flash_error) ?></div><?php endif; ?>

<section class="card" style="padding:22px; margin-bottom:24px;">
    <h2 style="margin-top:0;">Creer un utilisateur</h2>
    <form method="post" action="/super-admin/users" class="split">
        <div>
            <label>Restaurant</label>
            <select name="restaurant_id" data-role-scope>
                <option value="">Plateforme</option>
                <?php foreach ($restaurants as $restaurant): ?>
                    <option value="<?= e((string) $restaurant['id']) ?>"><?= e($restaurant['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Role</label>
            <select name="role_id" data-role-select>
                <?php foreach ($roles as $role): ?>
                    <option value="<?= e((string) $role['id']) ?>" data-scope="<?= e($role['scope']) ?>" data-restaurant-id="<?= e((string) ($role['restaurant_id'] ?? '')) ?>" data-role-code="<?= e($role['code']) ?>"><?= e($role['display_name'] ?? $role['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div><label>Nom complet</label><input name="full_name" required></div>
        <div><label>E-mail</label><input name="email" type="email" required></div>
        <div><label>Telephone</label><input name="phone"></div>
        <div><label>Mot de passe</label><input name="password" value="password" required></div>
        <div><label>Statut</label><select name="status"><option value="active">Actif</option><option value="disabled">Desactive</option><option value="banned">Banni</option></select></div>
        <div><label><input type="checkbox" name="must_change_password" value="1" checked style="width:auto;margin-right:8px;">Forcer le changement de mot de passe</label></div>
        <div style="grid-column:1 / -1;"><button type="submit">Creer l'utilisateur</button></div>
    </form>
</section>

<section class="card" style="padding:22px; margin-bottom:24px;">
    <h2 style="margin-top:0;">Affecter un utilisateur existant</h2>
    <p class="muted">Le super administrateur peut modifier a tout moment le restaurant et le role d'un compte existant.</p>

    <?php if ($users === []): ?>
        <div class="compact-empty">Aucun utilisateur disponible.</div>
    <?php else: ?>
        <div class="repeat-list">
            <?php foreach ($users as $user): ?>
                <form method="post" action="/super-admin/users/<?= e((string) $user['id']) ?>/update" class="role-panel">
                    <div class="topbar" style="margin-bottom:12px;">
                        <div>
                            <strong><?= e($user['full_name']) ?></strong>
                            <div class="muted"><?= e($user['email']) ?> · Role actuel : <?= e($user['role_display_name'] ?? $user['role_name']) ?></div>
                        </div>
                        <span class="pill <?= ($user['status'] ?? '') === 'active' ? 'badge-closed' : 'badge-bad' ?>"><?= e(status_label($user['status'])) ?></span>
                    </div>

                    <div class="split">
                        <div><label>Nom</label><input name="full_name" value="<?= e($user['full_name']) ?>"></div>
                        <div><label>E-mail</label><input name="email" value="<?= e($user['email']) ?>"></div>
                        <div><label>Telephone</label><input name="phone" value="<?= e($user['phone']) ?>"></div>
                        <div>
                            <label>Restaurant</label>
                            <select name="restaurant_id" data-role-scope>
                                <option value="">Plateforme</option>
                                <?php foreach ($restaurants as $restaurant): ?>
                                    <option value="<?= e((string) $restaurant['id']) ?>" <?= (string) ($user['restaurant_id'] ?? '') === (string) $restaurant['id'] ? 'selected' : '' ?>><?= e($restaurant['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Role</label>
                            <select name="role_id" data-role-select>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?= e((string) $role['id']) ?>"
                                            data-scope="<?= e($role['scope']) ?>"
                                            data-restaurant-id="<?= e((string) ($role['restaurant_id'] ?? '')) ?>"
                                            data-role-code="<?= e($role['code']) ?>"
                                            <?= (int) $user['role_id'] === (int) $role['id'] ? 'selected' : '' ?>>
                                        <?= e($role['display_name'] ?? $role['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div><label>Nouveau mot de passe</label><input name="password" value=""></div>
                        <div style="grid-column:1 / -1;"><label><input type="checkbox" name="must_change_password" value="1" <?= (int) $user['must_change_password'] === 1 ? 'checked' : '' ?> style="width:auto;margin-right:8px;">Forcer le changement au prochain acces</label></div>
                    </div>

                    <div class="toolbar-actions" style="margin-top:14px;">
                        <button type="submit">Enregistrer l'affectation</button>
                    </div>
                </form>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <div style="padding:22px 22px 10px;">
        <h2 style="margin:0;">Statut des comptes</h2>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Utilisateur</th><th>Restaurant</th><th>Role</th><th>Statut</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td><strong><?= e($user['full_name']) ?></strong><br><span class="muted"><?= e($user['email']) ?></span></td>
                    <td><?= e($user['restaurant_name'] ?? 'Plateforme') ?></td>
                    <td><?= e($user['role_display_name'] ?? $user['role_name']) ?></td>
                    <td><span class="pill <?= ($user['status'] ?? '') === 'active' ? 'badge-closed' : 'badge-bad' ?>"><?= e(status_label($user['status'])) ?></span></td>
                    <td>
                        <form method="post" action="/super-admin/users/<?= e((string) $user['id']) ?>/status" class="toolbar-actions">
                            <select name="status">
                                <option value="active">Actif</option>
                                <option value="disabled">Desactive</option>
                                <option value="banned">Banni</option>
                                <option value="archived">Archive</option>
                            </select>
                            <button type="submit" class="button-muted">Appliquer</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<script>
document.querySelectorAll('form').forEach(function (form) {
    var restaurantSelect = form.querySelector('[data-role-scope]');
    var roleSelect = form.querySelector('[data-role-select]');
    if (!restaurantSelect || !roleSelect) {
        return;
    }

    var options = Array.from(roleSelect.querySelectorAll('option'));

    function syncRoles() {
        var selectedRestaurantId = restaurantSelect.value || '';
        var firstVisible = null;

        options.forEach(function (option) {
            var scope = option.getAttribute('data-scope') || 'system';
            var optionRestaurantId = option.getAttribute('data-restaurant-id') || '';
            var roleCode = option.getAttribute('data-role-code') || '';
            var visible = scope === 'system'
                ? (selectedRestaurantId === '' ? roleCode === 'super_admin' : roleCode !== 'super_admin')
                : (selectedRestaurantId !== '' && optionRestaurantId === selectedRestaurantId);

            option.hidden = !visible;

            if (visible && firstVisible === null) {
                firstVisible = option;
            }
        });

        if (roleSelect.selectedOptions.length === 0 || roleSelect.selectedOptions[0].hidden) {
            if (firstVisible !== null) {
                roleSelect.value = firstVisible.value;
            }
        }
    }

    restaurantSelect.addEventListener('change', syncRoles);
    syncRoles();
});
</script>
