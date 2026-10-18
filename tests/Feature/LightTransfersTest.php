<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use ItHealer\LaravelBitcoin\BIP39\BIP39;
use ItHealer\LaravelBitcoin\BIP39\Language\English;
use ItHealer\LaravelBitcoin\Facades\Bitcoin;
use ItHealer\LaravelBitcoin\Light\Tx\Transaction;
use ItHealer\LaravelBitcoin\Models\BitcoinWallet;

uses(RefreshDatabase::class);

function transferSeed(): string
{
    return bin2hex(BIP39::fromWords(explode(' ', LIGHT_MNEMONIC), English::getInstance())->generateSeed(''));
}

function fundReceive0(int ...$values): void
{
    $utxos = chainState()->utxos;
    foreach ($values as $i => $value) {
        $utxos[RECEIVE_0][] = ['txid' => str_pad(dechex(count($utxos[RECEIVE_0] ?? []) + 1), 64, 'c', STR_PAD_LEFT), 'vout' => $i, 'value' => $value, 'status' => ['confirmed' => true, 'block_height' => 899_000]];
    }
    chainState()->utxos = $utxos;
}

function sendSetup(): BitcoinWallet
{
    $wallet = lightSetup();
    Bitcoin::createAddress($wallet);

    return $wallet->fresh();
}

it('previews a transfer with the recommended fee rate and the spendable balance', function () {
    $wallet = sendSetup();
    fundReceive0(100_000);

    $preview = Bitcoin::previewMassPSBT($wallet, RECEIVE_0, [['address' => STRANGER, 'amount' => '0.0005']]);

    expect($preview['feeRate'])->toBe(10.0)
        ->and($preview['fee'])->toBe('0.00001410')
        ->and($preview['beforeBalance'])->toBe('0.00100000')
        ->and($preview['afterBalance'])->toBe('0.00048590')
        ->and($preview['totalAmount'])->toBe('0.00050000')
        ->and($preview)->not->toHaveKey('plan')
        ->and(chainState()->broadcasts)->toBe([]);
});

it('sends: signs, broadcasts to every provider, records the transfer and lowers the balance', function () {
    $wallet = sendSetup();
    $wallet->update(['balance' => '0.001']);
    $wallet->addresses()->where('address', RECEIVE_0)->update(['balance' => '0.001']);
    fundReceive0(100_000);

    // by reference: through the facade the preview would not come back
    $transfers = app(\ItHealer\LaravelBitcoin\Bitcoin::class)->sendMassPSBT($wallet, RECEIVE_0, [['address' => STRANGER, 'amount' => '0.0005', 'comment' => 'rent']], preview: $preview, seedHex: transferSeed());

    $broadcast = chainState()->broadcasts;
    $tx = Transaction::fromHex($broadcast[0]['hex']);

    expect($broadcast)->toHaveCount(2)
        ->and(array_column($broadcast, 'host'))->toBe(['mempool.test', 'blockstream.test'])
        ->and($transfers)->toHaveCount(1)
        ->and($transfers[0]->txid)->toBe($tx->txid())
        ->and($transfers[0]->receiver_address)->toBe(STRANGER)
        ->and($transfers[0]->comment)->toBe('rent')
        ->and(btc8($transfers[0]->amount))->toBe('0.00050000')
        ->and($wallet->transactions()->sole())->direction->toBe('out')
        ->and(btc8($wallet->transactions()->sole()->amount))->toBe('-0.00051410')
        ->and(btc8($wallet->fresh()->balance))->toBe('0.00048590')
        ->and(btc8($wallet->addresses()->where('address', RECEIVE_0)->value('balance')))->toBe('0.00048590')
        ->and($preview['fee'])->toBe('0.00001410');
});

