<?php

namespace ItHealer\LaravelBitcoin\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use ItHealer\LaravelBitcoin\Models\BitcoinDeposit;
use ItHealer\LaravelBitcoin\WebhookHandlers\WebhookHandlerInterface;

class BitcoinWebhookCommand extends Command
{
    protected $signature = 'bitcoin:webhook {deposit_id}';

    protected $description = 'Bitcoin deposit webhook handler';

    public function handle(): void
    {
        /** @var class-string<BitcoinDeposit> $model */
        $model = config('bitcoin.models.deposit');
        $deposit = $model::with(['wallet', 'address'])->findOrFail($this->argument('deposit_id'));

        /** @var class-string<WebhookHandlerInterface> $model */
        $model = config('bitcoin.webhook_handler');

        /** @var WebhookHandlerInterface $handler */
        $handler = App::make($model);

        $handler->handle($deposit->wallet, $deposit->address, $deposit);

        $this->info('Webhook successfully execute!');
    }
}
