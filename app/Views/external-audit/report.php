<section class="topbar"><div class="brand"><h1>Rapport <?= e($report['report_type']) ?></h1><p>Activite du <?= e($report['activity_date']) ?> · Auteur operationnel <?= e($report['author_name']) ?> · Version <?= (int) $report['version_no'] ?></p></div><span class="pill"><?= e($report['status']) ?></span></section>
<?php if (!empty($report['delegation_reason'])): ?><div class="card" style="padding:16px;margin-bottom:18px"><strong>Saisie par delegation</strong><p>Saisi par utilisateur #<?= (int) $report['entered_by'] ?>, fonction <?= e($report['entered_by_role']) ?>. Motif : <?= e($report['delegation_reason']) ?></p></div><?php endif; ?>

<?php if ($report['status'] === 'BROUILLON'): ?>
<section class="card" style="padding:22px;margin-bottom:22px">
<?php
$entryMode = (string) $report['report_type'];
$modeHelp = [
    'boissons' => 'Stock precedent repris automatiquement, achats et conditionnements, mouvements expliques, restant reel et encaissement.',
    'cuisine' => 'Matieres premieres, production, consommation, plats vendus ou reportes, retours, credits, depenses et incidents.',
    'serveur' => 'Saisie rapide des quantites et credits. Le serveur ne saisit jamais le prix catalogue.',
    'annexes' => 'Controle autonome des autres categories.',
][$entryMode] ?? 'Saisie autonome du rapport.';
$fieldLabels = [
    'previous_stock' => $entryMode === 'cuisine' ? 'Stock initial / matieres' : 'Stock precedent',
    'cases' => 'Casiers', 'half_cases' => 'Demi-casiers', 'units' => 'Unites',
    'purchased_quantity' => 'Quantite achetee', 'purchase_unit_price' => 'Prix achat unitaire',
    'purchase_total' => 'Prix achat global',
    'explained_entries' => $entryMode === 'cuisine' ? 'Quantite produite / recettes' : 'Entrees expliquees',
    'explained_outputs' => $entryMode === 'cuisine' ? 'Matieres consommees / retours' : 'Sorties expliquees',
    'remaining_stock' => $entryMode === 'cuisine' ? 'Stock reel / plats reportes' : 'Stock restant reel',
    'sold_quantity_declared' => $entryMode === 'cuisine' ? 'Plats vendus' : 'Quantite vendue',
    'credit_amount' => 'Credits', 'expense_amount' => 'Depenses', 'transport_amount' => 'Transport',
];
$fields = $entryMode === 'serveur'
    ? ['sold_quantity_declared','credit_amount']
    : ($entryMode === 'cuisine'
        ? ['previous_stock','purchased_quantity','purchase_unit_price','purchase_total','explained_entries','explained_outputs','remaining_stock','sold_quantity_declared','credit_amount','expense_amount','transport_amount']
        : ['previous_stock','cases','half_cases','units','purchased_quantity','purchase_unit_price','purchase_total','explained_entries','explained_outputs','remaining_stock','sold_quantity_declared','credit_amount','expense_amount','transport_amount']);
