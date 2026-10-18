<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use ItHealer\LaravelBitcoin\Models\BitcoinNode;
use ItHealer\LaravelBitcoin\Models\BitcoinWallet;
use ItHealer\LaravelBitcoin\Services\Bitcoin\NodeSyncService;

uses(RefreshDatabase::class);

it('syncs only the node-mode wallets of its own node', function () {
    $node = BitcoinNode::create(['name' => 'a', 'host' => 'node-a.test', 'port' => 8332, 'available' => true]);
    $other = BitcoinNode::create(['name' => 'b', 'host' => 'node-b.test', 'port' => 8332, 'available' => true]);
    BitcoinWallet::create(['node_id' => $node->id, 'name' => 'mine', 'descriptors' => '[]']);
    BitcoinWallet::create(['node_id' => $other->id, 'name' => 'theirs', 'descriptors' => '[]']);
    BitcoinWallet::create(['mode' => 'light', 'name' => 'light', 'xpub' => BIP84_TEST_XPUB]);

    $names = (fn () => $this->wallets()->pluck('name')->all())->call(new NodeSyncService($node));

    expect($names)->toBe(['mine']);
});
