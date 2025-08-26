<?php

namespace ItHealer\LaravelBitcoin\Models;

use Illuminate\Database\Eloquent\Model;
use ItHealer\LaravelBitcoin\BitcoindRpcApi;
use ItHealer\LaravelBitcoin\ElectrumRpcApi;

class ElectrumNode extends Model
{
    protected $fillable = [
        'name',
        'title',
        'host',
        'port',
        'username',
        'password',
        'config',
        'pid',
        'sync_at',
        'worked',
        'worked_data',
        'available',
    ];

    protected $casts = [
        'port' => 'integer',
        'password' => 'encrypted',
        'config' => 'json',
        'pid' => 'integer',
        'sync_at' => 'datetime',
        'worked' => 'boolean',
        'worked_data' => 'json',
        'available' => 'boolean',
    ];

    public function api(): ElectrumRpcApi
    {
        return new ElectrumRpcApi(
            electrum: $this
        );
    }
}
