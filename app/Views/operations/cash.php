<?php
declare(strict_types=1);

$restaurantCurrency = restaurant_currency($restaurant);
$summary = $cash['summary'] ?? [];
$transfers = $cash['transfers'] ?? [];
$movements = $cash['movements'] ?? [];
$pendingServerSales = $cash['pending_server_sales'] ?? [];
$cashiers = $cash['cashiers'] ?? [];
?>

<section class="topbar">
    <div class="brand">
        <h1>Caisse</h1>
        <p>Suivi compact de l argent du serveur vers la caisse, puis vers le gerant et le proprietaire, sans melanger les restaurants.</p>
    </div>
</section>

<?php if (!empty($flash_success)): ?><div class="flash-ok"><?= e($flash_success) ?></div><?php endif; ?>
<?php if (!empty($flash_error)): ?><div class="flash-bad"><?= e($flash_error) ?></div><?php endif; ?>

<section class="grid stats">
    <article class="card stat"><span>Total vendu</span><strong><?= e(format_money($summary['total_sold'] ?? 0, $restaurantCurrency)) ?></strong></article>
    <article class="card stat"><span>Remis a caisse</span><strong><?= e(format_money($summary['total_remitted_to_cash'] ?? 0, $restaurantCurrency)) ?></strong></article>
    <article class="card stat"><span>Recu caisse</span><strong><?= e(format_money($summary['total_received_by_cash'] ?? 0, $restaurantCurrency)) ?></strong></article>
    <article class="card stat"><span>Depenses</span><strong><?= e(format_money($summary['cash_expenses'] ?? 0, $restaurantCurrency)) ?></strong></article>
    <article class="card stat"><span>Solde caisse</span><strong><?= e(format_money($summary['cash_balance'] ?? 0, $restaurantCurrency)) ?></strong></article>
    <article class="card stat"><span>Ecarts</span><strong><?= e(format_money($summary['discrepancies'] ?? 0, $restaurantCurrency)) ?></strong></article>
</section>

<?php $ccp = $cash_clarity_period ?? []; ?>
<section class="card" style="padding:22px; margin-top:20px;">
    <h2 style="margin-top:0;">Caisse claire (période des filtres)</h2>
    <?php if ($ccp !== []): ?>
        <p class="muted" style="margin-top:0;">Résumé du <?= e((string) ($ccp['period_from'] ?? '')) ?> au <?= e((string) ($ccp['period_to'] ?? '')) ?> · entrées +, sorties −.</p>
        <ul style="margin:0; padding-left:20px; line-height:1.7;">
            <li><strong>Argent versé par les serveurs</strong> : + <?= e(format_money((float) ($ccp['server_remittance_total'] ?? 0), $restaurantCurrency)) ?></li>
            <li><strong>Total reçu par la caisse</strong> (ventes) : + <?= e(format_money((float) ($ccp['cashier_received_sales'] ?? 0), $restaurantCurrency)) ?></li>
            <li><strong>Total remis au gérant</strong> : − <?= e(format_money((float) ($ccp['declared_to_manager'] ?? 0), $restaurantCurrency)) ?></li>
            <li><strong>Total reçu par le gérant</strong> : + <?= e(format_money((float) ($ccp['manager_received'] ?? 0), $restaurantCurrency)) ?></li>
            <li><strong>Total remis au propriétaire</strong> : − <?= e(format_money((float) ($ccp['declared_to_owner'] ?? 0), $restaurantCurrency)) ?></li>
            <li><strong>Total reçu par le propriétaire</strong> : + <?= e(format_money((float) ($ccp['owner_received'] ?? 0), $restaurantCurrency)) ?></li>
            <li><strong>Solde caisse</strong> (vue résumé sur la plage filtre) : <?= e(format_money((float) ($ccp['cash_balance'] ?? 0), $restaurantCurrency)) ?></li>
            <li><strong>Solde gérant</strong> (reçu − déclaré vers propriétaire) : <?= e(format_money((float) ($ccp['manager_net_period'] ?? 0), $restaurantCurrency)) ?></li>
            <li><strong>Écarts signalés</strong> : <?= e(format_money((float) ($ccp['discrepancy_total'] ?? 0), $restaurantCurrency)) ?></li>
        </ul>
    <?php else: ?>
        <p class="muted">Chargez les données de caisse pour voir la synthèse.</p>
    <?php endif; ?>
</section>

