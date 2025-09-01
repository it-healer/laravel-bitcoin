<?php

namespace ItHealer\LaravelBitcoin\Services\Bitcoin;

use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Date;
use ItHealer\LaravelBitcoin\Facades\Bitcoin;
use ItHealer\LaravelBitcoin\Models\BitcoinNode;
use ItHealer\LaravelBitcoin\Models\BitcoinWallet;
use ItHealer\LaravelBitcoin\Services\BaseConsole;

class NodeSyncService extends BaseConsole
{
    protected readonly BitcoinNode $node;
    /** @var array<string, InvokedProcess> */
    protected array $processes = [];

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

            $this->syncWallets();
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

    protected function syncWallets(): static
    {
        $walletModel = Bitcoin::getModelWallet();

        $walletModel::query()
            ->orderBy('sync_at', 'desc')
            ->get()
            ->each(function(BitcoinWallet $wallet) {
                $this->log("Начинаем синхронизацию кошелька $wallet->name...");

                try {
                    $service = new WalletSyncService($wallet);
                    $service
                        ->setLogger(fn(string $message, ?string $type) => $this->log($message, $type))
                        ->run();

                    $this->log("Успех!", "success");
                }
                catch( \Exception $e ) {
                    $this->log("Ошибка: {$e->getMessage()}", "error");
                }
            });

        return $this;
    }
}