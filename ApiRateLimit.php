<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * ApiRateLimit
 *
 * Per-key rate limiting.
 * Default: 60 requests/minute globally.
 * Per-key override: stored in api_access_keys.rate_limit column.
 * Returns standard rate limit headers on every response.
 */
class ApiRateLimit
{
    private const DEFAULT_LIMIT   = 60;   // requests per window
    private const WINDOW_SECONDS  = 60;   // 1 minute window

    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->get('_api_key');

        if (! $apiKey) {
            return $next($request);
        }

        $limit    = (int) ($apiKey->rate_limit ?? self::DEFAULT_LIMIT);
        $cacheKey = 'api_rate_limit_' . $apiKey->id . '_' . floor(time() / self::WINDOW_SECONDS);
        $current  = (int) Cache::get($cacheKey, 0);

        if ($current >= $limit) {
            return response()->json([
                'success' => false,
                'message' => 'Rate limit exceeded. Maximum ' . $limit . ' requests per minute.',
                'data'    => null,
            ], 429)->withHeaders([
                'X-RateLimit-Limit'     => $limit,
                'X-RateLimit-Remaining' => 0,
                'X-RateLimit-Reset'     => (floor(time() / self::WINDOW_SECONDS) + 1) * self::WINDOW_SECONDS,
                'Retry-After'           => self::WINDOW_SECONDS - (time() % self::WINDOW_SECONDS),
            ]);
        }

        // Increment counter
        Cache::put($cacheKey, $current + 1, self::WINDOW_SECONDS);

        $response = $next($request);

        // Add rate limit headers to every response
        return $response->withHeaders([
            'X-RateLimit-Limit'     => $limit,
            'X-RateLimit-Remaining' => max(0, $limit - $current - 1),
            'X-RateLimit-Reset'     => (floor(time() / self::WINDOW_SECONDS) + 1) * self::WINDOW_SECONDS,
        ]);
    }
}