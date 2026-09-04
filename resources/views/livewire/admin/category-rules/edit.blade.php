<?php

declare(strict_types=1);

use App\Actions\ApplyCategoryRuleRetroactivelyAction;
use App\Actions\FindMatchingTransactionsForCategoryRuleAction;
use App\Actions\Models\CategoryRule\CreateCategoryRule;
use App\Actions\Models\CategoryRule\UpdateCategoryRule;
use App\Models\CategoryRule;
use App\Models\CategoryRuleCondition;
use App\Models\CategoryRuleConditionGroup;
use Livewire\Volt\Component;

new class extends Component
{
    public ?CategoryRule $categoryRule = null;

    public ?int $category_id = null;

    public ?string $name = null;

    /**
     * How the groups below combine — irrelevant with only one group, which is the common case.
     */
    public string $match_type = 'all';

    /**
     * @var array<int, array{match_type: string, conditions: array<int, array{field: string, operator: string, value: ?string, value_end: ?string}>}>
     */
    public array $groups = [];

    public ?string $statusMessage = null;

    public function mount(?CategoryRule $categoryRule): void
    {
        if ($categoryRule && $categoryRule->exists) {
            $this->authorize('update', $categoryRule);

            $this->categoryRule = $categoryRule;
            $this->category_id = $categoryRule->category_id;
            $this->name = $categoryRule->name;
            $this->match_type = $categoryRule->match_type;
            $this->groups = $categoryRule->conditionGroups->map(fn (CategoryRuleConditionGroup $group): array => [
                'match_type' => $group->match_type,
                'conditions' => $group->conditions->map(fn (CategoryRuleCondition $condition): array => [
                    'field' => $condition->field,
                    'operator' => $condition->operator,
                    'value' => $condition->value,
                    'value_end' => $condition->value_end,
                ])->all(),
            ])->all();
        } else {
            $this->authorize('create', CategoryRule::class);

            $this->categoryRule = null;
        }

        if ($this->groups === []) {
            $this->addGroup();
        }
    }

    public function addGroup(): void
    {
        $this->groups[] = ['match_type' => 'all', 'conditions' => [$this->blankCondition()]];
    }

    public function removeGroup(int $groupIndex): void
    {
        unset($this->groups[$groupIndex]);
        $this->groups = array_values($this->groups);

        if ($this->groups === []) {
            $this->addGroup();
        }
    }

    public function addCondition(int $groupIndex): void
    {
        $this->groups[$groupIndex]['conditions'][] = $this->blankCondition();
    }

    public function removeCondition(int $groupIndex, int $conditionIndex): void
    {
        unset($this->groups[$groupIndex]['conditions'][$conditionIndex]);
        $this->groups[$groupIndex]['conditions'] = array_values($this->groups[$groupIndex]['conditions']);

        if ($this->groups[$groupIndex]['conditions'] === []) {
            $this->groups[$groupIndex]['conditions'][] = $this->blankCondition();
        }
    }

    /**
     * @return array{field: string, operator: string, value: string, value_end: null}
     */
    private function blankCondition(): array
    {
        return ['field' => 'merchant_name', 'operator' => 'contains', 'value' => '', 'value_end' => null];
    }

    /**
     * @return array<string, string>
     */
    public function operatorsFor(string $field): array
    {
        return match ($field) {
            'name', 'merchant_name' => ['contains' => 'contains', 'equals' => 'equals', 'starts_with' => 'starts with', 'regex' => 'regex (advanced)'],
            'amount' => ['equals' => 'equals', 'greater_than' => 'greater than', 'less_than' => 'less than', 'between' => 'between'],
            'account_id' => ['is' => 'is'],
            'date' => ['before' => 'before', 'after' => 'after', 'between' => 'between'],
            default => [],
        };
    }

    public function save(): void
    {
        $this->persistRule($this->validate($this->validationRules()));

        $this->redirect(route('category-rules.index'), navigate: true);
    }

    /**
     * Saves the rule (creating it first if this is still the "create" form), then immediately
     * applies it to every currently-matching uncategorized transaction shown in the preview list
     * below — reusing that exact same candidate set so there's no surprise between what was
     * shown and what happened.
     */
    public function applyToExistingTransactions(): void
    {
        $rule = $this->persistRule($this->validate($this->validationRules()));

        $applied = ApplyCategoryRuleRetroactivelyAction::run($rule);

        $this->statusMessage = $applied === 1
            ? 'Applied to 1 existing transaction.'
            : "Applied to {$applied} existing transactions.";
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function validationRules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'name' => ['nullable', 'string', 'max:255'],
            'match_type' => ['required', 'in:all,any'],
            'groups' => ['required', 'array', 'min:1'],
            'groups.*.match_type' => ['required', 'in:all,any'],
            'groups.*.conditions' => ['required', 'array', 'min:1'],
            'groups.*.conditions.*.field' => ['required', 'in:name,merchant_name,amount,account_id,date'],
            'groups.*.conditions.*.operator' => ['required', 'string'],
            'groups.*.conditions.*.value' => ['nullable', 'string'],
            'groups.*.conditions.*.value_end' => ['nullable', 'string'],
        ];
    }

    /**
     * @param  array{category_id: int, name: ?string, match_type: string, groups: array<int, array{match_type: string, conditions: array<int, array{field: string, operator: string, value: ?string, value_end: ?string}>}>}  $validated
     */
    private function persistRule(array $validated): CategoryRule
    {
        if ($this->categoryRule) {
            $this->authorize('update', $this->categoryRule);

            $rule = UpdateCategoryRule::run(
                $this->categoryRule,
                $validated['category_id'],
                $validated['name'],
                $validated['match_type'],
                $validated['groups'],
            );
        } else {
            $this->authorize('create', CategoryRule::class);

            $rule = CreateCategoryRule::run(
                auth()->user(),
                $validated['category_id'],
                $validated['name'],
                $validated['match_type'],
                $validated['groups'],
            );

            $this->categoryRule = $rule;
        }

        return $rule->fresh(['conditionGroups.conditions']);
    }

    public function with(): array
    {
        $matching = FindMatchingTransactionsForCategoryRuleAction::run(auth()->user(), $this->buildPreviewRule());

        return [
            'categories' => auth()->user()->categories()->orderBy('name')->get(),
            'accounts' => auth()->user()->accounts()->active()->with('linked_account')->get(),
            'matchingTransactions' => $matching->take(25),
            'matchingCount' => $matching->count(),
        ];
    }

    /**
     * Builds an in-memory (possibly unsaved) CategoryRule reflecting the CURRENT form state, so
     * the live preview list/count agrees with what's on screen even before Save is pressed —
     * brute-forced in PHP against a fully-materialized collection rather than a dynamic
     * per-condition SQL query. Simple and correct; revisit if this app's transaction volume ever
     * makes that collection large enough to matter (it's a self-hosted personal-finance tool,
     * not built for that scale today).
     */
    private function buildPreviewRule(): CategoryRule
    {
        $rule = new CategoryRule(['match_type' => $this->match_type]);
        $rule->setRelation('conditionGroups', collect($this->groups)->map(function (array $group): CategoryRuleConditionGroup {
            $groupModel = new CategoryRuleConditionGroup(['match_type' => $group['match_type']]);
            $groupModel->setRelation('conditions', collect($group['conditions'])->map(
                fn (array $condition): CategoryRuleCondition => new CategoryRuleCondition($condition)
            ));

            return $groupModel;
        }));

        return $rule;
    }
}

