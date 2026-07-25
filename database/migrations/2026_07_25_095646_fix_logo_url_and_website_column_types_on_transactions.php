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
            // Only "worked" because SQLite ignores declared column types and just stores
            // whatever's given it — real Plaid logo/website URL strings have been living in these
            // nominally `integer` columns the whole time. Would truncate/fail on MySQL/Postgres.
            $table->string('logo_url')->nullable()->change();
            $table->string('website')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->integer('logo_url')->nullable()->change();
            $table->integer('website')->nullable()->change();
        });
    }
};
