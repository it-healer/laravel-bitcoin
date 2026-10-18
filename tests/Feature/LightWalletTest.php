<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use ItHealer\LaravelBitcoin\Facades\Bitcoin;
use ItHealer\LaravelBitcoin\Light\Api\ProviderException;
use ItHealer\LaravelBitcoin\Light\Api\ProviderPool;
use ItHealer\LaravelBitcoin\Models\BitcoinDeposit;
use ItHealer\LaravelBitcoin\Models\BitcoinDeposit as Deposit;
use ItHealer\LaravelBitcoin\Models\BitcoinProvider;
use ItHealer\LaravelBitcoin\Models\BitcoinWallet;
use ItHealer\LaravelBitcoin\WebhookHandlers\WebhookHandlerInterface;

uses(RefreshDatabase::class);

const LIGHT_MNEMONIC = 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';
const RECEIVE_0 = 'bc1qcr8te4kr609gcawutmrza0j4xv80jy8z306fyu';
const RECEIVE_1 = 'bc1qnjg0jd8228aq7egyzacy8cys3knf9xvrerkf9g';
const CHANGE_0 = 'bc1q8c6fshw2dlwun7ekn9qwf37cu2rn755upcp6el';
const STRANGER = 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4';

/**
 * Fake Esplora: transactions are the state, address stats and listings are computed from them
 * the way a real server does. `down` providers answer 429.
 *
 * @return ArrayObject{txs: array<string, array>, tip: int, down: list<string>, handled: list<int>}
 */
function chainState(bool $reset = false): ArrayObject
{
    static $state;

    if ($reset || $state === null) {
        $state = new ArrayObject(['txs' => [], 'tip' => 900_000, 'down' => [], 'handled' => []], ArrayObject::ARRAY_AS_PROPS);
    }

    return $state;
}

/**
 * @param list<array{0: string, 1: int}> $inputs address, value
 * @param list<array{0: string, 1: int}> $outputs address, value
 */
function addTx(string $txid, array $inputs, array $outputs, ?int $height = null, int $fee = 500): void
{
    chainState()->txs[$txid] = [
        'txid' => $txid,
        'vin' => array_map(fn ($in) => ['txid' => str_repeat('f', 64), 'vout' => 0, 'prevout' => ['scriptpubkey_address' => $in[0], 'value' => $in[1]]], $inputs),
        'vout' => array_map(fn ($out) => ['scriptpubkey_address' => $out[0], 'value' => $out[1]], $outputs),
        'fee' => $fee,
        'status' => $height ? ['confirmed' => true, 'block_height' => $height, 'block_time' => 1_760_000_000] : ['confirmed' => false],
    ];
}

function confirmTx(string $txid, int $height): void
{
    $txs = chainState()->txs;
    $txs[$txid]['status'] = ['confirmed' => true, 'block_height' => $height, 'block_time' => 1_760_000_000];
    chainState()->txs = $txs;
}

function addressStats(string $address): array
{
    $stats = ['chain_stats' => [], 'mempool_stats' => []];

    foreach (['chain_stats' => true, 'mempool_stats' => false] as $key => $confirmed) {
        $funded = $spent = $count = 0;

        foreach (chainState()->txs as $tx) {
            if ($tx['status']['confirmed'] !== $confirmed) {
                continue;
            }

            $in = array_sum(array_map(fn ($i) => $i['prevout']['scriptpubkey_address'] === $address ? $i['prevout']['value'] : 0, $tx['vin']));
            $out = array_sum(array_map(fn ($o) => $o['scriptpubkey_address'] === $address ? $o['value'] : 0, $tx['vout']));
            $involved = $in > 0 || $out > 0;

            $funded += $out;
            $spent += $in;
            $count += $involved ? 1 : 0;
        }

        $stats[$key] = ['funded_txo_count' => 0, 'funded_txo_sum' => $funded, 'spent_txo_count' => 0, 'spent_txo_sum' => $spent, 'tx_count' => $count];
    }

    return $stats;
}

