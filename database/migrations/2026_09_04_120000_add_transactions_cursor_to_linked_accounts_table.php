<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('linked_accounts', function (Blueprint $table) {
            // Plaid /transactions/sync cursor. Without persisting it, every pull starts
            // from null and refetches the full days_requested window; `removed` events
            // are also cursor-relative, so deletions get missed entirely.
            // text, not string: Plaid documents the cursor as opaque with no length bound.
            $table->text('transactions_cursor')->nullable()->after('last_sync_error');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('linked_accounts', function (Blueprint $table) {
            $table->dropColumn('transactions_cursor');
        });
    }
};
