<?php

namespace ItHealer\LaravelBitcoin;

use ItHealer\LaravelBitcoin\Commands\BitcoinSyncCommand;
use ItHealer\LaravelBitcoin\Commands\BitcoinSyncWalletCommand;
use ItHealer\LaravelBitcoin\Commands\BitcoinWebhookCommand;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class BitcoinServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('bitcoin')
            ->hasConfigFile()
            ->hasMigrations([
                'create_bitcoin_nodes_table',
                'create_bitcoin_wallets_table',
                'create_bitcoin_addresses_table',
                'create_bitcoin_deposits_table',
            ])
            ->hasCommands(
                BitcoinSyncCommand::class,
                BitcoinSyncWalletCommand::class,
                BitcoinWebhookCommand::class,
            )
            ->hasInstallCommand(function(InstallCommand $command) {
                $command
                    ->publishConfigFile()
                    ->publishMigrations();
            });

        $this->app->singleton(Bitcoin::class);
    }
}