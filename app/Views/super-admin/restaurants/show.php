<?php
declare(strict_types=1);

$subscriptionTimezone = safe_timezone($subscription['timezone'] ?? ($restaurant['settings']['restaurant_reports_timezone'] ?? $restaurant['timezone'] ?? null));
$generatedLink = restaurant_generated_access_url($restaurant);
$logoPreview = restaurant_media_url_or_default($restaurant['logo_url'] ?? null, 'logo');
$photoPreview = restaurant_media_url_or_default($restaurant['cover_image_url'] ?? null, 'photo');
$faviconPreview = restaurant_media_url_or_default($restaurant['favicon_url'] ?? null, 'favicon');
$supervisionLinks = [
    'Tableau de bord' => '/owner?restaurant_id=' . rawurlencode((string) $restaurant['id']),
    'Ventes' => '/ventes?restaurant_id=' . rawurlencode((string) $restaurant['id']),
    'Stock' => '/stock?restaurant_id=' . rawurlencode((string) $restaurant['id']),
    'Cuisine' => '/cuisine?restaurant_id=' . rawurlencode((string) $restaurant['id']),
    'Caisse' => '/caisse?restaurant_id=' . rawurlencode((string) $restaurant['id']),
    'Rapport' => '/rapport?restaurant_id=' . rawurlencode((string) $restaurant['id']),
    'Discipline' => '/owner/discipline?restaurant_id=' . rawurlencode((string) $restaurant['id']),
    'Paie' => '/owner/paie/preparer?restaurant_id=' . rawurlencode((string) $restaurant['id']),
    'Audit filtré' => '/super-admin/audit?restaurant_id=' . rawurlencode((string) $restaurant['id']),
];
$activeModules = array_values(array_filter(
    (array) ($restaurant['modules'] ?? []),
    static fn (array $module): bool => (int) ($module['is_enabled'] ?? 0) === 1
));
$recentAudits = (array) ($restaurant['recent_audits'] ?? []);
$canDeleteTestRestaurant = (bool) ($can_delete_test_restaurant ?? false);
?>

<section class="topbar">
    <div class="brand">
        <h1><?= e($restaurant['public_name'] ?? $restaurant['name']) ?></h1>
        <p>Fiche complète du restaurant avec accès généré automatiquement, visuels par upload, couleurs visuelles et statut réel.</p>
    </div>
    <div class="actions"><a href="/super-admin/restaurants">Retour à la liste</a></div>
</section>

<?php if (!empty($flash_success)): ?><div class="flash-ok"><?= e($flash_success) ?></div><?php endif; ?>
<?php if (!empty($flash_error)): ?><div class="flash-bad"><?= e($flash_error) ?></div><?php endif; ?>

<?php if (restaurant_status_blocks_operations($restaurant['status'] ?? null)): ?>
    <section class="status-banner status-<?= e(restaurant_status_severity($restaurant['status'] ?? null)) ?>">
        <div>
            <strong><?= e(status_label($restaurant['status'] ?? null)) ?></strong>
            <div><?= e(restaurant_status_message($restaurant['status'] ?? null) ?? 'Accès limité.') ?></div>
        </div>
        <span class="pill <?= restaurant_status_severity($restaurant['status'] ?? null) === 'danger' ? 'badge-bad' : 'badge-progress' ?>">
            Restaurant #<?= e((string) $restaurant['id']) ?>
        </span>
    </section>
<?php endif; ?>

<section class="grid stats">
    <article class="card stat"><span>Code</span><strong><?= e($restaurant['restaurant_code'] ?? '-') ?></strong></article>
    <article class="card stat"><span>Statut</span><strong><?= e(status_label($restaurant['status'])) ?></strong></article>
    <article class="card stat"><span>Abonnement</span><strong><?= e(subscription_status_label($subscription['status'] ?? null)) ?></strong></article>
    <article class="card stat"><span>Paiement</span><strong><?= e(subscription_payment_label($subscription['payment_status'] ?? null)) ?></strong></article>
</section>

