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
        Schema::table('transactions', function (Blueprint $table): void {
            // Only "worked" because SQLite ignores the declared VARCHAR(255) length limit
            // entirely — the raw Plaid transaction payload this column stores (already cast as
            // `json` on the Transaction model) routinely exceeds 255 bytes, which MySQL enforces
            // strictly and rejects outright ("Data too long for column"). `json` matches the
            // existing model cast and gets native JSON validation on MySQL/Postgres for free.
            $table->json('original')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->string('original')->nullable()->change();
        });
    }
};
