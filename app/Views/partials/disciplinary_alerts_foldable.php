<?php
declare(strict_types=1);
/** @var list<array<string, mixed>> $disciplinary_alerts */
/** @var array<string, mixed>|null $discipline_work_schedule */
$alerts = $disciplinary_alerts ?? [];
$sched = is_array($discipline_work_schedule ?? null) ? $discipline_work_schedule : [];
$canAct = can_access('staff.team_gauges.view');
if (!$canAct || $alerts === []) {
    return;
}
?>
<div class="disciplinary-alerts-block no-print" style="margin-bottom:18px;">
    <h3 style="margin:0 0 10px; font-size:1rem;">Alertes disciplinaires <span class="pill badge-progress"><?= count($alerts) ?></span></h3>
    <p class="muted" style="margin:10px 0 12px; font-size:0.9rem;">Synthèse immédiate (absences, caisse, remises). Actions tracées dans le journal score et l’audit.</p>
    <div style="display:grid; gap:12px;">
        <?php foreach ($alerts as $a): ?>
            <article style="border:1px solid var(--line, #e5e7eb); border-radius:10px; padding:12px;">
                <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:baseline; justify-content:space-between;">
                    <strong><?= e((string) ($a['agent'] ?? '')) ?></strong>
                    <span class="pill <?= (($a['severity'] ?? '') === 'critique') ? 'badge-bad' : 'badge-progress' ?>"><?= e((string) ($a['severity'] ?? '')) ?></span>
                </div>
                <p class="muted" style="margin:6px 0 4px; font-size:0.88rem;"><?= e(role_label((string) ($a['role'] ?? ''))) ?> · <?= e((string) ($a['message'] ?? '')) ?></p>
                <?php if (!empty($a['dates']) && is_array($a['dates'])): ?>
                    <p class="muted" style="margin:0; font-size:0.85rem;">Dates : <?= e(implode(', ', array_map('strval', $a['dates']))) ?></p>
                <?php endif; ?>
                <?php if (isset($a['score_hint']) && $a['score_hint'] !== null && is_numeric($a['score_hint'])): ?>
                    <p class="muted" style="margin:4px 0 0; font-size:0.85rem;">Score mois (indic.) : <?= e((string) $a['score_hint']) ?>/100</p>
                <?php endif; ?>
                <p style="margin:8px 0 0; font-size:0.88rem;"><em>Proposition :</em> <?= e((string) ($a['proposition'] ?? '—')) ?></p>
                <form method="post" action="/owner/discipline/alert-action" style="margin-top:10px; display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end;">
                    <input type="hidden" name="target_user_id" value="<?= e((string) (int) ($a['user_id'] ?? 0)) ?>">
                    <label style="flex:1; min-width:140px;">
                        <span class="muted" style="font-size:0.8rem;">Action</span>
                        <select name="action" required style="width:100%;">
                            <option value="warn">Avertir</option>
                            <option value="justify">Demander justification</option>
                            <option value="sanction">Appliquer sanction</option>
                            <option value="clemency">Accorder clémence</option>
                            <option value="watch">Surveiller</option>
                        </select>
                    </label>
                    <label style="flex:2; min-width:160px;">
                        <span class="muted" style="font-size:0.8rem;">Note (obligatoire sauf « surveiller »)</span>
                        <input type="text" name="note" placeholder="Motif / consigne" style="width:100%;">
                    </label>
                    <button type="submit" class="button-muted">Enregistrer</button>
                </form>
            </article>
        <?php endforeach; ?>
    </div>
</div>
