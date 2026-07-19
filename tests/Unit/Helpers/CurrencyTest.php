<?php

declare(strict_types=1);

it('returns an empty string for a null amount', function (): void {
    expect(currency())->toBe('');
});

it('formats a positive USD amount', function (): void {
    expect(currency(1234.5, 'USD', true))->toBe('$1,234.50');
});

it('formats a non-USD currency instead of crashing', function (): void {
    expect(currency(1234.5, 'EUR', true))->toBe('€1,234.50');
});

it('formats a currency with no dedicated symbol via its ISO code', function (): void {
    expect(currency(10, 'XYZ', true))->toContain('10.00');
});

it('wraps non-flat output in a color-coded span', function (): void {
    expect(currency(10, 'USD'))->toContain('text-zinc-700')
        ->and(currency(-10, 'USD'))->toContain('text-red-700');
});
