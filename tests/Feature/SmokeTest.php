<?php

declare(strict_types=1);

it('returns a redirect to login for guests', function (): void {
    $this->get('/')
        ->assertRedirect('/login');
});
