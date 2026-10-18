<?php

use ItHealer\LaravelBitcoin\BIP39\BIP39;
use ItHealer\LaravelBitcoin\BIP39\Language\English;
use ItHealer\LaravelBitcoin\Light\Encoding\Base58;
use ItHealer\LaravelBitcoin\Light\Encoding\Bech32;
use ItHealer\LaravelBitcoin\Light\Keys\ExtendedKey;

const BIP84_MNEMONIC = 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';
const BIP84_ACCOUNT_XPUB = 'xpub6CatWdiZiodmUeTDp8LT5or8nmbKNcuyvz7WyksVFkKB4RHwCD3XyuvPEbvqAQY3rAPshWcMLoP2fMFMKHPJ4ZeZXYVUhLv1VMrjPC7PW6V';
const BIP84_ACCOUNT_ZPUB = 'zpub6rFR7y4Q2AijBEqTUquhVz398htDFrtymD9xYYfG1m4wAcvPhXNfE3EfH1r1ADqtfSdVCToUG868RvUUkgDKf31mGDtKsAYz2oz2AGutZYs';

function bip84Seed(): string
{
    return BIP39::fromWords(explode(' ', BIP84_MNEMONIC), English::getInstance())->generateSeed('');
}

/* ---------- BIP32 test vector 1 ---------- */

it('derives the BIP32 test vector 1 chain', function (string $path, string $xpub, ?string $xprv) {
    $key = ExtendedKey::fromSeed(hex2bin('000102030405060708090a0b0c0d0e0f'))->derivePath($path);

    expect($key->toXpub())->toBe($xpub);

    if ($xprv !== null) {
        expect($key->toXprv())->toBe($xprv);
    }
})->with([
    'm' => ['m', 'xpub661MyMwAqRbcFtXgS5sYJABqqG9YLmC4Q1Rdap9gSE8NqtwybGhePY2gZ29ESFjqJoCu1Rupje8YtGqsefD265TMg7usUDFdp6W1EGMcet8', 'xprv9s21ZrQH143K3QTDL4LXw2F7HEK3wJUD2nW2nRk4stbPy6cq3jPPqjiChkVvvNKmPGJxWUtg6LnF5kejMRNNU3TGtRBeJgk33yuGBxrMPHi'],
    'm/0H' => ["m/0'", 'xpub68Gmy5EdvgibQVfPdqkBBCHxA5htiqg55crXYuXoQRKfDBFA1WEjWgP6LHhwBZeNK1VTsfTFUHCdrfp1bgwQ9xv5ski8PX9rL2dZXvgGDnw', null],
    'm/0H/1' => ["m/0'/1", 'xpub6ASuArnXKPbfEwhqN6e3mwBcDTgzisQN1wXN9BJcM47sSikHjJf3UFHKkNAWbWMiGj7Wf5uMash7SyYq527Hqck2AxYysAA7xmALppuCkwQ', null],
    'm/0H/1/2H' => ["m/0'/1/2'", 'xpub6D4BDPcP2GT577Vvch3R8wDkScZWzQzMMUm3PWbmWvVJrZwQY4VUNgqFJPMM3No2dFDFGTsxxpG5uJh7n7epu4trkrX7x7DogT5Uv6fcLW5', null],
    'm/0H/1/2H/2' => ["m/0'/1/2'/2", 'xpub6FHa3pjLCk84BayeJxFW2SP4XRrFd1JYnxeLeU8EqN3vDfZmbqBqaGJAyiLjTAwm6ZLRQUMv1ZACTj37sR62cfN7fe5JnJ7dh8zL4fiyLHV', null],
    'm/0H/1/2H/2/1000000000' => ["m/0'/1/2'/2/1000000000", 'xpub6H1LXWLaKsWFhvm6RVpEL9P4KfRZSW7abD2ttkWP3SSQvnyA8FSVqNTEcYFgJS2UaFcxupHiYkro49S8yGasTvXEYBVPamhGW6cFJodrTHy', null],
]);

it('gets the same child from the public key as from the private key', function () {
    $parent = ExtendedKey::fromSeed(hex2bin('000102030405060708090a0b0c0d0e0f'))->derivePath("m/0'");
    $fromXpub = ExtendedKey::fromPublicString($parent->toXpub());

    expect($fromXpub->hasPrivateKey())->toBeFalse()
        ->and($fromXpub->deriveChild(1)->toXpub())->toBe($parent->deriveChild(1)->toXpub())
        ->and($fromXpub->derivePath('m/1/2')->toXpub())->toBe($parent->derivePath('m/1/2')->toXpub());
});

