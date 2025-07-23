<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ItHealer\LaravelBitcoin\Models\BitcoinAddress;
use ItHealer\LaravelBitcoin\Models\BitcoinWallet;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bitcoin_deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(BitcoinWallet::class, 'wallet_id')
                ->constrained('bitcoin_wallets')
                ->cascadeOnDelete();
            $table->foreignIdFor(BitcoinAddress::class, 'address_id')
                ->constrained('bitcoin_addresses')
                ->cascadeOnDelete();
            $table->string('txid');
            $table->decimal('amount', 20, 8);
            $table->unsignedInteger('block_height')
                ->nullable();
            $table->integer('confirmations');
            $table->timestamp('time_at');
            $table->timestamps();

            $table->unique(['address_id', 'txid'], 'unique_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitcoin_deposits');
    }
};
