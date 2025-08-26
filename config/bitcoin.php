<?php

return [
    /*
     * Sets the handler to be used when Bitcoin Wallet has a new deposit.
     */
    'webhook_handler' => \ItHealer\LaravelBitcoin\WebhookHandlers\EmptyWebhookHandler::class,

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
     * BitcoinWallet model must be or extend `ItHealer\LaravelBitcoin\Models\BitcoinWallet::class`
     * BitcoinAddress model must be or extend `ItHealer\LaravelBitcoin\Models\BitcoinAddress::class`
     * BitcoinDeposit model must be or extend `ItHealer\LaravelBitcoin\Models\BitcoinDeposit::class`
     */
    'models' => [
        'rpc_client' => \ItHealer\LaravelBitcoin\BitcoindRpcApi::class,
        'node' => \ItHealer\LaravelBitcoin\Models\BitcoinNode::class,
        'electrum' => \ItHealer\LaravelBitcoin\Models\ElectrumNode::class,
        'wallet' => \ItHealer\LaravelBitcoin\Models\BitcoinWallet::class,
        'address' => \ItHealer\LaravelBitcoin\Models\BitcoinAddress::class,
        'deposit' => \ItHealer\LaravelBitcoin\Models\BitcoinDeposit::class,
    ],

    /**
     * Bitcoin Core settings
     */
    'core' => [
        'execute_path' => base_path('bitcoind'),
        'ports' => [
            'min' => 10000,
            'max' => 10999,
        ],
        'watcher_period' => 30,

        'network' => 'mainnet',
        'directory' => storage_path('app/bitcoin'),
        'prune' => true,
        'prune_size_mb' => 550,
        'rpc_allow' => '127.0.0.1',
        'max_connections' => 12,
        'fallback_fee' => 0.00005,
        'add_nodes' => '',

        'bip39_command' => 'npx --yes @it-healer/bitcoin-bip39', // or install npm i -g @it-healer/bitcoin-bip39 and change for 'bitcoin-bip39'
    ],

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
