<?php

test('home page loads', function (): void {
    $this->get('/')
        ->assertSuccessful()
        ->assertSee('Laravel');
});
