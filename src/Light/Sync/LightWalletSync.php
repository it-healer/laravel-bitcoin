<?php

namespace ItHealer\LaravelBitcoin\Light\Sync;

use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Date;
use ItHealer\LaravelBitcoin\Facades\Bitcoin;
use ItHealer\LaravelBitcoin\Light\AddressBook;
use ItHealer\LaravelBitcoin\Light\Api\EsploraApi;
use ItHealer\LaravelBitcoin\Light\Api\ProviderPool;
use ItHealer\LaravelBitcoin\Models\BitcoinAddress;
use ItHealer\LaravelBitcoin\Models\BitcoinDeposit;
use ItHealer\LaravelBitcoin\Models\BitcoinTransaction;
use ItHealer\LaravelBitcoin\Models\BitcoinWallet;
use ItHealer\LaravelBitcoin\Services\BaseConsole;
use ItHealer\LaravelBitcoin\WebhookHandlers\WebhookHandlerInterface;

/**
 * Synchronizes a light wallet from public Esplora APIs.
 *
 * Every address costs one request (`GET /address/{a}`): balances come from its chain and
 * mempool stats. The history of an address is read only when those stats changed since the
 * last sync (`stats_hash`), and only until a transaction already known as confirmed.
 * Addresses that were never handed out (the gap) are checked every `gap_scan_interval`.
 *
 * - deposit: an output to a wallet address in a transaction with no wallet inputs;
 * - transfer: an output to a foreign address in a transaction with wallet inputs;
 * - confirmations grow from the cached tip height, without extra requests;
 * - an unconfirmed transaction gone from the mempool for `dropped_after` is marked dropped and
 *   its pending deposits are removed.
 */
class LightWalletSync extends BaseConsole
{
    protected AddressBook $book;

    protected ProviderPool $pool;

    protected int $tip;

    /** @var array<string, array<string, mixed>> transactions read in this run, by txid */
    protected array $fetched = [];

    public function __construct(
        protected readonly BitcoinWallet $wallet,
        protected readonly bool $full = false,
    ) {
        $this->book = new AddressBook($wallet);
        $this->pool = App::make(ProviderPool::class);
    }

    public function run(): void
    {
        parent::run();

        if (!$this->wallet->isLight()) {
            throw new \LogicException('Wallet '.$this->wallet->name.' is not a light wallet.');
        }

        $scanGap = $this->full || $this->gapScanDue();
        $due = $scanGap ? collect() : $this->dueAddresses();

        if (!$scanGap && $due->isEmpty() && !$this->hasPendingWork()) {
            $this->log('No address is due, nothing to confirm.');

            return;
        }

        $this->tip = $this->pool->tipHeight();
        $this->log('Tip height: '.$this->tip.', addresses due: '.($scanGap ? 'all' : $due->count()));

        $this->scanAddresses($scanGap, $due);
        $this->processTransactions();
        $this->resolveMissingPending();
        $this->updateConfirmations();
        $this->updateWalletBalance($scanGap);
        $this->executeWebhooks();
    }

    /**
     * Smart polling: issued and used addresses whose own interval has passed (see
     * `interval()`), and those with unknown stats (new, or just spent from by this wallet).
     *
     * @return Collection<int, BitcoinAddress>
     */
    protected function dueAddresses(): Collection
    {
        $now = Date::now();

        return $this->wallet->addresses()
            ->where(fn ($query) => $query->whereNotNull('issued_at')->orWhere('tx_count', '>', 0))
            ->orderBy('chain')
            ->orderBy('index')
            ->get()
            ->filter(fn (BitcoinAddress $address) => $address->stats_hash === null
                || !$address->sync_at
                || $address->sync_at->lte($now->copy()->subSeconds(max(0, $this->interval($address) - 5))));
    }

