<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bitcoin_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('title')->nullable();
            $table->string('driver')->default('esplora');
            $table->string('base_url');
            $table->string('proxy')->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->unsignedBigInteger('block_height')->nullable();
            $table->unsignedInteger('requests')->default(0);
            $table->date('requests_at')->nullable();
            $table->timestamp('sync_at')->nullable();
            $table->boolean('worked')->default(true);
            $table->boolean('available')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitcoin_providers');
    }
};