function addressTxs(string $address, ?string $after): array
{
    $involved = array_values(array_filter(chainState()->txs, function ($tx) use ($address) {
        foreach ($tx['vin'] as $i) {
            if ($i['prevout']['scriptpubkey_address'] === $address) {
                return true;
            }
        }
        foreach ($tx['vout'] as $o) {
            if ($o['scriptpubkey_address'] === $address) {
                return true;
            }
        }

        return false;
    }));

    $mempool = array_values(array_filter($involved, fn ($tx) => !$tx['status']['confirmed']));
    $confirmed = array_values(array_filter($involved, fn ($tx) => $tx['status']['confirmed']));
    usort($confirmed, fn ($a, $b) => $b['status']['block_height'] <=> $a['status']['block_height']);

    if ($after === null) {
        return [...$mempool, ...array_slice($confirmed, 0, 25)];
    }

    $position = array_search($after, array_column($confirmed, 'txid'), true);

    return array_slice($confirmed, $position + 1, 25);
}

function fakeEsplora(): void
{
    Http::fake(function ($request) {
        $url = $request->url();
        $host = parse_url($url, PHP_URL_HOST);
        $path = preg_replace('#^/api/#', '', parse_url($url, PHP_URL_PATH));

        if (in_array($host, chainState()->down, true)) {
            return Http::response('Too Many Requests', 429);
        }

        return match (true) {
            $path === 'blocks/tip/height' => Http::response((string)chainState()->tip),
            (bool)preg_match('#^address/([^/]+)$#', $path, $m) => Http::response(addressStats($m[1])),
            (bool)preg_match('#^address/([^/]+)/txs(?:/chain/([0-9a-f]+))?$#', $path, $m) => Http::response(addressTxs($m[1], $m[2] ?? null)),
            (bool)preg_match('#^tx/([0-9a-f]+)/status$#', $path, $m) => isset(chainState()->txs[$m[1]])
                ? Http::response(chainState()->txs[$m[1]]['status'])
                : Http::response('Transaction not found', 404),
            $path === 'tx' && $request->method() === 'POST' => Http::response(hash('sha256', $request->body())),
            default => Http::response('not found', 404),
        };
    });
}

function lightSetup(): BitcoinWallet
{
    chainState(reset: true);
    fakeEsplora();
    config()->set('bitcoin.webhook.handler', null);

    BitcoinProvider::create(['name' => 'mempool', 'base_url' => 'https://mempool.test/api', 'priority' => 1]);
    BitcoinProvider::create(['name' => 'blockstream', 'base_url' => 'https://blockstream.test/api', 'priority' => 2]);

    $wallet = Bitcoin::createLightWallet('w1', LIGHT_MNEMONIC);
    // added long ago: transactions of the fake chain (block time 2025) count as new money
    $wallet->forceFill(['created_at' => now()->subYears(10)])->save();

    return $wallet;
}

function syncWallet(BitcoinWallet $wallet, bool $full = false): BitcoinWallet
{
    Bitcoin::syncLightWallet($wallet->fresh(), $full);

    return $wallet->fresh();
}

function btc8(mixed $value): string
{
    return (string)\Brick\Math\BigDecimal::of((string)$value)->toScale(8);
}

function requestsTo(string $pattern): int
{
    return collect(Http::recorded())->filter(fn ($pair) => preg_match($pattern, parse_url($pair[0]->url(), PHP_URL_PATH)))->count();
}

/* ---------- Wallet and addresses ---------- */

