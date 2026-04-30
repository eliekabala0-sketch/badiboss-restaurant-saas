<section class="topbar">
    <div class="brand">
        <h1>Roles et acces du restaurant</h1>
        <p>Attribuez rapidement les roles predefinis, creez des roles personnalises et affectez chaque utilisateur au bon niveau d'acces.</p>
    </div>
</section>

<?php if (!empty($flash_success)): ?><div class="flash-ok"><?= e($flash_success) ?></div><?php endif; ?>
<?php if (!empty($flash_error)): ?><div class="flash-bad"><?= e($flash_error) ?></div><?php endif; ?>

<section class="card" style="padding:22px; margin-bottom:24px;">
    <div class="topbar" style="margin-bottom:16px;">
        <div>
            <h2 style="margin:0;">Roles predefinis</h2>
            <p class="muted" style="margin:6px 0 0;">Ces roles fonctionnent immediatement sans recreation manuelle.</p>
        </div>
        <span class="pill badge-gold"><?= e((string) count($preset_roles)) ?> roles</span>
    </div>

    <div class="grid" style="grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));">
        <?php foreach ($preset_roles as $role): ?>
            <article class="role-panel">
                <div class="topbar" style="margin-bottom:10px;">
                    <strong><?= e($role['display_name'] ?? $role['name']) ?></strong>
                    <span class="pill <?= (int) ($role['is_locked'] ?? 0) === 1 ? 'badge-ready' : 'badge-neutral' ?>"><?= e(status_label($role['status'])) ?></span>
                </div>

                <p class="muted" style="margin-top:0;">
                    <?php if (!empty($role['description'])): ?>
                        <?= e($role['description']) ?>
                    <?php else: ?>
                        Role operationnel preconfigure pour ce restaurant.
                    <?php endif; ?>
                </p>

                <?php if (($role['permission_labels'] ?? []) !== []): ?>
                    <div class="inline-list">
                        <?php foreach ($role['permission_labels'] as $permissionLabel): ?>
                            <span class="pill badge-neutral"><?= e($permissionLabel) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="muted">Aucun acces operationnel supplementaire.</p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="split" style="margin-bottom:24px;">
    <article class="card" style="padding:22px;">
        <h2 style="margin-top:0;">Creer un role personnalise</h2>
        <p class="muted">Les codes internes restent en base. L'interface affiche uniquement les libelles en francais.</p>

        <form method="post" action="/owner/access/roles" class="split">
            <div><label>Nom du role</label><input name="name" required></div>
            <div><label>Code interne facultatif</label><input name="code" placeholder="genere automatiquement si vide"></div>
            <div style="grid-column:1 / -1;"><label>Description</label><textarea name="description" placeholder="Exemple : supervise la salle et la fermeture caisse."></textarea></div>
            <div><label>Statut</label><select name="status"><option value="active">Actif</option><option value="inactive">Inactif</option></select></div>

            <div style="grid-column:1 / -1;">
                <label>Acces a accorder</label>
                <div class="section-stack">
                    <?php foreach ($permission_groups as $group): ?>
                        <div class="role-panel">
                            <strong><?= e($group['label']) ?></strong>
                            <div class="grid" style="grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); margin-top:12px;">
                                <?php foreach ($group['permissions'] as $permission): ?>
                                    <label style="margin-bottom:0; padding:12px 14px; border:1px solid var(--line); border-radius:14px; background:rgba(255,255,255,0.02);">
                                        <input type="checkbox" name="permission_ids[]" value="<?= e((string) $permission['id']) ?>" style="width:auto;margin-right:8px;">
                                        <strong><?= e($permission['label']) ?></strong>
                                        <?php if (!empty($permission['description_fr'])): ?><div class="muted" style="margin-top:6px;"><?= e($permission['description_fr']) ?></div><?php endif; ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="grid-column:1 / -1;"><button type="submit">Creer le role</button></div>
        </form>
    </article>

    <article class="card" style="padding:22px;">
        <h2 style="margin-top:0;">Affecter un utilisateur</h2>
        <p class="muted">L'affectation est limitee aux utilisateurs de votre restaurant. Aucun utilisateur d'un autre restaurant n'apparait ici.</p>

        <?php if ($users === []): ?>
            <div class="compact-empty">Aucun utilisateur disponible pour ce restaurant.</div>
        <?php else: ?>
            <?php foreach ($users as $user): ?>
                <form method="post" action="/owner/access/users/<?= e((string) $user['id']) ?>/role" style="padding:14px 0; border-bottom:1px solid var(--line);">
                    <p style="margin:0 0 10px;">
                        <strong><?= e($user['full_name']) ?></strong><br>
                        <span class="muted"><?= e($user['email']) ?> · Role actuel : <?= e($user['role_display_name'] ?? $user['role_name']) ?></span>
                    </p>
                    <select name="role_id">
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= e((string) $role['id']) ?>" <?= (int) $user['role_id'] === (int) $role['id'] ? 'selected' : '' ?>><?= e($role['display_name'] ?? $role['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit">Enregistrer l'affectation</button>
                </form>
            <?php endforeach; ?>
        <?php endif; ?>
    </article>
</section>

<section class="card" style="padding:22px;">
    <h2 style="margin-top:0;">Roles disponibles</h2>
    <p class="muted">Les roles systeme restent attribuables. Seuls les roles personnalises du restaurant peuvent etre modifies ici.</p>

    <?php foreach ($roles as $role): ?>
        <div class="role-panel" style="margin-bottom:14px;">
            <div class="topbar" style="margin-bottom:10px;">
                <div>
                    <strong><?= e($role['display_name'] ?? $role['name']) ?></strong>
                    <div class="muted"><?= e((int) ($role['is_system_preset'] ?? 0) === 1 ? 'Role predefini' : 'Role personnalise') ?></div>
                </div>
                <span class="pill <?= (int) ($role['is_locked'] ?? 0) === 1 ? 'badge-ready' : 'badge-neutral' ?>"><?= e(status_label($role['status'])) ?></span>
            </div>

            <?php if (($role['permission_labels'] ?? []) !== []): ?>
                <div class="inline-list" style="margin-bottom:14px;">
                    <?php foreach ($role['permission_labels'] as $permissionLabel): ?>
                        <span class="pill badge-neutral"><?= e($permissionLabel) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="muted">Aucun acces operationnel selectionne.</p>
            <?php endif; ?>

            <?php if ((int) ($role['is_locked'] ?? 0) !== 1): ?>
                <form method="post" action="/owner/access/roles/<?= e((string) $role['id']) ?>/permissions">
                    <div class="section-stack">
                        <?php foreach ($permission_groups as $group): ?>
                            <div>
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
                    </div>
                    <div class="toolbar-actions" style="margin-top:14px;">
                        <button type="submit">Mettre a jour les acces</button>
                    </div>
                </form>

                <form method="post" action="/owner/access/roles/<?= e((string) $role['id']) ?>/status" class="toolbar-actions" style="margin-top:14px;">
                    <select name="status" style="max-width:220px;">
                        <option value="active" <?= ($role['status'] ?? '') === 'active' ? 'selected' : '' ?>>Actif</option>
                        <option value="inactive" <?= ($role['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactif</option>
                        <option value="archived">Archive</option>
                    </select>
                    <button type="submit" class="button-muted">Changer le statut</button>
                </form>
            <?php else: ?>
                <p class="muted" style="margin-bottom:0;">Ce role predefini reste attribuable mais sa structure n'est pas modifiable ici.</p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>
