<?php

namespace ItHealer\LaravelBitcoin\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use ItHealer\LaravelBitcoin\Facades\Bitcoin;
use ItHealer\LaravelBitcoin\Models\BitcoinWallet;

/**
 * Syncs light wallets that are due: touched within `active_window` — every `active_interval`,
 * the rest every `idle_interval`. Run it every minute from the scheduler.
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
            : $model::query()->where('mode', 'light')->get()->filter(fn (BitcoinWallet $wallet) => $this->isDue($wallet));

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

    protected function isDue(BitcoinWallet $wallet): bool
    {
        if (!$wallet->sync_at) {
            return true;
        }

        $active = $wallet->touch_at && $wallet->touch_at->gte(Date::now()->subSeconds((int)config('bitcoin.light.active_window', 1800)));
        $interval = (int)config($active ? 'bitcoin.light.active_interval' : 'bitcoin.light.idle_interval', 600);

        return $wallet->sync_at->lte(Date::now()->subSeconds($interval - 5));
    }
}
