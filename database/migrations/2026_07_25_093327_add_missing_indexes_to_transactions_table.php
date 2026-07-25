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
        Schema::table('transactions', function (Blueprint $table) {
            // account_id and original_category_id are foreignId() without ->constrained(), so
            // neither got an index; transaction_id (Plaid's own id, used for idempotent sync
            // matching) never had one either. Fine at today's data volume, but these are exactly
            // the columns filtered/joined on for every transaction list and sync run.
            $table->index('account_id');
            $table->index('original_category_id');
            $table->index('transaction_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['account_id']);
            $table->dropIndex(['original_category_id']);
            $table->dropIndex(['transaction_id']);
        });
    }
};
