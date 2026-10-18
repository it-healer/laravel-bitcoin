<?php

use ItHealer\LaravelBitcoin\BIP39\BIP39;
use ItHealer\LaravelBitcoin\BIP39\Language\English;
use ItHealer\LaravelBitcoin\Light\Psbt\PsbtBuilder;
use ItHealer\LaravelBitcoin\Light\Psbt\Signer;
use ItHealer\LaravelBitcoin\Light\Tx\Script;
use ItHealer\LaravelBitcoin\Light\Tx\Transaction;

const SIGN_RECEIVE_0 = 'bc1qcr8te4kr609gcawutmrza0j4xv80jy8z306fyu';
const SIGN_RECEIVE_1 = 'bc1qnjg0jd8228aq7egyzacy8cys3knf9xvrerkf9g';
const SIGN_STRANGER = 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4';

function signSeed(): string
{
    return bin2hex(BIP39::fromWords(explode(' ', 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about'), English::getInstance())->generateSeed(''));
}

function utxo(int $value, string $address = SIGN_RECEIVE_0, string $path = "m/84'/0'/0'/0/0", ?string $txid = null): array
{
    static $n = 0;

    return ['txid' => $txid ?? str_pad(dechex(++$n), 64, 'a', STR_PAD_LEFT), 'vout' => 0, 'value' => $value, 'script' => Script::forAddress($address), 'path' => $path];
}

/* ---------- Building ---------- */

it('pays the recipient, returns the change and charges fee = rate × vsize', function () {
    $plan = PsbtBuilder::build([utxo(100_000)], [['address' => SIGN_STRANGER, 'amount' => '0.0005']], 10, SIGN_RECEIVE_0);

    // 1 input, recipient + change: 10.5 + 68 + 31 + 31 = 140.5 → 141 vB
    expect($plan['vsize'])->toBe(141)
        ->and($plan['fee'])->toBe(1410)
        ->and($plan['outputs'][0])->toMatchArray(['value' => 50_000, 'role' => 'recipient'])
        ->and($plan['outputs'][1])->toMatchArray(['value' => 100_000 - 50_000 - 1410, 'role' => 'change', 'address' => SIGN_RECEIVE_0]);
});

it('takes the largest coins first and only as many as needed', function () {
    $plan = PsbtBuilder::build([utxo(10_000), utxo(80_000), utxo(30_000)], [['address' => SIGN_STRANGER, 'amount' => '0.0009']], 1, SIGN_RECEIVE_0);

    expect(array_column($plan['inputs'], 'value'))->toBe([80_000, 30_000]);
});

it('gives a change below dust to the fee', function () {
    // 400 left: change would be 400 − 141 = 259 < 294 (dust), so all 400 go to the fee
    $plan = PsbtBuilder::build([utxo(50_400)], [['address' => SIGN_STRANGER, 'amount' => '0.0005']], 1, SIGN_RECEIVE_0);

    expect($plan['outputs'])->toHaveCount(1)
        ->and($plan['fee'])->toBe(400);

    // 500 left: change 359 is above dust and stays
    expect(PsbtBuilder::build([utxo(50_500)], [['address' => SIGN_STRANGER, 'amount' => '0.0005']], 1, SIGN_RECEIVE_0)['outputs'])->toHaveCount(2);
});

it('lets the first recipient pay the fee', function () {
    $plan = PsbtBuilder::build([utxo(100_000)], [['address' => SIGN_STRANGER, 'amount' => '0.001']], 10, SIGN_RECEIVE_0, subtractFeeFromFirst: true);

    // all coins, no change: 10.5 + 68 + 31 → 110 vB
    expect($plan['outputs'])->toHaveCount(1)
        ->and($plan['fee'])->toBe(1100)
        ->and($plan['outputs'][0]['value'])->toBe(100_000 - 1100);
});

it('keeps the order recipients, change, OP_RETURN', function () {
    $plan = PsbtBuilder::build([utxo(100_000)], [['address' => SIGN_STRANGER, 'amount' => '0.0005']], 2, SIGN_RECEIVE_0, data: '=:ETH.ETH:0xabc');

    expect(array_column($plan['outputs'], 'role'))->toBe(['recipient', 'change', 'data'])
        ->and($plan['outputs'][2]['value'])->toBe(0)
        ->and($plan['outputs'][2]['script'])->toBe(Script::opReturn('=:ETH.ETH:0xabc'));
});

it('refuses what it cannot build', function (array $utxos, array $recipients, string $error) {
    expect(fn () => PsbtBuilder::build($utxos, $recipients, 5, SIGN_RECEIVE_0))->toThrow(Exception::class, $error);
})->with([
    'not enough' => [[utxo(10_000)], [['address' => SIGN_STRANGER, 'amount' => '0.001']], 'Not enough funds'],
    'fee not covered' => [[utxo(50_000)], [['address' => SIGN_STRANGER, 'amount' => '0.0005']], 'Not enough funds'],
    'dust' => [[utxo(10_000)], [['address' => SIGN_STRANGER, 'amount' => '0.000001']], 'dust'],
    'nine decimals' => [[utxo(10_000)], [['address' => SIGN_STRANGER, 'amount' => '0.000010001']], '8 decimals'],
    'bad address' => [[utxo(10_000)], [['address' => 'bc1qnotanaddress', 'amount' => '0.00001']], ''],
]);

it('serializes a PSBT with the unsigned transaction and the spent outputs', function () {
    $plan = PsbtBuilder::build([utxo(100_000)], [['address' => SIGN_STRANGER, 'amount' => '0.0005']], 2, SIGN_RECEIVE_0);
    $psbt = base64_decode($plan['psbt']);

    expect(substr($psbt, 0, 5))->toBe("psbt\xff")
        ->and(str_contains($psbt, pack('P', 100_000)."\x16".Script::forAddress(SIGN_RECEIVE_0)))->toBeTrue();
});

/* ---------- Signing (real signer, node) ---------- */

it('signs with the bundled signer and passes the independent check', function () {
    $plan = PsbtBuilder::build([utxo(100_000), utxo(60_000, SIGN_RECEIVE_1, "m/84'/0'/0'/0/1")], [['address' => SIGN_STRANGER, 'amount' => '0.0015']], 3, SIGN_RECEIVE_0);

    $signed = Signer::sign($plan, signSeed());
    $tx = Transaction::fromHex($signed['hex']);

    expect($signed['txid'])->toBe($plan['txid'])
        ->and($tx->inputs)->toHaveCount(2)
        ->and(array_column($tx->inputs, 'sequence'))->toBe([PsbtBuilder::SEQUENCE_RBF, PsbtBuilder::SEQUENCE_RBF])
        // the real size (weight = base × 3 + total) never exceeds the estimate the fee was paid for
        ->and((int)ceil((strlen($tx->serializeLegacy()) * 3 + strlen(hex2bin($signed['hex']))) / 4))->toBeLessThanOrEqual($plan['vsize']);
});

it('refuses to sign an input that is not of the wallet seed', function () {
    $plan = PsbtBuilder::build([utxo(100_000, SIGN_STRANGER, "m/84'/0'/0'/0/0")], [['address' => SIGN_RECEIVE_1, 'amount' => '0.0005']], 2, SIGN_RECEIVE_0);

    expect(fn () => Signer::sign($plan, signSeed()))->toThrow(RuntimeException::class, 'does not belong');
});

it('refuses a path that points to another key of the seed', function () {
    $plan = PsbtBuilder::build([utxo(100_000, SIGN_RECEIVE_0, "m/84'/0'/0'/0/7")], [['address' => SIGN_STRANGER, 'amount' => '0.0005']], 2, SIGN_RECEIVE_0);

    expect(fn () => Signer::sign($plan, signSeed()))->toThrow(RuntimeException::class, 'does not belong');
});

it('rejects a signed transaction that differs from the plan', function () {
    $plan = PsbtBuilder::build([utxo(100_000)], [['address' => SIGN_STRANGER, 'amount' => '0.0005']], 2, SIGN_RECEIVE_0);
    $signed = Signer::sign($plan, signSeed());

    $other = $plan;
    $other['outputs'][0]['value'] += 1;
    expect(fn () => Signer::verify($other, $signed['hex']))->toThrow(RuntimeException::class);

    // a valid transaction, but of another plan: the txid does not match
    $another = PsbtBuilder::build([utxo(90_000)], [['address' => SIGN_STRANGER, 'amount' => '0.0005']], 2, SIGN_RECEIVE_0);
    expect(fn () => Signer::verify($another, $signed['hex']))->toThrow(RuntimeException::class, 'txid');
});

it('detects a broken signature', function () {
    $plan = PsbtBuilder::build([utxo(100_000)], [['address' => SIGN_STRANGER, 'amount' => '0.0005']], 2, SIGN_RECEIVE_0);
    $hex = Signer::sign($plan, signSeed())['hex'];

    // flip one byte inside the DER signature (r) of the only input
    $tx = Transaction::fromHex($hex);
    $signature = $tx->inputs[0]['witness'][0];
    $broken = substr_replace($hex, bin2hex(chr(ord($signature[10]) ^ 1)), strpos($hex, bin2hex($signature)) + 20, 2);

    expect(fn () => Signer::verify($plan, $broken))->toThrow(RuntimeException::class, 'invalid signature');
});
