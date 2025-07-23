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
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'port' => 'integer',
        'password' => 'encrypted',
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
