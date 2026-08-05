<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Services/ExternalAuditService.php';

use App\Services\ExternalAuditService;

$cases = [
    'cashier_server' => 'serveur',
    'stock_manager' => 'boissons',
    'kitchen' => 'cuisine',
    'manager' => null,
    'owner' => null,
];
foreach ($cases as $role => $expected) {
    $actual = ExternalAuditService::auditAssignment(['role_code' => $role]);
    if ($actual['report_type'] !== $expected) {
        fwrite(STDERR, "ECHEC affectation {$role}\n");
        exit(1);
    }
}
if (!ExternalAuditService::auditAssignment(['role_code' => 'manager'])['is_manager']) {
    fwrite(STDERR, "ECHEC tableau gerant\n");
    exit(1);
}
echo "OK ExternalAuditAssignment: 6 controles\n";