    /**
     * How often an address is asked: every `active_interval` while a merchant order waits on it,
     * while it has an unconfirmed transaction or while its wallet is open; every
     * `idle_interval` when issued or active recently; otherwise every `cold_interval`.
     */
    protected function interval(BitcoinAddress $address): int
    {
        $now = Date::now();
        $config = fn (string $key, int $default) => (int)config('bitcoin.light.'.$key, $default);

        $touched = $this->wallet->touch_at && $this->wallet->touch_at->gte($now->copy()->subSeconds($config('active_window', 1800)));

        if (($address->watch_until && $address->watch_until->isAfter($now)) || $address->mempool_tx_count > 0 || $touched) {
            return $config('active_interval', 60);
        }

        $recent = ($address->issued_at && $address->issued_at->gte($now->copy()->subDays($config('new_address_days', 7))))
            || ($address->activity_at && $address->activity_at->gte($now->copy()->subDays($config('hot_window_days', 30))));

        return $recent ? $config('idle_interval', 600) : $config('cold_interval', 86400);
    }

    /**
     * Something to finish without scanning: confirmations still counting, or an unconfirmed
     * transaction that may have left the mempool.
     */
    protected function hasPendingWork(): bool
    {
        $target = max(6, (int)config('bitcoin.webhook.confirmations', 6));

        return $this->wallet->deposits()->whereNotNull('block_height')->where('confirmations', '<', $target)->exists()
            || $this->wallet->transfers()->whereNotNull('block_height')->where('confirmations', '<', $target)->exists()
            || $this->wallet->deposits()->where('webhook_status', 0)->exists()
            || $this->wallet->transactions()->whereNull('block_height')->whereNull('dropped_at')->exists();
    }

    protected function gapScanDue(): bool
    {
        $interval = (int)config('bitcoin.light.gap_scan_interval', 86400);

        return !$this->wallet->gap_scan_at || $this->wallet->gap_scan_at->lte(Date::now()->subSeconds($interval));
    }

    /**
     * Issued and used addresses every time, the hidden gap only when `$scanGap`. Finding a used
     * address in the gap derives more addresses, which are scanned in the same run.
     */
    /**
     * @param Collection<int, BitcoinAddress> $due
     */
    protected function scanAddresses(bool $scanGap, Collection $due): void
    {
        $this->book->ensureGap();

        if (!$scanGap) {
            $due->each(fn (BitcoinAddress $address) => $this->scanAddress($address));

            return;
        }

        // Everything, the hidden gap too; a used address found there derives more, scanned in this run
        $scanned = [];

        do {
            $addresses = $this->wallet->addresses()
                ->whereNotIn('id', $scanned)
                ->orderBy('chain')
                ->orderBy('index')
                ->get();

            foreach ($addresses as $address) {
                $scanned[] = $address->id;
                $this->scanAddress($address);
            }

            $added = $this->book->ensureGap();
        } while ($added > 0);
    }

    protected function scanAddress(BitcoinAddress $address): void
    {
        $stats = $this->pool->call(fn (EsploraApi $api) => $api->address($address->address));

        $chain = $stats['chain_stats'] ?? [];
        $mempool = $stats['mempool_stats'] ?? [];
        $hash = hash('sha256', json_encode([$chain, $mempool]));
        $txCount = (int)($chain['tx_count'] ?? 0) + (int)($mempool['tx_count'] ?? 0);

        $confirmed = (int)($chain['funded_txo_sum'] ?? 0) - (int)($chain['spent_txo_sum'] ?? 0);
        $pending = (int)($mempool['funded_txo_sum'] ?? 0) - (int)($mempool['spent_txo_sum'] ?? 0);

        if ($hash !== $address->stats_hash && $txCount > 0) {
            $this->log('Address '.$address->address.' changed, reading its history...');
            $this->fetchHistory($address);
        }

        $address->update([
            // An unconfirmed spend lowers the balance at once, an unconfirmed receipt is shown apart
            'balance' => self::btc($confirmed + min($pending, 0)),
            'unconfirmed_balance' => self::btc(max($pending, 0)),
            'stats_hash' => $hash,
            'tx_count' => $txCount,
            'mempool_tx_count' => (int)($mempool['tx_count'] ?? 0),
            // Activity — a change seen after an earlier look; the first look (import) is not one
            ...($address->stats_hash !== null && $hash !== $address->stats_hash ? ['activity_at' => Date::now()] : []),
            'sync_at' => Date::now(),
            ...($txCount > 0 && !$address->issued_at ? ['issued_at' => Date::now()] : []),
            ...($txCount > 0 && !$address->issued_at && $address->chain === AddressBook::RECEIVE ? ['available' => true] : []),
        ]);
    }

