<?php

namespace ItHealer\LaravelBitcoin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ItHealer\LaravelBitcoin\BitcoindRpcApi;

class BitcoinNode extends Model
{
    protected $fillable = [
        'name',
        'title',
        'host',
        'port',
        'username',
        'password',
        'sync_at',
        'worked',
        'worked_data',
        'available',
    ];

    protected $casts = [
        'port' => 'integer',
        'password' => 'encrypted',
        'sync_at' => 'datetime',
        'worked' => 'boolean',
        'worked_data' => 'json',
        'available' => 'boolean',
    ];

    public function wallets(): HasMany
    {
        /** @var class-string<BitcoinWallet> $model */
        $model = config('bitcoin.models.wallet');

        return $this->hasMany($model, 'node_id');
    }

    public function api(): BitcoindRpcApi
    {
        /** @var class-string<BitcoindRpcApi> $model */
        $model = config('bitcoin.models.rpc_client');

        return new $model(
            host: $this->host,
            port: $this->port,
            username: $this->username,
            password: $this->password,
        );
    }
}
