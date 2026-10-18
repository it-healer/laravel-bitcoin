<?php

namespace ItHealer\LaravelBitcoin\Light\Api;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use ItHealer\LaravelBitcoin\Facades\Bitcoin;
use ItHealer\LaravelBitcoin\Models\BitcoinProvider;

/**
 * Asks the available providers in priority order: when one does not answer, rate-limits or
 * fails on its side (ProviderException) it is skipped for `cooldown_seconds` and the next one
 * is asked. Errors of the request itself (bad address, invalid transaction) are thrown at once.
 */
class ProviderPool
{
    /**
     * @template T
     * @param Closure(EsploraApi, BitcoinProvider): T $callback
     * @return T
     */
    public function call(Closure $callback): mixed
    {
        $last = null;

        foreach ($this->providers() as $provider) {
            try {
                return $callback($provider->api(), $provider);
            } catch (ProviderException $e) {
                $last = $e;
                $this->coolDown($provider, $e);
            }
        }

        throw new ProviderException(
            $last ? 'All Bitcoin providers failed, last: '.$last->getMessage() : 'No available Bitcoin providers.',
            previous: $last,
        );
    }

    /**
     * Height of the chain tip, cached for `tip_cache_seconds`.
     */
    public function tipHeight(): int
    {
        return (int)Cache::remember(
            'bitcoin:light:tip',
            (int)config('bitcoin.light.tip_cache_seconds', 30),
            fn (): int => $this->call(function (EsploraApi $api, BitcoinProvider $provider): int {
                $height = $api->tipHeight();
                $provider->update(['block_height' => $height, 'sync_at' => Date::now(), 'worked' => true]);

                return $height;
            }),
        );
    }

    /**
     * Sends the transaction through every available provider: it is out once at least one of
     * them accepted it (or already has it in the mempool).
     *
     * @return array{txid: string, accepted: list<string>, errors: array<string, string>}
     */
    public function broadcast(string $hex, string $expectedTxid): array
    {
        $accepted = [];
        $errors = [];

        foreach ($this->providers() as $provider) {
            try {
                $txid = $provider->api()->broadcast($hex);

                if (strcasecmp($txid, $expectedTxid) !== 0) {
                    $errors[$provider->name] = 'Unexpected txid '.$txid;
                    continue;
                }

                $accepted[] = $provider->name;
            } catch (\RuntimeException $e) {
                if (preg_match('/already (in|known)|txn-already|already have/i', $e->getMessage())) {
                    $accepted[] = $provider->name;
                    continue;
                }

                $errors[$provider->name] = $e->getMessage();

                if ($e instanceof ProviderException) {
                    $this->coolDown($provider, $e);
                }
            }
        }

        if ($accepted === []) {
            throw new \RuntimeException('The transaction was not accepted: '.implode('; ', array_map(
                fn (string $name, string $error) => $name.': '.$error,
                array_keys($errors),
                $errors,
            )));
        }

        return ['txid' => $expectedTxid, 'accepted' => $accepted, 'errors' => $errors];
    }

    /**
     * @return Collection<int, BitcoinProvider>
     */
    public function providers(): Collection
    {
        $model = Bitcoin::getModelProvider();

        $providers = $model::query()
            ->where('available', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        $ready = $providers->reject(fn (BitcoinProvider $provider) => Cache::has($this->cooldownKey($provider)));

        // All of them cooling down — still try them rather than fail without asking anyone
        return $ready->isNotEmpty() ? $ready->values() : $providers;
    }

    protected function coolDown(BitcoinProvider $provider, \Throwable $e): void
    {
        Cache::put($this->cooldownKey($provider), $e->getMessage(), (int)config('bitcoin.light.cooldown_seconds', 60));
        $provider->update(['worked' => false]);
    }

    protected function cooldownKey(BitcoinProvider $provider): string
    {
        return 'bitcoin:light:cooldown:'.$provider->getKey();
    }
}
