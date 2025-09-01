<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ItHealer\LaravelBitcoin\Models\BitcoinAddress;
use ItHealer\LaravelBitcoin\Models\BitcoinWallet;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bitcoin_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(BitcoinWallet::class, 'wallet_id')
                ->constrained('bitcoin_wallets')
                ->cascadeOnDelete();
            $table->foreignIdFor(BitcoinAddress::class, 'address_id')
                ->constrained('bitcoin_addresses')
                ->cascadeOnDelete();
            $table->string('txid');
            $table->string('receiver_address');
            $table->decimal('amount', 20, 8);
            $table->string('comment')
                ->nullable();
            $table->unsignedInteger('block_height')
                ->nullable();
            $table->integer('confirmations')
                ->default(0);
            $table->timestamp('time_at');
            $table->timestamps();

            $table->unique(['address_id', 'txid', 'receiver_address'], 'unique_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitcoin_transfers');
    }
};
