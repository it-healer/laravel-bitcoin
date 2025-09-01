<?php

return [
    /*
     * WebHook
     *
     * Handler - Sets the handler to be used when Bitcoin Wallet has a new deposit.
     * Confirmations - Minimum number of confirmations for a call webhook.
     */
    'webhook' => [
        'handler' => \ItHealer\LaravelBitcoin\WebhookHandlers\EmptyWebhookHandler::class,
        'confirmations' => 6,
    ],

    /*
     * Set address type of generate new addresses.
     */
    'address_type' => \ItHealer\LaravelBitcoin\Enums\AddressType::BECH32,

    /*
     * Set model class for both BitcoinWallet, BitcoinAddress, BitcoinDeposit,
     * to allow more customization.
     *
     * BitcoindRpcApi model must be or extend `ItHealer\LaravelBitcoin\BitcoindRpcApi::class`
     * BitcoinNode model must be or extend `ItHealer\LaravelBitcoin\Models\BitcoinNode::class`
     * ElectrumNode model must be or extend `ItHealer\LaravelBitcoin\Models\ElectrumNode::class`
     * BitcoinWallet model must be or extend `ItHealer\LaravelBitcoin\Models\BitcoinWallet::class`
     * BitcoinAddress model must be or extend `ItHealer\LaravelBitcoin\Models\BitcoinAddress::class`
     * BitcoinDeposit model must be or extend `ItHealer\LaravelBitcoin\Models\BitcoinDeposit::class`
     * BitcoinTransfer model must be or extend `ItHealer\LaravelBitcoin\Models\BitcoinTransfer::class`
     */
    'models' => [
        'rpc_client' => \ItHealer\LaravelBitcoin\BitcoindRpcApi::class,
        'node' => \ItHealer\LaravelBitcoin\Models\BitcoinNode::class,
        'electrum' => \ItHealer\LaravelBitcoin\Models\ElectrumNode::class,
        'wallet' => \ItHealer\LaravelBitcoin\Models\BitcoinWallet::class,
        'address' => \ItHealer\LaravelBitcoin\Models\BitcoinAddress::class,
        'deposit' => \ItHealer\LaravelBitcoin\Models\BitcoinDeposit::class,
        'transfer' => \ItHealer\LaravelBitcoin\Models\BitcoinTransfer::class,
    ],

    /*
     * BIP39 Convert Script
     */
    'bip39_command' => 'npx --yes @it-healer/bitcoin-bip39', // or install npm i -g @it-healer/bitcoin-bip39 and change for 'bitcoin-bip39'

    /*
     * Electrum
     */
    'electrum' => [
        'binary_path' => 'python3',
        'execute_path' => storage_path('app/electrum/run_electrum'),
        'ports' => [
            'min' => 10000,
            'max' => 10999,
        ],
        'watcher_period' => 30,

        'network' => 'mainnet',
        'directory' => storage_path('app/electrum-data'),
    ],
];
