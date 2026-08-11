<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\PaymentTransaction;
use App\Helpers\MoneyHelper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaystackService
{
    private string $secretKey;
    private string $baseUrl = 'https://api.paystack.co';

    // Timeout constants — prevents hanging requests
    private const CONNECT_TIMEOUT = 10;  // seconds to establish connection
    private const REQUEST_TIMEOUT = 30;  // seconds to wait for full response

    public function __construct()
    {
        $this->secretKey = (string) \App\Services\ProviderCredentialResolver::get(
            'paystack', 'secret_key', config('vtu.payment.gateways.paystack.secret_key', '')
        );
    }

    /**
     * Initiate a Paystack payment.
     *
     * @return array{url: string, reference: string}
     * @throws \RuntimeException
     */
    public function initiate(
        \App\Models\User $user,
        string $amount,
        string $callbackUrl,
    ): array {
        // Guard: keys must be set
        if (empty($this->secretKey)) {
            throw new \RuntimeException(
                'Paystack secret key is not configured. Please contact support.'
            );
        }

        // Validate amount server-side
        $amount = MoneyHelper::validate($amount, 'fund amount');

        // Generate unique reference server-side
        $reference = $this->generateReference();

        // Convert naira → kobo for Paystack
        $amountKobo = (int) bcmul($amount, '100', 0);

        // Create PaymentTransaction BEFORE calling Paystack
        PaymentTransaction::create([
            'tenant_id'  => $user->tenant_id,
            'user_id'    => $user->id,
            'reference'  => $reference,
            'gateway'    => 'paystack',
            'amount'     => $amount,
            'status'     => 'pending',
            'ip_address' => request()->ip(),
        ]);

        Log::info('Paystack: initiating payment', [
            'reference'   => $reference,
            'amount'      => $amount,
            'amount_kobo' => $amountKobo,
            'user_id'     => $user->id,
        ]);

        try {
            // Add timeout so the request never hangs indefinitely
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->secretKey}",
                'Content-Type'  => 'application/json',
            ])
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::REQUEST_TIMEOUT)
            ->post("{$this->baseUrl}/transaction/initialize", [
                'email'        => $user->email,
                'amount'       => $amountKobo,
                'reference'    => $reference,
                'callback_url' => $callbackUrl,
                'metadata'     => [
                    'user_id'   => $user->id,
                    'tenant_id' => $user->tenant_id,
                    'custom_fields' => [
                        [
                            'display_name'  => 'User ID',
                            'variable_name' => 'user_id',
                            'value'         => $user->id,
                        ],
                    ],
                ],
            ]);

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // Connection timeout or DNS failure
            PaymentTransaction::where('reference', $reference)
                ->update(['status' => 'failed']);

            Log::error('Paystack: connection failed', [
                'reference' => $reference,
                'error'     => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'Could not connect to Paystack. Please check your internet connection and try again.'
            );

        } catch (\Throwable $e) {
            PaymentTransaction::where('reference', $reference)
                ->update(['status' => 'failed']);

            Log::error('Paystack: unexpected error during initialize', [
                'reference' => $reference,
                'error'     => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'Payment initialization failed. Please try again.'
            );
        }

        Log::info('Paystack: initialize response', [
            'reference' => $reference,
            'status'    => $response->status(),
            'body'      => $response->json(),
        ]);

        if (! $response->successful() || ! $response->json('status')) {
            PaymentTransaction::where('reference', $reference)
                ->update(['status' => 'failed']);

            Log::error('Paystack initialize failed', [
                'reference' => $reference,
                'http_code' => $response->status(),
                'response'  => $response->json(),
            ]);

            throw new \RuntimeException(
                $response->json('message') ?? 'Paystack initialization failed. Please try again.'
            );
        }

        $authUrl = $response->json('data.authorization_url');

        if (empty($authUrl)) {
            PaymentTransaction::where('reference', $reference)
                ->update(['status' => 'failed']);

            Log::error('Paystack: no authorization_url in response', [
                'reference' => $reference,
                'response'  => $response->json(),
            ]);

            throw new \RuntimeException(
                'Invalid response from Paystack. Please try again.'
            );
        }

        Log::info('Paystack: payment initiated successfully', [
            'reference' => $reference,
            'url'       => $authUrl,
        ]);

        return [
            'url'       => $authUrl,
            'reference' => $reference,
        ];
    }

    /**
     * Verify a payment server-to-server after Paystack redirects back.
     * Only determines what page to show — wallet credit done by WebhookHandler.
     *
     * @return array{status: string, payment: PaymentTransaction|null}
     */
    public function verifyForCallback(string $reference): array
    {
        $payment = PaymentTransaction::where('reference', $reference)->first();

        if (! $payment) {
            return ['status' => 'not_found', 'payment' => null];
        }

        // Webhook already processed it
        if (in_array($payment->status, ['success', 'failed'])) {
            // Fire notification if success and not already notified
            if ($payment->status === 'success' && empty($payment->notified_at)) {
                try {
                    $user = \App\Models\User::find($payment->user_id);
                    if ($user) {
                        $user->notify(new \App\Notifications\WalletFunded(
                            amount:      (string) $payment->amount_credited,
                            balanceAfter:(string) $user->fresh()->wallet_balance,
                            reference:   $reference,
                            gateway:     'paystack',
                        ));
                    }
                } catch (\Throwable $e) {
                    Log::warning('WalletFunded notification failed (callback)', ['error' => $e->getMessage()]);
                }
            }
            return ['status' => $payment->status, 'payment' => $payment];
        }

        // Still pending — verify with Paystack
        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->secretKey}",
            ])
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::REQUEST_TIMEOUT)
            ->get("{$this->baseUrl}/transaction/verify/{$reference}");

            if (! $response->successful()) {
                return ['status' => 'pending', 'payment' => $payment];
            }

            $paystackStatus = $response->json('data.status') ?? '';

            if ($paystackStatus === 'success') {
                return ['status' => 'success', 'payment' => $payment];
            }

            if (in_array($paystackStatus, ['failed', 'abandoned'])) {
                $payment->update(['status' => 'failed']);
                return ['status' => 'failed', 'payment' => $payment];
            }

            return ['status' => 'pending', 'payment' => $payment];

        } catch (\Throwable $e) {
            Log::error('Paystack verify callback error', [
                'reference' => $reference,
                'error'     => $e->getMessage(),
            ]);
            return ['status' => 'pending', 'payment' => $payment];
        }
    }

    /**
     * Generate a unique server-side transaction reference.
     */
    public function generateReference(): string
    {
        return 'VTU-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(8));
    }

    /**
     * Calculate Paystack charge using BCMath.
     * 1.5% + ₦100 flat fee above ₦2,500, capped at ₦2,000.
     */
    public function calculateCharge(string $amount): string
    {
        $chargePercent = config('vtu.payment.gateways.paystack.charge_percent', '1.5');
        $percentCharge = MoneyHelper::percentage($amount, $chargePercent);

        if (MoneyHelper::compare($amount, '2500') > 0) {
            $percentCharge = MoneyHelper::add($percentCharge, '100');
        }

        if (MoneyHelper::compare($percentCharge, '2000') > 0) {
            return '2000.00';
        }

        return $percentCharge;
    }
}
