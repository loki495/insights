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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id');
            $table->string('transaction_id');
            $table->string('transaction_type');
            $table->integer('amount');
            $table->string('name');
            $table->string('currency');
            $table->string('status')->nullable();
            $table->string('merchant_name')->nullable();
            $table->string('merchant_entity_id')->nullable();
            $table->string('payment_channel');
            $table->integer('running_balance')->nullable();
            $table->datetime('authorized_at')->nullable();
            $table->integer('logo_url')->nullable();
            $table->integer('website')->nullable();
            $table->string('original');
            $table->foreignId('original_category_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