    /**
     * Unconfirmed and newest confirmed transactions, then older pages until a transaction
     * already stored as confirmed at the same height.
     */
    protected function fetchHistory(BitcoinAddress $address): void
    {
        $after = null;

        do {
            $page = $this->pool->call(fn (EsploraApi $api) => $api->addressTransactions($address->address, $after));
            $confirmed = array_values(array_filter($page, fn (array $tx) => $tx['status']['confirmed'] ?? false));
            $reachedKnown = false;

            foreach ($page as $tx) {
                $this->fetched[$tx['txid']] = $tx;

                if (($tx['status']['confirmed'] ?? false) && $this->isKnownConfirmed($tx)) {
                    $reachedKnown = true;
                }
            }

            $after = count($confirmed) >= 25 ? end($confirmed)['txid'] : null;
        } while ($after !== null && !$reachedKnown);
    }

    protected function isKnownConfirmed(array $tx): bool
    {
        return $this->wallet->transactions()
            ->where('txid', $tx['txid'])
            ->where('block_height', $tx['status']['block_height'] ?? null)
            ->exists();
    }

    protected function processTransactions(): void
    {
        if ($this->fetched === []) {
            return;
        }

        /** @var Collection<string, BitcoinAddress> $own */
        $own = $this->wallet->addresses()->get()->keyBy('address');

        foreach ($this->fetched as $tx) {
            $this->processTransaction($tx, $own);
        }
    }

    /**
     * @param Collection<string, BitcoinAddress> $own
     */
    protected function processTransaction(array $tx, Collection $own): void
    {
        $txid = $tx['txid'];
        $height = ($tx['status']['confirmed'] ?? false) ? (int)$tx['status']['block_height'] : null;
        $time = isset($tx['status']['block_time']) ? Date::createFromTimestamp($tx['status']['block_time']) : null;
        $confirmations = $height ? max(0, $this->tip - $height + 1) : 0;

        $inputSum = 0;
        $firstInput = null;

        foreach ($tx['vin'] ?? [] as $input) {
            $from = $input['prevout']['scriptpubkey_address'] ?? null;

            if ($from !== null && $own->has($from)) {
                $inputSum += (int)$input['prevout']['value'];
                $firstInput ??= $own->get($from);
            }
        }

        $ownOutputs = [];
        $foreignOutputs = [];

        foreach ($tx['vout'] ?? [] as $output) {
            $to = $output['scriptpubkey_address'] ?? null;

            if ($to !== null && $own->has($to)) {
                $ownOutputs[$to] = ($ownOutputs[$to] ?? 0) + (int)$output['value'];
            } elseif ($to !== null) {
                $foreignOutputs[] = ['address' => $to, 'value' => (int)$output['value']];
            }
        }

        $outputSum = array_sum($ownOutputs);
        $direction = $inputSum === 0 ? 'in' : ($foreignOutputs === [] ? 'self' : 'out');

        /** @var BitcoinTransaction $transaction */
        $transaction = $this->wallet->transactions()->firstOrNew(['txid' => $txid]);
        $transaction->fill([
            'direction' => $direction,
            'amount' => self::btc($outputSum - $inputSum),
            'fee' => $direction === 'in' ? null : self::btc((int)($tx['fee'] ?? 0)),
            'block_height' => $height,
            'time_at' => $time ?? $transaction->time_at ?? Date::now(),
            'dropped_at' => null,
        ]);
        $transaction->updated_at = Date::now();
        $transaction->save();

        if ($direction === 'in') {
            foreach ($ownOutputs as $to => $value) {
                /** @var BitcoinAddress $address */
                $address = $own->get($to);

                $deposit = Bitcoin::getModelDeposit()::query()->updateOrCreate(
                    ['address_id' => $address->id, 'txid' => $txid],
                    [
                        'wallet_id' => $this->wallet->id,
                        'amount' => self::btc($value),
                        'block_height' => $height,
                        'confirmations' => $confirmations,
                        'time_at' => $transaction->time_at,
                    ],
                );

                // History mined well before the wallet was added here (first sync of a restored
                // wallet) is recorded without the webhook: no "received" push or merchant call for
                // old money. Judged by block height, not by the miner-set block time, with a margin
                if ($deposit->wasRecentlyCreated && $this->isHistory($height)) {
                    $deposit->update(['webhook_status' => 2, 'webhook_data' => ['skipped' => 'mined before the wallet was added']]);
                }
            }
        }

        // Transfers recorded by a send of this wallet (to itself too) follow their transaction
        $this->wallet->transfers()->where('txid', $txid)->update(['block_height' => $height, 'confirmations' => $confirmations]);

        if ($direction === 'out' && $firstInput) {
            foreach ($foreignOutputs as $output) {
                $this->wallet->transfers()->updateOrCreate(
                    ['address_id' => $firstInput->id, 'txid' => $txid, 'receiver_address' => $output['address']],
                    [
                        'amount' => self::btc($output['value']),
                        'block_height' => $height,
                        'confirmations' => $confirmations,
                        'time_at' => $transaction->time_at,
                    ],
                );
            }
        }
    }

