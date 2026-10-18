<?php

use ItHealer\LaravelBitcoin\Light\Tx\Script;
use ItHealer\LaravelBitcoin\Light\Tx\Transaction;

/* BIP143 "Native P2WPKH" example */
const BIP143_UNSIGNED = '0100000002fff7f7881a8099afa6940d42d1e7f6362bec38171ea3edf433541db4e4ad969f0000000000eeffffffef51e1b804cc89d182d279655c3aa89e815b1b309fe287d9b2b55d57b90ec68a0100000000ffffffff02202cb206000000001976a9148280b37df378db99f66f85c95a783a76ac7a6d5988ac9093510d000000001976a9143bde42dbee7e4dbe6a21b2d50ce2f0167faa815988ac11000000';
const BIP143_SIGNED = '01000000000102fff7f7881a8099afa6940d42d1e7f6362bec38171ea3edf433541db4e4ad969f00000000494830450221008b9d1dc26ba6a9cb62127b02742fa9d754cd3bebf337f7a55d114c8e5cdd30be022040529b194ba3f9281a99f2b1c0a19c0489bc22ede944ccf4ecbab4cc618ef3ed01eeffffffef51e1b804cc89d182d279655c3aa89e815b1b309fe287d9b2b55d57b90ec68a0100000000ffffffff02202cb206000000001976a9148280b37df378db99f66f85c95a783a76ac7a6d5988ac9093510d000000001976a9143bde42dbee7e4dbe6a21b2d50ce2f0167faa815988ac000247304402203609e17b84f6a7d30c80bfa610b5b4542f32a8a0d5447a12fb1366d7f01cc44a0220573a954c4518331561406f90300e8f3358f51928d43c212a8caed02de67eebee0121025476c2e83188368da1ff3e292e7acafcdb3566bb0ad253f62fc70f07aeee635711000000';

it('parses and re-serializes a transaction without witnesses', function () {
    $tx = Transaction::fromHex(BIP143_UNSIGNED);

    expect($tx->version)->toBe(1)
        ->and($tx->locktime)->toBe(0x11)
        ->and($tx->inputs)->toHaveCount(2)
        ->and($tx->inputs[1]['vout'])->toBe(1)
        ->and($tx->outputs[0]['value'])->toBe(112_340_000)
        ->and(bin2hex($tx->serializeLegacy()))->toBe(BIP143_UNSIGNED);
});

it('parses a segwit transaction: the txid does not depend on the witness, witness items read', function () {
    $signed = Transaction::fromHex(BIP143_SIGNED);
    $stripped = clone $signed;
    $stripped->inputs = array_map(fn ($input) => [...$input, 'witness' => []], $signed->inputs);

    // input 0 is a legacy P2PK input: its scriptSig is part of the txid, the witness of input 1 is not
    expect($signed->txid())->toBe($stripped->txid())
        ->and($signed->txid())->toBe(Transaction::fromHex(bin2hex($signed->serializeLegacy()))->txid())
        ->and(strlen($signed->inputs[0]['script_sig']))->toBe(73)
        ->and($signed->inputs[1]['witness'])->toHaveCount(2)
        ->and(bin2hex($signed->inputs[1]['witness'][1]))->toBe('025476c2e83188368da1ff3e292e7acafcdb3566bb0ad253f62fc70f07aeee6357');
});

it('computes the BIP143 signature hash of a P2WPKH input', function () {
    $tx = Transaction::fromHex(BIP143_UNSIGNED);

    expect(bin2hex($tx->p2wpkhSighash(1, hex2bin('1d0f172a0ecb48aee1be1f2687d2963ae33f71a1'), 600_000_000)))
        ->toBe('c37af31116d1b27caf68aae9e3ac82f1477929014d5b917657d0eb49478cb670');
});

it('verifies the BIP143 example signature against that hash', function () {
    $signed = Transaction::fromHex(BIP143_SIGNED);
    [$signature, $pubkey] = $signed->inputs[1]['witness'];
    $sighash = $signed->p2wpkhSighash(1, hex2bin('1d0f172a0ecb48aee1be1f2687d2963ae33f71a1'), 600_000_000);
    $key = (new Elliptic\EC('secp256k1'))->keyFromPublic(bin2hex($pubkey), 'hex');

    expect($key->verify(bin2hex($sighash), bin2hex(substr($signature, 0, -1))))->toBeTrue()
        ->and($key->verify(bin2hex(str_repeat("\x01", 32)), bin2hex(substr($signature, 0, -1))))->toBeFalse();
});

it('rejects malformed or trailing transaction data', function (string $hex) {
    expect(fn () => Transaction::fromHex($hex))->toThrow(InvalidArgumentException::class);
})->with([
    'truncated' => [substr(BIP143_UNSIGNED, 0, 100)],
    'trailing' => [BIP143_UNSIGNED.'00'],
    'not hex' => ['zz'],
]);

it('builds output scripts of every address kind', function (string $address, string $script, string $kind) {
    expect(bin2hex(Script::forAddress($address)))->toBe($script)
        ->and(Script::parse($address)['kind'])->toBe($kind);
})->with([
    'p2wpkh' => ['bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4', '0014751e76e8199196d454941c45d1b3a323f1433bd6', 'p2wpkh'],
    'p2tr' => ['bc1p0xlxvlhemja6c4dqv22uapctqupfhlxm9h8z3k2e72q4k9hcz7vqzk5jj0', '512079be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798', 'p2tr'],
    'p2pkh' => ['1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2', '76a91477bff20c60e522dfaa3350c39b030a5d004e839a88ac', 'p2pkh'],
    'p2sh' => ['3J98t1WpEZ73CNmQviecrnyiWrnqRhWNLy', 'a914b472a266d0bd89c13706a4132ccfb16f7c3b9fcb87', 'p2sh'],
]);

it('builds OP_RETURN scripts up to 80 bytes', function () {
    expect(bin2hex(Script::opReturn('hi')))->toBe('6a026869')
        ->and(bin2hex(substr(Script::opReturn(str_repeat('a', 80)), 0, 3)))->toBe('6a4c50')
        ->and(fn () => Script::opReturn(str_repeat('a', 81)))->toThrow(InvalidArgumentException::class);
});
