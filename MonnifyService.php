<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MonnifyService
 *
 * Handles all outbound communication with the Monnify API.
 * Monnify uses reserved virtual accounts — each user gets a
 * dedicated bank account. Payments come in via bank transfer.
 * Wallet crediting is handled exclusively by WebhookHandler.
 *
 * Config keys (all from existing config/vtu.php):
 *   vtu.payment.gateways.monnify.api_key
 *   vtu.payment.gateways.monnify.secret_key
 *   vtu.payment.gateways.monnify.contract_code
 *   vtu.payment.gateways.monnify.base_url
 *   vtu.payment.gateways.monnify.is_live
 */
class MonnifyService
{
    private string $apiKey;
    private string $secretKey;
    private string $contractCode;
    private string $baseUrl;
    private bool   $isLive;

    private const CONNECT_TIMEOUT = 10;
    private const REQUEST_TIMEOUT = 30;

     public function __construct()
    {
        $this->apiKey       = (string) \App\Services\ProviderCredentialResolver::get('monnify', 'api_key', config('vtu.payment.gateways.monnify.api_key', ''));
        $this->secretKey    = (string) \App\Services\ProviderCredentialResolver::get('monnify', 'secret_key', config('vtu.payment.gateways.monnify.secret_key', ''));
        $this->contractCode = (string) \App\Services\ProviderCredentialResolver::get('monnify', 'contract_code', config('vtu.payment.gateways.monnify.contract_code', ''));
        $this->baseUrl      = config('vtu.payment.gateways.monnify.base_url', 'https://sandbox.monnify.com');
        $this->isLive       = config('vtu.payment.gateways.monnify.is_live', false);
    }

    // ── Authentication ────────────────────────────────────────────────────────

    /**
     * Get a Monnify access token via Basic Auth.
     * Monnify tokens expire — always fetch fresh for each request.
     *
     * @throws \RuntimeException
     */
    private function getAccessToken(): string
    {
        if (empty($this->apiKey) || empty($this->secretKey)) {
            throw new \RuntimeException(
                'Monnify API credentials are not configured.'
            );
        }

        $credentials = base64_encode("{$this->apiKey}:{$this->secretKey}");

        try {
            $response = Http::withHeaders([
                'Authorization' => "Basic {$credentials}",
                'Content-Type'  => 'application/json',
            ])
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::REQUEST_TIMEOUT)
            ->post("{$this->baseUrl}/api/v1/auth/login");

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Monnify: auth connection failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException(
                'Could not connect to Monnify. Please try again.'
            );
        }

        if (! $response->successful() || ! $response->json('requestSuccessful')) {
            Log::error('Monnify: auth failed', ['response' => $response->json()]);
            throw new \RuntimeException(
                'Monnify authentication failed. Please contact support.'
            );
        }

