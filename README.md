# repl-monitor

A monitor for MariaDB primary/replica pairs. It writes a heartbeat on each
primary once a minute, reads it back off the replica, and emails whoever is on
the list when the replica falls behind, stops replicating, or stops answering.

---

## How it decides something is wrong

Two independent signals per pair, per minute:

**1. The heartbeat.** One row per pair in a small table that is inside
replication. Every minute the monitor upserts that row on the primary with a
timestamp taken from *its own* clock, then reads the row back off the replica
and compares it to *its own* clock again. Neither database server's clock enters
the arithmetic, so a server with a drifting clock cannot produce phantom lag —
which is the usual failure of `Seconds_Behind_Source`.

After writing a beat the monitor waits up to two seconds for that beat to show
up on the replica before measuring. Without that window a perfectly healthy pair
reads as a full minute behind on every check, because the newest row on the
replica is still the previous minute's beat. The wait ends the instant the beat
arrives, so a healthy pair costs one extra query and a genuinely lagging pair
falls through to the last beat that *did* arrive — which is the number you want.

**2. `SHOW REPLICA STATUS`.** A stopped IO or SQL thread is caught the moment it
stops, rather than a threshold's worth of minutes later when the heartbeat
finally goes stale. Being refused this grant is recorded and shown, but is not
itself treated as a fault: the heartbeat answers the question on its own.

The verdict, worst first:

| Status | Meaning |
|:--|:--|
| **Unreachable** | One of the two servers did not answer. |
| **Broken** | A replication thread is not running, the replica reports no replication at all, or no beat has ever arrived. |
| **Lagging** | The beat arrived, but older than the pair's threshold. |
| **Healthy** | The beat arrived inside the threshold. |

---

## Getting started

```bash
composer install
npm install && npm run build
cp .env.example .env && php artisan key:generate
# point DB_* at this app's own database, then:
php artisan migrate
```

Visit `/register` to create the first operator account. **The sign-up page then
disappears** — this app stores credentials for production database servers, so
open registration is not something it can have. Later accounts:

```bash
php artisan replication:add-user --name="Jane" --email=jane@example.com
```

### The one line that makes it a monitor

```cron
* * * * * cd /path/to/repl-monitor && php artisan schedule:run >> /dev/null 2>&1
```

That drives `replication:check` every minute and `replication:prune` nightly.
Without it the app is a UI over an empty history.

---

## Running it in a container

Two services out of one image, in `compose.yaml`:

| | |
|:--|:--|
| `app` | The UI, on FrankenPHP. Also runs the migrations on start. |
| `scheduler` | `schedule:work` — the cron line above, with nothing else on the box to run it. |

The scheduler is not an optional extra. Without it the container is a UI over
an empty history.

```bash
cp docker.env.example docker.env   # mail settings live here
docker compose up -d --build
```

Then `http://localhost:8000/register` for the first operator account, after
which the sign-up page disappears as it does anywhere else.

Everything the monitor owns — its SQLite database and its `APP_KEY` — is on the
`repl-monitor-data` volume. **Back it up.** The key is what decrypts the stored
database passwords; a volume restored without it leaves every pair with
credentials that cannot be read. Set `APP_KEY` in `docker.env` instead if you
would rather hold it yourself; leave it blank and the first run generates one
and says so in the log.

The monitor's own store is deliberately not on a server it watches. A monitor
that goes down with the database is one that cannot tell you the database went
down.

### Next to MaxScale

The container needs to reach the database servers itself; the host having
access does not carry over. If MaxScale is already on a Docker network that can
see them, put this on the same one — the bottom of `compose.yaml` has the
stanza, commented:

```yaml
networks:
  default:
    name: maxscale_default   # docker network ls for the real name
    external: true
```

**Point pairs at the servers, not at the MaxScale listener.** A pair's two
addresses have to be the actual primary and the actual replica: the whole
measurement is "write here, read *there*", and a read that MaxScale routes to
whichever backend it likes — including the primary — measures nothing. Router
ports are the one place these credentials should not go.

### Day to day

```bash
docker compose logs -f scheduler                              # what it is doing every minute
docker compose run --rm app php artisan replication:add-user  # another operator
docker compose run --rm app php artisan replication:test pair-name
docker compose run --rm scheduler check                       # one pass, now
docker compose up -d --build                                  # upgrade; migrations run on start
```

The UI is published on `127.0.0.1:8000` only, on the assumption that something
already terminating TLS goes in front of it. Widen the mapping in
`compose.yaml` if not, and set `APP_URL` to whatever the browser sees.

Times in the UI are UTC.

---

## Setting up a pair

### 1. Give it a database to beat in

The heartbeat table has to be somewhere that is actually replicated. Either name
an existing replicated schema when you add the pair, or let the app make one for
the purpose — **Set up the heartbeat** on the pair form creates the schema,
creates the table in it, and then proves a beat actually crosses. Same thing from
the command line:

```bash
php artisan replication:provision <pair>
```

