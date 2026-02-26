@props([
    'heading' => null,
    'subheading' => null,
    'breadcrumbs' => [],
    'actions' => null,
])
<div class="relative w-full page-heading">

    @if ($breadcrumbs)
    <flux:breadcrumbs class="text-zinc-700 mb-4">
        @foreach ($breadcrumbs as $text => $route)
            @if ($route)
                <flux:breadcrumbs.item href="{{ (strpos($route, 'http') === 0) ? $route : route($route) }}">{{ $text }}</flux:breadcrumbs.item>
            @else
                <flux:breadcrumbs.item>{{ $text }}</flux:breadcrumbs.item>
            @endif
        @endforeach

        <flux:breadcrumbs.item>{!! __($subheading) !!}</flux:breadcrumbs.item>

    </flux:breadcrumbs>
    @endif

    <flux:heading class="!text-3xl" accent="true" level="1">{!! $heading !!}</flux:heading>

    <flux:subheading size="lg" class="mb-6">
        <div class="flex items-center justify-between">
            {!! __($subheading) !!}

            {{ $actions ?? '' }}
        </div>
    </flux:subheading>

    <flux:separator variant="subtle"></flux:separator>

</div>
