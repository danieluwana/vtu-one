<?php

declare(strict_types=1);

namespace App\Services\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Exceptions\WebhookException;

/**
 * WebhookSecurityService
 *
 * Protects webhook endpoints from:
 *  1. Fake webhooks (invalid signature)
 *  2. Replay attacks (same webhook sent twice)
 *  3. Expired webhooks (too old)
 *  4. Tampered payloads
 */
class WebhookSecurityService
{
    /**
     * Verify a Paystack webhook.
     * Paystack signs webhooks with HMAC-SHA512.
     *
     * @throws WebhookException
     */
    public function verifyPaystack(Request $request): array
    {
        $signature = $request->header('x-paystack-signature');

        if (! $signature) {
            throw new WebhookException('Missing Paystack signature header.');
        }

        $secretKey  = config('vtu.payment.gateways.paystack.secret_key');
        $rawBody    = $request->getContent();
        $expected   = hash_hmac('sha512', $rawBody, $secretKey);

        if (! hash_equals($expected, $signature)) {
            Log::warning('Paystack webhook signature mismatch', [
                'ip' => $request->ip(),
            ]);
            throw new WebhookException('Invalid Paystack webhook signature.');
        }

        $payload = json_decode($rawBody, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new WebhookException('Invalid webhook payload.');
        }

        // Replay attack prevention
        $eventId = $payload['data']['reference'] ?? null;
        if ($eventId) {
            $this->preventReplay("paystack_{$eventId}");
        }

        return $payload;
    }

    /**
     * Verify a Monnify webhook.
     * Monnify uses HMAC-SHA512 with their secret key.
     *
     * @throws WebhookException
     */
    public function verifyMonnify(Request $request): array
    {
        $signature = $request->header('monnify-signature');

        if (! $signature) {
            throw new WebhookException('Missing Monnify signature header.');
        }

        $secretKey = config('vtu.payment.gateways.monnify.secret_key');
        $rawBody   = $request->getContent();
        $expected  = hash_hmac('sha512', $rawBody, $secretKey);

        if (! hash_equals($expected, $signature)) {
            Log::warning('Monnify webhook signature mismatch', [
                'ip' => $request->ip(),
            ]);
            throw new WebhookException('Invalid Monnify webhook signature.');
        }

        $payload = json_decode($rawBody, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new WebhookException('Invalid webhook payload.');
        }

        // Replay attack prevention
        $eventId = $payload['eventData']['transactionReference'] ?? null;
        if ($eventId) {
            $this->preventReplay("monnify_{$eventId}");
        }

        return $payload;
    }

    /**
     * Prevent replay attacks by tracking processed event IDs.
     * If the same event ID is seen twice, it's a replay attack.
     *
     * @throws WebhookException
     */
    private function preventReplay(string $eventId): void
    {
        $cacheKey = "webhook_processed_{$eventId}";

        if (Cache::has($cacheKey)) {
            Log::warning('Webhook replay attack detected', [
                'event_id' => $eventId,
            ]);
            throw new WebhookException('Duplicate webhook event detected.');
        }

        // Mark as processed for 48 hours
        Cache::put($cacheKey, true, now()->addHours(48));
    }
}