<section class="card" style="padding:22px; margin-top:24px;">
    <details class="compact-card" data-autoclose-details>
        <summary><strong>Afficher les filtres</strong></summary>
        <form method="get" action="/caisse" class="split" style="margin-top:14px;">
            <div><label>Date debut</label><input type="date" name="date_from" value="<?= e((string) ($filters['date_from'] ?? '')) ?>"></div>
            <div><label>Date fin</label><input type="date" name="date_to" value="<?= e((string) ($filters['date_to'] ?? '')) ?>"></div>
            <div><label>Statut transfert</label><input name="status" value="<?= e((string) ($filters['status'] ?? '')) ?>" placeholder="RECU_CAISSE"></div>
            <div><label>Type mouvement</label><input name="movement_type" value="<?= e((string) ($filters['movement_type'] ?? '')) ?>" placeholder="DEPENSE"></div>
            <div><label>Utilisateur</label>
                <select name="user_id">
                    <option value="0">Tous</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= e((string) $user['id']) ?>" <?= (int) ($filters['user_id'] ?? 0) === (int) $user['id'] ? 'selected' : '' ?>><?= e(named_actor_label($user['full_name'] ?? null, $user['role_code'] ?? null)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="align-self:end;"><button type="submit">Filtrer</button></div>
        </form>
    </details>
</section>

