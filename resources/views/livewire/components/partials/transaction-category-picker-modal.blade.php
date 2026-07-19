<template x-teleport="body">
    <div
        class="fixed inset-0 flex items-center justify-center z-50 p-4"
        x-cloak
        x-data="{
            open: false,
            add: false,
            bulkMode: false,
            transaction_id: 0,
            transaction_name: '',
            transaction_amount: 0,
            editing_category_id: 0,
            suggestions: [],
            suggestionsLoading: false,
            categorySearch: '',
            categoryParentId: 0,
            creatingCategory: false,
            newCategoryName: '',
            newCategoryParentId: '',
            newCategoryColor: '#3b82f6',
            creatingCategoryError: '',
            selectCategory(categoryId) {
                this.open = false;
                if (this.bulkMode) {
                    this.bulkAssignCategory(categoryId);
                } else {
                    this.applyCategory(this.transaction_id, categoryId);
                }
            },
            loadSuggestions(transactionId) {
                this.suggestions = [];
                this.suggestionsLoading = true;
                $wire.suggestCategoriesForTransaction(transactionId).then((list) => {
                    if (this.transaction_id === transactionId) {
                        this.suggestions = list;
                        this.suggestionsLoading = false;
                    }
                });
            },
            filteredCategories() {
                const q = this.categorySearch.trim().toLowerCase();
                if (!q) return this.categoryList;
                return this.categoryList
                    .filter(c => c.full_name.toLowerCase().includes(q))
                    .sort((a, b) => a.full_name.localeCompare(b.full_name));
            },
            categoriesAtCurrentLevel() {
                return this.categoryList
                    .filter(c => (c.parent_id || 0) === this.categoryParentId)
                    .sort((a, b) => a.name.localeCompare(b.name));
            },
            categoryHasChildren(catId) {
                return this.categoryList.some(c => (c.parent_id || 0) === catId);
            },
            currentParentCategory() {
                return this.categoryLookup[this.categoryParentId] || null;
            },
            drillInto(catId) {
                this.categoryParentId = catId;
                this.categorySearch = '';
            },
            drillUp() {
                const parent = this.currentParentCategory();
                this.categoryParentId = parent ? (parent.parent_id || 0) : 0;
            },
            startCreatingCategory() {
                this.creatingCategory = true;
                this.creatingCategoryError = '';
                this.newCategoryName = this.categorySearch.trim();
                this.newCategoryParentId = this.categorySearch.trim() ? '' : (this.categoryParentId || '');
                this.newCategoryColor = this.categoryColorPalette[Math.floor(Math.random() * this.categoryColorPalette.length)];
            },
            createAndApplyCategory() {
                if (!this.newCategoryName.trim()) {
                    this.creatingCategoryError = 'Name is required.';
                    return;
                }
                this.creatingCategoryError = '';
                $wire.createCategory(this.newCategoryName, this.newCategoryParentId || null, this.newCategoryColor).then((created) => {
                    this.categoryList.push(created);
                    this.categoryLookup[created.id] = created;
                    this.creatingCategory = false;
                    this.selectCategory(created.id);
                }).catch(() => {
                    this.creatingCategoryError = 'Could not create category. Please try again.';
                });
            },
        }"
        x-on:keydown.escape.window="open = false"
        x-show="open"
        @add-category.window="add=true;open=true;bulkMode=false;categorySearch='';categoryParentId=0;creatingCategory=false;transaction_id=event.detail.transaction_id;transaction_amount=event.detail.transaction_amount;transaction_name=event.detail.transaction_name;editing_category_id=0;loadSuggestions(event.detail.transaction_id);"
        @edit-category.window="add=false;open=true;bulkMode=false;categorySearch='';creatingCategory=false;transaction_id=event.detail.transaction_id;transaction_amount=event.detail.transaction_amount;transaction_name=event.detail.transaction_name;editing_category_id=event.detail.category_id;categoryParentId=(categoryLookup[event.detail.category_id]?.parent_id || 0);loadSuggestions(event.detail.transaction_id);"
        @bulk-add-category.window="add=true;open=true;bulkMode=true;categorySearch='';categoryParentId=0;creatingCategory=false;editing_category_id=0;suggestions=[];suggestionsLoading=false;"
    >
        <div class="fixed inset-0 bg-zinc-900/50" @click="open = false"></div>
        <div class="bg-white dark:bg-zinc-900 text-zinc-900 dark:text-white p-4 rounded-xl w-full max-w-96 max-h-[85vh] z-10 flex flex-col overflow-hidden shadow-xl">
            <div class="flex flex-col gap-4 min-h-0 flex-1" x-show="!creatingCategory">
                <div class="flex items-center justify-between">
                    <span x-show="bulkMode">Assign Category</span>
                    <span x-show="!bulkMode && add">Add Category</span>
                    <span x-show="!bulkMode && !add">Edit Category</span>
                    <button
                        type="button"
                        x-show="!bulkMode && editing_category_id > 0"
                        @click="open = false; clearCategory(transaction_id)"
                        class="cursor-pointer text-xs text-red-500 hover:text-red-600"
                    >Clear category</button>
                </div>
                <div class="flex justify-between" x-show="!bulkMode">
                    <div><span x-html="transaction_name"></span> (#<span x-text="transaction_id"></span>)</div>
                    <span x-html="transaction_amount"></span>
                </div>
                <div class="text-sm text-zinc-500 dark:text-zinc-400" x-show="bulkMode">
                    <span x-text="selected_transactions.length"></span> transaction(s) selected
                </div>

                <div class="flex flex-col gap-1 shrink-0" x-show="!bulkMode && suggestionsLoading" x-cloak>
                    <div class="px-2 py-1.5 rounded-lg border border-dashed border-zinc-300 dark:border-zinc-600 text-zinc-500 dark:text-zinc-400 text-sm flex items-center gap-2">
                        <flux:icon.loading class="size-4 shrink-0" />
                        Loading suggestions...
                    </div>
                </div>

                <div class="flex flex-col gap-1 shrink-0" x-show="!bulkMode && !suggestionsLoading && suggestions.length > 0">
                    <template x-for="suggestion in suggestions" :key="suggestion.id">
                        <button
                            type="button"
                            @click="open = false; applyCategory(transaction_id, suggestion.id)"
                            class="cursor-pointer text-left px-2 py-1.5 rounded-lg border border-dashed border-zinc-300 dark:border-zinc-600 text-zinc-600 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-white/10 flex items-center gap-2"
                        >
                            <span class="inline-block size-3 rounded-full shrink-0" :style="`background-color: ${suggestion.color}`"></span>
                            Suggested: <span x-text="suggestion.name"></span>
                        </button>
                    </template>
                </div>

                <x-input type="text" x-model="categorySearch" placeholder="Search categories..." class="w-full shrink-0"></x-input>

                <button
                    type="button"
                    x-show="!categorySearch.trim() && categoryParentId !== 0"
                    @click="drillUp()"
                    class="cursor-pointer flex items-center gap-1 text-sm text-zinc-600 dark:text-zinc-300 hover:text-zinc-900 dark:hover:text-white shrink-0"
                >
                    <flux:icon.chevron-left class="size-4 shrink-0" />
                    <span x-text="currentParentCategory()?.name || 'All Categories'"></span>
                </button>

                <div class="overflow-y-auto flex flex-col gap-1 min-h-0 flex-1">
                    <template x-if="categorySearch.trim()">
                        <template x-for="cat in filteredCategories()" :key="cat.id">
                            <button
                                type="button"
                                @click="selectCategory(cat.id)"
                                class="cursor-pointer text-left px-2 py-1.5 rounded-lg hover:bg-zinc-100 dark:hover:bg-white/10 flex items-center gap-2"
                                :class="{ 'bg-zinc-100 dark:bg-white/10 ring-1 ring-inset ring-zinc-300 dark:ring-zinc-600': cat.id === editing_category_id }"
                            >
                                <span class="inline-block size-3 rounded-full shrink-0" :style="`background-color: ${cat.color}`"></span>
                                <span x-text="cat.full_name" class="grow"></span>
                                <flux:icon.check x-show="cat.id === editing_category_id" class="size-4 shrink-0" />
                            </button>
                        </template>
                    </template>

                    <template x-if="!categorySearch.trim()">
                        <template x-for="cat in categoriesAtCurrentLevel()" :key="cat.id">
                            <div class="flex items-center gap-1">
                                <button
                                    type="button"
                                    x-show="categoryHasChildren(cat.id)"
                                    @click="drillInto(cat.id)"
                                    title="Browse subcategories"
                                    class="cursor-pointer shrink-0 p-1.5 rounded-lg hover:bg-zinc-100 dark:hover:bg-white/10 text-zinc-500 dark:text-zinc-400"
                                >
                                    <flux:icon.chevron-right class="size-4" />
                                </button>
                                <button
                                    type="button"
                                    @click="selectCategory(cat.id)"
                                    class="grow cursor-pointer text-left px-2 py-1.5 rounded-lg hover:bg-zinc-100 dark:hover:bg-white/10 flex items-center gap-2"
                                    :class="{ 'bg-zinc-100 dark:bg-white/10 ring-1 ring-inset ring-zinc-300 dark:ring-zinc-600': cat.id === editing_category_id }"
                                >
                                    <span class="inline-block size-3 rounded-full shrink-0" :style="`background-color: ${cat.color}`"></span>
                                    <span x-text="cat.name" class="grow"></span>
                                    <flux:icon.check x-show="cat.id === editing_category_id" class="size-4 shrink-0" />
                                </button>
                                <button
                                    type="button"
                                    x-show="categoryHasChildren(cat.id)"
                                    @click="drillInto(cat.id)"
                                    title="Browse subcategories"
                                    class="cursor-pointer shrink-0 p-1.5 rounded-lg hover:bg-zinc-100 dark:hover:bg-white/10 text-zinc-500 dark:text-zinc-400"
                                >
                                    <flux:icon.chevron-right class="size-4" />
                                </button>
                            </div>
                        </template>
                    </template>

                    <div x-show="(categorySearch.trim() ? filteredCategories() : categoriesAtCurrentLevel()).length === 0" class="text-zinc-500 dark:text-zinc-400 text-sm px-2 py-1.5">No matching categories</div>
                </div>

                <button
                    type="button"
                    @click="startCreatingCategory()"
                    class="cursor-pointer text-left px-2 py-1.5 rounded-lg border border-dashed border-zinc-400 dark:border-zinc-600 text-zinc-600 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-white/10 shrink-0"
                >+ Create new category</button>

                <flux:button variant="subtle" class="w-full shrink-0" @click="open = false">Cancel</flux:button>
            </div>

            <div class="flex flex-col gap-4 min-h-0 flex-1" x-show="creatingCategory" x-cloak>
                <div class="flex items-center gap-2">
                    <button type="button" @click="creatingCategory = false" class="cursor-pointer text-zinc-500 dark:text-zinc-400 hover:text-zinc-900 dark:hover:text-white">&larr;</button>
                    <div>Create Category</div>
                </div>

                <div class="flex flex-col gap-1">
                    <label for="new-category-name" class="text-sm text-zinc-600 dark:text-zinc-400">Name</label>
                    <x-input id="new-category-name" type="text" x-model="newCategoryName" placeholder="e.g. Groceries" class="w-full" autofocus></x-input>
                </div>

                <div class="flex flex-col gap-1">
                    <label for="new-category-parent" class="text-sm text-zinc-600 dark:text-zinc-400">Parent (optional)</label>
                    <select id="new-category-parent" x-model="newCategoryParentId" class="border border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 rounded-lg p-2 w-full">
                        <option value="">-- No parent (top-level) --</option>
                        <template x-for="cat in categoryList" :key="cat.id">
                            <option :value="cat.id" x-text="cat.name"></option>
                        </template>
                    </select>
                </div>

                <div class="flex items-center gap-2">
                    <label for="new-category-color" class="text-sm text-zinc-600 dark:text-zinc-400">Color</label>
                    <input id="new-category-color" type="color" x-model="newCategoryColor" class="h-8 w-14 cursor-pointer rounded border border-zinc-300 dark:border-zinc-600" />
                </div>

                <div x-show="creatingCategoryError" x-text="creatingCategoryError" class="text-sm text-red-500"></div>

                <flux:button variant="primary" @click="createAndApplyCategory()">Create &amp; Assign</flux:button>
            </div>
        </div>
    </div>
</template>
