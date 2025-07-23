<?php

namespace ItHealer\LaravelBitcoin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ItHealer\LaravelBitcoin\Casts\DecimalCast;

class BitcoinWallet extends Model
{
    protected $fillable = [
        'name',
        'title',
        'password',
        'descriptors',
        'sync_at',
        'balance',
        'unconfirmed_balance',
    ];

    protected $hidden = [
        'password',
        'descriptors',
    ];

    protected $casts = [
        'password' => 'encrypted',
        'descriptors' => 'encrypted:json',
        'sync_at' => 'datetime',
        'balance' => DecimalCast::class,
        'unconfirmed_balance' => DecimalCast::class,
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
}
