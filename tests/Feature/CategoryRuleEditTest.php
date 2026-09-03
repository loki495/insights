<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\CategoryRule;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

function makeCategoryRuleEditTestAccount(User $user): Account
{
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(), 'access_token' => 'access_'.uniqid(),
    ]);

    return Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '0000', 'name' => 'Checking', 'official_name' => 'Checking Official',
        'type' => 'depository', 'subtype' => 'checking',
    ]);
}

it('creates a rule with one group and one condition via save()', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);
    $category = categoryFor($user, 'Coffee');

    $test = Livewire::test('admin.category-rules.edit', ['categoryRule' => null]);
    $test->set('category_id', $category->id)
        ->set('groups.0.conditions.0.field', 'merchant_name')
        ->set('groups.0.conditions.0.operator', 'contains')
        ->set('groups.0.conditions.0.value', 'starbucks')
        ->call('save');

    $rule = CategoryRule::where('user_id', $user->id)->firstOrFail();
    expect($rule->category_id)->toBe($category->id)
        ->and($rule->conditionGroups)->toHaveCount(1)
        ->and($rule->conditionGroups->first()->conditions->first()->value)->toBe('starbucks');
});

it('creates a rule expressing "(X and Y) or Z" across two groups', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);
    $category = categoryFor($user, 'Coffee');

    $test = Livewire::test('admin.category-rules.edit', ['categoryRule' => null]);
    $test->set('category_id', $category->id)
        ->set('match_type', 'any')
        ->set('groups.0.match_type', 'all')
        ->set('groups.0.conditions.0.field', 'merchant_name')
        ->set('groups.0.conditions.0.operator', 'contains')
        ->set('groups.0.conditions.0.value', 'starbucks')
        ->call('addCondition', 0)
        ->set('groups.0.conditions.1.field', 'amount')
        ->set('groups.0.conditions.1.operator', 'less_than')
        ->set('groups.0.conditions.1.value', '10')
        ->call('addGroup')
        ->set('groups.1.conditions.0.field', 'amount')
        ->set('groups.1.conditions.0.operator', 'greater_than')
        ->set('groups.1.conditions.0.value', '1000')
        ->call('save');

    $rule = CategoryRule::where('user_id', $user->id)->firstOrFail();
    expect($rule->match_type)->toBe('any')
        ->and($rule->conditionGroups)->toHaveCount(2)
        ->and($rule->conditionGroups->first()->conditions)->toHaveCount(2)
        ->and($rule->conditionGroups->last()->conditions)->toHaveCount(1);
});

it('loads an existing rule\'s groups and conditions into the form on edit', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);
    $category = categoryFor($user, 'Coffee');
    $rule = CategoryRule::factory()->for($user)->for($category)->create(['name' => 'My Rule', 'match_type' => 'any']);
    $group = $rule->conditionGroups()->create(['match_type' => 'all', 'position' => 0]);
    $group->conditions()->create(['field' => 'amount', 'operator' => 'less_than', 'value' => '10']);

    $test = Livewire::test('admin.category-rules.edit', ['categoryRule' => $rule]);

    $test->assertSet('name', 'My Rule')
        ->assertSet('match_type', 'any')
        ->assertSet('groups.0.match_type', 'all')
        ->assertSet('groups.0.conditions.0.field', 'amount')
        ->assertSet('groups.0.conditions.0.value', '10');
});

it('refuses to load another user\'s rule for editing', function (): void {
    $owner = User::factory()->create();
    $category = categoryFor($owner, 'Coffee');
    $rule = CategoryRule::factory()->for($owner)->for($category)->create();

    $stranger = User::factory()->create();
    test()->actingAs($stranger);

    Livewire::test('admin.category-rules.edit', ['categoryRule' => $rule])->assertForbidden();
});

it('adds and removes conditions within a group', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);

    $test = Livewire::test('admin.category-rules.edit', ['categoryRule' => null]);
    expect($test->get('groups.0.conditions'))->toHaveCount(1);

    $test->call('addCondition', 0);
    expect($test->get('groups.0.conditions'))->toHaveCount(2);

    $test->call('removeCondition', 0, 0);
    expect($test->get('groups.0.conditions'))->toHaveCount(1);
});

it('removing the last condition in a group leaves one blank condition behind, never zero', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);

    $test = Livewire::test('admin.category-rules.edit', ['categoryRule' => null]);
    $test->call('removeCondition', 0, 0);

    expect($test->get('groups.0.conditions'))->toHaveCount(1);
});

it('adds and removes groups', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);

    $test = Livewire::test('admin.category-rules.edit', ['categoryRule' => null]);
    expect($test->get('groups'))->toHaveCount(1);

    $test->call('addGroup');
    expect($test->get('groups'))->toHaveCount(2);

    $test->call('removeGroup', 0);
    expect($test->get('groups'))->toHaveCount(1);
});

it('removing the last group leaves one blank group behind, never zero', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);

    $test = Livewire::test('admin.category-rules.edit', ['categoryRule' => null]);
    $test->call('removeGroup', 0);

    expect($test->get('groups'))->toHaveCount(1);
});

