<?php

namespace ItHealer\LaravelBitcoin\Light\Tx;

use ItHealer\LaravelBitcoin\Light\Encoding\Base58;
use ItHealer\LaravelBitcoin\Light\Encoding\Bech32;

/**
 * Output scripts (scriptPubKey) of mainnet addresses and OP_RETURN, with their dust limits.
 */
class Script
{
    /** Dust of an output by script kind (Bitcoin Core, dustRelayFee 3 sat/vB) */
    protected const DUST = ['p2pkh' => 546, 'p2sh' => 540, 'p2wpkh' => 294, 'p2wsh' => 330, 'p2tr' => 330, 'witness' => 330];

    public static function forAddress(string $address): string
    {
        return self::parse($address)['script'];
    }

    /**
     * @return array{script: string, kind: string}
     */
    public static function parse(string $address): array
    {
        $address = trim($address);

        if (preg_match('/^bc1/i', $address)) {
            [$version, $program] = Bech32::decodeSegwit('bc', $address);
            $kind = match (true) {
                $version === 0 && strlen($program) === 20 => 'p2wpkh',
                $version === 0 => 'p2wsh',
                $version === 1 && strlen($program) === 32 => 'p2tr',
                default => 'witness',
            };

            return ['script' => chr($version === 0 ? 0 : 0x50 + $version).chr(strlen($program)).$program, 'kind' => $kind];
        }

        if (strlen($address) < 26 || strlen($address) > 35) {
            throw new \InvalidArgumentException('Invalid Bitcoin address.');
        }

        $payload = Base58::decodeCheck($address);

        if (strlen($payload) !== 21) {
            throw new \InvalidArgumentException('Invalid Bitcoin address.');
        }

        $hash = substr($payload, 1);

        return match (ord($payload[0])) {
            0x00 => ['script' => "\x76\xa9\x14".$hash."\x88\xac", 'kind' => 'p2pkh'],
            0x05 => ['script' => "\xa9\x14".$hash."\x87", 'kind' => 'p2sh'],
            default => throw new \InvalidArgumentException('Not a mainnet Bitcoin address.'),
        };
    }

    public static function opReturn(string $data): string
    {
        if (strlen($data) > 80) {
            throw new \InvalidArgumentException('OP_RETURN data is limited to 80 bytes.');
        }

        // OP_RETURN, then a direct push (up to 75 bytes) or OP_PUSHDATA1
        return "\x6a".(strlen($data) <= 75 ? chr(strlen($data)) : "\x4c".chr(strlen($data))).$data;
    }

    public static function dust(string $kind): int
    {
        return self::DUST[$kind] ?? 546;
    }

    /**
     * Virtual size of an output with this script: value (8) + length + script.
     */
    public static function outputVsize(string $script): int
    {
        return 8 + strlen(Transaction::varInt(strlen($script))) + strlen($script);
    }
}
