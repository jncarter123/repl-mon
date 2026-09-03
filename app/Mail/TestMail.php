<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Deliberately built the same way a real alert is — same markdown pipeline,
 * same From address, same transport. A test that goes out by some simpler
 * route proves less than nothing.
 */
class TestMail extends Mailable
{
    use Queueable, SerializesModels;

    // Not $mailer: Mailable already owns that name, and redeclaring it
    // with a type is a fatal error rather than an override.
    public function __construct(public string $mailerName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '['.config('app.name').'] Test message');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.test');
    }
}
