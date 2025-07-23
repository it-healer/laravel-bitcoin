<?php

namespace ItHealer\LaravelBitcoin\Facades;

use Illuminate\Support\Facades\Facade;

class Bitcoin extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \ItHealer\LaravelBitcoin\Bitcoin::class;
    }
}
