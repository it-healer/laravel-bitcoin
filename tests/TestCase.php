<?php

namespace ItHealer\LaravelBitcoin\Tests;

use ItHealer\LaravelBitcoin\BitcoinServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            BitcoinServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        config()->set('bitcoin.light.request_pause_ms', 0);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
