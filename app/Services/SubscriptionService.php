<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Container;
use App\Core\Database;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class SubscriptionService
{
    public function __construct(private readonly Database $database) {}
    public function summaryForRestaurant(int|array|null $restaurant): ?array
    {
        $restaurantRow = is_array($restaurant) ? $restaurant : $this->findRestaurant((int) $restaurant);
        if (!is_array($restaurantRow)) { return null; }
        $timezone = $this->restaurantTimezone($restaurantRow);
        $now = new DateTimeImmutable('now', $timezone);
        $rules = $this->subscriptionRules();
        $graceDays = (int) ($rules['subscription_grace_days'] ?? 2);
        $warningDays = (int) ($rules['subscription_warning_days'] ?? 5);
        $status = (string) ($restaurantRow['subscription_status'] ?? 'DRAFT');
        $paymentStatus = (string) ($restaurantRow['subscription_payment_status'] ?? 'UNPAID');
        $startedAt = $this->toDate((string) ($restaurantRow['subscription_started_at'] ?? ''), $timezone);
        $endsAt = $this->toDate((string) ($restaurantRow['subscription_ends_at'] ?? ''), $timezone);
        $graceEndsAt = $this->toDate((string) ($restaurantRow['subscription_grace_ends_at'] ?? ''), $timezone);
        if ($endsAt !== null && $graceEndsAt === null) { $graceEndsAt = $endsAt->add(new DateInterval('P' . $graceDays . 'D')); }
        $effectiveStatus = $status;
        if ($status === 'ACTIVE' && $endsAt !== null && $now > $endsAt) { $effectiveStatus = ($graceEndsAt !== null && $now <= $graceEndsAt) ? 'GRACE_PERIOD' : 'EXPIRED'; }
        elseif ($status === 'GRACE_PERIOD' && $graceEndsAt !== null && $now > $graceEndsAt) { $effectiveStatus = 'EXPIRED'; }
        if ($effectiveStatus !== $status && !empty($restaurantRow['id'])) { $this->persistEffectiveStatus((int) $restaurantRow['id'], $effectiveStatus, $graceEndsAt); }
        $daysRemaining = $endsAt !== null ? max(0, (int) $now->diff($endsAt)->format('%r%a')) : null;
        $daysExpired = ($endsAt !== null && $effectiveStatus === 'EXPIRED') ? max(0, abs((int) $now->diff($endsAt)->format('%r%a'))) : null;
        $graceDaysRemaining = $graceEndsAt !== null ? max(0, (int) $now->diff($graceEndsAt)->format('%r%a')) : null;
        return ['status' => $effectiveStatus, 'payment_status' => $paymentStatus, 'timezone' => $timezone->getName(), 'today' => $now->format('Y-m-d'), 'started_at' => $startedAt?->format('Y-m-d H:i:s'), 'ends_at' => $endsAt?->format('Y-m-d H:i:s'), 'grace_ends_at' => $graceEndsAt?->format('Y-m-d H:i:s'), 'is_operational' => in_array($effectiveStatus, ['ACTIVE', 'GRACE_PERIOD'], true), 'days_remaining' => $daysRemaining, 'days_expired' => $daysExpired, 'grace_days_remaining' => $graceDaysRemaining, 'warning_days' => $warningDays, 'grace_days' => $graceDays, 'message' => $this->statusMessage($effectiveStatus, $paymentStatus, $endsAt, $graceEndsAt, $daysRemaining, $graceDaysRemaining, $daysExpired)];
    }
    public function canUseOperationalFeatures(?array $user): bool
    {
        if (!is_array($user) || ($user['scope'] ?? null) === 'super_admin') { return true; }
        $summary = $this->summaryForRestaurant((int) ($user['restaurant_id'] ?? 0));
        return (bool) ($summary['is_operational'] ?? false);
    }
    public function activateRestaurant(int $restaurantId, array $payload, array $actor): void
    {
        $restaurant = $this->findRestaurant($restaurantId); if ($restaurant === null) { throw new \RuntimeException('Restaurant introuvable.'); }
        $timezone = $this->restaurantTimezone($restaurant);
        $startDate = $this->toDate((string) ($payload['subscription_started_at'] ?? ''), $timezone) ?? new DateTimeImmutable('now', $timezone);
        $durationDays = max(1, (int) ($payload['subscription_duration_days'] ?? 30));
        $endDate = $startDate->add(new DateInterval('P' . $durationDays . 'D'));
        $rules = $this->subscriptionRules(); $graceDays = (int) ($rules['subscription_grace_days'] ?? 2); $graceEnd = $endDate->add(new DateInterval('P' . $graceDays . 'D'));
        $paymentStatus = ($payload['payment_status'] ?? 'PAID') === 'WAIVED' ? 'WAIVED' : 'PAID';
        $statement = $this->database->pdo()->prepare('UPDATE restaurants SET status = "active", subscription_status = "ACTIVE", subscription_payment_status = :payment_status, subscription_started_at = :subscription_started_at, subscription_ends_at = :subscription_ends_at, subscription_validated_at = NOW(), subscription_grace_ends_at = :subscription_grace_ends_at, subscription_exempted_at = :subscription_exempted_at, subscription_exemption_reason = :subscription_exemption_reason, activated_at = COALESCE(activated_at, NOW()), updated_at = NOW() WHERE id = :id');
        $statement->execute(['payment_status' => $paymentStatus, 'subscription_started_at' => $startDate->format('Y-m-d H:i:s'), 'subscription_ends_at' => $endDate->format('Y-m-d H:i:s'), 'subscription_grace_ends_at' => $graceEnd->format('Y-m-d H:i:s'), 'subscription_exempted_at' => $paymentStatus === 'WAIVED' ? (new DateTimeImmutable('now', $timezone))->format('Y-m-d H:i:s') : null, 'subscription_exemption_reason' => $payload['justification'] ?? null, 'id' => $restaurantId]);
        Container::getInstance()->get('audit')->log(['restaurant_id' => $restaurantId, 'user_id' => $actor['id'], 'actor_name' => $actor['full_name'], 'actor_role_code' => $actor['role_code'], 'module_name' => 'tenant_management', 'action_name' => $paymentStatus === 'WAIVED' ? 'subscription_exceptional_activation' : 'subscription_validated', 'entity_type' => 'restaurants', 'entity_id' => (string) $restaurantId, 'new_values' => $payload, 'justification' => $payload['justification'] ?? 'Validation abonnement manuelle']);
    }
    public function declarePayment(int $restaurantId, array $actor): void
    {
        $statement = $this->database->pdo()->prepare('UPDATE restaurants SET subscription_status = "PENDING_VALIDATION", subscription_payment_status = "DECLARED", subscription_payment_declared_at = NOW(), updated_at = NOW() WHERE id = :id');
        $statement->execute(['id' => $restaurantId]);
        Container::getInstance()->get('audit')->log(['restaurant_id' => $restaurantId, 'user_id' => $actor['id'], 'actor_name' => $actor['full_name'], 'actor_role_code' => $actor['role_code'], 'module_name' => 'tenant_management', 'action_name' => 'subscription_payment_declared', 'entity_type' => 'restaurants', 'entity_id' => (string) $restaurantId, 'justification' => 'Déclaration manuelle de paiement']);
    }
    public function markPendingPayment(int $restaurantId): void
    {
        $statement = $this->database->pdo()->prepare('UPDATE restaurants SET subscription_status = "PENDING_PAYMENT", subscription_payment_status = "UNPAID", updated_at = NOW() WHERE id = :id');
        $statement->execute(['id' => $restaurantId]);
    }
    public function subscriptionRules(): array
    {
        $settings = Container::getInstance()->get('platformSettings')->listSystemSettings(); $rules = $settings['global_subscription_rules_json'] ?? [];
        return is_array($rules) ? array_merge(['subscription_grace_days' => 2, 'subscription_warning_days' => 5, 'default_duration_days' => 30], $rules) : ['subscription_grace_days' => 2, 'subscription_warning_days' => 5, 'default_duration_days' => 30];
    }
    private function statusMessage(string $status, string $paymentStatus, ?DateTimeImmutable $endsAt, ?DateTimeImmutable $graceEndsAt, ?int $daysRemaining, ?int $graceDaysRemaining, ?int $daysExpired): string
    {
        return match ($status) {
            'ACTIVE' => $endsAt !== null ? 'Actif jusqu’au ' . $endsAt->format('d/m/Y') . ($daysRemaining !== null ? ' (' . $daysRemaining . ' jour(s) restant(s))' : '') : 'Abonnement actif.',
            'GRACE_PERIOD' => $graceEndsAt !== null ? 'Période de grâce en cours jusqu’au ' . $graceEndsAt->format('d/m/Y') . ($graceDaysRemaining !== null ? ' (' . $graceDaysRemaining . ' jour(s) restant(s))' : '') : 'Période de grâce en cours.',
            'EXPIRED' => 'Abonnement expiré' . ($daysExpired !== null ? ' depuis ' . $daysExpired . ' jour(s)' : '.'),
            'PENDING_VALIDATION' => 'Paiement déclaré, en attente de validation plateforme.',
            'PENDING_PAYMENT' => 'Abonnement en attente de paiement.',
            'SUSPENDED' => 'Abonnement suspendu par la plateforme.',
            default => $paymentStatus === 'UNPAID' ? 'Espace créé, abonnement non encore activé.' : 'Abonnement en attente.',
        };
    }
    private function findRestaurant(int $restaurantId): ?array
    {
        $statement = $this->database->pdo()->prepare('SELECT * FROM restaurants WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $restaurantId]); $restaurant = $statement->fetch(PDO::FETCH_ASSOC);
        return $restaurant ?: null;
    }
    private function persistEffectiveStatus(int $restaurantId, string $effectiveStatus, ?DateTimeImmutable $graceEndsAt): void
    {
        $statement = $this->database->pdo()->prepare('UPDATE restaurants SET subscription_status = :subscription_status, subscription_grace_ends_at = :subscription_grace_ends_at, updated_at = NOW() WHERE id = :id');
        $statement->execute(['subscription_status' => $effectiveStatus, 'subscription_grace_ends_at' => $graceEndsAt?->format('Y-m-d H:i:s'), 'id' => $restaurantId]);
    }
    private function toDate(string $value, DateTimeZone $timezone): ?DateTimeImmutable
    {
        if (trim($value) === '') { return null; }
        try { return new DateTimeImmutable($value, $timezone); } catch (\Throwable) { return null; }
    }
    private function restaurantTimezone(array $restaurant): DateTimeZone
    {
        try { return new DateTimeZone((string) ($restaurant['timezone'] ?? config('app.timezone', 'Africa/Lagos'))); } catch (\Throwable) { return new DateTimeZone((string) config('app.timezone', 'Africa/Lagos')); }
    }
}
