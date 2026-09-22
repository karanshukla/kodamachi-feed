<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use App\Api\RequestAuthenticator;
use App\Feed\FeedConfig;
use App\ServiceAuth\ServiceAuthVerifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tempest\Http\GenericRequest;
use Tempest\Http\Method;
use Tests\Support\FakeDidDocumentResolver;
use Tests\Support\TestKey;

/**
 * @internal
 */
final class RequestAuthenticatorTest extends TestCase
{
    private const string AUDIENCE = 'did:web:feed.test';
    private const string LXM = 'app.bsky.feed.getFeedSkeleton';

    #[Test]
    public function verifies_a_did_plc_token(): void
    {
        $key = TestKey::secp256k1();
        $resolver = new FakeDidDocumentResolver([$key->didDocument('did:plc:requester')]);

        self::assertSame(
            'did:plc:requester',
            self::authenticator($resolver)->authenticate(self::request($key, 'did:plc:requester'), self::LXM),
        );
    }

    /**
     * A did:web issuer names the host its document is fetched from, so
     * resolving one lets the caller decide how long a worker waits and what
     * gets cached. With auth optional that buys nothing but a rate-limit
     * bucket.
     */
    #[Test]
    public function does_not_resolve_a_did_web_token_when_auth_is_optional(): void
    {
        $key = TestKey::secp256k1();
        $resolver = new FakeDidDocumentResolver([$key->didDocument('did:web:attacker.example')]);

        self::assertNull(
            self::authenticator($resolver)->authenticate(self::request($key, 'did:web:attacker.example'), self::LXM),
        );
        self::assertSame([], $resolver->dids);
    }

    private static function authenticator(FakeDidDocumentResolver $resolver): RequestAuthenticator
    {
        return new RequestAuthenticator(
            new FeedConfig(
                hostname: 'feed.test',
                serviceDid: self::AUDIENCE,
                publisherDid: 'did:plc:publisher',
                textTerms: ['kodamachi'],
                altTerms: ['kodamachi'],
            ),
            new ServiceAuthVerifier($resolver),
            new NullLogger(),
        );
    }

    private static function request(TestKey $key, string $issuer): GenericRequest
    {
        $token = $key->sign(['iss' => $issuer, 'aud' => self::AUDIENCE, 'exp' => time() + 60, 'lxm' => self::LXM]);

        return new GenericRequest(Method::GET, '/', headers: ['Authorization' => "Bearer {$token}"]);
    }
}
