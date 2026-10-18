<?php

namespace ItHealer\LaravelBitcoin\Concerns;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ItHealer\LaravelBitcoin\BIP39\BIP39;
use ItHealer\LaravelBitcoin\BIP39\Language\English;
use ItHealer\LaravelBitcoin\Light\AddressBook;
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
        if (!ctype_xdigit($seedHex) || strlen($seedHex) < 32 || strlen($seedHex) > 128 || strlen($seedHex) % 2 !== 0) {
            throw new \InvalidArgumentException('Seed must be 16-64 bytes in hex.');
        }

        $path = $derivationPath ?? config('bitcoin.light.derivation_path', "m/84'/0'/0'");
        $xpub = ExtendedKey::fromSeed(hex2bin($seedHex))->derivePath($path)->neutered()->toXpub();

        return $this->createLightWalletFromXpub($name, $xpub, $title, $path);
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
}
