<?php

use App\Models\OriginalCategory;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {

    use WithPagination;

    public ?int $category_id;
    public ?OriginalCategory $category;

    public function mount(?OriginalCategory $category): void
    {
        $this->category = $category;
        $this->category_id = $category->id;
    }

    #[On('clicked')]
    public function clicked($category)
    {
        dd($category);
    }
}

?>
    <x-page-wrapper heading="Reports" subheading="Category Transactions - {{ $category->id > 0 ? $category->name : 'All Categories' }} {{ $category_id ? '(' . $category->plaid_id . ')': '' }}" :breadcrumbs="['Reports' => 'reports.index', 'Categories' => 'reports.category.index']">

        <livewire:components.transactions :category="$category" :allow_accounts="true"></livewire:components.transactions>

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
