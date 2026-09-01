<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\AlertKind;
use App\Models\ReplicationCheck;
use App\Models\ServerPair;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReplicationAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ServerPair $pair,
        public ReplicationCheck $check,
        public AlertKind $kind,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: static::subjectFor($this->pair, $this->check, $this->kind));
    }

    public static function subjectFor(ServerPair $pair, ReplicationCheck $check, AlertKind $kind): string
    {
        return $kind === AlertKind::Recovery
            ? "[{$pair->name}] Replication recovered"
            : "[{$pair->name}] Replication {$check->status->label()}";
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.replication-alert');
    }
}
