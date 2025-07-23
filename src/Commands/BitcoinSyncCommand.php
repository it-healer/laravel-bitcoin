<?php

namespace ItHealer\LaravelBitcoin\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use ItHealer\LaravelBitcoin\Models\BitcoinWallet;
use ItHealer\LaravelBitcoin\Services\SyncService;

class BitcoinSyncCommand extends Command
{
    protected $signature = 'bitcoin:sync';

    protected $description = 'Bitcoin sync wallets';

    public function handle(): void
    {
        /** @var class-string<BitcoinWallet> $model */
        $model = config('bitcoin.models.wallet');

        $model::orderBy('id')
            ->each(function (BitcoinWallet $wallet) {
                $this->info("Bitcoin Wallet $wallet->name starting sync...");

                try {
                    App::make(SyncService::class, [
                        'wallet' => $wallet
                    ])->run();

                    $this->info("Bitcoin Wallet $wallet->name successfully sync finished!");
                }
                catch(\Exception $e) {
                    $this->error("Error: {$e->getMessage()}");
                }
            });
    }
}