If you would rather do it by hand, it is one statement:

```sql
-- on the primary
CREATE DATABASE repl_monitor;
```

Either way, the schema must not be excluded by `binlog_ignore_db` /
`replicate_ignore_db` on either side, or the monitor will correctly and
permanently report the pair as broken. This is the easiest way to set the app up
wrong and the hardest to spot later, which is why the setup step ends by writing
a beat and waiting for it: if it does not arrive, it reads those four variables
and tells you which one is in the way, or that a replication thread is stopped.

This does not configure replication — it assumes your DBAs already have. It
issues no `CHANGE MASTER`, changes no server settings, and its only DDL is
`CREATE ... IF NOT EXISTS`.

### 2. Grant the monitor user

On the **primary** — it needs to write the beat, and `CREATE` only if you want
the app to make the table for you:

```sql
CREATE USER 'repl_monitor'@'10.0.0.%' IDENTIFIED BY '…';
GRANT SELECT, INSERT, UPDATE, CREATE ON repl_monitor.* TO 'repl_monitor'@'10.0.0.%';
```

On the **replica** — it needs to read the beat, plus the privilege that makes
`SHOW REPLICA STATUS` readable:

```sql
CREATE USER 'repl_monitor'@'10.0.1.%' IDENTIFIED BY '…';
GRANT SELECT ON repl_monitor.* TO 'repl_monitor'@'10.0.1.%';

-- MariaDB 10.5.9+
GRANT REPLICA MONITOR ON *.* TO 'repl_monitor'@'10.0.1.%';
-- older MariaDB, or MySQL
GRANT REPLICATION CLIENT ON *.* TO 'repl_monitor'@'10.0.1.%';
```

Nothing here needs write access to your data, and nothing needs `SUPER`.

### 3. Add the pair

**Server pairs → Add a pair.** Fill in both sides, use **Test connection** on
each — it reports connecting, the heartbeat table and the status grant
separately, so a half-working pair tells you which half — then **Set up the
heartbeat**, which creates the schema and table on the primary and confirms the
beat reaches the replica.

Replication carries the DDL across for you. If your setup does not replicate DDL,
tick **Create on the replica as well**, or run:

```bash
php artisan replication:provision <pair> --replica
```

If the monitor's own credentials are not allowed to create the schema, the app
does not ask you for a root password: it prints the `CREATE DATABASE` and `GRANT`
to hand to somebody who has the rights, and you run it again afterwards. It is
safe to run as many times as you like.

Later on, **Verify replication** on a pair's own page repeats just the last step.

### 4. Say who gets told

**Recipients** is the global list. A pair can name its own recipients on its own
page, in which case it uses *those instead of* the global list, not as well as.
Both the pair page and the recipients page say so on screen, and warn when a
pair would have nobody to email.

---

## Alerting

Configured per pair, so a reporting replica and a payments replica do not have
to share a temperament:

- **Lag threshold** — seconds behind before the pair counts as lagging.
- **Failures before alerting** — consecutive bad checks. `2` rides out a single
  slow minute; `1` tells you about it.
- **Remind every** — minutes between reminders while an outage continues. `0`
  sends one email per outage and no reminders.

On top of that: a problem that gets *worse* (lagging → broken) always sends
immediately, because that is new information; and a recovery always sends once,
so nobody drives in to check.

Every email is recorded — subject, recipients, and any delivery failure — on the
pair's page and on the dashboard. An alert nobody can prove was sent is the same
as no alert.

---

## Commands

| Command | |
|:--|:--|
| `replication:check [pair]` | One pass over every enabled pair. This is the scheduled one. |
| `replication:test [pair]` | Connect to both servers and report which grants are missing. |
| `replication:provision [pair] [--replica] [--verify-only]` | Create the heartbeat schema and table, then prove a beat gets across. |
| `replication:install-heartbeat [pair] [--replica]` | Create just the heartbeat table, assuming the schema exists. |
| `replication:prune` | Trim check history (default 14 days); alerts are kept a year. |
| `replication:add-user` | Create an operator account. |

---

## Notes on what is stored

- Database passwords are encrypted at rest with `APP_KEY` and are never sent
  back to the browser. A blank password field on the edit form means "leave it
  alone"; clearing one takes an explicit switch.
- Error text from PDO is scrubbed of the pair's own credentials before it is
  written to the check history, shown on screen, or put in an email.
- The heartbeat table name is validated as a plain identifier in the form *and*
  again at the connection layer, because it is interpolated into SQL where no
  placeholder is possible.
- Deleting a pair takes its history and its own recipients with it. The
  heartbeat row on your primary is left where it is — deleting from your
  database is not this app's call to make.

## Demo rows

The dev database ships with three throwaway pairs (`demo-in-sync`,
`demo-no-heartbeat`, `demo-unreachable`) pointed at the local MariaDB so the
dashboard has something to show. Delete them whenever.

## Checks

```bash
composer test   # pint --test, phpstan level 7, pest
```
