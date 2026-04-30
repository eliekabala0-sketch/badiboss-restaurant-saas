<?php
declare(strict_types=1);

$subscriptionTimezone = safe_timezone($subscription['timezone'] ?? ($restaurant['timezone'] ?? null));
$restaurantCurrency = restaurant_currency($restaurant);
$printQuery = http_build_query(['print' => '1']);
$decisionBadgeClass = static function (?string $status): string {
    return match ((string) $status) {
        'EN_ATTENTE_VALIDATION_MANAGER' => 'badge-progress',
        'VALIDE' => 'badge-closed',
        'REJETE' => 'badge-bad',
        default => 'badge-neutral',
    };
};
?>
<style>
@media print {
    .no-print { display:none !important; }
    .card { box-shadow:none !important; border:1px solid #d6d6d6; }
}
</style>
<section class="topbar">
    <div class="brand">
        <h1><?= e(($user['role_code'] ?? '') === 'manager' ? 'Pilotage opérationnel' : 'Pilotage du restaurant') ?></h1>
        <p>Suivez votre restaurant en temps réel, avec un abonnement fiable, des accès cohérents et une traçabilité exploitable jusqu’aux décisions du gérant.</p>
    </div>
    <?php if (restaurant_status_blocks_operations($restaurant['status'] ?? null)): ?>
        <div class="compact-empty">Le tableau de bord reste visible pour information, mais les actions métier sont bloquées tant que le restaurant n’est pas réactivé.</div>
    <?php endif; ?>
</section>

<?php if (!empty($flash_success)): ?><div class="flash-ok"><?= e($flash_success) ?></div><?php endif; ?>
<?php if (!empty($flash_error)): ?><div class="flash-bad"><?= e($flash_error) ?></div><?php endif; ?>
<section class="card no-print" style="padding:18px; margin-bottom:24px;">
    <div class="toolbar-actions">
        <button type="button" onclick="window.print()">Imprimer</button>
        <a href="/owner?<?= e($printQuery) ?>" class="button-muted" target="_blank" rel="noopener noreferrer">Export imprimable / PDF navigateur</a>
    </div>
</section>
<?php if (restaurant_status_blocks_operations($restaurant['status'] ?? null)): ?>
    <section class="status-banner status-<?= e(restaurant_status_severity($restaurant['status'] ?? null)) ?>">
        <div>
            <strong><?= e(status_label($restaurant['status'] ?? null)) ?></strong>
            <div><?= e(restaurant_status_message($restaurant['status'] ?? null) ?? 'Accès limité au restaurant.') ?></div>
        </div>
        <span class="pill <?= restaurant_status_severity($restaurant['status'] ?? null) === 'danger' ? 'badge-bad' : 'badge-progress' ?>">Actions métier bloquées</span>
    </section>
<?php endif; ?>

<section class="grid stats">
    <article class="card stat"><span>Restaurant</span><strong><?= e($restaurant['public_name'] ?? $restaurant['name'] ?? '-') ?></strong></article>
    <article class="card stat"><span>Code</span><strong><?= e($restaurant['restaurant_code'] ?? '-') ?></strong></article>
    <article class="card stat"><span>Abonnement</span><strong><?= e(subscription_status_label($subscription['status'] ?? null)) ?></strong></article>
    <article class="card stat"><span>Paiement</span><strong><?= e(subscription_payment_label($subscription['payment_status'] ?? null)) ?></strong></article>
</section>

<?php
$restaurantAccessUrl = restaurant_generated_access_url($restaurant);
$restaurantRegisterUrl = restaurant_generated_registration_url($restaurant);
?>

<section class="card" style="padding:24px; margin-bottom:24px;">
    <div class="topbar" style="margin-bottom:18px;">
        <div>
            <h2 style="margin:0;">Liens du restaurant</h2>
            <p class="muted" style="margin:6px 0 0;">Lien public, lien d'inscription client et code restaurant a partager sans manipulation technique.</p>
        </div>
        <span class="pill badge-gold">Portail public</span>
    </div>

    <div class="grid" style="grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));">
        <div class="link-box">
            <strong>Lien d'acces restaurant</strong>
            <a href="<?= e(restaurant_generated_access_path($restaurant)) ?>" target="_blank" rel="noopener noreferrer" id="owner-restaurant-link" data-copy-value="<?= e($restaurantAccessUrl) ?>"><?= e($restaurantAccessUrl) ?></a>
            <div class="toolbar-actions">
                <button type="button" class="button-muted" data-copy-target="#owner-restaurant-link">Copier le lien</button>
                <a href="<?= e(restaurant_generated_access_path($restaurant)) ?>" target="_blank" rel="noopener noreferrer" class="button-muted">Ouvrir</a>
            </div>
        </div>

        <div class="link-box">
            <strong>Lien d'inscription client</strong>
            <a href="<?= e(restaurant_generated_registration_path($restaurant)) ?>" target="_blank" rel="noopener noreferrer" id="owner-register-link" data-copy-value="<?= e($restaurantRegisterUrl) ?>"><?= e($restaurantRegisterUrl) ?></a>
            <div class="toolbar-actions">
                <button type="button" class="button-muted" data-copy-target="#owner-register-link">Copier le lien</button>
                <a href="<?= e(restaurant_generated_registration_path($restaurant)) ?>" target="_blank" rel="noopener noreferrer" class="button-muted">Ouvrir</a>
            </div>
        </div>

        <div class="link-box">
            <strong>Code restaurant</strong>
            <span id="owner-restaurant-code" data-copy-value="<?= e($restaurant['restaurant_code'] ?? '-') ?>"><?= e($restaurant['restaurant_code'] ?? '-') ?></span>
            <div class="toolbar-actions">
                <button type="button" class="button-muted" data-copy-target="#owner-restaurant-code">Copier le code</button>
            </div>
            <span class="muted">Le code permet aussi l'inscription client sans passer par le lien direct.</span>
        </div>
    </div>
</section>

<section class="split">
    <article class="card" style="padding:24px;">
        <h2 style="margin-top:0;">Statut du restaurant</h2>
        <p><strong>Nom :</strong> <?= e($restaurant['name'] ?? '-') ?></p>
        <p><strong>Rôle courant :</strong> <?= e(restaurant_role_label($user['role_code'] ?? null)) ?></p>
        <p><strong>Aujourd’hui :</strong> <?= e(((new DateTimeImmutable(($subscription['today'] ?? 'now') . ' 00:00:00', $subscriptionTimezone))->format('d/m/Y'))) ?></p>
        <p><strong>Début abonnement :</strong> <?= e(format_date_fr($subscription['started_at'] ?? null, $subscriptionTimezone)) ?></p>
        <p><strong>Fin abonnement :</strong> <?= e(format_date_fr($subscription['ends_at'] ?? null, $subscriptionTimezone)) ?></p>
        <p><strong>Fin de grâce :</strong> <?= e(format_date_fr($subscription['grace_ends_at'] ?? null, $subscriptionTimezone)) ?></p>
        <p><strong>Jours restants :</strong> <?= e((string) ($subscription['days_remaining'] ?? '-')) ?></p>
        <p><strong>Jours expirés :</strong> <?= e((string) ($subscription['days_expired'] ?? '-')) ?></p>
        <p><strong>Message :</strong> <?= e($subscription['message'] ?? 'Aucun message') ?></p>
        <?php if (($subscription['status'] ?? null) !== 'ACTIVE' && ($subscription['status'] ?? null) !== 'GRACE_PERIOD'): ?>
            <form method="post" action="/owner/subscription/pay">
                <button type="submit">Payer l’abonnement</button>
            </form>
        <?php endif; ?>
    </article>

    <article class="card" style="padding:24px;">
        <h2 style="margin-top:0;">Accès disponibles</h2>
        <p><strong>Stock :</strong> <?= $can_access_stock ? 'Oui' : 'Non' ?></p>
        <p><strong>Cuisine :</strong> <?= $can_access_kitchen ? 'Oui' : 'Non' ?></p>
        <p><strong>Ventes :</strong> <?= $can_access_sales ? 'Oui' : 'Non' ?></p>
        <p><strong>Rapports :</strong> <?= $can_access_reports ? 'Oui' : 'Non' ?></p>
        <p class="muted">Les écritures et les rapports avancés restent verrouillés tant que l’abonnement n’est pas opérationnel.</p>
    </article>
</section>

<section class="card" style="padding:24px; margin-top:24px;">
    <h2 style="margin-top:0;">Parametres du restaurant</h2>
    <p class="muted">La devise change uniquement l affichage du restaurant courant. Aucun montant historique n est converti.</p>
    <form method="post" action="/owner/settings/currency" class="split no-print">
        <div>
            <label>Devise du restaurant</label>
            <select name="currency">
                <option value="USD" <?= $restaurantCurrency === 'USD' ? 'selected' : '' ?>>USD</option>
                <option value="CDF" <?= $restaurantCurrency === 'CDF' ? 'selected' : '' ?>>CDF</option>
            </select>
        </div>
        <div style="align-self:end;">
            <button type="submit">Enregistrer</button>
        </div>
    </form>
    <p><strong>Devise active :</strong> <?= e($restaurantCurrency) ?></p>
</section>

<section class="card" style="padding:24px; margin-top:24px;">
    <h2 style="margin-top:0;">Orientation rapide</h2>
    <div class="nav" style="margin-bottom:0;">
        <?php if (!restaurant_status_blocks_operations($restaurant['status'] ?? null) && $can_access_stock): ?><a href="/stock">Ouvrir Stock</a><?php endif; ?>
        <?php if (!restaurant_status_blocks_operations($restaurant['status'] ?? null) && $can_access_kitchen): ?><a href="/cuisine">Ouvrir Cuisine</a><?php endif; ?>
        <?php if (!restaurant_status_blocks_operations($restaurant['status'] ?? null) && $can_access_sales): ?><a href="/ventes">Ouvrir Ventes</a><?php endif; ?>
        <?php if (!restaurant_status_blocks_operations($restaurant['status'] ?? null) && $can_access_reports): ?><a href="/rapport">Voir les rapports</a><?php endif; ?>
        <?php if (!restaurant_status_blocks_operations($restaurant['status'] ?? null)): ?><a href="/owner/menu">Voir le menu</a><?php endif; ?>
        <?php if (can_access('tenant.access.manage')): ?><a href="/owner/access">Rôles et accès</a><?php endif; ?>
    </div>
</section>

<?php if (!empty($sales_period_totals)): ?>
    <section class="card" style="padding:24px; margin-top:24px;">
        <h2 style="margin-top:0;">Total vendu par serveur</h2>
        <p class="muted">Jour, semaine et mois restent lisibles au même endroit, avec le total général et le détail par serveur.</p>

        <div class="grid">
            <?php foreach ($sales_period_totals as $period): ?>
                <article class="card" style="padding:18px; border-radius:16px;">
                    <div class="topbar" style="margin-bottom:12px;">
                        <strong><?= e($period['label']) ?></strong>
                        <span class="pill badge-gold"><?= e(format_money($period['total_general'] ?? 0, $restaurant)) ?></span>
                    </div>

                    <?php if (($period['sales_by_server'] ?? []) === []): ?>
                        <p class="muted">Aucune vente validée sur cette période.</p>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                <tr>
                                    <th>Serveur</th>
                                    <th>Ventes</th>
                                    <th>Montant</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($period['sales_by_server'] as $row): ?>
                                    <tr>
                                        <td><?= e(named_actor_label($row['server_name'] ?? null, 'cashier_server')) ?></td>
                                        <td><?= e((string) $row['sales_count']) ?></td>
                                        <td><?= e(format_money($row['total_amount'], $restaurant)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($manager_queue_cases !== []): ?>
    <section class="card" style="padding:24px; margin-top:24px;">
        <div class="topbar" style="margin-bottom:18px;">
            <div>
                <h2 style="margin:0;">À décider par le gérant</h2>
                <p class="muted" style="margin:6px 0 0;">Chaque arbitrage repose uniquement sur les acteurs réellement présents dans la trace applicative.</p>
            </div>
            <span class="pill badge-bad"><?= e((string) count($manager_queue_cases)) ?> cas</span>
        </div>

        <div class="grid">
            <?php foreach ($manager_queue_cases as $case): ?>
                <article class="card" style="padding:20px; border-radius:16px;">
                    <div class="topbar" style="margin-bottom:12px;">
                        <div>
                            <strong>Cas #<?= e((string) $case['id']) ?> · <?= e(case_source_label($case['source_module'] ?? null)) ?></strong>
                            <div class="muted">
                                <?= e($case['trace']['origin_label'] ?? ($case['reported_category'] ?? $case['case_type'])) ?>
                                · <?= e(format_date_fr($case['submitted_to_manager_at'] ?? $case['technical_confirmed_at'] ?? $case['created_at'])) ?>
                                · Produit <?= e($case['trace']['product_name'] ?? ($case['stock_item_name'] ?? 'Produit')) ?>
                            </div>
                        </div>
                        <span class="pill <?= e($decisionBadgeClass($case['status'])) ?>"><?= e(validation_status_label($case['status'])) ?></span>
                    </div>

                    <p><strong>Origine :</strong> <?= e($case['trace']['source_summary'] ?? ($case['reported_category'] ?? $case['case_type'])) ?></p>
                    <p><strong>Quantité concernée :</strong> <?= e((string) ($case['trace']['quantity_affected'] ?? $case['quantity_affected'])) ?> <?= e((string) ($case['trace']['unit_name'] ?? $case['unit_name'])) ?></p>

                    <?php if (($case['trace']['metrics'] ?? []) !== []): ?>
                        <div class="table-wrap" style="margin-top:12px;">
                            <table>
                                <thead>
                                <tr>
                                    <th>Repère</th>
                                    <th>Valeur</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($case['trace']['metrics'] as $metric): ?>
                                    <tr>
                                        <td><?= e($metric['label'] ?? '-') ?></td>
                                        <td><?= e($metric['value'] ?? '-') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <div class="split" style="margin-top:16px;">
                        <div>
                            <h3 style="margin:0 0 10px;">Chaîne de responsabilité</h3>
                            <?php if (($case['trace']['steps'] ?? []) === []): ?>
                                <p class="muted">Aucune étape détaillée disponible.</p>
                            <?php else: ?>
                                <?php foreach ($case['trace']['steps'] as $step): ?>
                                    <div style="padding:12px 14px; border:1px solid var(--line); border-radius:14px; margin-bottom:10px;">
                                        <strong><?= e($step['label'] ?? '-') ?></strong>
                                        <div class="muted">
                                            <?= e($step['actor_name'] ?? 'Agent') ?>
                                            <?php if (!empty($step['role_code'])): ?> · <?= e(restaurant_role_label($step['role_code'])) ?><?php endif; ?>
                                            <?php if (!empty($step['time'])): ?> · <?= e(format_date_fr($step['time'])) ?><?php endif; ?>
                                        </div>
                                        <?php if (!empty($step['details'])): ?><div><?= e($step['details']) ?></div><?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <div>
                            <h3 style="margin:0 0 10px;">Acteurs liés seulement</h3>
                            <?php if (($case['linked_actors'] ?? []) === []): ?>
                                <p class="muted">Aucun acteur exploitable n’a été trouvé dans la trace.</p>
                            <?php else: ?>
                                <?php foreach ($case['linked_actors'] as $actor): ?>
                                    <div style="padding:12px 14px; border:1px solid var(--line); border-radius:14px; margin-bottom:10px;">
                                        <strong><?= e($actor['name']) ?></strong>
                                        <div class="muted">
                                            <?= e(restaurant_role_label($actor['role_code'] ?? null)) ?>
                                            · <?= e($actor['reason'] ?? 'Trace applicative') ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <?php if (($case['trace']['notes'] ?? []) !== []): ?>
                                <h3 style="margin:16px 0 10px;">Observations déjà enregistrées</h3>
                                <?php foreach ($case['trace']['notes'] as $note): ?>
                                    <div style="padding:12px 14px; border:1px solid var(--line); border-radius:14px; margin-bottom:10px;"><?= e($note) ?></div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (can_access('incident.decide')): ?>
                        <form method="post" action="/operations/cases/<?= e((string) $case['id']) ?>/decision" class="split" style="margin-top:18px;">
                            <input type="hidden" name="redirect_to" value="/owner">
                            <div>
                                <label>Décision finale</label>
                                <select name="decision_status">
                                    <option value="VALIDE">Valider</option>
                                    <option value="REJETE">Rejeter</option>
                                </select>
                            </div>
                            <div>
                                <label>Qualification finale</label>
                                <select name="final_qualification">
                                    <?php foreach ($final_qualifications as $qualification): ?>
                                        <option value="<?= e($qualification) ?>"><?= e($qualification) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label>Mode d’imputation</label>
                                <select name="responsibility_scope">
                                    <option value="restaurant">Restaurant</option>
                                    <option value="sans_faute_individuelle">Sans faute individuelle</option>
                                    <option value="agent_lie">Agent lié à la trace</option>
                                </select>
                            </div>
                            <div>
                                <label>Agent lié concerné</label>
                                <select name="responsible_user_id">
                                    <option value="0">Aucun agent individuel</option>
                                    <?php foreach (($case['linked_actors'] ?? []) as $actor): ?>
                                        <option value="<?= e((string) $actor['id']) ?>"><?= e($actor['name']) ?> · <?= e(restaurant_role_label($actor['role_code'] ?? null)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label>Perte matière</label>
                                <input name="material_loss_amount" value="0">
                            </div>
                            <div>
                                <label>Perte argent</label>
                                <input name="cash_loss_amount" value="0">
                            </div>
                            <div style="grid-column:1 / -1;">
                                <label>Justification du gérant</label>
                                <textarea name="manager_justification" required>Décision motivée à partir de la traçabilité disponible.</textarea>
                            </div>
                            <div style="grid-column:1 / -1;">
                                <button type="submit">Enregistrer la décision motivée</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <p class="muted" style="margin-top:16px;">Ce cas attend l’arbitrage du gérant. Le propriétaire peut suivre la trace et la décision finale sans intervenir dans l’imputation.</p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($case_decision_history !== []): ?>
    <section class="card" style="padding:24px; margin-top:24px;">
        <h2 style="margin-top:0;">Décisions enregistrées</h2>
        <p class="muted">Le propriétaire garde une visibilité claire sur la justification, les acteurs liés et l’impact financier de chaque arbitrage.</p>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Cas</th>
                    <th>Décision</th>
                    <th>Imputation</th>
                    <th>Justification</th>
                    <th>Date</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($case_decision_history as $case): ?>
                    <tr>
                        <td>
                            #<?= e((string) $case['id']) ?> · <?= e(case_source_label($case['source_module'] ?? null)) ?><br>
                            <span class="muted"><?= e($case['trace']['product_name'] ?? ($case['stock_item_name'] ?? 'Produit')) ?></span>
                        </td>
                        <td>
                            <?= e($case['final_qualification'] ?? '-') ?><br>
                            <span class="muted"><?= e(validation_status_label($case['status'])) ?></span>
                        </td>
                        <td>
                            <?= e(case_responsibility_label($case['responsibility_scope'] ?? null, $case['responsible_user_name'] ?? null)) ?><br>
                            <span class="muted">
                                Matière <?= e((string) ($case['material_loss_amount'] ?? 0)) ?>
                                · Argent <?= e((string) ($case['cash_loss_amount'] ?? 0)) ?>
                            </span>
                        </td>
                        <td><?= e($case['manager_justification'] ?? '-') ?></td>
                        <td><?= e(signed_actor_line('Decide', $case['decided_by_name'] ?? null, 'manager', $case['decided_at'] ?? $case['resolved_at'] ?? $case['created_at'], $restaurant, $subscriptionTimezone)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
