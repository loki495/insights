<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * created_at is the most-filtered column in the app - every report action
     * (balance trend, income/expense trend, category breakdown trend), the
     * dashboard's spending query, and the transactions list all range-filter or
     * sort on it - and the 2026_07_25 index pass covered account_id,
     * original_category_id and transaction_id but skipped it.
     *
     * Composite rather than a standalone created_at index because those same
     * queries are almost always scoped to a set of accounts first
     * (whereIn('account_id', ...) then whereBetween('created_at', ...)), which is
     * exactly the leftmost-prefix shape a composite serves. It also subsumes the
     * existing standalone account_id index for those lookups; that one is left in
     * place rather than dropped, since removing an index is a separate call with
     * its own risk and this migration is purely additive.
     *
     * `type` is deliberately not indexed: its only consumer is scopeReportable's
     * whereNotIn('type', ['transfer', 'adjustment']), and a negated match on a
     * low-cardinality column is not something an index helps with.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['account_id', 'created_at'], 'transactions_account_id_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_account_id_created_at_index');
        });
    }
};
