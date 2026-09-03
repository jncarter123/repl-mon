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
- `ConnectionTester` reports each capability separately — connecting, the schema
  being there, the table being there, the status grant — and when the pair's
  schema does not exist yet it **retries over `serverConnection()`** rather than
  reporting a dead connection. The DSN names the schema, so MariaDB refuses the
  connect before any statement runs; left as a plain failure, the first thing
  anybody does on a new pair reads as bad credentials and they go and create the
  database by hand instead of pressing the setup button that would have made it.
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

A token is required — none in existence, no route (404), because the response
names pairs and their state. A *wrong* token is 401: that one is a misconfigured
check command and telling the two apart is worth more than the little it gives
away. The route is outside the `web` group on purpose — no session, no CSRF, and
never a redirect to a login page for something that only reads status codes.

Tokens come from two places and `HealthTokens` is the only thing that knows it:
`REPL_HEALTH_TOKEN`, and rows in `health_tokens` issued from the Health endpoint
page (`App\Livewire\Health\Index`, its own nav item — how this app is watched is
a different subject from what it watches, and burying it on the dashboard is how
it ends up never set up).
**Several may be valid at once, on purpose** — that is what makes rotating one a
rotation instead of an outage, and it is why the middleware asks a service
rather than comparing against a config value. Deleting the last token switches
the endpoint off; that is the intended way to turn it off, not a bug.

Health tokens are stored `encrypted` and **are** shown in the UI, unlike every
other secret in this app. That is deliberate: this one exists to be copied into
a check command, possibly months later, and hashing it would make setting up a
second checker mean rotating the one already working. It is masked until asked
for, and it is not a credential for anybody else's server.

`last_used_at` is stamped at most once a minute, and the page shows it,
because a token that has never been polled is a check somebody started wiring up
and did not finish — the same silence the endpoint exists to break.

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

## Mail

Transport is environment only — `MAIL_MAILER` in `docker.env` or `.env`. There
is no UI for it and there should not be: it is not per-pair, and a mail setting
that can be edited from inside the app is one an operator can lock themselves
out of hearing about.

Two transports are configured and documented, `smtp` and `ses-v2`, plus `log`
for a first day. **SES has two doors and the choice is about credentials, not
mail**: its SMTP endpoint needs nothing installed, and the `ses-v2` API mailer
exists for exactly one reason — with `AWS_ACCESS_KEY_ID` unset the SDK signs
with an EC2/ECS role, so there is no long-lived secret in the environment.
`aws/aws-sdk-php` is a direct dependency for that path alone. Do not remove it
assuming the SMTP endpoint covers everything; it does not cover the role.

`MailError::describe()` is the mail-side companion to `DatabaseError::describe()`
and exists for the same rule: **never display a password**. A transport puts the
failing conversation into the exception, and that string is stored on the alert
row, shown on the dashboard and printed by `replication:test-mail`. It strips
every configured mailer secret, longest first so a value containing another is
redacted whole. `AlertDispatcher` goes through it — `DatabaseError` would not
have caught the SMTP password, only a pair's.

`replication:test-mail` is the only way to find out that mail works before an
outage does the asking. It sends a real `TestMail` through the real transport
and the real markdown pipeline — anything simpler proves less than nothing —
prints the host or region it is about to use next to the failure it caused, and
**says out loud when `MAIL_MAILER=log`**, which succeeds at everything except
sending. With no `--to` it uses the global list, not every recipient in the
table: a pair's own recipients were named to narrow who hears about that pair,
and a test is not news about a pair.

---

## Security

- **Never log or display a database password.** `DatabaseError::describe()`
  strips the pair's own credentials out of PDO messages and truncates them;
  everything that surfaces an exception goes through it.
- **Never interpolate an identifier that has not been through
  `PairConnectionFactory::assertSafeIdentifier()`.** The heartbeat table name is
  the only interpolated identifier in the app; it is validated in the form and
  again at the connection layer. Both locks are tested.
- Health tokens are the one secret that **is** shown in the UI, masked, and the
  reason is in "The health endpoint" above. Database passwords are not, and
  neither is the mailer's — see `MailError` under "Mail".
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
- **Never** surface a mail exception without `MailError::describe()`: it is the
  only thing standing between the SMTP password and the dashboard.
- **Never** fold a refused `CREATE` into a fault. A denial means somebody needs to
  run a `GRANT`; the app prints it (`GrantAdvice`) rather than asking an operator
  for credentials it would then have to hold.
