<?php

declare(strict_types=1);

test('home page loads', function (): void {
    $this->get('/')
        ->assertSuccessful()
        ->assertSee('Laravel');
});
