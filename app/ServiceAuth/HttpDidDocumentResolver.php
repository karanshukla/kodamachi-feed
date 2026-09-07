<?php

declare(strict_types=1);

namespace App\ServiceAuth;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Throwable;

/**
 * Resolves did:plc via a PLC directory and did:web via the domain's
 * .well-known/did.json.
 *
 * Two freshness bounds, matching the behaviour of @atproto/identity's
 * MemoryCache: a document younger than $staleAfter is served without a
 * network call at all, an older one is refetched, and a document younger than
 * $maxAge is still served if that refetch fails. A signing key rotation is
 * therefore visible within $staleAfter, while a directory outage does not
 * take the feed's auth down with it.
 */
final class HttpDidDocumentResolver implements DidDocumentResolver
{
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly DidDocumentCache $cache = new NullDidDocumentCache(),
        private readonly string $plcDirectory = 'https://plc.directory',
        private readonly int $staleAfter = 3600,
        private readonly int $maxAge = 86400,
    ) {}

    public function resolve(string $did, bool $forceRefresh = false): array
    {
        $cached = $this->cache->get($did);

        if (!$forceRefresh && $cached !== null && $cached['age'] < $this->staleAfter) {
            return $cached['document'];
        }

        try {
            $document = $this->fetch($did);
        } catch (Throwable $e) {
            if ($cached !== null && $cached['age'] < $this->maxAge) {
                return $cached['document'];
            }

            throw new ServiceAuthException("Could not resolve {$did}: {$e->getMessage()}", previous: $e);
        }

        $this->cache->put($did, $document);

        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(string $did): array
    {
        $response = $this->httpClient->sendRequest(
            $this->requestFactory->createRequest('GET', $this->documentUrl($did))
                ->withHeader('Accept', 'application/json'),
        );

        if ($response->getStatusCode() !== 200) {
            throw new ServiceAuthException("DID resolution returned HTTP {$response->getStatusCode()}");
        }

        $document = json_decode((string) $response->getBody(), true);

        if (!is_array($document)) {
            throw new ServiceAuthException('DID document is not a JSON object');
        }

        /** @var array<string, mixed> $document */
        return $document;
    }

    private function documentUrl(string $did): string
    {
        if (str_starts_with($did, 'did:plc:')) {
            return rtrim($this->plcDirectory, '/') . '/' . $did;
        }

        if (str_starts_with($did, 'did:web:')) {
            $identifier = substr($did, 8);

            if (str_contains($identifier, ':')) {
                throw new ServiceAuthException('did:web with a path is not supported');
            }

            return 'https://' . urldecode($identifier) . '/.well-known/did.json';
        }

        throw new ServiceAuthException("Unsupported DID method in {$did}");
    }
}
