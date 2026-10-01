<?php

declare(strict_types=1);

namespace Tests\Unit\ServiceAuth;

use App\ServiceAuth\RefreshCooldownResolver;
use KaranShukla\PhpAtprotoIdentity\Resolution\Cache\DidDocumentCache;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeDidDocumentResolver;

/**
 * @internal
 */
final class RefreshCooldownResolverTest extends TestCase
{
    private const string DID = 'did:plc:requester';

    #[Test]
    public function does_not_refetch_a_document_fetched_within_the_cooldown(): void
    {
        $inner = new FakeDidDocumentResolver([['id' => self::DID]]);

        new RefreshCooldownResolver($inner, self::cacheHolding(age: 10))->resolve(self::DID, forceRefresh: true);

        self::assertSame(0, $inner->refreshes);
    }

    #[Test]
    public function refetches_a_document_older_than_the_cooldown(): void
    {
        $inner = new FakeDidDocumentResolver([['id' => self::DID]]);

        new RefreshCooldownResolver($inner, self::cacheHolding(age: 60))->resolve(self::DID, forceRefresh: true);

        self::assertSame(1, $inner->refreshes);
    }

    #[Test]
    public function refetches_when_nothing_is_cached(): void
    {
        $inner = new FakeDidDocumentResolver([['id' => self::DID]]);

        new RefreshCooldownResolver($inner, self::cacheHolding(age: null))->resolve(self::DID, forceRefresh: true);

        self::assertSame(1, $inner->refreshes);
    }

    private static function cacheHolding(?int $age): DidDocumentCache
    {
        return new readonly class($age) implements DidDocumentCache {
            public function __construct(private ?int $age) {}

            public function get(string $did): ?array
            {
                return $this->age === null ? null : ['document' => ['id' => $did], 'age' => $this->age];
            }

            public function put(string $did, array $document): void {}
        };
    }
}
