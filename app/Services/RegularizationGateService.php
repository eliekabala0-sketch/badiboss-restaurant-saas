<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Container;
use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Garde-fous début de journée : lecture seule + pénalités disciplinaires traçables.
 */
final class RegularizationGateService
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @return array{blocked: bool, reasons: list<string>, codes: list<string>, backlog: array<string, int>}
     */
    public function assessForUser(int $restaurantId, array $user): array
    {
        $scope = (string) ($user['scope'] ?? '');
        if ($scope === 'super_admin') {
            return ['blocked' => false, 'reasons' => [], 'codes' => [], 'backlog' => []];
        }

        $role = (string) ($user['role_code'] ?? '');
        $uid = (int) ($user['id'] ?? 0);
        $backlog = Container::getInstance()->get('salesService')->regularizationBacklogCounts($restaurantId);

        if (in_array($role, ['owner', 'manager'], true)) {
            return [
                'blocked' => false,
                'reasons' => [],
                'codes' => [],
                'backlog' => $backlog,
            ];
        }

        $reasons = [];
        $codes = [];

        if ($role === 'cashier_server' && $uid > 0) {
            $cash = Container::getInstance()->get('reportService')->agentServerCashAccountReadModel($restaurantId, $uid);
            $todaySf = (float) (($cash['today']['shortfall'] ?? 0));
            $legacySf = (float) ($cash['legacy_shortfall'] ?? 0);
            if ($todaySf > 0.0001) {
                $reasons[] = 'Manquant ou remise en attente sur vos ventes clôturées (aujourd’hui).';
                $codes[] = 'server_shortfall_today';
            }
            if ($legacySf > 0.0001) {
                $reasons[] = 'Anciennes ventes non entièrement régularisées à la caisse.';
                $codes[] = 'server_shortfall_legacy';
            }
            if ($this->serverHasRejectedRemittanceOutstanding($restaurantId, $uid)) {
                $reasons[] = 'Au moins une remise caisse a été rejetée : le montant reste à votre charge tant qu’une remise valide n’est pas enregistrée.';
                $codes[] = 'server_remittance_rejected';
            }
            if ($this->serverHasStaleOpenRequests($restaurantId, $uid)) {
                $reasons[] = 'Commandes service non clôturées depuis la veille.';
                $codes[] = 'server_stale_requests';
            }
        }

        if (in_array($role, ['cashier_accountant', 'stock_manager'], true)) {
            if (($backlog['overdue_remis_a_caisse'] ?? 0) > 0) {
                $reasons[] = 'Une ou plusieurs remises serveur attendent une décision caisse (héritées de la veille).';
                $codes[] = 'cashier_pending_remis';
            }
        }

        if ($role === 'kitchen') {
            if ($this->kitchenHasBacklogItems($restaurantId)) {
                $reasons[] = 'Des lignes cuisine du service restent à traiter (veille).';
                $codes[] = 'kitchen_pending';
            }
        }

        if ($role === 'stock_manager') {
            if ($this->stockMagasinHasOpenFromPriorDay($restaurantId)) {
                $reasons[] = 'Demandes magasin cuisine à finaliser (veille).';
                $codes[] = 'stock_kitchen_requests_pending';
            }
        }

        $blocked = $reasons !== [];
        if ($blocked) {
            Container::getInstance()->get('staffDiscipline')->ensureLedgerPenalty(
                $restaurantId,
                $uid,
                $codes[0] ?? 'regularization_hold',
                $this->todayYmd($restaurantId),
            );
        }

        return [
            'blocked' => $blocked,
            'reasons' => $reasons,
            'codes' => $codes,
            'backlog' => $backlog,
        ];
    }

    private function todayYmd(int $restaurantId): string
    {
        return Container::getInstance()->get('reportService')->todayForRestaurant($restaurantId);
    }

    private function reportTz(int $restaurantId): DateTimeZone
    {
        $restaurant = Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId);
        $name = (string) ($restaurant['timezone'] ?? config('app.timezone', 'Africa/Lagos'));
        try {
            return new DateTimeZone($name);
        } catch (\Throwable) {
            return new DateTimeZone((string) config('app.timezone', 'Africa/Lagos'));
        }
    }

    private function serverHasRejectedRemittanceOutstanding(int $restaurantId, int $serverUserId): bool
    {
        foreach (Container::getInstance()->get('cashService')->listSaleRemittanceTracking($restaurantId, $serverUserId) as $row) {
            if ((int) ($row['server_id'] ?? 0) !== $serverUserId) {
                continue;
            }
            $st = (string) ($row['transfer_status'] ?? '');
            if (in_array($st, ['REMISE_REJETEE_CAISSE', 'REMISE_REJETEE_GERANT'], true)) {
                return true;
            }
        }

        return false;
    }

    private function serverHasStaleOpenRequests(int $restaurantId, int $serverUserId): bool
    {
        $tz = $this->reportTz($restaurantId);
        $todayStart = (new DateTimeImmutable('now', $tz))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $st = $this->database->pdo()->prepare(
            'SELECT COUNT(*) FROM server_requests
             WHERE restaurant_id = :rid AND server_id = :uid
               AND status NOT IN ("ANNULE", "REFUSE_CUISINE", "CLOTURE", "VENDU_TOTAL", "VENDU_PARTIEL")
               AND created_at < :today_start'
        );
        $st->execute(['rid' => $restaurantId, 'uid' => $serverUserId, 'today_start' => $todayStart]);

        return (int) $st->fetchColumn() > 0;
    }

    private function kitchenHasBacklogItems(int $restaurantId): bool
    {
        $tz = $this->reportTz($restaurantId);
        $todayStart = (new DateTimeImmutable('now', $tz))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $st = $this->database->pdo()->prepare(
            'SELECT COUNT(*) FROM server_request_items sri
             INNER JOIN server_requests sr ON sr.id = sri.request_id
             WHERE sr.restaurant_id = :rid
               AND sri.status IN ("DEMANDE", "EN_PREPARATION", "FOURNI_PARTIEL", "FOURNI_TOTAL", "PRET_A_SERVIR")
               AND COALESCE(sr.created_at, sr.updated_at) < :today_start'
        );
        $st->execute(['rid' => $restaurantId, 'today_start' => $todayStart]);

        return (int) $st->fetchColumn() > 0;
    }

    private function stockMagasinHasOpenFromPriorDay(int $restaurantId): bool
    {
        $tz = $this->reportTz($restaurantId);
        $todayStart = (new DateTimeImmutable('now', $tz))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $st = $this->database->pdo()->prepare(
            'SELECT COUNT(*) FROM kitchen_stock_requests
             WHERE restaurant_id = :rid
               AND status NOT IN ("ANNULE", "REFUSE_STOCK", "CLOTURE")
               AND created_at < :today_start'
        );
        $st->execute(['rid' => $restaurantId, 'today_start' => $todayStart]);

        return (int) $st->fetchColumn() > 0;
    }
}