    /**
     * Mined at least `history_margin_blocks` before the wallet's birth height. Unknown birth
     * height or an unconfirmed transaction is never history.
     */
    protected function isHistory(?int $height): bool
    {
        $birth = $this->wallet->birth_height;

        return $height !== null && $birth !== null
            && $height <= $birth - max(1, (int)config('bitcoin.light.history_margin_blocks', 6));
    }

    /**
     * Unconfirmed transactions not seen in this run: once unseen for `dropped_after`, ask a
     * provider — confirmed meanwhile gets its height, unknown is marked dropped and its pending
     * deposits are removed. Only checked when some history was read in this run (a dropped
     * transaction changes the mempool stats of its addresses).
     */
    protected function resolveMissingPending(): void
    {
        if ($this->fetched === []) {
            return;
        }

        $threshold = Date::now()->subSeconds((int)config('bitcoin.light.dropped_after', 1800));

        $this->wallet->transactions()
            ->whereNull('block_height')
            ->whereNull('dropped_at')
            ->whereNotIn('txid', array_keys($this->fetched))
            ->where('updated_at', '<=', $threshold)
            ->get()
            ->each(function (BitcoinTransaction $transaction): void {
                $status = $this->pool->call(fn (EsploraApi $api) => $api->transactionStatus($transaction->txid));

                if ($status !== null && ($status['confirmed'] ?? false)) {
                    $transaction->update(['block_height' => (int)$status['block_height']]);
                    $this->wallet->deposits()->where('txid', $transaction->txid)->update(['block_height' => (int)$status['block_height']]);
                    $this->wallet->transfers()->where('txid', $transaction->txid)->update(['block_height' => (int)$status['block_height']]);

                    return;
                }

                if ($status === null) {
                    $this->log('Transaction '.$transaction->txid.' left the mempool, marking it dropped.');
                    $transaction->update(['dropped_at' => Date::now()]);
                    $this->wallet->deposits()->where('txid', $transaction->txid)->whereNull('block_height')->where('webhook_status', 0)->delete();
                    $this->wallet->transfers()->where('txid', $transaction->txid)->whereNull('block_height')->delete();
                } else {
                    $transaction->touch();
                }
            });
    }