?>
<p class="muted"><?= e($modeHelp) ?></p>
<style>
.audit-fields thead{display:none}.audit-fields tr{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:10px;padding:14px;border-bottom:1px solid var(--line)}.audit-fields td{display:block;padding:4px;border:0}.audit-fields input{width:100%!important}.audit-fields small.field-label{display:block;color:var(--muted);margin-bottom:5px}.audit-recap{position:sticky;bottom:8px;padding:12px;background:var(--panel);border:1px solid var(--brand);border-radius:14px;z-index:2}
</style>
<form method="post" enctype="multipart/form-data" action="/audit-externe/rapports/<?= (int) $report['id'] ?>/brouillon" data-audit-form>
    <input type="hidden" name="report_id" value="<?= (int) $report['id'] ?>">
    <input type="hidden" name="activity_date" value="<?= e($report['activity_date']) ?>">
    <input type="hidden" name="report_type" value="<?= e($report['report_type']) ?>">
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
        <label>Vente declaree <input type="number" min="0" step="0.01" name="declared_sales" value="<?= e($report['declared_sales']) ?>"></label>
        <label>Argent presente <input type="number" min="0" step="0.01" name="presented_cash" value="<?= e($report['presented_cash']) ?>"></label>
    </div>
    <label>Observations <textarea name="observations"><?= e($report['observations']) ?></textarea></label>
    <label>Piece jointe facultative (JPG, PNG, WebP) <input type="file" name="evidence" accept="image/jpeg,image/png,image/webp"></label>
    <h2>Lignes produits</h2>
    <div class="table-wrap audit-fields"><table><thead><tr><th>Produit</th><th>Champs du parcours</th></tr></thead><tbody>
    <?php foreach ($products as $product): $saved = null; foreach ($items as $candidate) { if ((int) $candidate['product_id'] === (int) $product['id']) { $saved = $candidate; break; } } ?>
        <tr data-price="<?= e((string) $product['sale_price']) ?>"><td><strong><?= e($product['name']) ?></strong><br><small><?= e($product['category_name']) ?> · prix fige <?= number_format((float) $product['sale_price'], 0, ',', ' ') ?></small></td>
        <?php foreach ($fields as $field): ?>
            <td><small class="field-label"><?= e($fieldLabels[$field]) ?></small><input data-field="<?= e($field) ?>" title="<?= e($fieldLabels[$field]) ?>" style="width:105px" type="number" min="0" step="0.001" name="items[<?= (int) $product['id'] ?>][<?= e($field) ?>]" value="<?= e((string) ($saved[$field === 'purchase_unit_price' ? 'purchase_price_snapshot' : $field] ?? ($field === 'previous_stock' && $product['previous_stock_default'] !== null ? $product['previous_stock_default'] : '0'))) ?>"></td>
        <?php endforeach; ?>
        <td><small class="field-label"><?= $entryMode === 'serveur' ? 'Personne du credit, motif et observation' : 'Incident, justification, preparation ou retour' ?></small><input style="width:180px" name="items[<?= (int) $product['id'] ?>][incident_note]" value="<?= e((string) ($saved['incident_note'] ?? '')) ?>" placeholder="Personne, motif, recette, retour…"></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <div class="audit-recap">Recapitulatif avant soumission : <strong data-recap>0 unite · 0</strong></div>
    <button type="submit">Enregistrer le brouillon</button>
</form>
<script>
(()=>{const f=document.querySelector('[data-audit-form]');if(!f)return;const update=()=>{let q=0,a=0;f.querySelectorAll('tr[data-price]').forEach(r=>{const s=Number(r.querySelector('[data-field="sold_quantity_declared"]')?.value||0);q+=s;a+=s*Number(r.dataset.price||0);const t=r.querySelector('[data-field="purchase_total"]'),u=r.querySelector('[data-field="purchase_unit_price"]'),n=Number(r.querySelector('[data-field="purchased_quantity"]')?.value||0);if(t&&u&&document.activeElement===t&&n>0)u.value=(Number(t.value||0)/n).toFixed(2)});f.querySelector('[data-recap]').textContent=q.toLocaleString('fr-FR')+' unite · '+a.toLocaleString('fr-FR')});f.addEventListener('input',update);update()})();
</script>
</section>
<section class="card" style="padding:22px;margin-bottom:22px">
    <h2>Soumettre</h2><p>Apres soumission, l'auteur ne peut plus modifier. Le catalogue et les calculs sont figes.</p>
    <form method="post" action="/audit-externe/rapports/<?= (int) $report['id'] ?>/soumettre">
        <input type="hidden" name="idempotency_key" value="<?= e(bin2hex(random_bytes(16))) ?>">
        <button type="submit">Verifier et soumettre</button>
    </form>
</section>
<?php else: ?>
<?php if ($result): ?>
<section class="grid stats">
<?php foreach ([
    'Vente calculee' => $result['calculated_sales'], 'Vente declaree' => $result['declared_sales'],
    'Achats' => $result['purchases'], 'Depenses' => $result['expenses'], 'Credits' => $result['credits'],
    'Manquant' => $result['missing_amount'], 'Suspect' => $result['suspicious_amount'],
    'Injection' => $result['injection_amount'], 'Attendu' => $result['expected_amount'],
    'Presente' => $result['presented_cash'], 'Ecart caisse' => $result['cash_gap'],
] as $label => $value): ?><article class="card stat"><span><?= e($label) ?></span><strong><?= number_format((float) $value, 0, ',', ' ') ?></strong></article><?php endforeach; ?>
</section>
<?php endif; ?>
<section class="card" style="padding:22px"><h2>Valeurs figees</h2><div class="table-wrap"><table><thead><tr><th>Produit</th><th>Disponible</th><th>Vendu calcule</th><th>Injection</th><th>Vente</th></tr></thead><tbody>
<?php foreach ($items as $item): ?><tr><td><?= e($item['product_name_snapshot']) ?></td><td><?= e($item['calculated_available']) ?></td><td><?= e($item['calculated_sold_quantity']) ?></td><td><?= e($item['calculated_injection_quantity']) ?></td><td><?= number_format((float) $item['calculated_sale_amount'], 0, ',', ' ') ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>

