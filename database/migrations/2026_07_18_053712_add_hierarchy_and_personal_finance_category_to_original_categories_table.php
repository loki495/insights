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
        Schema::table('original_categories', function (Blueprint $table) {
            $table->dropColumn(['description', 'logo_url']);

            $table->string('plaid_id')->nullable()->index()->change();
            $table->foreignId('parent_id')->nullable()->constrained('original_categories');

            // Plaid "personal finance category"
            $table->string('pf_primary')->nullable()->index();
            $table->string('pf_detailed')->nullable()->index();
            $table->string('pf_confidence')->nullable();

            $table->unique(['name', 'parent_id']); // prevents dup siblings
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('original_categories', function (Blueprint $table) {
            $table->dropUnique(['name', 'parent_id']);
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['pf_primary', 'pf_detailed', 'pf_confidence']);

            $table->string('plaid_id')->nullable(false)->change();

            $table->string('description')->nullable();
            $table->string('logo_url')->nullable();
        });
    }
};
