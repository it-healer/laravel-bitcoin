<?php

namespace ItHealer\LaravelBitcoin\WebhookHandlers;

use Illuminate\Support\Facades\Log;
use ItHealer\LaravelBitcoin\Models\BitcoinAddress;
use ItHealer\LaravelBitcoin\Models\BitcoinDeposit;
use ItHealer\LaravelBitcoin\Models\BitcoinWallet;

class EmptyWebhookHandler implements WebhookHandlerInterface
{
    public function handle(BitcoinWallet $wallet, BitcoinAddress $address, BitcoinDeposit $deposit): void
    {
        Log::error('Bitcoin Wallet '.$wallet->name.' new transaction '.$deposit->txid.' for address '.$address->address);
    }
}