it('creates a light wallet that keeps only the account xpub', function () {
    $wallet = lightSetup();

    expect($wallet->isLight())->toBeTrue()
        ->and($wallet->node_id)->toBeNull()
        ->and($wallet->mnemonic)->toBeNull()
        ->and($wallet->seed)->toBeNull()
        ->and($wallet->xpub)->toBe('xpub6CatWdiZiodmUeTDp8LT5or8nmbKNcuyvz7WyksVFkKB4RHwCD3XyuvPEbvqAQY3rAPshWcMLoP2fMFMKHPJ4ZeZXYVUhLv1VMrjPC7PW6V')
        ->and($wallet->derivation_path)->toBe("m/84'/0'/0'")
        ->and($wallet->addresses()->where('chain', 0)->count())->toBe(20)
        ->and($wallet->addresses()->where('chain', 1)->count())->toBe(20)
        ->and($wallet->addresses()->where('available', true)->count())->toBe(0)
        ->and($wallet->addresses()->where('chain', 0)->where('index', 0)->value('address'))->toBe(RECEIVE_0)
        ->and($wallet->addresses()->where('chain', 1)->where('index', 0)->value('address'))->toBe(CHANGE_0);
});

it('creates the same light wallet from the BIP39 seed as from the mnemonic', function () {
    lightSetup();
    $seed = bin2hex(\ItHealer\LaravelBitcoin\BIP39\BIP39::fromWords(explode(' ', LIGHT_MNEMONIC), \ItHealer\LaravelBitcoin\BIP39\Language\English::getInstance())->generateSeed(''));

    $fromSeed = Bitcoin::createLightWalletFromSeed('w-seed', $seed);

    expect($fromSeed->xpub)->toBe(BitcoinWallet::where('name', 'w1')->value('xpub'))
        ->and(fn () => Bitcoin::createLightWalletFromSeed('w-bad', 'not-hex'))->toThrow(InvalidArgumentException::class);
});

it('hands out receive addresses in order and keeps the gap ahead', function () {
    $wallet = lightSetup();

    $first = Bitcoin::createAddress($wallet, title: 'Order 1');
    $second = Bitcoin::createAddress($wallet->fresh());

    expect($first->address)->toBe(RECEIVE_0)
        ->and($first->title)->toBe('Order 1')
        ->and($first->available)->toBeTrue()
        ->and($first->issued_at)->not->toBeNull()
        ->and($second->address)->toBe(RECEIVE_1)
        ->and($wallet->addresses()->where('chain', 0)->max('index'))->toBe(21);
});

/* ---------- Deposits ---------- */

it('shows an unconfirmed deposit, then confirms it and calls the webhook once', function () {
    $wallet = lightSetup();
    $address = Bitcoin::createAddress($wallet);

    config()->set('bitcoin.webhook.handler', SpyBitcoinWebhook::class);
    config()->set('bitcoin.webhook.confirmations', 3);

    addTx(str_repeat('a', 64), [[STRANGER, 150_000]], [[RECEIVE_0, 100_000], [STRANGER, 49_500]]);
    $wallet = syncWallet($wallet);

    $deposit = Deposit::query()->sole();
    expect(btc8($wallet->balance))->toBe('0.00000000')
        ->and(btc8($wallet->unconfirmed_balance))->toBe('0.00100000')
        ->and(btc8($deposit->amount))->toBe('0.00100000')
        ->and($deposit->address_id)->toBe($address->id)
        ->and($deposit->confirmations)->toBe(0)
        ->and($wallet->transactions()->sole()->direction)->toBe('in');

    confirmTx(str_repeat('a', 64), 900_000);
    $wallet = syncWallet($wallet);

    expect(btc8($wallet->balance))->toBe('0.00100000')
        ->and(btc8($wallet->unconfirmed_balance))->toBe('0.00000000')
        ->and($deposit->fresh()->block_height)->toBe(900_000)
        ->and($deposit->fresh()->confirmations)->toBe(1)
        ->and(chainState()->handled)->toBe([]);

    chainState()->tip = 900_002;
    \Illuminate\Support\Facades\Cache::flush();
    syncWallet($wallet);
    syncWallet($wallet);

    expect($deposit->fresh()->confirmations)->toBe(3)
        ->and($deposit->fresh()->webhook_status)->toBe(2)
        ->and(chainState()->handled)->toBe([$deposit->id]);
});

