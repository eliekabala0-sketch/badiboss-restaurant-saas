<?php
$tenantName = $tenant['public_name'] ?? $tenant['name'] ?? 'Restaurant';
$logoUrl = !empty($tenant['logo_url'] ?? null) ? restaurant_media_url_or_default($tenant['logo_url'], 'logo') : null;
$coverUrl = !empty($tenant['cover_image_url'] ?? null) ? restaurant_media_url_or_default($tenant['cover_image_url'], 'photo') : null;
?>

<section class="card" style="padding:28px; border-top:10px solid <?= e($tenant['primary_color'] ?? '#0f766e') ?>;">
    <div class="topbar" style="align-items:flex-start;">
        <div>
            <h1 style="margin-top:0; color: <?= e($tenant['secondary_color'] ?? '#111827') ?>;">
                <?= e($tenant !== null ? $tenantName : 'Inscription client') ?>
            </h1>
            <p class="muted" style="max-width:720px;">
                <?php if ($tenant !== null): ?>
                    Creez votre compte client pour <?= e($tenantName) ?>. Votre compte sera automatiquement lie a ce restaurant.
                <?php else: ?>
                    Entrez le code restaurant recu aupres du restaurant pour creer votre compte client.
                <?php endif; ?>
            </p>
        </div>
        <?php if ($logoUrl !== null): ?><img src="<?= e($logoUrl) ?>" alt="Logo restaurant" style="width:88px; height:88px; object-fit:cover; border-radius:18px; border:1px solid var(--line);"><?php endif; ?>
    </div>

    <?php if (!empty($success)): ?><div class="flash-ok"><?= e($success) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="flash-bad"><?= e($error) ?></div><?php endif; ?>

    <?php if ($coverUrl !== null): ?>
        <div class="media-preview" style="margin-bottom:18px;">
            <img src="<?= e($coverUrl) ?>" alt="Photo du restaurant" style="height:220px;">
        </div>
    <?php endif; ?>

    <div class="split">
        <article class="card" style="padding:20px;">
            <h2 style="margin-top:0;">Restaurant</h2>
            <?php if ($tenant !== null): ?>
                <p><strong>Nom :</strong> <?= e($tenantName) ?></p>
                <p><strong>Code restaurant :</strong> <?= e($tenant['restaurant_code'] ?? '-') ?></p>
                <p><strong>Portail :</strong> <a href="<?= e(restaurant_generated_access_path($tenant)) ?>"><?= e(restaurant_generated_access_url($tenant)) ?></a></p>
            <?php else: ?>
                <p class="muted">Le restaurant sera retrouve a partir du code saisi dans le formulaire.</p>
            <?php endif; ?>
        </article>

        <article class="card" style="padding:20px;">
            <h2 style="margin-top:0;">Compte client</h2>
            <p class="muted">Role attribue automatiquement : Client.</p>
            <p class="muted">Si vous utilisez le lien du restaurant, vous ne pouvez pas etre rattache a un autre restaurant.</p>
        </article>
    </div>

    <form method="post" action="<?= e($tenant !== null ? restaurant_generated_registration_path($tenant) : '/portal/register') ?>" class="split" style="margin-top:20px;">
        <?php if ($tenant === null): ?>
            <div style="grid-column:1 / -1;">
                <label>Code restaurant</label>
                <input name="restaurant_code" value="<?= e($input['restaurant_code'] ?? '') ?>" required placeholder="Exemple : badi-saveurs-gombe">
            </div>
        <?php else: ?>
            <input type="hidden" name="restaurant_code" value="<?= e($tenant['restaurant_code'] ?? '') ?>">
        <?php endif; ?>

        <div><label>Nom complet</label><input name="full_name" value="<?= e($input['full_name'] ?? '') ?>" required></div>
        <div><label>E-mail</label><input name="email" type="email" value="<?= e($input['email'] ?? '') ?>" required></div>
        <div><label>Telephone</label><input name="phone" value="<?= e($input['phone'] ?? '') ?>"></div>
        <div><label>Mot de passe</label><input name="password" type="password" required></div>
        <div style="grid-column:1 / -1;"><button type="submit">Creer mon compte client</button></div>
    </form>
</section>
