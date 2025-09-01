<?php

namespace ItHealer\LaravelBitcoin\WebhookHandlers;

use ItHealer\LaravelBitcoin\Models\BitcoinAddress;
use ItHealer\LaravelBitcoin\Models\BitcoinDeposit;
use ItHealer\LaravelBitcoin\Models\BitcoinWallet;

interface WebhookHandlerInterface
{
    public function handle(BitcoinDeposit $deposit): ?array;
}