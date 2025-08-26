<?php

namespace ItHealer\LaravelBitcoin\Concerns;

use ItHealer\LaravelBitcoin\Facades\Bitcoin;
use ItHealer\LaravelBitcoin\Models\BitcoinNode;
use ItHealer\LaravelBitcoin\Models\BitcoinWallet;

trait Wallets
{
    public function createWallet(
        BitcoinNode $node,
        string $name,
        ?string $password = null,
        ?string $title = null,
        ?bool $savePassword = true
    ): BitcoinWallet {
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

        /** @var class-string<BitcoinWallet> $walletModel */
        $walletModel = Bitcoin::getModelWallet();
        $wallet = new $walletModel([
            'node_id' => $node->id,
            'name' => $name,
            'title' => $title,
        ]);

        $wallet->unlockWallet($password);
        if ($savePassword) {
            $wallet->password = $password;
        }
        $wallet->descriptors = json_encode($descriptors);
        $wallet->save();

        Bitcoin::createAddress($wallet, null, 'Primary Address');

        return $wallet;
    }

    public function importWallet(
        BitcoinNode $node,
        string $name,
        array $descriptors,
        ?string $password = null,
        ?string $title = null,
        ?bool $savePassword = true
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

        /** @var class-string<BitcoinWallet> $walletModel */
        $walletModel = Bitcoin::getModelWallet();
        $wallet = new $walletModel([
            'node_id' => $node->id,
            'name' => $name,
            'title' => $title,
        ]);

        $wallet->unlockWallet($password);
        if ($savePassword) {
            $wallet->password = $password;
        }
        $wallet->descriptors = json_encode($descriptors);
        $wallet->save();

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

    public function createWalletBIP39(
        BitcoinNode $node,
        string $name,
        ?string $password = null,
        ?int $mnemonicSize = 18,
        ?string $passphrase = null,
        ?bool $savePassword = true,
    ): BitcoinWallet {
        $mnemonic = Bitcoin::bip39MnemonicGenerate($mnemonicSize);
        $seed = Bitcoin::bip39MnemonicSeed($mnemonic, $passphrase);
        $descriptors = Bitcoin::bip39ToDescriptors($mnemonic, $passphrase);

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

        /** @var class-string<BitcoinWallet> $walletModel */
        $walletModel = Bitcoin::getModelWallet();
        $wallet = new $walletModel([
            'node_id' => $node->id,
            'name' => $name,
        ]);

        $wallet->unlockWallet($password);
        if ($savePassword) {
            $wallet->password = $password;
        }
        $wallet->mnemonic = implode(" ", $mnemonic);
        $wallet->seed = $seed;
        $wallet->descriptors = json_encode($descriptors);
        $wallet->save();

        $listReceivedByAddress = $api->request('listreceivedbyaddress', ['include_empty' => true], $wallet->name);
        foreach ($listReceivedByAddress as $item) {
            $wallet->addresses()->create([
                'address' => $item['address'],
                'type' => Bitcoin::validateAddress($node, $item['address']),
            ]);
        }

        if (count($listReceivedByAddress) === 0) {
            Bitcoin::createAddress($wallet, null, 'Primary Address');
        }

        return $wallet;
    }

    public function restoreWalletBIP39(
        BitcoinNode $node,
        string $name,
        string|array $mnemonic,
        ?string $passphrase = null,
        ?string $password = null,
        ?bool $savePassword = true,
    ): BitcoinWallet {
        if (is_array($mnemonic)) {
            $mnemonic = implode(" ", $mnemonic);
        }
        $seed = Bitcoin::bip39MnemonicSeed($mnemonic, $passphrase);
        $descriptors = Bitcoin::bip39ToDescriptors($mnemonic, $passphrase);

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

        /** @var class-string<BitcoinWallet> $walletModel */
        $walletModel = Bitcoin::getModelWallet();
        $wallet = new $walletModel([
            'node_id' => $node->id,
            'name' => $name,
        ]);

        $wallet->unlockWallet($password);
        if ($savePassword) {
            $wallet->password = $password;
        }
        $wallet->mnemonic = $mnemonic;
        $wallet->seed = $seed;
        $wallet->descriptors = json_encode($descriptors);
        $wallet->save();

        $listReceivedByAddress = $api->request('listreceivedbyaddress', ['include_empty' => true], $wallet->name);
        foreach ($listReceivedByAddress as $item) {
            $wallet->addresses()->create([
                'address' => $item['address'],
                'type' => Bitcoin::validateAddress($node, $item['address']),
            ]);
        }

        if (count($listReceivedByAddress) === 0) {
            Bitcoin::createAddress($wallet, null, 'Primary Address');
        }

        return $wallet;
    }
}