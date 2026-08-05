<section class="topbar">
    <div class="brand">
        <h1><?= e($assignment['label'] ?? 'Audit externe') ?></h1>
        <p>Application metier autonome. Aucune saisie de cette page n'ecrit dans les ventes, le stock ou la caisse operationnels.</p>
    </div>
</section>

<form method="get" class="card" style="padding:18px;margin-bottom:20px">
    <label>Date d'activite <input type="date" name="date" value="<?= e($date) ?>"></label>
    <button type="submit">Afficher</button>
</form>
<?php if ($is_manager_dashboard): ?>
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

<?php if ($is_manager_dashboard): ?>
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
<?php endif; ?>

<?php if ($is_manager_dashboard): ?>
<section class="grid stats">
    <?php foreach ([
        'Serveurs actifs' => $tracking['summary']['active_server_count'],
        'Serveurs attendus' => $tracking['summary']['servers_expected'],
        'Rapports serveurs recus' => $tracking['summary']['server_reports_received'],
        'Rapports serveurs manquants' => $tracking['summary']['server_reports_missing'],
        'Tous rapports attendus' => $tracking['summary']['expected'],
        'Tous rapports recus' => $tracking['summary']['received'],
        'Tous rapports en retard' => $tracking['summary']['late'],
    ] as $label => $value): ?>
        <article class="card stat"><span><?= e($label) ?></span><strong><?= (int) $value ?></strong></article>
    <?php endforeach; ?>
</section>

<section class="card" style="padding:22px;margin-bottom:22px">
    <h2>Suivi des rapports attendus · <?= e($date) ?></h2>
    <p class="muted">La liste est construite dynamiquement depuis tous les utilisateurs actifs ayant une fonction attendue. Aucun compte serveur n'est code en dur.</p>
    <div class="table-wrap"><table><thead><tr><th>Nom</th><th>Fonction</th><th>Rapport attendu</th><th>Recu</th><th>Date activite</th><th>Heure limite</th><th>Heure depot</th><th>Retard</th><th>Statut</th></tr></thead><tbody>
    <?php foreach ($tracking['rows'] as $trackingRow): ?><tr>
        <td><?= e($trackingRow['name']) ?></td><td><?= e($trackingRow['function']) ?></td><td><?= e($trackingRow['expected_report']) ?></td>
        <td><?= $trackingRow['received'] ? 'Oui' : 'Non' ?></td><td><?= e($trackingRow['activity_date']) ?></td><td><?= e($trackingRow['deadline_time']) ?></td>
        <td><?= e($trackingRow['submission_time'] ?? '-') ?></td><td><?= e($trackingRow['delay']) ?></td><td><span class="pill"><?= e($trackingRow['status']) ?></span></td>
    </tr><?php endforeach; ?>
    <?php if ($tracking['rows'] === []): ?><tr><td colspan="9">Aucune fonction active soumise a rapport pour cette date.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<?php endif; ?>

<?php if ($is_manager_dashboard): ?>
<section class="card" style="padding:22px;margin-bottom:22px"><h2>Heures limites par fonction</h2>
<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr))">
<?php foreach ($role_expectations as $expectation): ?><form method="post" action="/audit-externe/attentes/<?= e($expectation['role_code']) ?>" class="card" style="padding:16px">
    <strong><?= e($expectation['role_label']) ?></strong>
    <input type="hidden" name="date" value="<?= e($date) ?>">
    <label>Rapport <input value="<?= e($expectation['report_type']) ?>" disabled></label>
    <label>Heure limite <input type="time" name="deadline_time" value="<?= e(substr((string) $expectation['deadline_time'],0,5)) ?>" required></label>
    <label><input type="checkbox" name="is_required" value="1" <?= (bool) $expectation['is_required'] ? 'checked' : '' ?> style="width:auto"> Rapport obligatoire</label>
    <button type="submit">Enregistrer</button>
</form><?php endforeach; ?>
</div></section>
<?php endif; ?>

<section class="card" style="padding:22px;margin-bottom:22px">
    <h2><?= $is_manager_dashboard ? 'Nouveau brouillon' : e($assignment['label']) ?></h2>
    <?php if (!$is_manager_dashboard): ?><p class="pill" style="display:inline-block">BROUILLON NON ENCORE SOUMIS</p><p class="muted">Votre fonction a ete detectee automatiquement. Enregistrez pour commencer et continuer plus tard.</p><?php endif; ?>
    <form method="post" enctype="multipart/form-data" action="/audit-externe/rapports">
        <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(210px,1fr))">
            <?php if ($is_manager_dashboard): ?>
            <label>Type
                <select name="report_type"><option value="boissons">Boissons / stock</option><option value="cuisine">Cuisine</option><option value="serveur">Serveur</option><option value="annexes">Annexes</option></select>
            </label>
            <?php else: ?>
                <input type="hidden" name="report_type" value="<?= e((string) $assignment['report_type']) ?>">
            <?php endif; ?>
            <label>Date d'activite <input type="date" name="activity_date" value="<?= e($date) ?>" required></label>
            <label>Vente declaree <input type="number" min="0" step="0.01" name="declared_sales" value="0"></label>
            <label>Argent presente <input type="number" min="0" step="0.01" name="presented_cash" value="0"></label>
            <?php if ($is_manager_dashboard): ?>
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
            <label>Piece jointe facultative <input type="file" name="evidence" accept="image/jpeg,image/png,image/webp"></label>
        </div>
        <label>Observations <textarea name="observations" rows="3"></textarea></label>
        <button type="submit"><?= $is_manager_dashboard ? 'Commencer le brouillon' : 'OUVRIR MON BROUILLON' ?></button>
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

<?php if ($is_manager_dashboard): ?>
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
