<?php

declare(strict_types=1);

namespace App\Actions\Models\CategoryRule;

use App\Models\CategoryRule;

final class DeleteCategoryRule
{
    public static function run(CategoryRule $rule): void
    {
        $rule->delete();
    }
}
