<?php

declare(strict_types=1);

use App\Data\ProbeResult;
use App\Enums\AlertKind;
use App\Enums\CheckStatus;
use App\Mail\ReplicationAlertMail;
use App\Models\AlertRecipient;
use App\Models\ReplicationAlert;
use App\Models\ServerPair;
use App\Services\ReplicationChecker;
use App\Services\ReplicationProbe;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakeProbe;

beforeEach(function () {
    Mail::fake();
    AlertRecipient::factory()->create(['server_pair_id' => null, 'email' => 'ops@example.com']);
});

function probing(ProbeResult $result): void
{
    app()->instance(ReplicationProbe::class, new FakeProbe($result));
}

function healthy(): ProbeResult
{
    return new ProbeResult(
        primaryReachable: true,
        replicaReachable: true,
        heartbeatRowFound: true,
        lagSeconds: 0.3,
        ioRunning: 'Yes',
        sqlRunning: 'Yes',
    );
}

function broken(): ProbeResult
{
    return new ProbeResult(
        primaryReachable: true,
        replicaReachable: true,
        heartbeatRowFound: true,
        lagSeconds: 1.0,
        ioRunning: 'Yes',
        sqlRunning: 'No',
    );
}

function lagging(): ProbeResult
{
    return new ProbeResult(
        primaryReachable: true,
        replicaReachable: true,
        heartbeatRowFound: true,
        lagSeconds: 900.0,
        ioRunning: 'Yes',
        sqlRunning: 'Yes',
    );
}

it('emails once when a pair goes bad and does not repeat itself every minute', function () {
    $pair = ServerPair::factory()->create(['failures_before_alert' => 1, 'realert_after_minutes' => 60]);

    probing(broken());

    app(ReplicationChecker::class)->check($pair);
    app(ReplicationChecker::class)->check($pair->fresh());
    app(ReplicationChecker::class)->check($pair->fresh());

    Mail::assertSent(ReplicationAlertMail::class, 1);

    expect($pair->fresh()->alerting)->toBeTrue()
        ->and($pair->fresh()->consecutive_failures)->toBe(3)
        ->and($pair->checks()->count())->toBe(3);
});

it('waits for the configured number of consecutive failures before saying anything', function () {
    $pair = ServerPair::factory()->create(['failures_before_alert' => 3]);

    probing(lagging());

    app(ReplicationChecker::class)->check($pair);
    Mail::assertNothingSent();

    app(ReplicationChecker::class)->check($pair->fresh());
    Mail::assertNothingSent();

    app(ReplicationChecker::class)->check($pair->fresh());
    Mail::assertSent(ReplicationAlertMail::class, 1);
});

it('sends again immediately when a problem gets worse', function () {
    $pair = ServerPair::factory()->create(['failures_before_alert' => 1, 'realert_after_minutes' => 0]);

    probing(lagging());
    app(ReplicationChecker::class)->check($pair);

    // Lagging is bad; a stopped thread is worse, and that is new information.
    probing(broken());
    app(ReplicationChecker::class)->check($pair->fresh());

    Mail::assertSent(ReplicationAlertMail::class, 2);

    expect(ReplicationAlert::query()->pluck('status')->all())
        ->toBe([CheckStatus::Lagging, CheckStatus::Broken]);
});

it('sends a reminder once the re-alert interval has passed', function () {
    $pair = ServerPair::factory()->create(['failures_before_alert' => 1, 'realert_after_minutes' => 30]);

    probing(broken());
    app(ReplicationChecker::class)->check($pair);
    Mail::assertSent(ReplicationAlertMail::class, 1);

    $this->travel(29)->minutes();
    app(ReplicationChecker::class)->check($pair->fresh());
    Mail::assertSent(ReplicationAlertMail::class, 1);

    $this->travel(2)->minutes();
    app(ReplicationChecker::class)->check($pair->fresh());
    Mail::assertSent(ReplicationAlertMail::class, 2);
});

it('never reminds when the interval is zero', function () {
    $pair = ServerPair::factory()->create(['failures_before_alert' => 1, 'realert_after_minutes' => 0]);

    probing(broken());
    app(ReplicationChecker::class)->check($pair);

    $this->travel(3)->days();
    app(ReplicationChecker::class)->check($pair->fresh());

    Mail::assertSent(ReplicationAlertMail::class, 1);
});

it('says so when the pair recovers, and only once', function () {
    $pair = ServerPair::factory()->create(['failures_before_alert' => 1]);

    probing(broken());
    app(ReplicationChecker::class)->check($pair);

    probing(healthy());
    app(ReplicationChecker::class)->check($pair->fresh());
    app(ReplicationChecker::class)->check($pair->fresh());

    Mail::assertSent(ReplicationAlertMail::class, 2);

    $pair->refresh();

    expect($pair->alerting)->toBeFalse()
        ->and($pair->consecutive_failures)->toBe(0)
        ->and($pair->failing_since)->toBeNull()
        ->and($pair->current_status)->toBe(CheckStatus::Ok)
        ->and(ReplicationAlert::query()->latest('id')->first()->kind)->toBe(AlertKind::Recovery);
});

it('does not announce a recovery for a pair that was never alerting', function () {
    $pair = ServerPair::factory()->create();

    probing(healthy());
    app(ReplicationChecker::class)->check($pair);

    Mail::assertNothingSent();
});

