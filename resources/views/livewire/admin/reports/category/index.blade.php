<?php

use App\Models\Category;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public ?int $category_id;

    public ?Category $category;

    public function mount(?Category $category): void
    {
        // Livewire replays route-bound mount() params on every subsequent request against this
        // component, and when the optional {category?} segment is absent it replays as an empty,
        // unsaved Category instance rather than literal null — a bare `if ($category)` treats
        // that as truthy. Matches the subheading below's existing `$category?->id > 0` guard.
        if ($category?->id) {
            $this->authorize('view', $category);
        }

        $this->category = $category;
        $this->category_id = $category?->id;
    }
}

?>
<x-page-wrapper heading="Transaction Search"
    subheading="{{ $category?->id > 0 ? $category->name : 'All Categories' }}"
    :breadcrumbs="['Transaction Search' => null]"
>

        <livewire:components.transactions :category="$category" :allow_accounts="true" :allow_running_balance="false" />

    </x-page-wrapper>
      <script type="text/javascript">

    // waiting for DOM loaded
    document.addEventListener('DOMContentLoaded', function () {

      // listen for the event
      Livewire.on('categoryIdChanged', params => {
        if (!params.categoryId) {
            params.categoryId = '';
        }
        history.pushState(null, null, '{{ route('reports.category.index') }}/' + params.categoryId);
      });
    });
  </script>
