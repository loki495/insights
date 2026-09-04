<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\CategoryRuleConditionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class CategoryRuleCondition extends Model
{
    /** @use HasFactory<CategoryRuleConditionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    #[\Override]
    protected $fillable = [
        'field',
        'operator',
        'value',
        'value_end',
    ];

    /**
     * @return BelongsTo<CategoryRuleConditionGroup, $this>
     */
    public function conditionGroup(): BelongsTo
    {
        return $this->belongsTo(CategoryRuleConditionGroup::class, 'category_rule_condition_group_id');
    }

    public function matches(Transaction $transaction): bool
    {
        return match ($this->field) {
            'name' => $this->matchesText($transaction->name),
            'merchant_name' => $this->matchesText($transaction->merchant_name),
            'amount' => $this->matchesAmount((float) $transaction->amount),
            'account_id' => $this->matchesAccountId($transaction->account_id),
            'date' => $this->matchesDate($transaction->created_at),
            default => false,
        };
    }

    private function matchesText(?string $subject): bool
    {
        if ($subject === null || $this->value === null) {
            return false;
        }

        $lowerSubject = mb_strtolower($subject);
        $lowerValue = mb_strtolower($this->value);

        return match ($this->operator) {
            'contains' => str_contains($lowerSubject, $lowerValue),
            'equals' => $lowerSubject === $lowerValue,
            'starts_with' => str_starts_with($lowerSubject, $lowerValue),
            // Matched against the original (non-lowercased) subject — a regex author may rely
            // on case (or add their own /i flag), unlike the plain-text operators above, which
            // are always case-insensitive. Suppressed: an invalid pattern should mean "this
            // condition never matches", not a 500 the moment a user mistypes a regex —
            // preg_match() returns false on failure, which the strict `=== 1` already treats as
            // no-match.
            'regex' => @preg_match($this->value, $subject) === 1,
            default => false,
        };
    }

    private function matchesAmount(float $amount): bool
    {
        if ($this->value === null || ! is_numeric($this->value)) {
            return false;
        }

        // Magnitude, not signed value — matches the transaction list's own amount-range filter
        // convention (BuildTransactionsQueryAction), where "between $50 and $200" shouldn't
        // require the user to also know/guess the sign.
        $subject = abs($amount);
        $value = (float) $this->value;

        return match ($this->operator) {
            'equals' => abs($subject - $value) < 0.001,
            'greater_than' => $subject > $value,
            'less_than' => $subject < $value,
            'between' => $this->value_end !== null && is_numeric($this->value_end)
                && $subject >= $value && $subject <= (float) $this->value_end,
            default => false,
        };
    }

    private function matchesAccountId(int $accountId): bool
    {
        if ($this->value === null || ! is_numeric($this->value)) {
            return false;
        }

        return match ($this->operator) {
            'is' => (int) $this->value === $accountId,
            default => false,
        };
    }

    private function matchesDate(?CarbonInterface $transactionDate): bool
    {
        if (! $transactionDate instanceof CarbonInterface || $this->value === null) {
            return false;
        }

        $date = $transactionDate->copy()->startOfDay();
        $value = $this->parseDate($this->value);

        if (! $value instanceof Carbon) {
            return false;
        }

        return match ($this->operator) {
            'before' => $date->lt($value),
            'after' => $date->gt($value),
            'between' => ($valueEnd = $this->parseDate($this->value_end)) instanceof Carbon
                && $date->gte($value) && $date->lte($valueEnd),
            default => false,
        };
    }

    private function parseDate(?string $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Exception) {
            return null;
        }
    }
}
