<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;

it('aligns sidebar account rows without a resting background and highlights them on hover', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
        'provider_name' => 'Test Bank',
    ]);
    $account = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '0000',
        'name' => 'Everyday Checking',
        'official_name' => 'Everyday Checking',
        'type' => 'depository',
        'subtype' => 'checking',
        'current_balance' => 1250,
    ]);
    Transaction::factory()->for($account)->create([
        'name' => 'Coffee',
        'amount' => -5,
        'currency' => 'USD',
    ]);

    test()->actingAs($user);

    $page = visit(route('linked-accounts.accounts.show', [$linkedAccount, $account]))
        ->resize(1280, 900);

    $metrics = $page->script(<<<JS
            (() => {
                const item = document.querySelector('[data-testid="sidebar-account-{$account->id}"]');
                const content = item?.querySelector('[data-content]');
                const badge = Array.from(item?.querySelectorAll('span') ?? []).find(
                    (element) => element.textContent.trim() === '1'
                );
                const institutionLink = document.querySelector('a[href$="/linked-accounts/{$linkedAccount->id}/accounts"]');
                const institutionHeading = institutionLink?.closest('.px-3');

                if (! item || ! content || ! badge || ! institutionHeading) return null;

                const contentBox = content.getBoundingClientRect();
                const badgeBox = badge.getBoundingClientRect();
                const headingBox = institutionHeading.getBoundingClientRect();
                const itemBox = item.getBoundingClientRect();
                const centerDifference = Math.abs(
                    (contentBox.top + contentBox.height / 2) - (badgeBox.top + badgeBox.height / 2)
                );
                const verticalInset = Math.min(
                    contentBox.top - itemBox.top,
                    itemBox.bottom - contentBox.bottom
                );

                return {
                    centerDifference,
                    headingGap: itemBox.top - headingBox.bottom,
                    verticalInset,
                };
            })()
            JS);

    expect($metrics)->not->toBeNull()
        ->and($metrics['centerDifference'])->toBeLessThanOrEqual(1)
        ->and($metrics['headingGap'])->toBeGreaterThanOrEqual(4)
        ->and($metrics['verticalInset'])->toBeGreaterThanOrEqual(10);

    $page
        ->assertScript("getComputedStyle(document.querySelector('[data-testid=sidebar-account-{$account->id}]')).backgroundColor === 'rgba(0, 0, 0, 0)'")
        ->hover("[data-testid=sidebar-account-{$account->id}]")
        ->assertScript("getComputedStyle(document.querySelector('[data-testid=sidebar-account-{$account->id}]')).backgroundColor !== 'rgba(0, 0, 0, 0)'");
});
