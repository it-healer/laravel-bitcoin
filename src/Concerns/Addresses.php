<?php

namespace ItHealer\LaravelBitcoin\Concerns;

use ItHealer\LaravelBitcoin\Enums\AddressType;
use ItHealer\LaravelBitcoin\Models\BitcoinAddress;
use ItHealer\LaravelBitcoin\Models\BitcoinNode;
use ItHealer\LaravelBitcoin\Models\BitcoinWallet;

trait Addresses
{
    public function createAddress(
        BitcoinWallet $wallet,
        ?AddressType $type = null,
        ?string $title = null
    ): BitcoinAddress {
        $api = $wallet->node->api();

        if (!$type) {
            $type = config('bitcoin.address_type', AddressType::BECH32);
        }

        if ($wallet->plain_password) {
            $api->request('walletpassphrase', [
                'passphrase' => $wallet->plain_password,
                'timeout' => 60
            ], $wallet->name);
        }

        $data = $api->request('getnewaddress', [
            'address_type' => $type->value,
        ], $wallet->name);
        $address = $data['result'];

        return $wallet->addresses()->create([
            'address' => $address,
            'type' => $type,
            'title' => $title,
        ]);
    }

    public function validateAddress(BitcoinNode $node, string $address): ?AddressType
    {
        $validateAddress = $node->api()->request('validateaddress', [
            'address' => $address
        ]);

        if (!($validateAddress['isvalid'] ?? false)) {
            return null;
        }

        if ($validateAddress['iswitness'] ?? false) {
            return ($validateAddress['witness_version'] ?? false) ? AddressType::BECH32M : AddressType::BECH32;
        }
        if ($validateAddress['isscript'] ?? false) {
            return AddressType::P2SH_SEGWIT;
        }

        return AddressType::LEGACY;
    }
}