<?php
declare(strict_types=1);
$restaurant = is_array($restaurant ?? null) ? $restaurant : [];
$sched = is_array($discipline_schedule ?? null) ? $discipline_schedule : [];
?>
<section class="topbar">
    <div class="brand">
        <h1>Ma discipline</h1>
        <p class="muted">Vos jauges et votre rythme (fuseau restaurant). Les responsables voient l’équipe sur <a href="/owner/discipline">Discipline</a>.</p>
    </div>
</section>

<?php if (!empty($sched['notice_unset'])): ?>
    <section class="status-banner status-warning no-print" style="margin-bottom:18px;">
        <strong>Horaire par défaut</strong>
        <p class="muted" style="margin:8px 0 0;">Début travail, tolérance et limite caisse ne sont pas encore personnalisés — valeurs par défaut utilisées pour les retards / remises.</p>
    </section>
<?php endif; ?>

<?php
$self_staff_gauges = $my_gauges ?? null;
$staff_audit_highlights = $staff_audit_highlights ?? [];
$staff_gauges_panel_title = 'Vos jauges discipline';
require base_path('app/Views/partials/staff_discipline_gauges_foldable.php');
?>

<p class="muted no-print" style="margin-top:16px;">
    <a href="/rapport">← Retour au rapport</a>
</p>
