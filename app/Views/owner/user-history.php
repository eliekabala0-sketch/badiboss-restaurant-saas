<?php
declare(strict_types=1);

$user = $snapshot['user'] ?? [];
$sales = $snapshot['sales'] ?? [];
$losses = $snapshot['losses'] ?? [];
$restaurantCurrency = restaurant_currency($restaurant);
?>
<section class="topbar">
    <div class="brand">
        <h1>Historique utilisateur</h1>
        <p>Vue nominative limitee au restaurant courant, utile pour imprimer ou verifier qui a fait quoi.</p>
    </div>
</section>

<?php if (!empty($flash_success)): ?><div class="flash-ok"><?= e($flash_success) ?></div><?php endif; ?>
<?php if (!empty($flash_error)): ?><div class="flash-bad"><?= e($flash_error) ?></div><?php endif; ?>

<section class="grid stats">
    <article class="card stat"><span>Utilisateur</span><strong><?= e(named_actor_label($user['full_name'] ?? null, $user['role_code'] ?? null)) ?></strong></article>
    <article class="card stat"><span>Ventes</span><strong><?= e((string) ($sales['sales_count'] ?? 0)) ?></strong></article>
    <article class="card stat"><span>Total vendu</span><strong><?= e(format_money($sales['sales_total'] ?? 0, $restaurantCurrency)) ?></strong></article>
    <article class="card stat"><span>Demandes serveur</span><strong><?= e((string) ($snapshot['server_requests_count'] ?? 0)) ?></strong></article>
    <article class="card stat"><span>Cas operationnels</span><strong><?= e((string) ($snapshot['operation_cases_count'] ?? 0)) ?></strong></article>
    <article class="card stat"><span>Pertes liees</span><strong><?= e(format_money($losses['losses_total'] ?? 0, $restaurantCurrency)) ?></strong></article>
</section>

<section class="card" style="padding:22px; margin-top:24px;">
    <div class="toolbar-actions no-print">
        <a href="/owner/access" class="button-muted">Retour aux acces</a>
        <button type="button" onclick="window.print()">Imprimer</button>
    </div>
    <p><strong>Email :</strong> <?= e((string) ($user['email'] ?? '-')) ?></p>
    <p><strong>Role :</strong> <?= e(named_actor_label(null, $user['role_code'] ?? null)) ?></p>
    <p><strong>Pertes declarees :</strong> <?= e((string) ($losses['losses_count'] ?? 0)) ?></p>

    <h2 style="margin-top:20px;">Dernieres traces</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Qui</th><th>Module</th><th>Action</th><th>Ancienne valeur</th><th>Nouvelle valeur</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach (($snapshot['audits'] ?? []) as $audit): ?>
                <?php
                    $oldValues = json_decode((string) ($audit['old_values_json'] ?? ''), true);
                    $newValues = json_decode((string) ($audit['new_values_json'] ?? ''), true);
                    $oldText = is_array($oldValues) ? json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
                    $newText = is_array($newValues) ? json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
                ?>
                <tr>
                    <td><?= e(named_actor_label($audit['actor_name'] ?? null, $audit['actor_role_code'] ?? null)) ?></td>
                    <td><?= e(permission_module_label($audit['module_name'] ?? null)) ?></td>
                    <td><?= e((string) ($audit['action_name'] ?? '-')) ?></td>
                    <td><span class="muted"><?= e($oldText !== '' ? $oldText : '-') ?></span></td>
                    <td><span class="muted"><?= e($newText !== '' ? $newText : ($audit['justification'] ?? '-')) ?></span></td>
                    <td><?= e(format_date_fr($audit['created_at'] ?? null)) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (($snapshot['audits'] ?? []) === []): ?>
                <tr><td colspan="6"><div class="compact-empty">Aucune trace recente pour cet agent.</div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