<section class="split" style="margin-top:24px;">
    <article class="card" style="padding:22px;">
        <details class="compact-card" data-autoclose-details>
            <summary><strong>Serveur vers caisse</strong></summary>
            <p class="muted" style="margin-top:14px;">La remise part d une vente cloturee. Le montant vient toujours de la vente et ne se saisit pas librement.</p>
            <?php if ($pendingServerSales === []): ?>
                <p class="muted">Aucune vente cloturee en attente de remise.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Vente</th><th>Serveur</th><th>Montant</th><th>Trace</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($pendingServerSales as $sale): ?>
                            <tr>
                                <td>#<?= e((string) $sale['sale_id']) ?></td>
                                <td><?= e(named_actor_label($sale['server_name'] ?? null, 'cashier_server')) ?></td>
                                <td><?= e(format_money($sale['sale_total_amount'] ?? 0, $restaurantCurrency)) ?></td>
                                <td>
                                    Vente liee
                                    <?php if (!empty($sale['server_request_id'])): ?>
                                        <br><span class="muted">Demande #<?= e((string) $sale['server_request_id']) ?> - <?= e((string) ($sale['service_reference'] ?? '-')) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="post" action="/caisse/remises-serveur">
                                        <input type="hidden" name="sale_id" value="<?= e((string) $sale['sale_id']) ?>">
                                        <select name="to_user_id" style="margin-bottom:10px;">
                                            <?php foreach ($cashiers as $cashier): ?>
                                                <option value="<?= e((string) $cashier['id']) ?>"><?= e(named_actor_label($cashier['full_name'] ?? null, $cashier['role_code'] ?? null)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" <?= $cashiers === [] ? 'disabled' : '' ?>>Enregistrer la remise</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </details>
    </article>

    <article class="card" style="padding:22px;">
        <details class="compact-card" data-autoclose-details>
            <summary><strong>Entrée / sortie / dépense</strong></summary>
            <form method="post" action="/caisse/mouvements" style="margin-top:14px;">
                <label>Type</label>
                <select name="movement_type">
                    <option value="ENTREE">Entree</option>
                    <option value="SORTIE">Sortie</option>
                    <option value="DEPENSE">Depense</option>
                    <option value="AJUSTEMENT">Ajustement</option>
                </select>
                <label>Montant</label>
                <input name="amount" value="0">
                <label>Note</label>
                <textarea name="note">Mouvement de caisse justifie.</textarea>
                <button type="submit">Enregistrer</button>
            </form>
        </details>
    </article>
</section>

<section class="split" style="margin-top:24px;">
    <article class="card" style="padding:22px;">
        <details class="compact-card" data-autoclose-details>
            <summary><strong>Caisse vers gerant</strong></summary>
            <form method="post" action="/caisse/remises-gerant" style="margin-top:14px;">
                <label>Gerant</label>
                <select name="to_user_id">
                    <?php foreach (($cash['managers'] ?? []) as $manager): ?>
                        <option value="<?= e((string) $manager['id']) ?>"><?= e(named_actor_label($manager['full_name'] ?? null, $manager['role_code'] ?? null)) ?></option>
                    <?php endforeach; ?>
                </select>
                <label>Montant</label>
                <input name="amount" value="0">
                <label>Note</label>
                <textarea name="note">Remise caisse vers gerant.</textarea>
                <button type="submit">Remettre au gerant</button>
            </form>
        </details>
    </article>

    <article class="card" style="padding:22px;">
        <details class="compact-card" data-autoclose-details>
            <summary><strong>Gerant vers proprietaire</strong></summary>
            <form method="post" action="/caisse/remises-proprietaire" style="margin-top:14px;">
                <label>Proprietaire</label>
                <select name="to_user_id">
                    <?php foreach (($cash['owners'] ?? []) as $owner): ?>
                        <option value="<?= e((string) $owner['id']) ?>"><?= e(named_actor_label($owner['full_name'] ?? null, $owner['role_code'] ?? null)) ?></option>
                    <?php endforeach; ?>
                </select>
                <label>Montant</label>
                <input name="amount" value="0">
                <label>Note</label>
                <textarea name="note">Remise gerant vers proprietaire.</textarea>
                <button type="submit">Remettre au proprietaire</button>
            </form>
        </details>
    </article>
</section>

<section class="card" style="padding:22px; margin-top:24px;">
    <details class="compact-card">
        <summary><strong>Historique des remises</strong></summary>
    <h2 style="margin-top:14px;">Historique des remises</h2>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Flux</th>
                <th>Vente liee</th>
                <th>Montant</th>
                <th>Statut</th>
                <th>Heure</th>
                <th>Action</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($transfers as $transfer): ?>
                <tr>
                    <td><?= e(named_actor_label($transfer['from_user_name'] ?? null)) ?> -> <?= e(named_actor_label($transfer['to_user_name'] ?? null)) ?></td>
                    <td>
                        <?php if (!empty($transfer['sale_id'])): ?>
                            <strong>#<?= e((string) $transfer['sale_id']) ?></strong>
                            <?php if (!empty($transfer['server_request_id'])): ?>
                                <br><span class="muted">Demande #<?= e((string) $transfer['server_request_id']) ?> - <?= e((string) ($transfer['service_reference'] ?? '-')) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="muted">Sans vente liee</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e(format_money($transfer['amount'] ?? 0, $restaurantCurrency)) ?></td>
                    <td>
                        <?= e(cash_transfer_status_label($transfer['status'] ?? null)) ?>
                        <?php if ((float) ($transfer['discrepancy_amount'] ?? 0) != 0.0): ?>
                            <br><span class="muted">Ecart <?= e(format_money($transfer['discrepancy_amount'] ?? 0, $restaurantCurrency)) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= e(format_date_fr($transfer['received_at'] ?? $transfer['requested_at'] ?? $transfer['created_at'] ?? null)) ?></td>
                    <td>
                        <?php if (($transfer['status'] ?? '') === 'REMIS_A_CAISSE'): ?>
                            <form method="post" action="/caisse/transferts/<?= e((string) $transfer['id']) ?>/reception-caisse">
                                <input name="amount_received" value="<?= e((string) ($transfer['amount'] ?? 0)) ?>">
                                <textarea name="discrepancy_note" placeholder="Justification si ecart"></textarea>
                                <button type="submit">Confirmer caisse</button>
                            </form>
                            <?php if (can_access('cash.receive.cashier') && (string) ($transfer['source_type'] ?? '') === 'sale'): ?>
                                <form method="post" action="/caisse/transferts/<?= e((string) $transfer['id']) ?>/soumission-gerant" style="margin-top:12px;" onsubmit="return confirm('Soumettre cette remise au gérant ? Le comptage physique restera en attente de sa décision.');">
                                    <label class="muted">Motif soumission gérant</label>
                                    <textarea name="reason" required placeholder="Contexte obligatoire pour le gérant"></textarea>
                                    <button type="submit">Soumettre au gérant</button>
                                </form>
                                <form method="post" action="/caisse/transferts/<?= e((string) $transfer['id']) ?>/rejet-remise" style="margin-top:12px;" onsubmit="return confirm('Rejeter définitivement cette remise côté caisse ? Le serveur pourra refaire une remise après correction.');">
                                    <label class="muted">Motif rejet caisse</label>
                                    <textarea name="reason" required placeholder="Motif obligatoire"></textarea>
                                    <button type="submit">Rejeter la remise</button>
                                </form>
                            <?php endif; ?>
                        <?php elseif (($transfer['status'] ?? '') === 'REMIS_A_GERANT'): ?>
                            <form method="post" action="/caisse/transferts/<?= e((string) $transfer['id']) ?>/reception-gerant">
                                <input name="amount_received" value="<?= e((string) ($transfer['amount'] ?? 0)) ?>">
                                <button type="submit">Confirmer gerant</button>
                            </form>
                        <?php elseif (($transfer['status'] ?? '') === 'REMIS_A_PROPRIETAIRE'): ?>
                            <form method="post" action="/caisse/transferts/<?= e((string) $transfer['id']) ?>/reception-proprietaire">
                                <input name="amount_received" value="<?= e((string) ($transfer['amount'] ?? 0)) ?>">
                                <button type="submit">Confirmer proprietaire</button>
                            </form>
                        <?php else: ?>
                            <span class="muted"><?= e((string) ($transfer['note'] ?? '-')) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    </details>
</section>

<section class="card" style="padding:22px; margin-top:24px;">
    <details class="compact-card">
        <summary><strong>Historique des mouvements caisse</strong></summary>
    <h2 style="margin-top:14px;">Historique des mouvements caisse</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Type</th><th>Montant</th><th>Acteur</th><th>Note</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($movements as $movement): ?>
                <tr>
                    <td><?= e((string) ($movement['movement_type'] ?? '-')) ?></td>
                    <td><?= e(format_money($movement['amount'] ?? 0, $restaurantCurrency)) ?></td>
                    <td><?= e(named_actor_label($movement['created_by_name'] ?? null)) ?></td>
                    <td><?= e((string) ($movement['note'] ?? '-')) ?></td>
                    <td><?= e(format_date_fr($movement['created_at'] ?? null)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    </details>
</section>
