<?php

declare(strict_types=1);

/**
 * Smoke + checklist mémoire pour cohérence « décision responsable » (Railway).
 * Usage : php scripts/railway_manager_resolution_coherence.php
 */

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Support/helpers.php';

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    $baseDir = BASE_PATH . '/app/';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use App\Services\ManagerResolutionService;

$codes = [
    ManagerResolutionService::OUTCOME_VALIDE_GERANT,
    ManagerResolutionService::OUTCOME_CLOTURE_GERANT,
    ManagerResolutionService::OUTCOME_MANQUANT_GERANT,
    ManagerResolutionService::OUTCOME_REJET_GERANT,
    ManagerResolutionService::OUTCOME_PARTIEL_GERANT,
    ManagerResolutionService::OUTCOME_FORCE_CAISSE_GERANT,
    ManagerResolutionService::OUTCOME_ESCALADE_PROPRIETAIRE,
];

foreach ($codes as $c) {
    if ($c === '') {
        fwrite(STDERR, "FAIL empty outcome\n");
        exit(1);
    }
}
$lbl = responsible_outcome_label(ManagerResolutionService::OUTCOME_VALIDE_GERANT);
if ($lbl === '' || $lbl === ManagerResolutionService::OUTCOME_VALIDE_GERANT) {
    fwrite(STDERR, "FAIL responsible_outcome_label\n");
    exit(1);
}

fwrite(STDOUT, "railway_manager_resolution_coherence OK\n");
fwrite(STDOUT, "\nManuel Railway (résumé):\n");
fwrite(STDOUT, "A. Commande bloquée → clôture sans vente → outcome CLOTURE_GERANT / pas de vente.\n");
fwrite(STDOUT, "B. Validée servie → une vente origine server_request / VALIDE_GERANT.\n");
fwrite(STDOUT, "C. Partiel → RECU_CAISSE + manquant cohérent / PARTIEL_GERANT.\n");
fwrite(STDOUT, "D. Double clic → déjà traité, pas de double ligne.\n");
fwrite(STDOUT, "E. Rapports : cohérence vendu / reçu / écart.\n");
