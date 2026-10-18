<?php

namespace ItHealer\LaravelBitcoin\Light\Api;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Esplora HTTP API (mempool.space/api, blockstream.info/api).
 * https://github.com/Blockstream/esplora/blob/master/API.md
 *
 * Amounts are in satoshi. A 429, a 5xx or no answer throws ProviderException, so the pool
 * can ask the next provider; a 4xx is returned to the caller as RuntimeException.
 */
class EsploraApi
{
    protected bool $requested = false;

    public function __construct(
        protected readonly string $baseUrl,
        protected readonly ?string $proxy = null,
        protected readonly ?Closure $onRequest = null,
    ) {
    }

    /**
     * @return array{chain_stats: array<string, int>, mempool_stats: array<string, int>}
     */
    public function address(string $address): array
    {
        return $this->json('get', 'address/'.$address);
    }

    /**
     * Unconfirmed transactions and the newest 25 confirmed ones; with `$afterTxid` the next
     * 25 confirmed ones after that transaction.
     *
     * @return list<array<string, mixed>>
     */
    public function addressTransactions(string $address, ?string $afterTxid = null): array
    {
        return $this->json('get', 'address/'.$address.'/txs'.($afterTxid ? '/chain/'.$afterTxid : ''));
    }

    /**
     * @return list<array{txid: string, vout: int, value: int, status: array<string, mixed>}>
     */
    public function addressUtxos(string $address): array
    {
        return $this->json('get', 'address/'.$address.'/utxo');
    }

    /**
     * @return array<string, mixed>
     */
    public function transaction(string $txid): array
    {
        return $this->json('get', 'tx/'.$txid);
    }

    /**
     * Null when the provider does not know the transaction (never seen, or dropped from
     * the mempool).
     *
     * @return array{confirmed: bool, block_height?: int, block_time?: int}|null
     */
    public function transactionStatus(string $txid): ?array
    {
        $response = $this->send('get', 'tx/'.$txid.'/status');

        if ($response->status() === 404 || $response->status() === 400) {
            return null;
        }

        return $this->decode($response);
    }

    public function transactionHex(string $txid): string
    {
        return trim($this->body($this->send('get', 'tx/'.$txid.'/hex')));
    }

    public function tipHeight(): int
    {
        $height = trim($this->body($this->send('get', 'blocks/tip/height')));

        if (!ctype_digit($height)) {
            throw new ProviderException('Unexpected tip height: '.substr($height, 0, 100));
        }

        return (int)$height;
    }

    /**
     * Recommended fee rates in sat/vB: fastest, half hour, hour, economy, minimum.
     * mempool.space has /v1/fees/recommended, plain Esplora only /fee-estimates (target
     * blocks → rate), which is mapped to the same keys.
     *
     * @return array{fastest: float, half_hour: float, hour: float, economy: float, minimum: float}
     */
    public function feeRates(): array
    {
        $response = $this->send('get', 'v1/fees/recommended');

        if ($response->successful() && is_array($data = $response->json()) && isset($data['fastestFee'])) {
            return [
                'fastest' => (float)$data['fastestFee'],
                'half_hour' => (float)$data['halfHourFee'],
                'hour' => (float)$data['hourFee'],
                'economy' => (float)($data['economyFee'] ?? $data['hourFee']),
                'minimum' => (float)($data['minimumFee'] ?? 1),
            ];
        }

        $estimates = $this->json('get', 'fee-estimates');
        $rate = fn (int $blocks): float => (float)($estimates[(string)$blocks] ?? $estimates['144'] ?? 1);

        return [
            'fastest' => $rate(1),
            'half_hour' => $rate(3),
            'hour' => $rate(6),
            'economy' => $rate(144),
            'minimum' => max(1.0, (float)min($estimates ?: [1])),
        ];
    }

    /**
     * Broadcasts a signed raw transaction and returns its txid.
     */
    public function broadcast(string $hex): string
    {
        $response = $this->send('post', 'tx', $hex);

        return trim($this->body($response));
    }

    protected function json(string $method, string $path): array
    {
        return $this->decode($this->send($method, $path));
    }

    protected function decode(Response $response): array
    {
        $data = json_decode($this->body($response), true);

        if (!is_array($data)) {
            throw new ProviderException('Unexpected answer: '.substr($response->body(), 0, 200));
        }

        return $data;
    }

    protected function body(Response $response): string
    {
        if ($response->successful()) {
            return $response->body();
        }

        $message = 'HTTP '.$response->status().': '.substr(trim($response->body()), 0, 300);

        if ($response->status() === 429 || $response->serverError()) {
            throw new ProviderException($message);
        }

        throw new \RuntimeException($message);
    }

    protected function send(string $method, string $path, ?string $body = null): Response
    {
        // Public APIs answer 429 to bursts: a short pause between requests of one provider
        $pause = (int)config('bitcoin.light.request_pause_ms', 100);
        if ($this->requested && $pause > 0) {
            usleep($pause * 1000);
        }
        $this->requested = true;

        if ($this->onRequest) {
            ($this->onRequest)();
        }

        try {
            $request = $this->client();

            return $body !== null
                ? $request->withBody($body, 'text/plain')->send($method, $path)
                : $request->send($method, $path);
        } catch (ConnectionException $e) {
            throw new ProviderException($e->getMessage(), previous: $e);
        }
    }

    protected function client(): PendingRequest
    {
        $client = Http::baseUrl(rtrim($this->baseUrl, '/').'/')
            ->timeout((int)config('bitcoin.light.timeout', 20))
            ->connectTimeout(10);

        return $this->proxy ? $client->withOptions(['proxy' => $this->proxy]) : $client;
    }
}