?>
<x-page-wrapper :heading="$categoryRule ? 'Edit Rule' : 'Create Rule'" subheading="Automatically assign a category to matching new transactions" :breadcrumbs="['Autocategorize Rules' => route('category-rules.index')]">

    <div class="mb-4 w-full max-w-2xl flex flex-col gap-4">
        <div class="flex flex-col gap-1">
            <label class="text-sm font-medium text-zinc-600 dark:text-zinc-400">Name (optional)</label>
            <flux:input wire:model="name" placeholder="e.g. Coffee shops" />
        </div>

        <div class="flex flex-col gap-1">
            <label class="text-sm font-medium text-zinc-600 dark:text-zinc-400">Assign category</label>
            <flux:select wire:model="category_id">
                <flux:select.option value="">-- Select a category --</flux:select.option>
                @foreach($categories as $category)
                <flux:select.option value="{{ $category->id }}">{{ $category->fullName }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        @if(count($groups) > 1)
        <div class="flex flex-col gap-1">
            <label class="text-sm font-medium text-zinc-600 dark:text-zinc-400">Groups combine via</label>
            <flux:select wire:model.live="match_type">
                <flux:select.option value="all">ALL of the groups below must match</flux:select.option>
                <flux:select.option value="any">ANY of the groups below may match</flux:select.option>
            </flux:select>
        </div>
        @endif

        <div class="flex flex-col gap-3">
            @foreach($groups as $groupIndex => $group)
            @if($groupIndex > 0)
            <div class="text-center text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase">
                {{ $match_type === 'any' ? 'OR' : 'AND' }}
            </div>
            @endif

            <div class="flex flex-col gap-2 p-3 rounded-xl border border-zinc-300 dark:border-zinc-600" wire:key="group-{{ $groupIndex }}">
                <div class="flex items-center justify-between gap-2">
                    <flux:select wire:model.live="groups.{{ $groupIndex }}.match_type" class="w-56">
                        <flux:select.option value="all">ALL of these conditions match</flux:select.option>
                        <flux:select.option value="any">ANY of these conditions match</flux:select.option>
                    </flux:select>

                    @if(count($groups) > 1)
                    <x-button icon="trash" title="Remove group" class="cursor-pointer" variant="danger" wire:click="removeGroup({{ $groupIndex }})"></x-button>
                    @endif
                </div>

                @foreach($group['conditions'] as $conditionIndex => $condition)
                <div class="flex flex-col sm:flex-row gap-2 sm:items-center p-2 rounded-lg bg-zinc-100 dark:bg-white/10" wire:key="condition-{{ $groupIndex }}-{{ $conditionIndex }}">
                    <flux:select id="group-{{ $groupIndex }}-condition-{{ $conditionIndex }}-field" wire:model.live="groups.{{ $groupIndex }}.conditions.{{ $conditionIndex }}.field" class="sm:w-40">
                        <flux:select.option value="name">Name</flux:select.option>
                        <flux:select.option value="merchant_name">Merchant</flux:select.option>
                        <flux:select.option value="amount">Amount</flux:select.option>
                        <flux:select.option value="account_id">Account</flux:select.option>
                        <flux:select.option value="date">Date</flux:select.option>
                    </flux:select>

                    <flux:select id="group-{{ $groupIndex }}-condition-{{ $conditionIndex }}-operator" wire:model.live="groups.{{ $groupIndex }}.conditions.{{ $conditionIndex }}.operator" class="sm:w-40">
                        @foreach($this->operatorsFor($condition['field']) as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    @if($condition['field'] === 'account_id')
                    <flux:select id="group-{{ $groupIndex }}-condition-{{ $conditionIndex }}-value" wire:model.live.debounce.500ms="groups.{{ $groupIndex }}.conditions.{{ $conditionIndex }}.value" class="sm:w-48">
                        <flux:select.option value="">-- Select an account --</flux:select.option>
                        @foreach($accounts as $account)
                        <flux:select.option value="{{ $account->id }}">{{ $account->linked_account->provider_name }} - {{ $account->display_name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @elseif($condition['field'] === 'date')
                    <flux:input id="group-{{ $groupIndex }}-condition-{{ $conditionIndex }}-value" type="date" wire:model.live.debounce.500ms="groups.{{ $groupIndex }}.conditions.{{ $conditionIndex }}.value" class="sm:w-40" />
                    @elseif($condition['field'] === 'amount')
                    <flux:input id="group-{{ $groupIndex }}-condition-{{ $conditionIndex }}-value" type="number" step="0.01" min="0" wire:model.live.debounce.500ms="groups.{{ $groupIndex }}.conditions.{{ $conditionIndex }}.value" placeholder="Amount" class="sm:w-32" />
                    @else
                    <flux:input id="group-{{ $groupIndex }}-condition-{{ $conditionIndex }}-value" wire:model.live.debounce.500ms="groups.{{ $groupIndex }}.conditions.{{ $conditionIndex }}.value" placeholder="{{ $condition['operator'] === 'regex' ? '/pattern/' : 'Text to match' }}" class="sm:w-56" />
                    @endif

                    @if($condition['operator'] === 'between')
                    <span class="text-zinc-500 dark:text-zinc-400">and</span>
                    @if($condition['field'] === 'date')
                    <flux:input type="date" wire:model.live.debounce.500ms="groups.{{ $groupIndex }}.conditions.{{ $conditionIndex }}.value_end" class="sm:w-40" />
                    @else
                    <flux:input type="number" step="0.01" min="0" wire:model.live.debounce.500ms="groups.{{ $groupIndex }}.conditions.{{ $conditionIndex }}.value_end" placeholder="and" class="sm:w-32" />
                    @endif
                    @endif

                    <x-button icon="trash" title="Remove condition" class="cursor-pointer" variant="danger" wire:click="removeCondition({{ $groupIndex }}, {{ $conditionIndex }})"></x-button>
                </div>
                @endforeach

                <flux:button variant="subtle" wire:click="addCondition({{ $groupIndex }})" class="w-full sm:w-auto">+ Add condition to this group</flux:button>
            </div>
            @endforeach

            <flux:button variant="subtle" wire:click="addGroup" class="w-full sm:w-auto">+ Add group{{ count($groups) > 1 ? ' ('.($match_type === 'any' ? 'OR' : 'AND').')' : '' }}</flux:button>
        </div>

        <div class="flex flex-col gap-2" wire:loading.class="opacity-50" wire:target="groups,match_type,category_id">
            <div class="text-sm text-zinc-600 dark:text-zinc-400">
                <span wire:loading.remove wire:target="groups,match_type,category_id">{{ $matchingCount }} matching uncategorized transaction{{ $matchingCount === 1 ? '' : 's' }} right now.</span>
                <span wire:loading wire:target="groups,match_type,category_id">Checking…</span>
            </div>

            @if($matchingCount > 0)
            <ul class="flex flex-col gap-1 max-h-64 overflow-y-auto text-sm border border-zinc-200 dark:border-zinc-700 rounded-lg p-2">
                @foreach($matchingTransactions as $transaction)
                <li class="flex justify-between gap-2" wire:key="matching-transaction-{{ $transaction->id }}">
                    <span class="truncate">{{ $transaction->merchant_name ?: $transaction->name }}</span>
                    <span class="text-zinc-500 dark:text-zinc-400 whitespace-nowrap">{{ $transaction->created_at->format('M j, Y') }} &middot; {!! currency($transaction->amount, $transaction->currency, true) !!}</span>
                </li>
                @endforeach
            </ul>
            @if($matchingCount > $matchingTransactions->count())
            <div class="text-xs text-zinc-500 dark:text-zinc-400">+{{ $matchingCount - $matchingTransactions->count() }} more not shown</div>
            @endif
            @endif
        </div>

        @if($statusMessage)
        <div class="text-sm text-green-600 dark:text-green-400">{{ $statusMessage }}</div>
        @endif

        <div class="flex flex-col sm:flex-row gap-2">
            <x-button wire:click="save" class="w-full sm:w-auto">Save</x-button>

            @if($matchingCount > 0)
            <x-button
                wire:click="applyToExistingTransactions"
                wire:confirm="Apply this rule to {{ $matchingCount }} existing uncategorized transaction{{ $matchingCount === 1 ? '' : 's' }} now?"
                variant="subtle"
                class="w-full sm:w-auto"
            >
                Apply to {{ $matchingCount }} existing transaction{{ $matchingCount === 1 ? '' : 's' }}
            </x-button>
            @endif
        </div>
    </div>

</x-page-wrapper>