it('refuses hardened derivation without the private key', function () {
    expect(fn () => ExtendedKey::fromPublicString(BIP84_ACCOUNT_XPUB)->derivePath("m/0'"))->toThrow(LogicException::class);
});

/* ---------- BIP84 test vectors ---------- */

it('derives the BIP84 account key from the mnemonic', function () {
    $account = ExtendedKey::fromSeed(bip84Seed())->derivePath("m/84'/0'/0'")->neutered();

    expect($account->toXpub())->toBe(BIP84_ACCOUNT_XPUB)
        ->and($account->hasPrivateKey())->toBeFalse();
});

it('derives the BIP84 receive and change addresses from the account xpub and zpub', function (string $encoded) {
    $account = ExtendedKey::fromPublicString($encoded);

    expect($account->derivePath('m/0/0')->p2wpkhAddress())->toBe('bc1qcr8te4kr609gcawutmrza0j4xv80jy8z306fyu')
        ->and($account->derivePath('m/0/1')->p2wpkhAddress())->toBe('bc1qnjg0jd8228aq7egyzacy8cys3knf9xvrerkf9g')
        ->and($account->derivePath('m/1/0')->p2wpkhAddress())->toBe('bc1q8c6fshw2dlwun7ekn9qwf37cu2rn755upcp6el');
})->with(['xpub' => BIP84_ACCOUNT_XPUB, 'zpub' => BIP84_ACCOUNT_ZPUB]);

it('rejects a damaged or non-public extended key', function (string $encoded) {
    expect(fn () => ExtendedKey::fromPublicString($encoded))->toThrow(InvalidArgumentException::class);
})->with([
    'checksum' => [substr(BIP84_ACCOUNT_XPUB, 0, -1).'W'],
    'xprv' => ['xprv9s21ZrQH143K3QTDL4LXw2F7HEK3wJUD2nW2nRk4stbPy6cq3jPPqjiChkVvvNKmPGJxWUtg6LnF5kejMRNNU3TGtRBeJgk33yuGBxrMPHi'],
    'garbage' => ['0OIl'],
]);

/* ---------- Bech32 / Bech32m (BIP173, BIP350) ---------- */

it('decodes valid segwit addresses', function (string $address, int $version, string $program) {
    [$decodedVersion, $decodedProgram] = Bech32::decodeSegwit('bc', $address);

    expect($decodedVersion)->toBe($version)
        ->and(bin2hex($decodedProgram))->toBe($program);
})->with([
    'v0 upper case' => ['BC1QW508D6QEJXTDG4Y5R3ZARVARY0C5XW7KV8F3T4', 0, '751e76e8199196d454941c45d1b3a323f1433bd6'],
    'v1 taproot' => ['bc1p0xlxvlhemja6c4dqv22uapctqupfhlxm9h8z3k2e72q4k9hcz7vqzk5jj0', 1, '79be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798'],
]);

it('rejects invalid segwit addresses', function (string $address) {
    expect(fn () => Bech32::decodeSegwit('bc', $address))->toThrow(InvalidArgumentException::class);
})->with([
    'v1 with bech32 checksum' => ['bc1p0xlxvlhemja6c4dqv22uapctqupfhlxm9h8z3k2e72q4k9hcz7vqh2y7hd'],
    'v0 with bech32m checksum' => ['bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kemeawh'],
    'mixed case' => ['bc1QW508D6QEJXTDG4Y5R3ZARVARY0C5XW7KV8F3T4'],
    'testnet prefix' => ['tb1qw508d6qejxtdg4y5r3zarvary0c5xw7kxpjzsx'],
    'bad checksum' => ['bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t5'],
]);

it('round-trips a segwit address', function () {
    $program = hex2bin('751e76e8199196d454941c45d1b3a323f1433bd6');

    expect(Bech32::encodeSegwit('bc', 0, $program))->toBe('bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4');
});

it('keeps leading zero bytes in Base58Check', function () {
    $payload = "\0\0".random_bytes(20);

    expect(Base58::decodeCheck(Base58::encodeCheck($payload)))->toBe($payload);
});
