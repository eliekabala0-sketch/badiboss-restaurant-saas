<section class="topbar">
    <div class="brand">
        <h1>Créer mon restaurant</h1>
        <p>Créez votre espace, votre compte principal et préparez votre activation dans une interface simple et claire.</p>
    </div>
</section>

<?php if (!empty($flash_error)): ?><div class="flash-bad"><?= e($flash_error) ?></div><?php endif; ?>

<section class="card" style="padding:24px;">
    <form method="post" action="/creer-mon-restaurant" enctype="multipart/form-data">
        <div class="split">
            <div>
                <label>Nom du restaurant</label>
                <input name="name" value="<?= e($form_old['name'] ?? '') ?>" required>
            </div>
            <div>
                <label>Code restaurant proposé</label>
                <input name="restaurant_code" value="<?= e($form_old['restaurant_code'] ?? '') ?>" placeholder="Laissez vide pour une génération automatique">
            </div>
            <div>
                <label>Adresse</label>
                <input name="address_line" value="<?= e($form_old['address_line'] ?? '') ?>">
            </div>
            <div>
                <label>Ville</label>
                <input name="city" value="<?= e($form_old['city'] ?? '') ?>">
            </div>
            <div>
                <label>Téléphone du restaurant</label>
                <input name="phone" value="<?= e($form_old['phone'] ?? '') ?>">
            </div>
            <div>
                <label>E-mail du restaurant</label>
                <input name="support_email" type="email" value="<?= e($form_old['support_email'] ?? '') ?>">
            </div>
            <div>
                <label>Plan de départ</label>
                <select name="subscription_plan_id"><?php foreach ($plans as $plan): ?><option value="<?= e((string) $plan['id']) ?>"><?= e($plan['name']) ?></option><?php endforeach; ?></select>
            </div>
            <div>
                <label>Logo du restaurant</label>
                <input name="logo" type="file" accept=".jpg,.jpeg,.png,.webp">
            </div>
            <div>
                <label>Photo du restaurant</label>
                <input name="photo" type="file" accept=".jpg,.jpeg,.png,.webp">
            </div>
        </div>

        <h2 style="margin-top:18px;">Compte principal</h2>
        <div class="split">
            <div>
                <label>Nom du responsable principal</label>
                <input name="primary_contact_name" value="<?= e($form_old['primary_contact_name'] ?? '') ?>" required>
            </div>
            <div>
                <label>Rôle principal</label>
                <select name="primary_role_code">
                    <option value="owner">Propriétaire</option>
                    <option value="manager" <?= (($form_old['primary_role_code'] ?? '') === 'manager') ? 'selected' : '' ?>>Gérant principal</option>
                </select>
            </div>
            <div>
                <label>E-mail du compte principal</label>
                <input name="primary_contact_email" type="email" value="<?= e($form_old['primary_contact_email'] ?? '') ?>" required>
            </div>
            <div>
                <label>Téléphone du compte principal</label>
                <input name="primary_contact_phone" value="<?= e($form_old['primary_contact_phone'] ?? '') ?>">
            </div>
            <div style="grid-column:1 / -1;">
                <label>Mot de passe initial</label>
                <input name="password" type="password" required>
            </div>
        </div>

        <button type="submit">Créer mon restaurant</button>
    </form>
</section>
