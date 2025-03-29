@props([
    'heading' => null,
    'subheading' => null,
    'breadcrumbs' => [],
])

<div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">

    <x-page-heading heading="{{ $heading }}" subheading="{{ $subheading }}" :breadcrumbs="$breadcrumbs"></x-page-heading>

    {{ $slot }}

</div>

