<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiAccessKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ApiKeyAuth
 *
 * Authenticates API requests via Bearer token.
 * Resolves the key, checks active/expired/IP-whitelisted,
 * stamps the authenticated user and key onto the request,
 * and updates last_used_at.
 */
class ApiKeyAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->bearerToken();

        if (! $bearerToken) {
            return $this->unauthorized('API key is required. Pass it as: Authorization: Bearer {your_api_key}');
        }

        // Find the key — scoped to active, non-deleted, non-expired
        $apiKey = ApiAccessKey::withoutGlobalScope('tenant')
            ->where('key', $bearerToken)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })
            ->with('user')
            ->first();

        if (! $apiKey) {
            return $this->unauthorized('Invalid or expired API key.');
        }

        // Check the user account is still active
        $user = $apiKey->user;

        if (! $user) {
            return $this->unauthorized('API key owner not found.');
        }

        $userStatus = $user->status?->value ?? $user->status;
        if (! in_array($userStatus, ['active'], true)) {
            return $this->unauthorized('Your account has been suspended. Contact support.');
        }

        // IP whitelist check (if configured)
        if (! empty($apiKey->allowed_ips)) {
            $clientIp = $request->ip();
            if (! in_array($clientIp, $apiKey->allowed_ips, true)) {
                return $this->unauthorized("Request from IP {$clientIp} is not allowed for this API key.");
            }
        }

        // Stamp onto request for downstream controllers
        $request->merge(['_api_key' => $apiKey, '_api_user' => $user]);
        auth()->setUser($user);

        // Update last_used_at (non-blocking — don't fail the request if this fails)
        try {
            $apiKey->updateQuietly(['last_used_at' => now()]);
        } catch (\Throwable $e) {
            // Silently ignore
        }

        return $next($request);
    }

    private function unauthorized(string $message): Response
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data'    => null,
        ], 401);
    }
}