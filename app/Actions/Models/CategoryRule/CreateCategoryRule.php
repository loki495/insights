<?php

declare(strict_types=1);

namespace App\Actions\Models\CategoryRule;

use App\Models\CategoryRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CreateCategoryRule
{
    /**
     * @param  array<int, array{match_type: string, conditions: array<int, array{field: string, operator: string, value: ?string, value_end: ?string}>}>  $groups
     */
    public static function run(User $user, int $categoryId, ?string $name, string $matchType, array $groups): CategoryRule
    {
        return DB::transaction(function () use ($user, $categoryId, $name, $matchType, $groups): CategoryRule {
            $priority = $user->categoryRules()->max('priority');

            $rule = $user->categoryRules()->create([
                'category_id' => $categoryId,
                'name' => $name,
                'match_type' => $matchType,
                'priority' => $priority === null ? 0 : $priority + 1,
                'active' => true,
            ]);

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
