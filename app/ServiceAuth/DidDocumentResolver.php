<?php

declare(strict_types=1);

namespace App\ServiceAuth;

interface DidDocumentResolver
{
    /**
     * @return array<string, mixed> the DID document
     */
    public function resolve(string $did, bool $forceRefresh = false): array;
}
