<?php

namespace ItHealer\LaravelBitcoin\Services\Sync;

use Illuminate\Support\Facades\Date;
use ItHealer\LaravelBitcoin\Models\BitcoinNode;
use ItHealer\LaravelBitcoin\Services\BaseConsole;

class NodeSyncService extends BaseConsole
{
    protected readonly BitcoinNode $node;

    public function __construct(BitcoinNode|string|int $node)
    {
        $this->node = $node instanceof BitcoinNode ? $node : BitcoinNode::findOrFail($node);
    }

    public function run(): void
    {
        parent::run();

        $this->log("Начинаем синхронизацию ноды {$this->node->name}...");

        $this->node->update([
            'sync_at' => Date::now(),
        ]);

        try {
            $api = $this->node->api();
            $blockchainInfo = $api->request('getblockchaininfo');

            $this->node->update([
                'sync_at' => Date::now(),
                'worked' => true,
                'worked_data' => $blockchainInfo,
            ]);
        }
        catch(\Exception $e) {
            $this->node->update([
                'worked' => false,
                'worked_data' => [
                    'error_at' => Date::now(),
                    'error_message' => $e->getMessage(),
                ],
            ]);

            $this->log("Ошибка: {$e->getMessage()}", "error");
            return;
        }

        $this->log("Синхронизация ноды {$this->node->name} завершена!");
    }
}