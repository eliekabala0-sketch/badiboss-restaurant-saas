<section class="topbar">
    <div class="brand">
        <h1>Audit externe</h1>
        <p>Application metier autonome. Aucune saisie de cette page n'ecrit dans les ventes, le stock ou la caisse operationnels.</p>
    </div>
</section>

<form method="get" class="card" style="padding:18px;margin-bottom:20px">
    <label>Date d'activite <input type="date" name="date" value="<?= e($date) ?>"></label>
    <button type="submit">Afficher</button>
</form>
<?php if (can_access('audit.external.manage')): ?>
<form method="get" action="/audit-externe/periode" class="card" style="padding:18px;margin-bottom:20px">
    <strong>Rapports et confrontations par periode</strong>
    <label>Du <input type="date" name="from" value="<?= e($date) ?>"></label>
    <label>Au <input type="date" name="to" value="<?= e($date) ?>"></label>
    <button type="submit">Lire le rapport</button>
    <?php
    $anchor = new DateTimeImmutable($date);
    $weekStart = $anchor->modify('monday this week')->format('Y-m-d');
    $weekEnd = $anchor->modify('sunday this week')->format('Y-m-d');
    $monthStart = $anchor->modify('first day of this month')->format('Y-m-d');
    $monthEnd = $anchor->modify('last day of this month')->format('Y-m-d');
    $yearStart = $anchor->format('Y-01-01');
    $yearEnd = $anchor->format('Y-12-31');
    ?>
    <div class="actions" style="margin-top:12px">
        <a href="/audit-externe/periode?from=<?= e($date) ?>&to=<?= e($date) ?>">Journalier</a>
        <a href="/audit-externe/periode?from=<?= e($weekStart) ?>&to=<?= e($weekEnd) ?>">Hebdomadaire</a>
        <a href="/audit-externe/periode?from=<?= e($monthStart) ?>&to=<?= e($monthEnd) ?>">Mensuel</a>
        <a href="/audit-externe/periode?from=<?= e($yearStart) ?>&to=<?= e($yearEnd) ?>">Annuel</a>
    </div>
</form>
<?php endif; ?>

<section class="grid stats">
    <?php foreach ([
        'Rapports' => $dashboard['summary']['reports'],
        'Brouillons' => $dashboard['summary']['drafts'],
        'Soumis' => $dashboard['summary']['submitted'],
        'Vente calculee' => number_format($dashboard['summary']['calculated_sales'], 0, ',', ' '),
        'Manquants' => number_format($dashboard['summary']['missing_amount'], 0, ',', ' '),
        'Suspects' => number_format($dashboard['summary']['suspicious_amount'], 0, ',', ' '),
        'Injections' => number_format($dashboard['summary']['injection_amount'], 0, ',', ' '),
    ] as $label => $value): ?>
        <article class="card stat"><span><?= e($label) ?></span><strong><?= e((string) $value) ?></strong></article>
    <?php endforeach; ?>
</section>

<section class="card" style="padding:22px;margin-bottom:22px">
    <h2>Nouveau brouillon</h2>
    <form method="post" action="/audit-externe/rapports">
        <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(210px,1fr))">
            <label>Type
                <select name="report_type"><option value="boissons">Boissons / stock</option><option value="cuisine">Cuisine</option><option value="serveur">Serveur</option><option value="annexes">Annexes</option></select>
            </label>
            <label>Date d'activite <input type="date" name="activity_date" value="<?= e($date) ?>" required></label>
            <label>Vente declaree <input type="number" min="0" step="0.01" name="declared_sales" value="0"></label>
            <label>Argent presente <input type="number" min="0" step="0.01" name="presented_cash" value="0"></label>
            <?php if (can_access('audit.external.manage')): ?>
            <label>Agent concerne
                <select name="operational_author_id">
                    <option value="<?= (int) current_user()['id'] ?>">Moi-meme</option>
                    <?php foreach ($users as $user): if ((int) $user['id'] === (int) current_user()['id']) continue; ?>
                        <option value="<?= (int) $user['id'] ?>"><?= e($user['full_name']) ?> · <?= e($user['role_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Motif de delegation <input name="delegation_reason" placeholder="Agent empeche"></label>
            <?php endif; ?>
            <label><input type="checkbox" name="is_test" value="1"> Rapport de test (sandbox uniquement)</label>
        </div>
        <label>Observations <textarea name="observations" rows="3"></textarea></label>
        <button type="submit">Commencer le brouillon</button>
    </form>
</section>

<section class="card" style="padding:22px;margin-bottom:22px">
    <h2>Rapports du <?= e($date) ?></h2>
    <div class="table-wrap"><table><thead><tr><th>Type</th><th>Auteur</th><th>Statut</th><th>Vente calculee</th><th>Manquant</th><th>Action</th></tr></thead><tbody>
    <?php foreach ($dashboard['reports'] as $report): ?><tr>
        <td><?= e($report['report_type']) ?></td><td><?= e($report['author_name']) ?></td><td><span class="pill"><?= e($report['status']) ?></span></td>
        <td><?= number_format((float) ($report['calculated_sales'] ?? 0), 0, ',', ' ') ?></td>
        <td><?= number_format((float) ($report['missing_amount'] ?? 0), 0, ',', ' ') ?></td>
        <td><a href="/audit-externe/rapports/<?= (int) $report['id'] ?>">Ouvrir</a></td>
    </tr><?php endforeach; ?>
    <?php if ($dashboard['reports'] === []): ?><tr><td colspan="6">Aucun rapport pour cette date.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>

<?php if (can_access('audit.external.manage')): ?>
<section class="grid" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr))">
    <article class="card" style="padding:22px"><h2>Ajouter une categorie</h2>
        <form method="post" action="/audit-externe/categories">
            <label>Nom <input name="name" required></label>
            <label>Mode <select name="audit_mode"><option value="stock">Stock</option><option value="cuisine">Cuisine</option><option value="ventes">Ventes</option></select></label>
            <button type="submit">Ajouter</button>
        </form>
    </article>
    <article class="card" style="padding:22px"><h2>Ajouter un produit</h2>
        <form method="post" action="/audit-externe/produits">
            <label>Nom <input name="name" required></label>
            <label>Categorie <select name="category_id" required><option value="">Choisir manuellement</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>"><?= e($category['name']) ?></option><?php endforeach; ?></select></label>
            <label>Unite <input name="unit" value="unite"></label>
            <label>Prix vente <input type="number" min="0" step="0.01" name="sale_price" required></label>
            <label>Prix achat habituel <input type="number" min="0" step="0.01" name="usual_purchase_price"></label>
            <button type="submit">Ajouter</button>
        </form>
    </article>
</section>
<?php endif; ?>
