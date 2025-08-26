<?php

namespace ItHealer\LaravelBitcoin\Services\Electrum;

use Illuminate\Support\Facades\Date;
use ItHealer\LaravelBitcoin\Models\ElectrumNode;
use ItHealer\LaravelBitcoin\Models\BitcoinNode;
use ItHealer\LaravelBitcoin\Services\BaseConsole;

class NodeSyncService extends BaseConsole
{
    protected readonly ElectrumNode $node;

    public function __construct(ElectrumNode|string|int $node)
    {
        $this->node = $node instanceof ElectrumNode ? $node : ElectrumNode::findOrFail($node);
    }

    public function run(): void
    {
        parent::run();

        $this->log("Начинаем синхронизацию Electrum {$this->node->name}...");

        $this->node->update([
            'sync_at' => Date::now(),
        ]);

        try {
            $api = $this->node->api();
            $versionInfo = $api->request('version_info');

            $this->node->update([
                'sync_at' => Date::now(),
                'worked' => true,
                'worked_data' => $versionInfo,
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
