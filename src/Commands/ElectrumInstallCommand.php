<?php

namespace ItHealer\LaravelBitcoin\Commands;

use Illuminate\Console\Command;
use ItHealer\LaravelBitcoin\Services\ElectrumInstallerService;

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
