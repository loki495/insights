<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CategoryRuleConditionGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CategoryRuleConditionGroup extends Model
{
    /** @use HasFactory<CategoryRuleConditionGroupFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    #[\Override]
    protected $fillable = [
        'match_type',
        'position',
    ];

    /**
     * @return BelongsTo<CategoryRule, $this>
     */
    public function categoryRule(): BelongsTo
    {
        return $this->belongsTo(CategoryRule::class);
    }

    /**
     * @return HasMany<CategoryRuleCondition, $this>
     */
    public function conditions(): HasMany
    {
        return $this->hasMany(CategoryRuleCondition::class);
    }

    /**
     * A group with no conditions never matches — same reasoning as CategoryRule::matches(): an
     * empty AND would otherwise be vacuously true.
     */
    public function matches(Transaction $transaction): bool
    {
        if ($this->conditions->isEmpty()) {
            return false;
        }

        return match ($this->match_type) {
            'any' => $this->conditions->contains(fn (CategoryRuleCondition $condition): bool => $condition->matches($transaction)),
            default => $this->conditions->every(fn (CategoryRuleCondition $condition): bool => $condition->matches($transaction)),
        };
    }
}