it('records history from before the wallet was added without calling the webhook', function () {
    $wallet = lightSetup();
    config()->set('bitcoin.webhook.handler', SpyBitcoinWebhook::class);
    config()->set('bitcoin.webhook.confirmations', 1);
    $wallet->forceFill(['created_at' => \Illuminate\Support\Carbon::createFromTimestamp(1_760_000_100)])->save();

    // block_time of addTx is 1 760 000 000 — before the wallet; the second one comes later
    addTx(str_repeat('a', 64), [[STRANGER, 150_000]], [[RECEIVE_0, 100_000]], 899_000);
    $wallet = syncWallet($wallet, full: true);

    $old = Deposit::sole();
    expect($old->webhook_status)->toBe(2)
        ->and($old->webhook_data['skipped'] ?? null)->not->toBeNull()
        ->and(chainState()->handled)->toBe([]);

    addTx(str_repeat('b', 64), [[STRANGER, 150_000]], [[RECEIVE_1, 50_000]]);
    $txs = chainState()->txs;
    $txs[str_repeat('b', 64)]['status'] = ['confirmed' => true, 'block_height' => 900_000, 'block_time' => 1_760_000_200];
    chainState()->txs = $txs;
    syncWallet($wallet, full: true);

    $new = Deposit::where('txid', str_repeat('b', 64))->sole();
    expect(chainState()->handled)->toBe([$new->id]);
});

it('does not read the history of an address whose stats did not change', function () {
    $wallet = lightSetup();
    Bitcoin::createAddress($wallet);
    addTx(str_repeat('a', 64), [[STRANGER, 150_000]], [[RECEIVE_0, 100_000]], 899_990);

    syncWallet($wallet, full: true);
    $before = requestsTo('#/txs#');

    syncWallet($wallet);

    expect(requestsTo('#/txs#'))->toBe($before)
        ->and(Deposit::count())->toBe(1);
});

it('finds a payment to a gap address on a full sync and extends the gap', function () {
    $wallet = lightSetup();
    $gapAddress = $wallet->addresses()->where('chain', 0)->where('index', 15)->value('address');
    addTx(str_repeat('b', 64), [[STRANGER, 80_000]], [[$gapAddress, 70_000]], 899_000);

    // a regular sync checks only issued addresses
    $wallet->update(['gap_scan_at' => now()]);
    syncWallet($wallet);
    expect(Deposit::count())->toBe(0);

    $wallet = syncWallet($wallet, full: true);
    $found = $wallet->addresses()->where('address', $gapAddress)->sole();

    expect(Deposit::count())->toBe(1)
        ->and($found->available)->toBeTrue()
        ->and($found->issued_at)->not->toBeNull()
        ->and(btc8($wallet->balance))->toBe('0.00070000')
        ->and($wallet->addresses()->where('chain', 0)->max('index'))->toBe(35)
        ->and($wallet->gap_scan_at)->not->toBeNull();
});

it('checks the gap again once gap_scan_interval passed', function () {
    $wallet = lightSetup();
    syncWallet($wallet, full: true);

    $gapAddress = $wallet->addresses()->where('chain', 0)->where('index', 3)->value('address');
    addTx(str_repeat('c', 64), [[STRANGER, 80_000]], [[$gapAddress, 70_000]], 899_000);

    syncWallet($wallet);
    expect(Deposit::count())->toBe(0);

    $this->travel(25)->hours();
    syncWallet($wallet);
    expect(Deposit::count())->toBe(1);
});

/* ---------- Outgoing ---------- */

