<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('linked_accounts', function (Blueprint $table) {
            $table->boolean('auto_pull_enabled')->default(false)->after('closed_at');
            $table->unsignedInteger('auto_pull_interval_value')->default(24)->after('auto_pull_enabled');
            $table->string('auto_pull_interval_unit')->default('hours')->after('auto_pull_interval_value');
            $table->timestamp('last_pulled_at')->nullable()->after('auto_pull_interval_unit');
        });

        // Auto-pull is opt-in for newly-linked institutions going forward (hence the column
        // default above), but every institution that was *already* linked before this migration
        // relied on the old unconditional fixed schedule (~every 10 days) — turning that off for
        // them by default would silently stop their sync. Preserve their existing behavior.
        DB::table('linked_accounts')->update([
            'auto_pull_enabled' => true,
            'auto_pull_interval_value' => 10,
            'auto_pull_interval_unit' => 'days',
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('linked_accounts', function (Blueprint $table) {
            $table->dropColumn(['auto_pull_enabled', 'auto_pull_interval_value', 'auto_pull_interval_unit', 'last_pulled_at']);
        });
    }
};
