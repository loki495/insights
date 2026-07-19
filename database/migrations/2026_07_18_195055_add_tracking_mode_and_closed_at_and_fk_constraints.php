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
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('tracking_mode')->default('tracked')->after('linked_account_id');
        });

        Schema::table('linked_accounts', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('access_token');
        });

        // No orphans exist today (verified before writing this migration), so these can be
        // added as real constraints now rather than staying the plain unconstrained columns
        // they were before.
        Schema::table('accounts', function (Blueprint $table) {
            $table->foreign('linked_account_id')->references('id')->on('linked_accounts');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreign('account_id')->references('id')->on('accounts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropForeign(['linked_account_id']);
        });

        Schema::table('linked_accounts', function (Blueprint $table) {
            $table->dropColumn('closed_at');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('tracking_mode');
        });
    }
};
