<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ItHealer\LaravelBitcoin\Enums\AddressType;
use ItHealer\LaravelBitcoin\Models\BitcoinWallet;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bitcoin_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(BitcoinWallet::class, 'wallet_id')
                ->constrained('bitcoin_wallets')
                ->cascadeOnDelete();
            $table->string('address');
            $table->enum('type', AddressType::values());
            $table->string('title')
                ->nullable();
            $table->timestamp('sync_at')
                ->nullable();
            $table->decimal('balance', 20, 8)
                ->nullable();
            $table->decimal('unconfirmed_balance', 20, 8)
                ->nullable();
            $table->boolean('available')
                ->default(true);
            $table->timestamps();

            $table->unique(['wallet_id', 'address', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitcoin_addresses');
    }
};
