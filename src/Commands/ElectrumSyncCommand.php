<?php

namespace ItHealer\LaravelBitcoin\Commands;

use Illuminate\Console\Command;
use ItHealer\LaravelBitcoin\Services\Electrum\SyncService;


class ElectrumSyncCommand extends Command
{
    protected $signature = 'electrum:sync';

    protected $description = 'Electrum sync process';

    public function handle(SyncService $service): void
    {
        $service
            ->setLogger(fn(string $message, ?string $type) => $this->{$type ? ($type === 'success' ? 'info' : $type) : 'line'}($message))
            ->run();
    }
}
