<?php

declare(strict_types=1);

namespace Tests\Support;

use App\ServiceAuth\DidDocumentResolver;
use App\ServiceAuth\ServiceAuthException;

/**
 * Serves a scripted sequence of DID documents, and counts resolutions, so a
 * test can assert on refetch behaviour without touching the network.
 */
final class FakeDidDocumentResolver implements DidDocumentResolver
{
    public int $resolutions = 0;

    public int $refreshes = 0;

    /** @var list<array<string, mixed>> */
    private array $documents;

    /** @param list<array<string, mixed>> $documents served in order, the last one repeating */
    public function __construct(array $documents)
    {
        $this->documents = $documents;
    }

    public function resolve(string $did, bool $forceRefresh = false): array
    {
        if ($this->documents === []) {
            throw new ServiceAuthException("No document for {$did}");
        }

        if ($forceRefresh) {
            $this->refreshes++;
        }

        $index = min($this->resolutions, count($this->documents) - 1);
        $this->resolutions++;

        return $this->documents[$index];
    }
}
