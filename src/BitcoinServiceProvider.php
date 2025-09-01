<?php

namespace ItHealer\LaravelBitcoin;

use ItHealer\LaravelBitcoin\Commands\BitcoinCommand;
use ItHealer\LaravelBitcoin\Commands\BitcoinCoreInstallCommand;
use ItHealer\LaravelBitcoin\Commands\ElectrumCommand;
use ItHealer\LaravelBitcoin\Commands\ElectrumInstallCommand;
use ItHealer\LaravelBitcoin\Commands\BitcoinNodeSyncCommand;
use ItHealer\LaravelBitcoin\Commands\BitcoinCronCommand;
use ItHealer\LaravelBitcoin\Commands\BitcoinWalletSyncCommand;
use ItHealer\LaravelBitcoin\Commands\BitcoinWebhookCommand;
use ItHealer\LaravelBitcoin\Commands\ElectrumNodeSyncCommand;
use ItHealer\LaravelBitcoin\Commands\ElectrumCronCommand;
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
            ->discoversMigrations()
            ->hasCommands(
                BitcoinNodeSyncCommand::class,
                BitcoinCronCommand::class,
                BitcoinWalletSyncCommand::class,
                BitcoinWebhookCommand::class,

                ElectrumCommand::class,
                ElectrumInstallCommand::class,
                ElectrumCronCommand::class,
                ElectrumNodeSyncCommand::class,
            )
            ->hasInstallCommand(function(InstallCommand $command) {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToRunMigrations()
                    ->askToStarRepoOnGitHub('it-healer/laravel-bitcoin');
            });

        $this->app->singleton(Bitcoin::class);
    }
}