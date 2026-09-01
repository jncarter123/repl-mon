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

**`HeartbeatManager::awaitBeat()` is not a busy-wait to be optimised away.**
After writing beat N it polls the replica for up to `settle_timeout_ms` waiting
for beat N to appear. Remove it and every healthy pair reports a full check
interval of lag, because the newest row on the replica is still beat N-1. The
loop exits the moment the beat lands, so the healthy path costs one query.
`ReplicationProbe` and `HeartbeatProvisioner` both call it — the provisioner with
a longer budget (`provision_verify_timeout_ms`), because a setup check run by
hand can afford to wait and a per-minute check cannot.

**`SHOW REPLICA STATUS` vs `SHOW SLAVE STATUS`.** Both are tried, and column
names are read case-insensitively from both vocabularies
(`Replica_IO_Running`/`Slave_IO_Running`, `Seconds_Behind_Source`/
`Seconds_Behind_Master`). Do not collapse to one spelling. This lives in
`ReplicaStatusReader` and has exactly one implementation; the probe and the
setup-time diagnosis both go through it.

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
- `HeartbeatProvisioner` **acts and reports**: it creates the heartbeat schema
  and table and then proves a beat crosses. It decides nothing about alerting and
  writes no models. It assumes replication is already configured — it issues no
  `CHANGE MASTER`, touches no server variable, and its only DDL is
  `CREATE ... IF NOT EXISTS`. Keep it that way: a monitor that can reconfigure
  replication is a different and much more dangerous piece of software.
- `HealthReporter` **reads and reports** for the outside world: it answers
  `GET /api/health` from this app's own store only. It never probes a monitored
  server, never writes, and never alerts — a health endpoint that could hang on a
  dead MariaDB is one more thing to go wrong at the worst moment.
- `ReplicationFilters` and `GrantAdvice` are **pure**, for the same reason
  `ReplicationEvaluator` is: the interesting judgement is in them, and the test
  suite has no MariaDB.

Livewire components are thin: UI state, delegate to services. No Eloquent
queries or business logic in a component beyond the read that feeds the view.

---

## The health endpoint

`GET /api/health` (`routes/api.php` → `HealthController` → `HealthReporter`)
exists because every other way out of this app is email, and email fails
quietly. Two rules carry the whole design:

**Stale checks are critical, whatever the pairs last said.** If no
`replication:check` has run for `health.stale_after_minutes`, every pair's
`current_status` is a museum piece and no alert is coming, however bad things
get. This is the one failure only an outside observer can see, and it is the
reason the endpoint exists — a version of it that reports green pairs while
nothing is checking them is worse than not having it.

**Only 200 means OK. Everything else is 503, lag included.** A check command
that reads nothing but the status code has to catch a lagging replica too. The
body still separates `REPLICATION WARNING` from `REPLICATION CRITICAL` for
anyone parsing it.

Also reported, because each is a way for a real outage to go unheard: a pair
nobody would be emailed about, an alert with a `delivery_error` inside
`health.delivery_failure_window_minutes`, and nothing enabled to watch at all.

The token is required — no `REPL_HEALTH_TOKEN`, no route (404), because the
response names pairs and their state. A *wrong* token is 401: that one is a
misconfigured check command and telling the two apart is worth more than the
little it gives away. The route is outside the `web` group on purpose — no
session, no CSRF, and never a redirect to a login page for something that only
reads status codes.

Keep the text body's first line a complete verdict. It is what a paging system
quotes, and it is the string operators match on (`check_http -s`).

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

## Container

`Dockerfile` + `compose.yaml` build **one image with two roles**, selected by
the first argument to `docker/entrypoint.sh`: `web` (FrankenPHP over
`public/`, plus the migrations) and `scheduler` (`schedule:work`). Do not fold
the scheduler into the web container — an image that only serves the UI is a
monitor that never checks anything.

- The web role owns the migrations and the `APP_KEY`; the scheduler waits on
  `service_healthy` so the two never race for either. Keep that `depends_on`.
- `APP_KEY` is generated onto the data volume on first run if unset. It
  decrypts the pairs' stored passwords, so it must live with the SQLite file,
  not in the image.
- The app's own store is SQLite on a volume, on purpose: the monitor must not
  depend on a server it is watching. `QUEUE_CONNECTION=sync` for the same
  reason — nothing queues, and a queued alert with no worker is a lost one.
- Assets build on Debian, not Alpine: `package.json` pins `*-linux-x64-gnu`
  binaries. The CSS build needs `vendor/` — `app.css` imports Flux from it.

---

## What to avoid

- **Never** compare a timestamp taken from one server against a clock on another.
- **Never** let a single pair's failure abort the run for the rest.
- **Never** treat a missing `REPLICA MONITOR` grant as an outage.
- **Never** send an alert without recording it.
- **Never** let the health endpoint touch a monitored server, or answer 200 on
  anything but a healthy, freshly-checked list.
- **Never** put business logic in a Livewire component or a Blade view.
- **Never** widen `ReplicationEvaluator` to do I/O.
- **Never** let provisioning grow past `CREATE ... IF NOT EXISTS` — no `DROP`, no
  `ALTER`, nothing that changes how replication itself is set up.
- **Never** fold a refused `CREATE` into a fault. A denial means somebody needs to
  run a `GRANT`; the app prints it (`GrantAdvice`) rather than asking an operator
  for credentials it would then have to hold.
