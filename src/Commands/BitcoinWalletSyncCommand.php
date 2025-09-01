<?php

namespace ItHealer\LaravelBitcoin\Commands;

use Illuminate\Console\Command;
use ItHealer\LaravelBitcoin\Facades\Bitcoin;
use ItHealer\LaravelBitcoin\Services\Bitcoin\WalletSyncService;

class BitcoinWalletSyncCommand extends Command
{
    protected $signature = 'bitcoin:wallet-sync {wallet_id}';

    protected $description = 'Bitcoin Wallet sync process';

    public function handle(): void
    {
        $walletId = $this->argument('wallet_id');

        $model = Bitcoin::getModelWallet();
        $wallet = $model::findOrFail($walletId);

        $this->info("Bitcoin Wallet $wallet->name starting sync...");

        try {
            $service = new WalletSyncService($wallet);
            $service
                ->setLogger(fn(string $message, ?string $type) => $this->{$type ? ($type === 'success' ? 'info' : $type) : 'line'}($message))
                ->run();

            $this->info("Bitcoin Wallet $wallet->name successfully sync finished!");
        }
        catch(\Exception $e) {
            $this->error("Error: {$e->getMessage()}");
        }
    }
}
