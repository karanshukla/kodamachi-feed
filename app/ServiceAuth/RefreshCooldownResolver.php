<?php

declare(strict_types=1);

namespace App\ServiceAuth;

use KaranShukla\PhpAtprotoIdentity\Resolution\Cache\DidDocumentCache;
use KaranShukla\PhpAtprotoIdentity\Resolution\DidDocumentResolver;

/**
 * Turns a forced refresh into a normal resolution while the cached document is
 * younger than the cooldown.
 *
 * The verifier forces a refresh on every signature mismatch so a key rotation
 * is picked up straight away. A document fetched within the last minute
 * already reflects any rotation that matters, so refetching it again only
 * lets a stream of badly signed tokens turn into a stream of directory
 * requests.
 */
final readonly class RefreshCooldownResolver implements DidDocumentResolver
{
    public function __construct(
        private DidDocumentResolver $resolver,
        private DidDocumentCache $cache,
        private int $cooldown = 60,
    ) {}

    public function resolve(string $did, bool $forceRefresh = false): array
    {
        if ($forceRefresh) {
            $cached = $this->cache->get($did);
            $forceRefresh = $cached === null || $cached['age'] >= $this->cooldown;
        }

        return $this->resolver->resolve($did, $forceRefresh);
    }
}
