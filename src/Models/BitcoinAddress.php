<?php

namespace ItHealer\LaravelBitcoin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ItHealer\LaravelBitcoin\Casts\BigDecimalCast;
use ItHealer\LaravelBitcoin\Enums\AddressType;

class BitcoinAddress extends Model
{
    protected $fillable = [
        'wallet_id',
        'address',
        'type',
        'title',
        'sync_at',
        'balance',
        'unconfirmed_balance'
    ];

    protected $casts = [
        'type' => AddressType::class,
        'sync_at' => 'datetime',
        'balance' => BigDecimalCast::class,
        'unconfirmed_balance' => BigDecimalCast::class,
    ];

    public function wallet(): BelongsTo
    {
        /** @var class-string<BitcoinWallet> $model */
        $model = config('bitcoin.models.wallet');

        return $this->belongsTo($model, 'wallet_id', 'id');
    }

    public function deposits(): HasMany
    {
        /** @var class-string<BitcoinDeposit> $model */
        $model = config('bitcoin.models.deposit');

        return $this->hasMany($model, 'address_id', 'id');
    }
}
