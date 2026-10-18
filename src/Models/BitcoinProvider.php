<?php

namespace ItHealer\LaravelBitcoin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use ItHealer\LaravelBitcoin\Light\Api\EsploraApi;

/**
 * Public blockchain API (Esplora: mempool.space, blockstream.info) used by light wallets
 * instead of a node. Every HTTP request is counted in `requests` per day.
 */
class BitcoinProvider extends Model
{
    protected $fillable = [
        'name',
        'title',
        'driver',
        'base_url',
        'proxy',
        'priority',
        'block_height',
        'requests',
        'requests_at',
        'sync_at',
        'worked',
        'available',
    ];

    protected $casts = [
        'priority' => 'integer',
        'block_height' => 'integer',
        'requests' => 'integer',
        'requests_at' => 'date',
        'sync_at' => 'datetime',
        'worked' => 'boolean',
        'available' => 'boolean',
    ];

    protected ?EsploraApi $_api = null;

    public function api(): EsploraApi
    {
        return $this->_api ??= new EsploraApi(
            baseUrl: $this->base_url,
            proxy: $this->proxy,
            onRequest: fn () => $this->recordRequest(),
        );
    }

    /**
     * Counts one request for today in a single UPDATE (a new day starts from 1), so processes
     * holding a stale copy of the model do not reset each other's count.
     */
    public function recordRequest(): void
    {
        if (!$this->exists) {
            return;
        }

        $today = Date::now()->toDateString();
        $connection = $this->getConnection();

        static::query()->whereKey($this->getKey())->toBase()->update([
            'requests' => $connection->raw('case when requests_at >= '.$connection->getPdo()->quote($today).' then requests + 1 else 1 end'),
            'requests_at' => $today,
        ]);
    }
}
