<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use App\Api\ClientAddress;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tempest\Http\GenericRequest;
use Tempest\Http\Method;

/**
 * @internal
 */
final class ClientAddressTest extends TestCase
{
    #[Test]
    public function trusts_only_the_hop_the_edge_appended(): void
    {
        // Everything left of the last entry came from the caller, who could
        // otherwise pick a fresh rate-limit bucket on every request.
        $request = new GenericRequest(Method::GET, '/', headers: [
            'X-Forwarded-For' => '10.0.0.1, 203.0.113.7',
        ]);

        self::assertSame('203.0.113.7', ClientAddress::of($request));
    }

    #[Test]
    public function reads_a_single_forwarded_address(): void
    {
        $request = new GenericRequest(Method::GET, '/', headers: ['X-Forwarded-For' => '203.0.113.7']);

        self::assertSame('203.0.113.7', ClientAddress::of($request));
    }
}
