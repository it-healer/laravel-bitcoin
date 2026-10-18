<?php

namespace ItHealer\LaravelBitcoin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ItHealer\LaravelBitcoin\Casts\BigDecimalCast;
use ItHealer\LaravelBitcoin\Facades\Bitcoin;

/**
 * A transaction of a light wallet as a whole: `amount` is the net change of the wallet
 * balance (negative for outgoing, fee included), `direction` is in, out or self.
 */
class BitcoinTransaction extends Model
{
    protected $fillable = [
        'wallet_id',
        'txid',
        'direction',
        'amount',
        'fee',
        'block_height',
        'time_at',
        'dropped_at',
    ];

    protected $casts = [
        'amount' => BigDecimalCast::class,
        'fee' => BigDecimalCast::class,
        'block_height' => 'integer',
        'time_at' => 'datetime',
        'dropped_at' => 'datetime',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Bitcoin::getModelWallet(), 'wallet_id');
    }
}
