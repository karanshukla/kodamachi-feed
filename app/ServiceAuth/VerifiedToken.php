<?php

declare(strict_types=1);

namespace App\ServiceAuth;

final readonly class VerifiedToken
{
    /** @param array<string, mixed> $claims */
    public function __construct(
        public string $issuer,
        public string $audience,
        public ?string $lxm,
        public array $claims,
    ) {}
}
