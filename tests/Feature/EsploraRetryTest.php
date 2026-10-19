<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use ItHealer\LaravelBitcoin\Light\Api\EsploraApi;
use ItHealer\LaravelBitcoin\Light\Api\ProviderException;

beforeEach(function () {
    config()->set('bitcoin.light.retry_sleep_ms', 0);
    config()->set('bitcoin.light.request_pause_ms', 0);
});

it('repeats a request whose DNS lookup timed out and counts it once', function () {
    $counted = 0;

    Http::fakeSequence('esplora.test*')
        ->pushFailedConnection('cURL error 28: Resolving timed out after 10000 milliseconds')
        ->pushFailedConnection('cURL error 6: Could not resolve host: esplora.test')
        ->push('870000');

    $api = new EsploraApi('https://esplora.test/api', onRequest: function () use (&$counted) {
        $counted++;
    });

    expect($api->tipHeight())->toBe(870000)
        ->and($counted)->toBe(1);
    Http::assertSentCount(3);
});

it('gives up after the configured retries so the next provider is tried', function () {
    config()->set('bitcoin.light.retries', 1);

    Http::fakeSequence('esplora.test*')
        ->pushFailedConnection('cURL error 7: Failed to connect to esplora.test port 443')
        ->pushFailedConnection('cURL error 7: Failed to connect to esplora.test port 443')
        ->push('870000');

    expect(fn () => (new EsploraApi('https://esplora.test/api'))->tipHeight())->toThrow(ProviderException::class);
    Http::assertSentCount(2);
});

it('repeats a broadcast that never reached the provider', function () {
    $txid = str_repeat('ab', 32);

    Http::fakeSequence('esplora.test*')
        ->pushFailedConnection('cURL error 28: Connection timed out after 20001 milliseconds')
        ->push($txid);

    expect((new EsploraApi('https://esplora.test/api'))->broadcast('0200'))->toBe($txid);
    Http::assertSentCount(2);
});

it('does not repeat a broadcast that may have reached the provider', function () {
    Http::fakeSequence('esplora.test*')
        ->pushFailedConnection('cURL error 28: Operation timed out after 20000 milliseconds with 0 bytes received')
        ->push(str_repeat('ab', 32));

    expect(fn () => (new EsploraApi('https://esplora.test/api'))->broadcast('0200'))->toThrow(ProviderException::class);
    Http::assertSentCount(1);
});

it('does not repeat an answered error', function () {
    Http::fakeSequence('esplora.test*')
        ->push('Too Many Requests', 429)
        ->push('870000');

    expect(fn () => (new EsploraApi('https://esplora.test/api'))->tipHeight())->toThrow(ProviderException::class);
    Http::assertSentCount(1);
});

it('tells failures before connecting from the others', function (string $message, bool $before) {
    expect(EsploraApi::failedBeforeConnecting(new ConnectionException($message)))->toBe($before);
})->with([
    ['cURL error 28: Resolving timed out after 10000 milliseconds', true],
    ['cURL error 6: Could not resolve host: mempool.space', true],
    ['cURL error 7: Failed to connect to blockstream.info port 443 after 3 ms: Couldn\'t connect to server', true],
    ['cURL error 28: Connection timed out after 20001 milliseconds', true],
    ['cURL error 28: Operation timed out after 20000 milliseconds with 0 bytes received', false],
    ['cURL error 56: Recv failure: Connection reset by peer', false],
    ['cURL error 28: Operation timed out after 20000 ms (Connection timed out earlier)', false],
    ['proxy said: cURL error 7: Failed to connect', false],
]);
