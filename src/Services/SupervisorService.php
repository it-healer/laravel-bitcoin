<?php

namespace ItHealer\LaravelBitcoin\Services;

use Brick\Math\BigDecimal;
use ItHealer\LaravelBitcoin\Facades\Bitcoin;
use ItHealer\LaravelBitcoin\Models\BitcoinNode;
use Symfony\Component\Process\Process;

class SupervisorService extends BaseConsole
{
    protected bool $shouldRun = true;

    /** @var class-string<BitcoinNode> */
    protected string $model = BitcoinNode::class;

    protected array $processes = [];
    protected int $watcherPeriod;

    public function __construct()
    {
        $this->model = Bitcoin::getModelNode();
        $this->watcherPeriod = (int)config('bitcoin.core.watcher_period', 30);
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

        $this->log("Starting Bitcoin worker service...");

        $this
            ->sigterm()
            ->while()
            ->closeProcesses();

        $this->log("Bitcoin worker stopped.");
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
            ->whereNotNull('config')
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

            $this->log("Starting bitcoind for node {$node->name}...");

            try {
                $process = static::startProcess($node);
                $this->processes[$node->id] = $process;

                sleep(2);

                $pid = $process->getPid();
                $node->update(['pid' => $pid]);

                $this->log("Started bitcoind with PID {$pid} for node {$node->name}");
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

    public static function startProcess(BitcoinNode $node): Process
    {
        $executePath = config('bitcoin.core.execute_path', 'bitcoind');
        $coreDirectory = config('bitcoin.core.directory');
        $dataDir = $node->config['data_dir'] ?? $coreDirectory.'/node_'.$node->name;
        $walletDir = $node->config['wallet_dir'] ?? $coreDirectory.'/node_'.$node->name.'/wallets';
        $logFile = $node->config['log_file'] ?? storage_path('logs/bitcoin/node_'.$node->name.'.log');
        $logDir = dirname($logFile);

        foreach ([$dataDir, $walletDir, $logDir] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }

        $network = $node->config['network'] ?? config('bitcoin.core.network', 'mainnet');
        $rpcAllow = array_filter(explode(',', $node->config['rpc_allow'] ?? config('bitcoin.core.rpc_allow', '127.0.0.1')));
        $pruneEnabled = (bool)($node->config['prune'] ?? config('bitcoin.core.prune', true));
        $pruneSizeMb = (int)($node->config['prune_size_mb'] ?? config('bitcoin.core.prune_size_mb', 550));
        $maxConn = (int)($node->config['max_connections'] ?? config('bitcoin.core.max_connections', 12));
        $fallbackFee = (float)($node->config['fallback_fee'] ?? config('bitcoin.core.fallback_fee', 0));
        $addNodes = array_filter(explode(',', $node->config['add_nodes'] ?? config('bitcoin.core.add_nodes')));

        $args = [
            $executePath,
            '-server=1',
            '-rpcbind='.$node->host,
            '-rpcport='.(int)$node->port,
            '-rpcworkqueue=64',
            '-rpcthreads='.(int)(config('bitcoin.core.rpc_threads', 8)),
            '-deprecatedrpc=addresses',
            '-disablewallet=0',
            '-walletdir='.$walletDir,
            '-datadir='.$dataDir,
            '-listen=0',
            '-dnsseed=1',
            '-upnp=0',
            '-natpmp=0',
            '-maxconnections='.$maxConn,
            '-debuglogfile='.$logFile,
        ];

        if ($pruneEnabled) {
            $args[] = '-prune='.max(550, $pruneSizeMb);
        }
        if ($fallbackFee) {
            $args[] = '-fallbackfee='.BigDecimal::of($fallbackFee)->__toString();
        }

        foreach ($rpcAllow as $ip) {
            $args[] = '-rpcallowip='.$ip;
        }

        if ($node->username) {
            $args[] = '-rpcuser='.$node->username;
            $args[] = '-rpcpassword='.$node->password;
        }

        foreach ($addNodes as $peer) {
            $args[] = '-addnode='.$peer;
        }

        $net = strtolower($network);
        if ($net === 'testnet') {
            $args[] = '-testnet';
        }
        if ($net === 'signet') {
            $args[] = '-signet';
        }
        if ($net === 'regtest') {
            $args[] = '-regtest';
        }

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