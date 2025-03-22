@props([
    'heading' => null,
    'subheading' => null,
])
<div class="relative mb-6 w-full">
    <flux:heading size="xl" level="1">{{ $heading }}</flux:heading>
    <flux:subheading size="lg" class="mb-6">{{ __($subheading) }}</flux:subheading>
    <flux:separator variant="subtle" />
</div>
