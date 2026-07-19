<template x-teleport="body">
    <div
        class="fixed inset-0 flex items-center justify-center z-50 p-4"
        x-cloak
        x-data="{
            open: false,
            transaction_id: 0,
            transaction_type: null,
            transaction_details: null,
            pair: null,
            pair_search: '',
            pair_candidates: [],
            pairSearching: false,
            typeOptions: [
                { value: 'income', label: 'Income' },
                { value: 'expense', label: 'Expense' },
                { value: 'transfer', label: 'Transfer' },
                { value: 'adjustment', label: 'Adjustment' },
            ],
            openFor(transactionId) {
                this.open = true;
                this.transaction_id = transactionId;
                this.transaction_type = null;
                this.transaction_details = null;
                this.pair = null;
                this.pair_search = '';
                this.pair_candidates = [];
                $wire.typeEditorData(transactionId).then((data) => {
                    this.transaction_type = data.type;
                    this.pair = data.pair;
                    this.transaction_details = data.transaction;
                });
            },
            selectType(type) {
                // Optimistic: the pill in the list updates instantly (via the shared
                // optimisticTypes on the outer scope) rather than waiting on the round trip.
                this.optimisticTypes[this.transaction_id] = type;
                this.transaction_type = type;
                if (type !== 'transfer') {
                    this.pair = null;
                }
                $wire.saveType(this.transaction_id, type).then((data) => {
                    this.transaction_type = data.type;
                    this.pair = data.pair;
                    delete this.optimisticTypes[this.transaction_id];
                }).catch(() => {
                    delete this.optimisticTypes[this.transaction_id];
                });
            },
            searchPairs() {
                if (!this.pair_search.trim()) {
                    this.pair_candidates = [];
                    return;
                }
                this.pairSearching = true;
                $wire.searchTransferPairCandidates(this.transaction_id, this.pair_search).then((list) => {
                    this.pair_candidates = list;
                    this.pairSearching = false;
                });
            },
            selectPair(otherId) {
                $wire.pairTransaction(this.transaction_id, otherId).then((pairInfo) => {
                    this.pair = pairInfo;
                    this.pair_search = '';
                    this.pair_candidates = [];
                });
            },
            clearPair() {
                $wire.unpairTransaction(this.transaction_id).then(() => {
                    this.pair = null;
                });
            },
        }"
        x-show="open"
        x-on:keydown.escape.window="open = false"
        @edit-type.window="openFor(event.detail.transaction_id)"
    >
        <div class="fixed inset-0 bg-zinc-900/50" @click="open = false"></div>
        <div class="bg-white dark:bg-zinc-900 text-zinc-900 dark:text-white p-4 rounded-xl w-full max-w-96 z-10 flex flex-col gap-4 shadow-xl">
            <div class="flex items-center justify-between">
                <span class="font-medium">Type</span>
                <button type="button" class="cursor-pointer text-zinc-500 hover:text-zinc-800 dark:hover:text-white" @click="open = false">
                    <flux:icon.x-mark class="size-4" />
                </button>
            </div>

            <template x-if="transaction_details">
                <div class="flex items-start justify-between gap-2 text-sm border-b border-zinc-200 dark:border-zinc-700 pb-4">
                    <div class="min-w-0">
                        <div class="font-medium break-words" x-text="transaction_details?.name"></div>
                        <div class="text-xs text-zinc-500 dark:text-zinc-400" x-text="transaction_details?.date + (transaction_details?.merchant_name ? ' · ' + transaction_details.merchant_name : '')"></div>
                    </div>
                    <div class="font-semibold shrink-0" x-text="transaction_details?.amount"></div>
                </div>
            </template>

            <div class="grid grid-cols-2 gap-2">
                <template x-for="option in typeOptions" :key="option.value">
                    <button
                        type="button"
                        @click="selectType(option.value)"
                        class="cursor-pointer text-sm px-3 py-2 rounded-lg border"
                        :class="transaction_type === option.value ? 'border-zinc-800 dark:border-white bg-zinc-100 dark:bg-white/10 font-medium' : 'border-zinc-200 dark:border-zinc-600 hover:bg-zinc-100 dark:hover:bg-white/10'"
                        x-text="option.label"
                    ></button>
                </template>
            </div>

            <div x-show="transaction_type === 'transfer'" x-cloak class="flex flex-col gap-2 border-t border-zinc-200 dark:border-zinc-700 pt-4">
                <span class="font-medium text-sm">Transfer Pair</span>

                <template x-if="pair">
                    <div class="flex items-center justify-between gap-2 border border-zinc-300 dark:border-zinc-600 rounded-lg p-2">
                        <div class="flex flex-col text-sm min-w-0">
                            <span class="break-words" x-text="pair?.label"></span>
                            <span class="text-xs text-zinc-500 dark:text-zinc-400" x-text="pair?.amount"></span>
                        </div>
                        <flux:button size="sm" variant="danger" class="shrink-0" @click="clearPair()">Unpair</flux:button>
                    </div>
                </template>

                <template x-if="!pair">
                    <div class="flex flex-col gap-2">
                        <x-input type="text" x-model="pair_search" @input.debounce.400ms="searchPairs()" placeholder="Search for the other leg by name/merchant..." class="w-full"></x-input>
                        <div class="flex flex-col gap-1 max-h-48 overflow-y-auto">
                            <template x-for="candidate in pair_candidates" :key="candidate.id">
                                <button
                                    type="button"
                                    @click="selectPair(candidate.id)"
                                    class="cursor-pointer text-left text-sm px-2 py-1.5 rounded-lg border border-zinc-200 dark:border-zinc-600 hover:bg-zinc-100 dark:hover:bg-white/10 flex items-center justify-between gap-2"
                                >
                                    <span class="min-w-0 break-words" x-text="candidate.label"></span>
                                    <span class="text-xs text-zinc-500 dark:text-zinc-400 shrink-0" x-text="candidate.amount"></span>
                                </button>
                            </template>
                            <div x-show="pair_search.trim() && !pairSearching && pair_candidates.length === 0" class="text-sm text-zinc-500 dark:text-zinc-400 px-2 py-1.5">No matching unpaired transfers found.</div>
                        </div>
                    </div>
                </template>
            </div>

            <flux:button variant="subtle" class="w-full" @click="open = false">Close</flux:button>
        </div>
    </div>
</template>
