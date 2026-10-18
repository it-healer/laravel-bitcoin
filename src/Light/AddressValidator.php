<?php

namespace ItHealer\LaravelBitcoin\Light;

use ItHealer\LaravelBitcoin\Enums\AddressType;
use ItHealer\LaravelBitcoin\Light\Encoding\Base58;
use ItHealer\LaravelBitcoin\Light\Encoding\Bech32;

/**
 * Mainnet address check without a node: bech32/bech32m (BIP173/BIP350) and Base58Check with
 * the P2PKH (0x00) and P2SH (0x05) versions. Same answer as bitcoind's validateaddress.
 */
class AddressValidator
{
    public static function type(string $address): ?AddressType
    {
        $address = trim($address);

        // Bech32 addresses are at most 90 characters, Base58 ones 26–35: anything longer is
        // rejected before decoding (decoding cost grows with the length)
        if ($address === '' || strlen($address) > 90) {
            return null;
        }

        if (preg_match('/^bc1/i', $address)) {
            try {
                [$version] = Bech32::decodeSegwit('bc', $address);
            } catch (\InvalidArgumentException) {
                return null;
            }

            return $version === 0 ? AddressType::BECH32 : AddressType::BECH32M;
        }

        if (strlen($address) < 26 || strlen($address) > 35) {
            return null;
        }

        try {
            $payload = Base58::decodeCheck($address);
        } catch (\InvalidArgumentException) {
            return null;
        }

        if (strlen($payload) !== 21) {
            return null;
        }

        return match (ord($payload[0])) {
            0x00 => AddressType::LEGACY,
            0x05 => AddressType::P2SH_SEGWIT,
            default => null,
        };
    }
}
