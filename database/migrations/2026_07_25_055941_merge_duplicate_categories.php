<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * One-time data cleanup ahead of the categories (parent_id, name) unique index below: this
     * app's global/unscoped category model let duplicate (parent_id, name) rows accumulate
     * (confirmed root cause: DemoDataSeeder used to call Category::create() unconditionally for
     * its "Income"/"Expenses" categories instead of finding existing ones, so re-seeding created
     * fresh duplicates every time). For each duplicate group, keeps the lowest id as canonical and
     * folds the rest into it — repointing any child categories and category_transaction rows —
     * before deleting the duplicate rows.
     *
     * Runs as a fixed-point loop, not a single pass: repointing two duplicate parents onto one
     * canonical parent can make their children collide for the first time (e.g. two separately
     * demo-seeded "Expenses" trees each had their own "Utilities" child — those only become
     * duplicates of each other once both "Expenses" rows merge into one parent). Confirmed by
     * testing against a real copy of this app's dev database, not hypothetical.
     */
    public function up(): void
    {
        do {
            $groups = DB::table('categories')
                ->select('parent_id', DB::raw('LOWER(name) as lname'))
                ->groupBy('parent_id', 'lname')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            foreach ($groups as $group) {
                $ids = DB::table('categories')
                    ->where('parent_id', $group->parent_id)
                    ->whereRaw('LOWER(name) = ?', [$group->lname])
                    ->orderBy('id')
                    ->pluck('id');

                $canonicalId = $ids->first();
                $duplicateIds = $ids->slice(1);

                foreach ($duplicateIds as $duplicateId) {
                    DB::table('categories')
                        ->where('parent_id', $duplicateId)
                        ->update(['parent_id' => $canonicalId]);

                    $canonicalTransactionIds = DB::table('category_transaction')
                        ->where('category_id', $canonicalId)
                        ->pluck('transaction_id');

                    // A transaction already linked to the canonical category doesn't need (and,
                    // with no unique constraint on this pivot today, could otherwise end up
                    // with) a second pivot row once its duplicate-category link is repointed onto
                    // the same canonical id — drop those instead of repointing them.
                    DB::table('category_transaction')
                        ->where('category_id', $duplicateId)
                        ->whereIn('transaction_id', $canonicalTransactionIds)
                        ->delete();

                    DB::table('category_transaction')
                        ->where('category_id', $duplicateId)
                        ->update(['category_id' => $canonicalId]);

                    DB::table('categories')->where('id', $duplicateId)->delete();
                }
            }
        } while ($groups->isNotEmpty());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Not reversible in any meaningful sense — merged duplicate categories are gone.
    }
};
