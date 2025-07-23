<?php

namespace ItHealer\LaravelBitcoin\Enums;

enum AddressType: string
{
    case LEGACY = 'legacy';
    case P2SH_SEGWIT = 'p2wpkh-p2sh';
    case BECH32 = 'bech32';
    case BECH32M = 'bech32m';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
