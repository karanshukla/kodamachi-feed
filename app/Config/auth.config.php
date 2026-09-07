<?php

declare(strict_types=1);

use Aazsamir\Libphpsky\Client\AuthConfig;

use function Tempest\env;

/**
 * Credentials for the *outgoing* calls this service makes (backfill search,
 * publishing the feed record). Unrelated to the service-auth tokens incoming
 * requests carry, which App\ServiceAuth verifies.
 */
return new AuthConfig(
    login: env('FEEDGEN_HANDLE') ?: null,
    password: env('FEEDGEN_APP_PASSWORD') ?: null,
);
