<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\IncidentSummary;
use App\Enums\AlertKind;
use App\Mail\ReplicationAlertMail;
use App\Models\ReplicationAlert;
use App\Models\ReplicationCheck;
use App\Models\ServerPair;
use App\Support\MailError;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends the email and writes down that it did. The record is the point: an
 * alert nobody can prove was sent is the same as no alert, and "who was on the
 * list that night" is the first question after an outage.
 */
class AlertDispatcher
{
    public function __construct(protected IncidentSummariser $incidents) {}

    public function send(ServerPair $pair, ReplicationCheck $check, AlertKind $kind): ReplicationAlert
    {
        $recipients = $pair->resolvedRecipients();

        // Worked out once, before anything is sent, so the email and the row
        // that records it tell the same story. It is the only part of a
        // recovery alert that says what the recovery was from.
        $incident = $this->incidents->summarise($pair, $check);

        $subject = ReplicationAlertMail::subjectFor($pair, $check, $kind, $incident);

        if ($recipients->isEmpty()) {
            // Loud, because a monitor with nobody to tell is worse than no
            // monitor: it looks like everything is fine.
            Log::warning('Replication alert had no recipients.', [
                'server_pair_id' => $pair->getKey(),
                'pair' => $pair->name,
                'status' => $check->status->value,
            ]);

            return $this->record($pair, $check, $kind, $subject, [], 'No recipients are configured for this pair, and the global list is empty.', $incident);
        }

        $errors = [];

        foreach ($recipients as $recipient) {
            try {
                // One message each: the recipient list is internal, and an
                // outage email is not the place to publish it.
                Mail::to($recipient->email, $recipient->name)
                    ->send(new ReplicationAlertMail($pair, $check, $kind, $incident));
            } catch (Throwable $e) {
                $errors[] = $recipient->email.': '.MailError::describe($e);

                Log::error('Replication alert delivery failed.', [
                    'server_pair_id' => $pair->getKey(),
                    'recipient' => $recipient->email,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $this->record(
            $pair,
            $check,
            $kind,
            $subject,
            array_values($recipients->pluck('email')->all()),
            $errors === [] ? null : implode(' | ', $errors),
            $incident,
        );
    }

    /**
     * @param  list<string>  $emails
     */
    protected function record(
        ServerPair $pair,
        ReplicationCheck $check,
        AlertKind $kind,
        string $subject,
        array $emails,
        ?string $deliveryError,
        ?IncidentSummary $incident = null,
    ): ReplicationAlert {
        return $pair->alerts()->create([
            'replication_check_id' => $check->getKey(),
            'kind' => $kind,
            'status' => $check->status,
            'subject' => $subject,
            'summary' => $check->message,
            'recipients' => $emails,
            'lag_seconds' => $check->lag_seconds,
            // Copied onto the row rather than looked up later: checks are
            // pruned after a fortnight and alerts are kept for a year, and the
            // question "what was that, last month?" is the one being answered.
            'incident_started_at' => $incident?->startedAt,
            'incident_truncated' => $incident->startedBeforeWindow ?? false,
            'incident_duration_seconds' => $incident?->durationSeconds,
            'failed_checks' => $incident?->failedChecks,
            'worst_status' => $incident?->worstStatus,
            'peak_lag_seconds' => $incident?->peakLagSeconds,
            'first_failure_message' => $incident?->firstFailureMessage,
            'replica_error' => $incident?->replicaError,
            'status_counts' => $incident?->statusCounts,
            'delivery_error' => $deliveryError,
            'sent_at' => now(),
        ]);
    }
}
