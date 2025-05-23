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
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('linked_account_id');
            $table->string('plaid_account_id');
            $table->string('mask');
            $table->string('name');
            $table->string('official_name');
            $table->string('type');
            $table->string('subtype');
            $table->string('currency')->default('USD');
            $table->string('nickname')->nullable();
            $table->integer('available_balance')->nullable();
            $table->integer('current_balance')->nullable();
            $table->integer('limit')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
