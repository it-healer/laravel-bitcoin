<?php

namespace ItHealer\LaravelBitcoin\Light;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Date;
use ItHealer\LaravelBitcoin\Enums\AddressType;
use ItHealer\LaravelBitcoin\Light\Api\EsploraApi;
use ItHealer\LaravelBitcoin\Light\Api\ProviderPool;
use ItHealer\LaravelBitcoin\Light\Keys\ExtendedKey;
use ItHealer\LaravelBitcoin\Models\BitcoinAddress;
use ItHealer\LaravelBitcoin\Models\BitcoinWallet;

/**
 * Addresses of a light wallet, derived from its account xpub: chain 0 — receive, chain 1 —
 * change. `gap_limit` unused addresses are kept ahead on each chain so funds sent to an
 * address handed out by another wallet app with the same mnemonic are still found. Addresses
 * that were never handed out are hidden (available = false, issued_at = null).
 */
class AddressBook
{
    public const RECEIVE = 0;

    public const CHANGE = 1;

    public function __construct(protected readonly BitcoinWallet $wallet)
    {
    }

    /**
     * Derives missing addresses so each chain has `gap_limit` unused ones after the last used
     * (or issued) one. Returns how many addresses were added.
     */
    public function ensureGap(): int
    {
        $added = 0;

        foreach ([self::RECEIVE, self::CHANGE] as $chain) {
            $lastUsed = $this->wallet->addresses()
                ->where('chain', $chain)
                ->where(fn ($query) => $query->where('tx_count', '>', 0)->orWhereNotNull('issued_at'))
                ->max('index');

            $wanted = ($lastUsed === null ? -1 : (int)$lastUsed) + $this->gapLimit();
            $existing = $this->wallet->addresses()->where('chain', $chain)->max('index');

            for ($index = $existing === null ? 0 : (int)$existing + 1; $index <= $wanted; $index++) {
                $this->create($chain, $index);
                $added++;
            }
        }

        return $added;
    }

    /**
     * Hands out the next unused receive address (the lowest one never issued and never used).
     */
    public function issueReceiveAddress(?string $title = null): BitcoinAddress
    {
        return DB::transaction(function () use ($title): BitcoinAddress {
            $this->ensureGap();

            do {
                /** @var BitcoinAddress $address */
                $address = $this->wallet->addresses()
                    ->where('chain', self::RECEIVE)
                    ->whereNull('issued_at')
                    ->where('tx_count', 0)
                    ->orderBy('index')
                    ->lockForUpdate()
                    ->firstOrFail();

                // A restored wallet before its first full sync does not know which addresses are
                // used yet: each candidate is checked so an address with history is never handed out
                $used = !$this->wallet->gap_scan_at && $this->hasHistory($address->address);

                if ($used) {
                    $address->update(['tx_count' => 1, 'issued_at' => Date::now(), 'available' => true, 'stats_hash' => null]);
                    $this->ensureGap();
                }
            } while ($used);

            $address->update([
                'issued_at' => Date::now(),
                'available' => true,
                'title' => $title,
            ]);

            $this->ensureGap();

            return $address;
        });
    }

    /**
     * The next unused change address, marked as issued (it is about to receive change).
     */
    public function issueChangeAddress(): BitcoinAddress
    {
        return DB::transaction(function (): BitcoinAddress {
            $this->ensureGap();

            /** @var BitcoinAddress $address */
            $address = $this->wallet->addresses()
                ->where('chain', self::CHANGE)
                ->whereNull('issued_at')
                ->where('tx_count', 0)
                ->orderBy('index')
                ->lockForUpdate()
                ->firstOrFail();

            $address->update(['issued_at' => Date::now()]);
            $this->ensureGap();

            return $address;
        });
    }

    protected function hasHistory(string $address): bool
    {
        $stats = App::make(ProviderPool::class)->call(fn (EsploraApi $api) => $api->address($address));

        return (int)($stats['chain_stats']['tx_count'] ?? 0) + (int)($stats['mempool_stats']['tx_count'] ?? 0) > 0;
    }

    public function derive(int $chain, int $index): string
    {
        return $this->chainKey($chain)->deriveChild($index)->p2wpkhAddress();
    }

    protected function create(int $chain, int $index): BitcoinAddress
    {
        return $this->wallet->addresses()->create([
            'address' => $this->derive($chain, $index),
            'type' => AddressType::BECH32,
            'chain' => $chain,
            'index' => $index,
            'available' => false,
        ]);
    }

    protected function chainKey(int $chain): ExtendedKey
    {
        static $keys = [];

        return $keys[$this->wallet->xpub.'/'.$chain] ??= ExtendedKey::fromPublicString((string)$this->wallet->xpub)->deriveChild($chain);
    }

    protected function gapLimit(): int
    {
        return max(1, (int)config('bitcoin.light.gap_limit', 20));
    }
}
