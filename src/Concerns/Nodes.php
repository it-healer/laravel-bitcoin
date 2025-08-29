<?php

namespace ItHealer\LaravelBitcoin\Concerns;

use ItHealer\LaravelBitcoin\BitcoindRpcApi;
use ItHealer\LaravelBitcoin\Facades\Bitcoin;
use ItHealer\LaravelBitcoin\Models\BitcoinNode;

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
}