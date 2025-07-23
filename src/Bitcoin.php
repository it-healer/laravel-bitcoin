<?php

namespace ItHealer\LaravelBitcoin;

use Decimal\Decimal;
use ItHealer\LaravelBitcoin\Enums\AddressType;
use ItHealer\LaravelBitcoin\Models\BitcoinAddress;
use ItHealer\LaravelBitcoin\Models\BitcoinNode;
use ItHealer\LaravelBitcoin\Models\BitcoinWallet;

class Bitcoin
{
    public function createNode(string $name, ?string $title, string $host, int $port = 8332, string $username = null, string $password = null): BitcoinNode
    {
        /** @var class-string<BitcoinNode> $model */
        $model = config('bitcoin.models.rpc_client');
        $api = new $model($host, $port, $username, $password);

        $api->request('getblockchaininfo');

        /** @var class-string<BitcoinNode> $model */
        $model = config('bitcoin.models.node');

        return $model::create([
            'name' => $name,
            'title' => $title,
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
        ]);
    }

    public function createWallet(BitcoinNode $node, string $name, ?string $password = null, ?string $title = null): BitcoinWallet
    {
        $api = $node->api();

        $api->request('createwallet', [
            'wallet_name' => $name,
            'passphrase' => $password,
            'load_on_startup' => true,
        ]);

        if ($password) {
            $api->request('walletpassphrase', [
                'passphrase' => $password,
                'timeout' => 60
            ], $name);
        }

        $descriptors = $api->request('listdescriptors', [
            'private' => true,
        ], $name)['descriptors'];

        $wallet = $node->wallets()->create([
            'name' => $name,
            'title' => $title,
            'password' => $password,
            'descriptors' => $descriptors,
        ]);

        $this->createAddress($wallet, null, 'Primary Address');

        return $wallet;
    }

    public function importWallet(
        BitcoinNode $node,
        string $name,
        array $descriptors,
        ?string $password = null,
        ?string $title = null
    ): BitcoinWallet {
        $api = $node->api();

        $api->request('createwallet', [
            'wallet_name' => $name,
            'passphrase' => $password,
            'blank' => true,
            'load_on_startup' => true,
        ]);

        if ($password) {
            $api->request('walletpassphrase', [
                'passphrase' => $password,
                'timeout' => 60
            ], $name);
        }

        $importDescriptors = $api->request('importdescriptors', [
            'requests' => $descriptors,
        ], $name);

        foreach ($importDescriptors as $item) {
            if (!($item['success'] ?? false)) {
                throw new \Exception(
                    'ImportDescriptors '.($item['error']['code'] ?? 0).' - '.($item['error']['message'] ?? '')
                );
            }
        }

        $wallet = $node->wallets()->create([
            'name' => $name,
            'title' => $title,
            'password' => $password,
            'descriptors' => $descriptors,
        ]);

        $listReceivedByAddress = $api->request('listreceivedbyaddress', ['include_empty' => true], $wallet->name);
        foreach ($listReceivedByAddress as $item) {
            $wallet->addresses()->create([
                'address' => $item['address'],
                'type' => $this->validateAddress($node, $item['address']),
            ]);
        }

        if (count($listReceivedByAddress) === 0) {
            $this->createAddress($wallet, null, 'Primary Address');
        }

        return $wallet;
    }

    public function createAddress(
        BitcoinWallet $wallet,
        ?AddressType $type = null,
        ?string $title = null
    ): BitcoinAddress {
        $api = $wallet->node->api();

        if (!$type) {
            $type = config('bitcoin.address_type', AddressType::BECH32);
        }

        if ($wallet->password) {
            $api->request('walletpassphrase', [
                'passphrase' => $wallet->password,
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

    public function sendAll(BitcoinWallet $wallet, string $address, int|float|null $feeRate = null): string
    {
        $api = $wallet->node->api();

        if ($wallet->password) {
            $api->request('walletpassphrase', [
                'passphrase' => $wallet->password,
                'timeout' => 60
            ], $wallet->name);
        }

        $sendAll = $api->request('sendall', [
            'recipients' => [$address],
            'estimate_mode' => $feeRate ? 'unset' : 'economical',
            'fee_rate' => $feeRate,
            'options' => [
                'send_max' => true,
            ]
        ], $wallet->name);

        if (!($sendAll['complete'] ?? false)) {
            throw new \Exception(json_encode($sendAll));
        }

        return $sendAll['txid'];
    }

    public function send(
        BitcoinWallet $wallet,
        string $address,
        int|float|string|Decimal $amount,
        int|float|null $feeRate = null,
        bool $subtractFeeFromAmount = false
    ): string {
        $api = $wallet->node->api();

        if (($amount instanceof Decimal)) {
            $amount = new Decimal((string)$amount, 8);
        }

        if ($wallet->password) {
            $api->request('walletpassphrase', [
                'passphrase' => $wallet->password,
                'timeout' => 60
            ], $wallet->name);
        }

        $sendToAddress = $api->request('sendtoaddress', [
            'address' => $address,
            'amount' => $amount->toString(),
            'subtractfeefromamount' => $subtractFeeFromAmount,
            'estimate_mode' => $feeRate ? 'unset' : 'economical',
            'fee_rate' => $feeRate
        ], $wallet->name);

        if (!is_string($sendToAddress['result'])) {
            throw new \Exception(json_encode($sendToAddress));
        }

        return $sendToAddress['result'];
    }
}
