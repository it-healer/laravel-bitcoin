<?php

namespace ItHealer\LaravelBitcoin\Commands;

use Illuminate\Console\Command;
use ItHealer\LaravelBitcoin\Services\Electrum\ElectrumSupervisorService;

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
