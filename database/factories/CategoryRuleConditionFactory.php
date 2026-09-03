<?php

namespace Database\Factories;

use App\Models\CategoryRuleCondition;
use App\Models\CategoryRuleConditionGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CategoryRuleCondition>
 */
class CategoryRuleConditionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_rule_condition_group_id' => CategoryRuleConditionGroup::factory(),
            'field' => 'merchant_name',
            'operator' => 'contains',
            'value' => fake()->word(),
            'value_end' => null,
        ];
    }
}
