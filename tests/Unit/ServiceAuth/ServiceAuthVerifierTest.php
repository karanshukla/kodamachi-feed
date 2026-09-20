<?php

declare(strict_types=1);

namespace Tests\Unit\ServiceAuth;

use App\ServiceAuth\ServiceAuthException;
use App\ServiceAuth\ServiceAuthVerifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeDidDocumentResolver;
use Tests\Support\TestKey;

/**
 * @internal
 */
final class ServiceAuthVerifierTest extends TestCase
{
    private const string ISSUER = 'did:plc:requester';
    private const string AUDIENCE = 'did:web:feed.test';
    private const string LXM = 'app.bsky.feed.getFeedSkeleton';

    #[Test]
    public function accepts_a_well_formed_token(): void
    {
        $key = TestKey::secp256k1();
        $verifier = self::verifierFor($key);

        $verified = $verifier->verify($this->tokenFrom($key), self::AUDIENCE, self::LXM);

        self::assertSame(self::ISSUER, $verified->issuer);
        self::assertSame(self::LXM, $verified->lxm);
    }

    #[Test]
    public function accepts_a_p256_signed_token(): void
    {
        $key = TestKey::p256();

        self::assertSame(
            self::ISSUER,
            self::verifierFor($key)->verify($this->tokenFrom($key), self::AUDIENCE, self::LXM)->issuer,
        );
    }

    /**
     * `lxm` postdates the original service-auth spec, so a token without it
     * comes from an older implementation rather than from the wrong method.
     * Rejecting those 401s a whole client, which shows its users a
     * permanently empty feed.
     */
    #[Test]
    public function tolerates_a_token_minted_without_an_lxm_claim(): void
    {
        $key = TestKey::secp256k1();
        $token = $this->tokenFrom($key, lxm: null);

        self::assertSame(
            self::ISSUER,
            self::verifierFor($key)->verify($token, self::AUDIENCE, self::LXM)->issuer,
        );
    }

    #[Test]
    public function rejects_a_token_minted_for_a_different_method(): void
    {
        $key = TestKey::secp256k1();
        $token = $this->tokenFrom($key, lxm: 'app.bsky.feed.getFeed');

        $this->expectException(ServiceAuthException::class);

        self::verifierFor($key)->verify($token, self::AUDIENCE, self::LXM);
    }

    #[Test]
    public function rejects_a_token_addressed_to_another_service(): void
    {
        $key = TestKey::secp256k1();
        $token = $this->tokenFrom($key, audience: 'did:web:someone.else');

        $this->expectException(ServiceAuthException::class);

        self::verifierFor($key)->verify($token, self::AUDIENCE, self::LXM);
    }

    #[Test]
    public function rejects_an_expired_token(): void
    {
        $key = TestKey::secp256k1();
        $token = $this->tokenFrom($key, expiresAt: time() - 3600);

        $this->expectException(ServiceAuthException::class);

        self::verifierFor($key)->verify($token, self::AUDIENCE, self::LXM);
    }

    /**
     * A token with no expiry would be valid forever, so its absence is a
     * rejection rather than something to shrug at.
     */
    #[Test]
    public function rejects_a_token_with_no_expiry(): void
    {
        $key = TestKey::secp256k1();
        $token = $key->sign([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'lxm' => self::LXM,
        ]);

        $this->expectException(ServiceAuthException::class);

        self::verifierFor($key)->verify($token, self::AUDIENCE, self::LXM);
    }

    #[Test]
    public function rejects_a_token_signed_by_someone_else(): void
    {
        $published = TestKey::secp256k1();
        $impostor = TestKey::secp256k1();

        $this->expectException(ServiceAuthException::class);

        self::verifierFor($published)->verify($this->tokenFrom($impostor), self::AUDIENCE, self::LXM);
    }

    #[Test]
    public function rejects_a_malformed_token(): void
    {
        $this->expectException(ServiceAuthException::class);

        self::verifierFor(TestKey::secp256k1())->verify('not.a.jwt', self::AUDIENCE, self::LXM);
    }

    /**
     * A rotated signing key makes every cached document stale. Refetching once
     * costs one request; not refetching costs an hour of 401s for that
     * account.
     */
    #[Test]
    public function refetches_the_document_once_when_the_signature_does_not_match(): void
    {
        $rotated = TestKey::secp256k1();
        $resolver = new FakeDidDocumentResolver([
            TestKey::secp256k1()->didDocument(self::ISSUER),
            $rotated->didDocument(self::ISSUER),
        ]);

        $verified = new ServiceAuthVerifier($resolver)
            ->verify($this->tokenFrom($rotated), self::AUDIENCE, self::LXM);

        self::assertSame(self::ISSUER, $verified->issuer);
        self::assertSame(1, $resolver->refreshes);
    }

    /**
     * Only a signature mismatch is worth a second resolution: an expired token
     * will not verify against a fresher document either.
     */
    #[Test]
    public function does_not_refetch_for_a_failure_a_fresh_document_cannot_fix(): void
    {
        $key = TestKey::secp256k1();
        $resolver = new FakeDidDocumentResolver([$key->didDocument(self::ISSUER)]);

        try {
            new ServiceAuthVerifier($resolver)
                ->verify($this->tokenFrom($key, expiresAt: time() - 3600), self::AUDIENCE, self::LXM);
            self::fail('Expected the expired token to be rejected');
        } catch (ServiceAuthException) {
            self::assertSame(0, $resolver->refreshes);
        }
    }

    #[Test]
    public function resolves_the_bare_did_when_the_issuer_names_a_service(): void
    {
        $key = TestKey::secp256k1();
        $resolver = new FakeDidDocumentResolver([$key->didDocument(self::ISSUER)]);
        $token = $key->sign([
            'iss' => self::ISSUER . '#atproto_labeler',
            'aud' => self::AUDIENCE,
            'exp' => time() + 60,
        ]);

        new ServiceAuthVerifier($resolver)->verify($token, self::AUDIENCE, self::LXM);

        self::assertSame([self::ISSUER], $resolver->dids);
    }

    #[Test]
    public function reads_the_issuer_off_an_unverifiable_token_for_logging(): void
    {
        $token = TestKey::secp256k1()->sign(['iss' => self::ISSUER, 'aud' => 'wrong', 'exp' => 1]);

        self::assertSame(self::ISSUER, ServiceAuthVerifier::unverifiedIssuer($token));
        self::assertNull(ServiceAuthVerifier::unverifiedIssuer('garbage'));
    }

    private static function verifierFor(TestKey $key): ServiceAuthVerifier
    {
        return new ServiceAuthVerifier(
            new FakeDidDocumentResolver([$key->didDocument(self::ISSUER)]),
        );
    }

    private function tokenFrom(
        TestKey $key,
        ?string $lxm = self::LXM,
        string $audience = self::AUDIENCE,
        ?int $expiresAt = null,
    ): string {
        $claims = [
            'iss' => self::ISSUER,
            'aud' => $audience,
            'exp' => $expiresAt ?? time() + 60,
        ];

        if ($lxm !== null) {
            $claims['lxm'] = $lxm;
        }

        return $key->sign($claims);
    }
}
