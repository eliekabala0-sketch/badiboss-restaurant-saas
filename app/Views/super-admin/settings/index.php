<?php
declare(strict_types=1);

$clientRules = $settings['global_client_access_rules_json'] ?? [];
$automationRules = $settings['global_automation_rules_json'] ?? [];
$subscriptionRules = $settings['global_subscription_rules_json'] ?? [];
$alertRules = $settings['global_alert_rules_json'] ?? [];
$defaultRestaurantSettings = $settings['global_default_restaurant_settings_json'] ?? [];
$visualSettings = $settings['global_visual_settings_json'] ?? ($visual_defaults ?? []);

$roleAssignments = [];
foreach ($assignments as $assignment) {
    if (($assignment['effect'] ?? 'allow') !== 'allow') {
        continue;
    }

    $roleAssignments[(int) $assignment['role_id']][] = (int) $assignment['permission_id'];
}
?>

<section class="topbar">
    <div class="brand">
        <h1>Paramètres plateforme</h1>
        <p>Le super administrateur règle ici la plateforme avec des formulaires simples, sans format technique ni champs inutiles.</p>
    </div>
</section>

<?php if (!empty($flash_success)): ?><div class="flash-ok"><?= e($flash_success) ?></div><?php endif; ?>

<form method="post" action="/super-admin/settings" class="section-stack">
    <details class="card fold-card" open>
        <summary>
            <div>
                <strong>Plateforme</strong>
                <div class="muted">Règles publiques, délais automatiques et seuils d’alerte.</div>
            </div>
            <span class="pill badge-gold">Essentiel</span>
        </summary>
        <div class="fold-body split">
            <article class="card" style="padding:18px;">
                <h2 style="margin-top:0;">Accès client</h2>
                <div class="inline-list">
                    <label><input type="checkbox" name="public_menu_enabled" value="1" <?= !empty($clientRules['public_menu_enabled']) ? 'checked' : '' ?> style="width:auto;"> Menu public visible</label>
                    <label><input type="checkbox" name="public_restaurant_info_enabled" value="1" <?= !empty($clientRules['public_restaurant_info_enabled']) ? 'checked' : '' ?> style="width:auto;"> Informations publiques visibles</label>
                    <label><input type="checkbox" name="auth_required_for_order" value="1" <?= !empty($clientRules['auth_required_for_order']) ? 'checked' : '' ?> style="width:auto;"> Compte requis pour commander</label>
                    <label><input type="checkbox" name="auth_required_for_reservation" value="1" <?= !empty($clientRules['auth_required_for_reservation']) ? 'checked' : '' ?> style="width:auto;"> Compte requis pour réserver</label>
                </div>
            </article>

            <article class="card" style="padding:18px;">
                <h2 style="margin-top:0;">Délais et alertes</h2>
                <label>Clôture automatique après (heures)</label>
                <input type="number" min="1" name="sale_auto_after_hours" value="<?= e((string) ($automationRules['sale_auto_after_hours'] ?? 24)) ?>">
                <label>Alerte incidents serveur</label>
                <input type="number" min="1" name="server_incident_threshold" value="<?= e((string) ($alertRules['server_incident_threshold'] ?? 3)) ?>">
                <label>Alerte pertes cuisine</label>
                <input type="number" min="1" name="kitchen_loss_threshold" value="<?= e((string) ($alertRules['kitchen_loss_threshold'] ?? 2)) ?>">
                <label>Alerte incohérences répétées</label>
                <input type="number" min="1" name="repeated_inconsistency_threshold" value="<?= e((string) ($alertRules['repeated_inconsistency_threshold'] ?? 2)) ?>">
                <label>Alerte retours fréquents</label>
                <input type="number" min="1" name="frequent_return_threshold" value="<?= e((string) ($alertRules['frequent_return_threshold'] ?? 3)) ?>">
            </article>
        </div>
    </details>

    <details class="card fold-card" open>
        <summary>
            <div>
                <strong>Abonnements</strong>
                <div class="muted">Règles globales et formules lisibles, avec activation ou désactivation simple.</div>
            </div>
            <span class="pill badge-ready"><?= e((string) count($subscription_plans)) ?> plan(s)</span>
        </summary>
        <div class="fold-body section-stack">
            <article class="card" style="padding:18px;">
                <h2 style="margin-top:0;">Règles d’abonnement</h2>
                <div class="split">
                    <div>
                        <label>Jours de grâce</label>
                        <input type="number" min="0" name="subscription_grace_days" value="<?= e((string) ($subscriptionRules['subscription_grace_days'] ?? 2)) ?>">
                    </div>
                    <div>
                        <label>Jours d’alerte avant échéance</label>
                        <input type="number" min="0" name="subscription_warning_days" value="<?= e((string) ($subscriptionRules['subscription_warning_days'] ?? 5)) ?>">
                    </div>
                    <div>
                        <label>Durée par défaut (jours)</label>
                        <input type="number" min="1" name="default_duration_days" value="<?= e((string) ($subscriptionRules['default_duration_days'] ?? 30)) ?>">
                    </div>
                </div>
            </article>

            <article class="card" style="padding:18px;">
                <div class="toolbar">
                    <div>
                        <h2 style="margin:0;">Types d’abonnement</h2>
                        <p class="muted" style="margin:6px 0 0;">Chaque formule reste modifiable sans code.</p>
                    </div>
                </div>

                <div class="section-stack">
                    <?php foreach ($subscription_plans as $plan): ?>
                        <div class="role-panel">
                            <div class="toolbar">
                                <strong><?= e($plan['name']) ?></strong>
                                <span class="pill <?= ($plan['status'] ?? '') === 'active' ? 'badge-closed' : 'badge-off' ?>"><?= e(status_label($plan['status'] ?? null)) ?></span>
                            </div>
                            <div class="split">
                                <div>
                                    <label>Nom</label>
                                    <input name="plan_name[<?= e((string) $plan['id']) ?>]" value="<?= e($plan['name']) ?>">
                                </div>
                                <div>
                                    <label>Code interne simple</label>
                                    <input name="plan_code[<?= e((string) $plan['id']) ?>]" value="<?= e($plan['code']) ?>">
                                </div>
                                <div style="grid-column:1 / -1;">
                                    <label>Description</label>
                                    <textarea name="plan_description[<?= e((string) $plan['id']) ?>]"><?= e($plan['description']) ?></textarea>
                                </div>
                                <div>
                                    <label>Prix mensuel</label>
                                    <input name="plan_monthly_price[<?= e((string) $plan['id']) ?>]" value="<?= e((string) $plan['monthly_price']) ?>">
                                </div>
                                <div>
                                    <label>Prix annuel</label>
                                    <input name="plan_yearly_price[<?= e((string) $plan['id']) ?>]" value="<?= e((string) $plan['yearly_price']) ?>">
                                </div>
                                <div>
                                    <label>Utilisateurs max</label>
                                    <input type="number" min="1" name="plan_max_users[<?= e((string) $plan['id']) ?>]" value="<?= e((string) $plan['max_users']) ?>">
                                </div>
                                <div>
                                    <label>Restaurants max</label>
                                    <input type="number" min="1" name="plan_max_restaurants[<?= e((string) $plan['id']) ?>]" value="<?= e((string) $plan['max_restaurants']) ?>">
                                </div>
                                <div>
                                    <label>État</label>
                                    <select name="plan_status[<?= e((string) $plan['id']) ?>]">
                                        <option value="active" <?= ($plan['status'] ?? '') === 'active' ? 'selected' : '' ?>>Activer</option>
                                        <option value="inactive" <?= ($plan['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Désactiver</option>
                                        <option value="archived" <?= ($plan['status'] ?? '') === 'archived' ? 'selected' : '' ?>>Archiver</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <details class="card fold-card" style="margin-top:18px;">
                    <summary>
                        <div>
                            <strong>Ajouter un type d’abonnement</strong>
                            <div class="muted">Créer une nouvelle formule sans passer par le code.</div>
                        </div>
                        <span class="pill badge-neutral">Nouveau</span>
                    </summary>
                    <div class="fold-body split">
                        <div>
                            <label>Nom</label>
                            <input name="new_plan_name">
                        </div>
                        <div>
                            <label>Code interne simple</label>
                            <input name="new_plan_code">
                        </div>
                        <div style="grid-column:1 / -1;">
                            <label>Description</label>
                            <textarea name="new_plan_description"></textarea>
                        </div>
                        <div>
                            <label>Prix mensuel</label>
                            <input name="new_plan_monthly_price" value="0.00">
                        </div>
                        <div>
                            <label>Prix annuel</label>
                            <input name="new_plan_yearly_price" value="">
                        </div>
                        <div>
                            <label>Utilisateurs max</label>
                            <input type="number" min="1" name="new_plan_max_users" value="10">
                        </div>
                        <div>
                            <label>Restaurants max</label>
                            <input type="number" min="1" name="new_plan_max_restaurants" value="1">
                        </div>
                        <div>
                            <label>État</label>
                            <select name="new_plan_status">
                                <option value="active">Activer</option>
                                <option value="inactive">Désactiver</option>
                            </select>
                        </div>
                    </div>
                </details>
            </article>
        </div>
    </details>

    <details class="card fold-card" open>
        <summary>
            <div>
                <strong>Apparence</strong>
                <div class="muted">Couleurs par panneau visuel, style d’icône et modules activés.</div>
            </div>
            <span class="pill badge-progress">Visuel</span>
        </summary>
        <div class="fold-body split">
            <article class="card" style="padding:18px;">
                <h2 style="margin-top:0;">Couleurs par défaut</h2>
                <div class="color-field">
                    <label>Couleur principale</label>
                    <div class="color-picker-row">
                        <input type="color" name="default_primary_color" value="<?= e($visualSettings['default_primary_color'] ?? '#0F766E') ?>">
                        <span class="pill badge-neutral"><?= e($visualSettings['default_primary_color'] ?? '#0F766E') ?></span>
                    </div>
                </div>
                <div class="color-field">
                    <label>Couleur secondaire</label>
                    <div class="color-picker-row">
                        <input type="color" name="default_secondary_color" value="<?= e($visualSettings['default_secondary_color'] ?? '#111827') ?>">
                        <span class="pill badge-neutral"><?= e($visualSettings['default_secondary_color'] ?? '#111827') ?></span>
                    </div>
                </div>
                <div class="color-field">
                    <label>Couleur d’accent</label>
                    <div class="color-picker-row">
                        <input type="color" name="default_accent_color" value="<?= e($visualSettings['default_accent_color'] ?? '#F59E0B') ?>">
                        <span class="pill badge-neutral"><?= e($visualSettings['default_accent_color'] ?? '#F59E0B') ?></span>
                    </div>
                </div>
                <label>Style d’icône</label>
                <select name="default_icon_style">
                    <option value="standard" <?= ($visualSettings['default_icon_style'] ?? 'standard') === 'standard' ? 'selected' : '' ?>>Standard</option>
                    <option value="minimal" <?= ($visualSettings['default_icon_style'] ?? '') === 'minimal' ? 'selected' : '' ?>>Minimal</option>
                    <option value="bold" <?= ($visualSettings['default_icon_style'] ?? '') === 'bold' ? 'selected' : '' ?>>Marqué</option>
                </select>
                <div class="swatch-row">
                    <div class="swatch-card">
                        <div class="color-chip" style="background:<?= e($visualSettings['default_primary_color'] ?? '#0F766E') ?>"></div>
                        <small>Principale</small>
                    </div>
                    <div class="swatch-card">
                        <div class="color-chip" style="background:<?= e($visualSettings['default_secondary_color'] ?? '#111827') ?>"></div>
                        <small>Secondaire</small>
                    </div>
                    <div class="swatch-card">
                        <div class="color-chip" style="background:<?= e($visualSettings['default_accent_color'] ?? '#F59E0B') ?>"></div>
                        <small>Accent</small>
                    </div>
                </div>
            </article>

            <article class="card" style="padding:18px;">
                <h2 style="margin-top:0;">Modules visibles</h2>
                <div class="inline-list">
                    <?php
                    $availableModules = ['menu', 'stock', 'kitchen', 'sales', 'reports', 'roles', 'branding'];
                    foreach ($availableModules as $moduleCode):
                    ?>
                        <label><input type="checkbox" name="module_catalog[]" value="<?= e($moduleCode) ?>" <?= in_array($moduleCode, $settings['global_module_catalog_json'] ?? [], true) ? 'checked' : '' ?> style="width:auto;"> <?= e(ucfirst($moduleCode)) ?></label>
                    <?php endforeach; ?>
                </div>
            </article>
        </div>
    </details>

    <details class="card fold-card">
        <summary>
            <div>
                <strong>Sécurité et règles métier</strong>
                <div class="muted">États, qualifications, imputations, incidents et réglages par défaut des restaurants.</div>
            </div>
            <span class="pill badge-neutral">Réglages</span>
        </summary>
        <div class="fold-body section-stack">
            <article class="card" style="padding:18px;">
                <h2 style="margin-top:0;">États métier</h2>
                <div class="repeat-list" data-repeat-list="validation-states">
                    <?php foreach ($settings['global_validation_states_json'] ?? [] as $index => $value): ?>
                        <div class="repeat-item" <?= $index === 0 ? 'data-repeat-template="1"' : '' ?>>
                            <input name="validation_states[]" value="<?= e($value) ?>">
                            <button type="button" class="button-muted" data-repeat-remove="validation-states">Retirer</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="button-muted" data-repeat-add="validation-states">Ajouter un état</button>
            </article>

            <article class="card" style="padding:18px;">
                <h2 style="margin-top:0;">Qualifications finales</h2>
                <div class="repeat-list" data-repeat-list="final-qualifications">
                    <?php foreach ($settings['global_final_qualifications_json'] ?? [] as $index => $value): ?>
                        <div class="repeat-item" <?= $index === 0 ? 'data-repeat-template="1"' : '' ?>>
                            <input name="final_qualifications[]" value="<?= e($value) ?>">
                            <button type="button" class="button-muted" data-repeat-remove="final-qualifications">Retirer</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="button-muted" data-repeat-add="final-qualifications">Ajouter une qualification</button>
            </article>

            <article class="card" style="padding:18px;">
                <h2 style="margin-top:0;">Imputations possibles</h2>
                <div class="repeat-list" data-repeat-list="responsibility-targets">
                    <?php foreach ($settings['global_responsibility_targets_json'] ?? [] as $index => $value): ?>
                        <div class="repeat-item" <?= $index === 0 ? 'data-repeat-template="1"' : '' ?>>
                            <input name="responsibility_targets[]" value="<?= e($value) ?>">
                            <button type="button" class="button-muted" data-repeat-remove="responsibility-targets">Retirer</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="button-muted" data-repeat-add="responsibility-targets">Ajouter une imputation</button>
            </article>

            <article class="card" style="padding:18px;">
                <h2 style="margin-top:0;">Types d’incident</h2>
                <div class="repeat-list" data-repeat-list="incident-types">
                    <?php foreach ($settings['global_incident_types_json'] ?? [] as $index => $value): ?>
                        <div class="repeat-item" <?= $index === 0 ? 'data-repeat-template="1"' : '' ?>>
                            <input name="incident_types[]" value="<?= e($value) ?>">
                            <button type="button" class="button-muted" data-repeat-remove="incident-types">Retirer</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="button-muted" data-repeat-add="incident-types">Ajouter un type</button>
            </article>

            <article class="card" style="padding:18px;">
                <h2 style="margin-top:0;">Réglages par défaut pour les restaurants</h2>
                <div class="split">
                    <div>
                        <label>Fenêtre de retour (heures)</label>
                        <input type="number" min="1" name="restaurant_return_window_hours" value="<?= e((string) ($defaultRestaurantSettings['restaurant_return_window_hours'] ?? 24)) ?>">
                    </div>
                    <div>
                        <label>Clôture serveur automatique (minutes)</label>
                        <input type="number" min="15" name="restaurant_server_auto_close_minutes" value="<?= e((string) ($defaultRestaurantSettings['restaurant_server_auto_close_minutes'] ?? 90)) ?>">
                    </div>
                </div>
                <div class="inline-list">
                    <label><input type="checkbox" name="restaurant_loss_validation_required" value="1" <?= !empty($defaultRestaurantSettings['restaurant_loss_validation_required']) ? 'checked' : '' ?> style="width:auto;"> Validation des pertes requise</label>
                    <label><input type="checkbox" name="restaurant_public_menu_enabled" value="1" <?= !empty($defaultRestaurantSettings['restaurant_public_menu_enabled']) ? 'checked' : '' ?> style="width:auto;"> Menu public activé</label>
                    <label><input type="checkbox" name="restaurant_public_order_requires_auth" value="1" <?= !empty($defaultRestaurantSettings['restaurant_public_order_requires_auth']) ? 'checked' : '' ?> style="width:auto;"> Compte requis pour commande</label>
                    <label><input type="checkbox" name="restaurant_public_reservation_requires_auth" value="1" <?= !empty($defaultRestaurantSettings['restaurant_public_reservation_requires_auth']) ? 'checked' : '' ?> style="width:auto;"> Compte requis pour réservation</label>
                </div>
            </article>
        </div>
    </details>

    <details class="card fold-card">
        <summary>
            <div>
                <strong>Autorisations</strong>
                <div class="muted">Vue claire par rôle pour vérifier rapidement les droits actuels.</div>
            </div>
            <span class="pill badge-neutral"><?= e((string) count($roles)) ?> rôle(s)</span>
        </summary>
        <div class="fold-body">
            <div class="toolbar">
                <p class="muted" style="margin:0;">Les droits détaillés restent modifiables dans la page dédiée, sans manipulation technique.</p>
            </div>
            <div class="section-stack">
                <?php foreach ($roles as $role): ?>
                    <div class="role-panel">
                        <div class="toolbar">
                            <div>
                                <strong><?= e($role['name']) ?></strong>
                                <div class="muted"><?= e($role['code']) ?> · <?= ($role['scope'] ?? '') === 'system' ? 'Rôle système' : 'Rôle restaurant' ?></div>
                            </div>
                            <span class="pill badge-neutral"><?= e((string) count($roleAssignments[(int) $role['id']] ?? [])) ?> droit(s)</span>
                        </div>
                        <div class="inline-list">
                            <?php foreach ($permissions as $permission): ?>
                                <?php if (!in_array((int) $permission['id'], $roleAssignments[(int) $role['id']] ?? [], true)) {
                                    continue;
                                } ?>
                                <label><?= e($permission['code']) ?></label>
                            <?php endforeach; ?>
                            <?php if (($roleAssignments[(int) $role['id']] ?? []) === []): ?>
                                <span class="muted">Aucun droit explicite défini.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </details>

    <div class="toolbar" style="margin-top:4px;">
        <div class="toolbar-actions">
            <button type="submit">Enregistrer</button>
        </div>
    </div>
</form>
