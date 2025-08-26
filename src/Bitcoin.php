<?php

namespace ItHealer\LaravelBitcoin;

use ItHealer\LaravelBitcoin\Concerns\Addresses;
use ItHealer\LaravelBitcoin\Concerns\Electrums;
use ItHealer\LaravelBitcoin\Concerns\Models;
use ItHealer\LaravelBitcoin\Concerns\Nodes;
use ItHealer\LaravelBitcoin\Concerns\Transfers;
use ItHealer\LaravelBitcoin\Concerns\Utils;
use ItHealer\LaravelBitcoin\Concerns\Wallets;

class Bitcoin
{
    use Utils, Models, Nodes, Electrums, Wallets, Addresses, Transfers;
}
