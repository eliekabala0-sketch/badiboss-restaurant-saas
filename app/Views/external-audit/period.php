<section class="topbar"><div class="brand"><h1>Rapport Audit externe indépendant</h1><p><?= e($restaurant['name'] ?? '') ?> · <?= e($period['from']) ?> au <?= e($period['to']) ?> · données Audit externe figées</p></div></section>
<div class="actions" style="margin-bottom:22px"><a href="/audit-externe/export/excel?from=<?= e($period['from']) ?>&to=<?= e($period['to']) ?>">Télécharger Excel</a> <a href="/audit-externe/export/pdf?from=<?= e($period['from']) ?>&to=<?= e($period['to']) ?>">Télécharger PDF</a> <a href="/audit-externe">Retour</a></div>

<section class="grid stats">
<?php foreach (['Ventes calculées'=>'calculated_sales','Ventes déclarées'=>'declared_sales','Achats'=>'purchases','Dépenses'=>'expenses','Manquants'=>'missing_amount','Suspects'=>'suspicious_amount','Injections'=>'injection_amount','Attendu'=>'expected_amount','Présenté'=>'presented_cash','Écart caisse'=>'cash_gap'] as $label=>$key): ?>
<article class="card stat"><span><?= e($label) ?></span><strong><?= number_format((float) $period['totals'][$key],0,',',' ') ?></strong></article><?php endforeach; ?>
</section>

<section class="card" style="padding:22px;margin-bottom:22px"><h2>Informations générales et résultats journaliers</h2>
<div class="table-wrap"><table><thead><tr><th>Date</th><th>Rapports</th><th>Calculé</th><th>Déclaré</th><th>Manquant</th><th>Suspect</th><th>Injection</th><th>Écart caisse</th></tr></thead><tbody>
<?php foreach ($period['days'] as $day): ?><tr><td><?= e($day['activity_date']) ?></td><td><?= (int) ($day['reports'] ?? 0) ?><?= !empty($day['missing']) ? ' · manquant' : '' ?></td><td><?= number_format((float) ($day['calculated_sales'] ?? 0),0,',',' ') ?></td><td><?= number_format((float) ($day['declared_sales'] ?? 0),0,',',' ') ?></td><td><?= number_format((float) ($day['missing_amount'] ?? 0),0,',',' ') ?></td><td><?= number_format((float) ($day['suspicious_amount'] ?? 0),0,',',' ') ?></td><td><?= number_format((float) ($day['injection_amount'] ?? 0),0,',',' ') ?></td><td><?= number_format((float) ($day['cash_gap'] ?? 0),0,',',' ') ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>

<?php
$reportsByType = [];
foreach ($period['reports'] as $periodReport) {
    $reportsByType[(string) $periodReport['report_type']][] = $periodReport;
}
$metricLabels = [
    'purchases' => 'Achats', 'expenses' => 'Depenses', 'credits' => 'Credits',
    'injection_amount' => 'Injections', 'suspicious_amount' => 'Montants suspects',
    'missing_amount' => 'Manquants',
];
?>
<section class="grid" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr));margin-bottom:22px">
<?php foreach (['boissons' => 'Situation boissons', 'cuisine' => 'Situation cuisine', 'annexes' => 'Situation des autres categories', 'serveur' => 'Situation des serveurs'] as $type => $heading): ?>
<article class="card" style="padding:22px"><h2><?= e($heading) ?></h2>
<?php foreach ($reportsByType[$type] ?? [] as $typeReport): ?><p><a href="/audit-externe/rapports/<?= (int) $typeReport['id'] ?>">LIRE LE RAPPORT #<?= (int) $typeReport['id'] ?></a> · <?= e($typeReport['activity_date']) ?> · <?= e($typeReport['author_name']) ?> · <?= number_format((float) ($typeReport['calculated_sales'] ?? 0),0,',',' ') ?></p><?php endforeach; ?>
<?php if (($reportsByType[$type] ?? []) === []): ?><p class="muted">Aucun rapport sur la periode.</p><?php endif; ?>
</article>
<?php endforeach; ?>
</section>

<section class="card" style="padding:22px;margin-bottom:22px"><h2>Achats, depenses, credits, injections, suspects et manquants</h2>
<div class="table-wrap"><table><thead><tr><th>Date</th><th>Rapport</th><th>Auteur</th><?php foreach ($metricLabels as $label): ?><th><?= e($label) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($period['reports'] as $metricReport): ?><tr><td><?= e($metricReport['activity_date']) ?></td><td>#<?= (int) $metricReport['id'] ?></td><td><?= e($metricReport['author_name']) ?></td><?php foreach ($metricLabels as $key => $label): ?><td><?= number_format((float) ($metricReport[$key] ?? 0),0,',',' ') ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table></div></section>

