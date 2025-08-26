<?php

namespace ItHealer\LaravelBitcoin\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use ItHealer\LaravelBitcoin\Models\BitcoinWallet;
use ItHealer\LaravelBitcoin\Services\CoreInstallerService;
use ItHealer\LaravelBitcoin\Services\SyncService;

class BitcoinCoreInstallCommand extends Command
{
    protected $signature = 'bitcoin:core-install';

    protected $description = 'Bitcoin Core installer';

    public function handle(CoreInstallerService $service): void
    {
        $service
            ->setLogger(fn(string $message, ?string $type) => $this->{$type ? ($type === 'success' ? 'info' : $type) : 'line'}($message))
            ->run();
    }
}
