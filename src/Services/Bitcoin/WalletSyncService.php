<?php

namespace ItHealer\LaravelBitcoin\Services\Bitcoin;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Date;
use ItHealer\LaravelBitcoin\BitcoindRpcApi;
use ItHealer\LaravelBitcoin\Models\BitcoinAddress;
use ItHealer\LaravelBitcoin\Models\BitcoinDeposit;
use ItHealer\LaravelBitcoin\Models\BitcoinTransfer;
use ItHealer\LaravelBitcoin\Models\BitcoinWallet;
use ItHealer\LaravelBitcoin\Services\BaseConsole;
use ItHealer\LaravelBitcoin\WebhookHandlers\WebhookHandlerInterface;

class WalletSyncService extends BaseConsole
{
    protected readonly BitcoindRpcApi $api;
    protected readonly WebhookHandlerInterface $webhookHandler;

    public function __construct(protected readonly BitcoinWallet $wallet)
    {
        $this->api = $this->wallet->node->api();

        /** @var class-string<WebhookHandlerInterface> $model */
        $model = config('bitcoin.webhook.handler');
        $this->webhookHandler = App::make($model);
    }

    public function run(): void
    {
        parent::run();

        $this
            ->unlockWallet()
            ->walletBalances()
            ->addressesBalances()
            ->syncTransactions()
            ->executeWebhooks();
    }

    protected function unlockWallet(): self
    {
        if ($this->wallet->password) {
            $this->log('Разблокируем кошелек при помощи пароля...');

            $this->api->request('walletpassphrase', [
                'passphrase' => $this->wallet->password,
                'timeout' => 60,
            ], $this->wallet->name);
        }

        return $this;
    }

    protected function walletBalances(): self
    {
        $getBalances = $this->api->request('getbalances', [], $this->wallet->name);
        $this->log("Метод getbalances: ".json_encode($getBalances));

        $this->wallet->update([
            'balance' => BigDecimal::of((string)$getBalances['mine']['trusted']),
            'unconfirmed_balance' => BigDecimal::of((string)$getBalances['mine']['untrusted_pending']),
            'sync_at' => Date::now(),
        ]);
        return $this;
    }

    protected function addressesBalances(): self
    {
        $listUnspent = $this->api->request('listunspent', ['minconf' => 0], $this->wallet->name);
        $this->log("Метод listunspent: ".json_encode($listUnspent));


        $this->wallet
            ->addresses()
            ->update([
                'sync_at' => Date::now(),
                'balance' => 0,
                'unconfirmed_balance' => 0,
            ]);

        if (count($listUnspent) > 0) {
            foreach ($listUnspent as $item) {
                $address = $this->wallet
                    ->addresses()
                    ->whereAddress($item['address'])
                    ->lockForUpdate()
                    ->first();
                if ($address) {
                    if ($item['confirmations'] > 0) {
                        $address->update([
                            'balance' => $address->balance->plus((string)$item['amount'])
                        ]);
                    } else {
                        $address->update([
                            'unconfirmed_balance' => $address->unconfirmed_balance->plus((string)$item['amount'])
                        ]);
                    }
                }
            }
        }

        return $this;
    }

    protected function syncTransactions(): self
    {
        $listTransactions = $this->api->request('listtransactions', [
            'count' => 100,
        ], $this->wallet->name);
        $this->log("Метод listtransactions: ".json_encode($listTransactions));

        $depositsData = [];
        $transfersData = [];

        foreach ($listTransactions as $item) {
            /** @var ?BitcoinAddress $address */
            $address = $this->wallet->addresses()->whereAddress($item['address'])->first();
            if (!$address) {
                continue;
            }

            switch ($item['category']) {
                case 'receive':
                    $depositsData[] = [
                        'wallet_id' => $this->wallet->id,
                        'address_id' => $address->id,
                        'txid' => $item['txid'],
                        'amount' => BigDecimal::of((string)$item['amount']),
                        'block_height' => $item['blockheight'] ?? null,
                        'confirmations' => $item['confirmations'] ?? 0,
                        'time_at' => Date::createFromTimestamp($item['time']),
                        'webhook_status' => 0,
                    ];
                    break;

                case 'send':
                    $isTransferExists = $this->wallet
                        ->transfers()
                        ->where('txid', $item['txid'])
                        ->where('confirmations', '>=', config('bitcoin.webhook.confirmations'))
                        ->exists();
                    if ($isTransferExists) {
                        break;
                    }

                    $getTransaction = $this->api->request('gettransaction', [
                        'txid' => $item['txid'],
                        'verbose' => true,
                    ], $this->wallet->name);
                    foreach ($getTransaction['decoded']['vout'] ?? [] as $vout) {
                        $voutValue = $vout['value'];
                        $voutAddress = $vout['scriptPubKey']['address'];

                        $transfersData[] = [
                            'wallet_id' => $this->wallet->id,
                            'address_id' => $address->id,
                            'txid' => $item['txid'],
                            'receiver_address' => $voutAddress,
                            'amount' => $voutValue,
                            'block_height' => $item['blockheight'] ?? null,
                            'confirmations' => $item['confirmations'] ?? 0,
                            'time_at' => Date::createFromTimestamp($item['time']),
                        ];
                    }
                    break;
            }
        }

        if (count($depositsData) > 0) {
            BitcoinDeposit::upsert(
                $depositsData,
                ['address_id', 'txid'],
                ['amount', 'block_height', 'confirmations', 'time_at', 'updated_at']
            );
        }
        if (count($transfersData) > 0) {
            BitcoinTransfer::upsert(
                $transfersData,
                ['address_id', 'txid', 'receiver_address'],
                ['amount', 'block_height', 'confirmations', 'time_at', 'updated_at']
            );
        }

        return $this;
    }

    protected function executeWebhooks(): self
    {
        $this->wallet
            ->deposits()
            ->with(['wallet', 'address'])
            ->where('confirmations', '>=', (int)config('bitcoin.webhook.confirmations', 0))
            ->where('webhook_status', 0)
            ->orderBy('created_at')
            ->lockForUpdate()
            ->get()
            ->each(function (BitcoinDeposit $deposit) {
                $deposit->update([
                    'webhook_status' => 1,
                    'webhook_data' => [
                        'start_at' => Date::now(),
                    ],
                ]);

                try {
                    $webhookData = $this->webhookHandler->handle($deposit) ?? [];

                    $deposit->update([
                        'webhook_status' => 2,
                        'webhook_data' => [
                            'start_at' => $deposit->webhook_data['start_at'] ?? Date::now(),
                            'success_at' => Date::now(),
                            ...$webhookData,
                        ],
                    ]);
                } catch (\Exception $e) {
                    $deposit->update([
                        'webhook_status' => 3,
                        'webhook_data' => [
                            'start_at' => $deposit->webhook_data['start_at'] ?? Date::now(),
                            'error_at' => Date::now(),
                            'error_message' => $e->getMessage(),
                        ],
                    ]);

                    $this->log("Депозит {$deposit->id} WebHook не сработал: {$e->getMessage()}", "error");
                }
            });

        return $this;
    }
}