<?php if ($report['status'] !== 'BROUILLON' && $report['status'] !== 'ANNULE'): ?>
<section class="grid" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr));margin-top:22px">
    <article class="card" style="padding:22px"><h2>Demander une correction</h2>
        <form method="post" action="/audit-externe/rapports/<?= (int) $report['id'] ?>/correction">
            <label>Motif obligatoire <input name="reason" required></label><button type="submit">Envoyer la demande</button>
        </form>
    </article>
    <?php if (can_access('audit.external.manage') && in_array($report['status'], ['SOUMIS','CORRIGE'], true)): ?>
    <article class="card" style="padding:22px"><h2>Cloture</h2>
        <form method="post" action="/audit-externe/rapports/<?= (int) $report['id'] ?>/verrouiller"><button type="submit">Verrouiller le rapport</button></form>
    </article>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if (can_access('audit.external.manage')): ?>
<section class="card" style="padding:22px;margin-top:22px"><h2>Demandes de correction</h2>
<?php foreach ($correction_requests as $correction): ?>
<div style="padding:12px;border-bottom:1px solid var(--line)"><strong><?= e($correction['requester_name']) ?> · <?= e($correction['status']) ?></strong><p><?= e($correction['reason']) ?></p>
<?php if ($correction['status'] === 'PENDING'): ?><form method="post" action="/audit-externe/corrections/<?= (int) $correction['id'] ?>/decision">
<input type="hidden" name="report_id" value="<?= (int) $report['id'] ?>"><label>Note <input name="note"></label>
<button name="decision" value="approve">Accepter</button><button name="decision" value="reject">Rejeter</button></form><?php endif; ?></div>
<?php endforeach; ?><?php if ($correction_requests === []): ?><p>Aucune demande.</p><?php endif; ?>
</section>

<section class="card" style="padding:22px;margin-top:22px"><h2>Dossier de perte</h2>
<p>Employer uniquement : perte probable, anomalie ou responsabilite a determiner.</p>
<form method="post" enctype="multipart/form-data" action="/audit-externe/rapports/<?= (int) $report['id'] ?>/pertes">
<input type="hidden" name="activity_date" value="<?= e($report['activity_date']) ?>">
<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr))">
<label>Produit <select name="product_id"><option value="">Non precise</option><?php foreach ($products as $product): ?><option value="<?= (int) $product['id'] ?>"><?= e($product['name']) ?></option><?php endforeach; ?></select></label>
<label>Quantite <input type="number" min="0" step="0.001" name="quantity"></label>
<label>Valeur <input type="number" min="0" step="0.01" name="value_amount" required></label>
<label>Personnes liees <input name="involved_people" placeholder="Noms separes par virgule"></label>
<label>Cause/justification <input name="cause"></label>
<label>Preuve (reference) <input name="evidence_path"></label>
<label>Photo facultative <input type="file" name="evidence" accept="image/jpeg,image/png,image/webp"></label>
<label>Statut <select name="status"><option value="A_VERIFIER">A verifier</option><option value="EN_JUSTIFICATION">En justification</option><option value="EXPLIQUE">Explique</option><option value="CONFIRME">Confirme</option><option value="CONTESTE">Conteste</option><option value="RESOLU">Resolu</option><option value="ANNULE">Annule</option></select></label>
</div><button type="submit">Creer le dossier</button></form>
<div class="table-wrap"><table><thead><tr><th>Date</th><th>Produit</th><th>Valeur</th><th>Personnes</th><th>Cause / preuve</th><th>Statut / decision</th><th>Action</th></tr></thead><tbody><?php foreach ($losses as $loss): ?><tr><td><?= e($loss['activity_date']) ?></td><td><?= e($loss['product_name'] ?? '-') ?></td><td><?= number_format((float) $loss['value_amount'],0,',',' ') ?></td><td><?= e(implode(', ', json_decode((string) ($loss['involved_people_json'] ?? '[]'), true) ?: [])) ?></td><td><?= e($loss['cause']) ?><br><small><?= e($loss['evidence_path'] ?? '-') ?></small></td><td><?= e($loss['status']) ?><br><small><?= e($loss['manager_decision'] ?? '') ?></small></td><td><form method="post" action="/audit-externe/pertes/<?= (int) $loss['id'] ?>/decision"><input type="hidden" name="report_id" value="<?= (int) $report['id'] ?>"><select name="status"><?php foreach (['A_VERIFIER','EN_JUSTIFICATION','EXPLIQUE','CONFIRME','CONTESTE','RESOLU','ANNULE'] as $lossStatus): ?><option value="<?= e($lossStatus) ?>" <?= $lossStatus === $loss['status'] ? 'selected' : '' ?>><?= e($lossStatus) ?></option><?php endforeach; ?></select><input name="decision" placeholder="Decision motivee" required><button type="submit">Enregistrer</button></form></td></tr><?php endforeach; ?></tbody></table></div>
</section>

