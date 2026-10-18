<?php

namespace ItHealer\LaravelBitcoin\Tests\Support;

use ItHealer\LaravelBitcoin\Models\BitcoinDeposit;
use ItHealer\LaravelBitcoin\WebhookHandlers\WebhookHandlerInterface;

class SpyBitcoinWebhook implements WebhookHandlerInterface
{
    public function handle(BitcoinDeposit $deposit): ?array
    {
        chainState()->handled = [...chainState()->handled, $deposit->id];

        return null;
    }
}
