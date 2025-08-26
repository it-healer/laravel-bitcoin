<?php

namespace ItHealer\LaravelBitcoin\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use ItHealer\LaravelBitcoin\Services\Electrum\NodeSyncService;


class ElectrumNodeSyncCommand extends Command
{
    protected $signature = 'electrum:node-sync {node}';

    protected $description = 'Electrum Node sync process';

    public function handle(): void
    {
        $node = $this->argument('node');

        App::make(NodeSyncService::class, compact('node'))
            ->setLogger(fn(string $message, ?string $type) => $this->{$type ? ($type === 'success' ? 'info' : $type) : 'line'}($message))
            ->run();
    }
}
