<?php

use Livewire\Livewire;

it('renders successfully', function () {
    Livewire::test('foo')
        ->assertStatus(200);
});
