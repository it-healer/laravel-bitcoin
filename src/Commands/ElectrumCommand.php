<?php

namespace ItHealer\LaravelBitcoin\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use ItHealer\LaravelBitcoin\Models\BitcoinWallet;
use ItHealer\LaravelBitcoin\Services\CoreInstallerService;
use ItHealer\LaravelBitcoin\Services\ElectrumSupervisorService;
use ItHealer\LaravelBitcoin\Services\SupervisorService;
use ItHealer\LaravelBitcoin\Services\SyncService;

class ElectrumCommand extends Command
{
    protected $signature = 'electrum';

    protected $description = 'Electrum supervisor process';

    public function handle(ElectrumSupervisorService $service): void
    {
        $service
            ->setLogger(fn(string $message, ?string $type) => $this->{$type ? ($type === 'success' ? 'info' : $type) : 'line'}($message))
            ->run();
    }
}
