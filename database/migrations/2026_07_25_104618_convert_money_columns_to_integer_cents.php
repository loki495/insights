<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Data-only migration — no schema change. transactions.amount/running_balance and
     * accounts.available_balance/current_balance/limit are already declared `integer`; they just
     * actually contain real decimal dollar values today (e.g. -105.27), which only "worked"
     * because SQLite ignores declared column types. Converts existing values to true integer
     * cents so the declared type finally matches reality, and so this is exact on MySQL/Postgres
     * too, not just SQLite. NULL * 100 stays NULL, so the three nullable Account columns need no
     * special-casing.
     */
    public function up(): void
    {
        DB::statement('UPDATE transactions SET amount = ROUND(amount * 100)');
        DB::statement('UPDATE transactions SET running_balance = ROUND(running_balance * 100)');
        DB::statement('UPDATE accounts SET available_balance = ROUND(available_balance * 100)');
        DB::statement('UPDATE accounts SET current_balance = ROUND(current_balance * 100)');

        // `limit` is a reserved word — hardcoded double-quote identifier quoting only works on
        // SQLite/Postgres, MySQL treats double quotes as a string literal by default. wrap() asks
        // the current connection's own grammar for the right quote style instead.
        $limit = DB::connection()->getQueryGrammar()->wrap('limit');
        DB::statement("UPDATE accounts SET {$limit} = ROUND({$limit} * 100)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('UPDATE transactions SET amount = amount / 100.0');
        DB::statement('UPDATE transactions SET running_balance = running_balance / 100.0');
        DB::statement('UPDATE accounts SET available_balance = available_balance / 100.0');
        DB::statement('UPDATE accounts SET current_balance = current_balance / 100.0');

        $limit = DB::connection()->getQueryGrammar()->wrap('limit');
        DB::statement("UPDATE accounts SET {$limit} = {$limit} / 100.0");
    }
};
