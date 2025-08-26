<?php

namespace ItHealer\LaravelBitcoin\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use ItHealer\LaravelBitcoin\Models\BitcoinWallet;
use ItHealer\LaravelBitcoin\Services\CoreInstallerService;
use ItHealer\LaravelBitcoin\Services\ElectrumInstallerService;
use ItHealer\LaravelBitcoin\Services\SyncService;

class ElectrumInstallCommand extends Command
{
    protected $signature = 'electrum:install';

    protected $description = 'Electrum installer';

    public function handle(ElectrumInstallerService $service): void
    {
        $service
            ->setLogger(fn(string $message, ?string $type) => $this->{$type ? ($type === 'success' ? 'info' : $type) : 'line'}($message))
            ->run();
    }
}
