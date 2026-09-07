<?php

declare(strict_types=1);

namespace Tests\Unit\ServiceAuth;

use App\ServiceAuth\DidKey;
use App\ServiceAuth\ServiceAuthException;
use App\ServiceAuth\VerificationKey;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestKey;

/**
 * @internal
 */
final class DidKeyTest extends TestCase
{
    #[Test]
    public function derives_a_usable_secp256k1_key_from_its_multibase_form(): void
    {
        $testKey = TestKey::secp256k1();
        $decoded = DidKey::fromMultibase($testKey->multibase);

        self::assertSame(VerificationKey::CURVE_SECP256K1, $decoded->curve);
        self::assertSame('ES256K', $decoded->algorithm());

        // The property that matters: a signature made with the private half
        // verifies against the key recovered from the published half. If the
        // point decompression were wrong, this is what would fail.
        $token = $testKey->sign(['iss' => 'did:plc:test', 'exp' => time() + 60]);
        $claims = JWT::decode($token, new Key($decoded->pem(), $decoded->algorithm()));

        self::assertSame('did:plc:test', $claims->iss);
    }

    #[Test]
    public function derives_a_usable_p256_key_from_its_multibase_form(): void
    {
        $testKey = TestKey::p256();
        $decoded = DidKey::fromMultibase($testKey->multibase);

        self::assertSame(VerificationKey::CURVE_P256, $decoded->curve);
        self::assertSame('ES256', $decoded->algorithm());

        $token = $testKey->sign(['iss' => 'did:plc:test', 'exp' => time() + 60]);

        self::assertSame(
            'did:plc:test',
            JWT::decode($token, new Key($decoded->pem(), $decoded->algorithm()))->iss,
        );
    }

    #[Test]
    public function accepts_the_did_key_form(): void
    {
        $multibase = TestKey::secp256k1()->multibase;

        self::assertEquals(
            DidKey::fromMultibase($multibase),
            DidKey::fromDidKey('did:key:' . $multibase),
        );
    }

    #[Test]
    public function rejects_a_multibase_that_is_not_base58btc(): void
    {
        $this->expectException(ServiceAuthException::class);

        DidKey::fromMultibase('mAQIDBA');
    }

    #[Test]
    public function rejects_a_key_type_atproto_does_not_sign_with(): void
    {
        $this->expectException(ServiceAuthException::class);

        // Ed25519 (multicodec 0xed 0x01): a real key type, but not one used
        // for ATProto repo signatures.
        DidKey::fromMultibase('z6MkhaXgBZDvotDkL5257faiztiGiC2QtKLGpbnnEGta2doK');
    }
}
