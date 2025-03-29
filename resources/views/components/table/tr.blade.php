@props([
])

<tr {{ $attributes->merge(['class' => '']) }}>
    {{ $slot}}
</tr>
