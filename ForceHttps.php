<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ForceHttps Middleware
 *
 * Redirects all HTTP requests to HTTPS in production.
 * Only applies when APP_ENV=production.
 *
 * Also sets secure cookie headers on the response.
 */
class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        // Only force HTTPS in production
        if (app()->isProduction() && ! $request->isSecure()) {
            return redirect()->secure($request->getRequestUri(), 301);
        }

        $response = $next($request);

        // Set secure cookie attributes on every response
        if (app()->isProduction()) {
            foreach ($response->headers->getCookies() as $cookie) {
                $response->headers->setCookie(
                    new \Symfony\Component\HttpFoundation\Cookie(
                        name:     $cookie->getName(),
                        value:    $cookie->getValue(),
                        expire:   $cookie->getExpiresTime(),
                        path:     $cookie->getPath(),
                        domain:   $cookie->getDomain(),
                        secure:   true,       // HTTPS only
                        httpOnly: true,       // No JS access
                        raw:      false,
                        sameSite: 'Strict',   // CSRF protection
                    )
                );
            }
        }

        return $response;
    }
}
