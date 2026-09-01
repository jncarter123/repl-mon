<?php

declare(strict_types=1);

use App\Models\AlertRecipient;
use App\Models\ServerPair;

it('falls back to the global list when the pair names nobody', function () {
    AlertRecipient::factory()->create(['server_pair_id' => null, 'email' => 'ops@example.com']);
    AlertRecipient::factory()->create(['server_pair_id' => null, 'email' => 'dba@example.com']);

    $pair = ServerPair::factory()->create();

    expect($pair->usesGlobalRecipients())->toBeTrue()
        ->and($pair->resolvedRecipients()->pluck('email')->all())
        ->toBe(['dba@example.com', 'ops@example.com']);
});

it('uses the pair list instead of the global one, not as well as', function () {
    AlertRecipient::factory()->create(['server_pair_id' => null, 'email' => 'ops@example.com']);

    $pair = ServerPair::factory()->create();
    AlertRecipient::factory()->create(['server_pair_id' => $pair->id, 'email' => 'orders-team@example.com']);

    expect($pair->usesGlobalRecipients())->toBeFalse()
        ->and($pair->resolvedRecipients()->pluck('email')->all())
        ->toBe(['orders-team@example.com']);
});

it('ignores disabled recipients on both lists', function () {
    AlertRecipient::factory()->create(['server_pair_id' => null, 'email' => 'ops@example.com']);

    $pair = ServerPair::factory()->create();
    AlertRecipient::factory()->create(['server_pair_id' => $pair->id, 'email' => 'muted@example.com', 'enabled' => false]);

    // The pair's only recipient is switched off, so the global list applies again.
    expect($pair->resolvedRecipients()->pluck('email')->all())->toBe(['ops@example.com']);
});

it('takes a pair recipient with it when the pair is deleted', function () {
    $pair = ServerPair::factory()->create();
    AlertRecipient::factory()->create(['server_pair_id' => $pair->id]);
    AlertRecipient::factory()->create(['server_pair_id' => null]);

    $pair->delete();

    expect(AlertRecipient::query()->count())->toBe(1)
        ->and(AlertRecipient::query()->sole()->isGlobal())->toBeTrue();
});
