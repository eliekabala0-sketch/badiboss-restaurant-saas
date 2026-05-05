<section class="topbar">
    <div class="brand">
        <h1>Super administration</h1>
        <p>Vue d ensemble courte, claire et orientee action pour gerer la plateforme sans ecran technique surcharge.</p>
    </div>
</section>

<?php if (!empty($flash_success ?? null)): ?><div class="flash-ok"><?= e($flash_success) ?></div><?php endif; ?>
<?php if (!empty($flash_error ?? null)): ?><div class="flash-bad"><?= e($flash_error) ?></div><?php endif; ?>

<?php $resetFilters = $reset_preview['filters'] ?? []; ?>
<?php $selectedTypes = $resetFilters['data_types'] ?? []; ?>
<?php $resetOptions = [
    'commandes_serveur' => 'Commandes serveur',
    'demandes_cuisine' => 'Cuisine / plats prepares',
    'demandes_stock' => 'Demandes stock cuisine',
    'stock_magasin' => 'Stock magasin (mouvements)',
    'stock_cuisine' => 'Stock cuisine (inventaire)',
    'ventes' => 'Ventes',
    'caisse_finance' => 'Caisse / finance',
    'pertes' => 'Pertes',
    'incidents' => 'Incidents / cas',
    'retours' => 'Retours ventes',
    'rapports_operationnels' => 'Corrections / incidents rapports',
    'audit_operationnel' => 'Audit operationnel lie',
    'stock_articles_fiches' => 'Articles stock (fiches orphelines, periode)',
    'images_test' => 'Images de test',
]; ?>

<section class="grid stats">
    <article class="card stat"><span>Restaurants</span><strong><?= e((string) $stats['restaurants_total']) ?></strong></article>
    <article class="card stat"><span>Restaurants actifs</span><strong><?= e((string) $stats['restaurants_active']) ?></strong></article>
    <article class="card stat"><span>Utilisateurs</span><strong><?= e((string) $stats['users_total']) ?></strong></article>
    <article class="card stat"><span>Entrees d audit</span><strong><?= e((string) $stats['audit_entries']) ?></strong></article>
</section>