it('records an outgoing transfer with change and the net amount', function () {
    $wallet = lightSetup();
    Bitcoin::createAddress($wallet);
    addTx(str_repeat('a', 64), [[STRANGER, 150_000]], [[RECEIVE_0, 100_000]], 899_000);
    addTx(str_repeat('d', 64), [[RECEIVE_0, 100_000]], [[STRANGER, 60_000], [CHANGE_0, 39_000]], 899_500, fee: 1_000);

    $wallet = syncWallet($wallet, full: true);
    $out = $wallet->transactions()->where('txid', str_repeat('d', 64))->sole();
    $transfer = $wallet->transfers()->sole();

    expect($out->direction)->toBe('out')
        ->and(btc8($out->amount))->toBe('-0.00061000')
        ->and(btc8($out->fee))->toBe('0.00001000')
        ->and($transfer->receiver_address)->toBe(STRANGER)
        ->and(btc8($transfer->amount))->toBe('0.00060000')
        ->and(Deposit::count())->toBe(1) // change is not a deposit
        ->and(btc8($wallet->balance))->toBe('0.00039000')
        ->and($wallet->addresses()->where('address', CHANGE_0)->value('available'))->toBeFalse();
});

it('treats a transfer between own addresses as self, without deposit or transfer', function () {
    $wallet = lightSetup();
    Bitcoin::createAddress($wallet);
    Bitcoin::createAddress($wallet->fresh());
    addTx(str_repeat('a', 64), [[STRANGER, 150_000]], [[RECEIVE_0, 100_000]], 899_000);
    addTx(str_repeat('e', 64), [[RECEIVE_0, 100_000]], [[RECEIVE_1, 99_000]], 899_100, fee: 1_000);

    $wallet = syncWallet($wallet, full: true);

    expect($wallet->transactions()->where('txid', str_repeat('e', 64))->value('direction'))->toBe('self')
        ->and(Deposit::count())->toBe(1)
        ->and($wallet->transfers()->count())->toBe(0)
        ->and(btc8($wallet->balance))->toBe('0.00099000');
});

it('lowers the balance at once for an unconfirmed spend', function () {
    $wallet = lightSetup();
    Bitcoin::createAddress($wallet);
    addTx(str_repeat('a', 64), [[STRANGER, 150_000]], [[RECEIVE_0, 100_000]], 899_000);
    addTx(str_repeat('d', 64), [[RECEIVE_0, 100_000]], [[STRANGER, 99_000]], fee: 1_000);

    $wallet = syncWallet($wallet, full: true);

    expect(btc8($wallet->balance))->toBe('0.00000000')
        ->and(btc8($wallet->unconfirmed_balance))->toBe('0.00000000');
});

/* ---------- Dropped transactions ---------- */

it('drops an unconfirmed deposit that left the mempool', function () {
    $wallet = lightSetup();
    Bitcoin::createAddress($wallet);
    addTx(str_repeat('a', 64), [[STRANGER, 150_000]], [[RECEIVE_0, 100_000]]);
    syncWallet($wallet);
    expect(Deposit::count())->toBe(1);

    // replaced: the original is gone, a new transaction pays someone else
    $txs = chainState()->txs;
    unset($txs[str_repeat('a', 64)]);
    chainState()->txs = $txs;
    addTx(str_repeat('9', 64), [[STRANGER, 150_000]], [[STRANGER, 149_000]]);

    $this->travel(31)->minutes();
    Bitcoin::createAddress($wallet->fresh()); // something else changes, so history is read
    addTx(str_repeat('8', 64), [[STRANGER, 50_000]], [[RECEIVE_1, 40_000]]);
    $wallet = syncWallet($wallet);

    expect($wallet->transactions()->where('txid', str_repeat('a', 64))->value('dropped_at'))->not->toBeNull()
        ->and(Deposit::where('txid', str_repeat('a', 64))->exists())->toBeFalse()
        ->and(Deposit::where('txid', str_repeat('8', 64))->exists())->toBeTrue()
        ->and(btc8($wallet->unconfirmed_balance))->toBe('0.00040000');
});

