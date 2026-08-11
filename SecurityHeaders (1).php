<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SecurityHeaders
 *
 * Applies security headers to every response.
 * Production CSP allows Paystack and Monnify payment flows.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // ── Standard security headers ──────────────────────────────────────
        $response->headers->set('X-Content-Type-Options',  'nosniff');
        $response->headers->set('X-Frame-Options',          'SAMEORIGIN');
        $response->headers->set('X-XSS-Protection',         '1; mode=block');
        $response->headers->set('Referrer-Policy',           'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy',        'camera=(), microphone=(), geolocation=()');

        // ── HSTS — only on production with HTTPS ───────────────────────────
        if (! app()->isLocal()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains; preload'
            );
        }

        // ── Content Security Policy ────────────────────────────────────────
        $csp = app()->isLocal() ? $this->devCsp() : $this->productionCsp();
        $response->headers->set('Content-Security-Policy', $csp);

        return $response;
    }

    /**
     * Development CSP — permissive, allows Vite HMR and all local ports.
     */
    private function devCsp(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' "
                . "http://localhost:* http://127.0.0.1:* "
                . "https://js.paystack.co https://cdn.jsdelivr.net",
            "style-src 'self' 'unsafe-inline' "
                . "http://localhost:* http://127.0.0.1:* "
                . "https://fonts.googleapis.com https://cdn.jsdelivr.net",
            "font-src 'self' data: "
                . "http://localhost:* http://127.0.0.1:* "
                . "https://fonts.gstatic.com https://cdn.jsdelivr.net",
            "img-src 'self' data: https: http://localhost:* http://127.0.0.1:* https://ui-avatars.com",
            "connect-src 'self' "
                . "ws://localhost:* ws://127.0.0.1:* "
                . "http://localhost:* http://127.0.0.1:* "
                . "https://api.paystack.co https://api.monnify.com https://sandbox.monnify.com",
            // Allow Paystack checkout iframe + YouTube video embeds (promo banners)
            "frame-src 'self' https://checkout.paystack.com https://*.paystack.co https://www.youtube.com https://www.youtube-nocookie.com",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self' https://checkout.paystack.com",
        ]);
    }

    /**
     * Production CSP — strict but allows all payment gateway flows.
     *
     * Paystack: needs js.paystack.co script + checkout.paystack.com iframe
     * Monnify:  server-to-server only, no frontend assets needed
     * Fonts:    Google Fonts + jsdelivr for icons/CSS
     * Avatars:  ui-avatars.com for user profile pictures
     */
    private function productionCsp(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' "
                . "https://js.paystack.co https://cdn.jsdelivr.net",
            "style-src 'self' 'unsafe-inline' "
                . "https://fonts.googleapis.com https://cdn.jsdelivr.net",
            "font-src 'self' data: "
                . "https://fonts.gstatic.com https://cdn.jsdelivr.net",
            "img-src 'self' data: https: https://ui-avatars.com",
            "connect-src 'self' "
                . "https://api.paystack.co "
                . "https://api.monnify.com "
                . "https://checkout.paystack.com",
            // Allow Paystack checkout iframe + YouTube video embeds (promo banners) — critical for payment flow and video banners
            "frame-src 'self' https://checkout.paystack.com https://*.paystack.co https://www.youtube.com https://www.youtube-nocookie.com",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self' https://checkout.paystack.com",
        ]);
    }
}
