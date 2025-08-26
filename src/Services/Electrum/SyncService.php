<?php

namespace ItHealer\LaravelBitcoin\Services\Electrum;

use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Process;
use ItHealer\LaravelBitcoin\Facades\Bitcoin;
use ItHealer\LaravelBitcoin\Models\ElectrumNode;
use ItHealer\LaravelBitcoin\Models\BitcoinNode;
use ItHealer\LaravelBitcoin\Services\BaseConsole;

class SyncService extends BaseConsole
{
    /** @var array<string, InvokedProcess> */
    protected array $processes = [];

    public function run(): void
    {
        parent::run();

        $model = Bitcoin::getModelElectrum();
        $model::query()
            ->where('available', true)
            ->orderBy('sync_at')
            ->each(function(ElectrumNode $node) {
                $this->runProcess($node);
            });

        foreach ($this->processes as $name => $process) {
            $result = $process->wait();
            if ($result->successful()) {
                $this->log("Electrum $name успешно синхронизирована!", "success");
            } else {
                $this->log("Electrum $name не смогло синхронизироваться!", "error");
            }
        }
    }

    protected function runProcess(ElectrumNode $node): void
    {
        $this->log("Запускаем процесс синхронизации Electrum $node->name...", "info");

        $process = Process::start(['php', 'artisan', 'electrum:node-sync', $node->id], function ($type, $output) {
            $output = explode("\n", $output);
            $output = array_map('trim', $output);
            $output = array_filter($output);
            foreach ($output as $line) {
                $this->log("Node: $line");
            }
        });
        $this->processes[$node->name] = $process;

        $this->log("Процесс успешно запущен: {$process->id()}", "info");
    }
}
