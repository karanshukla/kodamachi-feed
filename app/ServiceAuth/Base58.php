<?php

declare(strict_types=1);

namespace App\ServiceAuth;

/**
 * base58btc, the encoding multibase uses behind the `z` prefix.
 */
final class Base58
{
    private const string ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    public static function decode(string $input): string
    {
        if ($input === '') {
            return '';
        }

        $num = gmp_init(0);

        for ($i = 0, $len = strlen($input); $i < $len; $i++) {
            $index = strpos(self::ALPHABET, $input[$i]);

            if ($index === false) {
                throw new ServiceAuthException("Invalid base58 character: {$input[$i]}");
            }

            $num = gmp_add(gmp_mul($num, 58), $index);
        }

        $hex = gmp_strval($num, 16);

        if (strlen($hex) % 2 === 1) {
            $hex = '0' . $hex;
        }

        $bytes = $hex === '0' ? '' : (string) hex2bin($hex);

        // Leading '1's encode leading zero bytes, which the bignum conversion drops.
        for ($i = 0; $i < strlen($input) && $input[$i] === '1'; $i++) {
            $bytes = "\x00" . $bytes;
        }

        return $bytes;
    }
}
