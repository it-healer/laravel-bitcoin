<?php

namespace ItHealer\LaravelBitcoin\Commands;

use Illuminate\Console\Command;
use ItHealer\LaravelBitcoin\Facades\Bitcoin;

/**
 * Syncs light wallets; run it every minute from the scheduler. Each sync asks the providers only
 * for the addresses that are due by their own interval (smart polling, see LightWalletSync), so
 * a run over quiet wallets costs no requests.
 */
class BitcoinLightSyncCommand extends Command
{
    protected $signature = 'bitcoin:light-sync {wallet_id? : Sync only this wallet, now} {--full : Also check the hidden gap addresses}';

    protected $description = 'Sync light Bitcoin wallets from public APIs';

    public function handle(): int
    {
        $model = Bitcoin::getModelWallet();

        $wallets = $this->argument('wallet_id')
            ? $model::query()->whereKey($this->argument('wallet_id'))->where('mode', 'light')->get()
            : $model::query()->where('mode', 'light')->get();

        foreach ($wallets as $wallet) {
            try {
                $synced = Bitcoin::syncLightWallet(
                    $wallet,
                    (bool)$this->option('full'),
                    fn (string $message, ?string $type) => $this->{$type ? ($type === 'success' ? 'info' : $type) : 'line'}($message),
                );
                $this->info('Bitcoin wallet '.$wallet->name.($synced ? ' synced.' : ' is already syncing, skipped.'));
            } catch (\Throwable $e) {
                $this->error('Bitcoin wallet '.$wallet->name.': '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
