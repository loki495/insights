<?php

declare(strict_types=1);

it('strips stray U+FFFD replacement characters and collapses the resulting double space', function (): void {
    $result = cleanPlaidText("DEMO BANK REWARDS VISA\u{FFFD}\u{FFFD} CARD");

    expect($result)->toBe('DEMO BANK REWARDS VISA CARD');
});

it('leaves normal text untouched', function (): void {
    expect(cleanPlaidText('Checking Account'))->toBe('Checking Account');
});

it('passes through null', function (): void {
    expect(cleanPlaidText(null))->toBeNull();
});

it('trims leading/trailing whitespace left behind by a replacement character at the edge', function (): void {
    $result = cleanPlaidText("\u{FFFD} Some Card");

    expect($result)->toBe('Some Card');
});
