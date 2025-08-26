<?php

namespace ItHealer\LaravelBitcoin\Concerns;

use ItHealer\LaravelBitcoin\BitcoindRpcApi;
use ItHealer\LaravelBitcoin\Models\BitcoinAddress;
use ItHealer\LaravelBitcoin\Models\BitcoinDeposit;
use ItHealer\LaravelBitcoin\Models\ElectrumNode;
use ItHealer\LaravelBitcoin\Models\BitcoinNode;
use ItHealer\LaravelBitcoin\Models\BitcoinWallet;

trait Models
{
    /**
     * @return class-string<BitcoinNode>
     */
    public function getModelNode(): string
    {
        return config('bitcoin.models.node');
    }

    /**
     * @return class-string<ElectrumNode>
     */
    public function getModelElectrum(): string
    {
        return config('bitcoin.models.electrum');
    }

    /**
     * @return class-string<BitcoinWallet>
     */
    public function getModelWallet(): string
    {
        return config('bitcoin.models.wallet');
    }

    /**
     * @return class-string<BitcoinAddress>
     */
    public function getModelAddress(): string
    {
        return config('bitcoin.models.address');
    }

    /**
     * @return class-string<BitcoinDeposit>
     */
    public function getModelDeposit(): string
    {
        return config('bitcoin.models.deposit');
    }

    /**
     * @return class-string<BitcoindRpcApi>
     */
    public function getModelAPI(): string
    {
        return config('bitcoin.models.rpc_client');
    }
}