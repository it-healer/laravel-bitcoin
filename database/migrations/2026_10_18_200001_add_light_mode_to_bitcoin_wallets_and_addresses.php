<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        /*
         * Light wallets have no node, and deleting the node of the old mode must never delete
         * wallets: node_id becomes optional and is cleared instead of cascading.
         */
        Schema::table('bitcoin_wallets', function (Blueprint $table) {
            $table->dropForeign(['node_id']);
        });

        Schema::table('bitcoin_wallets', function (Blueprint $table) {
            $table->unsignedBigInteger('node_id')->nullable()->change();
            $table->text('descriptors')->nullable()->change();
            $table->foreign('node_id')->references('id')->on('bitcoin_nodes')->nullOnDelete();
        });

        Schema::table('bitcoin_wallets', function (Blueprint $table) {
            $table->string('mode')->default('node')->after('node_id');
            $table->string('xpub')->nullable()->after('descriptors');
            $table->string('derivation_path')->nullable()->after('xpub');
            $table->timestamp('touch_at')->nullable()->after('sync_at');
            $table->timestamp('gap_scan_at')->nullable()->after('touch_at');
        });

        Schema::table('bitcoin_addresses', function (Blueprint $table) {
            $table->unsignedTinyInteger('chain')->nullable()->after('type');
            $table->unsignedInteger('index')->nullable()->after('chain');
            $table->timestamp('issued_at')->nullable()->after('index');
            $table->string('stats_hash', 64)->nullable()->after('unconfirmed_balance');
            $table->unsignedInteger('tx_count')->default(0)->after('stats_hash');

            $table->unique(['wallet_id', 'chain', 'index']);
        });
    }

    public function down(): void
    {
        Schema::table('bitcoin_addresses', function (Blueprint $table) {
            $table->dropUnique(['wallet_id', 'chain', 'index']);
            $table->dropColumn(['chain', 'index', 'issued_at', 'stats_hash', 'tx_count']);
        });

        Schema::table('bitcoin_wallets', function (Blueprint $table) {
            $table->dropColumn(['mode', 'xpub', 'derivation_path', 'touch_at', 'gap_scan_at']);
            $table->dropForeign(['node_id']);
        });

        Schema::table('bitcoin_wallets', function (Blueprint $table) {
            $table->foreign('node_id')->references('id')->on('bitcoin_nodes')->cascadeOnDelete();
        });
    }
};
