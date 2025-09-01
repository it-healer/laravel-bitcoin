<?php

namespace ItHealer\LaravelBitcoin\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use ItHealer\LaravelBitcoin\Facades\Bitcoin;
use ItHealer\LaravelBitcoin\Models\BitcoinDeposit;
use ItHealer\LaravelBitcoin\WebhookHandlers\WebhookHandlerInterface;

class BitcoinWebhookCommand extends Command
{
    protected $signature = 'bitcoin:webhook {deposit_id}';

    protected $description = 'Bitcoin deposit webhook handler';

    public function handle(): void
    {
        $depositId = $this->argument('deposit_id');

        $model = Bitcoin::getModelDeposit();
        $deposit = $model::with(['wallet', 'address'])->findOrFail($depositId);

        /** @var class-string<WebhookHandlerInterface> $model */
        $model = config('bitcoin.webhook.handler');

        /** @var WebhookHandlerInterface $handler */
        $handler = App::make($model);

        $handler->handle($deposit);

        $this->info('Webhook successfully execute!');
    }
}