it('does not spend the same coins twice before the providers see the first send', function () {
    $wallet = sendSetup();
    fundReceive0(100_000, 60_000);

    Bitcoin::sendMassPSBT($wallet, RECEIVE_0, [['address' => STRANGER, 'amount' => '0.0005']], seedHex: transferSeed());
    $first = Transaction::fromHex(chainState()->broadcasts[0]['hex']);

    // the provider still lists the spent coin; the second send must use the other one
    Bitcoin::sendMassPSBT($wallet, RECEIVE_0, [['address' => STRANGER, 'amount' => '0.0002']], seedHex: transferSeed());
    $second = Transaction::fromHex(chainState()->broadcasts[2]['hex']);

    expect($first->inputs[0]['vout'])->toBe(0)
        ->and($second->inputs[0]['vout'])->toBe(1)
        ->and(fn () => Bitcoin::sendMassPSBT($wallet, RECEIVE_0, [['address' => STRANGER, 'amount' => '0.0002']], seedHex: transferSeed()))
        ->toThrow(RuntimeException::class);
});

it('spends confirmed coins and own unconfirmed change, not someone else\'s unconfirmed coins', function () {
    $wallet = sendSetup();
    $utxos = [
        ['txid' => str_repeat('1', 64), 'vout' => 0, 'value' => 40_000, 'status' => ['confirmed' => true]],
        ['txid' => str_repeat('2', 64), 'vout' => 0, 'value' => 500_000, 'status' => ['confirmed' => false]],
        ['txid' => str_repeat('3', 64), 'vout' => 1, 'value' => 30_000, 'status' => ['confirmed' => false]],
    ];
    chainState()->utxos = [RECEIVE_0 => $utxos];
    $wallet->transactions()->create(['txid' => str_repeat('3', 64), 'direction' => 'out', 'amount' => '-0.0001']);

    $preview = Bitcoin::previewMassPSBT($wallet, RECEIVE_0, [['address' => STRANGER, 'amount' => '0.0006']]);

    expect($preview['beforeBalance'])->toBe('0.00070000')
        ->and(array_column($preview['inputs'], 'txid'))->toBe([str_repeat('1', 64), str_repeat('3', 64)]);
});

it('sends the whole balance with the fee taken from it', function () {
    $wallet = sendSetup();
    fundReceive0(100_000, 20_000);

    $transfer = Bitcoin::sendPSBT($wallet, RECEIVE_0, STRANGER, null, seedHex: transferSeed());
    $tx = Transaction::fromHex(chainState()->broadcasts[0]['hex']);

    // 2 inputs, 1 output: 10.5 + 136 + 31 = 177.5 → 178 vB × 10
    expect($tx->outputs)->toHaveCount(1)
        ->and($tx->outputs[0]['value'])->toBe(120_000 - 1780)
        ->and(btc8($transfer->amount))->toBe('0.00118220');
});

it('previews the old single-recipient shape for swaps, with OP_RETURN data', function () {
    $wallet = sendSetup();
    fundReceive0(100_000);

    $preview = Bitcoin::previewPSBT($wallet, RECEIVE_0, STRANGER, '0.0005', data: bin2hex('=:ETH.ETH:0xabc'));

    expect($preview)->toHaveKeys(['from', 'to', 'amount', 'fee', 'beforeBalance', 'afterBalance', 'inputs'])
        ->and($preview['to'])->toBe(STRANGER)
        ->and($preview['amount'])->toBe('0.00050000');
});

it('refuses to send without the seed, with a fee rate above the limit, or from a foreign address', function () {
    $wallet = sendSetup();
    fundReceive0(100_000);
    $other = Bitcoin::createLightWalletFromXpub('other', BIP84_TEST_XPUB, birthHeight: 0);
    $foreign = Bitcoin::createAddress($other);

    expect(fn () => Bitcoin::sendMassPSBT($wallet, RECEIVE_0, [['address' => STRANGER, 'amount' => '0.0005']]))->toThrow(RuntimeException::class, 'seed')
        ->and(fn () => Bitcoin::previewMassPSBT($wallet, RECEIVE_0, [['address' => STRANGER, 'amount' => '0.0005']], feeRate: 5000))->toThrow(RuntimeException::class, 'limit')
        ->and(fn () => Bitcoin::previewMassPSBT($wallet, $foreign, [['address' => STRANGER, 'amount' => '0.0005']]))->toThrow(InvalidArgumentException::class)
        ->and(chainState()->broadcasts)->toBe([]);
});

