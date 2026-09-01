<?php

declare(strict_types=1);

use App\Models\User;

it('offers registration while there is no operator account yet', function () {
    $this->get(route('register'))->assertOk();
});

it('hides registration once the first operator exists', function () {
    User::factory()->create();

    // 404 rather than 403: an app holding production database credentials
    // should not advertise a sign-up endpoint to whoever is probing it.
    $this->get(route('register'))->assertNotFound();

    $this->post(route('register'), [
        'name' => 'Intruder',
        'email' => 'intruder@example.com',
        'password' => 'password-password',
        'password_confirmation' => 'password-password',
    ])->assertNotFound();

    expect(User::query()->count())->toBe(1);
});
