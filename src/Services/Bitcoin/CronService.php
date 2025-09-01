<?php

namespace ItHealer\LaravelBitcoin\Services\Bitcoin;

use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use ItHealer\LaravelBitcoin\Facades\Bitcoin;
use ItHealer\LaravelBitcoin\Models\BitcoinNode;
use ItHealer\LaravelBitcoin\Services\BaseConsole;

class CronService extends BaseConsole
{
    /** @var array<string, InvokedProcess> */
    protected array $processes = [];

    public function run(): void
    {
        parent::run();

        $model = Bitcoin::getModelNode();
        $model::query()
            ->where('available', true)
            ->orderBy('sync_at')
            ->each(function(BitcoinNode $node) {
                $this->runProcess($node);
            });

        foreach ($this->processes as $name => $process) {
            $result = $process->wait();
            if ($result->successful()) {
                $this->log("Node $name успешно синхронизирована!", "success");
            } else {
                $this->log("Node $name не смогло синхронизироваться!", "error");
            }
        }
    }

    protected function runProcess(BitcoinNode $node): void
    {
        $this->log("Запускаем процесс синхронизации Node $node->name...", "info");

        $process = Process::start(['php', 'artisan', 'bitcoin:node-sync', $node->id], function ($type, $output) {
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