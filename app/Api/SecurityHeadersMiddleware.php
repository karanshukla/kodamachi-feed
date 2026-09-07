<?php

declare(strict_types=1);

namespace App\Api;

use Tempest\Http\Request;
use Tempest\Http\Response;
use Tempest\Router\HttpMiddleware;
use Tempest\Router\HttpMiddlewareCallable;

/**
 * Everything this service returns is JSON consumed by an AppView, so the
 * browser-facing surface is nil -- these headers cost nothing and close off
 * the ways a JSON endpoint can still be turned against someone.
 */
final readonly class SecurityHeadersMiddleware implements HttpMiddleware
{
    public function __invoke(Request $request, HttpMiddlewareCallable $next): Response
    {
        $response = $next($request);

        $response->addHeader('X-Content-Type-Options', 'nosniff');
        $response->addHeader('X-Frame-Options', 'DENY');
        $response->addHeader('Referrer-Policy', 'no-referrer');
        $response->addHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");

        return $response;
    }
}
