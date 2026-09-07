<?php

declare(strict_types=1);

namespace App\ServiceAuth;

/**
 * Storage for resolved DID documents.
 *
 * Kept deliberately small so a host application can back it with whatever it
 * already has (a table, APCu, a PSR-16 pool) without pulling a cache
 * abstraction into this component.
 */
interface DidDocumentCache
{
    /**
     * @return array{document: array<string, mixed>, age: int}|null age is in seconds
     */
    public function get(string $did): ?array;

    /** @param array<string, mixed> $document */
    public function put(string $did, array $document): void;
}
