<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bitcoin_nodes', function (Blueprint $table) {
            $table->id();
            $table->string('name')
                ->unique();
            $table->string('title')
                ->nullable();
            $table->string('host')
                ->default('127.0.0.1');
            $table->unsignedInteger('port')
                ->default(8332);
            $table->string('username')
                ->nullable();
            $table->text('password')
                ->nullable();
            $table->timestamp('sync_at')
                ->nullable();
            $table->boolean('worked')
                ->default(false);
            $table->json('worked_data')
                ->default('[]');
            $table->boolean('available')
                ->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitcoin_nodes');
    }
};
