<table {{ $attributes->merge(['class' => 'w-full border-collapse']) }}>
    @if ($head ?? false)
    <thead class="border-b border-gray-300">
        {{ $head }}
    </thead>
    @endif

    <tbody>
        {{ $body }}
    </tbody>
</table>
