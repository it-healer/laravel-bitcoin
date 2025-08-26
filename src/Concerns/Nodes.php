<?php

namespace ItHealer\LaravelBitcoin\Concerns;

use Illuminate\Support\Str;
use ItHealer\LaravelBitcoin\BitcoindRpcApi;
use ItHealer\LaravelBitcoin\Facades\Bitcoin;
use ItHealer\LaravelBitcoin\Models\BitcoinNode;
use ItHealer\LaravelBitcoin\Services\SupervisorService;

trait Nodes
{
    public function createNode(
        string $name,
        ?string $title,
        string $host,
        int $port = 8332,
        string $username = null,
        string $password = null
    ): BitcoinNode {
        /** @var class-string<BitcoindRpcApi> $model */
        $model = Bitcoin::getModelAPI();
        $api = new $model($host, $port, $username, $password);

        $api->request('getblockchaininfo');

        /** @var class-string<BitcoinNode> $model */
        $model = Bitcoin::getModelNode();

        return $model::create([
            'name' => $name,
            'title' => $title,
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
        ]);
    }

    public function createLocalNode(string $name, array $config = [], ?string $title = null): BitcoinNode
    {
        /** @var class-string<BitcoinNode> $model */
        $model = Bitcoin::getModelNode();

        $exists = $model::where('name', $name)->exists();
        if( $exists ) {
            throw new \Exception('Node name is already exists.');
        }

        $minPort = (int)config('bitcoin.core.ports.min', 10000);
        $maxPort = (int)config('bitcoin.core.ports.max', 10999);
        for ($i = 0; $i < 50; $i++) {
            $port = mt_rand($minPort, $maxPort);
            $connection = @fsockopen('127.0.0.1', $port);
            if ($connection) {
                fclose($connection);
                $port = null;
                continue;
            }
            break;
        }
        if (!$port) {
            throw new \Exception('Not found free port.');
        }

        $node = new $model([
            'name' => $name,
            'title' => $title,
            'host' => '127.0.0.1',
            'port' => $port,
            'username' => Str::random(),
            'password' => Str::random(),
            'config' => $config,
        ]);
        $process = SupervisorService::startProcess($node);
        $node->pid = $process->getPid();
        try {
            $node->api()->request('getnetworkinfo');
        }
        catch (\Exception $e) {
            $process->stop(3);
            throw $e;
        }
        $node->save();

        return $node;
    }
}