<section class="card" style="padding:22px; margin-bottom:24px;">
    <div class="topbar" style="margin-bottom:16px;">
        <div>
            <h2 style="margin:0;">Supervision express</h2>
            <p class="muted" style="margin:8px 0 0;">Ouverture rapide des modules BELIEVE avec le bon ciblage restaurant, visibilité immédiate sur l’état réel et les dernières traces utiles.</p>
        </div>
        <span class="pill badge-ready"><?= !empty($subscription['is_operational']) ? 'Opérationnel' : 'À surveiller' ?></span>
    </div>

    <div class="grid stats" style="margin-bottom:18px;">
        <article class="card stat"><span>Utilisateurs</span><strong><?= e((string) count((array) ($restaurant['users'] ?? []))) ?></strong></article>
        <article class="card stat"><span>Modules actifs</span><strong><?= e((string) count($activeModules)) ?></strong></article>
        <article class="card stat"><span>Catégories menu</span><strong><?= e((string) count((array) ($restaurant['categories'] ?? []))) ?></strong></article>
        <article class="card stat"><span>Articles menu</span><strong><?= e((string) count((array) ($restaurant['items'] ?? []))) ?></strong></article>
    </div>

    <div class="split">
        <div class="link-box">
            <strong>Accès rapides</strong>
            <div class="toolbar-actions">
                <?php foreach ($supervisionLinks as $label => $href): ?>
                    <a href="<?= e($href) ?>" target="_blank" rel="noopener noreferrer" class="button-muted"><?= e($label) ?></a>
                <?php endforeach; ?>
            </div>
            <span class="muted">Le super admin garde désormais le bon restaurant cible pour les actions de suivi et de dépannage.</span>
        </div>

        <div class="link-box">
            <strong>État utile</strong>
            <span>Restaurant : <?= e(status_label($restaurant['status'] ?? null)) ?></span>
            <span>Abonnement : <?= e(subscription_status_label($subscription['status'] ?? null)) ?> · <?= e(subscription_payment_label($subscription['payment_status'] ?? null)) ?></span>
            <span>Message : <?= e((string) ($subscription['message'] ?? 'Aucun message')) ?></span>
            <span>Jour référence : <?= e((string) ($subscription['today'] ?? '-')) ?></span>
        </div>
    </div>

    <div class="link-box" style="margin-top:18px;">
        <strong>Modules actifs</strong>
        <?php if ($activeModules === []): ?>
            <span class="muted">Aucun module actif déclaré.</span>
        <?php else: ?>
            <div class="toolbar-actions">
                <?php foreach ($activeModules as $module): ?>
                    <span class="pill badge-neutral"><?= e((string) ($module['module_code'] ?? 'module')) ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="link-box" style="margin-top:18px;">
        <strong>Dernières traces d’audit</strong>
        <?php if ($recentAudits === []): ?>
            <span class="muted">Aucune trace récente disponible pour ce restaurant.</span>
        <?php else: ?>
            <?php foreach ($recentAudits as $audit): ?>
                <div>
                    <strong><?= e((string) ($audit['module_name'] ?? 'module')) ?> / <?= e((string) ($audit['action_name'] ?? 'action')) ?></strong>
                    <div class="muted"><?= e((string) ($audit['actor_name'] ?? 'Système')) ?> · <?= e(restaurant_role_label((string) ($audit['actor_role_code'] ?? ''))) ?> · <?= e(format_date_fr($audit['created_at'] ?? null, $subscriptionTimezone)) ?></div>
                    <div><?= e((string) ($audit['justification'] ?? 'Sans justification détaillée.')) ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<div class="section-stack">
    <details class="card fold-card" open>
        <summary>
            <div>
                <strong>Accès du restaurant</strong>
                <div class="muted">Lien web généré automatiquement, sans saisie manuelle.</div>
            </div>
            <span class="pill badge-gold">Automatique</span>
        </summary>
        <div class="fold-body">
            <div class="link-box">
                <strong>Lien d’accès du restaurant</strong>
                <a href="<?= e(restaurant_generated_access_path($restaurant)) ?>" target="_blank" rel="noopener noreferrer" id="restaurant-generated-link" data-copy-value="<?= e($generatedLink) ?>"><?= e($generatedLink) ?></a>
                <div class="toolbar-actions">
                    <button type="button" class="button-muted" data-copy-target="#restaurant-generated-link">Copier le lien</button>
                    <a href="<?= e(restaurant_generated_access_path($restaurant)) ?>" target="_blank" rel="noopener noreferrer" class="button-muted">Ouvrir le lien</a>
                </div>
                <span class="muted">Le lien est construit à partir du slug du restaurant. L’ancien lien enregistré reste conservé pour compatibilité interne.</span>
            </div>
        </div>
    </details>

    <details class="card fold-card" open>
        <summary>
            <div>
                <strong>Informations du restaurant</strong>
                <div class="muted">Données principales, sans lien technique à saisir.</div>
            </div>
            <span class="pill badge-neutral">Identité</span>
        </summary>
        <div class="fold-body">
            <form method="post" action="/super-admin/restaurants/<?= e((string) $restaurant['id']) ?>/update" class="split">
                <div><label>Nom commercial</label><input name="name" value="<?= e($restaurant['name']) ?>"></div>
                <div><label>Code restaurant</label><input name="restaurant_code" value="<?= e($restaurant['restaurant_code']) ?>"></div>
                <div><label>Slug public</label><input name="slug" value="<?= e($restaurant['slug']) ?>"></div>
                <div><label>Raison sociale</label><input name="legal_name" value="<?= e($restaurant['legal_name']) ?>"></div>
                <div><label>E-mail</label><input name="support_email" value="<?= e($restaurant['support_email']) ?>"></div>
                <div><label>Téléphone</label><input name="phone" value="<?= e($restaurant['phone']) ?>"></div>
                <div><label>Pays</label><input name="country" value="<?= e($restaurant['country']) ?>"></div>
                <div><label>Ville</label><input name="city" value="<?= e($restaurant['city']) ?>"></div>
                <div style="grid-column:1 / -1;"><label>Adresse</label><input name="address_line" value="<?= e($restaurant['address_line']) ?>"></div>
                <div><label>Fuseau horaire</label><input name="timezone" value="<?= e($restaurant['timezone']) ?>"></div>
                <div><label>Devise</label><input name="currency_code" value="<?= e($restaurant['currency_code']) ?>"></div>
                <div><label>Plan</label><select name="subscription_plan_id"><?php foreach ($plans as $plan): ?><option value="<?= e((string) $plan['id']) ?>" <?= (int) $plan['id'] === (int) $restaurant['subscription_plan_id'] ? 'selected' : '' ?>><?= e($plan['name']) ?></option><?php endforeach; ?></select></div>
                <div style="grid-column:1 / -1;"><button type="submit">Enregistrer</button></div>
            </form>
        </div>
    </details>

    <details class="card fold-card" open>
        <summary>
            <div>
                <strong>Apparence</strong>
                <div class="muted">Logo, photo, favicon, couleurs et texte d’accueil en mode visuel.</div>
            </div>
            <span class="pill badge-ready">Branding</span>
        </summary>
        <div class="fold-body">
            <div class="media-preview-grid">
                <div class="media-preview">
                    <strong>Logo actuel</strong>
                    <img src="<?= e($logoPreview) ?>" alt="Logo actuel" width="160" height="110" decoding="async" data-file-preview-image="restaurant-logo">
                    <small data-file-preview-name="restaurant-logo"><?= e($restaurant['logo_url'] ?? 'Visuel par defaut') ?></small>
                    <div class="muted">Compatible avec les anciennes URL déjà enregistrées.</div>
                </div>
                <div class="media-preview">
                    <strong>Photo actuelle</strong>
                    <img src="<?= e($photoPreview) ?>" alt="Photo actuelle" width="160" height="110" loading="lazy" decoding="async" data-file-preview-image="restaurant-photo">
                    <small data-file-preview-name="restaurant-photo"><?= e($restaurant['cover_image_url'] ?? 'Visuel par defaut') ?></small>
                    <div class="muted">Aperçu mis à jour après upload.</div>
                </div>
                <div class="media-preview">
                    <strong>Favicon actuel</strong>
                    <img src="<?= e($faviconPreview) ?>" alt="Favicon actuel" width="160" height="110" loading="lazy" decoding="async" data-file-preview-image="restaurant-favicon">
                    <small data-file-preview-name="restaurant-favicon"><?= e($restaurant['favicon_url'] ?? 'Visuel par defaut') ?></small>
                    <div class="muted">Aperçu mis à jour après upload.</div>
                </div>
            </div>

            <form method="post" action="/super-admin/restaurants/<?= e((string) $restaurant['id']) ?>/branding" enctype="multipart/form-data" class="split">
                <div><label>Nom public</label><input name="public_name" value="<?= e($restaurant['public_name']) ?>"></div>
                <div><label>Nom de l’application</label><input name="app_display_name" value="<?= e($restaurant['app_display_name']) ?>"></div>
                <div><label>Nom court</label><input name="app_short_name" value="<?= e($restaurant['app_short_name']) ?>"></div>
                <div><label>Sous-domaine</label><input name="web_subdomain" value="<?= e($restaurant['web_subdomain']) ?>"></div>
                <div><label>Domaine personnalisé</label><input name="custom_domain" value="<?= e($restaurant['custom_domain']) ?>"></div>
                <div><label>Titre du portail</label><input name="portal_title" value="<?= e($restaurant['portal_title']) ?>"></div>
                <div style="grid-column:1 / -1;"><label>Slogan du portail</label><input name="portal_tagline" value="<?= e($restaurant['portal_tagline']) ?>"></div>
                <div style="grid-column:1 / -1;"><label>Texte d’accueil</label><textarea name="welcome_text"><?= e($restaurant['welcome_text']) ?></textarea></div>
                <div><label>Logo du restaurant</label><input name="logo" type="file" accept=".jpg,.jpeg,.png,.webp" data-file-preview-input="restaurant-logo"></div>
                <div><label>Photo du restaurant</label><input name="photo" type="file" accept=".jpg,.jpeg,.png,.webp" data-file-preview-input="restaurant-photo"></div>
                <div><label>Favicon</label><input name="favicon" type="file" accept=".jpg,.jpeg,.png,.webp" data-file-preview-input="restaurant-favicon"></div>
                <div class="color-field">
                    <label>Couleur principale</label>
                    <div class="color-picker-row">
                        <input type="color" name="primary_color" value="<?= e($restaurant['primary_color'] ?: '#0F766E') ?>">
                        <span class="pill badge-neutral"><?= e($restaurant['primary_color'] ?: '#0F766E') ?></span>
                    </div>
                </div>
                <div class="color-field">
                    <label>Couleur secondaire</label>
                    <div class="color-picker-row">
                        <input type="color" name="secondary_color" value="<?= e($restaurant['secondary_color'] ?: '#111827') ?>">
                        <span class="pill badge-neutral"><?= e($restaurant['secondary_color'] ?: '#111827') ?></span>
                    </div>
                </div>
                <div class="color-field">
                    <label>Couleur d’accent</label>
                    <div class="color-picker-row">
                        <input type="color" name="accent_color" value="<?= e($restaurant['accent_color'] ?: '#F59E0B') ?>">
                        <span class="pill badge-neutral"><?= e($restaurant['accent_color'] ?: '#F59E0B') ?></span>
                    </div>
                </div>
                <div><label><input type="checkbox" name="pwa_enabled" value="1" <?= (($restaurant['settings']['restaurant_feature_pwa_enabled'] ?? '0') === '1') ? 'checked' : '' ?> style="width:auto;margin-right:8px;">Mode application web activé</label></div>
                <div style="grid-column:1 / -1;"><button type="submit">Enregistrer l’apparence</button></div>
            </form>
        </div>
    </details>

    <details class="card fold-card">
        <summary>
            <div>
                <strong>Paramètres du restaurant</strong>
                <div class="muted">Réglages opérationnels lisibles, sans JSON.</div>
            </div>
            <span class="pill badge-neutral">Réglages</span>
        </summary>
        <div class="fold-body">
            <form method="post" action="/super-admin/restaurants/<?= e((string) $restaurant['id']) ?>/settings" class="split">
                <div><label>Fenêtre de retour (heures)</label><input type="number" min="1" name="restaurant_return_window_hours" value="<?= e($restaurant['settings']['restaurant_return_window_hours'] ?? '24') ?>"></div>
                <div><label>Clôture automatique serveur (minutes)</label><input type="number" min="15" name="restaurant_server_auto_close_minutes" value="<?= e($restaurant['settings']['restaurant_server_auto_close_minutes'] ?? '90') ?>"></div>
                <div><label>Fuseau des rapports</label><input name="restaurant_reports_timezone" value="<?= e($restaurant['settings']['restaurant_reports_timezone'] ?? 'Africa/Kinshasa') ?>"></div>
                <div><label><input type="checkbox" name="restaurant_loss_validation_required" value="1" <?= (($restaurant['settings']['restaurant_loss_validation_required'] ?? '0') === '1') ? 'checked' : '' ?> style="width:auto;margin-right:8px;">Validation des pertes requise</label></div>
                <div><label><input type="checkbox" name="restaurant_feature_pwa_enabled" value="1" <?= (($restaurant['settings']['restaurant_feature_pwa_enabled'] ?? '0') === '1') ? 'checked' : '' ?> style="width:auto;margin-right:8px;">Mode application web activé</label></div>
                <div style="grid-column:1 / -1;"><label>Texte d’accueil paramétré</label><textarea name="restaurant_welcome_text"><?= e($restaurant['settings']['restaurant_welcome_text'] ?? '') ?></textarea></div>
                <div style="grid-column:1 / -1;"><button type="submit">Enregistrer les paramètres</button></div>
            </form>
        </div>
    </details>

    <details class="card fold-card" open>
        <summary>
            <div>
                <strong>Suspension et bannissement</strong>
                <div class="muted">Action réelle, auditée, avec effet immédiat sur les accès métier.</div>
            </div>
            <span class="pill <?= ($restaurant['status'] ?? '') === 'active' ? 'badge-closed' : (($restaurant['status'] ?? '') === 'suspended' ? 'badge-progress' : 'badge-bad') ?>"><?= e(status_label($restaurant['status'])) ?></span>
        </summary>
        <div class="fold-body section-stack">
            <div class="link-box">
                <strong>Statut actuel</strong>
                <span><?= e(status_label($restaurant['status'])) ?></span>
                <span class="muted"><?= e(restaurant_status_message($restaurant['status'] ?? null) ?? 'Le restaurant fonctionne normalement.') ?></span>
            </div>
            <div class="toolbar-actions">
                <form method="post" action="/super-admin/restaurants/<?= e((string) $restaurant['id']) ?>/status">
                    <input type="hidden" name="status" value="active">
                    <button type="submit">Réactiver</button>
                </form>
                <form method="post" action="/super-admin/restaurants/<?= e((string) $restaurant['id']) ?>/status">
                    <input type="hidden" name="status" value="suspended">
                    <button type="submit" class="button-muted">Suspendre</button>
                </form>
                <form method="post" action="/super-admin/restaurants/<?= e((string) $restaurant['id']) ?>/status">
                    <input type="hidden" name="status" value="banned">
                    <button type="submit">Bannir</button>
                </form>
                <form method="post" action="/super-admin/restaurants/<?= e((string) $restaurant['id']) ?>/status">
                    <input type="hidden" name="status" value="archived">
                    <button type="submit" class="button-muted">Archiver</button>
                </form>
            </div>
        </div>
    </details>

    <details class="card fold-card">
        <summary>
            <div>
                <strong>Archive / suppression test</strong>
                <div class="muted">Suppression definitive reservee aux restaurants test ou sandbox.</div>
            </div>
            <span class="pill <?= $canDeleteTestRestaurant ? 'badge-bad' : 'badge-neutral' ?>"><?= $canDeleteTestRestaurant ? 'Test uniquement' : 'Restaurant reel protege' ?></span>
        </summary>
        <div class="fold-body section-stack">
            <div class="link-box">
                <strong>Archive</strong>
                <span class="muted">L archivage conserve les donnees et bloque seulement l exploitation courante.</span>
                <form method="post" action="/super-admin/restaurants/<?= e((string) $restaurant['id']) ?>/status">
                    <input type="hidden" name="status" value="archived">
                    <button type="submit" class="button-muted">Archiver</button>
                </form>
            </div>
            <?php if ($canDeleteTestRestaurant): ?>
                <div class="link-box" style="border-color:rgba(185,28,28,.35);">
                    <strong>Supprimer definitivement le restaurant test</strong>
                    <span class="muted">Cette action supprime le restaurant test, ses utilisateurs test, fichiers test et donnees liees. Aucun restaurant client reel n est autorise ici.</span>
                    <form method="post" action="/super-admin/restaurants/<?= e((string) $restaurant['id']) ?>/delete-test" onsubmit="return confirm('Supprimer definitivement ce restaurant test ?');">
                        <label>Saisir SUPPRIMER pour confirmer</label>
                        <input name="confirmation" placeholder="SUPPRIMER" autocomplete="off" required>
                        <button type="submit">Supprimer definitivement</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="link-box">
                    <strong>Impossible - restaurant reel.</strong>
                    <span class="muted">La suppression definitive est bloquee pour les restaurants clients actifs ou reels.</span>
                </div>
            <?php endif; ?>
        </div>
    </details>

    <details class="card fold-card">
        <summary>
            <div>
                <strong>Abonnement et calendrier</strong>
                <div class="muted">Activation ou réactivation simple du restaurant.</div>
            </div>
            <span class="pill badge-off"><?= e(subscription_status_label($subscription['status'] ?? null)) ?></span>
        </summary>
        <div class="fold-body split">
            <article class="card" style="padding:18px;">
                <h2 style="margin-top:0;">État actuel</h2>
                <p><strong>Aujourd’hui :</strong> <?= e(((new DateTimeImmutable(($subscription['today'] ?? 'now') . ' 00:00:00', $subscriptionTimezone))->format('d/m/Y'))) ?></p>
                <p><strong>Début :</strong> <?= e(format_date_fr($subscription['started_at'] ?? null, $subscriptionTimezone)) ?></p>
                <p><strong>Fin :</strong> <?= e(format_date_fr($subscription['ends_at'] ?? null, $subscriptionTimezone)) ?></p>
                <p><strong>Fin de grâce :</strong> <?= e(format_date_fr($subscription['grace_ends_at'] ?? null, $subscriptionTimezone)) ?></p>
                <p><strong>Jours restants :</strong> <?= e((string) ($subscription['days_remaining'] ?? '-')) ?></p>
                <p><strong>Message :</strong> <?= e($subscription['message'] ?? '-') ?></p>
            </article>
            <article class="card" style="padding:18px;">
                <h2 style="margin-top:0;">Activer / réactiver</h2>
                <form method="post" action="/super-admin/restaurants/<?= e((string) $restaurant['id']) ?>/subscription/activate">
                    <label>Date de début</label>
                    <input name="subscription_started_at" value="<?= e(today_for_restaurant($restaurant, false, 'Y-m-d H:i:s')) ?>">
                    <label>Durée en jours</label>
                    <input name="subscription_duration_days" value="<?= e((string) ($subscription_rules['default_duration_days'] ?? 30)) ?>">
                    <label>Mode d’activation</label>
                    <select name="payment_status">
                        <option value="PAID">Activation après paiement</option>
                        <option value="WAIVED">Activation exceptionnelle</option>
                    </select>
                    <label>Justification</label>
                    <textarea name="justification">Validation ou activation exceptionnelle décidée par la plateforme.</textarea>
                    <button type="submit">Valider / activer</button>
                </form>
            </article>
        </div>
    </details>
</div>
