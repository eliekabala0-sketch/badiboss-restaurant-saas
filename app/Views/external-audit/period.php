<section class="topbar"><div class="brand"><h1>Rapport Audit externe indépendant</h1><p><?= e($restaurant['name'] ?? '') ?> · <?= e($period['from']) ?> au <?= e($period['to']) ?> · données Audit externe figées</p></div></section>
<div class="actions" style="margin-bottom:22px"><a href="/audit-externe/export/excel?from=<?= e($period['from']) ?>&to=<?= e($period['to']) ?>">Télécharger Excel</a> <a href="/audit-externe/export/pdf?from=<?= e($period['from']) ?>&to=<?= e($period['to']) ?>">Télécharger PDF</a> <a href="/audit-externe">Retour</a></div>

<section class="grid stats">
<?php foreach (['Ventes calculées'=>'calculated_sales','Ventes déclarées'=>'declared_sales','Achats'=>'purchases','Dépenses'=>'expenses','Manquants'=>'missing_amount','Suspects'=>'suspicious_amount','Injections'=>'injection_amount','Attendu'=>'expected_amount','Présenté'=>'presented_cash','Écart caisse'=>'cash_gap'] as $label=>$key): ?>
<article class="card stat"><span><?= e($label) ?></span><strong><?= number_format((float) $period['totals'][$key],0,',',' ') ?></strong></article><?php endforeach; ?>
</section>

<section class="grid stats">
<?php foreach ([
    'Rapports attendus' => $period['tracking']['summary']['expected'],
    'Rapports recus' => $period['tracking']['summary']['received'],
    'Rapports manquants' => $period['tracking']['summary']['missing'],
    'Rapports en retard' => $period['tracking']['summary']['late'],
    'Serveurs actifs' => $period['tracking']['summary']['active_server_count'],
    'Rapports serveurs recus' => $period['tracking']['summary']['server_reports_received'],
    'Rapports serveurs manquants' => $period['tracking']['summary']['server_reports_missing'],
] as $label => $value): ?><article class="card stat"><span><?= e($label) ?></span><strong><?= (int) $value ?></strong></article><?php endforeach; ?>
</section>

<section class="card" style="padding:22px;margin-bottom:22px"><h2>Suivi des depots et retards</h2>
<div class="table-wrap"><table><thead><tr><th>Nom</th><th>Fonction</th><th>Rapport attendu</th><th>Rapport recu</th><th>Date activite</th><th>Heure limite</th><th>Heure depot</th><th>Retard</th><th>Statut</th></tr></thead><tbody>
<?php foreach ($period['tracking']['rows'] as $trackingRow): ?><tr><td><?= e($trackingRow['name']) ?></td><td><?= e($trackingRow['function']) ?></td><td><?= e($trackingRow['expected_report']) ?></td><td><?= $trackingRow['received'] ? 'Oui' : 'Non' ?></td><td><?= e($trackingRow['activity_date']) ?></td><td><?= e($trackingRow['deadline_time']) ?></td><td><?= e($trackingRow['submission_time'] ?? '-') ?></td><td><?= e($trackingRow['delay']) ?></td><td><?= e($trackingRow['status']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>

<section class="card" style="padding:22px;margin-bottom:22px"><h2>Indicateurs de ponctualite par utilisateur</h2>
<div class="table-wrap"><table><thead><tr><th>Nom</th><th>Fonction</th><th>Attendus</th><th>Remis</th><th>Manquants</th><th>En retard</th><th>Taux ponctualite</th></tr></thead><tbody>
<?php foreach ($period['tracking']['indicators'] as $indicator): ?><tr><td><?= e($indicator['name']) ?></td><td><?= e($indicator['function']) ?></td><td><?= (int) $indicator['expected'] ?></td><td><?= (int) $indicator['received'] ?></td><td><?= (int) $indicator['missing'] ?></td><td><?= (int) $indicator['late'] ?></td><td><?= e((string) $indicator['punctuality_rate']) ?> %</td></tr><?php endforeach; ?>
</tbody></table></div>
<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr));margin-top:18px">
<?php foreach (['most_punctual' => 'Utilisateurs les plus ponctuels','most_late' => 'Utilisateurs ayant le plus de retards','most_missing' => 'Utilisateurs ayant le plus de rapports manquants'] as $rankingKey => $rankingLabel): ?><article><h3><?= e($rankingLabel) ?></h3><ol><?php foreach (array_slice($period['tracking']['rankings'][$rankingKey],0,10) as $ranked): ?><li><?= e($ranked['name']) ?> · <?= $rankingKey === 'most_punctual' ? e((string) $ranked['punctuality_rate']) . ' %' : (int) $ranked[$rankingKey === 'most_late' ? 'late' : 'missing'] ?></li><?php endforeach; ?></ol></article><?php endforeach; ?>
</div></section>

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
