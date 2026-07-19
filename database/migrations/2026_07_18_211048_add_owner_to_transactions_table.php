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
            // Stub for future household/ownership modeling — no UI or logic reads/writes this
            // yet. Deliberately a free-form nullable string, not a FK/enum: who "owns" a
            // transaction (an individual, a household) hasn't been designed yet, this just
            // avoids another migration touching this table when that work starts.
            $table->string('owner')->nullable()->after('transfer_pair_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('owner');
        });
    }
};
