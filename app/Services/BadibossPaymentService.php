<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class BadibossPaymentService
{
    public function __construct(private readonly Database $database) {}

    public function createSubscriptionPayment(int $restaurantId, array $actor): array
    {
        $pdo = $this->database->pdo();
        $restaurant = $this->restaurant($restaurantId);
        if ($restaurant === null) {
            throw new \RuntimeException('Restaurant introuvable.');
        }

        $plan = $this->plan((int) ($restaurant['subscription_plan_id'] ?? 0));
        if ($plan === null) {
            throw new \RuntimeException('Plan d’abonnement introuvable.');
        }

        $currency = strtoupper((string) ($restaurant['currency_code'] ?? 'USD'));
        $amount = $currency === 'CDF' ? (float) $plan['monthly_price'] * 2800 : (float) $plan['monthly_price'];
        $minimum = $currency === 'CDF' ? 500.0 : 5.0;
        if ($amount < $minimum) {
            throw new \RuntimeException('Le montant de l’abonnement est inférieur au minimum accepté.');
        }

        $reference = sprintf('BBS-%d-%s-%s', $restaurantId, gmdate('YmdHis'), bin2hex(random_bytes(4)));
        $payload = [
            'reference' => $reference,
            'customer_id' => 'restaurant_' . $restaurantId,
            'customer_name' => (string) ($restaurant['name'] ?? 'Badiboss Restaurant'),
            'clientPhone' => preg_replace('/\D+/', '', (string) ($restaurant['phone'] ?? '')),
            'amount' => $amount,
            'currency' => $currency,
            'telecom' => strtoupper((string) ($_POST['telecom'] ?? 'OM')),
            'description' => 'Abonnement ' . (string) ($plan['name'] ?? 'Restaurant') . ' mensuel',
            'callback_url' => rtrim((string) config('app.url', ''), '/') . '/webhooks/badiboss-pay',
            'metadata' => ['plan' => (string) ($plan['code'] ?? 'starter'), 'duration' => 'monthly', 'restaurant_id' => $restaurantId],
        ];

        $statement = $pdo->prepare('INSERT INTO subscription_payments (restaurant_id, reference, amount, currency, status, payload_json, created_at, updated_at) VALUES (:restaurant_id, :reference, :amount, :currency, "pending", :payload, NOW(), NOW())');
        $statement->execute(['restaurant_id' => $restaurantId, 'reference' => $reference, 'amount' => $amount, 'currency' => $currency, 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);

        $response = $this->post($payload);
        $transactionId = (string) ($response['transaction_id'] ?? $response['id'] ?? '');
        $update = $pdo->prepare('UPDATE subscription_payments SET transaction_id = :transaction_id, provider_response_json = :response, updated_at = NOW() WHERE reference = :reference');
        $update->execute(['transaction_id' => $transactionId !== '' ? $transactionId : null, 'response' => json_encode($response, JSON_UNESCAPED_UNICODE), 'reference' => $reference]);
        return ['reference' => $reference, 'transaction_id' => $transactionId, 'provider' => $response];
    }

    public function handleWebhook(array $payload, string $rawBody): void
    {
        $reference = trim((string) ($payload['reference'] ?? ''));
        $status = strtolower(trim((string) ($payload['status'] ?? '')));
        if ($reference === '' || $status === '') { throw new \RuntimeException('Webhook incomplet.'); }
        $payment = $this->payment($reference);
        if ($payment === null) { throw new \RuntimeException('Référence de paiement inconnue.'); }
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();
        try {
            $locked = $this->payment($reference, true);
            if (($locked['status'] ?? '') === 'success') { $pdo->commit(); return; }
            $newStatus = in_array($status, ['success', 'paid', 'completed'], true) ? 'success' : (in_array($status, ['failed', 'cancelled', 'canceled'], true) ? 'failed' : 'pending');
            $update = $pdo->prepare('UPDATE subscription_payments SET status = :status, webhook_payload_json = :payload, updated_at = NOW() WHERE reference = :reference');
            $update->execute(['status' => $newStatus, 'payload' => $rawBody, 'reference' => $reference]);
            if ($newStatus === 'success') {
                $restaurant = $this->restaurant((int) $payment['restaurant_id'], true);
                $duration = 30;
                $start = new \DateTimeImmutable('now', new \DateTimeZone((string) ($restaurant['timezone'] ?? 'Africa/Kinshasa')));
                $end = $start->add(new \DateInterval('P' . max(1, $duration) . 'D'));
                $activate = $pdo->prepare('UPDATE restaurants SET status = "active", subscription_status = "ACTIVE", subscription_payment_status = "PAID", subscription_started_at = :started, subscription_ends_at = :ends, subscription_validated_at = NOW(), subscription_grace_ends_at = DATE_ADD(:ends2, INTERVAL 2 DAY), activated_at = COALESCE(activated_at, NOW()), updated_at = NOW() WHERE id = :id');
                $activate->execute(['started' => $start->format('Y-m-d H:i:s'), 'ends' => $end->format('Y-m-d H:i:s'), 'ends2' => $end->format('Y-m-d H:i:s'), 'id' => (int) $payment['restaurant_id']]);
            }
            $pdo->commit();
        } catch (\Throwable $e) { $pdo->rollBack(); throw $e; }
    }

    public function verifyWebhook(string $rawBody, string $signature, string $timestamp): bool
    {
        $secret = (string) env('BADIBOSS_PAY_WEBHOOK_SECRET', '');
        if ($secret === '' || $signature === '' || $timestamp === '' || abs(time() - (int) $timestamp) > 600) return false;
        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        return hash_equals($expected, trim(str_replace('sha256=', '', $signature)));
    }

    private function post(array $payload): array
    {
        $url = (string) env('BADIBOSS_PAY_PAYMENT_URL', 'https://pay.badiboss.com/api/v1/apps/badiboss-restaurant/payments');
        $key = (string) env('BADIBOSS_PAY_API_KEY', ''); $secret = (string) env('BADIBOSS_PAY_API_SECRET', '');
        if ($key === '' || $secret === '') throw new \RuntimeException('Le paiement n’est pas configuré côté serveur.');
        $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-API-Key: ' . $key, 'X-API-Secret: ' . $secret], CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR)]);
        $body = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $error = curl_error($ch); curl_close($ch);
        if ($body === false || $error !== '' || $code < 200 || $code >= 300) throw new \RuntimeException('Le prestataire de paiement est temporairement indisponible.');
        $decoded = json_decode((string) $body, true); if (!is_array($decoded)) throw new \RuntimeException('Réponse de paiement invalide.'); return $decoded;
    }
    private function restaurant(int $id, bool $lock = false): ?array { $s = $this->database->pdo()->prepare('SELECT * FROM restaurants WHERE id = :id LIMIT 1' . ($lock ? ' FOR UPDATE' : '')); $s->execute(['id' => $id]); $r = $s->fetch(PDO::FETCH_ASSOC); return $r ?: null; }
    private function plan(int $id): ?array { $s = $this->database->pdo()->prepare('SELECT * FROM subscription_plans WHERE id = :id LIMIT 1'); $s->execute(['id' => $id]); $r = $s->fetch(PDO::FETCH_ASSOC); return $r ?: null; }
    private function payment(string $reference, bool $lock = false): ?array { $s = $this->database->pdo()->prepare('SELECT * FROM subscription_payments WHERE reference = :reference LIMIT 1' . ($lock ? ' FOR UPDATE' : '')); $s->execute(['reference' => $reference]); $r = $s->fetch(PDO::FETCH_ASSOC); return $r ?: null; }
}
