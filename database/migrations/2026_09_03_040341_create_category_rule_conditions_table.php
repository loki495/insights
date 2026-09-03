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
            // Custom (shorter) constraint name: the default Laravel would generate here
            // ("category_rule_conditions_category_rule_condition_group_id_foreign") exceeds
            // MySQL's 64-character identifier limit — SQLite doesn't enforce this, so the
            // default only broke on the MySQL CI job.
            $table->foreignId('category_rule_condition_group_id')
                ->constrained(indexName: 'category_rule_conditions_group_id_foreign')
                ->cascadeOnDelete();
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
