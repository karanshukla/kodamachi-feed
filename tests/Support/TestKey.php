<?php

declare(strict_types=1);

namespace Tests\Support;

use Firebase\JWT\JWT;
use KaranShukla\PhpAtprotoIdentity\Key\DidKey;
use KaranShukla\PhpAtprotoIdentity\Key\VerificationKey;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * A throwaway signing key, published the way a DID document publishes one.
 *
 * Tests that verify tokens need a key they control both halves of: the
 * private half to sign with, and the multibase form a DID document would
 * carry.
 */
final readonly class TestKey
{
    private const string ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    private function __construct(
        private OpenSSLAsymmetricKey $privateKey,
        public string $multibase,
        public string $algorithm,
    ) {}

    public static function secp256k1(): self
    {
        return self::generate('secp256k1', "\xe7\x01", 'ES256K');
    }

    public static function p256(): self
    {
        return self::generate('prime256v1', "\x80\x24", 'ES256');
    }

    /**
     * @param array<string, mixed> $claims
     */
    public function sign(array $claims): string
    {
        return JWT::encode($claims, $this->privateKey, $this->algorithm);
    }

    /**
     * The `verificationMethod` entry a DID document would publish for this key.
     *
     * @return array<string, mixed>
     */
    public function didDocument(string $did): array
    {
        return [
            'id' => $did,
            'verificationMethod' => [
                [
                    'id' => $did . '#atproto',
                    'type' => 'Multikey',
                    'controller' => $did,
                    'publicKeyMultibase' => $this->multibase,
                ],
            ],
        ];
    }

    private static function generate(string $curve, string $multicodec, string $algorithm): self
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => $curve,
        ]);

        if ($key === false) {
            throw new RuntimeException("Could not generate a {$curve} key");
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false) {
            throw new RuntimeException('Could not read the generated key');
        }

        $x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
        $parity = (ord($y[31]) & 1) === 1 ? "\x03" : "\x02";

        return new self($key, 'z' . self::base58($multicodec . $parity . $x), $algorithm);
    }

    private static function base58(string $bytes): string
    {
        $number = gmp_init(bin2hex($bytes) ?: '0', 16);
        $encoded = '';

        while (gmp_cmp($number, 0) > 0) {
            $encoded = self::ALPHABET[gmp_intval(gmp_mod($number, 58))] . $encoded;
            $number = gmp_div_q($number, 58);
        }

        for ($i = 0; $i < strlen($bytes) && $bytes[$i] === "\x00"; $i++) {
            $encoded = '1' . $encoded;
        }

        return $encoded;
    }

    /** Exposed so a test can assert the decoded key matches what OpenSSL made. */
    public function verificationKey(): VerificationKey
    {
        return DidKey::fromMultibase($this->multibase);
    }
}
