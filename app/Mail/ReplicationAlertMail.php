<?php

declare(strict_types=1);

namespace App\Mail;

use App\Data\IncidentSummary;
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
        public ?IncidentSummary $incident = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: static::subjectFor($this->pair, $this->check, $this->kind, $this->incident));
    }

    /**
     * The subject line is the only part of an alert a phone shows at 3am, and
     * "recovered" on its own says nothing about what from or for how long — so
     * a recovery carries the outage in it.
     */
    public static function subjectFor(
        ServerPair $pair,
        ReplicationCheck $check,
        AlertKind $kind,
        ?IncidentSummary $incident = null,
    ): string {
        if ($kind !== AlertKind::Recovery) {
            return "[{$pair->name}] Replication {$check->status->label()}";
        }

        return $incident === null
            ? "[{$pair->name}] Replication recovered"
            : "[{$pair->name}] Replication recovered — {$incident->worstStatus->label()} for {$incident->duration()}";
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.replication-alert');
    }
}