it('keeps a pending transaction that is merely unseen for a short while', function () {
    $wallet = lightSetup();
    Bitcoin::createAddress($wallet);
    addTx(str_repeat('a', 64), [[STRANGER, 150_000]], [[RECEIVE_0, 100_000]]);
    syncWallet($wallet);

    $txs = chainState()->txs;
    unset($txs[str_repeat('a', 64)]);
    chainState()->txs = $txs;
    Bitcoin::createAddress($wallet->fresh());
    addTx(str_repeat('8', 64), [[STRANGER, 50_000]], [[RECEIVE_1, 40_000]]);
    syncWallet($wallet);

    expect(Deposit::where('txid', str_repeat('a', 64))->exists())->toBeTrue();
});

/* ---------- Reorgs and concurrency ---------- */

it('clears the height of a deposit whose block was reorganized away and holds the webhook', function () {
    $wallet = lightSetup();
    Bitcoin::createAddress($wallet);
    config()->set('bitcoin.webhook.handler', SpyBitcoinWebhook::class);
    config()->set('bitcoin.webhook.confirmations', 3);

    addTx(str_repeat('a', 64), [[STRANGER, 150_000]], [[RECEIVE_0, 100_000]], 900_000);
    syncWallet($wallet);
    expect(Deposit::sole()->confirmations)->toBe(1);

    // reorg: the transaction is back in the mempool, while the tip keeps growing
    $txs = chainState()->txs;
    $txs[str_repeat('a', 64)]['status'] = ['confirmed' => false];
    chainState()->txs = $txs;
    chainState()->tip = 900_005;
    \Illuminate\Support\Facades\Cache::flush();
    syncWallet($wallet);

    expect(Deposit::sole()->block_height)->toBeNull()
        ->and(Deposit::sole()->confirmations)->toBe(0)
        ->and($wallet->transactions()->sole()->block_height)->toBeNull()
        ->and(chainState()->handled)->toBe([]);

    // mined again in another block
    confirmTx(str_repeat('a', 64), 900_004);
    \Illuminate\Support\Facades\Cache::flush();
    syncWallet($wallet);

    expect(Deposit::sole()->block_height)->toBe(900_004)
        ->and(Deposit::sole()->confirmations)->toBe(2);
});

it('updates the height of a deposit that moved to another block', function () {
    $wallet = lightSetup();
    Bitcoin::createAddress($wallet);
    addTx(str_repeat('a', 64), [[STRANGER, 150_000]], [[RECEIVE_0, 100_000]], 899_998);
    syncWallet($wallet);

    confirmTx(str_repeat('a', 64), 899_999);
    syncWallet($wallet);

    expect(Deposit::sole()->block_height)->toBe(899_999)
        ->and(Deposit::sole()->confirmations)->toBe(2);
});

it('calls the webhook of a deposit claimed by another run only once', function () {
    $wallet = lightSetup();
    Bitcoin::createAddress($wallet);
    config()->set('bitcoin.webhook.handler', SpyBitcoinWebhook::class);
    config()->set('bitcoin.webhook.confirmations', 1);
    addTx(str_repeat('a', 64), [[STRANGER, 150_000]], [[RECEIVE_0, 100_000]], 899_000);

    // another run claims the deposit between our query and our claim
    Deposit::retrieved(function (Deposit $deposit) {
        if ($deposit->webhook_status === 0 && chainState()->handled === [] && !isset(chainState()->raced)) {
            chainState()->raced = true;
            Deposit::query()->whereKey($deposit->id)->update(['webhook_status' => 1]);
        }
    });

    syncWallet($wallet);
    Deposit::flushEventListeners();

    expect(chainState()->handled)->toBe([])
        ->and(Deposit::sole()->webhook_status)->toBe(1);
});

