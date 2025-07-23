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
        'wallet' => \ItHealer\LaravelBitcoin\Models\BitcoinWallet::class,
        'address' => \ItHealer\LaravelBitcoin\Models\BitcoinAddress::class,
        'deposit' => \ItHealer\LaravelBitcoin\Models\BitcoinDeposit::class,
    ],
];
