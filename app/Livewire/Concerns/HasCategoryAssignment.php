<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\Category;
use App\Models\Transaction;
use Closure;
use InvalidArgumentException;
use Livewire\Attributes\Computed;

/**
 * Category browsing/assignment for the transaction list: the picker's option/lookup arrays,
 * merchant- and original-category-based suggestions, and single/bulk assignment. Host components
 * only need to provide `chartNeedsRefresh`, the flag these actions set to trigger a chart refresh —
 * the `$categories` collection itself comes from `#[Computed] categories()` below, which this
 * trait provides.
 */
trait HasCategoryAssignment
{
    #[Computed]
    public function categories()
    {
        return Category::all()->sortBy('fullName')->values();
    }

    #[Computed]
    public function categoryPickerOptions(): array
    {
        return $this->categories
            ->map(fn ($category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'full_name' => $category->fullName,
                'parent_id' => $category->parent_id ?: 0,
                'color' => $category->color ?: '#3b82f6',
            ])
            ->values()
            ->toArray();
    }

    #[Computed]
    public function categoryPickerLookup(): array
    {
        return $this->categories
            ->mapWithKeys(fn ($category): array => [
                $category->id => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'full_name' => $category->fullName,
                    'parent_id' => $category->parent_id ?: 0,
                    'color' => $category->color ?: '#3b82f6',
                ],
            ])
            ->toArray();
    }

    /**
     * For each distinct merchant on the current page, find the category most
     * commonly used on other transactions from that merchant. Doubles as the
     * groundwork for a future auto-categorization rule engine.
     */
    private function merchantSuggestions($transactions): array
    {
        $merchants = collect($transactions)
            ->pluck('merchant_name')
            ->filter()
            ->unique()
            ->values();

        if ($merchants->isEmpty()) {
            return [];
        }

        return Transaction::query()
            ->whereIn('merchant_name', $merchants)
            ->whereHas('categories')
            ->with('categories')
            ->get()
            ->groupBy('merchant_name')
            ->map(function ($merchantTransactions): ?array {
                $topCategoryId = $merchantTransactions
                    ->flatMap->categories
                    ->countBy('id')
                    ->sortDesc()
                    ->keys()
                    ->first();

                if (! $topCategoryId) {
                    return null;
                }

                $category = $this->categories->firstWhere('id', $topCategoryId);

                return $category ? [
                    'id' => $category->id,
                    'name' => $category->fullName,
                    'color' => $category->color ?: '#3b82f6',
                ] : null;
            })
            ->filter()
            ->toArray();
    }

    public function saveCategory($transaction_id, $category_id): void
    {
        $transaction = Transaction::findOrFail($transaction_id);
        $this->authorize('update', $transaction);
        $transaction->categories()->sync([$category_id]);
        $transaction->save();
        $this->chartNeedsRefresh = true;
    }

    /**
     * Up to two best-guess categories for a single transaction, used to seed
     * the category picker: the category most commonly assigned to other
     * transactions from the same merchant, and separately the category most
     * commonly assigned to other transactions sharing the same Plaid
     * original category (catches cases merchant_name is missing or too
     * inconsistent to match on, since Plaid's own categorization is usually
     * present). Already-assigned categories are excluded.
     */
    public function suggestCategoriesForTransaction($transaction_id): array
    {
        $transaction = Transaction::findOrFail($transaction_id);
        $this->authorize('view', $transaction);

        $currentCategoryIds = $transaction->categories->pluck('id');

        return collect([
            $this->topCategoryFor(fn ($query) => $query->where('merchant_name', $transaction->merchant_name), $transaction, (bool) $transaction->merchant_name),
            $this->topCategoryFor(fn ($query) => $query->where('original_category_id', $transaction->original_category_id), $transaction, (bool) $transaction->original_category_id),
        ])
            ->filter()
            ->unique('id')
            ->reject(fn ($suggestion) => $currentCategoryIds->contains($suggestion['id']))
            ->take(2)
            ->values()
            ->toArray();
    }

    private function topCategoryFor(Closure $scope, Transaction $transaction, bool $enabled): ?array
    {
        if (! $enabled) {
            return null;
        }

        $query = Transaction::query()->where('id', '!=', $transaction->id);
        $scope($query);

        $topCategoryId = $query
            ->whereHas('categories')
            ->with('categories')
            ->get()
            ->flatMap->categories
            ->countBy('id')
            ->sortDesc()
            ->keys()
            ->first();

        if (! $topCategoryId) {
            return null;
        }

        $category = $this->categories->firstWhere('id', $topCategoryId);

        return $category ? [
            'id' => $category->id,
            'name' => $category->fullName,
            'color' => $category->color ?: '#3b82f6',
        ] : null;
    }

    public function createCategory(string $name, ?int $parent_id, ?string $color): array
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('Category name is required.');
        }

        $category = Category::create([
            'name' => $name,
            'parent_id' => $parent_id ?: 0,
            'color' => $color ?: '#3b82f6',
        ]);

        return [
            'id' => $category->id,
            'name' => $category->name,
            'full_name' => $category->fullName,
            'parent_id' => $category->parent_id ?: 0,
            'color' => $category->color,
        ];
    }

    public function clearCategory($transaction_id): void
    {
        $transaction = Transaction::findOrFail($transaction_id);
        $this->authorize('update', $transaction);
        $transaction->categories()->sync([]);
        $this->chartNeedsRefresh = true;
    }

    public function bulkAssignCategory($category_id, array $transaction_ids): void
    {
        $transactions = Transaction::whereIn('id', $transaction_ids)->get();

        foreach ($transactions as $transaction) {
            $this->authorize('update', $transaction);
            $transaction->categories()->sync([$category_id]);
        }

        $this->chartNeedsRefresh = true;
    }
}
