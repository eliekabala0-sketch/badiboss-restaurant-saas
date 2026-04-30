<section class="card" style="padding:32px; overflow:hidden; position:relative;">
    <div style="display:grid; grid-template-columns:1.3fr 0.9fr; gap:24px; align-items:center;">
        <div>
            <span class="pill">Gestion restaurant pensée pour le terrain</span>
            <h1 style="font-size:3rem; margin:16px 0 12px;">Pilotez le stock, la cuisine, les ventes et les rapports sans confusion.</h1>
            <p class="muted" style="font-size:1.1rem;">Badiboss aide le propriétaire et le gérant à suivre précisément ce qui entre, ce qui sort, ce qui est produit, ce qui est vendu et ce qui est perdu, restaurant par restaurant.</p>
            <div class="actions" style="margin-top:20px; display:flex; gap:12px; flex-wrap:wrap;">
                <a href="/login">Se connecter</a>
                <a href="/creer-mon-restaurant" style="background:var(--brand-2);">Créer mon restaurant</a>
            </div>
        </div>
        <div class="card" style="padding:22px; background:rgba(255,255,255,0.75);">
            <h2 style="margin-top:0;">Activation simple</h2>
            <p><strong>Étape 1 :</strong> vous créez votre restaurant et votre compte principal.</p>
            <p><strong>Étape 2 :</strong> vous déclarez le paiement ou attendez une activation exceptionnelle.</p>
            <p><strong>Étape 3 :</strong> la plateforme valide et votre restaurant devient opérationnel.</p>
            <p class="muted" style="margin-bottom:0;">Tant que l’abonnement n’est pas actif, les actions sensibles restent verrouillées.</p>
        </div>
    </div>
</section>

<section class="split" style="margin-top:24px;">
    <article class="card" style="padding:24px;">
        <h2 style="margin-top:0;">Pilotage clair</h2>
        <p><strong>Stock :</strong> entrées, sorties, pertes, ruptures et demandes urgentes.</p>
        <p><strong>Cuisine :</strong> production réelle, fourni au service, incidents et retours.</p>
        <p><strong>Ventes :</strong> demandé, fourni, vendu, retourné et pertes constatées.</p>
        <p><strong>Rapports :</strong> journaliers, hebdomadaires et mensuels sur de vraies dates.</p>
    </article>

    <article class="card" style="padding:24px;">
        <h2 style="margin-top:0;">Confiance et autonomie</h2>
        <p>Chaque restaurant reste indépendant, avec son propre code, son propre abonnement, ses propres utilisateurs et son propre historique.</p>
        <p class="muted">La plateforme garde le contrôle technique et l’audit, sans interférer avec la gestion quotidienne du restaurant.</p>
    </article>
</section>

<section class="card" style="padding:24px; margin-top:24px;">
    <h2 style="margin-top:0;">Plans disponibles</h2>
    <div class="grid stats" style="margin-bottom:0;">
        <?php foreach ($plans as $plan): ?>
            <article class="card stat">
                <span><?= e($plan['code']) ?></span>
                <strong><?= e($plan['name']) ?></strong>
            </article>
        <?php endforeach; ?>
    </div>
</section>
