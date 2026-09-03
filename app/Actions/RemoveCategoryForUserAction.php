<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class RemoveCategoryForUserAction
{
    /**
     * "Deleting" a category only ever removes the acting user's own adoption/usage — detaches it
     * from all of the user's own transactions and drops their pivot row. The shared categories
     * row itself is never touched (it may end up an orphan with zero adopters, which is fine by
     * design — see the pre-launch audit note this whole feature stems from). This also sidesteps
     * a separate pre-existing latent bug: category_transaction's FK has no cascade, so directly
     * calling Category::delete() on a row with any transactions attached would likely throw.
     *
     * Also deactivates (never deletes) any of the user's own autocategorize rules that assign
     * this category — a rule still pointing at a category the user no longer sees in their own
     * picker would keep silently applying it, which is more confusing than just turning it off.
     */
    public static function run(User $user, Category $category): void
    {
        DB::transaction(function () use ($user, $category): void {
            $ownedAccountIds = $user->accounts()->pluck('accounts.id');

            $transactions = Transaction::query()
                ->whereIn('account_id', $ownedAccountIds)
                ->whereHas('categories', fn (Builder $query): Builder => $query->where('categories.id', $category->id))
                ->get();

            foreach ($transactions as $transaction) {
                $transaction->categories()->detach($category->id);
            }

            $user->categories()->detach($category->id);

            $user->categoryRules()->where('category_id', $category->id)->update(['active' => false]);
        });
    }
}
