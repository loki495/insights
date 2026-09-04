<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;

it('expands the current institution after navigating through its sidebar links', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
        'provider_name' => 'Navigation Bank',
    ]);
    $account = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '0000',
        'name' => 'Navigation Checking',
        'official_name' => 'Navigation Checking',
        'type' => 'depository',
        'subtype' => 'checking',
    ]);

    test()->actingAs($user);

    visit(route('linked-accounts.index'))
        ->resize(1280, 900)
        ->assertScript("document.querySelector('[data-testid=sidebar-account-{$account->id}]').getClientRects().length === 0")
        ->click("[data-testid=sidebar-institution-{$linkedAccount->id}] > [data-flux-navlist-group-heading] a")
        ->assertScript("window.location.pathname === '/linked-accounts/{$linkedAccount->id}/accounts'")
        ->assertScript("document.querySelector('[data-testid=sidebar-account-{$account->id}]').getClientRects().length > 0")
        ->click("[data-testid=sidebar-account-{$account->id}]")
        ->assertScript("window.location.pathname === '/linked-accounts/{$linkedAccount->id}/account/{$account->id}/transactions'")
        ->assertScript("document.querySelector('[data-testid=sidebar-account-{$account->id}]').getClientRects().length > 0");
});

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
    $secondAccount = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '1111',
        'name' => 'Savings',
        'official_name' => 'Savings',
        'type' => 'depository',
        'subtype' => 'savings',
        'current_balance' => 500,
    ]);
    $secondLinkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
        'provider_name' => 'Second Bank',
    ]);
    $thirdAccount = Account::factory()->for($secondLinkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '2222',
        'name' => 'Second Checking',
        'official_name' => 'Second Checking',
        'type' => 'depository',
        'subtype' => 'checking',
        'current_balance' => 250,
    ]);
    Transaction::factory()->for($account)->create([
        'name' => 'Coffee',
        'amount' => -5,
        'currency' => 'USD',
    ]);

    test()->actingAs($user);

    $page = visit(route('linked-accounts.accounts.show', [$linkedAccount, $account]))
        ->resize(1280, 900);

    $page
        ->assertScript("document.querySelector('[data-testid=sidebar-account-{$account->id}]').getClientRects().length > 0")
        ->assertScript("document.querySelector('[data-testid=sidebar-account-{$thirdAccount->id}]').getClientRects().length === 0")
        ->assertScript("document.querySelector('[data-testid=sidebar-institution-{$linkedAccount->id}] button').getBoundingClientRect().right <= document.querySelector('[data-testid=sidebar-institution-{$linkedAccount->id}] a').getBoundingClientRect().left")
        ->click("[data-testid=sidebar-institution-{$secondLinkedAccount->id}] button[aria-label='Toggle Second Bank accounts']");

    $metrics = $page->script(<<<JS
            (() => {
                const item = document.querySelector('[data-testid="sidebar-account-{$account->id}"]');
                const secondItem = document.querySelector('[data-testid="sidebar-account-{$secondAccount->id}"]');
                const thirdItem = document.querySelector('[data-testid="sidebar-account-{$thirdAccount->id}"]');
                const content = item?.querySelector('[data-content]');
                const accountName = content?.children[0];
                const accountBalance = content?.children[1];
                const badge = Array.from(item?.querySelectorAll('span') ?? []).find(
                    (element) => element.textContent.trim() === '1'
                );
                const institutionLink = document.querySelector('a[href$="/linked-accounts/{$linkedAccount->id}/accounts"]');
                const institutionHeading = institutionLink?.closest('.px-3');
                const secondInstitutionLink = document.querySelector('a[href$="/linked-accounts/{$secondLinkedAccount->id}/accounts"]');
                const secondInstitutionHeading = secondInstitutionLink?.closest('.px-3');
                const institutionGroup = institutionHeading?.closest('ui-disclosure');
                const topLevelGroup = institutionGroup?.parentElement?.closest('ui-disclosure');
                const topLevelButton = topLevelGroup?.querySelector(':scope > button');
                const nextTopLevelItem = document.querySelector('a[href$="/categories"]');

                if (! item || ! secondItem || ! thirdItem || ! content || ! accountName || ! accountBalance || ! badge || ! institutionHeading || ! secondInstitutionHeading || ! topLevelButton || ! nextTopLevelItem) return null;

                const contentBox = content.getBoundingClientRect();
                const accountNameBox = accountName.getBoundingClientRect();
                const accountBalanceBox = accountBalance.getBoundingClientRect();
                const badgeBox = badge.getBoundingClientRect();
                const headingBox = institutionHeading.getBoundingClientRect();
                const itemBox = item.getBoundingClientRect();
                const secondItemBox = secondItem.getBoundingClientRect();
                const thirdItemBox = thirdItem.getBoundingClientRect();
                const secondInstitutionHeadingBox = secondInstitutionHeading.getBoundingClientRect();
                const topLevelButtonBox = topLevelButton.getBoundingClientRect();
                const nextTopLevelItemBox = nextTopLevelItem.getBoundingClientRect();
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
                    accountGap: secondItemBox.top - itemBox.bottom,
                    institutionGap: secondInstitutionHeadingBox.top - secondItemBox.bottom,
                    firstChildGap: headingBox.top - topLevelButtonBox.bottom,
                    nextTopLevelGap: nextTopLevelItemBox.top - thirdItemBox.bottom,
                    badgeRightDifference: Math.abs(headingBox.right - badgeBox.right),
                    accountTextGap: accountBalanceBox.top - accountNameBox.bottom,
                };
            })()
            JS);

    expect($metrics)->not->toBeNull()
        ->and($metrics['centerDifference'])->toBeLessThanOrEqual(1)
        ->and($metrics['headingGap'])->toBeGreaterThanOrEqual(4)
        ->and($metrics['verticalInset'])->toBeGreaterThanOrEqual(1)
        ->and($metrics['accountGap'])->toBe(0)
        ->and($metrics['institutionGap'])->toBeGreaterThanOrEqual(8)
        ->and($metrics['firstChildGap'])->toBeGreaterThanOrEqual(4)
        ->and($metrics['nextTopLevelGap'])->toBeGreaterThanOrEqual(8)
        ->and($metrics['badgeRightDifference'])->toBeLessThanOrEqual(1)
        ->and($metrics['accountTextGap'])->toBeGreaterThanOrEqual(2);

    $institutionBackground = $page->script("getComputedStyle(document.querySelector('[data-flux-navlist-group-heading]')).backgroundColor");

    $page
        ->hover("[data-flux-navlist-group-heading] a[href$='/linked-accounts/{$linkedAccount->id}/accounts']")
        ->assertScript("getComputedStyle(document.querySelector('[data-flux-navlist-group-heading]')).backgroundColor !== ".json_encode($institutionBackground));

    $page
        ->click("[data-testid=sidebar-institution-{$linkedAccount->id}] button[aria-label='Toggle Test Bank accounts']")
        ->assertScript("document.querySelector('[data-testid=sidebar-account-{$account->id}]').getClientRects().length === 0")
        ->assertScript("document.querySelector('[data-testid=sidebar-account-{$secondAccount->id}]').getClientRects().length === 0")
        ->assertScript("document.querySelector('[data-testid=sidebar-account-{$thirdAccount->id}]').getClientRects().length > 0")
        ->click("[data-testid=sidebar-institution-{$linkedAccount->id}] button[aria-label='Toggle Test Bank accounts']")
        ->assertScript("document.querySelector('[data-testid=sidebar-account-{$account->id}]').getClientRects().length > 0");

    $page
        ->assertScript("getComputedStyle(document.querySelector('[data-testid=sidebar-account-{$account->id}]')).backgroundColor === 'rgba(0, 0, 0, 0)'")
        ->hover("[data-testid=sidebar-account-{$account->id}]")
        ->assertScript("getComputedStyle(document.querySelector('[data-testid=sidebar-account-{$account->id}]')).backgroundColor !== 'rgba(0, 0, 0, 0)'");
});
