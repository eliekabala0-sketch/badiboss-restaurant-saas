<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Core\Container;
use App\Core\Request;

final class BadibossPaymentController
{
    public function webhook(Request $request): void
    {
        $raw = $request->body;
        $headers = array_change_key_case($request->headers, CASE_LOWER);
        $signature = (string) ($headers['x-badiboss-signature'] ?? '');
        $timestamp = (string) ($headers['x-badiboss-timestamp'] ?? '');
        if (!Container::getInstance()->get('badibossPayment')->verifyWebhook($raw, $signature, $timestamp)) { http_response_code(401); echo 'invalid signature'; return; }
        try { Container::getInstance()->get('badibossPayment')->handleWebhook($request->json(), $raw); http_response_code(200); echo 'ok'; }
        catch (\Throwable $e) { error_log((string) $e); http_response_code(422); echo 'invalid webhook'; }
    }
}
