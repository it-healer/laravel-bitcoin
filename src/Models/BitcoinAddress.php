<?php

namespace ItHealer\LaravelBitcoin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ItHealer\LaravelBitcoin\Casts\BigDecimalCast;
use ItHealer\LaravelBitcoin\Enums\AddressType;
use ItHealer\LaravelBitcoin\Facades\Bitcoin;

class BitcoinAddress extends Model
{
    protected $fillable = [
        'wallet_id',
        'address',
        'type',
        'title',
        'sync_at',
        'balance',
        'unconfirmed_balance',
        'available',
    ];

    protected $casts = [
        'type' => AddressType::class,
        'sync_at' => 'datetime',
        'balance' => BigDecimalCast::class,
        'unconfirmed_balance' => BigDecimalCast::class,
        'available' => 'boolean',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Bitcoin::getModelWallet(), 'wallet_id', 'id');
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(Bitcoin::getModelDeposit(), 'address_id', 'id');
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(Bitcoin::getModelTransfer(), 'address_id', 'id');
    }
}
