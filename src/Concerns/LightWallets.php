<?php

namespace ItHealer\LaravelBitcoin\Concerns;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Date;
use ItHealer\LaravelBitcoin\BIP39\BIP39;
use ItHealer\LaravelBitcoin\BIP39\Language\English;
use ItHealer\LaravelBitcoin\Light\AddressBook;
use ItHealer\LaravelBitcoin\Light\LightTransfers;
use ItHealer\LaravelBitcoin\Light\Api\EsploraApi;
use ItHealer\LaravelBitcoin\Light\Api\ProviderPool;
use ItHealer\LaravelBitcoin\Light\Keys\ExtendedKey;
use ItHealer\LaravelBitcoin\Light\Sync\LightWalletSync;
use ItHealer\LaravelBitcoin\Models\BitcoinWallet;

/**
 * Light wallets: no node, the server keeps only the account xpub.
 */
trait LightWallets
{
    /**
     * Light wallet from a BIP39 mnemonic. Only the account xpub is stored; the mnemonic is
     * used for this derivation and forgotten.
     */
    public function createLightWallet(
        string $name,
        #[\SensitiveParameter] string|array $mnemonic,
        #[\SensitiveParameter] ?string $passphrase = null,
        ?string $title = null,
        ?string $derivationPath = null,
    ): BitcoinWallet {
        $words = is_array($mnemonic) ? $mnemonic : preg_split('/\s+/', trim($mnemonic));
        $seed = BIP39::fromWords($words, English::getInstance())->generateSeed((string)$passphrase);

        return $this->createLightWalletFromSeed($name, bin2hex($seed), $title, $derivationPath);
    }

    /**
     * Light wallet from a BIP39 seed (hex, mnemonic and passphrase already applied). Only the
     * account xpub is stored.
     */
    public function createLightWalletFromSeed(
        string $name,
        #[\SensitiveParameter] string $seedHex,
        ?string $title = null,
        ?string $derivationPath = null,
    ): BitcoinWallet {
        $path = $derivationPath ?? config('bitcoin.light.derivation_path', "m/84'/0'/0'");

        return $this->createLightWalletFromXpub($name, $this->xpubFromSeed($seedHex, $path), $title, $path);
    }

