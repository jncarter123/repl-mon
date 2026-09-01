# repl-monitor — Claude Instructions

## Purpose

Watches MariaDB primary/replica pairs. Once a minute, per pair: upsert a
heartbeat row on the primary, read it back off the replica, ask the replica
about its own threads, and email a list of people when something is wrong.

Read `README.md` first — it explains the mechanism and the grants. This file is
only the things that are easy to get wrong.

---

## Stack

- PHP 8.4, Laravel 13, Livewire 4 (class components), Flux 2, Fortify
- Pest 5, Larastan level 7, Pint. `composer test` runs all three.
- `Date::use(CarbonImmutable::class)` is set in `AppServiceProvider`, so `now()`
  is immutable everywhere.

---

## The measurement — do not "simplify" it

**Lag is measured against this host's clock at both ends.** The monitor stamps
`beat_at` in PHP, not with `NOW()` on the primary, and compares it to PHP's
clock again after reading it off the replica. Switching either end to a server
clock reintroduces exactly the drift problem this design exists to avoid.

**`ReplicationProbe::awaitBeat()` is not a busy-wait to be optimised away.**
After writing beat N it polls the replica for up to `settle_timeout_ms` waiting
for beat N to appear. Remove it and every healthy pair reports a full check
interval of lag, because the newest row on the replica is still beat N-1. The
loop exits the moment the beat lands, so the healthy path costs one query.

**`SHOW REPLICA STATUS` vs `SHOW SLAVE STATUS`.** Both are tried, and column
names are read case-insensitively from both vocabularies
(`Replica_IO_Running`/`Slave_IO_Running`, `Seconds_Behind_Source`/
`Seconds_Behind_Master`). Do not collapse to one spelling.

**Three different "we could not read the status" cases, deliberately kept
apart:**

| Signal | Meaning | Alerts? |
|:--|:--|:--|
| `statusQueryError` | We were refused the grant, or the syntax is unsupported | **No** — the heartbeat still works |
| `notAReplica` | The server answered, with zero rows: it is not replicating | Yes, Broken |
| `threadsRunning() === false` | A thread is not `Yes` | Yes, Broken |

Folding a missing grant into a fault would make the app unusable at any shop
that will not hand out `REPLICA MONITOR`.

---

## Layering

- `ReplicationProbe` gathers **facts only** and returns a `ProbeResult`. It
  never decides anything and never writes to the database.
- `ReplicationEvaluator` turns a `ProbeResult` into a `CheckOutcome`. **Pure** —
  no I/O, no clock, no models written. That is what makes every branch cheap to
  test, and every branch is tested. Keep it that way.
- `ReplicationChecker` orchestrates: probe, evaluate, persist, transition state,
  decide whether to alert.
- `AlertDispatcher` sends and, crucially, **records** — including when there was
  nobody to send to.

Livewire components are thin: UI state, delegate to services. No Eloquent
queries or business logic in a component beyond the read that feeds the view.

---

## Alerting rules

All of `ReplicationChecker::maybeAlert()`, and each rule exists to stop the
monitor becoming a mail folder people filter away:

- nothing until `failures_before_alert` consecutive bad checks;
- one email when a problem starts, not one a minute;
- another **immediately** on escalation (lagging → broken) — new information;
- a reminder every `realert_after_minutes` (`0` disables reminders entirely);
- one when it clears.

Pausing a pair clears `alerting`, `consecutive_failures` and `failing_since`, so
a pair switched off mid-outage does not fire a recovery email when it is
switched back on.

---

## Recipients

`ServerPair::resolvedRecipients()` — a pair's own enabled recipients if it has
any, **otherwise** the global list (`server_pair_id IS NULL`). Never both:
naming someone specific is how an operator says "not the usual list". Both the
pair page and the recipients page state this on screen, because discovering it
during an outage is the wrong time.

A pair with no recipients and an empty global list still gets a
`ReplicationAlert` row, with `delivery_error` explaining that nobody was
emailed, plus a `Log::warning`. A monitor with nobody to tell is worse than no
monitor: it looks like everything is fine.

---

## Security

- **Never log or display a database password.** `DatabaseError::describe()`
  strips the pair's own credentials out of PDO messages and truncates them;
  everything that surfaces an exception goes through it.
- **Never interpolate an identifier that has not been through
  `PairConnectionFactory::assertSafeIdentifier()`.** The heartbeat table name is
  the only interpolated identifier in the app; it is validated in the form and
  again at the connection layer. Both locks are tested.
- Passwords use the `encrypted` cast and are never sent to the browser — blank
  on the edit form means "unchanged", and clearing one takes the explicit
  `*_no_password` switch.
- Registration is first-run only (`RegistrationIsFirstRunOnly`, matching
  `register*` so both the form and `register.store` are covered). It 404s rather
  than 403s. Further accounts come from `replication:add-user`.

---

## Connections

Pair connections are built at runtime by `PairConnectionFactory` and are never
in `config/database.php` — a pair can be added or edited in the UI and the
connection has to follow it. It purges before connecting and `forget()`s after,
so an edited host is never talked to under the old credentials.

Every connection carries a connect timeout and, on MariaDB, a session
`max_statement_time`. One hung server must not hold up the other pairs, and
`replication:check` catches per-pair exceptions for the same reason: the next
pair in the list may be the one actually on fire.

---

## What to avoid

- **Never** compare a timestamp taken from one server against a clock on another.
- **Never** let a single pair's failure abort the run for the rest.
- **Never** treat a missing `REPLICA MONITOR` grant as an outage.
- **Never** send an alert without recording it.
- **Never** put business logic in a Livewire component or a Blade view.
- **Never** widen `ReplicationEvaluator` to do I/O.
