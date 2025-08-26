<?php

namespace ItHealer\LaravelBitcoin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ItHealer\LaravelBitcoin\Casts\BigDecimalCast;
use ItHealer\LaravelBitcoin\Casts\EncryptedCast;

class BitcoinWallet extends Model
{
    protected static array $plainPasswords = [];

    protected $fillable = [
        'node_id',
        'name',
        'title',
        'password',
        'mnemonic',
        'seed',
        'descriptors',
        'sync_at',
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
        'balance' => BigDecimalCast::class,
        'unconfirmed_balance' => BigDecimalCast::class,
    ];

    public function node(): BelongsTo
    {
        /** @var class-string<BitcoinNode> $model */
        $model = config('bitcoin.models.node');

        return $this->belongsTo($model, 'node_id');
    }

    public function addresses(): HasMany
    {
        /** @var class-string<BitcoinAddress> $model */
        $model = config('bitcoin.models.address');

        return $this->hasMany($model, 'wallet_id', 'id');
    }

    public function deposits(): HasMany
    {
        /** @var class-string<BitcoinDeposit> $model */
        $model = config('bitcoin.models.deposit');

        return $this->hasMany($model, 'wallet_id', 'id');
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
