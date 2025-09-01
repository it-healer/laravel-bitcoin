<?php

namespace ItHealer\LaravelBitcoin\WebhookHandlers;

use Illuminate\Support\Facades\Log;
use ItHealer\LaravelBitcoin\Models\BitcoinAddress;
use ItHealer\LaravelBitcoin\Models\BitcoinDeposit;
use ItHealer\LaravelBitcoin\Models\BitcoinWallet;

class EmptyWebhookHandler implements WebhookHandlerInterface
{
    public function handle(BitcoinDeposit $deposit): ?array
    {
        Log::error('Bitcoin new deposit '.$deposit->txid);

        return null;
    }
}