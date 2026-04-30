<section class="topbar">
    <div class="brand">
        <h1>Journal d’audit</h1>
        <p>Filtres minimum par restaurant, utilisateur, module et date.</p>
    </div>
</section>

<section class="card" style="padding:22px; margin-bottom:24px;">
    <form method="get" action="/super-admin/audit" class="split">
        <div><label>Restaurant</label><select name="restaurant_id"><option value="">Tous</option><?php foreach ($restaurants as $restaurant): ?><option value="<?= e((string) $restaurant['id']) ?>" <?= (string) $filters['restaurant_id'] === (string) $restaurant['id'] ? 'selected' : '' ?>><?= e($restaurant['name']) ?></option><?php endforeach; ?></select></div>
        <div><label>Utilisateur</label><select name="user_id"><option value="">Tous</option><?php foreach ($users as $user): ?><option value="<?= e((string) $user['id']) ?>" <?= (string) $filters['user_id'] === (string) $user['id'] ? 'selected' : '' ?>><?= e($user['full_name']) ?></option><?php endforeach; ?></select></div>
        <div><label>Module</label><input name="module_name" value="<?= e((string) $filters['module_name']) ?>"></div>
        <div><label>Date de début</label><input type="date" name="date_from" value="<?= e((string) $filters['date_from']) ?>"></div>
        <div><label>Date fin</label><input type="date" name="date_to" value="<?= e((string) $filters['date_to']) ?>"></div>
        <div style="align-self:end;"><button type="submit">Filtrer</button></div>
    </form>
</section>

<section class="card">
    <div class="table-wrap">
        <table>
            <thead><tr><th>Date</th><th>Restaurant</th><th>Acteur</th><th>Module</th><th>Action</th><th>Entité</th><th>Justification</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= e($row['created_at']) ?></td>
                    <td><?= e($row['restaurant_name'] ?? '-') ?></td>
                    <td><?= e($row['actor_name']) ?><br><span class="muted"><?= e($row['actor_role_code']) ?></span></td>
                    <td><?= e($row['module_name']) ?></td>
                    <td><?= e($row['action_name']) ?></td>
                    <td><?= e(($row['entity_type'] ?? '-') . ' #' . ($row['entity_id'] ?? '-')) ?></td>
                    <td><?= e($row['justification']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
