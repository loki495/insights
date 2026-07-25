<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\CreateOrAdoptCategoryAction;
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
    /**
     * Adopted-only — a category the acting user hasn't adopted (see User::categories()) never
     * shows up in the picker/suggestions/chips. Decorates each with its per-user pivot color so
     * every existing `$category->color` read elsewhere keeps working unchanged.
     */
    #[Computed]
    public function categories()
    {
        return auth()->user()->categories()->get()
            ->each(fn ($category) => $category->setAttribute('color', $category->pivot->color ?: '#3b82f6'))
            ->sortBy('fullName')
            ->values();
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

        // Scoped to the acting user's own accounts — without this, suggestions were being
        // computed from every user's transaction history system-wide, a cross-tenant leak.
        $ownedAccountIds = auth()->user()->accounts()->pluck('accounts.id');

        return Transaction::query()
            ->whereIn('account_id', $ownedAccountIds)
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

        // Scoped to the acting user's own accounts — same cross-tenant fix as merchantSuggestions().
        $ownedAccountIds = auth()->user()->accounts()->pluck('accounts.id');

        $query = Transaction::query()->whereIn('account_id', $ownedAccountIds)->where('id', '!=', $transaction->id);
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

        $color = $color ?: '#3b82f6';
        $category = CreateOrAdoptCategoryAction::run(auth()->user(), $parent_id ?: null, $name, $color);

        return [
            'id' => $category->id,
            'name' => $category->name,
            'full_name' => $category->fullName,
            'parent_id' => $category->parent_id ?: 0,
            'color' => $color,
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
