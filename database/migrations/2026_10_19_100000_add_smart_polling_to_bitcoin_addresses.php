<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bitcoin_addresses', function (Blueprint $table) {
            $table->unsignedInteger('mempool_tx_count')->default(0)->after('tx_count');
            $table->timestamp('activity_at')->nullable()->after('mempool_tx_count');
            $table->timestamp('watch_until')->nullable()->after('activity_at');
        });
    }

    public function down(): void
    {
        Schema::table('bitcoin_addresses', function (Blueprint $table) {
            $table->dropColumn(['mempool_tx_count', 'activity_at', 'watch_until']);
        });
    }
};
