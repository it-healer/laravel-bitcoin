<?php

namespace ItHealer\LaravelBitcoin\Services;

use Brick\Math\BigDecimal;
use ItHealer\LaravelBitcoin\Facades\Bitcoin;
use ItHealer\LaravelBitcoin\Models\ElectrumNode;
use ItHealer\LaravelBitcoin\Models\BitcoinNode;
use Symfony\Component\Process\Process;

class ElectrumSupervisorService extends BaseConsole
{
    protected bool $shouldRun = true;

    /** @var class-string<ElectrumNode> */
    protected string $model = ElectrumNode::class;

    protected array $processes = [];
    protected int $watcherPeriod;

    public function __construct()
    {
        $this->model = Bitcoin::getModelElectrum();
        $this->watcherPeriod = (int)config('bitcoin.electrum.watcher_period', 30);
    }

    protected function log(string $message, ?string $type = null): void
    {
        if ($this->logger) {
            call_user_func($this->logger, $message, $type);
        }
    }

    public function run(): void
    {
        parent::run();

        $this->log("Starting Bitcoin Electrum worker service...");

        $this
            ->sigterm()
            ->while()
            ->closeProcesses();

        $this->log("Bitcoin Electrum worker stopped.");
    }

    protected function sigterm(): static
    {
        pcntl_async_signals(true);

        pcntl_signal(SIGTERM, function () {
            $this->log("SIGTERM received. Shutting down gracefully...");
            $this->shouldRun = false;
        });

        pcntl_signal(SIGINT, function () {
            $this->log("SIGINT (Ctrl+C) received. Exiting...");
            $this->shouldRun = false;
        });

        return $this;
    }

    protected function while(): static
    {
        while ($this->shouldRun) {
            $this->thread();
            sleep($this->watcherPeriod);
        }

        return $this;
    }

    protected function thread(): void
    {
        $nodes = $this->model::query()
            ->where('available', true)
            ->orderBy('sync_at')
            ->get();

        $activeNodesIDs = [];

        foreach ($nodes as $node) {
            $activeNodesIDs[] = $node->id;

            if (!$this->isPortFree($node->host ?? '127.0.0.1', (int)$node->port)) {
                continue;
            }

            if ($node->pid) {
                $this->killPid((int)$node->pid);
                $node->update(['pid' => null]);
            }

            $this->log("Starting electrum for node {$node->name}...");

            try {
                $process = static::startProcess($node);
                $this->processes[$node->id] = $process;

                sleep(2);

                $pid = $process->getPid();
                $node->update(['pid' => $pid]);

                $this->log("Started electrum with PID {$pid} for node {$node->name}");
            } catch (\Throwable $e) {
                $this->log("Error: {$e->getMessage()}", 'error');
            }
        }

        foreach ($this->processes as $nodeId => $process) {
            if (!in_array($nodeId, $activeNodesIDs, true)) {
                $this->log("Node #{$nodeId} no longer active, stopping process");
                $this->killProcess($nodeId);
            }
        }
    }

    public static function startProcess(ElectrumNode $node): Process
    {
        $binaryPath = config('bitcoin.electrum.binary_path', 'python3');
        $executePath = config('bitcoin.electrum.execute_path', 'electrum');

        $directory = config('bitcoin.electrum.directory');
        $dataDir = $directory.'/node_'.$node->name;

        foreach ([$dataDir] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }

        $network = strtolower($node->config['network'] ?? config('bitcoin.electrum.network', 'mainnet'));

        $args = [
            $binaryPath,
            $executePath,
            '-D', $dataDir,
            'daemon',
            '--'.$network,
            '--rpcuser', $node->username,
            '--rpcpassword', $node->password,
            '--rpchost', $node->host,
            '--rpcport', $node->port,
        ];
        $args = array_filter($args);

        $process = new Process($args);
        $process->start();

        sleep(3);
        if ($error = trim($process->getErrorOutput())) {
            if (!$process->isRunning()) {
                throw new \RuntimeException($error);
            }
        }

        return $process;
    }

    protected function killProcess(int $nodeId): void
    {
        if (isset($this->processes[$nodeId])) {
            $process = $this->processes[$nodeId];

            if ($process->isRunning()) {
                $process->stop(3);
                $this->log("Stopped process for node #{$nodeId}");
            }

            unset($this->processes[$nodeId]);
        }

        $this->model::where('id', $nodeId)->update(['pid' => null]);
    }

    protected function closeProcesses(): static
    {
        foreach ($this->processes as $nodeId => $process) {
            $this->killProcess($nodeId);
        }
        return $this;
    }

    protected function isPortFree(string $host, int $port): bool
    {
        $connection = @fsockopen($host, $port);
        if (is_resource($connection)) {
            fclose($connection);
            return false;
        }
        return true;
    }

    protected function killPid(int $pid): void
    {
        if (posix_kill($pid, 0)) {
            exec("kill -9 {$pid}");
            $this->log("Killed process with PID {$pid}");
        } else {
            $this->log("Process with PID {$pid} is not killed", 'error');
        }
    }
}