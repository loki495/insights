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
        Schema::create('category_rule_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_rule_condition_group_id')->constrained()->cascadeOnDelete();
            $table->string('field');
            $table->string('operator');
            $table->string('value')->nullable();
            $table->string('value_end')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('category_rule_conditions');
    }
};
