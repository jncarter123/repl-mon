<?php

declare(strict_types=1);

use App\Mail\TestMail;
use App\Models\AlertRecipient;
use App\Support\MailError;
use Illuminate\Support\Facades\Mail;

it('sends to the addresses given on the command line', function () {
    Mail::fake();

    $this->artisan('replication:test-mail', ['--to' => ['you@example.com', 'ops@example.com']])
        ->assertSuccessful();

    Mail::assertSent(TestMail::class, fn ($mail) => $mail->hasTo('you@example.com'));
    Mail::assertSent(TestMail::class, fn ($mail) => $mail->hasTo('ops@example.com'));
});

it('falls back to the global recipient list', function () {
    Mail::fake();

    AlertRecipient::factory()->create(['server_pair_id' => null, 'email' => 'dba@example.com']);
    AlertRecipient::factory()->create(['server_pair_id' => null, 'email' => 'muted@example.com', 'enabled' => false]);

    $this->artisan('replication:test-mail')->assertSuccessful();

    Mail::assertSent(TestMail::class, 1);
    Mail::assertSent(TestMail::class, fn ($mail) => $mail->hasTo('dba@example.com'));
});

it('fails rather than pretending, when there is nobody to send to', function () {
    Mail::fake();

    $this->artisan('replication:test-mail')->assertFailed();

    Mail::assertNothingSent();
});

it('says out loud that the log mailer sends nothing', function () {
    Mail::fake();

    config()->set('mail.default', 'log');

    $this->artisan('replication:test-mail', ['--to' => ['you@example.com']])
        ->expectsOutputToContain('does not send anything')
        ->assertSuccessful();
});

it('reports a transport failure instead of exiting zero', function () {
    config()->set('mail.default', 'smtp');
    config()->set('mail.mailers.smtp.host', 'smtp.invalid.example');
    config()->set('mail.mailers.smtp.port', 1);
    config()->set('mail.mailers.smtp.timeout', 1);

    $this->artisan('replication:test-mail', ['--to' => ['you@example.com']])
        ->assertFailed();
});

it('keeps the mailer password out of a transport error', function () {
    config()->set('mail.mailers.smtp.password', 'hunter2-the-smtp-password');

    $described = MailError::describe(
        new RuntimeException('Failed to authenticate: PLAIN hunter2-the-smtp-password rejected'),
    );

    expect($described)->not->toContain('hunter2-the-smtp-password')
        ->and($described)->toContain('[redacted]');
});

it('keeps the SES secret key out of a transport error', function () {
    config()->set('services.ses.secret', 'wJalrXUtnFEMI-EXAMPLE-KEY');

    expect(MailError::describe(new RuntimeException('SignatureDoesNotMatch for wJalrXUtnFEMI-EXAMPLE-KEY')))
        ->not->toContain('wJalrXUtnFEMI-EXAMPLE-KEY');
});

it('does not redact when nothing is configured', function () {
    config()->set('mail.mailers.smtp.password', null);
    config()->set('services.ses.secret', null);

    expect(MailError::describe(new RuntimeException('Connection refused')))
        ->toBe('Connection refused');
});
