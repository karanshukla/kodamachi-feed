<?php

declare(strict_types=1);

namespace App\ServiceAuth;

use GMP;

/**
 * An elliptic-curve public key recovered from a DID document, in a form
 * OpenSSL will accept.
 *
 * ATProto publishes signing keys as compressed points inside a multibase
 * string. OpenSSL wants a PEM-encoded SubjectPublicKeyInfo over an
 * uncompressed point, so the point is decompressed here. Both curves in use
 * have p = 3 (mod 4), which makes the modular square root a single modular
 * exponentiation rather than a full Tonelli-Shanks.
 */
final readonly class VerificationKey
{
    public const string CURVE_SECP256K1 = 'secp256k1';
    public const string CURVE_P256 = 'p256';

    /** @param non-empty-string $compressedPoint 33 bytes: a 0x02/0x03 parity prefix and X */
    public function __construct(
        public string $curve,
        public string $compressedPoint,
    ) {}

    /**
     * The JWS algorithm identifier a token signed by this key must declare.
     */
    public function algorithm(): string
    {
        return $this->curve === self::CURVE_SECP256K1 ? 'ES256K' : 'ES256';
    }

    public function pem(): string
    {
        $der = self::sequence(
            self::sequence(
                // OID 1.2.840.10045.2.1 -- id-ecPublicKey
                "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01" . $this->curveOid(),
            ) . self::bitString("\x04" . $this->uncompressedPoint()),
        );

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private function curveOid(): string
    {
        return $this->curve === self::CURVE_SECP256K1
            // OID 1.3.132.0.10 -- secp256k1
            ? "\x06\x05\x2b\x81\x04\x00\x0a"
            // OID 1.2.840.10045.3.1.7 -- prime256v1
            : "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
    }

    /**
     * @return string 64 bytes: X followed by Y
     */
    private function uncompressedPoint(): string
    {
        if (strlen($this->compressedPoint) !== 33) {
            throw new ServiceAuthException('Compressed point must be 33 bytes');
        }

        $prefix = ord($this->compressedPoint[0]);

        if ($prefix !== 0x02 && $prefix !== 0x03) {
            throw new ServiceAuthException(sprintf('Unexpected point prefix 0x%02x', $prefix));
        }

        [$p, $a, $b] = $this->curveParameters();

        $x = gmp_init(bin2hex(substr($this->compressedPoint, 1)), 16);

        // y^2 = x^3 + ax + b (mod p)
        $ySquared = gmp_mod(
            gmp_add(
                gmp_add(gmp_powm($x, 3, $p), gmp_mul($a, $x)),
                $b,
            ),
            $p,
        );

        // p = 3 (mod 4), so a square root is y^((p+1)/4).
        $y = gmp_powm($ySquared, gmp_div_q(gmp_add($p, 1), 4), $p);

        if (gmp_cmp(gmp_powm($y, 2, $p), $ySquared) !== 0) {
            throw new ServiceAuthException('Point is not on the curve');
        }

        // The prefix records the parity of y; flip to the other root if needed.
        if ((int) gmp_intval(gmp_mod($y, 2)) !== ($prefix & 1)) {
            $y = gmp_sub($p, $y);
        }

        return substr($this->compressedPoint, 1) . self::pad(gmp_strval($y, 16));
    }

    /**
     * @return array{GMP, GMP, GMP} p, a, b
     */
    private function curveParameters(): array
    {
        if ($this->curve === self::CURVE_SECP256K1) {
            return [
                gmp_init('FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEFFFFFC2F', 16),
                gmp_init(0),
                gmp_init(7),
            ];
        }

        return [
            gmp_init('FFFFFFFF00000001000000000000000000000000FFFFFFFFFFFFFFFFFFFFFFFF', 16),
            gmp_init('FFFFFFFF00000001000000000000000000000000FFFFFFFFFFFFFFFFFFFFFFFC', 16),
            gmp_init('5AC635D8AA3A93E7B3EBBD55769886BC651D06B0CC53B0F63BCE3C3E27D2604B', 16),
        ];
    }

    private static function pad(string $hex): string
    {
        return (string) hex2bin(str_pad($hex, 64, '0', STR_PAD_LEFT));
    }

    private static function sequence(string $contents): string
    {
        return "\x30" . self::length(strlen($contents)) . $contents;
    }

    private static function bitString(string $contents): string
    {
        // The leading NUL is the "unused bits" count, always zero for whole bytes.
        return "\x03" . self::length(strlen($contents) + 1) . "\x00" . $contents;
    }

    private static function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        if ($length < 0x100) {
            return "\x81" . chr($length);
        }

        return "\x82" . pack('n', $length);
    }
}
