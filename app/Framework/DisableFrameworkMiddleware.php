<?php

declare(strict_types=1);

namespace App\Framework;

use Tempest\Core\KernelEvent;
use Tempest\EventBus\EventHandler;
use Tempest\Http\Session\ManageSessionMiddleware;
use Tempest\Http\Session\TrackPreviousUrlMiddleware;
use Tempest\Router\RouteConfig;
use Tempest\Router\SetCookieHeadersMiddleware;

/**
 * This is a machine-to-machine JSON API: nothing here has a session, and the
 * cookies the session middleware sets would only be noise on an XRPC
 * response.
 */
final class DisableFrameworkMiddleware
{
    public function __construct(
        private readonly RouteConfig $routeConfig,
    ) {}

    #[EventHandler(KernelEvent::BOOTED)]
    public function __invoke(): void
    {
        $this->routeConfig->middleware->remove(ManageSessionMiddleware::class);
        $this->routeConfig->middleware->remove(SetCookieHeadersMiddleware::class);
        $this->routeConfig->middleware->remove(TrackPreviousUrlMiddleware::class);
    }
}
