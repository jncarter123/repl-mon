<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AlertKind;
use App\Mail\ReplicationAlertMail;
use App\Models\ReplicationAlert;
use App\Models\ReplicationCheck;
use App\Models\ServerPair;
use App\Support\DatabaseError;
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
    public function send(ServerPair $pair, ReplicationCheck $check, AlertKind $kind): ReplicationAlert
    {
        $recipients = $pair->resolvedRecipients();
        $subject = ReplicationAlertMail::subjectFor($pair, $check, $kind);

        if ($recipients->isEmpty()) {
            // Loud, because a monitor with nobody to tell is worse than no
            // monitor: it looks like everything is fine.
            Log::warning('Replication alert had no recipients.', [
                'server_pair_id' => $pair->getKey(),
                'pair' => $pair->name,
                'status' => $check->status->value,
            ]);

            return $this->record($pair, $check, $kind, $subject, [], 'No recipients are configured for this pair, and the global list is empty.');
        }

        $errors = [];

        foreach ($recipients as $recipient) {
            try {
                // One message each: the recipient list is internal, and an
                // outage email is not the place to publish it.
                Mail::to($recipient->email, $recipient->name)
                    ->send(new ReplicationAlertMail($pair, $check, $kind));
            } catch (Throwable $e) {
                $errors[] = $recipient->email.': '.DatabaseError::describe($e);

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
    ): ReplicationAlert {
        return $pair->alerts()->create([
            'replication_check_id' => $check->getKey(),
            'kind' => $kind,
            'status' => $check->status,
            'subject' => $subject,
            'summary' => $check->message,
            'recipients' => $emails,
            'lag_seconds' => $check->lag_seconds,
            'delivery_error' => $deliveryError,
            'sent_at' => now(),
        ]);
    }
}
