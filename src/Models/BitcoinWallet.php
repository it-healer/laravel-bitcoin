<?php

namespace ItHealer\LaravelBitcoin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ItHealer\LaravelBitcoin\Casts\BigDecimalCast;
use ItHealer\LaravelBitcoin\Casts\EncryptedCast;
use ItHealer\LaravelBitcoin\Facades\Bitcoin;

class BitcoinWallet extends Model
{
    protected static array $plainPasswords = [];

    protected $fillable = [
        'node_id',
        'mode',
        'name',
        'title',
        'password',
        'mnemonic',
        'seed',
        'descriptors',
        'xpub',
        'derivation_path',
        'sync_at',
        'touch_at',
        'gap_scan_at',
        'balance',
        'unconfirmed_balance',
    ];

    protected $appends = [
        'has_password',
        'has_mnemonic',
        'has_seed',
    ];

    protected $hidden = [
        'password',
        'mnemonic',
        'seed',
        'descriptors',
    ];

    protected $casts = [
        'password' => 'encrypted',
        'mnemonic' => EncryptedCast::class,
        'seed' => EncryptedCast::class,
        'descriptors' => EncryptedCast::class,
        'sync_at' => 'datetime',
        'touch_at' => 'datetime',
        'gap_scan_at' => 'datetime',
        'balance' => BigDecimalCast::class,
        'unconfirmed_balance' => BigDecimalCast::class,
    ];

    public function node(): BelongsTo
    {
        return $this->belongsTo(Bitcoin::getModelNode(), 'node_id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Bitcoin::getModelAddress(), 'wallet_id', 'id');
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(Bitcoin::getModelDeposit(), 'wallet_id', 'id');
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(Bitcoin::getModelTransfer(), 'wallet_id', 'id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Bitcoin::getModelTransaction(), 'wallet_id', 'id');
    }

    /**
     * Light wallet: addresses come from `xpub`, data from public APIs, no node.
     */
    public function isLight(): bool
    {
        return $this->mode === 'light';
    }

    public function unlockWallet(?string $password): void
    {
        self::$plainPasswords[$this->name] = $password;
    }

    public function getPlainPasswordAttribute(): ?string
    {
        return self::$plainPasswords[$this->name] ?? null;
    }

    public function getHasPasswordAttribute(): bool
    {
        return !!$this->password;
    }

    public function getHasMnemonicAttribute(): bool
    {
        return !!$this->mnemonic;
    }

    public function getHasSeedAttribute(): bool
    {
        return !!$this->seed;
    }
}
