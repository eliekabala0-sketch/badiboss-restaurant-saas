<section class="card auth" style="max-width:540px; margin:40px auto; padding:28px;">
    <div class="brand">
        <h1>Connexion</h1>
        <p>Connectez-vous à votre restaurant ou à la plateforme avec un compte déjà autorisé.</p>
    </div>

    <?php if (!empty($error ?? null)): ?>
        <div class="error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (!empty($success ?? null)): ?>
        <div class="flash-ok"><?= e($success) ?></div>
    <?php endif; ?>

    <form method="post" action="/login">
        <label for="email">Adresse e-mail</label>
        <input id="email" name="email" type="email" required>

        <label for="password">Mot de passe</label>
        <input id="password" name="password" type="password" required>

        <button type="submit">Se connecter</button>
    </form>

    <p class="muted" style="margin-bottom:0;">Pas encore de restaurant ? <a href="/creer-mon-restaurant">Créer mon restaurant</a></p>
</section>
