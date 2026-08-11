<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\AuditService;
use App\Helpers\MoneyHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WebhookHandler
 *
 * Processes verified webhook payloads from payment gateways.
 *
 * KEY DIFFERENCE between Paystack and Monnify:
 *
 * PAYSTACK:
 *   User initiates → we create PaymentTransaction → Paystack sends same ref back
 *   So we always find the PaymentTransaction record
 *
 * MONNIFY VIRTUAL ACCOUNTS:
 *   User transfers to their dedicated virtual account → Monnify fires webhook
 *   We never created a PaymentTransaction — money just arrived
 *   So we must: find user by virtual account number → create transaction → credit wallet
 */
class WebhookHandler
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    // =========================================================================
    // PAYSTACK
    // =========================================================================

    public function handlePaystack(array $payload): void
    {
        $event = $payload['event'] ?? '';

        match ($event) {
            'charge.success'  => $this->processPaystackSuccess($payload['data']),
            'transfer.failed' => $this->handlePaystackTransferFailed($payload['data']),
            default           => Log::info("Unhandled Paystack event: {$event}"),
        };
    }

    private function processPaystackSuccess(array $data): void
    {
        $gatewayRef = $data['reference'] ?? null;

        if (! $gatewayRef) {
            Log::warning('Paystack webhook missing reference');
            return;
        }

        DB::transaction(function () use ($data, $gatewayRef) {

            $payment = PaymentTransaction::where('reference', $gatewayRef)
                ->lockForUpdate()
                ->first();

            if (! $payment) {
                Log::warning('Paystack webhook: payment not found', [
                    'reference' => $gatewayRef,
                ]);
                return;
            }

            if ($payment->status === 'success') {
                Log::info('Paystack webhook: already processed', ['reference' => $gatewayRef]);
                return;
            }

            // Convert kobo → naira for amount verification
            $webhookAmountNaira = MoneyHelper::divide(
                (string) ($data['amount'] ?? 0),
                '100'
            );

            if (MoneyHelper::compare($webhookAmountNaira, $payment->amount) !== 0) {
                Log::critical('Paystack amount mismatch — possible tampering', [
                    'reference'      => $gatewayRef,
                    'webhook_amount' => $webhookAmountNaira,
                    'our_amount'     => $payment->amount,
                ]);

                $this->auditService->log('payment_amount_mismatch', null, [
                    'gateway'        => 'paystack',
                    'reference'      => $gatewayRef,
                    'webhook_amount' => $webhookAmountNaira,
                    'our_amount'     => $payment->amount,
                ]);

                return;
            }

            // Server-to-server verification
            if (! $this->verifyPaystackPaymentServerSide($gatewayRef)) {
                Log::warning('Paystack server-to-server verification failed', [
                    'reference' => $gatewayRef,
                ]);
                return;
            }

            $paystackCharge = $this->calculatePaystackCharge($payment->amount);
            $amountToCredit = MoneyHelper::subtract($payment->amount, $paystackCharge);

            $payment->update([
                'status'            => 'success',
                'gateway_reference' => $data['id'] ?? null,
                'gateway_charge'    => $paystackCharge,
                'amount_credited'   => $amountToCredit,
                'channel'           => $data['channel'] ?? null,
                'gateway_response'  => $data,
                'paid_at'           => now(),
            ]);

            $user = User::find($payment->user_id);
            if ($user) {
                $user->creditWallet(
                    amount:         $amountToCredit,
                    category:       'fund_wallet',
                    description:    "Wallet funded via Paystack — Ref: {$gatewayRef}",
                    metadata:       [
                        'gateway'           => 'paystack',
                        'payment_reference' => $gatewayRef,
                        'channel'           => $data['channel'] ?? null,
                    ],
                    idempotencyKey: "paystack_credit_{$gatewayRef}",
                );

                $this->auditService->logFinancial(
                    event:     'wallet_funded',
                    user:      $user,
                    amount:    (float) $amountToCredit,
                    reference: $gatewayRef,
                    extra:     ['gateway' => 'paystack'],
                );
            }
        });
    }

    // =========================================================================
    // MONNIFY
    // =========================================================================

    public function handleMonnify(array $payload): void
    {
        $event = $payload['eventType'] ?? '';

        Log::info('Monnify webhook received', [
            'event'   => $event,
            'payload' => $payload,
        ]);

        match ($event) {
            'SUCCESSFUL_TRANSACTION' => $this->processMonnifySuccess($payload['eventData']),
            'FAILED_TRANSACTION'     => $this->handleMonnifyFailed($payload['eventData']),
            default                  => Log::info("Unhandled Monnify event: {$event}"),
        };
    }

    /**
     * Process a successful Monnify virtual account transfer.
     *
     * Monnify virtual accounts work differently from Paystack:
     * - User transfers money to their dedicated virtual account
     * - We never created a PaymentTransaction for this — money just arrived
     * - We must find the user by their virtual account number
     * - Then create a PaymentTransaction record and credit their wallet
     *
     * Idempotency: we check for existing PaymentTransaction by gateway reference
     * to prevent double-credit on webhook retries.
     */
    private function processMonnifySuccess(array $data): void
    {
        $gatewayRef     = $data['transactionReference'] ?? null;
        $accountNumber  = $data['destinationAccountInformation']['accountNumber']
                          ?? $data['settlementAmount']
                          ?? null;
        $amountPaid     = (string) ($data['amountPaid'] ?? $data['settledAmount'] ?? 0);
        $paymentRef     = $data['paymentReference'] ?? $gatewayRef;

        Log::info('Monnify: processing success', [
            'gatewayRef'    => $gatewayRef,
            'accountNumber' => $accountNumber,
            'amountPaid'    => $amountPaid,
            'data_keys'     => array_keys($data),
        ]);

        if (! $gatewayRef) {
            Log::warning('Monnify webhook: missing transactionReference');
            return;
        }

        if (MoneyHelper::compare($amountPaid, '0') <= 0) {
            Log::warning('Monnify webhook: zero or missing amount', ['data' => $data]);
            return;
        }

        DB::transaction(function () use ($data, $gatewayRef, $paymentRef, $amountPaid, $accountNumber) {

            // ── Idempotency check ──────────────────────────────────────────
            // Check if we already processed this gateway reference
            $existingPayment = PaymentTransaction::where('gateway_reference', $gatewayRef)
                ->lockForUpdate()
                ->first();

            if ($existingPayment && $existingPayment->status === 'success') {
                Log::info('Monnify webhook: already processed', ['reference' => $gatewayRef]);
                return;
            }

            // ── Find user by virtual account number ────────────────────────
            $user = $this->findUserByVirtualAccount($accountNumber, $data);

            if (! $user) {
                Log::error('Monnify webhook: could not find user for virtual account', [
                    'gatewayRef'    => $gatewayRef,
                    'accountNumber' => $accountNumber,
                    'data'          => $data,
                ]);
                return;
            }

            Log::info('Monnify webhook: found user', [
                'user_id'    => $user->id,
                'gatewayRef' => $gatewayRef,
            ]);

            // ── Create PaymentTransaction record ───────────────────────────
            $payment = PaymentTransaction::create([
                'tenant_id'         => $user->tenant_id,
                'user_id'           => $user->id,
                'reference'         => $paymentRef ?? 'MONNIFY-' . $gatewayRef,
                'gateway_reference' => $gatewayRef,
                'gateway'           => 'monnify',
                'amount'            => $amountPaid,
                'gateway_charge'    => '0.00',
                'amount_credited'   => $amountPaid,
                'status'            => 'success',
                'channel'           => 'bank_transfer',
                'gateway_response'  => $data,
                'ip_address'        => request()->ip(),
                'paid_at'           => now(),
            ]);

            // ── Credit wallet ──────────────────────────────────────────────
            $user->creditWallet(
                amount:         $amountPaid,
                category:       'fund_wallet',
                description:    "Wallet funded via Monnify bank transfer — Ref: {$gatewayRef}",
                metadata:       [
                    'gateway'           => 'monnify',
                    'transaction_ref'   => $gatewayRef,
                    'payment_ref'       => $paymentRef,
                    'account_number'    => $accountNumber,
                    'amount_paid'       => $amountPaid,
                ],
                idempotencyKey: "monnify_credit_{$gatewayRef}",
            );

            Log::info('Monnify webhook: wallet credited successfully', [
                'user_id'    => $user->id,
                'amount'     => $amountPaid,
                'gatewayRef' => $gatewayRef,
            ]);

            $this->auditService->logFinancial(
                event:     'wallet_funded',
                user:      $user,
                amount:    (float) $amountPaid,
                reference: $gatewayRef,
                extra:     ['gateway' => 'monnify'],
            );
        });
    }

    /**
     * Find user by their Monnify virtual account number.
     *
     * Monnify sends the destination account number in the webhook.
     * We stored virtual account details in users.virtual_accounts (JSON).
     * We search through that JSON to find which user owns the account.
     *
     * Multiple fallback strategies to handle different Monnify payload formats.
     */
    private function findUserByVirtualAccount(?string $accountNumber, array $data): ?User
    {
        // ── Strategy 1: Find by account number in virtual_accounts JSON ───
        if ($accountNumber) {
            // Search all users whose virtual_accounts JSON contains this account number
            $user = User::whereNotNull('virtual_accounts')
                ->where('virtual_accounts', 'like', "%{$accountNumber}%")
                ->first();

            if ($user) {
                return $user;
            }
        }

        // ── Strategy 2: Find by monnify_account_ref ────────────────────────
        // Monnify includes accountReference in the payload
        $accountRef = $data['accountReference']
            ?? $data['destinationAccountInformation']['accountReference']
            ?? null;

        if ($accountRef) {
            $user = User::where('monnify_account_ref', $accountRef)->first();
            if ($user) {
                return $user;
            }
        }

        // ── Strategy 3: Parse account reference format VTU-USER-{id}-{tenant} ─
        // Our MonnifyService creates accounts with reference: "VTU-USER-{userId}-{tenantId}"
        if ($accountRef && str_starts_with($accountRef, 'VTU-USER-')) {
            $parts = explode('-', $accountRef);
            // Format: VTU-USER-{userId}-{tenantId} → parts[2] = userId
            if (isset($parts[2]) && is_numeric($parts[2])) {
                $user = User::find((int) $parts[2]);
                if ($user) {
                    return $user;
                }
            }
        }

        // ── Strategy 4: Find by customer email in payload ──────────────────
        $customerEmail = $data['customer']['email']
            ?? $data['customerEmail']
            ?? null;

        if ($customerEmail) {
            $user = User::where('email', $customerEmail)->first();
            if ($user) {
                return $user;
            }
        }

        return null;
    }

    // ── Monnify Failed ────────────────────────────────────────────────────────

    private function handleMonnifyFailed(array $data): void
    {
        $reference = $data['paymentReference'] ?? $data['transactionReference'] ?? null;

        Log::warning('Monnify transaction failed', ['data' => $data]);

        if ($reference) {
            PaymentTransaction::where('reference', $reference)
                ->update([
                    'status'           => 'failed',
                    'gateway_response' => $data,
                ]);
        }
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function verifyPaystackPaymentServerSide(string $reference): bool
    {
        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => 'Bearer ' . config('vtu.payment.gateways.paystack.secret_key'),
            ])->get("https://api.paystack.co/transaction/verify/{$reference}");

            return $response->successful()
                && ($response->json('data.status') === 'success');
        } catch (\Exception $e) {
            Log::error('Paystack verification failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function calculatePaystackCharge(string $amount): string
    {
        $chargePercent = config('vtu.payment.gateways.paystack.charge_percent', '1.5');
        $charge        = MoneyHelper::percentage($amount, $chargePercent);

        return MoneyHelper::compare($charge, '2000') > 0
            ? '2000.00'
            : $charge;
    }

    private function handlePaystackTransferFailed(array $data): void
    {
        Log::warning('Paystack transfer failed', $data);
    }
}
