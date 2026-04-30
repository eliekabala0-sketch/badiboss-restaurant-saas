<section class="topbar">
    <div class="brand">
        <h1>Restaurants</h1>
        <p>Créez, ouvrez et pilotez chaque restaurant avec un écran simple: identité, accès, abonnement et statut réel.</p>
    </div>
</section>

<?php if (!empty($flash_success)): ?><div class="flash-ok"><?= e($flash_success) ?></div><?php endif; ?>
<?php if (!empty($flash_error)): ?><div class="flash-bad"><?= e($flash_error) ?></div><?php endif; ?>

<div class="section-stack">
    <details class="card fold-card" open>
        <summary>
            <div>
                <strong>Nouveau restaurant</strong>
                <div class="muted">Le lien d’accès est généré automatiquement. Les visuels se chargent par fichier.</div>
            </div>
            <span class="pill badge-gold">Création</span>
        </summary>
        <div class="fold-body">
            <form method="post" action="/super-admin/restaurants" enctype="multipart/form-data" class="split">
                <div>
                    <label>Nom commercial</label>
                    <input name="name" required>
                </div>
                <div>
                    <label>Restaurant ID / code</label>
                    <input name="restaurant_code" placeholder="Laissez vide pour une génération automatique">
                </div>
                <div>
                    <label>Slug public</label>
                    <input name="slug" placeholder="Laissez vide pour une génération automatique">
                </div>
                <div>
                    <label>Nom public</label>
                    <input name="public_name">
                </div>
                <div>
                    <label>Plan</label>
                    <select name="subscription_plan_id">
                        <?php foreach ($plans as $plan): ?>
                            <option value="<?= e((string) $plan['id']) ?>"><?= e($plan['name']) ?> (<?= e($plan['code']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>E-mail</label>
                    <input name="support_email" type="email">
                </div>
                <div>
                    <label>Téléphone</label>
                    <input name="phone">
                </div>
                <div>
                    <label>Pays</label>
                    <input name="country" value="République démocratique du Congo">
                </div>
                <div>
                    <label>Ville</label>
                    <input name="city">
                </div>
                <div>
                    <label>Adresse</label>
                    <input name="address_line">
                </div>
                <div>
                    <label>Sous-domaine</label>
                    <input name="web_subdomain" placeholder="Optionnel">
                </div>
                <div>
                    <label>Domaine personnalisé</label>
                    <input name="custom_domain" placeholder="Optionnel">
                </div>
                <div>
                    <label>Logo du restaurant</label>
                    <input name="logo" type="file" accept=".jpg,.jpeg,.png,.webp" data-file-preview-input="new-logo">
                </div>
                <div>
                    <label>Photo du restaurant</label>
                    <input name="photo" type="file" accept=".jpg,.jpeg,.png,.webp" data-file-preview-input="new-photo">
                </div>
                <div>
                    <label>Favicon</label>
                    <input name="favicon" type="file" accept=".jpg,.jpeg,.png,.webp" data-file-preview-input="new-favicon">
                </div>
                <div>
                    <label>Nom de l’application</label>
                    <input name="app_display_name">
                </div>
                <div>
                    <label>Nom court</label>
                    <input name="app_short_name">
                </div>
                <div style="grid-column:1 / -1;">
                    <label>Titre du portail</label>
                    <input name="portal_title">
                </div>
                <div style="grid-column:1 / -1;">
                    <label>Slogan du portail</label>
                    <input name="portal_tagline">
                </div>
                <div style="grid-column:1 / -1;">
                    <label>Texte d’accueil</label>
                    <textarea name="welcome_text"></textarea>
                </div>
                <div class="color-field">
                    <label>Couleur principale</label>
                    <div class="color-picker-row">
                        <input type="color" name="primary_color" value="<?= e($visual_defaults['default_primary_color'] ?? '#0F766E') ?>">
                        <span class="pill badge-neutral">Principale</span>
                    </div>
                </div>
                <div class="color-field">
                    <label>Couleur secondaire</label>
                    <div class="color-picker-row">
                        <input type="color" name="secondary_color" value="<?= e($visual_defaults['default_secondary_color'] ?? '#111827') ?>">
                        <span class="pill badge-neutral">Secondaire</span>
                    </div>
                </div>
                <div class="color-field">
                    <label>Couleur d’accent</label>
                    <div class="color-picker-row">
                        <input type="color" name="accent_color" value="<?= e($visual_defaults['default_accent_color'] ?? '#F59E0B') ?>">
                        <span class="pill badge-neutral">Accent</span>
                    </div>
                </div>
                <div>
                    <label><input type="checkbox" name="feature_pwa_enabled" value="1" checked style="width:auto;margin-right:8px;">Mode application web activé</label>
                </div>
                <div style="grid-column:1 / -1;">
                    <div class="media-preview-grid">
                        <div class="media-preview">
                            <strong>Logo</strong>
                            <img class="hidden" alt="Aperçu logo" data-file-preview-image="new-logo">
                            <small data-file-preview-name="new-logo">Aucun fichier choisi</small>
                            <div class="muted">Aperçu après sélection</div>
                        </div>
                        <div class="media-preview">
                            <strong>Photo</strong>
                            <img class="hidden" alt="Aperçu photo" data-file-preview-image="new-photo">
                            <small data-file-preview-name="new-photo">Aucun fichier choisi</small>
                            <div class="muted">Aperçu après sélection</div>
                        </div>
                        <div class="media-preview">
                            <strong>Favicon</strong>
                            <img class="hidden" alt="Aperçu favicon" data-file-preview-image="new-favicon">
                            <small data-file-preview-name="new-favicon">Aucun fichier choisi</small>
                            <div class="muted">Aperçu après sélection</div>
                        </div>
                    </div>
                </div>
                <div style="grid-column:1 / -1;">
                    <div class="link-box">
                        <strong>Lien d’accès du restaurant</strong>
                        <span class="muted">Il sera généré automatiquement après création à partir du slug du restaurant.</span>
                    </div>
                </div>
                <div style="grid-column:1 / -1;">
                    <button type="submit">Créer le restaurant</button>
                </div>
            </form>
        </div>
    </details>

    <details class="card fold-card" open>
        <summary>
            <div>
                <strong>Liste des restaurants</strong>
                <div class="muted">Fiche courte, statut réel et lien d’accès clair.</div>
            </div>
            <span class="pill badge-neutral"><?= e((string) count($restaurants)) ?> restaurant(s)</span>
        </summary>
        <div class="fold-body">
            <?php if ($restaurants === []): ?>
                <div class="compact-empty">Aucun restaurant enregistré pour le moment.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Restaurant</th>
                            <th>Apparence</th>
                            <th>Lien d’accès</th>
                            <th>Plan</th>
                            <th>Statut</th>
                            <th>Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($restaurants as $restaurant): ?>
                            <?php $generatedLink = restaurant_generated_access_url($restaurant); ?>
                            <tr>
                                <td><?= e((string) $restaurant['id']) ?></td>
                                <td>
                                    <strong><?= e($restaurant['name']) ?></strong><br>
                                    <span class="muted"><?= e($restaurant['restaurant_code'] ?? '-') ?> · <?= e($restaurant['slug']) ?></span>
                                </td>
                                <td>
                                    <?= e($restaurant['public_name'] ?? '-') ?><br>
                                    <span class="muted"><?= e($restaurant['web_subdomain'] ?? '-') ?></span>
                                </td>
                                <td>
                                    <div class="link-box">
                                        <a href="<?= e(restaurant_generated_access_path($restaurant)) ?>" target="_blank" rel="noopener noreferrer" data-copy-value="<?= e($generatedLink) ?>" id="restaurant-link-<?= e((string) $restaurant['id']) ?>"><?= e($generatedLink) ?></a>
                                        <button type="button" class="button-muted" data-copy-target="#restaurant-link-<?= e((string) $restaurant['id']) ?>">Copier le lien</button>
                                    </div>
                                </td>
                                <td><?= e($restaurant['plan_name'] ?? '-') ?></td>
                                <td>
                                    <span class="pill <?= ($restaurant['status'] ?? '') === 'active' ? 'badge-closed' : (($restaurant['status'] ?? '') === 'suspended' ? 'badge-progress' : 'badge-bad') ?>"><?= e(status_label($restaurant['status'])) ?></span>
                                </td>
                                <td><a href="/super-admin/restaurants/<?= e((string) $restaurant['id']) ?>">Ouvrir la fiche</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </details>
</div>
