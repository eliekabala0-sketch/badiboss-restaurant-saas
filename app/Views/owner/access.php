<?php
$userPage = $user_page ?? ['items' => $users ?? [], 'total' => count($users ?? []), 'page' => 1, 'total_pages' => 1, 'filters' => []];
$filters = $userPage['filters'] ?? [];
$roleLabelsById = [];
$permissionsByRole = [];
foreach ($roles as $role) {
    $roleLabelsById[(int) $role['id']] = (string) ($role['display_name'] ?? $role['name']);
    $permissionsByRole[(int) $role['id']] = $role['permission_labels'] ?? [];
}
$queryBase = [
    'q' => (string) ($filters['search'] ?? ''),
    'status' => (string) ($filters['status'] ?? ''),
    'role_id' => (string) ((int) ($filters['role_id'] ?? 0) ?: ''),
];
$pageUrl = static function (int $page) use ($queryBase, $access_editor): string {
    $query = array_filter(array_merge($queryBase, [
        'page' => $page,
        'access_editor' => !empty($access_editor) ? '1' : '',
    ]), static fn ($value): bool => $value !== '' && $value !== null);

    return '/owner/users' . ($query === [] ? '' : '?' . http_build_query($query));
};
$selectedRoleId = (int) ($filters['role_id'] ?? 0);
?>

<section class="topbar">
    <div class="brand">
        <h1>Personnel et acces</h1>
        <p>Gerez les agents du restaurant, leurs postes, leur statut de connexion et les acces effectifs sans supprimer de compte.</p>
    </div>
    <div class="toolbar-actions">
        <a href="/owner/users<?= !empty($access_editor) ? '' : '?access_editor=1' ?>" class="button-muted">
            <?= !empty($access_editor) ? 'Masquer permissions avancees' : 'Permissions avancees' ?>
        </a>
    </div>
</section>

<?php if (!empty($flash_success)): ?><div class="flash-ok"><?= e($flash_success) ?></div><?php endif; ?>
<?php if (!empty($flash_error)): ?><div class="flash-bad"><?= e($flash_error) ?></div><?php endif; ?>

