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
        Schema::create('category_rule_condition_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_rule_id')->constrained()->cascadeOnDelete();
            // How THIS group's own conditions combine — 'all' or 'any'. One level of nesting
            // only: a group holds plain conditions, never another group.
            $table->string('match_type')->default('all');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('category_rule_condition_groups');
    }
};