<div class="section-stack">
    <section class="card" style="padding:22px;">
        <h2 style="margin-top:0;">Acces rapides</h2>
        <p class="muted">Les actions les plus utiles restent visibles des l ouverture.</p>
        <div class="nav" style="margin-bottom:0;">
            <a href="/super-admin/restaurants">Restaurants</a>
            <a href="/super-admin/users">Utilisateurs</a>
            <a href="/super-admin/settings">Parametres</a>
            <a href="/super-admin/menu">Menu</a>
            <a href="/super-admin/audit">Journal d audit</a>
        </div>
    </section>

    <section class="card" style="padding:22px;">
        <h2 style="margin-top:0;">Reinitialisation controlee</h2>
        <p class="muted">Reserve au super administrateur. Toujours previsualiser avant toute action et confirmer avec le texte exact.</p>
        <form method="post" action="/super-admin/reset/preview">
            <div class="split">
                <div>
                    <label>Restaurant concerne</label>
                    <select name="restaurant_id" required data-reset-restaurant>
                        <option value="">Choisir un restaurant</option>
                        <?php foreach ($restaurants as $restaurant): ?>
                            <option value="<?= e((string) $restaurant['id']) ?>" <?= (string) ($resetFilters['restaurant_id'] ?? '') === (string) $restaurant['id'] ? 'selected' : '' ?>><?= e($restaurant['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Perimetre</label>
                    <select name="scope" data-reset-scope>
                        <option value="restaurant" <?= ($resetFilters['scope'] ?? 'restaurant') === 'restaurant' ? 'selected' : '' ?>>Tout le restaurant</option>
                        <option value="user" <?= ($resetFilters['scope'] ?? '') === 'user' ? 'selected' : '' ?>>Un utilisateur precis</option>
                    </select>
                </div>
                <div data-reset-user-wrap>
                    <label>Utilisateur cible</label>
                    <select name="user_id" data-reset-user>
                        <option value="">Choisir un utilisateur</option>
                        <?php foreach (($restaurant_users ?? []) as $resetUser): ?>
                            <option value="<?= e((string) $resetUser['id']) ?>" data-restaurant-id="<?= e((string) $resetUser['restaurant_id']) ?>" <?= (string) ($resetFilters['user_id'] ?? '') === (string) $resetUser['id'] ? 'selected' : '' ?>><?= e($resetUser['full_name'] . ' - ' . $resetUser['email']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Periode</label>
                    <select name="period_type" data-reset-period>
                        <option value="day" <?= ($resetFilters['period_type'] ?? 'day') === 'day' ? 'selected' : '' ?>>Un jour precis</option>
                        <option value="week" <?= ($resetFilters['period_type'] ?? '') === 'week' ? 'selected' : '' ?>>Une semaine</option>
                        <option value="month" <?= ($resetFilters['period_type'] ?? '') === 'month' ? 'selected' : '' ?>>Un mois</option>
                        <option value="custom" <?= ($resetFilters['period_type'] ?? '') === 'custom' ? 'selected' : '' ?>>Plage personnalisee</option>
                    </select>
                </div>
                <div data-period-field="day">
                    <label>Jour</label>
                    <input type="date" name="day_value" value="<?= e((string) ($_POST['day_value'] ?? '')) ?>">
                </div>
                <div data-period-field="week">
                    <label>Semaine</label>
                    <input type="date" name="week_value" value="<?= e((string) ($_POST['week_value'] ?? '')) ?>">
                </div>
                <div data-period-field="month">
                    <label>Mois</label>
                    <input type="month" name="month_value" value="<?= e((string) ($_POST['month_value'] ?? '')) ?>">
                </div>
                <div data-period-field="custom">
                    <label>Date debut</label>
                    <input type="date" name="date_from" value="<?= e((string) ($_POST['date_from'] ?? '')) ?>">
                </div>
                <div data-period-field="custom">
                    <label>Date fin</label>
                    <input type="date" name="date_to" value="<?= e((string) ($_POST['date_to'] ?? '')) ?>">
                </div>
            </div>

            <label>Donnees a reinitialiser</label>
            <div class="inline-list">
                <?php foreach ($resetOptions as $value => $label): ?>
                    <label><input type="checkbox" name="data_types[]" value="<?= e($value) ?>" style="width:auto;margin-right:8px;" <?= in_array($value, $selectedTypes, true) ? 'checked' : '' ?>><?= e($label) ?></label>
                <?php endforeach; ?>
            </div>

            <div style="margin-top:16px;">
                <button type="submit">Previsualiser les donnees concernees</button>
            </div>
        </form>

        <?php if (!empty($reset_preview)): ?>
            <div class="card" style="padding:18px; margin-top:18px;">
                <h3 style="margin-top:0;">Previsualisation</h3>
                <p class="muted">Restaurant : <?= e($reset_preview['restaurant']['name'] ?? '-') ?> · Periode : <?= e($reset_preview['period']['label'] ?? '-') ?></p>
                <p class="muted">Montant total concerne : <?= e(number_format((float) ($reset_preview['amount_total'] ?? 0), 2, '.', ' ')) ?></p>
                <?php if ((int) ($reset_preview['counts']['stock_magasin_mouvements_exclus'] ?? 0) > 0): ?>
                    <p class="muted"><strong>Attention :</strong> <?= e((string) (int) $reset_preview['counts']['stock_magasin_mouvements_exclus']) ?> mouvement(s) stock sur la periode sont lies a une production cuisine et ne seront pas effaces tant que les productions restent (choisir aussi « Cuisine / plats prepares » ou effacer les productions d abord).</p>
                <?php endif; ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Bloc</th>
                            <th>Nombre</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (($reset_preview['counts'] ?? []) as $key => $count): ?>
                            <tr>
                                <td><?= e((string) (($reset_preview['dataset_labels'][$key] ?? null) ?: $key)) ?></td>
                                <td><?= e((string) $count) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (($reset_preview['users_concerned'] ?? []) !== []): ?>
                    <div class="table-wrap" style="margin-top:14px;">
                        <table>
                            <thead><tr><th>Utilisateurs concernes</th><th>Email</th></tr></thead>
                            <tbody>
                            <?php foreach ($reset_preview['users_concerned'] as $affectedUser): ?>
                                <tr>
                                    <td><?= e((string) ($affectedUser['full_name'] ?? '-')) ?></td>
                                    <td><?= e((string) ($affectedUser['email'] ?? '-')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <form method="post" action="/super-admin/reset/execute" style="margin-top:16px;">
                    <input type="hidden" name="restaurant_id" value="<?= e((string) ($reset_preview['filters']['restaurant_id'] ?? '')) ?>">
                    <input type="hidden" name="scope" value="<?= e((string) ($reset_preview['filters']['scope'] ?? 'restaurant')) ?>">
                    <input type="hidden" name="user_id" value="<?= e((string) ($reset_preview['filters']['user_id'] ?? '0')) ?>">
                    <input type="hidden" name="period_type" value="<?= e((string) ($reset_preview['filters']['period_type'] ?? 'day')) ?>">
                    <input type="hidden" name="day_value" value="<?= e((string) ($_POST['day_value'] ?? '')) ?>">
                    <input type="hidden" name="week_value" value="<?= e((string) ($_POST['week_value'] ?? '')) ?>">
                    <input type="hidden" name="month_value" value="<?= e((string) ($_POST['month_value'] ?? '')) ?>">
                    <input type="hidden" name="date_from" value="<?= e((string) ($_POST['date_from'] ?? '')) ?>">
                    <input type="hidden" name="date_to" value="<?= e((string) ($_POST['date_to'] ?? '')) ?>">
                    <?php foreach (($reset_preview['filters']['data_types'] ?? []) as $selectedType): ?>
                        <input type="hidden" name="data_types[]" value="<?= e((string) $selectedType) ?>">
                    <?php endforeach; ?>
                    <label>Motif de reinitialisation</label>
                    <textarea name="reset_reason" required></textarea>
                    <label>Confirmation forte</label>
                    <input name="confirmation_text" placeholder="REINITIALISER ou REINITIALISER RESTAURANT <?= e((string) ($reset_preview['filters']['restaurant_id'] ?? '')) ?>">
                    <button type="submit">Executer la reinitialisation ciblee</button>
                </form>
            </div>
        <?php endif; ?>

        <?php if (!empty($reset_report)): ?>
            <div class="card" style="padding:18px; margin-top:18px;">
                <h3 style="margin-top:0;">Rapport apres reinitialisation</h3>
                <p class="muted">Restaurant : <?= e($reset_report['preview']['restaurant']['name'] ?? '-') ?> · Periode : <?= e($reset_report['preview']['period']['label'] ?? '-') ?></p>
                <p class="muted">Motif : <?= e((string) ($reset_report['reason'] ?? '-')) ?></p>
                <p class="muted">Confirmation : <?= e((string) ($reset_report['confirmation_text'] ?? '-')) ?></p>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Table</th><th>Lignes touchees</th></tr></thead>
                        <tbody>
                        <?php foreach (($reset_report['deleted'] ?? []) as $table => $count): ?>
                            <tr>
                                <td><?= e((string) $table) ?></td>
                                <td><?= e((string) $count) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="muted" style="margin-bottom:0;">Audit cree : <strong>super_admin_data_reset</strong></p>
            </div>
        <?php endif; ?>
    </section>

    <details class="card fold-card" open>
        <summary>
            <div>
                <strong>Restaurants clients</strong>
                <div class="muted">Liste condensee avec lien genere, statut reel et branding.</div>
            </div>
            <span class="pill badge-neutral"><?= e((string) count($restaurants)) ?> restaurant(s)</span>
        </summary>
        <div class="fold-body">
            <?php if ($restaurants === []): ?>
                <div class="compact-empty">Aucun restaurant a afficher.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Restaurant</th>
                            <th>Branding</th>
                            <th>Lien d acces</th>
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

<script>
(function () {
    const restaurantSelect = document.querySelector('[data-reset-restaurant]');
    const scopeSelect = document.querySelector('[data-reset-scope]');
    const userWrap = document.querySelector('[data-reset-user-wrap]');
    const userSelect = document.querySelector('[data-reset-user]');
    const periodSelect = document.querySelector('[data-reset-period]');

    const syncUsers = () => {
        if (!restaurantSelect || !userSelect) {
            return;
        }

        const restaurantId = restaurantSelect.value;
        Array.from(userSelect.options).forEach((option, index) => {
            if (index === 0) {
                option.hidden = false;
                return;
            }

            option.hidden = restaurantId !== '' && option.getAttribute('data-restaurant-id') !== restaurantId;
            if (option.hidden && option.selected) {
                userSelect.value = '';
            }
        });
    };

    const syncScope = () => {
        if (!scopeSelect || !userWrap) {
            return;
        }
        userWrap.style.display = scopeSelect.value === 'user' ? '' : 'none';
    };

    const syncPeriod = () => {
        const mode = periodSelect ? periodSelect.value : 'day';
        document.querySelectorAll('[data-period-field]').forEach((node) => {
            node.style.display = node.getAttribute('data-period-field') === mode ? '' : 'none';
        });
    };

    restaurantSelect && restaurantSelect.addEventListener('change', syncUsers);
    scopeSelect && scopeSelect.addEventListener('change', syncScope);
    periodSelect && periodSelect.addEventListener('change', syncPeriod);

    syncUsers();
    syncScope();
    syncPeriod();
})();
</script>