it('skips a sync of a wallet that is already syncing', function () {
    $wallet = lightSetup();
    $lock = \Illuminate\Support\Facades\Cache::lock('bitcoin:light-sync:'.$wallet->id, 60);
    $lock->get();

    expect(Bitcoin::syncLightWallet($wallet))->toBeFalse()
        ->and($wallet->fresh()->sync_at)->toBeNull();

    $lock->release();

    expect(Bitcoin::syncLightWallet($wallet))->toBeTrue();
});

/* ---------- History pages ---------- */

it('reads older history pages until a known confirmed transaction', function () {
    $wallet = lightSetup();
    Bitcoin::createAddress($wallet);

    for ($i = 0; $i < 30; $i++) {
        addTx(str_pad(dechex($i + 1), 64, '0', STR_PAD_LEFT), [[STRANGER, 20_000]], [[RECEIVE_0, 10_000]], 800_000 + $i);
    }

    syncWallet($wallet, full: true);
    expect(Deposit::count())->toBe(30)
        ->and(requestsTo('#/txs/chain/#'))->toBe(1);

    addTx(str_repeat('7', 64), [[STRANGER, 20_000]], [[RECEIVE_0, 10_000]], 899_999);
    $chainPages = requestsTo('#/txs/chain/#');
    syncWallet($wallet);

    expect(Deposit::count())->toBe(31)
        ->and(requestsTo('#/txs/chain/#'))->toBe($chainPages);
});

/* ---------- Providers ---------- */

it('falls back to the next provider and counts requests per provider', function () {
    $wallet = lightSetup();
    Bitcoin::createAddress($wallet);
    chainState()->down = ['mempool.test'];

    syncWallet($wallet);

    $mempool = BitcoinProvider::where('name', 'mempool')->sole();
    $blockstream = BitcoinProvider::where('name', 'blockstream')->sole();

    expect($mempool->requests)->toBe(1) // asked once, then cooled down
        ->and($mempool->worked)->toBeFalse()
        ->and($blockstream->requests)->toBeGreaterThan(1)
        ->and($blockstream->block_height)->toBe(900_000);
});

it('fails when every provider fails', function () {
    lightSetup();
    chainState()->down = ['mempool.test', 'blockstream.test'];

    expect(fn () => app(ProviderPool::class)->tipHeight())->toThrow(ProviderException::class);
});

it('broadcasts to every provider and succeeds when one accepts', function () {
    lightSetup();
    chainState()->down = ['mempool.test'];
    $hex = '0200000001abcdef';

    $result = app(ProviderPool::class)->broadcast($hex, hash('sha256', $hex));

    expect($result['accepted'])->toBe(['blockstream'])
        ->and($result['errors'])->toHaveKey('mempool');
});

it('rejects a broadcast when the providers return another txid', function () {
    lightSetup();

    expect(fn () => app(ProviderPool::class)->broadcast('0200', str_repeat('0', 64)))->toThrow(RuntimeException::class);
});

/* ---------- Scheduling ---------- */

it('syncs touched wallets every minute and the rest less often', function () {
    $active = lightSetup();
    $idle = Bitcoin::createLightWalletFromXpub('w2', 'xpub6ASuArnXKPbfEwhqN6e3mwBcDTgzisQN1wXN9BJcM47sSikHjJf3UFHKkNAWbWMiGj7Wf5uMash7SyYq527Hqck2AxYysAA7xmALppuCkwQ');

    $active->update(['touch_at' => now(), 'sync_at' => now()->subSeconds(70)]);
    $idle->update(['sync_at' => now()->subSeconds(70)]);

    $this->artisan('bitcoin:light-sync')->assertSuccessful();

    expect($active->fresh()->sync_at->gt(now()->subSeconds(5)))->toBeTrue()
        ->and($idle->fresh()->sync_at->lt(now()->subSeconds(60)))->toBeTrue();
});

class SpyBitcoinWebhook implements WebhookHandlerInterface
{
    public function handle(BitcoinDeposit $deposit): ?array
    {
        chainState()->handled = [...chainState()->handled, $deposit->id];

        return null;
    }
}
