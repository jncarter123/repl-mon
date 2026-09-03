<x-mail::message>
# Mail is working

This is a test sent by `php artisan replication:test-mail`. Nothing is wrong
with any replica — it only proves that this monitor can reach you, which is the
one thing it cannot tell you about itself.

<x-mail::table>
| | |
|:--|:--|
| Sent by | {{ config('app.name') }} |
| Transport | `{{ $mailerName }}` |
| From | `{{ config('mail.from.address') }}` |
| Sent at | {{ now()->toDayDateTimeString() }} UTC |
</x-mail::table>

If a real alert never arrives after this one did, the problem is the recipient
list rather than the mail settings.

{{ config('app.name') }}
</x-mail::message>
