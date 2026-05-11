<?php
declare(strict_types=1);
/** @var array<string, mixed>|null $manager_resolution_panel */
$mr = $manager_resolution_panel ?? null;
$user = current_user();
$can = \App\Services\ManagerResolutionService::actorCanResolve(is_array($user) ? $user : null);
if (!is_array($mr) || ($mr['entity_kind'] ?? '') === '' || !$can) {
    return;
}
$decisions = $mr['decisions'] ?? [];
if (!is_array($decisions) || $decisions === []) {
    return;
}
$kind = (string) $mr['entity_kind'];
$eid = (int) ($mr['entity_id'] ?? 0);
$restaurantCurrency = restaurant_currency((isset($restaurant) && is_array($restaurant)) ? $restaurant : null);
?>
<section class="card no-print manager-resolution-block" style="padding:18px 22px; margin-bottom:22px; border-left:4px solid var(--brand, #d4af37);">
    <h3 style="margin:0 0 6px;">Décision du responsable</h3>
    <p class="muted" style="margin:0 0 12px; font-size:0.95rem;">Cas bloqué ou litigieux — vous tranchez pour l’équipe, sans repasser par l’agent.</p>
    <p class="muted" style="margin:0 0 12px;"><strong>Situation :</strong> <?= e((string) ($mr['operation_label'] ?? '')) ?></p>
    <dl style="margin:0 0 14px; display:grid; gap:6px;">
        <div><span class="muted">Agent</span> <?= e((string) ($mr['agent_label'] ?? '—')) ?></div>
        <div><span class="muted">Date d’origine</span> <?= e(format_date_fr($mr['origin_at'] ?? null)) ?></div>
        <div><span class="muted">Montant / produits</span> <?= e(format_money((float) ($mr['amount_hint'] ?? 0), $restaurantCurrency)) ?></div>
        <div><span class="muted">Statut actuel</span> <?= e((string) ($mr['status_label'] ?? '')) ?></div>
        <div><span class="muted">Cause du blocage</span> <?= e((string) ($mr['block_reason'] ?? '')) ?></div>
    </dl>
    <?php
    $preview = $mr['sanction_preview']['ledger_preview'] ?? [];
    if (is_array($preview) && $preview !== []): ?>
        <p class="muted" style="margin:0 0 8px;"><strong>Historique discipline (aperçu)</strong></p>
        <ul style="margin:0 0 14px; padding-left:20px; line-height:1.5;">
            <?php foreach (array_slice($preview, -5) as $ln): ?>
                <li><?= e((string) ($ln['label'] ?? '')) ?> · <?= e((string) ($ln['delta_points'] ?? '')) ?> pts · <?= e((string) ($ln['day_ymd'] ?? '')) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <p style="margin:0 0 12px; font-size:0.92rem; color:var(--muted);"><em><?= e((string) ($mr['penalty_message_default'] ?? 'Régularisé par responsable — pénalité conservée')) ?></em></p>

    <?php if ($kind === 'server_request'):
        $focusReturn = 'focus=' . rawurlencode('server_request:' . (string) $eid); ?>
        <form method="post" action="/ventes/resolution-responsable" style="padding-top:12px; border-top:1px solid var(--line);">
            <input type="hidden" name="entity_kind" value="server_request">
            <input type="hidden" name="entity_id" value="<?= e((string) $eid) ?>">
            <input type="hidden" name="return_focus" value="<?= e($focusReturn) ?>">
            <label>Votre décision</label>
            <select name="decision" required>
                <?php foreach ($decisions as $d): ?>
                    <option value="<?= e((string) ($d['code'] ?? '')) ?>"><?= e((string) ($d['label'] ?? '')) ?></option>
                <?php endforeach; ?>
            </select>
            <label>Motif (obligatoire sauf « servie ») — vous pouvez garder le texte proposé</label>
            <textarea name="reason" placeholder="Décision du responsable">Décision du responsable</textarea>
            <label>Référence interne (optionnel)</label>
            <input type="text" name="imputation_basis" placeholder="Ex. réunion, appel">
            <label style="display:flex; align-items:center; gap:10px;">
                <input type="checkbox" name="grant_clemency" value="1"> Clémence (efface la pénalité de retard — motif et audit obligatoires)
            </label>
            <label>Motif de clémence</label>
            <textarea name="clemency_reason" placeholder="Si clémence est cochée"></textarea>
            <button type="submit">Enregistrer la décision</button>
        </form>
    <?php elseif ($kind === 'cash_transfer' || $kind === 'sale'):
        $tid = (int) ($mr['cash_transfer_id'] ?? 0);
        if ($kind === 'cash_transfer') {
            $tid = $eid;
        }
        $focusR = $kind === 'sale' ? ('focus=' . rawurlencode('sale:' . (string) $eid)) : ('focus=' . rawurlencode('cash_transfer:' . (string) $tid));
        if ($tid <= 0): ?>
            <p class="muted" style="margin:0;">Aucun transfert de remise actif : ouvrez une remise depuis la caisse ou la fiche vente.</p>
        <?php else: ?>
        <form method="post" action="/caisse/resolution-responsable" style="padding-top:12px; border-top:1px solid var(--line);">
            <input type="hidden" name="transfer_id" value="<?= e((string) $tid) ?>">
            <input type="hidden" name="return_focus" value="<?= e($focusR) ?>">
            <label>Votre décision</label>
            <select name="decision" required>
                <?php foreach ($decisions as $d): ?>
                    <option value="<?= e((string) ($d['code'] ?? '')) ?>"><?= e((string) ($d['label'] ?? '')) ?></option>
                <?php endforeach; ?>
            </select>
            <label>Montant accepté (uniquement si vous choisissez « partiel »)</label>
            <input type="number" step="0.01" name="amount_accepted" value="">
            <label>Motif — vous pouvez garder le texte proposé</label>
            <textarea name="reason" required placeholder="Décision du responsable">Décision du responsable</textarea>
            <label>Rattachement pour les rapports</label>
            <select name="imputation_basis">
                <option value="">— Par défaut fichier courant —</option>
                <option value="SALE_DAY">Jour de la vente</option>
                <option value="REMITTANCE_DAY">Jour de la remise</option>
                <option value="RESOLUTION_DAY">Jour de cette décision</option>
            </select>
            <label style="display:flex; align-items:center; gap:10px;">
                <input type="checkbox" name="grant_clemency" value="1"> Clémence discipline
            </label>
            <textarea name="clemency_reason" placeholder="Motif clémence si besoin"></textarea>
            <button type="submit">Enregistrer la décision</button>
        </form>
        <?php endif; ?>
    <?php endif; ?>
</section>