    /**
     * Account xpub of a BIP39 seed (hex) — what a light wallet keeps instead of the seed.
     */
    public function xpubFromSeed(#[\SensitiveParameter] string $seedHex, ?string $derivationPath = null): string
    {
        if (!ctype_xdigit($seedHex) || strlen($seedHex) < 32 || strlen($seedHex) > 128 || strlen($seedHex) % 2 !== 0) {
            throw new \InvalidArgumentException('Seed must be 16-64 bytes in hex.');
        }

        return ExtendedKey::fromSeed(hex2bin($seedHex))
            ->derivePath($derivationPath ?? config('bitcoin.light.derivation_path', "m/84'/0'/0'"))
            ->neutered()
            ->toXpub();
    }

    /**
     * Light wallet from an account xpub (or zpub). Without the mnemonic it can receive and
     * show funds; sending needs the mnemonic at signing time. `$birthHeight` — chain height
     * the wallet starts from: deposits mined well before it are history without webhooks
     * (current tip by default, 0 — nothing is history).
     */
    public function createLightWalletFromXpub(
        string $name,
        string $xpub,
        ?string $title = null,
        ?string $derivationPath = null,
        ?int $birthHeight = null,
    ): BitcoinWallet {
        $account = ExtendedKey::fromPublicString($xpub);

        // Without the birth height the first sync could not tell old history from new money:
        // the wallet is not created when no provider answers (ProviderException)
        $birthHeight ??= App::make(ProviderPool::class)->tipHeight();

        return DB::transaction(function () use ($name, $account, $title, $derivationPath, $birthHeight): BitcoinWallet {
            /** @var BitcoinWallet $wallet */
            $wallet = $this->getModelWallet()::query()->create([
                'mode' => 'light',
                'name' => $name,
                'title' => $title,
                'xpub' => $account->toXpub(),
                'derivation_path' => $derivationPath ?? config('bitcoin.light.derivation_path', "m/84'/0'/0'"),
                'birth_height' => $birthHeight,
            ]);

            (new AddressBook($wallet))->ensureGap();

            return $wallet;
        });
    }

    /**
     * Reads balances and history from the providers. `$full` also checks the hidden gap
     * addresses (first sync, restore from mnemonic). One sync of a wallet at a time: returns
     * false without syncing when another one is running.
     */
    public function syncLightWallet(BitcoinWallet $wallet, bool $full = false, ?\Closure $logger = null): bool
    {
        $lock = Cache::lock('bitcoin:light-sync:'.$wallet->getKey(), (int)config('bitcoin.light.lock_seconds', 900));

        if (!$lock->get()) {
            return false;
        }

        try {
            (new LightWalletSync($wallet, $full))->setLogger($logger)->run();
        } finally {
            $lock->release();
        }

        return true;
    }

    /**
     * Recommended fee rates of the network in sat/vB: fastest, half_hour, hour, economy, minimum.
     *
     * @return array<string, float>
     */
    public function lightFeeRates(): array
    {
        return LightTransfers::recommendedFeeRates();
    }

    /**
     * Status of a transaction from the providers: ['confirmed' => bool, 'block_height' => ?int,
     * 'confirmations' => int], or null when no provider knows it (not sent, or dropped).
     *
     * @return array{confirmed: bool, block_height: ?int, confirmations: int}|null
     */
    public function lightTransactionStatus(string $txid): ?array
    {
        if (!preg_match('/^[0-9a-f]{64}$/i', $txid)) {
            throw new \InvalidArgumentException('Invalid txid.');
        }

        $pool = App::make(ProviderPool::class);
        $status = $pool->call(fn (EsploraApi $api) => $api->transactionStatus($txid));

        if ($status === null) {
            return null;
        }

        $height = ($status['confirmed'] ?? false) ? (int)$status['block_height'] : null;

        return [
            'confirmed' => $height !== null,
            'block_height' => $height,
            'confirmations' => $height !== null ? max(0, $pool->tipHeight() - $height + 1) : 0,
        ];
    }

    /**
     * Speeds up an unconfirmed transaction of a light wallet (RBF): the same transaction with a
     * higher fee, paid from the change.
     *
     * @return array{txid: string, old_txid: string, fee: string, fee_rate: float}
     */
    public function bumpLightFee(BitcoinWallet $wallet, string $txid, float $feeRate, #[\SensitiveParameter] ?string $seedHex): array
    {
        if (!preg_match('/^[0-9a-f]{64}$/i', $txid)) {
            throw new \InvalidArgumentException('Invalid txid.');
        }

        return (new LightTransfers($wallet))->bumpFee($txid, $feeRate, $seedHex);
    }

    /**
     * Moves a wallet of the node mode to the light mode with its account xpub: the known
     * addresses get their chain and index (looked up to `$scanLimit` per chain), the node is
     * detached, and the hidden gap is derived. Addresses not found keep receiving in the history
     * but cannot be spent from (their key path is unknown). Deposits and transfers stay as they
     * are; `$birthHeight` (the current tip by default) keeps history found later out of webhooks.
     *
     * @return array{matched: int, unmatched: list<string>}
     */
    public function convertToLight(BitcoinWallet $wallet, string $xpub, ?int $birthHeight = null, int $scanLimit = 1000, ?string $derivationPath = null): array
    {
        if ($wallet->isLight()) {
            return ['matched' => 0, 'unmatched' => []];
        }

        $account = ExtendedKey::fromPublicString($xpub);
        $birthHeight ??= App::make(ProviderPool::class)->tipHeight();

        $known = $wallet->addresses()->pluck('id', 'address');
        $found = [];

        foreach ([AddressBook::RECEIVE, AddressBook::CHANGE] as $chain) {
            $chainKey = $account->deriveChild($chain);

            for ($index = 0; $index < $scanLimit && count($found) < $known->count(); $index++) {
                $address = $chainKey->deriveChild($index)->p2wpkhAddress();

                if ($known->has($address)) {
                    $found[$address] = [$chain, $index];
                }
            }
        }

        DB::transaction(function () use ($wallet, $account, $birthHeight, $found, $derivationPath): void {
            foreach ($found as $address => [$chain, $index]) {
                $wallet->addresses()->where('address', $address)->update([
                    'chain' => $chain,
                    'index' => $index,
                    'issued_at' => Date::now(),
                    'stats_hash' => null,
                ]);
            }

            $wallet->update([
                'mode' => 'light',
                'node_id' => null,
                'xpub' => $account->toXpub(),
                'derivation_path' => $derivationPath ?? config('bitcoin.light.derivation_path', "m/84'/0'/0'"),
                'birth_height' => $birthHeight,
                'gap_scan_at' => null,
            ]);

            (new AddressBook($wallet))->ensureGap();
        });

        return [
            'matched' => count($found),
            'unmatched' => $known->keys()->diff(array_keys($found))->values()->all(),
        ];
    }
}
