<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class EditUserCategoryAction
{
    /**
     * "Editing" a category never mutates the shared row in place — it find-or-creates a category
     * matching the new (parent_id, name), reassigns the acting user's own transactions from the
     * old category onto the new one, and repoints the user's own pivot. The old shared row is
     * left untouched (and may end up an orphan with zero adopters — acceptable by design, see
     * app/Actions/RemoveCategoryForUserAction.php).
     *
     * Scoped to *all* of the user's owned accounts, not just tracked ones — tracked/excluded is a
     * reporting-display concept, not an ownership one, and using it here would silently skip
     * reassigning reference/excluded accounts' transactions.
     *
     * $description isn't part of the (parent_id, name) identity used for dedup, so a
     * description-only edit finds the *same* row — there's no "new row" to move the change onto.
     * description was already shared/unscoped under the old fully-global model (unlike
     * color/ownership), so this just updates the matched row's description directly when it
     * differs — a continuation of existing behavior, not a new risk.
     */
    public static function run(User $user, Category $old, ?int $newParentId, string $newName, ?string $description, ?string $color): Category
    {
        return DB::transaction(function () use ($user, $old, $newParentId, $newName, $description, $color): Category {
            $new = FindOrCreateCategoryAction::run($newParentId, $newName);

            if ($new->description !== $description) {
                $new->update(['description' => $description]);
            }

            if ($new->id !== $old->id) {
                $ownedAccountIds = $user->accounts()->pluck('accounts.id');

                $transactions = Transaction::query()
                    ->whereIn('account_id', $ownedAccountIds)
                    ->whereHas('categories', fn (Builder $query): Builder => $query->where('categories.id', $old->id))
                    ->get();

                foreach ($transactions as $transaction) {
                    $transaction->categories()->detach($old->id);
                    $transaction->categories()->syncWithoutDetaching([$new->id]);
                }

                $user->categories()->detach($old->id);
            }

            AdoptCategoryForUserAction::run($user, $new, $color);

            return $new;
        });
    }
}