it('records the alert even when nobody is on the list', function () {
    AlertRecipient::query()->delete();

    $pair = ServerPair::factory()->create(['failures_before_alert' => 1]);

    probing(broken());
    app(ReplicationChecker::class)->check($pair);

    Mail::assertNothingSent();

    $alert = ReplicationAlert::query()->sole();

    expect($alert->recipients)->toBe([])
        ->and($alert->delivery_error)->toContain('No recipients');
});

it('keeps the state that drives the dashboard on the pair itself', function () {
    $pair = ServerPair::factory()->create(['failures_before_alert' => 1]);

    probing(lagging());
    $check = app(ReplicationChecker::class)->check($pair);

    $pair->refresh();

    expect($pair->current_status)->toBe(CheckStatus::Lagging)
        ->and($pair->last_lag_seconds)->toBe(900.0)
        ->and($pair->last_message)->toBe($check->message)
        ->and($pair->last_checked_at)->not->toBeNull()
        ->and($pair->failing_since)->not->toBeNull()
        ->and($pair->last_ok_at)->toBeNull();
});

it('records what the outage was on the recovery alert, whose own check is the healthy one', function () {
    $this->freezeSecond();

    $pair = ServerPair::factory()->create(['failures_before_alert' => 1, 'realert_after_minutes' => 0]);

    probing(lagging());
    app(ReplicationChecker::class)->check($pair);

    $this->travel(1)->minutes();
    probing(broken());
    app(ReplicationChecker::class)->check($pair->fresh());

    $this->travel(1)->minutes();
    probing(healthy());
    app(ReplicationChecker::class)->check($pair->fresh());

    $recovery = ReplicationAlert::query()->latest('id')->first();

    expect($recovery->kind)->toBe(AlertKind::Recovery)
        // The healthy check it points at says everything is fine; these say what
        // the night looked like.
        ->and($recovery->status)->toBe(CheckStatus::Ok)
        ->and($recovery->worst_status)->toBe(CheckStatus::Broken)
        ->and($recovery->failed_checks)->toBe(2)
        ->and($recovery->incident_duration_seconds)->toBe(120)
        ->and($recovery->peak_lag_seconds)->toBe(900.0)
        ->and($recovery->first_failure_message)->toContain('behind the primary')
        ->and($recovery->status_counts)->toBe(['broken' => 1, 'lagging' => 1])
        // The pair's history opens with the outage — nothing healthy before it —
        // so the monitor says how long it saw, not how long it was.
        ->and($recovery->incident_truncated)->toBeTrue()
        ->and($recovery->incidentHeadline())->toBe('Broken for at least 2m — 2 failed checks')
        ->and($recovery->subject)->toBe("[{$pair->name}] Replication recovered — Broken for at least 2m");
});

it('carries the episode on a problem alert too, so a reminder says how long it has been', function () {
    $this->freezeSecond();

    $pair = ServerPair::factory()->create(['failures_before_alert' => 1, 'realert_after_minutes' => 30]);

    probing(broken());
    app(ReplicationChecker::class)->check($pair);

    $this->travel(31)->minutes();
    app(ReplicationChecker::class)->check($pair->fresh());

    $reminder = ReplicationAlert::query()->latest('id')->first();

    expect($reminder->kind)->toBe(AlertKind::Problem)
        ->and($reminder->failed_checks)->toBe(2)
        ->and($reminder->incident_duration_seconds)->toBe(31 * 60)
        ->and($reminder->incidentHeadline())->toBe('Broken for at least 31m — 2 failed checks')
        // A reminder's subject stays stable, so it threads with the first email.
        ->and($reminder->subject)->toBe("[{$pair->name}] Replication Broken");
});

it('puts the outage in the email a sleeping operator will read in the morning', function () {
    $this->freezeSecond();

    $pair = ServerPair::factory()->create(['failures_before_alert' => 1]);

    probing(broken());
    app(ReplicationChecker::class)->check($pair);

    $this->travel(4)->minutes();
    probing(healthy());
    app(ReplicationChecker::class)->check($pair->fresh());

    Mail::assertSent(ReplicationAlertMail::class, function (ReplicationAlertMail $mail) {
        if ($mail->kind !== AlertKind::Recovery) {
            return false;
        }

        $body = $mail->render();

        return str_contains($body, 'What happened')
            && str_contains($body, 'Broken for at least 4m')
            && str_contains($body, 'Replication threads are not both running');
    });
});

it('leaves the incident columns empty when there is no episode to describe', function () {
    $pair = ServerPair::factory()->create(['failures_before_alert' => 1]);

    // A recovery alert is only sent for a pair that was alerting, so force the
    // one case where the dispatcher is handed a healthy check with no history
    // behind it: everything it could say would be invented.
    $pair->forceFill(['alerting' => true])->save();

    probing(healthy());
    app(ReplicationChecker::class)->check($pair);

    $alert = ReplicationAlert::query()->sole();

    expect($alert->kind)->toBe(AlertKind::Recovery)
        ->and($alert->hasIncident())->toBeFalse()
        ->and($alert->incidentHeadline())->toBeNull()
        ->and($alert->subject)->toBe("[{$pair->name}] Replication recovered");
});
