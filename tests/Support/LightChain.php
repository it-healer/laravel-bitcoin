<?php

/**
 * Shared helpers of the light-mode tests: a fake Esplora whose state is a set of transactions
 * (address stats and listings are computed from them like a real server does), UTXOs, fee
 * rates and broadcasts.
 */

use Illuminate\Support\Facades\Http;
use ItHealer\LaravelBitcoin\Facades\Bitcoin;
use ItHealer\LaravelBitcoin\Models\BitcoinProvider;
use ItHealer\LaravelBitcoin\Models\BitcoinWallet;

const LIGHT_MNEMONIC = 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';
const RECEIVE_0 = 'bc1qcr8te4kr609gcawutmrza0j4xv80jy8z306fyu';
const RECEIVE_1 = 'bc1qnjg0jd8228aq7egyzacy8cys3knf9xvrerkf9g';
const CHANGE_0 = 'bc1q8c6fshw2dlwun7ekn9qwf37cu2rn755upcp6el';
const BIP84_TEST_XPUB = 'xpub6CatWdiZiodmUeTDp8LT5or8nmbKNcuyvz7WyksVFkKB4RHwCD3XyuvPEbvqAQY3rAPshWcMLoP2fMFMKHPJ4ZeZXYVUhLv1VMrjPC7PW6V';
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
        $state = new ArrayObject(['txs' => [], 'tip' => 900_000, 'down' => [], 'handled' => [], 'utxos' => [], 'broadcasts' => [], 'fees' => ['fastestFee' => 20, 'halfHourFee' => 10, 'hourFee' => 5, 'economyFee' => 2, 'minimumFee' => 1]], ArrayObject::ARRAY_AS_PROPS);
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
            $path === 'tx' && $request->method() === 'POST' => (function () use ($request, $host) {
                chainState()->broadcasts = [...chainState()->broadcasts, ['host' => $host, 'hex' => $request->body()]];

                // 'lost': accepted, but the answer never arrives; 'rejected': refused
                if (($mode = chainState()['broadcast'] ?? null) !== null) {
                    if ($mode === 'lost') {
                        $txid = \ItHealer\LaravelBitcoin\Light\Tx\Transaction::fromHex($request->body())->txid();
                        $txs = chainState()->txs;
                        $txs[$txid] = ['txid' => $txid, 'vin' => [], 'vout' => [], 'fee' => 0, 'status' => ['confirmed' => false]];
                        chainState()->txs = $txs;

                        return Http::response('Gateway Timeout', 504);
                    }

                    return Http::response('sendrawtransaction RPC error: bad-txns-inputs-missingorspent', 400);
                }

                return Http::response(ctype_xdigit($request->body()) && strlen($request->body()) > 120
                    ? \ItHealer\LaravelBitcoin\Light\Tx\Transaction::fromHex($request->body())->txid()
                    : hash('sha256', $request->body()));
            })(),
            (bool)preg_match('#^address/([^/]+)/utxo$#', $path, $m) => Http::response(chainState()->utxos[$m[1]] ?? []),
            $path === 'v1/fees/recommended' => Http::response(chainState()->fees),
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

    // a clean start for each test: no cached tip, no counted requests, no history margin
    \Illuminate\Support\Facades\Cache::flush();
    BitcoinProvider::query()->update(['requests' => 0, 'block_height' => null]);
    $wallet->update(['birth_height' => 0]);

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
