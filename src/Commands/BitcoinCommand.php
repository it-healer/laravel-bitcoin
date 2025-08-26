<?php

namespace ItHealer\LaravelBitcoin\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use ItHealer\LaravelBitcoin\Models\BitcoinWallet;
use ItHealer\LaravelBitcoin\Services\CoreInstallerService;
use ItHealer\LaravelBitcoin\Services\SupervisorService;
use ItHealer\LaravelBitcoin\Services\SyncService;

class BitcoinCommand extends Command
{
    protected $signature = 'bitcoin';

    protected $description = 'Bitcoin Node supervisor process';

    public function handle(SupervisorService $service): void
    {
        $service
            ->setLogger(fn(string $message, ?string $type) => $this->{$type ? ($type === 'success' ? 'info' : $type) : 'line'}($message))
            ->run();
    }
}
