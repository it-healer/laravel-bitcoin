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
        'provider' => \ItHealer\LaravelBitcoin\Models\BitcoinProvider::class,
        'transaction' => \ItHealer\LaravelBitcoin\Models\BitcoinTransaction::class,
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

    /*
     * Light mode: no node, addresses from the account xpub, data from public Esplora APIs
     * (bitcoin_providers: mempool.space, blockstream.info).
     *
     * derivation_path   — account path of new light wallets (BIP84, native segwit).
     * gap_limit         — unused addresses kept ahead on each chain (receive, change).
     * gap_scan_interval — how often the unused (never issued) addresses are checked too, in
     *                     seconds: only issued addresses are known to payers, the gap is
     *                     for the same mnemonic used in another wallet app.
     * active_interval   — sync interval of a wallet touched within active_window.
     * idle_interval     — sync interval of other wallets.
     * tip_cache_seconds — the chain tip height is cached for this long.
     * request_pause_ms  — pause between requests to one provider (public APIs limit bursts).
     * cooldown_seconds  — a provider that answered 429/5xx or did not answer is skipped for
     *                     this long.
     * dropped_after     — an unconfirmed transaction missing from the mempool for this long
     *                     is considered dropped (replaced or evicted), in seconds.
     */
    'light' => [
        'derivation_path' => "m/84'/0'/0'",
        'gap_limit' => 20,
        'gap_scan_interval' => 86400,
        'active_window' => 1800,
        'active_interval' => 60,
        'idle_interval' => 600,
        'tip_cache_seconds' => 30,
        'request_pause_ms' => 250,
        'cooldown_seconds' => 60,
        'dropped_after' => 1800,
        'timeout' => 20,
        // A wallet sync holds a lock this long at most (a full sync of a busy wallet takes minutes)
        'lock_seconds' => 900,
        // Deposits mined this many blocks before the wallet was added are history (no webhook)
        'history_margin_blocks' => 6,
    ],
];
