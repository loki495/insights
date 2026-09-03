<?php

declare(strict_types=1);

namespace App\Actions\Models\CategoryRule;

use App\Models\CategoryRule;
use Illuminate\Support\Facades\DB;

final class UpdateCategoryRule
{
    /**
     * Groups (and their conditions) are always fully replaced rather than diffed — the
     * sentence-builder UI sends its whole group/condition tree on every save, and there's no
     * identity for a group or condition that anything else would ever need preserved.
     *
     * @param  array<int, array{match_type: string, conditions: array<int, array{field: string, operator: string, value: ?string, value_end: ?string}>}>  $groups
     */
    public static function run(CategoryRule $rule, int $categoryId, ?string $name, string $matchType, array $groups): CategoryRule
    {
        return DB::transaction(function () use ($rule, $categoryId, $name, $matchType, $groups): CategoryRule {
            $rule->update([
                'category_id' => $categoryId,
                'name' => $name,
                'match_type' => $matchType,
            ]);

            $rule->conditionGroups()->delete();

            foreach ($groups as $position => $group) {
                $groupModel = $rule->conditionGroups()->create([
                    'match_type' => $group['match_type'],
                    'position' => $position,
                ]);

                foreach ($group['conditions'] as $condition) {
                    $groupModel->conditions()->create($condition);
                }
            }

            return $rule;
        });
    }
}
