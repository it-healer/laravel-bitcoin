<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bitcoin_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')
                ->constrained('bitcoin_wallets')
                ->cascadeOnDelete();
            $table->string('txid', 64);
            $table->string('direction', 8);
            $table->decimal('amount', 20, 8);
            $table->decimal('fee', 20, 8)->nullable();
            $table->unsignedInteger('block_height')->nullable();
            $table->timestamp('time_at')->nullable();
            $table->timestamp('dropped_at')->nullable();
            $table->timestamps();

            $table->unique(['wallet_id', 'txid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitcoin_transactions');
    }
};