it('fails before broadcasting when the signer refuses', function () {
    $wallet = sendSetup();
    fundReceive0(100_000);
    $otherSeed = bin2hex(random_bytes(64));

    expect(fn () => Bitcoin::sendMassPSBT($wallet, RECEIVE_0, [['address' => STRANGER, 'amount' => '0.0005']], seedHex: $otherSeed))
        ->toThrow(RuntimeException::class, 'does not belong')
        ->and(chainState()->broadcasts)->toBe([])
        ->and($wallet->transfers()->count())->toBe(0);
});

it('does not broadcast what a compromised signer returns instead of the planned transaction', function () {
    $wallet = sendSetup();
    fundReceive0(100_000);
    config()->set('bitcoin.light.signer_command', [PHP_BINARY, __DIR__.'/../Support/evil-signer.php']);

    expect(fn () => Bitcoin::sendMassPSBT($wallet, RECEIVE_0, [['address' => STRANGER, 'amount' => '0.0005']], seedHex: transferSeed()))
        ->toThrow(RuntimeException::class, 'differs from the planned one')
        ->and(chainState()->broadcasts)->toBe([])
        ->and($wallet->transfers()->count())->toBe(0);
});

it('exposes the recommended fee rates', function () {
    sendSetup();

    expect(Bitcoin::lightFeeRates())->toBe(['fastest' => 20.0, 'half_hour' => 10.0, 'hour' => 5.0, 'economy' => 2.0, 'minimum' => 1.0]);
});

it('records the send when the broadcast answer was lost but a provider has the transaction', function () {
    $wallet = sendSetup();
    fundReceive0(100_000);
    chainState()['broadcast'] = 'lost';

    $transfers = Bitcoin::sendMassPSBT($wallet, RECEIVE_0, [['address' => STRANGER, 'amount' => '0.0005']], seedHex: transferSeed());

    expect($transfers)->toHaveCount(1)
        ->and($wallet->transactions()->where('txid', $transfers[0]->txid)->exists())->toBeTrue()
        ->and(fn () => Bitcoin::previewMassPSBT($wallet, RECEIVE_0, [['address' => STRANGER, 'amount' => '0.0001']]))
        ->toThrow(RuntimeException::class); // the only coin is reserved
});

it('keeps the coins reserved for a while after a rejected broadcast, then frees them', function () {
    $wallet = sendSetup();
    fundReceive0(100_000);
    chainState()['broadcast'] = 'rejected';

    expect(fn () => Bitcoin::sendMassPSBT($wallet, RECEIVE_0, [['address' => STRANGER, 'amount' => '0.0005']], seedHex: transferSeed()))
        ->toThrow(RuntimeException::class, 'not accepted')
        ->and($wallet->transfers()->count())->toBe(0)
        ->and(fn () => Bitcoin::previewMassPSBT($wallet, RECEIVE_0, [['address' => STRANGER, 'amount' => '0.0001']]))
        ->toThrow(RuntimeException::class);

    $this->travel(11)->minutes();

    expect(Bitcoin::previewMassPSBT($wallet, RECEIVE_0, [['address' => STRANGER, 'amount' => '0.0001']])['beforeBalance'])->toBe('0.00100000');
});

it('reports the status of a transaction from the providers', function () {
    lightSetup();
    addTx(str_repeat('a', 64), [[STRANGER, 1000]], [[RECEIVE_0, 900]], 899_998);
    addTx(str_repeat('b', 64), [[STRANGER, 1000]], [[RECEIVE_0, 900]]);

    expect(Bitcoin::lightTransactionStatus(str_repeat('a', 64)))->toBe(['confirmed' => true, 'block_height' => 899_998, 'confirmations' => 3])
        ->and(Bitcoin::lightTransactionStatus(str_repeat('b', 64)))->toBe(['confirmed' => false, 'block_height' => null, 'confirmations' => 0])
        ->and(Bitcoin::lightTransactionStatus(str_repeat('c', 64)))->toBeNull()
        ->and(fn () => Bitcoin::lightTransactionStatus('../etc'))->toThrow(InvalidArgumentException::class);
});