<section class="card" style="padding:22px;margin-top:22px"><h2>Annuler</h2><form method="post" action="/audit-externe/rapports/<?= (int) $report['id'] ?>/annuler"><label>Motif <input name="reason" required></label><button type="submit">Annuler avec trace</button></form></section>
<?php endif; ?>

<section class="card" style="padding:22px;margin-top:22px"><h2>Versions archivees</h2>
<div class="table-wrap"><table><thead><tr><th>Version</th><th>Date</th><th>Auteur</th><th>Motif</th><th>Action</th></tr></thead><tbody><?php foreach ($revisions as $revision): ?><tr><td><?= (int) $revision['version_no'] ?></td><td><?= e($revision['created_at']) ?></td><td><?= e($revision['actor_name'] ?? '-') ?></td><td><?= e($revision['reason']) ?></td><td><?php if (can_access('audit.external.manage')): ?><form method="post" action="/audit-externe/versions/<?= (int) $revision['id'] ?>/restaurer"><input type="hidden" name="report_id" value="<?= (int) $report['id'] ?>"><input name="reason" placeholder="Motif" required><button type="submit">Restaurer</button></form><?php endif; ?></td></tr><?php endforeach; ?><?php if ($revisions === []): ?><tr><td colspan="5">Aucune version archivee.</td></tr><?php endif; ?></tbody></table></div>
</section>

<?php if (can_access('audit.reset_report')): ?>
<section class="card" style="padding:22px;margin-top:22px"><h2>Reinitialiser le rapport</h2>
<p>Cette action va archiver la version actuelle, vider le contenu du rapport et rouvrir un nouveau brouillon. Les anciennes donnees resteront consultables.</p>
<form method="post" action="/audit-externe/rapports/<?= (int) $report['id'] ?>/reinitialiser" onsubmit="return confirm('Confirmer la reinitialisation et archivage ?')">
<label>Motif obligatoire <input name="reason" required></label><button type="submit">Reinitialiser le rapport</button></form></section>
<?php endif; ?>

<?php if ($attachments !== []): ?>
<section class="card" style="padding:22px;margin-top:22px"><h2>Pieces jointes</h2>
<?php foreach ($attachments as $attachment): ?>
<p><a href="<?= e($attachment['storage_path']) ?>" target="_blank" rel="noopener"><?= e($attachment['original_name']) ?></a> · <?= number_format((int) $attachment['size_bytes'] / 1024, 1, ',', ' ') ?> Ko</p>
<?php endforeach; ?>
</section>
<?php endif; ?>

<?php if (can_access('audit.delete_test') && (bool) $report['is_test']): ?>
<section class="card" style="padding:22px;margin-top:22px;border-color:var(--danger)"><h2>Supprimer definitivement ce test</h2>
<form method="post" action="/audit-externe/rapports/<?= (int) $report['id'] ?>/supprimer-test">
<label>Motif <input name="reason" required></label><label>Premiere confirmation <input name="confirmation" placeholder="SUPPRIMER TEST" required></label>
<label>Deuxieme confirmation <input name="confirmation_2" placeholder="CONFIRMER" required></label><button type="submit">Supprimer uniquement ce test</button></form></section>
<?php endif; ?>
