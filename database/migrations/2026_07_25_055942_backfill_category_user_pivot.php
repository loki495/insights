<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Categories were fully shared/global before this change, so there's no "who gets which
     * copy" decision here — every existing user adopts every existing category, carrying over its
     * current color. This is an N×M cross product; fine at this app's real scale (a handful of
     * users x at most a few hundred categories), not something to copy-paste somewhere that needs
     * to scale further.
     */
    public function up(): void
    {
        $colors = DB::table('categories')->pluck('color', 'id');
        $userIds = DB::table('users')->pluck('id');
        $categoryIds = DB::table('categories')->pluck('id');
        $now = now();

        $rows = [];

        foreach ($userIds as $userId) {
            foreach ($categoryIds as $categoryId) {
                $rows[] = [
                    'user_id' => $userId,
                    'category_id' => $categoryId,
                    'color' => $colors[$categoryId] ?: '#3b82f6',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('category_user')->insert($chunk);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('category_user')->truncate();
    }
};
