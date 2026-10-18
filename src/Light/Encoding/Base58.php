<?php

namespace ItHealer\LaravelBitcoin\Light\Encoding;

/**
 * Base58 and Base58Check (Bitcoin alphabet): extended keys and legacy/P2SH addresses.
 */
class Base58
{
    protected const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    public static function encode(string $binary): string
    {
        $number = gmp_import($binary === '' ? "\0" : $binary);
        $encoded = '';

        while (gmp_cmp($number, 0) > 0) {
            [$number, $remainder] = gmp_div_qr($number, 58);
            $encoded = self::ALPHABET[gmp_intval($remainder)].$encoded;
        }

        $leadingZeros = strlen($binary) - strlen(ltrim($binary, "\0"));

        return str_repeat('1', $leadingZeros).$encoded;
    }

    /** Longest accepted input: an extended key is 111 characters, an address up to 35 */
    public const MAX_LENGTH = 128;

    public static function decode(string $encoded): string
    {
        if (strlen($encoded) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('Base58 string is too long.');
        }

        $number = gmp_init(0);

        for ($i = 0, $length = strlen($encoded); $i < $length; $i++) {
            $digit = strpos(self::ALPHABET, $encoded[$i]);

            if ($digit === false) {
                throw new \InvalidArgumentException('Invalid Base58 character.');
            }

            $number = gmp_add(gmp_mul($number, 58), $digit);
        }

        $binary = gmp_cmp($number, 0) > 0 ? gmp_export($number) : '';
        $leadingOnes = strlen($encoded) - strlen(ltrim($encoded, '1'));

        return str_repeat("\0", $leadingOnes).$binary;
    }

    public static function encodeCheck(string $payload): string
    {
        return self::encode($payload.self::checksum($payload));
    }

    /**
     * Payload without the 4-byte checksum; throws when the checksum does not match.
     */
    public static function decodeCheck(string $encoded): string
    {
        $binary = self::decode($encoded);

        if (strlen($binary) < 5) {
            throw new \InvalidArgumentException('Base58Check payload is too short.');
        }

        $payload = substr($binary, 0, -4);

        if (!hash_equals(self::checksum($payload), substr($binary, -4))) {
            throw new \InvalidArgumentException('Invalid Base58Check checksum.');
        }

        return $payload;
    }

    protected static function checksum(string $payload): string
    {
        return substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
    }
}