<section class="card" style="padding:22px;margin-bottom:22px"><h2>Incidents et personnes liees</h2>
<div class="table-wrap"><table><thead><tr><th>Date</th><th>Produit</th><th>Categorie</th><th>Auteur</th><th>Incident / credit / retour</th></tr></thead><tbody>
<?php foreach ($period['incidents'] as $incident): ?><tr><td><?= e($incident['activity_date']) ?></td><td><?= e($incident['product_name_snapshot']) ?></td><td><?= e($incident['category_name_snapshot']) ?></td><td><?= e($incident['author_name']) ?></td><td><?= e($incident['incident_note']) ?></td></tr><?php endforeach; ?>
<?php if ($period['incidents'] === []): ?><tr><td colspan="5">Aucun incident renseigne.</td></tr><?php endif; ?>
</tbody></table></div></section>

<section class="card" style="padding:22px;margin-bottom:22px"><h2>Confrontation responsables / serveurs</h2>
<p>Responsables <?= number_format((float)$period['internal_confrontation']['responsible_total'],0,',',' ') ?> · Serveurs <?= number_format((float)$period['internal_confrontation']['server_total'],0,',',' ') ?> · Écart global <?= number_format((float)$period['internal_confrontation']['global_gap'],0,',',' ') ?></p>
<div class="table-wrap"><table><thead><tr><th>Produit</th><th>Catégorie</th><th>Qté responsables</th><th>Qté serveurs</th><th>Écart quantité</th><th>Écart montant</th><th>Personnes</th><th>Statut</th></tr></thead><tbody>
<?php foreach ($period['internal_confrontation']['rows'] as $row): ?><tr><td><?= e($row['product']) ?></td><td><?= e($row['category']) ?></td><td><?= e((string)$row['responsible_quantity']) ?></td><td><?= e((string)$row['server_quantity']) ?></td><td><?= e((string)$row['quantity_gap']) ?></td><td><?= number_format((float)$row['amount_gap'],0,',',' ') ?></td><td><?= e(implode(', ',array_merge($row['responsible_people'],$row['server_people']))) ?></td><td><?= e($row['status']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>

<section class="card" style="padding:22px;margin-bottom:22px"><h2>Confrontation Audit / Application Restaurant</h2><p>Lecture seule. Cette étape ne modifie jamais le rapport indépendant.</p>
<div class="table-wrap"><table><thead><tr><th>Élément</th><th>Audit externe</th><th>Application</th><th>Écart</th><th>Observation</th><th>Statut</th></tr></thead><tbody><?php foreach ($period['application_confrontation']['rows'] as $row): ?><tr><td><?= e($row['element']) ?></td><td><?= number_format((float)$row['audit_amount'],0,',',' ') ?></td><td><?= number_format((float)$row['application_amount'],0,',',' ') ?></td><td><?= number_format((float)$row['gap'],0,',',' ') ?></td><td><?= e($row['observation']) ?></td><td><?= e($row['status']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>

<section class="card" style="padding:22px;margin-bottom:22px"><h2>Analyse détaillée des pertes</h2><p>Total <?= number_format((float)$period['losses']['summary']['total'],0,',',' ') ?> · expliquées <?= number_format((float)$period['losses']['summary']['explained'],0,',',' ') ?> · non expliquées <?= number_format((float)$period['losses']['summary']['unexplained'],0,',',' ') ?></p>
<div class="table-wrap"><table><thead><tr><th>Date</th><th>Produit</th><th>Catégorie</th><th>Valeur</th><th>Responsable</th><th>Cause</th><th>Preuve</th><th>Statut</th></tr></thead><tbody><?php foreach ($period['losses']['rows'] as $row): ?><tr><td><?= e($row['activity_date']) ?></td><td><?= e($row['product_name'] ?? '-') ?></td><td><?= e($row['category_name'] ?? '-') ?></td><td><?= number_format((float)$row['value_amount'],0,',',' ') ?></td><td><?= e($row['responsible_name'] ?? '-') ?></td><td><?= e($row['cause']) ?></td><td><?= e($row['evidence_path'] ?? '-') ?></td><td><?= e($row['status']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>

<section class="card" style="padding:22px"><h2>Recommandations, conclusion et validation</h2><p>Examiner chaque écart séparément par jour, produit, catégorie et personne. Une anomalie n'est jamais qualifiée automatiquement de vol.</p><p>Validation numérique moteur <?= e(\App\Services\ExternalAuditEngine::VERSION) ?> : <code><?= e(hash('sha256', json_encode($period['totals']))) ?></code></p></section>
