<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CategoryRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CategoryRule extends Model
{
    /** @use HasFactory<CategoryRuleFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'category_id',
        'name',
        'match_type',
        'priority',
        'active',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return HasMany<CategoryRuleConditionGroup, $this>
     */
    public function conditionGroups(): HasMany
    {
        return $this->hasMany(CategoryRuleConditionGroup::class)->orderBy('position');
    }

    /**
     * A rule is a list of groups combined by $match_type — each group is its own AND/OR of
     * plain conditions (CategoryRuleConditionGroup::matches()), which is what lets a rule
     * express "(X and Y) or Z": match_type='any' across two groups, the first holding [X, Y]
     * with its own match_type='all', the second holding just [Z]. One level of nesting only.
     *
     * A rule with no groups (or every group empty) never matches — an empty AND would
     * otherwise be vacuously true and silently categorize every transaction it's checked
     * against.
     */
    public function matches(Transaction $transaction): bool
    {
        if ($this->conditionGroups->isEmpty()) {
            return false;
        }

        return match ($this->match_type) {
            'any' => $this->conditionGroups->contains(fn (CategoryRuleConditionGroup $group): bool => $group->matches($transaction)),
            default => $this->conditionGroups->every(fn (CategoryRuleConditionGroup $group): bool => $group->matches($transaction)),
        };
    }
}