it('shows the live count of currently-matching uncategorized transactions', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);
    $account = makeCategoryRuleEditTestAccount($user);
    Transaction::factory()->for($account)->create(['name' => 'Coffee', 'merchant_name' => 'Starbucks', 'amount' => -5, 'currency' => 'USD']);
    Transaction::factory()->for($account)->create(['name' => 'Groceries', 'merchant_name' => 'Whole Foods', 'amount' => -20, 'currency' => 'USD']);

    $test = Livewire::test('admin.category-rules.edit', ['categoryRule' => null]);
    $test->set('groups.0.conditions.0.field', 'merchant_name')
        ->set('groups.0.conditions.0.operator', 'contains')
        ->set('groups.0.conditions.0.value', 'starbucks');

    $test->assertSee('1 matching uncategorized transaction');
});

it('validation rejects saving without a category selected', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);

    $test = Livewire::test('admin.category-rules.edit', ['categoryRule' => null]);
    $test->set('groups.0.conditions.0.value', 'starbucks')->call('save');

    $test->assertHasErrors(['category_id']);
});

it('lists the currently-matching uncategorized transactions in the live preview', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);
    $account = makeCategoryRuleEditTestAccount($user);
    $matching = Transaction::factory()->for($account)->create(['name' => 'Coffee', 'merchant_name' => 'Starbucks', 'amount' => -5, 'currency' => 'USD']);
    Transaction::factory()->for($account)->create(['name' => 'Groceries', 'merchant_name' => 'Whole Foods', 'amount' => -20, 'currency' => 'USD']);

    $test = Livewire::test('admin.category-rules.edit', ['categoryRule' => null]);
    $test->set('groups.0.conditions.0.field', 'merchant_name')
        ->set('groups.0.conditions.0.operator', 'contains')
        ->set('groups.0.conditions.0.value', 'starbucks');

    $test->assertSee($matching->merchant_name)
        ->assertDontSee('Whole Foods')
        ->assertSee('Apply to 1 existing transaction');
});

it('hides the apply-to-existing button when nothing currently matches', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);

    $test = Livewire::test('admin.category-rules.edit', ['categoryRule' => null]);

    $test->assertDontSee('existing transaction');
});

it('applying to existing transactions saves the rule and categorizes every currently-matching transaction', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);
    $account = makeCategoryRuleEditTestAccount($user);
    $category = categoryFor($user, 'Coffee');
    $matching = Transaction::factory()->for($account)->create(['name' => 'Coffee', 'merchant_name' => 'Starbucks', 'amount' => -5, 'currency' => 'USD']);
    $nonMatching = Transaction::factory()->for($account)->create(['name' => 'Groceries', 'merchant_name' => 'Whole Foods', 'amount' => -20, 'currency' => 'USD']);

    $test = Livewire::test('admin.category-rules.edit', ['categoryRule' => null]);
    $test->set('category_id', $category->id)
        ->set('groups.0.conditions.0.field', 'merchant_name')
        ->set('groups.0.conditions.0.operator', 'contains')
        ->set('groups.0.conditions.0.value', 'starbucks')
        ->call('applyToExistingTransactions');

    $test->assertSee('Applied to 1 existing transaction.');

    $rule = CategoryRule::where('user_id', $user->id)->firstOrFail();
    expect($rule->conditionGroups)->toHaveCount(1)
        ->and($matching->fresh()->categories()->pluck('categories.id')->all())->toBe([$category->id])
        ->and($nonMatching->fresh()->categories()->count())->toBe(0);
});

it('applying to existing transactions on an already-saved rule updates it in place rather than creating a duplicate', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);
    $account = makeCategoryRuleEditTestAccount($user);
    $category = categoryFor($user, 'Coffee');
    $rule = CategoryRule::factory()->for($user)->for($category)->create();
    $group = $rule->conditionGroups()->create(['match_type' => 'all', 'position' => 0]);
    $group->conditions()->create(['field' => 'merchant_name', 'operator' => 'contains', 'value' => 'starbucks']);
    $matching = Transaction::factory()->for($account)->create(['name' => 'Coffee', 'merchant_name' => 'Starbucks', 'amount' => -5, 'currency' => 'USD']);

    Livewire::test('admin.category-rules.edit', ['categoryRule' => $rule])
        ->call('applyToExistingTransactions')
        ->assertSee('Applied to 1 existing transaction.');

    expect(CategoryRule::where('user_id', $user->id)->count())->toBe(1)
        ->and($matching->fresh()->categories()->pluck('categories.id')->all())->toBe([$category->id]);
});

it('applying to existing transactions on another user\'s rule is forbidden', function (): void {
    $owner = User::factory()->create();
    $category = categoryFor($owner, 'Coffee');
    $rule = CategoryRule::factory()->for($owner)->for($category)->create();

    $stranger = User::factory()->create();
    test()->actingAs($stranger);

    Livewire::test('admin.category-rules.edit', ['categoryRule' => $rule])->assertForbidden();
});
