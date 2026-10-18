<?php

namespace ItHealer\LaravelBitcoin\Concerns;

use ItHealer\LaravelBitcoin\Light\AddressBook;
use ItHealer\LaravelBitcoin\Light\AddressValidator;
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
        if ($wallet->isLight()) {
            return (new AddressBook($wallet))->issueReceiveAddress($title);
        }

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

    /**
     * Address type, or null for an invalid mainnet address. Checked locally, the node is not
     * needed any more (the argument is kept for compatibility).
     */
    public function validateAddress(?BitcoinNode $node, string $address): ?AddressType
    {
        return AddressValidator::type($address);
    }
}
