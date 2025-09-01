<?php

namespace ItHealer\LaravelBitcoin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ItHealer\LaravelBitcoin\Casts\BigDecimalCast;
use ItHealer\LaravelBitcoin\Facades\Bitcoin;

class BitcoinTransfer extends Model
{
    protected $fillable = [
        'wallet_id',
        'address_id',
        'txid',
        'receiver_address',
        'amount',
        'comment',
        'block_height',
        'confirmations',
        'time_at',
    ];

    protected $casts = [
        'amount' => BigDecimalCast::class,
        'confirmations' => 'integer',
        'time_at' => 'datetime',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Bitcoin::getModelWallet(), 'wallet_id');
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Bitcoin::getModelAddress(), 'address_id');
    }
}
