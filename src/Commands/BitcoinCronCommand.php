<?php

namespace ItHealer\LaravelBitcoin\Commands;

use Illuminate\Console\Command;
use ItHealer\LaravelBitcoin\Services\Bitcoin\CronService;

class BitcoinCronCommand extends Command
{
    protected $signature = 'bitcoin:cron';

    protected $description = 'Bitcoin cron process';

    public function handle(CronService $service): void
    {
        $service
            ->setLogger(fn(string $message, ?string $type) => $this->{$type ? ($type === 'success' ? 'info' : $type) : 'line'}($message))
            ->run();
    }
}