    /**
     * Confirmations of everything mined that is still below the target. The height of each such
     * transaction is checked again with a provider first: after a reorg it may be back in the
     * mempool (height cleared, confirmations 0) or in another block (height updated), so a
     * deposit never reaches the webhook on a stale height.
     */
    protected function updateConfirmations(): void
    {
        $target = max(6, (int)config('bitcoin.webhook.confirmations', 6));
        $queries = fn () => [$this->wallet->deposits(), $this->wallet->transfers()];

        $txids = collect($queries())
            ->flatMap(fn ($query) => $query->whereNotNull('block_height')->where('confirmations', '<', $target)->pluck('txid'))
            ->unique();

        foreach ($txids as $txid) {
            $status = $this->pool->call(fn (EsploraApi $api) => $api->transactionStatus($txid));
            $height = ($status['confirmed'] ?? false) ? (int)$status['block_height'] : null;

            foreach ([...$queries(), $this->wallet->transactions()] as $query) {
                $query->where('txid', $txid)
                    ->where(fn ($q) => $q->whereNull('block_height')->orWhere('block_height', '!=', $height ?? -1))
                    ->get()
                    ->each(fn ($row) => $row->update(['block_height' => $height]));
            }

            if ($height === null) {
                $this->log('Transaction '.$txid.' is no longer confirmed (reorg), waiting for it again.');
            }
        }

        // Transfers whose transaction is already known as mined (recorded before it was) take its height
        $mined = $this->wallet->transactions()->whereNotNull('block_height')->pluck('block_height', 'txid');
        $this->wallet->transfers()->whereNull('block_height')->whereIn('txid', $mined->keys())->get()
            ->each(fn ($transfer) => $transfer->update(['block_height' => $mined[$transfer->txid]]));

        foreach ($queries() as $query) {
            $query->where('confirmations', '<', $target + 1)
                ->get()
                ->each(fn ($row) => $row->update([
                    'confirmations' => $row->block_height ? max(0, $this->tip - (int)$row->block_height + 1) : 0,
                ]));
        }
    }

    protected function updateWalletBalance(bool $scanGap): void
    {
        $addresses = $this->wallet->addresses()->get(['balance', 'unconfirmed_balance']);

        $this->wallet->update([
            'balance' => $addresses->reduce(fn (BigDecimal $sum, BitcoinAddress $a) => $sum->plus($a->balance ?? 0), BigDecimal::zero()),
            'unconfirmed_balance' => $addresses->reduce(fn (BigDecimal $sum, BitcoinAddress $a) => $sum->plus($a->unconfirmed_balance ?? 0), BigDecimal::zero()),
            'sync_at' => Date::now(),
            ...($scanGap ? ['gap_scan_at' => Date::now()] : []),
        ]);
    }

    protected function executeWebhooks(): void
    {
        /** @var class-string<WebhookHandlerInterface>|null $handlerClass */
        $handlerClass = config('bitcoin.webhook.handler');

        if (!$handlerClass) {
            return;
        }

        $handler = App::make($handlerClass);

        $this->wallet->deposits()
            ->with(['wallet', 'address'])
            ->where('confirmations', '>=', (int)config('bitcoin.webhook.confirmations', 0))
            ->where('webhook_status', 0)
            ->orderBy('created_at')
            ->get()
            ->each(function (BitcoinDeposit $deposit) use ($handler): void {
                // Claimed with a conditional UPDATE: of two concurrent runs only one calls the handler
                $claimed = $deposit->newQuery()
                    ->whereKey($deposit->getKey())
                    ->where('webhook_status', 0)
                    ->update(['webhook_status' => 1, 'webhook_data' => json_encode(['start_at' => Date::now()])]);

                if ($claimed !== 1) {
                    return;
                }

                $deposit->refresh();

                try {
                    $deposit->update([
                        'webhook_status' => 2,
                        'webhook_data' => [
                            'start_at' => $deposit->webhook_data['start_at'] ?? Date::now(),
                            'success_at' => Date::now(),
                            ...($handler->handle($deposit) ?? []),
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

                    $this->log('Deposit '.$deposit->id.' webhook failed: '.$e->getMessage(), 'error');
                }
            });
    }

    public static function btc(int $satoshi): BigDecimal
    {
        return BigDecimal::ofUnscaledValue($satoshi, 8);
    }
}
