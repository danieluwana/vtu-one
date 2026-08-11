<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * HoneypotProtection Middleware
 *
 * Adds invisible honeypot field detection.
 * Real users never fill in hidden fields.
 * Bots that auto-fill forms will get caught.
 *
 * Also checks:
 *  - Form submission time (too fast = bot)
 *  - Hidden field filled = bot
 */
class HoneypotProtection
{
    // Minimum seconds a human would take to fill the form
    private const MIN_FORM_TIME_SECONDS = 3;

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST')) {

            // Check 1: Honeypot field filled (bots fill all fields)
            // The field name looks legitimate but is hidden via CSS
            if ($request->filled('website') || $request->filled('phone_number_confirm')) {
                Log::warning('Honeypot triggered — bot detected', [
                    'ip'         => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'url'        => $request->url(),
                ]);

                // Return fake success to confuse the bot
                return response()->json([
                    'success' => true,
                    'message' => 'Request received.',
                ]);
            }

            // Check 2: Form submitted too fast
            $formLoadedAt = $request->session()->get('form_loaded_at');
            if ($formLoadedAt) {
                $elapsed = time() - $formLoadedAt;
                if ($elapsed < self::MIN_FORM_TIME_SECONDS) {
                    Log::warning('Form submitted too fast — possible bot', [
                        'ip'      => $request->ip(),
                        'elapsed' => $elapsed,
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Please try again.',
                    ], 422);
                }
            }
        }

        // Set form load time for timing check
        if ($request->isMethod('GET')) {
            $request->session()->put('form_loaded_at', time());
        }

        return $next($request);
    }
}