        return $response->json('responseBody.accessToken');
    }

    // ── Reserved Virtual Accounts ─────────────────────────────────────────────

    /**
     * Create a reserved virtual account for a user.
     * Monnify assigns dedicated bank account numbers to the user.
     * Stores account details on the user record for future use.
     *
     * @throws \RuntimeException
     */
    public function createReservedAccount(User $user): array
    {
        if (empty($this->contractCode)) {
            throw new \RuntimeException(
                'Monnify contract code is not configured.'
            );
        }

        $token = $this->getAccessToken();

        // Use user's unique reference — prevents duplicate accounts
        $accountReference = "VTU-USER-{$user->id}-{$user->tenant_id}";

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
                'Content-Type'  => 'application/json',
            ])
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::REQUEST_TIMEOUT)
            ->post("{$this->baseUrl}/api/v2/bank-transfer/reserved-accounts", [
                'accountReference'       => $accountReference,
                'accountName'            => trim("{$user->first_name} {$user->last_name}"),
                'currencyCode'           => 'NGN',
                'contractCode'           => $this->contractCode,
                'customerEmail'          => $user->email,
                'customerName'           => trim("{$user->first_name} {$user->last_name}"),
                'customerBvn'            => '22222222222', // Use actual BVN in production
                'getAllAvailableBanks'    => true,         // Get all bank options
            ]);

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Monnify: create reserved account connection failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
            throw new \RuntimeException(
                'Could not connect to Monnify. Please try again.'
            );
        }

        if (! $response->successful() || ! $response->json('requestSuccessful')) {
            $errorMessage = $response->json('responseMessage') ?? 'Unknown error';

            Log::error('Monnify: create reserved account failed', [
                'user_id'  => $user->id,
                'response' => $response->json(),
            ]);

            // If account already exists, fetch it instead
            if (str_contains(strtolower($errorMessage), 'already exists') ||
                str_contains(strtolower($errorMessage), 'duplicate')) {
                return $this->getReservedAccount($user, $accountReference);
            }

            throw new \RuntimeException(
                "Monnify error: {$errorMessage}"
            );
        }

        $body     = $response->json('responseBody');
        $accounts = $body['accounts'] ?? [];

        // Store on user record
        $user->update([
            'monnify_account_ref' => $accountReference,
            'virtual_accounts'    => json_encode($accounts),
        ]);

        Log::info('Monnify: reserved account created', [
            'user_id'   => $user->id,
            'reference' => $accountReference,
            'accounts'  => count($accounts),
        ]);

        return $accounts;
    }

    /**
     * Fetch an existing reserved account by reference.
     *
     * @throws \RuntimeException
     */
    public function getReservedAccount(User $user, string $accountReference): array
    {
        $token = $this->getAccessToken();

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
            ])
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::REQUEST_TIMEOUT)
            ->get("{$this->baseUrl}/api/v2/bank-transfer/reserved-accounts/{$accountReference}");

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new \RuntimeException('Could not connect to Monnify. Please try again.');
        }

        if (! $response->successful() || ! $response->json('requestSuccessful')) {
            Log::error('Monnify: get reserved account failed', [
                'reference' => $accountReference,
                'response'  => $response->json(),
            ]);
            throw new \RuntimeException('Could not fetch your virtual account details.');
        }

        $accounts = $response->json('responseBody.accounts') ?? [];

        // Refresh stored accounts
        $user->update(['virtual_accounts' => json_encode($accounts)]);

        return $accounts;
    }

    /**
     * Get virtual accounts for a user.
     * Returns cached accounts from DB — creates new ones if not set.
     *
     * @return array  e.g. [['bankName' => 'GTBank', 'accountNumber' => '0123456789'], ...]
     */
    public function getOrCreateVirtualAccounts(User $user): array
    {
        // Return cached accounts if already stored
        if (! empty($user->virtual_accounts)) {
            $accounts = is_array($user->virtual_accounts)
                ? $user->virtual_accounts
                : json_decode($user->virtual_accounts, true);

            if (! empty($accounts)) {
                return $accounts;
            }
        }

        // Has a reference but no cached accounts — fetch from Monnify
        if (! empty($user->monnify_account_ref)) {
            try {
                return $this->getReservedAccount($user, $user->monnify_account_ref);
            } catch (\Throwable $e) {
                Log::warning('Monnify: could not fetch existing account, creating new', [
                    'user_id' => $user->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        // No account yet — create one
        return $this->createReservedAccount($user);
    }

    /**
     * Verify a Monnify transaction server-to-server.
     * Used to double-check webhook payload is genuine.
     */
    public function verifyTransaction(string $transactionReference): ?array
    {
        try {
            $token = $this->getAccessToken();

            $encodedRef = urlencode($transactionReference);

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
            ])
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::REQUEST_TIMEOUT)
            ->get("{$this->baseUrl}/api/v2/transactions/{$encodedRef}");

            if (! $response->successful() || ! $response->json('requestSuccessful')) {
                return null;
            }

            return $response->json('responseBody');

        } catch (\Throwable $e) {
            Log::error('Monnify: verify transaction failed', [
                'reference' => $transactionReference,
                'error'     => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Verify Monnify webhook HMAC signature.
     * Monnify signs with SHA-512 HMAC of the raw payload.
     */
    public function verifyWebhookSignature(string $rawPayload, ?string $signature): bool
    {
        if (! $signature || empty($this->secretKey)) {
            return false;
        }

        $expected = hash_hmac('sha512', $rawPayload, $this->secretKey);
        return hash_equals($expected, $signature);
    }
}