<section class="card" style="padding:22px; margin-bottom:24px;">
    <div class="topbar" style="margin-bottom:16px;">
        <div>
            <h2 style="margin:0;">Agents</h2>
            <p class="muted" style="margin:6px 0 0;"><?= e((string) ($userPage['total'] ?? 0)) ?> compte(s), affichage pagine.</p>
        </div>
    </div>

    <form method="get" action="/owner/users" class="split" style="margin-bottom:18px;">
        <?php if (!empty($access_editor)): ?><input type="hidden" name="access_editor" value="1"><?php endif; ?>
        <div><label>Rechercher</label><input name="q" value="<?= e((string) ($filters['search'] ?? '')) ?>" placeholder="Nom, email ou telephone"></div>
        <div>
            <label>Statut</label>
            <select name="status">
                <option value="">Tous</option>
                <option value="active" <?= ($filters['status'] ?? '') === 'active' ? 'selected' : '' ?>>Actif</option>
                <option value="disabled" <?= ($filters['status'] ?? '') === 'disabled' ? 'selected' : '' ?>>Suspendu / inactif</option>
                <option value="banned" <?= ($filters['status'] ?? '') === 'banned' ? 'selected' : '' ?>>Connexion bloquee</option>
                <option value="archived" <?= ($filters['status'] ?? '') === 'archived' ? 'selected' : '' ?>>Fin de contrat</option>
            </select>
        </div>
        <div>
            <label>Poste</label>
            <select name="role_id">
                <option value="">Tous</option>
                <?php foreach ($roles as $role): ?>
                    <option value="<?= e((string) $role['id']) ?>" <?= $selectedRoleId === (int) $role['id'] ? 'selected' : '' ?>><?= e($role['display_name'] ?? $role['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="align-self:end;"><button type="submit">Filtrer</button></div>
    </form>

    <div class="table-wrap">
        <table>
            <thead><tr><th>Agent</th><th>Poste</th><th>Acces effectifs</th><th>Statut</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($users as $user): ?>
                <?php $permissionLabels = $permissionsByRole[(int) $user['role_id']] ?? []; ?>
                <tr>
                    <td>
                        <strong><?= e($user['full_name']) ?></strong><br>
                        <span class="muted"><?= e($user['email']) ?><?= !empty($user['phone']) ? ' · ' . e($user['phone']) : '' ?></span>
                    </td>
                    <td><?= e($user['role_display_name'] ?? $user['role_name']) ?></td>
                    <td>
                        <?php if ($permissionLabels === []): ?>
                            <span class="muted">Aucun module supplementaire</span>
                        <?php else: ?>
                            <div class="inline-list">
                                <?php foreach (array_slice($permissionLabels, 0, 5) as $permissionLabel): ?>
                                    <span class="pill badge-neutral"><?= e($permissionLabel) ?></span>
                                <?php endforeach; ?>
                                <?php if (count($permissionLabels) > 5): ?><span class="pill badge-gold">+<?= e((string) (count($permissionLabels) - 5)) ?></span><?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td><span class="pill <?= ($user['status'] ?? '') === 'active' ? 'badge-closed' : 'badge-bad' ?>"><?= e(status_label($user['status'] ?? null)) ?></span></td>
                    <td class="toolbar-actions">
                        <a href="/owner/access/users/<?= e((string) $user['id']) ?>" class="button-muted">Fiche</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($users === []): ?>
                <tr><td colspan="5"><div class="compact-empty">Aucun agent ne correspond aux filtres.</div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="toolbar-actions" style="margin-top:16px;">
        <?php if ((int) ($userPage['page'] ?? 1) > 1): ?><a class="button-muted" href="<?= e($pageUrl((int) $userPage['page'] - 1)) ?>">Precedent</a><?php endif; ?>
        <span class="pill badge-neutral">Page <?= e((string) ($userPage['page'] ?? 1)) ?> / <?= e((string) ($userPage['total_pages'] ?? 1)) ?></span>
        <?php if ((int) ($userPage['page'] ?? 1) < (int) ($userPage['total_pages'] ?? 1)): ?><a class="button-muted" href="<?= e($pageUrl((int) $userPage['page'] + 1)) ?>">Voir plus</a><?php endif; ?>
    </div>
</section>

<section class="split" style="margin-bottom:24px;">
    <article class="card" style="padding:22px;">
        <h2 style="margin-top:0;">Creer un agent</h2>
        <form method="post" action="/owner/users" class="split">
            <div><label>Nom complet</label><input name="full_name" required></div>
            <div><label>Telephone</label><input name="phone"></div>
            <div><label>Email</label><input name="email" type="email" required></div>
            <div>
                <label>Poste</label>
                <select name="role_id" required>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= e((string) $role['id']) ?>"><?= e($role['display_name'] ?? $role['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label>Mot de passe initial</label><input name="password" value="password" required></div>
            <div>
                <label>Etat</label>
                <select name="status">
                    <option value="active">Actif</option>
                    <option value="disabled">Inactif / suspendu</option>
                </select>
            </div>
            <div style="grid-column:1 / -1;"><label>Motif si inactif</label><textarea name="status_reason" placeholder="Ex. acces cree en attente de contrat"></textarea></div>
            <div style="grid-column:1 / -1;"><label><input type="checkbox" name="must_change_password" value="1" checked style="width:auto;margin-right:8px;">Forcer le changement au prochain acces</label></div>
            <div style="grid-column:1 / -1;"><button type="submit">Creer l'agent</button></div>
        </form>
    </article>

    <article class="card" style="padding:22px;">
        <h2 style="margin-top:0;">Modifier ou suspendre</h2>
        <?php if ($users === []): ?>
            <div class="compact-empty">Aucun agent sur cette page.</div>
        <?php else: ?>
            <?php foreach ($users as $user): ?>
                <details class="compact-card" style="margin-bottom:12px;">
                    <summary><strong><?= e($user['full_name']) ?></strong> <span class="muted">· <?= e($user['role_display_name'] ?? $user['role_name']) ?></span></summary>
                    <div class="fold-body" style="padding:16px 0 0;">
                        <form method="post" action="/owner/users/<?= e((string) $user['id']) ?>/update" class="split">
                            <div><label>Nom</label><input name="full_name" value="<?= e($user['full_name']) ?>" required></div>
                            <div><label>Telephone</label><input name="phone" value="<?= e($user['phone'] ?? '') ?>"></div>
                            <div><label>Email</label><input name="email" type="email" value="<?= e($user['email']) ?>" required></div>
                            <div>
                                <label>Poste / service</label>
                                <select name="role_id">
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?= e((string) $role['id']) ?>" <?= (int) $user['role_id'] === (int) $role['id'] ? 'selected' : '' ?>><?= e($role['display_name'] ?? $role['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div><label>Nouveau mot de passe</label><input name="password" value=""></div>
                            <div><label><input type="checkbox" name="must_change_password" value="1" <?= (int) ($user['must_change_password'] ?? 0) === 1 ? 'checked' : '' ?> style="width:auto;margin-right:8px;">Changement requis</label></div>
                            <div style="grid-column:1 / -1;"><button type="submit">Enregistrer la fiche</button></div>
                        </form>

                        <form method="post" action="/owner/users/<?= e((string) $user['id']) ?>/status" class="split" style="margin-top:14px;">
                            <div>
                                <label>Action statut</label>
                                <select name="status">
                                    <option value="active">Reactiver / donner acces</option>
                                    <option value="disabled">Suspendre / retirer acces</option>
                                    <option value="banned">Bloquer connexion</option>
                                    <option value="archived">Fin de contrat</option>
                                </select>
                            </div>
                            <div><label>Motif</label><input name="status_reason" placeholder="Motif obligatoire en exploitation"></div>
                            <div style="align-self:end;"><button type="submit" class="button-muted">Appliquer sans supprimer</button></div>
                        </form>
                    </div>
                </details>
            <?php endforeach; ?>
        <?php endif; ?>
    </article>
</section>

<?php if (!empty($access_editor)): ?>
<section class="card" style="padding:22px; margin-bottom:24px;">
    <div class="topbar" style="margin-bottom:16px;">
        <div>
            <h2 style="margin:0;">Roles et permissions</h2>
            <p class="muted" style="margin:6px 0 0;">Editeur charge a la demande. Les changements sont scopes a ce restaurant.</p>
        </div>
    </div>

    <details class="compact-card" open>
        <summary><strong>Creer un role personnalise</strong></summary>
        <form method="post" action="/owner/access/roles" class="split" style="margin-top:16px;">
            <div><label>Nom du role</label><input name="name" required></div>
            <div><label>Code interne facultatif</label><input name="code" placeholder="genere automatiquement si vide"></div>
            <div style="grid-column:1 / -1;"><label>Description</label><textarea name="description"></textarea></div>
            <div><label>Statut</label><select name="status"><option value="active">Actif</option><option value="inactive">Inactif</option></select></div>
            <div style="grid-column:1 / -1;">
                <label>Acces a accorder</label>
                <?php foreach ($permission_groups as $group): ?>
                    <div class="role-panel" style="margin-bottom:12px;">
                        <strong><?= e($group['label']) ?></strong>
                        <div class="grid" style="grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); margin-top:10px;">
                            <?php foreach ($group['permissions'] as $permission): ?>
                                <label style="margin-bottom:0; padding:10px 12px; border:1px solid var(--line); border-radius:14px;">
                                    <input type="checkbox" name="permission_ids[]" value="<?= e((string) $permission['id']) ?>" style="width:auto;margin-right:8px;">
                                    <?= e($permission['label']) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div style="grid-column:1 / -1;"><button type="submit">Creer le role</button></div>
        </form>
    </details>

    <?php foreach ($roles as $role): ?>
        <details class="compact-card" style="margin-top:12px;">
            <summary>
                <strong><?= e($role['display_name'] ?? $role['name']) ?></strong>
                <span class="muted">· <?= e((int) ($role['is_system_preset'] ?? 0) === 1 ? 'Role predefini' : 'Role personnalise') ?></span>
            </summary>
            <div class="fold-body" style="padding:16px 0 0;">
                <form method="post" action="/owner/access/roles/<?= e((string) $role['id']) ?>/permissions">
                    <?php foreach ($permission_groups as $group): ?>
                        <div class="role-panel" style="margin-bottom:12px;">
                            <strong><?= e($group['label']) ?></strong>
                            <div class="grid" style="grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); margin-top:10px;">
                                <?php foreach ($group['permissions'] as $permission): ?>
                                    <label style="margin-bottom:0; padding:10px 12px; border:1px solid var(--line); border-radius:14px;">
                                        <input type="checkbox" name="permission_ids[]" value="<?= e((string) $permission['id']) ?>" style="width:auto;margin-right:8px;" <?= in_array((int) $permission['id'], $role_permissions[(int) $role['id']] ?? [], true) ? 'checked' : '' ?>>
                                        <?= e($permission['label']) ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div class="toolbar-actions"><button type="submit">Mettre a jour les acces</button></div>
                </form>
                <?php if ((int) ($role['is_locked'] ?? 0) !== 1): ?>
                    <form method="post" action="/owner/access/roles/<?= e((string) $role['id']) ?>/status" class="toolbar-actions" style="margin-top:14px;">
                        <select name="status" style="max-width:220px;">
                            <option value="active" <?= ($role['status'] ?? '') === 'active' ? 'selected' : '' ?>>Actif</option>
                            <option value="inactive" <?= ($role['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactif</option>
                            <option value="archived">Archive</option>
                        </select>
                        <button type="submit" class="button-muted">Changer le statut</button>
                    </form>
                <?php endif; ?>
            </div>
        </details>
    <?php endforeach; ?>
</section>
<?php else: ?>
<section class="card" style="padding:22px;">
    <h2 style="margin-top:0;">Postes disponibles</h2>
    <div class="grid" style="grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));">
        <?php foreach ($preset_roles as $role): ?>
            <article class="role-panel">
                <strong><?= e($role['display_name'] ?? $role['name']) ?></strong>
                <p class="muted" style="margin:8px 0 0;"><?= e($role['description'] ?? 'Role operationnel preconfigure.') ?></p>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
