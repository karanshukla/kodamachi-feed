<?php

declare(strict_types=1);

namespace App\Api;

use Tempest\Http\Request;
use Tempest\Http\Response;
use Tempest\Router\HttpMiddleware;
use Tempest\Router\HttpMiddlewareCallable;

/**
 * The outermost limit: a ceiling on how much traffic any single address can
 * cost this process, whatever it is asking for.
 *
 * getFeedSkeleton is fetched by the AppView server-side, so this budget is
 * shared across every viewer of the feed rather than being one person's. It
 * has to sit well above real traffic -- if it ever binds, every viewer behind
 * that AppView sees an empty feed at once.
 */
final readonly class ThrottleMiddleware implements HttpMiddleware
{
    private const int REQUESTS = 3000;
    private const int WINDOW_SECONDS = 900;

    public function __construct(
        private RateLimiter $rateLimiter,
    ) {}

    public function __invoke(Request $request, HttpMiddlewareCallable $next): Response
    {
        $address = ClientAddress::of($request);

        if (!$this->rateLimiter->hit('http:' . $address, self::REQUESTS, self::WINDOW_SECONDS)) {
            return XrpcError::rateLimited('Too many requests. Please try again later.');
        }

        return $next($request);
    }
}
