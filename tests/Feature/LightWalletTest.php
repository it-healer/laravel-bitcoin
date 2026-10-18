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
use ItHealer\LaravelBitcoin\Tests\Support\SpyBitcoinWebhook;

uses(RefreshDatabase::class);

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

it('never hands out an address with history before the first full sync', function () {
    $wallet = lightSetup();
    addTx(str_repeat('a', 64), [[STRANGER, 150_000]], [[RECEIVE_0, 100_000]], 800_000);

    $issued = Bitcoin::createAddress($wallet);

    expect($issued->address)->toBe(RECEIVE_1)
        ->and($wallet->addresses()->where('address', RECEIVE_0)->value('tx_count'))->toBe(1);

    // after a full sync the state is known and addresses are not checked one by one
    syncWallet($wallet, full: true);
    $before = requestsTo('#^/api/address/[^/]+$#');
    Bitcoin::createAddress($wallet->fresh());

    expect(requestsTo('#^/api/address/[^/]+$#'))->toBe($before);
});

/* ---------- Moving node wallets to the light mode ---------- */

it('moves a node wallet to the light mode, keeping its addresses, deposits and transfers', function () {
    lightSetup();
    $node = \ItHealer\LaravelBitcoin\Models\BitcoinNode::create(['name' => 'local', 'host' => '127.0.0.1', 'port' => 8332, 'available' => false]);
    $wallet = BitcoinWallet::create(['node_id' => $node->id, 'name' => 'old', 'descriptors' => '[]', 'balance' => '0.001']);
    $receive = $wallet->addresses()->create(['address' => RECEIVE_1, 'type' => 'bech32', 'title' => 'Shop', 'available' => true]);
    $change = $wallet->addresses()->create(['address' => CHANGE_0, 'type' => 'bech32', 'available' => true]);
    $foreign = $wallet->addresses()->create(['address' => STRANGER, 'type' => 'bech32', 'available' => true]);
    $deposit = $wallet->deposits()->create(['address_id' => $receive->id, 'txid' => str_repeat('a', 64), 'amount' => '0.001', 'confirmations' => 10, 'time_at' => now(), 'webhook_status' => 2]);

    $report = Bitcoin::convertToLight($wallet, BIP84_TEST_XPUB);
    $wallet->refresh();

    expect($report)->toBe(['matched' => 2, 'unmatched' => [STRANGER]])
        ->and($wallet->isLight())->toBeTrue()
        ->and($wallet->node_id)->toBeNull()
        ->and($wallet->birth_height)->toBe(900_000)
        ->and($receive->fresh()->only(['chain', 'index', 'title']))->toBe(['chain' => 0, 'index' => 1, 'title' => 'Shop'])
        ->and($change->fresh()->only(['chain', 'index']))->toBe(['chain' => 1, 'index' => 0])
        ->and($foreign->fresh()->chain)->toBeNull()
        ->and($deposit->fresh()->webhook_status)->toBe(2)
        // the gap continues after the used addresses
        ->and($wallet->addresses()->where('chain', 0)->max('index'))->toBe(21)
        ->and($wallet->addresses()->where('chain', 1)->max('index'))->toBe(20);

    // deleting the old node no longer touches the wallet
    $node->delete();
    expect(BitcoinWallet::find($wallet->id))->not->toBeNull();

    // a second call changes nothing
    expect(Bitcoin::convertToLight($wallet, BIP84_TEST_XPUB))->toBe(['matched' => 0, 'unmatched' => []]);
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

it('records history mined well before the wallet was added without calling the webhook', function () {
    $wallet = lightSetup();
    config()->set('bitcoin.webhook.handler', SpyBitcoinWebhook::class);
    config()->set('bitcoin.webhook.confirmations', 1);
    $wallet->update(['birth_height' => 900_000]);

    // old: 6+ blocks before the birth height — history
    addTx(str_repeat('a', 64), [[STRANGER, 150_000]], [[RECEIVE_0, 100_000]], 899_994);
    // within the margin (a miner's late timestamp does not matter, the height does) — new money
    addTx(str_repeat('b', 64), [[STRANGER, 150_000]], [[RECEIVE_1, 50_000]], 899_998);
    chainState()->tip = 900_010;

    syncWallet($wallet, full: true);

    $old = Deposit::where('txid', str_repeat('a', 64))->sole();
    $new = Deposit::where('txid', str_repeat('b', 64))->sole();

    expect($old->webhook_status)->toBe(2)
        ->and($old->webhook_data['skipped'] ?? null)->not->toBeNull()
        ->and(chainState()->handled)->toBe([$new->id]);
});

it('treats nothing as history when the birth height is unknown', function () {
    $wallet = lightSetup();
    config()->set('bitcoin.webhook.handler', SpyBitcoinWebhook::class);
    config()->set('bitcoin.webhook.confirmations', 1);
    $wallet->update(['birth_height' => null]);
    addTx(str_repeat('a', 64), [[STRANGER, 150_000]], [[RECEIVE_0, 100_000]], 100);

    syncWallet($wallet, full: true);

    expect(chainState()->handled)->toBe([Deposit::sole()->id]);
});

it('stores the chain height when a wallet is created', function () {
    lightSetup();
    chainState()->tip = 912_345;

    expect(Bitcoin::createLightWalletFromXpub('w2', BIP84_TEST_XPUB)->birth_height)->toBe(912_345);

    expect(Bitcoin::createLightWalletFromXpub('w3', BIP84_TEST_XPUB, birthHeight: 0)->birth_height)->toBe(0);

    chainState()->down = ['mempool.test', 'blockstream.test'];
    \Illuminate\Support\Facades\Cache::flush();

    expect(fn () => Bitcoin::createLightWalletFromXpub('w4', BIP84_TEST_XPUB))->toThrow(ProviderException::class)
        ->and(BitcoinWallet::where('name', 'w4')->exists())->toBeFalse();
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
    $wallet->update(['gap_scan_at' => now()]);
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
