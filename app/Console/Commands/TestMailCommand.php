<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\TestMail;
use App\Models\AlertRecipient;
use App\Support\MailError;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Email is the one way out of this app that fails quietly, and the failure
 * looks exactly like "nothing has gone wrong yet". This sends one message
 * through the configured transport so the silence can be told apart.
 */
class TestMailCommand extends Command
{
    protected $signature = 'replication:test-mail
                            {--to=* : Send here instead of the global recipient list}
                            {--mailer= : Use this mailer instead of the default}';

    protected $description = 'Send a test message through the configured mailer and report what the transport said';

    public function handle(): int
    {
        $mailer = (string) ($this->option('mailer') ?: config('mail.default'));

        $this->components->twoColumnDetail('Mailer', "<fg=cyan>{$mailer}</>");
        $this->components->twoColumnDetail('From', (string) config('mail.from.address'));

        foreach ($this->transportDetail($mailer) as $label => $value) {
            $this->components->twoColumnDetail($label, $value);
        }

        if ($mailer === 'log' || $mailer === 'array') {
            // Sending would "succeed" and nobody would be emailed. That is a
            // reasonable way to watch it work for a day, and a terrible thing
            // to discover during an outage.
            $this->components->warn("MAIL_MAILER={$mailer} does not send anything. The message below goes to the log, not to a mailbox.");
        }

        $recipients = $this->recipients();

        if ($recipients === []) {
            $this->components->error('Nobody to send to: the global recipient list is empty. Pass --to=you@example.com, or add a recipient.');

            return self::FAILURE;
        }

        $failed = 0;

        foreach ($recipients as $email) {
            try {
                Mail::mailer($mailer)->to($email)->send(new TestMail($mailer));

                $this->components->twoColumnDetail($email, '<fg=green>sent</>');
            } catch (Throwable $e) {
                $failed++;

                // Verbatim, minus the credentials: the transport's own wording
                // is the thing that tells an operator which knob is wrong.
                $this->components->twoColumnDetail($email, '<fg=red>failed</>');
                $this->components->error(MailError::describe($e));
            }
        }

        if ($failed > 0) {
            return self::FAILURE;
        }

        $this->components->info('Handed to the transport without error. If nothing arrives, the message was accepted and dropped downstream — check the mail server, and the spam folder.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    protected function recipients(): array
    {
        /** @var list<string> $to */
        $to = array_values(array_filter(array_map(
            fn (mixed $value): string => trim((string) $value),
            (array) $this->option('to'),
        )));

        if ($to !== []) {
            return $to;
        }

        // The global list, not every recipient in the table: a pair's own
        // recipients were named to *narrow* who hears about that pair, and a
        // test is not news about a pair.
        $global = AlertRecipient::query()
            ->global()
            ->where('enabled', true)
            ->orderBy('email')
            ->get();

        return array_values(array_map(
            fn (AlertRecipient $recipient): string => (string) $recipient->email,
            $global->all(),
        ));
    }

    /**
     * The settings worth reading back, so a typo in the one that matters is
     * visible next to the failure it caused.
     *
     * @return array<string, string>
     */
    protected function transportDetail(string $mailer): array
    {
        /** @var array<string, mixed> $config */
        $config = (array) config("mail.mailers.{$mailer}", []);
        $transport = (string) ($config['transport'] ?? $mailer);

        return match ($transport) {
            'smtp' => [
                'Host' => (string) ($config['host'] ?? '').':'.(string) ($config['port'] ?? ''),
                'Encryption' => (string) ($config['scheme'] ?? 'auto'),
                'Username' => ($config['username'] ?? null) === null ? '<fg=yellow>none — sending unauthenticated</>' : (string) $config['username'],
            ],
            'ses', 'ses-v2' => [
                'Region' => (string) config('services.ses.region'),
                'Credentials' => config('services.ses.key')
                    ? 'AWS_ACCESS_KEY_ID from the environment'
                    : '<fg=cyan>none set — the SDK will use the instance or task role</>',
            ],
            default => [],
        };
    }
}
